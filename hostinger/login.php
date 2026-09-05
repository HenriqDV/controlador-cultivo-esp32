<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

iniciar_sessao();

if (sessao_logada()) {
    header('Location: index.php');
    exit;
}

$erro = '';
$mostrarSplash = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = ip_cliente();
    $bloqueadoSegundos = login_bloqueado_segundos($ip);

    if ($bloqueadoSegundos > 0) {
        $minutos = (int) ceil($bloqueadoSegundos / 60);
        $erro = 'Muitas tentativas erradas. Tente novamente em ' . $minutos . ' min.';
    } else {
        $usuario = trim((string) ($_POST['usuario'] ?? ''));
        $senha = (string) ($_POST['senha'] ?? '');

        if ($usuario === WEB_LOGIN_USUARIO && password_verify($senha, WEB_LOGIN_SENHA_HASH)) {
            login_limpar_tentativas($ip);
            session_regenerate_id(true);
            $_SESSION['logado'] = true;
            $_SESSION['usuario'] = $usuario;
            // Em vez de redirecionar na hora, renderiza uma splash screen
            // curta (sem intera\u00e7\u00e3o) e deixa o JS levar pro painel.
            $mostrarSplash = true;
        } else {
            login_registrar_falha($ip);
            $erro = 'Usuário ou senha incorretos.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Smart Demetra — Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
    --raio: 16px;
    --raio-sm: 10px;
    --sombra: 0 12px 32px rgba(0, 0, 0, .4);
    --fonte: "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background:
      radial-gradient(1000px 500px at 20% -10%, rgba(76, 175, 80, .08), transparent 60%),
      var(--bg);
    color: var(--texto);
    font-family: var(--fonte);
    padding: 20px;
    -webkit-font-smoothing: antialiased;
  }
  .login-card {
    width: 100%;
    max-width: 340px;
    background: var(--card);
    border: 1px solid var(--borda);
    border-radius: var(--raio);
    padding: 30px 26px;
    box-shadow: var(--sombra);
  }
  .login-logo { display: flex; align-items: center; gap: 10px; margin: 0 0 4px; }
  .login-logo-svg { width: 30px; height: 34px; overflow: visible; flex: none; }
  .login-logo-svg path { fill: none; transform-box: fill-box; }
  .login-logo span {
    font-weight: 800;
    font-size: 22px;
    letter-spacing: -.01em;
    color: var(--texto);
  }

  /* Estado final (sem anima\u00e7\u00e3o / reduced-motion): broto ereto e verde,
     igual \u00e0 logo est\u00e1tica do painel. */
  .logo-caule { stroke: var(--accent); stroke-width: 2.4; stroke-linecap: round; transform-origin: 50% 100%; }
  .logo-folha-esq, .logo-folha-dir { stroke: var(--accent); stroke-width: 1.6; stroke-linejoin: round; fill: var(--accent-soft); }
  .logo-folha-esq { transform-origin: 88% 90%; }
  .logo-folha-dir { transform-origin: 12% 92%; }

  /* Anima\u00e7\u00e3o de entrada: a florzinha come\u00e7a murcha (curvada, tom \u00e2mbar)
     e firma-se em p\u00e9, ficando verde \u2014 s\u00f3 roda quando o sistema n\u00e3o pede
     movimento reduzido. */
  @media (prefers-reduced-motion: no-preference) {
    .logo-caule { animation: caulhaFirmar 1.7s cubic-bezier(.34, 1.4, .4, 1) both; }
    .logo-folha-esq { animation: folhaEsqFirmar 1.7s cubic-bezier(.34, 1.4, .4, 1) .12s both; }
    .logo-folha-dir { animation: folhaDirFirmar 1.7s cubic-bezier(.34, 1.4, .4, 1) .26s both; }
    .login-logo span { animation: logoTextoEntrar .5s ease .5s both; }
  }
  @keyframes caulhaFirmar {
    0%   { stroke: var(--amber); transform: rotate(-16deg) scaleY(.92); }
    60%  { stroke: var(--amber); }
    100% { stroke: var(--accent); transform: rotate(0deg) scaleY(1); }
  }
  @keyframes folhaEsqFirmar {
    0%   { stroke: var(--amber); fill: var(--amber-soft); opacity: .8; transform: rotate(-30deg) translate(1.5px, 3px) scale(.8); }
    100% { stroke: var(--accent); fill: var(--accent-soft); opacity: 1; transform: rotate(0deg) translate(0, 0) scale(1); }
  }
  @keyframes folhaDirFirmar {
    0%   { stroke: var(--amber); fill: var(--amber-soft); opacity: .8; transform: rotate(26deg) translate(-1.5px, 3px) scale(.8); }
    100% { stroke: var(--accent); fill: var(--accent-soft); opacity: 1; transform: rotate(0deg) translate(0, 0) scale(1); }
  }
  @keyframes logoTextoEntrar {
    0%   { opacity: 0; transform: translateX(-4px); }
    100% { opacity: 1; transform: translateX(0); }
  }
  .eyebrow {
    display: block;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--accent);
    margin: 0 0 8px;
  }
  p.subtitulo { margin: 0 0 22px; color: var(--muted); font-size: 13px; line-height: 1.5; }

  label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 14px; font-weight: 600; }
  .campo-com-icone { position: relative; margin-top: 6px; }
  .campo-com-icone svg {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    width: 16px; height: 16px; color: var(--muted); pointer-events: none;
  }
  input {
    width: 100%;
    padding: 11px 12px 11px 38px;
    border-radius: var(--raio-sm);
    border: 1px solid var(--borda);
    background: var(--bg);
    color: var(--texto);
    font-family: var(--fonte);
    font-size: 14px;
    transition: border-color .2s ease;
  }
  input:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; border-color: var(--accent); }

  .campo-com-icone input[data-com-alternar] { padding-right: 38px; }
  .btn-alternar-senha {
    position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
    width: 28px; height: 28px;
    display: flex; align-items: center; justify-content: center;
    background: none; border: none; border-radius: 7px;
    color: var(--muted); cursor: pointer; padding: 0; margin: 0;
    transition: color .2s ease, background-color .2s ease;
  }
  .btn-alternar-senha svg { width: 16px; height: 16px; }
  .btn-alternar-senha:hover { color: var(--texto); background: var(--card2); }
  .btn-alternar-senha:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; }

  button {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px;
    border: none;
    border-radius: var(--raio-sm);
    background: var(--accent);
    color: #0d1c0e;
    font-family: var(--fonte);
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    margin-top: 6px;
    transition: filter .2s ease, transform .1s ease;
  }
  button svg { width: 17px; height: 17px; }
  button:hover { filter: brightness(1.08); }
  button:active { transform: scale(.98); filter: brightness(.95); }

  .erro {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--red-soft);
    border: 1px solid var(--red);
    color: var(--red);
    border-radius: var(--raio-sm);
    padding: 10px 12px;
    font-size: 13px;
    margin-bottom: 16px;
  }
  .erro svg { width: 16px; height: 16px; flex: none; }

  /* ---------------- Splash screen (p\u00f3s-login, curta e sem intera\u00e7\u00e3o) ---------------- */
  .splash { display: flex; flex-direction: column; align-items: center; gap: 20px; }
  .splash-logo { display: flex; align-items: center; gap: 11px; }
  .splash-logo-svg { width: 34px; height: 40px; }
  .splash-logo span { font-weight: 800; font-size: 25px; letter-spacing: -.01em; color: var(--texto); }
  .splash-texto { font-size: 12px; color: var(--muted); margin: 0; }
  .splash-barra {
    width: 140px; height: 4px; border-radius: 999px;
    background: var(--card2); border: 1px solid var(--borda);
    overflow: hidden;
  }
  .splash-barra-preenchimento { height: 100%; width: 40%; border-radius: 999px; background: var(--accent); animation: splashCarregar 1.1s ease-in-out infinite; }
  @keyframes splashCarregar { 0% { transform: translateX(-120%); } 100% { transform: translateX(300%); } }
  @media (prefers-reduced-motion: reduce) {
    .splash-barra-preenchimento { animation: none; width: 100%; }
  }
</style>
</head>
<body>
<?php if ($mostrarSplash): ?>
  <div class="splash" role="status" aria-live="polite">
    <div class="splash-logo">
      <svg class="login-logo-svg splash-logo-svg" viewBox="0 0 28 32" xmlns="http://www.w3.org/2000/svg">
        <path class="logo-caule" d="M14 29 C14 22 14 15 14 8"/>
        <path class="logo-folha-esq" d="M14 15 C7 13 3 16 2 22 C9 23 14 20 14 15 Z"/>
        <path class="logo-folha-dir" d="M14 9 C20 7 25 10 26 15 C19 17 14 13 14 9 Z"/>
      </svg>
      <span>Smart Demetra</span>
    </div>
    <p class="splash-texto">Carregando o painel&hellip;</p>
    <div class="splash-barra"><div class="splash-barra-preenchimento"></div></div>
  </div>
  <noscript><meta http-equiv="refresh" content="1;url=index.php"></noscript>
  <script>
    // Curta e sem intera\u00e7\u00e3o: s\u00f3 uma pausa breve antes de ir pro painel.
    setTimeout(() => { window.location.href = 'index.php'; }, 1200);
  </script>
<?php else: ?>
  <form method="post" class="login-card" autocomplete="off">
    <span class="eyebrow">Painel de cultivo</span>
    <div class="login-logo">
      <svg class="login-logo-svg" viewBox="0 0 28 32" xmlns="http://www.w3.org/2000/svg">
        <path class="logo-caule" d="M14 29 C14 22 14 15 14 8"/>
        <path class="logo-folha-esq" d="M14 15 C7 13 3 16 2 22 C9 23 14 20 14 15 Z"/>
        <path class="logo-folha-dir" d="M14 9 C20 7 25 10 26 15 C19 17 14 13 14 9 Z"/>
      </svg>
      <span>Smart Demetra</span>
    </div>
    <p class="subtitulo">Entre com suas credenciais para acessar o painel.</p>
    <?php if ($erro !== ''): ?>
      <div class="erro"><svg data-lucide="alert-triangle"></svg><span><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></span></div>
    <?php endif; ?>
    <label>
      Usuário
      <div class="campo-com-icone">
        <svg data-lucide="user"></svg>
        <input type="text" name="usuario" required autofocus autocomplete="username">
      </div>
    </label>
    <label>
      Senha
      <div class="campo-com-icone">
        <svg data-lucide="lock"></svg>
        <input type="password" name="senha" id="campoSenha" data-com-alternar required autocomplete="current-password">
        <button type="button" class="btn-alternar-senha" id="btnAlternarSenha" aria-label="Mostrar senha" aria-pressed="false">
          <svg data-lucide="eye"></svg>
        </button>
      </div>
    </label>
    <button type="submit"><svg data-lucide="log-in"></svg>Entrar</button>
  </form>
  <script>
    if (window.lucide) lucide.createIcons();

    const campoSenha = document.getElementById('campoSenha');
    const btnAlternarSenha = document.getElementById('btnAlternarSenha');
    if (campoSenha && btnAlternarSenha) {
      btnAlternarSenha.addEventListener('click', () => {
        const visivel = campoSenha.type === 'text';
        campoSenha.type = visivel ? 'password' : 'text';
        btnAlternarSenha.setAttribute('aria-pressed', String(!visivel));
        btnAlternarSenha.setAttribute('aria-label', visivel ? 'Mostrar senha' : 'Ocultar senha');
        btnAlternarSenha.innerHTML = '<svg data-lucide="' + (visivel ? 'eye' : 'eye-off') + '"></svg>';
        if (window.lucide) lucide.createIcons();
      });
    }
  </script>
<?php endif; ?>
</body>
</html>