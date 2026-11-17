<?php
require_once 'includes/layout.php';
require_once 'includes/solicitacoes_lib.php';
require_login();

$nivel = user_level();
$setor = user_setor();
$isStaff = $nivel >= NIVEL_ARQUIVO;

/* Filtro de setor para usuários de setor (nível 1) */
$fs = $isStaff ? '' : ' AND s.setor_id = ' . (int) $setor;
$fr = $isStaff ? '' : ' AND r.setor_id = ' . (int) $setor;

$q = function ($sql) use ($pdo) {
    try { return $pdo->query($sql); } catch (PDOException $e) { error_log('Painel: ' . $e->getMessage()); return null; }
};
$val = function ($sql) use ($q) { $st = $q($sql); return $st ? (int) $st->fetchColumn() : 0; };

// Solicitações
$solPend     = $val("SELECT COUNT(*) FROM solicitacoes s WHERE (s.status IS NULL OR s.status <> 'Respondida')" . $fs);
$solAtraso   = $val("SELECT COUNT(*) FROM solicitacoes s WHERE (s.status IS NULL OR s.status <> 'Respondida') AND s.prazo IS NOT NULL AND s.prazo < NOW()" . $fs);
$solHoje     = $val("SELECT COUNT(*) FROM solicitacoes s WHERE (s.status IS NULL OR s.status <> 'Respondida') AND DATE(s.prazo) = CURDATE() AND s.prazo >= NOW()" . $fs);
$solMes      = $val("SELECT COUNT(*) FROM solicitacoes s WHERE s.data_solicitacao >= DATE_FORMAT(CURDATE(), '%Y-%m-01')" . $fs);
$respMes     = $val("SELECT COUNT(*) FROM solicitacoes s WHERE s.status = 'Respondida' AND s.data_resposta >= DATE_FORMAT(CURDATE(), '%Y-%m-01')" . $fs);
// Tempo médio de resposta nos últimos 90 dias, em horário de atendimento (seg–sex, 8h–18h, sem pausas)
$tempoMedio  = $q("SELECT s.data_solicitacao, s.data_resposta FROM solicitacoes s
                   WHERE s.data_resposta IS NOT NULL AND s.data_solicitacao IS NOT NULL AND s.data_resposta >= CURDATE() - INTERVAL 90 DAY" . $fs);
$tempoMedio  = $tempoMedio ? tempo_atendimento_media($tempoMedio->fetchAll()) : null;

// Romaneios
$romPend   = $val("SELECT COUNT(*) FROM romaneios r WHERE r.tipo = 'Saída' AND (r.status = 'Pendente' OR r.status IS NULL OR r.status = '')" . $fr);
$romAtraso = $val("SELECT COUNT(*) FROM romaneios r WHERE r.tipo = 'Saída' AND (r.status = 'Pendente' OR r.status IS NULL OR r.status = '') AND r.data_retorno < CURDATE()" . $fr);
$romAprov  = $val("SELECT COUNT(*) FROM romaneios r WHERE r.tipo = 'Entrada' AND (r.aprovado IS NULL OR r.aprovado <> 'Sim')" . $fr);

if ($isStaff) {
    $acervo = $q("SELECT COUNT(*) total, SUM(status = 'Fora do Arquivo') fora, SUM(higienizada = 'Não') nh,
                  SUM(numero = '-' OR numero IS NULL OR numero = '') sem_etiqueta FROM caixas");
    $acervo = $acervo ? $acervo->fetch() : ['total' => 0, 'fora' => 0, 'nh' => 0, 'sem_etiqueta' => 0];
    $livros = $q("SELECT COUNT(*) total, SUM(digitalizado = 'Sim') dig FROM livros");
    $livros = $livros ? $livros->fetch() : ['total' => 0, 'dig' => 0];
    $qtdPaginas = $val('SELECT COUNT(*) FROM paginas');
    $qtdDocs    = $val('SELECT COUNT(*) FROM documentos');
    $pctLivros  = $livros['total'] ? round($livros['dig'] / $livros['total'] * 100) : 0;
    $pctHig     = $acervo['total'] ? round(($acervo['total'] - $acervo['nh']) / $acervo['total'] * 100) : 0;
    $pctEtiq    = $acervo['total'] ? round(($acervo['total'] - $acervo['sem_etiqueta']) / $acervo['total'] * 100) : 0;
}

// Fila de solicitações pendentes (mais urgentes primeiro)
$st = $q("SELECT s.id, s.tipo, s.prazo, s.data_solicitacao, se.nome AS setor_nome
          FROM solicitacoes s LEFT JOIN setores se ON se.id = s.setor_id
          WHERE (s.status IS NULL OR s.status <> 'Respondida')" . $fs . "
          ORDER BY (s.prazo IS NULL), s.prazo ASC LIMIT 7");
$filaSol = $st ? $st->fetchAll() : [];

// Romaneios a devolver
$st = $q("SELECT r.id, r.data_saida, r.data_retorno, se.nome AS setor_nome, u.nome AS usuario,
                 (SELECT COUNT(*) FROM romaneio_caixas rc WHERE rc.romaneio_id = r.id) AS qtd
          FROM romaneios r LEFT JOIN setores se ON se.id = r.setor_id LEFT JOIN usuarios u ON u.id = r.usuario_id
          WHERE r.tipo = 'Saída' AND (r.status = 'Pendente' OR r.status IS NULL OR r.status = '')" . $fr . "
          ORDER BY (r.data_retorno IS NULL), r.data_retorno ASC LIMIT 6");
$filaRom = $st ? $st->fetchAll() : [];

if ($isStaff) {
    $st = $q("SELECT c.id, c.numero, c.conteudo, c.data_inicio, c.data_fim, c.observacao, c.higienizada, s.nome AS setor
              FROM caixas c LEFT JOIN setores s ON c.setor_id = s.id ORDER BY c.id DESC LIMIT 5");
    $caixasRecentes = $st ? $st->fetchAll() : [];
    $st = $q("SELECT l.id, l.numero_livro, s.nome AS nome_setor, l.data_inicio, l.data_fim, l.digitalizado
              FROM livros l LEFT JOIN setores s ON l.setor = s.id ORDER BY l.id DESC LIMIT 5");
    $livrosRecentes = $st ? $st->fetchAll() : [];
    $st = $q("SELECT resultado, COUNT(*) n FROM solicitacoes WHERE status = 'Respondida'
              AND data_resposta >= DATE_FORMAT(CURDATE(), '%Y-%m-01') GROUP BY resultado");
    $resMes = [];
    if ($st) foreach ($st as $r) $resMes[$r['resultado'] ?: 'Sem resultado'] = (int) $r['n'];
} else {
    $st = $q("SELECT s.id, s.tipo, s.resultado, s.data_resposta FROM solicitacoes s
              WHERE s.status = 'Respondida' AND s.setor_id = " . (int) $setor . " ORDER BY s.data_resposta DESC LIMIT 5");
    $respondidas = $st ? $st->fetchAll() : [];
}

function tempo_legivel($min)
{
    return tempo_atendimento_fmt($min);
}
function prazo_badge($prazo)
{
    if (!$prazo) return '<span class="badge no-dot">Sem prazo</span>';
    $ts = strtotime($prazo);
    if ($ts < time()) return '<span class="badge badge-danger">Atrasada · ' . e(fmt_date($prazo, 'd/m')) . '</span>';
    if (date('Y-m-d', $ts) === date('Y-m-d')) return '<span class="badge badge-warning">Vence hoje ' . e(date('H:i', $ts)) . '</span>';
    if ($ts < strtotime('+2 days')) return '<span class="badge badge-warning">Vence ' . e(fmt_date($prazo, 'd/m')) . '</span>';
    return '<span class="badge no-dot">Prazo ' . e(fmt_date($prazo, 'd/m')) . '</span>';
}

$hora = (int) date('H');
$saud = $hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite');
$primeiro = explode(' ', trim($_SESSION['nome'] ?? ''))[0];
$dias = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
$meses = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$hoje = $dias[(int) date('w')] . ', ' . date('j') . ' de ' . $meses[(int) date('n')];

$recebAberto = solicitacoes_recebimento()['aberto'];
$acoes = ($recebAberto || $isStaff
            ? '<a class="btn btn-secondary" href="php/solicitacoes.php?nova=1"><i class="fa-solid fa-plus"></i>Nova solicitação</a>'
            : '<button type="button" class="btn btn-secondary" disabled title="O Arquivo pausou o recebimento de novas solicitações"><i class="fa-solid fa-plus"></i>Nova solicitação</button>')
       . '<a class="btn btn-primary" href="php/romaneios.php?novo=1"><i class="fa-solid fa-truck-ramp-box"></i>Novo romaneio</a>';

layout_start($saud . ($primeiro ? ', ' . $primeiro : ''), [
    'active'   => 'inicio',
    'subtitle' => 'Hoje é ' . e($hoje) . '. ' . ($isStaff ? 'Veja o que precisa de atenção no arquivo.' : 'Acompanhe as solicitações e romaneios do seu setor.'),
    'actions'  => $acoes,
]);
?>
<?= solicitacoes_pausa_html($isStaff) ?>
<?php if ($nivel == NIVEL_SETOR && !user_setor()): ?>
<div class="alert alert-warning mb-2"><i class="fa-solid fa-triangle-exclamation"></i><span>Seu usuário não está vinculado a nenhum setor, por isso você ainda não consegue abrir solicitações nem romaneios. Peça a um administrador para definir o seu setor.</span></div>
<?php endif; ?>
<div class="kpis">
    <a class="kpi <?= $solAtraso ? 'is-danger' : 'is-warning' ?>" href="php/solicitacoes.php?situacao=pendentes">
        <div class="kpi-label"><i class="fa-solid fa-magnifying-glass"></i>Solicitações pendentes</div>
        <div class="kpi-value"><?= fmt_num($solPend) ?></div>
        <div class="kpi-note"><?= $solAtraso ? '<span class="text-danger">' . fmt_num($solAtraso) . ' com prazo vencido</span>' : 'Nenhuma atrasada' ?><?= $solHoje ? ' · ' . fmt_num($solHoje) . ' vencem hoje' : '' ?></div>
    </a>
    <a class="kpi <?= $romAtraso ? 'is-danger' : 'is-info' ?>" href="php/romaneios.php?status=Pendente">
        <div class="kpi-label"><i class="fa-solid fa-truck-ramp-box"></i>Romaneios a devolver</div>
        <div class="kpi-value"><?= fmt_num($romPend) ?></div>
        <div class="kpi-note"><?= $romAtraso ? '<span class="text-danger">' . fmt_num($romAtraso) . ' em atraso</span>' : 'Todos dentro do prazo' ?></div>
    </a>
    <a class="kpi <?= $romAprov ? 'is-warning' : '' ?>" href="php/<?= $isStaff ? 'romaneio-conf.php' : 'romaneios.php' ?>?status=Aguardando">
        <div class="kpi-label"><i class="fa-solid fa-clipboard-check"></i>Entradas aguardando aprovação</div>
        <div class="kpi-value"><?= fmt_num($romAprov) ?></div>
        <div class="kpi-note"><?= $romAprov ? ($nivel >= NIVEL_ADMIN ? 'Revise e aprove as novas caixas' : 'Aguardando o administrador') : 'Nada pendente' ?></div>
    </a>
    <?php if ($isStaff): ?>
    <a class="kpi is-success" href="php/solicitacoes-conf.php">
        <div class="kpi-label"><i class="fa-solid fa-gauge"></i>Tempo médio de resposta</div>
        <div class="kpi-value"><?= e(tempo_legivel($tempoMedio)) ?></div>
        <div class="kpi-note" title="Seg–sex, 8h–18h, sem os períodos com o recebimento pausado">Últimos 90 dias, em horário de atendimento</div>
    </a>
    <?php else: ?>
    <div class="kpi is-success">
        <div class="kpi-label"><i class="fa-solid fa-calendar-check"></i>Solicitações neste mês</div>
        <div class="kpi-value"><?= fmt_num($solMes) ?></div>
        <div class="kpi-note"><?= fmt_num($respMes) ?> respondidas · resposta média <?= e(tempo_legivel($tempoMedio)) ?></div>
    </div>
    <?php endif; ?>
</div>

<div class="grid grid-dash">
    <div class="stack">
        <section class="panel">
            <div class="panel-head">
                <h2 class="panel-title">Solicitações que precisam de atenção <span class="count"><?= fmt_num($solPend) ?></span></h2>
                <a class="btn btn-ghost btn-sm" href="php/solicitacoes.php?status=Pendente">Ver todas</a>
            </div>
            <?php if (!$filaSol): ?>
                <?= empty_state('Nenhuma solicitação pendente', $isStaff ? 'Todas as solicitações de pesquisa foram respondidas.' : 'Precisa de um documento do arquivo? Abra uma nova solicitação.', 'fa-circle-check', $isStaff ? '' : ($recebAberto ? '<a class="btn btn-primary" href="php/solicitacoes.php?nova=1">Nova solicitação</a>' : '')) ?>
            <?php else: ?>
                <ul class="list">
                    <?php foreach ($filaSol as $s): ?>
                        <li><a class="list-row" href="php/solicitacoes.php?id=<?= (int) $s['id'] ?>">
                            <span class="tag-id">#<?= e(fmt_id($s['id'], 3)) ?></span>
                            <span class="list-main">
                                <span class="list-title"><?= e($s['tipo'] ?: 'Solicitação') ?></span>
                                <span class="list-sub"><?= e($s['setor_nome'] ?: '—') ?> · aberta <?= e(rel_time($s['data_solicitacao'])) ?></span>
                            </span>
                            <span class="list-side"><?= prazo_badge($s['prazo']) ?></span>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php if ($isStaff): ?>
        <div class="grid grid-2">
            <section class="panel">
                <div class="panel-head">
                    <h2 class="panel-title">Caixas recentes</h2>
                    <a class="btn btn-ghost btn-sm" href="php/caixas.php">Ver mais</a>
                </div>
                <?php if (!$caixasRecentes): ?>
                    <?= empty_state('Nenhuma caixa cadastrada', '', 'fa-box-open') ?>
                <?php else: ?>
                <ul class="list">
                    <?php foreach ($caixasRecentes as $c):
                        $sem = in_array(trim((string) $c['numero']), ['', '-'], true); ?>
                        <li><a class="list-row" href="php/caixas.php?busca=<?= urlencode($sem ? (string) $c['conteudo'] : (string) $c['numero']) ?>">
                            <span class="tag-id<?= $sem ? ' is-muted' : '' ?>"><?= $sem ? 'Sem nº' : e($c['numero']) ?></span>
                            <span class="list-main">
                                <span class="list-title"><?= e($c['conteudo']) ?></span>
                                <span class="list-sub"><?= e($c['setor']) ?> · <?= e(fmt_periodo($c['data_inicio'], $c['data_fim'])) ?></span>
                            </span>
                            <?php $obs = strtoupper(trim((string) $c['observacao'])); ?>
                            <?php if ($c['higienizada'] === 'Não'): ?><span class="badge badge-warning">N/H</span><?php endif; ?>
                            <?php if (strpos($obs, 'CX1') !== false): ?><span class="badge badge-brass">CX1</span><?php elseif (strpos($obs, 'CX2') !== false): ?><span class="badge badge-brass">CX2</span><?php endif; ?>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>
            <section class="panel">
                <div class="panel-head">
                    <h2 class="panel-title">Livros recentes</h2>
                    <a class="btn btn-ghost btn-sm" href="php/livros.php">Ver mais</a>
                </div>
                <?php if (!$livrosRecentes): ?>
                    <?= empty_state('Nenhum livro cadastrado', '', 'fa-book') ?>
                <?php else: ?>
                <ul class="list">
                    <?php foreach ($livrosRecentes as $l): ?>
                        <li><a class="list-row" href="php/livros.php?busca=<?= urlencode((string) $l['numero_livro']) ?>">
                            <span class="tag-id"><small>Livro</small><?= e($l['numero_livro']) ?></span>
                            <span class="list-main">
                                <span class="list-title"><?= e($l['nome_setor'] ?: '—') ?></span>
                                <span class="list-sub"><?= e(fmt_periodo($l['data_inicio'], $l['data_fim'])) ?></span>
                            </span>
                            <?= $l['digitalizado'] === 'Sim' ? '<span class="badge badge-success">Digitalizado</span>' : '<span class="badge badge-warning">Pendente</span>' ?>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>
        </div>
        <?php else: ?>
        <section class="panel">
            <div class="panel-head">
                <h2 class="panel-title">Respondidas recentemente</h2>
                <a class="btn btn-ghost btn-sm" href="php/solicitacoes.php?status=Respondida">Ver todas</a>
            </div>
            <?php if (!$respondidas): ?>
                <?= empty_state('Nenhuma resposta ainda', 'As respostas do Arquivo às suas solicitações aparecerão aqui.', 'fa-inbox') ?>
            <?php else: ?>
            <ul class="list">
                <?php foreach ($respondidas as $r):
                    $cls = $r['resultado'] === 'Realizada' ? 'badge-success' : ($r['resultado'] === 'Não Consta' ? 'badge-warning' : 'badge-danger');
                    $lbl = SOLIC_RESULTADOS[$r['resultado']] ?? ($r['resultado'] ?: '—'); ?>
                    <li><a class="list-row" href="php/solicitacoes.php?id=<?= (int) $r['id'] ?>">
                        <span class="tag-id">#<?= e(fmt_id($r['id'], 3)) ?></span>
                        <span class="list-main"><span class="list-title"><?= e($r['tipo']) ?></span><span class="list-sub">Respondida <?= e(rel_time($r['data_resposta'])) ?></span></span>
                        <span class="badge <?= $cls ?>"><?= e($lbl) ?></span>
                    </a></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </div>

    <div class="stack">
        <section class="panel">
            <div class="panel-head">
                <h2 class="panel-title">Romaneios a devolver <span class="count"><?= fmt_num($romPend) ?></span></h2>
                <a class="btn btn-ghost btn-sm" href="php/romaneios.php?status=Pendente">Ver todos</a>
            </div>
            <?php if (!$filaRom): ?>
                <?= empty_state('Nenhuma caixa fora do arquivo', 'Quando caixas saírem por romaneio, os prazos de devolução aparecerão aqui.', 'fa-box-archive') ?>
            <?php else: ?>
                <ul class="list">
                    <?php foreach ($filaRom as $r):
                        $ret = $r['data_retorno'] ? strtotime($r['data_retorno']) : null;
                        $hojeTs = strtotime('today');
                        if (!$ret) { $b = '<span class="badge no-dot">Sem data</span>'; }
                        elseif ($ret < $hojeTs) { $b = '<span class="badge badge-danger">' . (int) round(($hojeTs - $ret) / 86400) . ' dia(s) em atraso</span>'; }
                        elseif ($ret <= strtotime('+2 days', $hojeTs)) { $b = '<span class="badge badge-warning">Devolver ' . e(fmt_date($r['data_retorno'], 'd/m')) . '</span>'; }
                        else { $b = '<span class="badge no-dot">Até ' . e(fmt_date($r['data_retorno'], 'd/m')) . '</span>'; } ?>
                        <li><a class="list-row" href="php/romaneios.php?id=<?= (int) $r['id'] ?>">
                            <span class="tag-id">#<?= e(fmt_id($r['id'])) ?></span>
                            <span class="list-main">
                                <span class="list-title"><?= e($r['setor_nome'] ?: '—') ?></span>
                                <span class="list-sub"><?= (int) $r['qtd'] ?> caixa(s) · <?= e($r['usuario'] ?: '—') ?></span>
                            </span>
                            <span class="list-side"><?= $b ?></span>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php if ($isStaff): ?>
        <section class="panel">
            <div class="panel-head"><h2 class="panel-title">Situação do acervo</h2></div>
            <div class="panel-body stack">
                <div class="grid grid-2" style="gap:12px">
                    <a class="kpi" href="php/caixas.php"><div class="kpi-label"><i class="fa-solid fa-box-archive"></i>Caixas</div><div class="kpi-value"><?= fmt_num($acervo['total']) ?></div><div class="kpi-note"><?= fmt_num($acervo['fora']) ?> fora do arquivo</div></a>
                    <a class="kpi" href="php/livros.php"><div class="kpi-label"><i class="fa-solid fa-book"></i>Livros</div><div class="kpi-value"><?= fmt_num($livros['total']) ?></div><div class="kpi-note"><?= fmt_num($qtdPaginas) ?> páginas</div></a>
                </div>
                <div class="meter-row"><span>Livros digitalizados</span><strong><?= $pctLivros ?>%</strong><div class="progress"><span style="width:<?= $pctLivros ?>%"></span></div></div>
                <div class="meter-row"><span>Caixas higienizadas</span><strong><?= $pctHig ?>%</strong><div class="progress"><span style="width:<?= $pctHig ?>%"></span></div></div>
                <div class="meter-row"><span>Caixas com etiqueta</span><strong><?= $pctEtiq ?>%</strong><div class="progress"><span style="width:<?= $pctEtiq ?>%"></span></div></div>
                <p class="small muted"><?= fmt_num($qtdDocs) ?> documentos digitalizados no acervo.</p>
            </div>
        </section>
        <section class="panel">
            <div class="panel-head"><h2 class="panel-title">Respostas deste mês</h2><a class="btn btn-ghost btn-sm" href="php/relatorios.php">Relatórios</a></div>
            <div class="panel-body">
                <?php if (!$resMes): ?>
                    <p class="muted">Nenhuma solicitação respondida neste mês ainda.</p>
                <?php else:
                    $totRes = array_sum($resMes);
                    $cores = ['Realizada' => 'var(--success)', 'Não Consta' => 'var(--brass-500)', 'Não Realizada' => 'var(--danger)', 'Dados Insuficientes' => 'var(--info)'];
                    foreach ($resMes as $k => $n): $pct = round($n / $totRes * 100); ?>
                        <div class="meter-row mb-2"><span><?= e(SOLIC_RESULTADOS[$k] ?? $k) ?></span><strong><?= fmt_num($n) ?></strong>
                            <div class="progress"><span style="width:<?= $pct ?>%;background:<?= $cores[$k] ?? 'var(--neutral)' ?>"></span></div></div>
                    <?php endforeach; endif; ?>
            </div>
        </section>
        <?php endif; ?>
    </div>
</div>
<?php layout_end();
