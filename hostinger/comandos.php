<?php
declare(strict_types=1);

require __DIR__ . '/api_comum.php';

// Eventos do painel: roda de eventos, relés e comandos (sessão).
// Exige login: antes qualquer pessoa com o endereço via estes dados.
require_login_api();

$limite = isset($_GET['limite']) ? max(1, min(500, (int) $_GET['limite'])) : 100;

try {
    $stmt = db()->prepare(
        'SELECT criado_em, dispositivo_timestamp, tipo, alvo, valor, origem
         FROM comandos_sistema
         ORDER BY criado_em DESC
         LIMIT ?'
    );
    $stmt->bindValue(1, $limite, PDO::PARAM_INT);
    $stmt->execute();

    $dados = $stmt->fetchAll();

    // Último modo de cultivo (fora do LIMIT), usado pela janela de luz do dashboard.
    $stmtModo = db()->prepare(
        "SELECT valor
         FROM comandos_sistema
         WHERE tipo = 'modo_cultivo'
         ORDER BY criado_em DESC
         LIMIT 1"
    );
    $stmtModo->execute();
    $modoAtual = $stmtModo->fetchColumn();

    // Último estado de cada relé, fora do LIMIT. Relés que mudam pouco (ex.: ventilador) saem da janela
    // das linhas mais recentes, e o painel mostraria "sem registro". "desde" = quando o estado atual começou
    // (primeiro registro da sequência com o mesmo valor; registros repetidos, como os do boot, não zeram a contagem).
    $reles = [];
    $stmtUltimo = db()->prepare(
        "SELECT id, criado_em, dispositivo_timestamp, tipo, alvo, valor, origem
         FROM comandos_sistema WHERE tipo = 'rele' AND alvo = ? ORDER BY id DESC LIMIT 1"
    );
    $stmtTroca = db()->prepare(
        "SELECT MAX(id) FROM comandos_sistema WHERE tipo = 'rele' AND alvo = ? AND valor <> ?"
    );
    $stmtInicio = db()->prepare(
        "SELECT COALESCE(dispositivo_timestamp, criado_em) FROM comandos_sistema
         WHERE tipo = 'rele' AND alvo = ? AND id > ? ORDER BY id ASC LIMIT 1"
    );
    foreach (['Luz', 'Umidificador', 'Ventilador', 'Irrigacao', 'Entrada de Ar', 'Aquecedor', 'Saida de Ar'] as $alvoRele) {
        $stmtUltimo->execute([$alvoRele]);
        $ultimo = $stmtUltimo->fetch();
        if (!$ultimo) {
            continue;
        }
        $stmtTroca->execute([$alvoRele, $ultimo['valor']]);
        $idTroca = (int) $stmtTroca->fetchColumn();
        $stmtInicio->execute([$alvoRele, $idTroca]);
        $ultimo['desde'] = $stmtInicio->fetchColumn() ?: null;
        unset($ultimo['id']);
        $reles[$alvoRele] = $ultimo;
    }

    // Última liberação da porta, também fora do LIMIT.
    $ultimoAcesso = db()->query(
        "SELECT criado_em, dispositivo_timestamp, tipo, alvo, valor, origem
         FROM comandos_sistema WHERE tipo = 'acesso' ORDER BY id DESC LIMIT 1"
    )->fetch() ?: null;

    // Clima por VPD confirmado pelo ESP32 (último registro, fora do LIMIT).
    $clima = db()->query(
        "SELECT valor, origem, COALESCE(dispositivo_timestamp, criado_em) AS quando
         FROM comandos_sistema WHERE tipo = 'clima_vpd' ORDER BY id DESC LIMIT 1"
    )->fetch() ?: null;

    // Automações antigas (firmware 2.5), só para o histórico.
    $automacoes = [];
    $stmtAuto = db()->query(
        "SELECT alvo, valor, origem, COALESCE(dispositivo_timestamp, criado_em) AS quando
         FROM comandos_sistema
         WHERE id IN (SELECT MAX(id) FROM comandos_sistema WHERE tipo = 'automacao' GROUP BY alvo)"
    );
    foreach ($stmtAuto->fetchAll() as $a) {
        $automacoes[$a['alvo']] = $a;
    }

    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode([
        'ok' => true,
        'dados' => $dados,
        'modo_atual' => $modoAtual !== false ? $modoAtual : null,
        'reles' => (object) $reles,
        'ultimo_acesso' => $ultimoAcesso,
        'clima' => $clima,
        'automacoes' => (object) $automacoes,
    ]);
} catch (Throwable $e) {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'erro' => 'falha_ao_consultar_comandos',
        'detalhe' => $e->getMessage(),
    ]);
}
