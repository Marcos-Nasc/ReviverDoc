<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ARQUIVO, ['POST']);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) {
    json_out('error', 'Documento não informado.', [], 400);
}
try {
    $st = $pdo->prepare('SELECT nome, arquivo FROM documentos WHERE id = ?');
    $st->execute([$id]);
    $doc = $st->fetch();
    if (!$doc) {
        json_out('error', 'Documento não encontrado. Ele pode já ter sido excluído.', [], 404);
    }
    $pdo->prepare('DELETE FROM documentos WHERE id = ?')->execute([$id]);
    if (!empty($doc['arquivo'])) {
        $f = basename($doc['arquivo']);
        @unlink(dirname(__DIR__) . '/uploads/documentos/' . $f);
        @unlink(dirname(__DIR__) . '/uploads/thumbs/' . pathinfo($f, PATHINFO_FILENAME) . '.jpg');
    }
    log_activity('excluir', 'documento', $id, "Documento \"{$doc['nome']}\" excluído");
    json_out('ok', 'Documento excluído.');
} catch (PDOException $e) {
    if (is_constraint_error($e)) {
        json_out('error', 'Este documento está vinculado a romaneios e não pode ser excluído.', [], 409);
    }
    json_out('error', db_error_message($e), [], 500);
}
