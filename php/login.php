<?php
require_once '../includes/bootstrap.php';
if (is_logged_in()) {
    redirect_to(user_level() >= NIVEL_ARQUIVO ? '../index.php' : 'solicitacoes.php');
}
$erro = (string) ($_GET['erro'] ?? '');
$mensagens = [
    '1'         => 'E-mail ou senha incorretos. Confira os dados e tente novamente.',
    'inativo'   => 'Seu acesso está desativado. Procure o administrador do sistema.',
    'expirado'  => 'A página ficou aberta por muito tempo. Entre novamente.',
    'bloqueado' => 'Muitas tentativas sem sucesso. Aguarde 5 minutos e tente novamente.',
];
$msg = $mensagens[$erro] ?? '';
$email = (string) ($_GET['email'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrar · ReviverDoc</title>
    <link rel="icon" href="../images/LOGO_ATHENA_ICON.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/app.css?v=<?= RD_VERSION ?>">
    <link rel="stylesheet" href="../css/login.css?v=<?= RD_VERSION ?>">
    <script>try{var t=localStorage.getItem('rd-theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
</head>
<body class="login-page">
    <aside class="login-aside" aria-hidden="true">
        <div class="login-brand">
            <img src="../images/LOGO_ATHENA_ICON.png" alt="">
            <span>ReviverDoc</span>
        </div>
        <div class="login-pitch">
            <h2>O arquivo geral, organizado e à mão.</h2>
            <ul>
                <li><i class="fa-solid fa-box-archive"></i>Localize caixas, livros e documentos digitalizados em segundos.</li>
                <li><i class="fa-solid fa-magnifying-glass"></i>Acompanhe solicitações de pesquisa e converse com o Arquivo.</li>
                <li><i class="fa-solid fa-truck-ramp-box"></i>Controle saídas e devoluções com romaneios e alertas de prazo.</li>
            </ul>
        </div>
        <div class="login-shelf">
            <?php for ($i = 0; $i < 9; $i++): ?><span></span><?php endfor; ?>
        </div>
    </aside>

    <main class="login-main">
        <form class="login-card" action="../includes/login_handler.php" method="POST" id="loginForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= e((string) ($_GET['next'] ?? '')) ?>">
            <img class="login-logo-mobile" src="../images/LOGO_ATHENA_ICON.png" alt="">
            <h1>Entrar</h1>
            <p class="login-lead">Use o e-mail e a senha fornecidos pelo administrador.</p>

            <?php if ($msg): ?>
                <div class="alert alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($msg) ?></span></div>
            <?php elseif (isset($_GET['saiu'])): ?>
                <div class="alert alert-success" role="status"><i class="fa-solid fa-circle-check"></i><span>Você saiu do sistema com segurança.</span></div>
            <?php endif; ?>

            <div class="field">
                <label for="email">E-mail</label>
                <div class="input-icon"><i class="fa-regular fa-envelope"></i>
                    <input type="email" id="email" name="email" required autocomplete="username" value="<?= e($email) ?>" <?= $email ? '' : 'autofocus' ?>>
                </div>
            </div>
            <div class="field">
                <label for="password">Senha</label>
                <div class="input-icon password-wrap"><i class="fa-solid fa-lock"></i>
                    <input type="password" id="password" name="password" required autocomplete="current-password" <?= $email ? 'autofocus' : '' ?>>
                    <button type="button" class="pw-toggle" id="btn-eye" aria-label="Mostrar senha"><i class="fa-regular fa-eye"></i></button>
                </div>
                <span class="help" id="caps" hidden><i class="fa-solid fa-triangle-exclamation"></i> Caps Lock está ativado.</span>
            </div>
            <button type="submit" class="btn btn-primary btn-block login-submit">Entrar</button>
            <p class="login-foot">Esqueceu a senha? Solicite a redefinição ao administrador do sistema.</p>
        </form>
    </main>
<script>
(function () {
    var form = document.getElementById('loginForm');
    var pw = document.getElementById('password');
    var eye = document.getElementById('btn-eye');
    eye.addEventListener('click', function () {
        var show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        eye.querySelector('i').className = show ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
        eye.setAttribute('aria-label', show ? 'Ocultar senha' : 'Mostrar senha');
        pw.focus();
    });
    pw.addEventListener('keyup', function (e) {
        if (e.getModifierState) document.getElementById('caps').hidden = !e.getModifierState('CapsLock');
    });
    form.addEventListener('submit', function (e) {
        if (!form.reportValidity()) { e.preventDefault(); return; }
        var b = form.querySelector('.login-submit');
        b.disabled = true; b.classList.add('is-loading');
    });
})();
</script>
</body>
</html>
