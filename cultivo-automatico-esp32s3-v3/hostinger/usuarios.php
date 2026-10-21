<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

register_shutdown_function(function (): void {
    $erro = error_get_last();
    $fataisTipo = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    if ($erro !== null && in_array($erro['type'], $fataisTipo, true)) {
        if (ob_get_length()) {
            ob_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok' => false,
            'erro' => 'erro_fatal_php',
            'detalhe' => $erro['message'] . ' em ' . basename((string) $erro['file']) . ':' . $erro['line'],
        ]);
    }
});

set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require __DIR__ . '/config.php';
    require __DIR__ . '/migracoes.php';
    require __DIR__ . '/auth.php';
} catch (Throwable $e) {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'erro' => 'falha_ao_carregar_config',
        'detalhe' => $e->getMessage(),
    ]);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// Contas (sessão).
//   GET                               -> { eu, admin, usuarios?, convite? }
//   POST {acao:'alterar_senha', senha_atual, nova_senha}
//   POST {acao:'redefinir_senha', id}            (admin) -> { senha_temporaria }
//   POST {acao:'remover', id}                    (admin)
//   POST {acao:'salvar_convite', codigo}         (admin; vazio desativa o cadastro)
require_login_api();
garantir_schema_v2();
garantir_tabelas_auth();

function responder(array $dados, int $status = 200): void
{
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($status);
    echo json_encode($dados);
    exit;
}

function exigir_admin(): void
{
    if (!auth_eh_admin()) {
        responder(['ok' => false, 'erro' => 'somente_admin'], 403);
    }
}

$usuarioAtual = (string) $_SESSION['usuario'];
$idAtual = $_SESSION['usuario_id'] ?? null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = read_json_body();
        $acao = (string) ($data['acao'] ?? '');

        if ($acao === 'alterar_senha') {
            $chaveLimite = '#senha:' . $usuarioAtual;
            $bloqueio = auth_bloqueio_segundos(ip_cliente(), $chaveLimite);
            if ($bloqueio > 0) {
                responder(['ok' => false, 'erro' => 'bloqueado', 'detalhe' => auth_msg_bloqueio($bloqueio)], 429);
            }
            $stmt = db()->prepare('SELECT senha_hash FROM usuarios WHERE id = ?');
            $stmt->execute([$idAtual]);
            $hash = $stmt->fetchColumn();
            if (!$hash || !password_verify((string) ($data['senha_atual'] ?? ''), $hash)) {
                auth_registrar_falha(ip_cliente(), $chaveLimite);
                responder(['ok' => false, 'erro' => 'senha_atual_incorreta', 'detalhe' => 'A senha atual está incorreta.'], 422);
            }
            $nova = (string) ($data['nova_senha'] ?? '');
            if (!auth_senha_valida($nova)) {
                responder(['ok' => false, 'erro' => 'senha_fraca', 'detalhe' => 'A nova senha precisa ter pelo menos 8 caracteres, com letras e números.'], 422);
            }
            if (password_verify($nova, $hash)) {
                responder(['ok' => false, 'erro' => 'senha_igual', 'detalhe' => 'A nova senha precisa ser diferente da atual.'], 422);
            }
            db()->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([password_hash($nova, PASSWORD_DEFAULT), $idAtual]);
            auth_limpar_falhas(ip_cliente(), $chaveLimite);
            auth_senha_trocada($usuarioAtual, true);
            responder(['ok' => true]);
        }

        exigir_admin();

        if ($acao === 'redefinir_senha' || $acao === 'remover') {
            $id = (int) ($data['id'] ?? 0);
            $stmt = db()->prepare('SELECT usuario FROM usuarios WHERE id = ?');
            $stmt->execute([$id]);
            $alvo = $stmt->fetchColumn();
            if ($alvo === false) {
                responder(['ok' => false, 'erro' => 'usuario_nao_encontrado'], 404);
            }
            if ($idAtual !== null && $id === (int) $idAtual) {
                responder(['ok' => false, 'erro' => 'proprio_usuario', 'detalhe' => 'Use "Alterar senha" para a sua própria conta.'], 422);
            }
            if ($acao === 'remover' && auth_eh_admin_legado((string) $alvo)) {
                responder(['ok' => false, 'erro' => 'admin_principal', 'detalhe' => 'O administrador principal (config.php) não pode ser removido.'], 422);
            }
            auth_senha_trocada((string) $alvo, false);
            if ($acao === 'remover') {
                db()->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
                responder(['ok' => true]);
            }
            $temp = auth_gerar_senha_temporaria();
            db()->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([password_hash($temp, PASSWORD_DEFAULT), $id]);
            responder(['ok' => true, 'usuario' => $alvo, 'senha_temporaria' => $temp]);
        }

        if ($acao === 'salvar_convite') {
            if (auth_convite_no_config()) {
                responder(['ok' => false, 'erro' => 'convite_no_config', 'detalhe' => 'O código está fixo no config.php (CADASTRO_CODIGO_CONVITE).'], 422);
            }
            $codigo = trim((string) ($data['codigo'] ?? ''));
            if ($codigo !== '' && !preg_match('/^[A-Za-z0-9-]{6,40}$/', $codigo)) {
                responder(['ok' => false, 'erro' => 'codigo_invalido', 'detalhe' => 'Use de 6 a 40 letras, números ou hífens.'], 422);
            }
            if ($codigo === '') {
                db()->prepare("DELETE FROM configuracoes WHERE chave = 'cadastro_codigo_convite'")->execute();
            } else {
                salvar_configuracao('cadastro_codigo_convite', $codigo);
            }
            responder(['ok' => true, 'convite' => ['codigo' => $codigo !== '' ? $codigo : null, 'no_config' => false]]);
        }

        responder(['ok' => false, 'erro' => 'acao_invalida'], 422);
    }

    $resposta = [
        'ok' => true,
        'eu' => ['usuario' => $usuarioAtual, 'conta_no_banco' => $idAtual !== null],
        'admin' => auth_eh_admin(),
    ];
    if (auth_eh_admin()) {
        $resposta['usuarios'] = array_map(fn($u) => $u + ['principal' => auth_eh_admin_legado($u['usuario']), 'voce' => $u['usuario'] === $usuarioAtual],
            db()->query('SELECT id, usuario, email, papel, criado_em, ultimo_login FROM usuarios ORDER BY criado_em ASC')->fetchAll());
        $resposta['convite'] = ['codigo' => auth_codigo_convite(), 'no_config' => auth_convite_no_config()];
    }
    responder($resposta);
} catch (Throwable $e) {
    responder(['ok' => false, 'erro' => 'falha_usuarios', 'detalhe' => $e->getMessage()], 500);
}
