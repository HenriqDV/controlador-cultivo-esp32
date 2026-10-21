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
    require __DIR__ . '/telegram.php';
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

// Notificações por Telegram (sessão).
//   GET                                -> status do bot/chat e interruptores
//   POST {acao:'vincular'}             -> gera o código e o link t.me/<bot>?start=<código>
//   POST {acao:'verificar'}            -> procura o /start do código e guarda o chat_id
//   POST {acao:'teste'}                -> envia uma mensagem de teste
//   POST {acao:'desconectar'}
//   POST {acao:'preferencia', chave, ligado}
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

function status_notificacoes(): array
{
    $configurado = telegram_configurado();
    $vinculo = $configurado ? telegram_vinculo_pendente() : null;
    $bot = $configurado ? telegram_bot_usuario() : null;
    $nome = ler_chave('telegram_chat_nome');
    return [
        'ok' => true,
        'configurado' => $configurado,
        'bot_usuario' => $bot,
        'conectado' => telegram_conectado(),
        'chat_nome' => $nome !== '' ? $nome : null,
        'vinculo' => $vinculo && $bot ? $vinculo + ['link' => 'https://t.me/' . rawurlencode($bot) . '?start=' . $vinculo['codigo']] : null,
        'preferencias' => notif_preferencias(),
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = read_json_body();
        $acao = (string) ($data['acao'] ?? '');

        if ($acao === 'preferencia') {
            $chave = (string) ($data['chave'] ?? '');
            if (!array_key_exists($chave, NOTIF_PADRAO) || !is_bool($data['ligado'] ?? null)) {
                responder(['ok' => false, 'erro' => 'preferencia_invalida'], 422);
            }
            salvar_configuracao($chave, $data['ligado'] ? '1' : '0');
            responder(status_notificacoes());
        }

        if (!telegram_configurado()) {
            responder(['ok' => false, 'erro' => 'bot_nao_configurado', 'detalhe' => 'Defina TELEGRAM_BOT_TOKEN no config.php do servidor.'], 422);
        }

        if ($acao === 'vincular') {
            if (telegram_bot_usuario() === null) {
                responder(['ok' => false, 'erro' => 'bot_inacessivel', 'detalhe' => 'Não foi possível falar com o bot. Confira o token.'], 502);
            }
            telegram_novo_vinculo();
            responder(status_notificacoes());
        }

        if ($acao === 'verificar') {
            $erro = null;
            telegram_verificar_vinculo($erro);
            $st = status_notificacoes();
            if ($erro !== null && !$st['conectado']) {
                $st['aviso'] = str_contains($erro, 'webhook') ? 'O bot tem um webhook ativo; remova-o para usar o vínculo pelo painel.' : $erro;
            }
            responder($st);
        }

        if ($acao === 'teste') {
            if (!telegram_conectado()) {
                responder(['ok' => false, 'erro' => 'sem_chat', 'detalhe' => 'Conecte um chat primeiro.'], 422);
            }
            $ultimo = ler_chave('telegram_teste_em');
            if ($ultimo !== null && $ultimo > agora_local()->modify('-15 seconds')->format('Y-m-d H:i:s')) {
                responder(['ok' => false, 'erro' => 'aguarde', 'detalhe' => 'Aguarde alguns segundos para enviar outro teste.'], 429);
            }
            salvar_configuracao('telegram_teste_em', agora_local()->format('Y-m-d H:i:s'));
            $erro = null;
            $ok = telegram_enviar('🔔 Teste do Smart Demetra: as notificações estão funcionando.', null, $erro);
            registrar_evento_painel('notificacao', 'teste', $ok ? 'enviada' : 'falhou', 'telegram');
            if (!$ok) {
                responder(['ok' => false, 'erro' => 'falha_envio', 'detalhe' => 'O Telegram recusou a mensagem: ' . $erro], 502);
            }
            responder(status_notificacoes());
        }

        if ($acao === 'desconectar') {
            telegram_desconectar();
            registrar_evento_painel('notificacao', 'telegram', 'desconectado', (string) ($_SESSION['usuario'] ?? 'painel'));
            responder(status_notificacoes());
        }

        responder(['ok' => false, 'erro' => 'acao_invalida'], 422);
    }

    responder(status_notificacoes());
} catch (Throwable $e) {
    responder(['ok' => false, 'erro' => 'falha_notificacoes', 'detalhe' => $e->getMessage()], 500);
}
