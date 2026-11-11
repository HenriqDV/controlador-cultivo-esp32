<?php
declare(strict_types=1);

require __DIR__ . '/api_comum.php';

// Preferências do painel (login).
//   GET  -> { config, fotoperiodo: { vegetativo, floracao } }
//   POST -> { config: { chave: valor } }  (fotoperíodo vai por enviar_comando.php)
require_login_api();
garantir_schema_v2();

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
    'vpd_muda_min' => [0, 3, 2, 'VPD da muda'],
    'vpd_muda_max' => [0, 3, 2, 'VPD da muda'],
    'clima_trava_temp' => [15, 40, 1, 'Trava do aquecedor'],
    'clima_trava_umid' => [30, 95, 0, 'Trava do umidificador'],
    'clima_min_ligado' => [1, 15, 0, 'Tempo mínimo ligado'],
    'clima_min_desligado' => [1, 15, 0, 'Tempo mínimo desligado'],
    'agua_alerta_min' => [0, 90, 0, 'Alerta da caixa d\'água'],
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
            return in_array($v, ['inicio', 'relogio', 'fotoperiodo', 'comandos', 'caixa', 'historico', 'dispositivo', 'configuracoes'], true) ? $v : null;
        case 'intervalo_atualizacao_seg':
            return in_array($v, INTERVALOS_ATUALIZACAO, true) ? $v : null;
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
            ['vpd_muda_min', 'vpd_muda_max', 'VPD da muda'],
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
