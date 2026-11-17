<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_SETOR, ['POST']);
exigir_setor_do_usuario();

$tipo       = ($_POST['tipo'] ?? 'Saída') === 'Entrada' ? 'Entrada' : 'Saída';
$data_saida = post_date('data_saida') ?: date('Y-m-d');
$usuario_id = user_id();

// Setor do romaneio: equipe do Arquivo pode escolher; usuário de setor usa sempre o próprio setor
$setor_id = user_setor();
if (user_level() >= NIVEL_ARQUIVO && !empty($_POST['setor_id'])) {
    $setor_id = (int) $_POST['setor_id'];
    if (!setor_nome($setor_id)) {
        json_out('error', 'Setor inválido.', [], 422);
    }
}
if (!$setor_id) {
    json_out('error', 'Informe o setor do romaneio.', [], 422);
}

$descricao = trim(str_replace("\r\n", "\n", (string) ($_POST['descricao'] ?? '')));
if (mb_strlen($descricao) > 2000) {
    json_out('error', 'A descrição pode ter no máximo 2000 caracteres.', [], 422);
}

try {
    if ($tipo === 'Saída') {
        // Saída: o solicitante descreve o que precisa; o Arquivo separa o material
        $data_retorno = post_date('data_retorno');
        if ($descricao === '') {
            json_out('error', 'Descreva o que deve sair do arquivo (ex.: contratos do RH de 2019).', [], 422);
        }
        if ($data_retorno && $data_retorno < $data_saida) {
            json_out('error', 'A data de retorno não pode ser anterior à data da movimentação.', [], 422);
        }
        $st = $pdo->prepare('INSERT INTO romaneios (tipo, usuario_id, conteudo, data_saida, data_retorno, status, aprovado, setor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$tipo, $usuario_id, $descricao, $data_saida, $data_retorno, 'Pendente', 'Sim', $setor_id]);
        $romaneio_id = (int) $pdo->lastInsertId();
        log_activity('criar', 'romaneio', $romaneio_id, 'Romaneio de saída: ' . mb_strimwidth($descricao, 0, 120, '…'));
        json_out('ok', 'Romaneio de saída #' . fmt_id($romaneio_id) . ' registrado.', ['id' => $romaneio_id]);
    }

    // Entrada: novas caixas aguardam aprovação de um administrador
    $novas = (array) ($_POST['new_caixas'] ?? []);
    $itens = [];
    foreach ($novas as $c) {
        if (!is_array($c)) continue;
        $conteudo = trim((string) ($c['conteudo'] ?? ''));
        if ($conteudo === '') {
            json_out('error', 'Informe o conteúdo de cada caixa de entrada.', [], 422);
        }
        $ini = valid_date($c['data_inicio'] ?? '') ? $c['data_inicio'] : null;
        $fim = valid_date($c['data_fim'] ?? '') ? $c['data_fim'] : null;
        if ($ini && $fim && $fim < $ini) {
            json_out('error', "A data fim da caixa “{$conteudo}” é anterior à data início.", [], 422);
        }
        // Usuário de setor só cadastra caixas do próprio setor; a equipe do Arquivo pode escolher o setor de cada caixa
        $setor_caixa = (user_level() >= NIVEL_ARQUIVO && !empty($c['setor_id'])) ? (int) $c['setor_id'] : (int) $setor_id;
        $itens[] = [
            'conteudo'    => $conteudo,
            'setor_id'    => $setor_caixa,
            'data_inicio' => $ini,
            'data_fim'    => $fim,
            'observacao'  => trim((string) ($c['observacao'] ?? '')),
        ];
    }
    if (!$itens) {
        json_out('error', 'Adicione pelo menos uma caixa ao romaneio de entrada.', [], 422);
    }
    $st = $pdo->prepare('INSERT INTO romaneios (tipo, usuario_id, conteudo, data_saida, status, aprovado, setor_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$tipo, $usuario_id, json_encode($itens, JSON_UNESCAPED_UNICODE), $data_saida, 'Pendente de Aprovação', 'Não', $setor_id]);
    $romaneio_id = (int) $pdo->lastInsertId();

    log_activity('criar', 'romaneio', $romaneio_id, 'Romaneio de entrada com ' . count($itens) . ' caixa(s) aguardando aprovação');
    notify(user_ids_by_level(NIVEL_ADMIN), 'Romaneio de entrada #' . fmt_id($romaneio_id) . ' (' . (setor_nome($setor_id) ?: 'setor') . ') aguarda aprovação.', 'php/romaneio-conf.php?id=' . $romaneio_id);
    json_out('ok', 'Romaneio de entrada #' . fmt_id($romaneio_id) . ' enviado para aprovação.', ['id' => $romaneio_id]);
} catch (PDOException $e) {
    json_out('error', db_error_message($e, 'Não foi possível salvar o romaneio. Tente novamente.'), [], 500);
}
