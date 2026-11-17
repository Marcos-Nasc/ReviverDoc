<?php
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ARQUIVO, ['POST']);
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) {
    json_out('error', 'Setor não informado.', [], 400);
}
try {
    $nome = setor_nome($id);
    // Confere todos os vínculos (não depende só das chaves estrangeiras do banco)
    $vinculos = [
        'usuários'                => 'SELECT COUNT(*) FROM usuarios WHERE setor_id = ?',
        'caixas'                  => 'SELECT COUNT(*) FROM caixas WHERE setor_id = ? OR setor_alocado = ?',
        'livros'                  => 'SELECT COUNT(*) FROM livros WHERE setor = ?',
        'solicitações'            => "SELECT COUNT(*) FROM solicitacoes WHERE setor_id = ? OR setor_pesquisa = ?",
        'romaneios'               => 'SELECT COUNT(*) FROM romaneios WHERE setor_id = ?',
    ];
    $usos = [];
    foreach ($vinculos as $rotulo => $sql) {
        $c = $pdo->prepare($sql);
        $c->execute(array_fill(0, substr_count($sql, '?'), $id));
        $n = (int) $c->fetchColumn();
        if ($n) $usos[] = $n . ' ' . $rotulo;
    }
    if ($usos) {
        json_out('error', "O setor {$nome} não pode ser excluído porque está vinculado a: " . implode(', ', $usos) . '.', [], 409);
    }
    $st = $pdo->prepare('DELETE FROM setores WHERE id = ?');
    $st->execute([$id]);
    if (!$st->rowCount()) {
        json_out('error', 'Setor não encontrado ou já excluído.', [], 404);
    }
    log_activity('excluir', 'setor', $id, "Setor {$nome} excluído");
    json_out('ok', 'Setor excluído.');
} catch (PDOException $e) {
    if (is_constraint_error($e)) {
        json_out('error', 'Este setor está vinculado a usuários, caixas, livros, solicitações ou romaneios e não pode ser excluído.', [], 409);
    }
    json_out('error', db_error_message($e, 'Não foi possível excluir o setor.'), [], 500);
}
