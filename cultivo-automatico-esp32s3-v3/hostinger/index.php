<?php
declare(strict_types=1);
// Dashboard (login): Início, Relógio de eventos, Automação, Comandos, Histórico,
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
  [hidden] { display: none !important; }
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
  /* Logo da marca (caule + duas folhas). */
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
  .periodo-botoes { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
  .periodo-botao { font-size: 12px; font-weight: 600; color: var(--muted); background: var(--card2); border: 1px solid var(--borda); border-radius: 999px; padding: 6px 12px; cursor: pointer; transition: background-color .2s ease, color .2s ease; }
  .periodo-botao.ativo { color: #0d1c0e; background: var(--accent); border-color: var(--accent); }
  .grafico-topo { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
  .grafico-card { background: var(--card); border: 1px solid var(--borda); border-radius: var(--raio); box-shadow: var(--sombra); padding: 14px; margin-bottom: 16px; }
  .grafico-wrap { position: relative; height: 220px; }
  .grafico-wrap canvas { width: 100% !important; height: 100% !important; }
  .grafico-card h2 { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; font-weight: 700; margin: 0 0 10px; }
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


  /* ---------------- Sidebar: seções ---------------- */
  .nav-secao { font-size: 10px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .1em; padding: 14px 12px 6px; opacity: .8; }

  /* ---------------- Faixas de VPD (mesmas cores na barra e no gráfico) ---------------- */
  /* 0–0,4 rosa fraco · 0,4–0,8 rosa forte · 0,8–1,2 verde · 1,2–1,6 amarelo · 1,6–2,0+ rosa forte */
  .barra-trilho.vpd { background: linear-gradient(to right, #f4aac3 0%, #f4aac3 20%, #e84885 20%, #e84885 40%, #4CAF50 40%, #4CAF50 60%, #e8c977 60%, #e8c977 80%, #e84885 80%, #e84885 100%); opacity: .8; }
  .vpd-legenda { display: flex; flex-wrap: wrap; gap: 6px 14px; margin-top: 12px; }
  .vpd-legenda span { display: flex; align-items: center; gap: 6px; font-size: 11px; color: var(--muted); }
  .vpd-legenda i { width: 12px; height: 12px; border-radius: 3px; flex: none; }
  .grafico-wrap.alto { height: 260px; }

  /* ---------------- Alertas nos cards de leitura ---------------- */
  .card.alerta { border-color: rgba(232, 201, 119, .55); }
  .card.alerta .card-icone { background: var(--amber-soft); color: var(--amber); }
  .card-legenda { font-size: 12px; color: var(--muted); margin-top: -4px; min-height: 16px; }
  .card.alerta .card-legenda { color: var(--amber); font-weight: 600; }
  .barra-status { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
  .barra-ideal { font-size: 12px; color: var(--muted); }
  .barra-nota { margin: 8px 0 0; font-size: 12px; color: var(--muted); line-height: 1.5; }
  .barra-marcador[hidden] { display: none; }

  /* ---------------- Relés (aba Comandos) ---------------- */
  .rele-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; }
  @media (max-width: 560px) { .rele-grid { grid-template-columns: 1fr 1fr; gap: 8px; } .rele-card { padding: 12px; min-height: 0; } .rele-nome { font-size: 13px; } }
  .rele-card {
    position: relative; background: var(--bg); border: 1px solid var(--borda); border-radius: 14px;
    padding: 14px; display: flex; flex-direction: column; gap: 14px; min-height: 132px;
    transition: border-color .2s ease, background-color .2s ease;
  }
  .rele-card.on { border-color: rgba(232, 201, 119, .45); background: linear-gradient(160deg, rgba(232, 201, 119, .09), transparent 60%), var(--bg); }
  .rele-card-topo { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
  .rele-icone {
    width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center;
    background: var(--card2); color: var(--muted); flex: none; transition: background-color .2s ease, color .2s ease;
  }
  .rele-icone svg { width: 21px; height: 21px; }
  .rele-card.on .rele-icone { background: var(--amber-soft); color: var(--amber); }
  .rele-num { font-size: 10px; font-weight: 700; color: var(--muted); letter-spacing: .06em; text-transform: uppercase; background: var(--card2); border-radius: 6px; padding: 3px 7px; }
  .rele-nome { font-size: 14px; font-weight: 700; line-height: 1.25; }
  .rele-rodape { display: flex; align-items: flex-end; justify-content: space-between; gap: 10px; margin-top: auto; }
  .rele-estado { font-size: 12px; color: var(--muted); line-height: 1.35; }
  .rele-estado strong { display: block; font-size: 13px; color: var(--texto); }
  .rele-card.on .rele-estado strong { color: var(--amber); }
  .rele-card.pendente .rele-estado strong { color: var(--azul); }

  .switch {
    position: relative; width: 52px; height: 30px; border-radius: 999px; flex: none;
    background: #2f3632; border: 1px solid var(--borda); cursor: pointer; padding: 0;
    transition: background-color .2s ease, border-color .2s ease;
  }
  .switch::after {
    content: ""; position: absolute; top: 3px; left: 3px; width: 22px; height: 22px; border-radius: 50%;
    background: #cfd5d0; box-shadow: 0 2px 6px rgba(0,0,0,.4); transition: transform .2s ease, background-color .2s ease;
  }
  .switch[aria-checked="true"] { background: var(--amber); border-color: var(--amber); }
  .switch[aria-checked="true"]::after { transform: translateX(22px); background: #fff; }
  .switch:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }
  .switch:disabled { cursor: wait; opacity: .7; }
  .rele-card.pendente .switch::after { animation: switchPulso 1s ease-in-out infinite; }
  @keyframes switchPulso { 50% { opacity: .45; } }

  /* ---------------- Automação ---------------- */
  .auto-status { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-bottom: 18px; }
  .auto-status .card-valor { font-size: 18px; }
  .auto-status { grid-template-columns: repeat(4, minmax(0, 1fr)); }
  @media (max-width: 1100px) { .auto-status { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  .timer-badges { flex-wrap: wrap; justify-content: flex-end; }
  #autoTimerCard.ok #autoTimer { color: var(--accent); }
  #autoTimerCard.espera #autoTimer { color: var(--amber); }
  #autoTimerCard.espera .card-icone { background: var(--amber-soft); color: var(--amber); }
  #autoTimerCard.erro { border-color: rgba(224, 138, 133, .45); }
  #autoTimerCard.erro #autoTimer { color: var(--red); }
  #autoTimerCard.erro .card-icone { background: var(--red-soft); color: var(--red); }
  .cmd-link { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 14px; border-radius: 10px; border: 1px solid var(--borda); background: var(--bg); color: var(--texto); font-size: 13px; text-decoration: none; }
  .cmd-link svg { width: 16px; height: 16px; color: var(--accent); flex: none; }
  .cmd-link:hover { border-color: var(--accent-line); }
  .troca-fase { border-top: 1px solid var(--borda); margin-top: 16px; padding-top: 14px; display: flex; flex-direction: column; gap: 10px; }
  .troca-fase h4 { margin: 0; font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
  .troca-fase p { margin: 0; font-size: 13px; color: var(--muted); line-height: 1.5; }
  .troca-fase input[type="date"] { font-family: inherit; font-size: 15px; font-weight: 600; color: var(--texto); background: var(--bg); border: 1px solid var(--borda); border-radius: 10px; padding: 10px 12px; width: 100%; color-scheme: dark; }
  .troca-acoes { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
  .troca-acoes .btn { justify-content: center; }
  .troca-acoes .btn svg { width: 16px; height: 16px; }
  .btn.btn-verde-sec { background: var(--accent-soft); border: 1px solid var(--accent-line); color: var(--accent); }
  .btn.btn-verde-sec.confirmar { background: var(--accent); color: #0d1c0e; }
  .timer-card:not(.ativo) .linha-tempo-luz { opacity: .65; }
  .troca-programada { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 12px; border-radius: 10px; background: var(--azul-soft); border: 1px solid rgba(127, 169, 194, .35); font-size: 13px; }
  .troca-programada strong { color: var(--texto); }
  .pill-inativa { background: var(--borda); color: var(--muted); }
  .auto-grid { display: grid; grid-template-columns: 1fr; gap: 14px; }
  @media (min-width: 1100px) { .auto-grid { grid-template-columns: 1fr 1fr; } }
  .timer-card { background: var(--card); border: 1px solid var(--borda); border-radius: var(--raio); box-shadow: var(--sombra); padding: 16px; }
  .timer-card.ativo { border-color: var(--accent-line); }
  .timer-cabecalho { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 4px; }
  .timer-titulo { display: flex; align-items: center; gap: 10px; }
  .timer-titulo h3 { margin: 0; font-size: 16px; font-weight: 800; }
  .timer-titulo .card-icone { width: 36px; height: 36px; }
  .timer-sub { font-size: 12px; color: var(--muted); margin: 0 0 14px; }
  .timer-badges { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
  .badge-fixo { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; color: var(--muted); background: var(--card2); border: 1px solid var(--borda); border-radius: 999px; padding: 4px 10px; white-space: nowrap; }
  .badge-fixo svg { width: 12px; height: 12px; }

  .linha-tempo { position: relative; height: 34px; border-radius: 9px; background: #191d1c; border: 1px solid var(--borda); overflow: hidden; }
  .linha-tempo-luz { position: absolute; top: 0; bottom: 0; background: linear-gradient(180deg, #f1d58a, #e0b955); }
  .linha-tempo-agora { position: absolute; top: -2px; bottom: -2px; width: 2px; background: #fff; box-shadow: 0 0 6px rgba(255,255,255,.7); }
  .linha-tempo-escala { display: flex; justify-content: space-between; font-size: 10px; color: var(--muted); margin: 6px 2px 0; font-variant-numeric: tabular-nums; }

  .timer-campos { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 16px; }
  .campo { display: flex; flex-direction: column; gap: 6px; }
  .campo label, .campo .campo-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; font-weight: 700; }
  .campo-hora { display: flex; align-items: center; gap: 6px; }
  .campo-hora span { color: var(--muted); font-weight: 700; }
  select, input[type="text"], input[type="number"] {
    font-family: inherit; font-size: 15px; font-weight: 600; color: var(--texto);
    background: var(--bg); border: 1px solid var(--borda); border-radius: 10px; padding: 10px 12px; min-width: 0; width: 100%;
  }
  select:focus, input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
  .campo-hora select { width: auto; flex: 1; font-variant-numeric: tabular-nums; }
  .campo-leitura { font-size: 15px; font-weight: 700; padding: 10px 12px; border-radius: 10px; background: var(--card2); border: 1px dashed var(--borda); font-variant-numeric: tabular-nums; }
  .timer-ajuste { display: flex; gap: 6px; margin-top: 10px; }
  .timer-ajuste button { flex: 1; font-size: 12px; font-weight: 700; color: var(--muted); background: var(--card2); border: 1px solid var(--borda); border-radius: 8px; padding: 8px 6px; cursor: pointer; font-family: inherit; }
  .timer-ajuste button:hover { color: var(--texto); }
  .timer-resumo { display: flex; gap: 8px; margin-top: 14px; }
  .timer-resumo div { flex: 1; background: var(--card2); border-radius: 10px; padding: 9px 10px; font-size: 11px; color: var(--muted); }
  .timer-resumo strong { display: block; font-size: 15px; color: var(--texto); margin-top: 2px; font-variant-numeric: tabular-nums; }
  .timer-acoes { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }
  .btn { font-family: inherit; font-size: 13px; font-weight: 700; border-radius: 10px; padding: 11px 16px; cursor: pointer; border: 1px solid var(--borda); background: var(--card2); color: var(--texto); display: inline-flex; align-items: center; justify-content: center; gap: 7px; }
  .btn svg { width: 16px; height: 16px; }
  .btn-primario { background: var(--accent); border-color: var(--accent); color: #0d1c0e; }
  .btn-primario:disabled { background: var(--card2); border-color: var(--borda); color: var(--muted); cursor: not-allowed; }
  .btn-sec { color: var(--muted); }
  .timer-acoes .btn-primario { flex: 1; }
  .auto-nota { font-size: 12px; color: var(--muted); line-height: 1.55; background: var(--card); border: 1px solid var(--borda); border-radius: 12px; padding: 12px 14px; margin-top: 14px; display: flex; gap: 10px; }
  .auto-nota svg { width: 16px; height: 16px; color: var(--azul); flex: none; margin-top: 1px; }

  /* ---------------- Dispositivo / Configurações ---------------- */
  .selos-status { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
  .selo-status { display: inline-flex; align-items: center; gap: 7px; font-size: 12px; font-weight: 600; padding: 6px 12px; border-radius: 999px; border: 1px solid var(--accent-line); background: var(--accent-soft); color: var(--accent); white-space: nowrap; }
  .selo-status i { width: 7px; height: 7px; border-radius: 50%; background: currentColor; flex: none; }
  .selo-status svg { width: 14px; height: 14px; }
  .selo-status.off { border-color: rgba(224, 138, 133, .45); background: var(--red-soft); color: var(--red); }
  .selo-status.neutro { border-color: var(--borda); background: var(--card); color: var(--texto); }
  .selo-status.neutro svg { color: var(--accent); }
  .aviso-offline { display: flex; gap: 14px; background: var(--card); border: 1px solid rgba(224, 138, 133, .45); border-radius: var(--raio); padding: 16px; margin-bottom: 16px; }
  .aviso-offline-icone { width: 40px; height: 40px; border-radius: 11px; background: var(--red-soft); color: var(--red); display: flex; align-items: center; justify-content: center; flex: none; }
  .aviso-offline-icone svg { width: 20px; height: 20px; }
  .aviso-offline strong { display: block; font-size: 15px; margin-bottom: 4px; }
  .aviso-offline p { margin: 0 0 10px; font-size: 13px; color: var(--muted); line-height: 1.55; }
  .aviso-offline a { font-size: 13px; font-weight: 700; color: var(--accent); text-decoration: none; }
  .so-offline { display: none; }
  #aba-inicio.offline .so-offline { display: flex; margin-top: 0; margin-bottom: 16px; }
  #aba-inicio.offline .stats .card { opacity: .55; }
  #aba-inicio.offline .card-legenda { color: var(--muted) !important; font-weight: 400 !important; }
  #aba-inicio.offline .card.alerta { border-color: var(--borda); }
  #aba-inicio.offline .esconder-offline, #aba-inicio.offline .barra-card { display: none; }
  .status-linha.offline { color: var(--red); }
  .disp-card { background: var(--card); border: 1px solid var(--accent-line); border-radius: var(--raio); box-shadow: var(--sombra); padding: 18px; margin-bottom: 16px; }
  .disp-card.off { border-color: rgba(224, 138, 133, .45); }
  .disp-topo { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 14px; }
  .disp-icone { width: 44px; height: 44px; border-radius: 12px; }
  .disp-icone svg { width: 22px; height: 22px; }
  .disp-nome { flex: 1; min-width: 0; }
  .disp-nome h3 { margin: 0 0 2px; font-size: 17px; }
  .disp-nome small { display: block; font-size: 12px; color: var(--muted); }
  .disp-grade { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .disp-item { background: var(--card2); border-radius: 12px; padding: 12px 14px; }
  .disp-item span { display: block; font-size: 12px; color: var(--muted); margin-bottom: 4px; }
  .disp-item strong { font-size: 16px; }
  .disp-rodape { margin: 14px 0 0; font-size: 12px; color: var(--muted); }
  .btn-csv { padding: 8px 12px; font-size: 12px; text-decoration: none; }
  .btn-csv svg { width: 15px; height: 15px; }
  .disp-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; align-items: start; }
  .disp-secao { margin: 0; background: var(--card); border: 1px solid var(--borda); border-radius: var(--raio); box-shadow: var(--sombra); padding: 18px; display: flex; flex-direction: column; gap: 12px; }
  .disp-secao-titulo { margin: 0; display: flex; align-items: center; gap: 10px; font-size: 16px; }
  .disp-secao-titulo svg { width: 20px; height: 20px; color: var(--accent); }
  .disp-sub { font-size: 14px; }
  .disp-texto { margin: -6px 0 0; font-size: 13px; color: var(--muted); line-height: 1.5; }
  .disp-meta { margin: 0; font-size: 12px; color: var(--muted); line-height: 1.5; }
  .tok-caixa { display: flex; align-items: center; gap: 8px; background: var(--bg); border: 1px solid var(--borda); border-radius: 12px; padding: 10px 12px; min-height: 46px; }
  .tok-caixa code { flex: 1; min-width: 0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 14px; color: var(--texto); overflow-wrap: anywhere; }
  .tok-caixa.novo { border-color: var(--accent-line); }
  .tok-caixa .btn-icone { padding: 7px; }
  .disp-acoes { display: grid; grid-template-columns: repeat(auto-fit, minmax(0, 1fr)); gap: 8px; border-top: 1px solid var(--borda); padding-top: 14px; }
  .disp-acoes.um { grid-template-columns: 1fr; border-top: none; padding-top: 0; }
  .disp-acoes .btn svg { width: 16px; height: 16px; }
  .disp-acoes .btn-perigo { background: var(--red-soft); border-color: rgba(224, 138, 133, .4); }
  .passos { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
  .passos li { display: flex; gap: 12px; font-size: 13px; line-height: 1.5; }
  .passos i { flex: none; width: 24px; height: 24px; border-radius: 50%; background: var(--accent-soft); color: var(--accent); font-style: normal; font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
  .vinc-codigo { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 14px; border-radius: 12px; background: var(--accent-soft); border: 1px solid var(--accent-line); }
  .vinc-codigo span, .vinc-codigo small { font-size: 12px; color: var(--muted); }
  .vinc-codigo code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 26px; font-weight: 700; letter-spacing: .12em; color: var(--accent); }
  .atividade { list-style: none; margin: 0; padding: 0; }
  .atividade li { display: flex; align-items: center; gap: 12px; padding: 11px 0; border-top: 1px solid var(--borda); }
  .atividade li:first-child { border-top: none; }
  .atividade li > i { width: 8px; height: 8px; border-radius: 50%; flex: none; background: var(--accent); }
  .atividade li.atencao > i { background: var(--amber); }
  .atividade li.falha > i { background: var(--red); }
  .atividade li div { flex: 1; min-width: 0; }
  .atividade strong { display: block; font-size: 14px; font-weight: 600; }
  .atividade small { display: block; font-size: 12px; color: var(--muted); }
  .atividade time { font-size: 12px; color: var(--muted); }
  .atividade li.vazio { font-size: 13px; color: var(--muted); }
  .placeholder-card { background: var(--card); border: 1px dashed #3a423c; border-radius: var(--raio); padding: 28px 22px; text-align: center; }
  .placeholder-card .card-icone { width: 54px; height: 54px; border-radius: 16px; margin: 0 auto 14px; }
  .placeholder-card .card-icone svg { width: 26px; height: 26px; }
  .placeholder-card h3 { margin: 0 0 8px; font-size: 16px; }
  .placeholder-card p { margin: 0 auto; max-width: 520px; font-size: 13px; color: var(--muted); line-height: 1.6; }
  .placeholder-card .pill { display: inline-block; margin-top: 16px; }

  .cfg-grupo { background: var(--card); border: 1px solid var(--borda); border-radius: var(--raio); box-shadow: var(--sombra); padding: 4px 16px; margin-bottom: 14px; }
  .cfg-grupo-titulo { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; padding: 14px 0 6px; }
  .cfg-grupo-titulo svg { width: 17px; height: 17px; color: var(--accent); }
  .cfg-linha { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 12px 0; border-top: 1px solid var(--borda); }
  .cfg-grupo-titulo + .cfg-linha { border-top: none; }
  .cfg-texto { flex: 1; min-width: 0; }
  .cfg-texto strong { display: block; font-size: 13px; font-weight: 600; }
  .cfg-texto small { display: block; font-size: 12px; color: var(--muted); margin-top: 2px; line-height: 1.4; }
  .cfg-controle { flex: none; width: 190px; display: flex; gap: 6px; align-items: center; }
  .cfg-controle input[type="number"], .cfg-controle input[data-decimal] { width: 100%; text-align: center; }
  .cfg-controle span { color: var(--muted); font-size: 12px; }
  .cfg-linha.desativada { opacity: .55; }
  .cfg-tg { width: auto; flex: 0 1 380px; min-width: 0; }
  .tg-status { flex: 1; min-width: 0; height: 40px; display: flex; align-items: center; gap: 8px; padding: 0 12px; background: var(--bg); border: 1px solid var(--borda); border-radius: 10px; font-size: 13px; font-weight: 600; }
  .tg-status i { width: 7px; height: 7px; border-radius: 50%; background: var(--muted); flex: none; }
  .tg-status.ok i { background: var(--accent); }
  .tg-status span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .cfg-tg .btn { white-space: nowrap; }
  .cfg-avisar { padding-bottom: 0; }
  .cfg-switch { border-top: none; padding: 8px 0; }
  .cfg-switch .cfg-texto span { display: block; font-size: 14px; }
  .link-acao { background: none; border: 0; padding: 0; font: inherit; color: var(--accent); text-decoration: underline; cursor: pointer; }
  .switch.verde[aria-checked="true"] { background: var(--accent); border-color: var(--accent); }
  .switch.verde[aria-checked="true"]::after { background: #0c120e; }
  @media (max-width: 640px) { .cfg-linha-tg { flex-direction: column; align-items: stretch; } .cfg-tg { flex-basis: auto; } }
  input[type="password"] { font-family: inherit; font-size: 15px; color: var(--texto); background: var(--bg); border: 1px solid var(--borda); border-radius: 10px; padding: 10px 12px; width: 100%; }
  .cfg-subpainel { border-top: 1px solid var(--borda); padding: 12px 0 14px; display: flex; flex-direction: column; gap: 10px; }
  .cfg-campos-senha { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; }
  .cfg-nota { margin: 0; font-size: 12px; color: var(--muted); line-height: 1.5; }
  .cfg-acoes { display: flex; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
  .cfg-convite { width: 260px; }
  .cfg-convite input { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: .04em; }
  .btn-icone { padding: 10px; flex: none; }
  .cfg-temp { margin: 0 0 12px; padding: 12px 14px; border-radius: 12px; background: var(--accent-soft); border: 1px solid var(--accent-line); font-size: 13px; line-height: 1.5; }
  .cfg-temp code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 15px; font-weight: 700; color: var(--accent); background: var(--bg); padding: 2px 8px; border-radius: 6px; user-select: all; }
  .usuario-linha .cfg-controle { width: auto; flex-wrap: wrap; justify-content: flex-end; }
  .usuario-linha .btn { padding: 8px 12px; font-size: 12px; }
  .btn-perigo { color: var(--red); }
  .btn-perigo.confirmar { background: var(--red-soft); border-color: var(--red); }
  .cfg-rodape { position: sticky; bottom: 12px; display: none; align-items: center; justify-content: flex-end; gap: 8px; margin-top: 6px; z-index: 5; background: #1f2422; border: 1px solid var(--accent-line); border-radius: 14px; padding: 10px; box-shadow: 0 10px 30px rgba(0,0,0,.55); }
  .cfg-rodape.visivel { display: flex; }
  .cfg-rodape-txt { flex: 1; font-size: 12px; color: var(--muted); padding-left: 4px; }
  @media (max-width: 560px) {
    .cfg-linha { flex-direction: column; align-items: stretch; }
    .cfg-linha.cfg-switch { flex-direction: row; align-items: center; }
    .cfg-controle, .cfg-convite { width: 100%; }
    .usuario-linha .cfg-controle { justify-content: flex-start; }
    .timer-campos { grid-template-columns: 1fr; }
  }

  @media (prefers-reduced-motion: reduce) {
    .skeleton, .roda-agora-pulso, .icone-ventilador.girando, .cmd-btn-spinner, .rele-card.pendente .switch::after { animation: none !important; }
  }
</style>
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
        <p class="barra-nota" id="soloNota" hidden>O sensor enviou 0% nas &uacute;ltimas 3 leituras. Verifique o cabo e a conex&atilde;o do sensor de solo.</p>
      </div>
    </div>

    <!-- ===================== ABA: COMANDOS ===================== -->
    <div class="aba" id="aba-comandos">
      <h1 class="titulo-aba">Comandos</h1>
      <p class="subtitulo-aba" style="margin-bottom:10px;">Os comandos v&atilde;o para uma fila; o ESP32 consulta a cada poucos segundos e aplica assim que chegar nele.</p>

      <div class="modo-pills">
        <div class="modo-pill" id="pillVegetativo">Vegeta&ccedil;&atilde;o<small id="pillVegetativoSub">18h luz &middot; 16:00&ndash;10:00</small></div>
        <div class="modo-pill" id="pillFloracao">Flora&ccedil;&atilde;o<small id="pillFloracaoSub">12h luz &middot; 19:00&ndash;07:00</small></div>
      </div>

      <div class="cmd-grupo">
        <div class="cmd-grupo-cabecalho">
          <div class="cmd-grupo-titulo"><svg data-lucide="sprout"></svg>Modo de cultivo</div>
          <span class="pill pill-neutro" id="cmdModoCultivoPill">--</span>
        </div>
        <a class="cmd-link" href="#automacao" data-ir-aba="automacao"><span>Trocar de fase, agora ou numa data programada, fica na Automa&ccedil;&atilde;o</span><svg data-lucide="arrow-right"></svg></a>
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
          <span style="font-size:12px;color:var(--muted);">toque no interruptor para ligar/desligar</span>
        </div>
        <div class="rele-grid" id="cmdReles"></div>
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
      <p class="subtitulo-aba">O que aconteceu nas &uacute;ltimas 24 horas, posicionado pela hora do dispositivo.</p>
      <section>
        <div class="roda-card">
          <p class="roda-desc">Eventos posicionados pela hora do dispositivo. O arco claro mostra a janela de luz programada para o modo ativo. Toque num ponto do rel&oacute;gio (ou num item da lista, mais abaixo) para ver os detalhes; toque na legenda pra mostrar/esconder cada tipo de evento.</p>

          <div class="roda-svg-wrap"><svg id="rodaEventos" viewBox="-20 -20 400 400"></svg></div>

          <div class="roda-legenda" id="rodaLegenda">
            <button type="button" class="roda-legenda-item ativo" data-filtro="acesso"><i style="background:var(--accent);"></i>Acesso liberado</button>
            <button type="button" class="roda-legenda-item ativo" data-filtro="ligado"><i style="background:var(--amber);"></i>Rel&eacute; ligado</button>
            <button type="button" class="roda-legenda-item ativo" data-filtro="desligado"><i style="background:var(--azul);"></i>Rel&eacute; desligado</button>
            <button type="button" class="roda-legenda-item ativo" data-filtro="alerta"><i style="background:var(--red);"></i>Alertas e avisos</button>
            <button type="button" class="roda-legenda-item ativo" data-filtro="fase"><i style="background:#b48ee0;"></i>Troca de fase</button>
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

    <!-- ===================== ABA: AUTOMAÇÃO ===================== -->
    <div class="aba" id="aba-automacao">
      <h1 class="titulo-aba">Automa&ccedil;&atilde;o</h1>
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
                <option value="automacao">Automa&ccedil;&atilde;o</option>
                <option value="comandos">Comandos</option>
                <option value="historico">Hist&oacute;rico</option>
              </select>
            </div>
          </div>
          <div class="cfg-linha">
            <div class="cfg-texto"><strong>Atualiza&ccedil;&atilde;o autom&aacute;tica</strong><small>O ESP32 envia leituras a cada 1 min, ent&atilde;o intervalos menores n&atilde;o trazem dados novos. Op&ccedil;&otilde;es: 1, 2, 5 ou 10 min.</small></div>
            <div class="cfg-controle">
              <select name="intervalo_atualizacao_seg">
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

<script>
/* ==================== Navegação: sidebar + abas ==================== */
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');
const btnHamburger = document.getElementById('btnHamburger');
const ABAS_VALIDAS = ['inicio', 'relogio', 'automacao', 'comandos', 'historico', 'dispositivo', 'configuracoes'];

function abrirSidebar() { sidebar.classList.add('aberta'); overlay.classList.add('visivel'); }
function fecharSidebar() { sidebar.classList.remove('aberta'); overlay.classList.remove('visivel'); }
btnHamburger.addEventListener('click', abrirSidebar);
overlay.addEventListener('click', fecharSidebar);

let abaAtual = 'inicio';
function mudarAba(aba) {
  if (!ABAS_VALIDAS.includes(aba)) aba = 'inicio';
  abaAtual = aba;
  document.querySelectorAll('.aba').forEach(el => el.classList.toggle('ativa', el.id === 'aba-' + aba));
  document.querySelectorAll('.nav-item[data-aba]').forEach(btn => btn.classList.toggle('ativo', btn.dataset.aba === aba));
  fecharSidebar();
  try { history.replaceState(null, '', '#' + aba); } catch (e) { /* ignora */ }
  // Chart.js precisa recalcular o tamanho ao reaparecer.
  if (aba === 'historico') { setTimeout(() => { [graficoTemperatura, graficoUmidade, graficoSolo, graficoVpd].forEach(g => { if (g) g.resize(); }); }, 0); }
  if (aba === 'relogio') renderizarRoda();
  if (aba === 'automacao') renderizarAutomacao();
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
  intervalo_atualizacao_seg: '60',
  periodo_padrao_historico: '24h',
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
const INTERVALOS_VALIDOS = ['60', '120', '300', '600'];
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

// Solo "sem leitura": 0% exato nas últimas 3 leituras (sensor solto ou sem contato).
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
let graficoVpd = null;
let ultimaLeitura = null;

async function carregarResumo() {
  // Sempre 24h (dados crus) para pegar a leitura mais recente.
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
    const ultimas3 = linhas.slice(-3);
    soloSemLeitura = ultimas3.length === 3 && ultimas3.every(l => l.solo_percentual !== null && Number(l.solo_percentual) === 0);
    document.getElementById('statSolo').textContent = soloSemLeitura ? '—' : fmtSolo(ultima.solo_percentual);
    atualizarVPD(ultima.temp_interna, ultima.umid_interna);
    atualizarSoloBarra(ultima.solo_percentual);
    ultimaLeitura = ultima;
    aplicarAlertasCards(ultima);
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
  if (graficoVpd) { graficoVpd.destroy(); graficoVpd = null; }
  tirarEsqueleto('graficoTemperaturaSkeleton');
  tirarEsqueleto('graficoUmidadeSkeleton');
  tirarEsqueleto('graficoSoloSkeleton');
  tirarEsqueleto('graficoVpdSkeleton');
  document.getElementById('graficoTemperaturaSkeleton').style.display = 'none';
  document.getElementById('graficoUmidadeSkeleton').style.display = 'none';
  document.getElementById('graficoSoloSkeleton').style.display = 'none';
  document.getElementById('graficoVpdSkeleton').style.display = 'none';

  if (linhas.length === 0) {
    mostrarMensagemGrafico('graficoTemperatura', 'sem dados nesse periodo ainda');
    mostrarMensagemGrafico('graficoUmidade', 'sem dados nesse periodo ainda');
    mostrarMensagemGrafico('graficoSolo', 'sem dados nesse periodo ainda');
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
  if (abaAtual === 'automacao') atualizarStatusAutomacao();
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
function corDoEvento(c) {
  if (c.tipo === 'alerta') return c.valor === 'normalizado' ? '#9CC65A' : '#e08a85';
  if (c.tipo === 'acesso') return '#4CAF50';
  if (c.tipo === 'fase') return '#b48ee0';
  if (c.tipo === 'notificacao') return c.valor === 'falhou' ? '#e08a85' : '#6fb3e0';
  return c.valor === 'ligado' ? '#e8c977' : '#7fa9c2';
}
function descricaoEvento(c) {
  if (c.tipo === 'alerta' && c.alvo === 'Solo') return c.valor === 'normalizado' ? 'Sensor de solo voltou a ler' : 'Sensor de solo sem leitura';
  if (c.tipo === 'alerta') return 'Alerta: ' + c.alvo + ' (' + c.valor + ')';
  if (c.tipo === 'acesso') return 'Acesso liberado';
  if (c.tipo === 'notificacao') {
    if (c.alvo === 'telegram') return 'Telegram ' + (c.valor === 'conectado' ? 'conectado' : 'desconectado');
    const nome = { temperatura: 'temperatura', umidade: 'umidade', solo: 'solo', vpd: 'VPD', offline: 'dispositivo offline', timer: 'timer não confirmado', resumo: 'resumo diário', teste: 'teste' }[c.alvo] || c.alvo;
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
  return c.alvo + ' ' + (c.valor === 'ligado' ? 'ligado' : 'desligado');
}
function chaveEventos(eventos) { return eventos.map(ev => (ev.dispositivo_timestamp || ev.criado_em) + '|' + ev.tipo + '|' + ev.alvo + '|' + ev.valor).join(','); }

const filtrosAtivos = { acesso: true, ligado: true, desligado: true, alerta: true, fase: true, janela: true };
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
  return ultimoEventosHoje.filter(c => c.tipo === 'fase' ? filtrosAtivos.fase : (c.tipo === 'alerta' || c.tipo === 'notificacao') ? filtrosAtivos.alerta : c.tipo === 'acesso' ? filtrosAtivos.acesso : (c.valor === 'ligado' ? filtrosAtivos.ligado : filtrosAtivos.desligado));
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
    const janela = janelaDoModo(modoAtual);
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
  if (!document.getElementById('rodaEventos')) return;
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
  ultimoTodosComandos = todos;

  const agora = Date.now();
  const eventosHoje = todos.filter(c => {
    if (c.tipo !== 'acesso' && c.tipo !== 'alerta' && c.tipo !== 'fase' && c.tipo !== 'notificacao' && !(c.tipo === 'rele' && RELES_NA_RODA.includes(c.alvo))) return false;
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
  atualizarCardLuz();
  if (abaAtual === 'automacao') {
    // preserva rascunho em edição
    if (rascunhoTimer.vegetativo === null && rascunhoTimer.floracao === null) renderizarAutomacao();
    else atualizarStatusAutomacao();
  }
}

/* ==================== Comandos (botões) ==================== */
const RELES_CONTROLAVEIS = ['Desumidificador', 'Ventilador', 'Entrada de Ar 1', 'Entrada de Ar 2', 'Saida de Ar'];
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

function atualizarBotoesComando(todosComandos, modoAtual) {
  atualizarPill('cmdModoCultivoPill', modoAtual === 'floracao' ? 'Flora\u00e7\u00e3o' : 'Vegeta\u00e7\u00e3o', 'pill-auto');

  const luzEstado = estadoAtualLuz(todosComandos);
  ultimoLuzEstado = luzEstado;
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

/* ==================== Relés (aba Comandos) ==================== */
// Número do relé na placa (índice em reles[] do firmware).
const RELE_NUMERO = { 'Desumidificador': 1, 'Ventilador': 2, 'Entrada de Ar 1': 4, 'Entrada de Ar 2': 5, 'Saida de Ar': 7 };
const RELE_ROTULO = { 'Saida de Ar': 'Saída de Ar' };
// Comandos enviados e ainda não confirmados: nome -> { valor, ate }.
const relesPendentes = {};
let ultimoTodosComandos = [];

function ultimoRegistroRele(todosComandos, nomeRele) {
  return todosComandos.find(c => c.tipo === 'rele' && c.alvo === nomeRele) || null;
}

function renderizarBotoesReles(todosComandos) {
  const container = document.getElementById('cmdReles');
  if (!container) return;
  container.innerHTML = '';
  const agora = Date.now();
  RELES_CONTROLAVEIS.forEach(nome => {
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
      const desde = new Date(String(timestampEvento(ultimo)).replace(' ', 'T')).getTime();
      const dur = isNaN(desde) ? '' : formatarDuracao(agora - desde);
      detalhe = !dur ? '' : (dur === 'agora mesmo' ? dur : 'há ' + dur);
    }

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
        '<button type="button" class="switch" role="switch" aria-checked="' + ligado + '" aria-label="' + escapeHtml(RELE_ROTULO[nome] || nome) + '" data-rele="' + escapeHtml(nome) + '"' + (pendente ? ' disabled' : '') + '></button>' +
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
  const ok = await enviarComando('rele', nome, novoValor, null);
  if (!ok) {
    delete relesPendentes[nome];
    renderizarBotoesReles(ultimoTodosComandos);
    return;
  }
  // Reconsulta algumas vezes até o ESP32 confirmar.
  [6000, 15000, 30000].forEach(ms => setTimeout(() => { carregarRodaEventos().catch(() => {}); }, ms));
});

/* ==================== Automação (timers de luz) ==================== */
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

async function postAutomacao(corpo, botao) {
  const rotulo = botao ? botao.innerHTML : '';
  if (botao) { botao.disabled = true; botao.innerHTML = '<span class="cmd-btn-spinner"></span>enviando'; }
  try {
    const r = await fetch('automacao.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(corpo) });
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

function atualizarStatusAutomacao() {
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

function renderizarAutomacao() {
  renderizarTimerCard('vegetativo');
  renderizarTimerCard('floracao');
  atualizarStatusAutomacao();
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
      const d = await postAutomacao({ acao: 'ativar', fase: modo }, acao);
      if (d) {
        mostrarToast('\u2713 ' + NOME_MODO[modo] + ' ativada \u2014 o ESP32 aplica em alguns segundos', 'ok');
        ultimoModoAtual = modo;
        renderizarAutomacao();
        atualizarSeloFase();
        [2000, 8000, 20000].forEach(ms => setTimeout(() => { atualizarTudo().catch(() => {}); }, ms));
      }
      return;
    }
    if (acao.dataset.acao === 'programar') {
      const data = card.querySelector('[data-campo="data-troca"]').value;
      if (!data) { mostrarToast('escolha uma data', 'erro'); return; }
      const d = await postAutomacao({ acao: 'programar', fase: modo, data }, acao);
      if (d) { mostrarToast('\u2713 troca programada', 'ok'); renderizarAutomacao(); carregarRodaEventos().catch(() => {}); }
      return;
    }
    if (acao.dataset.acao === 'cancelar-troca') {
      const d = await postAutomacao({ acao: 'cancelar', id: Number(acao.dataset.id) }, acao);
      if (d) { mostrarToast('troca programada cancelada', 'ok'); renderizarAutomacao(); carregarRodaEventos().catch(() => {}); }
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
        renderizarAutomacao();
        renderizarRoda();
        [6000, 15000, 30000].forEach(ms => setTimeout(() => { carregarConfiguracoes().then(() => { if (abaAtual === 'automacao') renderizarAutomacao(); }).catch(() => {}); }, ms));
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

// Valores antigos fora das opções atuais (ex.: atualização de 30 s) viram o padrão.
function normalizarConfig(c) {
  if (!INTERVALOS_VALIDOS.includes(String(c.intervalo_atualizacao_seg))) c.intervalo_atualizacao_seg = '60';
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
  if (ultimaLeitura) { atualizarSoloBarra(ultimaLeitura.solo_percentual); aplicarAlertasCards(ultimaLeitura); }

  atualizarPillsModo();
  if (!formConfigSujo()) preencherFormConfig();

  if (!INTERVALOS_VALIDOS.includes(String(CONFIG.intervalo_atualizacao_seg))) CONFIG.intervalo_atualizacao_seg = '60';
  const seg = Number(CONFIG.intervalo_atualizacao_seg);
  if (seg !== intervaloAtualizacaoSeg) {
    intervaloAtualizacaoSeg = seg;
    armarAtualizacao();
  }
  if (abaAtual === 'automacao') renderizarAutomacao();
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
const CAMPOS_CONFIG = ['nome_cultivo', 'pagina_inicial', 'intervalo_atualizacao_seg', 'periodo_padrao_historico', 'solo_ideal_min', 'solo_ideal_max', 'temp_alerta_min', 'temp_alerta_max', 'umid_alerta_min', 'umid_alerta_max', 'vpd_vegetativo_min', 'vpd_vegetativo_max', 'vpd_floracao_min', 'vpd_floracao_max', 'dispositivo_sem_dados_min'];
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
    carregarStatus().catch(() => {});
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
// Vem de status.php; os segundos são do servidor e avançam aqui a cada segundo.
let STATUS = null;
let statusRecebidoEm = 0;

function segundosSemDados() {
  if (!STATUS || STATUS.segundos_sem_dados === null) return null;
  return STATUS.segundos_sem_dados + Math.round((Date.now() - statusRecebidoEm) / 1000);
}
function dispositivoOffline() {
  const seg = segundosSemDados();
  return !STATUS || seg === null || seg > STATUS.limite_min * 60;
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

async function carregarStatus() {
  STATUS = await buscarJson('status.php');
  statusRecebidoEm = Date.now();
  renderizarStatus();
}

function renderizarStatus() {
  if (!STATUS) return;
  const seg = segundosSemDados();
  const off = dispositivoOffline();
  const selo = document.getElementById('seloConexao');
  selo.classList.toggle('off', off);
  selo.querySelector('span').textContent = seg === null ? 'Offline · sem leituras ainda'
    : off ? 'Offline · sem dados há ' + textoHa(seg) : 'Online · leitura há ' + textoHa(seg);

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
    'Enviando leituras a cada 1 min · ' + Number(STATUS.leituras_24h).toLocaleString('pt-BR') + ' recebidas nas últimas 24h';
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
  intervaloAtualizacaoId = setInterval(atualizarTudo, intervaloAtualizacaoSeg * 1000);
}
// Aba em segundo plano: pausa a busca; ao voltar, atualiza se estiver vencido e retoma.
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'hidden') { armarAtualizacao(); return; }
  if (!ultimaAtualizacaoEm || Date.now() - ultimaAtualizacaoEm >= intervaloAtualizacaoSeg * 1000) atualizarTudo();
  armarAtualizacao();
});

async function atualizarTudo() {
  const erros = [];
  try { await carregarConfiguracoes(); } catch (e) { erros.push('configura\u00e7\u00f5es: ' + e.message); }
  try { await carregarStatus(); } catch (e) { erros.push('status: ' + e.message); }
  try { await carregarResumo(); } catch (e) { erros.push('resumo: ' + e.message); }
  try { await carregarGraficos(periodoAtual); } catch (e) { erros.push('graficos: ' + e.message); }
  if (abaAtual === 'dispositivo') { try { await carregarDispositivo(); } catch (e) { erros.push('dispositivo: ' + e.message); } }
  try { await carregarRodaEventos(); } catch (e) {
    erros.push('roda de eventos: ' + e.message);
    document.getElementById('rodaStatus').innerHTML = '<div class="roda-status-item">erro ao carregar eventos (' + escapeHtml(e.message) + ')</div>';
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
  const hash = location.hash.replace('#', '');
  mudarAba(ABAS_VALIDAS.includes(hash) ? hash : CONFIG.pagina_inicial);
  carregarUsuarios().catch(e => { console.warn('usuarios:', e.message); });
  carregarNotificacoes().catch(e => { console.warn('notificacoes:', e.message); });
  atualizarTudo();
})();
</script>
</body>
</html>