<?php
declare(strict_types=1);

// Notificações por Telegram. Requer config.php e migracoes.php já carregados.
// Token: constante TELEGRAM_BOT_TOKEN no config.php ou variável de ambiente.
// Chat, interruptores e estado ficam no banco (configuracoes + notificacoes_estado).

const NOTIF_PADRAO = ['notif_faixa' => '1', 'notif_offline' => '1', 'notif_timer' => '1', 'notif_resumo' => '0'];
const NOTIF_INTERVALO_MIN = 30;     // no máximo 1 aviso do mesmo tipo nesse intervalo
const NOTIF_TIMER_ESPERA_SEG = 300; // timer sem confirmação depois de 5 min
const NOTIF_RESUMO_HORA = 8;        // resumo diário às 08:00
const VINCULO_VALIDADE_MIN = 30;

function telegram_token(): ?string
{
    $t = defined('TELEGRAM_BOT_TOKEN') ? (string) constant('TELEGRAM_BOT_TOKEN') : (string) getenv('TELEGRAM_BOT_TOKEN');
    return preg_match('/^\d+:[\w-]{20,}$/', $t) ? $t : null;
}

function telegram_configurado(): bool
{
    return telegram_token() !== null && function_exists('curl_init');
}

function telegram_conectado(): bool
{
    return telegram_configurado() && (ler_chave('telegram_chat_id') ?? '') !== '';
}

// Chama a API do bot. Retorna o campo "result" ou null em caso de erro.
function telegram_api(string $metodo, array $params = [], ?string &$erro = null): mixed
{
    $token = telegram_token();
    if ($token === null) {
        $erro = 'bot_nao_configurado';
        return null;
    }
    $base = defined('TELEGRAM_API_URL') ? (string) constant('TELEGRAM_API_URL') : 'https://api.telegram.org';
    $ch = curl_init($base . '/bot' . $token . '/' . $metodo);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);
    $corpo = curl_exec($ch);
    $falha = curl_error($ch);
    curl_close($ch);
    if ($corpo === false) {
        $erro = 'sem_conexao: ' . $falha;
        return null;
    }
    $json = json_decode((string) $corpo, true);
    if (!is_array($json) || empty($json['ok'])) {
        $erro = is_array($json) ? (string) ($json['description'] ?? 'erro_api') : 'resposta_invalida';
        return null;
    }
    return $json['result'] ?? true;
}

function telegram_enviar(string $texto, ?string $chat = null, ?string &$erro = null): bool
{
    $chat = $chat ?? ler_chave('telegram_chat_id');
    if ($chat === null || $chat === '') {
        $erro = 'sem_chat';
        return false;
    }
    return telegram_api('sendMessage', ['chat_id' => $chat, 'text' => $texto, 'disable_web_page_preview' => true], $erro) !== null;
}

function telegram_bot_usuario(): ?string
{
    $u = ler_chave('telegram_bot_usuario');
    if ($u !== null && $u !== '') {
        return $u;
    }
    $eu = telegram_api('getMe');
    if (is_array($eu) && !empty($eu['username'])) {
        salvar_configuracao('telegram_bot_usuario', (string) $eu['username']);
        return (string) $eu['username'];
    }
    return null;
}

function notif_preferencias(): array
{
    $p = [];
    foreach (NOTIF_PADRAO as $chave => $padrao) {
        $p[$chave] = (ler_chave($chave) ?? $padrao) === '1';
    }
    return $p;
}

/* ---------------- Vínculo do chat (/start CODIGO) ---------------- */

function telegram_novo_vinculo(): string
{
    $codigo = bin2hex(random_bytes(6));
    salvar_configuracao('telegram_vinculo_codigo', $codigo);
    salvar_configuracao('telegram_vinculo_expira', agora_local()->modify('+' . VINCULO_VALIDADE_MIN . ' minutes')->format('Y-m-d H:i:s'));
    return $codigo;
}

function telegram_vinculo_pendente(): ?array
{
    $codigo = ler_chave('telegram_vinculo_codigo');
    $expira = ler_chave('telegram_vinculo_expira');
    if (!$codigo || !$expira || $expira <= agora_local()->format('Y-m-d H:i:s')) {
        return null;
    }
    return ['codigo' => $codigo, 'expira_em' => $expira];
}

// Lê as mensagens novas do bot e vincula o chat que mandou "/start CODIGO".
function telegram_verificar_vinculo(?string &$erro = null): bool
{
    $pendente = telegram_vinculo_pendente();
    if ($pendente === null) {
        return false;
    }
    $offset = (int) (ler_chave('telegram_update_offset') ?? '0');
    $updates = telegram_api('getUpdates', ['offset' => $offset, 'timeout' => 0, 'allowed_updates' => ['message']], $erro);
    if (!is_array($updates)) {
        return false;
    }
    $vinculou = false;
    foreach ($updates as $u) {
        $offset = max($offset, (int) ($u['update_id'] ?? 0) + 1);
        $msg = $u['message'] ?? null;
        if (!is_array($msg) || $vinculou) {
            continue;
        }
        $texto = trim((string) ($msg['text'] ?? ''));
        if (!hash_equals('/start ' . $pendente['codigo'], $texto)) {
            continue;
        }
        $chat = $msg['chat'] ?? [];
        $nome = (string) ($chat['title'] ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? '')));
        if ($nome === '') {
            $nome = (string) ($chat['username'] ?? 'chat');
        }
        salvar_configuracao('telegram_chat_id', (string) $chat['id']);
        salvar_configuracao('telegram_chat_nome', mb_substr($nome, 0, 60));
        salvar_configuracao('telegram_vinculo_codigo', '');
        telegram_enviar('✅ Smart Demetra conectado. Os avisos do cultivo vão chegar aqui.', (string) $chat['id']);
        registrar_evento_painel('notificacao', 'telegram', 'conectado', 'telegram');
        $vinculou = true;
    }
    salvar_configuracao('telegram_update_offset', (string) $offset);
    return $vinculou;
}

function telegram_desconectar(): void
{
    foreach (['telegram_chat_id', 'telegram_chat_nome', 'telegram_vinculo_codigo'] as $c) {
        salvar_configuracao($c, '');
    }
    db()->exec('UPDATE notificacoes_estado SET notificado = 0');
}

/* ---------------- Avisos com limite e "normalizado" ---------------- */

// Atualiza o estado de um tipo de aviso e envia quando precisa:
//   entrou em alerta  -> envia, se o último envio desse tipo tiver mais de 30 min
//   segue em alerta   -> envia quando o intervalo de 30 min passar (se ainda não avisou)
//   voltou ao normal  -> envia "normalizado", se o alerta foi avisado
function notificar_condicao(string $tipo, bool $alerta, string $msgAlerta, string $msgNormal, bool $ligado): void
{
    $stmt = db()->prepare('SELECT em_alerta, notificado, ultimo_envio FROM notificacoes_estado WHERE tipo = ?');
    $stmt->execute([$tipo]);
    $e = $stmt->fetch() ?: ['em_alerta' => 0, 'notificado' => 0, 'ultimo_envio' => null];
    $emAlerta = (bool) $e['em_alerta'];
    $notificado = (bool) $e['notificado'];
    $ultimo = $e['ultimo_envio'];
    $agora = agora_local();
    $livre = $ultimo === null || $ultimo <= $agora->modify('-' . NOTIF_INTERVALO_MIN . ' minutes')->format('Y-m-d H:i:s');
    $conectado = telegram_conectado();

    if ($alerta) {
        if (!$notificado && $ligado && $conectado && $livre) {
            $ok = telegram_enviar($msgAlerta);
            registrar_evento_painel('notificacao', $tipo, $ok ? 'enviada' : 'falhou', 'telegram');
            $ultimo = $agora->format('Y-m-d H:i:s'); // falha também espera o intervalo
            $notificado = $ok;
        }
        $emAlerta = true;
    } elseif ($emAlerta) {
        if ($notificado && $conectado) {
            $ok = telegram_enviar($msgNormal);
            registrar_evento_painel('notificacao', $tipo, $ok ? 'normalizado' : 'falhou', 'telegram');
        }
        $emAlerta = false;
        $notificado = false;
    } else {
        return; // nada mudou
    }

    db()->prepare(
        'INSERT INTO notificacoes_estado (tipo, em_alerta, notificado, ultimo_envio) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE em_alerta = VALUES(em_alerta), notificado = VALUES(notificado), ultimo_envio = VALUES(ultimo_envio)'
    )->execute([$tipo, (int) $emAlerta, (int) $notificado, $ultimo]);
}

function fmt_num(float $v, int $casas = 1): string
{
    return number_format($v, $casas, ',', '.');
}

// Chamado a cada leitura recebida (registrar_leitura.php).
function avaliar_faixas_leitura(): void
{
    $l = db()->query('SELECT temp_interna, umid_interna, solo_percentual, vpd FROM leituras_ambiente ORDER BY id DESC LIMIT 1')->fetch();
    if (!$l) {
        return;
    }
    $c = ler_configuracoes();
    $ligado = notif_preferencias()['notif_faixa'];
    $fase = ciclo_atual()['fase_pedida'];
    $nomeFase = $fase === 'floracao' ? 'Floração' : 'Vegetação';

    $faixas = [
        ['temperatura', 'Temperatura interna', $l['temp_interna'], 'temp_alerta', ' °C', 1],
        ['umidade', 'Umidade interna', $l['umid_interna'], 'umid_alerta', '%', 0],
        ['solo', 'Umidade do solo', $l['solo_percentual'], 'solo_ideal', '%', 0],
        ['vpd', 'VPD', $l['vpd'], 'vpd_' . $fase, ' kPa', 2],
    ];
    $soloSemLeitura = db()->query("SELECT valor FROM comandos_sistema WHERE tipo = 'alerta' AND alvo = 'Solo' ORDER BY id DESC LIMIT 1")->fetchColumn() === 'sem_leitura';

    foreach ($faixas as [$tipo, $rotulo, $valor, $chave, $unidade, $casas]) {
        // Solo em 0% é tratado como sensor sem leitura, não como solo seco.
        if ($valor === null || ($tipo === 'solo' && ($soloSemLeitura || (int) $valor === 0))) {
            continue;
        }
        $v = (float) $valor;
        $min = (float) $c[$chave . '_min'];
        $max = (float) $c[$chave . '_max'];
        $faixa = fmt_num($min, $casas) . '–' . fmt_num($max, $casas) . $unidade;
        $sufixo = $tipo === 'vpd' ? ' para ' . $nomeFase : '';
        $acima = $v > $max;
        notificar_condicao(
            $tipo,
            $v < $min || $v > $max,
            '⚠️ ' . $rotulo . ' ' . ($acima ? 'acima' : 'abaixo') . ' da faixa' . $sufixo . ': ' . fmt_num($v, $casas) . $unidade . ' (ideal ' . $faixa . ').',
            '✅ ' . $rotulo . ' voltou à faixa: ' . fmt_num($v, $casas) . $unidade . '.',
            $ligado
        );
    }
}

// Offline: avaliado no cron e a cada leitura (para avisar o "normalizado" na hora).
function avaliar_offline(): void
{
    $u = db()->query('SELECT COALESCE(dispositivo_timestamp, criado_em) AS quando, TIMESTAMPDIFF(SECOND, criado_em, NOW()) AS seg FROM leituras_ambiente ORDER BY id DESC LIMIT 1')->fetch();
    if (!$u) {
        return;
    }
    $limite = (int) ler_configuracoes()['dispositivo_sem_dados_min'];
    $seg = (int) $u['seg'];
    notificar_condicao(
        'offline',
        $seg > $limite * 60,
        '📡 O ESP32 parou de enviar leituras. Última leitura às ' . substr((string) $u['quando'], 11, 5) . ' (há ' . intdiv($seg, 60) . ' min). Verifique a energia e o Wi‑Fi.',
        '✅ O ESP32 voltou a enviar leituras.',
        notif_preferencias()['notif_offline']
    );
}

// Timer da fase ativa sem confirmação do ESP32 depois de 5 min (cron).
function avaliar_timer(): void
{
    $fase = ciclo_atual()['fase_pedida'];
    $e = estado_fotoperiodo()[$fase];
    $nome = $fase === 'floracao' ? 'Floração' : 'Vegetação';
    $offline = (int) (db()->query("SELECT em_alerta FROM notificacoes_estado WHERE tipo = 'offline'")->fetchColumn() ?: 0) === 1;
    $esperou = $e['salvo_ha_seg'] !== null && $e['salvo_ha_seg'] >= NOTIF_TIMER_ESPERA_SEG;
    $alerta = !$offline && $esperou && in_array($e['estado'], ['aguardando', 'divergente'], true);
    $msg = $e['estado'] === 'divergente'
        ? '⏱️ O ESP32 está usando o timer ' . $e['confirmado'] . ' na ' . $nome . ', mas o painel tem ' . $e['inicio'] . '.'
        : '⏱️ O ESP32 não confirmou o timer da ' . $nome . ' (' . $e['inicio'] . ') em 5 min.';
    notificar_condicao('timer', $alerta, $msg, '✅ Timer da ' . $nome . ' confirmado pelo ESP32 (' . $e['inicio'] . ').', notif_preferencias()['notif_timer']);
}

// Resumo diário às 08:00 com as médias do dia anterior (cron).
function enviar_resumo_diario(bool $forcar = false): bool
{
    $agora = agora_local();
    $hoje = $agora->format('Y-m-d');
    if (!$forcar) {
        if ((int) $agora->format('G') < NOTIF_RESUMO_HORA || ler_chave('notif_resumo_dia') === $hoje) {
            return false;
        }
        if (!notif_preferencias()['notif_resumo'] || !telegram_conectado()) {
            return false;
        }
        salvar_configuracao('notif_resumo_dia', $hoje);
    }
    $ontem = $agora->modify('-1 day');
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS n, AVG(temp_interna) AS t, MIN(temp_interna) AS tmin, MAX(temp_interna) AS tmax,
                AVG(umid_interna) AS u, AVG(NULLIF(solo_percentual, 0)) AS s, AVG(vpd) AS v
         FROM leituras_ambiente
         WHERE COALESCE(dispositivo_timestamp, criado_em) >= ? AND COALESCE(dispositivo_timestamp, criado_em) < ?'
    );
    $stmt->execute([$ontem->format('Y-m-d') . ' 00:00:00', $hoje . ' 00:00:00']);
    $r = $stmt->fetch();
    $ciclo = ciclo_atual();
    $fase = $ciclo['fase_pedida'] === 'floracao' ? 'Floração' : 'Vegetação';
    $linhas = ['🌿 Resumo de ' . $ontem->format('d/m') . ' · ' . $fase . ($ciclo['dia'] ? ', dia ' . $ciclo['dia'] . ' do ciclo' : '')];
    if (!$r || (int) $r['n'] === 0) {
        $linhas[] = 'Nenhuma leitura recebida nesse dia.';
    } else {
        if ($r['t'] !== null) {
            $linhas[] = 'Temperatura interna: ' . fmt_num((float) $r['t']) . ' °C (mín ' . fmt_num((float) $r['tmin']) . ' · máx ' . fmt_num((float) $r['tmax']) . ')';
        }
        if ($r['u'] !== null) {
            $linhas[] = 'Umidade interna: ' . fmt_num((float) $r['u'], 0) . '%';
        }
        if ($r['s'] !== null) {
            $linhas[] = 'Solo: ' . fmt_num((float) $r['s'], 0) . '%';
        }
        if ($r['v'] !== null) {
            $linhas[] = 'VPD: ' . fmt_num((float) $r['v'], 2) . ' kPa';
        }
        $linhas[] = 'Leituras: ' . number_format((int) $r['n'], 0, ',', '.');
    }
    $ok = telegram_enviar(implode("\n", $linhas));
    registrar_evento_painel('notificacao', 'resumo', $ok ? 'enviada' : 'falhou', 'telegram');
    return $ok;
}

// Tudo que o cron avalia a cada minuto.
function executar_notificacoes(): void
{
    if (!telegram_configurado()) {
        return;
    }
    if (telegram_vinculo_pendente() !== null) {
        telegram_verificar_vinculo();
    }
    avaliar_offline();
    avaliar_timer();
    enviar_resumo_diario();
}
