<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ARQUIVO, ['GET']);
$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    json_out('error', 'Caixa não informada.', [], 400);
}
$st = $pdo->prepare('SELECT * FROM caixas WHERE id = ?');
$st->execute([$id]);
$caixa = $st->fetch();
if (!$caixa) {
    json_out('error', 'Caixa não encontrada. Ela pode ter sido excluída.', [], 404);
}
// Valores vazios/antigos: mesmo critério usado nas listas (vazio = no arquivo / higienizada)
$caixa['status'] = $caixa['status'] === 'Fora do Arquivo' ? 'Fora do Arquivo' : 'No Arquivo';
$caixa['higienizada'] = $caixa['higienizada'] === 'Não' ? 'Não' : 'Sim';
foreach (['data_inicio', 'data_fim', 'data_digitalizacao'] as $d) {
    if (isset($caixa[$d]) && strpos((string) $caixa[$d], '0000-00-00') === 0) $caixa[$d] = null;
}
json_out('ok', '', ['data' => $caixa]);
