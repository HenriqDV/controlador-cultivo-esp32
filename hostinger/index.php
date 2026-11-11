<?php
declare(strict_types=1);
// Dashboard (login): Início, Relógio de eventos, Fotoperíodo, Comandos, Histórico,
// Dispositivo e Configurações. Comandos vão para a fila comandos_pendentes (polling do ESP32).
require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
iniciar_sessao();
auth_restaurar_sessao();
require_login_html();
$usuarioLogado = $_SESSION['usuario'] ?? '';
$ehAdmin = auth_eh_admin();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Smart Demetra — Painel</title>
<link rel="preload" href="fonts/DemetraDisplay-Regular.otf" as="font" type="font/otf" crossorigin>
<link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="img/icone-192.png">
<link rel="apple-touch-icon" href="img/apple-touch-icon.png">
<meta name="theme-color" content="#121414">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.5.0/chart.umd.min.js"></script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<link rel="stylesheet" href="app.css?v=<?= (int) @filemtime(__DIR__ . '/app.css') ?>">
</head>
<body>
<div class="app">

<?php require __DIR__ . '/partials/cabecalho.php'; ?>

    <!-- ===================== ABA: INÍCIO ===================== -->
    <div class="aba ativa" id="aba-inicio">
      <h1 class="titulo-aba">In&iacute;cio</h1>
      <p class="subtitulo-aba" style="margin-bottom:12px;">Leituras do ambiente e do cultivo em tempo real.</p>
      <div class="selos-status">
        <span class="selo-status" id="seloConexao"><i></i><span>verificando…</span></span>
        <span class="selo-status neutro" id="seloFase"><svg data-lucide="sprout"></svg><span>--</span></span>
      </div>

      <div class="aviso-offline" id="avisoOffline" hidden>
        <div class="aviso-offline-icone"><svg data-lucide="wifi-off"></svg></div>
        <div>
          <strong>O ESP32 parou de enviar leituras</strong>
          <p id="avisoOfflineTexto">&Uacute;ltima leitura &agrave;s --:--. Verifique a energia e o Wi-Fi do dispositivo. Os valores abaixo s&atilde;o os &uacute;ltimos recebidos.</p>
          <a href="#dispositivo" data-ir-aba="dispositivo">Ver dispositivo &rarr;</a>
        </div>
      </div>

      <div class="stats">
        <div class="card">
          <div class="card-topo"><span class="card-label">Temp. interna</span><div class="card-icone"><svg data-lucide="thermometer" style="width:16px;height:16px;"></svg></div></div>
          <div class="card-valor skeleton" id="statTempInterna">--</div>
          <div class="card-legenda" id="legTempInterna"></div>
        </div>
        <div class="card">
          <div class="card-topo"><span class="card-label">Umid. interna</span><div class="card-icone"><svg data-lucide="droplet" style="width:16px;height:16px;"></svg></div></div>
          <div class="card-valor skeleton" id="statUmidInterna">--</div>
          <div class="card-legenda" id="legUmidInterna"></div>
        </div>
        <div class="card">
          <div class="card-topo"><span class="card-label">Temp. externa</span><div class="card-icone"><svg data-lucide="thermometer" style="width:16px;height:16px;"></svg></div></div>
          <div class="card-valor skeleton" id="statTempExterna">--</div>
          <div class="card-legenda" id="legTempExterna">Ambiente externo</div>
        </div>
        <div class="card">
          <div class="card-topo"><span class="card-label">Umid. externa</span><div class="card-icone"><svg data-lucide="droplets" style="width:16px;height:16px;"></svg></div></div>
          <div class="card-valor skeleton" id="statUmidExterna">--</div>
          <div class="card-legenda" id="legUmidExterna">Ambiente externo</div>
        </div>
        <div class="card esconder-offline">
          <div class="card-topo"><span class="card-label">Solo</span><div class="card-icone" id="soloIcone"><svg data-lucide="sprout" style="width:16px;height:16px;"></svg></div></div>
          <div class="card-valor skeleton" id="statSolo">--</div>
          <div class="card-legenda" id="legSolo"></div>
        </div>
        <div class="card esconder-offline">
          <div class="card-topo"><span class="card-label">Luz no ciclo</span><div class="card-icone"><svg data-lucide="lightbulb" style="width:16px;height:16px;"></svg></div></div>
          <div class="card-valor skeleton" id="luzContagem">--</div>
          <div class="card-legenda" id="luzLegenda"></div>
        </div>
      </div>

      <div class="auto-nota so-offline" id="notaOffline">
        <svg data-lucide="info"></svg>
        <div>O timer de luz continua rodando no rel&oacute;gio do ESP32 mesmo sem conex&atilde;o.</div>
      </div>

      <div class="barra-card">
        <div class="barra-topo">
          <span class="barra-titulo"><svg data-lucide="gauge"></svg>VPD (d&eacute;ficit de press&atilde;o de vapor)</span>
          <span class="barra-valor skeleton" id="vpdValor">-- kPa</span>
        </div>
        <div class="barra-trilho vpd"><div class="barra-marcador" id="vpdMarcador"></div></div>
        <div class="barra-rodape">
          <div class="barra-escala"><span>0</span><span>0,4</span><span>0,8</span><span>1,2</span><span>1,6</span><span>2,0+</span></div>
        </div>
        <div class="barra-status"><span class="pill pill-neutro" id="vpdZonaPill">--</span><span class="barra-ideal" id="vpdIdeal"></span></div>
      </div>

      <div class="barra-card">
        <div class="barra-topo">
          <span class="barra-titulo"><svg data-lucide="droplets"></svg><span id="soloLabelFaixa">Umidade do solo (ideal entre 50% e 60%)</span></span>
          <span class="barra-valor skeleton" id="soloValor">-- %</span>
        </div>
        <div class="barra-trilho solo"><div class="barra-marcador" id="soloMarcador"></div></div>
        <div class="barra-rodape">
          <div class="barra-escala"><span>0</span><span>25</span><span>50</span><span>75</span><span>100</span></div>
        </div>
        <div class="barra-status"><span class="pill pill-neutro" id="soloStatusPill">--</span></div>
        <p class="barra-nota" id="soloNota" hidden>O sensor de solo n&atilde;o respondeu nas &uacute;ltimas 3 leituras. Verifique o cabo e a alimenta&ccedil;&atilde;o do sensor capacitivo.</p>
      </div>

      <div class="barra-card esconder-offline" id="aguaCard">
        <div class="barra-topo">
          <span class="barra-titulo"><svg data-lucide="glass-water"></svg>Caixa d&apos;&aacute;gua (70 L)</span>
          <span class="barra-valor skeleton" id="aguaValor">-- L</span>
        </div>
        <div class="barra-trilho agua"><div class="agua-preenchimento" id="aguaPreenchimento"></div><div class="agua-limite" id="aguaLimite" title="Alerta de n&iacute;vel baixo"></div></div>
        <div class="barra-rodape">
          <div class="barra-escala"><span>0</span><span>25%</span><span>50%</span><span>75%</span><span>100%</span></div>
        </div>
        <div class="barra-status"><span class="pill pill-neutro" id="aguaStatusPill">--</span><span class="agua-detalhe" id="aguaDetalhe"></span><a class="barra-link" href="#caixa" data-ir-aba="caixa">Detalhes<svg data-lucide="arrow-right"></svg></a></div>
        <p class="barra-nota" id="aguaNota" hidden>O sensor da caixa d&apos;&aacute;gua (HC-SR04) n&atilde;o respondeu nas &uacute;ltimas 3 leituras. Verifique a alimenta&ccedil;&atilde;o em 5 V e o divisor de tens&atilde;o no pino ECHO.</p>
      </div>
    </div>

    <!-- ===================== ABA: COMANDOS ===================== -->
    <div class="aba" id="aba-comandos">
      <h1 class="titulo-aba">Comandos</h1>
      <p class="subtitulo-aba" style="margin-bottom:10px;">Os comandos v&atilde;o para uma fila; o ESP32 consulta a cada poucos segundos e aplica assim que chegar nele.</p>

      <div class="cmd-grupo">
        <div class="cmd-grupo-cabecalho">
          <div class="cmd-grupo-titulo"><svg data-lucide="sprout"></svg>Cultivo e automa&ccedil;&otilde;es</div>
          <span style="font-size:12px;color:var(--muted);">fase e controles autom&aacute;ticos</span>
        </div>
        <div class="rele-grid" id="cmdControles"></div>
      </div>

      <div class="cmd-grupo">
        <div class="cmd-grupo-cabecalho">
          <div class="cmd-grupo-titulo"><svg data-lucide="plug-zap"></svg>Rel&eacute;s</div>
          <span style="font-size:12px;color:var(--muted);">toque no interruptor para ligar/desligar</span>
        </div>
        <div class="rele-grid" id="cmdReles"></div>
      </div>
    </div>

    <!-- ===================== ABA: CAIXA D'ÁGUA ===================== -->
    <div class="aba" id="aba-caixa">
      <h1 class="titulo-aba">Caixa d&apos;&aacute;gua</h1>
      <p class="subtitulo-aba">N&iacute;vel da caixa medido pelo sensor ultrass&ocirc;nico (HC-SR04) na tampa.</p>
      <div class="cx-selos">
        <span class="cx-selo" id="cxVivo"><i></i>Aguardando o sensor</span>
        <span class="cx-selo cx-medida"><svg data-lucide="ruler"></svg>66 &times; 36 &times; 35 cm &middot; 70 L</span>
      </div>

      <div class="cx-grade">
        <div class="cx-tanque-card">
          <div id="cxTanque"></div>
          <div class="cx-legenda">Escala em litros &middot; a linha verde marca a capacidade de uso</div>
        </div>

        <div class="cx-volume" id="cxVolume">
          <div class="cx-volume-topo"><span>Volume agora</span><div class="card-icone"><svg data-lucide="glass-water" style="width:16px;height:16px;"></svg></div></div>
          <div class="cx-valor"><span id="cxLitros">--</span><small>de 70 L</small></div>
          <div class="cx-status"><span class="pill pill-neutro" id="cxPill">--</span><span id="cxAviso">Aviso abaixo de 20% (14 L)</span></div>
          <div class="barra-trilho agua"><div class="agua-preenchimento" id="cxPreenchimento"></div><div class="agua-limite" id="cxLimite" title="Aviso de n&iacute;vel baixo"></div></div>
          <div class="barra-rodape"><div class="barra-escala"><span>0</span><span>25%</span><span>50%</span><span>75%</span><span>100%</span></div></div>
          <div class="cx-indicadores">
            <div class="cx-ind"><span>Altura da &aacute;gua</span><strong id="cxAltura">—</strong></div>
            <div class="cx-ind"><span>Falta para encher</span><strong id="cxFalta">—</strong></div>
            <div class="cx-ind"><span>Varia&ccedil;&atilde;o em 24 h</span><strong id="cxVariacao">—</strong></div>
            <div class="cx-ind"><span>&Uacute;ltima leitura</span><strong id="cxUltima">—</strong></div>
          </div>
          <div class="cx-bloqueio" id="cxBloqueio" hidden><svg data-lucide="shield-alert"></svg>Irriga&ccedil;&atilde;o bloqueada para proteger a bomba (caixa abaixo de 10%).</div>
        </div>
      </div>

      <div class="grafico-card cx-grafico">
        <h2>Volume nas &uacute;ltimas 24 horas (litros)</h2>
        <div class="grafico-wrap">
          <div class="grafico-skeleton skeleton" id="graficoCaixaSkeleton"></div>
          <canvas id="graficoCaixa"></canvas>
          <div class="grafico-erro" id="graficoCaixaMsg" style="display:none;"></div>
        </div>
      </div>

      <div class="auto-nota">
        <svg data-lucide="info"></svg>
        <div>Caixa de paredes retas, 66 &times; 36 cm e 35 cm de altura: 70 L ficam a cerca de 29,5 cm do fundo (100%). Abaixo de 10% o ESP32 bloqueia o rel&eacute; de irriga&ccedil;&atilde;o para proteger a bomba. O aviso de caixa baixa &eacute; ajustado em <a href="#configuracoes" data-ir-aba="configuracoes" style="color:var(--accent);">Configura&ccedil;&otilde;es</a>.</div>
      </div>
    </div>

    <!-- ===================== ABA: HISTÓRICO ===================== -->
    <div class="aba" id="aba-historico">
      <h1 class="titulo-aba">Hist&oacute;rico</h1>
      <p class="subtitulo-aba">Gr&aacute;ficos dos sensores e do VPD. O rel&oacute;gio de eventos tem p&aacute;gina pr&oacute;pria.</p>

      <div class="grafico-topo">
        <h2 style="margin:0;">Per&iacute;odo</h2>
        <div class="periodo-botoes">
          <button class="periodo-botao ativo" data-periodo="24h" onclick="mudarPeriodo('24h')">24 horas</button>
          <button class="periodo-botao" data-periodo="7d" onclick="mudarPeriodo('7d')">1 semana</button>
          <button class="periodo-botao" data-periodo="30d" onclick="mudarPeriodo('30d')">1 m&ecirc;s</button>
          <a class="btn btn-sec btn-csv" id="btnExportarCsv" href="exportar_csv.php?periodo=24h" download><svg data-lucide="download"></svg>Exportar CSV</a>
        </div>
      </div>

      <div class="grafico-card">
        <h2>Temperatura (interna x externa)</h2>
        <div class="grafico-wrap">
          <div class="grafico-skeleton skeleton" id="graficoTemperaturaSkeleton"></div>
          <canvas id="graficoTemperatura"></canvas>
          <div class="grafico-erro" id="graficoTemperaturaMsg" style="display:none;"></div>
        </div>
      </div>

      <div class="grafico-card">
        <h2>Umidade (interna x externa)</h2>
        <div class="grafico-wrap">
          <div class="grafico-skeleton skeleton" id="graficoUmidadeSkeleton"></div>
          <canvas id="graficoUmidade"></canvas>
          <div class="grafico-erro" id="graficoUmidadeMsg" style="display:none;"></div>
        </div>
      </div>

      <div class="grafico-card">
        <h2>Umidade do solo</h2>
        <div class="grafico-wrap">
          <div class="grafico-skeleton skeleton" id="graficoSoloSkeleton"></div>
          <canvas id="graficoSolo"></canvas>
          <div class="grafico-erro" id="graficoSoloMsg" style="display:none;"></div>
        </div>
      </div>

      <div class="grafico-card">
        <h2>Caixa d&apos;&aacute;gua (litros)</h2>
        <div class="grafico-wrap">
          <div class="grafico-skeleton skeleton" id="graficoAguaSkeleton"></div>
          <canvas id="graficoAgua"></canvas>
          <div class="grafico-erro" id="graficoAguaMsg" style="display:none;"></div>
        </div>
      </div>

      <div class="grafico-card">
        <h2>VPD &mdash; d&eacute;ficit de press&atilde;o de vapor (kPa)</h2>
        <div class="grafico-wrap alto">
          <div class="grafico-skeleton skeleton" id="graficoVpdSkeleton"></div>
          <canvas id="graficoVpd"></canvas>
          <div class="grafico-erro" id="graficoVpdMsg" style="display:none;"></div>
        </div>
        <div class="vpd-legenda" id="vpdLegenda"></div>
      </div>

    </div>

    <!-- ===================== ABA: RELÓGIO DE EVENTOS ===================== -->
    <div class="aba" id="aba-relogio">
      <h1 class="titulo-aba">Rel&oacute;gio de eventos</h1>
      <p class="subtitulo-aba" style="margin-bottom:16px;">&Uacute;ltimas 24 horas. Toque num ponto ou num trecho colorido.</p>
      <div class="rr-resumo" id="rrResumo"></div>
      <section>
        <div class="roda-card">
          <div class="rr-filtros" id="rrFiltros">
            <button type="button" class="rr-filtro" data-rr-filtro="equipamentos" aria-pressed="true"><i style="background:var(--amber);"></i><i class="vazado"></i>Equipamentos</button>
            <button type="button" class="rr-filtro" data-rr-filtro="automacoes" aria-pressed="true"><i style="background:#5cc8b4;"></i>Automa&ccedil;&otilde;es</button>
            <button type="button" class="rr-filtro" data-rr-filtro="porta" aria-pressed="true"><i style="background:var(--accent);"></i>Porta</button>
            <button type="button" class="rr-filtro" data-rr-filtro="avisos" aria-pressed="true"><i style="background:var(--red);"></i>Avisos</button>
            <button type="button" class="rr-filtro" data-rr-filtro="fase" aria-pressed="true"><i style="background:#b48ee0;"></i>Fase</button>
          </div>
          <div class="roda-svg-wrap"><svg id="rodaEventos" viewBox="0 0 400 400" role="img" aria-label="Rel&oacute;gio de eventos das &uacute;ltimas 24 horas"></svg></div>
          <div class="rr-aneis" id="rrAneis"></div>
          <div class="rr-detalhe" id="rrDetalhe" hidden></div>
        </div>
      </section>
      <div id="rrLista"></div>
    </div>

    <!-- ===================== ABA: FOTOPERÍODO ===================== -->
    <div class="aba" id="aba-fotoperiodo">
      <h1 class="titulo-aba">Fotoper&iacute;odo</h1>
      <p class="subtitulo-aba">Central dos timers de luz. Escolha a hora em que a luz acende em cada fase &mdash; a dura&ccedil;&atilde;o do ciclo &eacute; fixa.</p>

      <div class="auto-status">
        <div class="card">
          <div class="card-topo"><span class="card-label">Fase ativa</span><div class="card-icone"><svg data-lucide="sprout" style="width:16px;height:16px;"></svg></div></div>
          <div class="card-valor" id="autoFaseAtiva">--</div>
          <div class="card-legenda" id="autoFaseSub"></div>
        </div>
        <div class="card">
          <div class="card-topo"><span class="card-label">Luz agora</span><div class="card-icone"><svg data-lucide="lightbulb" style="width:16px;height:16px;"></svg></div></div>
          <div class="card-valor" id="autoLuzAgora">--</div>
          <div class="card-legenda" id="autoLuzSub"></div>
        </div>
        <div class="card">
          <div class="card-topo"><span class="card-label">Pr&oacute;xima mudan&ccedil;a</span><div class="card-icone"><svg data-lucide="hourglass" style="width:16px;height:16px;"></svg></div></div>
          <div class="card-valor" id="autoProxima">--</div>
          <div class="card-legenda" id="autoProximaSub"></div>
        </div>
        <div class="card" id="autoTimerCard">
          <div class="card-topo"><span class="card-label">Timer no ESP32</span><div class="card-icone" id="autoTimerIcone"><svg data-lucide="circle-check" style="width:16px;height:16px;"></svg></div></div>
          <div class="card-valor" id="autoTimer">--</div>
          <div class="card-legenda" id="autoTimerSub"></div>
        </div>
      </div>

      <div class="auto-grid">
        <div class="timer-card" id="timer-vegetativo" data-modo="vegetativo"></div>
        <div class="timer-card" id="timer-floracao" data-modo="floracao"></div>
      </div>

      <div class="auto-nota">
        <svg data-lucide="info"></svg>
        <div>O novo hor&aacute;rio vai para a fila de comandos e o ESP32 aplica em alguns segundos (fica salvo na mem&oacute;ria do rel&oacute;gio, mesmo se faltar energia). Se a luz estiver em modo manual, o timer volta a valer quando o manual expirar &mdash; hoje em <span id="notaManualMin">30 min</span>, ajust&aacute;vel em <a href="#configuracoes" data-ir-aba="configuracoes" style="color:var(--accent);">Configura&ccedil;&otilde;es</a>.</div>
      </div>
    </div>

    <!-- ===================== ABA: DISPOSITIVO ===================== -->
    <div class="aba" id="aba-dispositivo">
      <h1 class="titulo-aba">Dispositivo</h1>
      <p class="subtitulo-aba">Conex&atilde;o do hardware com a sua conta.</p>

      <div class="disp-card" id="dispCard">
        <div class="disp-topo">
          <div class="card-icone disp-icone"><svg data-lucide="cpu"></svg></div>
          <div class="disp-nome"><h3 id="dispNome">Estufa principal</h3><small id="dispModelo">ESP32-S3</small><small id="dispMac"></small></div>
          <span class="selo-status" id="dispStatus"><i></i><span>--</span></span>
        </div>
        <div class="disp-grade">
          <div class="disp-item"><span>Firmware</span><strong id="dispFirmware">--</strong></div>
          <div class="disp-item"><span>&Uacute;ltimo contato</span><strong id="dispContato">--</strong></div>
          <div class="disp-item"><span>Sinal Wi-Fi</span><strong id="dispSinal">--</strong></div>
          <div class="disp-item"><span>Ligado h&aacute;</span><strong id="dispUptime">--</strong></div>
        </div>
        <p class="disp-rodape" id="dispRodape">--</p>
      </div>

      <div class="disp-grid">
        <section class="disp-secao" id="dispToken">
          <h3 class="disp-secao-titulo"><svg data-lucide="key-round"></svg>Token do hardware</h3>
          <strong class="disp-sub" id="tokTitulo">Token ativo</strong>
          <p class="disp-texto" id="tokTexto">C&oacute;digo &uacute;nico que o ESP32 usa para se identificar no servidor. Por seguran&ccedil;a, o token completo s&oacute; aparece uma vez, quando &eacute; gerado.</p>
          <div class="tok-caixa" id="tokCaixa"><code id="tokValor">--</code><button type="button" class="btn btn-sec btn-icone" id="btnTokCopiar" title="Copiar" hidden><svg data-lucide="copy"></svg></button></div>
          <p class="disp-meta" id="tokMeta"></p>
          <div class="disp-acoes" id="tokAcoes">
            <button type="button" class="btn btn-sec" id="btnTokGerar"><svg data-lucide="refresh-cw"></svg>Gerar novo</button>
            <button type="button" class="btn btn-sec btn-perigo" id="btnTokRevogar"><svg data-lucide="ban"></svg>Revogar</button>
          </div>
          <p class="disp-meta" id="tokAviso">Ao gerar um novo token ou revogar o atual, o ESP32 para de enviar dados at&eacute; ser configurado com o novo c&oacute;digo.</p>
        </section>

        <section class="disp-secao" id="dispVinculo">
          <h3 class="disp-secao-titulo"><svg data-lucide="link"></svg>Vincular novo dispositivo</h3>
          <p class="disp-texto">Conecte outro ESP32 sem editar o firmware. Gere um token de vincula&ccedil;&atilde;o e siga os passos:</p>
          <ol class="passos">
            <li><i>1</i><span>Ligue o ESP32 e conecte o celular &agrave; rede Wi-Fi &ldquo;SmartDemetra-Setup&rdquo;. Se ele j&aacute; estiver configurado, segure o bot&atilde;o do OLED ao ligar.</span></li>
            <li><i>2</i><span>Abra 192.168.4.1 no navegador, escolha a sua rede e cole o token.</span></li>
            <li><i>3</i><span>Aguarde a confirma&ccedil;&atilde;o aqui &mdash; o dispositivo aparece como Online.</span></li>
          </ol>
          <div class="vinc-codigo" id="vincCodigo" hidden>
            <span>Token de vincula&ccedil;&atilde;o</span>
            <code id="vincValor">----</code>
            <small id="vincExpira"></small>
          </div>
          <div class="disp-acoes um">
            <button type="button" class="btn btn-verde-sec" id="btnVincGerar"><svg data-lucide="plus"></svg>Gerar token de vincula&ccedil;&atilde;o</button>
          </div>
        </section>

        <section class="disp-secao" id="dispAtividade">
          <h3 class="disp-secao-titulo"><svg data-lucide="activity"></svg>Atividade recente</h3>
          <ul class="atividade" id="listaAtividade"><li class="vazio">carregando...</li></ul>
        </section>
      </div>
    </div>

    <!-- ===================== ABA: CONFIGURAÇÕES ===================== -->
    <div class="aba" id="aba-configuracoes">
      <h1 class="titulo-aba">Configura&ccedil;&otilde;es</h1>
      <p class="subtitulo-aba">Prefer&ecirc;ncias do painel e faixas de alerta. Ficam salvas no servidor e valem em qualquer aparelho.</p>

      <form id="formConfig" autocomplete="off">
        <div class="cfg-grupo">
          <div class="cfg-grupo-titulo"><svg data-lucide="layout-dashboard"></svg>Painel</div>
          <div class="cfg-linha">
            <div class="cfg-texto"><strong>Nome do cultivo</strong><small>Aparece no menu e na aba do navegador.</small></div>
            <div class="cfg-controle"><input type="text" name="nome_cultivo" maxlength="40" required></div>
          </div>
          <div class="cfg-linha">
            <div class="cfg-texto"><strong>P&aacute;gina inicial</strong><small>Qual p&aacute;gina abre ao entrar no painel.</small></div>
            <div class="cfg-controle">
              <select name="pagina_inicial">
                <option value="inicio">In&iacute;cio</option>
                <option value="relogio">Rel&oacute;gio de eventos</option>
                <option value="fotoperiodo">Fotoper&iacute;odo</option>
                <option value="comandos">Comandos</option>
                <option value="caixa">Caixa d&apos;&aacute;gua</option>
                <option value="historico">Hist&oacute;rico</option>
              </select>
            </div>
          </div>
          <div class="cfg-linha">
            <div class="cfg-texto"><strong>Atualiza&ccedil;&atilde;o autom&aacute;tica</strong><small>O ESP32 manda o estado atual a cada 5 s, ent&atilde;o 5 s deixa o painel ao vivo. Cada atualiza&ccedil;&atilde;o &eacute; leve: busca s&oacute; o que mudou.</small></div>
            <div class="cfg-controle">
              <select name="intervalo_atualizacao_seg">
                <option value="5">5 segundos</option>
                <option value="10">10 segundos</option>
                <option value="30">30 segundos</option>
                <option value="60">1 minuto</option>
                <option value="120">2 minutos</option>
                <option value="300">5 minutos</option>
                <option value="600">10 minutos</option>
              </select>
            </div>
          </div>
          <div class="cfg-linha">
            <div class="cfg-texto"><strong>Per&iacute;odo padr&atilde;o do hist&oacute;rico</strong><small>Per&iacute;odo selecionado ao abrir os gr&aacute;ficos.</small></div>
            <div class="cfg-controle">
              <select name="periodo_padrao_historico">
                <option value="24h">24 horas</option>
                <option value="7d">1 semana</option>
                <option value="30d">1 m&ecirc;s</option>
              </select>
            </div>
          </div>
        </div>

        <div class="cfg-grupo">
          <div class="cfg-grupo-titulo"><svg data-lucide="bell-ring"></svg>Faixas de alerta</div>
          <div class="cfg-linha">
            <div class="cfg-texto"><strong>Caixa d&apos;&aacute;gua baixa</strong><small>Abaixo deste n&iacute;vel a barra da caixa fica em alerta e o Telegram avisa (capacidade de 70 L).</small></div>
            <div class="cfg-controle"><span>abaixo de</span><input type="number" name="agua_alerta_min" min="0" max="90" step="1"><span>%</span></div>
          </div>
          <div class="cfg-linha">
            <div class="cfg-texto"><strong>Umidade do solo ideal</strong><small>Usada na barra do solo da p&aacute;gina In&iacute;cio.</small></div>
            <div class="cfg-controle"><input type="number" name="solo_ideal_min" min="0" max="100" step="1"><span>a</span><input type="number" name="solo_ideal_max" min="0" max="100" step="1"><span>%</span></div>
          </div>
          <div class="cfg-linha">
            <div class="cfg-texto"><strong>Temperatura interna</strong><small>Fora desta faixa o card de temperatura fica em alerta.</small></div>
            <div class="cfg-controle"><input type="text" inputmode="decimal" data-decimal name="temp_alerta_min" maxlength="5"><span>a</span><input type="text" inputmode="decimal" data-decimal name="temp_alerta_max" maxlength="5"><span>&deg;C</span></div>
          </div>
          <div class="cfg-linha">
            <div class="cfg-texto"><strong>Umidade interna</strong><small>Fora desta faixa o card de umidade fica em alerta.</small></div>
            <div class="cfg-controle"><input type="number" name="umid_alerta_min" min="0" max="100" step="1"><span>a</span><input type="number" name="umid_alerta_max" min="0" max="100" step="1"><span>%</span></div>
          </div>
          <div class="cfg-linha">
            <div class="cfg-texto"><strong>VPD ideal &middot; Vegeta&ccedil;&atilde;o</strong><small>O In&iacute;cio avalia o VPD pela faixa da fase ativa.</small></div>
            <div class="cfg-controle"><input type="text" inputmode="decimal" data-decimal name="vpd_vegetativo_min" maxlength="4"><span>a</span><input type="text" inputmode="decimal" data-decimal name="vpd_vegetativo_max" maxlength="4"><span>kPa</span></div>
          </div>
          <div class="cfg-linha">
            <div class="cfg-texto"><strong>VPD ideal &middot; Flora&ccedil;&atilde;o</strong><small>Faixa usada quando a flora&ccedil;&atilde;o est&aacute; ativa.</small></div>
            <div class="cfg-controle"><input type="text" inputmode="decimal" data-decimal name="vpd_floracao_min" maxlength="4"><span>a</span><input type="text" inputmode="decimal" data-decimal name="vpd_floracao_max" maxlength="4"><span>kPa</span></div>
          </div>
          <div class="cfg-linha">
            <div class="cfg-texto"><strong>Dispositivo sem dados</strong><small>Depois desse tempo sem leituras o painel mostra o aviso de offline.</small></div>
            <div class="cfg-controle">
              <select name="dispositivo_sem_dados_min">
                <option value="2">2 minutos</option>
                <option value="5">5 minutos</option>
                <option value="10">10 minutos</option>
                <option value="30">30 minutos</option>
              </select>
            </div>
          </div>
        </div>

        <div class="cfg-rodape" id="cfgRodape">
          <span class="cfg-rodape-txt">Altera&ccedil;&otilde;es n&atilde;o salvas</span>
          <button type="button" class="btn btn-sec" id="cfgDescartar">Descartar</button>
          <button type="submit" class="btn btn-primario" id="cfgSalvar" disabled><svg data-lucide="save"></svg>Salvar altera&ccedil;&otilde;es</button>
        </div>
      </form>

      <div class="cfg-grupo" id="grupoNotif">
        <div class="cfg-grupo-titulo"><svg data-lucide="send"></svg>Notifica&ccedil;&otilde;es</div>
        <div class="cfg-linha cfg-linha-tg">
          <div class="cfg-texto"><strong>Telegram</strong><small id="tgInfo">Os avisos chegam pelo bot do Smart Demetra.</small></div>
          <div class="cfg-controle cfg-tg">
            <div class="tg-status" id="tgStatus"><i></i><span>carregando...</span></div>
            <button type="button" class="btn btn-sec" id="btnTgAcao" hidden>Enviar teste</button>
          </div>
        </div>
        <div class="cfg-subpainel" id="tgVinculo" hidden>
          <p class="cfg-nota" id="tgVinculoTxt">Abra o link no celular e toque em <strong>Iniciar</strong>. O painel reconhece o chat sozinho.</p>
          <div class="cfg-acoes">
            <button type="button" class="btn btn-sec" id="btnTgCancelar">Cancelar</button>
            <a class="btn btn-primario" id="tgLink" href="#" target="_blank" rel="noopener" style="text-decoration:none;"><svg data-lucide="send"></svg>Abrir no Telegram</a>
          </div>
        </div>
        <div class="cfg-linha cfg-avisar"><div class="cfg-texto"><strong>Avisar quando</strong></div></div>
        <div class="cfg-linha cfg-switch">
          <div class="cfg-texto"><span>Uma leitura sair da faixa</span><small>Temperatura, umidade, solo e VPD</small></div>
          <button type="button" class="switch verde" role="switch" aria-checked="false" data-notif="notif_faixa" aria-label="Uma leitura sair da faixa"></button>
        </div>
        <div class="cfg-linha cfg-switch">
          <div class="cfg-texto"><span>O dispositivo ficar offline</span><small>Ap&oacute;s o tempo sem dados definido acima</small></div>
          <button type="button" class="switch verde" role="switch" aria-checked="false" data-notif="notif_offline" aria-label="O dispositivo ficar offline"></button>
        </div>
        <div class="cfg-linha cfg-switch">
          <div class="cfg-texto"><span>Um timer n&atilde;o for confirmado</span><small>ESP32 sem confirmar em 5 min</small></div>
          <button type="button" class="switch verde" role="switch" aria-checked="false" data-notif="notif_timer" aria-label="Um timer n&atilde;o for confirmado"></button>
        </div>
        <div class="cfg-linha cfg-switch">
          <div class="cfg-texto"><span>Resumo di&aacute;rio &agrave;s 08:00</span><small>M&eacute;dias do dia anterior e dia do ciclo</small></div>
          <button type="button" class="switch verde" role="switch" aria-checked="false" data-notif="notif_resumo" aria-label="Resumo di&aacute;rio &agrave;s 08:00"></button>
        </div>
        <p class="cfg-nota" style="padding:4px 0 14px;">Avisos do mesmo tipo s&atilde;o enviados no m&aacute;ximo a cada 30 min, e voc&ecirc; recebe outro quando a situa&ccedil;&atilde;o se normaliza.</p>
      </div>

      <div class="cfg-grupo" id="grupoLuz">
        <div class="cfg-grupo-titulo"><svg data-lucide="lightbulb"></svg>Luz</div>
        <div class="cfg-linha">
          <div class="cfg-texto"><strong>Dura&ccedil;&atilde;o do modo manual</strong><small>Quanto tempo a luz fica no modo manual antes de voltar ao timer. Enviado ao ESP32 pela fila de comandos.</small><small id="luzManualStatus"></small></div>
          <div class="cfg-controle">
            <select id="luzManualMin" aria-label="Dura&ccedil;&atilde;o do modo manual">
              <option value="15">15 minutos</option>
              <option value="30">30 minutos</option>
              <option value="60">60 minutos</option>
              <option value="120">120 minutos</option>
            </select>
          </div>
        </div>
      </div>

      <div class="cfg-grupo">
        <div class="cfg-grupo-titulo"><svg data-lucide="shield"></svg>Conta</div>
        <div class="cfg-linha">
          <div class="cfg-texto"><strong>Usu&aacute;rio</strong><small>Logado como <?= htmlspecialchars($usuarioLogado, ENT_QUOTES, 'UTF-8') ?><?= $ehAdmin ? ' (administrador)' : '' ?>.</small></div>
          <div class="cfg-controle"><a href="logout.php" class="btn btn-sec" style="width:100%;text-decoration:none;"><svg data-lucide="log-out"></svg>Sair</a></div>
        </div>
        <div class="cfg-linha">
          <div class="cfg-texto"><strong>Alterar senha</strong><small id="senhaInfo">Troque a senha usada para entrar no painel.</small></div>
          <div class="cfg-controle"><button type="button" class="btn btn-sec" id="btnAbrirSenha" style="width:100%;"><svg data-lucide="key-round"></svg>Alterar</button></div>
        </div>
        <form class="cfg-subpainel" id="painelSenha" hidden autocomplete="off">
          <div class="cfg-campos-senha">
            <label class="campo"><span class="campo-label">Senha atual</span><input type="password" name="senha_atual" autocomplete="current-password" required></label>
            <label class="campo"><span class="campo-label">Nova senha</span><input type="password" name="nova_senha" autocomplete="new-password" minlength="8" required></label>
            <label class="campo"><span class="campo-label">Confirmar nova senha</span><input type="password" name="confirma" autocomplete="new-password" required></label>
          </div>
          <p class="cfg-nota">M&iacute;nimo de 8 caracteres, com letras e n&uacute;meros. Os outros aparelhos conectados vOs outros aparelhos conectados com "Lembrar de mim" precisar&atilde;o entrar de novo.atilde;o precisar entrar de novo.</p>
          <div class="cfg-acoes">
            <button type="button" class="btn btn-sec" id="btnCancelarSenha">Cancelar</button>
            <button type="submit" class="btn btn-primario"><svg data-lucide="save"></svg>Salvar senha</button>
          </div>
        </form>
      </div>

<?php if ($ehAdmin): ?>
      <div class="cfg-grupo" id="grupoUsuarios">
        <div class="cfg-grupo-titulo"><svg data-lucide="users"></svg>Usu&aacute;rios <span class="pill pill-neutro" id="conviteStatus" style="margin-left:auto;">--</span></div>
        <div class="cfg-linha">
          <div class="cfg-texto"><strong>C&oacute;digo de convite</strong><small id="conviteInfo">Quem souber este c&oacute;digo consegue criar uma conta na tela de login. Deixe vazio para desativar o cadastro.</small></div>
          <div class="cfg-controle cfg-convite">
            <input type="text" id="conviteCodigo" maxlength="40" placeholder="sem c&oacute;digo" autocomplete="off" spellcheck="false">
            <button type="button" class="btn btn-sec btn-icone" id="btnGerarConvite" title="Gerar c&oacute;digo"><svg data-lucide="refresh-cw"></svg></button>
          </div>
        </div>
        <div class="cfg-acoes" id="conviteAcoes" style="padding-bottom:12px;">
          <button type="button" class="btn btn-sec" id="btnCopiarConvite"><svg data-lucide="copy"></svg>Copiar</button>
          <button type="button" class="btn btn-primario" id="btnSalvarConvite"><svg data-lucide="save"></svg>Salvar c&oacute;digo</button>
        </div>
        <div class="cfg-temp" id="senhaTemporaria" hidden></div>
        <div id="listaUsuarios"><div class="cfg-linha"><div class="cfg-texto"><small>carregando...</small></div></div></div>
      </div>
<?php endif; ?>
    </div>

<?php require __DIR__ . '/partials/rodape.php'; ?>

<script src="app.js?v=<?= (int) @filemtime(__DIR__ . '/app.js') ?>"></script>
</body>
</html>
