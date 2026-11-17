<?php
require_once '../includes/layout.php';
require_level(NIVEL_ARQUIVO);
$admin = user_level() >= NIVEL_ADMIN;
$cards = [
    ['caixas-conf.php', 'fa-boxes-stacked', 'Caixas', 'Cadastrar, editar e excluir caixas do arquivo'],
    ['livros-conf.php', 'fa-book-bookmark', 'Livros', 'Gerenciar livros cadastrados'],
    ['paginas.php', 'fa-copy', 'Páginas', 'Enviar e organizar páginas digitalizadas dos livros'],
    ['documentos-conf.php', 'fa-file-arrow-up', 'Documentos', 'Enviar e organizar documentos digitalizados'],
    ['romaneio-conf.php', 'fa-clipboard-check', 'Romaneios', 'Concluir, aprovar e excluir romaneios'],
    ['solicitacoes-conf.php', 'fa-list-check', 'Solicitações', 'Editar e excluir solicitações de pesquisa'],
    ['setores.php', 'fa-sitemap', 'Setores', 'Setores usados em todo o sistema'],
];
if ($admin) {
    $cards = array_merge([
        ['usuarios.php', 'fa-users', 'Usuários', 'Contas de acesso, perfis e senhas'],
    ], $cards, [
        ['historico.php', 'fa-clock-rotate-left', 'Histórico de atividades', 'Quem fez o quê e quando'],
        ['sistema-conf.php', 'fa-sliders', 'Sistema e e-mail', 'E-mail de notificação e servidor SMTP'],
        ['../includes/backup.php', 'fa-download', 'Backup da plataforma', 'Baixar banco de dados e arquivos enviados (.zip)'],
    ]);
}
layout_start('Configurações', ['active' => 'configuracoes', 'subtitle' => 'Atalhos para as áreas de cadastro e administração.']);
?>
<div class="hub">
    <?php foreach ($cards as $c): ?>
        <?php if (strpos($c[0], 'backup') !== false): ?>
            <form method="POST" action="<?= e($c[0]) ?>" style="display:contents">
                <?= csrf_field() ?>
                <button type="submit" class="hub-card" data-backup style="text-align:left;cursor:pointer;font:inherit;color:inherit">
                    <span class="hub-icon"><i class="fa-solid <?= e($c[1]) ?>"></i></span>
                    <span><strong><?= e($c[2]) ?></strong><span><?= e($c[3]) ?></span></span>
                </button>
            </form>
        <?php else: ?>
        <a class="hub-card" href="<?= e($c[0]) ?>">
            <span class="hub-icon"><i class="fa-solid <?= e($c[1]) ?>"></i></span>
            <span><strong><?= e($c[2]) ?></strong><span><?= e($c[3]) ?></span></span>
        </a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php layout_end(['scripts' => <<<'JS'
<script>
var b = document.querySelector('[data-backup]');
if (b) b.addEventListener('click', function () { App.toast('Gerando o backup. O download começará em instantes — pode levar alguns minutos.', 'info', 8000); });
</script>
JS
]);
