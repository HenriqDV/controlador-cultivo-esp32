/* Estufa da tela Início (index.php + partials/estufa.php).
   Lê o estado que o app.js já mantém (relés, leitura, fase, configurações) e só troca atributos
   data-* da estufa; as animações e imagens ficam no app.css. Carrega depois do app.js. */
(function () {
  const raiz = document.getElementById('estufa');
  if (!raiz) return;
  const escala = document.getElementById('estufaEscala');
  const palco = document.getElementById('estufaPalco');
  const sino = document.getElementById('btnSino');
  const sinoNum = document.getElementById('sinoNum');
  const modal = document.getElementById('modalAtencao');
  const btnFechar = document.getElementById('btnFecharAtencao');

  const ESTAGIOS = { germinacao: 'Germinação', muda: 'Muda', vegetativo: 'Vegetação', floracao: 'Floração' };
  // Faixa de VPD de cada estágio: índice de FASES_CLIMA (0 muda, 1 vegetação, 2 floração).
  const INDICE_VPD = { germinacao: 0, muda: 0, vegetativo: 1, floracao: 2 };

  /* ---------- Escala: o palco tem 390 px de largura e acompanha o espaço disponível ---------- */
  function ajustarEscala() {
    const w = escala.clientWidth;
    if (w > 0) raiz.style.setProperty('--eg-s', (w / 390).toFixed(4));
  }
  ajustarEscala();
  if (window.ResizeObserver) new ResizeObserver(ajustarEscala).observe(escala);
  else window.addEventListener('resize', ajustarEscala);

  /* ---------- Estágio do cultivo ---------- */
  // Fase vinda do calendário de manejo (manejo.js): a que contém o dia de hoje, com o "dia N" dela.
  // Sem fase marcada para hoje, vale o modo de luz do ESP32 (vegetação ou floração).
  function estagioAtual() {
    const floracaoNoEsp = typeof ultimoModoAtual !== 'undefined' && ultimoModoAtual === 'floracao';
    const cal = typeof window.manejoFaseHoje === 'function' ? window.manejoFaseHoje() : null;
    // Floração já ligada no ESP32 vale sempre sobre uma fase anterior: o que de fato controla a luz não pode ser contrariado pela data.
    if (cal && !(floracaoNoEsp && cal.id !== 'floracao')) return { id: cal.id, dia: cal.dia, calculada: true };
    return { id: floracaoNoEsp ? 'floracao' : 'vegetativo', dia: null, calculada: false };
  }
  function diaDoEstagio(est) {
    if (est.dia) return est.dia;
    if (typeof CICLO !== 'undefined' && CICLO && (CICLO.fase_pedida || CICLO.fase) === est.id && CICLO.dia) return CICLO.dia;
    return null;
  }
  // Texto do selo "Fase" quando a fase vem do calendário (senão o app.js usa o texto padrão ao receber null).
  window.estagioRotuloSelo = function () {
    const est = estagioAtual();
    return est.calculada ? ESTAGIOS[est.id] + ' · dia ' + est.dia : null;
  };

  /* ---------- Estado dos aparelhos ---------- */
  function reles() { return (typeof relesEstado === 'object' && relesEstado) || {}; }
  function ligado(nome) { return (reles()[nome] || {}).valor === 'ligado'; }
  function luzLigada() {
    const r = reles().Luz;
    if (r) return r.valor === 'ligado';
    try { return !!estadoEDuracaoLuz(modoAtualParaLuz).ligada; } catch (e) { return false; }
  }
  const onOff = v => (v ? 'on' : 'off');

  /* ---------- Leituras sobrepostas ---------- */
  function num(v) { const n = Number(v); return v === null || v === undefined || v === '' || !isFinite(n) ? null : n; }
  function escrever(idValor, idFaixa, valor, texto, faixaTxt, fora) {
    const v = document.getElementById(idValor), f = document.getElementById(idFaixa);
    if (!v || !f) return;
    v.textContent = valor === null ? '--' : texto;
    f.textContent = faixaTxt;
    v.closest('.eg-leitura').classList.toggle('fora', !!fora && valor !== null);
  }
  function atualizarLeituras(est) {
    const l = (typeof ultimaLeitura !== 'undefined' && ultimaLeitura) || {};
    const c = CONFIG || {};
    const t = num(l.temp_interna), u = num(l.umid_interna), vpd = num(l.vpd), solo = num(l.solo_percentual);
    const tMin = Number(c.temp_alerta_min), tMax = Number(c.temp_alerta_max);
    const uMin = Number(c.umid_alerta_min), uMax = Number(c.umid_alerta_max);
    const sMin = Number(c.solo_ideal_min), sMax = Number(c.solo_ideal_max);
    const fx = (typeof faixaDaFase === 'function') ? faixaDaFase(INDICE_VPD[est.id]) : { min: 0, max: 0 };
    const fora = (v, a, b) => v !== null && isFinite(a) && isFinite(b) && (v < a || v > b);
    escrever('egTemp', 'egTempFx', t, brN(t, 1) + '°C', tMin + '–' + tMax + ' °C', fora(t, tMin, tMax));
    escrever('egUmid', 'egUmidFx', u, Math.round(u) + '%', uMin + '–' + uMax + '%', fora(u, uMin, uMax));
    escrever('egVpd', 'egVpdFx', vpd, brN(vpd, 2), fmtKpa(fx.min) + '–' + fmtKpa(fx.max) + ' kPa', fora(vpd, fx.min, fx.max));
    escrever('egSolo', 'egSoloFx', solo, Math.round(solo) + '%', sMin + '–' + sMax + '%', fora(solo, sMin, sMax));
  }

  /* ---------- Atualização da cena ---------- */
  function atualizar() {
    const est = estagioAtual();
    // Sem comunicação com o ESP32 não dá para afirmar o que está ligado: nada gira nem acende, a cena
    // perde a cor e aparece o aviso "Sem dados". (Antes da primeira resposta do servidor também fica assim.)
    const offline = typeof dispositivoOffline === 'function' && dispositivoOffline();
    const d = offline
      ? { luz: false, ventilador: false, entrada: false, saida: false, umidificador: false, aquecedor: false }
      : {
        luz: luzLigada(), ventilador: ligado('Ventilador'), entrada: ligado('Entrada de Ar'), saida: ligado('Saida de Ar'),
        umidificador: ligado('Umidificador'), aquecedor: ligado('Aquecedor'),
      };
    raiz.dataset.estagio = est.id;
    Object.keys(d).forEach(k => { raiz.dataset[k] = onOff(d[k]); });
    raiz.dataset.offline = offline ? '1' : '0';
    const aviso = document.getElementById('egSemDados');
    if (aviso) {
      aviso.hidden = !offline;
      if (offline) {
        let seg = null;
        try { seg = typeof segundosSemDados === 'function' ? segundosSemDados() : null; } catch (e) { seg = null; }
        document.getElementById('egSemDadosTxt').textContent = (typeof STATUS !== 'undefined' && STATUS && seg !== null && typeof textoHa === 'function')
          ? 'Sem dados do ESP32 ' + textoHa(seg) : 'Sem dados do ESP32';
      }
    }

    const dia = diaDoEstagio(est);
    raiz.querySelectorAll('.eg-chip').forEach(chip => {
      const atual = chip.dataset.estagio === est.id;
      chip.classList.toggle('atual', atual);
      if (atual) chip.setAttribute('aria-current', 'true'); else chip.removeAttribute('aria-current');
      const pequeno = chip.querySelector('small');
      if (pequeno) pequeno.textContent = atual ? (dia ? 'dia ' + dia : 'atual') : ' ';
    });

    const txt = v => (v ? 'ligado' : 'desligado');
    palco.setAttribute('aria-label', 'Estufa na fase ' + ESTAGIOS[est.id].toLowerCase() + (dia ? ', dia ' + dia : '') + '. Luz ' + (d.luz ? 'acesa' : 'apagada') +
      '. Ventilador ' + txt(d.ventilador) + '. Exaustor de entrada ' + txt(d.entrada) + '. Exaustor de saída ' + txt(d.saida) +
      '. Umidificador ' + txt(d.umidificador) + '. Aquecedor ' + txt(d.aquecedor) + '.' + (offline ? ' Sem comunicação com o ESP32: estado dos aparelhos desconhecido.' : ''));
    atualizarLeituras(est);
  }
  atualizar();
  setInterval(() => { if (!document.hidden && typeof abaAtual !== 'undefined' && abaAtual === 'inicio') { try { atualizar(); } catch (e) { console.warn('estufa:', e); } } }, 2000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { ajustarEscala(); try { atualizar(); } catch (e) { /* ignora */ } } });

  /* ---------- Poupar bateria: animações paradas com a aba oculta ou a cena fora da tela ---------- */
  let naTela = true;
  function aplicarPausa() { raiz.dataset.pausado = (document.hidden || !naTela) ? '1' : '0'; }
  if (window.IntersectionObserver) new IntersectionObserver(es => { naTela = es[es.length - 1].isIntersecting; aplicarPausa(); }, { threshold: 0.01 }).observe(raiz);
  document.addEventListener('visibilitychange', aplicarPausa);
  aplicarPausa();

  /* ---------- Sininho e popup de pontos de atenção ---------- */
  window.atualizarEstufa = function () { try { atualizar(); } catch (e) { console.warn('estufa:', e); } };

  window.atualizarSino = function (n) {
    if (typeof n !== 'number') n = document.querySelectorAll('#iniStatusLista .ini-item').length;
    sinoNum.textContent = n > 99 ? '99+' : String(n);
    sinoNum.hidden = n === 0;
    sino.classList.toggle('tem', n > 0);
    sino.setAttribute('aria-label', n === 0 ? 'Pontos de atenção: nenhum' : 'Pontos de atenção: ' + n);
  };
  function abrirAtencao() {
    modal.hidden = false;
    document.body.classList.add('modal-aberto');
    sino.setAttribute('aria-expanded', 'true');
    if (window.lucide) lucide.createIcons();
    btnFechar.focus();
  }
  window.fecharAtencao = function (devolverFoco) {
    if (modal.hidden) return;
    modal.hidden = true;
    document.body.classList.remove('modal-aberto');
    sino.setAttribute('aria-expanded', 'false');
    if (devolverFoco !== false) sino.focus();
  };
  sino.addEventListener('click', abrirAtencao);
  btnFechar.addEventListener('click', () => window.fecharAtencao(true));
  document.getElementById('modalAtencaoFundo').addEventListener('click', () => window.fecharAtencao(true));
  document.addEventListener('keydown', ev => {
    if (modal.hidden) return;
    if (ev.key === 'Escape') { ev.preventDefault(); window.fecharAtencao(true); return; }
    if (ev.key !== 'Tab') return;
    // Mantém o foco dentro do popup.
    const itens = Array.from(modal.querySelectorAll('button:not([tabindex="-1"]), a[href]')).filter(el => el.offsetParent !== null);
    if (!itens.length) return;
    const primeiro = itens[0], ultimo = itens[itens.length - 1];
    if (ev.shiftKey && document.activeElement === primeiro) { ev.preventDefault(); ultimo.focus(); }
    else if (!ev.shiftKey && document.activeElement === ultimo) { ev.preventDefault(); primeiro.focus(); }
  });
  window.atualizarSino(0);
})();
