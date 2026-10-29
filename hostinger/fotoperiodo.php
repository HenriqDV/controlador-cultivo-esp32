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

// Troca de fase pela página Fotoperíodo (sessão).
//   GET                                   -> { ciclo }
//   POST {acao:'ativar', fase}            -> enfileira a troca agora
//   POST {acao:'programar', fase, data}   -> agenda para o 1º horário de luz da data (AAAA-MM-DD)
//   POST {acao:'cancelar', id}            -> cancela a troca programada
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

$usuario = (string) ($_SESSION['usuario'] ?? 'painel');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = read_json_body();
        $acao = (string) ($data['acao'] ?? '');
        $fase = (string) ($data['fase'] ?? '');

        if ($acao === 'ativar' || $acao === 'programar') {
            if (!in_array($fase, FASES, true)) {
                responder(['ok' => false, 'erro' => 'fase_invalida'], 422);
            }
            $ciclo = ciclo_atual();
            if ($fase === $ciclo['fase_pedida']) {
                responder(['ok' => false, 'erro' => 'fase_ja_ativa', 'detalhe' => 'Esta fase já está ativa.'], 422);
            }
        }

        if ($acao === 'ativar') {
            db()->prepare("UPDATE trocas_fase SET status = 'cancelada', concluida_em = ? WHERE status = 'pendente'")
                ->execute([agora_local()->format('Y-m-d H:i:s')]);
            aplicar_troca_fase($fase, $usuario, 'ativada');
            responder(['ok' => true, 'ciclo' => ciclo_atual()]);
        }

        if ($acao === 'programar') {
            $dataTxt = (string) ($data['data'] ?? '');
            $dia = DateTimeImmutable::createFromFormat('!Y-m-d', $dataTxt, new DateTimeZone(FUSO_PAINEL));
            if (!$dia || $dia->format('Y-m-d') !== $dataTxt) {
                responder(['ok' => false, 'erro' => 'data_invalida', 'detalhe' => 'Escolha uma data válida.'], 422);
            }
            $inicio = ler_configuracoes()['fotoperiodo_' . $fase . '_inicio'];
            [$h, $m] = array_map('intval', explode(':', $inicio));
            $quando = $dia->setTime($h, $m);
            if ($quando <= agora_local()) {
                responder(['ok' => false, 'erro' => 'data_passada', 'detalhe' => 'O horário de luz desse dia (' . $inicio . ') já passou. Escolha outra data.'], 422);
            }
            if ($quando > agora_local()->modify('+1 year')) {
                responder(['ok' => false, 'erro' => 'data_distante', 'detalhe' => 'Escolha uma data dentro de 1 ano.'], 422);
            }
            db()->prepare("UPDATE trocas_fase SET status = 'cancelada', concluida_em = ? WHERE status = 'pendente'")
                ->execute([agora_local()->format('Y-m-d H:i:s')]);
            db()->prepare('INSERT INTO trocas_fase (fase, executar_em, criado_por) VALUES (?, ?, ?)')
                ->execute([$fase, $quando->format('Y-m-d H:i:s'), $usuario]);
            registrar_evento_painel('fase', $fase, 'programada para ' . $quando->format('d/m H:i'), $usuario);
            responder(['ok' => true, 'ciclo' => ciclo_atual()]);
        }

        if ($acao === 'cancelar') {
            $stmt = db()->prepare("UPDATE trocas_fase SET status = 'cancelada', concluida_em = ? WHERE id = ? AND status = 'pendente'");
            $stmt->execute([agora_local()->format('Y-m-d H:i:s'), (int) ($data['id'] ?? 0)]);
            if ($stmt->rowCount() === 0) {
                responder(['ok' => false, 'erro' => 'nada_para_cancelar'], 404);
            }
            registrar_evento_painel('fase', 'programacao', 'cancelada', $usuario);
            responder(['ok' => true, 'ciclo' => ciclo_atual()]);
        }

        responder(['ok' => false, 'erro' => 'acao_invalida'], 422);
    }

    responder(['ok' => true, 'ciclo' => ciclo_atual()]);
} catch (Throwable $e) {
    responder(['ok' => false, 'erro' => 'falha_fotoperiodo', 'detalhe' => $e->getMessage()], 500);
}
