<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ARQUIVO, ['GET']);
$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    json_out('error', 'Documento não informado.', [], 400);
}
$st = $pdo->prepare('SELECT * FROM documentos WHERE id = ?');
$st->execute([$id]);
$doc = $st->fetch();
if (!$doc) {
    json_out('error', 'Documento não encontrado. Ele pode ter sido excluído.', [], 404);
}
json_out('ok', '', $doc);
