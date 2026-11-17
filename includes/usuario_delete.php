<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ADMIN, ['POST']);
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) {
    json_out('error', 'Usuário não informado.', [], 400);
}
if ($id === user_id()) {
    json_out('error', 'Você não pode excluir a própria conta.', [], 422);
}
try {
    $info = $pdo->prepare('SELECT email FROM usuarios WHERE id = ?');
    $info->execute([$id]);
    $email = $info->fetchColumn();
    if ($email === false) {
        json_out('error', 'Usuário não encontrado ou já excluído.', [], 404);
    }
    $st = $pdo->prepare('DELETE FROM usuarios WHERE id = ?');
    $st->execute([$id]);
    log_activity('excluir', 'usuario', $id, "Usuário {$email} excluído");
    json_out('ok', 'Usuário excluído.');
} catch (PDOException $e) {
    if (is_constraint_error($e)) {
        json_out('error', 'Este usuário possui romaneios ou mensagens registradas. Para preservar o histórico, marque-o como inativo em vez de excluir.', [], 409);
    }
    json_out('error', db_error_message($e, 'Não foi possível excluir o usuário.'), [], 500);
}
