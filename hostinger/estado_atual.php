<?php
declare(strict_types=1);

require __DIR__ . '/api_comum.php';

// Estado atual do ESP32 (firmware 2.8.0+), enviado a cada 5 s: leituras, fase, luz e relés.
// Guarda só o último (uma linha), para o painel mostrar ao vivo pelo ao_vivo.php.
// O histórico continua em registrar_leitura.php (1 por minuto).
exigir_dispositivo(); // token do hardware ou API_KEY (modo legado)
$data = read_json_body();

// Só os campos conhecidos, com tipo conferido (o painel exibe esses valores).
$estado = [
    'dispositivo_timestamp' => nullable_datetime($data, 'dispositivo_timestamp'),
    'temp_interna' => nullable_float($data, 'temp_interna'),
    'umid_interna' => nullable_float($data, 'umid_interna'),
    'temp_externa' => nullable_float($data, 'temp_externa'),
    'umid_externa' => nullable_float($data, 'umid_externa'),
    'vpd' => nullable_float($data, 'vpd'),
    'solo_percentual' => nullable_int($data, 'solo_percentual'),
    'agua_distancia_cm' => nullable_float($data, 'agua_distancia_cm'),
    'agua_litros' => nullable_float($data, 'agua_litros'),
    'agua_percentual' => nullable_int($data, 'agua_percentual'),
    'modo_cultivo' => in_array($data['modo_cultivo'] ?? null, ['vegetativo', 'floracao'], true) ? $data['modo_cultivo'] : null,
    'luz_controle' => in_array($data['luz_controle'] ?? null, ['auto', 'ligada', 'desligada'], true) ? $data['luz_controle'] : null,
    'reles' => new stdClass(),
];
if (isset($data['reles']) && is_array($data['reles'])) {
    $reles = [];
    foreach ($data['reles'] as $nome => $ligado) {
        if (is_string($nome) && preg_match('/^[A-Za-z ]{1,30}$/', $nome) && count($reles) < 8) {
            $reles[$nome] = $ligado ? 1 : 0;
        }
    }
    $estado['reles'] = (object) $reles;
}

try {
    db()->prepare(
        'INSERT INTO estado_atual (id, dados, recebido_em) VALUES (1, ?, NOW())
         ON DUPLICATE KEY UPDATE dados = VALUES(dados), recebido_em = NOW()'
    )->execute([json_encode($estado)]);
    responder(['ok' => true]);
} catch (Throwable $e) {
    responder(['ok' => false, 'erro' => 'falha_ao_gravar_estado', 'detalhe' => $e->getMessage()], 500);
}
