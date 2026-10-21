<?php
declare(strict_types=1);

// Tarefas agendadas. Configure no hPanel (Avançado → Cron Jobs) para rodar a cada minuto:
//   php /home/SEU_USUARIO/domains/SEU-DOMINIO/public_html/cron.php
// Só roda pela linha de comando (não pelo navegador).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('somente via cron');
}

require __DIR__ . '/config.php';
require __DIR__ . '/migracoes.php';
require __DIR__ . '/telegram.php';

garantir_schema_v2();

$trocas = executar_trocas_programadas();
if ($trocas > 0) {
    echo date('c') . " trocas de fase executadas: $trocas\n";
}

try {
    registrar_offline_dispositivo(); // "Sem dados" na atividade recente do Dispositivo
} catch (Throwable $e) {
    echo date('c') . ' dispositivo: ' . $e->getMessage() . "\n";
}

try {
    executar_notificacoes(); // offline, timer sem confirmação, resumo diário e vínculo do Telegram
} catch (Throwable $e) {
    echo date('c') . ' notificações: ' . $e->getMessage() . "\n";
}
