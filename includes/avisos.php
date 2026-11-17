<?php
/**
 * Mural de avisos gerais (tabela chat_avisos_geral): alimenta o letreiro do topo e o chat de avisos.
 *
 * GET  ?apos=ID            → lista as mensagens (últimas 60, ou só as posteriores ao ID)
 * POST acao=enviar&mensagem=...
 * POST acao=excluir&id=N   → autor da mensagem ou administrador
 */
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_SETOR, ['GET', 'POST']);

if (!table_exists('chat_avisos_geral')) {
    json_out('error', 'O mural de avisos não está disponível neste banco de dados.', [], 404);
}

/** A tabela usa latin1: troca caracteres sem representação (ex.: emojis) para não falhar ao gravar. */
function aviso_para_latin1($texto)
{
    return texto_latin1($texto);
}

function aviso_formatar(array $r)
{
    // Mensagens antigas podem ter sido gravadas já com entidades HTML: normaliza antes de devolver texto puro
    $msg = html_entity_decode((string) $r['mensagem'], ENT_QUOTES, 'UTF-8');
    return [
        'id'           => (int) $r['id'],
        'nome'         => $r['nome'] ?: 'Usuário removido',
        'mensagem'     => $msg,
        'data'         => fmt_datetime($r['data_envio']),
        'quando'       => rel_time($r['data_envio']),
        'proprio'      => (int) $r['usuario_id'] === user_id(),
        'pode_excluir' => (int) $r['usuario_id'] === user_id() || user_level() >= NIVEL_ADMIN,
    ];
}

$metodo = $_SERVER['REQUEST_METHOD'];

try {
    if ($metodo === 'GET') {
        $apos = (int) ($_GET['apos'] ?? 0);
        $st = $pdo->prepare('SELECT a.id, a.usuario_id, a.mensagem, a.data_envio, u.nome
            FROM chat_avisos_geral a LEFT JOIN usuarios u ON u.id = a.usuario_id
            WHERE a.id > ? ORDER BY a.id DESC LIMIT 60');
        $st->execute([$apos]);
        $lista = array_reverse(array_map('aviso_formatar', $st->fetchAll()));
        json_out('ok', '', ['avisos' => $lista]);
    }

    $acao = (string) ($_POST['acao'] ?? 'enviar');
    if ($acao === 'excluir') {
        $id = (int) ($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT usuario_id FROM chat_avisos_geral WHERE id = ?');
        $st->execute([$id]);
        $autor = $st->fetchColumn();
        if ($autor === false) {
            json_out('error', 'Aviso não encontrado. Ele pode já ter sido removido.', [], 404);
        }
        if ((int) $autor !== user_id() && user_level() < NIVEL_ADMIN) {
            json_out('error', 'Somente o autor ou um administrador pode remover este aviso.', [], 403);
        }
        $pdo->prepare('DELETE FROM chat_avisos_geral WHERE id = ?')->execute([$id]);
        log_activity('excluir', 'aviso', $id, 'Aviso geral removido');
        json_out('ok', 'Aviso removido.');
    }

    $mensagem = trim(preg_replace('/[ \t]+/', ' ', (string) ($_POST['mensagem'] ?? '')));
    $mensagem = trim(aviso_para_latin1($mensagem));
    if ($mensagem === '') {
        json_out('error', 'Escreva a mensagem do aviso.', [], 422);
    }
    if (mb_strlen($mensagem) > 500) {
        json_out('error', 'O aviso pode ter no máximo 500 caracteres.', [], 422);
    }
    $st = $pdo->prepare('INSERT INTO chat_avisos_geral (usuario_id, mensagem, data_envio) VALUES (?, ?, NOW())');
    $st->execute([user_id(), $mensagem]);
    $novo = (int) $pdo->lastInsertId();
    log_activity('criar', 'aviso', $novo, 'Aviso geral publicado: ' . mb_substr($mensagem, 0, 80));
    json_out('ok', 'Aviso publicado.', ['id' => $novo]);
} catch (PDOException $e) {
    json_out('error', db_error_message($e, 'Não foi possível acessar o mural de avisos agora.'), [], 500);
}
