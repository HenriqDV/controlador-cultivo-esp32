<?php
declare(strict_types=1);

// Este arquivo so tem constantes/funcoes auxiliares - a blindagem contra
// erro (ob_start, tratamento de erro fatal, etc.) fica no topo de cada
// endpoint que usa este arquivo (dados.php, comandos.php,
// registrar_leitura.php, registrar_comando.php), pra nao depender de mais
// nenhum arquivo externo.

const API_KEY = '############';

const DB_HOST = 'localhost';
const DB_NAME = '############';
const DB_USER = '############';
const DB_PASS = '############';

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

header('Content-Type: application/json; charset=utf-8');
