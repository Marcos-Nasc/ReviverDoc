<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
$id = (int) ($_GET['id'] ?? 0);
$redirect = (string) ($_GET['redirect'] ?? '../index.php');
if ($id) {
    try {
        $st = $pdo->prepare('UPDATE notificacoes SET lida = 1 WHERE id = ? AND usuario_id = ?');
        $st->execute([$id, user_id()]);
    } catch (PDOException $e) {
    }
}
// Apenas caminhos internos (evita redirecionamento para sites externos)
if (!preg_match('#^(\.\./|[a-zA-Z0-9_\-]+(/|\.php))#', $redirect) || preg_match('#^[a-z]+:|//#i', $redirect)) {
    $redirect = '../index.php';
}
if (strpos($redirect, '../') !== 0) {
    $redirect = '../' . $redirect;
}
header('Location: ' . $redirect);
exit;
