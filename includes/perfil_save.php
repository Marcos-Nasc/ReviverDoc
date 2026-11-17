<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_SETOR, ['POST']);
$atual = (string) ($_POST['senha_atual'] ?? '');
$nova  = (string) ($_POST['senha_nova'] ?? '');
$conf  = (string) ($_POST['senha_confirma'] ?? '');
if ($atual === '' || $nova === '') {
    json_out('error', 'Preencha a senha atual e a nova senha.', [], 422);
}
if (strlen($nova) < 6) {
    json_out('error', 'A nova senha deve ter pelo menos 6 caracteres.', [], 422);
}
if ($nova !== $conf) {
    json_out('error', 'A confirmação não confere com a nova senha.', [], 422);
}
$st = $pdo->prepare('SELECT senha FROM usuarios WHERE id = ?');
$st->execute([user_id()]);
$hash = $st->fetchColumn();
if (!$hash || !password_verify($atual, $hash)) {
    json_out('error', 'A senha atual está incorreta.', [], 422);
}
$pdo->prepare('UPDATE usuarios SET senha = ? WHERE id = ?')->execute([password_hash($nova, PASSWORD_DEFAULT), user_id()]);
session_regenerate_id(true);
log_activity('senha', 'usuario', user_id(), 'Senha alterada pelo próprio usuário');
json_out('ok', 'Senha alterada. Use a nova senha no próximo acesso.');
