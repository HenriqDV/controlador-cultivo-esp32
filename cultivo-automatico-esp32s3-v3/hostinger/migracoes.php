<?php
declare(strict_types=1);

// Migração automática v2: tabela configuracoes + coluna vpd (com backfill), e depois as
// migrações versionadas de sql/migracoes/ (NNN_nome.up.sql; desfazer com NNN_nome.down.sql).
// Requer config.php carregado.

// VPD (kPa), mesma fórmula do firmware.
const SQL_FORMULA_VPD = '(0.6108 * EXP((17.27 * temp_interna) / (temp_interna + 237.3)) * (1 - umid_interna / 100))';

// Padrões e whitelist das configurações.
const CONFIG_PADRAO = [
    // Fotoperíodo: alterado só via fila de comandos.
    'fotoperiodo_vegetativo_inicio' => '16:00',
    'fotoperiodo_floracao_inicio' => '19:00',
    // Preferências do painel.
    'nome_cultivo' => 'Smart Demetra',
    'pagina_inicial' => 'inicio',
    'intervalo_atualizacao_seg' => '60',
    'periodo_padrao_historico' => '24h',
    'solo_ideal_min' => '50',
    'solo_ideal_max' => '60',
    'temp_alerta_min' => '18',
    'temp_alerta_max' => '30',
    'umid_alerta_min' => '40',
    'umid_alerta_max' => '70',
    'vpd_vegetativo_min' => '0.8',
    'vpd_vegetativo_max' => '1.2',
    'vpd_floracao_min' => '1.2',
    'vpd_floracao_max' => '1.6',
    'dispositivo_sem_dados_min' => '5',
    'luz_manual_min' => '30',
];

function garantir_schema_v2(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }

    try {
        $pdo = db();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS configuracoes (
                chave VARCHAR(60) PRIMARY KEY,
                valor VARCHAR(255) NOT NULL,
                atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $temColuna = $pdo->query("SHOW COLUMNS FROM leituras_ambiente LIKE 'vpd'")->fetch();
        if (!$temColuna) {
            $pdo->exec('ALTER TABLE leituras_ambiente ADD COLUMN vpd DECIMAL(4,2) NULL AFTER solo_percentual');
            $pdo->exec(
                'UPDATE leituras_ambiente SET vpd = ' . SQL_FORMULA_VPD . '
                 WHERE vpd IS NULL AND temp_interna IS NOT NULL AND umid_interna IS NOT NULL'
            );
        }
        aplicar_migracoes_versionadas($pdo);
        $ok = true;
    } catch (Throwable $e) {
        error_log('garantir_schema_v2: ' . $e->getMessage());
        $ok = false;
    }

    return $ok;
}

function calcular_vpd(?float $tempC, ?float $umid): ?float
{
    if ($tempC === null || $umid === null) {
        return null;
    }
    $svp = 0.6108 * exp((17.27 * $tempC) / ($tempC + 237.3));
    $vpd = $svp * (1 - $umid / 100);
    return round(max(0.0, min(99.0, $vpd)), 2);
}

function ler_configuracoes(): array
{
    $config = CONFIG_PADRAO;
    if (!garantir_schema_v2()) {
        return $config;
    }
    $linhas = db()->query('SELECT chave, valor FROM configuracoes')->fetchAll();
    foreach ($linhas as $l) {
        if (array_key_exists($l['chave'], $config)) {
            $config[$l['chave']] = $l['valor'];
        }
    }
    return $config;
}

function salvar_configuracao(string $chave, string $valor): void
{
    $stmt = db()->prepare(
        'INSERT INTO configuracoes (chave, valor) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
    );
    $stmt->execute([$chave, $valor]);
}

// "HH:MM" em passos de 15 min.
function horario_fotoperiodo_valido(string $v): bool
{
    return (bool) preg_match('/^([01]\d|2[0-3]):(00|15|30|45)$/', $v);
}

// Solo em 0% nas 3 últimas leituras = sensor sem leitura. Registra em comandos_sistema
// (tipo 'alerta', alvo 'Solo') só na mudança de estado: 'sem_leitura' ou 'normalizado'.
function verificar_sensor_solo(?string $timestamp): void
{
    $ultimas = db()->query('SELECT solo_percentual FROM leituras_ambiente ORDER BY id DESC LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
    if (count($ultimas) < 3) {
        return;
    }
    $semLeitura = true;
    foreach ($ultimas as $v) {
        if ($v === null || (int) $v !== 0) {
            $semLeitura = false;
            break;
        }
    }
    $stmt = db()->prepare("SELECT valor FROM comandos_sistema WHERE tipo = 'alerta' AND alvo = 'Solo' ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $ultimoEstado = $stmt->fetchColumn();
    $emAlerta = $ultimoEstado === 'sem_leitura';

    if ($semLeitura !== $emAlerta) {
        db()->prepare("INSERT INTO comandos_sistema (dispositivo_timestamp, tipo, alvo, valor, origem) VALUES (?, 'alerta', 'Solo', ?, 'servidor')")
            ->execute([$timestamp, $semLeitura ? 'sem_leitura' : 'normalizado']);
    }
}

// Aplica, em ordem, as migrações sql/migracoes/*.up.sql ainda não registradas em
// migracoes_aplicadas. Objeto já existente (tabela/coluna/índice) conta como aplicado.
function aplicar_migracoes_versionadas(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS migracoes_aplicadas (
            versao VARCHAR(60) PRIMARY KEY,
            aplicada_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $feitas = $pdo->query('SELECT versao FROM migracoes_aplicadas')->fetchAll(PDO::FETCH_COLUMN);
    $arquivos = glob(__DIR__ . '/sql/migracoes/*.up.sql') ?: [];
    sort($arquivos, SORT_STRING);
    foreach ($arquivos as $arquivo) {
        $versao = basename($arquivo, '.up.sql');
        if (in_array($versao, $feitas, true)) {
            continue;
        }
        foreach (dividir_sql((string) file_get_contents($arquivo)) as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                // 1050 tabela, 1060 coluna, 1061 índice já existem; 1091 já removido.
                if (!in_array((int) ($e->errorInfo[1] ?? 0), [1050, 1060, 1061, 1091], true)) {
                    throw $e;
                }
            }
        }
        $pdo->prepare('INSERT INTO migracoes_aplicadas (versao) VALUES (?)')->execute([$versao]);
    }
}

// Divide um arquivo .sql em comandos (ignora linhas de comentário "--").
function dividir_sql(string $conteudo): array
{
    $linhas = array_filter(preg_split('/\R/', $conteudo), static fn ($l) => !preg_match('/^\s*--/', $l));
    return array_values(array_filter(array_map('trim', explode(';', implode("\n", $linhas))), static fn ($c) => $c !== ''));
}

// Atualiza o status do dispositivo a cada leitura. Campos opcionais (firmware novo):
// firmware, rssi, uptime, mac, modelo. Firmware antigo só atualiza ultimo_contato.
function registrar_contato_dispositivo(array $data): void
{
    $txt = static function (string $chave, string $regex) use ($data): ?string {
        $v = isset($data[$chave]) && is_scalar($data[$chave]) ? trim((string) $data[$chave]) : '';
        return ($v !== '' && preg_match($regex, $v)) ? $v : null;
    };
    $int = static function (string $chave, int $min, int $max) use ($data): ?int {
        if (!isset($data[$chave]) || !is_numeric($data[$chave])) {
            return null;
        }
        $v = (int) $data[$chave];
        return ($v >= $min && $v <= $max) ? $v : null;
    };
    $antes = db()->query('SELECT firmware_versao, uptime_seg, TIMESTAMPDIFF(SECOND, ultimo_contato, NOW()) AS sem_contato FROM dispositivos WHERE id = 1')->fetch() ?: [];
    $firmware = $txt('firmware', '/^[0-9A-Za-z._-]{1,20}$/');
    $uptime = $int('uptime', 0, 4294967295);
    db()->prepare(
        'UPDATE dispositivos SET ultimo_contato = NOW(),
            firmware_versao = COALESCE(?, firmware_versao),
            wifi_rssi = COALESCE(?, wifi_rssi),
            uptime_seg = COALESCE(?, uptime_seg),
            mac = COALESCE(?, mac),
            modelo = COALESCE(?, modelo)
         WHERE id = 1'
    )->execute([
        $firmware,
        $int('rssi', -120, 0),
        $uptime,
        $txt('mac', '/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'),
        $txt('modelo', '/^[0-9A-Za-z ._-]{1,40}$/'),
    ]);

    // Atividade recente: volta depois de um período sem dados, reinício e troca de firmware.
    $semContato = isset($antes['sem_contato']) ? (int) $antes['sem_contato'] : null;
    $limite = (int) ler_configuracoes()['dispositivo_sem_dados_min'] * 60;
    if ($semContato !== null && $semContato > $limite) {
        registrar_evento_painel('dispositivo', 'conexao', 'reconectado:' . $semContato, 'servidor');
    } elseif ($uptime !== null && isset($antes['uptime_seg']) && $antes['uptime_seg'] !== null && $uptime + 5 < (int) $antes['uptime_seg']) {
        registrar_evento_painel('dispositivo', 'conexao', 'reiniciou', 'servidor');
    }
    if ($firmware !== null && !empty($antes['firmware_versao']) && $antes['firmware_versao'] !== $firmware) {
        registrar_evento_painel('dispositivo', 'firmware', 'firmware:v' . ltrim($antes['firmware_versao'], 'v') . '>v' . ltrim($firmware, 'v'), 'esp32');
    }
}

// ---------------- Ciclo de cultivo (Etapa 4) ----------------
// Datas do painel no horário de Brasília (o mesmo do firmware), independente do fuso do servidor.
const FUSO_PAINEL = 'America/Sao_Paulo';
const FASES = ['vegetativo', 'floracao'];

function agora_local(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(FUSO_PAINEL));
}

function ler_chave(string $chave): ?string
{
    $stmt = db()->prepare('SELECT valor FROM configuracoes WHERE chave = ?');
    $stmt->execute([$chave]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (string) $v;
}

// Fase em uso no ESP32: último evento modo_cultivo confirmado pelo dispositivo.
function fase_confirmada(): ?string
{
    $v = db()->query("SELECT valor FROM comandos_sistema WHERE tipo = 'modo_cultivo' ORDER BY id DESC LIMIT 1")->fetchColumn();
    return in_array($v, FASES, true) ? $v : null;
}

function registrar_inicio_fase(string $fase, ?DateTimeImmutable $quando = null): void
{
    salvar_configuracao('fase_atual', $fase);
    salvar_configuracao('fase_iniciada_em', ($quando ?? agora_local())->format('Y-m-d H:i:s'));
}

// Sem fase_iniciada_em salva: usa o início da sequência atual de eventos modo_cultivo.
function deduzir_inicio_fase(string $fase): ?string
{
    $eventos = db()->query(
        "SELECT valor, COALESCE(dispositivo_timestamp, criado_em) AS quando FROM comandos_sistema
         WHERE tipo = 'modo_cultivo' ORDER BY id DESC LIMIT 500"
    )->fetchAll();
    $inicio = null;
    foreach ($eventos as $e) {
        if ($e['valor'] !== $fase) {
            break;
        }
        $inicio = $e['quando'];
    }
    return $inicio;
}

// Fase ativa, início e dia do ciclo (dia 1 = dia da troca) + troca programada pendente.
// fase = a que o ESP32 confirmou; fase_iniciada_em = última troca (painel, programada ou OLED).
function ciclo_atual(): array
{
    $fase = fase_confirmada() ?? ler_chave('fase_atual') ?? 'vegetativo';
    $iniciada = ler_chave('fase_iniciada_em');
    if ($iniciada === null) {
        $iniciada = deduzir_inicio_fase($fase);
        if ($iniciada !== null) {
            salvar_configuracao('fase_atual', $fase);
            salvar_configuracao('fase_iniciada_em', $iniciada);
        }
    }
    $dia = null;
    if ($iniciada !== null) {
        $fuso = new DateTimeZone(FUSO_PAINEL);
        $inicio = new DateTimeImmutable(substr($iniciada, 0, 10), $fuso);
        $hoje = new DateTimeImmutable(agora_local()->format('Y-m-d'), $fuso);
        $dia = max(1, (int) $inicio->diff($hoje)->format('%r%a') + 1);
    }
    $programada = null;
    try {
        $programada = db()->query(
            "SELECT id, fase, executar_em, criado_por FROM trocas_fase WHERE status = 'pendente' ORDER BY executar_em ASC LIMIT 1"
        )->fetch() ?: null;
    } catch (Throwable $e) {
        $programada = null; // migração 003 ainda não aplicada
    }
    return [
        'fase' => $fase,
        'fase_pedida' => ler_chave('fase_atual') ?? $fase,
        'iniciada_em' => $iniciada,
        'dia' => $dia,
        'programada' => $programada,
    ];
}

function registrar_evento_painel(string $tipo, string $alvo, string $valor, string $origem): void
{
    db()->prepare('INSERT INTO comandos_sistema (dispositivo_timestamp, tipo, alvo, valor, origem) VALUES (?, ?, ?, ?, ?)')
        ->execute([agora_local()->format('Y-m-d H:i:s'), $tipo, $alvo, $valor, substr($origem, 0, 40)]);
}

// Enfileira a troca de fase para o ESP32, reinicia a contagem de dias e registra em Eventos.
function aplicar_troca_fase(string $fase, string $usuario, string $como): void
{
    db()->prepare("INSERT INTO comandos_pendentes (tipo, alvo, valor, usuario) VALUES ('modo_cultivo', 'modo_cultivo', ?, ?)")
        ->execute([$fase, $usuario]);
    registrar_inicio_fase($fase);
    registrar_evento_painel('fase', $fase, $como, $usuario);
}

// Chamado pelo cron.php: executa as trocas programadas cujo horário já chegou.
function executar_trocas_programadas(): int
{
    $agora = agora_local()->format('Y-m-d H:i:s');
    $stmt = db()->prepare("SELECT id, fase, criado_por FROM trocas_fase WHERE status = 'pendente' AND executar_em <= ? ORDER BY executar_em");
    $stmt->execute([$agora]);
    $n = 0;
    foreach ($stmt->fetchAll() as $t) {
        aplicar_troca_fase($t['fase'], (string) ($t['criado_por'] ?? 'programada'), 'programada_executada');
        db()->prepare("UPDATE trocas_fase SET status = 'executada', concluida_em = ? WHERE id = ?")->execute([$agora, $t['id']]);
        $n++;
    }
    return $n;
}

// Timer de cada fase: salvo no painel x último informado pelo ESP32.
function estado_fotoperiodo(?array $config = null): array
{
    $config = $config ?? ler_configuracoes();
    // As duas datas comparadas (atualizado_em e criado_em) vêm do mesmo relógio, o do banco.
    //   confirmado: ESP32 informou o mesmo horário salvo
    //   divergente: ESP32 informou outro horário depois do salvamento
    //   aguardando: horário salvo ainda não confirmado
    $fotoperiodo = [];
    $stmtSalvo = db()->prepare('SELECT atualizado_em, TIMESTAMPDIFF(SECOND, atualizado_em, NOW()) AS idade FROM configuracoes WHERE chave = ?');
    $stmt = db()->prepare(
        "SELECT valor, COALESCE(dispositivo_timestamp, criado_em) AS quando, criado_em
         FROM comandos_sistema
         WHERE tipo = 'fotoperiodo' AND alvo = ?
         ORDER BY id DESC
         LIMIT 1"
    );
    foreach (['vegetativo' => 18, 'floracao' => 12] as $modo => $horasLuz) {
        $salvo = $config['fotoperiodo_' . $modo . '_inicio'];
        $stmtSalvo->execute(['fotoperiodo_' . $modo . '_inicio']);
        $linhaSalvo = $stmtSalvo->fetch() ?: null;
        $stmt->execute([$modo]);
        $ultimo = $stmt->fetch() ?: null;

        if (!$ultimo) {
            $estado = $linhaSalvo ? 'aguardando' : 'sem_confirmacao';
        } elseif ($ultimo['valor'] === $salvo) {
            $estado = 'confirmado';
        } elseif ($linhaSalvo && $ultimo['criado_em'] > $linhaSalvo['atualizado_em']) {
            $estado = 'divergente';
        } elseif (!$linhaSalvo) {
            $estado = 'divergente'; // nada salvo no painel: vale o padrão, e o ESP32 usa outro
        } else {
            $estado = 'aguardando';
        }
        $fotoperiodo[$modo] = [
            'inicio' => $salvo,
            'horas_luz' => $horasLuz,
            'estado' => $estado,
            'confirmado' => $ultimo ? $ultimo['valor'] : null,
            'confirmado_em' => $ultimo ? $ultimo['quando'] : null,
            'salvo_ha_seg' => $linhaSalvo ? (int) $linhaSalvo['idade'] : null,
        ];
    }

    return $fotoperiodo;
}

// ---------------- Duração do modo manual da luz (Etapa 8) ----------------
const LUZ_MANUAL_OPCOES = ['15', '30', '60', '120'];

// Enfileira a duração para o ESP32 (substitui um envio ainda pendente).
function enfileirar_duracao_luz(string $minutos, string $usuario): void
{
    db()->prepare("DELETE FROM comandos_pendentes WHERE tipo = 'luz_duracao' AND status = 'pendente'")->execute();
    db()->prepare("INSERT INTO comandos_pendentes (tipo, alvo, valor, usuario) VALUES ('luz_duracao', 'Luz', ?, ?)")
        ->execute([$minutos, substr($usuario, 0, 30)]);
}

// Duração informada pelo ESP32 (null = firmware antigo, sem esse recurso).
function duracao_luz_esp(): ?array
{
    $e = db()->query("SELECT valor, COALESCE(dispositivo_timestamp, criado_em) AS quando FROM comandos_sistema WHERE tipo = 'luz_duracao' ORDER BY id DESC LIMIT 1")->fetch();
    return $e ? ['minutos' => (string) $e['valor'], 'quando' => $e['quando']] : null;
}

// ---------------- Token do hardware e vinculação (Etapa 7) ----------------
const TOKEN_HW_PREFIXO = 'sd_hw_';
const VINCULO_DISPOSITIVO_MIN = 30;
const ALFABETO_TOKEN = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sem 0/O e 1/I

function codigo_aleatorio(int $tamanho): string
{
    $s = '';
    for ($i = 0; $i < $tamanho; $i++) {
        $s .= ALFABETO_TOKEN[random_int(0, strlen(ALFABETO_TOKEN) - 1)];
    }
    return $s;
}

function normalizar_codigo_vinculo(string $c): string
{
    return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($c))) ?? '';
}

function dispositivo_token_info(): array
{
    try {
        $d = db()->query('SELECT token_estado, token_final, token_criado_em, token_ultimo_uso, vinculo_hash, vinculo_expira FROM dispositivos WHERE id = 1')->fetch();
    } catch (Throwable $e) {
        return ['estado' => 'legado', 'final' => null, 'criado_em' => null, 'ultimo_uso' => null, 'vinculo_expira' => null];
    }
    $vinculoValido = $d && $d['vinculo_hash'] && $d['vinculo_expira'] > agora_local()->format('Y-m-d H:i:s');
    return [
        'estado' => $d['token_estado'] ?? 'legado',
        'final' => $d['token_final'] ?? null,
        'criado_em' => $d['token_criado_em'] ?? null,
        'ultimo_uso' => $d['token_ultimo_uso'] ?? null,
        'vinculo_expira' => $vinculoValido ? $d['vinculo_expira'] : null,
    ];
}

// Gera o token definitivo: só o hash fica no banco; o texto volta uma única vez.
function gerar_token_hardware(string $origem, string $evento = 'token_gerado'): string
{
    $token = TOKEN_HW_PREFIXO . codigo_aleatorio(32);
    db()->prepare(
        "UPDATE dispositivos SET token_estado = 'ativo', token_hash = ?, token_final = ?, token_criado_em = ?,
            token_ultimo_uso = NULL, vinculo_hash = NULL, vinculo_expira = NULL WHERE id = 1"
    )->execute([hash('sha256', $token), substr($token, -4), agora_local()->format('Y-m-d H:i:s')]);
    registrar_evento_painel('dispositivo', 'token', $evento . ':' . substr($token, -4), $origem);
    return $token;
}

// Requisições do ESP32 (X-API-Key): token do hardware ou, no modo legado, a API_KEY.
function exigir_dispositivo(): void
{
    $chave = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
    $ok = false;
    try {
        garantir_schema_v2();
        $d = db()->query('SELECT token_estado, token_hash, token_ultimo_uso FROM dispositivos WHERE id = 1')->fetch();
        $estado = $d['token_estado'] ?? 'legado';
        if (str_starts_with($chave, TOKEN_HW_PREFIXO)) {
            $ok = $estado === 'ativo' && $d['token_hash'] && hash_equals($d['token_hash'], hash('sha256', $chave));
            $agora = agora_local();
            if ($ok && (!$d['token_ultimo_uso'] || $d['token_ultimo_uso'] < $agora->modify('-1 minute')->format('Y-m-d H:i:s'))) {
                db()->prepare('UPDATE dispositivos SET token_ultimo_uso = ? WHERE id = 1')->execute([$agora->format('Y-m-d H:i:s')]);
            }
        } else {
            $ok = $estado === 'legado' && hash_equals(API_KEY, $chave);
        }
    } catch (Throwable $e) {
        $ok = hash_equals(API_KEY, $chave); // banco sem a migração 006
    }
    if (!$ok) {
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'erro' => 'unauthorized']);
        exit;
    }
}

// Atividade recente do dispositivo (Dispositivo → Atividade recente).
function atividade_dispositivo(int $limite = 4): array
{
    $stmt = db()->prepare(
        "SELECT tipo, alvo, valor, COALESCE(dispositivo_timestamp, criado_em) AS quando FROM comandos_sistema
         WHERE tipo = 'dispositivo' OR tipo = 'fotoperiodo' OR (tipo = 'alerta' AND alvo = 'Solo')
         ORDER BY id DESC LIMIT " . max(1, min(20, $limite))
    );
    $stmt->execute();
    $horasLuz = ['vegetativo' => 18, 'floracao' => 12];
    $saida = [];
    foreach ($stmt->fetchAll() as $e) {
        [$valor, $extra] = array_pad(explode(':', (string) $e['valor'], 2), 2, '');
        $item = ['quando' => $e['quando'], 'cor' => 'ok', 'titulo' => $e['valor'], 'detalhe' => ''];
        if ($e['tipo'] === 'fotoperiodo') {
            $inicio = (string) $e['valor'];
            $h = $horasLuz[$e['alvo']] ?? 18;
            $fim = preg_match('/^(\d{2}):(\d{2})$/', $inicio, $m) ? sprintf('%02d:%s', ((int) $m[1] + $h) % 24, $m[2]) : '--:--';
            $item['titulo'] = 'Timer confirmado';
            $item['detalhe'] = ($e['alvo'] === 'floracao' ? 'Floração' : 'Vegetação') . ' · ' . $inicio . ' → ' . $fim;
        } elseif ($e['tipo'] === 'alerta') {
            $semLeitura = $e['valor'] === 'sem_leitura';
            $item['cor'] = $semLeitura ? 'atencao' : 'ok';
            $item['titulo'] = $semLeitura ? 'Sensor de solo sem leitura' : 'Sensor de solo voltou a ler';
            $item['detalhe'] = $semLeitura ? '0% em 3 leituras seguidas' : 'Leituras normais de novo';
        } else {
            $mapa = [
                'offline' => ['falha', 'Sem dados', 'O ESP32 parou de enviar leituras'],
                'reconectado' => ['atencao', 'Reconectado', 'Ficou ' . texto_duracao((int) $extra) . ' sem enviar e voltou sozinho'],
                'reiniciou' => ['atencao', 'ESP32 reiniciou', 'Voltou a enviar leituras após reiniciar'],
                'firmware' => ['ok', 'Firmware atualizado', str_replace('>', ' → ', $extra)],
                'token_gerado' => ['atencao', 'Novo token gerado', 'sd_hw_••••' . $extra],
                'token_revogado' => ['falha', 'Token revogado', 'O ESP32 não consegue mais enviar dados'],
                'vinculado' => ['ok', 'Dispositivo vinculado', 'Token sd_hw_••••' . $extra . ' entregue ao ESP32'],
                'vinculo_gerado' => ['ok', 'Token de vinculação gerado', 'Válido por ' . VINCULO_DISPOSITIVO_MIN . ' min'],
            ];
            if (isset($mapa[$valor])) {
                [$item['cor'], $item['titulo'], $item['detalhe']] = $mapa[$valor];
            }
        }
        $saida[] = $item;
    }
    return $saida;
}

function texto_duracao(int $seg): string
{
    if ($seg < 90) {
        return max(1, $seg) . ' s';
    }
    $min = intdiv($seg + 30, 60);
    return $min < 60 ? $min . ' min' : intdiv($min, 60) . 'h' . ($min % 60 ? ' ' . ($min % 60) . 'min' : '');
}

// Cron: registra "Sem dados" uma vez por queda (a volta é registrada na próxima leitura).
function registrar_offline_dispositivo(): void
{
    $seg = db()->query('SELECT TIMESTAMPDIFF(SECOND, MAX(criado_em), NOW()) FROM leituras_ambiente')->fetchColumn();
    if ($seg === null || $seg === false) {
        return;
    }
    $limite = (int) ler_configuracoes()['dispositivo_sem_dados_min'] * 60;
    if ((int) $seg <= $limite) {
        return;
    }
    $ultimo = db()->query("SELECT valor FROM comandos_sistema WHERE tipo = 'dispositivo' AND alvo = 'conexao' ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($ultimo !== 'offline') {
        registrar_evento_painel('dispositivo', 'conexao', 'offline', 'servidor');
    }
}
