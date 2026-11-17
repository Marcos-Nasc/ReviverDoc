<?php
require_once '../includes/layout.php';
require_level(NIVEL_ARQUIVO);

$page = current_page();
$per_page = 20;
$offset = ($page - 1) * $per_page;

$busca    = trim($_GET['busca'] ?? '');
$setor_id = $_GET['setor_id'] ?? '';
$status   = $_GET['status'] ?? '';
$higien   = $_GET['higienizada'] ?? '';

$where = ' WHERE 1';
$params = [];
if ($busca !== '') {
    $where .= ' AND (c.numero LIKE :busca OR c.conteudo LIKE :busca OR c.observacao LIKE :busca)';
    $params[':busca'] = "%$busca%";
}
if ($setor_id !== '') {
    $where .= ' AND c.setor_id = :setor_id';
    $params[':setor_id'] = $setor_id;
}
if (in_array($status, ['No Arquivo', 'Fora do Arquivo'], true)) {
    $where .= ' AND c.status = :status';
    $params[':status'] = $status;
}
if (in_array($higien, ['Sim', 'Não'], true)) {
    $where .= ' AND c.higienizada = :hig';
    $params[':hig'] = $higien;
}
$from = ' FROM caixas c LEFT JOIN setores s1 ON c.setor_id = s1.id LEFT JOIN setores s2 ON c.setor_alocado = s2.id';

$st = $pdo->prepare('SELECT COUNT(*)' . $from . $where);
$st->execute($params);
$total_records = (int) $st->fetchColumn();
$total_pages = (int) ceil($total_records / $per_page);

$stmt = $pdo->prepare('SELECT c.*, s1.nome AS setor_nome, s2.nome AS setor_alocado_nome' . $from . $where
    . ' ORDER BY CAST(c.numero AS UNSIGNED) ASC, c.id ASC LIMIT :limit OFFSET :offset');
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$caixas = $stmt->fetchAll();

$setores = all_setores();
$proximo = proximo_numero_caixa();

layout_start('Gerenciar caixas', [
    'active'   => 'caixas-conf',
    'subtitle' => 'Cadastre, edite e exclua caixas do arquivo.',
    'actions'  => '<a class="btn btn-secondary" href="caixas.php"><i class="fa-solid fa-box-archive"></i>Consulta de caixas</a>'
                . '<button type="button" class="btn btn-primary" id="btnNovo"><i class="fa-solid fa-plus"></i>Nova caixa</button>',
]);
?>
<section class="panel">
    <form method="GET" class="filters">
        <div class="field field-search">
            <label for="f-busca">Procurar</label>
            <div class="input-icon"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="f-busca" name="busca" placeholder="Número, conteúdo ou observação" value="<?= e($busca) ?>">
            </div>
        </div>
        <div class="field">
            <label for="f-setor">Setor de origem</label>
            <select id="f-setor" name="setor_id"><?= setor_options($setores, $setor_id, 'Todos') ?></select>
        </div>
        <div class="field">
            <label for="f-status">Status</label>
            <select id="f-status" name="status">
                <option value="">Todos</option>
                <option value="No Arquivo" <?= $status === 'No Arquivo' ? 'selected' : '' ?>>No arquivo</option>
                <option value="Fora do Arquivo" <?= $status === 'Fora do Arquivo' ? 'selected' : '' ?>>Fora do arquivo</option>
            </select>
        </div>
        <div class="field">
            <label for="f-hig">Higienizada</label>
            <select id="f-hig" name="higienizada">
                <option value="">Todas</option>
                <option value="Sim" <?= $higien === 'Sim' ? 'selected' : '' ?>>Sim</option>
                <option value="Não" <?= $higien === 'Não' ? 'selected' : '' ?>>Não</option>
            </select>
        </div>
        <div class="filters-actions">
            <a class="btn btn-ghost" href="caixas-conf.php">Limpar</a>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i>Filtrar</button>
        </div>
    </form>

    <?php if (!$caixas): ?>
        <?= empty_state('Nenhuma caixa encontrada', 'Ajuste os filtros ou cadastre uma nova caixa.', 'fa-box-open') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table responsive">
            <thead>
                <tr>
                    <th>Nº</th>
                    <th>Setor de origem</th>
                    <th>Conteúdo</th>
                    <th>Período</th>
                    <th>Status</th>
                    <th>Higienizada</th>
                    <th>Setor alocado</th>
                    <th class="col-actions"><span class="sr-only">Ações</span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($caixas as $c):
                $semEtiqueta = in_array(trim((string) $c['numero']), ['', '-'], true); ?>
                <tr>
                    <td class="col-primary" data-label="Nº"><span class="tag-id<?= $semEtiqueta ? ' is-muted' : '' ?>"><?= $semEtiqueta ? 'Sem nº' : e($c['numero']) ?></span></td>
                    <td data-label="Setor de origem"><?= e($c['setor_nome'] ?: '—') ?></td>
                    <td data-label="Conteúdo" class="wrap-text"><?= e($c['conteudo']) ?></td>
                    <td data-label="Período" class="nowrap"><?= e(fmt_date($c['data_inicio'])) ?> – <?= e(fmt_date($c['data_fim'])) ?></td>
                    <td data-label="Status"><?= $c['status'] !== 'Fora do Arquivo' ? '<span class="badge badge-success">No arquivo</span>' : '<span class="badge badge-danger">Fora do arquivo</span>' ?></td>
                    <td data-label="Higienizada"><?= $c['higienizada'] !== 'Não' ? '<span class="badge badge-info">Sim</span>' : '<span class="badge badge-warning">Não</span>' ?></td>
                    <td data-label="Setor alocado"><?= e($c['setor_alocado_nome'] ?: '—') ?></td>
                    <td class="col-actions">
                        <div class="row-actions">
                            <a class="act" title="Imprimir etiqueta" aria-label="Imprimir etiqueta" href="../includes/gerar_etiqueta.php?id=<?= (int) $c['id'] ?>" target="_blank" rel="noopener"><i class="fa-solid fa-tag"></i></a>
                            <button type="button" class="act" title="Editar" aria-label="Editar caixa" onclick="editarCaixa(<?= (int) $c['id'] ?>)"><i class="fa-solid fa-pen"></i></button>
                            <button type="button" class="act is-danger" title="Excluir" aria-label="Excluir caixa" onclick="excluirCaixa(<?= (int) $c['id'] ?>, '<?= e(addslashes($semEtiqueta ? 'sem número' : $c['numero'])) ?>', this)"><i class="fa-regular fa-trash-can"></i></button>
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

<div class="modal modal-lg" id="modalCaixa" role="dialog" aria-modal="true" aria-labelledby="modalTitulo">
    <div class="modal-dialog">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="modalTitulo">Nova caixa</h2>
                <p class="modal-sub">Campos com <span class="text-danger">*</span> são obrigatórios.</p>
            </div>
            <button class="icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="formCaixa" class="modal-body" novalidate>
            <input type="hidden" name="id">
            <div class="form-grid">
                <div class="field">
                    <label for="c-numero">Nº da caixa</label>
                    <input type="text" id="c-numero" name="numero" maxlength="20" value="-">
                    <span class="help">Use “-” para caixas ainda sem etiqueta. <button type="button" class="link-btn" id="usarProximo">Usar próximo nº (<?= (int) $proximo ?>)</button></span>
                </div>
                <div class="field">
                    <label for="c-setor">Setor de origem<span class="req">*</span></label>
                    <select id="c-setor" name="setor_id" required><?= setor_options($setores, '', 'Selecione') ?></select>
                </div>
                <div class="field span-2">
                    <label for="c-conteudo">Conteúdo<span class="req">*</span></label>
                    <input type="text" id="c-conteudo" name="conteudo" required list="lista-conteudos" placeholder="Ex.: Folha de pagamento">
                </div>
                <div class="field">
                    <label for="c-ini">Data início</label>
                    <input type="date" id="c-ini" name="data_inicio">
                </div>
                <div class="field">
                    <label for="c-fim">Data fim</label>
                    <input type="date" id="c-fim" name="data_fim">
                </div>
                <div class="form-section-title">Situação e localização</div>
                <div class="field">
                    <label for="c-status">Status</label>
                    <select id="c-status" name="status">
                        <option value="No Arquivo">No arquivo</option>
                        <option value="Fora do Arquivo">Fora do arquivo</option>
                    </select>
                </div>
                <div class="field">
                    <label for="c-hig">Higienizada</label>
                    <select id="c-hig" name="higienizada">
                        <option value="Sim">Sim</option>
                        <option value="Não">Não</option>
                    </select>
                </div>
                <div class="field">
                    <label for="c-coluna">Coluna</label>
                    <input type="text" id="c-coluna" name="coluna" maxlength="50" list="lista-colunas" placeholder="Ex.: A1">
                </div>
                <div class="field">
                    <label for="c-fileira">Fileira</label>
                    <input type="text" id="c-fileira" name="fileira" maxlength="50" list="lista-fileiras" placeholder="Ex.: Frente">
                </div>
                <div class="field">
                    <label for="c-alocado">Setor alocado</label>
                    <select id="c-alocado" name="setor_alocado"><?= setor_options($setores, '', 'Mesmo do setor de origem') ?></select>
                </div>
                <div class="field">
                    <label for="c-dig">Data de digitalização</label>
                    <input type="date" id="c-dig" name="data_digitalizacao">
                </div>
                <div class="field span-2">
                    <label for="c-obs">Observação</label>
                    <textarea id="c-obs" name="observacao" rows="3" placeholder="Ex.: CX1, caixa danificada…"></textarea>
                </div>
            </div>
        </form>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-modal-close>Cancelar</button>
            <button type="submit" form="formCaixa" class="btn btn-primary"><i class="fa-solid fa-check"></i>Salvar caixa</button>
        </div>
    </div>
</div>
<datalist id="lista-conteudos">
    <?php foreach ($pdo->query("SELECT DISTINCT conteudo FROM caixas WHERE conteudo <> '' ORDER BY conteudo LIMIT 300")->fetchAll(PDO::FETCH_COLUMN) as $ct): ?>
        <option value="<?= e($ct) ?>"></option>
    <?php endforeach; ?>
</datalist>
<datalist id="lista-fileiras"><option value="Frente"></option><option value="Atrás"></option><option value="Direita"></option><option value="Esquerda"></option></datalist>
<datalist id="lista-colunas">
    <?php foreach (['A', 'B', 'C', 'D', 'E'] as $l) for ($i = 1; $i <= 5; $i++) echo '<option value="' . $l . $i . '"></option>'; ?>
    <option value="Parede"></option>
</datalist>

<?php
$proximoJs = (int) $proximo;
layout_end(['scripts' => <<<JS
<script>
var form = document.getElementById('formCaixa');
document.getElementById('usarProximo').addEventListener('click', function () { form.elements.numero.value = '{$proximoJs}'; });
document.getElementById('btnNovo').addEventListener('click', function () {
    App.resetForm(form);
    form.elements.numero.value = '-';
    document.getElementById('modalTitulo').textContent = 'Nova caixa';
    App.openModal('modalCaixa');
});
function editarCaixa(id) {
    App.resetForm(form);
    document.getElementById('modalTitulo').textContent = 'Editar caixa';
    App.openModal('modalCaixa');
    App.modalLoading('modalCaixa', true);
    App.api('../includes/caixas_get.php?id=' + id)
        .then(function (d) { App.fillForm(form, d.data); })
        .catch(function (e) { App.toast(e.message, 'error'); App.closeModal('modalCaixa'); })
        .finally(function () { App.modalLoading('modalCaixa', false); });
}
function excluirCaixa(id, numero, btn) {
    App.action({
        title: 'Excluir caixa ' + numero + '?',
        message: 'A caixa será removida do cadastro e dos romaneios em que aparece. Caixas com documentos cadastrados não podem ser excluídas.',
        confirmText: 'Excluir caixa',
        url: '../includes/caixas_delete.php?id=' + id,
        button: btn
    });
}
form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var ini = form.elements.data_inicio.value, fim = form.elements.data_fim.value;
    if (!form.reportValidity()) return;
    if (ini && fim && fim < ini) { App.toast('A data fim não pode ser anterior à data início.', 'error'); form.elements.data_fim.focus(); return; }
    salvar(false);
});
function salvar(confirmado) {
    var fd = new FormData(form);
    if (confirmado) fd.append('confirmar_duplicado', '1');
    var btn = document.querySelector('[form=formCaixa][type=submit]');
    App.busy(btn, true);
    App.api('../includes/caixas_save.php', { method: 'POST', data: fd })
        .then(function (d) { App.reloadWith(d.message, 'success'); })
        .catch(function (e) {
            if (e.data && e.data.duplicado) {
                App.confirm({ title: 'Número já utilizado', message: e.message + ' Deseja salvar mesmo assim?', confirmText: 'Salvar mesmo assim', danger: false })
                    .then(function (ok) { if (ok) salvar(true); });
            } else { App.toast(e.message, 'error'); }
        })
        .finally(function () { App.busy(btn, false); });
}
(function () {
    var m = location.search.match(/[?&]editar=(\d+)/);
    if (m) editarCaixa(m[1]);
})();
</script>
JS
]);
