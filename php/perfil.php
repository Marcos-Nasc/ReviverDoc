<?php
require_once '../includes/layout.php';
require_login();
$st = $pdo->prepare('SELECT u.nome, u.email, u.nivel, u.status, s.nome AS setor FROM usuarios u LEFT JOIN setores s ON s.id = u.setor_id WHERE u.id = ?');
$st->execute([user_id()]);
$u = $st->fetch();
layout_start('Meu perfil', ['active' => 'perfil', 'subtitle' => 'Seus dados de acesso. Para alterar nome, e-mail ou setor, fale com o administrador.']);
?>
<div class="grid grid-2" style="max-width:1000px">
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title">Dados da conta</h2></div>
        <div class="panel-body">
            <dl class="dl">
                <dt>Nome</dt><dd><?= e($u['nome']) ?></dd>
                <dt>E-mail</dt><dd><?= e($u['email']) ?></dd>
                <dt>Setor</dt><dd><?= e($u['setor'] ?: '—') ?></dd>
                <dt>Perfil</dt><dd><?= e(nivel_label($u['nivel'])) ?></dd>
            </dl>
        </div>
    </section>
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title">Alterar senha</h2></div>
        <form id="formSenha" class="panel-body stack" novalidate autocomplete="off">
            <div class="field"><label for="p-atual">Senha atual<span class="req">*</span></label><input type="password" id="p-atual" name="senha_atual" required autocomplete="current-password"></div>
            <div class="field"><label for="p-nova">Nova senha<span class="req">*</span></label><input type="password" id="p-nova" name="senha_nova" required minlength="6" autocomplete="new-password"><span class="help">Pelo menos 6 caracteres. Evite usar a mesma senha de outros sistemas.</span></div>
            <div class="field"><label for="p-conf">Confirme a nova senha<span class="req">*</span></label><input type="password" id="p-conf" name="senha_confirma" required minlength="6" autocomplete="new-password"></div>
            <div><button type="submit" class="btn btn-primary"><i class="fa-solid fa-key"></i>Alterar senha</button></div>
        </form>
    </section>
</div>
<?php layout_end(['scripts' => <<<'JS'
<script>
var f = document.getElementById('formSenha');
f.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (!f.reportValidity()) return;
    if (f.senha_nova.value !== f.senha_confirma.value) { App.toast('A confirmação não confere com a nova senha.', 'error'); f.senha_confirma.focus(); return; }
    App.submitForm(f, { url: '../includes/perfil_save.php', reload: false, onSuccess: function () { f.reset(); } });
});
</script>
JS
]);
