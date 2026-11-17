<?php
require_once '../includes/layout.php';
require_level(NIVEL_ARQUIVO);

$page = current_page();
$per_page = 20;
$offset = ($page - 1) * $per_page;
$busca = trim($_GET['busca'] ?? '');
$where = ' WHERE 1';
$params = [];
if ($busca !== '') {
    $where .= ' AND s.nome LIKE :busca';
    $params[':busca'] = "%$busca%";
}
$st = $pdo->prepare('SELECT COUNT(*) FROM setores s' . $where);
$st->execute($params);
$total_records = (int) $st->fetchColumn();
$total_pages = (int) ceil($total_records / $per_page);

$stmt = $pdo->prepare('SELECT s.id, s.nome,
        (SELECT COUNT(*) FROM usuarios u WHERE u.setor_id = s.id) AS usuarios,
        (SELECT COUNT(*) FROM caixas c WHERE c.setor_id = s.id) AS caixas,
        (SELECT COUNT(*) FROM livros l WHERE l.setor = s.id) AS livros,
        (SELECT COUNT(*) FROM solicitacoes so WHERE so.setor_id = s.id) AS solicitacoes
    FROM setores s' . $where . ' ORDER BY s.nome ASC LIMIT :limit OFFSET :offset');
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$setores = $stmt->fetchAll();

layout_start('Setores', [
    'active'   => 'setores',
    'subtitle' => 'Setores da empresa usados em caixas, livros, usuários e solicitações.',
    'actions'  => '<button type="button" class="btn btn-primary" id="btnNovo"><i class="fa-solid fa-plus"></i>Novo setor</button>',
]);
?>
<section class="panel">
    <form method="GET" class="filters">
        <div class="field field-search">
            <label for="f-busca">Procurar</label>
            <div class="input-icon"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="f-busca" name="busca" placeholder="Nome do setor" value="<?= e($busca) ?>"></div>
        </div>
        <div class="filters-actions">
            <a class="btn btn-ghost" href="setores.php">Limpar</a>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i>Filtrar</button>
        </div>
    </form>
    <?php if (!$setores): ?>
        <?= empty_state('Nenhum setor encontrado', 'Cadastre os setores da empresa para organizar o acervo.', 'fa-sitemap') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table responsive">
            <thead><tr><th>Setor</th><th class="text-right">Usuários</th><th class="text-right">Caixas</th><th class="text-right">Livros</th><th class="text-right">Solicitações</th><th class="col-actions"><span class="sr-only">Ações</span></th></tr></thead>
            <tbody>
            <?php foreach ($setores as $s):
                $emUso = $s['usuarios'] + $s['caixas'] + $s['livros'] + $s['solicitacoes']; ?>
                <tr>
                    <td class="col-primary" data-label="Setor"><span class="cell-title"><?= e($s['nome']) ?></span></td>
                    <td class="text-right" data-label="Usuários"><?= fmt_num($s['usuarios']) ?></td>
                    <td class="text-right" data-label="Caixas"><?= fmt_num($s['caixas']) ?></td>
                    <td class="text-right" data-label="Livros"><?= fmt_num($s['livros']) ?></td>
                    <td class="text-right" data-label="Solicitações"><?= fmt_num($s['solicitacoes']) ?></td>
                    <td class="col-actions"><div class="row-actions">
                        <button type="button" class="act" title="Renomear" aria-label="Renomear setor" onclick="editar(<?= (int) $s['id'] ?>, '<?= e(addslashes($s['nome'])) ?>')"><i class="fa-solid fa-pen"></i></button>
                        <button type="button" class="act is-danger" title="Excluir" aria-label="Excluir setor" onclick="excluir(<?= (int) $s['id'] ?>, '<?= e(addslashes($s['nome'])) ?>', <?= (int) $emUso ?>, this)"><i class="fa-regular fa-trash-can"></i></button>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?= render_pagination($page, $total_pages, $total_records, $per_page) ?>
</section>

<div class="modal modal-sm" id="modalSetor" role="dialog" aria-modal="true" aria-labelledby="sTitulo">
    <div class="modal-dialog">
        <div class="modal-header"><h2 class="modal-title" id="sTitulo">Novo setor</h2><button class="icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <form id="formSetor" class="modal-body" novalidate>
            <input type="hidden" name="id">
            <div class="field"><label for="s-nome">Nome do setor<span class="req">*</span></label><input type="text" id="s-nome" name="nome" required maxlength="100"></div>
        </form>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-modal-close>Cancelar</button>
            <button type="submit" form="formSetor" class="btn btn-primary"><i class="fa-solid fa-check"></i>Salvar setor</button>
        </div>
    </div>
</div>
<?php
layout_end(['scripts' => <<<'JS'
<script>
var form = document.getElementById('formSetor');
document.getElementById('btnNovo').addEventListener('click', function () {
    App.resetForm(form); document.getElementById('sTitulo').textContent = 'Novo setor'; App.openModal('modalSetor');
});
function editar(id, nome) {
    App.resetForm(form); App.fillForm(form, { id: id, nome: nome });
    document.getElementById('sTitulo').textContent = 'Renomear setor'; App.openModal('modalSetor');
}
function excluir(id, nome, emUso, btn) {
    if (emUso > 0) { App.toast('O setor ' + nome + ' está em uso por usuários, caixas, livros ou solicitações e não pode ser excluído. Você pode renomeá-lo.', 'error'); return; }
    App.action({ title: 'Excluir o setor ' + nome + '?', message: 'Esta ação não pode ser desfeita.', confirmText: 'Excluir setor', url: '../includes/setor_delete.php?id=' + id, button: btn });
}
form.addEventListener('submit', function (ev) { ev.preventDefault(); App.submitForm(form, { url: '../includes/setor_save.php', modal: 'modalSetor' }); });
</script>
JS
]);
