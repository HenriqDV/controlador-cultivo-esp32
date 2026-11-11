<?php
declare(strict_types=1);

require __DIR__ . '/api_comum.php';

// Leituras para os gráficos do painel (sessão).
// Exige login: antes qualquer pessoa com o endereço via estes dados.
require_login_api();

// 24h: leituras cruas. 7d/30d: médias por hora.
$periodosValidos = [
    '24h' => ['horas' => 24, 'agrupar' => false],
    '7d' => ['horas' => 24 * 7, 'agrupar' => true],
    '30d' => ['horas' => 24 * 30, 'agrupar' => true],
];
$periodo = isset($_GET['periodo']) && isset($periodosValidos[$_GET['periodo']]) ? $_GET['periodo'] : '24h';
$config = $periodosValidos[$periodo];
// Só no 24h: ?desde=<id> devolve apenas as leituras novas (o painel acrescenta ao que já tem).
$desde = (!$config['agrupar'] && isset($_GET['desde'])) ? max(0, (int) $_GET['desde']) : 0;

// VPD gravado ou, em leituras antigas, calculado na hora.
$colunaVpd = garantir_schema_v2()
    ? 'COALESCE(vpd, ' . SQL_FORMULA_VPD . ')'
    : SQL_FORMULA_VPD;

try {
    if ($config['agrupar']) {
        // Agrupa pela hora do dispositivo (GMT-3); usa criado_em se faltar.
        $stmt = db()->prepare(
            "SELECT
                DATE_FORMAT(COALESCE(dispositivo_timestamp, criado_em), '%Y-%m-%d %H:00:00') AS criado_em,
                DATE_FORMAT(COALESCE(dispositivo_timestamp, criado_em), '%Y-%m-%d %H:00:00') AS dispositivo_timestamp,
                AVG(temp_interna) AS temp_interna,
                AVG(umid_interna) AS umid_interna,
                AVG(temp_externa) AS temp_externa,
                AVG(umid_externa) AS umid_externa,
                AVG(solo_percentual) AS solo_percentual,
                AVG($colunaVpd) AS vpd,
                AVG(agua_litros) AS agua_litros,
                AVG(agua_percentual) AS agua_percentual
             FROM leituras_ambiente
             WHERE criado_em >= NOW() - INTERVAL ? HOUR
             GROUP BY DATE_FORMAT(COALESCE(dispositivo_timestamp, criado_em), '%Y-%m-%d %H:00:00')
             ORDER BY dispositivo_timestamp ASC"
        );
        $stmt->bindValue(1, $config['horas'], PDO::PARAM_INT);
        $stmt->execute();
        $linhas = $stmt->fetchAll();
    } else {
        $stmt = db()->prepare(
            "SELECT
                id,
                criado_em,
                dispositivo_timestamp,
                temp_interna,
                umid_interna,
                temp_externa,
                umid_externa,
                solo_percentual,
                ROUND($colunaVpd, 2) AS vpd,
                agua_distancia_cm,
                agua_litros,
                agua_percentual
             FROM leituras_ambiente
             WHERE criado_em >= NOW() - INTERVAL ? HOUR AND id > ?
             ORDER BY id ASC"
        );
        $stmt->bindValue(1, $config['horas'], PDO::PARAM_INT);
        $stmt->bindValue(2, $desde, PDO::PARAM_INT);
        $stmt->execute();
        $linhas = $stmt->fetchAll();
    }

    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode(['ok' => true, 'periodo' => $periodo, 'desde' => $desde, 'dados' => $linhas]);
} catch (Throwable $e) {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'erro' => 'falha_ao_consultar_leituras',
        'detalhe' => $e->getMessage(),
    ]);
}
