<?php
require_once __DIR__ . '/solicitacoes_lib.php';
api_guard(NIVEL_SETOR, ['POST']);

$sid = (int) ($_POST['solicitacao_id'] ?? 0);
$texto = trim((string) ($_POST['mensagem'] ?? ''));
if (!$sid || $texto === '') {
    json_out('error', 'Escreva uma mensagem antes de enviar.', [], 422);
}
if (mb_strlen($texto) > 2000) {
    json_out('error', 'A mensagem pode ter no máximo 2.000 caracteres.', [], 422);
}
$acesso = solicitacao_acessivel($sid);
if ($acesso === null) {
    json_out('error', 'Solicitação não encontrada.', [], 404);
}
if ($acesso === false) {
    json_out('error', 'Você não tem permissão para participar desta conversa.', [], 403);
}
// Mesmo formato de armazenamento usado desde a criação do chat (texto escapado)
$mensagem = htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
$uid = user_id();

try {
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO chat_mensagens (solicitacao_id, usuario_id, mensagem) VALUES (?, ?, ?)')->execute([$sid, $uid, $mensagem]);
    $novo = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO chat_leitura_status (solicitacao_id, usuario_id, ultima_mensagem_lida_id) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE ultima_mensagem_lida_id = VALUES(ultima_mensagem_lida_id)')->execute([$sid, $uid, $novo]);
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out('error', db_error_message($e, 'Não foi possível enviar a mensagem.'), [], 500);
}

// Avisa o outro lado da conversa
if (user_level() >= NIVEL_ARQUIVO) {
    if (!empty($acesso['usuario_id']) && (int) $acesso['usuario_id'] !== $uid) {
        notify([$acesso['usuario_id']], "Nova mensagem do Arquivo na solicitação #{$sid}.", 'php/solicitacoes.php?id=' . $sid . '&chat=1');
    }
} else {
    notify(equipe_arquivo_ids(), "Nova mensagem na solicitação #{$sid}.", 'php/solicitacoes.php?id=' . $sid . '&chat=1');
}
json_out('ok', 'Mensagem enviada.', ['id' => $novo]);
