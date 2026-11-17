<?php
require_once __DIR__ . '/solicitacoes_lib.php';
api_guard(NIVEL_SETOR, ['GET']);

$sid = (int) ($_GET['solicitacao_id'] ?? 0);
if (!$sid) {
    json_out('error', 'Solicitação inválida.', [], 400);
}
$acesso = solicitacao_acessivel($sid);
if ($acesso === null) {
    json_out('error', 'Solicitação não encontrada. Ela pode ter sido excluída.', [], 404);
}
if ($acesso === false) {
    json_out('error', 'Você não tem permissão para ver esta conversa.', [], 403);
}
try {
    $st = $pdo->prepare('
        SELECT cm.id, cm.mensagem, cm.data_envio, cm.usuario_id, u.nome AS nome_usuario
        FROM chat_mensagens cm
        LEFT JOIN usuarios u ON cm.usuario_id = u.id
        WHERE cm.solicitacao_id = ?
        ORDER BY cm.data_envio ASC, cm.id ASC');
    $st->execute([$sid]);
    $msgs = $st->fetchAll();
    foreach ($msgs as &$m) {
        // A mensagem já é armazenada escapada (FILTER_SANITIZE_FULL_SPECIAL_CHARS); o nome é escapado aqui
        $m['nome_usuario'] = e($m['nome_usuario'] ?? 'Usuário removido');
        $m['data_fmt'] = fmt_datetime($m['data_envio']);
        $m['proprio'] = (int) $m['usuario_id'] === user_id();
    }
    unset($m);
    json_out('ok', '', ['mensagens' => $msgs]);
} catch (PDOException $e) {
    json_out('error', db_error_message($e, 'Não foi possível carregar as mensagens.'), [], 500);
}
