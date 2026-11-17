<?php
/**
 * Conexão com o banco de dados.
 *
 * As credenciais podem ser sobrescritas sem editar este arquivo:
 *   1) criando includes/config.local.php que retorne um array
 *      ['host'=>..., 'dbname'=>..., 'user'=>..., 'pass'=>..., 'port'=>...]
 *   2) ou pelas variáveis de ambiente REVIVERDOC_DB_HOST / _NAME / _USER / _PASS / _PORT.
 * As credenciais reais ficam SÓ no servidor, em includes/config.local.php
 * (ignorado pelo Git). Modelo: includes/config.local.example.php
 */
if (isset($pdo) && $pdo instanceof PDO) {
    return;
}

$__db = [
    'host'   => 'localhost',
    'dbname' => 'reviverdoc',
    'user'   => '',
    'pass'   => '',
    'port'   => 3306,
];

$__local = __DIR__ . '/config.local.php';
if (is_file($__local)) {
    $__override = include $__local;
    if (is_array($__override)) {
        $__db = array_merge($__db, $__override);
    }
}
foreach (['host' => 'HOST', 'dbname' => 'NAME', 'user' => 'USER', 'pass' => 'PASS', 'port' => 'PORT'] as $__k => $__env) {
    $__v = getenv('REVIVERDOC_DB_' . $__env);
    if ($__v !== false && $__v !== '') {
        $__db[$__k] = $__v;
    }
}

// Variáveis mantidas por compatibilidade (usadas pelo backup)
$host   = $__db['host'];
$dbname = $__db['dbname'];
$user   = $__db['user'];
$pass   = $__db['pass'];
$port   = (int) $__db['port'];

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $pdo->exec("SET time_zone = '-03:00'");
} catch (PDOException $e) {
    error_log('ReviverDoc - falha de conexão com o banco: ' . $e->getMessage());
    $__isJson = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']));
    http_response_code(503);
    if ($__isJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Banco de dados indisponível no momento. Tente novamente em instantes.', 'mensagem' => 'Banco de dados indisponível no momento.']);
    } else {
        echo '<!DOCTYPE html><html lang="pt-br"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Indisponível</title></head>'
            . '<body style="font-family:system-ui,sans-serif;background:#F4F5F1;color:#14213D;display:grid;place-items:center;min-height:100vh;margin:0">'
            . '<div style="max-width:420px;padding:32px;background:#fff;border:1px solid #DDE0D8;border-radius:12px">'
            . '<h1 style="font-size:20px;margin:0 0 8px">Não foi possível conectar ao banco de dados</h1>'
            . '<p style="margin:0;color:#4A5468;line-height:1.5">O sistema está temporariamente indisponível. Aguarde alguns instantes e recarregue a página. Se o problema continuar, avise o administrador.</p>'
            . '</div></body></html>';
    }
    exit;
}
unset($__db, $__local, $__override, $__k, $__env, $__v);
