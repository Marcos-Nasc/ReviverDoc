<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ARQUIVO, ['POST']);
$id = (int) ($_POST['id'] ?? 0);
$nome = trim(preg_replace('/\s+/', ' ', (string) ($_POST['nome'] ?? '')));
if ($nome === '') {
    json_out('error', 'Informe o nome do setor.', [], 422);
}
try {
    $dup = $pdo->prepare('SELECT COUNT(*) FROM setores WHERE nome = ? AND id <> ?');
    $dup->execute([$nome, $id]);
    if ((int) $dup->fetchColumn() > 0) {
        json_out('error', "Já existe um setor chamado {$nome}.", [], 409);
    }
    if ($id) {
        $pdo->prepare('UPDATE setores SET nome = ? WHERE id = ?')->execute([$nome, $id]);
        log_activity('editar', 'setor', $id, "Setor renomeado para {$nome}");
        json_out('ok', 'Setor atualizado.');
    }
    $pdo->prepare('INSERT INTO setores (nome) VALUES (?)')->execute([$nome]);
    $novo = (int) $pdo->lastInsertId();
    log_activity('criar', 'setor', $novo, "Setor {$nome} criado");
    json_out('ok', 'Setor criado.', ['id' => $novo]);
} catch (PDOException $e) {
    json_out('error', db_error_message($e, 'Não foi possível salvar o setor.'), [], 500);
}
