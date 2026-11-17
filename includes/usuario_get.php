<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ADMIN, ['GET']);
$id = (int) ($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT id, nome, email, setor_id, nivel, status FROM usuarios WHERE id = ?');
$st->execute([$id]);
$u = $st->fetch();
if (!$u) {
    json_out('error', 'Usuário não encontrado.', [], 404);
}
$u['status'] = $u['status'] === 'Inativo' ? 'Inativo' : 'Ativo';
json_out('ok', '', ['data' => $u]);
