<?php
declare(strict_types=1);

// Este arquivo so tem constantes/funcoes auxiliares - a blindagem contra
// erro (ob_start, tratamento de erro fatal, etc.) fica no topo de cada
// endpoint que usa este arquivo (dados.php, comandos.php,
// registrar_leitura.php, registrar_comando.php), pra nao depender de mais
// nenhum arquivo externo.

const API_KEY = 'demetra_oqFeUum8u8a5HniKF2cUrWSHGLuZRhfg';

const DB_HOST = 'localhost';
const DB_NAME = 'u187589109_smartDemetra';
const DB_USER = 'u187589109_demetrius';
const DB_PASS = 'Hdv@1909';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function require_api_key(): void
{
    $header = $_SERVER['HTTP_X_API_KEY'] ?? '';

    if (!hash_equals(API_KEY, $header)) {
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code(401);
        echo json_encode(['ok' => false, 'erro' => 'unauthorized']);
        exit;
    }
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);

    if (!is_array($data)) {
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code(400);
        echo json_encode(['ok' => false, 'erro' => 'json_invalido']);
        exit;
    }

    return $data;
}

function nullable_float(array $data, string $key): ?float
{
    return isset($data[$key]) && is_numeric($data[$key]) ? (float) $data[$key] : null;
}

function nullable_int(array $data, string $key): ?int
{
    return isset($data[$key]) && is_numeric($data[$key]) ? (int) $data[$key] : null;
}

function nullable_datetime(array $data, string $key): ?string
{
    if (empty($data[$key]) || !is_string($data[$key])) {
        return null;
    }

    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $data[$key]);
    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}

// ---------------- LOGIN DO DASHBOARD WEB ----------------
// Usuário único (projeto de uso pessoal/hobby, não multi-usuário). A senha
// NUNCA fica em texto puro — troque o hash abaixo gerando um novo com:
//   php -r "echo password_hash('SUA_SENHA_AQUI', PASSWORD_DEFAULT), PHP_EOL;"
//
// TROQUE ISTO:
const WEB_LOGIN_USUARIO = 'admin';
const WEB_LOGIN_SENHA_HASH = '$2y$10$p5fvGNNs.VQe2o73BWfUZu0NkcV36Pb1H1Gca/77vWbEJjAwd3vvG'; // hash da senha do admin


const LOGIN_MAX_TENTATIVAS = 5;
const LOGIN_BLOQUEIO_MINUTOS = 2;

function ip_cliente(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// Sessão HTTP simples (cookie). Chamar sempre antes de mexer com $_SESSION.
function iniciar_sessao(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,     // a Hostinger serve por HTTPS
            'httponly' => true,   // JS não precisa (nem deve) ler o cookie de sessão
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function sessao_logada(): bool
{
    iniciar_sessao();
    return !empty($_SESSION['logado']) && !empty($_SESSION['usuario']);
}

// Segundos restantes de bloqueio pro IP (0 = não bloqueado). Bloqueio fica
// persistido no banco (não na sessão), pra valer mesmo se o navegador
// trocar de cookie/sessão entre tentativas.
function login_bloqueado_segundos(string $ip): int
{
    $stmt = db()->prepare('SELECT bloqueado_ate FROM login_tentativas WHERE ip = ?');
    $stmt->execute([$ip]);
    $bloqueadoAte = $stmt->fetchColumn();

    if (!$bloqueadoAte) {
        return 0;
    }

    $restante = strtotime($bloqueadoAte) - time();
    return $restante > 0 ? $restante : 0;
}

function login_registrar_falha(string $ip): void
{
    $stmt = db()->prepare(
        'INSERT INTO login_tentativas (ip, tentativas) VALUES (?, 1)
         ON DUPLICATE KEY UPDATE tentativas = tentativas + 1,
             bloqueado_ate = IF(tentativas + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), bloqueado_ate)'
    );
    $stmt->execute([$ip, LOGIN_MAX_TENTATIVAS, LOGIN_BLOQUEIO_MINUTOS]);
}

function login_limpar_tentativas(string $ip): void
{
    $stmt = db()->prepare('DELETE FROM login_tentativas WHERE ip = ?');
    $stmt->execute([$ip]);
}

// Pra páginas HTML (index.php): manda pro login.php se não estiver logado.
function require_login_html(): void
{
    if (!sessao_logada()) {
        header('Location: login.php');
        exit;
    }
}

// Pra endpoints JSON chamados pelo dashboard (enviar_comando.php): 401 em
// vez de redirecionar, já que quem chama é fetch(), não o navegador
// navegando de verdade.
function require_login_api(): void
{
    if (!sessao_logada()) {
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'erro' => 'nao_autenticado']);
        exit;
    }
}
