<?php
declare(strict_types=1);

// Contas do painel: usuários no banco, "lembrar de mim", código de convite e CSRF.
// Requer config.php carregado. O usuário de WEB_LOGIN_USUARIO (config.php) continua
// valendo como administrador.

const LEMBRAR_COOKIE = 'sd_lembrar';
const LEMBRAR_DIAS = 30;
const USUARIO_REGEX = '/^[a-z0-9._-]{3,30}$/';
const LIMITE_TENTATIVAS = 5;      // erros por IP + usuário...
const LIMITE_JANELA_MIN = 15;     // ...nesta janela
const LIMITE_TENTATIVAS_IP = 20;  // erros por IP (qualquer usuário) na mesma janela

if (!function_exists('garantir_schema_v2')) {
    require_once __DIR__ . '/migracoes.php';
}

function garantir_tabelas_auth(): void
{
    static $feito = false;
    if ($feito) {
        return;
    }
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS usuarios (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            usuario VARCHAR(30) NOT NULL UNIQUE,
            email VARCHAR(120) NULL UNIQUE,
            senha_hash VARCHAR(255) NOT NULL,
            papel ENUM('admin','usuario') NOT NULL DEFAULT 'usuario',
            sessao_versao INT UNSIGNED NOT NULL DEFAULT 0,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ultimo_login TIMESTAMP NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS lembrar_tokens (
            seletor CHAR(24) PRIMARY KEY,
            validador_hash CHAR(64) NOT NULL,
            usuario VARCHAR(30) NOT NULL,
            expira_em DATETIME NOT NULL,
            INDEX idx_lembrar_usuario (usuario)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $feito = true;
    garantir_schema_v2();
    auth_migrar_admin_legado();
}

// O admin do config.php vira uma conta no banco (a senha pode ser trocada no painel).
// Se o hash do config.php mudar depois, ele passa a valer de novo: é a forma de
// recuperar o acesso caso a senha seja esquecida.
function auth_migrar_admin_legado(): void
{
    if (!defined('WEB_LOGIN_USUARIO') || !defined('WEB_LOGIN_SENHA_HASH')) {
        return;
    }
    $hash = (string) WEB_LOGIN_SENHA_HASH;
    $usuario = mb_substr(trim((string) WEB_LOGIN_USUARIO), 0, 30);
    if ($usuario === '' || !preg_match('/^\$(2[aby]|argon2i|argon2id)\$/', $hash)) {
        return;
    }
    try {
        $stmt = db()->prepare('SELECT id FROM usuarios WHERE usuario = ?');
        $stmt->execute([$usuario]);
        $id = $stmt->fetchColumn();
        $registrado = ler_chave('admin_config_hash');
        if ($id === false) {
            db()->prepare("INSERT INTO usuarios (usuario, email, senha_hash, papel) VALUES (?, NULL, ?, 'admin')")->execute([$usuario, $hash]);
        } elseif ($registrado !== $hash) {
            db()->prepare("UPDATE usuarios SET senha_hash = ?, papel = 'admin', sessao_versao = sessao_versao + 1 WHERE id = ?")->execute([$hash, $id]);
            db()->prepare('DELETE FROM lembrar_tokens WHERE usuario = ?')->execute([$usuario]);
        } else {
            return;
        }
        salvar_configuracao('admin_config_hash', $hash);
    } catch (Throwable $e) {
        error_log('auth_migrar_admin_legado: ' . $e->getMessage()); // migração 005 ainda não aplicada
    }
}

// ---------------- Sessão ----------------

function auth_iniciar_sessao_usuario(string $usuario, ?int $id, string $papel, int $versao = 0): void
{
    iniciar_sessao();
    session_regenerate_id(true);
    $_SESSION['logado'] = true;
    $_SESSION['usuario'] = $usuario;
    $_SESSION['usuario_id'] = $id;
    $_SESSION['papel'] = $papel;
    $_SESSION['sessao_versao'] = $versao;
    if ($id !== null) {
        db()->prepare('UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?')->execute([$id]);
    }
}

function auth_eh_admin(): bool
{
    return sessao_logada() && ($_SESSION['papel'] ?? '') === 'admin';
}

// Usuário de config.php (sem registro no banco).
function auth_eh_admin_legado(string $usuario): bool
{
    return defined('WEB_LOGIN_USUARIO') && $usuario === WEB_LOGIN_USUARIO;
}

// Valida usuário (ou e-mail) e senha. Retorna [usuario, id, papel, sessao_versao] ou null.
function auth_verificar_credenciais(string $login, string $senha): ?array
{
    garantir_tabelas_auth();
    $login = trim($login);
    if ($login === '') {
        return null;
    }

    $stmt = db()->prepare('SELECT id, usuario, senha_hash, papel, sessao_versao FROM usuarios WHERE usuario = ? OR usuario = ? OR email = ? LIMIT 1');
    $stmt->execute([$login, mb_strtolower($login), mb_strtolower($login)]);
    $u = $stmt->fetch();
    // Reserva: admin do config.php ainda sem conta no banco (ex.: migração 005 pendente).
    if (!$u && auth_eh_admin_legado($login) && defined('WEB_LOGIN_SENHA_HASH') && password_verify($senha, (string) WEB_LOGIN_SENHA_HASH)) {
        return [$login, null, 'admin', 0];
    }
    if ($u && password_verify($senha, $u['senha_hash'])) {
        if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
            db()->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $u['id']]);
        }
        return [$u['usuario'], (int) $u['id'], $u['papel'], (int) $u['sessao_versao']];
    }
    if (!$u) {
        password_verify($senha, '$2y$10$pWsoxtjSKsgm.ddbdLtrzeIZhBAMFUZkpZUy0fW4rxf8FOW7ZEh6O'); // mesmo tempo de resposta
    }
    return null;
}

// Sessão aberta antes de uma troca de senha (ou de uma conta removida) é encerrada.
function auth_validar_sessao(): void
{
    if (empty($_SESSION['logado']) || empty($_SESSION['usuario'])) {
        return;
    }
    $stmt = db()->prepare('SELECT id, papel, sessao_versao FROM usuarios WHERE usuario = ?');
    $stmt->execute([$_SESSION['usuario']]);
    $u = $stmt->fetch();
    if (!$u && auth_eh_admin_legado((string) $_SESSION['usuario'])) {
        return; // admin do config.php ainda sem conta no banco
    }
    if (!$u || (int) $u['sessao_versao'] !== (int) ($_SESSION['sessao_versao'] ?? 0)) {
        $_SESSION = [];
        session_regenerate_id(true);
        return;
    }
    $_SESSION['usuario_id'] = (int) $u['id']; // sessões antigas do admin do config.php
    $_SESSION['papel'] = $u['papel'];
}

// Troca de senha: invalida as outras sessões e os "lembrar de mim" do usuário.
// A sessão atual continua (com um novo id e, se tinha, um novo "lembrar de mim").
function auth_senha_trocada(string $usuario, bool $sessaoAtual): void
{
    db()->prepare('UPDATE usuarios SET sessao_versao = sessao_versao + 1 WHERE usuario = ?')->execute([$usuario]);
    auth_encerrar_tokens_do_usuario($usuario);
    if ($sessaoAtual) {
        $stmt = db()->prepare('SELECT sessao_versao FROM usuarios WHERE usuario = ?');
        $stmt->execute([$usuario]);
        $_SESSION['sessao_versao'] = (int) $stmt->fetchColumn();
        session_regenerate_id(true);
        if (!empty($_COOKIE[LEMBRAR_COOKIE])) {
            auth_lembrar($usuario);
        }
    }
}

// ---------------- Limite de tentativas ----------------
// Erros ficam em login_falhas: no máximo 5 por IP + usuário (e 20 por IP) em 15 min.

function auth_bloqueio_segundos(string $ip, string $chave): int
{
    try {
        $janela = 'NOW() - INTERVAL ' . LIMITE_JANELA_MIN . ' MINUTE';
        $porChave = db()->prepare("SELECT criado_em FROM login_falhas WHERE ip = ? AND chave = ? AND criado_em > $janela ORDER BY criado_em DESC LIMIT " . LIMITE_TENTATIVAS);
        $porChave->execute([$ip, mb_strtolower($chave)]);
        $lista = $porChave->fetchAll(PDO::FETCH_COLUMN);
        $porIp = db()->prepare("SELECT criado_em FROM login_falhas WHERE ip = ? AND criado_em > $janela ORDER BY criado_em DESC LIMIT " . LIMITE_TENTATIVAS_IP);
        $porIp->execute([$ip]);
        $listaIp = $porIp->fetchAll(PDO::FETCH_COLUMN);
        $restante = 0;
        foreach ([[$lista, LIMITE_TENTATIVAS], [$listaIp, LIMITE_TENTATIVAS_IP]] as [$l, $max]) {
            if (count($l) >= $max) {
                $s = db()->prepare('SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), ? + INTERVAL ' . LIMITE_JANELA_MIN . ' MINUTE))');
                $s->execute([end($l)]); // a mais antiga das que contam
                $restante = max($restante, (int) $s->fetchColumn());
            }
        }
        return $restante;
    } catch (Throwable $e) {
        error_log('auth_bloqueio_segundos: ' . $e->getMessage());
        return 0;
    }
}

function auth_registrar_falha(string $ip, string $chave): void
{
    try {
        db()->prepare('INSERT INTO login_falhas (ip, chave) VALUES (?, ?)')->execute([$ip, mb_substr(mb_strtolower($chave), 0, 140)]);
        if (random_int(1, 20) === 1) {
            db()->exec('DELETE FROM login_falhas WHERE criado_em < NOW() - INTERVAL 1 DAY');
        }
    } catch (Throwable $e) {
        error_log('auth_registrar_falha: ' . $e->getMessage());
    }
}

function auth_limpar_falhas(string $ip, string $chave): void
{
    try {
        db()->prepare('DELETE FROM login_falhas WHERE ip = ? AND chave = ?')->execute([$ip, mb_strtolower($chave)]);
    } catch (Throwable $e) {
        error_log('auth_limpar_falhas: ' . $e->getMessage());
    }
}

function auth_msg_bloqueio(int $segundos): string
{
    return 'Muitas tentativas erradas. Tente novamente em ' . max(1, (int) ceil($segundos / 60)) . ' min.';
}

// ---------------- Lembrar de mim ----------------
// Cookie "seletor:validador"; no banco fica só o hash do validador.

function auth_cookie_opcoes(int $expira): array
{
    return ['expires' => $expira, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'];
}

function auth_lembrar(string $usuario): void
{
    garantir_tabelas_auth();
    $seletor = bin2hex(random_bytes(12));
    $validador = bin2hex(random_bytes(32));
    $expira = time() + LEMBRAR_DIAS * 86400;
    db()->prepare('INSERT INTO lembrar_tokens (seletor, validador_hash, usuario, expira_em) VALUES (?, ?, ?, FROM_UNIXTIME(?))')
        ->execute([$seletor, hash('sha256', $validador), $usuario, $expira]);
    setcookie(LEMBRAR_COOKIE, $seletor . ':' . $validador, auth_cookie_opcoes($expira));
}

function auth_esquecer_cookie(): void
{
    $valor = $_COOKIE[LEMBRAR_COOKIE] ?? '';
    if (strpos($valor, ':') !== false) {
        [$seletor] = explode(':', $valor, 2);
        try {
            garantir_tabelas_auth();
            db()->prepare('DELETE FROM lembrar_tokens WHERE seletor = ?')->execute([$seletor]);
        } catch (Throwable $e) {
            error_log('auth_esquecer_cookie: ' . $e->getMessage());
        }
    }
    setcookie(LEMBRAR_COOKIE, '', auth_cookie_opcoes(time() - 3600));
}

// Sem sessão ativa: entra pelo cookie "lembrar de mim" (e troca o token).
function auth_restaurar_sessao(): void
{
    if (sessao_logada()) {
        try {
            garantir_tabelas_auth();
            auth_validar_sessao();
        } catch (Throwable $e) {
            error_log('auth_validar_sessao: ' . $e->getMessage());
        }
    }
    if (sessao_logada() || empty($_COOKIE[LEMBRAR_COOKIE])) {
        return;
    }
    $partes = explode(':', (string) $_COOKIE[LEMBRAR_COOKIE], 2);
    if (count($partes) !== 2 || !ctype_xdigit($partes[0]) || strlen($partes[0]) !== 24) {
        auth_esquecer_cookie();
        return;
    }
    [$seletor, $validador] = $partes;
    try {
        garantir_tabelas_auth();
        $stmt = db()->prepare('SELECT validador_hash, usuario, expira_em > NOW() AS valido FROM lembrar_tokens WHERE seletor = ?');
        $stmt->execute([$seletor]);
        $t = $stmt->fetch();
        db()->prepare('DELETE FROM lembrar_tokens WHERE seletor = ? OR expira_em < NOW()')->execute([$seletor]);
        if (!$t || !$t['valido'] || !hash_equals($t['validador_hash'], hash('sha256', $validador))) {
            setcookie(LEMBRAR_COOKIE, '', auth_cookie_opcoes(time() - 3600));
            return;
        }
        $usuario = $t['usuario'];
        $s = db()->prepare('SELECT id, papel, sessao_versao FROM usuarios WHERE usuario = ?');
        $s->execute([$usuario]);
        $u = $s->fetch();
        if (!$u) {
            setcookie(LEMBRAR_COOKIE, '', auth_cookie_opcoes(time() - 3600));
            return;
        }
        auth_iniciar_sessao_usuario($usuario, (int) $u['id'], $u['papel'], (int) $u['sessao_versao']);
        auth_lembrar($usuario);
    } catch (Throwable $e) {
        error_log('auth_restaurar_sessao: ' . $e->getMessage());
    }
}

function auth_encerrar_tokens_do_usuario(string $usuario): void
{
    garantir_tabelas_auth();
    db()->prepare('DELETE FROM lembrar_tokens WHERE usuario = ?')->execute([$usuario]);
}

// ---------------- Código de convite ----------------
// CADASTRO_CODIGO_CONVITE no config.php tem prioridade; senão vale o código salvo
// pelo admin em Configurações. Sem nenhum dos dois, o cadastro fica desativado.

function auth_codigo_convite(): ?string
{
    if (defined('CADASTRO_CODIGO_CONVITE') && CADASTRO_CODIGO_CONVITE !== '') {
        return (string) CADASTRO_CODIGO_CONVITE;
    }
    try {
        $stmt = db()->prepare("SELECT valor FROM configuracoes WHERE chave = 'cadastro_codigo_convite'");
        $stmt->execute();
        $v = $stmt->fetchColumn();
        return ($v !== false && $v !== '') ? (string) $v : null;
    } catch (Throwable $e) {
        return null;
    }
}

function auth_convite_no_config(): bool
{
    return defined('CADASTRO_CODIGO_CONVITE') && CADASTRO_CODIGO_CONVITE !== '';
}

// ---------------- Validação ----------------

// Mínimo 8 caracteres com letras e números.
function auth_senha_valida(string $senha): bool
{
    return mb_strlen($senha) >= 8 && preg_match('/\p{L}/u', $senha) && preg_match('/\d/', $senha);
}

function auth_gerar_senha_temporaria(): string
{
    $letras = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';
    $s = '';
    for ($i = 0; $i < 8; $i++) {
        $s .= $letras[random_int(0, strlen($letras) - 1)];
    }
    return $s . random_int(10, 99);
}

// Cria a conta. Retorna lista de erros (vazia = sucesso).
function auth_cadastrar(string $usuario, string $email, string $senha, string $confirma): array
{
    garantir_tabelas_auth();
    $erros = [];
    $usuario = mb_strtolower(trim($usuario));
    $email = mb_strtolower(trim($email));

    if (!preg_match(USUARIO_REGEX, $usuario)) {
        $erros[] = 'O nome de usuário deve ter de 3 a 30 caracteres: letras minúsculas, números, ponto, hífen ou sublinhado.';
    } elseif (auth_eh_admin_legado($usuario)) {
        $erros[] = 'Este nome de usuário já está em uso.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) {
        $erros[] = 'Informe um e-mail válido.';
    }
    if (!auth_senha_valida($senha)) {
        $erros[] = 'A senha precisa ter pelo menos 8 caracteres, com letras e números.';
    }
    if ($senha !== $confirma) {
        $erros[] = 'As senhas não coincidem.';
    }

    $stmt = db()->prepare('SELECT usuario, email FROM usuarios WHERE usuario = ? OR email = ?');
    $stmt->execute([$usuario, $email]);
    foreach ($stmt->fetchAll() as $existente) {
        if ($existente['usuario'] === $usuario && !in_array('Este nome de usuário já está em uso.', $erros, true)) {
            $erros[] = 'Este nome de usuário já está em uso.';
        }
        if ($existente['email'] === $email) {
            $erros[] = 'Este e-mail já está cadastrado.';
        }
    }

    if ($erros) {
        return $erros;
    }

    try {
        db()->prepare('INSERT INTO usuarios (usuario, email, senha_hash) VALUES (?, ?, ?)')
            ->execute([$usuario, $email, password_hash($senha, PASSWORD_DEFAULT)]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['Este nome de usuário ou e-mail já está cadastrado.'];
        }
        throw $e;
    }
    return [];
}

// ---------------- CSRF (formulários HTML) ----------------

function csrf_token(): string
{
    iniciar_sessao();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_valido(?string $token): bool
{
    iniciar_sessao();
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

// Toda página/endpoint que carrega este arquivo confere se a sessão ainda vale.
if (PHP_SAPI !== 'cli' && isset($_COOKIE[session_name()])) {
    try {
        iniciar_sessao();
        if (!empty($_SESSION['logado'])) {
            garantir_tabelas_auth();
            auth_validar_sessao();
        }
    } catch (Throwable $e) {
        error_log('auth_validar_sessao: ' . $e->getMessage());
    }
}
