<?php
require_once '../includes/layout.php';
require_level(NIVEL_ARQUIVO);

$page = current_page();
$per_page = 20;
$offset = ($page - 1) * $per_page;

$busca  = trim($_GET['busca'] ?? '');
$caixa  = $_GET['caixa'] ?? '';
$inicio = $_GET['inicio'] ?? '';
$fim    = $_GET['fim'] ?? '';

$where = ' WHERE 1';
$params = [];
if (user_level() == NIVEL_SETOR) {
    $where .= ' AND c.setor_id = :setor_usuario';
    $params[':setor_usuario'] = user_setor();
}
if ($busca !== '') {
    $where .= ' AND d.nome LIKE :busca';
    $params[':busca'] = "%$busca%";
}
if ($caixa !== '') {
    $where .= ' AND d.caixa_id = :caixa';
    $params[':caixa'] = $caixa;
}
if (valid_date($inicio)) {
    $where .= ' AND d.data_digitalizacao >= :inicio';
    $params[':inicio'] = $inicio;
}
if (valid_date($fim)) {
    $where .= ' AND d.data_digitalizacao <= :fim';
    $params[':fim'] = $fim;
}
$from = ' FROM documentos d LEFT JOIN caixas c ON d.caixa_id = c.id LEFT JOIN setores s ON c.setor_id = s.id';
$select = 'SELECT d.id, d.nome, d.data_digitalizacao, d.arquivo, d.caixa_id, c.numero AS caixa_numero, s.nome AS setor_nome';
$order = ' ORDER BY d.data_digitalizacao DESC, d.id DESC';

if (($_GET['export'] ?? '') === 'csv') {
    $st = $pdo->prepare($select . $from . $where . $order);
    $st->execute($params);
    $rows = [];
    foreach ($st as $d) {
        $rows[] = [$d['nome'], $d['caixa_numero'], $d['setor_nome'], fmt_date($d['data_digitalizacao'], 'd/m/Y', ''), $d['arquivo']];
    }
    csv_download('documentos_' . date('Y-m-d') . '.csv', ['Documento', 'Caixa', 'Setor', 'Digitalização', 'Arquivo'], $rows);
}

$st = $pdo->prepare('SELECT COUNT(d.id)' . $from . $where);
$st->execute($params);
$total_records = (int) $st->fetchColumn();
$total_pages = (int) ceil($total_records / $per_page);

$stmt = $pdo->prepare($select . $from . $where . $order . ' LIMIT :limit OFFSET :offset');
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$documentos = $stmt->fetchAll();

$caixas = $pdo->query('SELECT id, numero FROM caixas ORDER BY CAST(numero AS UNSIGNED) ASC, id ASC')->fetchAll();

layout_start('Documentos', [
    'active'   => 'documentos',
    'subtitle' => 'Documentos digitalizados do acervo, com a caixa onde estão guardados.',
    'actions'  => '<a class="btn btn-secondary" href="' . e(export_url()) . '"><i class="fa-solid fa-file-csv"></i>Exportar</a>'
                . '<a class="btn btn-primary" href="documentos-conf.php"><i class="fa-solid fa-pen-to-square"></i>Gerenciar documentos</a>',
]);
?>
<section class="panel">
    <form method="GET" class="filters">
        <div class="field field-search">
            <label for="f-busca">Procurar por nome</label>
            <div class="input-icon"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="f-busca" name="busca" placeholder="Nome do documento" value="<?= e($busca) ?>">
            </div>
        </div>
        <div class="field">
            <label for="f-caixa">Caixa</label>
            <select id="f-caixa" name="caixa">
                <option value="">Todas</option>
                <?php foreach ($caixas as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (string) $caixa === (string) $c['id'] ? 'selected' : '' ?>>Caixa <?= e(in_array(trim((string) $c['numero']), ['', '-'], true) ? 'sem nº (#' . $c['id'] . ')' : $c['numero']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="f-ini">Digitalizado de</label>
            <input type="date" id="f-ini" name="inicio" value="<?= e($inicio) ?>">
        </div>
        <div class="field">
            <label for="f-fim">Digitalizado até</label>
            <input type="date" id="f-fim" name="fim" value="<?= e($fim) ?>">
        </div>
        <div class="filters-actions">
            <a class="btn btn-ghost" href="documentos.php">Limpar</a>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i>Filtrar</button>
        </div>
    </form>

    <?php if (!$documentos): ?>
        <?= empty_state('Nenhum documento encontrado', 'Revise os filtros ou cadastre documentos digitalizados na gestão de documentos.', 'fa-file-circle-question', '<a class="btn btn-secondary" href="documentos.php">Limpar filtros</a>') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table responsive">
            <thead>
                <tr>
                    <th>Documento</th>
                    <th>Caixa</th>
                    <th>Setor</th>
                    <th>Digitalização</th>
                    <th class="col-actions"><span class="sr-only">Ações</span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($documentos as $d):
                $semNum = in_array(trim((string) $d['caixa_numero']), ['', '-'], true); ?>
                <tr>
                    <td class="col-primary" data-label="Documento"><span class="cell-title"><?= e($d['nome']) ?></span></td>
                    <td data-label="Caixa"><?= $d['caixa_id'] ? '<span class="tag-id' . ($semNum ? ' is-muted' : '') . '">' . ($semNum ? 'Sem nº' : e($d['caixa_numero'])) . '</span>' : '<span class="muted">—</span>' ?></td>
                    <td data-label="Setor"><?= e($d['setor_nome'] ?: '—') ?></td>
                    <td data-label="Digitalização" class="nowrap"><?= e(fmt_date($d['data_digitalizacao'])) ?></td>
                    <td class="col-actions">
                        <div class="row-actions">
                            <?php if (!empty($d['arquivo'])): ?>
                                <a class="act" title="Abrir PDF" aria-label="Abrir PDF" href="../uploads/documentos/<?= e(rawurlencode($d['arquivo'])) ?>" target="_blank" rel="noopener"><i class="fa-regular fa-file-pdf"></i></a>
                            <?php else: ?>
                                <span class="act" title="Sem arquivo anexado" aria-label="Sem arquivo anexado" style="opacity:.4;cursor:default"><i class="fa-regular fa-file"></i></span>
                            <?php endif; ?>
                            <a class="act" title="Editar" aria-label="Editar documento" href="documentos-conf.php?editar=<?= (int) $d['id'] ?>"><i class="fa-solid fa-pen"></i></a>
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
<?php
layout_end();
