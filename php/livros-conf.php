<?php
require_once '../includes/layout.php';
require_level(NIVEL_ARQUIVO);

$page = current_page();
$per_page = 20;
$offset = ($page - 1) * $per_page;

$busca        = trim($_GET['busca'] ?? '');
$setor_id     = $_GET['setor'] ?? '';
$digitalizado = $_GET['digitalizado'] ?? '';

$where = ' WHERE 1';
$params = [];
if ($busca !== '') {
    $where .= ' AND (l.numero_livro LIKE :busca OR l.posicao LIKE :busca)';
    $params[':busca'] = "%$busca%";
}
if ($setor_id !== '') {
    $where .= ' AND l.setor = :setor';
    $params[':setor'] = $setor_id;
}
if (in_array($digitalizado, ['Sim', 'Não'], true)) {
    $where .= ' AND l.digitalizado = :digitalizado';
    $params[':digitalizado'] = $digitalizado;
}
$from = ' FROM livros l LEFT JOIN setores s ON l.setor = s.id';

$st = $pdo->prepare('SELECT COUNT(*)' . $from . $where);
$st->execute($params);
$total_records = (int) $st->fetchColumn();
$total_pages = (int) ceil($total_records / $per_page);

$stmt = $pdo->prepare('SELECT l.*, s.nome AS setor_nome, (SELECT COUNT(*) FROM paginas p WHERE p.livro_id = l.id) AS total_paginas'
    . $from . $where . ' ORDER BY l.id ASC LIMIT :limit OFFSET :offset');
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$livros = $stmt->fetchAll();

$setores = all_setores();

layout_start('Gerenciar livros', [
    'active'   => 'livros-conf',
    'subtitle' => 'Cadastre e edite os livros do arquivo. As páginas de cada livro são gerenciadas em “Gerenciar páginas”.',
    'actions'  => '<a class="btn btn-secondary" href="livros.php"><i class="fa-solid fa-book"></i>Consulta de livros</a>'
                . '<button type="button" class="btn btn-primary" id="btnNovo"><i class="fa-solid fa-plus"></i>Novo livro</button>',
]);
?>
<section class="panel">
    <form method="GET" class="filters">
        <div class="field field-search">
            <label for="f-busca">Nº do livro</label>
            <div class="input-icon"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="f-busca" name="busca" placeholder="Número ou posição" value="<?= e($busca) ?>">
            </div>
        </div>
        <div class="field">
            <label for="f-setor">Setor</label>
            <select id="f-setor" name="setor"><?= setor_options($setores, $setor_id, 'Todos') ?></select>
        </div>
        <div class="field">
            <label for="f-dig">Digitalizado</label>
            <select id="f-dig" name="digitalizado">
                <option value="">Todos</option>
                <option value="Sim" <?= $digitalizado === 'Sim' ? 'selected' : '' ?>>Sim</option>
                <option value="Não" <?= $digitalizado === 'Não' ? 'selected' : '' ?>>Não</option>
            </select>
        </div>
        <div class="filters-actions">
            <a class="btn btn-ghost" href="livros-conf.php">Limpar</a>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i>Filtrar</button>
        </div>
    </form>

    <?php if (!$livros): ?>
        <?= empty_state('Nenhum livro encontrado', 'Ajuste os filtros ou cadastre um novo livro.', 'fa-book') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table responsive">
            <thead>
                <tr>
                    <th>Nº livro</th>
                    <th>Período</th>
                    <th>Posição</th>
                    <th>Setor</th>
                    <th>Páginas</th>
                    <th>Digitalizado</th>
                    <th>Digitalização</th>
                    <th class="col-actions"><span class="sr-only">Ações</span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($livros as $l): ?>
                <tr>
                    <td class="col-primary" data-label="Nº livro"><span class="tag-id"><small>Livro</small><?= e($l['numero_livro']) ?></span></td>
                    <td data-label="Período" class="nowrap"><?= e(fmt_date($l['data_inicio'])) ?> – <?= e(fmt_date($l['data_fim'])) ?></td>
                    <td data-label="Posição"><?= e($l['posicao'] ?: '—') ?></td>
                    <td data-label="Setor"><?= e($l['setor_nome'] ?: '—') ?></td>
                    <td data-label="Páginas"><a href="paginas.php?livro_id=<?= (int) $l['id'] ?>"><?= fmt_num($l['total_paginas']) ?></a></td>
                    <td data-label="Digitalizado"><?= $l['digitalizado'] === 'Sim' ? '<span class="badge badge-success">Sim</span>' : '<span class="badge badge-warning">Não</span>' ?></td>
                    <td data-label="Digitalização" class="nowrap"><?= e(fmt_date($l['data_digitalizacao'])) ?></td>
                    <td class="col-actions">
                        <div class="row-actions">
                            <a class="act" title="Gerenciar páginas" aria-label="Gerenciar páginas do livro" href="paginas.php?livro_id=<?= (int) $l['id'] ?>"><i class="fa-regular fa-copy"></i></a>
                            <button type="button" class="act" title="Editar" aria-label="Editar livro" onclick="editarLivro(<?= (int) $l['id'] ?>)"><i class="fa-solid fa-pen"></i></button>
                            <button type="button" class="act is-danger" title="Excluir" aria-label="Excluir livro" onclick="excluirLivro(<?= (int) $l['id'] ?>, <?= e(json_encode((string) $l['numero_livro'])) ?>, <?= (int) $l['total_paginas'] ?>, this)"><i class="fa-regular fa-trash-can"></i></button>
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

<div class="modal" id="modalLivro" role="dialog" aria-modal="true" aria-labelledby="modalTitulo">
    <div class="modal-dialog">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="modalTitulo">Novo livro</h2>
                <p class="modal-sub">Campos com <span class="text-danger">*</span> são obrigatórios.</p>
            </div>
            <button class="icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="formLivro" class="modal-body" novalidate>
            <input type="hidden" name="id">
            <div class="form-grid">
                <div class="field">
                    <label for="l-num">Nº do livro<span class="req">*</span></label>
                    <input type="number" id="l-num" name="numero_livro" min="0" step="1" required>
                </div>
                <div class="field">
                    <label for="l-pos">Posição</label>
                    <input type="text" id="l-pos" name="posicao" maxlength="10" placeholder="Ex.: P3">
                </div>
                <div class="field">
                    <label for="l-ini">Data início</label>
                    <input type="date" id="l-ini" name="data_inicio">
                </div>
                <div class="field">
                    <label for="l-fim">Data fim</label>
                    <input type="date" id="l-fim" name="data_fim">
                </div>
                <div class="field span-2">
                    <label for="l-setor">Setor</label>
                    <select id="l-setor" name="setor"><?= setor_options($setores, '', 'Nenhum') ?></select>
                </div>
                <div class="field">
                    <label for="l-dig">Digitalizado</label>
                    <select id="l-dig" name="digitalizado">
                        <option value="Não">Não</option>
                        <option value="Sim">Sim</option>
                    </select>
                </div>
                <div class="field">
                    <label for="l-ddig">Data de digitalização</label>
                    <input type="date" id="l-ddig" name="data_digitalizacao">
                </div>
            </div>
        </form>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-modal-close>Cancelar</button>
            <button type="submit" form="formLivro" class="btn btn-primary"><i class="fa-solid fa-check"></i>Salvar livro</button>
        </div>
    </div>
</div>

<?php
layout_end(['scripts' => <<<'JS'
<script>
var form = document.getElementById('formLivro');
document.getElementById('btnNovo').addEventListener('click', function () {
    App.resetForm(form);
    document.getElementById('modalTitulo').textContent = 'Novo livro';
    App.openModal('modalLivro');
});
function editarLivro(id) {
    App.resetForm(form);
    document.getElementById('modalTitulo').textContent = 'Editar livro';
    App.openModal('modalLivro');
    App.modalLoading('modalLivro', true);
    App.api('../includes/livros_get.php?id=' + id)
        .then(function (d) { App.fillForm(form, d); })
        .catch(function (e) { App.toast(e.message, 'error'); App.closeModal('modalLivro'); })
        .finally(function () { App.modalLoading('modalLivro', false); });
}
function excluirLivro(id, numero, paginas, btn) {
    if (paginas > 0) {
        App.confirm({
            title: 'O livro ' + numero + ' tem páginas',
            message: 'Este livro possui ' + paginas + ' página(s) cadastrada(s). Exclua as páginas antes de excluir o livro.',
            confirmText: 'Ver páginas', danger: false
        }).then(function (sim) { if (sim) window.location.href = 'paginas.php?livro_id=' + id; });
        return;
    }
    App.action({
        title: 'Excluir livro ' + numero + '?',
        message: 'O livro será removido do cadastro.',
        confirmText: 'Excluir livro',
        url: '../includes/livros_delete.php?id=' + id,
        button: btn
    });
}
form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (!form.reportValidity()) return;
    var ini = form.elements.data_inicio.value, fim = form.elements.data_fim.value;
    if (ini && fim && fim < ini) { App.toast('A data fim não pode ser anterior à data início.', 'error'); form.elements.data_fim.focus(); return; }
    App.submitForm(form, { url: '../includes/livros_save.php', modal: 'modalLivro' });
});
(function () {
    var m = location.search.match(/[?&]editar=(\d+)/);
    if (m) editarLivro(m[1]);
})();
</script>
JS
]);
