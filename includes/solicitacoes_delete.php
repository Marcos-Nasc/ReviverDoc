<?php
require_once __DIR__ . '/solicitacoes_lib.php';
api_guard(NIVEL_ARQUIVO, ['POST']);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) {
    json_out('error', 'Solicitação não informada.', [], 400);
}
try {
    $st = $pdo->prepare('SELECT arquivo FROM solicitacoes WHERE id = ?');
    $st->execute([$id]);
    $sol = $st->fetch();
    if (!$sol) {
        json_out('error', 'Solicitação não encontrada. Ela pode já ter sido excluída.', [], 404);
    }
    // Remove explicitamente os registros do chat (equivale ao ON DELETE CASCADE do banco)
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM chat_leitura_status WHERE solicitacao_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM chat_mensagens WHERE solicitacao_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM solicitacoes WHERE id = ?')->execute([$id]);
    notificacoes_remover_links(['php/solicitacoes.php?id='], $id);
    $pdo->commit();
    if (!empty($sol['arquivo']) && is_file(__DIR__ . '/../uploads/solicitacao/' . basename($sol['arquivo']))) {
        @unlink(__DIR__ . '/../uploads/solicitacao/' . basename($sol['arquivo']));
    }
    log_activity('excluir', 'solicitacao', $id, "Solicitação #{$id} excluída");
    json_out('ok', "Solicitação #{$id} excluída.");
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (is_constraint_error($e)) {
        json_out('error', 'Não é possível excluir, pois esta solicitação possui registros associados.', [], 409);
    }
    json_out('error', db_error_message($e), [], 500);
}
