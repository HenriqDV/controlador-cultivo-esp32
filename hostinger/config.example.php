<?php
declare(strict_types=1);

// Modelo: copie para config.php (fora do git) e preencha.

// Mesma chave de SECRET_HOSTINGER_API_KEY no firmware
const API_KEY = 'SUA_API_KEY_AQUI';

const DB_HOST = 'localhost';
const DB_NAME = 'SEU_BANCO';
const DB_USER = 'SEU_USUARIO';
const DB_PASS = 'SUA_SENHA_DE_BANCO';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function require_api_key(): void
{
    $header = $_SERVER['HTTP_X_API_KEY'] ?? '';

    if (!hash_equals(API_KEY, $header)) {
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code(401);
        echo json_encode(['ok' => false, 'erro' => 'unauthorized']);
        exit;
    }
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);

    if (!is_array($data)) {
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code(400);
        echo json_encode(['ok' => false, 'erro' => 'json_invalido']);
        exit;
    }

    return $data;
}

function nullable_float(array $data, string $key): ?float
{
    return isset($data[$key]) && is_numeric($data[$key]) ? (float) $data[$key] : null;
}

function nullable_int(array $data, string $key): ?int
{
    return isset($data[$key]) && is_numeric($data[$key]) ? (int) $data[$key] : null;
}

function nullable_datetime(array $data, string $key): ?string
{
    if (empty($data[$key]) || !is_string($data[$key])) {
        return null;
    }

    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $data[$key]);
    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}

// ---------------- LOGIN DO DASHBOARD ----------------
// Administrador principal. No primeiro acesso ele vira uma conta no banco e a senha
// pode ser trocada no painel; para recuperar o acesso, cole aqui um hash novo.
// O limite de tentativas de login fica no auth.php. Gere o hash com:
//   php -r "echo password_hash('SUA_SENHA', PASSWORD_DEFAULT), PHP_EOL;"
const WEB_LOGIN_USUARIO = 'admin';
const WEB_LOGIN_SENHA_HASH = 'COLE_AQUI_O_HASH_GERADO_PELO_COMANDO_ACIMA';

// Opcional: código de convite fixo para o cadastro. Sem esta linha, o admin define
// o código em Configurações → Usuários (sem código, o cadastro fica desativado).
// const CADASTRO_CODIGO_CONVITE = 'TROQUE-ESTE-CODIGO';

// ---------------- TELEGRAM (opcional) ----------------
// Token do bot criado no @BotFather. Também pode vir da variável de ambiente
// TELEGRAM_BOT_TOKEN. Sem token, a seção Notificações fica desativada.
// const TELEGRAM_BOT_TOKEN = '123456789:AAAA...';

function ip_cliente(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// Chamar antes de usar $_SESSION.
function iniciar_sessao(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,     // a Hostinger serve por HTTPS
            'httponly' => true,   // JS não precisa (nem deve) ler o cookie de sessão
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function sessao_logada(): bool
{
    iniciar_sessao();
    return !empty($_SESSION['logado']) && !empty($_SESSION['usuario']);
}

// Páginas HTML: redireciona para o login.
function require_login_html(): void
{
    if (!sessao_logada()) {
        header('Location: login.php');
        exit;
    }
}

// Endpoints JSON (fetch): responde 401 em vez de redirecionar.
function require_login_api(): void
{
    if (!sessao_logada()) {
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'erro' => 'nao_autenticado']);
        exit;
    }
}
