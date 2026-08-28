<?php
declare(strict_types=1);

// Recebe um comando dos botões do dashboard web (index.php, atrás de
// login) e grava numa fila (comandos_pendentes) que o ESP32 consulta por
// polling — ver comandos_pendentes.php e, no firmware,
// atualizarComandosPendentesHostinger(). Protegido por SESSÃO de login
// (não pela X-API-Key do dispositivo — este endpoint é pro navegador, não
// pro ESP32).

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

header('Content-Type: application/json; charset=utf-8');

require_login_api();

$data = read_json_body();

$tipo = trim((string) ($data['tipo'] ?? ''));
$alvo = trim((string) ($data['alvo'] ?? ''));
$valor = trim((string) ($data['valor'] ?? ''));

// Whitelist — mesmos nomes/valores que o firmware entende em
// aplicarComandoRemotoWeb(). Rejeita qualquer coisa fora disso: evita que
// um comando malformado (ou uma tentativa de injeção pelo corpo do POST)
// chegue na fila que o ESP32 consome.
$relesValidos = ['Desumidificador', 'Ventilador', 'Entrada de Ar 1', 'Entrada de Ar 2', 'Saida de Ar'];

$valido = false;
if ($tipo === 'rele' && in_array($alvo, $relesValidos, true) && in_array($valor, ['ligado', 'desligado'], true)) {
    $valido = true;
} elseif ($tipo === 'modo_cultivo' && $alvo === 'modo_cultivo' && in_array($valor, ['vegetativo', 'floracao'], true)) {
    $valido = true;
} elseif ($tipo === 'luz_manual' && $alvo === 'luz_manual' && in_array($valor, ['auto', 'ligada', 'desligada'], true)) {
    $valido = true;
} elseif ($tipo === 'acesso' && $alvo === 'acesso' && $valor === 'liberar') {
    $valido = true;
}

if (!$valido) {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'comando_invalido']);
    exit;
}

try {
    $stmt = db()->prepare(
        'INSERT INTO comandos_pendentes (tipo, alvo, valor, usuario) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$tipo, $alvo, $valor, $_SESSION['usuario'] ?? null]);

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
