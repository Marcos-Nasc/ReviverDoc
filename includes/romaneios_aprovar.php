<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ADMIN, ['POST']);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) {
    json_out('error', 'Romaneio não informado.', [], 400);
}
try {
    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT conteudo, setor_id, usuario_id FROM romaneios WHERE id = ? AND tipo = 'Entrada' AND (aprovado IS NULL OR aprovado <> 'Sim') FOR UPDATE");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) {
        $pdo->rollBack();
        json_out('error', 'Romaneio de entrada pendente não encontrado ou já processado.', [], 404);
    }
    $novas = json_decode((string) $r['conteudo'], true);
    if (!is_array($novas) || !$novas) {
        $pdo->rollBack();
        json_out('error', 'Não há caixas descritas neste romaneio ou os dados estão corrompidos.', [], 422);
    }
    $prox = proximo_numero_caixa();
    $ins  = $pdo->prepare("INSERT INTO caixas (numero, conteudo, setor_id, data_inicio, data_fim, observacao, status) VALUES (?, ?, ?, ?, ?, ?, 'No Arquivo')");
    $link = $pdo->prepare('INSERT INTO romaneio_caixas (romaneio_id, caixa_id) VALUES (?, ?)');
    $criadas = [];
    $numeros = [];
    foreach ($novas as $c) {
        $conteudo = trim((string) ($c['conteudo'] ?? ''));
        if ($conteudo === '') continue;
        // Usa o setor informado para cada caixa; se ausente, o setor do romaneio
        $setor = !empty($c['setor_id']) && setor_nome($c['setor_id']) ? (int) $c['setor_id'] : $r['setor_id'];
        $ins->execute([
            (string) $prox, $conteudo, $setor,
            valid_date($c['data_inicio'] ?? '') ? $c['data_inicio'] : null,
            valid_date($c['data_fim'] ?? '') ? $c['data_fim'] : null,
            trim((string) ($c['observacao'] ?? '')),
        ]);
        $link->execute([$id, (int) $pdo->lastInsertId()]);
        $criadas[] = $conteudo;
        $numeros[] = $prox;
        $prox++;
    }
    if (!$criadas) {
        $pdo->rollBack();
        json_out('error', 'Nenhuma caixa válida para criar neste romaneio.', [], 422);
    }
    $final = 'Caixas criadas: ' . implode('; ', $criadas);
    $pdo->prepare("UPDATE romaneios SET aprovado = 'Sim', status = 'Concluído', conteudo = ? WHERE id = ?")->execute([$final, $id]);
    $pdo->commit();

    $faixa = count($numeros) > 1 ? 'nº ' . reset($numeros) . ' a ' . end($numeros) : 'nº ' . reset($numeros);
    notificacoes_marcar_lidas_link('php/romaneio-conf.php?id=' . $id);
    log_activity('aprovar', 'romaneio', $id, 'Romaneio de entrada aprovado; caixas ' . $faixa);
    if ((int) $r['usuario_id'] && (int) $r['usuario_id'] !== user_id()) {
        notify([(int) $r['usuario_id']], 'Seu romaneio de entrada #' . fmt_id($id) . ' foi aprovado (caixas ' . $faixa . ').', 'php/romaneios.php?id=' . $id, false);
    }
    json_out('ok', 'Romaneio aprovado. ' . count($criadas) . ' caixa(s) criada(s): ' . $faixa . '.');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out('error', db_error_message($e, 'Não foi possível aprovar o romaneio.'), [], 500);
}
