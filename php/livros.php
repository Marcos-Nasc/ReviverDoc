<?php
require_once '../includes/layout.php';
require_level(NIVEL_ARQUIVO);

$page = current_page();
$per_page = 20;
$offset = ($page - 1) * $per_page;

$busca  = trim($_GET['busca'] ?? '');
$setor  = $_GET['setor'] ?? '';
$inicio = $_GET['inicio'] ?? '';
$fim    = $_GET['fim'] ?? '';
$dig    = $_GET['digitalizado'] ?? '';

$where = ' WHERE 1';
$params = [];
if (user_level() == NIVEL_SETOR) {
    $where .= ' AND livros.setor = :setor_usuario';
    $params[':setor_usuario'] = user_setor();
}
if ($busca !== '') {
    $where .= ' AND (livros.numero_livro LIKE :busca OR livros.posicao LIKE :busca)';
    $params[':busca'] = "%$busca%";
}
if ($setor !== '') {
    $where .= ' AND livros.setor = :setor';
    $params[':setor'] = $setor;
}
if (valid_date($inicio)) {
    $where .= ' AND livros.data_fim >= :inicio';
    $params[':inicio'] = $inicio;
}
if (valid_date($fim)) {
    $where .= ' AND livros.data_inicio <= :fim';
    $params[':fim'] = $fim;
}
$whereBase = $where;
$paramsBase = $params;
if ($dig === 'Sim') {
    $where .= " AND livros.digitalizado = 'Sim'";
} elseif ($dig === 'Não') {
    $where .= " AND (livros.digitalizado IS NULL OR livros.digitalizado <> 'Sim')";
}
$from = ' FROM livros LEFT JOIN setores ON livros.setor = setores.id';
$select = 'SELECT livros.id, livros.numero_livro, setores.nome AS setor, livros.data_inicio, livros.data_fim, livros.posicao, livros.digitalizado,
           (SELECT COUNT(*) FROM paginas p WHERE p.livro_id = livros.id) AS total_paginas';
$order = ' ORDER BY livros.data_inicio ASC, livros.id ASC';

if (($_GET['export'] ?? '') === 'csv') {
    $st = $pdo->prepare($select . $from . $where . $order);
    $st->execute($params);
    $rows = [];
    foreach ($st as $l) {
        $rows[] = [$l['numero_livro'], $l['setor'], fmt_date($l['data_inicio'], 'd/m/Y', ''), fmt_date($l['data_fim'], 'd/m/Y', ''), $l['posicao'], $l['digitalizado'], $l['total_paginas']];
    }
    csv_download('livros_' . date('Y-m-d') . '.csv', ['Número', 'Setor', 'Início', 'Fim', 'Posição', 'Digitalizado', 'Páginas'], $rows);
}

$st = $pdo->prepare('SELECT COUNT(*)' . $from . $where);
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
$livros = $stmt->fetchAll();

$setores = all_setores();
// Contadores dos filtros rápidos: mesmos filtros da busca (exceto a digitalização)
$stc = $pdo->prepare("SELECT COUNT(*) total, COALESCE(SUM(livros.digitalizado = 'Sim'), 0) sim" . $from . $whereBase);
$stc->execute($paramsBase);
$contagem = $stc->fetch();

$qf = function ($key, $label, $n = null) use ($dig) {
    $q = clean_query();
    unset($q['page'], $q['digitalizado']);
    if ($key !== '') $q['digitalizado'] = $key;
    return '<a class="qf' . ($dig === $key ? ' is-active' : '') . '" href="?' . e(http_build_query($q)) . '">' . e($label)
        . ($n !== null ? ' <span class="n">' . fmt_num($n) . '</span>' : '') . '</a>';
};

layout_start('Livros', [
    'active'   => 'livros',
    'subtitle' => 'Livros do arquivo e suas páginas digitalizadas.',
    'actions'  => '<a class="btn btn-secondary" href="' . e(export_url()) . '"><i class="fa-solid fa-file-csv"></i>Exportar</a>'
                . '<a class="btn btn-primary" href="livros-conf.php"><i class="fa-solid fa-pen-to-square"></i>Gerenciar livros</a>',
]);
?>
<div class="quick-filters mb-2">
    <?= $qf('', 'Todos', $contagem['total']) ?>
    <?= $qf('Sim', 'Digitalizados', $contagem['sim']) ?>
    <?= $qf('Não', 'Não digitalizados', $contagem['total'] - $contagem['sim']) ?>
</div>

<section class="panel">
    <form method="GET" class="filters">
        <?php if ($dig !== ''): ?><input type="hidden" name="digitalizado" value="<?= e($dig) ?>"><?php endif; ?>
        <div class="field field-search">
            <label for="f-busca">Procurar</label>
            <div class="input-icon"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="f-busca" name="busca" placeholder="Número do livro ou posição" value="<?= e($busca) ?>">
            </div>
        </div>
        <div class="field">
            <label for="f-setor">Setor</label>
            <select id="f-setor" name="setor"><?= setor_options($setores, $setor, 'Todos os setores') ?></select>
        </div>
        <div class="field">
            <label for="f-ini">Período a partir de</label>
            <input type="date" id="f-ini" name="inicio" value="<?= e($inicio) ?>">
        </div>
        <div class="field">
            <label for="f-fim">Período até</label>
            <input type="date" id="f-fim" name="fim" value="<?= e($fim) ?>">
        </div>
        <div class="filters-actions">
            <a class="btn btn-ghost" href="livros.php">Limpar</a>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i>Filtrar</button>
        </div>
    </form>

    <?php if (!$livros): ?>
        <?= empty_state('Nenhum livro encontrado', 'Revise os filtros ou limpe a busca para ver todos os livros.', 'fa-book', '<a class="btn btn-secondary" href="livros.php">Limpar filtros</a>') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table responsive">
            <thead>
                <tr>
                    <th>Número</th>
                    <th>Setor</th>
                    <th>Período</th>
                    <th>Posição</th>
                    <th>Páginas</th>
                    <th>Digitalização</th>
                    <th class="col-actions"><span class="sr-only">Ações</span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($livros as $l): ?>
                <tr>
                    <td class="col-primary" data-label="Número"><span class="tag-id"><small>Livro</small><?= e($l['numero_livro']) ?></span></td>
                    <td data-label="Setor"><?= e($l['setor'] ? mb_strtoupper($l['setor']) : '—') ?></td>
                    <td data-label="Período" class="nowrap"><?= e(fmt_periodo($l['data_inicio'], $l['data_fim'])) ?></td>
                    <td data-label="Posição"><?= $l['posicao'] !== null && $l['posicao'] !== '' ? e(mb_strtoupper($l['posicao'])) : '<span class="muted">—</span>' ?></td>
                    <td data-label="Páginas"><?= fmt_num($l['total_paginas']) ?></td>
                    <td data-label="Digitalização"><?= strtolower((string) $l['digitalizado']) === 'sim' ? '<span class="badge badge-success">Digitalizado</span>' : '<span class="badge badge-warning">Não digitalizado</span>' ?></td>
                    <td class="col-actions">
                        <div class="row-actions">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="abrirLivro(<?= (int) $l['id'] ?>)"><i class="fa-solid fa-book-open"></i>Abrir livro</button>
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

<div class="modal modal-xl" id="modal-livro" role="dialog" aria-modal="true" aria-labelledby="livro-title">
    <div class="modal-dialog">
        <div class="modal-header">
            <div><h2 class="modal-title" id="livro-title">Páginas do livro</h2></div>
            <button class="icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="livro-body"></div>
    </div>
</div>

<?php
layout_end(['scripts' => <<<'JS'
<script>
var livroBody = document.getElementById('livro-body');
function carregarPaginas(qs) {
    livroBody.innerHTML = '<div class="loading-block"><div class="spinner"></div>Carregando páginas…</div>';
    fetch('../includes/livro_paginas.php?' + qs, { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error(); return r.text(); })
        .then(function (html) {
            livroBody.innerHTML = html;
            var t = livroBody.querySelector('[data-livro-titulo]');
            if (t) document.getElementById('livro-title').textContent = t.getAttribute('data-livro-titulo');
        })
        .catch(function () { livroBody.innerHTML = '<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span>Não foi possível carregar as páginas. Tente novamente.</span></div>'; });
}
function abrirLivro(id) {
    document.getElementById('livro-title').textContent = 'Páginas do livro';
    App.openModal('modal-livro');
    // Aplica ao modal o mesmo período filtrado na lista, quando houver
    var p = new URLSearchParams(window.location.search);
    var qs = new URLSearchParams({ id: id });
    if (p.get('inicio')) qs.set('inicio', p.get('inicio'));
    if (p.get('fim')) qs.set('fim', p.get('fim'));
    carregarPaginas(qs.toString());
}
livroBody.addEventListener('submit', function (ev) {
    var f = ev.target.closest('#filtro-paginas');
    if (!f) return;
    ev.preventDefault();
    carregarPaginas(new URLSearchParams(new FormData(f)).toString());
});
livroBody.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-limpar-paginas]');
    if (!b) return;
    ev.preventDefault();
    carregarPaginas('id=' + encodeURIComponent(b.getAttribute('data-limpar-paginas')));
});
</script>
JS
]);
