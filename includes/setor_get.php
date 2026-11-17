<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ARQUIVO, ['GET']);
$st = $pdo->prepare('SELECT id, nome FROM setores WHERE id = ?');
$st->execute([(int) ($_GET['id'] ?? 0)]);
$s = $st->fetch();
if (!$s) {
    json_out('error', 'Setor não encontrado.', [], 404);
}
json_out('ok', '', ['data' => $s]);
