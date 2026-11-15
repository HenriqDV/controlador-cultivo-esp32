<?php
declare(strict_types=1);

require __DIR__ . '/api_comum.php';

// Dados extras da página Início (sessão):
//   - reles: mudanças de estado de cada relé nas últimas 25 h, mais o último estado antes dessa janela
//     (para saber quem já estava ligado no começo). Alimenta a faixa "quem estava ligado", o tempo
//     ligado de cada equipamento e os períodos de luz.
//   - agua_7d: litros da caixa por hora nos últimos 7 dias, para o consumo diário e a autonomia.
// Consultas pequenas; o painel busca na carga completa (a cada 5 min) e quando chega evento novo.
require_login_api();

const RELES_INICIO = ['Luz', 'Umidificador', 'Ventilador', 'Irrigacao', 'Entrada de Ar', 'Aquecedor', 'Saida de Ar'];

try {
    $janelaHoras = 25;

    $marcadores = implode(',', array_fill(0, count(RELES_INICIO), '?'));
    $stmt = db()->prepare(
        "SELECT alvo, valor, origem, COALESCE(dispositivo_timestamp, criado_em) AS quando
         FROM comandos_sistema
         WHERE tipo = 'rele' AND alvo IN ($marcadores) AND criado_em >= NOW() - INTERVAL ? HOUR
         ORDER BY id ASC"
    );
    $i = 1;
    foreach (RELES_INICIO as $alvo) {
        $stmt->bindValue($i++, $alvo);
    }
    $stmt->bindValue($i, $janelaHoras, PDO::PARAM_INT);
    $stmt->execute();
    $eventos = $stmt->fetchAll();

    $stmtAntes = db()->prepare(
        "SELECT alvo, valor, origem, COALESCE(dispositivo_timestamp, criado_em) AS quando
         FROM comandos_sistema
         WHERE tipo = 'rele' AND alvo = ? AND criado_em < NOW() - INTERVAL ? HOUR
         ORDER BY id DESC LIMIT 1"
    );
    $antes = [];
    foreach (RELES_INICIO as $alvo) {
        $stmtAntes->bindValue(1, $alvo);
        $stmtAntes->bindValue(2, $janelaHoras, PDO::PARAM_INT);
        $stmtAntes->execute();
        $linha = $stmtAntes->fetch();
        if ($linha) {
            $antes[$alvo] = $linha;
        }
    }

    $stmtAgua = db()->prepare(
        "SELECT DATE_FORMAT(COALESCE(dispositivo_timestamp, criado_em), '%Y-%m-%d %H:00:00') AS hora,
                AVG(agua_litros) AS litros
         FROM leituras_ambiente
         WHERE criado_em >= NOW() - INTERVAL 7 DAY AND agua_litros IS NOT NULL
         GROUP BY DATE_FORMAT(COALESCE(dispositivo_timestamp, criado_em), '%Y-%m-%d %H:00:00')
         ORDER BY hora ASC"
    );
    $stmtAgua->execute();
    $agua = $stmtAgua->fetchAll();

    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode([
        'ok' => true,
        'reles' => $eventos,
        'reles_antes' => (object) $antes,
        'agua_7d' => $agua,
    ]);
} catch (Throwable $e) {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'erro' => 'falha_ao_consultar_resumo_inicio',
        'detalhe' => $e->getMessage(),
    ]);
}
