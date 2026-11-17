<?php
require_once '../includes/layout.php';
require_once '../includes/solicitacoes_lib.php';
require_level(NIVEL_ARQUIVO);
// Notificações antigas apontavam para ?id=N: abre a tela de consulta/resposta da solicitação
if (isset($_GET['id']) && ctype_digit((string) $_GET['id']) && !isset($_GET['editar'])) {
    redirect_to('solicitacoes.php?id=' . (int) $_GET['id']);
}

$page = current_page();
$per_page = 20;
$offset = ($page - 1) * $per_page;

$busca       = trim($_GET['busca'] ?? '');
$setor_id    = $_GET['setor_id'] ?? '';
$status      = $_GET['status'] ?? '';
$data_inicio = $_GET['data_inicio'] ?? '';
$data_fim    = $_GET['data_fim'] ?? '';

$where = ' WHERE 1';
$params = [];
if ($busca !== '') {
    if (ctype_digit(ltrim($busca, '#'))) {
        $where .= ' AND (s.id = :bid OR s.tipo LIKE :busca OR s.informacao LIKE :busca OR s.resultado LIKE :busca)';
        $params[':bid'] = (int) ltrim($busca, '#');
    } else {
        $where .= ' AND (s.tipo LIKE :busca OR s.informacao LIKE :busca OR s.resultado LIKE :busca)';
    }
    $params[':busca'] = "%$busca%";
}
if ($setor_id !== '') {
    $where .= ' AND s.setor_id = :setor_id';
    $params[':setor_id'] = $setor_id;
}
if (in_array($status, ['Pendente', 'Respondida'], true)) {
    $where .= ' AND s.status = :status';
    $params[':status'] = $status;
} elseif ($status === 'Atrasada') {
    $where .= " AND (s.status IS NULL OR s.status <> 'Respondida') AND s.prazo < NOW()";
}
if (valid_date($data_inicio)) {
    $where .= ' AND s.data_solicitacao >= :data_inicio';
    $params[':data_inicio'] = $data_inicio . ' 00:00:00';
}
if (valid_date($data_fim)) {
    $where .= ' AND s.data_solicitacao <= :data_fim';
    $params[':data_fim'] = $data_fim . ' 23:59:59';
}
$from = ' FROM solicitacoes s LEFT JOIN setores se ON s.setor_id = se.id LEFT JOIN usuarios u ON s.usuario_id = u.id';

$st = $pdo->prepare('SELECT COUNT(*)' . $from . $where);
$st->execute($params);
$total_records = (int) $st->fetchColumn();
$total_pages = (int) ceil($total_records / $per_page);

$stmt = $pdo->prepare('SELECT s.id, s.tipo, s.prazo, s.status, s.resultado, s.data_solicitacao, se.nome AS setor_nome, u.nome AS solicitante'
    . $from . $where . ' ORDER BY s.data_solicitacao DESC, s.id DESC LIMIT :limit OFFSET :offset');
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$solicitacoes = $stmt->fetchAll();
$setores = all_setores();

layout_start('Gerenciar solicitações', [
    'active'   => 'solicitacoes-conf',
    'subtitle' => 'Corrija dados, cadastre ou exclua solicitações de pesquisa. Para responder, use a tela de solicitações.',
    'actions'  => '<a class="btn btn-secondary" href="solicitacoes.php"><i class="fa-solid fa-magnifying-glass"></i>Atender solicitações</a>'
                . '<button type="button" class="btn btn-primary" id="btnNovo"><i class="fa-solid fa-plus"></i>Nova solicitação</button>',
]);
?>
<section class="panel">
    <form method="GET" class="filters">
        <div class="field field-search">
            <label for="f-busca">Procurar</label>
            <div class="input-icon"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="f-busca" name="busca" placeholder="Nº, tipo, informação, resultado" value="<?= e($busca) ?>">
            </div>
        </div>
        <div class="field">
            <label for="f-setor">Setor</label>
            <select id="f-setor" name="setor_id"><?= setor_options($setores, $setor_id, 'Todos') ?></select>
        </div>
        <div class="field">
            <label for="f-status">Status</label>
            <select id="f-status" name="status">
                <option value="">Todos</option>
                <?php foreach (['Pendente' => 'Pendente', 'Atrasada' => 'Atrasada', 'Respondida' => 'Respondida'] as $v => $l): ?>
                    <option value="<?= $v ?>" <?= $status === $v ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="f-ini">Solicitada de</label>
            <input type="date" id="f-ini" name="data_inicio" value="<?= e($data_inicio) ?>">
        </div>
        <div class="field">
            <label for="f-fim">até</label>
            <input type="date" id="f-fim" name="data_fim" value="<?= e($data_fim) ?>">
        </div>
        <div class="filters-actions">
            <a class="btn btn-ghost" href="solicitacoes-conf.php">Limpar</a>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i>Filtrar</button>
        </div>
    </form>

    <?php if (!$solicitacoes): ?>
        <?= empty_state('Nenhuma solicitação encontrada', 'Ajuste os filtros ou cadastre uma nova solicitação.', 'fa-list-check') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table responsive">
            <thead>
                <tr>
                    <th>Nº</th>
                    <th>Tipo</th>
                    <th>Setor</th>
                    <th>Solicitada em</th>
                    <th>Prazo</th>
                    <th>Status</th>
                    <th>Resultado</th>
                    <th class="col-actions"><span class="sr-only">Ações</span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($solicitacoes as $s): $sit = prazo_situacao($s['prazo'], $s['status']); ?>
                <tr class="<?= $sit === 'atrasada' ? 'is-overdue' : ($sit === 'proxima' ? 'is-warning' : '') ?>">
                    <td class="col-primary" data-label="Nº"><span class="tag-id">#<?= e(fmt_id($s['id'], 3)) ?></span></td>
                    <td data-label="Tipo" class="wrap-text"><?= e($s['tipo'] ?: '—') ?></td>
                    <td data-label="Setor"><?= e($s['setor_nome'] ?: '—') ?><?php if ($s['solicitante']): ?><span class="cell-sub"><?= e($s['solicitante']) ?></span><?php endif; ?></td>
                    <td data-label="Solicitada em" class="nowrap"><?= e(fmt_datetime($s['data_solicitacao'])) ?></td>
                    <td data-label="Prazo" class="nowrap"><?= e(fmt_datetime($s['prazo'])) ?><?= $sit === 'atrasada' ? ' <span class="badge badge-danger">Atrasada</span>' : '' ?></td>
                    <td data-label="Status"><?= $s['status'] === 'Respondida' ? '<span class="badge badge-success">Respondida</span>' : '<span class="badge badge-warning">Pendente</span>' ?></td>
                    <td data-label="Resultado"><?= resultado_badge($s['resultado'], $s['status']) ?></td>
                    <td class="col-actions">
                        <div class="row-actions">
                            <a class="act" href="solicitacoes.php?id=<?= (int) $s['id'] ?>" title="Ver e responder" aria-label="Ver e responder"><i class="fa-solid fa-reply"></i></a>
                            <button type="button" class="act" title="Editar" aria-label="Editar solicitação" onclick="editar(<?= (int) $s['id'] ?>)"><i class="fa-solid fa-pen"></i></button>
                            <button type="button" class="act is-danger" title="Excluir" aria-label="Excluir solicitação" onclick="excluir(<?= (int) $s['id'] ?>, this)"><i class="fa-regular fa-trash-can"></i></button>
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

<div class="modal modal-lg" id="modalSolic" role="dialog" aria-modal="true" aria-labelledby="modalTitulo">
    <div class="modal-dialog">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="modalTitulo">Nova solicitação</h2>
                <p class="modal-sub">Campos com <span class="text-danger">*</span> são obrigatórios.</p>
            </div>
            <button class="icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="formSolic" class="modal-body" novalidate>
            <input type="hidden" name="id">
            <div class="form-grid">
                <div class="field">
                    <label for="s-tipo">Tipo<span class="req">*</span></label>
                    <input type="text" id="s-tipo" name="tipo" required maxlength="100">
                </div>
                <div class="field">
                    <label for="s-prazo">Prazo para resposta</label>
                    <input type="datetime-local" id="s-prazo" name="prazo">
                </div>
                <div class="field">
                    <label for="s-setor">Setor de origem</label>
                    <select id="s-setor" name="setor_id"><?= setor_options($setores, '', 'Selecione') ?></select>
                </div>
                <div class="field">
                    <label for="s-sp">Setor da pesquisa</label>
                    <select id="s-sp" name="setor_pesquisa"><?= setor_options($setores, '', 'Selecione') ?></select>
                    <span class="help" id="s-sp-legado" hidden></span>
                </div>
                <div class="field span-2">
                    <label for="s-info">Informações</label>
                    <textarea id="s-info" name="informacao" rows="4"></textarea>
                </div>
                <div class="field span-2">
                    <label for="s-obs">Observação</label>
                    <textarea id="s-obs" name="observacao" rows="2"></textarea>
                </div>
            </div>
        </form>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-modal-close>Cancelar</button>
            <button type="submit" form="formSolic" class="btn btn-primary"><i class="fa-solid fa-check"></i>Salvar solicitação</button>
        </div>
    </div>
</div>

<?php
layout_end(['scripts' => <<<'JS'
<script>
var form = document.getElementById('formSolic');
var legado = document.getElementById('s-sp-legado');
document.getElementById('btnNovo').addEventListener('click', function () {
    App.resetForm(form);
    legado.hidden = true;
    document.getElementById('modalTitulo').textContent = 'Nova solicitação';
    App.openModal('modalSolic');
});
function editar(id) {
    App.resetForm(form);
    legado.hidden = true;
    document.getElementById('modalTitulo').textContent = 'Editar solicitação #' + String(id).padStart(3, '0');
    App.openModal('modalSolic');
    App.modalLoading('modalSolic', true);
    App.api('../includes/solicitacoes_get.php?id=' + id)
        .then(function (r) {
            var d = r.data;
            App.fillForm(form, { id: d.id, tipo: d.tipo, prazo: d.prazo, setor_id: d.setor_id, setor_pesquisa: d.setor_pesquisa, informacao: d.informacao, observacao: d.observacao });
            // Registros antigos guardavam o nome do setor em vez do código
            if (d.setor_pesquisa && !d.setor_pesquisa_nome && d.setor_pesquisa !== '0') {
                legado.hidden = false;
                legado.textContent = 'Valor registrado anteriormente: ' + d.setor_pesquisa + '. Ele será mantido se nenhum setor for selecionado.';
            }
        })
        .catch(function (e) { App.toast(e.message, 'error'); App.closeModal('modalSolic'); })
        .finally(function () { App.modalLoading('modalSolic', false); });
}
function excluir(id, btn) {
    App.action({
        title: 'Excluir a solicitação #' + String(id).padStart(3, '0') + '?',
        message: 'A solicitação e a conversa vinculada a ela serão removidas definitivamente.',
        confirmText: 'Excluir solicitação',
        url: '../includes/solicitacoes_delete.php?id=' + id,
        button: btn
    });
}
form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    App.submitForm(form, { url: '../includes/solicitacoes_save.php', modal: 'modalSolic' });
});
(function () {
    var m = location.search.match(/[?&]editar=(\d+)/);
    if (m) editar(m[1]);
})();
</script>
JS
]);
