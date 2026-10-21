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

// Status do dispositivo (sessão): online/offline pela última leitura e dados do ESP32.
// Os tempos são calculados no servidor para não depender do relógio do navegador.
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

try {
    $config = ler_configuracoes();
    $limiteMin = in_array($config['dispositivo_sem_dados_min'], ['2', '5', '10', '30'], true) ? (int) $config['dispositivo_sem_dados_min'] : 5;

    $ultima = db()->query(
        'SELECT criado_em, dispositivo_timestamp, TIMESTAMPDIFF(SECOND, criado_em, NOW()) AS segundos
         FROM leituras_ambiente ORDER BY id DESC LIMIT 1'
    )->fetch();
    $leituras24h = (int) db()->query('SELECT COUNT(*) FROM leituras_ambiente WHERE criado_em >= NOW() - INTERVAL 24 HOUR')->fetchColumn();

    $disp = null;
    try {
        $disp = db()->query(
            'SELECT nome, modelo, mac, firmware_versao, wifi_rssi, uptime_seg, ultimo_contato,
                    TIMESTAMPDIFF(SECOND, ultimo_contato, NOW()) AS segundos_desde_contato
             FROM dispositivos WHERE id = 1'
        )->fetch() ?: null;
    } catch (Throwable $e) {
        $disp = null; // migração 002 ainda não aplicada
    }

    $segundos = $ultima ? max(0, (int) $ultima['segundos']) : null;
    $avisoOffline = null;
    if ($ultima) {
        $stmt = db()->prepare("SELECT dispositivo_timestamp FROM comandos_sistema WHERE tipo = 'notificacao' AND alvo = 'offline' AND valor = 'enviada' AND criado_em >= ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$ultima['criado_em']]);
        $avisoOffline = $stmt->fetchColumn() ?: null;
    }
    responder([
        'ok' => true,
        'limite_min' => $limiteMin,
        'offline' => $segundos === null || $segundos > $limiteMin * 60,
        'segundos_sem_dados' => $segundos,
        'ultima_leitura' => $ultima ? ($ultima['dispositivo_timestamp'] ?: $ultima['criado_em']) : null,
        'leituras_24h' => $leituras24h,
        'aviso_offline_em' => $avisoOffline,
        'dispositivo' => $disp,
    ]);
} catch (Throwable $e) {
    responder(['ok' => false, 'erro' => 'falha_status', 'detalhe' => $e->getMessage()], 500);
}
