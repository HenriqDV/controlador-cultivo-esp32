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

$tipo = trim((string) ($data['tipo'] ?? ''));
$alvo = trim((string) ($data['alvo'] ?? ''));
$valor = trim((string) ($data['valor'] ?? ''));
$origem = trim((string) ($data['origem'] ?? ''));

if ($tipo === '' || $alvo === '' || $valor === '' || $origem === '') {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'campos_obrigatorios']);
    exit;
}

try {
    $stmt = db()->prepare(
        'INSERT INTO comandos_sistema (
            dispositivo_timestamp,
            tipo,
            alvo,
            valor,
            origem
        ) VALUES (?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        nullable_datetime($data, 'timestamp'),
        substr($tipo, 0, 40),
        substr($alvo, 0, 80),
        substr($valor, 0, 80),
        substr($origem, 0, 40),
    ]);

    // ESP32 informou outra duração do modo manual (ex.: memória do relógio zerada):
    // reenvia a do painel, se ainda não houver um envio pendente.
    if ($tipo === 'luz_duracao') {
        try {
            $desejado = ler_configuracoes()['luz_manual_min'];
            $pendente = (int) db()->query("SELECT COUNT(*) FROM comandos_pendentes WHERE tipo = 'luz_duracao' AND status = 'pendente'")->fetchColumn();
            if ($valor !== $desejado && $pendente === 0 && in_array($desejado, LUZ_MANUAL_OPCOES, true)) {
                enfileirar_duracao_luz($desejado, 'sincronizacao');
            }
        } catch (Throwable $e) {
            error_log('luz_duracao: ' . $e->getMessage());
        }
    }

    // Troca de fase feita no próprio ESP32 (menu OLED): reinicia a contagem de dias.
    if ($tipo === 'modo_cultivo' && in_array($valor, FASES, true)) {
        try {
            garantir_schema_v2();
            if (ler_chave('fase_atual') !== $valor) {
                registrar_inicio_fase($valor);
            }
        } catch (Throwable $e) {
            error_log('registrar_inicio_fase: ' . $e->getMessage());
        }
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
        'erro' => 'falha_ao_gravar_comando',
        'detalhe' => $e->getMessage(),
    ]);
}
