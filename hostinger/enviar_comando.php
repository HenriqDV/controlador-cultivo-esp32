<?php
declare(strict_types=1);

// Dashboard (login) -> fila comandos_pendentes, consumida pelo ESP32.
require __DIR__ . '/api_comum.php';

require_login_api();

$data = read_json_body();

$tipo = trim((string) ($data['tipo'] ?? ''));
$alvo = trim((string) ($data['alvo'] ?? ''));
$valor = trim((string) ($data['valor'] ?? ''));

// Whitelist: só comandos que o firmware entende (aplicarComandoRemotoWeb()).
$relesValidos = ['Irrigacao', 'Ventilador', 'Umidificador', 'Entrada de Ar', 'Aquecedor', 'Saida de Ar'];

// Clima por VPD: formato e limites em clima_valido() (migracoes.php), os mesmos do firmware.

$valido = false;
if ($tipo === 'rele' && in_array($alvo, $relesValidos, true) && in_array($valor, ['ligado', 'desligado'], true)) {
    $valido = true;
} elseif ($tipo === 'modo_cultivo' && $alvo === 'modo_cultivo' && in_array($valor, ['vegetativo', 'floracao'], true)) {
    $valido = true;
} elseif ($tipo === 'luz_manual' && $alvo === 'luz_manual' && in_array($valor, ['auto', 'ligada', 'desligada'], true)) {
    $valido = true;
} elseif ($tipo === 'acesso' && $alvo === 'acesso' && $valor === 'liberar') {
    $valido = true;
} elseif ($tipo === 'clima_vpd' && $alvo === 'clima' && clima_valido($valor)) {
    $valido = true;
} elseif ($tipo === 'aquecedor_noturno' && $alvo === 'aquecedor' && in_array($valor, ['0', '1'], true)) {
    // Aquecedor noturno (firmware 2.10.0): liga quando a luz apaga e desliga em máximo da faixa de VPD - 0,20 kPa.
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
    // Clima por VPD e aquecedor noturno: só o último ajuste ainda não entregue vale.
    if ($tipo === 'clima_vpd' || $tipo === 'aquecedor_noturno') {
        $limpar = db()->prepare("DELETE FROM comandos_pendentes WHERE tipo = ? AND status = 'pendente'");
        $limpar->execute([$tipo]);
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
