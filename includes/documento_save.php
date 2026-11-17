<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ARQUIVO, ['POST']);

$id                 = (int) ($_POST['id'] ?? 0);
$nome               = trim((string) ($_POST['nome'] ?? ''));
$caixa_id           = (int) ($_POST['caixa_id'] ?? 0);
$data_digitalizacao = post_date('data_digitalizacao');

if ($nome === '' || !$caixa_id) {
    json_out('error', 'Informe o nome do documento e a caixa.', [], 422);
}
if (mb_strlen($nome) > 150) {
    json_out('error', 'O nome do documento pode ter no máximo 150 caracteres.', [], 422);
}

$root      = dirname(__DIR__);
$uploadDir = $root . '/uploads/documentos/';
$thumbDir  = $root . '/uploads/thumbs/';

try {
    $chk = $pdo->prepare('SELECT COUNT(*) FROM caixas WHERE id = ?');
    $chk->execute([$caixa_id]);
    if (!(int) $chk->fetchColumn()) {
        json_out('error', 'A caixa selecionada não existe mais. Atualize a página e escolha outra.', [], 422);
    }

    $antigo = null;
    $isEdit = $id > 0;
    if ($isEdit) {
        $st = $pdo->prepare('SELECT arquivo FROM documentos WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            json_out('error', 'Documento não encontrado. Ele pode ter sido excluído.', [], 404);
        }
        $antigo = $row['arquivo'];
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
        $slug = slugify($nome) ?: 'documento';
        $novoArquivo = $slug . '-' . time() . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.pdf';
        if (!move_uploaded_file($_FILES['arquivo']['tmp_name'], $uploadDir . $novoArquivo)) {
            json_out('error', 'Não foi possível gravar o arquivo no servidor. Verifique as permissões da pasta uploads.', [], 500);
        }
        make_pdf_thumb($uploadDir . $novoArquivo, $thumbDir . pathinfo($novoArquivo, PATHINFO_FILENAME) . '.jpg', 'documento');
        $arquivo = $novoArquivo;
    }

    try {
        if ($isEdit) {
            $pdo->prepare('UPDATE documentos SET nome = ?, caixa_id = ?, data_digitalizacao = ?, arquivo = ? WHERE id = ?')
                ->execute([$nome, $caixa_id, $data_digitalizacao, $arquivo, $id]);
        } else {
            $pdo->prepare('INSERT INTO documentos (nome, caixa_id, data_digitalizacao, arquivo) VALUES (?, ?, ?, ?)')
                ->execute([$nome, $caixa_id, $data_digitalizacao, $arquivo]);
            $id = (int) $pdo->lastInsertId();
        }
    } catch (PDOException $e) {
        if ($novoArquivo) { // desfaz o upload se o banco falhou
            @unlink($uploadDir . $novoArquivo);
            @unlink($thumbDir . pathinfo($novoArquivo, PATHINFO_FILENAME) . '.jpg');
        }
        throw $e;
    }

    // Substituição: remove o PDF e a miniatura antigos somente após salvar no banco
    if ($novoArquivo && $antigo && $antigo !== $novoArquivo) {
        $old = basename($antigo);
        @unlink($uploadDir . $old);
        @unlink($thumbDir . pathinfo($old, PATHINFO_FILENAME) . '.jpg');
    }

    if ($isEdit) {
        log_activity('editar', 'documento', $id, "Documento \"{$nome}\" atualizado" . ($novoArquivo ? ' (novo PDF)' : ''));
        json_out('ok', 'Documento atualizado.', ['id' => $id]);
    }
    log_activity('criar', 'documento', $id, "Documento \"{$nome}\" cadastrado");
    json_out('ok', 'Documento cadastrado.', ['id' => $id]);
} catch (PDOException $e) {
    json_out('error', db_error_message($e, 'Não foi possível salvar o documento. Tente novamente.'), [], 500);
}
