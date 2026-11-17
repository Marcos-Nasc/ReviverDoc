<?php
require_once '../includes/layout.php';
require_once '../includes/romaneios_lista.php';
require_login();

$f = rom_filters();
if (($_GET['export'] ?? '') === 'csv') {
    rom_csv($f);
}
$page = current_page();
$per_page = 20;
$total_records = 0;
$romaneios = rom_fetch_page($f, $page, $per_page, $total_records);
$total_pages = (int) ceil($total_records / $per_page);
$cnt = rom_counts($f);
$setores = all_setores();
$caixas = []; // a saída agora é feita por descrição, sem escolher caixas
$filtrando = $f['busca'] !== '' || $f['tipo'] !== '' || $f['status'] !== '' || $f['data_inicio'] !== '' || $f['data_fim'] !== '' || $f['setor'] !== '';

$actions = '<a class="btn btn-secondary" href="' . e(export_url()) . '"><i class="fa-solid fa-file-csv"></i>Exportar</a>';
if (user_level() >= NIVEL_ARQUIVO) {
    $actions .= '<a class="btn btn-secondary" href="romaneio-conf.php"><i class="fa-solid fa-clipboard-check"></i>Gerenciar</a>';
}
$actions .= '<button type="button" class="btn btn-primary" id="btnNovo"><i class="fa-solid fa-plus"></i>Novo romaneio</button>';

layout_start('Romaneios', [
    'active'   => 'romaneios',
    'subtitle' => user_level() == NIVEL_SETOR
        ? 'Retiradas e entradas de caixas do seu setor no Arquivo Geral.'
        : 'Retiradas e entradas de caixas no Arquivo Geral.',
    'actions'  => $actions,
    'head'     => rom_styles(),
]);
?>
<div class="quick-filters mb-2">
    <?= rom_qf('', 'Todos', $cnt['total'] ?? 0, $f['status']) ?>
    <?= rom_qf('Pendente', 'Pendentes', $cnt['pendentes'] ?? 0, $f['status']) ?>
    <?= rom_qf('Atrasado', 'Atrasados', $cnt['atrasados'] ?? 0, $f['status']) ?>
    <?= rom_qf('Aguardando', 'Aguardando aprovação', $cnt['aguardando'] ?? 0, $f['status']) ?>
    <?= rom_qf('Concluído', 'Concluídos', $cnt['concluidos'] ?? 0, $f['status']) ?>
</div>

<section class="panel">
    <form method="GET" class="filters">
        <?php if ($f['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
        <div class="field field-search">
            <label for="f-busca">Procurar</label>
            <div class="input-icon"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="f-busca" name="busca" placeholder="Nº do romaneio, solicitante, descrição ou nº da caixa" value="<?= e($f['busca']) ?>">
            </div>
        </div>
        <div class="field">
            <label for="f-tipo">Tipo</label>
            <select id="f-tipo" name="tipo">
                <option value="">Todos os tipos</option>
                <option value="Saída" <?= $f['tipo'] === 'Saída' ? 'selected' : '' ?>>Saída</option>
                <option value="Entrada" <?= $f['tipo'] === 'Entrada' ? 'selected' : '' ?>>Entrada</option>
            </select>
        </div>
        <?php if (user_level() >= NIVEL_ARQUIVO): ?>
        <div class="field">
            <label for="f-setor">Setor</label>
            <select id="f-setor" name="setor"><?= setor_options($setores, $f['setor'], 'Todos os setores') ?></select>
        </div>
        <?php endif; ?>
        <div class="field">
            <label for="f-di">De</label>
            <input type="date" id="f-di" name="data_inicio" value="<?= e($f['data_inicio']) ?>">
        </div>
        <div class="field">
            <label for="f-df">Até</label>
            <input type="date" id="f-df" name="data_fim" value="<?= e($f['data_fim']) ?>">
        </div>
        <div class="filters-actions">
            <a class="btn btn-ghost" href="romaneios.php">Limpar</a>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i>Filtrar</button>
        </div>
    </form>

    <?php if (!$romaneios): ?>
        <?= $filtrando
            ? empty_state('Nenhum romaneio encontrado', 'Revise os filtros ou limpe a busca para ver todos os romaneios.', 'fa-truck-ramp-box', '<a class="btn btn-secondary" href="romaneios.php">Limpar filtros</a>')
            : empty_state('Nenhum romaneio registrado', 'Registre uma saída do arquivo descrevendo o que precisa, ou envie novas caixas para entrada.', 'fa-truck-ramp-box', '<button type="button" class="btn btn-primary" data-new-rom><i class="fa-solid fa-plus"></i>Novo romaneio</button>') ?>
    <?php else: ?>
        <?= rom_table($romaneios, false) ?>
    <?php endif; ?>
    <?= render_pagination($page, $total_pages, $total_records, $per_page) ?>
</section>

<?php
rom_modais($setores, $caixas, false);
layout_end(['scripts' => rom_script_config(false)
    . '<script>document.addEventListener("click",function(e){if(e.target.closest("[data-new-rom]"))document.getElementById("btnNovo").click();});</script>']);
