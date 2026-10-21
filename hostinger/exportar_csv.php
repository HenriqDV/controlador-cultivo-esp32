<?php
declare(strict_types=1);

// Histórico em CSV (sessão), no período escolhido em Histórico: ?periodo=24h|7d|30d.
// Separador ";" e vírgula decimal (abre direto no Excel em português), UTF-8 com BOM.
require __DIR__ . '/config.php';
require __DIR__ . '/migracoes.php';
require __DIR__ . '/auth.php';
iniciar_sessao();
auth_restaurar_sessao();
require_login_html();
garantir_schema_v2();

$periodos = ['24h' => 24, '7d' => 24 * 7, '30d' => 24 * 30];
$periodo = isset($_GET['periodo'], $periodos[$_GET['periodo']]) ? (string) $_GET['periodo'] : '24h';
$horas = $periodos[$periodo];

function num_csv($v, int $casas): string
{
    return $v === null ? '' : number_format((float) $v, $casas, ',', '');
}

try {
    $pdo = db();
    // Estado da luz: último evento do relé antes de cada leitura (pelo horário do servidor).
    $eventos = $pdo->prepare(
        "SELECT criado_em AS quando, valor FROM comandos_sistema
         WHERE tipo = 'rele' AND alvo = 'Luz' AND criado_em >= NOW() - INTERVAL ? HOUR ORDER BY id ASC"
    );
    $eventos->bindValue(1, $horas, PDO::PARAM_INT);
    $eventos->execute();
    $luz = $eventos->fetchAll();
    $antes = $pdo->prepare(
        "SELECT valor FROM comandos_sistema WHERE tipo = 'rele' AND alvo = 'Luz' AND criado_em < NOW() - INTERVAL ? HOUR ORDER BY id DESC LIMIT 1"
    );
    $antes->bindValue(1, $horas, PDO::PARAM_INT);
    $antes->execute();
    $estadoLuz = $antes->fetchColumn() ?: null;

    $leituras = $pdo->prepare(
        'SELECT COALESCE(dispositivo_timestamp, criado_em) AS quando, criado_em, temp_interna, umid_interna, temp_externa, umid_externa,
                solo_percentual, COALESCE(vpd, ' . SQL_FORMULA_VPD . ') AS vpd
         FROM leituras_ambiente WHERE criado_em >= NOW() - INTERVAL ? HOUR ORDER BY criado_em ASC, id ASC'
    );
    $leituras->bindValue(1, $horas, PDO::PARAM_INT);
    $leituras->execute();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Falha ao gerar o CSV: ' . $e->getMessage();
    exit;
}

$arquivo = 'smart-demetra-' . $periodo . '-' . agora_local()->format('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $arquivo . '"');
header('Cache-Control: no-store');

$saida = fopen('php://output', 'w');
fwrite($saida, "\xEF\xBB\xBF");
fputcsv($saida, ['Data/hora', 'Temperatura interna (°C)', 'Umidade interna (%)', 'Temperatura externa (°C)', 'Umidade externa (%)', 'Solo (%)', 'VPD (kPa)', 'Luz'], ';');

$i = 0;
$total = count($luz);
while ($l = $leituras->fetch()) {
    while ($i < $total && $luz[$i]['quando'] <= $l['criado_em']) { // mesmo relógio (servidor)
        $estadoLuz = $luz[$i]['valor'];
        $i++;
    }
    $quando = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $l['quando']);
    fputcsv($saida, [
        $quando ? $quando->format('d/m/Y H:i:s') : (string) $l['quando'],
        num_csv($l['temp_interna'], 1),
        num_csv($l['umid_interna'], 1),
        num_csv($l['temp_externa'], 1),
        num_csv($l['umid_externa'], 1),
        $l['solo_percentual'] === null ? '' : (string) (int) $l['solo_percentual'],
        num_csv($l['vpd'], 2),
        $estadoLuz === 'ligado' ? 'acesa' : ($estadoLuz === 'desligado' ? 'apagada' : ''),
    ], ';');
}
fclose($saida);
