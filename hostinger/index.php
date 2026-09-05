<?php
declare(strict_types=1);
// Dashboard completo da Hostinger — leitura E controle. Única interface do
// sistema (o servidor HTTP local do ESP32 foi removido por instabilidade
// sob AsyncTCP). Mostra leituras de ambiente, a Roda de Eventos e os
// comandos (relés, modo de cultivo, luz manual, acesso), que gravam na
// fila "comandos_pendentes" (ver enviar_comando.php) — o ESP32 busca essa
// fila por polling. Protegida por login (ver login.php/require_login_html()).
require __DIR__ . '/config.php';
require_login_html();
$usuarioLogado = $_SESSION['usuario'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Smart Demetra — Painel</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.5.0/chart.umd.min.js"></script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<style>
  :root {
    color-scheme: dark;
    --bg: #121414;
    --card: #1a1e1d;
    --card2: #20241f;
    --borda: #2a302c;
    --texto: #eef1ee;
    --muted: #8a938e;
    --accent: #4CAF50;
    --accent-soft: rgba(76, 175, 80, .14);
    --accent-line: rgba(76, 175, 80, .35);
    --amber: #e8c977;
    --amber-soft: rgba(232, 201, 119, .14);
    --red: #e08a85;
    --red-soft: rgba(224, 138, 133, .14);
    --azul: #7fa9c2;
    --azul-soft: rgba(127, 169, 194, .14);
    --raio: 16px;
    --raio-sm: 10px;
    --sombra: 0 10px 28px rgba(0, 0, 0, .38);
    --fonte: "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    --sidebar-w: 240px;
  }
  * { box-sizing: border-box; }
  html, body { height: 100%; }
  body {
    font-family: var(--fonte);
    background: var(--bg);
    color: var(--texto);
    margin: 0;
    -webkit-font-smoothing: antialiased;
  }
  button { font-family: inherit; }
  svg.icone { width: 18px; height: 18px; stroke-width: 2; flex: none; }

  /* ---------------- Layout raiz ---------------- */
  .app { display: flex; min-height: 100vh; }

  /* ---------------- Sidebar ---------------- */
  .sidebar {
    position: fixed;
    top: 0; left: 0; bottom: 0;
    width: var(--sidebar-w);
    background: var(--card);
    border-right: 1px solid var(--borda);
    display: flex;
    flex-direction: column;
    z-index: 200;
    transition: transform .2s ease;
  }
  .sidebar-logo {
    display: flex; align-items: center; gap: 10px;
    padding: 22px 20px 18px;
    border-bottom: 1px solid var(--borda);
  }
  /* Marca oficial (MIV): caule + duas folhas, viewBox 28\u00d732 \u2014 substitui
     o \u00edcone gen\u00e9rico do pacote de \u00edcones nos pontos onde a LOGO da marca
     \u00e9 exibida (n\u00e3o em \u00edcones ilustrativos de sensores/comandos). */
  .logo-marca { width: 22px; height: 25px; flex: none; overflow: visible; }
  .logo-marca-caule { fill: none; stroke: var(--accent); stroke-width: 2.4; stroke-linecap: round; }
  .logo-marca-folha { fill: var(--accent-soft); stroke: var(--accent); stroke-width: 1.6; stroke-linejoin: round; }
  .sidebar-logo span {
    font-weight: 800; font-size: 16px; letter-spacing: -.01em; color: var(--texto);
    white-space: nowrap;
  }
  .sidebar-nav { display: flex; flex-direction: column; gap: 2px; padding: 14px 12px; flex: 1; }
  .nav-item {
    display: flex; align-items: center; gap: 12px;
    width: 100%;
    background: none; border: none; border-radius: var(--raio-sm);
    padding: 11px 12px;
    font-size: 14px; font-weight: 600; color: var(--muted);
    cursor: pointer; text-decoration: none;
    transition: background-color .2s ease, color .2s ease;
  }
  .nav-item svg { width: 18px; height: 18px; }
  .nav-item:hover { background: var(--card2); color: var(--texto); }
  .nav-item.ativo { background: var(--accent-soft); color: var(--accent); }
  .sidebar-rodape { padding: 12px; border-top: 1px solid var(--borda); display: flex; flex-direction: column; gap: 8px; }
  .sidebar-usuario {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 12px; font-size: 12px; color: var(--muted);
  }
  .sidebar-usuario svg { width: 16px; height: 16px; }
  .sidebar-usuario strong { color: var(--texto); font-weight: 700; }
  .nav-sair:hover { background: var(--red-soft); color: var(--red); }

  .sidebar-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0, 0, 0, .5);
    z-index: 150;
  }

  /* ---------------- Topo mobile ---------------- */
  .topo-mobile {
    display: none;
    position: sticky; top: 0; z-index: 120;
    align-items: center; justify-content: space-between; gap: 12px;
    padding: 14px 16px;
    background: rgba(18, 20, 20, .92);
    backdrop-filter: blur(6px);
    border-bottom: 1px solid var(--borda);
  }
  .btn-hamburger {
    background: var(--card2); border: 1px solid var(--borda); border-radius: 10px;
    width: 38px; height: 38px; display: flex; align-items: center; justify-content: center;
    color: var(--texto); cursor: pointer; flex: none;
  }
  .topo-mobile .topo-logo { display: flex; align-items: center; gap: 8px; font-weight: 800; font-size: 16px; }

  /* ---------------- Conteúdo ---------------- */
  .conteudo { margin-left: var(--sidebar-w); flex: 1; min-width: 0; padding: 24px; max-width: 1000px; }

  @media (max-width: 900px) {
    .sidebar {
      left: auto; right: 0;
      border-right: none; border-left: 1px solid var(--borda);
      transform: translateX(100%);
    }
    .sidebar.aberta { transform: translateX(0); }
    .sidebar-overlay.visivel { display: block; }
    .topo-mobile { display: flex; }
    .conteudo { margin-left: 0; padding: 18px 16px 32px; }
  }

  /* ---------------- Abas ---------------- */
  .aba { display: none; }
  .aba.ativa { display: block; animation: entrarAba .2s ease; }
  @keyframes entrarAba { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

  h1.titulo-aba {
    font-size: 20px; font-weight: 800; letter-spacing: -.01em; margin: 0 0 4px;
  }
  p.subtitulo-aba { font-size: 13px; color: var(--muted); margin: 0 0 20px; }

  section { margin-bottom: 20px; }
  section h2 {
    font-size: 12px; color: var(--muted); text-transform: uppercase;
    letter-spacing: .08em; font-weight: 700; margin: 0 0 10px;
  }

  /* ---------------- Skeleton / shimmer ---------------- */
  .skeleton {
    color: transparent !important;
    background: linear-gradient(100deg, var(--borda) 30%, #2a3129 45%, var(--borda) 60%);
    background-size: 250% 100%;
    animation: shimmer 1.3s ease infinite;
    border-radius: 6px;
    min-width: 54px;
    display: inline-block;
  }
  @keyframes shimmer { 0% { background-position: 150% 0; } 100% { background-position: -50% 0; } }

  /* ---------------- Cards de estatística ---------------- */
  .stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 18px; }
  @media (min-width: 640px) { .stats { grid-template-columns: repeat(3, 1fr); } }
  .card {
    background: var(--card); border: 1px solid var(--borda); border-radius: var(--raio);
    padding: 14px; display: flex; flex-direction: column; gap: 10px;
  }
  .card-topo { display: flex; align-items: center; justify-content: space-between; }
  .card-icone {
    width: 32px; height: 32px; border-radius: 9px; background: var(--accent-soft);
    display: flex; align-items: center; justify-content: center; color: var(--accent); flex: none;
  }
  .card-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; font-weight: 700; }
  .card-valor { font-size: 22px; font-weight: 700; letter-spacing: -.01em; font-variant-numeric: tabular-nums; }

  /* ---------------- Barras (VPD / Solo) ---------------- */
  .barra-card { background: var(--card); border: 1px solid var(--borda); border-radius: var(--raio); box-shadow: var(--sombra); padding: 16px; margin-bottom: 14px; }
  .barra-topo { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
  .barra-titulo { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--muted); }
  .barra-titulo svg { width: 16px; height: 16px; color: var(--accent); }
  .barra-valor { font-size: 19px; font-weight: 700; font-variant-numeric: tabular-nums; }
  .barra-trilho { position: relative; height: 10px; border-radius: 6px; overflow: visible; }
  .barra-trilho.vpd { background: linear-gradient(to right, #7a3a3a 0%, #7a3a3a 20%, #6b4a72 20%, #6b4a72 40%, var(--accent) 40%, var(--accent) 60%, #7a6a2e 60%, #7a6a2e 80%, #7a3a3a 80%, #7a3a3a 100%); opacity: .85; }
  .barra-trilho.solo { height: 14px; border-radius: 7px; background: linear-gradient(to right, #223a4a 0%, #223a4a 43%, var(--azul) 45%, var(--azul) 65%, #223a4a 67%, #223a4a 100%); }
  .barra-marcador { position: absolute; top: -4px; width: 3px; height: 18px; background: #fff; border-radius: 2px; box-shadow: 0 0 4px rgba(0,0,0,.6); left: 50%; transform: translateX(-50%); transition: left .3s ease; }
  .barra-trilho.solo .barra-marcador { top: -5px; width: 4px; height: 24px; }
  .barra-rodape { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; gap: 10px; flex-wrap: wrap; }
  .barra-escala { display: flex; flex: 1; font-size: 11px; color: var(--muted); min-width: 200px; }
  .barra-escala span { flex: 1; text-align: center; }
  .barra-escala span:first-child { text-align: left; }
  .barra-escala span:last-child { text-align: right; }
  .pill { font-size: 12px; font-weight: 700; padding: 5px 12px; border-radius: 999px; white-space: nowrap; }
  .pill-ok { background: var(--accent-soft); color: var(--accent); }
  .pill-warn { background: var(--amber-soft); color: var(--amber); }
  .pill-alert { background: var(--red-soft); color: var(--red); }
  .pill-neutro { background: var(--borda); color: var(--muted); }
  .pill-auto { background: var(--azul-soft); color: var(--azul); }

  /* ---------------- Comandos ---------------- */
  .cmd-intro { font-size: 12px; color: var(--muted); line-height: 1.5; margin: 0 0 18px; }
  .cmd-grupo { background: var(--card); border: 1px solid var(--borda); border-radius: var(--raio); padding: 14px; margin-bottom: 12px; box-shadow: var(--sombra); }
  .cmd-grupo-cabecalho { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 12px; }
  .cmd-grupo-titulo { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--texto); font-weight: 700; }
  .cmd-grupo-titulo svg { width: 17px; height: 17px; color: var(--accent); }

  .cmd-seg { display: flex; border-radius: 10px; overflow: hidden; border: 1px solid var(--borda); background: var(--bg); }
  .cmd-seg .cmd-btn { flex: 1; border: none; border-radius: 0; background: transparent; }
  .cmd-seg .cmd-btn + .cmd-btn { border-left: 1px solid var(--borda); }
  .cmd-botoes { display: flex; gap: 8px; }
  .cmd-botoes .cmd-btn { flex: 1; }

  .cmd-btn {
    min-width: 64px; padding: 11px 10px; border-radius: 10px; border: 1px solid var(--borda);
    background: var(--card2); color: var(--texto); font-size: 13px; font-weight: 600; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 6px;
    transition: background-color .2s ease, border-color .2s ease, color .2s ease, transform .1s ease;
  }
  .cmd-btn:active { transform: scale(.97); }
  .cmd-btn:disabled { cursor: wait; opacity: .65; }
  .cmd-btn:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
  .cmd-btn.selecionado { background: var(--accent-soft); color: var(--accent); border-color: var(--accent-line); font-weight: 700; }
  .cmd-btn.ativo-on { background: var(--amber-soft); color: var(--amber); font-weight: 700; }
  .cmd-btn.ativo-off { background: var(--azul-soft); color: var(--azul); font-weight: 700; }

  .cmd-btn-acesso { width: 100%; background: var(--accent-soft); border-color: var(--accent-line); color: var(--accent); padding: 13px 12px; font-size: 14px; }
  .cmd-btn-acesso.pendente-confirmacao { background: var(--red-soft); border-color: var(--red); color: var(--red); }

  .cmd-confirmacao { display: flex; align-items: center; gap: 8px; margin-top: 8px; }
  .cmd-confirmacao span { font-size: 12px; color: var(--muted); flex: 1; }
  .cmd-confirmacao button { font-family: inherit; font-size: 12px; font-weight: 700; border-radius: 8px; padding: 7px 12px; cursor: pointer; border: 1px solid var(--borda); }
  .cmd-confirmar-sim { background: var(--accent-soft); border-color: var(--accent-line); color: var(--accent); }
  .cmd-confirmar-nao { background: var(--card2); color: var(--muted); }

  .cmd-reles { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 10px; }
  .cmd-rele-bloco { background: var(--bg); border: 1px solid var(--borda); border-radius: 10px; padding: 10px 12px; }
  .cmd-rele-cabecalho { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 8px; }
  .cmd-rele-nome { display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 600; }
  .cmd-rele-nome svg { width: 16px; height: 16px; color: var(--accent); }
  .icone-ventilador.girando { animation: girarVentilador 1.6s linear infinite; }
  @keyframes girarVentilador { to { transform: rotate(360deg); } }

  .modo-pills { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
  .modo-pill { flex: 1; min-width: 140px; background: var(--card2); border: 1px solid var(--borda); border-radius: 12px; padding: 10px 14px; font-size: 13px; font-weight: 700; color: var(--muted); }
  .modo-pill small { display: block; margin-top: 3px; font-size: 11px; font-weight: 600; color: var(--muted); }
  .modo-pill.ativo { border-color: var(--accent-line); background: var(--accent-soft); color: var(--accent); }
  .modo-pill.ativo small { color: var(--accent); }

  /* ---------------- Histórico ---------------- */
  .periodo-botoes { display: flex; gap: 6px; }
  .periodo-botao { font-size: 12px; font-weight: 600; color: var(--muted); background: var(--card2); border: 1px solid var(--borda); border-radius: 999px; padding: 6px 12px; cursor: pointer; transition: background-color .2s ease, color .2s ease; }
  .periodo-botao.ativo { color: #0d1c0e; background: var(--accent); border-color: var(--accent); }
  .grafico-topo { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
  .grafico-card { background: var(--card); border: 1px solid var(--borda); border-radius: var(--raio); box-shadow: var(--sombra); padding: 14px; margin-bottom: 16px; }
  .grafico-wrap { position: relative; height: 220px; }
  .grafico-wrap canvas { width: 100% !important; height: 100% !important; }
  .grafico-erro { color: var(--muted); font-size: 13px; padding: 20px 0; text-align: center; }
  .grafico-skeleton { position: absolute; inset: 0; border-radius: 10px; }

  .roda-card { background: var(--card); border: 1px solid var(--borda); border-radius: var(--raio); box-shadow: var(--sombra); padding: 16px; }
  .roda-desc { font-size: 13px; color: var(--muted); line-height: 1.5; margin: 0 0 14px; }
  .roda-svg-wrap { width: 100%; display: flex; justify-content: center; margin-bottom: 14px; touch-action: manipulation; }
  .roda-svg-wrap svg { width: 100%; max-width: 380px; height: auto; }
  .roda-hora-label { font-size: 11px; fill: var(--muted); text-anchor: middle; }
  .roda-evento-dot { cursor: pointer; }
  .roda-evento-dot circle:last-of-type { transition: stroke-width .15s ease; }
  .roda-evento-dot:hover circle:last-of-type, .roda-evento-dot:focus circle:last-of-type, .roda-evento-dot.selecionado circle:last-of-type { stroke: #eef1ee; stroke-width: 3; }
  .roda-evento-dot:focus { outline: none; }
  @keyframes rodaPulso { 0% { r: 5; stroke-opacity: .9; } 100% { r: 15; stroke-opacity: 0; } }
  .roda-agora-pulso { animation: rodaPulso 2s ease-out infinite; }

  .roda-legenda { display: flex; gap: 4px; flex-wrap: wrap; justify-content: center; margin-bottom: 14px; }
  .roda-legenda-item { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--muted); background: none; border: none; font-family: inherit; padding: 5px 8px; border-radius: 999px; cursor: pointer; transition: opacity .2s ease, background-color .2s ease; }
  .roda-legenda-item:active { background: var(--card2); }
  .roda-legenda-item.ativo { color: var(--texto); }
  .roda-legenda-item:not(.ativo) { opacity: .4; }
  .roda-legenda-item i { width: 10px; height: 10px; border-radius: 50%; display: inline-block; flex: none; }

  .roda-detalhe { background: var(--card2); border: 1px solid var(--borda); border-radius: 12px; padding: 12px 14px; margin-bottom: 14px; min-height: 20px; }
  .roda-detalhe-vazio { color: var(--muted); font-size: 12px; }
  .roda-detalhe-topo { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 4px; }
  .roda-detalhe-hora { font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; }
  .roda-detalhe-fechar { background: none; border: none; color: var(--muted); font-size: 11px; font-family: inherit; cursor: pointer; padding: 2px 4px; white-space: nowrap; }
  .roda-detalhe-fechar:active { color: var(--texto); }
  .roda-detalhe-desc { font-size: 14px; font-weight: 600; margin-bottom: 4px; }
  .roda-detalhe-meta { font-size: 12px; color: var(--muted); }
  .roda-detalhe-lista { display: flex; flex-direction: column; gap: 6px; margin-top: 6px; }
  .roda-detalhe-linha { display: flex; align-items: center; gap: 8px; font-size: 12px; }
  .roda-detalhe-linha-dot { width: 8px; height: 8px; border-radius: 50%; flex: none; }
  .roda-detalhe-linha-hora { font-weight: 700; min-width: 38px; }

  .roda-status { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; margin-bottom: 18px; }
  .roda-status-item { background: var(--card2); border-radius: 10px; padding: 10px 12px; font-size: 12px; }
  .roda-status-item .roda-status-nome { color: var(--muted); margin-bottom: 3px; }
  .roda-status-item .roda-status-valor { font-size: 14px; font-weight: 700; }
  .roda-status-item .roda-status-valor.on { color: var(--amber); }
  .roda-status-item .roda-status-valor.off { color: var(--azul); }
  .roda-status-item .roda-status-valor.acesso { color: var(--accent); }

  .roda-lista-cabecalho { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px; }
  .roda-lista-cabecalho h3 { margin: 0; font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .03em; font-weight: 700; }
  .roda-lista-contagem { font-size: 12px; color: var(--muted); }
  .roda-lista { display: flex; flex-direction: column; gap: 6px; max-height: 280px; overflow-y: auto; }
  .roda-lista-item { display: flex; align-items: center; gap: 8px; width: 100%; background: var(--card2); border: 1px solid transparent; border-radius: 10px; padding: 8px 10px; font-size: 12px; color: var(--texto); font-family: inherit; text-align: left; cursor: pointer; }
  .roda-lista-item:active { background: #262b29; }
  .roda-lista-item.selecionado { border-color: #eef1ee; }
  .roda-lista-dot { width: 8px; height: 8px; border-radius: 50%; flex: none; }
  .roda-lista-hora { font-weight: 700; min-width: 36px; flex: none; }
  .roda-lista-desc { flex: 1; }
  .roda-lista-origem { color: var(--muted); font-size: 11px; flex: none; }
  .roda-lista-vazio { color: var(--muted); font-size: 12px; padding: 8px 0; text-align: center; }

  /* ---------------- Toast + rodapé de status ---------------- */
  .cmd-btn-spinner { display: inline-block; width: 11px; height: 11px; border-radius: 50%; border: 2px solid currentColor; border-right-color: transparent; animation: cmdGirar .6s linear infinite; vertical-align: -2px; margin-right: 5px; }
  @keyframes cmdGirar { to { transform: rotate(360deg); } }
  .cmd-toast { position: fixed; left: 50%; bottom: 18px; transform: translateX(-50%) translateY(12px); max-width: calc(100% - 32px); background: #1f2422; border: 1px solid var(--borda); color: var(--texto); font-size: 13px; font-weight: 600; padding: 10px 18px; border-radius: 999px; box-shadow: 0 8px 24px rgba(0,0,0,.45); opacity: 0; pointer-events: none; transition: opacity .2s ease, transform .2s ease; z-index: 300; text-align: center; }
  .cmd-toast.visivel { opacity: 1; transform: translateX(-50%) translateY(0); }
  .cmd-toast.ok { border-color: var(--accent); color: var(--accent); }
  .cmd-toast.erro { border-color: var(--red); color: var(--red); }
  .status-linha { font-size: 12px; color: var(--muted); text-align: center; margin-top: 6px; }

  @media (prefers-reduced-motion: reduce) {
    .skeleton, .roda-agora-pulso, .icone-ventilador.girando, .cmd-btn-spinner { animation: none !important; }
  }
</style>
</head>
<body>
<div class="app">

  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
      <svg class="logo-marca" viewBox="0 0 28 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path class="logo-marca-caule" d="M14 29 C14 22 14 15 14 8"/>
        <path class="logo-marca-folha" d="M14 15 C7 13 3 16 2 22 C9 23 14 20 14 15 Z"/>
        <path class="logo-marca-folha" d="M14 9 C20 7 25 10 26 15 C19 17 14 13 14 9 Z"/>
      </svg>
      <span>Smart Demetra</span>
    </div>
    <nav class="sidebar-nav">
      <button type="button" class="nav-item ativo" data-aba="inicio"><svg data-lucide="home"></svg>In&iacute;cio</button>
      <button type="button" class="nav-item" data-aba="comandos"><svg data-lucide="sliders-horizontal"></svg>Comandos</button>
      <button type="button" class="nav-item" data-aba="historico"><svg data-lucide="history"></svg>Hist&oacute;rico</button>
    </nav>
    <div class="sidebar-rodape">
      <div class="sidebar-usuario"><svg data-lucide="user"></svg><span>Logado como <strong><?= htmlspecialchars($usuarioLogado, ENT_QUOTES, 'UTF-8') ?></strong></span></div>
      <a href="logout.php" class="nav-item nav-sair"><svg data-lucide="log-out"></svg>Sair</a>
    </div>
  </aside>

  <main class="conteudo">

    <header class="topo-mobile">
      <div class="topo-logo">
        <svg class="logo-marca" viewBox="0 0 28 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path class="logo-marca-caule" d="M14 29 C14 22 14 15 14 8"/>
          <path class="logo-marca-folha" d="M14 15 C7 13 3 16 2 22 C9 23 14 20 14 15 Z"/>
          <path class="logo-marca-folha" d="M14 9 C20 7 25 10 26 15 C19 17 14 13 14 9 Z"/>
        </svg>
        Smart Demetra
      </div>
      <button type="button" class="btn-hamburger" id="btnHamburger" aria-label="Abrir menu"><svg data-lucide="menu"></svg></button>
    </header>

    <!-- ===================== ABA: IN\u00cdCIO ===================== -->
    <div class="aba ativa" id="aba-inicio">
      <h1 class="titulo-aba">In&iacute;cio</h1>
      <p class="subtitulo-aba">Leituras atuais do ambiente de cultivo.</p>

      <div class="stats">
        <div class="card">
          <div class="card-topo"><span class="card-label">Temp. interna</span><div class="card-icone"><svg data-lucide="thermometer" style="width:16px;height:16px;"></svg></div></div>
          <div class="card-valor skeleton" id="statTempInterna">--</div>
        </div>
        <div class="card">
          <div class="card-topo"><span class="card-label">Umid. interna</span><div class="card-icone"><svg data-lucide="droplet" style="width:16px;height:16px;"></svg></div></div>
          <div class="card-valor skeleton" id="statUmidInterna">--</div>
        </div>
        <div class="card">
          <div class="card-topo"><span class="card-label">Temp. externa</span><div class="card-icone"><svg data-lucide="thermometer" style="width:16px;height:16px;"></svg></div></div>
          <div class="card-valor skeleton" id="statTempExterna">--</div>
        </div>
        <div class="card">
          <div class="card-topo"><span class="card-label">Umid. externa</span><div class="card-icone"><svg data-lucide="droplets" style="width:16px;height:16px;"></svg></div></div>
          <div class="card-valor skeleton" id="statUmidExterna">--</div>
        </div>
        <div class="card">
          <div class="card-topo"><span class="card-label">Solo</span><div class="card-icone"><svg data-lucide="sprout" style="width:16px;height:16px;"></svg></div></div>
          <div class="card-valor skeleton" id="statSolo">--</div>
        </div>
        <div class="card">
          <div class="card-topo"><span class="card-label">Luz do cultivo</span><div class="card-icone"><svg data-lucide="lightbulb" style="width:16px;height:16px;"></svg></div></div>
          <div class="card-valor skeleton" id="luzContagem">--</div>
        </div>
      </div>

      <div class="barra-card">
        <div class="barra-topo">
          <span class="barra-titulo"><svg data-lucide="gauge"></svg>VPD (d&eacute;ficit de press&atilde;o de vapor)</span>
          <span class="barra-valor skeleton" id="vpdValor">-- kPa</span>
        </div>
        <div class="barra-trilho vpd"><div class="barra-marcador" id="vpdMarcador"></div></div>
        <div class="barra-rodape">
          <div class="barra-escala"><span>0</span><span>0,4</span><span>0,8</span><span>1,2</span><span>1,6</span><span>2,0+</span></div>
          <span class="pill pill-neutro" id="vpdZonaPill">--</span>
        </div>
      </div>

      <div class="barra-card">
        <div class="barra-topo">
          <span class="barra-titulo"><svg data-lucide="droplets"></svg>Umidade do solo (ideal entre 50% e 60%)</span>
          <span class="barra-valor skeleton" id="soloValor">-- %</span>
        </div>
        <div class="barra-trilho solo"><div class="barra-marcador" id="soloMarcador"></div></div>
        <div class="barra-rodape">
          <div class="barra-escala"><span>0</span><span>25</span><span>45</span><span>65</span><span>100</span></div>
          <span class="pill pill-neutro" id="soloStatusPill">--</span>
        </div>
      </div>
    </div>

    <!-- ===================== ABA: COMANDOS ===================== -->
    <div class="aba" id="aba-comandos">
      <h1 class="titulo-aba">Comandos</h1>
      <p class="subtitulo-aba" style="margin-bottom:10px;">Os comandos v&atilde;o para uma fila; o ESP32 consulta a cada poucos segundos e aplica assim que chegar nele.</p>

      <div class="modo-pills">
        <div class="modo-pill" id="pillVegetativo">Vegeta&ccedil;&atilde;o<small>18h luz &middot; 16h&ndash;10h</small></div>
        <div class="modo-pill" id="pillFloracao">Flora&ccedil;&atilde;o<small>12h luz &middot; 19h&ndash;07h</small></div>
      </div>

      <div class="cmd-grupo">
        <div class="cmd-grupo-cabecalho">
          <div class="cmd-grupo-titulo"><svg data-lucide="sprout"></svg>Modo de cultivo</div>
          <span class="pill pill-neutro" id="cmdModoCultivoPill">--</span>
        </div>
        <div class="cmd-seg" id="cmdModoCultivo">
          <button class="cmd-btn" data-tipo="modo_cultivo" data-alvo="modo_cultivo" data-valor="vegetativo" aria-pressed="false">Vegeta&ccedil;&atilde;o</button>
          <button class="cmd-btn" data-tipo="modo_cultivo" data-alvo="modo_cultivo" data-valor="floracao" aria-pressed="false">Flora&ccedil;&atilde;o</button>
        </div>
      </div>

      <div class="cmd-grupo">
        <div class="cmd-grupo-cabecalho">
          <div class="cmd-grupo-titulo"><svg data-lucide="lightbulb"></svg>Luz</div>
          <span class="pill pill-neutro" id="cmdLuzPill">--</span>
        </div>
        <div class="cmd-seg" id="cmdLuz">
          <button class="cmd-btn" data-tipo="luz_manual" data-alvo="luz_manual" data-valor="auto" aria-pressed="false">Autom&aacute;tico</button>
          <button class="cmd-btn" data-tipo="luz_manual" data-alvo="luz_manual" data-valor="ligada" aria-pressed="false">Ligar</button>
          <button class="cmd-btn" data-tipo="luz_manual" data-alvo="luz_manual" data-valor="desligada" aria-pressed="false">Desligar</button>
        </div>
      </div>

      <div class="cmd-grupo">
        <div class="cmd-grupo-cabecalho">
          <div class="cmd-grupo-titulo"><svg data-lucide="door-open"></svg>Acesso (porta)</div>
          <span class="pill pill-neutro" id="cmdAcessoPill">--</span>
        </div>
        <div id="cmdAcessoArea">
          <button class="cmd-btn cmd-btn-acesso" id="cmdBtnAcesso" data-tipo="acesso" data-alvo="acesso" data-valor="liberar"><svg data-lucide="lock-open" style="width:16px;height:16px;"></svg>Liberar acesso</button>
        </div>
      </div>

      <div class="cmd-grupo">
        <div class="cmd-grupo-cabecalho">
          <div class="cmd-grupo-titulo"><svg data-lucide="plug-zap"></svg>Rel&eacute;s</div>
        </div>
        <div class="cmd-reles" id="cmdReles"></div>
      </div>
    </div>

    <!-- ===================== ABA: HIST\u00d3RICO ===================== -->
    <div class="aba" id="aba-historico">
      <h1 class="titulo-aba">Hist&oacute;rico</h1>
      <p class="subtitulo-aba">Gr&aacute;ficos de sensores e linha do tempo de eventos.</p>

      <div class="grafico-topo">
        <h2 style="margin:0;">Per&iacute;odo</h2>
        <div class="periodo-botoes">
          <button class="periodo-botao ativo" data-periodo="24h" onclick="mudarPeriodo('24h')">24 horas</button>
          <button class="periodo-botao" data-periodo="7d" onclick="mudarPeriodo('7d')">1 semana</button>
          <button class="periodo-botao" data-periodo="30d" onclick="mudarPeriodo('30d')">1 m&ecirc;s</button>
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

      <section>
        <h2>Rel&oacute;gio de eventos</h2>
        <div class="roda-card">
          <p class="roda-desc">Eventos posicionados pela hora do dispositivo. O arco claro mostra a janela de luz programada para o modo ativo. Toque num ponto do rel&oacute;gio (ou num item da lista, mais abaixo) para ver os detalhes; toque na legenda pra mostrar/esconder cada tipo de evento.</p>

          <div class="roda-svg-wrap"><svg id="rodaEventos" viewBox="-20 -20 400 400"></svg></div>

          <div class="roda-legenda" id="rodaLegenda">
            <button type="button" class="roda-legenda-item ativo" data-filtro="acesso"><i style="background:var(--accent);"></i>Acesso liberado</button>
            <button type="button" class="roda-legenda-item ativo" data-filtro="ligado"><i style="background:var(--amber);"></i>Rel&eacute; ligado</button>
            <button type="button" class="roda-legenda-item ativo" data-filtro="desligado"><i style="background:var(--azul);"></i>Rel&eacute; desligado</button>
            <button type="button" class="roda-legenda-item ativo" data-filtro="janela"><i style="background:#3c4a34;"></i>Janela de luz do modo</button>
          </div>

          <div class="roda-detalhe" id="rodaDetalhe">
            <div class="roda-detalhe-vazio" id="rodaDetalheVazio">Toque em um ponto do rel&oacute;gio ou na lista abaixo para ver os detalhes do evento.</div>
            <div class="roda-detalhe-conteudo" id="rodaDetalheConteudo" style="display:none;"></div>
          </div>

          <div class="roda-status" id="rodaStatus"></div>

          <div class="roda-lista-cabecalho">
            <h3>Eventos (&uacute;ltimas 24h)</h3>
            <span class="roda-lista-contagem" id="rodaListaContagem"></span>
          </div>
          <div class="roda-lista" id="rodaLista"></div>
        </div>
      </section>
    </div>

    <div class="status-linha" id="statusLinha">conectando...</div>
  </main>
</div>

<div class="cmd-toast" id="cmdToast" role="status" aria-live="polite"></div>

<script>
/* ==================== Navega\u00e7\u00e3o: sidebar + abas ==================== */
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');
const btnHamburger = document.getElementById('btnHamburger');

function abrirSidebar() { sidebar.classList.add('aberta'); overlay.classList.add('visivel'); }
function fecharSidebar() { sidebar.classList.remove('aberta'); overlay.classList.remove('visivel'); }
btnHamburger.addEventListener('click', abrirSidebar);
overlay.addEventListener('click', fecharSidebar);

function mudarAba(aba) {
  document.querySelectorAll('.aba').forEach(el => el.classList.toggle('ativa', el.id === 'aba-' + aba));
  document.querySelectorAll('.nav-item[data-aba]').forEach(btn => btn.classList.toggle('ativo', btn.dataset.aba === aba));
  fecharSidebar();
  // Chart.js precisa recalcular o tamanho do canvas quando a aba volta a ficar vis\u00edvel.
  if (aba === 'historico') { setTimeout(() => { if (graficoTemperatura) graficoTemperatura.resize(); if (graficoUmidade) graficoUmidade.resize(); if (graficoSolo) graficoSolo.resize(); }, 0); }
}
document.querySelectorAll('.nav-item[data-aba]').forEach(btn => {
  btn.addEventListener('click', () => mudarAba(btn.dataset.aba));
});

if (window.lucide) lucide.createIcons();

/* ==================== Utilit\u00e1rios ==================== */
function escapeHtml(v) {
  if (v === null || v === undefined) return '--';
  return String(v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function fmtTemp(v) { return (v === null || v === undefined) ? '--' : Number(v).toFixed(1).replace('.', ',') + '\u00b0C'; }
function fmtUmid(v) { return (v === null || v === undefined) ? '--' : Number(v).toFixed(0) + '%'; }
function fmtSolo(v) { return (v === null || v === undefined) ? '--' : Number(v).toFixed(0) + '%'; }

/* Remove o efeito skeleton assim que o primeiro dado real chega em cada elemento. */
function tirarEsqueleto(id) { const el = document.getElementById(id); if (el) el.classList.remove('skeleton'); }

function atualizarSoloBarra(soloPercentual) {
  const valorEl = document.getElementById('soloValor');
  const marcadorEl = document.getElementById('soloMarcador');
  const pillEl = document.getElementById('soloStatusPill');
  tirarEsqueleto('soloValor');
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
  // Faixa alvo real \u00e9 50-60%; a faixa clara na barra tem 5 pontos de folga
  // pra cada lado (45-65%) s\u00f3 pra dar uma leitura visual mais suave.
  if (v < 50) { pillEl.textContent = 'Seco \u2014 considere regar'; pillEl.className = 'pill pill-warn'; }
  else if (v <= 60) { pillEl.textContent = 'Ideal'; pillEl.className = 'pill pill-ok'; }
  else { pillEl.textContent = 'Encharcado'; pillEl.className = 'pill pill-alert'; }
}

// Mesma f\u00f3rmula usada no firmware (calcularVPD() em main.cpp, Tetens/Magnus):
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
function classePillVPD(vpd) {
  if (vpd < 0.4 || vpd >= 1.6) return 'pill-alert';
  if (vpd < 0.8 || vpd >= 1.2) return 'pill-warn';
  return 'pill-ok';
}

function atualizarVPD(tempInterna, umidInterna) {
  tirarEsqueleto('vpdValor');
  const pillEl = document.getElementById('vpdZonaPill');
  if (tempInterna === null || tempInterna === undefined || umidInterna === null || umidInterna === undefined) {
    document.getElementById('vpdValor').textContent = '-- kPa';
    pillEl.textContent = '--'; pillEl.className = 'pill pill-neutro';
    document.getElementById('vpdMarcador').style.left = '50%';
    return;
  }
  const vpd = calcularVPD(Number(tempInterna), Number(umidInterna));
  document.getElementById('vpdValor').textContent = vpd.toFixed(2).replace('.', ',') + ' kPa';
  pillEl.textContent = zonaVPD(vpd);
  pillEl.className = 'pill ' + classePillVPD(vpd);
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

async function carregarResumo() {
  // Sempre usa 24h (dados crus, ~1 leitura/min) pra pegar o valor mais
  // recente de verdade, independente do periodo selecionado nos graficos.
  const d = await buscarJson('dados.php?periodo=24h');
  const linhas = d.dados || [];
  if (linhas.length > 0) {
    const ultima = linhas[linhas.length - 1];
    tirarEsqueleto('statTempInterna'); tirarEsqueleto('statUmidInterna');
    tirarEsqueleto('statTempExterna'); tirarEsqueleto('statUmidExterna');
    tirarEsqueleto('statSolo');
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
  const valor = linha.dispositivo_timestamp || linha.criado_em;
  const partes = valor.split(/[- :]/);
  if (partes.length < 5) return valor;
  const [, mes, dia, hora, min] = partes;
  return periodo === '24h' ? (hora + ':' + min) : (dia + '/' + mes + ' ' + hora + 'h');
}

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

  const d = await buscarJson('dados.php?periodo=' + encodeURIComponent(periodo));
  const linhas = d.dados || [];

  if (graficoTemperatura) { graficoTemperatura.destroy(); graficoTemperatura = null; }
  if (graficoUmidade) { graficoUmidade.destroy(); graficoUmidade = null; }
  if (graficoSolo) { graficoSolo.destroy(); graficoSolo = null; }
  tirarEsqueleto('graficoTemperaturaSkeleton');
  tirarEsqueleto('graficoUmidadeSkeleton');
  tirarEsqueleto('graficoSoloSkeleton');
  document.getElementById('graficoTemperaturaSkeleton').style.display = 'none';
  document.getElementById('graficoUmidadeSkeleton').style.display = 'none';
  document.getElementById('graficoSoloSkeleton').style.display = 'none';

  if (linhas.length === 0) {
    mostrarMensagemGrafico('graficoTemperatura', 'sem dados nesse periodo ainda');
    mostrarMensagemGrafico('graficoUmidade', 'sem dados nesse periodo ainda');
    mostrarMensagemGrafico('graficoSolo', 'sem dados nesse periodo ainda');
    return;
  }
  mostrarMensagemGrafico('graficoTemperatura', null);
  mostrarMensagemGrafico('graficoUmidade', null);
  mostrarMensagemGrafico('graficoSolo', null);

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
}

function mudarPeriodo(periodo) {
  if (periodo === periodoAtual) return;
  periodoAtual = periodo;
  document.querySelectorAll('.periodo-botao').forEach(btn => btn.classList.toggle('ativo', btn.dataset.periodo === periodo));
  carregarGraficos(periodo).catch(e => { document.getElementById('statusLinha').textContent = 'erro ao carregar grafico: ' + e.message; });
}

/* ==================== Card de luz (contagem regressiva) ==================== */
// Janela de luz por modo de cultivo — precisa bater com luzDeveEstarLigada()
// no firmware: vegetativo liga 16h e desliga 10h, floracao liga 19h e desliga 07h.
const JANELA_LUZ_POR_MODO = { vegetativo: { inicio: 16, fim: 10 }, floracao: { inicio: 19, fim: 7 } };
let modoAtualParaLuz = 'vegetativo';

function estadoEDuracaoLuz(modoAtual) {
  const janela = JANELA_LUZ_POR_MODO[modoAtual] || JANELA_LUZ_POR_MODO.vegetativo;
  const agora = new Date();
  const horaAtual = agora.getHours() + agora.getMinutes() / 60 + agora.getSeconds() / 3600;
  const ligada = janela.inicio > janela.fim ? (horaAtual >= janela.inicio || horaAtual < janela.fim) : (horaAtual >= janela.inicio && horaAtual < janela.fim);
  const horaAlvo = ligada ? janela.fim : janela.inicio;
  let diffHoras = horaAlvo - horaAtual;
  if (diffHoras <= 0) diffHoras += 24;
  return { ligada, totalSegundos: Math.round(diffHoras * 3600) };
}
function formatarContagemLuz(totalSegundos) {
  const h = Math.floor(totalSegundos / 3600);
  const m = Math.floor((totalSegundos % 3600) / 60);
  const s = totalSegundos % 60;
  const pad = n => String(n).padStart(2, '0');
  return h > 0 ? (h + 'h ' + pad(m) + 'min') : (m + 'min ' + pad(s) + 's');
}
function atualizarCardLuz() {
  const { totalSegundos } = estadoEDuracaoLuz(modoAtualParaLuz);
  const contagemEl = document.getElementById('luzContagem');
  if (contagemEl) { tirarEsqueleto('luzContagem'); contagemEl.textContent = formatarContagemLuz(totalSegundos); }
}
atualizarCardLuz();
setInterval(atualizarCardLuz, 1000);

/* ==================== Roda de eventos ==================== */
const RELES_NA_RODA = ['Ventilador', 'Saida de Ar'];

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
  return h + 'h' + (m > 0 ? String(m).padStart(2, '0') + 'min' : '');
}
function corDoEvento(c) { if (c.tipo === 'acesso') return '#4CAF50'; return c.valor === 'ligado' ? '#e8c977' : '#7fa9c2'; }
function descricaoEvento(c) { if (c.tipo === 'acesso') return 'Acesso liberado'; return c.alvo + ' ' + (c.valor === 'ligado' ? 'ligado' : 'desligado'); }
function chaveEventos(eventos) { return eventos.map(ev => (ev.dispositivo_timestamp || ev.criado_em) + '|' + ev.tipo + '|' + ev.alvo + '|' + ev.valor).join(','); }

const filtrosAtivos = { acesso: true, ligado: true, desligado: true, janela: true };
let ultimoEventosHoje = [];
let ultimoModoAtual = 'vegetativo';
let rodaSelecionadoChave = null;

function agruparEventosProximos(eventos, limiarMinutos) {
  const ordenados = eventos.map(ev => ({ ev, horaFrac: horaFracionada(timestampEvento(ev)) })).sort((a, b) => a.horaFrac - b.horaFrac);
  const clusters = [];
  ordenados.forEach(item => {
    const ultimo = clusters[clusters.length - 1];
    if (ultimo && (item.horaFrac - ultimo.horaFracMax) * 60 <= limiarMinutos) {
      ultimo.membros.push(item.ev);
      ultimo.horaFracMax = item.horaFrac;
      ultimo.horaFracMedia = ultimo.membros.reduce((soma, m) => soma + horaFracionada(timestampEvento(m)), 0) / ultimo.membros.length;
    } else {
      clusters.push({ membros: [item.ev], horaFracMax: item.horaFrac, horaFracMedia: item.horaFrac });
    }
  });
  return clusters;
}
function eventosFiltrados() {
  return ultimoEventosHoje.filter(c => c.tipo === 'acesso' ? filtrosAtivos.acesso : (c.valor === 'ligado' ? filtrosAtivos.ligado : filtrosAtivos.desligado));
}

function mostrarDetalheEvento(eventos) {
  rodaSelecionadoChave = chaveEventos(eventos);
  const vazio = document.getElementById('rodaDetalheVazio');
  const conteudo = document.getElementById('rodaDetalheConteudo');
  vazio.style.display = 'none';
  conteudo.style.display = '';
  const fecharBtn = '<button type="button" class="roda-detalhe-fechar" onclick="fecharDetalheEvento()">fechar \u2715</button>';
  if (eventos.length === 1) {
    const ev = eventos[0];
    const hhmm = timestampEvento(ev) ? String(timestampEvento(ev)).substring(11, 16) : '--:--';
    const t = new Date(String(timestampEvento(ev) || '').replace(' ', 'T')).getTime();
    const haQuanto = !isNaN(t) ? formatarDuracao(Date.now() - t) : '';
    conteudo.innerHTML = '<div class="roda-detalhe-topo"><span class="roda-detalhe-hora">' + hhmm + '</span>' + fecharBtn + '</div>' +
      '<div class="roda-detalhe-desc">' + escapeHtml(descricaoEvento(ev)) + '</div>' +
      '<div class="roda-detalhe-meta">origem: ' + escapeHtml(ev.origem || '?') + (haQuanto ? ' &middot; h\u00e1 ' + escapeHtml(haQuanto) : '') + '</div>';
  } else {
    const linhas = eventos.slice().sort((a, b) => horaFracionada(timestampEvento(a)) - horaFracionada(timestampEvento(b))).map(ev => {
      const hhmm = timestampEvento(ev) ? String(timestampEvento(ev)).substring(11, 16) : '--:--';
      return '<div class="roda-detalhe-linha"><span class="roda-detalhe-linha-dot" style="background:' + corDoEvento(ev) + ';"></span>' +
        '<span class="roda-detalhe-linha-hora">' + hhmm + '</span><span>' + escapeHtml(descricaoEvento(ev)) + '</span></div>';
    }).join('');
    conteudo.innerHTML = '<div class="roda-detalhe-topo"><span class="roda-detalhe-desc">' + eventos.length + ' eventos pr\u00f3ximos</span>' + fecharBtn + '</div>' +
      '<div class="roda-detalhe-lista">' + linhas + '</div>';
  }
  renderizarRoda();
}
function fecharDetalheEvento() {
  rodaSelecionadoChave = null;
  const vazio = document.getElementById('rodaDetalheVazio');
  const conteudo = document.getElementById('rodaDetalheConteudo');
  if (vazio) vazio.style.display = '';
  if (conteudo) { conteudo.style.display = 'none'; conteudo.innerHTML = ''; }
  renderizarRoda();
}

function desenharRoda(eventosHoje, modoAtual) {
  const svg = document.getElementById('rodaEventos');
  svg.innerHTML = '';
  const cx = 180, cy = 180, raioTrilha = 145;

  svg.appendChild(svgEl('circle', { cx, cy, r: raioTrilha, fill: 'none', stroke: '#242a27', 'stroke-width': 26, opacity: 0.6 }));

  if (filtrosAtivos.janela) {
    const janela = JANELA_LUZ_POR_MODO[modoAtual] || JANELA_LUZ_POR_MODO.vegetativo;
    svg.appendChild(svgEl('path', { d: caminhoArco(cx, cy, raioTrilha, janela.inicio, janela.fim), fill: 'none', stroke: '#3c4a34', 'stroke-width': 26, 'stroke-linecap': 'butt', opacity: 0.9 }));
  }

  for (let h = 0; h < 24; h++) {
    const principal = h % 3 === 0;
    const p1 = pontoNoCirculo(cx, cy, raioTrilha + 15, anguloGraus(h));
    const p2 = pontoNoCirculo(cx, cy, raioTrilha + (principal ? 27 : 20), anguloGraus(h));
    svg.appendChild(svgEl('line', { x1: p1.x.toFixed(2), y1: p1.y.toFixed(2), x2: p2.x.toFixed(2), y2: p2.y.toFixed(2), stroke: principal ? '#8a938e' : '#3a423c', 'stroke-width': principal ? 1.5 : 1 }));
    if (principal) {
      const pLabel = pontoNoCirculo(cx, cy, raioTrilha + 39, anguloGraus(h));
      const texto = svgEl('text', { x: pLabel.x.toFixed(2), y: pLabel.y.toFixed(2), class: 'roda-hora-label' });
      texto.textContent = String(h).padStart(2, '0') + 'h';
      svg.appendChild(texto);
    }
  }

  const agora = new Date();
  const horaAgora = agora.getHours() + agora.getMinutes() / 60;
  const pAgora = pontoNoCirculo(cx, cy, raioTrilha, anguloGraus(horaAgora));
  svg.appendChild(svgEl('line', { x1: cx, y1: cy, x2: pAgora.x.toFixed(2), y2: pAgora.y.toFixed(2), stroke: '#8a938e', 'stroke-width': 1.5, 'stroke-dasharray': '3 4' }));
  svg.appendChild(svgEl('circle', { cx: pAgora.x.toFixed(2), cy: pAgora.y.toFixed(2), r: 6, fill: 'none', stroke: '#eef1ee', 'stroke-width': 1.5, class: 'roda-agora-pulso' }));
  svg.appendChild(svgEl('circle', { cx: pAgora.x.toFixed(2), cy: pAgora.y.toFixed(2), r: 4, fill: '#eef1ee' }));
  svg.appendChild(svgEl('circle', { cx, cy, r: 5, fill: '#eef1ee' }));
  const pLabelAgora = pontoNoCirculo(cx, cy, raioTrilha - 24, anguloGraus(horaAgora));
  const textoAgora = svgEl('text', { x: pLabelAgora.x.toFixed(2), y: pLabelAgora.y.toFixed(2), 'text-anchor': 'middle', 'font-size': '11', 'font-weight': '700', fill: '#eef1ee' });
  textoAgora.textContent = String(agora.getHours()).padStart(2, '0') + ':' + String(agora.getMinutes()).padStart(2, '0');
  svg.appendChild(textoAgora);

  const areaCentro = svgEl('circle', { cx, cy, r: 12, fill: 'transparent', style: 'cursor:pointer;' });
  areaCentro.addEventListener('click', fecharDetalheEvento);
  svg.appendChild(areaCentro);

  const clusters = agruparEventosProximos(eventosHoje, 8);
  clusters.forEach(cluster => {
    const p = pontoNoCirculo(cx, cy, raioTrilha, anguloGraus(cluster.horaFracMedia));
    const grupo = svgEl('g', { class: 'roda-evento-dot', tabindex: '0', role: 'button' });
    grupo.appendChild(svgEl('circle', { cx: p.x.toFixed(2), cy: p.y.toFixed(2), r: 16, fill: 'transparent' }));
    const multiplo = cluster.membros.length > 1;
    const cor = multiplo ? '#dfe4de' : corDoEvento(cluster.membros[0]);
    grupo.appendChild(svgEl('circle', { cx: p.x.toFixed(2), cy: p.y.toFixed(2), r: multiplo ? 9 : 7, fill: cor, stroke: '#121414', 'stroke-width': 2 }));
    if (multiplo) {
      const contagem = svgEl('text', { x: p.x.toFixed(2), y: p.y.toFixed(2), 'text-anchor': 'middle', 'dominant-baseline': 'central', 'font-size': '9', 'font-weight': '700', fill: '#121414' });
      contagem.textContent = String(cluster.membros.length);
      grupo.appendChild(contagem);
    }
    const chave = chaveEventos(cluster.membros);
    if (chave === rodaSelecionadoChave) grupo.classList.add('selecionado');
    const abrir = () => mostrarDetalheEvento(cluster.membros);
    grupo.addEventListener('click', abrir);
    grupo.addEventListener('keydown', ev => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); abrir(); } });
    svg.appendChild(grupo);
  });
}

function atualizarListaEventos(eventos) {
  const lista = document.getElementById('rodaLista');
  const contagemEl = document.getElementById('rodaListaContagem');
  if (!lista) return;
  const ordenados = eventos.slice().sort((a, b) => horaFracionada(timestampEvento(b)) - horaFracionada(timestampEvento(a)));
  if (contagemEl) contagemEl.textContent = ordenados.length + (ordenados.length === 1 ? ' evento' : ' eventos');
  if (ordenados.length === 0) { lista.innerHTML = '<div class="roda-lista-vazio">Nenhum evento no filtro atual, nas \u00faltimas 24h.</div>'; return; }
  lista.innerHTML = ordenados.map((ev, i) => {
    const hhmm = timestampEvento(ev) ? String(timestampEvento(ev)).substring(11, 16) : '--:--';
    const selecionado = chaveEventos([ev]) === rodaSelecionadoChave ? ' selecionado' : '';
    return '<button type="button" class="roda-lista-item' + selecionado + '" data-i="' + i + '">' +
      '<span class="roda-lista-dot" style="background:' + corDoEvento(ev) + ';"></span>' +
      '<span class="roda-lista-hora">' + hhmm + '</span>' +
      '<span class="roda-lista-desc">' + escapeHtml(descricaoEvento(ev)) + '</span>' +
      '<span class="roda-lista-origem">' + escapeHtml(ev.origem || '') + '</span></button>';
  }).join('');
  lista.querySelectorAll('.roda-lista-item').forEach(btn => btn.addEventListener('click', () => mostrarDetalheEvento([ordenados[Number(btn.dataset.i)]])));
}

function renderizarRoda() {
  const filtrados = eventosFiltrados();
  desenharRoda(filtrados, ultimoModoAtual);
  atualizarListaEventos(filtrados);
}

const rodaLegendaEl = document.getElementById('rodaLegenda');
if (rodaLegendaEl) {
  rodaLegendaEl.addEventListener('click', ev => {
    const btn = ev.target.closest('.roda-legenda-item');
    if (!btn) return;
    const filtro = btn.dataset.filtro;
    if (!(filtro in filtrosAtivos)) return;
    filtrosAtivos[filtro] = !filtrosAtivos[filtro];
    btn.classList.toggle('ativo', filtrosAtivos[filtro]);
    fecharDetalheEvento();
  });
}

function atualizarStatusRoda(todosComandos) {
  const container = document.getElementById('rodaStatus');
  container.innerHTML = '';
  const agora = Date.now();
  RELES_NA_RODA.forEach(nomeRele => {
    const ultimo = todosComandos.find(c => c.tipo === 'rele' && c.alvo === nomeRele);
    const item = document.createElement('div');
    item.className = 'roda-status-item';
    if (!ultimo) {
      item.innerHTML = '<div class="roda-status-nome">' + escapeHtml(nomeRele) + '</div><div class="roda-status-valor">sem registro</div>';
    } else {
      const ligado = ultimo.valor === 'ligado';
      const desde = new Date((ultimo.dispositivo_timestamp || ultimo.criado_em).replace(' ', 'T'));
      const duracao = formatarDuracao(agora - desde.getTime());
      item.innerHTML = '<div class="roda-status-nome">' + escapeHtml(nomeRele) + '</div><div class="roda-status-valor ' + (ligado ? 'on' : 'off') + '">' + (ligado ? 'ligado' : 'desligado') + ' h\u00e1 ' + duracao + '</div>';
    }
    container.appendChild(item);
  });
  const ultimoAcesso = todosComandos.find(c => c.tipo === 'acesso');
  const itemAcesso = document.createElement('div');
  itemAcesso.className = 'roda-status-item';
  if (!ultimoAcesso) {
    itemAcesso.innerHTML = '<div class="roda-status-nome">Porta</div><div class="roda-status-valor">sem registro</div>';
  } else {
    const desde = new Date((ultimoAcesso.dispositivo_timestamp || ultimoAcesso.criado_em).replace(' ', 'T'));
    const duracao = formatarDuracao(agora - desde.getTime());
    itemAcesso.innerHTML = '<div class="roda-status-nome">Porta</div><div class="roda-status-valor acesso">liberada h\u00e1 ' + duracao + '</div>';
  }
  container.appendChild(itemAcesso);
}

async function carregarRodaEventos() {
  const d = await buscarJson('comandos.php?limite=300');
  const todos = d.dados || [];
  const modoAtual = d.modo_atual === 'floracao' ? 'floracao' : 'vegetativo';

  document.getElementById('pillVegetativo').classList.toggle('ativo', modoAtual === 'vegetativo');
  document.getElementById('pillFloracao').classList.toggle('ativo', modoAtual === 'floracao');

  modoAtualParaLuz = modoAtual;
  atualizarCardLuz();

  const agora = Date.now();
  const eventosHoje = todos.filter(c => {
    if (c.tipo !== 'acesso' && !(c.tipo === 'rele' && RELES_NA_RODA.includes(c.alvo))) return false;
    const ts = timestampEvento(c);
    if (!ts) return false;
    const t = new Date(String(ts).replace(' ', 'T')).getTime();
    return !isNaN(t) && (agora - t) <= 24 * 60 * 60 * 1000;
  });

  ultimoEventosHoje = eventosHoje;
  ultimoModoAtual = modoAtual;
  renderizarRoda();
  atualizarStatusRoda(todos);
  atualizarBotoesComando(todos, modoAtual);
}

/* ==================== Comandos (bot\u00f5es) ==================== */
const RELES_CONTROLAVEIS = ['Desumidificador', 'Ventilador', 'Entrada de Ar 1', 'Entrada de Ar 2', 'Saida de Ar'];
// \u00cdcone Lucide por rel\u00e9 — ajuda a identificar cada bloco rapidamente
// numa lista com v\u00e1rios itens parecidos, sem depender s\u00f3 do texto.
const RELE_ICONE = {
  'Desumidificador': 'droplet',
  'Ventilador': 'fan',
  'Entrada de Ar 1': 'wind',
  'Entrada de Ar 2': 'wind',
  'Saida de Ar': 'arrow-up-from-line',
};

function estadoAtualRele(todosComandos, nomeRele) {
  const ultimo = todosComandos.find(c => c.tipo === 'rele' && c.alvo === nomeRele);
  return ultimo ? ultimo.valor : null;
}

function estadoAtualLuz(todosComandos) {
  // O firmware grava TODO evento de luz (automáticos e manuais) como
  // tipo='rele'/alvo='Luz', diferenciados pelo campo "origem":
  //   'automatico_luz' / 'retomou_automatico' -> automático
  //   'override_manual_luz' / 'override_manual' -> manual
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

function renderizarBotoesReles(todosComandos) {
  const container = document.getElementById('cmdReles');
  container.innerHTML = '';
  RELES_CONTROLAVEIS.forEach(nome => {
    const estado = estadoAtualRele(todosComandos, nome);
    const pillVariante = estado === 'ligado' ? 'pill-warn' : estado === 'desligado' ? 'pill-auto' : 'pill-neutro';
    const pillTexto = estado === 'ligado' ? 'ligado' : estado === 'desligado' ? 'desligado' : 'sem registro';
    const icone = RELE_ICONE[nome] || 'plug';
    const girandoClasse = (nome === 'Ventilador' && estado === 'ligado') ? ' girando' : '';

    const bloco = document.createElement('div');
    bloco.className = 'cmd-rele-bloco';
    bloco.innerHTML =
      '<div class="cmd-rele-cabecalho">' +
        '<div class="cmd-rele-nome"><svg data-lucide="' + icone + '" class="icone-ventilador' + girandoClasse + '"></svg>' + escapeHtml(nome) + '</div>' +
        '<span class="pill ' + pillVariante + '">' + pillTexto + '</span>' +
      '</div>' +
      '<div class="cmd-botoes">' +
        '<button class="cmd-btn' + (estado === 'ligado' ? ' ativo-on' : '') + '" data-tipo="rele" data-alvo="' + escapeHtml(nome) + '" data-valor="ligado" aria-pressed="' + (estado === 'ligado') + '">Ligar</button>' +
        '<button class="cmd-btn' + (estado === 'desligado' ? ' ativo-off' : '') + '" data-tipo="rele" data-alvo="' + escapeHtml(nome) + '" data-valor="desligado" aria-pressed="' + (estado === 'desligado') + '">Desligar</button>' +
      '</div>';
    container.appendChild(bloco);
  });
  if (window.lucide) lucide.createIcons();
}

function atualizarBotoesComando(todosComandos, modoAtual) {
  document.querySelectorAll('#cmdModoCultivo .cmd-btn').forEach(btn => {
    const selecionado = btn.dataset.valor === modoAtual;
    btn.classList.toggle('selecionado', selecionado);
    btn.setAttribute('aria-pressed', String(selecionado));
  });
  atualizarPill('cmdModoCultivoPill', modoAtual === 'floracao' ? 'Flora\u00e7\u00e3o' : 'Vegeta\u00e7\u00e3o', 'pill-auto');

  const luzEstado = estadoAtualLuz(todosComandos);
  document.querySelectorAll('#cmdLuz .cmd-btn').forEach(btn => {
    const selecionado = btn.dataset.valor === luzEstado;
    btn.classList.remove('selecionado', 'ativo-on', 'ativo-off');
    if (selecionado) btn.classList.add(luzEstado === 'ligada' ? 'ativo-on' : luzEstado === 'desligada' ? 'ativo-off' : 'selecionado');
    btn.setAttribute('aria-pressed', String(selecionado));
  });
  const luzPillTexto = luzEstado === 'auto' ? 'autom\u00e1tico' : (luzEstado === 'ligada' ? 'ligada (manual)' : 'desligada (manual)');
  const luzPillVariante = luzEstado === 'auto' ? 'pill-auto' : (luzEstado === 'ligada' ? 'pill-warn' : 'pill-auto');
  atualizarPill('cmdLuzPill', luzPillTexto, luzPillVariante);

  const ultimoAcesso = todosComandos.find(c => c.tipo === 'acesso');
  if (ultimoAcesso) {
    const desde = new Date((ultimoAcesso.dispositivo_timestamp || ultimoAcesso.criado_em).replace(' ', 'T'));
    atualizarPill('cmdAcessoPill', 'liberada h\u00e1 ' + formatarDuracao(Date.now() - desde.getTime()), 'pill-ok');
  } else {
    atualizarPill('cmdAcessoPill', 'sem registro', 'pill-neutro');
  }

  renderizarBotoesReles(todosComandos);
}

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
      return;
    }
    const dados = await resposta.json();
    if (!dados.ok) { mostrarToast('erro: ' + (dados.erro || 'desconhecido'), 'erro'); return; }
    mostrarToast('\u2713 comando enviado \u2014 aplica em alguns segundos', 'ok');
    setTimeout(() => { carregarRodaEventos().catch(() => {}); }, 1500);
  } catch (e) {
    mostrarToast('falha de rede: ' + e.message, 'erro');
  } finally {
    if (botao) {
      botao.disabled = false;
      if (botao.innerHTML.indexOf('cmd-btn-spinner') !== -1) botao.innerHTML = rotuloOriginal;
    }
  }
}

let cmdAcessoTimeout = null;
function pedirConfirmacaoAcesso() {
  const area = document.getElementById('cmdAcessoArea');
  const botaoOriginal = document.getElementById('cmdBtnAcesso');
  if (!area || !botaoOriginal) return;
  botaoOriginal.style.display = 'none';
  botaoOriginal.classList.add('pendente-confirmacao');
  const confirmacao = document.createElement('div');
  confirmacao.className = 'cmd-confirmacao';
  confirmacao.innerHTML = '<span>Liberar o acesso (abrir a trava) agora?</span><button type="button" class="cmd-confirmar-nao">N\u00e3o</button><button type="button" class="cmd-confirmar-sim">Sim, liberar</button>';
  area.appendChild(confirmacao);
  const limpar = () => { clearTimeout(cmdAcessoTimeout); confirmacao.remove(); botaoOriginal.style.display = ''; botaoOriginal.classList.remove('pendente-confirmacao'); };
  confirmacao.querySelector('.cmd-confirmar-nao').addEventListener('click', limpar);
  confirmacao.querySelector('.cmd-confirmar-sim').addEventListener('click', () => { limpar(); enviarComando('acesso', 'acesso', 'liberar', botaoOriginal); });
  cmdAcessoTimeout = setTimeout(limpar, 5000);
}

document.addEventListener('click', ev => {
  const botao = ev.target.closest('.cmd-btn');
  if (!botao) return;
  const { tipo, alvo, valor } = botao.dataset;
  if (!tipo || !alvo || !valor) return;
  if (tipo === 'acesso') { pedirConfirmacaoAcesso(); return; }
  enviarComando(tipo, alvo, valor, botao);
});

/* ==================== Ciclo principal ==================== */
async function atualizarTudo() {
  const erros = [];
  try { await carregarResumo(); } catch (e) { erros.push('resumo: ' + e.message); }
  try { await carregarGraficos(periodoAtual); } catch (e) { erros.push('graficos: ' + e.message); }
  try { await carregarRodaEventos(); } catch (e) {
    erros.push('roda de eventos: ' + e.message);
    document.getElementById('rodaStatus').innerHTML = '<div class="roda-status-item">erro ao carregar eventos (' + escapeHtml(e.message) + ')</div>';
  }
  document.getElementById('statusLinha').textContent = erros.length ? 'falha ao atualizar (' + erros.join(' | ') + ')' : 'atualizado ' + new Date().toLocaleTimeString('pt-BR');
}

atualizarTudo();
setInterval(atualizarTudo, 60000); // a cada 1 min (mesmo ritmo do envio do ESP32)
</script>
</body>
</html>