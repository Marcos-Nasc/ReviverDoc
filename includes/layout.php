<?php
/**
 * Layout padrão das páginas autenticadas: <head>, menu lateral, barra superior e rodapé de scripts.
 *
 * Uso:
 *   layout_start('Caixas', ['active' => 'caixas', 'subtitle' => '...', 'actions' => '<button ...>']);
 *   ...conteúdo...
 *   layout_end(['scripts' => '<script>...</script>']);
 */
require_once __DIR__ . '/bootstrap.php';

function nav_items()
{
    $n = user_level();
    $groups = [];
    $groups[] = ['label' => null, 'items' => [
        ['key' => 'inicio', 'href' => 'index.php', 'icon' => 'fa-gauge-high', 'label' => 'Painel'],
    ]];
    if ($n >= NIVEL_ARQUIVO) {
        $groups[] = ['label' => 'Acervo', 'items' => [
            ['key' => 'caixas', 'href' => 'php/caixas.php', 'icon' => 'fa-box-archive', 'label' => 'Caixas'],
            ['key' => 'livros', 'href' => 'php/livros.php', 'icon' => 'fa-book', 'label' => 'Livros'],
            ['key' => 'documentos', 'href' => 'php/documentos.php', 'icon' => 'fa-file-lines', 'label' => 'Documentos'],
        ]];
    }
    $groups[] = ['label' => 'Atendimento', 'items' => [
        ['key' => 'solicitacoes', 'href' => 'php/solicitacoes.php', 'icon' => 'fa-magnifying-glass', 'label' => 'Solicitações de pesquisa'],
        ['key' => 'romaneios', 'href' => 'php/romaneios.php', 'icon' => 'fa-truck-ramp-box', 'label' => 'Romaneios'],
    ]];
    if ($n >= NIVEL_ARQUIVO) {
        $groups[] = ['label' => 'Gestão', 'items' => [
            ['key' => 'relatorios', 'href' => 'php/relatorios.php', 'icon' => 'fa-chart-column', 'label' => 'Relatórios'],
        ]];
        $admin = [
            ['key' => 'configuracoes', 'href' => 'php/configuracoes.php', 'icon' => 'fa-gear', 'label' => 'Visão geral'],
            ['key' => 'caixas-conf', 'href' => 'php/caixas-conf.php', 'icon' => 'fa-boxes-stacked', 'label' => 'Gerenciar caixas'],
            ['key' => 'livros-conf', 'href' => 'php/livros-conf.php', 'icon' => 'fa-book-bookmark', 'label' => 'Gerenciar livros'],
            ['key' => 'paginas', 'href' => 'php/paginas.php', 'icon' => 'fa-copy', 'label' => 'Gerenciar páginas'],
            ['key' => 'documentos-conf', 'href' => 'php/documentos-conf.php', 'icon' => 'fa-file-arrow-up', 'label' => 'Gerenciar documentos'],
            ['key' => 'romaneio-conf', 'href' => 'php/romaneio-conf.php', 'icon' => 'fa-clipboard-check', 'label' => 'Gerenciar romaneios'],
            ['key' => 'solicitacoes-conf', 'href' => 'php/solicitacoes-conf.php', 'icon' => 'fa-list-check', 'label' => 'Gerenciar solicitações'],
            ['key' => 'setores', 'href' => 'php/setores.php', 'icon' => 'fa-sitemap', 'label' => 'Setores'],
        ];
        $groups[] = ['label' => 'Cadastros', 'collapsible' => true, 'items' => $admin];
        if ($n >= NIVEL_ADMIN) {
            $groups[] = ['label' => 'Administração', 'collapsible' => true, 'items' => [
                ['key' => 'usuarios', 'href' => 'php/usuarios.php', 'icon' => 'fa-users', 'label' => 'Usuários'],
                ['key' => 'historico', 'href' => 'php/historico.php', 'icon' => 'fa-clock-rotate-left', 'label' => 'Histórico de atividades'],
                ['key' => 'sistema', 'href' => 'php/sistema-conf.php', 'icon' => 'fa-sliders', 'label' => 'Sistema e e-mail'],
            ]];
        }
    }
    return $groups;
}

function run_daily_jobs()
{
    global $pdo;
    $marcadores = [__DIR__ . '/last_romaneio_check.txt', __DIR__ . '/../backup_temp/last_romaneio_check.txt'];
    $today = date('Y-m-d');
    foreach ($marcadores as $m) {
        if (is_file($m) && trim((string) @file_get_contents($m)) === $today) {
            return; // já executada hoje
        }
    }
    // Marca antes de executar para que acessos simultâneos não repitam a rotina;
    // se a pasta includes/ não aceitar gravação, usa backup_temp/
    $marcado = null;
    foreach ($marcadores as $m) {
        if (@file_put_contents($m, $today) !== false) { $marcado = $m; break; }
    }
    if (!$marcado) {
        return; // sem onde registrar: não executa a cada página
    }
    try {
        if (!defined('RD_INTERNAL_JOB')) {
            define('RD_INTERNAL_JOB', true);
        }
        include_once __DIR__ . '/notificar_romaneios_vencendo.php';
    } catch (Exception $e) {
        @file_put_contents($marcado, ''); // tenta de novo no próximo acesso
        rd_log_erro('Rotina diária de romaneios falhou: ' . $e->getMessage());
    }
}

function layout_start($title, array $opts = [])
{
    global $pdo;
    $base     = app_base();
    $active   = $opts['active'] ?? '';
    $subtitle = $opts['subtitle'] ?? '';
    $actions  = $opts['actions'] ?? '';
    $back     = $opts['back'] ?? null; // ['href' => ..., 'label' => ...]

    run_daily_jobs();

    $notifications = [];
    $unread = 0;
    try {
        $st = $pdo->prepare('SELECT id, mensagem, link, data_criacao FROM notificacoes WHERE usuario_id = ? AND lida = 0 ORDER BY data_criacao DESC LIMIT 8');
        $st->execute([user_id()]);
        $notifications = $st->fetchAll();
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM notificacoes WHERE usuario_id = ? AND lida = 0');
        $cnt->execute([user_id()]);
        $unread = (int) $cnt->fetchColumn();
    } catch (Exception $e) {
    }
    $GLOBALS['unread_count'] = $unread;

    // Letreiro de avisos gerais (tabela chat_avisos_geral)
    $avisos = [];
    $temAvisos = table_exists('chat_avisos_geral');
    if ($temAvisos) {
        try {
            $st = $pdo->query('SELECT a.id, a.mensagem, a.data_envio, u.nome FROM chat_avisos_geral a
                               LEFT JOIN usuarios u ON u.id = a.usuario_id ORDER BY a.id DESC LIMIT 8');
            $avisos = $st->fetchAll();
        } catch (Exception $e) {
        }
    }
    $ultimoAviso = $avisos ? (int) $avisos[0]['id'] : 0;

    $nome  = $_SESSION['nome'] ?? 'Usuário';
    $parts = preg_split('/\s+/', trim($nome));
    $initials = mb_strtoupper(mb_substr($parts[0] ?? 'U', 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
    $flash = flash_pop();
    ?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="app-base" content="<?= e($base) ?>">
    <title><?= e($title) ?> · ReviverDoc</title>
    <link rel="icon" href="<?= $base ?>images/LOGO_ATHENA_ICON.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= $base ?>css/app.css?v=<?= RD_VERSION ?>">
    <script>try{var t=localStorage.getItem('rd-theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <?= $opts['head'] ?? '' ?>
</head>
<body class="app<?= !empty($opts['body_class']) ? ' ' . e($opts['body_class']) : '' ?>">
<a class="skip-link" href="#conteudo">Ir para o conteúdo</a>
<aside class="sidebar" id="sidebar" aria-label="Menu principal">
    <div class="sidebar-brand">
        <a href="<?= $base ?>index.php" class="brand-link" aria-label="Página inicial">
            <img src="<?= $base ?>images/LOGO_ATHENA_ICON.png" alt="" class="brand-mark">
            <span class="brand-text"><strong>ReviverDoc</strong><small>Gestão do Arquivo</small></span>
        </a>
        <button class="icon-btn sidebar-close" type="button" data-sidebar-close aria-label="Fechar menu"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <nav class="nav">
        <?php foreach (nav_items() as $gi => $group):
            $hasActive = false;
            foreach ($group['items'] as $it) { if ($it['key'] === $active) { $hasActive = true; } }
            $collapsible = !empty($group['collapsible']);
            ?>
            <div class="nav-group<?= $collapsible ? ' is-collapsible' : '' ?><?= ($collapsible && !$hasActive) ? ' is-collapsed' : '' ?>" data-group="<?= (int) $gi ?>">
                <?php if ($group['label']): ?>
                    <?php if ($collapsible): ?>
                        <button type="button" class="nav-label nav-toggle" aria-expanded="<?= $hasActive ? 'true' : 'false' ?>"><?= e($group['label']) ?><i class="fa-solid fa-chevron-down"></i></button>
                    <?php else: ?>
                        <div class="nav-label"><?= e($group['label']) ?></div>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="nav-items">
                <?php foreach ($group['items'] as $it): ?>
                    <a href="<?= $base . $it['href'] ?>" class="nav-link<?= $it['key'] === $active ? ' is-active' : '' ?>"<?= $it['key'] === $active ? ' aria-current="page"' : '' ?>>
                        <i class="fa-solid <?= $it['icon'] ?>"></i><span><?= e($it['label']) ?></span>
                    </a>
                <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
        <span class="sidebar-version">ReviverDoc <?= RD_VERSION ?></span>
    </div>
</aside>
<div class="sidebar-backdrop" data-sidebar-close></div>

<div class="shell">
    <header class="topbar">
        <button class="icon-btn menu-btn" type="button" data-sidebar-open aria-label="Abrir menu"><i class="fa-solid fa-bars"></i></button>
        <div class="search" data-search>
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
            <input type="search" class="search-input" placeholder="<?= user_level() >= NIVEL_ARQUIVO ? 'Buscar caixa, livro, documento, solicitação…' : 'Buscar solicitação ou romaneio…' ?>" aria-label="Busca rápida" autocomplete="off">
            <kbd class="search-kbd">Ctrl K</kbd>
            <div class="search-results" role="listbox" hidden></div>
        </div>
        <div class="topbar-actions">
            <button class="icon-btn search-mobile-btn" type="button" data-search-open aria-label="Buscar"><i class="fa-solid fa-magnifying-glass"></i></button>
            <button class="icon-btn" type="button" data-theme-toggle aria-label="Alternar tema claro/escuro" title="Alternar tema"><i class="fa-solid fa-circle-half-stroke"></i></button>
            <div class="dropdown" data-dropdown>
                <button class="icon-btn" type="button" data-dropdown-toggle aria-label="Notificações" aria-haspopup="true">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($unread > 0): ?><span class="dot-count"><?= $unread > 99 ? '99+' : $unread ?></span><?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-notif" role="menu">
                    <div class="dropdown-head">
                        <strong>Notificações</strong>
                        <?php if ($unread > 0): ?><button type="button" class="link-btn" data-notif-read-all>Marcar todas como lidas</button><?php endif; ?>
                    </div>
                    <?php if (!$notifications): ?>
                        <div class="dropdown-empty"><i class="fa-regular fa-bell-slash"></i>Você está em dia. Nenhuma notificação nova.</div>
                    <?php else: foreach ($notifications as $n): ?>
                        <a class="notif-item" href="<?= $base ?>includes/marcar_notificacao_lida.php?id=<?= (int) $n['id'] ?>&amp;redirect=<?= urlencode($base . ($n['link'] ?: 'index.php')) ?>">
                            <span class="notif-msg"><?= e($n['mensagem']) ?></span>
                            <span class="notif-time"><?= e(rel_time($n['data_criacao'])) ?></span>
                        </a>
                    <?php endforeach; endif; ?>
                    <a class="dropdown-foot" href="<?= $base ?>php/notificacoes.php">Ver todas as notificações</a>
                </div>
            </div>
            <div class="dropdown" data-dropdown>
                <button class="user-btn" type="button" data-dropdown-toggle aria-haspopup="true">
                    <span class="avatar"><?= e($initials) ?></span>
                    <span class="user-meta"><strong><?= e($nome) ?></strong><small><?= e(nivel_label(user_level())) ?></small></span>
                    <i class="fa-solid fa-chevron-down user-caret"></i>
                </button>
                <div class="dropdown-menu" role="menu">
                    <div class="dropdown-user">
                        <strong><?= e($nome) ?></strong>
                        <small><?= e($_SESSION['email'] ?? '') ?></small>
                        <?php if (user_setor()): ?><small>Setor: <?= e(setor_nome(user_setor()) ?? '—') ?></small><?php endif; ?>
                    </div>
                    <a class="dropdown-item" href="<?= $base ?>php/perfil.php"><i class="fa-regular fa-user"></i>Meu perfil e senha</a>
                    <a class="dropdown-item" href="<?= $base ?>php/notificacoes.php"><i class="fa-regular fa-bell"></i>Notificações</a>
                    <a class="dropdown-item is-danger" href="<?= $base ?>includes/logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i>Sair</a>
                </div>
            </div>
        </div>
    </header>
    <?php if ($temAvisos): ?>
    <div class="ticker" data-ticker data-ultimo="<?= $ultimoAviso ?>">
        <button type="button" class="ticker-label" data-avisos-open aria-label="Abrir mural de avisos">
            <i class="fa-solid fa-bullhorn"></i><span>Avisos</span><span class="ticker-dot" hidden></span>
        </button>
        <div class="ticker-viewport" data-avisos-open role="button" tabindex="0" aria-label="Avisos gerais — clique para ver todos e escrever">
            <div class="ticker-track">
                <?php if (!$avisos): ?>
                    <span class="ticker-item ticker-empty">Nenhum aviso no momento. Clique aqui para escrever o primeiro.</span>
                <?php else: foreach ($avisos as $a): ?>
                    <span class="ticker-item"><strong><?= e($a['nome'] ?: 'Usuário removido') ?>:</strong> <?= e(html_entity_decode((string) $a['mensagem'], ENT_QUOTES, 'UTF-8')) ?> <small><?= e(rel_time($a['data_envio'])) ?></small></span>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <button type="button" class="ticker-write btn btn-sm btn-secondary" data-avisos-open data-avisos-focus><i class="fa-regular fa-pen-to-square"></i><span>Escrever aviso</span></button>
    </div>
    <?php endif; ?>

    <main class="main" id="conteudo" tabindex="-1">
        <div class="page-head">
            <div class="page-head-text">
                <?php if ($back): ?>
                    <a class="back-link" href="<?= e($back['href']) ?>"><i class="fa-solid fa-arrow-left"></i><?= e($back['label']) ?></a>
                <?php endif; ?>
                <h1 class="page-title"><?= e($title) ?></h1>
                <?php if ($subtitle): ?><p class="page-subtitle"><?= $subtitle ?></p><?php endif; ?>
            </div>
            <?php if ($actions): ?><div class="page-actions"><?= $actions ?></div><?php endif; ?>
        </div>
        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>" role="status"><i class="fa-solid fa-circle-info"></i><span><?= e($flash['text']) ?></span></div>
        <?php endif; ?>
<?php
}

function layout_end(array $opts = [])
{
    $base = app_base();
    ?>
    </main>
</div>
<div class="toast-stack" aria-live="polite" aria-atomic="false"></div>
<?php if (table_exists('chat_avisos_geral')): ?>
<div class="modal modal-lg" id="modalAvisos" role="dialog" aria-modal="true" aria-labelledby="avisos-title">
    <div class="modal-dialog">
        <div class="modal-header">
            <div><h2 class="modal-title" id="avisos-title"><i class="fa-solid fa-bullhorn" style="color:var(--brass-500)"></i> Mural de avisos</h2>
            <p class="modal-sub">Recados para todos os usuários. As mensagens aparecem no letreiro do topo.</p></div>
            <button class="icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="chat-box" id="avisos-lista"><div class="loading-block"><div class="spinner"></div>Carregando avisos…</div></div>
            <form class="chat-form" id="avisos-form" novalidate>
                <textarea name="mensagem" maxlength="500" placeholder="Escreva um aviso para todos… (Enter envia, Shift+Enter quebra linha)" aria-label="Mensagem do aviso"></textarea>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i><span class="sr-only">Publicar</span></button>
            </form>
            <p class="help mt-1">Até 500 caracteres. Você pode remover os avisos que publicou<?= user_level() >= NIVEL_ADMIN ? '; como administrador, pode remover qualquer aviso' : '' ?>.</p>
        </div>
    </div>
</div>
<?php endif; ?>
<script src="<?= $base ?>script/app.js?v=<?= RD_VERSION ?>"></script>
<?= $opts['scripts'] ?? '' ?>
</body>
</html>
<?php
}

/** Bloco padrão de estado vazio. */
function empty_state($title, $text = '', $icon = 'fa-folder-open', $action = '')
{
    return '<div class="empty"><div class="empty-icon"><i class="fa-solid ' . e($icon) . '"></i></div>'
        . '<h3>' . e($title) . '</h3>' . ($text ? '<p>' . e($text) . '</p>' : '') . $action . '</div>';
}
