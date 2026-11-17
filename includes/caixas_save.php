<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ARQUIVO, ['POST']);

$nul = function ($k) { $v = trim((string) ($_POST[$k] ?? '')); return $v === '' ? null : $v; };

$id                 = (int) ($_POST['id'] ?? 0);
$setor_id           = $nul('setor_id');
$conteudo           = $nul('conteudo');
$data_inicio        = post_date('data_inicio');
$data_fim           = post_date('data_fim');
$observacao         = $nul('observacao');
$status             = in_array($_POST['status'] ?? '', ['No Arquivo', 'Fora do Arquivo'], true) ? $_POST['status'] : 'No Arquivo';
$higienizada        = in_array($_POST['higienizada'] ?? '', ['Sim', 'Não'], true) ? $_POST['higienizada'] : 'Sim';
$setor_alocado      = $nul('setor_alocado');
$coluna             = $nul('coluna');
$fileira            = $nul('fileira');
$data_digitalizacao = post_date('data_digitalizacao');
$numero             = $nul('numero') ?? '-';

if (!$setor_id || !$conteudo) {
    json_out('error', 'Informe o setor de origem e o conteúdo da caixa.', [], 422);
}
if ($data_inicio && $data_fim && $data_fim < $data_inicio) {
    json_out('error', 'A data fim não pode ser anterior à data início.', [], 422);
}
if (numero_caixa_invalido($numero)) {
    json_out('error', 'Número de caixa inválido: use só números positivos (ex.: 1071) ou "-" para caixa sem etiqueta.', [], 422);
}
if (mb_strlen($numero) > 20) {
    json_out('error', 'O número da caixa pode ter no máximo 20 caracteres.', [], 422);
}

try {
    // Aviso de número duplicado (exceto "-", usado para caixas sem etiqueta)
    if ($numero !== '-') {
        $dup = $pdo->prepare('SELECT COUNT(*) FROM caixas WHERE numero = ? AND id <> ?');
        $dup->execute([$numero, $id]);
        if ((int) $dup->fetchColumn() > 0 && empty($_POST['confirmar_duplicado'])) {
            json_out('error', "Já existe uma caixa com o número {$numero}. Verifique o número antes de salvar.", ['duplicado' => true], 409);
        }
    }
    if ($id) {
        $st = $pdo->prepare('UPDATE caixas SET setor_id = ?, numero = ?, conteudo = ?, data_inicio = ?, data_fim = ?, observacao = ?,
                status = ?, higienizada = ?, setor_alocado = ?, coluna = ?, fileira = ?, data_digitalizacao = ? WHERE id = ?');
        $st->execute([$setor_id, $numero, $conteudo, $data_inicio, $data_fim, $observacao, $status, $higienizada, $setor_alocado, $coluna, $fileira, $data_digitalizacao, $id]);
        log_activity('editar', 'caixa', $id, "Caixa {$numero} atualizada");
        json_out('ok', 'Caixa atualizada.');
    }
    $st = $pdo->prepare('INSERT INTO caixas (numero, setor_id, conteudo, data_inicio, data_fim, observacao, status, higienizada, setor_alocado, coluna, fileira, data_digitalizacao)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$numero, $setor_id, $conteudo, $data_inicio, $data_fim, $observacao, $status, $higienizada, $setor_alocado, $coluna, $fileira, $data_digitalizacao]);
    $novo = (int) $pdo->lastInsertId();
    log_activity('criar', 'caixa', $novo, "Caixa {$numero} cadastrada ({$conteudo})");
    json_out('ok', 'Caixa cadastrada.', ['id' => $novo]);
} catch (PDOException $e) {
    json_out('error', db_error_message($e, 'Não foi possível salvar a caixa. Verifique os dados e tente novamente.'), [], 500);
}
