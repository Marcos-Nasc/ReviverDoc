<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ARQUIVO, ['POST']);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) {
    json_out('error', 'Página não informada.', [], 400);
}
try {
    $st = $pdo->prepare('SELECT p.arquivo, p.numero_pagina, p.letra, l.numero_livro FROM paginas p LEFT JOIN livros l ON l.id = p.livro_id WHERE p.id = ?');
    $st->execute([$id]);
    $pagina = $st->fetch();
    if (!$pagina) {
        json_out('error', 'Página não encontrada. Ela pode já ter sido excluída.', [], 404);
    }
    $pdo->prepare('DELETE FROM paginas WHERE id = ?')->execute([$id]);
    if (!empty($pagina['arquivo'])) {
        $f = basename($pagina['arquivo']);
        @unlink(dirname(__DIR__) . '/uploads/paginas/' . $f);
        @unlink(dirname(__DIR__) . '/uploads/thumbs/' . pathinfo($f, PATHINFO_FILENAME) . '.jpg');
    }
    log_activity('excluir', 'pagina', $id, 'Página ' . $pagina['numero_pagina'] . ($pagina['letra'] ?: '') . ' do livro ' . ($pagina['numero_livro'] ?? '?') . ' excluída');
    json_out('ok', 'Página excluída.');
} catch (PDOException $e) {
    json_out('error', db_error_message($e), [], 500);
}
