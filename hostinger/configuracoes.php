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

// Preferências do painel (login).
//   GET  -> { config, fotoperiodo: { vegetativo, floracao } }
//   POST -> { config: { chave: valor } }  (fotoperíodo vai por enviar_comando.php)
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

// Número com vírgula ou ponto decimal; null se não for número.
function numero(string $v): ?float
{
    $v = str_replace(',', '.', trim($v));
    return preg_match('/^-?\d+(\.\d+)?$/', $v) ? (float) $v : null;
}

// Limites físicos de cada preferência numérica: [mínimo, máximo, casas decimais, rótulo].
const LIMITES_PREFERENCIAS = [
    'solo_ideal_min' => [0, 100, 0, 'Umidade do solo'],
    'solo_ideal_max' => [0, 100, 0, 'Umidade do solo'],
    'umid_alerta_min' => [0, 100, 0, 'Umidade interna'],
    'umid_alerta_max' => [0, 100, 0, 'Umidade interna'],
    'temp_alerta_min' => [0, 50, 1, 'Temperatura interna'],
    'temp_alerta_max' => [0, 50, 1, 'Temperatura interna'],
    'vpd_vegetativo_min' => [0, 3, 2, 'VPD da vegetação'],
    'vpd_vegetativo_max' => [0, 3, 2, 'VPD da vegetação'],
    'vpd_floracao_min' => [0, 3, 2, 'VPD da floração'],
    'vpd_floracao_max' => [0, 3, 2, 'VPD da floração'],
];

// Retorna o valor normalizado ou null se inválido.
function normalizar_preferencia(string $chave, $valor): ?string
{
    $v = is_scalar($valor) ? trim((string) $valor) : '';
    if (isset(LIMITES_PREFERENCIAS[$chave])) {
        [$min, $max, $casas] = LIMITES_PREFERENCIAS[$chave];
        $n = numero($v);
        if ($n === null || $n < $min || $n > $max) {
            return null;
        }
        return $casas === 0 ? (string) (int) round($n) : rtrim(rtrim(number_format($n, $casas, '.', ''), '0'), '.');
    }
    switch ($chave) {
        case 'nome_cultivo':
            $v = preg_replace('/\s+/', ' ', strip_tags($v));
            return ($v !== '' && mb_strlen($v) <= 40) ? $v : null;
        case 'pagina_inicial':
            if ($v === 'automacao') {
                $v = 'fotoperiodo'; // nome antigo da página
            }
            return in_array($v, ['inicio', 'relogio', 'fotoperiodo', 'comandos', 'historico', 'dispositivo', 'configuracoes'], true) ? $v : null;
        case 'intervalo_atualizacao_seg':
            return in_array($v, ['60', '120', '300', '600'], true) ? $v : null;
        case 'periodo_padrao_historico':
            return in_array($v, ['24h', '7d', '30d'], true) ? $v : null;
        case 'dispositivo_sem_dados_min':
            return in_array($v, ['2', '5', '10', '30'], true) ? $v : null;
        case 'luz_manual_min':
            return in_array($v, LUZ_MANUAL_OPCOES, true) ? $v : null;
    }
    return null;
}

function mensagem_invalido(string $chave): string
{
    if (isset(LIMITES_PREFERENCIAS[$chave])) {
        [$min, $max, , $rotulo] = LIMITES_PREFERENCIAS[$chave];
        return $rotulo . ': use um número entre ' . str_replace('.', ',', (string) $min) . ' e ' . str_replace('.', ',', (string) $max) . '.';
    }
    return 'Valor inválido em ' . $chave . '.';
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = read_json_body();
        $entrada = $data['config'] ?? null;
        if (!is_array($entrada) || count($entrada) === 0) {
            responder(['ok' => false, 'erro' => 'nada_para_salvar'], 422);
        }

        $novos = [];
        foreach ($entrada as $chave => $valor) {
            $chave = (string) $chave;
            if (str_starts_with($chave, 'fotoperiodo_') || !array_key_exists($chave, CONFIG_PADRAO)) {
                responder(['ok' => false, 'erro' => 'chave_invalida', 'detalhe' => $chave], 422);
            }
            $normal = normalizar_preferencia($chave, $valor);
            if ($normal === null) {
                responder(['ok' => false, 'erro' => 'valor_invalido', 'campo' => $chave, 'detalhe' => mensagem_invalido($chave)], 422);
            }
            $novos[$chave] = $normal;
        }

        $atual = array_merge(ler_configuracoes(), $novos);
        $faixas = [
            ['solo_ideal_min', 'solo_ideal_max', 'Umidade do solo'],
            ['temp_alerta_min', 'temp_alerta_max', 'Temperatura interna'],
            ['umid_alerta_min', 'umid_alerta_max', 'Umidade interna'],
            ['vpd_vegetativo_min', 'vpd_vegetativo_max', 'VPD da vegetação'],
            ['vpd_floracao_min', 'vpd_floracao_max', 'VPD da floração'],
        ];
        foreach ($faixas as [$cMin, $cMax, $rotulo]) {
            if ((float) $atual[$cMin] >= (float) $atual[$cMax]) {
                responder(['ok' => false, 'erro' => 'faixa_invalida', 'campo' => $cMin, 'detalhe' => $rotulo . ': o mínimo precisa ser menor que o máximo.'], 422);
            }
        }

        $antes = ler_configuracoes();
        foreach ($novos as $chave => $valor) {
            salvar_configuracao($chave, $valor);
        }
        if (isset($novos['luz_manual_min']) && $novos['luz_manual_min'] !== $antes['luz_manual_min']) {
            enfileirar_duracao_luz($novos['luz_manual_min'], (string) ($_SESSION['usuario'] ?? 'painel'));
        }
        responder(['ok' => true, 'config' => ler_configuracoes()]);
    }

    $config = ler_configuracoes();

    $fotoperiodo = estado_fotoperiodo($config);
    responder(['ok' => true, 'config' => $config, 'fotoperiodo' => $fotoperiodo, 'ciclo' => ciclo_atual(), 'luz_duracao_esp' => duracao_luz_esp()]);
} catch (Throwable $e) {
    responder(['ok' => false, 'erro' => 'falha_configuracoes', 'detalhe' => $e->getMessage()], 500);
}
