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
    require __DIR__ . '/telegram.php';
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

exigir_dispositivo(); // token do hardware ou API_KEY (modo legado)
$data = read_json_body();

try {
    $tempInterna = nullable_float($data, 'temp_interna');
    $umidInterna = nullable_float($data, 'umid_interna');
    $valores = [
        nullable_datetime($data, 'timestamp'),
        $tempInterna,
        $umidInterna,
        nullable_float($data, 'temp_externa'),
        nullable_float($data, 'umid_externa'),
        nullable_int($data, 'solo_percentual'),
    ];

    if (garantir_schema_v2()) {
        $stmt = db()->prepare(
            'INSERT INTO leituras_ambiente (
                dispositivo_timestamp,
                temp_interna,
                umid_interna,
                temp_externa,
                umid_externa,
                solo_percentual,
                vpd
            ) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $valores[] = calcular_vpd($tempInterna, $umidInterna);
    } else {
        // Sem a coluna vpd: grava sem ela.
        $stmt = db()->prepare(
            'INSERT INTO leituras_ambiente (
                dispositivo_timestamp,
                temp_interna,
                umid_interna,
                temp_externa,
                umid_externa,
                solo_percentual
            ) VALUES (?, ?, ?, ?, ?, ?)'
        );
    }
    $stmt->execute($valores);

    try {
        registrar_contato_dispositivo($data);
    } catch (Throwable $e) {
        error_log('registrar_contato_dispositivo: ' . $e->getMessage());
    }

    try {
        verificar_sensor_solo($valores[0]);
    } catch (Throwable $e) {
        error_log('verificar_sensor_solo: ' . $e->getMessage());
    }

    try {
        if (telegram_configurado()) {
            avaliar_offline();        // avisa "voltou a enviar" na hora
            avaliar_faixas_leitura(); // leitura fora da faixa
        }
    } catch (Throwable $e) {
        error_log('notificacoes: ' . $e->getMessage());
    }

    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'erro' => 'falha_ao_gravar_leitura',
        'detalhe' => $e->getMessage(),
    ]);
}
