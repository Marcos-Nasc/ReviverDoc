<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ARQUIVO, ['POST']);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) {
    json_out('error', 'Livro não informado.', [], 400);
}
try {
    $st = $pdo->prepare('SELECT numero_livro FROM livros WHERE id = ?');
    $st->execute([$id]);
    $numero = $st->fetchColumn();
    if ($numero === false) {
        json_out('error', 'Livro não encontrado. Ele pode já ter sido excluído.', [], 404);
    }
    $pg = $pdo->prepare('SELECT COUNT(*) FROM paginas WHERE livro_id = ?');
    $pg->execute([$id]);
    if ((int) $pg->fetchColumn() > 0) {
        json_out('error', 'Não é possível excluir este livro, pois ele possui páginas cadastradas. Exclua as páginas primeiro.', [], 409);
    }
    $pdo->prepare('DELETE FROM livros WHERE id = ?')->execute([$id]);
    log_activity('excluir', 'livro', $id, "Livro {$numero} excluído");
    json_out('ok', 'Livro excluído.');
} catch (PDOException $e) {
    if (is_constraint_error($e)) {
        json_out('error', 'Não é possível excluir este livro, pois ele possui páginas ou outros registros associados.', [], 409);
    }
    json_out('error', db_error_message($e), [], 500);
}
