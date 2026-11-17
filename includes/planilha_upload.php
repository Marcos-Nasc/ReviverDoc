<?php
/** Substitui a planilha de livros usada na sugestão de livro (somente administradores). */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/planilha_livros.php';
api_guard(NIVEL_ADMIN, ['POST']);

$f = $_FILES['planilha'] ?? null;
if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
    json_out('error', 'Selecione o arquivo .xlsx da planilha de livros.', [], 422);
}
if ($f['error'] !== UPLOAD_ERR_OK) {
    json_out('error', upload_error_message($f['error']), [], 422);
}
if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
    json_out('error', 'Envie a planilha no formato .xlsx (Excel).', [], 422);
}
try {
    $linhas = planilha_ler_xlsx($f['tmp_name']);
} catch (Exception $e) {
    json_out('error', 'Não foi possível ler a planilha. Confira se é um arquivo .xlsx válido.', [], 422);
}
$cab = mb_strtoupper(implode(' ', $linhas[0] ?? []));
if (strpos($cab, 'LIVRO') === false || strpos($cab, 'ANO') === false) {
    json_out('error', 'A primeira linha da planilha precisa ter os títulos das colunas (Nº DO LIVRO, SETOR, PERÍODO, MÊS 1, MÊS 2, ANO 1, ANO 2).', [], 422);
}
$destino = PLANILHA_LIVROS;
if (is_file($destino)) {
    @copy($destino, __DIR__ . '/../backup_temp/planilha_arquivo_anterior_' . date('Y-m-d_H-i-s') . '.xlsx');
}
if (!move_uploaded_file($f['tmp_name'], $destino)) {
    json_out('error', 'Não foi possível gravar a planilha na pasta do sistema. Verifique as permissões de escrita.', [], 500);
}
@unlink(planilha_livros_cache_file());
clearstatcache();
$total = count(planilha_livros());
log_activity('upload', 'planilha', null, "Planilha de livros atualizada ({$total} livros)");
json_out('ok', "Planilha atualizada: {$total} livros carregados.", ['total' => $total]);
