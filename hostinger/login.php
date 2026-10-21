<?php
declare(strict_types=1);

// Telas de acesso: login, cadastro (com código de convite), resultado do cadastro,
// orientação de senha esquecida e carregamento após o login.
require __DIR__ . '/config.php';
require __DIR__ . '/migracoes.php';
require __DIR__ . '/auth.php';

iniciar_sessao();
garantir_schema_v2();
auth_restaurar_sessao();

$telasGet = ['login', 'cadastro', 'esqueci'];
$tela = in_array($_GET['tela'] ?? '', $telasGet, true) ? $_GET['tela'] : 'login';

if (sessao_logada() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$erroLogin = '';
$errosCadastro = [];
$cadastroOk = null;
$valorUsuario = isset($_GET['u']) ? mb_substr(trim((string) $_GET['u']), 0, 120) : '';
$rascunho = $_SESSION['cadastro_rascunho'] ?? ['usuario' => '', 'email' => ''];
$conviteAtivo = auth_codigo_convite() !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');
    $ip = ip_cliente();

    if ($acao === 'entrar') {
        $tela = 'login';
        $valorUsuario = mb_substr(trim((string) ($_POST['usuario'] ?? '')), 0, 120);
        $bloqueio = auth_bloqueio_segundos($ip, $valorUsuario);
        $msgBloqueio = auth_msg_bloqueio($bloqueio);
        if (!csrf_valido($_POST['csrf'] ?? null)) {
            $erroLogin = 'A página expirou. Tente entrar novamente.';
        } elseif ($bloqueio > 0) {
            $erroLogin = $msgBloqueio;
        } else {
            $conta = auth_verificar_credenciais($valorUsuario, (string) ($_POST['senha'] ?? ''));
            if ($conta) {
                auth_limpar_falhas($ip, $valorUsuario);
                auth_iniciar_sessao_usuario($conta[0], $conta[1], $conta[2], $conta[3]);
                if (!empty($_POST['lembrar'])) {
                    auth_lembrar($conta[0]);
                }
                $tela = 'carregando';
            } else {
                auth_registrar_falha($ip, $valorUsuario);
                $erroLogin = 'Usuário ou senha incorretos.';
            }
        }
    } elseif ($acao === 'cadastrar') {
        $usuarioNovo = (string) ($_POST['usuario'] ?? '');
        $emailNovo = (string) ($_POST['email'] ?? '');
        $_SESSION['cadastro_rascunho'] = $rascunho = [
            'usuario' => mb_substr(mb_strtolower(trim($usuarioNovo)), 0, 30),
            'email' => mb_substr(trim($emailNovo), 0, 120),
        ];
        $codigo = auth_codigo_convite();
        $bloqueio = auth_bloqueio_segundos($ip, '#convite');
        $msgBloqueio = auth_msg_bloqueio($bloqueio);

        if (!csrf_valido($_POST['csrf'] ?? null)) {
            $errosCadastro[] = 'A página expirou. Preencha o cadastro novamente.';
        } elseif ($bloqueio > 0) {
            $errosCadastro[] = $msgBloqueio;
        } elseif ($codigo === null) {
            $errosCadastro[] = 'O cadastro está desativado. Peça um código de convite ao administrador.';
        } elseif (!hash_equals($codigo, trim((string) ($_POST['convite'] ?? '')))) {
            auth_registrar_falha($ip, '#convite');
            $errosCadastro[] = 'Código de convite inválido.';
        }

        if (!$errosCadastro) {
            $errosCadastro = auth_cadastrar($usuarioNovo, $emailNovo, (string) ($_POST['senha'] ?? ''), (string) ($_POST['confirma'] ?? ''));
        }

        if ($errosCadastro) {
            $tela = 'erro_cadastro';
        } else {
            unset($_SESSION['cadastro_rascunho']);
            $cadastroOk = ['usuario' => mb_strtolower(trim($usuarioNovo)), 'email' => mb_strtolower(trim($emailNovo))];
            $tela = 'cadastro_ok';
        }
    }
}

$csrf = csrf_token();

function h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

// Ícones (traços no estilo Lucide) com stroke = currentColor.
function ic(string $nome, int $tam = 16): string
{
    $p = [
        'broto' => '<path d="M12 22V9"/><path d="M12 11c0-4 3-6 8-6 0 4-3 6-8 6z"/><path d="M12 9C12 6 9.5 4 5 4c0 3 2.5 5 7 5z"/>',
        'usuario' => '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
        'cadeado' => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'olho' => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
        'olho-fechado' => '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/>',
        'entrar' => '<path d="m10 17 5-5-5-5"/><path d="M15 12H3"/><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>',
        'usuario-mais' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/>',
        'escudo' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'voltar' => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'email' => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'alerta' => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'repetir' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'convite' => '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/>',
        'chave' => '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>',
    ][$nome] ?? '';
    return '<svg width="' . $tam . '" height="' . $tam . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

function campo_senha(string $id, string $nome, string $rotulo, string $placeholder, string $autocomplete, string $extra = ''): string
{
    return '<div class="campo"><label for="' . $id . '">' . $rotulo . '</label>'
        . '<div class="entrada com-botao">' . ic('cadeado')
        . '<input id="' . $id . '" name="' . $nome . '" type="password" placeholder="' . $placeholder . '" autocomplete="' . $autocomplete . '" required ' . $extra . '>'
        . '<button type="button" class="alternar-senha" data-alvo="' . $id . '" aria-label="Mostrar senha" aria-pressed="false">' . ic('olho') . '</button>'
        . '</div></div>';
}

$titulos = [
    'login' => 'Acesso',
    'cadastro' => 'Cadastro',
    'esqueci' => 'Recuperar acesso',
    'erro_cadastro' => 'Erro no cadastro',
    'cadastro_ok' => 'Cadastro concluído',
    'carregando' => 'Carregando',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Smart Demetra — <?= h($titulos[$tela]) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    color-scheme: dark;
    --bg: #111613;
    --card: #1B201E;
    --borda: #272D29;
    --campo: #101512;
    --campo-borda: #2A302B;
    --sec: #151A17;
    --sec-borda: #2F3631;
    --texto: #E8EAE8;
    --texto-2: #C2C7C3;
    --muted: #8A908B;
    --placeholder: #7A817B;
    --verde: #49AF50;
    --verde-tinta: #0C120E;
    --verde-glow: rgba(73, 175, 80, .18);
    --vermelho: #E27064;
    --amarelo: #DFC269;
    --fonte: Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  }
  * { box-sizing: border-box; }
  [hidden] { display: none !important; }
  html, body { min-height: 100%; }
  body {
    margin: 0; min-height: 100vh; background: var(--bg); color: var(--texto); font-family: var(--fonte);
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 20px;
    padding: 24px 16px; -webkit-font-smoothing: antialiased;
  }
  a { color: var(--verde); }
  input::placeholder { color: var(--placeholder); }

  .cartao {
    width: 100%; max-width: 400px; background: var(--card); border: 1px solid var(--borda); border-radius: 20px;
    padding: 28px 24px; display: flex; flex-direction: column; gap: 22px;
  }
  .cartao.erro { border-color: rgba(226, 112, 100, .35); }
  .cartao.sucesso { border-color: rgba(73, 175, 80, .45); }

  .marca { display: flex; flex-direction: column; gap: 10px; }
  .sobrelinha { font-size: 11px; font-weight: 600; letter-spacing: .08em; color: var(--verde); text-transform: uppercase; }
  .logo { display: flex; align-items: center; gap: 10px; color: var(--verde); }
  .logo h1 { margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -.02em; color: var(--texto); }
  .marca p { margin: 0; font-size: 14px; line-height: 20px; color: var(--muted); }

  .cabecalho-mini { display: flex; align-items: center; gap: 8px; color: var(--verde); }
  .cabecalho-mini strong { font-size: 15px; font-weight: 700; letter-spacing: -.01em; color: var(--texto); }
  .cabecalho-mini span { margin-left: auto; font-size: 11px; font-weight: 600; letter-spacing: .08em; color: var(--muted); }

  form { display: flex; flex-direction: column; gap: 14px; margin: 0; }
  .campo { display: flex; flex-direction: column; gap: 6px; }
  .campo label { font-size: 12px; font-weight: 500; color: var(--muted); }
  .entrada {
    display: flex; align-items: center; gap: 10px; height: 48px; padding: 0 14px;
    background: var(--campo); border: 1px solid var(--campo-borda); border-radius: 12px; color: var(--muted);
    transition: border-color .15s ease, box-shadow .15s ease, color .15s ease;
  }
  .entrada.com-botao { padding-right: 4px; }
  .entrada:focus-within { border-color: var(--verde); box-shadow: 0 0 0 3px var(--verde-glow); color: var(--verde); }
  .entrada.invalida { border-color: rgba(226, 112, 100, .6); }
  .entrada svg { flex: none; }
  .entrada input {
    flex: 1; min-width: 0; height: 100%; background: transparent; border: 0; outline: none;
    color: var(--texto); font: 400 15px var(--fonte);
  }
  .alternar-senha {
    width: 40px; height: 40px; flex: none; display: flex; align-items: center; justify-content: center;
    background: transparent; border: 0; border-radius: 8px; color: var(--muted); cursor: pointer;
  }
  .alternar-senha:hover { color: var(--texto); }
  .alternar-senha:focus-visible { outline: 2px solid var(--verde); }

  .linha-opcoes { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .lembrar { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--texto-2); cursor: pointer; min-height: 44px; }
  .lembrar input { width: 18px; height: 18px; margin: 0; accent-color: var(--verde); }
  .link-pequeno { font-size: 13px; font-weight: 500; text-decoration: none; min-height: 44px; display: flex; align-items: center; }

  .btn {
    height: 48px; display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%;
    border-radius: 12px; font: 600 15px var(--fonte); text-decoration: none; cursor: pointer; border: 0;
    transition: filter .15s ease, transform .1s ease;
  }
  .btn:active { transform: scale(.985); }
  .btn-primario { background: var(--verde); color: var(--verde-tinta); }
  .btn-primario:hover { filter: brightness(1.07); }
  .btn-primario:disabled { background: #2A302B; color: var(--muted); cursor: not-allowed; transform: none; filter: none; }
  .btn-secundario { background: var(--sec); border: 1px solid var(--sec-borda); color: var(--texto); }
  .btn-secundario:hover { border-color: #3b443d; }
  .btn:focus-visible, .link-pequeno:focus-visible, .voltar:focus-visible { outline: 2px solid var(--verde); outline-offset: 2px; }
  .acoes { display: flex; flex-direction: column; gap: 10px; }

  .separador { display: flex; align-items: center; gap: 12px; font-size: 12px; color: var(--muted); }
  .separador::before, .separador::after { content: ""; flex: 1; height: 1px; background: var(--borda); }

  .rodape-seguro { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--muted); }
  .rodape-texto { margin: 0; font-size: 13px; color: var(--muted); }
  .rodape-texto a { font-weight: 600; text-decoration: none; }

  .voltar { align-self: flex-start; display: flex; align-items: center; gap: 6px; min-height: 36px; font-size: 13px; font-weight: 500; color: var(--texto-2); text-decoration: none; margin-bottom: -6px; }
  .voltar:hover { color: var(--texto); }

  .aviso {
    display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border-radius: 12px; font-size: 13px; line-height: 19px;
    background: rgba(226, 112, 100, .08); border: 1px solid rgba(226, 112, 100, .28); color: var(--texto);
  }
  .aviso svg { flex: none; margin-top: 1px; color: var(--vermelho); }
  .aviso.neutro { background: rgba(223, 194, 105, .08); border-color: rgba(223, 194, 105, .3); }
  .aviso.neutro svg { color: var(--amarelo); }

  .forca { display: flex; flex-direction: column; gap: 6px; padding-top: 4px; }
  .forca-barras { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; }
  .forca-barras i { height: 4px; border-radius: 2px; background: var(--campo-borda); transition: background-color .2s ease; }
  .forca-texto { display: flex; justify-content: space-between; gap: 12px; font-size: 12px; line-height: 16px; color: var(--muted); }
  .forca-texto strong { font-weight: 600; white-space: nowrap; }
  .dica-campo { font-size: 12px; line-height: 16px; color: var(--muted); }
  .dica-campo.erro { color: var(--vermelho); }

  .resultado { display: flex; flex-direction: column; align-items: center; gap: 14px; text-align: center; }
  .resultado-icone { width: 64px; height: 64px; border-radius: 20px; display: flex; align-items: center; justify-content: center; }
  .resultado-icone.erro { background: rgba(226, 112, 100, .14); color: var(--vermelho); }
  .resultado-icone.sucesso { background: rgba(73, 175, 80, .16); color: var(--verde); }
  .resultado-icone.info { background: rgba(223, 194, 105, .14); color: var(--amarelo); }
  .resultado h1 { margin: 0; font-size: 22px; line-height: 28px; font-weight: 700; letter-spacing: -.02em; }
  .resultado p { margin: 0; font-size: 14px; line-height: 20px; color: var(--muted); }

  .lista-erros {
    margin: 0; padding: 14px 16px; list-style: none; display: flex; flex-direction: column; gap: 10px;
    background: rgba(226, 112, 100, .08); border: 1px solid rgba(226, 112, 100, .28); border-radius: 12px;
  }
  .lista-erros li { display: flex; align-items: flex-start; gap: 10px; font-size: 14px; line-height: 20px; }
  .lista-erros svg { flex: none; margin-top: 3px; color: var(--vermelho); }

  .dados { margin: 0; padding: 4px 16px; display: flex; flex-direction: column; background: var(--sec); border: 1px solid var(--borda); border-radius: 12px; }
  .dados div { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 0; }
  .dados div + div { border-top: 1px solid #252B27; }
  .dados dt { font-size: 13px; color: var(--muted); }
  .dados dd { margin: 0; font-size: 14px; font-weight: 600; overflow-wrap: anywhere; text-align: right; }

  .passos { margin: 0; padding: 14px 16px 14px 34px; display: flex; flex-direction: column; gap: 8px; background: var(--sec); border: 1px solid var(--borda); border-radius: 12px; font-size: 14px; line-height: 20px; color: var(--texto-2); text-align: left; }
  .passos strong { color: var(--texto); }

  .carregando { display: flex; flex-direction: column; align-items: center; gap: 28px; }
  .carregando-marca { display: flex; flex-direction: column; align-items: center; gap: 16px; }
  .carregando-icone { width: 76px; height: 76px; border-radius: 22px; background: #1F3525; border: 1px solid rgba(73, 175, 80, .3); display: flex; align-items: center; justify-content: center; color: var(--verde); }
  .carregando h1 { margin: 0; font-size: 26px; font-weight: 700; letter-spacing: -.02em; }
  .carregando p { margin: 0; font-size: 14px; color: var(--muted); }
  .progresso-area { width: 220px; display: flex; flex-direction: column; align-items: center; gap: 12px; }
  .progresso { width: 100%; height: 6px; border-radius: 3px; background: #232A24; overflow: hidden; }
  .progresso div { width: 0; height: 100%; border-radius: 3px; background: var(--verde); animation: encher 1.3s cubic-bezier(.3, .7, .4, 1) forwards; }
  .progresso-status { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--muted); }
  .ponto { width: 6px; height: 6px; border-radius: 3px; background: var(--verde); animation: pulsar 1s ease-in-out infinite; }
  @keyframes encher { to { width: 100%; } }
  @keyframes pulsar { 50% { opacity: .3; } }
  @media (prefers-reduced-motion: reduce) {
    .progresso div { animation: none; width: 100%; }
    .ponto { animation: none; }
  }
</style>
</head>
<body>

<?php if ($tela === 'carregando'): ?>
  <main class="carregando" role="status" aria-live="polite">
    <div class="carregando-marca">
      <div class="carregando-icone"><?= ic('broto', 38) ?></div>
      <h1>Smart Demetra</h1>
      <p>Carregando o painel…</p>
    </div>
    <div class="progresso-area">
      <div class="progresso" role="progressbar" aria-label="Carregando o painel"><div></div></div>
      <div class="progresso-status"><span class="ponto" aria-hidden="true"></span>Conectando ao servidor</div>
    </div>
  </main>
  <noscript><meta http-equiv="refresh" content="1;url=index.php"></noscript>
  <script>setTimeout(() => { window.location.replace('index.php'); }, 1400);</script>

<?php elseif ($tela === 'cadastro'): ?>
  <section class="cartao">
    <a class="voltar" href="login.php"><?= ic('voltar') ?>Voltar para o login</a>
    <div class="marca">
      <span class="sobrelinha">Novo cadastro</span>
      <div class="logo"><?= ic('broto', 26) ?><h1>Smart Demetra</h1></div>
      <p>Preencha os dados abaixo para criar seu acesso ao painel.</p>
    </div>
    <?php if (!$conviteAtivo): ?>
      <div class="aviso neutro"><?= ic('alerta') ?><span>O cadastro está desativado no momento. Peça ao administrador para gerar um código de convite.</span></div>
    <?php endif; ?>
    <form method="post" action="login.php" id="formCadastro" novalidate>
      <input type="hidden" name="acao" value="cadastrar">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <div class="campo">
        <label for="cadUsuario">Nome do usuário</label>
        <div class="entrada"><?= ic('usuario') ?><input id="cadUsuario" name="usuario" type="text" placeholder="Ex.: joao.silva" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="30" pattern="[a-z0-9._\-]{3,30}" required value="<?= h($rascunho['usuario']) ?>" autofocus></div>
        <span class="dica-campo" id="dicaUsuario">3 a 30 caracteres: letras minúsculas, números, ponto, hífen ou sublinhado.</span>
      </div>
      <div class="campo">
        <label for="cadEmail">E-mail</label>
        <div class="entrada"><?= ic('email') ?><input id="cadEmail" name="email" type="email" placeholder="voce@email.com" autocomplete="email" maxlength="120" required value="<?= h($rascunho['email']) ?>"></div>
      </div>
      <div class="campo">
        <label for="cadSenha">Nova senha</label>
        <div class="entrada com-botao"><?= ic('cadeado') ?><input id="cadSenha" name="senha" type="password" autocomplete="new-password" required minlength="8"><button type="button" class="alternar-senha" data-alvo="cadSenha" aria-label="Mostrar senha" aria-pressed="false"><?= ic('olho') ?></button></div>
        <div class="forca" aria-live="polite">
          <div class="forca-barras" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
          <div class="forca-texto"><span>Use 8+ caracteres com letras, números e símbolos.</span><strong id="forcaRotulo"></strong></div>
        </div>
      </div>
      <div class="campo">
        <label for="cadConfirma">Confirmar senha</label>
        <div class="entrada com-botao" id="entradaConfirma"><?= ic('cadeado') ?><input id="cadConfirma" name="confirma" type="password" placeholder="Repita a senha" autocomplete="new-password" required><button type="button" class="alternar-senha" data-alvo="cadConfirma" aria-label="Mostrar senha" aria-pressed="false"><?= ic('olho') ?></button></div>
        <span class="dica-campo erro" id="dicaConfirma" hidden>As senhas não coincidem.</span>
      </div>
      <div class="campo">
        <label for="cadConvite">Código de convite</label>
        <div class="entrada"><?= ic('convite') ?><input id="cadConvite" name="convite" type="text" placeholder="Fornecido pelo administrador" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="40" required></div>
      </div>
      <button type="submit" class="btn btn-primario" style="margin-top: 6px;" <?= $conviteAtivo ? '' : 'disabled' ?>><?= ic('usuario-mais', 18) ?>Criar conta</button>
    </form>
  </section>
  <p class="rodape-texto">Já tem uma conta? <a href="login.php">Entrar</a></p>

<?php elseif ($tela === 'erro_cadastro'): ?>
  <section class="cartao erro" role="alert">
    <div class="cabecalho-mini"><?= ic('broto', 20) ?><strong>Smart Demetra</strong><span>CADASTRO</span></div>
    <div class="resultado">
      <div class="resultado-icone erro"><?= ic('alerta', 30) ?></div>
      <h1>Não foi possível concluir o cadastro</h1>
      <p>Revise os pontos abaixo e tente novamente.</p>
    </div>
    <ul class="lista-erros">
      <?php foreach ($errosCadastro as $e): ?>
        <li><?= ic('x', 14) ?><?= h($e) ?></li>
      <?php endforeach; ?>
    </ul>
    <div class="acoes">
      <a class="btn btn-primario" href="login.php?tela=cadastro"><?= ic('repetir', 18) ?>Tentar novamente</a>
      <a class="btn btn-secundario" href="login.php"><?= ic('voltar', 18) ?>Voltar para o login</a>
    </div>
  </section>

<?php elseif ($tela === 'cadastro_ok'): ?>
  <section class="cartao sucesso" role="status">
    <div class="cabecalho-mini"><?= ic('broto', 20) ?><strong>Smart Demetra</strong><span>CADASTRO</span></div>
    <div class="resultado">
      <div class="resultado-icone sucesso"><?= ic('check', 30) ?></div>
      <h1>Cadastro concluído!</h1>
      <p>Sua conta foi criada. Agora é só entrar com seu usuário e senha.</p>
    </div>
    <dl class="dados">
      <div><dt>Usuário</dt><dd><?= h($cadastroOk['usuario']) ?></dd></div>
      <div><dt>E-mail</dt><dd><?= h($cadastroOk['email']) ?></dd></div>
    </dl>
    <a class="btn btn-primario" href="login.php?u=<?= h(rawurlencode($cadastroOk['usuario'])) ?>"><?= ic('entrar', 18) ?>Fazer login</a>
  </section>

<?php elseif ($tela === 'esqueci'): ?>
  <section class="cartao">
    <div class="cabecalho-mini"><?= ic('broto', 20) ?><strong>Smart Demetra</strong><span>ACESSO</span></div>
    <div class="resultado">
      <div class="resultado-icone info"><?= ic('chave', 30) ?></div>
      <h1>Esqueceu a senha?</h1>
      <p>A senha é redefinida pelo administrador do painel.</p>
    </div>
    <ol class="passos">
      <li>Peça ao administrador para abrir <strong>Configurações → Usuários</strong> e tocar em <strong>Redefinir senha</strong> na sua conta.</li>
      <li>Entre com a <strong>senha temporária</strong> que ele te passar.</li>
      <li>Troque por uma senha sua em <strong>Configurações → Alterar senha</strong>.</li>
    </ol>
    <a class="btn btn-secundario" href="login.php"><?= ic('voltar', 18) ?>Voltar para o login</a>
  </section>

<?php else: ?>
  <section class="cartao">
    <div class="marca">
      <span class="sobrelinha">Painel de cultivo</span>
      <div class="logo"><?= ic('broto', 26) ?><h1>Smart Demetra</h1></div>
      <p>Entre com suas credenciais para acessar o painel.</p>
    </div>
    <form method="post" action="login.php">
      <input type="hidden" name="acao" value="entrar">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <?php if ($erroLogin !== ''): ?>
        <div class="aviso" role="alert"><?= ic('alerta') ?><span><?= h($erroLogin) ?></span></div>
      <?php endif; ?>
      <div class="campo">
        <label for="usuario">Usuário</label>
        <div class="entrada<?= $erroLogin !== '' ? ' invalida' : '' ?>"><?= ic('usuario') ?><input id="usuario" name="usuario" type="text" placeholder="Digite seu usuário" autocomplete="username" autocapitalize="none" spellcheck="false" required value="<?= h($valorUsuario) ?>" <?= $valorUsuario === '' ? 'autofocus' : '' ?>></div>
      </div>
      <?= campo_senha('senha', 'senha', 'Senha', 'Digite sua senha', 'current-password', $valorUsuario !== '' ? 'autofocus' : '') ?>
      <div class="linha-opcoes">
        <label class="lembrar" for="lembrar"><input id="lembrar" name="lembrar" type="checkbox" value="1" checked>Lembrar de mim</label>
        <a class="link-pequeno" href="login.php?tela=esqueci">Esqueci a senha</a>
      </div>
      <button type="submit" class="btn btn-primario"><?= ic('entrar', 18) ?>Entrar</button>
    </form>
    <div class="separador">ou</div>
    <a class="btn btn-secundario" href="login.php?tela=cadastro"><span style="color: var(--verde); display: flex;"><?= ic('usuario-mais', 18) ?></span>Criar nova conta</a>
  </section>
  <div class="rodape-seguro"><?= ic('escudo', 14) ?>Conexão protegida</div>
<?php endif; ?>

<script>
  const ICONE_OLHO = <?= json_encode(ic('olho')) ?>;
  const ICONE_OLHO_FECHADO = <?= json_encode(ic('olho-fechado')) ?>;
  document.querySelectorAll('.alternar-senha').forEach(btn => {
    btn.addEventListener('click', () => {
      const campo = document.getElementById(btn.dataset.alvo);
      const mostrar = campo.type === 'password';
      campo.type = mostrar ? 'text' : 'password';
      btn.setAttribute('aria-pressed', String(mostrar));
      btn.setAttribute('aria-label', mostrar ? 'Ocultar senha' : 'Mostrar senha');
      btn.innerHTML = mostrar ? ICONE_OLHO_FECHADO : ICONE_OLHO;
      campo.focus();
    });
  });

  const form = document.getElementById('formCadastro');
  if (form) {
    const senha = document.getElementById('cadSenha');
    const confirma = document.getElementById('cadConfirma');
    const barras = form.querySelectorAll('.forca-barras i');
    const rotulo = document.getElementById('forcaRotulo');
    const NIVEIS = [
      { texto: '', cor: '' },
      { texto: 'Fraca', cor: '#E27064' },
      { texto: 'Média', cor: '#DFC269' },
      { texto: 'Boa', cor: '#9CC65A' },
      { texto: 'Forte', cor: '#49AF50' },
    ];
    function forcaSenha(s) {
      if (!s) return 0;
      let p = 0;
      if (s.length >= 8) p++;
      if (/[a-zA-ZÀ-ÿ]/.test(s) && /\d/.test(s)) p++;
      if (/[^a-zA-Z0-9À-ÿ]/.test(s)) p++;
      if (s.length >= 12) p++;
      return Math.max(1, p);
    }
    function atualizarForca() {
      const n = forcaSenha(senha.value);
      barras.forEach((b, i) => { b.style.background = i < n ? NIVEIS[n].cor : ''; });
      rotulo.textContent = NIVEIS[n].texto;
      rotulo.style.color = NIVEIS[n].cor;
    }
    function atualizarConfirma() {
      const diferente = confirma.value !== '' && confirma.value !== senha.value;
      document.getElementById('dicaConfirma').hidden = !diferente;
      document.getElementById('entradaConfirma').classList.toggle('invalida', diferente);
    }
    senha.addEventListener('input', () => { atualizarForca(); atualizarConfirma(); });
    confirma.addEventListener('input', atualizarConfirma);
    const usuario = document.getElementById('cadUsuario');
    usuario.addEventListener('input', () => { usuario.value = usuario.value.toLowerCase().replace(/\s+/g, ''); });
    atualizarForca();
  }
</script>
</body>
</html>
