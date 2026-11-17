<?php
require_once '../includes/layout.php';
require_once '../includes/solicitacoes_lib.php';
require_login();

$nivel = user_level();
$staff = $nivel >= NIVEL_ARQUIVO;
$page = current_page();
$per_page = 20;
$offset = ($page - 1) * $per_page;

$busca    = trim($_GET['busca'] ?? '');
$tipo     = trim($_GET['tipo'] ?? '');
$data     = $_GET['data'] ?? '';
$setor    = $staff ? ($_GET['setor'] ?? '') : '';
$situacao = $_GET['situacao'] ?? '';
// Parâmetros alternativos aceitos (atalhos do painel)
$status_param = $_GET['status'] ?? '';
if ($situacao === '' && $status_param === 'Pendente') $situacao = 'pendentes';
if ($situacao === '' && $status_param === 'Respondida') $situacao = 'respondidas';
if (!empty($_GET['atrasadas'])) $situacao = 'atrasadas';
if (!in_array($situacao, ['', 'pendentes', 'atrasadas', 'respondidas'], true)) $situacao = '';

$base_where = ' WHERE 1';
$params = [];
if (!$staff) {
    $base_where .= ' AND s.setor_id = :setor_usuario';
    $params[':setor_usuario'] = user_setor();
}
$where = $base_where;
if ($tipo !== '') {
    $where .= ' AND s.tipo LIKE :tipo';
    $params[':tipo'] = "%$tipo%";
}
if (valid_date($data)) {
    $where .= ' AND DATE(s.prazo) = :data';
    $params[':data'] = $data;
}
if ($setor !== '') {
    $where .= ' AND s.setor_id = :setor';
    $params[':setor'] = $setor;
}
if ($busca !== '') {
    if (ctype_digit(ltrim($busca, '#'))) {
        $where .= ' AND (s.id = :busca_id OR s.tipo LIKE :busca OR s.informacao LIKE :busca)';
        $params[':busca_id'] = (int) ltrim($busca, '#');
    } else {
        $where .= ' AND (s.tipo LIKE :busca OR s.informacao LIKE :busca)';
    }
    $params[':busca'] = "%$busca%";
}
$where_sem_situacao = $where;
$order = ' ORDER BY s.prazo DESC, s.id DESC';
if ($situacao === 'pendentes') {
    $where .= " AND (s.status IS NULL OR s.status <> 'Respondida')";
    $order = ' ORDER BY (s.prazo IS NULL), s.prazo ASC, s.id ASC';
} elseif ($situacao === 'atrasadas') {
    $where .= " AND (s.status IS NULL OR s.status <> 'Respondida') AND s.prazo < NOW()";
    $order = ' ORDER BY s.prazo ASC, s.id ASC';
} elseif ($situacao === 'respondidas') {
    $where .= " AND s.status = 'Respondida'";
    $order = ' ORDER BY s.data_resposta DESC, s.id DESC';
}

$from = ' FROM solicitacoes s LEFT JOIN setores se ON s.setor_id = se.id LEFT JOIN setores sp ON s.setor_pesquisa = sp.id LEFT JOIN usuarios u ON s.usuario_id = u.id';

if ($staff && ($_GET['export'] ?? '') === 'csv') {
    $st = $pdo->prepare('SELECT s.*, se.nome AS setor_nome, sp.nome AS setor_pesquisa_nome, u.nome AS solicitante' . $from . $where . $order);
    $st->execute($params);
    $rows = [];
    foreach ($st as $s) {
        $rows[] = [$s['id'], $s['tipo'], $s['setor_nome'], $s['solicitante'], setor_pesquisa_label($s['setor_pesquisa'], $s['setor_pesquisa_nome']),
            fmt_datetime($s['data_solicitacao'], ''), fmt_datetime($s['prazo'], ''), $s['status'],
            $s['status'] === 'Respondida' ? (SOLIC_RESULTADOS[$s['resultado']] ?? $s['resultado']) : '', fmt_datetime($s['data_resposta'], ''),
            $s['informacao'], $s['observacao'], $s['resposta']];
    }
    csv_download('solicitacoes_' . date('Y-m-d') . '.csv', ['Nº', 'Tipo', 'Setor', 'Solicitante', 'Setor da pesquisa', 'Solicitada em', 'Prazo', 'Status', 'Resultado', 'Respondida em', 'Informações', 'Observação', 'Resposta'], $rows);
}

// Contadores dos filtros rápidos (respeitam os demais filtros)
$cnt_params = $params;
$cnt = $pdo->prepare("SELECT COUNT(*) total, SUM((s.status IS NULL OR s.status <> 'Respondida')) pendentes,
        SUM((s.status IS NULL OR s.status <> 'Respondida') AND s.prazo < NOW()) atrasadas, SUM(s.status = 'Respondida') respondidas" . $from . $where_sem_situacao);
$cnt->execute($cnt_params);
$contagem = $cnt->fetch();

$st = $pdo->prepare('SELECT COUNT(*)' . $from . $where);
$st->execute($params);
$total_records = (int) $st->fetchColumn();
$total_pages = (int) ceil($total_records / $per_page);

$sql = 'SELECT s.id, s.tipo, s.informacao, s.prazo, s.status, s.resultado, s.arquivo, s.data_solicitacao, s.data_resposta,
               se.nome AS setor_nome, u.nome AS solicitante,
               (SELECT MAX(cm.id) FROM chat_mensagens cm WHERE cm.solicitacao_id = s.id) AS ultima_mensagem_id,
               (SELECT cls.ultima_mensagem_lida_id FROM chat_leitura_status cls WHERE cls.solicitacao_id = s.id AND cls.usuario_id = :usuario_logado_id) AS ultima_lida_id,
               (SELECT cm2.usuario_id FROM chat_mensagens cm2 WHERE cm2.solicitacao_id = s.id ORDER BY cm2.id DESC LIMIT 1) AS remetente_ultima'
     . $from . $where . $order . ' LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':usuario_logado_id', user_id(), PDO::PARAM_INT);
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$solicitacoes = $stmt->fetchAll();

$setores = all_setores();
$meu_setor = user_setor() ? setor_nome(user_setor()) : null;

$qf = function ($key, $label, $n) use ($situacao) {
    $q = clean_query();
    unset($q['page'], $q['situacao'], $q['status'], $q['atrasadas'], $q['id'], $q['chat'], $q['nova']);
    if ($key !== '') $q['situacao'] = $key;
    return '<a class="qf' . ($situacao === $key ? ' is-active' : '') . '" href="?' . e(http_build_query($q)) . '">' . e($label)
        . ' <span class="n">' . fmt_num((int) $n) . '</span></a>';
};

$actions = '';
if ($staff) {
    $actions .= '<a class="btn btn-secondary" href="' . e(export_url()) . '"><i class="fa-solid fa-file-csv"></i>Exportar</a>';
    $actions .= '<button type="button" class="btn btn-secondary" id="btnSugestao"><i class="fa-solid fa-book-open"></i>Sugestão de livro</button>';
}
$receb = solicitacoes_recebimento();
$bloqueado = !$receb['aberto'] && !$staff;
if ($staff) {
    $actions .= $receb['aberto']
        ? '<button type="button" class="btn btn-secondary" id="btnRecebimento" data-aberto="1" title="Pausar o recebimento de novas solicitações dos setores"><span class="dot-status is-on" aria-hidden="true"></span>Recebendo solicitações</button>'
        : '<button type="button" class="btn btn-warning" id="btnRecebimento" data-aberto="0" title="Voltar a receber novas solicitações dos setores"><span class="dot-status" aria-hidden="true"></span>Recebimento pausado</button>';
}
$actions .= '<button type="button" class="btn btn-primary" id="btnNova"' . ($bloqueado ? ' disabled title="O Arquivo pausou o recebimento de novas solicitações"' : '') . '><i class="fa-solid fa-plus"></i>Nova solicitação</button>';

layout_start('Solicitações de pesquisa', [
    'active'   => 'solicitacoes',
    'subtitle' => $staff
        ? 'Acompanhe os pedidos de pesquisa dos setores, responda e converse com os solicitantes.'
        : 'Peça ao Arquivo a localização de documentos e acompanhe as respostas do seu setor' . ($meu_setor ? ' (' . e($meu_setor) . ')' : '') . '.',
    'actions'  => $actions,
    'head'     => '<style>
        .prazo-cell { display: grid; gap: 3px; justify-items: start; }
        .resp-current { display: flex; align-items: center; gap: 8px; font-size: 14px; }
        .detail-section + .detail-section { margin-top: 18px; padding-top: 18px; border-top: 1px solid var(--border); }
        .detail-section h3 { font-size: 15px; font-weight: 600; margin-bottom: 10px; }
        .modal-tabs { padding: 0 22px; background: var(--surface); }
        .sug-form { display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 14px; }
        .sug-form .field { flex: 1 1 220px; }
        .sug-anos { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; font-size: 13.5px; color: var(--text-2); margin-bottom: 14px; }
        .sug-group { margin-bottom: 18px; }
        .sug-group h4 { font-size: 14px; font-weight: 600; margin: 0 0 8px; }
        .sug-item { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 8px; background: var(--surface); }
        .sug-item .list-main { min-width: 0; }
        .sug-item .sug-acts { display: flex; gap: 4px; flex-shrink: 0; }
        @media (max-width: 760px) { .sug-item { flex-wrap: wrap; } .sug-item .sug-acts { width: 100%; } }
        .chat-hint { font-size: 12.5px; color: var(--text-3); margin-top: 6px; }
        .dot-status { width: 9px; height: 9px; border-radius: 50%; background: var(--warning); display: inline-block; flex-shrink: 0; }
        .dot-status.is-on { background: var(--success); box-shadow: 0 0 0 3px var(--success-bg); }
        #avisoPausa > div { line-height: 1.5; }
        @media (max-width: 760px) { .hide-sm { display: none; } .chat-hint { display: none; } }
    </style>',
]);
?>
<?= solicitacoes_pausa_html($staff) ?>
<div class="quick-filters mb-2">
    <?= $qf('', 'Todas', $contagem['total']) ?>
    <?= $qf('pendentes', 'Pendentes', $contagem['pendentes']) ?>
    <?= $qf('atrasadas', 'Atrasadas', $contagem['atrasadas']) ?>
    <?= $qf('respondidas', 'Respondidas', $contagem['respondidas']) ?>
</div>

<section class="panel">
    <form method="GET" class="filters">
        <?php if ($situacao !== ''): ?><input type="hidden" name="situacao" value="<?= e($situacao) ?>"><?php endif; ?>
        <div class="field field-search">
            <label for="f-busca">Procurar</label>
            <div class="input-icon"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="f-busca" name="busca" placeholder="Nº, tipo ou informação" value="<?= e($busca) ?>">
            </div>
        </div>
        <?php if ($staff): ?>
        <div class="field">
            <label for="f-setor">Setor solicitante</label>
            <select id="f-setor" name="setor"><?= setor_options($setores, $setor, 'Todos os setores') ?></select>
        </div>
        <?php endif; ?>
        <div class="field">
            <label for="f-data">Prazo em</label>
            <input type="date" id="f-data" name="data" value="<?= e($data) ?>">
        </div>
        <div class="filters-actions">
            <a class="btn btn-ghost" href="solicitacoes.php">Limpar</a>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i>Filtrar</button>
        </div>
    </form>

    <?php if (!$solicitacoes): ?>
        <?php if ($contagem['total'] == 0 && $busca === '' && !$data && !$setor): ?>
            <?= empty_state('Nenhuma solicitação ainda', 'Quando precisar localizar um documento no arquivo, abra uma solicitação de pesquisa.', 'fa-magnifying-glass', $bloqueado ? '' : '<button type="button" class="btn btn-primary" onclick="novaSolicitacao()"><i class="fa-solid fa-plus"></i>Nova solicitação</button>') ?>
        <?php else: ?>
            <?= empty_state('Nenhuma solicitação encontrada', 'Revise os filtros ou limpe a busca para ver todas as solicitações.', 'fa-filter-circle-xmark', '<a class="btn btn-secondary" href="solicitacoes.php">Limpar filtros</a>') ?>
        <?php endif; ?>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table responsive">
            <thead>
                <tr>
                    <th>Nº</th>
                    <th>Tipo</th>
                    <?php if ($staff): ?><th>Setor</th><?php endif; ?>
                    <th>Solicitada em</th>
                    <th>Prazo</th>
                    <th>Status</th>
                    <th>Resultado</th>
                    <th class="col-actions"><span class="sr-only">Ações</span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($solicitacoes as $s):
                $sit = prazo_situacao($s['prazo'], $s['status']);
                $nao_lida = $s['ultima_mensagem_id'] > 0 && $s['ultima_mensagem_id'] != $s['ultima_lida_id'] && $s['remetente_ultima'] != user_id();
                $info = trim((string) $s['informacao']);
                ?>
                <tr class="<?= $sit === 'atrasada' ? 'is-overdue' : ($sit === 'proxima' ? 'is-warning' : '') ?>" data-id="<?= (int) $s['id'] ?>">
                    <td class="col-primary" data-label="Nº"><span class="tag-id">#<?= e(fmt_id($s['id'], 3)) ?></span></td>
                    <td data-label="Tipo" class="wrap-text">
                        <span class="cell-title"><?= e($s['tipo'] ?: '—') ?></span>
                        <?php if ($info !== ''): ?><span class="cell-sub"><?= e(mb_strimwidth($info, 0, 60, '…')) ?></span><?php endif; ?>
                    </td>
                    <?php if ($staff): ?>
                        <td data-label="Setor"><?= e($s['setor_nome'] ?: '—') ?><?php if ($s['solicitante']): ?><span class="cell-sub"><?= e($s['solicitante']) ?></span><?php endif; ?></td>
                    <?php endif; ?>
                    <td data-label="Solicitada em" class="nowrap"><?= e(fmt_date($s['data_solicitacao'])) ?></td>
                    <td data-label="Prazo" class="nowrap">
                        <div class="prazo-cell">
                            <span><?= e(fmt_datetime($s['prazo'])) ?></span>
                            <?php if ($sit === 'atrasada'): ?><span class="badge badge-danger">Atrasada</span>
                            <?php elseif ($sit === 'proxima'): ?><span class="badge badge-warning">Vence em breve</span><?php endif; ?>
                        </div>
                    </td>
                    <td data-label="Status"><?= $s['status'] === 'Respondida' ? '<span class="badge badge-success">Respondida</span>' : '<span class="badge badge-warning">Pendente</span>' ?></td>
                    <td data-label="Resultado">
                        <div class="badges">
                            <?= resultado_badge($s['resultado'], $s['status']) ?>
                            <?php if (!empty($s['arquivo'])): ?>
                                <a class="badge badge-info no-dot" href="../uploads/solicitacao/<?= e(rawurlencode($s['arquivo'])) ?>" target="_blank" rel="noopener" title="Abrir anexo"><i class="fa-solid fa-paperclip"></i>Anexo</a>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="col-actions">
                        <div class="row-actions">
                            <button type="button" class="act" title="<?= $staff && $s['status'] !== 'Respondida' ? 'Ver e responder' : 'Ver detalhes' ?>" aria-label="Ver detalhes da solicitação" onclick="abrirSolicitacao(<?= (int) $s['id'] ?>)">
                                <i class="fa-solid <?= $staff && $s['status'] !== 'Respondida' ? 'fa-reply' : 'fa-eye' ?>"></i>
                            </button>
                            <button type="button" class="act" data-chat="<?= (int) $s['id'] ?>" title="Conversa<?= $nao_lida ? ' (mensagem nova)' : '' ?>" aria-label="Abrir conversa<?= $nao_lida ? ' — há mensagem nova' : '' ?>" onclick="abrirChat(<?= (int) $s['id'] ?>)">
                                <i class="fa-regular fa-comments"></i><?php if ($nao_lida): ?><span class="dot"></span><?php endif; ?>
                            </button>
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

<!-- Detalhes / resposta -->
<div class="modal modal-lg" id="modalDetalhes" role="dialog" aria-modal="true" aria-labelledby="det-title">
    <div class="modal-dialog">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="det-title">Solicitação</h2>
                <p class="modal-sub" id="det-sub"></p>
            </div>
            <button class="icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <?php if ($staff): ?>
        <div class="tabs modal-tabs" role="tablist">
            <button type="button" class="tab is-active" role="tab" data-det-tab="detalhes" aria-selected="true">Detalhes e resposta</button>
            <button type="button" class="tab" role="tab" data-det-tab="sugestao" aria-selected="false"><i class="fa-solid fa-book-open"></i> Sugestão de livro</button>
        </div>
        <?php endif; ?>
        <div class="modal-body" id="det-pane-sugestao" hidden>
            <div data-sugestao></div>
        </div>
        <div class="modal-body" id="det-pane-detalhes">
            <div class="detail-section">
                <div class="flex mb-2" id="det-badges"></div>
                <dl class="dl">
                    <dt>Tipo</dt><dd id="det-tipo"></dd>
                    <dt>Setor solicitante</dt><dd id="det-setor"></dd>
                    <dt>Solicitante</dt><dd id="det-solicitante"></dd>
                    <dt>Setor da pesquisa</dt><dd id="det-setor-pesquisa"></dd>
                    <dt>Solicitada em</dt><dd id="det-data"></dd>
                    <dt>Prazo</dt><dd id="det-prazo"></dd>
                    <dt>Informações</dt><dd><div class="note-box" id="det-info"></div></dd>
                    <dt>Observações</dt><dd class="pre" id="det-obs"></dd>
                </dl>
            </div>
            <?php if ($staff): ?>
            <form id="formResposta" class="detail-section" novalidate>
                <h3>Responder ao solicitante</h3>
                <input type="hidden" name="id">
                <div class="form-grid">
                    <div class="field">
                        <label for="r-resultado">Resultado<span class="req">*</span></label>
                        <select id="r-resultado" name="resultado" required>
                            <?php foreach (SOLIC_RESULTADOS as $v => $l): ?>
                                <option value="<?= e($v) ?>"><?= e($l) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="help" id="r-resultado-help"></span>
                    </div>
                    <div class="field">
                        <span class="label">Respondida em</span>
                        <span id="r-data" class="muted" style="padding-top:9px">—</span>
                    </div>
                    <div class="field span-2">
                        <label for="r-resposta">Mensagem de resposta</label>
                        <textarea id="r-resposta" name="resposta" rows="3" maxlength="100" placeholder="Ex.: Documento localizado na caixa 1012, digitalizado em anexo."></textarea>
                        <span class="help">Até 100 caracteres. Para explicações mais longas, use o chat da solicitação.</span>
                    </div>
                    <div class="field span-2">
                        <span class="label">Anexo</span>
                        <div class="resp-current" id="r-anexo-atual" hidden></div>
                        <label class="file-drop">
                            <span class="file-drop-icon"><i class="fa-solid fa-paperclip"></i></span>
                            <span class="file-drop-text"><strong data-file-label="Clique ou arraste um arquivo">Clique ou arraste um arquivo</strong><span>PDF, imagem ou documento · até <?= e(upload_limite_texto(50)) ?>. Se nenhum arquivo for enviado, o anexo atual é mantido.</span></span>
                            <input type="file" name="arquivo">
                        </label>
                    </div>
                </div>
            </form>
            <?php else: ?>
            <div class="detail-section">
                <h3>Resposta do Arquivo</h3>
                <dl class="dl">
                    <dt>Resultado</dt><dd id="ro-resultado"></dd>
                    <dt>Respondida em</dt><dd id="ro-data"></dd>
                    <dt>Mensagem</dt><dd><div class="note-box" id="ro-resposta"></div></dd>
                    <dt>Anexo</dt><dd id="ro-anexo"></dd>
                </dl>
            </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" id="det-chat"><i class="fa-regular fa-comments"></i>Abrir conversa</button>
            <span style="flex:1"></span>
            <button type="button" class="btn btn-secondary" data-modal-close>Fechar</button>
            <?php if ($staff): ?><button type="submit" form="formResposta" class="btn btn-primary" id="btnResponder"><i class="fa-solid fa-paper-plane"></i>Enviar resposta</button><?php endif; ?>
        </div>
    </div>
</div>

<?php if ($staff): ?>
<div class="modal modal-lg" id="modalSugestao" role="dialog" aria-modal="true" aria-labelledby="sug-title">
    <div class="modal-dialog">
        <div class="modal-header">
            <div><h2 class="modal-title" id="sug-title">Sugestão de livro</h2><p class="modal-sub">Informe uma data, mês ou ano para ver em quais livros da planilha do arquivo procurar.</p></div>
            <button class="icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body"><div data-sugestao></div></div>
    </div>
</div>
<?php endif; ?>

<?php if ($staff): ?>
<!-- Pausar recebimento -->
<div class="modal modal-sm" id="modalPausa" role="dialog" aria-modal="true" aria-labelledby="pausa-title">
    <div class="modal-dialog">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="pausa-title">Pausar o recebimento</h2>
                <p class="modal-sub">Os setores deixam de abrir novas solicitações até o Arquivo reabrir. As solicitações já abertas, respostas e chat continuam funcionando.</p>
            </div>
            <button class="icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="formPausa" class="modal-body" novalidate>
            <input type="hidden" name="aberto" value="0">
            <div class="field">
                <label for="p-msg">Aviso para os setores (opcional)</label>
                <textarea id="p-msg" name="mensagem" rows="3" maxlength="300" placeholder="Ex.: Inventário do acervo até sexta-feira, 13/11. Urgências pelo ramal 2040."></textarea>
            </div>
        </form>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-modal-close>Cancelar</button>
            <button type="submit" form="formPausa" class="btn btn-warning"><i class="fa-solid fa-circle-pause"></i>Pausar recebimento</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Nova solicitação -->
<div class="modal modal-lg" id="modalNova" role="dialog" aria-modal="true" aria-labelledby="nova-title">
    <div class="modal-dialog">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="nova-title">Nova solicitação de pesquisa</h2>
                <p class="modal-sub">Descreva o documento procurado com o máximo de detalhes. Campos com <span class="text-danger">*</span> são obrigatórios.</p>
            </div>
            <button class="icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="formNova" class="modal-body" novalidate>
            <div class="form-grid">
                <div class="field">
                    <label for="n-tipo">Tipo de documento<span class="req">*</span></label>
                    <input type="text" id="n-tipo" name="tipo" required maxlength="100" list="lista-tipos" placeholder="Ex.: Contrato, folha de ponto">
                </div>
                <div class="field">
                    <label for="n-prazo">Prazo para resposta</label>
                    <input type="datetime-local" id="n-prazo" name="prazo" min="<?= date('Y-m-d\TH:i') ?>">
                </div>
                <div class="field">
                    <label for="n-setor">Setor de origem</label>
                    <?php if ($staff || !user_setor()): ?>
                        <select id="n-setor" name="setor_id"><?= setor_options($setores, user_setor() ?: '', 'Selecione') ?></select>
                    <?php else: ?>
                        <input type="text" id="n-setor" value="<?= e($meu_setor ?: '—') ?>" readonly>
                        <span class="help">A solicitação é registrada para o seu setor.</span>
                    <?php endif; ?>
                </div>
                <div class="field">
                    <label for="n-setor-pesq">Setor da pesquisa</label>
                    <select id="n-setor-pesq" name="setor_pesquisa"><?= setor_options($setores, '', 'Selecione') ?></select>
                    <span class="help">Setor dono dos documentos a pesquisar.</span>
                </div>
                <div class="field span-2">
                    <label for="n-info">Informações</label>
                    <textarea id="n-info" name="informacao" rows="4" placeholder="Nome, matrícula, número do processo, período…"></textarea>
                </div>
                <div class="field span-2">
                    <label for="n-obs">Observação</label>
                    <textarea id="n-obs" name="observacao" rows="2"></textarea>
                </div>
            </div>
        </form>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-modal-close>Cancelar</button>
            <button type="submit" form="formNova" class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i>Enviar solicitação</button>
        </div>
    </div>
</div>
<datalist id="lista-tipos">
    <?php
    $tw = $staff ? '' : ' WHERE setor_id = ' . (int) user_setor();
    foreach ($pdo->query("SELECT DISTINCT tipo FROM solicitacoes{$tw} ORDER BY tipo LIMIT 200")->fetchAll(PDO::FETCH_COLUMN) as $t): if ($t === null || $t === '') continue; ?>
        <option value="<?= e($t) ?>"></option>
    <?php endforeach; ?>
</datalist>

<!-- Conversa -->
<div class="modal modal-lg" id="modalChat" role="dialog" aria-modal="true" aria-labelledby="chat-title">
    <div class="modal-dialog">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="chat-title">Conversa da solicitação</h2>
                <p class="modal-sub">Troque mensagens com <?= $staff ? 'o setor solicitante' : 'a equipe do Arquivo' ?> sobre esta pesquisa.</p>
            </div>
            <button class="icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="chat-box" id="chatBox" aria-live="polite"></div>
            <form class="chat-form" id="formChat">
                <label for="chatMsg" class="sr-only">Mensagem</label>
                <textarea id="chatMsg" name="mensagem" maxlength="2000" placeholder="Escreva sua mensagem…"></textarea>
                <button type="submit" class="btn btn-primary" aria-label="Enviar mensagem"><i class="fa-solid fa-paper-plane"></i><span class="hide-sm">Enviar</span></button>
            </form>
            <p class="chat-hint">Enter envia · Shift + Enter quebra a linha</p>
        </div>
    </div>
</div>

<?php
$cfgJs = json_encode(['staff' => $staff, 'uid' => user_id(), 'bloqueado' => $bloqueado]);
layout_end(['scripts' => <<<JS
<script>
var CFG = {$cfgJs};
var esc = App.esc;
var atual = null;

function novaSolicitacao() {
    if (CFG.bloqueado) { App.toast('O Arquivo pausou o recebimento de novas solicitações no momento.', 'error'); return; }
    var f = document.getElementById('formNova');
    App.resetForm(f);
    var p = document.getElementById('n-prazo');
    var d = new Date(Date.now() - new Date().getTimezoneOffset() * 60000);
    p.min = d.toISOString().slice(0, 16);
    App.openModal('modalNova');
}
document.getElementById('btnNova').addEventListener('click', novaSolicitacao);

/* ---------- Ligar/desligar o recebimento (equipe do Arquivo) ---------- */
var btnReceb = document.getElementById('btnRecebimento');
if (btnReceb) {
    btnReceb.addEventListener('click', function () {
        if (btnReceb.dataset.aberto === '1') {
            App.resetForm(document.getElementById('formPausa'));
            App.openModal('modalPausa');
            setTimeout(function () { document.getElementById('p-msg').focus(); }, 80);
            return;
        }
        App.confirm({ title: 'Reabrir o recebimento', message: 'Os setores voltarão a poder abrir novas solicitações de pesquisa.', confirmText: 'Reabrir', danger: false })
            .then(function (sim) {
                if (!sim) return;
                App.busy(btnReceb, true);
                App.api('../includes/solicitacoes_recebimento.php', { method: 'POST', data: { aberto: '1' } })
                    .then(function (d) { App.toast(d.message, 'success'); setTimeout(function () { location.reload(); }, 600); })
                    .catch(function (e) { App.toast(e.message, 'error'); App.busy(btnReceb, false); });
            });
    });
    document.getElementById('formPausa').addEventListener('submit', function (ev) {
        ev.preventDefault();
        App.submitForm(this, { url: '../includes/solicitacoes_recebimento.php', modal: 'modalPausa', reload: true });
    });
}
document.getElementById('formNova').addEventListener('submit', function (ev) {
    ev.preventDefault();
    App.submitForm(this, { url: '../includes/solicitacoes_save.php', modal: 'modalNova' });
});

/* Explica cada resultado para quem responde */
var AJUDA_RESULTADO = {
    'Realizada': 'Documento localizado e enviado ao solicitante.',
    'Não Consta': 'O documento não está guardado no Arquivo (nunca foi enviado para cá).',
    'Não Realizada': 'O documento deveria estar no Arquivo, mas não foi encontrado na pesquisa.',
    'Dados Insuficientes': 'Faltam informações para fazer a pesquisa.'
};
var selRes = document.getElementById('r-resultado');
function ajudaResultado() { var h = document.getElementById('r-resultado-help'); if (h && selRes) h.textContent = AJUDA_RESULTADO[selRes.value] || ''; }
if (selRes) selRes.addEventListener('change', ajudaResultado);

function setText(id, v) { var el = document.getElementById(id); if (el) el.textContent = (v === null || v === undefined || v === '') ? '—' : v; }

/* ---------- Sugestão de livro ---------- */
function montarSugestao(box, texto, auto) {
    box.innerHTML =
        '<form class="sug-form" novalidate><div class="field"><label>Data, mês ou ano</label>' +
        '<div class="input-icon"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" placeholder="Ex.: 15/03/1953, março de 1953, 1953"></div></div>' +
        '<div class="field" style="flex:0 1 200px"><label>Setor (dono)</label><select name="setor"><option value="">Todos</option></select></div>' +
        '<button type="submit" class="btn btn-primary"><i class="fa-solid fa-book-open"></i>Sugerir livros</button></form>' +
        '<div class="sug-res"></div>';
    var form = box.querySelector('form'), res = box.querySelector('.sug-res'), setoresCarregados = false;
    form.addEventListener('submit', function (ev) { ev.preventDefault(); buscar(); });
    form.setor.addEventListener('change', function () { if (form.q.value.trim()) buscar(); });
    var podeUsar = CFG.staff && document.getElementById('formResposta') && !box.closest('#modalSugestao');
    function acoes(numero) {
        return '<a class="btn btn-ghost btn-sm" href="livros.php?busca=' + encodeURIComponent(numero) + '" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i>Abrir</a>' +
            (podeUsar ? '<button type="button" class="btn btn-ghost btn-sm" data-usar="' + esc(numero) + '"><i class="fa-solid fa-reply"></i>Usar na resposta</button>' : '');
    }
    function item(numero, titulo, sub, badge, comAbrir) {
        return '<div class="sug-item"><span class="tag-id"><small>Livro</small>' + esc(numero) + '</span>' +
            '<span class="list-main"><span class="list-title">' + titulo + '</span><span class="list-sub">' + sub + '</span></span>' +
            '<span class="sug-acts">' + (badge || '') + (comAbrir === false ? (podeUsar ? '<button type="button" class="btn btn-ghost btn-sm" data-usar="' + esc(numero) + '"><i class="fa-solid fa-reply"></i>Usar na resposta</button>' : '') : acoes(numero)) + '</span></div>';
    }
    var motivos = { data: '<span class="badge badge-success">Contém a data</span>', mes: '<span class="badge badge-info">Cobre o mês</span>', ano: '<span class="badge no-dot">Mesmo ano</span>' };
    function buscar() {
        var q = form.q.value.trim();
        if (!q) { res.innerHTML = '<p class="muted">Informe uma data, mês ou ano para ver em quais livros procurar.</p>'; return; }
        res.innerHTML = '<div class="loading-block"><div class="spinner"></div>Procurando livros…</div>';
        App.api('../includes/livros_sugestao.php?q=' + encodeURIComponent(q) + '&setor=' + encodeURIComponent(form.setor.value)).then(function (d) {
            if (!setoresCarregados && d.setores_planilha) {
                setoresCarregados = true;
                d.setores_planilha.sort().forEach(function (st) { var o = document.createElement('option'); o.value = st; o.textContent = st; form.setor.appendChild(o); });
            }
            var html = '<div class="sug-anos">' + (d.referencias.length
                ? 'Procurando por: ' + d.referencias.map(function (r) { return '<span class="badge badge-brass no-dot">' + esc(r.label) + '</span>'; }).join('')
                : 'Nenhuma data, mês ou ano reconhecido. Ex.: 15/03/1953, março de 1953 ou 1953.') + '</div>';
            if (!d.referencias.length) { res.innerHTML = html; return; }
            if (d.tem_planilha) {
                html += '<div class="sug-group"><h4>Planilha de livros do arquivo' + (d.total_planilha ? ' · ' + d.total_planilha + ' livro(s)' : '') + '</h4>';
                html += d.planilha.length ? d.planilha.map(function (l) {
                    return item(l.numero, 'Livro ' + esc(l.numero) + ' · ' + esc(l.setor), esc(l.periodo) + (l.original ? ' · planilha: ' + esc(l.original) : ''), motivos[l.motivo], false);
                }).join('') + (d.total_planilha > d.planilha.length ? '<p class="muted small">Mostrando os ' + d.planilha.length + ' mais próximos. Informe uma data completa para refinar.</p>' : '')
                : '<p class="muted small">Nenhum livro da planilha cobre esse período' + (form.setor.value ? ' para o setor ' + esc(form.setor.value) : '') + '.</p>';
                html += '</div>';
            } else if (d.tabela_sugestoes) {
                html += '<div class="sug-group"><h4>Tabela de sugestões do banco</h4>';
                html += d.sugestoes.length ? d.sugestoes.map(function (r) { return item(r.numero_livro, 'Livro ' + esc(r.numero_livro), 'Período: ' + esc(r.periodo || '—'), '', false); }).join('')
                    : '<p class="muted small">Nenhuma indicação para este período.</p>';
                html += '</div>';
            } else {
                html += '<div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation"></i><span>A planilha de livros (planilha_arquivo.xlsx) não foi encontrada na pasta do sistema.</span></div>';
            }
            if (d.livros.length) {
                html += '<div class="sug-group"><h4>Livros cadastrados no sistema que cobrem o período</h4>';
                html += d.livros.map(function (l) {
                    return item(l.numero_livro, 'Livro ' + esc(l.numero_livro) + ' · ' + esc(l.setor), esc(l.periodo) + ' · posição ' + esc(l.posicao) + (l.tipo ? ' · ' + esc(l.tipo) : ''),
                        l.digitalizado ? '<span class="badge badge-success">Digitalizado · ' + l.paginas + ' pág.</span>' : '<span class="badge badge-warning">Não digitalizado</span>');
                }).join('') + '</div>';
            }
            res.innerHTML = html;
        }).catch(function (e) { res.innerHTML = '<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span>' + esc(e.message) + '</span></div>'; });
    }
    res.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-usar]');
        if (!b) return;
        var f = document.getElementById('formResposta');
        f.elements.resposta.value = ('Consultar livro nº ' + b.getAttribute('data-usar') + '. ' + f.elements.resposta.value).slice(0, 100);
        mostrarAba('detalhes');
        f.elements.resposta.focus();
        App.toast('Livro incluído na mensagem de resposta.', 'success');
    });
    form.q.value = texto || '';
    if (auto) buscar(); else form.q.focus();
}
function mostrarAba(nome) {
    Array.prototype.forEach.call(document.querySelectorAll('[data-det-tab]'), function (t) {
        var on = t.getAttribute('data-det-tab') === nome;
        t.classList.toggle('is-active', on); t.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    var pd = document.getElementById('det-pane-detalhes'), ps = document.getElementById('det-pane-sugestao');
    if (pd) pd.hidden = nome !== 'detalhes';
    if (ps) ps.hidden = nome !== 'sugestao';
    var br = document.getElementById('btnResponder'); if (br) br.hidden = nome !== 'detalhes';
}
var sugTexto = '';
Array.prototype.forEach.call(document.querySelectorAll('[data-det-tab]'), function (t) {
    t.addEventListener('click', function () {
        var nome = t.getAttribute('data-det-tab');
        mostrarAba(nome);
        var box = document.querySelector('#det-pane-sugestao [data-sugestao]');
        if (nome === 'sugestao' && box && !box.getAttribute('data-for')) { box.setAttribute('data-for', atual); montarSugestao(box, sugTexto, true); }
    });
});
var btnSug = document.getElementById('btnSugestao');
if (btnSug) btnSug.addEventListener('click', function () {
    montarSugestao(document.querySelector('#modalSugestao [data-sugestao]'), '', false);
    App.openModal('modalSugestao');
});

function abrirSolicitacao(id) {
    atual = id;
    mostrarAba('detalhes');
    var sbox = document.querySelector('#det-pane-sugestao [data-sugestao]');
    if (sbox) { sbox.removeAttribute('data-for'); sbox.innerHTML = ''; }
    App.openModal('modalDetalhes');
    App.modalLoading('modalDetalhes', true);
    App.api('../includes/solicitacoes_get.php?id=' + id).then(function (r) {
        var s = r.data;
        document.getElementById('det-title').textContent = 'Solicitação #' + String(s.id).padStart(3, '0');
        setText('det-sub', s.status === 'Respondida' ? 'Respondida em ' + s.data_resposta_fmt : 'Aguardando resposta do Arquivo');
        var b = s.status === 'Respondida' ? '<span class="badge badge-success">Respondida</span>' : '<span class="badge badge-warning">Pendente</span>';
        if (s.status !== 'Respondida' && s.prazo && new Date(s.prazo.replace(' ', 'T')) < new Date()) b += '<span class="badge badge-danger">Atrasada</span>';
        document.getElementById('det-badges').innerHTML = b;
        setText('det-tipo', s.tipo); setText('det-setor', s.setor_nome); setText('det-solicitante', s.solicitante_nome);
        setText('det-setor-pesquisa', s.setor_pesquisa_label); setText('det-data', s.data_solicitacao_fmt); setText('det-prazo', s.prazo_fmt);
        setText('det-info', s.informacao); setText('det-obs', s.observacao);
        // Texto usado para sugerir livros: anos e datas citados na solicitação
        sugTexto = [s.tipo, s.informacao, s.observacao].join(' ').match(/\b\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4}\b|\b\d{1,2}\/\d{4}\b|\b(jan|fev|mar|abr|mai|jun|jul|ago|set|out|nov|dez)[a-zç]*\.?\s*(de\s+|\/\s*)?(18|19|20)\d{2}\b|\b(18|19|20)\d{2}\b/gi);
        sugTexto = sugTexto ? sugTexto.filter(function (a) { return a.length > 4 || +a <= new Date().getFullYear() + 1; }).join(', ') : '';
        var anexo = s.arquivo ? '<a href="../uploads/solicitacao/' + encodeURIComponent(s.arquivo) + '" target="_blank" rel="noopener"><i class="fa-solid fa-paperclip"></i> ' + esc(s.arquivo) + '</a>' : '';
        if (CFG.staff) {
            var f = document.getElementById('formResposta');
            App.resetForm(f);
            f.elements.id.value = s.id;
            f.elements.resultado.value = s.status === 'Respondida' && s.resultado ? s.resultado : 'Realizada';
            ajudaResultado();
            f.elements.resposta.value = s.resposta || '';
            setText('r-data', s.status === 'Respondida' ? s.data_resposta_fmt : 'Ainda não respondida');
            var ra = document.getElementById('r-anexo-atual');
            ra.hidden = !s.arquivo;
            ra.innerHTML = s.arquivo ? 'Anexo atual: ' + anexo : '';
            document.getElementById('btnResponder').innerHTML = '<i class="fa-solid fa-paper-plane"></i>' + (s.status === 'Respondida' ? 'Atualizar resposta' : 'Enviar resposta');
        } else {
            setText('ro-resultado', s.resultado_label);
            setText('ro-data', s.status === 'Respondida' ? s.data_resposta_fmt : '');
            setText('ro-resposta', s.resposta || (s.status === 'Respondida' ? '' : 'Ainda não há resposta para esta solicitação.'));
            document.getElementById('ro-anexo').innerHTML = anexo || '<span class="muted">Nenhum arquivo anexado.</span>';
        }
    }).catch(function (e) {
        App.toast(e.message, 'error');
        App.closeModal('modalDetalhes');
    }).finally(function () { App.modalLoading('modalDetalhes', false); });
}
document.getElementById('det-chat').addEventListener('click', function () { if (atual) { App.closeModal('modalDetalhes'); abrirChat(atual); } });

var fr = document.getElementById('formResposta');
if (fr) fr.addEventListener('submit', function (ev) {
    ev.preventDefault();
    App.submitForm(fr, { url: '../includes/responder_solicitacoes.php', modal: 'modalDetalhes' });
});

/* ---------- Conversa ---------- */
var chatId = null, chatTimer = null, ultimoId = 0, forcarScroll = false;
var chatBox = document.getElementById('chatBox');
function renderMensagens(msgs) {
    var perto = chatBox.scrollHeight - chatBox.scrollTop - chatBox.clientHeight < 60;
    if (!msgs.length) {
        chatBox.innerHTML = '<div class="empty"><div class="empty-icon"><i class="fa-regular fa-comments"></i></div><h3>Nenhuma mensagem ainda</h3><p>Envie a primeira mensagem para iniciar a conversa.</p></div>';
        return;
    }
    var novoUltimo = msgs[msgs.length - 1].id;
    if (novoUltimo == ultimoId && chatBox.querySelector('.msg')) return;
    var html = '';
    msgs.forEach(function (m) {
        // m.mensagem já vem escapada do servidor (formato de armazenamento do chat)
        html += '<div class="msg ' + (m.proprio ? 'is-self' : 'is-other') + '">'
            + (m.proprio ? '' : '<span class="msg-author">' + m.nome_usuario + '</span>')
            + m.mensagem + '<span class="msg-meta">' + esc(m.data_fmt) + '</span></div>';
    });
    var primeira = ultimoId === 0;
    chatBox.innerHTML = html;
    if (primeira || perto || forcarScroll) chatBox.scrollTop = chatBox.scrollHeight;
    forcarScroll = false;
    ultimoId = novoUltimo;
    App.api('../includes/chat_mark_read.php', { method: 'POST', data: { solicitacao_id: chatId, ultima_mensagem_id: novoUltimo } })
        .then(function () {
            var btn = document.querySelector('[data-chat="' + chatId + '"] .dot');
            if (btn) btn.remove();
        }).catch(function () {});
}
function carregarChat(silencioso) {
    if (!chatId) return;
    var id = chatId;
    if (!silencioso) chatBox.innerHTML = '<div class="loading-block"><div class="spinner"></div>Carregando mensagens…</div>';
    App.api('../includes/chat_load.php?solicitacao_id=' + id).then(function (d) {
        if (id === chatId) renderMensagens(d.mensagens || []);
    }).catch(function (e) {
        if (e.status === 404 || e.status === 403) {
            clearInterval(chatTimer);
            App.closeModal('modalChat');
            if (!silencioso) App.toast(e.message, 'error');
            return;
        }
        if (!silencioso) chatBox.innerHTML = '<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span>' + esc(e.message) + '</span></div>';
    });
}
function abrirChat(id) {
    chatId = id; ultimoId = 0;
    document.getElementById('chat-title').textContent = 'Conversa da solicitação #' + String(id).padStart(3, '0');
    App.openModal('modalChat');
    carregarChat(false);
    clearInterval(chatTimer);
    chatTimer = setInterval(function () { if (!document.hidden) carregarChat(true); }, 15000);
}
document.getElementById('modalChat').addEventListener('modal:closed', function () { clearInterval(chatTimer); chatId = null; });
var chatMsg = document.getElementById('chatMsg');
chatMsg.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); document.getElementById('formChat').requestSubmit ? document.getElementById('formChat').requestSubmit() : document.getElementById('formChat').dispatchEvent(new Event('submit', { cancelable: true })); }
});
chatMsg.addEventListener('input', function () { chatMsg.style.height = 'auto'; chatMsg.style.height = Math.min(chatMsg.scrollHeight, 140) + 'px'; });
document.getElementById('formChat').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var txt = chatMsg.value.trim();
    if (!txt || !chatId) return;
    var btn = this.querySelector('[type=submit]');
    App.busy(btn, true);
    App.api('../includes/chat_send.php', { method: 'POST', data: { solicitacao_id: chatId, mensagem: txt } })
        .then(function () { chatMsg.value = ''; chatMsg.style.height = ''; ultimoId = -1; forcarScroll = true; carregarChat(true); })
        .catch(function (e) { App.toast(e.message, 'error'); })
        .finally(function () { App.busy(btn, false); chatMsg.focus(); });
});

/* ---------- Links diretos ---------- */
(function () {
    var q = new URLSearchParams(location.search);
    if (q.get('nova') === '1') novaSolicitacao();
    var id = parseInt(q.get('id'), 10);
    if (id) { if (q.get('chat') === '1') abrirChat(id); else abrirSolicitacao(id); }
})();
</script>
JS
]);
