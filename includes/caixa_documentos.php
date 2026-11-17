<?php
require_once __DIR__ . '/bootstrap.php';
page_guard(NIVEL_SETOR);

$caixa_id = (int) ($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT numero, setor_id FROM caixas WHERE id = ?');
$st->execute([$caixa_id]);
$caixa = $st->fetch();
if (!$caixa || !can_access_setor($caixa['setor_id'])) {
    echo '<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span>Caixa não encontrada ou sem permissão de acesso.</span></div>';
    exit;
}
$docs = $pdo->prepare('SELECT id, nome, data_digitalizacao, arquivo FROM documentos WHERE caixa_id = ? ORDER BY nome ASC');
$docs->execute([$caixa_id]);
$documentos = $docs->fetchAll();
$numero = in_array(trim((string) $caixa['numero']), ['', '-'], true) ? 'sem número' : $caixa['numero'];
?>
<p class="muted mb-2">Caixa <?= e($numero) ?> · <?= count($documentos) ?> documento(s)</p>
<?php if (!$documentos): ?>
    <div class="empty"><div class="empty-icon"><i class="fa-regular fa-file"></i></div><h3>Nenhum documento digitalizado</h3><p>Os documentos cadastrados para esta caixa aparecerão aqui.</p></div>
<?php else: ?>
<div class="media-grid">
    <?php foreach ($documentos as $d):
        $thumb = thumb_url_for($d['arquivo']);
        $tag = $d['arquivo'] ? 'a href="../uploads/documentos/' . e(rawurlencode($d['arquivo'])) . '" target="_blank" rel="noopener"' : 'div';
        ?>
        <<?= $tag ?> class="media-card" title="<?= e($d['nome']) ?>">
            <div class="media-thumb"><?= $thumb ? '<img src="../' . e($thumb) . '" alt="" loading="lazy">' : '<i class="fa-regular fa-file-pdf"></i>' ?></div>
            <div class="media-info">
                <span class="media-title"><?= e($d['nome']) ?></span>
                <span class="media-sub"><?= $d['data_digitalizacao'] ? 'Digitalizado em ' . e(fmt_date($d['data_digitalizacao'])) : 'Sem data de digitalização' ?></span>
            </div>
            <span class="media-open"><?= $d['arquivo'] ? '<i class="fa-solid fa-arrow-up-right-from-square"></i>Abrir PDF' : '<span class="muted">Sem arquivo anexado</span>' ?></span>
        </<?= $d['arquivo'] ? 'a' : 'div' ?>>
    <?php endforeach; ?>
</div>
<?php endif;
