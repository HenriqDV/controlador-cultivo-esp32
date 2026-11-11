/* Lógica do painel (index.php). Arquivo separado para o navegador guardar em cache;
   o index.php acrescenta ?v=<data do arquivo> para buscar de novo quando ele mudar.
   Roda no fim do <body>, depois do HTML, como antes. */
/* ==================== Navegação: sidebar + abas ==================== */
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');
const btnHamburger = document.getElementById('btnHamburger');
const ABAS_VALIDAS = ['inicio', 'relogio', 'fotoperiodo', 'comandos', 'caixa', 'historico', 'dispositivo', 'configuracoes'];

function abrirSidebar() { sidebar.classList.add('aberta'); overlay.classList.add('visivel'); document.body.classList.add('menu-aberto'); }
function fecharSidebar() { sidebar.classList.remove('aberta'); overlay.classList.remove('visivel'); document.body.classList.remove('menu-aberto'); }
btnHamburger.addEventListener('click', abrirSidebar);
overlay.addEventListener('click', fecharSidebar);

let abaAtual = 'inicio';
function mudarAba(aba) {
  if (aba === 'automacao') aba = 'fotoperiodo'; // nome antigo da página (links e preferências salvas)
  if (!ABAS_VALIDAS.includes(aba)) aba = 'inicio';
  abaAtual = aba;
  document.querySelectorAll('.aba').forEach(el => el.classList.toggle('ativa', el.id === 'aba-' + aba));
  document.querySelectorAll('.nav-item[data-aba]').forEach(btn => btn.classList.toggle('ativo', btn.dataset.aba === aba));
  fecharSidebar();
  try { history.replaceState(null, '', '#' + aba); } catch (e) { /* ignora */ }
  // Chart.js precisa recalcular o tamanho ao reaparecer.
  if (aba === 'historico') { setTimeout(() => { [graficoTemperatura, graficoUmidade, graficoSolo, graficoAgua, graficoVpd].forEach(g => { if (g) g.resize(); }); }, 0); }
  if (aba === 'relogio') renderizarRoda();
  if (aba === 'fotoperiodo') renderizarFotoperiodo();
  if (aba === 'caixa') { renderizarCaixa(); setTimeout(() => { if (graficoCaixa) graficoCaixa.resize(); }, 0); }
  if (aba === 'dispositivo') carregarDispositivo().catch(e => console.warn('dispositivo:', e.message));
  window.scrollTo(0, 0);
}
document.querySelectorAll('.nav-item[data-aba]').forEach(btn => {
  btn.addEventListener('click', () => mudarAba(btn.dataset.aba));
});
document.addEventListener('click', ev => {
  const link = ev.target.closest('[data-ir-aba]');
  if (link) { ev.preventDefault(); mudarAba(link.dataset.irAba); }
});

if (window.lucide) lucide.createIcons();

/* ==================== Configurações (servidor) ==================== */
// Padrões (iguais a CONFIG_PADRAO em migracoes.php) até configuracoes.php responder.
let CONFIG = {
  fotoperiodo_vegetativo_inicio: '16:00',
  fotoperiodo_floracao_inicio: '19:00',
  nome_cultivo: 'Smart Demetra',
  pagina_inicial: 'inicio',
  intervalo_atualizacao_seg: '5',
  periodo_padrao_historico: '24h',
  agua_alerta_min: '20',
  solo_ideal_min: '50',
  solo_ideal_max: '60',
  temp_alerta_min: '18',
  temp_alerta_max: '30',
  umid_alerta_min: '40',
  umid_alerta_max: '70',
  vpd_vegetativo_min: '0.8',
  vpd_vegetativo_max: '1.2',
  vpd_floracao_min: '1.2',
  vpd_floracao_max: '1.6',
  dispositivo_sem_dados_min: '5',
};
// Estado do rodapé "atualizado … · há N" e intervalos aceitos (em segundos).
let ultimaAtualizacaoEm = null;
let ultimoErroAtualizacao = '';
const INTERVALOS_VALIDOS = ['5', '10', '30', '60', '120', '300', '600'];
// Horário confirmado pelo ESP32 por modo (ver configuracoes.php).
let FOTOPERIODO_INFO = { vegetativo: null, floracao: null };
let CICLO = null; // { fase, dia, iniciada_em, programada }
let LUZ_DURACAO_ESP = null; // { minutos, quando } informado pelo ESP32

/* ==================== Utilitários ==================== */
function escapeHtml(v) {
  if (v === null || v === undefined) return '--';
  return String(v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function fmtTemp(v) { return (v === null || v === undefined) ? '--' : Number(v).toFixed(1).replace('.', ',') + '\u00b0C'; }
function fmtUmid(v) { return (v === null || v === undefined) ? '--' : Number(v).toFixed(0) + '%'; }
function fmtSolo(v) { return (v === null || v === undefined) ? '--' : Number(v).toFixed(0) + '%'; }

function tirarEsqueleto(id) { const el = document.getElementById(id); if (el) el.classList.remove('skeleton'); }

// Solo "sem leitura": sem valor (null) nas últimas 3 leituras. O firmware 2.7.0 (sensor capacitivo v1.2)
// manda null quando o sensor está solto ou sem alimentação; 0% agora é solo seco de verdade.
let soloSemLeitura = false;

function atualizarSoloBarra(soloPercentual) {
  const valorEl = document.getElementById('soloValor');
  const marcadorEl = document.getElementById('soloMarcador');
  const pillEl = document.getElementById('soloStatusPill');
  tirarEsqueleto('soloValor');
  document.getElementById('soloNota').hidden = !soloSemLeitura;
  marcadorEl.hidden = soloSemLeitura;
  if (soloSemLeitura) {
    valorEl.textContent = '—';
    pillEl.textContent = 'Sensor sem leitura';
    pillEl.className = 'pill pill-warn';
    return;
  }
  if (soloPercentual === null || soloPercentual === undefined) {
    valorEl.textContent = '-- %';
    marcadorEl.style.left = '0%';
    pillEl.textContent = '--';
    pillEl.className = 'pill pill-neutro';
    return;
  }
  const v = Math.max(0, Math.min(100, Number(soloPercentual)));
  valorEl.textContent = v.toFixed(0) + ' %';
  marcadorEl.style.left = v + '%';
  const soloMin = Number(CONFIG.solo_ideal_min), soloMax = Number(CONFIG.solo_ideal_max);
  if (v < soloMin) { pillEl.textContent = 'Seco — considere regar'; pillEl.className = 'pill pill-warn'; }
  else if (v <= soloMax) { pillEl.textContent = 'Ideal'; pillEl.className = 'pill pill-ok'; }
  else { pillEl.textContent = 'Encharcado'; pillEl.className = 'pill pill-alert'; }
}

// Caixa d'água (HC-SR04): paredes retas, 66 x 36 cm de base = 2,376 L por cm de água; 70 L = 100% (≈ 29,5 cm).
const CAIXA_CAPACIDADE_L = 70;
const CAIXA_LITROS_POR_CM = 66 * 36 / 1000;
let aguaSemLeitura = false;
function atualizarAguaBarra(leitura) {
  const valorEl = document.getElementById('aguaValor');
  if (!valorEl) return;
  const fill = document.getElementById('aguaPreenchimento'), pill = document.getElementById('aguaStatusPill');
  const detalhe = document.getElementById('aguaDetalhe'), nota = document.getElementById('aguaNota');
  const limite = Math.max(0, Math.min(90, Number(CONFIG.agua_alerta_min) || 20));
  document.getElementById('aguaLimite').style.left = limite + '%';
  tirarEsqueleto('aguaValor');
  nota.hidden = !aguaSemLeitura;
  const litros = leitura && leitura.agua_litros !== null && leitura.agua_litros !== undefined ? Number(leitura.agua_litros) : null;
  const pct = leitura && leitura.agua_percentual !== null && leitura.agua_percentual !== undefined ? Number(leitura.agua_percentual)
    : (litros !== null ? Math.min(100, litros / CAIXA_CAPACIDADE_L * 100) : null);
  if (litros === null || pct === null || isNaN(litros)) {
    valorEl.textContent = '-- L';
    fill.style.width = '0%';
    pill.textContent = aguaSemLeitura ? 'Sensor sem leitura' : 'Aguardando o sensor';
    pill.className = 'pill ' + (aguaSemLeitura ? 'pill-warn' : 'pill-neutro');
    detalhe.textContent = aguaSemLeitura ? '' : 'disponível a partir do firmware 2.7.0';
    return;
  }
  valorEl.textContent = brN(litros, 1) + ' L · ' + Math.round(pct) + '%';
  fill.style.width = Math.max(0, Math.min(100, pct)) + '%';
  fill.classList.toggle('baixo', pct < limite);
  if (pct < 10) { pill.textContent = 'Quase vazia — reabasteça'; pill.className = 'pill pill-alert'; }
  else if (pct < limite) { pill.textContent = 'Nível baixo — reabasteça'; pill.className = 'pill pill-warn'; }
  else if (pct >= 95) { pill.textContent = 'Cheia'; pill.className = 'pill pill-ok'; }
  else { pill.textContent = 'Nível ok'; pill.className = 'pill pill-ok'; }
  detalhe.textContent = '≈ ' + brN(litros / CAIXA_LITROS_POR_CM, 1) + ' cm de água · faltam ' + brN(Math.max(0, CAIXA_CAPACIDADE_L - litros), 1) + ' L para encher';
}

/* ==================== Caixa d'água (aba) ==================== */
// Caixa de paredes retas 66 x 36 x 35 cm; 70 L = 100% (≈ 29,5 cm de água). Abaixo de 10% o ESP32 bloqueia a irrigação.
const CAIXA_ALTURA_CM = 35;
const CAIXA_TOTAL_L = CAIXA_LITROS_POR_CM * CAIXA_ALTURA_CM;   // até a borda (≈ 83 L)
const CAIXA_PROTECAO_PCT = 10;
let graficoCaixa = null;

function leiturasComAgua() { return leituras24h.filter(l => l.agua_litros !== null && l.agua_litros !== undefined && l.agua_litros !== ''); }
function msLeitura(l) { return new Date(String(l.dispositivo_timestamp || l.criado_em || '').replace(' ', 'T')).getTime(); }
function percentualDaLeitura(l) {
  if (l.agua_percentual !== null && l.agua_percentual !== undefined) return Number(l.agua_percentual);
  return Math.min(100, Number(l.agua_litros) / CAIXA_CAPACIDADE_L * 100);
}
// Percentual atual da caixa (ou null), usado também para bloquear a irrigação nos Comandos.
function caixaPercentualAtual() {
  const l = ultimaLeitura;
  if (!l || l.agua_litros === null || l.agua_litros === undefined) return null;
  return percentualDaLeitura(l);
}

function svgTanque(litros, pct, limitePct) {
  const W = 320, x0 = 50, x1 = 306, yTop = 14, yBot = 286, H = yBot - yTop;
  const y = L => yBot - Math.max(0, Math.min(CAIXA_TOTAL_L, L)) / CAIXA_TOTAL_L * H;
  const baixo = pct !== null && pct < limitePct;
  const corA = baixo ? '#e08a85' : '#6fb3e0', corB = baixo ? '#a8644f' : '#3d7fa6';
  let s = '<svg class="cx-tanque" viewBox="0 0 ' + W + ' 300" role="img" aria-label="Desenho da caixa d\'água com ' + (litros === null ? 'nível desconhecido' : brN(litros, 1) + ' litros') + '">' +
    '<defs><linearGradient id="cxAgua" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' + corA + '" stop-opacity=".95"/><stop offset="1" stop-color="' + corB + '" stop-opacity=".9"/></linearGradient>' +
    '<clipPath id="cxClip"><rect x="' + x0 + '" y="' + yTop + '" width="' + (x1 - x0) + '" height="' + H + '" rx="10"/></clipPath></defs>';
  // escala em litros
  [0, 20, 40, 60, 80].forEach(v => {
    const yy = y(v);
    s += '<text x="' + (x0 - 10) + '" y="' + (yy + 4).toFixed(1) + '" text-anchor="end" class="cx-escala">' + v + ' L</text>';
  });
  // fundo e água
  s += '<rect x="' + x0 + '" y="' + yTop + '" width="' + (x1 - x0) + '" height="' + H + '" rx="10" fill="#1a1f1e"/>';
  if (litros !== null) {
    const yw = y(litros), amp = litros > 1 ? 3 : 0, passos = 4, larg = (x1 - x0) / passos;
    let d = 'M ' + x0 + ' ' + yw.toFixed(1);
    for (let i = 0; i < passos; i++) {
      const xa = x0 + i * larg;
      d += ' Q ' + (xa + larg / 4).toFixed(1) + ' ' + (yw - amp).toFixed(1) + ' ' + (xa + larg / 2).toFixed(1) + ' ' + yw.toFixed(1) +
           ' T ' + (xa + larg).toFixed(1) + ' ' + yw.toFixed(1);
    }
    d += ' L ' + x1 + ' ' + yBot + ' L ' + x0 + ' ' + yBot + ' Z';
    s += '<path d="' + d + '" fill="url(#cxAgua)" clip-path="url(#cxClip)"/>';
  }
  // capacidade (70 L) e mínimo
  const yCap = y(CAIXA_CAPACIDADE_L), yMin = y(CAIXA_CAPACIDADE_L * limitePct / 100);
  s += '<line x1="' + x0 + '" y1="' + yCap.toFixed(1) + '" x2="' + x1 + '" y2="' + yCap.toFixed(1) + '" class="cx-linha-cap"/>' +
       '<text x="' + (x1 - 10) + '" y="' + (yCap - 6).toFixed(1) + '" text-anchor="end" class="cx-rotulo-cap">70 L</text>' +
       '<line x1="' + x0 + '" y1="' + yMin.toFixed(1) + '" x2="' + x1 + '" y2="' + yMin.toFixed(1) + '" class="cx-linha-min"/>' +
       '<rect x="' + (x1 - 78) + '" y="' + (yMin - 21).toFixed(1) + '" width="70" height="17" rx="8.5" class="cx-rotulo-min-bg"/>' +
       '<text x="' + (x1 - 43) + '" y="' + (yMin - 9).toFixed(1) + '" text-anchor="middle" class="cx-rotulo-min">mín. ' + limitePct + '%</text>';
  // paredes (tampa tracejada, onde fica o sensor)
  s += '<path d="M ' + x0 + ' ' + yTop + ' L ' + x0 + ' ' + (yBot - 10) + ' Q ' + x0 + ' ' + yBot + ' ' + (x0 + 10) + ' ' + yBot +
       ' L ' + (x1 - 10) + ' ' + yBot + ' Q ' + x1 + ' ' + yBot + ' ' + x1 + ' ' + (yBot - 10) + ' L ' + x1 + ' ' + yTop + '" class="cx-parede"/>' +
       '<line x1="' + x0 + '" y1="' + yTop + '" x2="' + x1 + '" y2="' + yTop + '" class="cx-tampa"/>';
  // percentual
  if (pct !== null) {
    // dentro da água quando há espaço; com pouca água, no meio do espaço vazio (longe da linha do mínimo)
    const yw = y(litros), dentro = yBot - yw > 56;
    const yTexto = dentro ? (yw + yBot) / 2 + 11 : (Math.min(yw, yMin) + yCap) / 2 + 11;
    s += '<text x="' + ((x0 + x1) / 2) + '" y="' + yTexto.toFixed(1) + '" text-anchor="middle" class="cx-pct">' + Math.round(pct) + '%</text>';
  } else {
    s += '<text x="' + ((x0 + x1) / 2) + '" y="' + ((yTop + yBot) / 2) + '" text-anchor="middle" class="cx-sem">sem leitura</text>';
  }
  return s + '</svg>';
}

function renderizarCaixa() {
  const aba = document.getElementById('aba-caixa');
  if (!aba) return;
  const com = leiturasComAgua();
  const ult = com.length ? com[com.length - 1] : null;
  const ultimaGeral = leituras24h.length ? leituras24h[leituras24h.length - 1] : null;
  const limite = Math.max(0, Math.min(90, Number(CONFIG.agua_alerta_min) || 20));
  const atual = ultimaGeral && ultimaGeral.agua_litros !== null && ultimaGeral.agua_litros !== undefined ? ultimaGeral : null;
  const litros = atual ? Number(atual.agua_litros) : null;
  const pct = atual ? percentualDaLeitura(atual) : null;

  // selos do topo
  const vivo = document.getElementById('cxVivo');
  const minSemDados = Number(CONFIG.dispositivo_sem_dados_min) || 5;
  if (atual && Date.now() - msLeitura(atual) <= minSemDados * 60000) {
    vivo.className = 'cx-selo ok'; vivo.innerHTML = '<i></i>Ao vivo · ' + hhmmDeMs(msLeitura(atual));
  } else if (ult) {
    vivo.className = 'cx-selo aviso'; vivo.innerHTML = '<i></i>Última leitura há ' + formatarDuracao(Date.now() - msLeitura(ult));
  } else {
    vivo.className = 'cx-selo'; vivo.innerHTML = '<i></i>Aguardando o sensor';
  }

  document.getElementById('cxTanque').innerHTML = svgTanque(litros, pct, limite);

  // volume agora
  const card = document.getElementById('cxVolume');
  const pill = document.getElementById('cxPill');
  card.classList.remove('ok', 'baixo', 'vazio');
  document.getElementById('cxLimite').style.left = limite + '%';
  document.getElementById('cxAviso').textContent = 'Aviso abaixo de ' + limite + '% (' + brN(CAIXA_CAPACIDADE_L * limite / 100, 0) + ' L)';
  const bloqueio = document.getElementById('cxBloqueio');
  if (litros === null) {
    document.getElementById('cxLitros').textContent = '--';
    pill.className = 'pill pill-neutro';
    pill.textContent = ult ? 'Sensor sem leitura' : 'Aguardando o sensor';
    document.getElementById('cxPreenchimento').style.width = '0%';
    ['cxAltura', 'cxFalta', 'cxVariacao'].forEach(id => { document.getElementById(id).textContent = '—'; });
    document.getElementById('cxUltima').textContent = ult ? hhmmDeMs(msLeitura(ult)) : '—';
    bloqueio.hidden = true;
  } else {
    document.getElementById('cxLitros').textContent = brN(litros, 1) + ' L';
    const fill = document.getElementById('cxPreenchimento');
    fill.style.width = Math.max(0, Math.min(100, pct)) + '%';
    fill.classList.toggle('baixo', pct < limite);
    if (pct < CAIXA_PROTECAO_PCT) { pill.className = 'pill pill-alert'; pill.textContent = 'Quase vazia'; card.classList.add('vazio'); }
    else if (pct < limite) { pill.className = 'pill pill-warn'; pill.textContent = 'Nível baixo'; card.classList.add('baixo'); }
    else { pill.className = 'pill pill-ok'; pill.textContent = pct >= 95 ? 'Cheia' : 'Nível ok'; card.classList.add('ok'); }
    document.getElementById('cxAltura').textContent = brN(litros / CAIXA_LITROS_POR_CM, 1) + ' cm';
    document.getElementById('cxFalta').textContent = brN(Math.max(0, CAIXA_CAPACIDADE_L - litros), 1) + ' L';
    const primeira = com[0];
    const variacao = primeira ? litros - Number(primeira.agua_litros) : 0;
    document.getElementById('cxVariacao').textContent = (variacao > 0.05 ? '+' : variacao < -0.05 ? '−' : '') + brN(Math.abs(variacao), 1) + ' L';
    document.getElementById('cxUltima').textContent = hhmmDeMs(msLeitura(atual));
    bloqueio.hidden = !(pct < CAIXA_PROTECAO_PCT);
  }

  // gráfico de 24 h
  if (typeof Chart === 'undefined') return;
  if (graficoCaixa) { graficoCaixa.destroy(); graficoCaixa = null; }
  tirarEsqueleto('graficoCaixaSkeleton');
  document.getElementById('graficoCaixaSkeleton').style.display = 'none';
  if (!com.length) { mostrarMensagemGrafico('graficoCaixa', 'sem leituras da caixa d\'água nas últimas 24 horas'); return; }
  mostrarMensagemGrafico('graficoCaixa', null);
  graficoCaixa = montarGrafico('graficoCaixa', leituras24h, '24h', [{ label: 'Caixa d\'água (L)', campo: 'agua_litros', cor: '#6fb3e0' }], 'L', { min: 0, suggestedMax: CAIXA_CAPACIDADE_L });
  const ds = graficoCaixa.data.datasets[0];
  ds.fill = true; ds.backgroundColor = 'rgba(111,179,224,.12)'; ds.tension = 0.15;
  graficoCaixa.update();
}

// VPD (kPa) — mesma fórmula do firmware.
function calcularVPD(tempC, umidPercent) {
  const svp = 0.6108 * Math.exp((17.27 * tempC) / (tempC + 237.3));
  return svp * (1 - umidPercent / 100);
}
// Faixa ideal de VPD por fase (configurável na Etapa 3; até lá, os padrões).
const FAIXA_VPD_PADRAO = { vegetativo: [0.8, 1.2], floracao: [1.2, 1.6] };
function faixaVpd(modo) {
  const m = modo === 'floracao' ? 'floracao' : 'vegetativo';
  const min = parseFloat(String(CONFIG['vpd_' + m + '_min'] ?? '').replace(',', '.'));
  const max = parseFloat(String(CONFIG['vpd_' + m + '_max'] ?? '').replace(',', '.'));
  return (isFinite(min) && isFinite(max) && min < max) ? [min, max] : FAIXA_VPD_PADRAO[m];
}
function fmtKpa(v) { const n = Number(v); return (Number.isInteger(n) ? n.toFixed(1) : String(Math.round(n * 100) / 100)).replace('.', ','); }
// Compara o VPD com a faixa ideal da fase ativa.
function avaliarVpd(vpd, modo) {
  const [min, max] = faixaVpd(modo);
  const fase = NOME_MODO[modo === 'floracao' ? 'floracao' : 'vegetativo'];
  if (vpd < min) return { texto: 'Abaixo do ideal para ' + fase, classe: 'pill-warn', min, max };
  if (vpd > max) return { texto: 'Acima do ideal para ' + fase, classe: 'pill-warn', min, max };
  return { texto: 'Ideal para ' + fase, classe: 'pill-ok', min, max };
}

function atualizarVPD(tempInterna, umidInterna) {
  tirarEsqueleto('vpdValor');
  const pillEl = document.getElementById('vpdZonaPill');
  const idealEl = document.getElementById('vpdIdeal');
  if (tempInterna === null || tempInterna === undefined || umidInterna === null || umidInterna === undefined) {
    document.getElementById('vpdValor').textContent = '-- kPa';
    pillEl.textContent = '--'; pillEl.className = 'pill pill-neutro';
    idealEl.textContent = '';
    document.getElementById('vpdMarcador').style.left = '50%';
    return;
  }
  const vpd = calcularVPD(Number(tempInterna), Number(umidInterna));
  const av = avaliarVpd(vpd, ultimoModoAtual);
  document.getElementById('vpdValor').textContent = vpd.toFixed(2).replace('.', ',') + ' kPa';
  pillEl.textContent = av.texto;
  pillEl.className = 'pill ' + av.classe;
  idealEl.textContent = 'Ideal: ' + fmtKpa(av.min) + '–' + fmtKpa(av.max) + ' kPa';
  const pct = Math.max(0, Math.min(100, (vpd / 2.0) * 100));
  document.getElementById('vpdMarcador').style.left = pct + '%';
}

async function buscarJson(url) {
  // Evita resposta em cache.
  const r = await fetch(url + (url.includes('?') ? '&' : '?') + '_=' + Date.now(), { cache: 'no-store' });
  const texto = await r.text();
  let d;
  try {
    d = JSON.parse(texto);
  } catch (e) {
    const trecho = texto.trim().slice(0, 120).replace(/\s+/g, ' ');
    throw new Error('resposta invalida do servidor (HTTP ' + r.status + ')' + (trecho ? ': "' + trecho + '..."' : ' (vazia)'));
  }
  if (!r.ok || !d.ok) throw new Error((d.erro || ('HTTP ' + r.status)) + (d.detalhe ? ' \u2014 ' + d.detalhe : ''));
  return d;
}

let periodoAtual = '24h';
let graficoTemperatura = null;
let graficoUmidade = null;
let graficoSolo = null;
let graficoAgua = null;
let graficoVpd = null;
let ultimaLeitura = null;
let leituras24h = []; // leituras das últimas 24h (detalhes das automações no relógio)
let leituras24hCarregadas = false;
let ultimoIdLeitura = 0;  // maior id de leituras_ambiente já recebido (para dados.php?desde=)
let ultimoBloqueioIrrigacao = null;

// Mostra uma leitura nos cards e barras do Início (do banco ou do estado ao vivo).
function aplicarLeituraNosCards(ultima, redesenharReles) {
  tirarEsqueleto('statTempInterna'); tirarEsqueleto('statUmidInterna');
  tirarEsqueleto('statTempExterna'); tirarEsqueleto('statUmidExterna');
  tirarEsqueleto('statSolo');
  document.getElementById('statTempInterna').textContent = fmtTemp(ultima.temp_interna);
  document.getElementById('statUmidInterna').textContent = fmtUmid(ultima.umid_interna);
  document.getElementById('statTempExterna').textContent = fmtTemp(ultima.temp_externa);
  document.getElementById('statUmidExterna').textContent = fmtUmid(ultima.umid_externa);
  document.getElementById('statSolo').textContent = soloSemLeitura ? '—' : fmtSolo(ultima.solo_percentual);
  atualizarVPD(ultima.temp_interna, ultima.umid_interna);
  atualizarSoloBarra(ultima.solo_percentual);
  ultimaLeitura = ultima;
  atualizarAguaBarra(ultima);
  aplicarAlertasCards(ultima);
  // O card da irrigação depende do nível da caixa (proteção da bomba). Com o ao vivo isto roda
  // a cada 2 s: os relés só são redesenhados quando o bloqueio muda ou a cada leitura do banco
  // (1 por minuto, que também atualiza o "há quanto tempo"), para não atrapalhar um clique.
  const pct = caixaPercentualAtual();
  const bloqueio = pct !== null && pct < CAIXA_PROTECAO_PCT;
  if (ultimoTodosComandos.length && (redesenharReles || bloqueio !== ultimoBloqueioIrrigacao)) renderizarBotoesReles(ultimoTodosComandos);
  ultimoBloqueioIrrigacao = bloqueio;
}

// Recalcula os avisos de "sensor sem leitura" e mostra a leitura mais recente das 24h.
function aplicarLeituras24h() {
  const linhas = leituras24h;
  if (linhas.length > 0) {
    const ultimas3 = linhas.slice(-3);
    soloSemLeitura = ultimas3.length === 3 && ultimas3.every(l => l.solo_percentual === null);
    aguaSemLeitura = ultimas3.length === 3 && linhas.some(l => l.agua_litros !== null && l.agua_litros !== undefined) && ultimas3.every(l => l.agua_litros === null || l.agua_litros === undefined);
    // Com o estado ao vivo mais novo que o banco, os cards ficam com o ao vivo.
    if (!aoVivoRecente()) aplicarLeituraNosCards(linhas[linhas.length - 1], true);
    else if (ultimoTodosComandos.length) renderizarBotoesReles(ultimoTodosComandos);
  }
  if (abaAtual === 'caixa') renderizarCaixa();
}

function maiorIdLeitura(linhas) {
  return linhas.reduce((m, l) => Math.max(m, Number(l.id) || 0), 0);
}

async function carregarResumo() {
  // Carga completa das 24h (dados crus). Depois disso, só as leituras novas (carregarLeiturasNovas).
  const d = await buscarJson('dados.php?periodo=24h');
  leituras24h = d.dados || [];
  leituras24hCarregadas = true;
  ultimoIdLeitura = maiorIdLeitura(leituras24h);
  aplicarLeituras24h();
}

// Busca só as leituras com id maior que o último recebido e acrescenta às 24h.
async function carregarLeiturasNovas() {
  if (!leituras24hCarregadas) return carregarResumo();
  const d = await buscarJson('dados.php?periodo=24h&desde=' + ultimoIdLeitura);
  const novas = (d.dados || []).filter(l => (Number(l.id) || 0) > ultimoIdLeitura); // evita repetir se uma carga completa correu junto
  if (novas.length === 0) return;
  leituras24h = leituras24h.concat(novas);
  ultimoIdLeitura = maiorIdLeitura(novas);
  // Mantém só 24h, contando pelo relógio do próprio ESP32 (o mesmo das leituras).
  const limite = msLeitura(leituras24h[leituras24h.length - 1]) - 24 * 60 * 60 * 1000;
  if (!isNaN(limite)) leituras24h = leituras24h.filter(l => { const t = msLeitura(l); return isNaN(t) || t >= limite; });
  aplicarLeituras24h();
  // Gráficos de 24h: redesenha com os dados que já estão aqui, sem buscar de novo.
  if (periodoAtual === '24h' && typeof Chart !== 'undefined') desenharGraficos(leituras24h.slice(), '24h', true);
}

function formatarRotuloTempo(linha, periodo) {
  const valor = linha.dispositivo_timestamp || linha.criado_em;
  const partes = valor.split(/[- :]/);
  if (partes.length < 5) return valor;
  const [, mes, dia, hora, min] = partes;
  return periodo === '24h' ? (hora + ':' + min) : (dia + '/' + mes + ' ' + hora + 'h');
}

let graficoSemAnimacao = false; // redesenho de rotina (dado novo): sem animação, para não piscar
function montarGrafico(canvasId, linhas, periodo, series, unidade, escala) {
  const labels = linhas.map(l => formatarRotuloTempo(l, periodo));
  const datasets = series.map(s => ({
    label: s.label, data: linhas.map(l => l[s.campo] === null ? null : Number(l[s.campo])),
    borderColor: s.cor, backgroundColor: s.cor, spanGaps: true, pointRadius: 0, borderWidth: 2, tension: 0.25,
  }));
  const config = {
    type: 'line',
    data: { labels, datasets },
    options: {
      responsive: true, maintainAspectRatio: false,
      animation: graficoSemAnimacao ? false : undefined,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { labels: { color: '#8a938e', boxWidth: 12, font: { size: 11 } } } },
      scales: {
        x: { ticks: { color: '#8a938e', maxTicksLimit: 8, font: { size: 10 } }, grid: { color: '#242a27' } },
        y: { ticks: { color: '#8a938e', font: { size: 10 } }, grid: { color: '#242a27' }, title: { display: true, text: unidade, color: '#8a938e' }, ...(escala || {}) },
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
  if (typeof Chart === 'undefined') throw new Error('biblioteca de graficos (Chart.js) nao carregou - verifique a conexao ou algum bloqueador de anuncios/CDN');

  // 24h: reaproveita as leituras já carregadas pelo resumo (evita baixar as mesmas 1.440 linhas duas vezes).
  let linhas;
  if (periodo === '24h' && leituras24hCarregadas) linhas = leituras24h.slice();
  else linhas = (await buscarJson('dados.php?periodo=' + encodeURIComponent(periodo))).dados || [];
  desenharGraficos(linhas, periodo, false);
}

function desenharGraficos(linhas, periodo, semAnimacao) {
  graficoSemAnimacao = !!semAnimacao;
  try { desenharGraficosInterno(linhas, periodo); } finally { graficoSemAnimacao = false; }
}

function desenharGraficosInterno(linhas, periodo) {
  if (graficoTemperatura) { graficoTemperatura.destroy(); graficoTemperatura = null; }
  if (graficoUmidade) { graficoUmidade.destroy(); graficoUmidade = null; }
  if (graficoSolo) { graficoSolo.destroy(); graficoSolo = null; }
  if (graficoAgua) { graficoAgua.destroy(); graficoAgua = null; }
  if (graficoVpd) { graficoVpd.destroy(); graficoVpd = null; }
  tirarEsqueleto('graficoTemperaturaSkeleton');
  tirarEsqueleto('graficoUmidadeSkeleton');
  tirarEsqueleto('graficoSoloSkeleton');
  tirarEsqueleto('graficoAguaSkeleton');
  tirarEsqueleto('graficoVpdSkeleton');
  document.getElementById('graficoTemperaturaSkeleton').style.display = 'none';
  document.getElementById('graficoUmidadeSkeleton').style.display = 'none';
  document.getElementById('graficoSoloSkeleton').style.display = 'none';
  document.getElementById('graficoAguaSkeleton').style.display = 'none';
  document.getElementById('graficoVpdSkeleton').style.display = 'none';

  if (linhas.length === 0) {
    mostrarMensagemGrafico('graficoTemperatura', 'sem dados nesse periodo ainda');
    mostrarMensagemGrafico('graficoUmidade', 'sem dados nesse periodo ainda');
    mostrarMensagemGrafico('graficoSolo', 'sem dados nesse periodo ainda');
    mostrarMensagemGrafico('graficoAgua', 'sem dados nesse periodo ainda');
    mostrarMensagemGrafico('graficoVpd', 'sem dados nesse periodo ainda');
    return;
  }
  mostrarMensagemGrafico('graficoTemperatura', null);
  mostrarMensagemGrafico('graficoUmidade', null);
  mostrarMensagemGrafico('graficoSolo', null);
  mostrarMensagemGrafico('graficoVpd', null);

  graficoTemperatura = montarGrafico('graficoTemperatura', linhas, periodo, [
    { label: 'Interna (\u00b0C)', campo: 'temp_interna', cor: '#4CAF50' },
    { label: 'Externa (\u00b0C)', campo: 'temp_externa', cor: '#e8c977' },
  ], '\u00b0C');

  graficoUmidade = montarGrafico('graficoUmidade', linhas, periodo, [
    { label: 'Interna (%)', campo: 'umid_interna', cor: '#7fa9c2' },
    { label: 'Externa (%)', campo: 'umid_externa', cor: '#b58bc9' },
  ], '%');

  graficoSolo = montarGrafico('graficoSolo', linhas, periodo, [
    { label: 'Solo (%)', campo: 'solo_percentual', cor: '#b98d5e' },
  ], '%', { min: 0, max: 100 });

  if (linhas.some(l => l.agua_litros !== null && l.agua_litros !== undefined)) {
    mostrarMensagemGrafico('graficoAgua', null);
    graficoAgua = montarGrafico('graficoAgua', linhas, periodo, [
      { label: 'Caixa d\'água (L)', campo: 'agua_litros', cor: '#6fb3e0' },
    ], 'litros', { min: 0, suggestedMax: CAIXA_CAPACIDADE_L });
  } else {
    mostrarMensagemGrafico('graficoAgua', 'sem leituras da caixa d\'água nesse período (sensor a partir do firmware 2.7.0)');
  }

  // Leituras antigas sem VPD gravado: calcula aqui.
  linhas.forEach(l => {
    if ((l.vpd === null || l.vpd === undefined) && l.temp_interna !== null && l.umid_interna !== null) {
      l.vpd = calcularVPD(Number(l.temp_interna), Number(l.umid_interna));
    }
  });
  graficoVpd = montarGraficoVpd(linhas, periodo);
}

function marcarPeriodo(periodo) {
  document.querySelectorAll('.periodo-botao').forEach(btn => btn.classList.toggle('ativo', btn.dataset.periodo === periodo));
  const csv = document.getElementById('btnExportarCsv');
  if (csv) csv.href = 'exportar_csv.php?periodo=' + encodeURIComponent(periodo);
}
function mudarPeriodo(periodo) {
  if (periodo === periodoAtual) return;
  periodoAtual = periodo;
  marcarPeriodo(periodo);
  carregarGraficos(periodo).catch(e => { document.getElementById('statusLinha').textContent = 'erro ao carregar grafico: ' + e.message; });
}

/* ==================== Fotoperíodo (janela de luz por fase) ==================== */
// Duração fixa por fase (igual ao firmware); só o início é configurável.
const HORAS_LUZ_POR_MODO = { vegetativo: 18, floracao: 12 };
const INICIO_PADRAO_POR_MODO = { vegetativo: '16:00', floracao: '19:00' };
const NOME_MODO = { vegetativo: 'Vegetação', floracao: 'Floração' };
let modoAtualParaLuz = 'vegetativo';

function pad2(n) { return String(n).padStart(2, '0'); }
function hhmmParaMin(v) {
  const m = /^(\d{1,2}):(\d{2})$/.exec(String(v || ''));
  if (!m) return null;
  const t = Number(m[1]) * 60 + Number(m[2]);
  return (t >= 0 && t < 1440) ? t : null;
}
function minParaHHMM(min) { min = ((min % 1440) + 1440) % 1440; return pad2(Math.floor(min / 60)) + ':' + pad2(min % 60); }
function inicioLuzMin(modo) {
  const v = hhmmParaMin(CONFIG['fotoperiodo_' + modo + '_inicio']);
  return v === null ? hhmmParaMin(INICIO_PADRAO_POR_MODO[modo]) : v;
}
// inicio/fim em horas fracionadas; inicioMin/fimMin em minutos.
function janelaDoModo(modo, inicioMinForcado) {
  modo = modo === 'floracao' ? 'floracao' : 'vegetativo';
  const ini = (inicioMinForcado === undefined || inicioMinForcado === null) ? inicioLuzMin(modo) : inicioMinForcado;
  const fim = (ini + HORAS_LUZ_POR_MODO[modo] * 60) % 1440;
  return { inicio: ini / 60, fim: fim / 60, inicioMin: ini, fimMin: fim };
}
function luzLigadaNaJanela(janela, horaAtual) {
  return janela.inicio > janela.fim ? (horaAtual >= janela.inicio || horaAtual < janela.fim) : (horaAtual >= janela.inicio && horaAtual < janela.fim);
}

function estadoEDuracaoLuz(modoAtual) {
  const janela = janelaDoModo(modoAtual);
  const agora = new Date();
  const horaAtual = agora.getHours() + agora.getMinutes() / 60 + agora.getSeconds() / 3600;
  const ligada = luzLigadaNaJanela(janela, horaAtual);
  const horaAlvo = ligada ? janela.fim : janela.inicio;
  let diffHoras = horaAlvo - horaAtual;
  if (diffHoras <= 0) diffHoras += 24;
  return { ligada, totalSegundos: Math.round(diffHoras * 3600), proximaHHMM: minParaHHMM(Math.round(horaAlvo * 60)) };
}
function formatarContagemLuz(totalSegundos) {
  const h = Math.floor(totalSegundos / 3600);
  const m = Math.floor((totalSegundos % 3600) / 60);
  const s = totalSegundos % 60;
  return h > 0 ? (h + 'h ' + pad2(m) + 'min') : (m + 'min ' + pad2(s) + 's');
}
// Card "Luz no ciclo": tempo aceso desde o último acendimento do timer.
function formatarDuracaoCurta(totalMin) {
  const h = Math.floor(totalMin / 60), m = Math.floor(totalMin % 60);
  return h > 0 ? h + 'h ' + pad2(m) + 'min' : m + 'min';
}
function atualizarCardLuz() {
  const el = document.getElementById('luzContagem');
  const leg = document.getElementById('luzLegenda');
  if (el) {
    tirarEsqueleto('luzContagem');
    const j = janelaDoModo(modoAtualParaLuz);
    const agora = new Date();
    const minAgora = agora.getHours() * 60 + agora.getMinutes();
    const est = estadoEDuracaoLuz(modoAtualParaLuz);
    if (est.ligada) {
      const aceso = ((minAgora - j.inicioMin) % 1440 + 1440) % 1440;
      el.textContent = formatarDuracaoCurta(aceso);
      leg.textContent = 'de ' + HORAS_LUZ_POR_MODO[modoAtualParaLuz] + 'h · apaga ' + minParaHHMM(j.fimMin);
    } else {
      el.textContent = 'Apagada';
      leg.textContent = 'acende ' + minParaHHMM(j.inicioMin) + ' · em ' + formatarDuracaoCurta(Math.ceil(est.totalSegundos / 60));
    }
  }
  atualizarRodape();
  if (abaAtual === 'fotoperiodo') atualizarStatusFotoperiodo();
}
function atualizarPillsModo() {
  ['vegetativo', 'floracao'].forEach(modo => {
    const j = janelaDoModo(modo);
    const el = document.getElementById(modo === 'vegetativo' ? 'pillVegetativoSub' : 'pillFloracaoSub');
    if (el) el.textContent = HORAS_LUZ_POR_MODO[modo] + 'h luz · ' + minParaHHMM(j.inicioMin) + '–' + minParaHHMM(j.fimMin);
  });
}
atualizarCardLuz();
setInterval(atualizarCardLuz, 1000);

/* ==================== Roda de eventos ==================== */
const RELES_NA_RODA = ['Ventilador', 'Saida de Ar', 'Aquecedor', 'Umidificador'];
const RELES_NO_STATUS_RODA = ['Ventilador', 'Saida de Ar'];

function horaFracionada(dataHoraStr) {
  if (!dataHoraStr) return 0;
  const partes = String(dataHoraStr).split(' ');
  const hms = (partes[1] || '00:00:00').split(':');
  const h = Number(hms[0]) || 0, m = Number(hms[1]) || 0, s = Number(hms[2]) || 0;
  return h + m / 60 + s / 3600;
}
function timestampEvento(c) { return c.dispositivo_timestamp || c.criado_em; }
function anguloGraus(horaFrac) { return (horaFrac / 24) * 360 - 90; }
function pontoNoCirculo(cx, cy, raio, graus) { const rad = (graus * Math.PI) / 180; return { x: cx + raio * Math.cos(rad), y: cy + raio * Math.sin(rad) }; }
function caminhoArco(cx, cy, raio, horaInicio, horaFim) {
  let span = horaFim - horaInicio;
  if (span <= 0) span += 24;
  const p0 = pontoNoCirculo(cx, cy, raio, anguloGraus(horaInicio));
  const p1 = pontoNoCirculo(cx, cy, raio, anguloGraus(horaInicio + span));
  const arcoGrande = span > 12 ? 1 : 0;
  return 'M ' + p0.x.toFixed(2) + ' ' + p0.y.toFixed(2) + ' A ' + raio + ' ' + raio + ' 0 ' + arcoGrande + ' 1 ' + p1.x.toFixed(2) + ' ' + p1.y.toFixed(2);
}
function svgEl(tag, attrs) { const el = document.createElementNS('http://www.w3.org/2000/svg', tag); Object.keys(attrs).forEach(k => el.setAttribute(k, attrs[k])); return el; }
function formatarDuracao(ms) {
  if (ms < 0) ms = 0;
  const min = Math.floor(ms / 60000);
  if (min < 1) return 'agora mesmo';
  if (min < 60) return min + ' min';
  const h = Math.floor(min / 60), m = min % 60;
  if (h >= 24) { const d = Math.floor(h / 24), hr = h % 24; return d + (d === 1 ? ' dia' : ' dias') + (hr ? ' ' + hr + 'h' : ''); }
  return h + 'h' + (m > 0 ? String(m).padStart(2, '0') + 'min' : '');
}
function corDoEvento(c) {
  if (ehEventoAutomacao(c)) return (c.tipo === 'rele' && c.valor !== 'ligado') ? '#3f8f86' : '#5cc8b4';
  if (c.tipo === 'alerta') return c.valor === 'normalizado' ? '#9CC65A' : '#e08a85';
  if (c.tipo === 'acesso') return '#4CAF50';
  if (c.tipo === 'fase') return '#b48ee0';
  if (c.tipo === 'notificacao') return c.valor === 'falhou' ? '#e08a85' : '#6fb3e0';
  return c.valor === 'ligado' ? '#e8c977' : '#7fa9c2';
}
function descricaoEvento(c) {
  if (ehEventoAutomacao(c)) return descricaoAutomacao(c);
  if (c.tipo === 'alerta' && c.alvo === 'Solo') return c.valor === 'normalizado' ? 'Sensor de solo voltou a ler' : 'Sensor de solo sem leitura';
  if (c.tipo === 'alerta') return 'Alerta: ' + c.alvo + ' (' + c.valor + ')';
  if (c.tipo === 'acesso') return 'Acesso liberado';
  if (c.tipo === 'notificacao') {
    if (c.alvo === 'telegram') return 'Telegram ' + (c.valor === 'conectado' ? 'conectado' : 'desconectado');
    const nome = { temperatura: 'temperatura', umidade: 'umidade', solo: 'solo', agua: 'caixa d\'água baixa', vpd: 'VPD', offline: 'dispositivo offline', timer: 'timer não confirmado', resumo: 'resumo diário', teste: 'teste' }[c.alvo] || c.alvo;
    if (c.valor === 'falhou') return 'Falha ao enviar aviso no Telegram (' + nome + ')';
    if (c.valor === 'normalizado') return 'Telegram: ' + nome + ' normalizado';
    return 'Aviso no Telegram: ' + nome;
  }
  if (c.tipo === 'fase') {
    if (c.alvo === 'programacao') return 'Troca de fase programada cancelada';
    const nome = NOME_MODO[c.alvo] || c.alvo;
    if (String(c.valor).indexOf('programada para') === 0) return nome + ' ' + c.valor;
    return nome + ' ativada' + (c.valor === 'programada_executada' ? ' (programada)' : '');
  }
  return (RELE_ROTULO[c.alvo] || c.alvo) + ' ' + (c.valor === 'ligado' ? 'ligado' : 'desligado');
}
function chaveEventos(eventos) { return eventos.map(ev => (ev.dispositivo_timestamp || ev.criado_em) + '|' + ev.tipo + '|' + ev.alvo + '|' + ev.valor).join(','); }

/* ---------- Relógio de eventos: períodos ligados em anéis, eventos pontuais e lista agrupada ---------- */
// Equipamentos com anel próprio: cada trecho pintado é um período ligado.
const RODA_ANEIS = [
  { rele: 'Aquecedor', cor: '#e8a05c', icone: 'heater', raio: 121 },
  { rele: 'Umidificador', cor: '#6fb3e0', icone: 'droplets', raio: 106 },
];
const RODA_COR = { porta: '#4CAF50', aviso: '#e08a85', avisoOk: '#9CC65A', fase: '#b48ee0', auto: '#5cc8b4', ligado: '#e8c977', telegram: '#6fb3e0' };
const RODA_RAIO = 150;
const RODA_LIMITE_LISTA = 8;
const filtrosRoda = { equipamentos: true, automacoes: true, porta: true, avisos: true, fase: true };
let focoRoda = null;          // 'Aquecedor' | 'Umidificador' | 'porta' | 'avisos' | null
let rodaSelecao = [];         // ids dos itens selecionados
let rodaItens = [];
let rodaListaExpandida = false;
let rodaOntemAberto = false;
let ultimoEventosHoje = [];
let ultimoModoAtual = 'vegetativo';

function corSuave(hex, alfa) {
  const h = String(hex).replace('#', '');
  return 'rgba(' + parseInt(h.substr(0, 2), 16) + ',' + parseInt(h.substr(2, 2), 16) + ',' + parseInt(h.substr(4, 2), 16) + ',' + (alfa || 0.16) + ')';
}
function hhmmDeMs(ms) { const d = new Date(ms); return pad2(d.getHours()) + ':' + pad2(d.getMinutes()); }
function horaFracDeMs(ms) { const d = new Date(ms); return d.getHours() + d.getMinutes() / 60 + d.getSeconds() / 3600; }
function inicioDoDia(ms) { const d = new Date(ms); d.setHours(0, 0, 0, 0); return d.getTime(); }
function quandoTexto(ms) {
  const d = new Date(ms), hoje = inicioDoDia(Date.now());
  const data = pad2(d.getDate()) + '/' + pad2(d.getMonth() + 1) + '/' + d.getFullYear();
  if (ms >= hoje) return 'hoje, ' + data;
  if (ms >= hoje - 86400000) return 'ontem, ' + data;
  return data;
}
// "5h12" / "42 min" (cabe numa linha nos chips do resumo)
function duracaoCompacta(ms) { const m = Math.max(0, Math.round(ms / 60000)); return m < 60 ? m + ' min' : Math.floor(m / 60) + 'h' + pad2(m % 60); }
function duracaoCurta(ms) { const t = formatarDuracao(ms); return t === 'agora mesmo' ? 'menos de 1 min' : t; }
function origemCurta(origem) {
  return ({ automacao: 'Automático', automatico_luz: 'Automático', retomou_automatico: 'Automático', web: 'Painel', manual: 'Botão do OLED',
    botao: 'Botão', protecao_agua: 'Proteção da bomba', bloqueio_agua: 'Proteção da bomba', pausa_painel: 'Painel', pausa_oled: 'Botão do OLED', sistema: 'Sistema', servidor: 'Servidor', telegram: 'Telegram', boot: 'Ao ligar' })[origem] || origem || '—';
}
function htmlOrigem(origem) {
  const t = origemCurta(origem);
  return t === 'Automático' ? '<b class="rr-auto">Automático</b>' : escapeHtml(t);
}
function fmtGrandeza(campo, v) {
  if (v === null || v === undefined || isNaN(v)) return '—';
  return campo === 'temp_interna' ? fmtTemp(v) : Math.round(v) + '%';
}

// ---- Avisos: textos com o valor lido e a faixa configurada
const AVISO_CAMPO = { temperatura: ['temp_interna', 'Temperatura', 'temp_alerta_min', 'temp_alerta_max'], umidade: ['umid_interna', 'Umidade', 'umid_alerta_min', 'umid_alerta_max'] };
function textoAviso(c, irmao) {
  const ms = msEvento(c);
  const tg = c.tipo === 'notificacao' ? c : irmao;
  const envio = tg ? (tg.valor === 'falhou' ? 'falha ao enviar no Telegram' : (tg.valor === 'normalizado' ? 'aviso de normalização no Telegram' : 'aviso enviado no Telegram')) : '';
  if (c.tipo === 'alerta' && c.alvo === 'Solo') {
    const ok = c.valor === 'normalizado';
    return { titulo: ok ? 'Sensor de solo voltou a ler' : 'Sensor de solo sem leitura', sub: [ok ? 'Normalizado' : 'Verifique o cabo do sensor', envio].filter(Boolean).join(' · '), ok };
  }
  if (c.tipo === 'notificacao' && AVISO_CAMPO[c.alvo]) {
    const [campo, nome, cMin, cMax] = AVISO_CAMPO[c.alvo];
    const v = leituraEm(campo, ms);
    const min = Number(CONFIG[cMin]), max = Number(CONFIG[cMax]);
    const faixa = campo === 'temp_interna' ? fmtTemp(min) + '–' + fmtTemp(max) : min + '–' + max + '%';
    if (c.valor === 'normalizado') return { titulo: nome + ' voltou ao normal', sub: [v !== null ? fmtGrandeza(campo, v) : '', 'faixa ' + faixa].filter(Boolean).join(' · '), ok: true };
    let regra = 'fora da faixa ' + faixa;
    if (v !== null && v < min) regra = 'abaixo de ' + (campo === 'temp_interna' ? fmtTemp(min) : min + '%');
    if (v !== null && v > max) regra = 'acima de ' + (campo === 'temp_interna' ? fmtTemp(max) : max + '%');
    return { titulo: nome + (v !== null ? ' ' + fmtGrandeza(campo, v) : ' fora da faixa'), sub: regra.charAt(0).toUpperCase() + regra.slice(1) + ' · ' + envio, ok: false };
  }
  return { titulo: descricaoEvento(c), sub: origemCurta(c.origem), ok: c.valor === 'normalizado' || c.valor === 'conectado' || c.valor === 'enviada' && c.alvo === 'resumo' };
}

// ---- Configurações das automações: título curto + faixa na linha de baixo
function textoConfigAutomacao(c, relacionado) {
  const a = automacaoPorChave(c.alvo);
  const v = lerValorAutomacao(c.valor);
  if (!a || !v) return { titulo: descricaoEvento(c), sub: origemCurta(c.origem) };
  const nome = 'Automação ' + nomeDaAutomacao(a);
  const faixa = fmtAuto(a, v.min) + '–' + fmtAuto(a, v.max);
  const anterior = ultimoTodosComandos.find(x => x.tipo === 'automacao' && x.alvo === c.alvo && msEvento(x) < msEvento(c));
  const ant = anterior ? lerValorAutomacao(anterior.valor) : null;
  if (String(c.origem || '').indexOf('pausa') === 0) {
    const acao = relacionado ? (RELE_ROTULO[a.rele] || a.rele).toLowerCase() + (relacionado.valor === 'ligado' ? ' ligado' : ' desligado') + ' manualmente' : 'comando manual';
    return { titulo: nome + ' pausada', sub: origemCurta(c.origem) + ' · ' + acao };
  }
  if (!v.ativa) return { titulo: nome + ' desativada', sub: origemCurta(c.origem) };
  if (ant && ant.ativa && (ant.min !== v.min || ant.max !== v.max)) return { titulo: 'Faixa ' + nomeDaAutomacao(a) + ' alterada', sub: origemCurta(c.origem) + ' · ' + faixa };
  return { titulo: nome + (ant && !ant.ativa ? ' reativada' : ' ativada'), sub: origemCurta(c.origem) + ' · ' + faixa };
}

// Monta os itens do relógio a partir dos registros (mais recentes primeiro em ultimoTodosComandos).
function construirItensRoda() {
  const agora = Date.now(), inicioJanela = agora - 24 * 60 * 60 * 1000;
  const itens = [];
  const relesAnel = RODA_ANEIS.map(r => r.rele);

  // 1) Períodos ligados dos equipamentos com anel
  RODA_ANEIS.forEach(anel => {
    const evs = ultimoTodosComandos.filter(c => c.tipo === 'rele' && c.alvo === anel.rele && !isNaN(msEvento(c))).slice().reverse();
    let aberto = null;
    const antes = evs.filter(c => msEvento(c) < inicioJanela).pop();
    if (antes && antes.valor === 'ligado') aberto = { ev: antes, ms: inicioJanela, cortado: true };
    evs.filter(c => msEvento(c) >= inicioJanela && msEvento(c) <= agora).forEach(c => {
      if (c.valor === 'ligado' && !aberto) aberto = { ev: c, ms: msEvento(c), cortado: false };
      else if (c.valor !== 'ligado' && aberto) {
        itens.push(itemPeriodo(anel, aberto, c, msEvento(c)));
        aberto = null;
      }
    });
    if (aberto) itens.push(itemPeriodo(anel, aberto, null, null));
  });

  // 2) Eventos pontuais (com junção de registros que são uma ação só)
  const usados = new Set();
  const pontos = ultimoEventosHoje.filter(c => !(c.tipo === 'rele' && relesAnel.includes(c.alvo)));
  pontos.forEach((c, i) => {
    if (usados.has(c)) return;
    const ms = msEvento(c);
    let item = null;
    if (c.tipo === 'clima_vpd') {
      const pausa = String(c.origem || '').indexOf('pausa') === 0;
      const rel = pausa ? ultimoTodosComandos.find(x => x.tipo === 'rele' && (x.alvo === 'Aquecedor' || x.alvo === 'Umidificador') && x.origem !== 'automacao' && Math.abs(msEvento(x) - ms) <= 2 * 60000) : null;
      item = { categoria: 'automacoes', foco: null, cor: RODA_COR.auto, icone: pausa ? 'pause' : 'gauge', vazado: pausa, ...textoClima(c, rel) };
    } else if (c.tipo === 'automacao') {
      const a = automacaoPorChave(c.alvo);
      const rel = a && String(c.origem || '').indexOf('pausa') === 0
        ? ultimoTodosComandos.find(x => x.tipo === 'rele' && x.alvo === a.rele && x.origem !== 'automacao' && Math.abs(msEvento(x) - ms) <= 2 * 60000) : null;
      const t = textoConfigAutomacao(c, rel);
      item = { categoria: 'automacoes', foco: a ? a.rele : null, cor: RODA_COR.auto, icone: String(c.origem || '').indexOf('pausa') === 0 ? 'pause' : 'bot', vazado: String(c.origem || '').indexOf('pausa') === 0, ...t };
    } else if (c.tipo === 'acesso') {
      item = { categoria: 'porta', foco: 'porta', cor: RODA_COR.porta, icone: 'door-open', titulo: 'Porta liberada', sub: origemCurta(c.origem) };
    } else if (c.tipo === 'alerta' || c.tipo === 'notificacao') {
      // alerta do solo + aviso do Telegram do solo viram um item
      let irmao = null;
      if (c.tipo === 'alerta' || c.alvo === 'solo') {
        irmao = pontos.find(x => x !== c && !usados.has(x) && ((c.tipo === 'alerta' && x.tipo === 'notificacao' && x.alvo === 'solo') || (c.tipo === 'notificacao' && x.tipo === 'alerta' && x.alvo === 'Solo')) && Math.abs(msEvento(x) - ms) <= 10 * 60000);
        if (irmao) usados.add(irmao);
      }
      const base = c.tipo === 'alerta' ? c : (irmao && irmao.tipo === 'alerta' ? irmao : c);
      const t = textoAviso(base, base === c ? irmao : c);
      item = { categoria: 'avisos', foco: 'avisos', cor: t.ok ? RODA_COR.avisoOk : RODA_COR.aviso, icone: t.ok ? 'circle-check' : 'triangle-alert', titulo: t.titulo, sub: t.sub, grave: !t.ok, eventosExtra: irmao ? [irmao] : [] };
    } else if (c.tipo === 'fase') {
      item = { categoria: 'fase', foco: null, cor: RODA_COR.fase, icone: 'sprout', titulo: descricaoEvento(c), sub: origemCurta(c.origem) };
    } else if (c.tipo === 'rele') {
      const ligado = c.valor === 'ligado';
      const depois = ultimoTodosComandos.find(x => x.tipo === 'rele' && x.alvo === c.alvo && msEvento(x) > ms);
      item = { categoria: 'equipamentos', foco: null, cor: RODA_COR.ligado, vazado: !ligado, icone: RELE_ICONE[c.alvo] || 'plug',
        titulo: (RELE_ROTULO[c.alvo] || c.alvo) + (ligado ? ' ligado' : ' desligado'),
        sub: origemCurta(c.origem) + (!depois ? (ligado ? ' · continua ligado' : ' · continua desligado') : '') };
    }
    if (!item) return;
    // itens juntados ficam na hora do primeiro registro (ex.: alerta antes do aviso do Telegram)
    itens.push(Object.assign(item, { id: 'p|' + chaveEventos([c]), tipo: 'ponto', inicioMs: Math.min(ms, ...(item.eventosExtra || []).map(msEvento)), fimMs: null, eventos: [c].concat(item.eventosExtra || []) }));
  });
  return itens;
}

function itemPeriodo(anel, aberto, evFim, fimMs) {
  const a = automacaoDoRele(anel.rele);
  const nome = RELE_ROTULO[anel.rele] || anel.rele;
  const ini = aberto.ms, ate = fimMs === null ? Date.now() : fimMs;
  const dur = duracaoCurta(ate - ini);
  // ciclos do clima por VPD mostram o VPD; registros antigos, a temperatura ou a umidade
  const climaNoInicio = climaEm(ini);
  const cfgClima = aberto.ev.origem === 'automacao' ? climaNoInicio : null;
  const campo = climaNoInicio ? 'vpd' : (a ? a.campoLeitura : null);
  const lidaIni = campo === 'vpd' && !aberto.cortado ? leituraDaAcao(aberto.ev) : null;
  const lidaFim = campo === 'vpd' && evFim ? leituraDaAcao(evFim) : null;
  const vIni = campo === 'vpd' ? (lidaIni && lidaIni.vpd !== null ? lidaIni.vpd : vpdEm(ini)) : (campo ? leituraEm(campo, ini) : null);
  const vFim = campo === 'vpd' ? (fimMs === null ? vpdDeLeitura(ultimaLeitura) : (lidaFim && lidaFim.vpd !== null ? lidaFim.vpd : vpdEm(fimMs)))
    : (campo ? (fimMs === null ? (ultimaLeitura ? Number(ultimaLeitura[campo]) : null) : leituraEm(campo, fimMs)) : null);
  const leituras = (vIni !== null && vFim !== null)
    ? (campo === 'vpd' ? 'VPD ' + brN(vIni, 2) + ' → ' + brN(vFim, 2) + ' kPa' : fmtGrandeza(campo, vIni) + ' → ' + fmtGrandeza(campo, vFim)) : '';
  const auto = aberto.ev.origem === 'automacao';
  const sub = fimMs === null
    ? htmlOrigem(aberto.ev.origem) + ' · ' + escapeHtml(dur) + ' · em andamento'
    : htmlOrigem(aberto.ev.origem) + (leituras ? ' · ' + escapeHtml(leituras) : ' · ' + escapeHtml(dur));
  return {
    id: 'a|' + anel.rele + '|' + ini, tipo: 'periodo', categoria: 'equipamentos', foco: anel.rele, anel, cor: anel.cor, icone: anel.icone,
    inicioMs: ini, fimMs, eventos: [aberto.ev].concat(evFim ? [evFim] : []), auto, cortado: aberto.cortado,
    titulo: nome + ' ligado' + (fimMs === null ? '' : ' por ' + dur), subHtml: sub, leituras, campo, cfgClima, evIni: aberto.ev, evFim,
  };
}

function itemVisivel(it) {
  if (!filtrosRoda[it.categoria]) return false;
  if (focoRoda && it.foco !== focoRoda) return false;
  return true;
}

// ---- Resumo das 24h (os chips também filtram)
function renderizarResumoRoda() {
  const el = document.getElementById('rrResumo');
  if (!el) return;
  const chips = RODA_ANEIS.map(anel => {
    const periodos = rodaItens.filter(i => i.tipo === 'periodo' && i.anel === anel);
    const total = periodos.reduce((s, p) => s + ((p.fimMs === null ? Date.now() : p.fimMs) - p.inicioMs), 0);
    const acoes = periodos.reduce((s, p) => s + p.eventos.filter(e => !p.cortado || e !== p.evIni).length, 0);
    return { foco: anel.rele, cor: anel.cor, icone: anel.icone, nome: RELE_ROTULO[anel.rele] || anel.rele,
      sub: (total > 0 ? duracaoCompacta(total) + ' ligado' : 'desligado') + ' · ' + acoes + (acoes === 1 ? ' ação' : ' ações') };
  });
  const portas = rodaItens.filter(i => i.categoria === 'porta');
  const avisos = rodaItens.filter(i => i.categoria === 'avisos' && i.grave);
  const ultimo = l => l.length ? ' · ' + hhmmDeMs(Math.max(...l.map(i => i.inicioMs))) : '';
  chips.push({ foco: 'porta', cor: RODA_COR.porta, icone: 'door-open', nome: 'Porta', sub: portas.length ? portas.length + (portas.length === 1 ? ' abertura' : ' aberturas') + ultimo(portas) : 'nenhuma abertura' });
  chips.push({ foco: 'avisos', cor: RODA_COR.aviso, icone: 'triangle-alert', nome: 'Avisos', sub: avisos.length ? avisos.length + (avisos.length === 1 ? ' alerta' : ' alertas') + ultimo(avisos) : 'nenhum alerta' });
  el.innerHTML = chips.map(c =>
    '<button type="button" class="rr-chip' + (focoRoda === c.foco ? ' ativo' : '') + '" data-foco="' + c.foco + '" aria-pressed="' + (focoRoda === c.foco) + '" style="--c:' + c.cor + ';--cs:' + corSuave(c.cor, 0.1) + '">' +
      '<span class="rr-chip-icone" style="background:' + corSuave(c.cor) + ';color:' + c.cor + '"><svg data-lucide="' + c.icone + '"></svg></span>' +
      '<span><strong>' + escapeHtml(c.nome) + '</strong><small>' + escapeHtml(c.sub) + '</small></span></button>').join('');
}

function desenharRoda(itens, modoAtual) {
  const svg = document.getElementById('rodaEventos');
  svg.innerHTML = '';
  const cx = 200, cy = 200, R = RODA_RAIO;
  const agora = new Date();
  const horaAgora = agora.getHours() + agora.getMinutes() / 60;
  const pt = (r, h) => pontoNoCirculo(cx, cy, r, anguloGraus(h));
  const arco = (r, h0, h1) => caminhoArco(cx, cy, r, h0, h1);

  // trilha externa + janela de luz da fase ativa (18/6 na vegetação, 12/12 na floração)
  svg.appendChild(svgEl('circle', { cx, cy, r: R, fill: 'none', stroke: '#242a27', 'stroke-width': 22, opacity: 0.7 }));
  const janela = janelaDoModo(modoAtual);
  svg.appendChild(svgEl('path', { d: arco(R, janela.inicio, janela.fim), fill: 'none', stroke: '#33402d', 'stroke-width': 22 }));

  // anéis dos equipamentos
  RODA_ANEIS.forEach(anel => {
    const apagado = focoRoda && focoRoda !== anel.rele ? 0.25 : 1;
    svg.appendChild(svgEl('circle', { cx, cy, r: anel.raio, fill: 'none', stroke: '#202522', 'stroke-width': 9, opacity: apagado }));
    if (!filtrosRoda.equipamentos) return;
    itens.filter(i => i.tipo === 'periodo' && i.anel === anel).forEach(p => {
      const h0 = horaFracDeMs(p.inicioMs);
      let h1 = horaFracDeMs(p.fimMs === null ? Date.now() : p.fimMs);
      if ((h1 - h0 + 24) % 24 < 0.12) h1 = h0 + 0.12; // períodos curtos continuam visíveis
      const g = svgEl('g', { class: 'roda-evento-dot rr-arco', tabindex: '0', role: 'button', 'aria-label': p.titulo, opacity: apagado });
      const sel = rodaSelecao.includes(p.id);
      if (sel) g.appendChild(svgEl('path', { d: arco(anel.raio, h0, h1), fill: 'none', stroke: '#eef1ee', 'stroke-width': 14, 'stroke-linecap': 'round' }));
      g.appendChild(svgEl('path', { d: arco(anel.raio, h0, h1), fill: 'none', stroke: anel.cor, 'stroke-width': 9, 'stroke-linecap': 'round' }));
      g.appendChild(svgEl('path', { d: arco(anel.raio, h0, h1), fill: 'none', stroke: 'transparent', 'stroke-width': 18 }));
      if (p.fimMs === null) { const q = pt(anel.raio, h1); g.appendChild(svgEl('circle', { cx: q.x.toFixed(2), cy: q.y.toFixed(2), r: 5.5, fill: anel.cor, stroke: '#121414', 'stroke-width': 2 })); }
      const abrir = () => selecionarRoda([p.id], false);
      g.addEventListener('click', abrir);
      g.addEventListener('keydown', ev => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); abrir(); } });
      svg.appendChild(g);
    });
  });

  // marcas de hora
  for (let h = 0; h < 24; h++) {
    const principal = h % 3 === 0;
    const p1 = pt(R + 14, h), p2 = pt(R + (principal ? 24 : 18), h);
    svg.appendChild(svgEl('line', { x1: p1.x.toFixed(2), y1: p1.y.toFixed(2), x2: p2.x.toFixed(2), y2: p2.y.toFixed(2), stroke: principal ? '#8a938e' : '#3a423c', 'stroke-width': principal ? 1.5 : 1 }));
    if (principal) {
      const pl = pt(R + 36, h);
      const texto = svgEl('text', { x: pl.x.toFixed(2), y: pl.y.toFixed(2), class: 'roda-hora-label', 'dominant-baseline': 'central' });
      texto.textContent = pad2(h) + 'h';
      svg.appendChild(texto);
    }
  }

  // eventos pontuais (agrupados quando próximos)
  const pontos = itens.filter(i => i.tipo === 'ponto');
  const ordenados = pontos.map(it => ({ it, h: horaFracDeMs(it.inicioMs) })).sort((a, b) => a.h - b.h);
  const clusters = [];
  ordenados.forEach(o => {
    const ult = clusters[clusters.length - 1];
    if (ult && (o.h - ult.hMax) * 60 <= 8) { ult.membros.push(o.it); ult.hMax = o.h; ult.soma += o.h; }
    else clusters.push({ membros: [o.it], hMax: o.h, soma: o.h });
  });
  clusters.forEach(cl => {
    const q = pt(R, cl.soma / cl.membros.length);
    const g = svgEl('g', { class: 'roda-evento-dot', tabindex: '0', role: 'button', 'aria-label': cl.membros.map(m => m.titulo).join('; ') });
    g.appendChild(svgEl('circle', { cx: q.x.toFixed(2), cy: q.y.toFixed(2), r: 16, fill: 'transparent' }));
    const ids = cl.membros.map(m => m.id);
    const sel = ids.every(id => rodaSelecao.includes(id)) && rodaSelecao.length === ids.length;
    if (cl.membros.length > 1) {
      g.appendChild(svgEl('circle', { cx: q.x.toFixed(2), cy: q.y.toFixed(2), r: 9, fill: '#dfe4de', stroke: sel ? '#eef1ee' : '#121414', 'stroke-width': sel ? 3 : 2 }));
      const t = svgEl('text', { x: q.x.toFixed(2), y: q.y.toFixed(2), 'text-anchor': 'middle', 'dominant-baseline': 'central', 'font-size': '9', 'font-weight': '700', fill: '#121414' });
      t.textContent = String(cl.membros.length);
      g.appendChild(t);
    } else {
      const m = cl.membros[0];
      if (m.vazado) g.appendChild(svgEl('circle', { cx: q.x.toFixed(2), cy: q.y.toFixed(2), r: 5.5, fill: '#121414', stroke: sel ? '#eef1ee' : m.cor, 'stroke-width': sel ? 3.5 : 2.5 }));
      else g.appendChild(svgEl('circle', { cx: q.x.toFixed(2), cy: q.y.toFixed(2), r: 6.5, fill: m.cor, stroke: sel ? '#eef1ee' : '#121414', 'stroke-width': sel ? 3 : 2 }));
    }
    const abrir = () => selecionarRoda(ids, false);
    g.addEventListener('click', abrir);
    g.addEventListener('keydown', ev => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); abrir(); } });
    svg.appendChild(g);
  });

  // ontem: esmaece o trecho entre agora e a meia-noite (é o dia anterior no relógio de 24h)
  if (horaAgora < 23.9) {
    svg.appendChild(svgEl('path', { d: arco(128, horaAgora + 0.05, 24), fill: 'none', stroke: '#121414', 'stroke-width': 72, opacity: 0.62, 'pointer-events': 'none' }));
    // rótulo no meio do trecho de ontem, mas longe do texto do centro (fica acima ou abaixo dele)
    let hRot = horaAgora + (24 - horaAgora) / 2;
    for (let d = 0; d <= (24 - horaAgora) / 2; d += 0.1) {
      const h = [hRot - d, hRot + d].find(x => x > horaAgora + 0.5 && x < 23.5 && Math.abs(pt(86, x).y - cy) >= 55);
      if (h !== undefined) { hRot = h; break; }
    }
    const pl = pt(86, hRot);
    const t = svgEl('text', { x: pl.x.toFixed(2), y: pl.y.toFixed(2), 'text-anchor': 'middle', 'dominant-baseline': 'central', 'font-size': '9.5', 'font-weight': '700', fill: '#8a938e', 'letter-spacing': '.08em', 'pointer-events': 'none' });
    t.textContent = 'ONTEM';
    if (24 - horaAgora > 1.2) svg.appendChild(t);
    const m0 = pt(RODA_ANEIS[RODA_ANEIS.length - 1].raio - 10, 0), m1 = pt(R + 12, 0);
    svg.appendChild(svgEl('line', { x1: m0.x.toFixed(2), y1: m0.y.toFixed(2), x2: m1.x.toFixed(2), y2: m1.y.toFixed(2), stroke: '#8a938e', 'stroke-width': 1, 'stroke-dasharray': '2 3', 'pointer-events': 'none' }));
  }

  // ponteiro de agora
  const pa = pt(R, horaAgora), pi = pt(88, horaAgora);
  svg.appendChild(svgEl('line', { x1: pi.x.toFixed(2), y1: pi.y.toFixed(2), x2: pa.x.toFixed(2), y2: pa.y.toFixed(2), stroke: '#eef1ee', 'stroke-width': 1.5, 'stroke-dasharray': '3 4', 'pointer-events': 'none' }));
  svg.appendChild(svgEl('circle', { cx: pa.x.toFixed(2), cy: pa.y.toFixed(2), r: 7, fill: 'none', stroke: '#eef1ee', 'stroke-width': 1.5, class: 'roda-agora-pulso', 'pointer-events': 'none' }));
  svg.appendChild(svgEl('circle', { cx: pa.x.toFixed(2), cy: pa.y.toFixed(2), r: 5, fill: '#eef1ee', stroke: '#121414', 'stroke-width': 2, 'pointer-events': 'none' }));

  // centro: hora e resumo (toque limpa a seleção)
  const visiveis = itens.length;
  const ciclos = itens.filter(i => i.tipo === 'periodo' && i.auto).length;
  const centro = svgEl('g', { style: 'cursor:pointer;' });
  centro.appendChild(svgEl('circle', { cx, cy, r: 70, fill: 'transparent' }));
  const tHora = svgEl('text', { x: cx, y: cy - 16, 'text-anchor': 'middle', 'font-size': '30', 'font-weight': '800', fill: '#eef1ee', 'letter-spacing': '-.02em' });
  tHora.textContent = pad2(agora.getHours()) + ':' + pad2(agora.getMinutes());
  const tEv = svgEl('text', { x: cx, y: cy + 8, 'text-anchor': 'middle', 'font-size': '12', fill: '#8a938e' });
  tEv.textContent = visiveis + (visiveis === 1 ? ' evento' : ' eventos') + ' em 24h';
  centro.appendChild(tHora); centro.appendChild(tEv);
  if (ciclos > 0) {
    const tC = svgEl('text', { x: cx, y: cy + 26, 'text-anchor': 'middle', 'font-size': '12', 'font-weight': '700', fill: RODA_COR.auto });
    tC.textContent = ciclos + (ciclos === 1 ? ' ciclo automático' : ' ciclos automáticos');
    centro.appendChild(tC);
  }
  centro.addEventListener('click', () => selecionarRoda([], false));
  svg.appendChild(centro);

  // legenda dos anéis e da luz
  const leg = document.getElementById('rrAneis');
  if (leg) {
    const h = HORAS_LUZ_POR_MODO[modoAtual === 'floracao' ? 'floracao' : 'vegetativo'];
    leg.innerHTML = RODA_ANEIS.map(a => '<span><b style="background:' + a.cor + '"></b>' + escapeHtml(RELE_ROTULO[a.rele] || a.rele) + '</span>').join('') +
      '<span><b style="background:#33402d"></b>Luz ' + minParaHHMM(janela.inicioMin) + ' → ' + minParaHHMM(janela.fimMin) + ' · ' + h + '/' + (24 - h) + 'h</span>';
  }
}

function linhasDetalhe(it) {
  const L = [];
  if (it.tipo === 'periodo') {
    if (it.leituras) L.push([it.campo === 'vpd' ? 'Leituras' : it.campo === 'temp_interna' ? 'Temperatura' : 'Umidade', it.leituras]);
    const a = automacaoDoRele(it.anel.rele);
    if (it.auto && it.cfgClima) {
      const cf = it.cfgClima, centro = (cf.min + cf.max) / 2;
      // firmware 2.7.2: só o umidificador, desligando no mínimo; registros antigos (até 2.7.1) desligavam no meio da faixa
      // firmware 2.7.3: liga e desliga dentro da faixa (motivos ponto_*/teto/piso)
      const motivoIni = it.evIni ? (leituraDaAcao(it.evIni) || {}).motivo : '', motivoFim = it.evFim ? (leituraDaAcao(it.evFim) || {}).motivo : '';
      const novo = it.evFim || it.evIni ? ['ponto_ligar', 'ponto_desligar', 'teto', 'piso'].includes(motivoIni) || ['ponto_ligar', 'ponto_desligar', 'teto', 'piso'].includes(motivoFim) : true;
      const desligouNoMinimo = it.evFim ? motivoFim === 'vpd_min' : true;
      const pc = pontosClima(cf);
      L.push(['Regra', it.anel.rele === 'Aquecedor' ? 'liga abaixo de ' + brN(cf.min, 2) + ' · desliga em ' + brN(centro, 2)
        : novo ? 'liga em ' + brN(pc.liga, 2) + ' · desliga em ' + brN(pc.desliga, 2) + ' (sempre dentro da faixa)'
        : 'liga acima de ' + brN(cf.max, 2) + ' · desliga em ' + brN(desligouNoMinimo ? cf.min : centro, 2)]);
      L.push(['Fase do clima', faseClima(cf.fase).nome + ' · ' + brN(cf.min, 2) + '–' + brN(cf.max, 2) + ' kPa']);
      // motivo real de cada ação, com a leitura do sensor (firmware 2.6.1)
      const acao = (ev, rotulo) => {
        const lida = ev ? leituraDaAcao(ev) : null;
        if (!lida) return;
        const sensor = [lida.temp !== null ? brN(lida.temp, 1) + ' °C' : '', lida.umid !== null ? Math.round(lida.umid) + '%' : ''].filter(Boolean).join(' · ');
        L.push([rotulo, (MOTIVO_CLIMA[lida.motivo] ? MOTIVO_CLIMA[lida.motivo](cf) : lida.motivo) + (sensor ? ' · ' + sensor + ' no sensor' : '')]);
      };
      if (!it.cortado) acao(it.evIni, 'Ligou');
      if (it.evFim && it.evFim.origem === 'automacao') acao(it.evFim, 'Desligou');
    } else if (it.auto && a) {
      const f = faixaAutomacaoEm(a, it.inicioMs);
      L.push(['Regra', a.rotuloMin.toLowerCase() + ' ' + fmtAuto(a, f.min) + ' · ' + a.rotuloMax.toLowerCase() + ' ' + fmtAuto(a, f.max)]);
    } else {
      L.push(['Ligado por', origemCurta(it.evIni.origem)]);
    }
    if (it.evFim && it.evFim.origem !== it.evIni.origem) L.push(['Desligado por', origemCurta(it.evFim.origem)]);
    if (it.cortado) L.push(['Observação', 'já estava ligado antes das últimas 24h']);
  } else {
    const ev = it.eventos[0];
    if (ehEventoAutomacao(ev)) detalheAutomacao(ev).forEach(l => L.push(l));
    if (it.categoria !== 'automacoes') L.push(['Origem', origemCurta(ev.origem)]);
  }
  L.push(['Quando', quandoTexto(it.inicioMs)]);
  return L;
}

function renderizarDetalheRoda() {
  const el = document.getElementById('rrDetalhe');
  if (!el) return;
  const sel = rodaSelecao.map(id => rodaItens.find(i => i.id === id)).filter(Boolean);
  if (!sel.length) { el.hidden = true; el.innerHTML = ''; return; }
  el.hidden = false;
  const fechar = '<button type="button" class="rr-fechar" data-rr-fechar>fechar <svg data-lucide="x"></svg></button>';
  if (sel.length === 1) {
    const it = sel[0];
    const hora = it.tipo === 'periodo' ? hhmmDeMs(it.inicioMs) + ' – ' + (it.fimMs === null ? 'agora' : hhmmDeMs(it.fimMs)) : hhmmDeMs(it.inicioMs);
    const tag = it.tipo === 'periodo' ? (it.auto ? '<span class="rr-tag auto">Automático</span>' : '<span class="rr-tag">Manual</span>') : '';
    el.style.setProperty('--c', it.cor);
    el.innerHTML = '<div class="rr-detalhe-topo"><span class="rr-detalhe-hora">' + hora + '</span>' + fechar + '</div>' +
      '<h3>' + escapeHtml(it.titulo) + ' ' + tag + '</h3>' +
      '<div class="rr-linhas">' + linhasDetalhe(it).map(([k, v]) => '<span>' + escapeHtml(k) + '</span><div>' + escapeHtml(v) + '</div>').join('') + '</div>';
  } else {
    el.style.setProperty('--c', '#dfe4de');
    el.innerHTML = '<div class="rr-detalhe-topo"><span class="rr-detalhe-hora">' + sel.length + ' eventos próximos</span>' + fechar + '</div>' +
      '<div class="rr-mini">' + sel.sort((a, b) => a.inicioMs - b.inicioMs).map(it =>
        '<button type="button" data-rr-item="' + escapeHtml(it.id) + '"><i style="background:' + it.cor + '"></i><b>' + hhmmDeMs(it.inicioMs) + '</b>' + escapeHtml(it.titulo) + '</button>').join('') + '</div>';
  }
}

function htmlItemLista(it) {
  const sel = rodaSelecao.length === 1 && rodaSelecao[0] === it.id;
  const hora = it.tipo === 'periodo'
    ? hhmmDeMs(it.inicioMs) + '<small' + (it.fimMs === null ? ' class="vivo"' : '') + '>' + (it.fimMs === null ? 'agora' : hhmmDeMs(it.fimMs)) + '</small>'
    : hhmmDeMs(it.inicioMs);
  return '<button type="button" class="rr-ev' + (sel ? ' sel' : '') + '" data-rr-item="' + escapeHtml(it.id) + '" style="--c:' + it.cor + '">' +
    '<span class="rr-ev-icone" style="background:' + corSuave(it.cor) + ';color:' + it.cor + '"><svg data-lucide="' + it.icone + '"></svg></span>' +
    '<span class="rr-ev-texto"><strong>' + escapeHtml(it.titulo) + '</strong><span>' + (it.subHtml || escapeHtml(it.sub || '')) + '</span></span>' +
    '<span class="rr-ev-hora">' + hora + '</span></button>';
}

function renderizarListaRoda(itens) {
  const el = document.getElementById('rrLista');
  if (!el) return;
  const meiaNoite = inicioDoDia(Date.now());
  const ord = itens.slice().sort((a, b) => b.inicioMs - a.inicioMs);
  const hoje = ord.filter(i => i.inicioMs >= meiaNoite), ontem = ord.filter(i => i.inicioMs < meiaNoite);
  const mostrar = rodaListaExpandida ? hoje : hoje.slice(0, RODA_LIMITE_LISTA);
  let html = '<div class="rr-lista-cab"><h4>Hoje</h4><small>' + hoje.length + (hoje.length === 1 ? ' evento' : ' eventos') + '</small></div>';
  html += hoje.length ? '<div class="rr-lista">' + mostrar.map(htmlItemLista).join('') + '</div>' : '<div class="rr-vazio">Nenhum evento hoje' + (focoRoda || Object.values(filtrosRoda).includes(false) ? ' no filtro atual' : '') + '.</div>';
  if (hoje.length > RODA_LIMITE_LISTA) {
    html += '<button type="button" class="rr-mais" data-rr-mais><svg data-lucide="' + (rodaListaExpandida ? 'chevron-up' : 'chevron-down') + '"></svg>' +
      (rodaListaExpandida ? 'Mostrar menos' : 'Ver mais ' + (hoje.length - RODA_LIMITE_LISTA) + ' de hoje') + '</button>';
  }
  if (ontem.length) {
    const desde = hhmmDeMs(Date.now());
    html += '<button type="button" class="rr-ontem" data-rr-ontem aria-expanded="' + rodaOntemAberto + '"><span>Ontem · ' + ontem.length + (ontem.length === 1 ? ' evento' : ' eventos') + ' (a partir de ' + desde + ')</span><svg data-lucide="' + (rodaOntemAberto ? 'chevron-down' : 'chevron-right') + '"></svg></button>';
    if (rodaOntemAberto) html += '<div class="rr-lista rr-lista-ontem">' + ontem.map(htmlItemLista).join('') + '</div>';
  }
  el.innerHTML = html;
}

function renderizarFiltrosRoda() {
  document.querySelectorAll('#rrFiltros [data-rr-filtro]').forEach(btn => {
    const on = filtrosRoda[btn.dataset.rrFiltro];
    btn.classList.toggle('off', !on);
    btn.setAttribute('aria-pressed', String(on));
  });
}

function renderizarRoda() {
  if (!document.getElementById('rodaEventos')) return;
  rodaItens = construirItensRoda();
  rodaSelecao = rodaSelecao.filter(id => rodaItens.some(i => i.id === id));
  const visiveis = rodaItens.filter(itemVisivel);
  renderizarResumoRoda();
  renderizarFiltrosRoda();
  desenharRoda(visiveis, ultimoModoAtual);
  renderizarDetalheRoda();
  renderizarListaRoda(visiveis);
  if (window.lucide) lucide.createIcons();
}

function selecionarRoda(ids, rolarParaDetalhe) {
  const mesmo = ids.length && ids.length === rodaSelecao.length && ids.every(id => rodaSelecao.includes(id));
  rodaSelecao = mesmo ? [] : ids;
  renderizarRoda();
  if (rolarParaDetalhe && rodaSelecao.length) {
    const el = document.getElementById('rrDetalhe');
    if (el) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }
}
// compatibilidade com chamadas antigas
function fecharDetalheEvento() { selecionarRoda([], false); }

document.addEventListener('click', ev => {
  const chip = ev.target.closest('#rrResumo [data-foco]');
  if (chip) { focoRoda = focoRoda === chip.dataset.foco ? null : chip.dataset.foco; rodaSelecao = []; rodaListaExpandida = false; renderizarRoda(); return; }
  const filtro = ev.target.closest('#rrFiltros [data-rr-filtro]');
  if (filtro) { const k = filtro.dataset.rrFiltro; filtrosRoda[k] = !filtrosRoda[k]; rodaSelecao = []; renderizarRoda(); return; }
  if (ev.target.closest('[data-rr-fechar]')) { selecionarRoda([], false); return; }
  if (ev.target.closest('[data-rr-mais]')) { rodaListaExpandida = !rodaListaExpandida; renderizarRoda(); return; }
  if (ev.target.closest('[data-rr-ontem]')) { rodaOntemAberto = !rodaOntemAberto; renderizarRoda(); return; }
  const item = ev.target.closest('[data-rr-item]');
  if (item) { const naLista = !!item.closest('#rrLista'); selecionarRoda([item.dataset.rrItem], naLista); }
});

async function carregarRodaEventos() {
  const d = await buscarJson('comandos.php?limite=500');
  const todos = d.dados || [];
  // Completa com o último registro de cada relé e da porta que ficou fora da janela de linhas
  // (o ventilador, por exemplo, muda pouco e sairia da lista, aparecendo como "sem registro").
  relesEstado = d.reles || {};
  Object.keys(relesEstado).forEach(alvo => {
    if (!todos.some(c => c.tipo === 'rele' && c.alvo === alvo)) todos.push(relesEstado[alvo]);
  });
  if (d.ultimo_acesso && !todos.some(c => c.tipo === 'acesso')) todos.push(d.ultimo_acesso);
  const modoAtual = d.modo_atual === 'floracao' ? 'floracao' : 'vegetativo';
  automacoesEsp = d.automacoes || {};
  climaEsp = d.clima || null;
  resolverPendenciaClima();

  modoAtualParaLuz = modoAtual;
  ultimoTodosComandos = todos;

  const agora = Date.now();
  const eventosHoje = todos.filter((c, i) => {
    // Ao ligar, o ESP32 reconfirma o estado de cada relé; se nada mudou, não é um evento.
    if (c.tipo === 'rele' && c.origem === 'boot') {
      const anterior = todos.slice(i + 1).find(x => x.tipo === 'rele' && x.alvo === c.alvo);
      if (anterior && anterior.valor === c.valor) return false;
      // registro anterior fora da janela: o "desde" do servidor diz se o estado já vinha de antes
      const r = relesEstado[c.alvo];
      const desde = r && r.desde ? new Date(String(r.desde).replace(' ', 'T')).getTime() : NaN;
      if (!anterior && r && r.valor === c.valor && desde < msEvento(c)) return false;
    }
    const ehAutomacao = (c.tipo === 'automacao' || c.tipo === 'clima_vpd') && c.origem !== 'boot'; // o boot só reconfirma a configuração
    if (!ehAutomacao && c.tipo !== 'acesso' && c.tipo !== 'alerta' && c.tipo !== 'fase' && c.tipo !== 'notificacao' && !(c.tipo === 'rele' && RELES_NA_RODA.includes(c.alvo))) return false;
    const ts = timestampEvento(c);
    if (!ts) return false;
    const t = new Date(String(ts).replace(' ', 'T')).getTime();
    return !isNaN(t) && (agora - t) <= 24 * 60 * 60 * 1000;
  });

  ultimoEventosHoje = eventosHoje;
  ultimoModoAtual = modoAtual;
  renderizarRoda();
  atualizarBotoesComando(todos, modoAtual);
  atualizarCardLuz();
  if (abaAtual === 'fotoperiodo') {
    // preserva rascunho em edição
    if (rascunhoTimer.vegetativo === null && rascunhoTimer.floracao === null) renderizarFotoperiodo();
    else atualizarStatusFotoperiodo();
  }
}

/* ==================== Comandos (botões) ==================== */
const RELES_CONTROLAVEIS = ['Irrigacao', 'Ventilador', 'Umidificador', 'Entrada de Ar', 'Aquecedor', 'Saida de Ar'];
const RELE_ICONE = {
  'Umidificador': 'droplets',
  'Ventilador': 'fan',
  'Irrigacao': 'shower-head',
  'Entrada de Ar': 'wind',
  'Aquecedor': 'heater',
  'Saida de Ar': 'arrow-up-from-line',
};

function estadoAtualRele(todosComandos, nomeRele) {
  const ultimo = todosComandos.find(c => c.tipo === 'rele' && c.alvo === nomeRele);
  return ultimo ? ultimo.valor : null;
}

function estadoAtualLuz(todosComandos) {
  // Origem 'automatico_luz'/'retomou_automatico' = automático; demais = manual.
  const ultimo = todosComandos.find(c => c.tipo === 'rele' && c.alvo === 'Luz');
  if (!ultimo) return 'auto';
  const emAutomatico = ultimo.origem === 'retomou_automatico' || ultimo.origem === 'automatico_luz';
  if (emAutomatico) return 'auto';
  return ultimo.valor === 'ligado' ? 'ligada' : 'desligada';
}

function atualizarPill(id, texto, variante) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = texto;
  el.className = 'pill' + (variante ? ' ' + variante : ' pill-neutro');
}

// Pendências dos cards de controle (luz/acesso) enquanto o ESP32 não confirma.
let luzPendente = null;      // { valor: 'auto'|'ligada'|'desligada', ate }
let acessoConfirmando = false;
let acessoConfirmTimeout = null;
let acessoEnviando = false;
let ultimoModoCmd = 'vegetativo';

function duracaoDesde(registro) {
  if (!registro) return '';
  const desde = registro.tipo === 'rele' ? inicioEstadoRele(registro.alvo, registro) : new Date(String(timestampEvento(registro)).replace(' ', 'T')).getTime();
  if (isNaN(desde)) return '';
  const dur = formatarDuracao(Date.now() - desde);
  return dur === 'agora mesmo' ? dur : 'há ' + dur;
}

function atualizarBotoesComando(todosComandos, modoAtual) {
  ultimoModoCmd = modoAtual === 'floracao' ? 'floracao' : 'vegetativo';
  const luzEstado = estadoAtualLuz(todosComandos);
  ultimoLuzEstado = luzEstado;
  if (luzPendente && (luzPendente.valor === luzEstado || Date.now() > luzPendente.ate)) luzPendente = null;
  renderizarControles(todosComandos);
}

function renderizarControles(todosComandos) {
  const container = document.getElementById('cmdControles');
  if (!container) return;
  const cards = [];

  // --- Modo de cultivo
  const modo = ultimoModoCmd;
  const jm = janelaDoModo(modo);
  const ultimoModo = todosComandos.find(c => c.tipo === 'modo_cultivo');
  cards.push(
    '<div class="rele-card fase largo">' +
      '<div class="rele-card-topo">' +
        '<div class="rele-icone"><svg data-lucide="' + (modo === 'floracao' ? 'flower-2' : 'sprout') + '"></svg></div>' +
        '<span class="rele-num">Fase</span>' +
      '</div>' +
      '<div class="rele-nome">Modo de cultivo</div>' +
      '<div class="rele-rodape">' +
        '<div class="rele-estado"><strong>' + (modo === 'floracao' ? 'Floração' : 'Vegetação') + '</strong>' +
          HORAS_LUZ_POR_MODO[modo] + 'h luz · ' + minParaHHMM(jm.inicioMin) + '–' + minParaHHMM(jm.fimMin) + '</div>' +
        '<a class="rele-acao verde" href="#fotoperiodo" data-ir-aba="fotoperiodo" aria-label="Trocar de fase no Fotoperíodo" title="Trocar de fase no Fotoperíodo"><svg data-lucide="arrow-right"></svg></a>' +
      '</div>' +
    '</div>'
  );

  cards.push(htmlCardClima(todosComandos));

  container.innerHTML = cards.join('');
  renderizarBotoesReles(todosComandos);
}

/* ==================== Clima por VPD (aquecedor + umidificador) ==================== */
// A lógica roda no ESP32 (atualizarClima()); o painel escolhe a fase, a faixa e as travas.
// Comando: tipo "clima_vpd", alvo "clima", valor "ativa;fase;min;max;travaTemp;travaUmid;minLigado;minDesligado"
// (fase 0 muda, 1 vegetação, 2 floração; tempos mínimos em minutos). O ESP32 controla pela média de 1 min.
// A faixa de cada fase fica nas configurações (vpd_muda_*, vpd_vegetativo_*, vpd_floracao_*), as mesmas dos alertas de VPD.
const FASES_CLIMA = [
  { chave: 'muda', indice: 0, nome: 'Muda', icone: 'sprout', padrao: [0.4, 0.8] },
  { chave: 'vegetativo', indice: 1, nome: 'Vegetação', icone: 'leaf', padrao: [0.8, 1.2] },
  { chave: 'floracao', indice: 2, nome: 'Floração', icone: 'flower-2', padrao: [1.2, 1.6] },
];
const CLIMA_TRAVAS_PADRAO = { temp: 28, umid: 60, lig: 3, des: 3 };
const CLIMA_ESCALA = [0.2, 2.0];
let climaEsp = null;            // { valor, origem, quando } confirmado pelo ESP32
let climaPendente = null;       // estado enviado e ainda não confirmado (+ ate)
let climaRascunho = null;       // { fase, min, max, temp, umid, lig, des } em edição nos ajustes
let climaAjustesAbertos = false;

function brN(n, casas) { return Number(n).toFixed(casas).replace('.', ','); }
// faixa de VPD: 1 casa quando o valor é redondo (1,2), 2 quando não é (1,55)
function brFx(n) { return Math.abs(n * 10 - Math.round(n * 10)) < 1e-6 ? brN(n, 1) : brN(n, 2); }
function faseClima(indiceOuChave) { return FASES_CLIMA.find(f => f.indice === indiceOuChave || f.chave === indiceOuChave) || FASES_CLIMA[2]; }
function faixaDaFase(fase) {
  const f = faseClima(fase);
  const mn = Number(CONFIG['vpd_' + f.chave + '_min']), mx = Number(CONFIG['vpd_' + f.chave + '_max']);
  return (isFinite(mn) && isFinite(mx) && mx > mn) ? { min: mn, max: mx } : { min: f.padrao[0], max: f.padrao[1] };
}
function travasConfig() {
  const t = Number(CONFIG.clima_trava_temp), u = Number(CONFIG.clima_trava_umid);
  const l = Number(CONFIG.clima_min_ligado), d = Number(CONFIG.clima_min_desligado);
  return { temp: isFinite(t) && t > 0 ? t : CLIMA_TRAVAS_PADRAO.temp, umid: isFinite(u) && u > 0 ? u : CLIMA_TRAVAS_PADRAO.umid,
    lig: l >= 1 && l <= 15 ? l : CLIMA_TRAVAS_PADRAO.lig, des: d >= 1 && d <= 15 ? d : CLIMA_TRAVAS_PADRAO.des };
}
// Aceita o formato do firmware 2.6.0 (6 campos, tempos de 3 min) e o do 2.6.1 (8 campos).
function lerValorClima(valor) {
  const p = String(valor || '').split(';');
  if (p.length !== 6 && p.length !== 8) return null;
  const e = { ativa: p[0] === '1', fase: Number(p[1]), min: Number(p[2]), max: Number(p[3]), travaTemp: Number(p[4]), travaUmid: Number(p[5]),
    minLigado: p.length === 8 ? Number(p[6]) : 3, minDesligado: p.length === 8 ? Number(p[7]) : 3 };
  return [e.fase, e.min, e.max, e.travaTemp, e.travaUmid, e.minLigado, e.minDesligado].every(isFinite) ? e : null;
}
function valorClima(e) {
  return (e.ativa ? '1' : '0') + ';' + e.fase + ';' + Number(e.min).toFixed(2) + ';' + Number(e.max).toFixed(2) + ';' + Number(e.travaTemp).toFixed(1) + ';' +
    Math.round(e.travaUmid) + ';' + Math.round(e.minLigado || 3) + ';' + Math.round(e.minDesligado || 3);
}
function estadoClima() {
  if (climaPendente) return Object.assign({}, climaPendente, { pendente: true, registro: true });
  const lido = climaEsp ? lerValorClima(climaEsp.valor) : null;
  if (lido) return Object.assign(lido, { pendente: false, registro: true, origem: climaEsp.origem });
  const fase = ultimoModoCmd === 'vegetativo' ? 1 : 2, f = faixaDaFase(fase), t = travasConfig();
  return { ativa: false, fase, min: f.min, max: f.max, travaTemp: t.temp, travaUmid: t.umid, minLigado: t.lig, minDesligado: t.des, pendente: false, registro: false };
}
// Só o umidificador participa do clima por VPD (firmware 2.7.2); o aquecedor é um relé comum.
function climaAtivoNoRele(nomeRele) { return nomeRele === 'Umidificador' && estadoClima().ativa; }
function resolverPendenciaClima() {
  if (!climaPendente) return;
  if ((climaEsp && climaEsp.valor === valorClima(climaPendente)) || Date.now() > climaPendente.ate) climaPendente = null;
}
// Configuração do clima em vigor num instante (último registro até ali).
function climaEm(ms) {
  const reg = ultimoTodosComandos.find(c => c.tipo === 'clima_vpd' && msEvento(c) <= ms);
  return reg ? lerValorClima(reg.valor) : null;
}
function vpdDeLeitura(l) {
  if (!l) return null;
  if (l.vpd !== null && l.vpd !== undefined && l.vpd !== '') return Number(l.vpd);
  const t = Number(l.temp_interna), u = Number(l.umid_interna);
  return (l.temp_interna === null || l.umid_interna === null || isNaN(t) || isNaN(u)) ? null : calcularVPD(t, u);
}
function vpdEm(ms) {
  let melhor = null, dist = Infinity;
  leituras24h.forEach(l => {
    const t = new Date(String(l.criado_em || l.dispositivo_timestamp || '').replace(' ', 'T')).getTime();
    const v = vpdDeLeitura(l);
    if (isNaN(t) || v === null) return;
    const d = Math.abs(t - ms);
    if (d < dist) { dist = d; melhor = v; }
  });
  return dist <= 5 * 60 * 1000 ? melhor : null;
}
function fmtVpd(v) { return v === null || v === undefined || isNaN(v) ? '—' : brN(v, 2) + ' kPa'; }

function htmlPassoClima(campo, rotulo, valor, podeMenos, podeMais, mudou) {
  return '<div class="auto-passo' + (mudou ? ' alterado' : '') + '"><span class="auto-rotulo">' + rotulo + '</span><div class="auto-ctrl">' +
    '<button type="button" data-clima-passo="' + campo + '" data-delta="-1" aria-label="Diminuir ' + rotulo.toLowerCase() + '"' + (podeMenos ? '' : ' disabled') + '><svg data-lucide="minus"></svg></button>' +
    '<strong>' + valor + '</strong>' +
    '<button type="button" data-clima-passo="' + campo + '" data-delta="1" aria-label="Aumentar ' + rotulo.toLowerCase() + '"' + (podeMais ? '' : ' disabled') + '><svg data-lucide="plus"></svg></button></div></div>';
}

function htmlCardClima(todosComandos) {
  const est = estadoClima();
  const fase = faseClima(est.fase);
  const leitura = ultimaLeitura;
  const vpd = vpdDeLeitura(leitura);
  const temp = leitura && leitura.temp_interna !== null ? Number(leitura.temp_interna) : null;
  const umid = leitura && leitura.umid_interna !== null ? Number(leitura.umid_interna) : null;
  const pc = pontosClima(est);
  const pos = v => ((Math.min(Math.max(v, CLIMA_ESCALA[0]), CLIMA_ESCALA[1]) - CLIMA_ESCALA[0]) / (CLIMA_ESCALA[1] - CLIMA_ESCALA[0]) * 100).toFixed(1);

  // fases
  const fases = FASES_CLIMA.map(f => {
    const fx = faixaDaFase(f.indice);
    return '<button type="button" class="vpd-fase' + (f.indice === est.fase ? ' ativa' : '') + '" data-clima-fase="' + f.indice + '" aria-pressed="' + (f.indice === est.fase) + '"' + (est.pendente ? ' disabled' : '') + '>' +
      '<svg data-lucide="' + f.icone + '"></svg><strong>' + f.nome + '</strong><small>' + brFx(fx.min) + '–' + brFx(fx.max) + ' kPa</small></button>';
  }).join('');

  // medidor
  let selo = '<span class="vpd-estado">sem leitura</span>', classeAgora = '';
  if (vpd !== null) {
    if (vpd < est.min) { selo = '<span class="vpd-estado baixo">abaixo da faixa · ar úmido</span>'; classeAgora = ' baixo'; }
    else if (vpd > est.max) { selo = '<span class="vpd-estado alto">acima da faixa · ar seco</span>'; classeAgora = ' alto'; }
    else { selo = '<span class="vpd-estado ok">dentro da faixa</span>'; classeAgora = ' ok'; }
  }
  const escala = [0.4, 0.8, 1.2, 1.6, 2.0].map(v => '<span style="left:' + pos(v) + '%">' + brN(v, 1) + '</span>').join('');
  const medidor = '<div class="vpd-medidor"><div class="vpd-medidor-topo"><div><span>VPD agora</span><br><strong>' + (vpd === null ? '—' : brN(vpd, 2)) + '<small>kPa</small></strong></div>' + selo + '</div>' +
    '<div class="vpd-barra"><div class="vpd-alvo" style="left:' + pos(est.min) + '%;width:' + (pos(est.max) - pos(est.min)).toFixed(1) + '%"></div>' +
    '<div class="vpd-ponto" style="left:' + pos(pc.desliga) + '%" title="desliga em ' + brN(pc.desliga, 2) + '"></div><div class="vpd-ponto" style="left:' + pos(pc.liga) + '%" title="liga em ' + brN(pc.liga, 2) + '"></div>' +
    (vpd === null ? '' : '<div class="vpd-agora' + classeAgora + '" style="left:' + pos(vpd) + '%"></div>') + '</div>' +
    '<div class="vpd-escala">' + escala + '</div><div class="vpd-lados"><span>← úmido (VPD baixo)</span><span>seco (VPD alto) →</span></div>' +
    '<div class="vpd-leituras"><span>Temperatura <b>' + (temp === null ? '—' : brN(temp, 1) + ' °C') + '</b></span><span>Umidade <b>' + (umid === null ? '—' : Math.round(umid) + '%') + '</b></span>' +
    '<span>Alvo <b>' + brN(est.min, 2) + '–' + brN(est.max, 2) + '</b></span></div></div>';

  // equipamentos
  const linhaEquip = (nome, icone, cor, sobe) => {
    const reg = ultimoRegistroRele(todosComandos, nome);
    const ligado = !!reg && reg.valor === 'ligado';
    const ha = reg ? formatarDuracao(Date.now() - inicioEstadoRele(nome, reg)) : '';
    let texto;
    if (!est.ativa) texto = 'Desce o VPD · clima desativado';
    else if (umid !== null && umid >= est.travaUmid) texto = 'Trava: ' + Math.round(umid) + '% (máx. ' + Math.round(est.travaUmid) + '%)';
    else if (ligado) {
      const faltam = reg ? Math.ceil(est.minLigado - (Date.now() - inicioEstadoRele(nome, reg)) / 60000) : 0;
      texto = 'Umidificando · ligado ' + (ha === 'agora mesmo' ? 'agora' : 'há ' + ha) +
        (faltam > 0 ? ' (mínimo ' + est.minLigado + ' min)' : '') + ', desliga quando o VPD chegar em ' + brN(pc.desliga, 2);
    }
    else texto = 'Desligado até o VPD chegar em ' + brN(pc.liga, 2) + (ha && ha !== 'agora mesmo' ? ' · há ' + ha : '');
    return '<div class="vpd-eq' + (ligado ? ' on' : '') + '"><div class="vpd-eq-icone" style="background:' + corSuave(cor) + ';color:' + cor + '"><svg data-lucide="' + icone + '"></svg></div>' +
      '<div class="vpd-eq-texto"><strong>' + nome + '<small>Relé ' + RELE_NUMERO[nome] + '</small></strong><span>' + escapeHtml(texto) + '</span></div>' +
      '<span class="vpd-pill' + (ligado ? ' on' : '') + '">' + (ligado ? 'Ligado' : 'Desligado') + '</span></div>';
  };
  const equip = '<div class="vpd-equip">' + linhaEquip('Umidificador', 'droplets', '#6fb3e0', false) + '</div>';
  const regra = '<div class="vpd-regra">O VPD fica sempre dentro da faixa <b>' + brN(est.min, 2) + '–' + brN(est.max, 2) + '</b>: o umidificador liga em <b>' + brN(pc.liga, 2) + '</b>, antes do máximo, ' +
    'e desliga em <b>' + brN(pc.desliga, 2) + '</b>, antes do mínimo. Se chegar perto de uma borda (<b>' + brN(pc.teto, 2) + '</b> ou <b>' + brN(pc.piso, 2) + '</b>), age na hora, sem esperar o tempo mínimo. Trava: umidificador até <b>' + Math.round(est.travaUmid) + '%</b>. ' +
    'Fica no mínimo <b>' + est.minLigado + ' min</b> ligado e <b>' + est.minDesligado + ' min</b> desligado; o controle usa a média de 1 min, então picos de névoa no sensor não contam. ' +
    'O aquecedor não participa.</div>';

  // ajustes (faixa da fase e travas)
  let ajustes;
  if (climaAjustesAbertos) {
    const fx = faixaDaFase(est.fase), tv = travasConfig();
    const r = climaRascunho && climaRascunho.fase === est.fase ? climaRascunho : { fase: est.fase, min: fx.min, max: fx.max, temp: tv.temp, umid: tv.umid, lig: tv.lig, des: tv.des };
    const alterado = r.min !== fx.min || r.max !== fx.max || r.temp !== tv.temp || r.umid !== tv.umid || r.lig !== tv.lig || r.des !== tv.des;
    ajustes = '<button type="button" class="vpd-ajustes-btn" data-clima-ajustes aria-expanded="true"><span><svg data-lucide="sliders-horizontal"></svg>Faixa e travas de segurança</span><svg data-lucide="chevron-up"></svg></button>' +
      '<div class="vpd-ajustes"><h5>Faixa da ' + (fase.indice === 0 ? 'muda' : fase.indice === 1 ? 'vegetação' : 'floração') + '</h5>' +
      '<div class="auto-faixa">' +
        htmlPassoClima('min', 'Mínimo', brN(r.min, 2) + ' kPa', r.min - 0.05 >= 0.2 - 1e-9, r.min + 0.05 <= r.max - 0.1 + 1e-9, r.min !== fx.min) +
        htmlPassoClima('max', 'Máximo', brN(r.max, 2) + ' kPa', r.max - 0.05 >= r.min + 0.1 - 1e-9, r.max + 0.05 <= 2.5 + 1e-9, r.max !== fx.max) +
      '</div><p>Cada fase guarda a própria faixa (a mesma usada nos alertas de VPD).</p>' +
      '<h5>Trava de segurança e tempo mínimo</h5><div class="auto-faixa vpd-trava">' +
        htmlPassoClima('umid', 'Umidificador até', Math.round(r.umid) + '%', r.umid - 1 >= 30, r.umid + 1 <= 95, r.umid !== tv.umid) +
      '</div><p>O umidificador nunca passa dessa umidade, mesmo com o VPD perto do máximo.</p>' +
      '<div class="auto-faixa">' +
        htmlPassoClima('lig', 'Ligado no mínimo', r.lig + ' min', r.lig - 1 >= 1, r.lig + 1 <= 15, r.lig !== tv.lig) +
        htmlPassoClima('des', 'Desligado no mínimo', r.des + ' min', r.des - 1 >= 1, r.des + 1 <= 15, r.des !== tv.des) +
      '</div><p>Evita o liga/desliga curto. A trava, a falta de sensor e o VPD perto das bordas da faixa agem na hora.</p>' +
      '<button type="button" class="vpd-restaurar" data-clima-restaurar><svg data-lucide="rotate-ccw"></svg>Restaurar padrão da ' + fase.nome.toLowerCase() + '</button></div>' +
      (alterado ? '<div class="cmd-confirmacao"><span>Ajuste não salvo</span><button type="button" class="cmd-confirmar-nao" data-clima-cancelar>Cancelar</button><button type="button" class="cmd-confirmar-sim" data-clima-salvar>Salvar</button></div>' : '');
  } else {
    ajustes = '<button type="button" class="vpd-ajustes-btn" data-clima-ajustes aria-expanded="false"><span><svg data-lucide="sliders-horizontal"></svg>Faixa e travas de segurança</span>' +
      '<span><small>até ' + Math.round(est.travaUmid) + '% · mín. ' + est.minLigado + '/' + est.minDesligado + ' min</small><svg data-lucide="chevron-down"></svg></span></button>';
  }

  // rodapé
  let titulo, detalhe;
  if (est.pendente) { titulo = est.ativa ? 'Aplicando…' : 'Desativando…'; detalhe = 'aguardando o ESP32'; }
  else if (est.ativa) { titulo = 'Ativa'; detalhe = 'alvo da ' + fase.nome.toLowerCase() + ' · ' + brFx(est.min) + '–' + brFx(est.max) + ' kPa'; }
  else if (est.registro && String(est.origem || '').indexOf('pausa') === 0) { titulo = 'Pausada'; detalhe = 'comando manual no umidificador'; }
  else { titulo = 'Desativada'; detalhe = est.registro ? 'toque para ativar' : 'sem registro do ESP32'; }

  return '<div class="rele-card largo auto-card vpd-card' + (est.ativa ? ' liberado' : '') + (est.pendente ? ' pendente' : '') + '" id="cardVpd">' +
    '<div class="rele-card-topo"><div class="rele-icone vpd-icone"><svg data-lucide="gauge"></svg></div><span class="rele-num">Relé 3</span></div>' +
    '<div class="rele-nome">Clima por VPD</div>' +
    '<div class="vpd-desc">Escolha a fase e o ESP32 controla o umidificador: liga antes do VPD chegar no máximo e desliga antes do mínimo, para ele ficar sempre dentro da faixa.</div>' +
    '<div class="vpd-fases">' + fases + '</div>' + medidor + equip + regra + ajustes +
    '<div class="rele-rodape"><div class="rele-estado"><strong>' + titulo + '</strong>' + escapeHtml(detalhe) + '</div>' +
    '<button type="button" class="switch" role="switch" aria-checked="' + est.ativa + '" aria-label="Clima por VPD" data-clima-switch' + (est.pendente ? ' disabled' : '') + '></button></div></div>';
}

async function enviarClima(novo) {
  climaPendente = Object.assign({}, novo, { ate: Date.now() + 90 * 1000 });
  renderizarControles(ultimoTodosComandos);
  const ok = await enviarComando('clima_vpd', 'clima', valorClima(novo), null);
  if (!ok) { climaPendente = null; renderizarControles(ultimoTodosComandos); return false; }
  [6000, 15000, 30000].forEach(ms => setTimeout(() => { carregarRodaEventos().catch(() => {}); }, ms));
  return true;
}

async function salvarAjustesClima() {
  const est = estadoClima();
  const r = climaRascunho;
  if (!r) return;
  const f = faseClima(r.fase);
  const config = {};
  config['vpd_' + f.chave + '_min'] = r.min.toFixed(2);
  config['vpd_' + f.chave + '_max'] = r.max.toFixed(2);
  config.clima_trava_temp = r.temp.toFixed(1);
  config.clima_trava_umid = String(Math.round(r.umid));
  config.clima_min_ligado = String(r.lig);
  config.clima_min_desligado = String(r.des);
  try {
    const resp = await fetch('configuracoes.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ config }) });
    const d = await resp.json();
    if (!d.ok) { mostrarToast(d.detalhe || ('erro: ' + (d.erro || 'desconhecido')), 'erro'); return; }
    CONFIG = normalizarConfig(Object.assign({}, CONFIG, d.config));
  } catch (e) { mostrarToast('falha de rede: ' + e.message, 'erro'); return; }
  climaRascunho = null;
  // A fase em uso recebe a faixa nova; as outras só guardam o valor para quando forem escolhidas.
  if (r.fase === est.fase) enviarClima({ ativa: est.ativa, fase: est.fase, min: r.min, max: r.max, travaTemp: r.temp, travaUmid: r.umid, minLigado: r.lig, minDesligado: r.des });
  else renderizarControles(ultimoTodosComandos);
}

document.addEventListener('click', ev => {
  const bFase = ev.target.closest('[data-clima-fase]');
  if (bFase && !bFase.disabled) {
    const est = estadoClima(), fase = Number(bFase.dataset.climaFase);
    if (fase === est.fase) return;
    const fx = faixaDaFase(fase);
    climaRascunho = null;
    enviarClima({ ativa: est.ativa, fase, min: fx.min, max: fx.max, travaTemp: est.travaTemp, travaUmid: est.travaUmid, minLigado: est.minLigado, minDesligado: est.minDesligado });
    return;
  }
  const sw = ev.target.closest('.switch[data-clima-switch]');
  if (sw && !sw.disabled) {
    const est = estadoClima(), fx = faixaDaFase(est.fase), tv = travasConfig();
    // ao ativar, usa a faixa e as travas salvas no painel
    enviarClima({ ativa: !est.ativa, fase: est.fase, min: est.ativa ? est.min : fx.min, max: est.ativa ? est.max : fx.max, travaTemp: est.ativa ? est.travaTemp : tv.temp,
      travaUmid: est.ativa ? est.travaUmid : tv.umid, minLigado: est.ativa ? est.minLigado : tv.lig, minDesligado: est.ativa ? est.minDesligado : tv.des });
    return;
  }
  if (ev.target.closest('[data-clima-ajustes]')) { climaAjustesAbertos = !climaAjustesAbertos; if (!climaAjustesAbertos) climaRascunho = null; renderizarControles(ultimoTodosComandos); return; }
  const bPasso = ev.target.closest('[data-clima-passo]');
  if (bPasso && !bPasso.disabled) {
    const est = estadoClima(), fx = faixaDaFase(est.fase), tv = travasConfig();
    const r = climaRascunho && climaRascunho.fase === est.fase ? climaRascunho : { fase: est.fase, min: fx.min, max: fx.max, temp: tv.temp, umid: tv.umid, lig: tv.lig, des: tv.des };
    const passo = { min: 0.05, max: 0.05, temp: 0.5, umid: 1, lig: 1, des: 1 }[bPasso.dataset.climaPasso];
    const campo = bPasso.dataset.climaPasso;
    r[campo] = Math.round((r[campo] + Number(bPasso.dataset.delta) * passo) * 100) / 100;
    climaRascunho = r;
    renderizarControles(ultimoTodosComandos);
    return;
  }
  if (ev.target.closest('[data-clima-cancelar]')) { climaRascunho = null; renderizarControles(ultimoTodosComandos); return; }
  if (ev.target.closest('[data-clima-restaurar]')) {
    const est = estadoClima(), f = faseClima(est.fase);
    climaRascunho = { fase: est.fase, min: f.padrao[0], max: f.padrao[1], temp: CLIMA_TRAVAS_PADRAO.temp, umid: CLIMA_TRAVAS_PADRAO.umid, lig: CLIMA_TRAVAS_PADRAO.lig, des: CLIMA_TRAVAS_PADRAO.des };
    renderizarControles(ultimoTodosComandos);
    return;
  }
  if (ev.target.closest('[data-clima-salvar]')) { salvarAjustesClima(); }
});

/* ---------- Clima por VPD no relógio de eventos ---------- */
function textoClima(c, relacionado) {
  const v = lerValorClima(c.valor);
  if (!v) return { titulo: 'Clima por VPD', sub: origemCurta(c.origem) };
  const f = faseClima(v.fase);
  const faixa = brN(v.min, 2) + '–' + brN(v.max, 2) + ' kPa';
  const anterior = ultimoTodosComandos.find(x => x.tipo === 'clima_vpd' && msEvento(x) < msEvento(c));
  const ant = anterior ? lerValorClima(anterior.valor) : null;
  if (String(c.origem || '').indexOf('pausa') === 0) {
    const acao = relacionado ? (RELE_ROTULO[relacionado.alvo] || relacionado.alvo).toLowerCase() + (relacionado.valor === 'ligado' ? ' ligado' : ' desligado') + ' manualmente' : 'comando manual';
    return { titulo: 'Clima por VPD pausado', sub: origemCurta(c.origem) + ' · ' + acao };
  }
  if (!v.ativa && (!ant || ant.ativa)) return { titulo: 'Clima por VPD desativado', sub: origemCurta(c.origem) };
  if (ant && ant.ativa === v.ativa) {
    if (ant.fase !== v.fase) return { titulo: 'Fase do clima: ' + f.nome, sub: origemCurta(c.origem) + ' · ' + faixa };
    if (ant.min !== v.min || ant.max !== v.max) return { titulo: 'Faixa da ' + f.nome.toLowerCase() + ' alterada', sub: origemCurta(c.origem) + ' · ' + faixa };
    if (ant.travaTemp !== v.travaTemp || ant.travaUmid !== v.travaUmid) return { titulo: 'Travas do clima alteradas', sub: origemCurta(c.origem) + ' · até ' + brN(v.travaTemp, 1) + ' °C e ' + Math.round(v.travaUmid) + '%' };
    if (ant.minLigado !== v.minLigado || ant.minDesligado !== v.minDesligado) return { titulo: 'Tempo mínimo do clima alterado', sub: origemCurta(c.origem) + ' · ' + v.minLigado + ' min ligado · ' + v.minDesligado + ' min desligado' };
    if (!v.ativa) return { titulo: 'Clima por VPD ajustado (desativado)', sub: origemCurta(c.origem) + ' · ' + f.nome + ' ' + faixa };
    return { titulo: 'Clima por VPD reconfirmado', sub: origemCurta(c.origem) + ' · ' + f.nome + ' ' + faixa };
  }
  return { titulo: 'Clima por VPD ' + (ant ? 'reativado' : 'ativado'), sub: origemCurta(c.origem) + ' · ' + f.nome + ' ' + faixa };
}
function linhasClimaConfig(c) {
  const v = lerValorClima(c.valor);
  if (!v) return [];
  const centro = (v.min + v.max) / 2;
  const L = [['Fase', faseClima(v.fase).nome], ['Faixa', brN(v.min, 2) + '–' + brN(v.max, 2) + ' kPa'],
    ['Regra', 'umidificador liga em ' + brN(pontosClima(v).liga, 2) + ' e desliga em ' + brN(pontosClima(v).desliga, 2) + ' (sempre dentro da faixa)'],
    ['Trava', 'umidificador até ' + Math.round(v.travaUmid) + '%'],
    ['Tempo mínimo', v.minLigado + ' min ligado · ' + v.minDesligado + ' min desligado']];
  const vpd = vpdEm(msEvento(c));
  if (vpd !== null) L.push(['VPD', fmtVpd(vpd) + ' no momento']);
  return L;
}
// Leitura enviada pelo ESP32 junto da ação (firmware 2.6.1): "estado;vpd;temp;umid;motivo" (média de 1 min).
function leituraDaAcao(c) {
  const ms = msEvento(c), estado = c.valor === 'ligado' ? '1' : '0';
  const reg = ultimoTodosComandos.find(x => x.tipo === 'clima_leitura' && x.alvo === c.alvo && Math.abs(msEvento(x) - ms) <= 15000 && String(x.valor).charAt(0) === estado);
  if (!reg) return null;
  const p = String(reg.valor).split(';');
  const n = v => (v === '' || v === undefined || isNaN(Number(v))) ? null : Number(v);
  return { vpd: n(p[1]), temp: n(p[2]), umid: n(p[3]), motivo: p[4] || '' };
}
// Pontos de ação do firmware 2.7.3: o VPD fica sempre dentro da faixa.
// Liga 25% da largura abaixo do máximo, desliga 25% acima do mínimo; a 10% das bordas age na hora.
function pontosClima(cf) {
  const w = cf.max - cf.min;
  return { liga: cf.max - w * 0.25, desliga: cf.min + w * 0.25, teto: cf.max - w * 0.10, piso: cf.min + w * 0.10 };
}
const MOTIVO_CLIMA = {
  ponto_ligar: cf => 'VPD chegou no ponto de ligar (' + brN(pontosClima(cf).liga, 2) + '), antes do máximo ' + brN(cf.max, 2),
  ponto_desligar: cf => 'VPD chegou no ponto de desligar (' + brN(pontosClima(cf).desliga, 2) + '), antes do mínimo ' + brN(cf.min, 2),
  teto: cf => 'VPD quase no máximo (' + brN(cf.max, 2) + ') — ligou na hora',
  piso: cf => 'VPD quase no mínimo (' + brN(cf.min, 2) + ') — desligou na hora',
  vpd_baixo: cf => 'VPD abaixo de ' + brN(cf.min, 2) + ' (ar úmido)',
  vpd_alto: cf => 'VPD acima de ' + brN(cf.max, 2) + ' (ar seco)',
  centro: cf => 'VPD chegou no meio da faixa (' + brN((cf.min + cf.max) / 2, 2) + ')',
  vpd_min: cf => 'VPD chegou no mínimo da faixa (' + brN(cf.min, 2) + ')',
  trava_temp: cf => 'trava: temperatura chegou em ' + brN(cf.travaTemp, 1) + ' °C',
  trava_umid: cf => 'trava: umidade chegou em ' + Math.round(cf.travaUmid) + '%',
  sem_sensor: () => 'sem leitura do sensor (segurança)',
};
// Motivo de uma ação automática do aquecedor/umidificador com o clima por VPD.
function linhasAcaoClima(c, cfg) {
  const lida = leituraDaAcao(c);
  if (lida) {
    const L = [];
    if (lida.vpd !== null) L.push(['VPD (média 1 min)', fmtVpd(lida.vpd)]);
    if (lida.temp !== null || lida.umid !== null) L.push(['No sensor', (lida.temp !== null ? brN(lida.temp, 1) + ' °C' : '—') + ' · ' + (lida.umid !== null ? Math.round(lida.umid) + '%' : '—')]);
    L.push(['Motivo', MOTIVO_CLIMA[lida.motivo] ? MOTIVO_CLIMA[lida.motivo](cfg) : lida.motivo]);
    L.push(['Fase do clima', faseClima(cfg.fase).nome + ' · ' + brN(cfg.min, 2) + '–' + brN(cfg.max, 2) + ' kPa']);
    return L;
  }
  // firmware 2.6.0: estima pelas leituras de minuto em minuto
  const ms = msEvento(c), ligou = c.valor === 'ligado', aquecedor = c.alvo === 'Aquecedor';
  const centro = (cfg.min + cfg.max) / 2;
  const vpd = vpdEm(ms), temp = leituraEm('temp_interna', ms), umid = leituraEm('umid_interna', ms);
  const L = [];
  if (vpd !== null) L.push(['VPD', fmtVpd(vpd)]);
  let motivo;
  if (ligou) motivo = aquecedor ? 'VPD abaixo de ' + brN(cfg.min, 2) + ' (ar úmido)' : 'VPD acima de ' + brN(cfg.max, 2) + ' (ar seco)';
  else if (aquecedor && temp !== null && temp >= cfg.travaTemp) motivo = 'trava: temperatura chegou em ' + brN(cfg.travaTemp, 1) + ' °C';
  else if (!aquecedor && umid !== null && umid >= cfg.travaUmid) motivo = 'trava: umidade chegou em ' + Math.round(cfg.travaUmid) + '%';
  else if (vpd === null && temp === null) motivo = 'sem leitura do sensor (segurança)';
  else motivo = 'VPD chegou no meio da faixa (' + brN(centro, 2) + ')';
  L.push(['Motivo', motivo]);
  L.push(['Fase do clima', faseClima(cfg.fase).nome + ' · ' + brN(cfg.min, 2) + '–' + brN(cfg.max, 2) + ' kPa']);
  return L;
}

/* ==================== Automações antigas (firmware 2.5) ==================== */
// Substituídas pelo clima por VPD. Ficam só para descrever registros antigos no relógio de eventos.
const AUTOMACOES = [
  {
    chave: 'aquecedor', rele: 'Aquecedor', titulo: 'Aquecedor automático', icone: 'heater',
    unidade: '°C', passo: 0.5, casas: 1, limite: [5, 40], padrao: { min: 20, max: 24 },
    campoLeitura: 'temp_interna', rotuloMin: 'Liga abaixo de', rotuloMax: 'Desliga em',
    descricao: (mn, mx) => 'Liga o aquecedor abaixo de ' + mn + ' e desliga ao chegar em ' + mx + '.',
  },
  {
    chave: 'umidade', rele: 'Umidificador', titulo: 'Umidade do ar', icone: 'cloud-drizzle',
    unidade: '%', passo: 1, casas: 0, limite: [20, 95], padrao: { min: 55, max: 65 },
    campoLeitura: 'umid_interna', rotuloMin: 'Liga abaixo de', rotuloMax: 'Desliga em',
    descricao: (mn, mx) => 'Liga o umidificador abaixo de ' + mn + ' e desliga ao chegar em ' + mx + '.',
  },
];
let automacoesEsp = {};       // chave -> { valor, origem, quando } (histórico)

function lerValorAutomacao(valor) {
  const p = String(valor || '').split(';');
  if (p.length !== 3) return null;
  const min = Number(p[1]), max = Number(p[2]);
  if (isNaN(min) || isNaN(max)) return null;
  return { ativa: p[0] === '1', min, max };
}
function valorAutomacao(e) { return (e.ativa ? '1' : '0') + ';' + e.min.toFixed(1) + ';' + e.max.toFixed(1); }
function fmtAuto(a, n) { return n.toFixed(a.casas).replace('.', ',') + (a.unidade === '%' ? '%' : ' ' + a.unidade); }

function automacaoDoRele(nomeRele) {
  return AUTOMACOES.find(a => a.rele === nomeRele) || null;
}

/* ---------- Automações no relógio de eventos ---------- */
function ehEventoAutomacao(c) {
  return c.tipo === 'automacao' || c.tipo === 'clima_vpd' || (c.tipo === 'rele' && c.origem === 'automacao');
}
function msEvento(c) { return new Date(String(timestampEvento(c) || '').replace(' ', 'T')).getTime(); }
function dataEvento(c) {
  const ts = String(timestampEvento(c) || '');
  return ts.length >= 10 ? ts.substring(8, 10) + '/' + ts.substring(5, 7) + '/' + ts.substring(0, 4) + ' às ' + ts.substring(11, 19) : 'data desconhecida';
}
function hhmmEvento(c) { return String(timestampEvento(c) || '').substring(11, 16) || '--:--'; }
function rotuloOrigem(origem) {
  return ({ automacao: 'automação', pausa_painel: 'comando manual no painel', pausa_oled: 'botão do OLED' })[origem] || origem || '';
}
function automacaoPorChave(chave) { return AUTOMACOES.find(a => a.chave === chave) || null; }
function nomeDaAutomacao(a) { return a.chave === 'aquecedor' ? 'do aquecedor' : 'da umidade'; }

// Faixa em vigor num instante: último registro "automacao" até ali (ou o estado atual do ESP32).
function faixaAutomacaoEm(a, ms) {
  const reg = ultimoTodosComandos.find(c => c.tipo === 'automacao' && c.alvo === a.chave && msEvento(c) <= ms);
  const lido = reg ? lerValorAutomacao(reg.valor) : (automacoesEsp[a.chave] ? lerValorAutomacao(automacoesEsp[a.chave].valor) : null);
  return lido || { ativa: false, min: a.padrao.min, max: a.padrao.max };
}
// Leitura mais próxima (até 5 min antes ou depois) do instante.
function leituraEm(campo, ms) {
  let melhor = null, dist = Infinity;
  leituras24h.forEach(l => {
    const t = new Date(String(l.criado_em || l.dispositivo_timestamp || '').replace(' ', 'T')).getTime();
    const v = l[campo];
    if (isNaN(t) || v === null || v === undefined) return;
    const d = Math.abs(t - ms);
    if (d < dist) { dist = d; melhor = Number(v); }
  });
  return dist <= 5 * 60 * 1000 ? melhor : null;
}

function descricaoAutomacao(c) {
  if (c.tipo === 'clima_vpd') return textoClima(c).titulo;
  if (c.tipo === 'rele') {
    return (RELE_ROTULO[c.alvo] || c.alvo) + (c.valor === 'ligado' ? ' ligado' : ' desligado') + ' pela automação';
  }
  const a = automacaoPorChave(c.alvo);
  const v = lerValorAutomacao(c.valor);
  if (!a || !v) return 'Automação: ' + c.alvo + ' (' + c.valor + ')';
  const nome = nomeDaAutomacao(a);
  const faixa = fmtAuto(a, v.min) + '–' + fmtAuto(a, v.max);
  if (String(c.origem || '').indexOf('pausa') === 0) return 'Automação ' + nome + ' pausada (comando manual)';
  if (!v.ativa) return 'Automação ' + nome + ' desativada';
  const anterior = ultimoTodosComandos.find(x => x.tipo === 'automacao' && x.alvo === c.alvo && msEvento(x) < msEvento(c));
  const ant = anterior ? lerValorAutomacao(anterior.valor) : null;
  if (ant && ant.ativa && (ant.min !== v.min || ant.max !== v.max)) return 'Faixa ' + nome + ' alterada para ' + faixa;
  return 'Automação ' + nome + ' ativada (' + faixa + ')';
}

// Linhas extras do detalhe: leitura, regra aplicada e quanto tempo ficou ligado.
function detalheAutomacao(c) {
  if (c.tipo === 'clima_vpd') return linhasClimaConfig(c);
  const linhas = [];
  const ms = msEvento(c);
  if (c.tipo === 'automacao') {
    const a = automacaoPorChave(c.alvo);
    const v = lerValorAutomacao(c.valor);
    if (!a || !v) return linhas;
    linhas.push(['Regra', a.rotuloMin.toLowerCase() + ' ' + fmtAuto(a, v.min) + ' · ' + a.rotuloMax.toLowerCase() + ' ' + fmtAuto(a, v.max)]);
    linhas.push(['Relé', (RELE_ROTULO[a.rele] || a.rele) + ' (relé ' + (RELE_NUMERO[a.rele] ?? '?') + ')']);
    const leitura = leituraEm(a.campoLeitura, ms);
    if (leitura !== null) linhas.push([a.chave === 'aquecedor' ? 'Temperatura' : 'Umidade', fmtAuto(a, leitura) + ' no momento']);
    return linhas;
  }
  const a = automacaoDoRele(c.alvo);
  if (!a) return linhas;
  const ligou = c.valor === 'ligado';
  const cfgClima = climaEm(ms);
  if (cfgClima && cfgClima.ativa) {
    linhasAcaoClima(c, cfgClima).forEach(l => linhas.push(l));
  } else {
    // registro antigo (automação por temperatura/umidade, firmware 2.5)
    const faixa = faixaAutomacaoEm(a, ms);
    const leitura = leituraEm(a.campoLeitura, ms);
    const grandeza = a.chave === 'aquecedor' ? 'Temperatura' : 'Umidade';
    if (leitura !== null) linhas.push([grandeza, fmtAuto(a, leitura)]);
    linhas.push(['Motivo', ligou ? 'abaixo de ' + fmtAuto(a, faixa.min) + ' (liga)' : 'chegou em ' + fmtAuto(a, faixa.max) + ' (desliga)']);
  }
  // Próximo (ou anterior) registro do mesmo relé, de qualquer origem.
  const doRele = ultimoTodosComandos.filter(x => x.tipo === 'rele' && x.alvo === c.alvo);
  if (ligou) {
    const seguinte = doRele.filter(x => msEvento(x) > ms).pop();
    if (seguinte) linhas.push(['Ficou ligado', formatarDuracao(msEvento(seguinte) - ms) + ' (até ' + hhmmEvento(seguinte) + ')']);
    else linhas.push(['Ficou ligado', 'continua ligado (' + formatarDuracao(Date.now() - ms) + ')']);
  } else {
    const anterior = doRele.find(x => msEvento(x) < ms && x.valor === 'ligado');
    if (anterior) linhas.push(['Estava ligado', formatarDuracao(ms - msEvento(anterior)) + ' (desde ' + hhmmEvento(anterior) + ')']);
  }
  return linhas;
}


// Card da luz (relé 0), exibido no grupo Relés.
function htmlCardLuz(todosComandos) {
  const cards = [];
  const ultimoLuz = todosComandos.find(c => c.tipo === 'rele' && c.alvo === 'Luz');
  const modoLuz = luzPendente ? luzPendente.valor : ultimoLuzEstado;
  let luzLigada;
  if (modoLuz === 'auto') luzLigada = estadoEDuracaoLuz(modoAtualParaLuz).ligada;
  else luzLigada = modoLuz === 'ligada';
  let luzTitulo, luzDetalhe;
  if (luzPendente) {
    luzTitulo = luzPendente.valor === 'auto' ? 'Voltando ao timer…' : (luzLigada ? 'Ligando…' : 'Desligando…');
    luzDetalhe = 'aguardando o ESP32';
  } else {
    luzTitulo = luzLigada ? 'Ligada' : 'Desligada';
    const d = duracaoDesde(ultimoLuz);
    luzDetalhe = (modoLuz === 'auto' ? 'automático' : 'manual') + (d ? ' · ' + d : '');
  }
  const auto = modoLuz === 'auto';
  cards.push(
    '<div class="rele-card' + (luzLigada ? ' on' : '') + (luzPendente ? ' pendente' : '') + '">' +
      '<div class="rele-card-topo">' +
        '<div class="rele-icone"><svg data-lucide="' + (luzLigada ? 'lightbulb' : 'lightbulb-off') + '"></svg></div>' +
        '<button type="button" class="rele-chip' + (auto ? ' ativo' : '') + '" data-luz-auto aria-pressed="' + auto + '" title="' + (auto ? 'Seguindo o timer' : 'Voltar ao timer automático') + '"' + (luzPendente ? ' disabled' : '') + '>Auto</button>' +
      '</div>' +
      '<div class="rele-nome">Luz</div>' +
      '<div class="rele-rodape">' +
        '<div class="rele-estado"><strong>' + luzTitulo + '</strong>' + escapeHtml(luzDetalhe) + '</div>' +
        '<button type="button" class="switch" role="switch" aria-checked="' + luzLigada + '" aria-label="Luz (manual)" data-luz' + (luzPendente ? ' disabled' : '') + '></button>' +
      '</div>' +
    '</div>'
  );

  return cards.join('');
}

// Card da trava da porta (relé 6), exibido no grupo Relés.
function htmlCardAcesso(todosComandos) {
  const cards = [];
  const ultimoAcesso = todosComandos.find(c => c.tipo === 'acesso');
  let acTitulo, acDetalhe;
  if (acessoEnviando) { acTitulo = 'Liberando…'; acDetalhe = 'aguardando o ESP32'; }
  else if (acessoConfirmando) { acTitulo = 'Confirmar?'; acDetalhe = 'toque de novo para abrir'; }
  else if (ultimoAcesso) { acTitulo = 'Liberado'; acDetalhe = duracaoDesde(ultimoAcesso); }
  else { acTitulo = 'Trancado'; acDetalhe = 'sem registro'; }
  const recente = ultimoAcesso && (Date.now() - new Date(String(timestampEvento(ultimoAcesso)).replace(' ', 'T')).getTime()) < 2 * 60 * 1000;
  const acClasse = acessoConfirmando ? ' confirmando' : (acessoEnviando ? ' pendente' : (recente ? ' liberado' : ''));
  cards.push(
    '<div class="rele-card' + acClasse + '">' +
      '<div class="rele-card-topo">' +
        '<div class="rele-icone"><svg data-lucide="' + (recente || acessoEnviando ? 'lock-open' : 'lock') + '"></svg></div>' +
        '<span class="rele-num">Relé 6</span>' +
      '</div>' +
      '<div class="rele-nome">Acesso</div>' +
      '<div class="rele-rodape">' +
        '<div class="rele-estado"><strong>' + acTitulo + '</strong>' + escapeHtml(acDetalhe) + '</div>' +
        '<button type="button" class="rele-acao' + (acessoConfirmando ? ' perigo' : ' verde') + '" data-acesso aria-label="' + (acessoConfirmando ? 'Confirmar liberação do acesso' : 'Liberar acesso') + '"' + (acessoEnviando ? ' disabled' : '') + '>' +
          (acessoEnviando ? '<span class="cmd-btn-spinner"></span>' : '<svg data-lucide="' + (acessoConfirmando ? 'check' : 'lock-open') + '"></svg>') +
        '</button>' +
      '</div>' +
    '</div>'
  );
  return cards.join('');
}

// Luz: interruptor = manual liga/desliga; chip "Auto" = volta ao timer.
async function enviarLuz(valor) {
  luzPendente = { valor, ate: Date.now() + 90 * 1000 };
  renderizarControles(ultimoTodosComandos);
  const ok = await enviarComando('luz_manual', 'luz_manual', valor, null);
  if (!ok) { luzPendente = null; renderizarControles(ultimoTodosComandos); return; }
  [6000, 15000, 30000].forEach(ms => setTimeout(() => { carregarRodaEventos().catch(() => {}); }, ms));
}

document.addEventListener('click', async ev => {
  const swLuz = ev.target.closest('.switch[data-luz]');
  if (swLuz && !swLuz.disabled) { enviarLuz(swLuz.getAttribute('aria-checked') === 'true' ? 'desligada' : 'ligada'); return; }

  const chip = ev.target.closest('.rele-chip[data-luz-auto]');
  if (chip && !chip.disabled && !chip.classList.contains('ativo')) { enviarLuz('auto'); return; }

  const ac = ev.target.closest('.rele-acao[data-acesso]');
  if (ac && !ac.disabled) {
    if (!acessoConfirmando) {
      acessoConfirmando = true;
      renderizarControles(ultimoTodosComandos);
      clearTimeout(acessoConfirmTimeout);
      acessoConfirmTimeout = setTimeout(() => { acessoConfirmando = false; renderizarControles(ultimoTodosComandos); }, 5000);
      return;
    }
    clearTimeout(acessoConfirmTimeout);
    acessoConfirmando = false;
    acessoEnviando = true;
    renderizarControles(ultimoTodosComandos);
    await enviarComando('acesso', 'acesso', 'liberar', null);
    acessoEnviando = false;
    renderizarControles(ultimoTodosComandos);
  }
});

let cmdToastTimeout = null;
function mostrarToast(mensagem, tipo) {
  const toast = document.getElementById('cmdToast');
  if (!toast) return;
  toast.textContent = mensagem;
  toast.className = 'cmd-toast visivel' + (tipo ? ' ' + tipo : '');
  clearTimeout(cmdToastTimeout);
  cmdToastTimeout = setTimeout(() => { toast.classList.remove('visivel'); }, 3200);
}

async function enviarComando(tipo, alvo, valor, botao) {
  const rotuloOriginal = botao ? botao.innerHTML : '';
  if (botao) { botao.disabled = true; botao.innerHTML = '<span class="cmd-btn-spinner"></span>enviando'; }
  try {
    const resposta = await fetch('enviar_comando.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ tipo, alvo, valor }) });
    if (resposta.status === 401) {
      mostrarToast('sess\u00e3o expirada \u2014 redirecionando pro login...', 'erro');
      setTimeout(() => { window.location.href = 'login.php'; }, 1200);
      return false;
    }
    const dados = await resposta.json();
    if (!dados.ok) { mostrarToast('erro: ' + (dados.erro || 'desconhecido'), 'erro'); return false; }
    mostrarToast('\u2713 comando enviado \u2014 aplica em alguns segundos', 'ok');
    setTimeout(() => { carregarRodaEventos().catch(() => {}); }, 1500);
    return true;
  } catch (e) {
    mostrarToast('falha de rede: ' + e.message, 'erro');
    return false;
  } finally {
    if (botao) {
      botao.disabled = false;
      if (botao.innerHTML.indexOf('cmd-btn-spinner') !== -1) botao.innerHTML = rotuloOriginal;
    }
  }
}

document.addEventListener('click', ev => {
  const botao = ev.target.closest('.cmd-btn');
  if (!botao) return;
  const { tipo, alvo, valor } = botao.dataset;
  if (!tipo || !alvo || !valor) return;
  enviarComando(tipo, alvo, valor, botao);
});

/* ==================== Relés (aba Comandos) ==================== */
// Número do relé na placa (índice em reles[] do firmware).
const RELE_NUMERO = { 'Irrigacao': 1, 'Ventilador': 2, 'Umidificador': 3, 'Entrada de Ar': 4, 'Aquecedor': 5, 'Saida de Ar': 7 };
const RELE_ROTULO = { 'Saida de Ar': 'Saída de Ar', 'Irrigacao': 'Irrigação' };
// Comandos enviados e ainda não confirmados: nome -> { valor, ate }.
const relesPendentes = {};
let ultimoTodosComandos = [];
let relesEstado = {};   // último registro de cada relé vindo do servidor (com "desde")

// Quando o estado atual do relé começou. Usa o "desde" do servidor, que ignora registros repetidos
// (ex.: o ESP32 reconfirmando o estado ao ligar), para "ligado há 12 dias" não virar "há 2 min".
function inicioEstadoRele(nome, reg) {
  if (!reg) return NaN;
  const r = relesEstado[nome];
  if (r && r.valor === reg.valor && r.desde) {
    const t = new Date(String(r.desde).replace(' ', 'T')).getTime();
    if (!isNaN(t) && t <= msEvento(reg)) return t;
  }
  return msEvento(reg);
}

function ultimoRegistroRele(todosComandos, nomeRele) {
  return todosComandos.find(c => c.tipo === 'rele' && c.alvo === nomeRele) || null;
}

function renderizarBotoesReles(todosComandos) {
  const container = document.getElementById('cmdReles');
  if (!container) return;
  container.innerHTML = htmlCardLuz(todosComandos);
  const agora = Date.now();
  RELES_CONTROLAVEIS.forEach(nome => {
    // Trava (relé 6) entra na ordem da placa, antes da Saída de Ar (relé 7).
    if (nome === 'Saida de Ar') container.insertAdjacentHTML('beforeend', htmlCardAcesso(todosComandos));
    const ultimo = ultimoRegistroRele(todosComandos, nome);
    const estadoReal = ultimo ? ultimo.valor : null;
    const pend = relesPendentes[nome];
    if (pend && (pend.valor === estadoReal || agora > pend.ate)) delete relesPendentes[nome];
    const pendente = relesPendentes[nome] || null;
    const estado = pendente ? pendente.valor : estadoReal;
    const ligado = estado === 'ligado';

    let titulo, detalhe;
    if (pendente) {
      titulo = ligado ? 'Ligando…' : 'Desligando…';
      detalhe = 'aguardando o ESP32';
    } else if (!ultimo) {
      titulo = 'Desligado';
      detalhe = 'sem registro';
    } else {
      titulo = ligado ? 'Ligado' : 'Desligado';
      const desde = inicioEstadoRele(nome, ultimo);
      const dur = isNaN(desde) ? '' : formatarDuracao(agora - desde);
      detalhe = !dur ? '' : (dur === 'agora mesmo' ? dur : 'há ' + dur);
      if (climaAtivoNoRele(nome)) detalhe = 'clima por VPD' + (detalhe ? ' · ' + detalhe : '');
    }

    // Proteção da bomba: com a caixa abaixo de 10% a irrigação não liga (o ESP32 também recusa).
    const pctCaixa = nome === 'Irrigacao' ? caixaPercentualAtual() : null;
    const bloqueada = pctCaixa !== null && pctCaixa < CAIXA_PROTECAO_PCT && !ligado;
    if (bloqueada) { titulo = 'Bloqueada'; detalhe = 'caixa com ' + Math.round(pctCaixa) + '% · protege a bomba'; }

    const icone = RELE_ICONE[nome] || 'plug';
    const girando = (nome === 'Ventilador' && ligado) ? ' girando' : '';
    const card = document.createElement('div');
    card.className = 'rele-card' + (ligado ? ' on' : '') + (pendente ? ' pendente' : '');
    card.innerHTML =
      '<div class="rele-card-topo">' +
        '<div class="rele-icone"><svg data-lucide="' + icone + '" class="icone-ventilador' + girando + '"></svg></div>' +
        '<span class="rele-num">Relé ' + (RELE_NUMERO[nome] ?? '?') + '</span>' +
      '</div>' +
      '<div class="rele-nome">' + escapeHtml(RELE_ROTULO[nome] || nome) + '</div>' +
      '<div class="rele-rodape">' +
        '<div class="rele-estado"><strong>' + titulo + '</strong>' + escapeHtml(detalhe) + '</div>' +
        '<button type="button" class="switch" role="switch" aria-checked="' + ligado + '" aria-label="' + escapeHtml(RELE_ROTULO[nome] || nome) + '" data-rele="' + escapeHtml(nome) + '"' + (pendente || bloqueada ? ' disabled' : '') + (bloqueada ? ' title="Caixa d\'água abaixo de 10%"' : '') + '></button>' +
      '</div>';
    container.appendChild(card);
  });
  if (window.lucide) lucide.createIcons();
}

document.addEventListener('click', async ev => {
  const sw = ev.target.closest('.switch[data-rele]');
  if (!sw || sw.disabled) return;
  const nome = sw.dataset.rele;
  const novoValor = sw.getAttribute('aria-checked') === 'true' ? 'desligado' : 'ligado';
  relesPendentes[nome] = { valor: novoValor, ate: Date.now() + 90 * 1000 };
  renderizarBotoesReles(ultimoTodosComandos);
  // Comando manual no aquecedor/umidificador pausa o clima por VPD (o ESP32 faz o mesmo ao receber).
  const pausaClima = climaAtivoNoRele(nome);
  const estClima = estadoClima();
  const ok = await enviarComando('rele', nome, novoValor, null);
  if (!ok) {
    delete relesPendentes[nome];
    renderizarBotoesReles(ultimoTodosComandos);
    return;
  }
  if (pausaClima) {
    climaPendente = Object.assign({}, estClima, { ativa: false, pendente: undefined, registro: undefined, origem: undefined, ate: Date.now() + 90 * 1000 });
    renderizarControles(ultimoTodosComandos);
    mostrarToast('\u2713 comando enviado \u2014 clima por VPD pausado', 'ok');
  }
  // Reconsulta algumas vezes até o ESP32 confirmar.
  [6000, 15000, 30000].forEach(ms => setTimeout(() => { carregarRodaEventos().catch(() => {}); }, ms));
});

/* ==================== Fotoperíodo (timers de luz) ==================== */
// Horário em edição por modo (minutos), ou null quando não há rascunho.
const rascunhoTimer = { vegetativo: null, floracao: null };
let ultimoLuzEstado = 'auto';

function opcoesSelect(valores, selecionado) {
  return valores.map(v => '<option value="' + v + '"' + (v === selecionado ? ' selected' : '') + '>' + v + '</option>').join('');
}

function segmentosLinhaTempo(j) {
  const pct = m => (m / 1440 * 100).toFixed(3) + '%';
  if (j.fimMin > j.inicioMin) {
    return '<div class="linha-tempo-luz" style="left:' + pct(j.inicioMin) + ';width:' + pct(j.fimMin - j.inicioMin) + ';"></div>';
  }
  return '<div class="linha-tempo-luz" style="left:' + pct(j.inicioMin) + ';width:' + pct(1440 - j.inicioMin) + ';"></div>' +
    (j.fimMin > 0 ? '<div class="linha-tempo-luz" style="left:0;width:' + pct(j.fimMin) + ';"></div>' : '');
}

// Estado do timer da fase ativa no ESP32 (vem de configuracoes.php).
function estadoTimerEsp(modo) {
  const info = FOTOPERIODO_INFO[modo];
  const salvoMin = inicioLuzMin(modo);
  const faixa = m => minParaHHMM(m) + ' \u2192 ' + minParaHHMM((m + HORAS_LUZ_POR_MODO[modo] * 60) % 1440);
  const estado = info && info.estado ? info.estado : 'sem_confirmacao';
  const quando = info && info.confirmado_em ? String(info.confirmado_em).substring(11, 16) : '';
  if (estado === 'confirmado') return { classe: 'ok', icone: 'circle-check', texto: 'Confirmado', legenda: faixa(salvoMin) + (quando ? ' · às ' + quando : '') };
  if (estado === 'divergente') return { classe: 'erro', icone: 'circle-alert', texto: 'Divergente',
    legenda: 'ESP32: ' + faixa(hhmmParaMin(info.confirmado)) + ' · painel: ' + faixa(salvoMin) };
  if (estado === 'aguardando') return { classe: 'espera', icone: 'clock', texto: 'Aguardando',
    legenda: 'Enviado ' + faixa(salvoMin) + (info && info.salvo_ha_seg !== null ? ' há ' + textoHa(Number(info.salvo_ha_seg)) : '') };
  return { classe: '', icone: 'circle-help', texto: 'Sem confirmação', legenda: 'O ESP32 informa o timer ao ligar e após cada comando' };
}

function hojeIso() {
  const d = new Date();
  return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
}

function secaoTrocaFase(modo) {
  const prog = CICLO && CICLO.programada && CICLO.programada.fase === modo ? CICLO.programada : null;
  const inicio = minParaHHMM(inicioLuzMin(modo));
  let html = '<div class="troca-fase"><h4>Troca de fase</h4>' +
    '<p>Ative agora ou programe uma data. A ' + NOME_MODO[modo] + ' começa no primeiro horário de luz (' + inicio + ') desse dia, e a contagem de dias recomeça.</p>';
  if (prog) {
    const ts = String(prog.executar_em);
    html += '<div class="troca-programada"><span>Programada para <strong>' + ts.substring(8, 10) + '/' + ts.substring(5, 7) + ' às ' + ts.substring(11, 16) + '</strong></span>' +
      '<button type="button" class="btn btn-sec" data-acao="cancelar-troca" data-id="' + Number(prog.id) + '">Cancelar</button></div>';
  }
  html += '<input type="date" data-campo="data-troca" min="' + hojeIso() + '" aria-label="Data da troca">' +
    '<div class="troca-acoes">' +
      '<button type="button" class="btn btn-sec" data-acao="programar"><svg data-lucide="calendar"></svg>Programar</button>' +
      '<button type="button" class="btn btn-verde-sec" data-acao="ativar"><svg data-lucide="play"></svg>Ativar agora</button>' +
    '</div></div>';
  return html;
}

async function postFotoperiodo(corpo, botao) {
  const rotulo = botao ? botao.innerHTML : '';
  if (botao) { botao.disabled = true; botao.innerHTML = '<span class="cmd-btn-spinner"></span>enviando'; }
  try {
    const r = await fetch('fotoperiodo.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(corpo) });
    if (r.status === 401) { window.location.href = 'login.php'; return null; }
    const d = await r.json();
    if (!d.ok) { mostrarToast(d.detalhe || ('erro: ' + (d.erro || 'desconhecido')), 'erro'); return null; }
    if (d.ciclo) CICLO = d.ciclo;
    return d;
  } catch (e) {
    mostrarToast('falha de rede: ' + e.message, 'erro');
    return null;
  } finally {
    if (botao && document.body.contains(botao)) { botao.disabled = false; botao.innerHTML = rotulo; }
  }
}

function renderizarTimerCard(modo) {
  const card = document.getElementById('timer-' + modo);
  if (!card) return;
  const horasLuz = HORAS_LUZ_POR_MODO[modo];
  const ativo = ultimoModoAtual === modo;
  card.classList.toggle('ativo', ativo);
  const horas = Array.from({ length: 24 }, (_, i) => pad2(i));
  const minutos = ['00', '15', '30', '45'];
  card.innerHTML =
    '<div class="timer-cabecalho">' +
      '<div class="timer-titulo"><div class="card-icone"><svg data-lucide="' + (modo === 'vegetativo' ? 'sprout' : 'flower-2') + '" style="width:18px;height:18px;"></svg></div>' +
        '<h3>' + NOME_MODO[modo] + '</h3></div>' +
      '<div class="timer-badges">' + (ativo ? '<span class="pill pill-ok">Fase ativa</span>' : '<span class="pill pill-inativa">Inativa</span>') +
        '<span class="badge-fixo"><svg data-lucide="lock"></svg>' + horasLuz + 'h luz / ' + (24 - horasLuz) + 'h escuro</span></div>' +
    '</div>' +
    '<p class="timer-sub">Dura&ccedil;&atilde;o fixa de ' + horasLuz + '/' + (24 - horasLuz) + ' horas. Escolha s&oacute; a hora em que a luz acende; o apagar &eacute; calculado.</p>' +
    '<div class="linha-tempo" data-p="linha"></div>' +
    '<div class="linha-tempo-escala"><span>00h</span><span>06h</span><span>12h</span><span>18h</span><span>24h</span></div>' +
    '<div class="timer-campos">' +
      '<div class="campo"><label for="timerHora-' + modo + '">Luz acende &agrave;s</label>' +
        '<div class="campo-hora"><select id="timerHora-' + modo + '" data-campo="hora" aria-label="Hora">' + opcoesSelect(horas, '00') + '</select><span>:</span>' +
        '<select data-campo="min" aria-label="Minutos">' + opcoesSelect(minutos, '00') + '</select></div></div>' +
      '<div class="campo"><span class="campo-label">Luz apaga &agrave;s</span><div class="campo-leitura" data-p="fim">--:--</div></div>' +
    '</div>' +
    '<div class="timer-ajuste">' +
      '<button type="button" data-ajuste="-60">&minus;1h</button><button type="button" data-ajuste="-15">&minus;15min</button>' +
      '<button type="button" data-ajuste="15">+15min</button><button type="button" data-ajuste="60">+1h</button>' +
    '</div>' +
    '<div class="timer-resumo">' +
      '<div>Luz<strong>' + horasLuz + 'h</strong></div><div>Escuro<strong>' + (24 - horasLuz) + 'h</strong></div>' +
      '<div>' + (ativo ? 'Agora' : 'Se ativada agora') + '<strong data-p="agora">--</strong></div>' +
    '</div>' +
    '<div class="timer-acoes">' +
      '<button type="button" class="btn btn-primario" data-acao="salvar"><svg data-lucide="save"></svg>Salvar timer</button>' +
      '<button type="button" class="btn btn-sec" data-acao="descartar">Descartar</button>' +
      '<button type="button" class="btn btn-sec" data-acao="padrao" title="Volta para ' + INICIO_PADRAO_POR_MODO[modo] + '">Padr&atilde;o</button>' +
    '</div>' +
    (ativo ? '' : secaoTrocaFase(modo));
  if (window.lucide) lucide.createIcons();
  atualizarPreviewTimer(modo);
}

function atualizarPreviewTimer(modo) {
  const card = document.getElementById('timer-' + modo);
  if (!card) return;
  const salvo = inicioLuzMin(modo);
  const editado = rascunhoTimer[modo] !== null ? rascunhoTimer[modo] : salvo;
  const j = janelaDoModo(modo, editado);
  const sujo = editado !== salvo;

  card.querySelector('[data-campo="hora"]').value = pad2(Math.floor(editado / 60));
  card.querySelector('[data-campo="min"]').value = pad2(editado % 60);
  card.querySelector('[data-p="fim"]').textContent = minParaHHMM(j.fimMin) + (j.fimMin <= j.inicioMin ? ' (dia seguinte)' : '');

  const agora = new Date();
  const minAgora = agora.getHours() * 60 + agora.getMinutes();
  const ativo = ultimoModoAtual === modo;
  card.querySelector('[data-p="linha"]').innerHTML = segmentosLinhaTempo(j) +
    (ativo ? '<div class="linha-tempo-agora" style="left:' + (minAgora / 1440 * 100).toFixed(3) + '%;" title="agora"></div>' : '');
  card.querySelector('[data-p="agora"]').textContent = luzLigadaNaJanela(j, minAgora / 60) ? 'acesa' : 'apagada';

  card.querySelector('[data-acao="salvar"]').disabled = !sujo;
  card.querySelector('[data-acao="descartar"]').style.display = sujo ? '' : 'none';
  card.querySelector('[data-acao="padrao"]').style.display = (editado === hhmmParaMin(INICIO_PADRAO_POR_MODO[modo])) ? 'none' : '';
}

function atualizarStatusFotoperiodo() {
  const modo = ultimoModoAtual;
  const faseEl = document.getElementById('autoFaseAtiva');
  if (!faseEl) return;
  faseEl.textContent = NOME_MODO[modo] + ' (' + HORAS_LUZ_POR_MODO[modo] + '/' + (24 - HORAS_LUZ_POR_MODO[modo]) + ')';
  document.getElementById('autoFaseSub').textContent = CICLO && (CICLO.fase_pedida || CICLO.fase) === modo && CICLO.dia ? 'Dia ' + CICLO.dia + ' do ciclo' : 'Dia do ciclo indisponível';

  const est = estadoEDuracaoLuz(modo);
  const manual = ultimoLuzEstado !== 'auto';
  const j = janelaDoModo(modo, inicioLuzMin(modo));
  document.getElementById('autoLuzAgora').textContent = est.ligada ? 'Acesa' : 'Apagada';
  document.getElementById('autoLuzSub').textContent = manual ? 'modo manual ativo'
    : 'pelo timer, desde ' + minParaHHMM(est.ligada ? j.inicioMin : j.fimMin);
  document.getElementById('autoProxima').textContent = (est.ligada ? 'Apaga ' : 'Acende ') + est.proximaHHMM;
  document.getElementById('autoProximaSub').textContent = 'em ' + formatarDuracaoCurta(Math.max(1, Math.round(est.totalSegundos / 60)));

  const t = estadoTimerEsp(modo);
  const card = document.getElementById('autoTimerCard');
  card.classList.remove('ok', 'espera', 'erro');
  if (t.classe) card.classList.add(t.classe);
  document.getElementById('autoTimer').textContent = t.texto;
  document.getElementById('autoTimerSub').textContent = t.legenda;
  const icone = document.getElementById('autoTimerIcone');
  if (icone.dataset.icone !== t.icone) {
    icone.dataset.icone = t.icone;
    icone.innerHTML = '<svg data-lucide="' + t.icone + '" style="width:16px;height:16px;"></svg>';
    if (window.lucide) lucide.createIcons();
  }
}

function renderizarFotoperiodo() {
  renderizarTimerCard('vegetativo');
  renderizarTimerCard('floracao');
  atualizarStatusFotoperiodo();
}

const autoGridEl = document.querySelector('.auto-grid');
if (autoGridEl) {
  autoGridEl.addEventListener('change', ev => {
    const card = ev.target.closest('.timer-card');
    if (!card || !ev.target.dataset.campo) return;
    const modo = card.dataset.modo;
    const h = Number(card.querySelector('[data-campo="hora"]').value);
    const m = Number(card.querySelector('[data-campo="min"]').value);
    rascunhoTimer[modo] = h * 60 + m;
    atualizarPreviewTimer(modo);
  });
  autoGridEl.addEventListener('click', async ev => {
    const card = ev.target.closest('.timer-card');
    if (!card) return;
    const modo = card.dataset.modo;
    const atual = rascunhoTimer[modo] !== null ? rascunhoTimer[modo] : inicioLuzMin(modo);
    const ajuste = ev.target.closest('[data-ajuste]');
    if (ajuste) {
      rascunhoTimer[modo] = ((atual + Number(ajuste.dataset.ajuste)) % 1440 + 1440) % 1440;
      atualizarPreviewTimer(modo);
      return;
    }
    const acao = ev.target.closest('[data-acao]');
    if (!acao) return;
    if (acao.dataset.acao === 'ativar') {
      if (!acao.classList.contains('confirmar')) {
        acao.classList.add('confirmar');
        acao.innerHTML = '<svg data-lucide="check"></svg>Confirmar troca';
        if (window.lucide) lucide.createIcons();
        setTimeout(() => { if (document.body.contains(acao) && acao.classList.contains('confirmar')) { acao.classList.remove('confirmar'); acao.innerHTML = '<svg data-lucide="play"></svg>Ativar agora'; if (window.lucide) lucide.createIcons(); } }, 5000);
        return;
      }
      acao.classList.remove('confirmar');
      const d = await postFotoperiodo({ acao: 'ativar', fase: modo }, acao);
      if (d) {
        mostrarToast('\u2713 ' + NOME_MODO[modo] + ' ativada \u2014 o ESP32 aplica em alguns segundos', 'ok');
        ultimoModoAtual = modo;
        renderizarFotoperiodo();
        atualizarSeloFase();
        [2000, 8000, 20000].forEach(ms => setTimeout(() => { atualizarTudo().catch(() => {}); }, ms));
      }
      return;
    }
    if (acao.dataset.acao === 'programar') {
      const data = card.querySelector('[data-campo="data-troca"]').value;
      if (!data) { mostrarToast('escolha uma data', 'erro'); return; }
      const d = await postFotoperiodo({ acao: 'programar', fase: modo, data }, acao);
      if (d) { mostrarToast('\u2713 troca programada', 'ok'); renderizarFotoperiodo(); carregarRodaEventos().catch(() => {}); }
      return;
    }
    if (acao.dataset.acao === 'cancelar-troca') {
      const d = await postFotoperiodo({ acao: 'cancelar', id: Number(acao.dataset.id) }, acao);
      if (d) { mostrarToast('troca programada cancelada', 'ok'); renderizarFotoperiodo(); carregarRodaEventos().catch(() => {}); }
      return;
    }
    if (acao.dataset.acao === 'descartar') { rascunhoTimer[modo] = null; atualizarPreviewTimer(modo); return; }
    if (acao.dataset.acao === 'padrao') { rascunhoTimer[modo] = hhmmParaMin(INICIO_PADRAO_POR_MODO[modo]); atualizarPreviewTimer(modo); return; }
    if (acao.dataset.acao === 'salvar') {
      const hhmm = minParaHHMM(atual);
      // Passos de 15 min (mesma regra do servidor e do firmware).
      if (atual % 15 !== 0) { mostrarToast('use minutos 00, 15, 30 ou 45', 'erro'); return; }
      const ok = await enviarComando('fotoperiodo', modo, hhmm, acao);
      if (ok) {
        CONFIG['fotoperiodo_' + modo + '_inicio'] = hhmm;
        rascunhoTimer[modo] = null;
        atualizarPillsModo();
        renderizarFotoperiodo();
        renderizarRoda();
        [6000, 15000, 30000].forEach(ms => setTimeout(() => { carregarConfiguracoes().then(() => { if (abaAtual === 'fotoperiodo') renderizarFotoperiodo(); }).catch(() => {}); }, ms));
      }
    }
  });
}

/* ==================== Gráfico de VPD com faixas ==================== */
// Zonas de referência do gráfico; cores: rosa fraco, rosa forte, verde, amarelo, rosa forte.
const VPD_FAIXAS = [
  { de: 0.0, ate: 0.4, cor: '#f4aac3', nome: '0–0,4 · muito úmido' },
  { de: 0.4, ate: 0.8, cor: '#e84885', nome: '0,4–0,8 · propagação/veg inicial' },
  { de: 0.8, ate: 1.2, cor: '#4CAF50', nome: '0,8–1,2 · veg tardio/flora inicial' },
  { de: 1.2, ate: 1.6, cor: '#e8c977', nome: '1,2–1,6 · flora média/tardia' },
  { de: 1.6, ate: Infinity, cor: '#e84885', nome: '1,6–2,0+ · muito seco' },
];
function hexComAlfa(hex, a) {
  const n = parseInt(hex.slice(1), 16);
  return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
}
const pluginFaixasVpd = {
  id: 'faixasVpd',
  beforeDatasetsDraw(chart) {
    const { ctx, chartArea: a, scales: { y } } = chart;
    if (!a || !y) return;
    ctx.save();
    ctx.beginPath();
    ctx.rect(a.left, a.top, a.right - a.left, a.bottom - a.top);
    ctx.clip();
    VPD_FAIXAS.forEach(f => {
      const yTopo = y.getPixelForValue(Math.min(f.ate, y.max));
      const yBase = y.getPixelForValue(Math.max(f.de, y.min));
      if (yBase <= yTopo) return;
      ctx.fillStyle = hexComAlfa(f.cor, f.cor === '#f4aac3' ? 0.3 : 0.22);
      ctx.fillRect(a.left, yTopo, a.right - a.left, yBase - yTopo);
      ctx.fillStyle = hexComAlfa(f.cor, 0.5);
      ctx.fillRect(a.left, yTopo, a.right - a.left, 1);
    });
    ctx.restore();
  },
};
(function montarLegendaVpd() {
  const el = document.getElementById('vpdLegenda');
  if (el) el.innerHTML = VPD_FAIXAS.map(f => '<span><i style="background:' + f.cor + ';"></i>' + f.nome + '</span>').join('');
})();

function montarGraficoVpd(linhas, periodo) {
  const labels = linhas.map(l => formatarRotuloTempo(l, periodo));
  const valores = linhas.map(l => (l.vpd === null || l.vpd === undefined) ? null : Number(l.vpd));
  const maxDado = valores.reduce((m, v) => (v !== null && v > m ? v : m), 0);
  const yMax = Math.max(2.0, Math.ceil(maxDado * 10) / 10);
  const fmt = v => Number(v).toFixed(2).replace('.', ',');
  return new Chart(document.getElementById('graficoVpd').getContext('2d'), {
    type: 'line',
    data: { labels, datasets: [{ label: 'VPD (kPa)', data: valores, borderColor: '#eef1ee', backgroundColor: '#eef1ee', spanGaps: true, pointRadius: 0, pointHoverRadius: 4, borderWidth: 2, tension: 0.25 }] },
    options: {
      responsive: true, maintainAspectRatio: false,
      animation: graficoSemAnimacao ? false : undefined,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: c => c.parsed.y === null ? 'VPD: --' : 'VPD: ' + fmt(c.parsed.y) + ' kPa · ' + avaliarVpd(c.parsed.y, ultimoModoAtual).texto } },
      },
      scales: {
        x: { ticks: { color: '#8a938e', maxTicksLimit: 8, font: { size: 10 } }, grid: { color: '#242a27' } },
        y: { min: 0, max: yMax, ticks: { color: '#8a938e', font: { size: 10 }, stepSize: 0.4, callback: v => fmt(v).replace(/0$/, '') }, grid: { color: 'rgba(36,42,39,.6)' }, title: { display: true, text: 'kPa', color: '#8a938e' } },
      },
    },
    plugins: [pluginFaixasVpd],
  });
}

/* ==================== Configurações ==================== */
let intervaloAtualizacaoId = null;
let intervaloAtualizacaoSeg = null;

// Valores fora das opções atuais viram o padrão.
function normalizarConfig(c) {
  if (!INTERVALOS_VALIDOS.includes(String(c.intervalo_atualizacao_seg))) c.intervalo_atualizacao_seg = '5';
  if (c.pagina_inicial === 'automacao') c.pagina_inicial = 'fotoperiodo';
  return c;
}
async function carregarConfiguracoes() {
  const d = await buscarJson('configuracoes.php');
  if (d.config) CONFIG = normalizarConfig(Object.assign({}, CONFIG, d.config));
  if (d.fotoperiodo) FOTOPERIODO_INFO = d.fotoperiodo;
  if (d.ciclo) CICLO = d.ciclo;
  LUZ_DURACAO_ESP = d.luz_duracao_esp || null;
  atualizarSeloFase();
  renderizarLuzManual();
  aplicarConfiguracoes();
  if (abaAtual === 'relogio') renderizarRoda(); // faixa de luz segue o fotoperíodo salvo
}

function aplicarConfiguracoes() {
  const nome = CONFIG.nome_cultivo || 'Smart Demetra';
  document.getElementById('nomeCultivoSidebar').textContent = nome;
  document.getElementById('nomeCultivoTopo').textContent = nome;
  document.title = nome + ' — Painel';

  const soloMin = Number(CONFIG.solo_ideal_min), soloMax = Number(CONFIG.solo_ideal_max);
  document.getElementById('soloLabelFaixa').textContent = 'Umidade do solo (ideal entre ' + soloMin + '% e ' + soloMax + '%)';
  const trilho = document.querySelector('.barra-trilho.solo');
  if (trilho) {
    const a = Math.max(0, soloMin - 2), b = Math.min(100, soloMax + 2);
    trilho.style.background = 'linear-gradient(to right, #223a4a 0%, #223a4a ' + a + '%, var(--azul) ' + soloMin + '%, var(--azul) ' + soloMax + '%, #223a4a ' + b + '%, #223a4a 100%)';
  }
  if (ultimaLeitura) { atualizarSoloBarra(ultimaLeitura.solo_percentual); atualizarAguaBarra(ultimaLeitura); aplicarAlertasCards(ultimaLeitura); }

  atualizarPillsModo();
  if (!formConfigSujo()) preencherFormConfig();

  if (!INTERVALOS_VALIDOS.includes(String(CONFIG.intervalo_atualizacao_seg))) CONFIG.intervalo_atualizacao_seg = '5';
  const seg = Number(CONFIG.intervalo_atualizacao_seg);
  if (seg !== intervaloAtualizacaoSeg) {
    intervaloAtualizacaoSeg = seg;
    armarAtualizacao();
  }
  if (abaAtual === 'fotoperiodo') renderizarFotoperiodo();
}

// Legenda dos cards: faixa ok, alerta, ambiente externo e solo.
function aplicarAlertasCards(leitura) {
  const legenda = (idValor, idLeg, texto, alerta) => {
    document.getElementById(idValor).closest('.card').classList.toggle('alerta', !!alerta);
    document.getElementById(idLeg).textContent = texto;
  };
  const num = v => (v === null || v === undefined) ? null : Number(v);
  const t = num(leitura.temp_interna), u = num(leitura.umid_interna);
  const tMin = Number(CONFIG.temp_alerta_min), tMax = Number(CONFIG.temp_alerta_max), uMin = Number(CONFIG.umid_alerta_min), uMax = Number(CONFIG.umid_alerta_max);
  const faixaT = 'Faixa ok · ' + String(tMin).replace('.', ',') + '–' + String(tMax).replace('.', ',') + ' °C';
  if (t === null) legenda('statTempInterna', 'legTempInterna', '', false);
  else if (t < tMin) legenda('statTempInterna', 'legTempInterna', 'abaixo de ' + fmtTemp(tMin), true);
  else if (t > tMax) legenda('statTempInterna', 'legTempInterna', 'acima de ' + fmtTemp(tMax), true);
  else legenda('statTempInterna', 'legTempInterna', faixaT, false);
  if (u === null) legenda('statUmidInterna', 'legUmidInterna', '', false);
  else if (u < uMin) legenda('statUmidInterna', 'legUmidInterna', 'abaixo de ' + uMin + '%', true);
  else if (u > uMax) legenda('statUmidInterna', 'legUmidInterna', 'acima de ' + uMax + '%', true);
  else legenda('statUmidInterna', 'legUmidInterna', 'Faixa ok · ' + uMin + '–' + uMax + '%', false);

  // Solo: sensor sem leitura tem prioridade sobre seco/encharcado.
  const icone = document.getElementById('soloIcone');
  const iconeDesejado = soloSemLeitura ? 'alert-triangle' : 'sprout';
  if (icone.dataset.icone !== iconeDesejado) {
    icone.dataset.icone = iconeDesejado;
    icone.innerHTML = '<svg data-lucide="' + iconeDesejado + '" style="width:16px;height:16px;"></svg>';
    if (window.lucide) lucide.createIcons();
  }
  const s = num(leitura.solo_percentual);
  const sMin = Number(CONFIG.solo_ideal_min), sMax = Number(CONFIG.solo_ideal_max);
  if (soloSemLeitura) legenda('statSolo', 'legSolo', 'Sensor sem leitura', true);
  else if (s === null) legenda('statSolo', 'legSolo', '', false);
  else if (s < sMin) legenda('statSolo', 'legSolo', 'Seco · ideal ' + sMin + '–' + sMax + '%', true);
  else if (s > sMax) legenda('statSolo', 'legSolo', 'Encharcado · ideal ' + sMin + '–' + sMax + '%', true);
  else legenda('statSolo', 'legSolo', 'Ideal · ' + sMin + '–' + sMax + '%', false);
}

const formConfig = document.getElementById('formConfig');
const CAMPOS_CONFIG = ['nome_cultivo', 'pagina_inicial', 'intervalo_atualizacao_seg', 'periodo_padrao_historico', 'agua_alerta_min', 'solo_ideal_min', 'solo_ideal_max', 'temp_alerta_min', 'temp_alerta_max', 'umid_alerta_min', 'umid_alerta_max', 'vpd_vegetativo_min', 'vpd_vegetativo_max', 'vpd_floracao_min', 'vpd_floracao_max', 'dispositivo_sem_dados_min'];
const ehNumerico = el => el.type === 'number' || el.hasAttribute('data-decimal');
const paraNumero = v => parseFloat(String(v).replace(',', '.'));

function valoresFormConfig() {
  const v = {};
  CAMPOS_CONFIG.forEach(c => { const el = formConfig.elements[c]; if (el) v[c] = String(el.value).trim(); });
  return v;
}
function alteracoesFormConfig() {
  const v = valoresFormConfig();
  const mudou = {};
  CAMPOS_CONFIG.forEach(c => {
    const novo = v[c], antigo = String(CONFIG[c] ?? '');
    const iguais = ehNumerico(formConfig.elements[c]) ? paraNumero(novo) === paraNumero(antigo) : novo === antigo;
    if (!iguais) mudou[c] = novo;
  });
  return mudou;
}
function formConfigSujo() { return formConfig && Object.keys(alteracoesFormConfig()).length > 0 && formConfig.dataset.preenchido === '1'; }
function preencherFormConfig() {
  CAMPOS_CONFIG.forEach(c => { const el = formConfig.elements[c]; if (el) el.value = el.hasAttribute('data-decimal') ? (c.startsWith('vpd_') ? fmtKpa(CONFIG[c]) : String(CONFIG[c]).replace('.', ',')) : CONFIG[c]; });
  formConfig.dataset.preenchido = '1';
  atualizarRodapeConfig();
}
function atualizarRodapeConfig() {
  const sujo = Object.keys(alteracoesFormConfig()).length > 0;
  document.getElementById('cfgSalvar').disabled = !sujo;
  document.getElementById('cfgRodape').classList.toggle('visivel', sujo);
}
formConfig.addEventListener('input', atualizarRodapeConfig);
document.getElementById('cfgDescartar').addEventListener('click', preencherFormConfig);
formConfig.addEventListener('submit', async ev => {
  ev.preventDefault();
  const mudou = alteracoesFormConfig();
  if (Object.keys(mudou).length === 0) return;
  const v = valoresFormConfig();
  const faixas = [['solo_ideal', 'umidade do solo'], ['temp_alerta', 'temperatura'], ['umid_alerta', 'umidade interna'], ['vpd_vegetativo', 'VPD da vegetação'], ['vpd_floracao', 'VPD da floração']];
  for (const [c, rotulo] of faixas) {
    const a = paraNumero(v[c + '_min']), b = paraNumero(v[c + '_max']);
    if (!isFinite(a) || !isFinite(b)) { mostrarToast(rotulo + ': informe números válidos', 'erro'); return; }
    if (a >= b) { mostrarToast(rotulo + ': o mínimo precisa ser menor que o máximo', 'erro'); return; }
  }
  const btn = document.getElementById('cfgSalvar');
  btn.disabled = true;
  try {
    const r = await fetch('configuracoes.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ config: mudou }) });
    if (r.status === 401) { mostrarToast('sessão expirada — redirecionando pro login...', 'erro'); setTimeout(() => { window.location.href = 'login.php'; }, 1200); return; }
    const d = await r.json();
    if (!d.ok) { mostrarToast('erro: ' + (d.detalhe || d.erro || 'desconhecido'), 'erro'); btn.disabled = false; return; }
    CONFIG = normalizarConfig(Object.assign({}, CONFIG, d.config));
    preencherFormConfig();
    aplicarConfiguracoes();
    carregarAoVivo().catch(() => {});
    if (ultimaLeitura) atualizarVPD(ultimaLeitura.temp_interna, ultimaLeitura.umid_interna);
    mostrarToast('✓ configurações salvas', 'ok');
  } catch (e) {
    mostrarToast('falha de rede: ' + e.message, 'erro');
    btn.disabled = false;
  }
});

/* ==================== Conta e usuários ==================== */
async function postJson(url, corpo) {
  const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(corpo) });
  if (r.status === 401) { window.location.href = 'login.php'; throw new Error('sessão expirada'); }
  let d = {};
  try { d = await r.json(); } catch (e) { throw new Error('resposta inválida do servidor (HTTP ' + r.status + ')'); }
  if (!r.ok || !d.ok) throw new Error(d.detalhe || d.erro || ('HTTP ' + r.status));
  return d;
}
function formatarDataHora(v) {
  if (!v) return '—';
  const p = String(v).split(/[- :]/);
  return p.length >= 5 ? p[2] + '/' + p[1] + '/' + p[0] + ' ' + p[3] + ':' + p[4] : v;
}

const painelSenha = document.getElementById('painelSenha');
document.getElementById('btnAbrirSenha').addEventListener('click', () => {
  painelSenha.hidden = !painelSenha.hidden;
  if (!painelSenha.hidden) painelSenha.elements.senha_atual.focus();
});
document.getElementById('btnCancelarSenha').addEventListener('click', () => { painelSenha.reset(); painelSenha.hidden = true; });
painelSenha.addEventListener('submit', async ev => {
  ev.preventDefault();
  const f = painelSenha.elements;
  if (f.nova_senha.value !== f.confirma.value) { mostrarToast('as senhas novas não coincidem', 'erro'); return; }
  try {
    await postJson('usuarios.php', { acao: 'alterar_senha', senha_atual: f.senha_atual.value, nova_senha: f.nova_senha.value });
    painelSenha.reset();
    painelSenha.hidden = true;
    mostrarToast('✓ senha alterada · os outros aparelhos foram desconectados', 'ok');
  } catch (e) {
    mostrarToast('erro: ' + e.message, 'erro');
  }
});

function renderizarConvite(convite) {
  const input = document.getElementById('conviteCodigo');
  if (!input) return;
  const ativo = !!convite.codigo;
  input.value = convite.codigo || '';
  input.disabled = convite.no_config;
  document.getElementById('btnGerarConvite').disabled = convite.no_config;
  document.getElementById('btnSalvarConvite').hidden = convite.no_config;
  const status = document.getElementById('conviteStatus');
  status.textContent = ativo ? 'cadastro ativo' : 'cadastro desativado';
  status.className = 'pill ' + (ativo ? 'pill-ok' : 'pill-neutro');
  if (convite.no_config) {
    document.getElementById('conviteInfo').textContent = 'Código definido no config.php (CADASTRO_CODIGO_CONVITE). Para mudar por aqui, remova essa linha do config.php.';
  }
}

function renderizarUsuarios(usuarios) {
  const lista = document.getElementById('listaUsuarios');
  if (!lista) return;
  if (!usuarios.some(u => !u.voce)) {
    lista.innerHTML = '<div class="cfg-linha"><div class="cfg-texto"><small>Nenhuma conta cadastrada ainda. Compartilhe o código de convite para alguém criar uma.</small></div></div>';
    return;
  }
  lista.innerHTML = usuarios.map(u =>
    '<div class="cfg-linha usuario-linha">' +
      '<div class="cfg-texto"><strong>' + escapeHtml(u.usuario) + (u.papel === 'admin' ? ' <span class="pill pill-auto" style="padding:2px 8px;">admin</span>' : '') + '</strong>' +
        '<small>' + (u.voce ? 'você · ' : '') + escapeHtml(u.email || (u.principal ? 'administrador principal (config.php)' : 'sem e-mail')) + ' · criado em ' + escapeHtml(formatarDataHora(u.criado_em)) + ' · último acesso ' + escapeHtml(formatarDataHora(u.ultimo_login)) + '</small></div>' +
      (u.voce ? '' : '<div class="cfg-controle">' +
        '<button type="button" class="btn btn-sec" data-acao-usuario="redefinir_senha" data-id="' + u.id + '" data-nome="' + escapeHtml(u.usuario) + '">Redefinir senha</button>' +
        (u.principal ? '' : '<button type="button" class="btn btn-sec btn-perigo" data-acao-usuario="remover" data-id="' + u.id + '" data-nome="' + escapeHtml(u.usuario) + '">Remover</button>') +
      '</div>') +
    '</div>'
  ).join('');
}

async function carregarUsuarios() {
  const d = await buscarJson('usuarios.php');
  if (!d.eu.conta_no_banco) {
    document.getElementById('senhaInfo').textContent = 'A senha do administrador principal é definida no config.php (WEB_LOGIN_SENHA_HASH).';
    document.getElementById('btnAbrirSenha').disabled = true;
  }
  if (d.admin) {
    renderizarConvite(d.convite);
    renderizarUsuarios(d.usuarios || []);
  }
}

const grupoUsuarios = document.getElementById('grupoUsuarios');
if (grupoUsuarios) {
  document.getElementById('btnGerarConvite').addEventListener('click', () => {
    const letras = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const n = new Uint32Array(8);
    crypto.getRandomValues(n);
    const c = Array.from(n, x => letras[x % letras.length]).join('');
    document.getElementById('conviteCodigo').value = c.slice(0, 4) + '-' + c.slice(4);
  });
  document.getElementById('btnCopiarConvite').addEventListener('click', async () => {
    const v = document.getElementById('conviteCodigo').value;
    if (!v) { mostrarToast('nenhum código para copiar', 'erro'); return; }
    try { await navigator.clipboard.writeText(v); mostrarToast('✓ código copiado', 'ok'); }
    catch (e) { mostrarToast('não foi possível copiar — selecione o texto', 'erro'); }
  });
  document.getElementById('btnSalvarConvite').addEventListener('click', async () => {
    try {
      const d = await postJson('usuarios.php', { acao: 'salvar_convite', codigo: document.getElementById('conviteCodigo').value.trim() });
      renderizarConvite(d.convite);
      mostrarToast(d.convite.codigo ? '✓ código salvo — cadastro ativo' : '✓ cadastro desativado', 'ok');
    } catch (e) { mostrarToast('erro: ' + e.message, 'erro'); }
  });

  // Ações destrutivas pedem um segundo clique em até 4 s.
  let confirmarTimeout = null;
  grupoUsuarios.addEventListener('click', async ev => {
    const btn = ev.target.closest('[data-acao-usuario]');
    if (!btn) return;
    if (!btn.classList.contains('confirmar')) {
      grupoUsuarios.querySelectorAll('.confirmar').forEach(b => { b.classList.remove('confirmar'); b.textContent = b.dataset.rotulo; });
      btn.dataset.rotulo = btn.textContent;
      btn.classList.add('confirmar');
      btn.textContent = btn.dataset.acaoUsuario === 'remover' ? 'Confirmar remoção' : 'Confirmar';
      clearTimeout(confirmarTimeout);
      confirmarTimeout = setTimeout(() => { btn.classList.remove('confirmar'); btn.textContent = btn.dataset.rotulo; }, 4000);
      return;
    }
    clearTimeout(confirmarTimeout);
    btn.disabled = true;
    try {
      const d = await postJson('usuarios.php', { acao: btn.dataset.acaoUsuario, id: Number(btn.dataset.id) });
      const caixa = document.getElementById('senhaTemporaria');
      if (d.senha_temporaria) {
        caixa.hidden = false;
        caixa.innerHTML = 'Senha temporária de <strong>' + escapeHtml(d.usuario) + '</strong>: <code>' + escapeHtml(d.senha_temporaria) + '</code><br>Passe para a pessoa; ela pode trocar em Configurações → Alterar senha. Esta senha não será mostrada de novo.';
      } else {
        caixa.hidden = true;
        mostrarToast('✓ conta ' + btn.dataset.nome + ' removida', 'ok');
      }
      await carregarUsuarios();
    } catch (e) {
      mostrarToast('erro: ' + e.message, 'erro');
      btn.disabled = false;
    }
  });
}

/* ==================== Status do dispositivo (online/offline) ==================== */
// Vem do ao_vivo.php; os segundos são do servidor e avançam aqui a cada segundo.
let STATUS = null;
let statusRecebidoEm = 0;
// Hora (no relógio do navegador) em que o ESP32 mandou o último estado ao vivo
// (estado_atual.php, a cada 5 s), calculada pela idade que o servidor informa.
let aoVivoEm = 0;
const AO_VIVO_RECENTE_MS = 30 * 1000;   // o ESP32 manda o estado a cada 5 s
const ESTADO_NOVO_SEG = 30;             // estado do servidor mais velho que isso não vai para os cards
const OFFLINE_RAPIDO_SEG = 90;          // estado parado + banco parado há 90 s = offline sem esperar o limite

function aoVivoRecente() { return aoVivoEm > 0 && Date.now() - aoVivoEm < AO_VIVO_RECENTE_MS; }
function segundosSemDadosServidor() {
  if (!STATUS || STATUS.segundos_sem_dados === null) return null;
  return STATUS.segundos_sem_dados + Math.round((Date.now() - statusRecebidoEm) / 1000);
}
// Tempo desde o dado mais novo: o do banco (1 por minuto) ou o estado ao vivo (a cada 5 s).
function segundosSemDados() {
  const servidor = segundosSemDadosServidor();
  if (!aoVivoEm) return servidor;
  const aoVivo = Math.round((Date.now() - aoVivoEm) / 1000);
  return servidor === null ? aoVivo : Math.min(servidor, aoVivo);
}
function dispositivoOffline() {
  if (!STATUS) return true;
  const servidor = segundosSemDadosServidor();
  // O ESP32 manda estado a cada 5 s: se ele parou há 90 s e o banco também, já está offline.
  if (aoVivoEm && Date.now() - aoVivoEm > OFFLINE_RAPIDO_SEG * 1000 && (servidor === null || servidor > OFFLINE_RAPIDO_SEG)) return true;
  const seg = segundosSemDados();
  return seg === null || seg > STATUS.limite_min * 60;
}
function textoHa(seg) {
  if (seg < 60) return seg + ' s';
  if (seg < 3600) return Math.floor(seg / 60) + ' min';
  if (seg < 86400) return Math.floor(seg / 3600) + 'h ' + pad2(Math.floor((seg % 3600) / 60)) + 'min';
  return Math.floor(seg / 86400) + 'd ' + Math.floor((seg % 86400) / 3600) + 'h';
}
function horaDe(ts) { return ts ? String(ts).substring(11, 16) : '--:--'; }
function qualidadeSinal(rssi) {
  if (rssi === null || rssi === undefined) return '--';
  const q = rssi >= -55 ? 'excelente' : rssi >= -67 ? 'bom' : rssi >= -75 ? 'regular' : 'fraco';
  return String(rssi).replace('-', '−') + ' dBm · ' + q;
}
function formatarUptime(seg) {
  if (seg === null || seg === undefined) return '--';
  const d = Math.floor(seg / 86400), h = Math.floor((seg % 86400) / 3600), m = Math.floor((seg % 3600) / 60);
  return (d ? d + 'd ' : '') + (d || h ? h + 'h ' : '') + m + 'min';
}

/* ==================== Estado ao vivo (estado_atual.php) ==================== */
// Estado ao vivo nos cards. em: quando o ESP32 mandou (relógio do navegador).
function aplicarEstadoAoVivo(e, em) {
  // Nunca troca um estado mais novo por um mais velho (duas respostas podem chegar fora de ordem).
  if (em < aoVivoEm) return;
  // Só os campos da leitura; o resto da última leitura do banco fica como está.
  const campos = ['dispositivo_timestamp', 'temp_interna', 'umid_interna', 'temp_externa', 'umid_externa', 'vpd',
    'solo_percentual', 'agua_distancia_cm', 'agua_litros', 'agua_percentual'];
  const leitura = Object.assign({}, ultimaLeitura || {});
  campos.forEach(c => { if (c in e) leitura[c] = e[c]; });
  // O firmware só manda null depois de 3 falhas seguidas (mesmo critério do banco).
  soloSemLeitura = e.solo_percentual === null;
  aguaSemLeitura = e.agua_litros === null && leituras24h.some(l => l.agua_litros !== null && l.agua_litros !== undefined);
  aoVivoEm = em;
  aplicarLeituraNosCards(leitura);
  if (abaAtual === 'caixa') renderizarCaixa();

  // Relés e fase: o ao_vivo.php já avisa pelo id do último evento, na mesma resposta.
  renderizarStatus();
  atualizarRodape();
}

function renderizarStatus() {
  if (!STATUS) return;
  const seg = segundosSemDados();
  const off = dispositivoOffline();
  const selo = document.getElementById('seloConexao');
  selo.classList.toggle('off', off);
  selo.querySelector('span').textContent = seg === null ? 'Offline · sem leituras ainda'
    : off ? 'Offline · sem dados há ' + textoHa(seg)
    : aoVivoRecente() ? 'Online · ao vivo · há ' + textoHa(seg) : 'Online · leitura há ' + textoHa(seg);

  const aba = document.getElementById('aba-inicio');
  const mudou = aba.classList.contains('offline') !== off;
  aba.classList.toggle('offline', off);
  document.getElementById('avisoOffline').hidden = !off;
  if (off) {
    document.getElementById('avisoOfflineTexto').textContent = 'Última leitura às ' + horaDe(STATUS.ultima_leitura) +
      '. Verifique a energia e o Wi-Fi do dispositivo. Os valores abaixo são os últimos recebidos.' +
      (STATUS.aviso_offline_em ? ' Você recebeu um aviso no Telegram às ' + horaDe(STATUS.aviso_offline_em) + '.' : '');
    ['legTempInterna', 'legUmidInterna', 'legTempExterna', 'legUmidExterna'].forEach(id => {
      document.getElementById(id).textContent = 'às ' + horaDe(STATUS.ultima_leitura);
    });
  } else if (mudou && ultimaLeitura) {
    aplicarAlertasCards(ultimaLeitura);
    ['legTempExterna', 'legUmidExterna'].forEach(id => { document.getElementById(id).textContent = 'Ambiente externo'; });
  }

  // Página Dispositivo
  const d = STATUS.dispositivo || {};
  const ds = document.getElementById('dispStatus');
  ds.classList.toggle('off', off);
  ds.querySelector('span').textContent = off ? 'Offline' : 'Online';
  document.getElementById('dispCard').classList.toggle('off', off);
  document.getElementById('dispNome').textContent = d.nome || 'Estufa principal';
  document.getElementById('dispModelo').textContent = d.modelo || 'ESP32-S3';
  document.getElementById('dispMac').textContent = d.mac ? 'MAC ' + d.mac : '';
  document.getElementById('dispFirmware').textContent = d.firmware_versao ? 'v' + d.firmware_versao : 'não informado';
  const segContato = d.segundos_desde_contato !== null && d.segundos_desde_contato !== undefined
    ? Number(d.segundos_desde_contato) + Math.round((Date.now() - statusRecebidoEm) / 1000) : seg;
  document.getElementById('dispContato').textContent = segContato === null ? '--' : 'há ' + textoHa(segContato);
  document.getElementById('dispSinal').textContent = qualidadeSinal(d.wifi_rssi === null || d.wifi_rssi === undefined ? null : Number(d.wifi_rssi));
  document.getElementById('dispUptime').textContent = formatarUptime(d.uptime_seg === null || d.uptime_seg === undefined ? null : Number(d.uptime_seg) + (off ? 0 : Math.round((Date.now() - statusRecebidoEm) / 1000)));
  document.getElementById('dispRodape').textContent = (d.firmware_versao ? '' : 'Firmware antigo: versão, sinal e tempo ligado aparecem após atualizar o ESP32. ') +
    'Enviando leituras a cada 1 min' + (aoVivoRecente() ? ' · estado ao vivo a cada 5 s' : '') + ' · ' +
    Number(STATUS.leituras_24h).toLocaleString('pt-BR') + ' recebidas nas últimas 24h';
}

function atualizarSeloFase() {
  const el = document.getElementById('seloFase');
  if (!el) return;
  const fase = ultimoModoAtual === 'floracao' ? 'floracao' : 'vegetativo';
  const dia = CICLO && (CICLO.fase_pedida || CICLO.fase) === fase && CICLO.dia ? ' · dia ' + CICLO.dia : '';
  el.querySelector('span').textContent = NOME_MODO[fase] + dia;
}

/* ==================== Ciclo principal ==================== */
function atualizarRodape() {
  const el = document.getElementById('statusLinha');
  if (!el || !ultimaAtualizacaoEm) return;
  renderizarStatus();
  if (STATUS && dispositivoOffline()) {
    el.classList.add('offline');
    const seg = segundosSemDados();
    el.textContent = seg === null ? 'nenhuma leitura recebida ainda'
      : 'sem dados há ' + textoHa(seg) + ' · última leitura ' + String(STATUS.ultima_leitura).substring(11, 19);
    return;
  }
  el.classList.remove('offline');
  const seg = Math.max(0, Math.round((Date.now() - ultimaAtualizacaoEm) / 1000));
  const ha = seg < 60 ? 'há ' + seg + ' s' : 'há ' + Math.floor(seg / 60) + ' min';
  el.textContent = (ultimoErroAtualizacao ? 'falha ao atualizar (' + ultimoErroAtualizacao + ') · ' : '') +
    'atualizado ' + new Date(ultimaAtualizacaoEm).toLocaleTimeString('pt-BR') + ' · ' + ha;
}
function armarAtualizacao() {
  if (intervaloAtualizacaoId) clearInterval(intervaloAtualizacaoId);
  intervaloAtualizacaoId = null;
  if (document.visibilityState === 'hidden' || !intervaloAtualizacaoSeg) return;
  intervaloAtualizacaoId = setInterval(atualizarLeve, intervaloAtualizacaoSeg * 1000);
}
// Aba em segundo plano: pausa a busca; ao voltar, atualiza se estiver vencido e retoma.
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'hidden') { armarAtualizacao(); return; }
  if (!ultimaAtualizacaoEm || Date.now() - ultimaAtualizacaoEm >= intervaloAtualizacaoSeg * 1000) atualizarLeve();
  armarAtualizacao();
});

/* Atualização em dois níveis:
   - leve (a cada "Atualização automática"): ao_vivo.php, poucas centenas de bytes. Só busca
     leituras novas (dados.php?desde=) ou eventos (comandos.php) quando os ids mostram que mudaram;
   - completa (ao abrir, depois de ações e a cada 5 min): recarrega tudo, inclusive configurações
     e os gráficos de 7 e 30 dias. */
const INTERVALO_COMPLETA_MS = 5 * 60 * 1000;
let ultimaCompletaEm = 0;
let ultimoIdEvento = null;
let atualizandoLeve = false;

async function carregarAoVivo() {
  const d = await buscarJson('ao_vivo.php');
  STATUS = d.status;
  statusRecebidoEm = Date.now();
  if (d.estado && d.estado.dados) {
    const seg = Math.max(0, Number(d.estado.segundos) || 0);
    const em = Date.now() - seg * 1000;
    if (seg <= ESTADO_NOVO_SEG) aplicarEstadoAoVivo(d.estado.dados, em);
    // Estado parado: o servidor é a referência de quando ele chegou (serve para o aviso de offline).
    else aoVivoEm = em;
  }
  renderizarStatus();
  return d;
}

async function atualizarLeve() {
  if (!ultimaCompletaEm || Date.now() - ultimaCompletaEm >= INTERVALO_COMPLETA_MS) return atualizarTudo();
  if (atualizandoLeve) return;
  atualizandoLeve = true;
  const erros = [];
  try {
    const d = await carregarAoVivo();
    if ((Number(d.ultimo_id_leitura) || 0) > ultimoIdLeitura) {
      try { await carregarLeiturasNovas(); } catch (e) { erros.push('leituras: ' + e.message); }
    }
    const idEvento = Number(d.ultimo_id_evento) || 0;
    if (ultimoIdEvento !== null && idEvento !== ultimoIdEvento) {
      try { await carregarRodaEventos(); } catch (e) { erros.push('roda de eventos: ' + e.message); }
      if (abaAtual === 'dispositivo') { try { await carregarDispositivo(); } catch (e) { erros.push('dispositivo: ' + e.message); } }
    }
    ultimoIdEvento = idEvento;
  } catch (e) {
    erros.push('ao vivo: ' + e.message);
  } finally {
    atualizandoLeve = false;
  }
  ultimaAtualizacaoEm = Date.now();
  ultimoErroAtualizacao = erros.join(' | ');
  atualizarRodape();
}

async function atualizarTudo() {
  const erros = [];
  ultimaCompletaEm = Date.now();
  try { await carregarConfiguracoes(); } catch (e) { erros.push('configura\u00e7\u00f5es: ' + e.message); }
  try { ultimoIdEvento = Number((await carregarAoVivo()).ultimo_id_evento) || 0; } catch (e) { erros.push('status: ' + e.message); }
  try { await carregarResumo(); } catch (e) { erros.push('resumo: ' + e.message); }
  try { await carregarGraficos(periodoAtual); } catch (e) { erros.push('graficos: ' + e.message); }
  if (abaAtual === 'dispositivo') { try { await carregarDispositivo(); } catch (e) { erros.push('dispositivo: ' + e.message); } }
  try { await carregarRodaEventos(); } catch (e) {
    erros.push('roda de eventos: ' + e.message);
    const listaRoda = document.getElementById('rrLista');
    if (listaRoda) listaRoda.innerHTML = '<div class="rr-vazio">erro ao carregar eventos (' + escapeHtml(e.message) + ')</div>';
  }
  ultimaAtualizacaoEm = Date.now();
  ultimoErroAtualizacao = erros.join(' | ');
  if (ultimaLeitura) atualizarVPD(ultimaLeitura.temp_interna, ultimaLeitura.umid_interna);
  atualizarSeloFase();
  atualizarRodape();
}

/* ==================== Luz: duração do modo manual ==================== */
function renderizarLuzManual() {
  const sel = document.getElementById('luzManualMin');
  if (!sel) return;
  const desejado = String(CONFIG.luz_manual_min || '30');
  if (document.activeElement !== sel) sel.value = desejado;
  document.getElementById('notaManualMin').textContent = desejado + ' min';
  const st = document.getElementById('luzManualStatus');
  if (!LUZ_DURACAO_ESP) st.textContent = 'O ESP32 ainda não confirmou (firmware anterior à v2.4.0 usa 30 min fixos).';
  else if (String(LUZ_DURACAO_ESP.minutos) === desejado) st.textContent = '✓ Aplicado no ESP32 às ' + String(LUZ_DURACAO_ESP.quando).substring(11, 16) + '.';
  else st.textContent = 'Aguardando o ESP32 aplicar (ele usa ' + LUZ_DURACAO_ESP.minutos + ' min agora).';
}
document.getElementById('luzManualMin').addEventListener('change', async ev => {
  const sel = ev.target, valor = sel.value, anterior = String(CONFIG.luz_manual_min || '30');
  sel.disabled = true;
  try {
    const r = await fetch('configuracoes.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ config: { luz_manual_min: valor } }) });
    const d = await r.json();
    if (!d.ok) throw new Error(d.detalhe || d.erro || 'erro');
    CONFIG.luz_manual_min = valor;
    mostrarToast('alteração salva · enviada ao ESP32', 'ok');
    [6000, 15000].forEach(ms => setTimeout(() => carregarConfiguracoes().catch(() => {}), ms));
  } catch (e) {
    sel.value = anterior;
    mostrarToast('erro: ' + e.message, 'erro');
  } finally {
    sel.disabled = false;
    renderizarLuzManual();
  }
});

/* ==================== Dispositivo: token e vinculação ==================== */
let DISP = null;
let tokenRecemGerado = null;   // token completo, só até sair da página
let vincCodigoAtual = null;    // token de vinculação, só até sair da página
let vincPoll = null;

function dataHoraCurta(ts) {
  if (!ts) return '--';
  const t = String(ts);
  return t.substring(8, 10) + '/' + t.substring(5, 7) + '/' + t.substring(0, 4) + ' às ' + t.substring(11, 16);
}
function horaOuData(ts) {
  if (!ts) return '';
  const t = String(ts), hoje = new Date();
  const iso = hoje.getFullYear() + '-' + pad2(hoje.getMonth() + 1) + '-' + pad2(hoje.getDate());
  return t.substring(0, 10) === iso ? t.substring(11, 16) : t.substring(8, 10) + '/' + t.substring(5, 7);
}
function haQuanto(ts) {
  if (!ts) return null;
  const seg = Math.max(0, Math.round((Date.now() - new Date(String(ts).replace(' ', 'T')).getTime()) / 1000));
  return 'há ' + textoHa(seg);
}

async function carregarDispositivo() {
  const antes = DISP;
  DISP = await buscarJson('dispositivo.php');
  if (antes && antes.token.vinculo_expira && !DISP.token.vinculo_expira && DISP.token.estado === 'ativo' &&
      DISP.atividade.some(a => a.titulo === 'Dispositivo vinculado')) {
    vincCodigoAtual = null;
    mostrarToast('\u2713 dispositivo vinculado', 'ok');
  }
  renderizarDispositivo();
}

function renderizarDispositivo() {
  if (!DISP) return;
  const tk = DISP.token;
  const caixa = document.getElementById('tokCaixa');
  const valor = document.getElementById('tokValor');
  const meta = document.getElementById('tokMeta');
  const copiar = document.getElementById('btnTokCopiar');
  caixa.classList.toggle('novo', !!tokenRecemGerado);
  copiar.hidden = !tokenRecemGerado;
  document.getElementById('btnTokRevogar').hidden = tk.estado === 'revogado';
  if (tk.estado === 'ativo') {
    document.getElementById('tokTitulo').textContent = 'Token ativo';
    valor.textContent = tokenRecemGerado || ('sd_hw_' + '\u2022'.repeat(16) + (tk.final || ''));
    const uso = haQuanto(tk.ultimo_uso);
    meta.textContent = tokenRecemGerado ? 'Copie agora e cole no portal do ESP32 (192.168.4.1): o token completo não aparece de novo.'
      : 'Gerado em ' + dataHoraCurta(tk.criado_em) + ' · ' + (uso ? 'último uso ' + uso : 'ainda não usado pelo ESP32');
  } else if (tk.estado === 'revogado') {
    document.getElementById('tokTitulo').textContent = 'Token revogado';
    valor.textContent = '\u2014';
    meta.textContent = 'Nenhum dispositivo consegue enviar dados. Gere um novo token ou vincule um dispositivo.';
  } else {
    document.getElementById('tokTitulo').textContent = 'Usando a API_KEY do config.php';
    valor.textContent = 'API_KEY (modo compatível)';
    meta.textContent = 'O ESP32 usa a chave do config.php. Gere um token ou vincule o dispositivo para ele ter um código próprio; a partir daí a API_KEY deixa de ser aceita.';
  }
  const admin = !!DISP.admin;
  document.getElementById('tokAcoes').hidden = !admin;
  document.getElementById('btnVincGerar').hidden = !admin;
  document.getElementById('tokAviso').textContent = admin
    ? 'Ao gerar um novo token ou revogar o atual, o ESP32 para de enviar dados até ser configurado com o novo código.'
    : 'Só administradores podem gerar ou revogar o token.';

  const box = document.getElementById('vincCodigo');
  const btnV = document.getElementById('btnVincGerar');
  if (tk.vinculo_expira) {
    box.hidden = false;
    document.getElementById('vincValor').textContent = vincCodigoAtual || '\u2022\u2022\u2022\u2022-\u2022\u2022\u2022\u2022';
    document.getElementById('vincExpira').textContent = vincCodigoAtual
      ? 'Uso único · válido até ' + String(tk.vinculo_expira).substring(11, 16)
      : 'Token ativo até ' + String(tk.vinculo_expira).substring(11, 16) + '. Se não anotou, gere outro.';
    btnV.innerHTML = '<svg data-lucide="refresh-cw"></svg>Gerar outro token';
  } else {
    box.hidden = true;
    vincCodigoAtual = null;
    btnV.innerHTML = '<svg data-lucide="plus"></svg>Gerar token de vinculação';
  }
  // Enquanto há vinculação pendente, confere a cada 5 s se o ESP32 já usou o token.
  if (tk.vinculo_expira && !vincPoll) {
    vincPoll = setInterval(() => { if (abaAtual === 'dispositivo' && document.visibilityState === 'visible') carregarDispositivo().catch(() => {}); }, 5000);
  } else if (!tk.vinculo_expira && vincPoll) { clearInterval(vincPoll); vincPoll = null; }

  const lista = document.getElementById('listaAtividade');
  lista.innerHTML = DISP.atividade.length ? DISP.atividade.map(a =>
    '<li class="' + escapeHtml(a.cor) + '"><i></i><div><strong>' + escapeHtml(a.titulo) + '</strong><small>' + escapeHtml(a.detalhe) + '</small></div>' +
    '<time>' + escapeHtml(horaOuData(a.quando)) + '</time></li>').join('')
    : '<li class="vazio">Nenhum evento do dispositivo ainda.</li>';
  if (window.lucide) lucide.createIcons();
}

async function postDispositivo(acao, botao) {
  const rotulo = botao.innerHTML;
  botao.disabled = true; botao.innerHTML = '<span class="cmd-btn-spinner"></span>enviando';
  try {
    const r = await fetch('dispositivo.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ acao }) });
    if (r.status === 401) { window.location.href = 'login.php'; return null; }
    const d = await r.json();
    if (!d.ok) { mostrarToast(d.detalhe || ('erro: ' + (d.erro || 'desconhecido')), 'erro'); return null; }
    DISP = d;
    return d;
  } catch (e) {
    mostrarToast('falha de rede: ' + e.message, 'erro');
    return null;
  } finally {
    botao.disabled = false; botao.innerHTML = rotulo; botao.classList.remove('confirmar');
    if (window.lucide) lucide.createIcons();
  }
}

// Gerar novo / Revogar pedem um segundo clique.
function pedirConfirmacao(botao, texto) {
  if (botao.classList.contains('confirmar')) return true;
  const original = botao.innerHTML;
  botao.classList.add('confirmar');
  botao.innerHTML = texto;
  setTimeout(() => { if (botao.classList.contains('confirmar')) { botao.classList.remove('confirmar'); botao.innerHTML = original; if (window.lucide) lucide.createIcons(); } }, 5000);
  return false;
}

document.getElementById('btnTokGerar').addEventListener('click', async ev => {
  const b = ev.currentTarget;
  if (!pedirConfirmacao(b, 'Confirmar: gerar novo')) return;
  const d = await postDispositivo('gerar', b);
  if (d) { tokenRecemGerado = d.token_completo; b.innerHTML = '<svg data-lucide="refresh-cw"></svg>Gerar novo'; renderizarDispositivo(); mostrarToast('novo token gerado \u2014 copie agora', 'ok'); }
});
document.getElementById('btnTokRevogar').addEventListener('click', async ev => {
  const b = ev.currentTarget;
  if (!pedirConfirmacao(b, 'Confirmar revogação')) return;
  const d = await postDispositivo('revogar', b);
  if (d) { tokenRecemGerado = null; b.innerHTML = '<svg data-lucide="ban"></svg>Revogar'; renderizarDispositivo(); mostrarToast('token revogado', 'ok'); }
});
document.getElementById('btnTokCopiar').addEventListener('click', async () => {
  try { await navigator.clipboard.writeText(tokenRecemGerado); mostrarToast('\u2713 token copiado', 'ok'); }
  catch (e) { mostrarToast('não foi possível copiar; selecione o texto', 'erro'); }
});
document.getElementById('btnVincGerar').addEventListener('click', async ev => {
  const d = await postDispositivo('vincular', ev.currentTarget);
  if (d) { vincCodigoAtual = d.codigo; renderizarDispositivo(); }
});

/* ==================== Notificações (Telegram) ==================== */
let NOTIF = null;
let tgVerificando = null;

async function postNotif(corpo, botao) {
  const rotulo = botao ? botao.innerHTML : '';
  if (botao) { botao.disabled = true; botao.innerHTML = '<span class="cmd-btn-spinner"></span>enviando'; }
  try {
    const r = await fetch('notificacoes.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(corpo) });
    if (r.status === 401) { window.location.href = 'login.php'; return null; }
    const d = await r.json();
    if (!d.ok) { mostrarToast(d.detalhe || ('erro: ' + (d.erro || 'desconhecido')), 'erro'); return null; }
    NOTIF = d;
    renderizarNotificacoes();
    return d;
  } catch (e) {
    mostrarToast('falha de rede: ' + e.message, 'erro');
    return null;
  } finally {
    if (botao && document.body.contains(botao)) { botao.disabled = false; if (botao.innerHTML.indexOf('cmd-btn-spinner') !== -1) botao.innerHTML = rotulo; }
  }
}

async function carregarNotificacoes() {
  NOTIF = await buscarJson('notificacoes.php');
  renderizarNotificacoes();
}

function renderizarNotificacoes() {
  if (!NOTIF) return;
  const st = document.getElementById('tgStatus');
  const info = document.getElementById('tgInfo');
  const btn = document.getElementById('btnTgAcao');
  const painel = document.getElementById('tgVinculo');
  st.classList.toggle('ok', !!NOTIF.conectado);
  painel.hidden = true;
  btn.hidden = false;
  if (!NOTIF.configurado) {
    st.querySelector('span').textContent = 'Bot não configurado';
    info.textContent = 'Defina TELEGRAM_BOT_TOKEN no config.php do servidor para ativar os avisos.';
    btn.hidden = true;
  } else if (NOTIF.conectado) {
    st.querySelector('span').textContent = 'Conectado · ' + (NOTIF.chat_nome || 'chat');
    info.innerHTML = 'Os avisos chegam pelo bot' + (NOTIF.bot_usuario ? ' @' + escapeHtml(NOTIF.bot_usuario) : '') + '. <button type="button" class="link-acao" id="btnTgDesconectar">Desconectar</button>';
    btn.textContent = 'Enviar teste';
    btn.dataset.acao = 'teste';
  } else if (NOTIF.vinculo) {
    st.querySelector('span').textContent = 'Aguardando o /start no Telegram';
    info.textContent = 'O link vale até ' + String(NOTIF.vinculo.expira_em).substring(11, 16) + '.';
    btn.hidden = true;
    painel.hidden = false;
    document.getElementById('tgLink').href = NOTIF.vinculo.link;
  } else {
    st.querySelector('span').textContent = 'Não conectado';
    info.textContent = 'Os avisos chegam pelo bot do Smart Demetra.';
    btn.textContent = 'Conectar';
    btn.dataset.acao = 'vincular';
  }
  document.querySelectorAll('.switch[data-notif]').forEach(sw => {
    sw.setAttribute('aria-checked', String(!!NOTIF.preferencias[sw.dataset.notif]));
  });
  // Enquanto o link está aberto, confere a cada 4 s se o chat mandou o /start.
  if (NOTIF.vinculo && !NOTIF.conectado && !tgVerificando) {
    tgVerificando = setInterval(async () => {
      if (document.visibilityState !== 'visible' || abaAtual !== 'configuracoes') return;
      const d = await postNotif({ acao: 'verificar' });
      if (d && d.conectado) mostrarToast('\u2713 Telegram conectado', 'ok');
    }, 4000);
  }
  if ((!NOTIF.vinculo || NOTIF.conectado) && tgVerificando) { clearInterval(tgVerificando); tgVerificando = null; }
}

document.getElementById('grupoNotif').addEventListener('click', async ev => {
  const sw = ev.target.closest('.switch[data-notif]');
  if (sw) {
    const ligado = sw.getAttribute('aria-checked') !== 'true';
    sw.setAttribute('aria-checked', String(ligado));
    sw.disabled = true;
    const d = await postNotif({ acao: 'preferencia', chave: sw.dataset.notif, ligado });
    sw.disabled = false;
    if (d) mostrarToast('alteração salva', 'ok'); else sw.setAttribute('aria-checked', String(!ligado));
    return;
  }
  if (ev.target.closest('#btnTgAcao')) {
    const btn = ev.target.closest('#btnTgAcao');
    const d = await postNotif({ acao: btn.dataset.acao }, btn);
    if (d && btn.dataset.acao === 'teste') mostrarToast('\u2713 mensagem de teste enviada', 'ok');
    return;
  }
  if (ev.target.closest('#btnTgCancelar')) {
    NOTIF.vinculo = null;
    renderizarNotificacoes();
    return;
  }
  const desc = ev.target.closest('#btnTgDesconectar');
  if (desc) {
    if (!desc.classList.contains('confirmar')) { desc.classList.add('confirmar'); desc.textContent = 'Confirmar desconexão'; return; }
    const d = await postNotif({ acao: 'desconectar' });
    if (d) mostrarToast('Telegram desconectado', 'ok');
  }
});

// Carrega as configurações antes de abrir a página e buscar os dados.
(async function iniciar() {
  try { await carregarConfiguracoes(); } catch (e) { aplicarConfiguracoes(); }
  periodoAtual = ['24h', '7d', '30d'].includes(CONFIG.periodo_padrao_historico) ? CONFIG.periodo_padrao_historico : '24h';
  marcarPeriodo(periodoAtual);
  const hash = location.hash.replace('#', '').replace(/^automacao$/, 'fotoperiodo');
  mudarAba(ABAS_VALIDAS.includes(hash) ? hash : CONFIG.pagina_inicial);
  carregarUsuarios().catch(e => { console.warn('usuarios:', e.message); });
  carregarNotificacoes().catch(e => { console.warn('notificacoes:', e.message); });
  atualizarTudo();
})();
