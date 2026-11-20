/* Calendário de manejo (aba "calendario" do index.php + manejo.php).
   Mostra o mês, os registros do dia (aplicado ou agendado), o formulário rápido de produtos
   e o "check" que marca uma aplicação agendada como feita. Carrega depois do app.js. */
(function () {
  if (!document.getElementById('aba-calendario')) return;
  const $ = id => document.getElementById(id);
  const MESES = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
  const ERROS = {
    data_invalida: 'Data inválida.',
    produtos_invalidos: 'Marque pelo menos um produto.',
    observacao_longa: 'As observações passam de 500 caracteres.',
    repeticao_invalida: 'Repetição: use de 1 a 90 dias.',
    nome_invalido: 'Digite o nome do produto (2 a 60 letras).',
    registro_nao_encontrado: 'Esse registro não existe mais. Atualizando…',
    nao_autenticado: 'Sessão expirada.',
    fase_invalida: 'Fase inválida.',
    fim_antes_do_inicio: 'O fim não pode ser antes do início.',
  };
  const ICONE_CHECK = '<svg data-lucide="check"></svg>';
  const ICONE_LIXO = '<svg data-lucide="trash-2"></svg>';

  let mes = null;          // 'AAAA-MM' exibido
  let sel = null;          // 'AAAA-MM-DD' selecionado
  let hoje = null;         // data do servidor (fuso do painel)
  let fases = [];          // fases do cultivo: [{fase, inicio, fim|null}]
  let registros = [];      // do mês exibido
  let produtos = [];       // catálogo ativo: [{id, nome}]
  let resumo = { pendentes: 0, proximo: null };
  let form = { aberto: false, modo: 'feito', itens: new Set(), rep: '0', salvando: false };
  let removendoId = null, removendoTimer = null;

  const FASES = [
    { id: 'germinacao', nome: 'Germinação', cor: '#d4b84a' },
    { id: 'muda', nome: 'Muda', cor: '#8ec06c' },
    { id: 'vegetativo', nome: 'Vegetação', cor: '#3fae6a' },
    { id: 'floracao', nome: 'Floração', cor: '#c77dba' },
  ];
  const faseInfo = id => FASES.find(f => f.id === id);
  const pad = n => String(n).padStart(2, '0');
  const dmy = d => d.slice(8, 10) + '/' + d.slice(5, 7);
  const diasEntre = (a, b) => Math.round((new Date(b + 'T00:00:00') - new Date(a + 'T00:00:00')) / 86400000);
  // Fase que contém a data (se várias, a que começou por último).
  function faseDe(data) {
    let achada = null;
    fases.forEach(f => {
      if (f.inicio <= data && (!f.fim || data <= f.fim) && (!achada || f.inicio >= achada.inicio)) achada = f;
    });
    return achada;
  }
  // Usada pela estufa do Início: a fase de hoje e o "dia N" dela.
  window.manejoFaseHoje = function () {
    if (!hoje) return null;
    const f = faseDe(hoje);
    return f ? { id: f.fase, dia: diasEntre(f.inicio, hoje) + 1 } : null;
  };
  const nomeProduto = id => { const p = produtos.find(x => x.id === id); return p ? p.nome : 'Produto removido'; };
  const nomesDe = r => r.produtos.map(nomeProduto).join(' + ');
  const atrasado = r => r.situacao === 'agendado' && hoje && r.data <= hoje;   // pede o check
  const passou = r => r.situacao === 'agendado' && hoje && r.data < hoje;      // ponto vermelho

  async function api(url, opcoes) {
    const r = await fetch(url, opcoes);
    if (r.status === 401) {
      mostrarToast('sessão expirada — redirecionando pro login...', 'erro');
      setTimeout(() => { window.location.href = 'login.php'; }, 1200);
      const e = new Error('nao_autenticado'); e.codigo = 'nao_autenticado'; throw e;
    }
    const d = await r.json().catch(() => ({ ok: false, erro: 'resposta_invalida' }));
    if (!d.ok) { const e = new Error(d.detalhe || d.erro || 'erro'); e.codigo = d.erro; throw e; }
    return d;
  }
  const post = corpo => api('manejo.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(corpo) });
  const mensagem = e => ERROS[e.codigo] || ('Não foi possível concluir: ' + (e.message || 'erro desconhecido'));

  /* ---------- Carga ---------- */
  async function carregar() {
    const d = await api('manejo.php?mes=' + encodeURIComponent(mes) + '&_=' + Date.now(), { cache: 'no-store' });
    hoje = d.hoje;
    produtos = d.produtos || [];
    fases = d.fases || [];
    registros = d.registros || [];
    resumo = { pendentes: d.pendentes || 0, proximo: d.proximo || null };
    if (!sel || sel.slice(0, 7) !== mes) sel = (hoje && hoje.slice(0, 7) === mes) ? hoje : mes + '-01';
    renderizar();
    atualizarResumo();
    if (typeof window.atualizarEstufa === 'function') window.atualizarEstufa();
  }
  function recarregar() { return carregar().catch(e => { if (e.codigo !== 'nao_autenticado') mostrarToast(mensagem(e), 'erro'); }); }

  /* ---------- Resumo (selo, menu e lista de atenção do Início) ---------- */
  function atualizarResumo() {
    const n = resumo.pendentes;
    const nav = $('navCalPend');
    if (nav) { nav.textContent = n > 99 ? '99+' : String(n); nav.hidden = n === 0; }
    const selo = $('calSeloPend');
    if (selo) {
      selo.querySelector('span').textContent = n === 0 ? 'Nada pendente' : n + (n === 1 ? ' pendente' : ' pendentes');
      selo.classList.toggle('neutro', n === 0);
    }
    if (typeof renderizarInicio === 'function') { try { renderizarInicio(); } catch (e) { /* ignora */ } }
  }
  function quando(data) {
    if (!hoje) return data.slice(8) + '/' + data.slice(5, 7);
    const dias = Math.round((new Date(data + 'T00:00:00') - new Date(hoje + 'T00:00:00')) / 86400000);
    return dias === 0 ? 'hoje' : dias === 1 ? 'amanhã' : dias > 1 && dias <= 6 ? 'em ' + dias + ' dias' : data.slice(8) + '/' + data.slice(5, 7);
  }
  // Itens para a lista "pontos de atenção" do Início (app.js chama esta função).
  window.manejoItensAtencao = function () {
    const itens = [];
    if (resumo.pendentes > 0) {
      itens.push({ titulo: resumo.pendentes + (resumo.pendentes === 1 ? ' aplicação de manejo pendente' : ' aplicações de manejo pendentes'),
        detalhe: 'Agendadas para hoje ou dias anteriores e ainda sem o check no calendário.', acao: 'Abrir calendário', aba: 'calendario' });
    }
    const p = resumo.proximo;
    if (p && hoje) {
      const dias = Math.round((new Date(p.data + 'T00:00:00') - new Date(hoje + 'T00:00:00')) / 86400000);
      if (dias >= 0 && dias <= 2) {
        itens.push({ titulo: 'Próximo manejo ' + quando(p.data) + ': ' + p.produtos.map(nomeProduto).join(' + '),
          detalhe: p.observacao ? p.observacao : 'Agendado no calendário de manejo.', acao: 'Calendário', aba: 'calendario', leve: true });
      }
    }
    return itens;
  };

  /* ---------- Renderização ---------- */
  function renderizar() {
    const [ano, m] = mes.split('-').map(Number);
    $('calMesTitulo').textContent = MESES[m - 1] + ' ' + ano;
    const primeiro = new Date(ano, m - 1, 1).getDay();
    const total = new Date(ano, m, 0).getDate();
    let html = '';
    for (let i = 0; i < primeiro; i++) html += '<button type="button" class="cal-dia vazio" tabindex="-1" aria-hidden="true"></button>';
    for (let d = 1; d <= total; d++) {
      const chave = mes + '-' + pad(d);
      const lista = registros.filter(r => r.data === chave);
      const pontos = lista.slice(0, 3).map(r => '<i class="' + (r.situacao === 'feito' ? 'feito' : passou(r) ? 'atrasado' : 'agendado') + '"></i>').join('');
      const fase = faseDe(chave), fi = fase ? faseInfo(fase.fase) : null;
      const marca = fase ? (chave === fase.inicio ? ' fase-ini' : '') + (fase.fim && chave === fase.fim ? ' fase-fim' : '') : '';
      const rotulo = d + ' de ' + MESES[m - 1].toLowerCase() + (fi ? ', ' + fi.nome.toLowerCase() : '') + (lista.length ? ', ' + lista.length + (lista.length === 1 ? ' item' : ' itens') : ', sem itens');
      html += '<button type="button" class="cal-dia' + (chave === sel ? ' sel' : '') + (chave === hoje ? ' hoje' : '') + (fi ? ' tem-fase' + marca : '') + '" data-dia="' + chave + '"' +
        (fi ? ' style="--fase:' + fi.cor + ';"' : '') + ' aria-label="' + rotulo +
        '"' + (chave === sel ? ' aria-pressed="true"' : ' aria-pressed="false"') + '><span>' + d + '</span><span class="cal-pontos">' + pontos + '</span></button>';
    }
    $('calDias').innerHTML = html;
    renderizarDia();
    renderizarFases();
    if (window.lucide) lucide.createIcons();
  }

  /* ---------- Fases do cultivo ---------- */
  function statusFase(f) {
    if (!f) return 'Não marcada';
    if (hoje < f.inicio) { const n = diasEntre(hoje, f.inicio); return 'Começa ' + (n === 1 ? 'amanhã' : 'em ' + n + ' dias'); }
    if (f.fim && hoje > f.fim) return 'Concluída · ' + (diasEntre(f.inicio, f.fim) + 1) + ' dias';
    return 'Em andamento · dia ' + (diasEntre(f.inicio, hoje) + 1) + (f.fim ? ' de ' + (diasEntre(f.inicio, f.fim) + 1) : '');
  }
  function renderizarFases() {
    const ativo = document.activeElement && document.activeElement.closest && document.activeElement.closest('#calFases');
    if (ativo) return;   // não redesenha enquanto a pessoa digita uma data
    $('calFases').innerHTML = FASES.map(fi => {
      const f = fases.find(x => x.fase === fi.id);
      return '<div class="cal-fase" data-fase="' + fi.id + '"><div class="cal-fase-nome"><i style="background:' + fi.cor + ';"></i><strong>' + fi.nome + '</strong><span class="cal-fase-status">' + statusFase(f) + '</span></div>' +
        '<div class="cal-fase-datas"><label>Início<input type="date" data-campo="inicio" value="' + (f ? f.inicio : '') + '"></label>' +
        '<label>Fim<input type="date" data-campo="fim" value="' + (f && f.fim ? f.fim : '') + '"></label>' +
        '<button type="button" class="cal-fase-limpar" data-limpar="' + fi.id + '" aria-label="Limpar datas de ' + fi.nome + '"' + (f ? '' : ' hidden') + '>Limpar</button></div></div>';
    }).join('');
  }
  function erroFases(t) { const el = $('calFasesErro'); el.textContent = t || ''; el.hidden = !t; }
  async function salvarFase(id) {
    const linha = $('calFases').querySelector('[data-fase="' + id + '"]');
    const ini = linha.querySelector('[data-campo="inicio"]').value, fim = linha.querySelector('[data-campo="fim"]').value;
    erroFases('');
    try {
      let d;
      if (!ini && !fim) d = await post({ acao: 'limpar_fase', fase: id });
      else if (!ini) { erroFases('Informe a data de início de ' + faseInfo(id).nome.toLowerCase() + '.'); return; }
      else d = await post({ acao: 'salvar_fase', fase: id, inicio: ini, fim: fim });
      fases = d.fases;
      mostrarToast(ini ? faseInfo(id).nome + ' salva' : 'Datas removidas');
      if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
      renderizar();
      if (typeof window.atualizarEstufa === 'function') window.atualizarEstufa();
    } catch (e) { erroFases(mensagem(e)); }
  }
  $('calFases').addEventListener('change', ev => {
    const linha = ev.target.closest('[data-fase]');
    if (linha && ev.target.matches('input[type="date"]')) salvarFase(linha.dataset.fase);
  });
  $('calFases').addEventListener('click', ev => {
    const b = ev.target.closest('[data-limpar]');
    if (!b) return;
    const linha = $('calFases').querySelector('[data-fase="' + b.dataset.limpar + '"]');
    linha.querySelectorAll('input').forEach(i => { i.value = ''; });
    salvarFase(b.dataset.limpar);
  });

  function renderizarDia() {
    const [, m, d] = sel.split('-').map(Number);
    $('calDiaTitulo').textContent = d + ' de ' + MESES[m - 1] + (sel === hoje ? ' · hoje' : '');
    const lista = registros.filter(r => r.data === sel);
    $('calDiaContagem').textContent = lista.length === 0 ? '' : lista.length === 1 ? '1 item' : lista.length + ' itens';
    // Fase do dia e marcos (início/fim de fase) no topo da lista.
    const fdia = faseDe(sel);
    const marcos = [];
    fases.forEach(f => {
      if (f.inicio === sel) marcos.push('Início da ' + faseInfo(f.fase).nome.toLowerCase());
      if (f.fim === sel) marcos.push('Fim da ' + faseInfo(f.fase).nome.toLowerCase());
    });
    const faseHtml = fdia ? '<div class="cal-fase-dia" style="--fase:' + faseInfo(fdia.fase).cor + ';"><i></i><span>' + faseInfo(fdia.fase).nome + ' · dia ' + (diasEntre(fdia.inicio, sel) + 1) + '</span>' +
      (marcos.length ? '<strong>' + marcos.join(' · ') + '</strong>' : '') + '</div>' : (marcos.length ? '<div class="cal-fase-dia"><strong>' + marcos.join(' · ') + '</strong></div>' : '');
    if (!lista.length && !form.aberto) {
      $('calLista').innerHTML = faseHtml + '<div class="cal-vazio">Nada registrado nem agendado neste dia.</div>';
    } else {
      $('calLista').innerHTML = faseHtml + lista.map(r => {
        const feito = r.situacao === 'feito', pend = atrasado(r);
        const nomes = escapeHtml(nomesDe(r));
        const estado = (feito ? 'Aplicado' : pend ? 'Pendente: marque quando aplicar' : 'Agendado') + (r.repetir_dias ? ' · repete a cada ' + r.repetir_dias + ' d' : '');
        const classe = feito ? 'feito' : pend ? 'atrasado' : '';
        const confirmando = removendoId === r.id;
        return '<div class="cal-item"><button type="button" class="cal-check ' + classe + '" role="checkbox" aria-checked="' + feito + '" data-acao="alternar" data-id="' + r.id +
          '" aria-label="' + (feito ? 'Desmarcar ' : 'Marcar como aplicado: ') + nomes + '">' + ICONE_CHECK + '</button>' +
          '<div class="cal-item-corpo"><strong>' + nomes + '</strong><span class="cal-situacao ' + classe + '">' + estado +
          (feito && r.concluido_em ? ' · ' + r.concluido_em.slice(8, 10) + '/' + r.concluido_em.slice(5, 7) + ' às ' + r.concluido_em.slice(11, 16) : '') + '</span>' +
          (r.observacao ? '<span class="cal-obs">' + escapeHtml(r.observacao) + '</span>' : '') + '</div>' +
          '<button type="button" class="cal-remover' + (confirmando ? ' confirmar' : '') + '" data-acao="remover" data-id="' + r.id + '" aria-label="' +
          (confirmando ? 'Confirmar remoção de ' : 'Remover ') + nomes + '">' + ICONE_LIXO + (confirmando ? '<span>Remover?</span>' : '') + '</button></div>';
      }).join('');
    }
    const futuro = sel > hoje;
    $('calAdd').hidden = form.aberto;
    $('calAdd').querySelector('span').textContent = futuro ? 'Agendar aplicação' : 'Registrar aplicação';
    $('calForm').hidden = !form.aberto;
    if (form.aberto) renderizarForm();
    if (window.lucide) lucide.createIcons();
  }

  function renderizarForm() {
    $('calForm').querySelectorAll('[data-modo]').forEach(b => {
      const ativo = b.dataset.modo === form.modo;
      b.classList.toggle('ativo', ativo);
      b.setAttribute('aria-pressed', String(ativo));
    });
    $('calProdutos').innerHTML = produtos.map(p => {
      const on = form.itens.has(p.id);
      return '<button type="button" class="cal-prod' + (on ? ' ativo' : '') + '" role="checkbox" aria-checked="' + on + '" data-prod="' + p.id + '"><span class="caixa">' + ICONE_CHECK + '</span>' + escapeHtml(p.nome) + '</button>';
    }).join('');
    $('calForm').querySelectorAll('[data-rep]').forEach(b => {
      const ativo = b.dataset.rep === form.rep;
      b.classList.toggle('ativo', ativo);
      b.setAttribute('aria-pressed', String(ativo));
    });
    $('calRepOutro').hidden = form.rep !== 'x';
    $('calRepDica').hidden = form.rep === '0';
    $('calSalvar').disabled = form.itens.size === 0 || form.salvando;
    $('calSalvar').textContent = form.modo === 'agendado' ? 'Agendar' : 'Salvar registro';
  }

  function erroForm(texto) { const el = $('calErro'); el.textContent = texto || ''; el.hidden = !texto; }
  function abrirForm() {
    form = { aberto: true, modo: sel > hoje ? 'agendado' : 'feito', itens: new Set(), rep: '0', salvando: false };
    $('calRepDias').value = '10';
    $('calNota').value = ''; $('calNovoProd').value = ''; $('calContador').textContent = '0/500'; erroForm('');
    renderizarDia();
    const primeiro = $('calProdutos').querySelector('.cal-prod');
    if (primeiro) primeiro.focus();
  }
  function fecharForm(devolverFoco) {
    form.aberto = false;
    renderizarDia();
    if (devolverFoco) $('calAdd').focus();
  }

  /* ---------- Eventos ---------- */
  $('calAnterior').addEventListener('click', () => mudarMes(-1));
  $('calProximo').addEventListener('click', () => mudarMes(1));
  function mudarMes(delta) {
    const [a, m] = mes.split('-').map(Number);
    const d = new Date(a, m - 1 + delta, 1);
    mes = d.getFullYear() + '-' + pad(d.getMonth() + 1);
    sel = null; form.aberto = false; removendoId = null;
    recarregar();
  }
  $('calDias').addEventListener('click', ev => {
    const b = ev.target.closest('[data-dia]');
    if (!b) return;
    sel = b.dataset.dia; form.aberto = false; removendoId = null;
    renderizar();
  });
  $('calAdd').addEventListener('click', abrirForm);
  $('calCancelar').addEventListener('click', () => fecharForm(true));
  $('calForm').addEventListener('click', ev => {
    const modo = ev.target.closest('[data-modo]');
    if (modo) { form.modo = modo.dataset.modo; renderizarForm(); return; }
    const rep = ev.target.closest('[data-rep]');
    if (rep) { form.rep = rep.dataset.rep; erroForm(''); renderizarForm(); if (form.rep === 'x') $('calRepDias').focus(); return; }
    const prod = ev.target.closest('[data-prod]');
    if (prod) {
      const id = Number(prod.dataset.prod);
      if (form.itens.has(id)) form.itens.delete(id); else form.itens.add(id);
      erroForm('');
      renderizarForm();
      const de = $('calProdutos').querySelector('[data-prod="' + id + '"]'); if (de) de.focus();   // o foco não se perde ao redesenhar
    }
  });
  $('calNota').addEventListener('input', () => { $('calContador').textContent = $('calNota').value.length + '/500'; });

  async function adicionarProduto() {
    const campo = $('calNovoProd');
    const nome = campo.value.replace(/\s+/g, ' ').trim();
    if (nome.length < 2) { erroForm(ERROS.nome_invalido); campo.focus(); return; }
    $('calNovoProdBtn').disabled = true;
    try {
      const d = await post({ acao: 'novo_produto', nome });
      produtos = d.produtos;
      const novo = produtos.find(p => p.nome.toLowerCase() === nome.toLowerCase());
      if (novo) form.itens.add(novo.id);
      campo.value = ''; erroForm('');
      renderizarForm();
    } catch (e) { erroForm(mensagem(e)); }
    finally { $('calNovoProdBtn').disabled = false; }
  }
  $('calNovoProdBtn').addEventListener('click', adicionarProduto);
  $('calNovoProd').addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); adicionarProduto(); } });

  $('calForm').addEventListener('submit', async ev => {
    ev.preventDefault();
    if (form.itens.size === 0 || form.salvando) return;
    let repetir = null;
    if (form.rep !== '0') {
      repetir = form.rep === 'x' ? Number($('calRepDias').value) : Number(form.rep);
      if (!Number.isInteger(repetir) || repetir < 1 || repetir > 90) { erroForm(ERROS.repeticao_invalida); $('calRepDias').focus(); return; }
    }
    form.salvando = true; renderizarForm(); erroForm('');
    try {
      await post({ acao: 'criar', data: sel, produtos: Array.from(form.itens), observacao: $('calNota').value.trim(), situacao: form.modo, repetir_dias: repetir });
      mostrarToast(form.modo === 'agendado' ? 'Aplicação agendada' : repetir ? 'Registro salvo · próxima agendada' : 'Registro salvo');
      form.aberto = false; form.salvando = false;
      await carregar();
      $('calAdd').focus();
    } catch (e) {
      form.salvando = false; renderizarForm();
      erroForm(mensagem(e));
    }
  });

  $('calLista').addEventListener('click', async ev => {
    const b = ev.target.closest('[data-acao]');
    if (!b) return;
    const id = Number(b.dataset.id);
    if (b.dataset.acao === 'alternar') {
      b.disabled = true;
      try { await post({ acao: 'alternar', id }); await carregar(); }
      catch (e) { mostrarToast(mensagem(e), 'erro'); if (e.codigo === 'registro_nao_encontrado') recarregar(); else b.disabled = false; }
      return;
    }
    // Remover pede um segundo toque (sem janela de confirmação do navegador).
    if (removendoId !== id) {
      removendoId = id;
      clearTimeout(removendoTimer);
      removendoTimer = setTimeout(() => { removendoId = null; renderizarDia(); }, 4000);
      renderizarDia();
      const de = $('calLista').querySelector('[data-acao="remover"][data-id="' + id + '"]'); if (de) de.focus();
      return;
    }
    clearTimeout(removendoTimer); removendoId = null;
    try { await post({ acao: 'remover', id }); mostrarToast('Registro removido'); await carregar(); }
    catch (e) { mostrarToast(mensagem(e), 'erro'); recarregar(); }
  });

  /* ---------- Início ---------- */
  window.manejoAbrirAba = function () { recarregar(); };
  async function iniciar() {
    const agora = new Date();
    mes = agora.getFullYear() + '-' + pad(agora.getMonth() + 1);   // o servidor corrige 'hoje' ao responder
    await recarregar();
    if (hoje && hoje.slice(0, 7) !== mes) { mes = hoje.slice(0, 7); sel = null; await recarregar(); }
  }
  iniciar();
  // Pendências mudam com o passar do dia: confere de novo a cada 5 minutos e ao voltar para a aba do navegador.
  setInterval(() => { if (!document.hidden && !form.aberto) recarregar(); }, 300000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden && !form.aberto) recarregar(); });
})();
