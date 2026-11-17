<?php
require_once __DIR__ . '/solicitacoes_lib.php';
api_guard(NIVEL_SETOR, ['GET']);

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    json_out('error', 'Solicitação não informada.', [], 400);
}
$acesso = solicitacao_acessivel($id);
if ($acesso === null) {
    json_out('error', 'Solicitação não encontrada. Ela pode ter sido excluída.', [], 404);
}
if ($acesso === false) {
    json_out('error', 'Você não tem permissão para ver esta solicitação.', [], 403);
}
$st = $pdo->prepare('
    SELECT s.*, se.nome AS setor_nome, sp.nome AS setor_pesquisa_nome, u.nome AS solicitante_nome
    FROM solicitacoes s
    LEFT JOIN setores se ON s.setor_id = se.id
    LEFT JOIN setores sp ON s.setor_pesquisa = sp.id
    LEFT JOIN usuarios u ON s.usuario_id = u.id
    WHERE s.id = ?');
$st->execute([$id]);
$s = $st->fetch();
$s['setor_pesquisa_label'] = setor_pesquisa_label($s['setor_pesquisa'], $s['setor_pesquisa_nome']);
$s['prazo_fmt'] = fmt_datetime($s['prazo']);
$s['data_solicitacao_fmt'] = fmt_datetime($s['data_solicitacao']);
$s['data_resposta_fmt'] = fmt_datetime($s['data_resposta']);
$s['resultado_label'] = $s['status'] === 'Respondida' ? (SOLIC_RESULTADOS[$s['resultado']] ?? ($s['resultado'] ?: '—')) : 'Aguardando resposta';
json_out('ok', '', ['data' => $s]);
