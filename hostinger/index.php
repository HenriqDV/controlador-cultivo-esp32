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
      <h1 class="titulo-aba sem-sub">In&iacute;cio</h1>
      <div class="selos-status">
        <span class="selo-status" id="seloConexao"><i></i><span>verificando…</span></span>
        <span class="selo-status neutro" id="seloFase"><svg data-lucide="sprout"></svg><span>--</span></span>
        <span class="selo-status neutro" id="seloLuz"><svg data-lucide="sun"></svg><span><span class="skeleton" id="luzContagem">--</span><span id="luzLegenda"></span></span></span>
      </div>

      <div class="aviso-offline" id="avisoOffline" hidden>
        <div class="aviso-offline-icone"><svg data-lucide="wifi-off"></svg></div>
        <div>
          <strong>O ESP32 parou de enviar leituras</strong>
          <p id="avisoOfflineTexto">&Uacute;ltima leitura &agrave;s --:--. Verifique a energia e o Wi-Fi do dispositivo. Os valores abaixo s&atilde;o os &uacute;ltimos recebidos.</p>
          <a href="#dispositivo" data-ir-aba="dispositivo">Ver dispositivo &rarr;</a>
        </div>
      </div>

      <!-- O que pede atenção agora (desvios que duram 15 min ou mais) -->
      <section class="ini-status" id="iniStatus" aria-live="polite">
        <div class="ini-status-topo">
          <span class="ini-status-icone" id="iniStatusIcone"><svg data-lucide="circle-check"></svg></span>
          <h2 id="iniStatusTitulo">Verificando…</h2>
          <span class="ini-status-ok" id="iniStatusOk"></span>
        </div>
        <div class="ini-status-lista" id="iniStatusLista"></div>
      </section>

      <!-- Ambiente interno: últimas 6, 3 ou 1 hora, com luz e equipamentos no mesmo eixo -->
      <section class="h6-card" id="hist6" aria-label="Ambiente interno nas &uacute;ltimas horas">
        <div class="h6-topo">
          <h2>Ambiente interno</h2>
          <div class="periodo-botoes h6-janela" id="h6Janela" role="group" aria-label="Janela do gr&aacute;fico">
            <button type="button" class="periodo-botao ativo" data-h6-horas="6" aria-pressed="true">6 h</button>
            <button type="button" class="periodo-botao" data-h6-horas="3" aria-pressed="false">3 h</button>
            <button type="button" class="periodo-botao" data-h6-horas="1" aria-pressed="false">1 h</button>
          </div>
          <div class="h6-legenda">
            <span><i class="h6-leg-noite"></i>luz apagada</span>
            <span><i class="h6-leg-faixa"></i>faixa ideal</span>
          </div>
          <a class="barra-link" href="#historico" data-ir-aba="historico">Hist&oacute;rico<svg data-lucide="arrow-right"></svg></a>
        </div>
        <div class="h6-linhas" id="hist6Linhas">
          <div class="h6-linha" data-h6="temp">
            <div class="h6-info">
              <span class="h6-rotulo"><i style="background:#4CAF50;"></i>Temperatura</span>
              <div class="h6-valor-linha"><strong class="h6-valor skeleton" id="h6TempValor">--</strong><span class="h6-tend" id="h6TempTend"></span></div>
              <small class="h6-sub" id="h6TempSub"></small>
            </div>
            <div class="h6-grafico"><canvas id="h6TempGrafico"></canvas></div>
            <div class="h6-fora"><span>fora da faixa hoje</span><strong id="h6TempFora">--</strong></div>
          </div>
          <div class="h6-linha" data-h6="umid">
            <div class="h6-info">
              <span class="h6-rotulo"><i style="background:#7fa9c2;"></i>Umidade</span>
              <div class="h6-valor-linha"><strong class="h6-valor skeleton" id="h6UmidValor">--</strong><span class="h6-tend" id="h6UmidTend"></span></div>
              <small class="h6-sub" id="h6UmidSub"></small>
            </div>
            <div class="h6-grafico"><canvas id="h6UmidGrafico"></canvas></div>
            <div class="h6-fora"><span>fora da faixa hoje</span><strong id="h6UmidFora">--</strong></div>
          </div>
          <div class="h6-linha" data-h6="vpd">
            <div class="h6-info">
              <span class="h6-rotulo"><i style="background:#eef1ee;"></i>VPD</span>
              <div class="h6-valor-linha"><strong class="h6-valor skeleton" id="h6VpdValor">--</strong><span class="h6-tend" id="h6VpdTend"></span></div>
              <small class="h6-sub" id="h6VpdSub"></small>
            </div>
            <div class="h6-grafico"><canvas id="h6VpdGrafico"></canvas></div>
            <div class="h6-fora"><span>fora da faixa hoje</span><strong id="h6VpdFora">--</strong></div>
          </div>
          <div class="h6-equip" id="h6Equip"></div>
          <div class="h6-eixo" id="hist6Eixo"><span></span><span></span><span></span><span></span></div>
        </div>
        <p class="h6-vazio" id="hist6Vazio" hidden>Sem leituras nesse per&iacute;odo.</p>
      </section>

      <!-- Ambiente externo: uma faixa fina, logo abaixo do ambiente interno -->
      <div class="ini-externo" aria-label="Ambiente externo">
        <span class="ini-ext-titulo"><svg data-lucide="cloud-sun"></svg>Externo</span>
        <div class="ini-ext">
          <i class="ini-ponto" style="background:#e8c977;"></i>
          <strong class="ini-ext-valor skeleton" id="statTempExterna">--</strong>
          <small id="legTempExterna"></small>
        </div>
        <div class="ini-ext">
          <i class="ini-ponto" style="background:#b58bc9;"></i>
          <strong class="ini-ext-valor skeleton" id="statUmidExterna">--</strong>
          <small id="legUmidExterna"></small>
        </div>
      </div>

      <!-- Recursos: solo e caixa d'água, com projeção -->
      <div class="ini-grade2">
        <section class="ini-card" aria-label="Solo">
          <div class="ini-card-topo">
            <span class="card-icone"><svg data-lucide="sprout" style="width:16px;height:16px;"></svg></span>
            <h2>Solo</h2>
            <span class="ini-card-extra" id="soloLabelFaixa">ideal 50&ndash;60%</span>
          </div>
          <div class="ini-valor-linha"><span class="ini-valor skeleton" id="soloValor">-- %</span><span class="pill pill-neutro" id="soloStatusPill">--</span></div>
          <div class="barra-trilho solo"><div class="barra-marcador" id="soloMarcador"></div></div>
          <div class="barra-rodape"><div class="barra-escala"><span>0</span><span>25</span><span>50</span><span>75</span><span>100</span></div></div>
          <p class="barra-nota" id="soloNota" hidden>O sensor de solo n&atilde;o respondeu nas &uacute;ltimas 3 leituras. Verifique o cabo e a alimenta&ccedil;&atilde;o do sensor capacitivo.</p>
          <div class="ini-dados">
            <div><span>&Uacute;ltima irriga&ccedil;&atilde;o</span><strong id="soloUltIrrig">--</strong></div>
            <div><span>Ritmo</span><strong id="soloTaxa">--</strong></div>
            <div><span id="soloPrevisaoRotulo">Chega ao m&iacute;nimo</span><strong id="soloPrevisao">--</strong></div>
          </div>
        </section>

        <section class="ini-card" id="aguaCard" aria-label="Caixa d&apos;&aacute;gua">
          <div class="ini-card-topo">
            <span class="card-icone"><svg data-lucide="glass-water" style="width:16px;height:16px;"></svg></span>
            <h2>Caixa d&apos;&aacute;gua</h2>
            <a class="barra-link" href="#caixa" data-ir-aba="caixa">Detalhes<svg data-lucide="arrow-right"></svg></a>
          </div>
          <div class="ini-valor-linha"><span class="ini-valor skeleton" id="aguaValor">-- L</span><span class="pill pill-neutro" id="aguaStatusPill">--</span></div>
          <div class="barra-trilho agua"><div class="agua-preenchimento" id="aguaPreenchimento"></div><div class="agua-limite" id="aguaLimite" title="Alerta de n&iacute;vel baixo"></div></div>
          <div class="barra-rodape"><div class="barra-escala"><span>0</span><span>25%</span><span>50%</span><span>75%</span><span>100%</span></div></div>
          <span class="agua-detalhe" id="aguaDetalhe"></span>
          <p class="barra-nota" id="aguaNota" hidden>O sensor da caixa d&apos;&aacute;gua (HC-SR04) n&atilde;o respondeu nas &uacute;ltimas 3 leituras. Verifique a alimenta&ccedil;&atilde;o em 5 V e o divisor de tens&atilde;o no pino ECHO.</p>
          <div class="ini-dados">
            <div><span>Consumo</span><strong id="aguaConsumo">--</strong></div>
            <div><span>Autonomia</span><strong id="aguaAutonomia">--</strong></div>
            <div><span>Encher at&eacute;</span><strong id="aguaEncher">--</strong></div>
          </div>
        </section>
      </div>

      <!-- Resumo das últimas 24 horas -->
      <div class="ini-grade2">
        <section class="ini-card" aria-label="Luz acesa e apagada nas &uacute;ltimas 24 horas">
          <div class="ini-card-topo">
            <span class="card-icone ini-icone-ambar"><svg data-lucide="sun" style="width:16px;height:16px;"></svg></span>
            <h2>Luz acesa &times; apagada &middot; 24h</h2>
          </div>
          <table class="ini-tabela">
            <thead><tr><th scope="col">M&eacute;dia</th><th scope="col"><svg data-lucide="sun"></svg>Acesa</th><th scope="col"><svg data-lucide="moon"></svg>Apagada</th></tr></thead>
            <tbody id="iniLuzTabela"><tr><td colspan="3" class="ini-vazio">carregando…</td></tr></tbody>
          </table>
          <div class="ini-dif"><span>Diferen&ccedil;a dia/noite (DIF)</span><strong id="iniDif">--</strong></div>
          <small class="ini-nota" id="iniLuzFonte"></small>
        </section>

        <section class="ini-card" aria-label="Equipamentos nas &uacute;ltimas 24 horas">
          <div class="ini-card-topo">
            <span class="card-icone"><svg data-lucide="plug-zap" style="width:16px;height:16px;"></svg></span>
            <h2>Equipamentos &middot; tempo ligado em 24h</h2>
          </div>
          <div class="ini-equip" id="iniEquipLista"><p class="ini-vazio">carregando…</p></div>
        </section>
      </div>

      <div class="ini-saude" id="iniSaude"></div>
    </div>

    <!-- ===================== ABA: COMANDOS ===================== -->
    <div class="aba" id="aba-comandos">
      <div class="aba-topo">
        <h1 class="titulo-aba">Comandos</h1>
        <div class="aba-topo-selos">
          <span class="selo-status neutro" id="cmdSeloFila"><svg data-lucide="send"></svg><span>ESP32 aplica em segundos</span></span>
        </div>
      </div>

      <!-- 1) Faixa de VPD: a referência usada pelas duas automações -->
      <div class="cmd-grupo">
        <div class="cmd-grupo-cabecalho">
          <div class="cmd-grupo-titulo"><svg data-lucide="gauge"></svg>Faixa de VPD</div>
          <span class="cmd-grupo-resumo">usada pelas automa&ccedil;&otilde;es</span>
        </div>
        <div class="rele-grid" id="cmdControles"></div>
      </div>

      <!-- 2) Automações: umidificador e aquecedor -->
      <div class="cmd-grupo">
        <div class="cmd-grupo-cabecalho">
          <div class="cmd-grupo-titulo"><svg data-lucide="bot"></svg>Automa&ccedil;&otilde;es</div>
          <span class="cmd-grupo-resumo" id="cmdAutoResumo"></span>
        </div>
        <div class="cmd-auto-grid" id="cmdAutomacoes"></div>
      </div>

      <!-- 3) Controle manual de cada relé -->
      <div class="cmd-grupo">
        <div class="cmd-grupo-cabecalho">
          <div class="cmd-grupo-titulo"><svg data-lucide="plug-zap"></svg>Rel&eacute;s &middot; controle manual</div>
          <span class="cmd-grupo-resumo" id="cmdRelesResumo"></span>
        </div>
        <div class="rele-grid" id="cmdReles"></div>
      </div>
    </div>

    <!-- ===================== ABA: CAIXA D'ÁGUA ===================== -->
    <div class="aba" id="aba-caixa">
      <div class="aba-topo">
        <h1 class="titulo-aba">Caixa d&apos;&aacute;gua</h1>
        <div class="aba-topo-selos">
          <span class="cx-selo" id="cxVivo"><i></i>Aguardando o sensor</span>
          <span class="cx-selo cx-medida"><svg data-lucide="ruler"></svg>70 L &middot; 66 &times; 36 &times; 35 cm</span>
        </div>
      </div>

      <div class="cx-grade">
        <div class="cx-tanque-card">
          <div id="cxTanque"></div>
          <div class="cx-legenda"><span><i class="cx-leg-cap"></i>capacidade 70 L</span><span><i class="cx-leg-min"></i>aviso de n&iacute;vel baixo</span></div>
        </div>

        <div class="cx-volume" id="cxVolume">
          <div class="cx-volume-topo"><span>Volume agora</span><div class="card-icone"><svg data-lucide="glass-water" style="width:16px;height:16px;"></svg></div></div>
          <div class="cx-valor"><span id="cxLitros">--</span><small>de 70 L</small></div>
          <div class="cx-status"><span class="pill pill-neutro" id="cxPill">--</span><span id="cxAviso">Aviso abaixo de 20% (14 L)</span></div>
          <div class="barra-trilho agua"><div class="agua-preenchimento" id="cxPreenchimento"></div><div class="agua-limite" id="cxLimite" title="Aviso de n&iacute;vel baixo"></div></div>
          <div class="barra-rodape"><div class="barra-escala"><span>0</span><span>25%</span><span>50%</span><span>75%</span><span>100%</span></div></div>
          <div class="cx-indicadores">
            <div class="cx-ind destaque"><span><svg data-lucide="hourglass"></svg>Autonomia</span><strong id="cxAutonomia">—</strong></div>
            <div class="cx-ind destaque"><span><svg data-lucide="calendar-clock"></svg>Encher at&eacute;</span><strong id="cxEncher">—</strong></div>
            <div class="cx-ind"><span><svg data-lucide="trending-down"></svg>Consumo m&eacute;dio</span><strong id="cxConsumo">—</strong></div>
            <div class="cx-ind"><span><svg data-lucide="arrow-down-up"></svg>Varia&ccedil;&atilde;o em 24 h</span><strong id="cxVariacao">—</strong></div>
            <div class="cx-ind"><span><svg data-lucide="ruler"></svg>Altura da &aacute;gua</span><strong id="cxAltura">—</strong></div>
            <div class="cx-ind"><span><svg data-lucide="droplets"></svg>Falta para encher</span><strong id="cxFalta">—</strong></div>
          </div>
          <div class="cx-bloqueio" id="cxBloqueio" hidden><svg data-lucide="shield-alert"></svg>Irriga&ccedil;&atilde;o bloqueada: caixa abaixo de 10%.</div>
        </div>
      </div>

      <div class="grafico-card cx-grafico">
        <div class="grafico-cab"><h2>Volume &middot; 24 h</h2><div class="grafico-stats" id="cxGraficoStats"></div></div>
        <div class="grafico-wrap">
          <div class="grafico-skeleton skeleton" id="graficoCaixaSkeleton"></div>
          <canvas id="graficoCaixa"></canvas>
          <div class="grafico-erro" id="graficoCaixaMsg" style="display:none;"></div>
        </div>
      </div>

      <details class="ajuda"><summary><svg data-lucide="info"></svg>Como o n&iacute;vel &eacute; medido<svg data-lucide="chevron-down" class="ajuda-seta"></svg></summary><div><ul><li>Sensor ultrass&ocirc;nico (HC-SR04) na tampa mede a dist&acirc;ncia at&eacute; a &aacute;gua.</li><li>100% = 70 L, a cerca de 29,5 cm do fundo.</li><li>Abaixo de 10% o ESP32 bloqueia a irriga&ccedil;&atilde;o para proteger a bomba.</li><li>Autonomia e consumo usam a m&eacute;dia dos &uacute;ltimos 7 dias.</li><li>O aviso de n&iacute;vel baixo &eacute; ajustado em <a href="#configuracoes" data-ir-aba="configuracoes">Configura&ccedil;&otilde;es</a>.</li></ul></div></details>
    </div>

    <!-- ===================== ABA: HISTÓRICO ===================== -->
    <div class="aba" id="aba-historico">
      <div class="aba-topo">
        <h1 class="titulo-aba">Hist&oacute;rico</h1>
        <div class="periodo-botoes" role="group" aria-label="Per&iacute;odo">
          <button class="periodo-botao ativo" data-periodo="24h" onclick="mudarPeriodo('24h')">24 h</button>
          <button class="periodo-botao" data-periodo="7d" onclick="mudarPeriodo('7d')">7 dias</button>
          <button class="periodo-botao" data-periodo="30d" onclick="mudarPeriodo('30d')">30 dias</button>
          <a class="btn btn-sec btn-csv" id="btnExportarCsv" href="exportar_csv.php?periodo=24h" download title="Exportar CSV" aria-label="Exportar CSV"><svg data-lucide="download"></svg><span>CSV</span></a>
        </div>
      </div>
      <nav class="hist-atalhos" aria-label="Gr&aacute;ficos">
        <a href="#hist-temp"><i style="background:#4CAF50;"></i>Temperatura</a>
        <a href="#hist-umid"><i style="background:#7fa9c2;"></i>Umidade</a>
        <a href="#hist-vpd"><i style="background:#eef1ee;"></i>VPD</a>
        <a href="#hist-solo"><i style="background:#b98d5e;"></i>Solo</a>
        <a href="#hist-agua"><i style="background:#6fb3e0;"></i>Caixa</a>
      </nav>

      <div class="grafico-card" id="hist-temp">
        <div class="grafico-cab"><h2>Temperatura</h2><div class="grafico-stats" id="statsTemperatura"></div></div>
        <div class="grafico-wrap">
          <div class="grafico-skeleton skeleton" id="graficoTemperaturaSkeleton"></div>
          <canvas id="graficoTemperatura"></canvas>
          <div class="grafico-erro" id="graficoTemperaturaMsg" style="display:none;"></div>
        </div>
      </div>

      <div class="grafico-card" id="hist-umid">
        <div class="grafico-cab"><h2>Umidade do ar</h2><div class="grafico-stats" id="statsUmidade"></div></div>
        <div class="grafico-wrap">
          <div class="grafico-skeleton skeleton" id="graficoUmidadeSkeleton"></div>
          <canvas id="graficoUmidade"></canvas>
          <div class="grafico-erro" id="graficoUmidadeMsg" style="display:none;"></div>
        </div>
      </div>

      <div class="grafico-card" id="hist-vpd">
        <div class="grafico-cab"><h2>VPD</h2><div class="grafico-stats" id="statsVpd"></div></div>
        <div class="grafico-wrap alto">
          <div class="grafico-skeleton skeleton" id="graficoVpdSkeleton"></div>
          <canvas id="graficoVpd"></canvas>
          <div class="grafico-erro" id="graficoVpdMsg" style="display:none;"></div>
        </div>
        <div class="vpd-legenda" id="vpdLegenda"></div>
      </div>

      <div class="grafico-card" id="hist-solo">
        <div class="grafico-cab"><h2>Umidade do solo</h2><div class="grafico-stats" id="statsSolo"></div></div>
        <div class="grafico-wrap">
          <div class="grafico-skeleton skeleton" id="graficoSoloSkeleton"></div>
          <canvas id="graficoSolo"></canvas>
          <div class="grafico-erro" id="graficoSoloMsg" style="display:none;"></div>
        </div>
      </div>

      <div class="grafico-card" id="hist-agua">
        <div class="grafico-cab"><h2>Caixa d&apos;&aacute;gua</h2><div class="grafico-stats" id="statsAgua"></div></div>
        <div class="grafico-wrap">
          <div class="grafico-skeleton skeleton" id="graficoAguaSkeleton"></div>
          <canvas id="graficoAgua"></canvas>
          <div class="grafico-erro" id="graficoAguaMsg" style="display:none;"></div>
        </div>
      </div>

    </div>

    <!-- ===================== ABA: RELÓGIO DE EVENTOS ===================== -->
    <div class="aba" id="aba-relogio">
      <h1 class="titulo-aba">Rel&oacute;gio de eventos</h1>
      <p class="subtitulo-aba" style="margin-bottom:14px;">&Uacute;ltimas 24 h &middot; toque num ponto ou trecho para ver o detalhe</p>
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
      <p class="subtitulo-aba">Hora em que a luz acende em cada fase &middot; a dura&ccedil;&atilde;o &eacute; fixa</p>

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

      <details class="ajuda"><summary><svg data-lucide="info"></svg>Como o timer funciona<svg data-lucide="chevron-down" class="ajuda-seta"></svg></summary><div><ul><li>O hor&aacute;rio salvo vai para a fila e o ESP32 aplica em segundos.</li><li>Fica gravado no rel&oacute;gio do ESP32, mesmo sem energia ou internet.</li><li>Luz em modo manual: o timer volta a valer em <span id="notaManualMin">30 min</span> (ajuste em <a href="#configuracoes" data-ir-aba="configuracoes">Configura&ccedil;&otilde;es</a>).</li><li>Na troca de fase, a contagem de dias do ciclo recome&ccedil;a.</li></ul></div></details>
    </div>

    <!-- ===================== ABA: DISPOSITIVO ===================== -->
    <div class="aba" id="aba-dispositivo">
      <h1 class="titulo-aba sem-sub">Dispositivo</h1>

      <div class="disp-card" id="dispCard">
        <div class="disp-topo">
          <div class="card-icone disp-icone"><svg data-lucide="cpu"></svg></div>
          <div class="disp-nome"><h3 id="dispNome">Estufa principal</h3><small id="dispModelo">ESP32-S3</small><small id="dispMac"></small></div>
          <span class="selo-status" id="dispStatus"><i></i><span>--</span></span>
        </div>
        <div class="disp-grade">
          <div class="disp-item"><span><svg data-lucide="package"></svg>Firmware</span><strong id="dispFirmware">--</strong></div>
          <div class="disp-item"><span><svg data-lucide="radio"></svg>&Uacute;ltimo contato</span><strong id="dispContato">--</strong></div>
          <div class="disp-item"><span><svg data-lucide="wifi"></svg>Sinal Wi-Fi</span><strong id="dispSinal">--</strong><div class="sinal-barras" id="dispSinalBarras" aria-hidden="true"><i></i><i></i><i></i><i></i></div></div>
          <div class="disp-item"><span><svg data-lucide="power"></svg>Ligado h&aacute;</span><strong id="dispUptime">--</strong></div>
        </div>
        <p class="disp-rodape" id="dispRodape">--</p>
      </div>

      <div class="disp-grid">
        <section class="disp-secao" id="dispToken">
          <h3 class="disp-secao-titulo"><svg data-lucide="key-round"></svg>Token do hardware</h3>
          <strong class="disp-sub" id="tokTitulo">Token ativo</strong>
          <p class="disp-texto" id="tokTexto">Identifica o ESP32 no servidor. O c&oacute;digo completo s&oacute; aparece ao ser gerado.</p>
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
                    <ol class="passos">
            <li><i>1</i><span><strong>Conecte ao Wi-Fi do ESP32</strong>Rede &ldquo;SmartDemetra-Setup&rdquo;. J&aacute; configurado? Segure o bot&atilde;o do OLED ao ligar.</span></li>
            <li><i>2</i><span><strong>Abra 192.168.4.1</strong>Escolha a sua rede e cole o token.</span></li>
            <li><i>3</i><span><strong>Aguarde aqui</strong>O dispositivo aparece como Online.</span></li>
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
      <h1 class="titulo-aba sem-sub">Configura&ccedil;&otilde;es</h1>
      <nav class="cfg-atalhos" aria-label="Se&ccedil;&otilde;es">
        <a href="#cfg-painel"><svg data-lucide="layout-dashboard"></svg>Painel</a>
        <a href="#cfg-faixas"><svg data-lucide="bell-ring"></svg>Faixas</a>
        <a href="#grupoNotif"><svg data-lucide="send"></svg>Notifica&ccedil;&otilde;es</a>
        <a href="#grupoLuz"><svg data-lucide="lightbulb"></svg>Luz</a>
        <a href="#cfg-conta"><svg data-lucide="shield"></svg>Conta</a>
<?php if ($ehAdmin): ?>
        <a href="#grupoUsuarios"><svg data-lucide="users"></svg>Usu&aacute;rios</a>
<?php endif; ?>
      </nav>

      <form id="formConfig" autocomplete="off">
        <div class="cfg-grupo" id="cfg-painel">
          <div class="cfg-grupo-titulo"><svg data-lucide="layout-dashboard"></svg>Painel</div>
          <div class="cfg-linha">
            <div class="cfg-texto"><strong>Nome do cultivo</strong></div>
            <div class="cfg-controle"><input type="text" name="nome_cultivo" maxlength="40" required></div>
          </div>
          <div class="cfg-linha">
            <div class="cfg-texto"><strong>P&aacute;gina inicial</strong><small>Abre ao entrar</small></div>
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
            <div class="cfg-texto"><strong>Atualiza&ccedil;&atilde;o autom&aacute;tica</strong><small>5 s = ao vivo</small></div>
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
            <div class="cfg-texto"><strong>Per&iacute;odo padr&atilde;o do hist&oacute;rico</strong></div>
            <div class="cfg-controle">
              <select name="periodo_padrao_historico">
                <option value="24h">24 horas</option>
                <option value="7d">1 semana</option>
                <option value="30d">1 m&ecirc;s</option>
              </select>
            </div>
          </div>
        </div>

        <div class="cfg-grupo" id="cfg-faixas">
          <div class="cfg-grupo-titulo"><svg data-lucide="bell-ring"></svg>Faixas ideais e alertas<span class="cfg-grupo-dica">usadas no In&iacute;cio e nos avisos</span></div>
          <div class="cfg-linha">
            <div class="cfg-texto"><strong>Caixa d&apos;&aacute;gua baixa</strong><small>Avisa abaixo deste n&iacute;vel &middot; 70 L</small></div>
            <div class="cfg-controle"><span>abaixo de</span><input type="number" name="agua_alerta_min" min="0" max="90" step="1"><span>%</span></div>
          </div>
          <div class="cfg-linha">
            <div class="cfg-texto"><strong>Umidade do solo</strong></div>
            <div class="cfg-controle"><input type="number" name="solo_ideal_min" min="0" max="100" step="1"><span>a</span><input type="number" name="solo_ideal_max" min="0" max="100" step="1"><span>%</span></div>
          </div>
          <div class="cfg-linha">
            <div class="cfg-texto"><strong>Temperatura interna</strong></div>
            <div class="cfg-controle"><input type="text" inputmode="decimal" data-decimal name="temp_alerta_min" maxlength="5"><span>a</span><input type="text" inputmode="decimal" data-decimal name="temp_alerta_max" maxlength="5"><span>&deg;C</span></div>
          </div>
          <div class="cfg-linha">
            <div class="cfg-texto"><strong>Umidade interna</strong></div>
            <div class="cfg-controle"><input type="number" name="umid_alerta_min" min="0" max="100" step="1"><span>a</span><input type="number" name="umid_alerta_max" min="0" max="100" step="1"><span>%</span></div>
          </div>
          <div class="cfg-linha">
            <div class="cfg-texto"><strong>VPD &middot; Vegeta&ccedil;&atilde;o</strong></div>
            <div class="cfg-controle"><input type="text" inputmode="decimal" data-decimal name="vpd_vegetativo_min" maxlength="4"><span>a</span><input type="text" inputmode="decimal" data-decimal name="vpd_vegetativo_max" maxlength="4"><span>kPa</span></div>
          </div>
          <div class="cfg-linha">
            <div class="cfg-texto"><strong>VPD &middot; Flora&ccedil;&atilde;o</strong></div>
            <div class="cfg-controle"><input type="text" inputmode="decimal" data-decimal name="vpd_floracao_min" maxlength="4"><span>a</span><input type="text" inputmode="decimal" data-decimal name="vpd_floracao_max" maxlength="4"><span>kPa</span></div>
          </div>
          <div class="cfg-linha">
            <div class="cfg-texto"><strong>Considerar offline ap&oacute;s</strong><small>Tempo sem leituras at&eacute; avisar</small></div>
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
          <div class="cfg-texto"><strong>Telegram</strong><small id="tgInfo">Avisos pelo bot do Smart Demetra</small></div>
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
          <div class="cfg-texto"><span>O dispositivo ficar offline</span><small>Ap&oacute;s o tempo definido em Faixas</small></div>
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
        <p class="cfg-nota" style="padding:4px 0 14px;">No m&aacute;ximo 1 aviso do mesmo tipo a cada 30 min, e outro quando normalizar.</p>
      </div>

      <div class="cfg-grupo" id="grupoLuz">
        <div class="cfg-grupo-titulo"><svg data-lucide="lightbulb"></svg>Luz</div>
        <div class="cfg-linha">
          <div class="cfg-texto"><strong>Dura&ccedil;&atilde;o do modo manual</strong><small>Depois disso a luz volta ao timer</small><small id="luzManualStatus"></small></div>
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

      <div class="cfg-grupo" id="cfg-conta">
        <div class="cfg-grupo-titulo"><svg data-lucide="shield"></svg>Conta</div>
        <div class="cfg-linha">
          <div class="cfg-texto"><strong>Usu&aacute;rio</strong><small>Logado como <?= htmlspecialchars($usuarioLogado, ENT_QUOTES, 'UTF-8') ?><?= $ehAdmin ? ' (administrador)' : '' ?>.</small></div>
          <div class="cfg-controle"><a href="logout.php" class="btn btn-sec" style="width:100%;text-decoration:none;"><svg data-lucide="log-out"></svg>Sair</a></div>
        </div>
        <div class="cfg-linha">
          <div class="cfg-texto"><strong>Alterar senha</strong><small id="senhaInfo"></small></div>
          <div class="cfg-controle"><button type="button" class="btn btn-sec" id="btnAbrirSenha" style="width:100%;"><svg data-lucide="key-round"></svg>Alterar</button></div>
        </div>
        <form class="cfg-subpainel" id="painelSenha" hidden autocomplete="off">
          <div class="cfg-campos-senha">
            <label class="campo"><span class="campo-label">Senha atual</span><input type="password" name="senha_atual" autocomplete="current-password" required></label>
            <label class="campo"><span class="campo-label">Nova senha</span><input type="password" name="nova_senha" autocomplete="new-password" minlength="8" required></label>
            <label class="campo"><span class="campo-label">Confirmar nova senha</span><input type="password" name="confirma" autocomplete="new-password" required></label>
          </div>
          <p class="cfg-nota">M&iacute;nimo de 8 caracteres, com letras e n&uacute;meros. Os outros aparelhos conectados precisar&atilde;o entrar de novo.</p>
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
          <div class="cfg-texto"><strong>C&oacute;digo de convite</strong><small id="conviteInfo">Permite criar conta no login &middot; vazio desativa</small></div>
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
