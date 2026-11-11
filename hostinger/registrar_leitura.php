<?php
declare(strict_types=1);

require __DIR__ . '/api_comum.php';
require_once __DIR__ . '/telegram.php';

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
        // Caixa d'água (firmware 2.7.0+); firmwares antigos não mandam e ficam null.
        $stmt = db()->prepare(
            'INSERT INTO leituras_ambiente (
                dispositivo_timestamp,
                temp_interna,
                umid_interna,
                temp_externa,
                umid_externa,
                solo_percentual,
                vpd,
                agua_distancia_cm,
                agua_litros,
                agua_percentual
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $valores[] = calcular_vpd($tempInterna, $umidInterna);
        $valores[] = nullable_float($data, 'agua_distancia_cm');
        $valores[] = nullable_float($data, 'agua_litros');
        $valores[] = nullable_int($data, 'agua_percentual');
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
