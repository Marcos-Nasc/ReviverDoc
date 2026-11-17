<?php
require_once '../includes/layout.php';
require_level(NIVEL_ARQUIVO);

$page = current_page();
$per_page = 20;
$offset = ($page - 1) * $per_page;

$busca    = trim($_GET['busca'] ?? '');
$caixa_id = $_GET['caixa_id'] ?? '';
$anexo    = $_GET['anexo'] ?? '';

$where = ' WHERE 1';
$params = [];
if ($busca !== '') {
    $where .= ' AND (d.nome LIKE :busca OR d.arquivo LIKE :busca)';
    $params[':busca'] = "%$busca%";
}
if ($caixa_id !== '') {
    $where .= ' AND d.caixa_id = :caixa_id';
    $params[':caixa_id'] = $caixa_id;
}
if ($anexo === 'sem') {
    $where .= " AND (d.arquivo IS NULL OR d.arquivo = '')";
}
$from = ' FROM documentos d LEFT JOIN caixas c ON d.caixa_id = c.id LEFT JOIN setores s ON c.setor_id = s.id';

$st = $pdo->prepare('SELECT COUNT(d.id)' . $from . $where);
$st->execute($params);
$total_records = (int) $st->fetchColumn();
$total_pages = (int) ceil($total_records / $per_page);

$stmt = $pdo->prepare('SELECT d.*, c.numero AS caixa_numero, s.nome AS setor_nome' . $from . $where . ' ORDER BY d.id DESC LIMIT :limit OFFSET :offset');
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$documentos = $stmt->fetchAll();

$caixas = $pdo->query('SELECT c.id, c.numero, s.nome AS setor_nome FROM caixas c LEFT JOIN setores s ON c.setor_id = s.id ORDER BY CAST(c.numero AS UNSIGNED) ASC, setor_nome, c.id')->fetchAll();
$caixaLabel = function ($c) {
    $n = in_array(trim((string) $c['numero']), ['', '-'], true) ? 'sem nº (#' . $c['id'] . ')' : $c['numero'];
    return 'Caixa ' . $n . ' · ' . ($c['setor_nome'] ?: 'sem setor');
};

layout_start('Gerenciar documentos', [
    'active'   => 'documentos-conf',
    'subtitle' => 'Cadastre documentos digitalizados (PDF) e vincule cada um à sua caixa.',
    'actions'  => '<a class="btn btn-secondary" href="documentos.php"><i class="fa-solid fa-file-lines"></i>Consulta de documentos</a>'
                . '<button type="button" class="btn btn-primary" id="btnNovo"><i class="fa-solid fa-plus"></i>Novo documento</button>',
]);
?>
<section class="panel">
    <form method="GET" class="filters">
        <div class="field field-search">
            <label for="f-busca">Nome ou arquivo</label>
            <div class="input-icon"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="f-busca" name="busca" placeholder="Digite o nome" value="<?= e($busca) ?>">
            </div>
        </div>
        <div class="field">
            <label for="f-caixa">Caixa</label>
            <select id="f-caixa" name="caixa_id">
                <option value="">Todas</option>
                <?php foreach ($caixas as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (string) $caixa_id === (string) $c['id'] ? 'selected' : '' ?>><?= e($caixaLabel($c)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="f-anexo">Arquivo</label>
            <select id="f-anexo" name="anexo">
                <option value="">Todos</option>
                <option value="sem" <?= $anexo === 'sem' ? 'selected' : '' ?>>Sem PDF anexado</option>
            </select>
        </div>
        <div class="filters-actions">
            <a class="btn btn-ghost" href="documentos-conf.php">Limpar</a>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i>Filtrar</button>
        </div>
    </form>

    <?php if (!$documentos): ?>
        <?= empty_state('Nenhum documento encontrado', 'Ajuste os filtros ou cadastre um novo documento.', 'fa-file-circle-plus') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table responsive">
            <thead>
                <tr>
                    <th>Documento</th>
                    <th>Caixa</th>
                    <th>Arquivo</th>
                    <th>Digitalização</th>
                    <th class="col-actions"><span class="sr-only">Ações</span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($documentos as $d):
                $semNum = in_array(trim((string) $d['caixa_numero']), ['', '-'], true); ?>
                <tr>
                    <td class="col-primary" data-label="Documento"><span><span class="cell-title"><?= e($d['nome']) ?></span><span class="cell-sub">#<?= (int) $d['id'] ?></span></span></td>
                    <td data-label="Caixa"><?= $d['caixa_id'] ? '<span><span class="tag-id' . ($semNum ? ' is-muted' : '') . '">' . ($semNum ? 'Sem nº' : e($d['caixa_numero'])) . '</span><span class="cell-sub">' . e($d['setor_nome'] ?: '') . '</span></span>' : '<span class="muted">—</span>' ?></td>
                    <td data-label="Arquivo" class="wrap-text">
                        <?php if (!empty($d['arquivo'])): ?>
                            <a href="../uploads/documentos/<?= e(rawurlencode($d['arquivo'])) ?>" target="_blank" rel="noopener"><?= e($d['arquivo']) ?></a>
                        <?php else: ?>
                            <span class="badge badge-warning">Sem PDF</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Digitalização" class="nowrap"><?= e(fmt_date($d['data_digitalizacao'])) ?></td>
                    <td class="col-actions">
                        <div class="row-actions">
                            <button type="button" class="act" title="Editar" aria-label="Editar documento" onclick="editarDocumento(<?= (int) $d['id'] ?>)"><i class="fa-solid fa-pen"></i></button>
                            <button type="button" class="act is-danger" title="Excluir" aria-label="Excluir documento" onclick="excluirDocumento(<?= (int) $d['id'] ?>, <?= e(json_encode($d['nome'], JSON_UNESCAPED_UNICODE)) ?>, this)"><i class="fa-regular fa-trash-can"></i></button>
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

<div class="modal" id="modalDocumento" role="dialog" aria-modal="true" aria-labelledby="modalTitulo">
    <div class="modal-dialog">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="modalTitulo">Novo documento</h2>
                <p class="modal-sub">Campos com <span class="text-danger">*</span> são obrigatórios.</p>
            </div>
            <button class="icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="formDocumento" class="modal-body" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="id">
            <div class="form-grid">
                <div class="field span-2">
                    <label for="d-nome">Nome do documento<span class="req">*</span></label>
                    <input type="text" id="d-nome" name="nome" maxlength="150" required placeholder="Ex.: Contrato de prestação de serviços 2024">
                </div>
                <div class="field span-2">
                    <label for="d-caixa">Caixa<span class="req">*</span></label>
                    <select id="d-caixa" name="caixa_id" required>
                        <option value="">Selecione a caixa</option>
                        <?php foreach ($caixas as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= e($caixaLabel($c)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="d-data">Data de digitalização</label>
                    <input type="date" id="d-data" name="data_digitalizacao">
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
            <button type="submit" form="formDocumento" class="btn btn-primary"><i class="fa-solid fa-check"></i>Salvar documento</button>
        </div>
    </div>
</div>

<?php
layout_end(['scripts' => <<<'JS'
<script>
var form = document.getElementById('formDocumento');
var atual = document.getElementById('arquivoAtual');
document.getElementById('btnNovo').addEventListener('click', function () {
    App.resetForm(form);
    atual.innerHTML = '';
    document.getElementById('modalTitulo').textContent = 'Novo documento';
    App.openModal('modalDocumento');
});
function editarDocumento(id) {
    App.resetForm(form);
    atual.innerHTML = '';
    document.getElementById('modalTitulo').textContent = 'Editar documento';
    App.openModal('modalDocumento');
    App.modalLoading('modalDocumento', true);
    App.api('../includes/documento_get.php?id=' + id)
        .then(function (d) {
            App.fillForm(form, d);
            atual.innerHTML = d.arquivo
                ? 'Arquivo atual: <a href="../uploads/documentos/' + encodeURIComponent(d.arquivo) + '" target="_blank" rel="noopener">' + App.esc(d.arquivo) + '</a>. Envie outro PDF apenas se quiser substituí-lo.'
                : 'Nenhum arquivo anexado.';
        })
        .catch(function (e) { App.toast(e.message, 'error'); App.closeModal('modalDocumento'); })
        .finally(function () { App.modalLoading('modalDocumento', false); });
}
function excluirDocumento(id, nome, btn) {
    App.action({
        title: 'Excluir documento?',
        message: '“' + nome + '” e o PDF anexado serão removidos definitivamente.',
        confirmText: 'Excluir documento',
        url: '../includes/documento_delete.php?id=' + id,
        button: btn
    });
}
form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var file = form.elements.arquivo.files[0];
    if (file && !/\.pdf$/i.test(file.name)) { App.toast('Envie um arquivo em PDF.', 'error'); return; }
    App.submitForm(form, { url: '../includes/documento_save.php', modal: 'modalDocumento' });
});
(function () {
    var m = location.search.match(/[?&]editar=(\d+)/);
    if (m) editarDocumento(m[1]);
})();
</script>
JS
]);
