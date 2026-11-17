<?php
require_once __DIR__ . '/solicitacoes_lib.php';
api_guard(NIVEL_SETOR, ['POST']);

$sid = (int) ($_POST['solicitacao_id'] ?? 0);
$ultima = (int) ($_POST['ultima_mensagem_id'] ?? 0);
if (!$sid || !$ultima) {
    json_out('error', 'Dados de leitura inválidos.', [], 422);
}
$acesso = solicitacao_acessivel($sid);
if (!$acesso) {
    json_out('error', 'Você não tem permissão para esta conversa.', [], $acesso === null ? 404 : 403);
}
try {
    $chk = $pdo->prepare('SELECT COUNT(*) FROM chat_mensagens WHERE id = ? AND solicitacao_id = ?');
    $chk->execute([$ultima, $sid]);
    if (!(int) $chk->fetchColumn()) {
        json_out('error', 'Mensagem não encontrada nesta conversa.', [], 422);
    }
    $pdo->prepare('INSERT INTO chat_leitura_status (solicitacao_id, usuario_id, ultima_mensagem_lida_id) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE ultima_mensagem_lida_id = GREATEST(COALESCE(ultima_mensagem_lida_id, 0), VALUES(ultima_mensagem_lida_id))')
        ->execute([$sid, user_id(), $ultima]);
    // Notificações de chat desta solicitação deixam de ficar pendentes
    $pdo->prepare('UPDATE notificacoes SET lida = 1 WHERE usuario_id = ? AND lida = 0 AND link = ?')
        ->execute([user_id(), 'php/solicitacoes.php?id=' . $sid . '&chat=1']);
    json_out('ok', 'Leitura registrada.');
} catch (PDOException $e) {
    json_out('error', db_error_message($e), [], 500);
}
