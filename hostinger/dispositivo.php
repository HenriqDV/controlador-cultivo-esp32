<?php
declare(strict_types=1);

require __DIR__ . '/api_comum.php';

// Token do hardware e vinculação (sessão; alterações só para administradores).
//   GET                             -> { token, atividade }
//   POST {acao:'gerar'}             -> { token_completo } (aparece só nesta resposta)
//   POST {acao:'revogar'}
//   POST {acao:'vincular'}          -> { codigo } válido por 30 min, uso único
//   POST {acao:'cancelar_vinculo'}
require_login_api();
garantir_schema_v2();

function estado_dispositivo(): array
{
    return ['ok' => true, 'admin' => auth_eh_admin(), 'token' => dispositivo_token_info(), 'atividade' => atividade_dispositivo(4)];
}

$usuario = (string) ($_SESSION['usuario'] ?? 'painel');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!auth_eh_admin()) {
            responder(['ok' => false, 'erro' => 'somente_admin', 'detalhe' => 'Só administradores podem mudar o token do dispositivo.'], 403);
        }
        $acao = (string) (read_json_body()['acao'] ?? '');

        if ($acao === 'gerar') {
            $token = gerar_token_hardware($usuario);
            responder(estado_dispositivo() + ['token_completo' => $token]);
        }
        if ($acao === 'revogar') {
            db()->prepare("UPDATE dispositivos SET token_estado = 'revogado', token_hash = NULL, vinculo_hash = NULL, vinculo_expira = NULL WHERE id = 1")->execute();
            registrar_evento_painel('dispositivo', 'token', 'token_revogado', $usuario);
            responder(estado_dispositivo());
        }
        if ($acao === 'vincular') {
            $codigo = codigo_aleatorio(8);
            $expira = agora_local()->modify('+' . VINCULO_DISPOSITIVO_MIN . ' minutes');
            db()->prepare('UPDATE dispositivos SET vinculo_hash = ?, vinculo_expira = ? WHERE id = 1')
                ->execute([hash('sha256', $codigo), $expira->format('Y-m-d H:i:s')]);
            registrar_evento_painel('dispositivo', 'token', 'vinculo_gerado', $usuario);
            responder(estado_dispositivo() + ['codigo' => substr($codigo, 0, 4) . '-' . substr($codigo, 4)]);
        }
        if ($acao === 'cancelar_vinculo') {
            db()->prepare('UPDATE dispositivos SET vinculo_hash = NULL, vinculo_expira = NULL WHERE id = 1')->execute();
            responder(estado_dispositivo());
        }
        responder(['ok' => false, 'erro' => 'acao_invalida'], 422);
    }
    responder(estado_dispositivo());
} catch (Throwable $e) {
    responder(['ok' => false, 'erro' => 'falha_dispositivo', 'detalhe' => $e->getMessage()], 500);
}
