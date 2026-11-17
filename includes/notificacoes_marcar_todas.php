<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_SETOR, ['POST']);
try {
    $st = $pdo->prepare('UPDATE notificacoes SET lida = 1 WHERE usuario_id = ? AND lida = 0');
    $st->execute([user_id()]);
    json_out('ok', 'Notificações marcadas como lidas.', ['total' => $st->rowCount()]);
} catch (PDOException $e) {
    json_out('error', db_error_message($e), [], 500);
}
