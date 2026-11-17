<?php
require_once __DIR__ . '/bootstrap.php';
if (is_logged_in()) {
    log_activity('logout', 'usuario', user_id(), 'Saída do sistema');
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: ../php/login.php?saiu=1');
exit;
