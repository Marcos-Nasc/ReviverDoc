<?php
require_once '../includes/layout.php';
require_level(NIVEL_ADMIN);
$existe = table_exists('logs_atividade');
$page = current_page();
$per_page = 30;
$busca = trim($_GET['busca'] ?? '');
$entidade = $_GET['entidade'] ?? '';
$usuario = $_GET['usuario'] ?? '';
$ini = valid_date($_GET['de'] ?? '') ? $_GET['de'] : '';
$fim = valid_date($_GET['ate'] ?? '') ? $_GET['ate'] : '';
$logs = [];
$total = 0;
$entidades = [];
if ($existe) {
    $where = ' WHERE 1';
    $p = [];
    if ($busca !== '') { $where .= ' AND (l.descricao LIKE :b OR u.nome LIKE :b)'; $p[':b'] = "%$busca%"; }
    if ($entidade !== '') { $where .= ' AND l.entidade = :e'; $p[':e'] = $entidade; }
    if (ctype_digit((string) $usuario)) { $where .= ' AND l.usuario_id = :u'; $p[':u'] = (int) $usuario; }
    if ($ini) { $where .= ' AND l.data >= :ini'; $p[':ini'] = $ini . ' 00:00:00'; }
    if ($fim) { $where .= ' AND l.data <= :fim'; $p[':fim'] = $fim . ' 23:59:59'; }
    $from = ' FROM logs_atividade l LEFT JOIN usuarios u ON u.id = l.usuario_id';
    $st = $pdo->prepare('SELECT COUNT(*)' . $from . $where);
    $st->execute($p);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare('SELECT l.*, u.nome' . $from . $where . ' ORDER BY l.id DESC LIMIT :l OFFSET :o');
    foreach ($p as $k => $v) $st->bindValue($k, $v);
    $st->bindValue(':l', $per_page, PDO::PARAM_INT);
    $st->bindValue(':o', ($page - 1) * $per_page, PDO::PARAM_INT);
    $st->execute();
    $logs = $st->fetchAll();
    $entidades = $pdo->query('SELECT DISTINCT entidade FROM logs_atividade ORDER BY entidade')->fetchAll(PDO::FETCH_COLUMN);
}
$usuarios = $pdo->query('SELECT id, nome FROM usuarios ORDER BY nome')->fetchAll();
$acoes = ['criar' => ['Criou', 'badge-success'], 'editar' => ['Editou', 'badge-info'], 'excluir' => ['Excluiu', 'badge-danger'], 'login' => ['Entrou', 'no-dot'], 'logout' => ['Saiu', 'no-dot'],
          'responder' => ['Respondeu', 'badge-info'], 'aprovar' => ['Aprovou', 'badge-success'], 'concluir' => ['Concluiu', 'badge-success'], 'backup' => ['Backup', 'badge-brass'], 'senha' => ['Senha', 'badge-brass'], 'upload' => ['Enviou arquivo', 'badge-info']];
layout_start('Histórico de atividades', ['active' => 'historico', 'subtitle' => 'Registro de quem criou, alterou ou excluiu informações no sistema.']);
?>
<?php if (!$existe): ?>
    <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i><span><strong>O histórico ainda não está ativado.</strong> Execute o arquivo <strong>db/migracao_2026_11_modernizacao.sql</strong> no phpMyAdmin para criar a tabela <code>logs_atividade</code>. A partir daí, todas as alterações passam a ser registradas automaticamente.</span></div>
<?php else: ?>
<section class="panel">
    <form method="GET" class="filters">
        <div class="field field-search"><label for="h-b">Procurar</label><div class="input-icon"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="h-b" name="busca" value="<?= e($busca) ?>" placeholder="Descrição ou usuário"></div></div>
        <div class="field"><label for="h-e">Área</label><select id="h-e" name="entidade"><option value="">Todas</option><?php foreach ($entidades as $en): ?><option value="<?= e($en) ?>" <?= $entidade === $en ? 'selected' : '' ?>><?= e(ucfirst($en)) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="h-u">Usuário</label><select id="h-u" name="usuario"><option value="">Todos</option><?php foreach ($usuarios as $us): ?><option value="<?= (int) $us['id'] ?>" <?= (string) $usuario === (string) $us['id'] ? 'selected' : '' ?>><?= e($us['nome']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="h-de">De</label><input type="date" id="h-de" name="de" value="<?= e($ini) ?>"></div>
        <div class="field"><label for="h-ate">Até</label><input type="date" id="h-ate" name="ate" value="<?= e($fim) ?>"></div>
        <div class="filters-actions"><a class="btn btn-ghost" href="historico.php">Limpar</a><button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i>Filtrar</button></div>
    </form>
    <?php if (!$logs): ?>
        <?= empty_state('Nenhuma atividade registrada', 'As ações realizadas no sistema aparecerão aqui.', 'fa-clock-rotate-left') ?>
    <?php else: ?>
    <div class="table-wrap"><table class="table responsive">
        <thead><tr><th>Data</th><th>Usuário</th><th>Ação</th><th>Área</th><th>Descrição</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): $a = $acoes[$l['acao']] ?? [ucfirst($l['acao']), 'no-dot']; ?>
            <tr>
                <td class="col-primary nowrap" data-label="Data"><?= e(fmt_datetime($l['data'])) ?></td>
                <td data-label="Usuário"><?= e($l['nome'] ?: '—') ?></td>
                <td data-label="Ação"><span class="badge <?= $a[1] ?>"><?= e($a[0]) ?></span></td>
                <td data-label="Área"><?= e(ucfirst($l['entidade'])) ?><?= $l['entidade_id'] ? ' <span class="muted">#' . (int) $l['entidade_id'] . '</span>' : '' ?></td>
                <td data-label="Descrição" class="wrap-text"><?= e($l['descricao']) ?></td>
                <td data-label="IP" class="muted small"><?= e($l['ip'] ?: '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
    <?= render_pagination($page, (int) ceil($total / $per_page), $total, $per_page) ?>
</section>
<?php endif; ?>
<?php layout_end();
