<?php
require_once '../includes/layout.php';
require_login();
$page = current_page();
$per_page = 25;
$filtro = ($_GET['filtro'] ?? '') === 'nao_lidas' ? 'nao_lidas' : '';
$where = ' WHERE usuario_id = :u' . ($filtro ? ' AND lida = 0' : '');
$st = $pdo->prepare('SELECT COUNT(*) FROM notificacoes' . $where);
$st->execute([':u' => user_id()]);
$total = (int) $st->fetchColumn();
$pages = (int) ceil($total / $per_page);
$st = $pdo->prepare('SELECT id, mensagem, link, lida, data_criacao FROM notificacoes' . $where . ' ORDER BY data_criacao DESC, id DESC LIMIT :l OFFSET :o');
$st->bindValue(':u', user_id(), PDO::PARAM_INT);
$st->bindValue(':l', $per_page, PDO::PARAM_INT);
$st->bindValue(':o', ($page - 1) * $per_page, PDO::PARAM_INT);
$st->execute();
$lista = $st->fetchAll();
layout_start('Notificações', [
    'active'   => 'notificacoes',
    'subtitle' => 'Avisos sobre solicitações, romaneios e aprovações.',
    'actions'  => ($GLOBALS['unread_count'] ?? 0) ? '<button type="button" class="btn btn-secondary" data-notif-read-all><i class="fa-solid fa-check-double"></i>Marcar todas como lidas</button>' : '',
]);
?>
<section class="panel">
    <div class="tabs">
        <a class="tab<?= $filtro ? '' : ' is-active' ?>" href="notificacoes.php">Todas</a>
        <a class="tab<?= $filtro ? ' is-active' : '' ?>" href="notificacoes.php?filtro=nao_lidas">Não lidas <?= ($GLOBALS['unread_count'] ?? 0) ? '(' . (int) $GLOBALS['unread_count'] . ')' : '' ?></a>
    </div>
    <?php if (!$lista): ?>
        <?= empty_state($filtro ? 'Nenhuma notificação não lida' : 'Nenhuma notificação ainda', 'Você será avisado aqui sobre respostas, prazos e aprovações.', 'fa-bell-slash') ?>
    <?php else: ?>
    <ul class="list">
        <?php foreach ($lista as $n): ?>
            <li><a class="list-row" href="../includes/marcar_notificacao_lida.php?id=<?= (int) $n['id'] ?>&amp;redirect=<?= urlencode('../' . ($n['link'] ?: 'index.php')) ?>">
                <span class="hub-icon" style="width:34px;height:34px;font-size:14px"><i class="fa-<?= $n['lida'] ? 'regular' : 'solid' ?> fa-bell"></i></span>
                <span class="list-main"><span class="list-title" style="white-space:normal;<?= $n['lida'] ? 'font-weight:400;color:var(--text-2)' : 'font-weight:600' ?>"><?= e($n['mensagem']) ?></span><span class="list-sub"><?= e(fmt_datetime($n['data_criacao'])) ?> · <?= e(rel_time($n['data_criacao'])) ?></span></span>
                <?php if (!$n['lida']): ?><span class="badge badge-brass">Nova</span><?php endif; ?>
            </a></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?= render_pagination($page, $pages, $total, $per_page) ?>
</section>
<?php layout_end();
