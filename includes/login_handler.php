<?php
require_once __DIR__ . '/bootstrap.php';
$nextQs = (is_string($_POST['next'] ?? null) && $_POST['next'] !== '') ? '&next=' . rawurlencode($_POST['next']) : '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('../php/login.php');
}

$email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
$senha = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

// Limite de tentativas (por sessão e por IP + e-mail)
$_SESSION['_login_fail'] = $_SESSION['_login_fail'] ?? ['n' => 0, 't' => 0];
if (($_SESSION['_login_fail']['n'] >= 5 && $_SESSION['_login_fail']['t'] > time() - 300) || login_blocked($email)) {
    redirect_to('../php/login.php?erro=bloqueado&email=' . urlencode($email) . $nextQs);
}

if (!csrf_valid()) {
    redirect_to('../php/login.php?erro=expirado&email=' . urlencode($email) . $nextQs);
}

if ($email === '' || $senha === '') {
    redirect_to('../php/login.php?erro=1&email=' . urlencode($email) . $nextQs);
}

$stmt = $pdo->prepare('SELECT * FROM usuarios WHERE email = :email LIMIT 1');
$stmt->execute([':email' => $email]);
$usuario = $stmt->fetch();

if (!$usuario || !password_verify($senha, $usuario['senha'])) {
    $_SESSION['_login_fail'] = ['n' => $_SESSION['_login_fail']['n'] + 1, 't' => time()];
    login_fail($email);
    usleep(400000);
    redirect_to('../php/login.php?erro=1&email=' . urlencode($email) . $nextQs);
}

if (($usuario['status'] ?? 'Ativo') === 'Inativo') {
    redirect_to('../php/login.php?erro=inativo&email=' . urlencode($email) . $nextQs);
}

session_regenerate_id(true);
unset($_SESSION['_login_fail']);
login_reset($email);
$_SESSION['usuario_id']  = $usuario['id'];
$_SESSION['nome']        = $usuario['nome'];
$_SESSION['email']       = $usuario['email'];
$_SESSION['nivel']       = (int) $usuario['nivel'];
$_SESSION['setor_id']    = $usuario['setor_id'];
$_SESSION['_rd_checked'] = time();

// Atualiza o hash se o algoritmo padrão do PHP mudou
if (password_needs_rehash($usuario['senha'], PASSWORD_DEFAULT)) {
    try {
        $pdo->prepare('UPDATE usuarios SET senha = ? WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $usuario['id']]);
    } catch (Exception $e) {
    }
}
log_activity('login', 'usuario', (int) $usuario['id'], 'Entrada no sistema');

$destino = (int) $usuario['nivel'] === NIVEL_SETOR ? '../php/solicitacoes.php' : '../index.php';
$next = is_string($_POST['next'] ?? null) ? $_POST['next'] : '';
if ($next !== '' && preg_match('#^(index\.php|php/[a-z\-_]+\.php)(\?[^\s]*)?$#i', $next)) {
    $destino = '../' . $next;
}
redirect_to($destino);
