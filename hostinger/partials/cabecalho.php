<?php // Cabeçalho comum: menu lateral (computador) e barra superior (celular). Usa $usuarioLogado. ?>
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
      <img class="logo-marca" src="img/logo.png" alt="" width="70" height="70">
      <span class="marca-nome" id="nomeCultivoSidebar">Smart Demetra</span>
    </div>
    <nav class="sidebar-nav">
      <button type="button" class="nav-item ativo" data-aba="inicio"><svg data-lucide="home"></svg>In&iacute;cio</button>
      <button type="button" class="nav-item" data-aba="relogio"><svg data-lucide="clock"></svg>Rel&oacute;gio de eventos</button>
      <button type="button" class="nav-item" data-aba="fotoperiodo"><svg data-lucide="timer"></svg>Fotoper&iacute;odo</button>
      <button type="button" class="nav-item" data-aba="comandos"><svg data-lucide="sliders-horizontal"></svg>Comandos</button>
      <button type="button" class="nav-item" data-aba="caixa"><svg data-lucide="glass-water"></svg>Caixa d&apos;&aacute;gua</button>
      <button type="button" class="nav-item" data-aba="historico"><svg data-lucide="history"></svg>Hist&oacute;rico</button>
      <div class="nav-secao">Sistema</div>
      <button type="button" class="nav-item" data-aba="dispositivo"><svg data-lucide="cpu"></svg>Dispositivo</button>
      <button type="button" class="nav-item" data-aba="configuracoes"><svg data-lucide="settings"></svg>Configura&ccedil;&otilde;es</button>
    </nav>
    <div class="sidebar-rodape">
      <div class="sidebar-usuario"><svg data-lucide="user"></svg><span>Logado como <strong><?= htmlspecialchars($usuarioLogado, ENT_QUOTES, 'UTF-8') ?></strong></span></div>
      <a href="logout.php" class="nav-item nav-sair"><svg data-lucide="log-out"></svg>Sair</a>
    </div>
  </aside>

  <main class="conteudo">

    <header class="topo-mobile">
      <div class="topo-logo">
        <img class="logo-marca" src="img/logo.png" alt="" width="66" height="66">
        <span class="marca-nome" id="nomeCultivoTopo">Smart Demetra</span>
      </div>
      <button type="button" class="btn-hamburger" id="btnHamburger" aria-label="Abrir menu"><svg data-lucide="menu"></svg></button>
    </header>
