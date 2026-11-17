<?php
require_once '../includes/layout.php';
require_once '../includes/romaneios_lista.php';
require_level(NIVEL_ARQUIVO);

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

layout_start('Gerenciar romaneios', [
    'active'   => 'romaneio-conf',
    'subtitle' => user_level() >= NIVEL_ADMIN
        ? 'Aprove entradas, registre devoluções e acompanhe prazos de todos os setores.'
        : 'Registre devoluções e acompanhe prazos de todos os setores. A aprovação de entradas é feita por administradores.',
    'actions'  => '<a class="btn btn-secondary" href="' . e(export_url()) . '"><i class="fa-solid fa-file-csv"></i>Exportar</a>'
                . '<button type="button" class="btn btn-primary" id="btnNovo"><i class="fa-solid fa-plus"></i>Novo romaneio</button>',
    'head'     => rom_styles(),
]);
?>
<div class="quick-filters mb-2">
    <?= rom_qf('', 'Todos', $cnt['total'] ?? 0, $f['status']) ?>
    <?= rom_qf('Pendente', 'Pendentes', $cnt['pendentes'] ?? 0, $f['status']) ?>
    <?= rom_qf('Atrasado', 'Atrasados', $cnt['atrasados'] ?? 0, $f['status']) ?>
    <?= rom_qf('Vencendo', 'Vencem em até 3 dias', $cnt['vencendo'] ?? 0, $f['status']) ?>
    <?= rom_qf('Aguardando', 'Aguardando aprovação', $cnt['aguardando'] ?? 0, $f['status']) ?>
    <?= rom_qf('Concluído', 'Concluídos', $cnt['concluidos'] ?? 0, $f['status']) ?>
</div>

<section class="panel">
    <form method="GET" class="filters">
        <?php if ($f['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
        <div class="field field-search">
            <label for="f-busca">Procurar</label>
            <div class="input-icon"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="f-busca" name="busca" placeholder="Nº, solicitante, descrição ou nº da caixa" value="<?= e($f['busca']) ?>">
            </div>
        </div>
        <div class="field">
            <label for="f-setor">Setor</label>
            <select id="f-setor" name="setor"><?= setor_options($setores, $f['setor'], 'Todos os setores') ?></select>
        </div>
        <div class="field">
            <label for="f-tipo">Tipo</label>
            <select id="f-tipo" name="tipo">
                <option value="">Todos</option>
                <option value="Saída" <?= $f['tipo'] === 'Saída' ? 'selected' : '' ?>>Saída</option>
                <option value="Entrada" <?= $f['tipo'] === 'Entrada' ? 'selected' : '' ?>>Entrada</option>
            </select>
        </div>
        <div class="field">
            <label for="f-di">De</label>
            <input type="date" id="f-di" name="data_inicio" value="<?= e($f['data_inicio']) ?>">
        </div>
        <div class="field">
            <label for="f-df">Até</label>
            <input type="date" id="f-df" name="data_fim" value="<?= e($f['data_fim']) ?>">
        </div>
        <div class="filters-actions">
            <a class="btn btn-ghost" href="romaneio-conf.php">Limpar</a>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i>Filtrar</button>
        </div>
    </form>

    <?php if (!$romaneios): ?>
        <?= empty_state('Nenhum romaneio encontrado', 'Ajuste os filtros ou registre um novo romaneio.', 'fa-clipboard-check', '<a class="btn btn-secondary" href="romaneio-conf.php">Limpar filtros</a>') ?>
    <?php else: ?>
        <?= rom_table($romaneios, true) ?>
    <?php endif; ?>
    <?= render_pagination($page, $total_pages, $total_records, $per_page) ?>
</section>

<?php
rom_modais($setores, $caixas, true);
layout_end(['scripts' => rom_script_config(true)]);
