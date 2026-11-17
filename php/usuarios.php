<?php
require_once '../includes/layout.php';
require_level(NIVEL_ADMIN);

$page = current_page();
$per_page = 20;
$offset = ($page - 1) * $per_page;
$busca  = trim($_GET['busca'] ?? '');
$nivel  = $_GET['nivel'] ?? '';
$status = $_GET['status'] ?? '';
$setor  = $_GET['setor_id'] ?? '';

$where = ' WHERE 1';
$params = [];
if ($busca !== '') {
    $where .= ' AND (u.nome LIKE :busca OR u.email LIKE :busca)';
    $params[':busca'] = "%$busca%";
}
if (in_array($nivel, ['1', '2', '3'], true)) {
    $where .= ' AND u.nivel = :nivel';
    $params[':nivel'] = $nivel;
}
if ($status === 'Inativo') {
    $where .= " AND u.status = 'Inativo'";
} elseif ($status === 'Ativo') {
    $where .= " AND (u.status IS NULL OR u.status <> 'Inativo')";
}
if ($setor !== '') {
    $where .= ' AND u.setor_id = :setor';
    $params[':setor'] = $setor;
}
$from = ' FROM usuarios u LEFT JOIN setores s ON u.setor_id = s.id';
$st = $pdo->prepare('SELECT COUNT(*)' . $from . $where);
$st->execute($params);
$total_records = (int) $st->fetchColumn();
$total_pages = (int) ceil($total_records / $per_page);

$stmt = $pdo->prepare('SELECT u.id, u.nome, u.email, u.nivel, u.status, u.setor_id, s.nome AS setor_nome' . $from . $where . ' ORDER BY u.status ASC, u.nome ASC LIMIT :limit OFFSET :offset');
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$usuarios = $stmt->fetchAll();
$setores = all_setores();
$cont = $pdo->query("SELECT SUM(nivel = 1) n1, SUM(nivel = 2) n2, SUM(nivel = 3) n3, SUM(status = 'Inativo') inativos FROM usuarios")->fetch();

layout_start('Usuários', [
    'active'   => 'usuarios',
    'subtitle' => 'Gerencie quem acessa o sistema e o que cada pessoa pode fazer.',
    'actions'  => '<button type="button" class="btn btn-primary" id="btnNovo"><i class="fa-solid fa-user-plus"></i>Novo usuário</button>',
]);
?>
<div class="kpis">
    <div class="kpi"><div class="kpi-label"><i class="fa-solid fa-user"></i>Usuários de setor</div><div class="kpi-value"><?= fmt_num($cont['n1']) ?></div><div class="kpi-note">Abrem solicitações e romaneios do seu setor</div></div>
    <div class="kpi is-info"><div class="kpi-label"><i class="fa-solid fa-box-archive"></i>Equipe do Arquivo</div><div class="kpi-value"><?= fmt_num($cont['n2']) ?></div><div class="kpi-note">Operam o acervo e respondem solicitações</div></div>
    <div class="kpi is-warning"><div class="kpi-label"><i class="fa-solid fa-user-shield"></i>Administradores</div><div class="kpi-value"><?= fmt_num($cont['n3']) ?></div><div class="kpi-note">Acesso total, aprovam entradas</div></div>
    <div class="kpi"><div class="kpi-label"><i class="fa-solid fa-user-slash"></i>Inativos</div><div class="kpi-value"><?= fmt_num($cont['inativos']) ?></div><div class="kpi-note">Sem acesso ao sistema</div></div>
</div>

<section class="panel">
    <form method="GET" class="filters">
        <div class="field field-search">
            <label for="f-busca">Procurar</label>
            <div class="input-icon"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="f-busca" name="busca" placeholder="Nome ou e-mail" value="<?= e($busca) ?>"></div>
        </div>
        <div class="field"><label for="f-nivel">Perfil</label>
            <select id="f-nivel" name="nivel"><option value="">Todos</option>
                <?php for ($i = 1; $i <= 3; $i++): ?><option value="<?= $i ?>" <?= $nivel === (string) $i ? 'selected' : '' ?>><?= e(nivel_label($i)) ?></option><?php endfor; ?>
            </select></div>
        <div class="field"><label for="f-setor">Setor</label><select id="f-setor" name="setor_id"><?= setor_options($setores, $setor, 'Todos') ?></select></div>
        <div class="field"><label for="f-status">Status</label>
            <select id="f-status" name="status"><option value="">Todos</option>
                <option value="Ativo" <?= $status === 'Ativo' ? 'selected' : '' ?>>Ativo</option>
                <option value="Inativo" <?= $status === 'Inativo' ? 'selected' : '' ?>>Inativo</option>
            </select></div>
        <div class="filters-actions">
            <a class="btn btn-ghost" href="usuarios.php">Limpar</a>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i>Filtrar</button>
        </div>
    </form>
    <?php if (!$usuarios): ?>
        <?= empty_state('Nenhum usuário encontrado', 'Ajuste os filtros ou cadastre um novo usuário.', 'fa-users') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table responsive">
            <thead><tr><th>Nome</th><th>E-mail</th><th>Setor</th><th>Perfil</th><th>Status</th><th class="col-actions"><span class="sr-only">Ações</span></th></tr></thead>
            <tbody>
            <?php foreach ($usuarios as $u):
                $self = (int) $u['id'] === user_id(); ?>
                <tr>
                    <td class="col-primary" data-label="Nome"><span class="cell-title"><?= e($u['nome']) ?></span><?= $self ? ' <span class="badge badge-brass no-dot">Você</span>' : '' ?></td>
                    <td data-label="E-mail"><?= e($u['email']) ?></td>
                    <td data-label="Setor"><?= e($u['setor_nome'] ?: '—') ?></td>
                    <td data-label="Perfil"><?php $cls = ['1' => 'no-dot', '2' => 'badge-info', '3' => 'badge-brass']; ?><span class="badge <?= $cls[(string) $u['nivel']] ?? 'no-dot' ?>"><?= e(nivel_label($u['nivel'])) ?></span></td>
                    <td data-label="Status"><?= $u['status'] === 'Inativo' ? '<span class="badge badge-danger">Inativo</span>' : '<span class="badge badge-success">Ativo</span>' ?></td>
                    <td class="col-actions">
                        <div class="row-actions">
                            <button type="button" class="act" title="Editar" aria-label="Editar usuário" onclick="editar(<?= (int) $u['id'] ?>)"><i class="fa-solid fa-pen"></i></button>
                            <?php if (!$self): ?>
                                <button type="button" class="act is-danger" title="Excluir" aria-label="Excluir usuário" onclick="excluir(<?= (int) $u['id'] ?>, '<?= e(addslashes($u['nome'])) ?>', this)"><i class="fa-regular fa-trash-can"></i></button>
                            <?php endif; ?>
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

<div class="modal" id="modalUsuario" role="dialog" aria-modal="true" aria-labelledby="mTitulo">
    <div class="modal-dialog">
        <div class="modal-header">
            <div><h2 class="modal-title" id="mTitulo">Novo usuário</h2><p class="modal-sub">Campos com <span class="text-danger">*</span> são obrigatórios.</p></div>
            <button class="icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="formUsuario" class="modal-body" novalidate autocomplete="off">
            <input type="hidden" name="id">
            <div class="form-grid">
                <div class="field span-2"><label for="u-nome">Nome<span class="req">*</span></label><input type="text" id="u-nome" name="nome" required maxlength="100"></div>
                <div class="field span-2"><label for="u-email">E-mail<span class="req">*</span></label><input type="email" id="u-email" name="email" required maxlength="150" autocomplete="off"></div>
                <div class="field span-2">
                    <label for="u-senha" id="u-senha-label">Senha<span class="req">*</span></label>
                    <div class="flex" style="flex-wrap:nowrap">
                        <input type="text" id="u-senha" name="senha" minlength="6" autocomplete="new-password" style="flex:1">
                        <button type="button" class="btn btn-secondary" id="gerarSenha" title="Gerar senha"><i class="fa-solid fa-wand-magic-sparkles"></i>Gerar</button>
                    </div>
                    <span class="help" id="u-senha-help">Mínimo de 6 caracteres. Anote a senha para informar ao usuário.</span>
                </div>
                <div class="field"><label for="u-setor">Setor</label><select id="u-setor" name="setor_id"><?= setor_options($setores, '', 'Nenhum') ?></select></div>
                <div class="field"><label for="u-nivel">Perfil de acesso<span class="req">*</span></label>
                    <select id="u-nivel" name="nivel" required>
                        <?php for ($i = 1; $i <= 3; $i++): ?><option value="<?= $i ?>"><?= $i ?> — <?= e(nivel_label($i)) ?></option><?php endfor; ?>
                    </select></div>
                <div class="field"><label for="u-status">Status</label>
                    <select id="u-status" name="status"><option value="Ativo">Ativo</option><option value="Inativo">Inativo</option></select></div>
                <div class="field span-2"><p class="help" id="nivelHelp"></p></div>
            </div>
        </form>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-modal-close>Cancelar</button>
            <button type="submit" form="formUsuario" class="btn btn-primary"><i class="fa-solid fa-check"></i>Salvar usuário</button>
        </div>
    </div>
</div>
<?php
layout_end(['scripts' => <<<'JS'
<script>
var form = document.getElementById('formUsuario');
var ajuda = { '1': 'Usuário de setor: abre solicitações de pesquisa e romaneios e vê apenas os dados do próprio setor. Precisa ter um setor.',
              '2': 'Equipe do Arquivo: consulta e gerencia caixas, livros, documentos, solicitações e romaneios.',
              '3': 'Administrador: acesso total, incluindo usuários, setores, aprovação de entradas, backup e configurações.' };
function atualizarAjuda() {
    var v = form.elements.nivel.value;
    if (v && !form.elements.nivel.querySelector('option[value="' + v + '"]')) v = '';
    document.getElementById('nivelHelp').textContent = ajuda[v] || '';
}
form.elements.nivel.addEventListener('change', atualizarAjuda);
function modoSenha(novo) {
    form.elements.senha.required = novo;
    document.getElementById('u-senha-label').innerHTML = novo ? 'Senha<span class="req">*</span>' : 'Nova senha';
    document.getElementById('u-senha-help').textContent = novo ? 'Mínimo de 6 caracteres. Anote a senha para informar ao usuário.' : 'Deixe em branco para manter a senha atual.';
}
document.getElementById('gerarSenha').addEventListener('click', function () {
    var c = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789', s = '';
    var a = new Uint32Array(10); (window.crypto || window.msCrypto).getRandomValues(a);
    for (var i = 0; i < 10; i++) s += c[a[i] % c.length];
    form.elements.senha.value = s;
});
document.getElementById('btnNovo').addEventListener('click', function () {
    App.resetForm(form); form.elements.nivel.value = '1'; modoSenha(true); atualizarAjuda();
    document.getElementById('mTitulo').textContent = 'Novo usuário';
    App.openModal('modalUsuario');
});
function editar(id) {
    App.resetForm(form); modoSenha(false);
    document.getElementById('mTitulo').textContent = 'Editar usuário';
    App.openModal('modalUsuario'); App.modalLoading('modalUsuario', true);
    App.api('../includes/usuario_get.php?id=' + id)
        .then(function (d) {
            d = d.data; var sel = form.elements.nivel;
            if (d.nivel && !sel.querySelector('option[value="' + d.nivel + '"]')) { var o = document.createElement('option'); o.value = d.nivel; o.textContent = 'Nível ' + d.nivel; sel.appendChild(o); }
            App.fillForm(form, d); atualizarAjuda();
        })
        .catch(function (e) { App.toast(e.message, 'error'); App.closeModal('modalUsuario'); })
        .finally(function () { App.modalLoading('modalUsuario', false); });
}
function excluir(id, nome, btn) {
    App.action({ title: 'Excluir ' + nome + '?', message: 'O acesso será removido permanentemente. Se o usuário tiver registros no sistema, prefira marcá-lo como inativo.',
                 confirmText: 'Excluir usuário', url: '../includes/usuario_delete.php?id=' + id, button: btn });
}
form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (form.elements.nivel.value === '1' && !form.elements.setor_id.value) { App.toast('Usuários de setor precisam ter um setor definido.', 'error'); form.elements.setor_id.focus(); return; }
    App.submitForm(form, { url: '../includes/usuario_save.php', modal: 'modalUsuario' });
});
</script>
JS
]);
