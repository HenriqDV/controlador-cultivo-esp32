<?php
declare(strict_types=1);

// Calendário de manejo (login): o que foi aplicado, ou está agendado, em cada dia.
//   GET  ?mes=AAAA-MM -> { produtos, registros (do mês), hoje, pendentes, proximo }
//   POST { acao: 'criar',   data, produtos: [ids], observacao, situacao: 'feito'|'agendado', repetir_dias?: 1..90 }
//        { acao: 'alternar', id }                 (feito <-> agendado: é o "check" do dia; em série, agenda a próxima)
//        { acao: 'remover',  id }
//        { acao: 'novo_produto', nome }
//        { acao: 'salvar_fase', fase, inicio, fim? }   (fase: germinacao|muda|vegetativo|floracao; fim vazio = em andamento)
//        { acao: 'limpar_fase', fase }
require __DIR__ . '/api_comum.php';

require_login_api();
garantir_schema_v2();
try {
    manejo_garantir_colunas();
} catch (Throwable $e) {
    error_log('manejo_garantir_colunas: ' . $e->getMessage());
}

const MANEJO_MAX_PRODUTOS_POR_REGISTRO = 12;
const MANEJO_MAX_OBSERVACAO = 500;
const MANEJO_MAX_REPETIR_DIAS = 90;
const MANEJO_FASES = ['germinacao', 'muda', 'vegetativo', 'floracao'];

// Garante as colunas da repetição mesmo que a migração 011 não tenha rodado (arquivos .sql não enviados,
// marcador sql/.schema_ok antigo ou falta de permissão na hora): confere uma vez por requisição e cria o que faltar.
function manejo_garantir_colunas(): void
{
    $pdo = db();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS manejo_fases (
            fase VARCHAR(12) NOT NULL PRIMARY KEY,
            inicio DATE NOT NULL,
            fim DATE NULL,
            atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $tem = static fn (string $c): bool => (bool) $pdo->query("SHOW COLUMNS FROM manejo_registros LIKE '" . $c . "'")->fetch();
    if (!$tem('repetir_dias')) {
        $pdo->exec('ALTER TABLE manejo_registros ADD COLUMN repetir_dias SMALLINT UNSIGNED NULL AFTER observacao');
    }
    if (!$tem('origem_id')) {
        $pdo->exec('ALTER TABLE manejo_registros ADD COLUMN origem_id INT UNSIGNED NULL AFTER repetir_dias');
        try {
            $pdo->exec('ALTER TABLE manejo_registros ADD KEY idx_manejo_origem (origem_id)');
        } catch (PDOException $e) {
            // índice já existe: sem problema
        }
    }
}

function manejo_hoje(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(FUSO_PAINEL)))->format('Y-m-d');
}

function manejo_data_valida(string $d): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return false;
    }
    // Janela razoável: ninguém registra manejo a mais de 2 anos de hoje.
    $hoje = new DateTimeImmutable(manejo_hoje());
    $dia = new DateTimeImmutable($d);
    return abs((int) $hoje->diff($dia)->days) <= 731;
}

function manejo_erro(string $codigo, int $status = 422): void
{
    responder(['ok' => false, 'erro' => $codigo], $status);
}

// Fases já marcadas, na ordem do cultivo.
function manejo_fases(): array
{
    $linhas = db()->query('SELECT fase, inicio, fim FROM manejo_fases')->fetchAll(PDO::FETCH_ASSOC);
    $porFase = [];
    foreach ($linhas as $l) {
        $porFase[$l['fase']] = ['fase' => $l['fase'], 'inicio' => $l['inicio'], 'fim' => $l['fim']];
    }
    $saida = [];
    foreach (MANEJO_FASES as $f) {
        if (isset($porFase[$f])) {
            $saida[] = $porFase[$f];
        }
    }
    return $saida;
}

// Registros com a lista de ids de produtos de cada um.
function manejo_registros(string $de, string $ate): array
{
    $stmt = db()->prepare(
        'SELECT id, data, situacao, observacao, repetir_dias, concluido_em FROM manejo_registros
         WHERE data BETWEEN ? AND ? ORDER BY data, id'
    );
    $stmt->execute([$de, $ate]);
    $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return manejo_anexar_produtos($linhas);
}

function manejo_anexar_produtos(array $linhas): array
{
    if (!$linhas) {
        return [];
    }
    $ids = array_map(static fn ($l) => (int) $l['id'], $linhas);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT registro_id, produto_id FROM manejo_registro_produtos WHERE registro_id IN ($in) ORDER BY produto_id");
    $stmt->execute($ids);
    $porRegistro = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $porRegistro[(int) $r['registro_id']][] = (int) $r['produto_id'];
    }
    return array_map(static fn ($l) => [
        'id' => (int) $l['id'],
        'data' => $l['data'],
        'situacao' => $l['situacao'],
        'observacao' => $l['observacao'],
        'repetir_dias' => $l['repetir_dias'] !== null ? (int) $l['repetir_dias'] : null,
        'concluido_em' => $l['concluido_em'],
        'produtos' => $porRegistro[(int) $l['id']] ?? [],
    ], $linhas);
}

function manejo_produtos(): array
{
    $stmt = db()->query('SELECT id, nome FROM manejo_produtos WHERE ativo = 1 ORDER BY ordem, nome');
    return array_map(static fn ($p) => ['id' => (int) $p['id'], 'nome' => $p['nome']], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function manejo_registro_por_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT id, data, situacao, observacao, repetir_dias, concluido_em FROM manejo_registros WHERE id = ?');
    $stmt->execute([$id]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);
    return $linha ? manejo_anexar_produtos([$linha])[0] : null;
}

// Próxima data de uma série: base + N dias (se já passou, pula de N em N até cair depois de hoje).
function manejo_proxima_data(string $base, int $dias, string $hoje): string
{
    $d = (new DateTimeImmutable($base))->modify('+' . $dias . ' days');
    $limite = new DateTimeImmutable($hoje);
    for ($i = 0; $i < 400 && $d <= $limite; $i++) {
        $d = $d->modify('+' . $dias . ' days');
    }
    return $d->format('Y-m-d');
}

// Agenda a próxima aplicação da série (mesmos produtos e observação), se ainda não existir.
function manejo_agendar_proxima(array $reg, string $base): void
{
    $dias = (int) ($reg['repetir_dias'] ?? 0);
    if ($dias < 1) {
        return;
    }
    $pdo = db();
    $ja = $pdo->prepare('SELECT COUNT(*) FROM manejo_registros WHERE origem_id = ?');
    $ja->execute([$reg['id']]);
    if ((int) $ja->fetchColumn() > 0) {
        return;
    }
    $data = manejo_proxima_data($base, $dias, manejo_hoje());
    $pdo->prepare("INSERT INTO manejo_registros (data, situacao, observacao, repetir_dias, origem_id, usuario) VALUES (?, 'agendado', ?, ?, ?, ?)")
        ->execute([$data, $reg['observacao'], $dias, $reg['id'], $_SESSION['usuario'] ?? null]);
    $novo = (int) $pdo->lastInsertId();
    $lig = $pdo->prepare('INSERT INTO manejo_registro_produtos (registro_id, produto_id) VALUES (?, ?)');
    foreach ($reg['produtos'] as $pid) {
        $lig->execute([$novo, $pid]);
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $mes = (string) ($_GET['mes'] ?? substr(manejo_hoje(), 0, 7));
        if (!preg_match('/^(\d{4})-(\d{2})$/', $mes, $m) || !checkdate((int) $m[2], 1, (int) $m[1])) {
            manejo_erro('mes_invalido');
        }
        $inicio = $mes . '-01';
        $fim = (new DateTimeImmutable($inicio))->modify('last day of this month')->format('Y-m-d');
        $hoje = manejo_hoje();

        $pend = db()->prepare("SELECT COUNT(*) FROM manejo_registros WHERE situacao = 'agendado' AND data <= ?");
        $pend->execute([$hoje]);
        $prox = db()->prepare("SELECT id, data FROM manejo_registros WHERE situacao = 'agendado' AND data > ? ORDER BY data, id LIMIT 1");
        $prox->execute([$hoje]);
        $proximo = $prox->fetch(PDO::FETCH_ASSOC);
        $proximoReg = $proximo ? manejo_registro_por_id((int) $proximo['id']) : null;

        responder([
            'ok' => true,
            'hoje' => $hoje,
            'produtos' => manejo_produtos(),
            'fases' => manejo_fases(),
            'registros' => manejo_registros($inicio, $fim),
            'pendentes' => (int) $pend->fetchColumn(),
            'proximo' => $proximoReg,
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        manejo_erro('metodo_nao_permitido', 405);
    }

    $dados = read_json_body();
    $acao = (string) ($dados['acao'] ?? '');

    if ($acao === 'criar') {
        $data = (string) ($dados['data'] ?? '');
        if (!manejo_data_valida($data)) {
            manejo_erro('data_invalida');
        }
        $situacao = ($dados['situacao'] ?? '') === 'agendado' ? 'agendado' : 'feito';
        $ids = array_values(array_unique(array_map('intval', is_array($dados['produtos'] ?? null) ? $dados['produtos'] : [])));
        if (!$ids || count($ids) > MANEJO_MAX_PRODUTOS_POR_REGISTRO) {
            manejo_erro('produtos_invalidos');
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $conf = db()->prepare("SELECT COUNT(*) FROM manejo_produtos WHERE ativo = 1 AND id IN ($in)");
        $conf->execute($ids);
        if ((int) $conf->fetchColumn() !== count($ids)) {
            manejo_erro('produtos_invalidos');
        }
        $obs = is_scalar($dados['observacao'] ?? null) ? trim((string) $dados['observacao']) : '';
        if (mb_strlen($obs) > MANEJO_MAX_OBSERVACAO) {
            manejo_erro('observacao_longa');
        }

        $repetir = null;
        if (($dados['repetir_dias'] ?? null) !== null && $dados['repetir_dias'] !== '' && $dados['repetir_dias'] !== 0) {
            $r = $dados['repetir_dias'];
            if (!is_int($r) && !(is_string($r) && ctype_digit($r))) {
                manejo_erro('repeticao_invalida');
            }
            $repetir = (int) $r;
            if ($repetir < 1 || $repetir > MANEJO_MAX_REPETIR_DIAS) {
                manejo_erro('repeticao_invalida');
            }
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare('INSERT INTO manejo_registros (data, situacao, observacao, repetir_dias, usuario, concluido_em) VALUES (?, ?, ?, ?, ?, ?)');
            $ins->execute([
                $data, $situacao, $obs, $repetir, $_SESSION['usuario'] ?? null,
                $situacao === 'feito' ? (new DateTimeImmutable('now', new DateTimeZone(FUSO_PAINEL)))->format('Y-m-d H:i:s') : null,
            ]);
            $registroId = (int) $pdo->lastInsertId();
            $lig = $pdo->prepare('INSERT INTO manejo_registro_produtos (registro_id, produto_id) VALUES (?, ?)');
            foreach ($ids as $pid) {
                $lig->execute([$registroId, $pid]);
            }
            if ($situacao === 'feito' && $repetir !== null) {
                manejo_agendar_proxima(['id' => $registroId, 'observacao' => $obs, 'repetir_dias' => $repetir, 'produtos' => $ids], $data);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        responder(['ok' => true, 'registro' => manejo_registro_por_id($registroId)]);
    }

    if ($acao === 'alternar' || $acao === 'remover') {
        $id = (int) ($dados['id'] ?? 0);
        $reg = $id > 0 ? manejo_registro_por_id($id) : null;
        if ($reg === null) {
            manejo_erro('registro_nao_encontrado', 404);
        }
        if ($acao === 'remover') {
            db()->prepare('DELETE FROM manejo_registros WHERE id = ?')->execute([$id]);
            responder(['ok' => true]);
        }
        if ($reg['situacao'] === 'agendado') {
            $agora = (new DateTimeImmutable('now', new DateTimeZone(FUSO_PAINEL)))->format('Y-m-d H:i:s');
            db()->prepare("UPDATE manejo_registros SET situacao = 'feito', concluido_em = ? WHERE id = ?")->execute([$agora, $id]);
            // Série: a próxima conta a partir do dia da aplicação (o mais tardio entre o agendado e hoje).
            manejo_agendar_proxima($reg, max($reg['data'], manejo_hoje()));
        } else {
            db()->prepare("UPDATE manejo_registros SET situacao = 'agendado', concluido_em = NULL WHERE id = ?")->execute([$id]);
            // Desfez o check: some o agendamento automático que ele tinha gerado (se ainda não foi feito).
            db()->prepare("DELETE FROM manejo_registros WHERE origem_id = ? AND situacao = 'agendado'")->execute([$id]);
        }
        responder(['ok' => true, 'registro' => manejo_registro_por_id($id)]);
    }

    if ($acao === 'novo_produto') {
        $nome = preg_replace('/\s+/', ' ', strip_tags(is_scalar($dados['nome'] ?? null) ? trim((string) $dados['nome']) : ''));
        if ($nome === '' || mb_strlen($nome) < 2 || mb_strlen($nome) > 60) {
            manejo_erro('nome_invalido');
        }
        $ordem = (int) db()->query('SELECT COALESCE(MAX(ordem), 0) + 1 FROM manejo_produtos')->fetchColumn();
        // Se já existir (mesmo desativado), reativa em vez de duplicar.
        db()->prepare('INSERT INTO manejo_produtos (nome, ordem) VALUES (?, ?) ON DUPLICATE KEY UPDATE ativo = 1')->execute([$nome, $ordem]);
        responder(['ok' => true, 'produtos' => manejo_produtos()]);
    }

    if ($acao === 'salvar_fase' || $acao === 'limpar_fase') {
        $fase = (string) ($dados['fase'] ?? '');
        if (!in_array($fase, MANEJO_FASES, true)) {
            manejo_erro('fase_invalida');
        }
        if ($acao === 'limpar_fase') {
            db()->prepare('DELETE FROM manejo_fases WHERE fase = ?')->execute([$fase]);
            responder(['ok' => true, 'fases' => manejo_fases()]);
        }
        $inicio = (string) ($dados['inicio'] ?? '');
        $fimTxt = trim((string) ($dados['fim'] ?? ''));
        if (!manejo_data_valida($inicio)) {
            manejo_erro('data_invalida');
        }
        if ($fimTxt !== '' && !manejo_data_valida($fimTxt)) {
            manejo_erro('data_invalida');
        }
        if ($fimTxt !== '' && $fimTxt < $inicio) {
            manejo_erro('fim_antes_do_inicio');
        }
        db()->prepare(
            'INSERT INTO manejo_fases (fase, inicio, fim) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE inicio = VALUES(inicio), fim = VALUES(fim)'
        )->execute([$fase, $inicio, $fimTxt !== '' ? $fimTxt : null]);
        responder(['ok' => true, 'fases' => manejo_fases()]);
    }

    manejo_erro('acao_invalida');
} catch (Throwable $e) {
    responder(['ok' => false, 'erro' => 'erro_servidor', 'detalhe' => $e->getMessage()], 500);
}
