<?php
require_once __DIR__ . '/bootstrap.php';
page_guard(NIVEL_ARQUIVO);

$id = (int) ($_GET['id'] ?? 0);
$st = $pdo->prepare("
    SELECT c.*, s1.nome AS setor_origem, s2.nome AS setor_alocado_nome,
           (SELECT COUNT(*) FROM documentos d WHERE d.caixa_id = c.id) AS total_docs
    FROM caixas c
    LEFT JOIN setores s1 ON c.setor_id = s1.id
    LEFT JOIN setores s2 ON c.setor_alocado = s2.id
    WHERE c.id = ?");
$st->execute([$id]);
$c = $st->fetch();
if (!$c) {
    echo '<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span>Caixa não encontrada. Ela pode ter sido excluída.</span></div>';
    exit;
}
$mov = $pdo->prepare("
    SELECT r.id, r.tipo, r.data_saida, r.data_retorno, r.status, u.nome AS usuario, s.nome AS setor
    FROM romaneio_caixas rc
    JOIN romaneios r ON r.id = rc.romaneio_id
    LEFT JOIN usuarios u ON u.id = r.usuario_id
    LEFT JOIN setores s ON s.id = r.setor_id
    WHERE rc.caixa_id = ?
    ORDER BY r.data_saida DESC, r.id DESC
    LIMIT 15");
$mov->execute([$id]);
$movs = $mov->fetchAll();
$semEtiqueta = in_array(trim((string) $c['numero']), ['', '-'], true);
?>
<div class="flex between mb-2">
    <span class="tag-id<?= $semEtiqueta ? ' is-muted' : '' ?>" style="font-size:16px"><small>Caixa</small><?= $semEtiqueta ? 'Sem nº' : e($c['numero']) ?></span>
    <div class="flex">
        <?= $c['status'] === 'Fora do Arquivo' ? '<span class="badge badge-danger">Fora do arquivo</span>' : '<span class="badge badge-success">No arquivo</span>' ?>
        <?= $c['higienizada'] === 'Não' ? '<span class="badge badge-warning">Não higienizada</span>' : '<span class="badge badge-info">Higienizada</span>' ?>
    </div>
</div>
<dl class="dl">
    <dt>Conteúdo</dt><dd><?= e($c['conteudo'] ?: '—') ?></dd>
    <dt>Período</dt><dd><?= e(fmt_date($c['data_inicio'])) ?> a <?= e(fmt_date($c['data_fim'])) ?></dd>
    <dt>Setor de origem</dt><dd><?= e($c['setor_origem'] ?: '—') ?></dd>
    <?php if ($c['setor_alocado'] && $c['setor_alocado'] != $c['setor_id']): ?>
        <dt>Setor alocado</dt><dd><?= e($c['setor_alocado_nome']) ?></dd>
    <?php endif; ?>
    <dt>Localização</dt><dd><?= ($c['coluna'] || $c['fileira']) ? 'Coluna ' . e($c['coluna'] ?: '—') . ' · Fileira ' . e($c['fileira'] ?: '—') : '—' ?></dd>
    <dt>Observação</dt><dd class="pre"><?= e(trim((string) $c['observacao']) ?: '—') ?></dd>
    <dt>Digitalização</dt><dd><?= e(fmt_date($c['data_digitalizacao'])) ?></dd>
    <dt>Documentos</dt><dd><?= (int) $c['total_docs'] ?> digitalizado(s)</dd>
</dl>

<h3 class="mt-3 mb-2" style="font-size:15px">Movimentações (romaneios)</h3>
<?php if (!$movs): ?>
    <p class="muted small">Esta caixa ainda não teve movimentações registradas.</p>
<?php else: ?>
    <ul class="timeline">
        <?php foreach ($movs as $m):
            $atrasado = in_array((string) $m['status'], ['Pendente', ''], true) && $m['data_retorno'] && strtotime($m['data_retorno']) < strtotime('today'); ?>
            <li>
                <strong>Romaneio #<?= e(fmt_id($m['id'])) ?></strong> · <?= e($m['tipo']) ?> em <?= e(fmt_date($m['data_saida'])) ?>
                <?php if ($m['tipo'] === 'Saída'): ?>
                    <?= $atrasado ? '<span class="badge badge-danger">Atrasado</span>' : ($m['status'] === 'Concluído' ? '<span class="badge badge-success">Devolvida</span>' : '<span class="badge badge-warning">Pendente</span>') ?>
                <?php endif; ?>
                <div class="small muted"><?= e($m['usuario'] ?: '—') ?> · <?= e($m['setor'] ?: '—') ?><?= $m['data_retorno'] ? ' · retorno previsto ' . e(fmt_date($m['data_retorno'])) : '' ?></div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<div class="flex mt-3">
    <a class="btn btn-secondary" href="../includes/gerar_etiqueta.php?id=<?= $id ?>" target="_blank" rel="noopener"><i class="fa-solid fa-tag"></i>Imprimir etiqueta</a>
    <a class="btn btn-ghost" href="caixas-conf.php?editar=<?= $id ?>"><i class="fa-solid fa-pen"></i>Editar caixa</a>
</div>
