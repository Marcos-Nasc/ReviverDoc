<?php
require_once '../includes/layout.php';
require_level(NIVEL_ARQUIVO);

$page = current_page();
$per_page = 20;
$offset = ($page - 1) * $per_page;

$busca    = trim($_GET['busca'] ?? '');
$setor    = $_GET['setor'] ?? '';
$conteudo = $_GET['conteudo'] ?? '';
$data     = $_GET['data'] ?? '';
$situacao = $_GET['situacao'] ?? '';

$where = ' WHERE 1';
$params = [];
if (user_level() == NIVEL_SETOR) {
    $where .= ' AND caixas.setor_id = :setor_usuario';
    $params[':setor_usuario'] = user_setor();
}
if ($busca !== '') {
    $where .= ' AND (caixas.numero LIKE :busca OR caixas.conteudo LIKE :busca OR caixas.observacao LIKE :busca)';
    $params[':busca'] = "%$busca%";
}
if ($setor !== '') {
    $where .= ' AND caixas.setor_id = :setor';
    $params[':setor'] = $setor;
}
if ($conteudo !== '') {
    $where .= ' AND caixas.conteudo = :conteudo';
    $params[':conteudo'] = $conteudo;
}
if (valid_date($data)) {
    $where .= ' AND :data BETWEEN caixas.data_inicio AND caixas.data_fim';
    $params[':data'] = $data;
}
$whereBase = $where;
$paramsBase = $params;
if ($situacao === 'fora') {
    $where .= " AND caixas.status = 'Fora do Arquivo'";
} elseif ($situacao === 'arquivo') {
    $where .= " AND (caixas.status IS NULL OR caixas.status <> 'Fora do Arquivo')";
} elseif ($situacao === 'nh') {
    $where .= " AND caixas.higienizada = 'Não'";
} elseif ($situacao === 'sem_etiqueta') {
    $where .= " AND (caixas.numero = '-' OR caixas.numero IS NULL OR caixas.numero = '')";
}

$from = ' FROM caixas LEFT JOIN setores s1 ON caixas.setor_id = s1.id LEFT JOIN setores s2 ON caixas.setor_alocado = s2.id';
$order = " ORDER BY s1.nome ASC, FIELD(caixas.coluna, 'A1','A2','A3','A4','A5','B1','B2','B3','B4','B5','C1','C2','C3','C4','C5','D1','D2','D3','D4','D5','E1','E2','E3','E4','E5','Parede'), FIELD(caixas.fileira, 'Frente','Atrás','Direita','Esquerda'), caixas.id";
$select = 'SELECT caixas.id, caixas.numero, caixas.conteudo, caixas.data_inicio, caixas.data_fim, caixas.coluna, caixas.fileira,
           caixas.observacao, caixas.status, caixas.setor_alocado, caixas.higienizada, caixas.setor_id,
           s1.nome AS setor, s2.nome AS nome_setor_alocado';

if (($_GET['export'] ?? '') === 'csv') {
    $st = $pdo->prepare($select . $from . $where . $order);
    $st->execute($params);
    $rows = [];
    foreach ($st as $c) {
        $rows[] = [$c['numero'], $c['setor'], $c['conteudo'], fmt_date($c['data_inicio'], 'd/m/Y', ''), fmt_date($c['data_fim'], 'd/m/Y', ''),
            trim($c['coluna'] . ' ' . $c['fileira']), $c['status'], $c['higienizada'], $c['nome_setor_alocado'], $c['observacao']];
    }
    csv_download('caixas_' . date('Y-m-d') . '.csv', ['Número', 'Setor', 'Conteúdo', 'Início', 'Fim', 'Localização', 'Status', 'Higienizada', 'Setor alocado', 'Observação'], $rows);
}

$stmt_total = $pdo->prepare('SELECT COUNT(*)' . $from . $where);
$stmt_total->execute($params);
$total_records = (int) $stmt_total->fetchColumn();
$total_pages = (int) ceil($total_records / $per_page);

$stmt = $pdo->prepare($select . $from . $where . $order . ' LIMIT :limit OFFSET :offset');
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$caixas = $stmt->fetchAll();

$setores = all_setores();
$conteudos = $pdo->query("SELECT DISTINCT conteudo FROM caixas WHERE conteudo IS NOT NULL AND conteudo <> '' ORDER BY conteudo")->fetchAll(PDO::FETCH_COLUMN);
// Contadores dos filtros rápidos: mesmos filtros da busca (exceto a situação)
$stc = $pdo->prepare("SELECT COUNT(*) total, COALESCE(SUM(caixas.status = 'Fora do Arquivo'), 0) fora, COALESCE(SUM(caixas.higienizada = 'Não'), 0) nh,
                         COALESCE(SUM(caixas.numero = '-' OR caixas.numero IS NULL OR caixas.numero = ''), 0) sem_etiqueta" . $from . $whereBase);
$stc->execute($paramsBase);
$contagem = $stc->fetch();

$qf = function ($key, $label, $n = null) use ($situacao) {
    $q = clean_query();
    unset($q['page'], $q['situacao']);
    if ($key !== '') $q['situacao'] = $key;
    return '<a class="qf' . ($situacao === $key ? ' is-active' : '') . '" href="?' . e(http_build_query($q)) . '">' . e($label)
        . ($n !== null ? ' <span class="n">' . fmt_num($n) . '</span>' : '') . '</a>';
};

layout_start('Caixas', [
    'active'   => 'caixas',
    'subtitle' => 'Consulte as caixas do arquivo, sua localização e os documentos digitalizados.',
    'actions'  => '<a class="btn btn-secondary" href="' . e(export_url()) . '"><i class="fa-solid fa-file-csv"></i>Exportar</a>'
                . '<a class="btn btn-primary" href="caixas-conf.php"><i class="fa-solid fa-pen-to-square"></i>Gerenciar caixas</a>',
]);
?>
<div class="quick-filters mb-2">
    <?= $qf('', 'Todas', $contagem['total']) ?>
    <?= $qf('arquivo', 'No arquivo', $contagem['total'] - $contagem['fora']) ?>
    <?= $qf('fora', 'Fora do arquivo', $contagem['fora']) ?>
    <?= $qf('nh', 'Não higienizadas', $contagem['nh']) ?>
    <?= $qf('sem_etiqueta', 'Sem etiqueta', $contagem['sem_etiqueta']) ?>
</div>

<section class="panel">
    <form method="GET" class="filters">
        <?php if ($situacao !== ''): ?><input type="hidden" name="situacao" value="<?= e($situacao) ?>"><?php endif; ?>
        <div class="field field-search">
            <label for="f-busca">Procurar</label>
            <div class="input-icon"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="f-busca" name="busca" placeholder="Número, conteúdo ou observação" value="<?= e($busca) ?>">
            </div>
        </div>
        <div class="field">
            <label for="f-setor">Setor</label>
            <select id="f-setor" name="setor"><?= setor_options($setores, $setor, 'Todos os setores') ?></select>
        </div>
        <div class="field">
            <label for="f-conteudo">Conteúdo</label>
            <select id="f-conteudo" name="conteudo">
                <option value="">Todos os conteúdos</option>
                <?php foreach ($conteudos as $c): ?>
                    <option value="<?= e($c) ?>" <?= $conteudo === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="f-data">Contém a data</label>
            <input type="date" id="f-data" name="data" value="<?= e($data) ?>">
        </div>
        <div class="filters-actions">
            <a class="btn btn-ghost" href="caixas.php">Limpar</a>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i>Filtrar</button>
        </div>
    </form>

    <?php if (!$caixas): ?>
        <?= empty_state('Nenhuma caixa encontrada', 'Revise os filtros ou limpe a busca para ver todas as caixas.', 'fa-box-open', '<a class="btn btn-secondary" href="caixas.php">Limpar filtros</a>') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table responsive">
            <thead>
                <tr>
                    <th>Número</th>
                    <th>Setor</th>
                    <th>Conteúdo</th>
                    <th>Período</th>
                    <th>Localização</th>
                    <th>Situação</th>
                    <th class="col-actions"><span class="sr-only">Ações</span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($caixas as $c):
                $badges = [];
                if (!empty($c['setor_alocado']) && $c['setor_alocado'] != $c['setor_id']) {
                    $badges[] = '<span class="badge badge-info" title="Setor alocado">' . e(mb_strtoupper($c['nome_setor_alocado'])) . '</span>';
                }
                if ($c['status'] === 'Fora do Arquivo') {
                    $badges[] = '<span class="badge badge-danger">Fora do arquivo</span>';
                }
                if ($c['higienizada'] === 'Não') {
                    $badges[] = '<span class="badge badge-warning" title="Não higienizada">N/H</span>';
                }
                $obs = trim((string) $c['observacao']);
                if ($obs !== '' && $obs !== '-') {
                    $badges[] = '<span class="badge ' . (stripos($obs, 'CX') === 0 ? 'badge-brass' : 'no-dot') . '">' . e($obs) . '</span>';
                }
                $semEtiqueta = in_array(trim((string) $c['numero']), ['', '-'], true);
                ?>
                <tr>
                    <td class="col-primary" data-label="Número"><span class="tag-id<?= $semEtiqueta ? ' is-muted' : '' ?>"><?= $semEtiqueta ? 'Sem nº' : e($c['numero']) ?></span></td>
                    <td data-label="Setor"><?= e($c['setor'] ? mb_strtoupper($c['setor']) : '—') ?></td>
                    <td data-label="Conteúdo" class="wrap-text"><?= e($c['conteudo']) ?></td>
                    <td data-label="Período" class="nowrap"><?= e(fmt_periodo($c['data_inicio'], $c['data_fim'])) ?></td>
                    <td data-label="Localização" class="nowrap"><?= ($c['coluna'] || $c['fileira']) ? e(trim($c['coluna'] . ' · ' . $c['fileira'], ' ·')) : '<span class="muted">—</span>' ?></td>
                    <td data-label="Situação"><?= $badges ? '<div class="badges">' . implode('', $badges) . '</div>' : '<span class="badge badge-success">No arquivo</span>' ?></td>
                    <td class="col-actions">
                        <div class="row-actions">
                            <button type="button" class="act" title="Detalhes da caixa" aria-label="Detalhes da caixa" onclick="abrirDetalhes(<?= (int) $c['id'] ?>)"><i class="fa-regular fa-eye"></i></button>
                            <button type="button" class="act" title="Documentos digitalizados" aria-label="Documentos digitalizados" onclick="abrirDocumentos(<?= (int) $c['id'] ?>)"><i class="fa-regular fa-folder-open"></i></button>
                            <a class="act" title="Imprimir etiqueta" aria-label="Imprimir etiqueta" href="../includes/gerar_etiqueta.php?id=<?= (int) $c['id'] ?>" target="_blank" rel="noopener"><i class="fa-solid fa-tag"></i></a>
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

<div class="modal modal-lg" id="modal-detalhes" role="dialog" aria-modal="true" aria-labelledby="det-title">
    <div class="modal-dialog">
        <div class="modal-header">
            <div><h2 class="modal-title" id="det-title">Detalhes da caixa</h2></div>
            <button class="icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="det-body"></div>
    </div>
</div>

<div class="modal modal-xl" id="modal-documentos" role="dialog" aria-modal="true" aria-labelledby="docs-title">
    <div class="modal-dialog">
        <div class="modal-header">
            <div><h2 class="modal-title" id="docs-title">Documentos da caixa</h2></div>
            <button class="icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="docs-body"></div>
    </div>
</div>

<?php
layout_end(['scripts' => <<<'JS'
<script>
function carregarFragmento(modalId, bodyId, url) {
    var body = document.getElementById(bodyId);
    body.innerHTML = '<div class="loading-block"><div class="spinner"></div>Carregando…</div>';
    App.openModal(modalId);
    fetch(url, { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error(); return r.text(); })
        .then(function (html) { body.innerHTML = html; })
        .catch(function () { body.innerHTML = '<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span>Não foi possível carregar. Tente novamente.</span></div>'; });
}
function abrirDetalhes(id) { carregarFragmento('modal-detalhes', 'det-body', '../includes/caixa_detalhes.php?id=' + id); }
function abrirDocumentos(id) { carregarFragmento('modal-documentos', 'docs-body', '../includes/caixa_documentos.php?id=' + id); }
</script>
JS
]);
