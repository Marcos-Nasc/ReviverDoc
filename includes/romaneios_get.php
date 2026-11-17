<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_SETOR, ['GET']);

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    json_out('error', 'Romaneio não informado.', [], 400);
}
try {
    $st = $pdo->prepare('SELECT r.*, u.nome AS usuario_nome, s.nome AS setor_nome
        FROM romaneios r
        LEFT JOIN usuarios u ON u.id = r.usuario_id
        LEFT JOIN setores s ON s.id = r.setor_id
        WHERE r.id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r || !can_access_setor($r['setor_id'])) {
        json_out('error', 'Romaneio não encontrado. Ele pode ter sido excluído.', [], 404);
    }
    $cx = $pdo->prepare('SELECT c.id, c.numero, c.conteudo, c.data_inicio, c.data_fim, c.coluna, c.fileira, c.status
        FROM romaneio_caixas rc JOIN caixas c ON c.id = rc.caixa_id
        WHERE rc.romaneio_id = ? ORDER BY CAST(c.numero AS UNSIGNED), c.id');
    $cx->execute([$id]);
    $detalhes = $cx->fetchAll();
    $r['caixas'] = array_map('intval', array_column($detalhes, 'id'));
    $r['caixas_detalhes'] = $detalhes;
    $dados = rom_conteudo_dados($r['conteudo']);
    $r['descricao'] = $dados['descricao'];
    $r['entrada_itens'] = null;
    if ($r['tipo'] === 'Entrada' && $r['aprovado'] !== 'Sim' && $dados['caixas']) {
        $itens = $dados['caixas'];
        foreach ($itens as &$it) {
            $it['setor_nome'] = !empty($it['setor_id']) ? setor_nome($it['setor_id']) : null;
        }
        unset($it);
        $r['entrada_itens'] = $itens;
    }
    unset($r['conteudo']);
    json_out('ok', '', ['data' => $r]);
} catch (PDOException $e) {
    json_out('error', db_error_message($e), [], 500);
}
