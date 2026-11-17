<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ARQUIVO, ['GET']);
$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    json_out('error', 'Livro não informado.', [], 400);
}
$st = $pdo->prepare('SELECT * FROM livros WHERE id = ?');
$st->execute([$id]);
$livro = $st->fetch();
if (!$livro) {
    json_out('error', 'Livro não encontrado. Ele pode ter sido excluído.', [], 404);
}
json_out('ok', '', $livro);
