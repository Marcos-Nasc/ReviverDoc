<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ARQUIVO, ['POST']);

$id                 = (int) ($_POST['id'] ?? 0);
$numero_pagina      = trim((string) ($_POST['numero_pagina'] ?? ''));
$livro_id           = (int) ($_POST['livro_id'] ?? 0);
$letra              = mb_substr(trim((string) ($_POST['letra'] ?? '')), 0, 1);
$letra              = $letra === '' ? null : mb_strtoupper($letra);
$data_inicio        = post_date('data_inicio');
$data_fim           = post_date('data_fim');
$data_digitalizacao = post_date('data_digitalizacao');

if (!$livro_id || $numero_pagina === '') {
    json_out('error', 'Informe o livro e o número da página.', [], 422);
}
if (!ctype_digit($numero_pagina)) {
    json_out('error', 'O número da página deve conter apenas algarismos.', [], 422);
}
if ($data_inicio && $data_fim && $data_fim < $data_inicio) {
    json_out('error', 'A data fim não pode ser anterior à data início.', [], 422);
}

$root      = dirname(__DIR__);
$uploadDir = $root . '/uploads/paginas/';
$thumbDir  = $root . '/uploads/thumbs/';

try {
    $chk = $pdo->prepare('SELECT numero_livro FROM livros WHERE id = ?');
    $chk->execute([$livro_id]);
    $numero_livro = $chk->fetchColumn();
    if ($numero_livro === false) {
        json_out('error', 'O livro selecionado não existe mais. Atualize a página e escolha outro.', [], 422);
    }

    $isEdit = $id > 0;
    $antigo = null;
    $nomeOriginal = null;
    if ($isEdit) {
        $st = $pdo->prepare('SELECT arquivo, nome_original FROM paginas WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            json_out('error', 'Página não encontrada. Ela pode ter sido excluída.', [], 404);
        }
        $antigo = $row['arquivo'];
        $nomeOriginal = $row['nome_original'];
    }
    $arquivo = $antigo;
    $novoArquivo = null;

    if (isset($_FILES['arquivo']) && $_FILES['arquivo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $err = validate_pdf_upload($_FILES['arquivo']);
        if ($err) {
            json_out('error', $err, [], 422);
        }
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
        if (!is_dir($thumbDir)) @mkdir($thumbDir, 0775, true);
        $novoArquivo = uniqid() . '.pdf';
        if (!move_uploaded_file($_FILES['arquivo']['tmp_name'], $uploadDir . $novoArquivo)) {
            json_out('error', 'Não foi possível gravar o arquivo no servidor. Verifique as permissões da pasta uploads.', [], 500);
        }
        make_pdf_thumb($uploadDir . $novoArquivo, $thumbDir . pathinfo($novoArquivo, PATHINFO_FILENAME) . '.jpg', 'pagina');
        $arquivo = $novoArquivo;
        $nomeOriginal = mb_substr(basename((string) $_FILES['arquivo']['name']), 0, 255);
    }

    try {
        if ($isEdit) {
            $pdo->prepare('UPDATE paginas SET numero_pagina = ?, letra = ?, data_inicio = ?, data_fim = ?, livro_id = ?, arquivo = ?, nome_original = ?, data_digitalizacao = ? WHERE id = ?')
                ->execute([$numero_pagina, $letra, $data_inicio, $data_fim, $livro_id, $arquivo, $nomeOriginal, $data_digitalizacao, $id]);
        } else {
            $pdo->prepare('INSERT INTO paginas (numero_pagina, letra, data_inicio, data_fim, livro_id, arquivo, nome_original, data_digitalizacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$numero_pagina, $letra, $data_inicio, $data_fim, $livro_id, $arquivo, $nomeOriginal, $data_digitalizacao]);
            $id = (int) $pdo->lastInsertId();
        }
    } catch (PDOException $e) {
        if ($novoArquivo) {
            @unlink($uploadDir . $novoArquivo);
            @unlink($thumbDir . pathinfo($novoArquivo, PATHINFO_FILENAME) . '.jpg');
        }
        throw $e;
    }

    if ($novoArquivo && $antigo && $antigo !== $novoArquivo) {
        $old = basename($antigo);
        @unlink($uploadDir . $old);
        @unlink($thumbDir . pathinfo($old, PATHINFO_FILENAME) . '.jpg');
    }

    $desc = "Página {$numero_pagina}" . ($letra ?: '') . " do livro {$numero_livro}";
    if ($isEdit) {
        log_activity('editar', 'pagina', $id, $desc . ' atualizada' . ($novoArquivo ? ' (novo PDF)' : ''));
        json_out('ok', 'Página atualizada.', ['id' => $id]);
    }
    log_activity('criar', 'pagina', $id, $desc . ' cadastrada');
    json_out('ok', 'Página cadastrada.', ['id' => $id]);
} catch (PDOException $e) {
    json_out('error', db_error_message($e, 'Não foi possível salvar a página. Tente novamente.'), [], 500);
}
