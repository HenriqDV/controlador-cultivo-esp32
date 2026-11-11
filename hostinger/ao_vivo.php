<?php
declare(strict_types=1);

require __DIR__ . '/api_comum.php';

// Atualização leve do painel (sessão), chamada a cada poucos segundos.
// Responde em poucas centenas de bytes: status do ESP32, a leitura mais recente, o
// estado atual (enviado pelo ESP32 a cada 5 s) e os ids mais recentes de leituras e eventos. O painel só busca mais (dados.php?desde=,
// comandos.php) quando um desses ids muda.
require_login_api();
$temColunaVpd = garantir_schema_v2();

try {
    $colunaVpd = $temColunaVpd ? 'COALESCE(vpd, ' . SQL_FORMULA_VPD . ')' : SQL_FORMULA_VPD;
    $leitura = db()->query(
        "SELECT id, criado_em, dispositivo_timestamp, temp_interna, umid_interna, temp_externa, umid_externa,
                solo_percentual, ROUND($colunaVpd, 2) AS vpd, agua_distancia_cm, agua_litros, agua_percentual
         FROM leituras_ambiente ORDER BY id DESC LIMIT 1"
    )->fetch() ?: null;
    $ultimoEvento = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM comandos_sistema')->fetchColumn();

    // Estado atual enviado pelo ESP32 a cada 5 s (firmware 2.8.0+). Sem a tabela ou sem envio: null.
    $estado = null;
    try {
        $linha = db()->query(
            'SELECT dados, TIMESTAMPDIFF(SECOND, recebido_em, NOW()) AS segundos FROM estado_atual WHERE id = 1'
        )->fetch();
        $dados = $linha ? json_decode((string) $linha['dados'], true) : null;
        if (is_array($dados)) {
            $estado = ['dados' => $dados, 'segundos' => max(0, (int) $linha['segundos'])];
        }
    } catch (Throwable $e) {
        $estado = null; // migração 009 ainda não aplicada
    }

    responder([
        'ok' => true,
        'status' => dados_status(),
        'leitura' => $leitura,
        'ultimo_id_leitura' => $leitura ? (int) $leitura['id'] : 0,
        'ultimo_id_evento' => $ultimoEvento,
        'estado' => $estado,
    ]);
} catch (Throwable $e) {
    responder(['ok' => false, 'erro' => 'falha_ao_vivo', 'detalhe' => $e->getMessage()], 500);
}
