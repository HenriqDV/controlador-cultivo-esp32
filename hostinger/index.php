<?php
declare(strict_types=1);
// Dashboard básico da Hostinger — só LEITURA. O dashboard completo e o
// controle de verdade continuam no servidor local do ESP32
// (http://cultivo.local/); esta página aqui só existe pra acompanhar as
// leituras de ambiente e o histórico de comandos remotamente, mesmo fora
// da rede WiFi da estufa. Consome os mesmos endpoints públicos de leitura
// (dados.php e comandos.php, sem API key — a API key só protege os
// endpoints de escrita usados pelo ESP32).
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cultivo — Dashboard Web</title>
<style>
  :root {
    color-scheme: dark;
    --bg: #101214;
    --card: #1b1e22;
    --card2: #191c1f;
    --texto: #eef0f2;
    --muted: #8b95a1;
    --verde: #22c55e;
    --borda: #2a2e33;
  }
  * { box-sizing: border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    background: var(--bg);
    color: var(--texto);
    margin: 0;
    padding: 16px;
  }
  .wrap { max-width: 1000px; margin: 0 auto; }
  header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 18px;
    flex-wrap: wrap;
    gap: 8px;
  }
  header h1 { font-size: 19px; margin: 0; display: flex; align-items: center; gap: 8px; }
  .aviso {
    font-size: 12px;
    color: var(--muted);
    background: var(--card2);
    padding: 6px 10px;
    border-radius: 8px;
  }
  .header-acoes { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .btn-local {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    color: var(--texto);
    background: var(--card2);
    border: 1px solid var(--borda);
    border-radius: 999px;
    padding: 7px 12px;
    text-decoration: none;
    white-space: nowrap;
  }

  .vpd-card {
    background: var(--card);
    border-radius: 14px;
    padding: 16px;
    margin-bottom: 18px;
  }
  .vpd-topo { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px; flex-wrap: wrap; gap: 6px; }
  .vpd-label { font-size: 13px; color: var(--muted); }
  .vpd-valor { font-size: 20px; font-weight: 700; }
  .vpd-barra {
    position: relative;
    height: 10px;
    border-radius: 6px;
    overflow: visible;
    background: linear-gradient(to right,
      #AB0025 0%, #AB0025 20%,
      #FE69FF 20%, #FE69FF 40%,
      #0EAD31 40%, #0EAD31 60%,
      #DBCB0F 60%, #DBCB0F 80%,
      #AB0025 80%, #AB0025 100%);
  }
  .vpd-marcador {
    position: absolute;
    top: -4px;
    width: 3px;
    height: 18px;
    background: #fff;
    border-radius: 2px;
    box-shadow: 0 0 4px rgba(0,0,0,.6);
    left: 50%;
    transition: left .3s ease;
  }
  .vpd-rodape { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; gap: 10px; flex-wrap: wrap; }
  .vpd-escala { display: flex; flex: 1; font-size: 11px; color: var(--muted); min-width: 220px; }
  .vpd-escala span { flex: 1; text-align: center; }
  .vpd-escala span:first-child { text-align: left; }
  .vpd-escala span:last-child { text-align: right; }
  .vpd-zona {
    background: #14532d;
    color: var(--verde);
    font-size: 12px;
    font-weight: 600;
    padding: 5px 12px;
    border-radius: 999px;
    white-space: nowrap;
  }

  .solo-card {
    background: var(--card);
    border-radius: 14px;
    padding: 16px;
    margin-bottom: 18px;
  }
  .solo-topo { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px; flex-wrap: wrap; gap: 6px; }
  .solo-label { font-size: 13px; color: var(--muted); }
  .solo-valor { font-size: 20px; font-weight: 700; }
  .solo-barra {
    position: relative;
    height: 14px;
    border-radius: 7px;
    overflow: visible;
    /* Azul escuro na maior parte da barra; entre 45% e 65% (faixa ideal de
       umidade do solo, com folga de 5 pontos pra cada lado do alvo
       50%-60%) fica azul claro, com uma transicao suave nas bordas em vez
       de um corte seco. */
    background: linear-gradient(to right,
      #0B3B66 0%, #0B3B66 43%,
      #4FB8FF 45%, #4FB8FF 65%,
      #0B3B66 67%, #0B3B66 100%);
    box-shadow: inset 0 0 0 1px rgba(255,255,255,.04);
  }
  .solo-marcador {
    position: absolute;
    top: -5px;
    width: 4px;
    height: 24px;
    background: #fff;
    border-radius: 3px;
    box-shadow: 0 0 6px rgba(0,0,0,.7), 0 0 0 1px rgba(0,0,0,.3);
    left: 0%;
    transform: translateX(-50%);
    transition: left .3s ease;
  }
  .solo-rodape { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; gap: 10px; flex-wrap: wrap; }
  .solo-escala { display: flex; flex: 1; font-size: 11px; color: var(--muted); min-width: 220px; }
  .solo-escala span { flex: 1; text-align: center; }
  .solo-escala span:first-child { text-align: left; }
  .solo-escala span:last-child { text-align: right; }
  .solo-status {
    font-size: 12px;
    font-weight: 600;
    padding: 5px 12px;
    border-radius: 999px;
    white-space: nowrap;
  }
  .solo-status.seco { background: #4a2a12; color: #fdba74; }
  .solo-status.ideal { background: #0c3a5c; color: #7dd3fc; }
  .solo-status.encharcado { background: #0f3d63; color: #38bdf8; }

  .grafico-topo {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 10px;
  }
  .periodo-botoes { display: flex; gap: 6px; }
  .periodo-botao {
    font-size: 12px;
    font-weight: 600;
    color: var(--muted);
    background: var(--card2);
    border: 1px solid var(--borda);
    border-radius: 999px;
    padding: 6px 12px;
    cursor: pointer;
  }
  .periodo-botao.ativo { color: #05230f; background: var(--verde); border-color: var(--verde); }
  .grafico-card {
    background: var(--card);
    border-radius: 14px;
    padding: 14px;
    margin-bottom: 18px;
  }
  .grafico-wrap { position: relative; height: 220px; }
  .grafico-wrap canvas { width: 100% !important; height: 100% !important; }
  .grafico-erro { color: var(--muted); font-size: 13px; padding: 20px 0; text-align: center; }
  .stats {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin-bottom: 18px;
  }
  @media (min-width: 700px) {
    .stats { grid-template-columns: repeat(5, 1fr); }
  }
  .card {
    background: var(--card);
    border-radius: 14px;
    padding: 14px;
  }
  .card-label { font-size: 12px; color: var(--muted); margin-bottom: 6px; }
  .card-valor { font-size: 20px; font-weight: 700; }

  section { margin-bottom: 24px; }
  section h2 {
    font-size: 12px;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin: 0 0 10px;
    font-weight: 700;
  }

  table {
    width: 100%;
    border-collapse: collapse;
    background: var(--card);
    border-radius: 14px;
    overflow: hidden;
    font-size: 13px;
  }
  th, td {
    text-align: left;
    padding: 8px 10px;
    border-bottom: 1px solid var(--borda);
    white-space: nowrap;
  }
  th { color: var(--muted); font-weight: 600; font-size: 11px; text-transform: uppercase; }
  tbody tr:last-child td { border-bottom: none; }
  .tabela-scroll { width: 100%; overflow-x: auto; border-radius: 14px; }

  .status-linha { font-size: 12px; color: var(--muted); text-align: center; margin-top: 10px; }
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.5.0/chart.umd.min.js"></script>
</head>
<body>
<div class="wrap">

  <header>
    <h1>&#127793; Cultivo &mdash; Dashboard Web</h1>
    <div class="header-acoes">
      <span class="aviso">Somente leitura &middot; controle fica no dashboard local</span>
      <a class="btn-local" href="http://cultivo.local/" target="_blank" rel="noopener">&#127968; Servidor local</a>
    </div>
  </header>

  <div class="stats">
    <div class="card"><div class="card-label">Temp. interna</div><div class="card-valor" id="statTempInterna">--</div></div>
    <div class="card"><div class="card-label">Umid. interna</div><div class="card-valor" id="statUmidInterna">--</div></div>
    <div class="card"><div class="card-label">Temp. externa</div><div class="card-valor" id="statTempExterna">--</div></div>
    <div class="card"><div class="card-label">Umid. externa</div><div class="card-valor" id="statUmidExterna">--</div></div>
    <div class="card"><div class="card-label">Solo</div><div class="card-valor" id="statSolo">--</div></div>
  </div>

  <div class="vpd-card">
    <div class="vpd-topo">
      <span class="vpd-label">&#128274; VPD (d&eacute;ficit de press&atilde;o de vapor, calculado com T/U interna)</span>
      <span class="vpd-valor" id="vpdValor">-- kPa</span>
    </div>
    <div class="vpd-barra">
      <div class="vpd-marcador" id="vpdMarcador"></div>
    </div>
    <div class="vpd-rodape">
      <div class="vpd-escala"><span>0</span><span>0,4</span><span>0,8</span><span>1,2</span><span>1,6</span><span>2,0+</span></div>
      <div class="vpd-zona" id="vpdZonaPill">--</div>
    </div>
  </div>

  <div class="solo-card">
    <div class="solo-topo">
      <span class="solo-label">&#128167; Umidade do solo (ideal entre 50% e 60%)</span>
      <span class="solo-valor" id="soloValor">-- %</span>
    </div>
    <div class="solo-barra">
      <div class="solo-marcador" id="soloMarcador"></div>
    </div>
    <div class="solo-rodape">
      <div class="solo-escala"><span>0</span><span>25</span><span>45</span><span>65</span><span>100</span></div>
      <div class="solo-status" id="soloStatusPill">--</div>
    </div>
  </div>

  <section>
    <div class="grafico-topo">
      <h2 style="margin:0;">Hist&oacute;rico</h2>
      <div class="periodo-botoes">
        <button class="periodo-botao ativo" data-periodo="24h" onclick="mudarPeriodo('24h')">24 horas</button>
        <button class="periodo-botao" data-periodo="7d" onclick="mudarPeriodo('7d')">1 semana</button>
        <button class="periodo-botao" data-periodo="30d" onclick="mudarPeriodo('30d')">1 m&ecirc;s</button>
      </div>
    </div>

    <div class="grafico-card">
      <h2>Temperatura (interna x externa)</h2>
      <div class="grafico-wrap">
        <canvas id="graficoTemperatura"></canvas>
        <div class="grafico-erro" id="graficoTemperaturaMsg" style="display:none;"></div>
      </div>
    </div>

    <div class="grafico-card">
      <h2>Umidade (interna x externa)</h2>
      <div class="grafico-wrap">
        <canvas id="graficoUmidade"></canvas>
        <div class="grafico-erro" id="graficoUmidadeMsg" style="display:none;"></div>
      </div>
    </div>
  </section>

  <section>
    <h2>Comandos recentes</h2>
    <div class="tabela-scroll">
      <table>
        <thead>
          <tr>
            <th>Recebido em</th>
            <th>Hora do dispositivo</th>
            <th>Tipo</th>
            <th>Alvo</th>
            <th>Valor</th>
            <th>Origem</th>
          </tr>
        </thead>
        <tbody id="corpoComandos">
          <tr><td colspan="6">carregando...</td></tr>
        </tbody>
      </table>
    </div>
  </section>

  <div class="status-linha" id="statusLinha">conectando...</div>

</div>

<script>
function escapeHtml(v) {
  if (v === null || v === undefined) return '--';
  return String(v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function fmtTemp(v) {
  return (v === null || v === undefined) ? '--' : Number(v).toFixed(1).replace('.', ',') + '\u00b0C';
}
function fmtUmid(v) {
  return (v === null || v === undefined) ? '--' : Number(v).toFixed(0) + '%';
}
function fmtSolo(v) {
  return (v === null || v === undefined) ? '--' : Number(v).toFixed(0) + '%';
}

function atualizarSoloBarra(soloPercentual) {
  const valorEl = document.getElementById('soloValor');
  const marcadorEl = document.getElementById('soloMarcador');
  const pillEl = document.getElementById('soloStatusPill');
  if (soloPercentual === null || soloPercentual === undefined) {
    valorEl.textContent = '-- %';
    marcadorEl.style.left = '0%';
    pillEl.textContent = '--';
    pillEl.className = 'solo-status';
    return;
  }
  const v = Math.max(0, Math.min(100, Number(soloPercentual)));
  valorEl.textContent = v.toFixed(0) + ' %';
  marcadorEl.style.left = v + '%';

  // Faixa alvo real e 50-65%; a faixa azul clara na barra tem 5 pontos de
  // folga pra cada lado (45-65%) so pra dar uma leitura visual mais suave -
  // o selo de status abaixo usa a faixa "de verdade" (50-60%).
  if (v < 50) {
    pillEl.textContent = 'Seco \u2014 considere regar';
    pillEl.className = 'solo-status seco';
  } else if (v <= 60) {
    pillEl.textContent = 'Ideal';
    pillEl.className = 'solo-status ideal';
  } else {
    pillEl.textContent = 'Encharcado';
    pillEl.className = 'solo-status encharcado';
  }
}

// Mesma fórmula usada no firmware (calcularVPD() em main.cpp, Tetens/Magnus):
//   SVP = 0.6108 * e^(17.27*T / (T+237.3))
//   VPD = SVP * (1 - UR/100)
function calcularVPD(tempC, umidPercent) {
  const svp = 0.6108 * Math.exp((17.27 * tempC) / (tempC + 237.3));
  return svp * (1 - umidPercent / 100);
}

// Mesmas faixas/nomes de zonaVPD() no firmware.
function zonaVPD(vpd) {
  if (vpd < 0.4) return 'Muito umido';
  if (vpd < 0.8) return 'Propagacao/Veg inicial';
  if (vpd < 1.2) return 'Veg tardio/Flora inicial';
  if (vpd < 1.6) return 'Flora media/tardia';
  return 'Muito seco';
}

function atualizarVPD(tempInterna, umidInterna) {
  if (tempInterna === null || tempInterna === undefined || umidInterna === null || umidInterna === undefined) {
    document.getElementById('vpdValor').textContent = '-- kPa';
    document.getElementById('vpdZonaPill').textContent = '--';
    document.getElementById('vpdMarcador').style.left = '50%';
    return;
  }
  const vpd = calcularVPD(Number(tempInterna), Number(umidInterna));
  document.getElementById('vpdValor').textContent = vpd.toFixed(2).replace('.', ',') + ' kPa';
  document.getElementById('vpdZonaPill').textContent = zonaVPD(vpd);
  const pct = Math.max(0, Math.min(100, (vpd / 2.0) * 100));
  document.getElementById('vpdMarcador').style.left = pct + '%';
}

async function buscarJson(url) {
  // cache: 'no-store' + carimbo de tempo na URL evitam resposta em cache
  // (do navegador ou de algum proxy/CDN da hospedagem) mascarando dados
  // novos como se fossem erro/vazio.
  const r = await fetch(url + (url.includes('?') ? '&' : '?') + '_=' + Date.now(), { cache: 'no-store' });
  const texto = await r.text();
  let d;
  try {
    d = JSON.parse(texto);
  } catch (e) {
    // Resposta não é JSON válido (ex: aviso/erro do PHP misturado antes do
    // JSON) — mostra um pedacinho do que veio, ajuda a achar a causa real
    // direto pelo dashboard, sem precisar abrir o link cru.
    const trecho = texto.trim().slice(0, 120).replace(/\s+/g, ' ');
    throw new Error('resposta invalida do servidor (HTTP ' + r.status + ')' + (trecho ? ': "' + trecho + '..."' : ' (vazia)'));
  }
  if (!r.ok || !d.ok) {
    throw new Error((d.erro || ('HTTP ' + r.status)) + (d.detalhe ? ' — ' + d.detalhe : ''));
  }
  return d;
}

let periodoAtual = '24h';
let graficoTemperatura = null;
let graficoUmidade = null;

async function carregarResumo() {
  // Sempre usa 24h (dados crus, ~1 leitura/min) pra pegar o valor mais
  // recente de verdade, independente do periodo selecionado nos graficos
  // (que em 7d/30d vem agregado por hora e "atrasado" em relacao a agora).
  const d = await buscarJson('dados.php?periodo=24h');
  const linhas = d.dados || [];

  if (linhas.length > 0) {
    const ultima = linhas[linhas.length - 1];
    document.getElementById('statTempInterna').textContent = fmtTemp(ultima.temp_interna);
    document.getElementById('statUmidInterna').textContent = fmtUmid(ultima.umid_interna);
    document.getElementById('statTempExterna').textContent = fmtTemp(ultima.temp_externa);
    document.getElementById('statUmidExterna').textContent = fmtUmid(ultima.umid_externa);
    document.getElementById('statSolo').textContent = fmtSolo(ultima.solo_percentual);
    atualizarVPD(ultima.temp_interna, ultima.umid_interna);
    atualizarSoloBarra(ultima.solo_percentual);
  }
}

function formatarRotuloTempo(linha, periodo) {
  // Prioriza dispositivo_timestamp (hora do proprio ESP32, ja no fuso
  // certo - GMT-3). So cai pra criado_em (hora que o servidor recebeu) se
  // por acaso faltar - ex: leitura muito antiga, de antes do RTC
  // sincronizar no boot.
  const valor = linha.dispositivo_timestamp || linha.criado_em;
  const partes = valor.split(/[- :]/); // [ano, mes, dia, hora, min, seg]
  if (partes.length < 5) return valor;
  const [, mes, dia, hora, min] = partes;
  return periodo === '24h' ? (hora + ':' + min) : (dia + '/' + mes + ' ' + hora + 'h');
}

// Grafico generico de UM eixo Y so, com N linhas (series) - usado tanto pro
// grafico de temperatura (interna x externa) quanto pro de umidade (interna
// x externa). Clicar num item da legenda esconde/mostra so aquela linha -
// isso e comportamento padrao do Chart.js, nao precisa de nada extra.
function montarGrafico(canvasId, linhas, periodo, series, unidade, escalaFixa) {
  const labels = linhas.map(l => formatarRotuloTempo(l, periodo));

  const datasets = series.map(s => ({
    label: s.label,
    data: linhas.map(l => l[s.campo] === null ? null : Number(l[s.campo])),
    borderColor: s.cor,
    backgroundColor: s.cor,
    spanGaps: true,
    pointRadius: 0,
    borderWidth: 2,
    tension: 0.25,
  }));

  const eixoY = {
    ticks: { color: '#8b95a1', font: { size: 10 } },
    grid: { color: '#2a2e33' },
    title: { display: true, text: unidade, color: '#8b95a1' },
  };
  if (escalaFixa) {
    eixoY.min = escalaFixa[0];
    eixoY.max = escalaFixa[1];
  }

  const config = {
    type: 'line',
    data: { labels, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          labels: { color: '#8b95a1', boxWidth: 12, font: { size: 11 } },
          onClick: Chart.defaults.plugins.legend.onClick, // padrao: clique esconde/mostra a linha
        },
      },
      scales: {
        x: {
          ticks: { color: '#8b95a1', maxTicksLimit: 8, font: { size: 10 } },
          grid: { color: '#2a2e33' },
        },
        y: eixoY,
      },
    },
  };

  const ctx = document.getElementById(canvasId).getContext('2d');
  return new Chart(ctx, config);
}

function mostrarMensagemGrafico(prefixoId, texto) {
  document.getElementById(prefixoId).style.display = texto ? 'none' : '';
  const msgEl = document.getElementById(prefixoId + 'Msg');
  msgEl.textContent = texto || '';
  msgEl.style.display = texto ? '' : 'none';
}

async function carregarGraficos(periodo) {
  if (typeof Chart === 'undefined') {
    throw new Error('biblioteca de graficos (Chart.js) nao carregou - verifique a conexao ou algum bloqueador de anuncios/CDN');
  }

  const d = await buscarJson('dados.php?periodo=' + encodeURIComponent(periodo));
  const linhas = d.dados || [];

  if (graficoTemperatura) { graficoTemperatura.destroy(); graficoTemperatura = null; }
  if (graficoUmidade) { graficoUmidade.destroy(); graficoUmidade = null; }

  if (linhas.length === 0) {
    mostrarMensagemGrafico('graficoTemperatura', 'sem dados nesse periodo ainda');
    mostrarMensagemGrafico('graficoUmidade', 'sem dados nesse periodo ainda');
    return;
  }

  mostrarMensagemGrafico('graficoTemperatura', null);
  mostrarMensagemGrafico('graficoUmidade', null);

  graficoTemperatura = montarGrafico('graficoTemperatura', linhas, periodo, [
    { label: 'Interna (\u00b0C)', campo: 'temp_interna', cor: '#22c55e' },
    { label: 'Externa (\u00b0C)', campo: 'temp_externa', cor: '#f97316' },
  ], '\u00b0C', null);

  graficoUmidade = montarGrafico('graficoUmidade', linhas, periodo, [
    { label: 'Interna (%)', campo: 'umid_interna', cor: '#38bdf8' },
    { label: 'Externa (%)', campo: 'umid_externa', cor: '#c084fc' },
  ], '%', [0, 100]);
}

function mudarPeriodo(periodo) {
  if (periodo === periodoAtual) return;
  periodoAtual = periodo;
  document.querySelectorAll('.periodo-botao').forEach(btn => {
    btn.classList.toggle('ativo', btn.dataset.periodo === periodo);
  });
  carregarGraficos(periodo).catch(e => {
    document.getElementById('statusLinha').textContent = 'erro ao carregar grafico: ' + e.message;
  });
}

async function carregarComandos() {
  const d = await buscarJson('comandos.php?limite=30');
  const linhas = d.dados || [];

  const corpo = document.getElementById('corpoComandos');
  corpo.innerHTML = '';
  linhas.forEach(c => {
    const tr = document.createElement('tr');
    tr.innerHTML =
      '<td>' + escapeHtml(c.criado_em) + '</td>' +
      '<td>' + escapeHtml(c.dispositivo_timestamp) + '</td>' +
      '<td>' + escapeHtml(c.tipo) + '</td>' +
      '<td>' + escapeHtml(c.alvo) + '</td>' +
      '<td>' + escapeHtml(c.valor) + '</td>' +
      '<td>' + escapeHtml(c.origem) + '</td>';
    corpo.appendChild(tr);
  });
  if (linhas.length === 0) {
    corpo.innerHTML = '<tr><td colspan="6">sem comandos ainda</td></tr>';
  }
}

async function atualizarTudo() {
  const erros = [];

  try {
    await carregarResumo();
  } catch (e) {
    erros.push('resumo: ' + e.message);
  }

  try {
    await carregarGraficos(periodoAtual);
  } catch (e) {
    erros.push('graficos: ' + e.message);
  }

  try {
    await carregarComandos();
  } catch (e) {
    erros.push('comandos: ' + e.message);
    document.getElementById('corpoComandos').innerHTML =
      '<tr><td colspan="6">erro ao carregar comandos (' + escapeHtml(e.message) + ')</td></tr>';
  }

  document.getElementById('statusLinha').textContent = erros.length
    ? 'falha ao atualizar (' + erros.join(' | ') + ')'
    : 'atualizado ' + new Date().toLocaleTimeString('pt-BR');
}

atualizarTudo();
setInterval(atualizarTudo, 60000); // a cada 1 min (mesmo ritmo do envio do ESP32)
</script>
</body>
</html>
