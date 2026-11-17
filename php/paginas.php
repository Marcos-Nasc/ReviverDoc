<?php
require_once '../includes/layout.php';
require_level(NIVEL_ARQUIVO);

$page = current_page();
$per_page = 20;
$offset = ($page - 1) * $per_page;

$busca       = trim($_GET['busca'] ?? '');
$livro_id    = $_GET['livro_id'] ?? '';
$data_inicio = $_GET['data_inicio'] ?? '';
$data_fim    = $_GET['data_fim'] ?? '';

$where = ' WHERE 1';
$params = [];
if ($busca !== '') {
    $where .= ' AND (p.numero_pagina LIKE :busca OR p.nome_original LIKE :busca)';
    $params[':busca'] = "%$busca%";
}
if ($livro_id !== '') {
    $where .= ' AND p.livro_id = :livro_id';
    $params[':livro_id'] = $livro_id;
}
if (valid_date($data_inicio)) {
    $where .= ' AND p.data_fim >= :data_inicio';
    $params[':data_inicio'] = $data_inicio;
}
if (valid_date($data_fim)) {
    $where .= ' AND p.data_inicio <= :data_fim';
    $params[':data_fim'] = $data_fim;
}
$from = ' FROM paginas p LEFT JOIN livros l ON p.livro_id = l.id LEFT JOIN setores s ON l.setor = s.id';

$st = $pdo->prepare('SELECT COUNT(*)' . $from . $where);
$st->execute($params);
$total_records = (int) $st->fetchColumn();
$total_pages = (int) ceil($total_records / $per_page);

$stmt = $pdo->prepare('SELECT p.*, l.numero_livro, s.nome AS setor_nome' . $from . $where
    . ' ORDER BY l.numero_livro ASC, p.numero_pagina ASC, p.id ASC LIMIT :limit OFFSET :offset');
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$paginas = $stmt->fetchAll();

$livros = $pdo->query('SELECT l.id, l.numero_livro, s.nome AS setor_nome FROM livros l LEFT JOIN setores s ON l.setor = s.id ORDER BY l.numero_livro, setor_nome')->fetchAll();
$livroLabel = function ($l) {
    return 'Livro ' . $l['numero_livro'] . ' · ' . ($l['setor_nome'] ?: 'sem setor');
};

layout_start('Gerenciar páginas', [
    'active'   => 'paginas',
    'subtitle' => 'Envie as páginas digitalizadas (PDF) de cada livro e informe o período que cobrem.',
    'actions'  => '<a class="btn btn-secondary" href="livros-conf.php"><i class="fa-solid fa-book-bookmark"></i>Gerenciar livros</a>'
                . '<button type="button" class="btn btn-primary" id="btnNovo"><i class="fa-solid fa-plus"></i>Nova página</button>',
]);
?>
<section class="panel">
    <form method="GET" class="filters">
        <div class="field field-search">
            <label for="f-busca">Nº da página ou nome do arquivo</label>
            <div class="input-icon"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="f-busca" name="busca" placeholder="Digite…" value="<?= e($busca) ?>">
            </div>
        </div>
        <div class="field">
            <label for="f-livro">Livro</label>
            <select id="f-livro" name="livro_id">
                <option value="">Todos</option>
                <?php foreach ($livros as $l): ?>
                    <option value="<?= (int) $l['id'] ?>" <?= (string) $livro_id === (string) $l['id'] ? 'selected' : '' ?>><?= e($livroLabel($l)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="f-ini">Data a partir de</label>
            <input type="date" id="f-ini" name="data_inicio" value="<?= e($data_inicio) ?>">
        </div>
        <div class="field">
            <label for="f-fim">Data até</label>
            <input type="date" id="f-fim" name="data_fim" value="<?= e($data_fim) ?>">
        </div>
        <div class="filters-actions">
            <a class="btn btn-ghost" href="paginas.php">Limpar</a>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i>Filtrar</button>
        </div>
    </form>

    <?php if (!$paginas): ?>
        <?= empty_state('Nenhuma página encontrada', 'Ajuste os filtros ou envie uma nova página digitalizada.', 'fa-copy') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table responsive">
            <thead>
                <tr>
                    <th>Página</th>
                    <th>Livro / setor</th>
                    <th>Período</th>
                    <th>Arquivo</th>
                    <th>Digitalização</th>
                    <th class="col-actions"><span class="sr-only">Ações</span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($paginas as $p):
                $titulo = $p['numero_pagina'] . ($p['letra'] ? strtoupper($p['letra']) : ''); ?>
                <tr>
                    <td class="col-primary" data-label="Página"><span class="tag-id"><small>Pág.</small><?= e($titulo) ?></span></td>
                    <td data-label="Livro / setor"><?= $p['numero_livro'] !== null ? '<span>Livro ' . e($p['numero_livro']) . '<span class="cell-sub">' . e($p['setor_nome'] ?: 'sem setor') . '</span></span>' : '<span class="muted">—</span>' ?></td>
                    <td data-label="Período" class="nowrap"><?= e(fmt_date($p['data_inicio'])) ?><?= ($p['data_fim'] && $p['data_fim'] !== $p['data_inicio']) ? ' – ' . e(fmt_date($p['data_fim'])) : '' ?></td>
                    <td data-label="Arquivo" class="wrap-text">
                        <?php if (!empty($p['arquivo'])): ?>
                            <a href="../uploads/paginas/<?= e(rawurlencode($p['arquivo'])) ?>" target="_blank" rel="noopener"><?= e($p['nome_original'] ?: $p['arquivo']) ?></a>
                        <?php else: ?>
                            <span class="badge badge-warning">Sem PDF</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Digitalização" class="nowrap"><?= e(fmt_date($p['data_digitalizacao'])) ?></td>
                    <td class="col-actions">
                        <div class="row-actions">
                            <button type="button" class="act" title="Editar" aria-label="Editar página" onclick="editarPagina(<?= (int) $p['id'] ?>)"><i class="fa-solid fa-pen"></i></button>
                            <button type="button" class="act is-danger" title="Excluir" aria-label="Excluir página" onclick="excluirPagina(<?= (int) $p['id'] ?>, <?= e(json_encode('página ' . $titulo . ($p['numero_livro'] !== null ? ' do livro ' . $p['numero_livro'] : ''), JSON_UNESCAPED_UNICODE)) ?>, this)"><i class="fa-regular fa-trash-can"></i></button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?= render_pagination($page, $total_pages, $total_records, $per_page) ?>
</section>

<div class="modal" id="modalPagina" role="dialog" aria-modal="true" aria-labelledby="modalTitulo">
    <div class="modal-dialog">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="modalTitulo">Nova página</h2>
                <p class="modal-sub">Campos com <span class="text-danger">*</span> são obrigatórios.</p>
            </div>
            <button class="icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="formPagina" class="modal-body" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="id">
            <div class="form-grid">
                <div class="field span-2">
                    <label for="p-livro">Livro<span class="req">*</span></label>
                    <select id="p-livro" name="livro_id" required>
                        <option value="">Selecione o livro</option>
                        <?php foreach ($livros as $l): ?>
                            <option value="<?= (int) $l['id'] ?>"><?= e($livroLabel($l)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="p-num">Nº da página<span class="req">*</span></label>
                    <input type="number" id="p-num" name="numero_pagina" min="0" step="1" required>
                </div>
                <div class="field">
                    <label for="p-letra">Letra (opcional)</label>
                    <input type="text" id="p-letra" name="letra" maxlength="1" placeholder="Ex.: A para 12A">
                </div>
                <div class="field">
                    <label for="p-ini">Data início</label>
                    <input type="date" id="p-ini" name="data_inicio">
                </div>
                <div class="field">
                    <label for="p-fim">Data fim</label>
                    <input type="date" id="p-fim" name="data_fim">
                </div>
                <div class="field">
                    <label for="p-dig">Data de digitalização</label>
                    <input type="date" id="p-dig" name="data_digitalizacao">
                </div>
                <div class="field span-2">
                    <span class="label">Arquivo PDF</span>
                    <label class="file-drop">
                        <span class="file-drop-icon"><i class="fa-regular fa-file-pdf"></i></span>
                        <span class="file-drop-text">
                            <strong data-file-label="Escolher arquivo PDF">Escolher arquivo PDF</strong>
                            <span>Clique ou arraste o arquivo aqui. Apenas PDF.</span>
                        </span>
                        <input type="file" name="arquivo" accept="application/pdf,.pdf">
                    </label>
                    <span class="help" id="arquivoAtual"></span>
                </div>
            </div>
        </form>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-modal-close>Cancelar</button>
            <button type="submit" form="formPagina" class="btn btn-primary"><i class="fa-solid fa-check"></i>Salvar página</button>
        </div>
    </div>
</div>

<?php
$livroFiltro = (int) $livro_id;
layout_end(['scripts' => <<<JS
<script>
var form = document.getElementById('formPagina');
var atual = document.getElementById('arquivoAtual');
document.getElementById('btnNovo').addEventListener('click', function () {
    App.resetForm(form);
    atual.innerHTML = '';
    if ({$livroFiltro}) form.elements.livro_id.value = '{$livroFiltro}';
    document.getElementById('modalTitulo').textContent = 'Nova página';
    App.openModal('modalPagina');
});
// Ao escolher a data início, sugere a mesma data como fim (páginas costumam cobrir um único dia)
form.elements.data_inicio.addEventListener('change', function () {
    if (!form.elements.data_fim.value) form.elements.data_fim.value = this.value;
});
function editarPagina(id) {
    App.resetForm(form);
    atual.innerHTML = '';
    document.getElementById('modalTitulo').textContent = 'Editar página';
    App.openModal('modalPagina');
    App.modalLoading('modalPagina', true);
    App.api('../includes/paginas_get.php?id=' + id)
        .then(function (d) {
            App.fillForm(form, d);
            atual.innerHTML = d.arquivo
                ? 'Arquivo atual: <a href="../uploads/paginas/' + encodeURIComponent(d.arquivo) + '" target="_blank" rel="noopener">' + App.esc(d.nome_original || d.arquivo) + '</a>. Envie outro PDF apenas se quiser substituí-lo.'
                : 'Nenhum arquivo anexado.';
        })
        .catch(function (e) { App.toast(e.message, 'error'); App.closeModal('modalPagina'); })
        .finally(function () { App.modalLoading('modalPagina', false); });
}
function excluirPagina(id, desc, btn) {
    App.action({
        title: 'Excluir página?',
        message: 'A ' + desc + ' e o PDF anexado serão removidos definitivamente.',
        confirmText: 'Excluir página',
        url: '../includes/paginas_delete.php?id=' + id,
        button: btn
    });
}
form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (!form.reportValidity()) return;
    var ini = form.elements.data_inicio.value, fim = form.elements.data_fim.value;
    if (ini && fim && fim < ini) { App.toast('A data fim não pode ser anterior à data início.', 'error'); form.elements.data_fim.focus(); return; }
    var file = form.elements.arquivo.files[0];
    if (file && !/\.pdf$/i.test(file.name)) { App.toast('Envie um arquivo em PDF.', 'error'); return; }
    App.submitForm(form, { url: '../includes/paginas_save.php', modal: 'modalPagina' });
});
(function () {
    var m = location.search.match(/[?&]editar=(\d+)/);
    if (m) editarPagina(m[1]);
})();
</script>
JS
]);
