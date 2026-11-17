<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ARQUIVO, ['POST']);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) {
    json_out('error', 'Romaneio não informado.', [], 400);
}
try {
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT r.tipo, r.status, rc.caixa_id FROM romaneios r LEFT JOIN romaneio_caixas rc ON r.id = rc.romaneio_id WHERE r.id = ?');
    $st->execute([$id]);
    $info = $st->fetchAll();
    if (!$info) {
        $pdo->rollBack();
        json_out('error', 'Romaneio não encontrado. Ele pode já ter sido excluído.', [], 404);
    }
    $pdo->prepare('DELETE FROM romaneio_caixas WHERE romaneio_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM romaneios WHERE id = ?')->execute([$id]);
    notificacoes_remover_links(['php/romaneios.php?id=', 'php/romaneio-conf.php?id='], $id);
    // Saída pendente excluída: as caixas voltam ao arquivo (concluídas já voltaram; evita marcar caixas que saíram em outro romaneio)
    if ($info[0]['tipo'] === 'Saída' && in_array((string) $info[0]['status'], ['Pendente', ''], true)) {
        $ids = array_values(array_filter(array_map('intval', array_column($info, 'caixa_id'))));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE caixas SET status = 'No Arquivo' WHERE id IN ($ph)")->execute($ids);
        }
    }
    $pdo->commit();
    log_activity('excluir', 'romaneio', $id, 'Romaneio de ' . mb_strtolower($info[0]['tipo']) . ' excluído');
    json_out('ok', 'Romaneio excluído.');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out('error', db_error_message($e, 'Não foi possível excluir o romaneio.'), [], 500);
}
