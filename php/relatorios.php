<?php
require_once '../includes/layout.php';
require_once '../includes/solicitacoes_lib.php';
require_level(NIVEL_ARQUIVO);

$dataInicio = valid_date($_GET['data_inicio'] ?? '') ? $_GET['data_inicio'] : '';
$dataFim    = valid_date($_GET['data_fim'] ?? '') ? $_GET['data_fim'] : '';
$setorId    = ctype_digit((string) ($_GET['setor_id'] ?? '')) ? (int) $_GET['setor_id'] : 0;
// Período invertido (início depois do fim): troca as datas em vez de mostrar tudo vazio
if ($dataInicio && $dataFim && $dataInicio > $dataFim) {
    list($dataInicio, $dataFim) = [$dataFim, $dataInicio];
}
$erroRelatorio = false;

/* Filtros parametrizados (antes os valores eram concatenados no SQL) */
function periodo_sql($col, $ini, $fim, array &$p, $prefix)
{
    $sql = '';
    if ($ini) { $sql .= " AND $col >= :{$prefix}_ini"; $p[":{$prefix}_ini"] = $ini . ' 00:00:00'; }
    if ($fim) { $sql .= " AND $col <= :{$prefix}_fim"; $p[":{$prefix}_fim"] = $fim . ' 23:59:59'; }
    return $sql;
}
function run($sql, array $p = [])
{
    global $pdo;
    try { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(); }
    catch (PDOException $e) { $GLOBALS['erroRelatorio'] = true; rd_log_erro('Relatórios: ' . $e->getMessage()); return []; }
}

// Solicitações
$pS = [];
$wS = ' WHERE ' . ($dataInicio || $dataFim ? 's.data_solicitacao IS NOT NULL' : '1') . periodo_sql('s.data_solicitacao', $dataInicio, $dataFim, $pS, 's');
if ($setorId) { $wS .= ' AND s.setor_id = :s_setor'; $pS[':s_setor'] = $setorId; }

$resumo = run("SELECT COUNT(*) total, SUM(s.status = 'Respondida') respondidas, SUM((s.status IS NULL OR s.status <> 'Respondida')) pendentes,
                SUM((s.status IS NULL OR s.status <> 'Respondida') AND s.prazo < NOW()) atrasadas, SUM(s.resultado = 'Realizada' AND s.status = 'Respondida') realizadas,
                SUM(s.status = 'Respondida' AND s.prazo IS NOT NULL AND s.data_resposta <= s.prazo) no_prazo,
                SUM(s.status = 'Respondida' AND s.prazo IS NOT NULL) com_prazo,
                0 media_min
               FROM solicitacoes s" . $wS, $pS);
$resumo = $resumo ? $resumo[0] : [];

$porMes = run("SELECT DATE_FORMAT(s.data_solicitacao, '%Y-%m') ym, COUNT(*) recebidas, SUM(s.status = 'Respondida') respondidas,
                SUM(s.status = 'Respondida' AND s.resultado = 'Realizada') realizada,
                SUM(s.status = 'Respondida' AND s.resultado = 'Não Realizada') nao_realizada,
                SUM(s.status = 'Respondida' AND s.resultado = 'Não Consta') nao_consta
               FROM solicitacoes s" . $wS . " AND s.data_solicitacao IS NOT NULL GROUP BY DATE_FORMAT(s.data_solicitacao, '%Y-%m') ORDER BY ym", $pS);

// Tempo médio de resposta em horário de atendimento (seg–sex, 8h–18h, sem as pausas do recebimento)
$temposPorMes = [];
$temposTodos = run("SELECT DATE_FORMAT(s.data_solicitacao, '%Y-%m') ym, s.data_solicitacao, s.data_resposta FROM solicitacoes s" . $wS
    . " AND s.data_solicitacao IS NOT NULL AND s.data_resposta IS NOT NULL", $pS);
foreach ($temposTodos as $t) $temposPorMes[$t['ym']][] = $t;
$resumo['media_min'] = tempo_atendimento_media($temposTodos);
foreach ($porMes as &$m) $m['media_min'] = isset($temposPorMes[$m['ym']]) ? tempo_atendimento_media($temposPorMes[$m['ym']]) : null;
unset($m);

$porResultado = [];
foreach (run("SELECT s.resultado, COUNT(*) n FROM solicitacoes s" . $wS . " AND s.status = 'Respondida' GROUP BY s.resultado", $pS) as $r) {
    $k = ($r['resultado'] === null || $r['resultado'] === '') ? 'Sem resultado' : (string) $r['resultado'];
    $porResultado[] = ['resultado' => $k, 'n' => (int) $r['n']];
}
usort($porResultado, function ($a, $b) { return $b['n'] - $a['n']; });

$porSetor = run("SELECT COALESCE(se.nome, 'Sem setor') setor, COUNT(*) total, SUM((s.status IS NULL OR s.status <> 'Respondida')) pendentes
                 FROM solicitacoes s LEFT JOIN setores se ON se.id = s.setor_id" . $wS . " GROUP BY COALESCE(se.nome, 'Sem setor') ORDER BY total DESC", $pS);

// Romaneios
$pR = [];
$wR = ' WHERE r.data_saida IS NOT NULL' . periodo_sql('r.data_saida', $dataInicio, $dataFim, $pR, 'r');
if ($setorId) { $wR .= ' AND r.setor_id = :r_setor'; $pR[':r_setor'] = $setorId; }
$romMes = run("SELECT DATE_FORMAT(r.data_saida, '%Y-%m') ym, SUM(r.tipo = 'Saída') saidas, SUM(r.tipo = 'Entrada') entradas
               FROM romaneios r" . $wR . " GROUP BY DATE_FORMAT(r.data_saida, '%Y-%m') ORDER BY ym", $pR);
$romResumo = run("SELECT COUNT(*) total, SUM(r.tipo = 'Saída' AND (r.status = 'Pendente' OR r.status IS NULL OR r.status = '') AND r.data_retorno < CURDATE()) atrasados FROM romaneios r" . $wR, $pR);
$romResumo = $romResumo ? $romResumo[0] : ['total' => 0, 'atrasados' => 0];

// Acervo (estado atual; filtra por setor quando informado)
$pC = [];
$wC = '';
if ($setorId) { $wC = ' WHERE c.setor_id = :c_setor'; $pC[':c_setor'] = $setorId; }
$caixasSetor = run("SELECT COALESCE(s.nome, 'Sem setor') setor, SUM(c.numero = '-' OR c.numero = '' OR c.numero IS NULL) sem_etiqueta,
                    SUM(NOT (c.numero = '-' OR c.numero = '' OR c.numero IS NULL)) com_etiqueta
                    FROM caixas c LEFT JOIN setores s ON c.setor_id = s.id" . $wC . " GROUP BY COALESCE(s.nome, 'Sem setor') ORDER BY setor", $pC);

$pP = [];
$wP = ' WHERE p.data_digitalizacao IS NOT NULL' . periodo_sql('p.data_digitalizacao', $dataInicio, $dataFim, $pP, 'p');
if ($setorId) { $wP .= ' AND l.setor = :p_setor'; $pP[':p_setor'] = $setorId; }
$paginasMes = run("SELECT DATE_FORMAT(p.data_digitalizacao, '%Y-%m') ym, COUNT(*) paginas, COUNT(DISTINCT p.livro_id) livros
                   FROM paginas p LEFT JOIN livros l ON l.id = p.livro_id" . $wP . " GROUP BY DATE_FORMAT(p.data_digitalizacao, '%Y-%m') ORDER BY ym", $pP);

/* Exportação CSV da tabela mensal */
if (($_GET['export'] ?? '') === 'csv') {
    $rows = [];
    foreach ($porMes as $r) {
        $rows[] = [mes_label($r['ym']), $r['recebidas'], $r['respondidas'], $r['realizada'], $r['nao_realizada'], $r['nao_consta'], $r['media_min'] !== null ? round($r['media_min']) : ''];
    }
    csv_download('relatorio_solicitacoes_' . date('Y-m-d') . '.csv', ['Mês', 'Recebidas', 'Respondidas', 'Realizadas', 'Não encontradas', 'Não está no Arquivo', 'Tempo médio de resposta (min em horário de atendimento)'], $rows);
}

function tempo_fmt($min)
{
    return tempo_atendimento_fmt($min);
}

$total = (int) ($resumo['total'] ?? 0);
$resp  = (int) ($resumo['respondidas'] ?? 0);
$taxaResp   = $total ? round($resp / $total * 100) : 0;
$taxaReal   = $resp ? round(($resumo['realizadas'] ?? 0) / $resp * 100) : 0;
$taxaPrazo  = !empty($resumo['com_prazo']) ? round($resumo['no_prazo'] / $resumo['com_prazo'] * 100) : null;

$periodoTxt = ($dataInicio || $dataFim)
    ? 'Período: ' . ($dataInicio ? fmt_date($dataInicio) : 'início') . ' a ' . ($dataFim ? fmt_date($dataFim) : 'hoje')
    : 'Todo o período registrado';
if ($setorId) $periodoTxt .= ' · Setor: ' . (setor_nome($setorId) ?? '—');

$preset = function ($label, $ini, $fim) use ($dataInicio, $dataFim, $setorId) {
    $q = ['data_inicio' => $ini, 'data_fim' => $fim];
    if ($setorId) $q['setor_id'] = $setorId;
    $active = $dataInicio === $ini && $dataFim === $fim;
    return '<a class="qf' . ($active ? ' is-active' : '') . '" href="?' . e(http_build_query(array_filter($q))) . '">' . e($label) . '</a>';
};

$chartData = [
    'meses'      => array_map('mes_label', array_column($porMes, 'ym')),
    'recebidas'  => array_map('intval', array_column($porMes, 'recebidas')),
    'respondidas'=> array_map('intval', array_column($porMes, 'respondidas')),
    'tempo'      => array_map(function ($v) { return $v === null ? null : round($v / 60, 1); }, array_column($porMes, 'media_min')),
    'resultado'  => ['labels' => array_map(function ($r) { return SOLIC_RESULTADOS[$r] ?? $r; }, array_column($porResultado, 'resultado')), 'raw' => array_column($porResultado, 'resultado'), 'n' => array_map('intval', array_column($porResultado, 'n'))],
    'setor'      => ['labels' => array_column($porSetor, 'setor'), 'n' => array_map('intval', array_column($porSetor, 'total'))],
    'romMeses'   => array_map('mes_label', array_column($romMes, 'ym')),
    'saidas'     => array_map('intval', array_column($romMes, 'saidas')),
    'entradas'   => array_map('intval', array_column($romMes, 'entradas')),
    'caixas'     => ['labels' => array_column($caixasSetor, 'setor'), 'com' => array_map('intval', array_column($caixasSetor, 'com_etiqueta')), 'sem' => array_map('intval', array_column($caixasSetor, 'sem_etiqueta'))],
    'pagMeses'   => array_map('mes_label', array_column($paginasMes, 'ym')),
    'paginas'    => array_map('intval', array_column($paginasMes, 'paginas')),
];

layout_start('Relatórios', [
    'active'   => 'relatorios',
    'subtitle' => e($periodoTxt),
    'actions'  => '<a class="btn btn-secondary" href="' . e(export_url()) . '"><i class="fa-solid fa-file-csv"></i>Exportar tabela</a>'
                . '<button type="button" class="btn btn-primary" onclick="window.print()"><i class="fa-solid fa-print"></i>Imprimir / PDF</button>',
    'head'     => '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
.chart-box { position: relative; height: 280px; }
.grid-2 > .panel:last-child:nth-child(odd) { grid-column: 1 / -1; }
.chart-box.is-tall { height: 340px; }
.chart-legend { display: flex; flex-wrap: wrap; gap: 14px; font-size: 13.5px; color: var(--text-2); margin-bottom: 10px; }
.chart-legend span { display: inline-flex; align-items: center; gap: 7px; }
.chart-legend i { width: 12px; height: 12px; border-radius: 3px; display: inline-block; }
.chart-empty { height: 100%; display: grid; place-items: center; color: var(--text-3); font-size: 14px; text-align: center; }
.report-presets { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 16px; }
@media print { .chart-box { height: 260px; } .report-presets { display: none; } .grid-2 { grid-template-columns: 1fr 1fr; } .panel { break-inside: avoid; } }
</style>',
]);
?>
<?php if ($erroRelatorio): ?>
<div class="alert alert-error mb-2"><i class="fa-solid fa-circle-exclamation"></i><span>Alguns dados do relatório não puderam ser carregados. O erro foi registrado em Sistema e e-mail › Registro de erros.</span></div>
<?php endif; ?>
<div class="report-presets">
    <?= $preset('Este mês', date('Y-m-01'), date('Y-m-d')) ?>
    <?= $preset('Últimos 3 meses', date('Y-m-d', strtotime('-3 months')), date('Y-m-d')) ?>
    <?= $preset('Últimos 12 meses', date('Y-m-d', strtotime('-12 months')), date('Y-m-d')) ?>
    <?= $preset('Este ano', date('Y-01-01'), date('Y-m-d')) ?>
    <?= $preset('Todo o período', '', '') ?>
</div>

<section class="panel mb-2">
    <form method="GET" class="filters" style="border-radius: var(--radius-lg)">
        <div class="field"><label for="r-ini">De</label><input type="date" id="r-ini" name="data_inicio" value="<?= e($dataInicio) ?>"></div>
        <div class="field"><label for="r-fim">Até</label><input type="date" id="r-fim" name="data_fim" value="<?= e($dataFim) ?>"></div>
        <div class="field"><label for="r-setor">Setor</label><select id="r-setor" name="setor_id"><?= setor_options(all_setores(), $setorId ?: '', 'Todos os setores') ?></select></div>
        <div class="filters-actions">
            <a class="btn btn-ghost" href="relatorios.php">Limpar</a>
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i>Aplicar</button>
        </div>
    </form>
</section>

<div class="kpis">
    <div class="kpi"><div class="kpi-label"><i class="fa-solid fa-inbox"></i>Solicitações recebidas</div><div class="kpi-value"><?= fmt_num($total) ?></div><div class="kpi-note"><?= fmt_num($resumo['pendentes'] ?? 0) ?> pendentes<?= !empty($resumo['atrasadas']) ? ' · <span class="text-danger">' . fmt_num($resumo['atrasadas']) . ' atrasadas</span>' : '' ?></div></div>
    <div class="kpi is-success"><div class="kpi-label"><i class="fa-solid fa-reply"></i>Taxa de resposta</div><div class="kpi-value"><?= $taxaResp ?>%</div><div class="kpi-note"><?= fmt_num($resp) ?> respondidas<?= $taxaPrazo !== null ? ' · ' . $taxaPrazo . '% dentro do prazo' : '' ?></div></div>
    <div class="kpi is-info"><div class="kpi-label"><i class="fa-solid fa-stopwatch"></i>Tempo médio de resposta</div><div class="kpi-value"><?= e(tempo_fmt($resumo['media_min'] ?? null)) ?></div><div class="kpi-note" title="Conta só de segunda a sexta, das 8h às 18h, e não conta os períodos em que o recebimento de solicitações estava pausado.">Seg–sex, 8h–18h, sem pausas</div></div>
    <div class="kpi is-warning"><div class="kpi-label"><i class="fa-solid fa-bullseye"></i>Documentos localizados</div><div class="kpi-value"><?= $taxaReal ?>%</div><div class="kpi-note">das solicitações respondidas</div></div>
    <div class="kpi"><div class="kpi-label"><i class="fa-solid fa-truck-ramp-box"></i>Romaneios</div><div class="kpi-value"><?= fmt_num($romResumo['total']) ?></div><div class="kpi-note"><?= !empty($romResumo['atrasados']) ? '<span class="text-danger">' . fmt_num($romResumo['atrasados']) . ' com devolução atrasada</span>' : 'Nenhum atrasado' ?></div></div>
</div>

<div class="grid grid-2">
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title">Solicitações por mês</h2></div>
        <div class="panel-body">
            <div class="chart-legend"><span><i style="background:var(--c1)"></i>Recebidas</span><span><i style="background:var(--c2)"></i>Respondidas</span></div>
            <div class="chart-box"><canvas id="chMes" aria-label="Solicitações recebidas e respondidas por mês" role="img"></canvas></div>
        </div>
    </section>
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title">Resultado das solicitações respondidas</h2></div>
        <div class="panel-body"><div class="chart-box"><canvas id="chRes" aria-label="Resultado das solicitações" role="img"></canvas></div></div>
    </section>
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title">Tempo médio de resposta (horas de atendimento)</h2></div>
        <div class="panel-body"><div class="chart-box"><canvas id="chTempo" aria-label="Tempo médio de resposta por mês" role="img"></canvas></div></div>
    </section>
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title">Solicitações por setor</h2></div>
        <div class="panel-body"><div class="chart-box is-tall"><canvas id="chSetor" aria-label="Solicitações por setor" role="img"></canvas></div></div>
    </section>
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title">Romaneios por mês</h2></div>
        <div class="panel-body">
            <div class="chart-legend"><span><i style="background:var(--c1)"></i>Saídas</span><span><i style="background:var(--c3)"></i>Entradas</span></div>
            <div class="chart-box"><canvas id="chRom" aria-label="Romaneios por mês" role="img"></canvas></div>
        </div>
    </section>
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title">Caixas com e sem etiqueta por setor</h2><span class="small muted">situação atual</span></div>
        <div class="panel-body">
            <div class="chart-legend"><span><i style="background:var(--c1)"></i>Com etiqueta</span><span><i style="background:var(--c2)"></i>Sem etiqueta</span></div>
            <div class="chart-box is-tall"><canvas id="chCaixas" aria-label="Caixas com e sem etiqueta por setor" role="img"></canvas></div>
        </div>
    </section>
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title">Páginas de livros digitalizadas por mês</h2></div>
        <div class="panel-body"><div class="chart-box"><canvas id="chPag" aria-label="Páginas digitalizadas por mês" role="img"></canvas></div></div>
    </section>
</div>

<section class="panel mt-2">
    <div class="panel-head"><h2 class="panel-title">Tabela mensal de solicitações</h2><a class="btn btn-ghost btn-sm" href="<?= e(export_url()) ?>"><i class="fa-solid fa-file-csv"></i>Exportar CSV</a></div>
    <?php if (!$porMes): ?>
        <?= empty_state('Sem dados no período', 'Escolha outro período ou remova o filtro de setor.', 'fa-chart-column') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table responsive">
            <thead><tr><th>Mês</th><th class="text-right">Recebidas</th><th class="text-right">Respondidas</th><th class="text-right">Realizadas</th><th class="text-right">Não encontradas</th><th class="text-right">Não está no Arquivo</th><th class="text-right">Tempo médio</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($porMes) as $r): ?>
                <tr>
                    <td class="col-primary strong" data-label="Mês"><?= e(mes_label($r['ym'])) ?></td>
                    <td class="text-right" data-label="Recebidas"><?= fmt_num($r['recebidas']) ?></td>
                    <td class="text-right" data-label="Respondidas"><?= fmt_num($r['respondidas']) ?></td>
                    <td class="text-right" data-label="Realizadas"><?= fmt_num($r['realizada']) ?></td>
                    <td class="text-right" data-label="Não encontradas"><?= fmt_num($r['nao_realizada']) ?></td>
                    <td class="text-right" data-label="Não está no Arquivo"><?= fmt_num($r['nao_consta']) ?></td>
                    <td class="text-right" data-label="Tempo médio"><?= e(tempo_fmt($r['media_min'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php
$json = json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
layout_end(['scripts' => <<<JS
<script>
(function () {
    var D = {$json};
    var charts = [];
    function palette() {
        var dark = document.documentElement.getAttribute('data-theme') === 'dark';
        // Paleta categórica validada (contraste e daltonismo) para cada tema
        var c = dark ? ['#5A86CC', '#B5801A', '#2F9E7E', '#A574BE'] : ['#2C5FA8', '#B07A10', '#16856A', '#9A5BB0'];
        var cs = getComputedStyle(document.documentElement);
        return { c: c, text: cs.getPropertyValue('--text-2').trim(), grid: cs.getPropertyValue('--border').trim(), surface: cs.getPropertyValue('--surface').trim(),
                 good: cs.getPropertyValue('--success').trim(), warn: '#B07A10', bad: cs.getPropertyValue('--danger').trim(), info: cs.getPropertyValue('--info').trim() };
    }
    function setVars(p) { var r = document.documentElement.style; r.setProperty('--c1', p.c[0]); r.setProperty('--c2', p.c[1]); r.setProperty('--c3', p.c[2]); }
    function empty(id, msg) { var cv = document.getElementById(id); if (cv) cv.parentNode.innerHTML = '<div class="chart-empty">' + (msg || 'Sem dados para o período selecionado.') + '</div>'; }
    function base(p, extra) {
        var o = {
            responsive: true, maintainAspectRatio: false, animation: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { display: false }, tooltip: { backgroundColor: '#14213D', padding: 10, titleFont: { weight: '600' } } },
            scales: {
                x: { grid: { display: false }, ticks: { color: p.text }, border: { color: p.grid } },
                y: { beginAtZero: true, grid: { color: p.grid }, ticks: { color: p.text, precision: 0 }, border: { display: false } }
            }
        };
        return Object.assign(o, extra || {});
    }
    function bar(color, data, label, p) { return { label: label, data: data, backgroundColor: color, borderRadius: 4, borderSkipped: 'start', borderColor: p.surface, borderWidth: { top: 0, left: 0, right: 2, bottom: 0 }, maxBarThickness: 34 }; }
    function line(color, data, label) { return { label: label, data: data, borderColor: color, backgroundColor: color, borderWidth: 2, pointRadius: 4, pointHoverRadius: 6, tension: .25, spanGaps: true }; }

    function draw() {
        charts.forEach(function (c) { c.destroy(); }); charts = [];
        if (!window.Chart) { ['chMes','chRes','chTempo','chSetor','chRom','chCaixas','chPag'].forEach(function (id) { empty(id, 'Não foi possível carregar a biblioteca de gráficos. Verifique a conexão com a internet.'); }); return; }
        var p = palette(); setVars(p);
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        var mk = function (id, cfg) { var el = document.getElementById(id); if (el) charts.push(new Chart(el, cfg)); };

        if (D.meses.length) mk('chMes', { type: 'line', data: { labels: D.meses, datasets: [line(p.c[0], D.recebidas, 'Recebidas'), line(p.c[1], D.respondidas, 'Respondidas')] }, options: base(p) }); else empty('chMes');

        if (D.resultado.n.length) {
            var cor = { 'Realizada': p.good, 'Não Consta': p.warn, 'Não Realizada': p.bad, 'Dados Insuficientes': p.info };
            mk('chRes', { type: 'bar', data: { labels: D.resultado.labels, datasets: [{ label: 'Solicitações', data: D.resultado.n, backgroundColor: D.resultado.raw.map(function (r) { return cor[r] || p.c[3]; }), borderRadius: 4, maxBarThickness: 30 }] },
                options: base(p, { indexAxis: 'y', interaction: { mode: 'nearest', intersect: true }, scales: { x: { beginAtZero: true, grid: { color: p.grid }, ticks: { color: p.text, precision: 0 }, border: { display: false } }, y: { grid: { display: false }, ticks: { color: p.text } } } }) });
        } else empty('chRes');

        if (D.tempo.some(function (v) { return v !== null; })) mk('chTempo', { type: 'line', data: { labels: D.meses, datasets: [line(p.c[0], D.tempo, 'Horas')] },
            options: base(p, { plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return ' ' + String(c.parsed.y).replace('.', ',') + ' h'; } } } } }) }); else empty('chTempo');

        if (D.setor.n.length) mk('chSetor', { type: 'bar', data: { labels: D.setor.labels, datasets: [bar(p.c[0], D.setor.n, 'Solicitações', p)] },
            options: base(p, { indexAxis: 'y', interaction: { mode: 'nearest', intersect: true }, scales: { x: { beginAtZero: true, grid: { color: p.grid }, ticks: { color: p.text, precision: 0 }, border: { display: false } }, y: { grid: { display: false }, ticks: { color: p.text } } } }) }); else empty('chSetor');

        if (D.romMeses.length) mk('chRom', { type: 'bar', data: { labels: D.romMeses, datasets: [bar(p.c[0], D.saidas, 'Saídas', p), bar(p.c[2], D.entradas, 'Entradas', p)] }, options: base(p) }); else empty('chRom');

        if (D.caixas.labels.length) mk('chCaixas', { type: 'bar', data: { labels: D.caixas.labels, datasets: [bar(p.c[0], D.caixas.com, 'Com etiqueta', p), bar(p.c[1], D.caixas.sem, 'Sem etiqueta', p)] },
            options: base(p, { indexAxis: 'y', scales: { x: { stacked: true, beginAtZero: true, grid: { color: p.grid }, ticks: { color: p.text, precision: 0 }, border: { display: false } }, y: { stacked: true, grid: { display: false }, ticks: { color: p.text } } } }) }); else empty('chCaixas');

        if (D.pagMeses.length) mk('chPag', { type: 'bar', data: { labels: D.pagMeses, datasets: [bar(p.c[0], D.paginas, 'Páginas', p)] }, options: base(p) }); else empty('chPag');

    }
    draw();
    document.addEventListener('theme:changed', draw);
    window.addEventListener('beforeprint', function () { charts.forEach(function (c) { c.resize(); }); });
})();
</script>
JS
]);
