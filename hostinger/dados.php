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

// Periodo do grafico: 24h usa leituras cruas (1/min); 7d e 30d agregam por
// hora no proprio SQL (senao 30 dias a 1 leitura/min viraria ~43 mil linhas
// por resposta - pesado demais pro navegador e sem ganho nenhum visual).
$periodosValidos = [
    '24h' => ['horas' => 24, 'agrupar' => false],
    '7d' => ['horas' => 24 * 7, 'agrupar' => true],
    '30d' => ['horas' => 24 * 30, 'agrupar' => true],
];
$periodo = isset($_GET['periodo']) && isset($periodosValidos[$_GET['periodo']]) ? $_GET['periodo'] : '24h';
$config = $periodosValidos[$periodo];

try {
    if ($config['agrupar']) {
        // Agrupa pela hora do DISPOSITIVO (dispositivo_timestamp, ja no fuso
        // certo - GMT-3 - porque o firmware grava assim), nao pela hora que o
        // servidor da Hostinger recebeu o dado (criado_em, que pode estar em
        // outro fuso). Se por algum motivo uma leitura antiga nao tiver
        // dispositivo_timestamp (RTC ainda nao sincronizado no boot), cai de
        // volta pro criado_em so pra nao sumir da agregacao.
        $stmt = db()->prepare(
            "SELECT
                DATE_FORMAT(COALESCE(dispositivo_timestamp, criado_em), '%Y-%m-%d %H:00:00') AS criado_em,
                DATE_FORMAT(COALESCE(dispositivo_timestamp, criado_em), '%Y-%m-%d %H:00:00') AS dispositivo_timestamp,
                AVG(temp_interna) AS temp_interna,
                AVG(umid_interna) AS umid_interna,
                AVG(temp_externa) AS temp_externa,
                AVG(umid_externa) AS umid_externa,
                AVG(solo_percentual) AS solo_percentual
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
            'SELECT
                criado_em,
                dispositivo_timestamp,
                temp_interna,
                umid_interna,
                temp_externa,
                umid_externa,
                solo_percentual
             FROM leituras_ambiente
             WHERE criado_em >= NOW() - INTERVAL ? HOUR
             ORDER BY criado_em ASC'
        );
        $stmt->bindValue(1, $config['horas'], PDO::PARAM_INT);
        $stmt->execute();
        $linhas = $stmt->fetchAll();
    }

    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode(['ok' => true, 'periodo' => $periodo, 'dados' => $linhas]);
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
