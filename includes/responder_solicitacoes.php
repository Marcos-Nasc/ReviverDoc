<?php
require_once __DIR__ . '/solicitacoes_lib.php';
api_guard(NIVEL_ARQUIVO, ['POST']);

$id        = (int) ($_POST['id'] ?? 0);
$resultado = (string) ($_POST['resultado'] ?? '');
$resposta  = trim((string) ($_POST['resposta'] ?? ''));

if (!$id) {
    json_out('error', 'Solicitação não informada.', [], 400);
}
if (!array_key_exists($resultado, SOLIC_RESULTADOS)) {
    json_out('error', 'Selecione o resultado da pesquisa.', [], 422);
}

try {
    $st = $pdo->prepare('SELECT id, arquivo, usuario_id, status FROM solicitacoes WHERE id = ?');
    $st->execute([$id]);
    $sol = $st->fetch();
    if (!$sol) {
        json_out('error', 'Solicitação não encontrada. Ela pode ter sido excluída.', [], 404);
    }

    if (mb_strlen($resposta) > 100) {
        json_out('error', 'A mensagem de resposta pode ter no máximo 100 caracteres. Use o chat da solicitação para explicações mais longas.', [], 422);
    }
    // Mantém o anexo atual quando nenhum arquivo novo é enviado
    $arquivo = $sol['arquivo'];
    if (!empty($_FILES['arquivo']) && $_FILES['arquivo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['arquivo'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            json_out('error', upload_error_message($f['error']), [], 422);
        }
        if ($f['size'] > 50 * 1024 * 1024) {
            json_out('error', 'O arquivo excede o limite de 50 MB.', [], 422);
        }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $permitidas = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'txt', 'csv', 'zip', 'rar', '7z'];
        if (!in_array($ext, $permitidas, true)) {
            json_out('error', 'Tipo de arquivo não permitido. Envie PDF, imagem, documento, planilha ou arquivo compactado.', [], 422);
        }
        $base = slugify(pathinfo($f['name'], PATHINFO_FILENAME)) ?: 'anexo';
        $nome = time() . '_' . $base . '.' . $ext;
        $dir = __DIR__ . '/../uploads/solicitacao/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!move_uploaded_file($f['tmp_name'], $dir . $nome)) {
            json_out('error', 'Não foi possível salvar o anexo no servidor.', [], 500);
        }
        $arquivo = $nome;
    }

    // data_resposta guarda a PRIMEIRA resposta (vem antes de status no SET: o MySQL aplica as atribuições em ordem)
    $up = $pdo->prepare("UPDATE solicitacoes SET data_resposta = CASE WHEN status = 'Respondida' AND data_resposta IS NOT NULL THEN data_resposta ELSE NOW() END,
                         resultado = ?, resposta = ?, arquivo = ?, status = 'Respondida' WHERE id = ?");
    try {
        $up->execute([$resultado, $resposta === '' ? null : $resposta, $arquivo, $id]);
    } catch (PDOException $e) {
        if ($arquivo !== $sol['arquivo'] && $arquivo) {
            @unlink(__DIR__ . '/../uploads/solicitacao/' . $arquivo); // não deixa anexo órfão
        }
        throw $e;
    }

    $label = SOLIC_RESULTADOS[$resultado];
    // Os avisos de "nova solicitação" dos outros atendentes deixam de ficar pendentes
    notificacoes_marcar_lidas_link('php/solicitacoes.php?id=' . $id, (int) $sol['usuario_id']);
    log_activity('responder', 'solicitacao', $id, "Solicitação #{$id} respondida: {$label}");
    if (!empty($sol['usuario_id']) && (int) $sol['usuario_id'] !== user_id()) {
        notify([$sol['usuario_id']], "Sua solicitação #{$id} foi respondida ({$label}).", 'php/solicitacoes.php?id=' . $id);
    }
    json_out('ok', $sol['status'] === 'Respondida' ? 'Resposta atualizada.' : 'Resposta enviada ao solicitante.', ['arquivo' => $arquivo]);
} catch (PDOException $e) {
    json_out('error', db_error_message($e, 'Não foi possível registrar a resposta. Tente novamente.'), [], 500);
}
