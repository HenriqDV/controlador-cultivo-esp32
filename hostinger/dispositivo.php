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

// Token do hardware e vinculação (sessão; alterações só para administradores).
//   GET                             -> { token, atividade }
//   POST {acao:'gerar'}             -> { token_completo } (aparece só nesta resposta)
//   POST {acao:'revogar'}
//   POST {acao:'vincular'}          -> { codigo } válido por 30 min, uso único
//   POST {acao:'cancelar_vinculo'}
require_login_api();
garantir_schema_v2();

function responder(array $dados, int $status = 200): void
{
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($status);
    echo json_encode($dados);
    exit;
}

function estado_dispositivo(): array
{
    return ['ok' => true, 'admin' => auth_eh_admin(), 'token' => dispositivo_token_info(), 'atividade' => atividade_dispositivo(4)];
}

$usuario = (string) ($_SESSION['usuario'] ?? 'painel');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!auth_eh_admin()) {
            responder(['ok' => false, 'erro' => 'somente_admin', 'detalhe' => 'Só administradores podem mudar o token do dispositivo.'], 403);
        }
        $acao = (string) (read_json_body()['acao'] ?? '');

        if ($acao === 'gerar') {
            $token = gerar_token_hardware($usuario);
            responder(estado_dispositivo() + ['token_completo' => $token]);
        }
        if ($acao === 'revogar') {
            db()->prepare("UPDATE dispositivos SET token_estado = 'revogado', token_hash = NULL, vinculo_hash = NULL, vinculo_expira = NULL WHERE id = 1")->execute();
            registrar_evento_painel('dispositivo', 'token', 'token_revogado', $usuario);
            responder(estado_dispositivo());
        }
        if ($acao === 'vincular') {
            $codigo = codigo_aleatorio(8);
            $expira = agora_local()->modify('+' . VINCULO_DISPOSITIVO_MIN . ' minutes');
            db()->prepare('UPDATE dispositivos SET vinculo_hash = ?, vinculo_expira = ? WHERE id = 1')
                ->execute([hash('sha256', $codigo), $expira->format('Y-m-d H:i:s')]);
            registrar_evento_painel('dispositivo', 'token', 'vinculo_gerado', $usuario);
            responder(estado_dispositivo() + ['codigo' => substr($codigo, 0, 4) . '-' . substr($codigo, 4)]);
        }
        if ($acao === 'cancelar_vinculo') {
            db()->prepare('UPDATE dispositivos SET vinculo_hash = NULL, vinculo_expira = NULL WHERE id = 1')->execute();
            responder(estado_dispositivo());
        }
        responder(['ok' => false, 'erro' => 'acao_invalida'], 422);
    }
    responder(estado_dispositivo());
} catch (Throwable $e) {
    responder(['ok' => false, 'erro' => 'falha_dispositivo', 'detalhe' => $e->getMessage()], 500);
}
