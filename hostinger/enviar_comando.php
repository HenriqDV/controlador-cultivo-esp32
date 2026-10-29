<?php
declare(strict_types=1);

// Dashboard (login) -> fila comandos_pendentes, consumida pelo ESP32.

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

require_login_api();

$data = read_json_body();

$tipo = trim((string) ($data['tipo'] ?? ''));
$alvo = trim((string) ($data['alvo'] ?? ''));
$valor = trim((string) ($data['valor'] ?? ''));

// Whitelist: só comandos que o firmware entende (aplicarComandoRemotoWeb()).
$relesValidos = ['Umidificador', 'Ventilador', 'Irrigacao', 'Entrada de Ar', 'Aquecedor', 'Saida de Ar'];

// Faixas aceitas pelo firmware (definirAutomacaoRemota()).
function automacao_valida(string $alvo, string $valor): bool
{
    $limites = ['aquecedor' => [5.0, 40.0], 'umidade' => [20.0, 95.0]];
    if (!isset($limites[$alvo]) || !preg_match('/^([01]);(\d{1,2}(?:\.\d)?);(\d{1,2}(?:\.\d)?)$/', $valor, $m)) {
        return false;
    }
    [$inf, $sup] = $limites[$alvo];
    $min = (float) $m[2];
    $max = (float) $m[3];
    return $min >= $inf && $max <= $sup && ($max - $min) >= 1.0;
}

$valido = false;
if ($tipo === 'rele' && in_array($alvo, $relesValidos, true) && in_array($valor, ['ligado', 'desligado'], true)) {
    $valido = true;
} elseif ($tipo === 'modo_cultivo' && $alvo === 'modo_cultivo' && in_array($valor, ['vegetativo', 'floracao'], true)) {
    $valido = true;
} elseif ($tipo === 'luz_manual' && $alvo === 'luz_manual' && in_array($valor, ['auto', 'ligada', 'desligada'], true)) {
    $valido = true;
} elseif ($tipo === 'acesso' && $alvo === 'acesso' && $valor === 'liberar') {
    $valido = true;
} elseif ($tipo === 'automacao' && automacao_valida($alvo, $valor)) {
    // "ativa;min;max", ex.: "1;20.0;24.0" (aquecedor em °C, umidade em %).
    $valido = true;
} elseif ($tipo === 'fotoperiodo' && in_array($alvo, ['vegetativo', 'floracao'], true) && horario_fotoperiodo_valido($valor)) {
    // Início da luz "HH:MM"; a duração é fixa no firmware.
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
    // Automação: só o último ajuste ainda não entregue vale.
    if ($tipo === 'automacao') {
        db()->prepare("DELETE FROM comandos_pendentes WHERE tipo = 'automacao' AND alvo = ? AND status = 'pendente'")->execute([$alvo]);
    }
    $stmt = db()->prepare(
        'INSERT INTO comandos_pendentes (tipo, alvo, valor, usuario) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$tipo, $alvo, $valor, $_SESSION['usuario'] ?? null]);

    // Horário desejado (o confirmado chega em comandos_sistema).
    if ($tipo === 'modo_cultivo' && garantir_schema_v2() && ler_chave('fase_atual') !== $valor) {
        registrar_inicio_fase($valor);
        registrar_evento_painel('fase', $valor, 'ativada', (string) ($_SESSION['usuario'] ?? 'painel'));
    }
    if ($tipo === 'fotoperiodo' && garantir_schema_v2()) {
        salvar_configuracao('fotoperiodo_' . $alvo . '_inicio', $valor);
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
