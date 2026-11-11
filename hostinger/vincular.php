<?php
declare(strict_types=1);

require __DIR__ . '/api_comum.php';

// Vinculação do ESP32 (sem X-API-Key): troca o token de vinculação, de uso único e
// válido por 30 min, pelo token definitivo do hardware.
//   POST {codigo, mac?, modelo?, firmware?} -> {ok, token}
garantir_schema_v2();

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
