<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ADMIN, ['POST']);

$id       = (int) ($_POST['id'] ?? 0);
$nome     = trim((string) ($_POST['nome'] ?? ''));
$email    = trim((string) ($_POST['email'] ?? ''));
$senha    = (string) ($_POST['senha'] ?? '');
$setor_id = !empty($_POST['setor_id']) ? (int) $_POST['setor_id'] : null;
$nivel    = (int) ($_POST['nivel'] ?? 1);
$status   = ($_POST['status'] ?? 'Ativo') === 'Inativo' ? 'Inativo' : 'Ativo';

if ($nome === '' || $email === '') {
    json_out('error', 'Informe o nome e o e-mail do usuário.', [], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out('error', 'O e-mail informado não é válido.', [], 422);
}
if (!in_array($nivel, [1, 2, 3], true)) {
    json_out('error', 'Escolha um perfil de acesso válido.', [], 422);
}
if ($setor_id && !setor_nome($setor_id)) {
    json_out('error', 'O setor escolhido não existe mais. Atualize a página e selecione outro setor.', [], 422);
}
if ($nivel === NIVEL_SETOR && !$setor_id) {
    json_out('error', 'Usuários de setor precisam ter um setor definido.', [], 422);
}
if ($senha !== '' && strlen($senha) < 6) {
    json_out('error', 'A senha deve ter pelo menos 6 caracteres.', [], 422);
}
// Proteção contra autobloqueio: o administrador não pode rebaixar nem desativar a própria conta
if ($id && $id === user_id() && ($nivel < NIVEL_ADMIN || $status === 'Inativo')) {
    json_out('error', 'Você não pode remover o próprio acesso de administrador nem desativar sua conta.', [], 422);
}

try {
    $dup = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE email = ? AND id <> ?');
    $dup->execute([$email, $id]);
    if ((int) $dup->fetchColumn() > 0) {
        json_out('error', 'Este e-mail já está em uso por outro usuário.', [], 409);
    }
    if ($id) {
        if ($senha !== '') {
            $st = $pdo->prepare('UPDATE usuarios SET nome = ?, email = ?, senha = ?, setor_id = ?, nivel = ?, status = ? WHERE id = ?');
            $st->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $setor_id, $nivel, $status, $id]);
        } else {
            $st = $pdo->prepare('UPDATE usuarios SET nome = ?, email = ?, setor_id = ?, nivel = ?, status = ? WHERE id = ?');
            $st->execute([$nome, $email, $setor_id, $nivel, $status, $id]);
        }
        log_activity('editar', 'usuario', $id, "Usuário {$email} atualizado (perfil {$nivel}, {$status})" . ($senha !== '' ? ', senha redefinida' : ''));
        json_out('ok', 'Usuário atualizado.');
    }
    if ($senha === '') {
        json_out('error', 'Defina uma senha para o novo usuário.', [], 422);
    }
    $st = $pdo->prepare('INSERT INTO usuarios (nome, email, senha, setor_id, nivel, status) VALUES (?, ?, ?, ?, ?, ?)');
    $st->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $setor_id, $nivel, $status]);
    $novo = (int) $pdo->lastInsertId();
    log_activity('criar', 'usuario', $novo, "Usuário {$email} criado (perfil {$nivel})");
    json_out('ok', 'Usuário criado.', ['id' => $novo]);
} catch (PDOException $e) {
    $info = is_array($e->errorInfo) ? $e->errorInfo : [];
    if ((int) ($info[1] ?? 0) === 1062 || stripos($e->getMessage(), 'duplicate') !== false) {
        json_out('error', 'Este e-mail já está em uso por outro usuário.', [], 409);
    }
    json_out('error', db_error_message($e, 'Não foi possível salvar o usuário.'), [], 500);
}
