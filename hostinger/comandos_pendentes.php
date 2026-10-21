<?php
declare(strict_types=1);

// ESP32 (X-API-Key): devolve os comandos pendentes e já os marca como entregues.

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

try {
    $pdo = db();
    $pdo->beginTransaction();

    // FOR UPDATE evita entregar o mesmo comando duas vezes em polls simultâneos.
    $stmt = $pdo->prepare(
        "SELECT id, tipo, alvo, valor
         FROM comandos_pendentes
         WHERE status = 'pendente'
         ORDER BY id ASC
         LIMIT 20
         FOR UPDATE"
    );
    $stmt->execute();
    $comandos = $stmt->fetchAll();

    if (count($comandos) > 0) {
        $ids = array_column($comandos, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $upd = $pdo->prepare(
            "UPDATE comandos_pendentes SET status = 'entregue', entregue_em = NOW() WHERE id IN ($placeholders)"
        );
        $upd->execute($ids);
    }

    $pdo->commit();

    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode([
        'ok' => true,
        'comandos' => array_map(static function (array $c): array {
            return ['tipo' => $c['tipo'], 'alvo' => $c['alvo'], 'valor' => $c['valor']];
        }, $comandos),
    ]);
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'erro' => 'falha_ao_buscar_comandos_pendentes',
        'detalhe' => $e->getMessage(),
    ]);
}
