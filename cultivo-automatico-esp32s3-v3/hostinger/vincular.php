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
    require __DIR__ . '/auth.php'; // limite de tentativas
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

// Vinculação do ESP32 (sem X-API-Key): troca o token de vinculação, de uso único e
// válido por 30 min, pelo token definitivo do hardware.
//   POST {codigo, mac?, modelo?, firmware?} -> {ok, token}
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(['ok' => false, 'erro' => 'metodo_invalido'], 405);
}

try {
    $ip = ip_cliente();
    $bloqueio = auth_bloqueio_segundos($ip, '#vinculo');
    if ($bloqueio > 0) {
        responder(['ok' => false, 'erro' => 'bloqueado', 'tentar_em_seg' => $bloqueio], 429);
    }
    $data = read_json_body();
    $codigo = normalizar_codigo_vinculo((string) ($data['codigo'] ?? ''));
    $d = db()->query('SELECT vinculo_hash, vinculo_expira FROM dispositivos WHERE id = 1')->fetch();
    $valido = $codigo !== '' && $d && $d['vinculo_hash']
        && $d['vinculo_expira'] > agora_local()->format('Y-m-d H:i:s')
        && hash_equals($d['vinculo_hash'], hash('sha256', $codigo));
    if (!$valido) {
        auth_registrar_falha($ip, '#vinculo');
        responder(['ok' => false, 'erro' => 'codigo_invalido', 'detalhe' => 'Token de vinculação inválido ou expirado.'], 403);
    }
    $token = gerar_token_hardware('esp32', 'vinculado');
    auth_limpar_falhas($ip, '#vinculo');
    registrar_contato_dispositivo($data);
    responder(['ok' => true, 'token' => $token]);
} catch (Throwable $e) {
    responder(['ok' => false, 'erro' => 'falha_vinculo', 'detalhe' => $e->getMessage()], 500);
}
