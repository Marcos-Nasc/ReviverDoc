<?php
require_once __DIR__ . '/bootstrap.php';
page_guard(NIVEL_ARQUIVO);

$id = (int) ($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT l.id, l.numero_livro, l.setor, s.nome AS setor_nome FROM livros l LEFT JOIN setores s ON s.id = l.setor WHERE l.id = ?');
$st->execute([$id]);
$livro = $st->fetch();
if (!$livro || !can_access_setor($livro['setor'])) {
    echo '<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span>Livro não encontrado ou sem permissão de acesso.</span></div>';
    exit;
}

$inicio = valid_date($_GET['inicio'] ?? '') ? $_GET['inicio'] : '';
$fim    = valid_date($_GET['fim'] ?? '') ? $_GET['fim'] : '';

$sql = 'SELECT id, numero_pagina, letra, data_inicio, data_fim, arquivo, nome_original FROM paginas WHERE livro_id = :id';
$params = [':id' => $id];
if ($inicio) {
    $sql .= ' AND data_fim >= :inicio';
    $params[':inicio'] = $inicio;
}
if ($fim) {
    $sql .= ' AND data_inicio <= :fim';
    $params[':fim'] = $fim;
}
$sql .= ' ORDER BY numero_pagina, letra, id';
$pg = $pdo->prepare($sql);
$pg->execute($params);
$paginas = $pg->fetchAll();
?>
<div data-livro-titulo="Livro <?= e($livro['numero_livro']) ?> · <?= e($livro['setor_nome'] ?: 'sem setor') ?>"></div>
<form id="filtro-paginas" class="flex mb-2" style="align-items:flex-end">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <div class="field" style="flex:1 1 160px">
        <label for="pg-ini">Data a partir de</label>
        <input type="date" id="pg-ini" name="inicio" value="<?= e($inicio) ?>">
    </div>
    <div class="field" style="flex:1 1 160px">
        <label for="pg-fim">Data até</label>
        <input type="date" id="pg-fim" name="fim" value="<?= e($fim) ?>">
    </div>
    <div class="flex">
        <?php if ($inicio || $fim): ?><button type="button" class="btn btn-ghost" data-limpar-paginas="<?= (int) $id ?>">Limpar</button><?php endif; ?>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i>Filtrar páginas</button>
    </div>
</form>
<p class="muted small mb-2"><?= count($paginas) ?> página(s)<?= ($inicio || $fim) ? ' no período selecionado' : '' ?></p>

<?php if (!$paginas): ?>
    <div class="empty"><div class="empty-icon"><i class="fa-regular fa-file"></i></div><h3>Nenhuma página encontrada</h3><p><?= ($inicio || $fim) ? 'Não há páginas neste período. Ajuste as datas ou limpe o filtro.' : 'As páginas digitalizadas deste livro aparecerão aqui.' ?></p></div>
<?php else: ?>
<div class="media-grid">
    <?php foreach ($paginas as $p):
        $thumb = thumb_url_for($p['arquivo']);
        $periodo = ($p['data_inicio'] && $p['data_fim'] && $p['data_inicio'] !== $p['data_fim'])
            ? fmt_date($p['data_inicio']) . ' – ' . fmt_date($p['data_fim'])
            : fmt_date($p['data_inicio'] ?: $p['data_fim']);
        $titulo = 'Página ' . $p['numero_pagina'] . ($p['letra'] ? strtoupper($p['letra']) : '');
        ?>
        <?php if (!empty($p['arquivo'])): ?>
            <a class="media-card" href="../uploads/paginas/<?= e(rawurlencode($p['arquivo'])) ?>" target="_blank" rel="noopener" title="<?= e($titulo) ?>">
        <?php else: ?>
            <div class="media-card" title="<?= e($titulo) ?>">
        <?php endif; ?>
            <div class="media-thumb"><?= $thumb ? '<img src="../' . e($thumb) . '" alt="" loading="lazy">' : '<i class="fa-regular fa-file-lines"></i>' ?></div>
            <div class="media-info">
                <span class="media-title"><?= e($titulo) ?></span>
                <span class="media-sub"><?= e($periodo) ?></span>
            </div>
            <span class="media-open"><?= !empty($p['arquivo']) ? '<i class="fa-solid fa-arrow-up-right-from-square"></i>Abrir PDF' : '<span class="muted">Sem arquivo anexado</span>' ?></span>
        <?= !empty($p['arquivo']) ? '</a>' : '</div>' ?>
    <?php endforeach; ?>
</div>
<?php endif;
