<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ARQUIVO, ['POST']);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) {
    json_out('error', 'Romaneio não informado.', [], 400);
}
try {
    $pdo->beginTransaction();
    $st = $pdo->prepare("UPDATE romaneios SET status = 'Concluído' WHERE id = ? AND tipo = 'Saída' AND (status = 'Pendente' OR status IS NULL OR status = '')");
    $st->execute([$id]);
    if ($st->rowCount() === 0) {
        $pdo->rollBack();
        json_out('error', 'Romaneio não encontrado ou não é uma saída pendente.', [], 404);
    }
    $cx = $pdo->prepare('SELECT caixa_id FROM romaneio_caixas WHERE romaneio_id = ?');
    $cx->execute([$id]);
    $ids = array_map('intval', $cx->fetchAll(PDO::FETCH_COLUMN));
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE caixas SET status = 'No Arquivo' WHERE id IN ($ph)")->execute($ids);
    }
    $pdo->commit();
    log_activity('concluir', 'romaneio', $id, $ids ? 'Devolução registrada; ' . count($ids) . ' caixa(s) de volta ao arquivo' : 'Devolução registrada');
    json_out('ok', $ids ? 'Romaneio concluído. ' . count($ids) . ' caixa(s) de volta ao arquivo.' : 'Devolução registrada. Romaneio concluído.');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out('error', db_error_message($e, 'Não foi possível concluir o romaneio.'), [], 500);
}
