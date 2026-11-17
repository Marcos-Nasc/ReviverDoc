<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ARQUIVO, ['POST']);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) {
    json_out('error', 'Caixa não informada.', [], 400);
}
try {
    $info = $pdo->prepare('SELECT numero FROM caixas WHERE id = ?');
    $info->execute([$id]);
    $numero = $info->fetchColumn();
    if ($numero === false) {
        json_out('error', 'Caixa não encontrada. Ela pode já ter sido excluída.', [], 404);
    }
    $docs = $pdo->prepare('SELECT COUNT(*) FROM documentos WHERE caixa_id = ?');
    $docs->execute([$id]);
    if ((int) $docs->fetchColumn() > 0) {
        json_out('error', 'Esta caixa possui documentos cadastrados. Mova ou exclua os documentos antes de excluir a caixa.', [], 409);
    }
    $pdo->prepare('DELETE FROM caixas WHERE id = ?')->execute([$id]);
    log_activity('excluir', 'caixa', $id, "Caixa {$numero} excluída");
    json_out('ok', 'Caixa excluída.');
} catch (PDOException $e) {
    if (is_constraint_error($e)) {
        json_out('error', 'Não é possível excluir esta caixa, pois ela está associada a outros registros.', [], 409);
    }
    json_out('error', db_error_message($e), [], 500);
}
