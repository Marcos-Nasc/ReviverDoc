<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ARQUIVO, ['GET']);
$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    json_out('error', 'Página não informada.', [], 400);
}
$st = $pdo->prepare('SELECT * FROM paginas WHERE id = ?');
$st->execute([$id]);
$pagina = $st->fetch();
if (!$pagina) {
    json_out('error', 'Página não encontrada. Ela pode ter sido excluída.', [], 404);
}
json_out('ok', '', $pagina);
