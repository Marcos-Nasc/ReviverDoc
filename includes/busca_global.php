<?php
/** Busca rápida da barra superior (Ctrl K). Respeita o nível e o setor do usuário. */
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_SETOR, ['GET']);

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    json_out('ok', '', ['groups' => []]);
}
$like = '%' . $q . '%';
// Busca por número (#123, 0123, "romaneio 12") só quando o texto é essencialmente um número
$num = preg_match('/^\s*(?:#|n[ºo°]?\s*|romaneio\s*|solicita\S*\s*|caixa\s*|livro\s*)?0*(\d{1,9})\s*$/iu', $q, $mm) ? $mm[1] : '';
$numId = $num !== '' ? (int) $num : -1;
$staff = user_level() >= NIVEL_ARQUIVO;
$groups = [];

try {
    if ($staff) {
        $st = $pdo->prepare("SELECT c.id, c.numero, c.conteudo, c.data_inicio, c.data_fim, s.nome AS setor
                             FROM caixas c LEFT JOIN setores s ON s.id = c.setor_id
                             WHERE c.numero LIKE ? OR c.conteudo LIKE ? ORDER BY (c.numero = ?) DESC, c.id DESC LIMIT 5");
        $st->execute([$like, $like, $q]);
        $items = [];
        foreach ($st as $r) {
            $sem = in_array(trim((string) $r['numero']), ['', '-'], true);
            $items[] = ['title' => ($sem ? 'Caixa sem nº' : 'Caixa ' . $r['numero']) . ' · ' . $r['conteudo'],
                        'sub' => ($r['setor'] ?: '—') . ' · ' . fmt_periodo($r['data_inicio'], $r['data_fim']),
                        'url' => 'php/caixas.php?busca=' . rawurlencode($sem ? (string) $r['conteudo'] : (string) $r['numero'])];
        }
        $groups[] = ['label' => 'Caixas', 'type' => 'caixa', 'items' => $items];

        $st = $pdo->prepare("SELECT l.id, l.numero_livro, l.data_inicio, l.data_fim, s.nome AS setor
                             FROM livros l LEFT JOIN setores s ON s.id = l.setor
                             WHERE l.numero_livro LIKE ? ORDER BY l.numero_livro LIMIT 5");
        $st->execute([$like]);
        $items = [];
        foreach ($st as $r) {
            $items[] = ['title' => 'Livro ' . $r['numero_livro'], 'sub' => ($r['setor'] ?: '—') . ' · ' . fmt_periodo($r['data_inicio'], $r['data_fim']),
                        'url' => 'php/livros.php?busca=' . rawurlencode((string) $r['numero_livro'])];
        }
        $groups[] = ['label' => 'Livros', 'type' => 'livro', 'items' => $items];

        $st = $pdo->prepare("SELECT d.id, d.nome, d.data_digitalizacao, c.numero FROM documentos d
                             LEFT JOIN caixas c ON c.id = d.caixa_id WHERE d.nome LIKE ? ORDER BY d.id DESC LIMIT 5");
        $st->execute([$like]);
        $items = [];
        foreach ($st as $r) {
            $items[] = ['title' => $r['nome'], 'sub' => 'Caixa ' . ($r['numero'] ?: '—') . ' · digitalizado em ' . fmt_date($r['data_digitalizacao']),
                        'url' => 'php/documentos.php?busca=' . rawurlencode((string) $r['nome'])];
        }
        $groups[] = ['label' => 'Documentos', 'type' => 'documento', 'items' => $items];
    }

    $sql = "SELECT s.id, s.tipo, s.status, s.prazo, se.nome AS setor FROM solicitacoes s LEFT JOIN setores se ON se.id = s.setor_id
            WHERE (s.id = ? OR s.tipo LIKE ? OR s.informacao LIKE ?)";
    $params = [$numId, $like, $like];
    if (!$staff) {
        $sql .= ' AND s.setor_id = ?';
        $params[] = (int) user_setor();
    }
    $st = $pdo->prepare($sql . ' ORDER BY (s.id = ?) DESC, s.id DESC LIMIT 5');
    $params[] = $numId;
    $st->execute($params);
    $items = [];
    foreach ($st as $r) {
        $items[] = ['title' => 'Solicitação #' . fmt_id($r['id'], 3) . ' · ' . $r['tipo'], 'sub' => $r['status'] . ' · ' . ($r['setor'] ?: '—') . ($r['prazo'] ? ' · prazo ' . fmt_date($r['prazo']) : ''),
                    'url' => 'php/solicitacoes.php?id=' . (int) $r['id']];
    }
    $groups[] = ['label' => 'Solicitações de pesquisa', 'type' => 'solicitacao', 'items' => $items];

    $sql = "SELECT r.id, r.tipo, r.status, r.data_saida, se.nome AS setor FROM romaneios r LEFT JOIN setores se ON se.id = r.setor_id WHERE (r.id = ? OR r.conteudo LIKE ?)";
    $params = [$numId, $like];
    if (!$staff) {
        $sql .= ' AND r.setor_id = ?';
        $params[] = (int) user_setor();
    }
    $st = $pdo->prepare($sql . ' ORDER BY r.id DESC LIMIT 5');
    $st->execute($params);
    $items = [];
    foreach ($st as $r) {
        $items[] = ['title' => 'Romaneio #' . fmt_id($r['id']) . ' · ' . $r['tipo'], 'sub' => $r['status'] . ' · ' . ($r['setor'] ?: '—') . ' · ' . fmt_date($r['data_saida']),
                    'url' => 'php/romaneios.php?id=' . (int) $r['id']];
    }
    $groups[] = ['label' => 'Romaneios', 'type' => 'romaneio', 'items' => $items];

    json_out('ok', '', ['groups' => $groups]);
} catch (PDOException $e) {
    json_out('error', db_error_message($e, 'Não foi possível buscar agora.'), ['groups' => []], 500);
}
