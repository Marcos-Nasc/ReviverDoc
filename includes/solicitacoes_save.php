<?php
require_once __DIR__ . '/solicitacoes_lib.php';
api_guard(NIVEL_SETOR, ['POST']);

// Arquivo pausou o recebimento: setores não abrem novas solicitações (a equipe do Arquivo ainda pode registrar)
if (!(int) ($_POST['id'] ?? 0) && user_level() < NIVEL_ARQUIVO && !solicitacoes_recebimento()['aberto']) {
    $r = solicitacoes_recebimento();
    json_out('error', 'O Arquivo pausou o recebimento de novas solicitações no momento.' . ($r['mensagem'] !== '' ? ' Aviso: ' . $r['mensagem'] : ''), ['pausado' => true], 423);
}

$nul = function ($k) { $v = trim((string) ($_POST[$k] ?? '')); return $v === '' ? null : $v; };

$id             = (int) ($_POST['id'] ?? 0);
$tipo           = $nul('tipo');
$setor_id       = $nul('setor_id');
$informacao     = $nul('informacao');
$observacao     = $nul('observacao');
$prazo_raw      = $nul('prazo');
$setor_pesquisa = $nul('setor_pesquisa');
$prazo          = null;
if ($prazo_raw) {
    $ts = strtotime($prazo_raw);
    if (!$ts) {
        json_out('error', 'Informe um prazo válido.', [], 422);
    }
    $prazo = date('Y-m-d H:i:s', $ts);
    // Na criação, o prazo não pode já estar vencido (na edição, prazos antigos são mantidos)
    if (!(int) ($_POST['id'] ?? 0) && $ts < time() - 60) {
        json_out('error', 'O prazo informado já passou. Escolha uma data e hora futuras.', [], 422);
    }
}
if (!$tipo) {
    json_out('error', 'Informe o tipo da solicitação.', [], 422);
}
if ($setor_pesquisa !== null && !ctype_digit($setor_pesquisa)) {
    json_out('error', 'Selecione o setor da pesquisa na lista.', [], 422);
}
// Usuário de setor sempre solicita em nome do próprio setor
if (user_level() < NIVEL_ARQUIVO) {
    if ($id) {
        json_out('error', 'Você não tem permissão para editar solicitações.', [], 403);
    }
    exigir_setor_do_usuario();
    $setor_id = user_setor();
}

try {
    if ($id) {
        $chk = $pdo->prepare('SELECT COUNT(*) FROM solicitacoes WHERE id = ?');
        $chk->execute([$id]);
        if (!(int) $chk->fetchColumn()) {
            json_out('error', 'Solicitação não encontrada. Ela pode ter sido excluída.', [], 404);
        }
        // Sem setor escolhido, mantém o valor já gravado (inclusive registros antigos)
        $st = $pdo->prepare('UPDATE solicitacoes SET tipo = ?, setor_id = ?, informacao = ?, observacao = ?, prazo = ?, setor_pesquisa = COALESCE(?, setor_pesquisa) WHERE id = ?');
        $st->execute([$tipo, $setor_id, $informacao, $observacao, $prazo, $setor_pesquisa, $id]);
        log_activity('editar', 'solicitacao', $id, "Solicitação #{$id} atualizada");
        json_out('ok', 'Solicitação atualizada.', ['id' => $id]);
    }

    $agora = date('Y-m-d H:i:s');
    $st = $pdo->prepare("INSERT INTO solicitacoes (tipo, setor_id, informacao, observacao, prazo, data_solicitacao, status, resultado, setor_pesquisa, usuario_id)
                         VALUES (?, ?, ?, ?, ?, ?, 'Pendente', 'Não Realizada', ?, ?)");
    $st->execute([$tipo, $setor_id, $informacao, $observacao, $prazo, $agora, $setor_pesquisa, user_id() ?: null]);
    $novo = (int) $pdo->lastInsertId();
    log_activity('criar', 'solicitacao', $novo, "Solicitação #{$novo} criada ({$tipo})");

    // E-mail para o Arquivo (configurado em Sistema e e-mail)
    $cfg = app_config();
    $tpl = __DIR__ . '/email_template.html';
    if (!empty($cfg['email_notificacao']) && is_file($tpl)) {
        $setor_nome = $setor_id ? (setor_nome($setor_id) ?: 'Não informado') : 'Não informado';
        $corpo = strtr(file_get_contents($tpl), [
            '{{id_solicitacao}}'   => $novo,
            '{{setor_origem}}'     => e($setor_nome),
            '{{tipo}}'             => e($tipo),
            '{{informacao}}'       => nl2br(e((string) $informacao)),
            '{{observacao}}'       => nl2br(e((string) $observacao)),
            '{{data_solicitacao}}' => date('d/m/Y H:i', strtotime($agora)),
            '{{prazo}}'            => $prazo ? date('d/m/Y H:i', strtotime($prazo)) : 'Não definido',
            '{{ano_atual}}'        => date('Y'),
        ]);
        send_mail($cfg['email_notificacao'], "Nova Solicitação de Pesquisa (#{$novo}) - ReviverDoc", $corpo, 'ReviverDoc');
    }
    notify(equipe_arquivo_ids(), "Nova solicitação de pesquisa (#{$novo}) recebida.", 'php/solicitacoes.php?id=' . $novo, false);

    json_out('ok', 'Solicitação enviada ao Arquivo.', ['id' => $novo]);
} catch (PDOException $e) {
    json_out('error', db_error_message($e, 'Não foi possível salvar a solicitação. Tente novamente.'), [], 500);
}
