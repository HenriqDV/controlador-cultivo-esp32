<?php
declare(strict_types=1);

require __DIR__ . '/api_comum.php';

exigir_dispositivo(); // token do hardware ou API_KEY (modo legado)
$data = read_json_body();

$tipo = trim((string) ($data['tipo'] ?? ''));
$alvo = trim((string) ($data['alvo'] ?? ''));
$valor = trim((string) ($data['valor'] ?? ''));
$origem = trim((string) ($data['origem'] ?? ''));

if ($tipo === '' || $alvo === '' || $valor === '' || $origem === '') {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'campos_obrigatorios']);
    exit;
}

try {
    $stmt = db()->prepare(
        'INSERT INTO comandos_sistema (
            dispositivo_timestamp,
            tipo,
            alvo,
            valor,
            origem
        ) VALUES (?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        nullable_datetime($data, 'timestamp'),
        substr($tipo, 0, 40),
        substr($alvo, 0, 80),
        substr($valor, 0, 80),
        substr($origem, 0, 40),
    ]);

    // ESP32 informou outra duração do modo manual (ex.: memória do relógio zerada):
    // reenvia a do painel, se ainda não houver um envio pendente.
    if ($tipo === 'luz_duracao') {
        try {
            $desejado = ler_configuracoes()['luz_manual_min'];
            $pendente = (int) db()->query("SELECT COUNT(*) FROM comandos_pendentes WHERE tipo = 'luz_duracao' AND status = 'pendente'")->fetchColumn();
            if ($valor !== $desejado && $pendente === 0 && in_array($desejado, LUZ_MANUAL_OPCOES, true)) {
                enfileirar_duracao_luz($desejado, 'sincronizacao');
            }
        } catch (Throwable $e) {
            error_log('luz_duracao: ' . $e->getMessage());
        }
    }

    // Depois de reiniciar, o ESP32 informa a fase e os horários de luz que tirou da memória do
    // relógio. Se essa memória zerou (bateria do RTC fraca ou ausente), ele volta nos padrões
    // de fábrica (vegetativo, luz às 16h): reenvia o que está salvo no painel.
    if ($origem === 'boot' && $tipo === 'modo_cultivo' && in_array($valor, FASES, true)) {
        try {
            garantir_schema_v2();
            $pedida = ler_chave('fase_atual');
            if ($pedida !== null && in_array($pedida, FASES, true) && $pedida !== $valor) {
                enfileirar_sincronizacao('modo_cultivo', 'modo_cultivo', $pedida);
            }
        } catch (Throwable $e) {
            error_log('sincronizar fase: ' . $e->getMessage());
        }
    }
    // Os horários de luz só mudam pelo painel: se o ESP32 informar outro horário que o salvo,
    // reenvia o do painel (a fila evita repetir enquanto o envio estiver pendente).
    if ($tipo === 'fotoperiodo' && in_array($alvo, FASES, true)) {
        try {
            $salvo = ler_chave('fotoperiodo_' . $alvo . '_inicio');
            if ($salvo !== null && horario_fotoperiodo_valido($salvo) && $salvo !== $valor) {
                enfileirar_sincronizacao('fotoperiodo', $alvo, $salvo);
            }
        } catch (Throwable $e) {
            error_log('sincronizar fotoperiodo: ' . $e->getMessage());
        }
    }
    // Troca de fase feita no próprio ESP32 (menu OLED): reinicia a contagem de dias.
    // (O aviso de reinício acima não conta como troca: quem manda é a fase salva no painel.)
    if ($tipo === 'modo_cultivo' && $origem !== 'boot' && in_array($valor, FASES, true)) {
        try {
            garantir_schema_v2();
            if (ler_chave('fase_atual') !== $valor) {
                registrar_inicio_fase($valor);
            }
        } catch (Throwable $e) {
            error_log('registrar_inicio_fase: ' . $e->getMessage());
        }
    }

    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'erro' => 'falha_ao_gravar_comando',
        'detalhe' => $e->getMessage(),
    ]);
}
