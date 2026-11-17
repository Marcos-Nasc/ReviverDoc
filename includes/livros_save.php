<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ARQUIVO, ['POST']);

$id                 = (int) ($_POST['id'] ?? 0);
$numero_livro       = trim((string) ($_POST['numero_livro'] ?? ''));
$posicao            = trim((string) ($_POST['posicao'] ?? ''));
$setor              = !empty($_POST['setor']) ? (int) $_POST['setor'] : null;
$data_inicio        = post_date('data_inicio');
$data_fim           = post_date('data_fim');
$data_digitalizacao = post_date('data_digitalizacao');
$digitalizado       = in_array($_POST['digitalizado'] ?? '', ['Sim', 'Não'], true) ? $_POST['digitalizado'] : 'Não';

if ($numero_livro === '') {
    json_out('error', 'Informe o número do livro.', [], 422);
}
if (!ctype_digit($numero_livro)) {
    json_out('error', 'O número do livro deve conter apenas algarismos.', [], 422);
}
if (mb_strlen($posicao) > 10) {
    json_out('error', 'A posição pode ter no máximo 10 caracteres.', [], 422);
}
if ($data_inicio && $data_fim && $data_fim < $data_inicio) {
    json_out('error', 'A data fim não pode ser anterior à data início.', [], 422);
}

try {
    if ($id) {
        $st = $pdo->prepare('UPDATE livros SET numero_livro = ?, data_inicio = ?, data_fim = ?, posicao = ?, setor = ?, digitalizado = ?, data_digitalizacao = ? WHERE id = ?');
        $st->execute([$numero_livro, $data_inicio, $data_fim, $posicao, $setor, $digitalizado, $data_digitalizacao, $id]);
        log_activity('editar', 'livro', $id, "Livro {$numero_livro} atualizado");
        json_out('ok', 'Livro atualizado.');
    }
    $st = $pdo->prepare('INSERT INTO livros (numero_livro, data_inicio, data_fim, posicao, setor, digitalizado, data_digitalizacao) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$numero_livro, $data_inicio, $data_fim, $posicao, $setor, $digitalizado, $data_digitalizacao]);
    $novo = (int) $pdo->lastInsertId();
    log_activity('criar', 'livro', $novo, "Livro {$numero_livro} cadastrado");
    json_out('ok', 'Livro cadastrado.', ['id' => $novo]);
} catch (PDOException $e) {
    if (is_constraint_error($e)) {
        json_out('error', 'O número do livro já existe ou o setor informado é inválido. Verifique os dados.', [], 409);
    }
    json_out('error', db_error_message($e, 'Não foi possível salvar o livro. Tente novamente.'), [], 500);
}
