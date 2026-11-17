<?php
/**
 * Romaneios — consulta e componentes compartilhados por php/romaneios.php e php/romaneio-conf.php.
 *
 * Parâmetros GET aceitos (ambas as páginas):
 *   busca, tipo (Entrada|Saída), status (Pendente|Atrasado|Concluído|Aguardando|Vencendo),
 *   setor (somente nível ≥ 2), data_inicio, data_fim, page, export=csv, id (abre o romaneio)
 */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(403); exit; }
require_once __DIR__ . '/bootstrap.php';

function rom_filters()
{
    return [
        'busca'       => trim((string) ($_GET['busca'] ?? '')),
        'tipo'        => in_array($_GET['tipo'] ?? '', ['Entrada', 'Saída'], true) ? $_GET['tipo'] : '',
        'status'      => in_array($_GET['status'] ?? '', ['Pendente', 'Atrasado', 'Concluído', 'Aguardando', 'Vencendo'], true) ? $_GET['status'] : '',
        'setor'       => (user_level() >= NIVEL_ARQUIVO && ctype_digit((string) ($_GET['setor'] ?? ''))) ? $_GET['setor'] : '',
        'data_inicio' => valid_date($_GET['data_inicio'] ?? '') ? $_GET['data_inicio'] : '',
        'data_fim'    => valid_date($_GET['data_fim'] ?? '') ? $_GET['data_fim'] : '',
    ];
}

/** Monta FROM/WHERE/HAVING conforme filtros e perfil. Retorna [sql_base, params]. */
function rom_query(array $f)
{
    $where = ' WHERE 1';
    $having = '';
    $params = [];
    if (user_level() == NIVEL_SETOR) {
        $where .= ' AND r.setor_id = :setor_usuario';
        $params[':setor_usuario'] = user_setor();
    } elseif ($f['setor'] !== '') {
        $where .= ' AND r.setor_id = :setor_id';
        $params[':setor_id'] = $f['setor'];
    }
    if ($f['tipo'] !== '') {
        $where .= ' AND r.tipo = :tipo';
        $params[':tipo'] = $f['tipo'];
    }
    switch ($f['status']) {
        case 'Atrasado':
            $where .= " AND r.tipo = 'Saída' AND (r.status = 'Pendente' OR r.status IS NULL OR r.status = '') AND r.data_retorno < CURDATE()";
            break;
        case 'Vencendo':
            $where .= " AND r.tipo = 'Saída' AND (r.status = 'Pendente' OR r.status IS NULL OR r.status = '') AND r.data_retorno BETWEEN CURDATE() AND CURDATE() + INTERVAL 3 DAY";
            break;
        case 'Aguardando':
            $where .= " AND r.tipo = 'Entrada' AND (r.aprovado IS NULL OR r.aprovado <> 'Sim')";
            break;
        case 'Pendente':
            // Saídas com caixas ainda fora do arquivo (entradas pendentes ficam em "Aguardando aprovação")
            $where .= " AND r.tipo = 'Saída' AND (r.status = 'Pendente' OR r.status IS NULL OR r.status = '')";
            break;
        case 'Concluído':
            $where .= " AND r.tipo = 'Saída' AND r.status = 'Concluído'";
            break;
    }
    if ($f['data_inicio'] !== '') {
        $where .= ' AND r.data_saida >= :data_inicio';
        $params[':data_inicio'] = $f['data_inicio'];
    }
    if ($f['data_fim'] !== '') {
        $where .= ' AND r.data_saida <= :data_fim';
        $params[':data_fim'] = $f['data_fim'];
    }
    if ($f['busca'] !== '') {
        $having = ' HAVING (r.id LIKE :busca OR usuario_nome LIKE :busca OR r.conteudo LIKE :busca OR caixas_numeros LIKE :busca)';
        $params[':busca'] = '%' . $f['busca'] . '%';
    }
    $sql = "SELECT r.id, r.tipo, r.data_saida, r.data_retorno, r.status, r.conteudo, r.aprovado, r.setor_id, r.usuario_id,
                   u.nome AS usuario_nome, s.nome AS setor_nome,
                   GROUP_CONCAT(CASE WHEN c.id IS NULL THEN NULL WHEN c.numero IS NULL OR c.numero IN ('-', '') THEN 'S/N' ELSE c.numero END ORDER BY CAST(c.numero AS UNSIGNED) SEPARATOR ', ') AS caixas_numeros,
                   COUNT(c.id) AS total_caixas
            FROM romaneios r
            LEFT JOIN usuarios u ON r.usuario_id = u.id
            LEFT JOIN setores s ON r.setor_id = s.id
            LEFT JOIN romaneio_caixas rc ON r.id = rc.romaneio_id
            LEFT JOIN caixas c ON rc.caixa_id = c.id"
        . $where . ' GROUP BY r.id, r.tipo, r.data_saida, r.data_retorno, r.status, r.conteudo, r.aprovado, r.setor_id, r.usuario_id, u.nome, s.nome' . $having;
    return [$sql, $params];
}

function rom_fetch_page(array $f, $page, $per_page, &$total_records)
{
    global $pdo;
    list($sql, $params) = rom_query($f);
    $c = $pdo->prepare('SELECT COUNT(*) FROM (' . $sql . ') t');
    $c->execute($params);
    $total_records = (int) $c->fetchColumn();
    $st = $pdo->prepare($sql . ' ORDER BY r.data_saida DESC, r.id DESC LIMIT :limit OFFSET :offset');
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->bindValue(':limit', (int) $per_page, PDO::PARAM_INT);
    $st->bindValue(':offset', (int) (($page - 1) * $per_page), PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** Contagens para os filtros rápidos: mesmos filtros da lista (setor, tipo, datas, busca), exceto a situação. */
function rom_counts($f = null)
{
    global $pdo;
    $f = $f ?: rom_filters();
    $f['status'] = '';
    list($sql, $params) = rom_query($f);
    $st = $pdo->prepare("SELECT
            COALESCE(SUM(tipo = 'Saída' AND (status = 'Pendente' OR status IS NULL OR status = '')), 0) AS pendentes,
            COALESCE(SUM(tipo = 'Saída' AND (status = 'Pendente' OR status IS NULL OR status = '') AND data_retorno < CURDATE()), 0) AS atrasados,
            COALESCE(SUM(tipo = 'Saída' AND (status = 'Pendente' OR status IS NULL OR status = '') AND data_retorno BETWEEN CURDATE() AND CURDATE() + INTERVAL 3 DAY), 0) AS vencendo,
            COALESCE(SUM(tipo = 'Entrada' AND (aprovado IS NULL OR aprovado <> 'Sim')), 0) AS aguardando,
            COALESCE(SUM(tipo = 'Saída' AND status = 'Concluído'), 0) AS concluidos,
            COUNT(*) AS total
        FROM (" . $sql . ") t");
    $st->execute($params);
    return array_map('intval', $st->fetch() ?: []);
}

/** Situação apresentada do romaneio: [rótulo, classe do badge, classe da linha, observação de prazo]. */
function rom_status(array $r)
{
    if ($r['tipo'] === 'Entrada') {
        return $r['aprovado'] === 'Sim'
            ? ['Aprovado', 'badge-info', '', '']
            : ['Aguardando aprovação', 'badge-warning', 'is-warning', ''];
    }
    if ($r['status'] === 'Concluído') {
        return ['Concluído', 'badge-success', '', ''];
    }
    if (in_array((string) $r['status'], ['Pendente', ''], true) && !empty($r['data_retorno'])) {
        $dias = (int) floor((strtotime($r['data_retorno']) - strtotime('today')) / 86400);
        if ($dias < 0) {
            return ['Atrasado', 'badge-danger', 'is-overdue', 'há ' . abs($dias) . ' dia' . (abs($dias) > 1 ? 's' : '')];
        }
        if ($dias === 0) return ['Pendente', 'badge-warning', 'is-warning', 'vence hoje'];
        if ($dias <= 3) return ['Pendente', 'badge-warning', 'is-warning', 'vence em ' . $dias . ' dia' . ($dias > 1 ? 's' : '')];
        return ['Pendente', 'badge-warning', '', 'vence em ' . $dias . ' dias'];
    }
    return [$r['status'] ?: 'Pendente', 'badge-warning', '', ''];
}

/** Descrição dos itens: caixas vinculadas, caixas propostas na entrada e/ou a descrição escrita. */
function rom_descricao(array $r)
{
    $dados = rom_conteudo_dados($r['conteudo'] ?? '');
    $desc = $dados['descricao'];
    $descHtml = $desc !== '' ? '<span class="cell-sub rom-desc">' . e(mb_strimwidth(preg_replace('/\s+/', ' ', $desc), 0, 140, '…')) . '</span>' : '';
    if (!empty($r['caixas_numeros'])) {
        $n = (int) $r['total_caixas'];
        return '<span class="cell-title">' . $n . ' caixa' . ($n > 1 ? 's' : '') . '</span><span class="cell-sub">Nº ' . e($r['caixas_numeros']) . '</span>' . $descHtml;
    }
    if ($r['tipo'] === 'Entrada' && $dados['caixas']) {
        $itens = $dados['caixas'];
        $html = '<span class="cell-title">' . count($itens) . ' caixa' . (count($itens) > 1 ? 's' : '') . ' nova' . (count($itens) > 1 ? 's' : '') . '</span><ul class="cell-sub">';
        foreach (array_slice($itens, 0, 4) as $c) {
            $per = (!empty($c['data_inicio']) || !empty($c['data_fim'])) ? ' · ' . fmt_periodo($c['data_inicio'] ?? null, $c['data_fim'] ?? null, '') : '';
            $html .= '<li>' . e(($c['conteudo'] ?? '') !== '' ? $c['conteudo'] : 'Sem descrição') . e($per) . '</li>';
        }
        if (count($itens) > 4) $html .= '<li>+ ' . (count($itens) - 4) . ' outra(s)</li>';
        return $html . '</ul>' . $descHtml;
    }
    if ($desc !== '') {
        return '<span class="rom-desc-only">' . e(mb_strimwidth(preg_replace('/\s+/', ' ', $desc), 0, 160, '…')) . '</span>';
    }
    return '—';
}

function rom_csv(array $f)
{
    global $pdo;
    list($sql, $params) = rom_query($f);
    $st = $pdo->prepare($sql . ' ORDER BY r.data_saida DESC, r.id DESC');
    $st->execute($params);
    $rows = [];
    foreach ($st as $r) {
        $s = rom_status($r);
        $dados = rom_conteudo_dados($r['conteudo']);
        if ($r['caixas_numeros']) {
            $itens = 'Caixas: ' . $r['caixas_numeros'];
        } elseif ($dados['caixas']) {
            $itens = count($dados['caixas']) . ' caixa(s) nova(s): ' . implode('; ', array_map(function ($c) { return (string) ($c['conteudo'] ?? ''); }, $dados['caixas']));
        } else {
            $itens = '';
        }
        $rows[] = [fmt_id($r['id']), $r['tipo'], $r['usuario_nome'], $r['setor_nome'], $dados['descricao'], $itens,
            fmt_date($r['data_saida'], 'd/m/Y', ''), fmt_date($r['data_retorno'], 'd/m/Y', ''), $s[0]];
    }
    csv_download('romaneios_' . date('Y-m-d') . '.csv', ['Nº', 'Tipo', 'Usuário', 'Setor', 'Descrição', 'Caixas', 'Data', 'Retorno previsto', 'Situação'], $rows);
}

/** Link de filtro rápido preservando os demais filtros. */
function rom_qf($status, $label, $n, $current)
{
    $q = clean_query();
    unset($q['page'], $q['status'], $q['id'], $q['novo']);
    if ($status !== '') $q['status'] = $status;
    return '<a class="qf' . ($current === $status ? ' is-active' : '') . '" href="?' . e(http_build_query($q)) . '">' . e($label)
        . ($n !== null ? ' <span class="n">' . fmt_num($n) . '</span>' : '') . '</a>';
}

/** Caixas disponíveis para saída. */
function rom_caixas_disponiveis()
{
    global $pdo;
    return $pdo->query("SELECT c.id, c.numero, c.conteudo, c.data_inicio, c.data_fim, c.setor_id, s.nome AS setor_nome
        FROM caixas c LEFT JOIN setores s ON s.id = c.setor_id
        WHERE c.status = 'No Arquivo' ORDER BY CAST(c.numero AS UNSIGNED) ASC, c.id ASC")->fetchAll();
}

/** CSS específico do módulo. */
function rom_styles()
{
    return '<style>
.box-picker { border: 1px solid var(--border-strong); border-radius: var(--radius); overflow: hidden; }
.box-picker-head { display: flex; gap: 10px; align-items: center; padding: 10px; border-bottom: 1px solid var(--border); background: var(--surface-2); flex-wrap: wrap; }
.box-picker-head .input-icon { flex: 1 1 220px; }
.box-picker-head input { height: 38px; width: 100%; border-radius: var(--radius-sm); border: 1px solid var(--border-strong); background: var(--surface); padding: 0 12px; outline: none; }
.box-picker-head input:focus { border-color: var(--brass-500); }
.box-picker-count { font-size: 13.5px; font-weight: 600; color: var(--text-2); white-space: nowrap; }
.box-picker-list { max-height: 300px; overflow-y: auto; }
.box-opt { display: grid; grid-template-columns: 20px auto 1fr; gap: 12px; align-items: center; padding: 9px 12px; border-bottom: 1px solid var(--border); cursor: pointer; font-size: 14px; }
.box-opt:last-child { border-bottom: 0; }
.box-opt:hover { background: var(--surface-2); }
.box-opt input { width: 17px; height: 17px; accent-color: var(--primary); }
.box-opt.is-checked { background: color-mix(in srgb, var(--brass-500) 9%, var(--surface)); }
.box-opt-sub { display: block; font-size: 12.5px; color: var(--text-3); }
.box-picker-empty { padding: 20px; text-align: center; color: var(--text-3); font-size: 14px; }
.table td ul.cell-sub { margin: 2px 0 0; padding-left: 16px; }
.rom-table .wrap-text { min-width: 180px; max-width: 300px; }
.rom-table td { padding-left: 12px; padding-right: 12px; }
tr.is-target td { background: color-mix(in srgb, var(--brass-500) 10%, var(--surface)) !important; }
.prazo-note { display: block; font-size: 12.5px; color: var(--text-3); margin-top: 2px; }
.is-overdue .prazo-note { color: var(--danger); font-weight: 600; }
.box-list { list-style: none; margin: 0; padding: 0; border: 1px solid var(--border); border-radius: var(--radius); }
.box-list li { display: flex; gap: 12px; align-items: center; padding: 10px 12px; border-bottom: 1px solid var(--border); font-size: 14px; }
.box-list li:last-child { border-bottom: 0; }
.rom-desc { display: block; white-space: normal; max-width: 46ch; }
.rom-desc-only { display: block; white-space: normal; max-width: 52ch; font-size: 14px; }
.note-box.is-desc { white-space: pre-wrap; }
</style>';
}

/** Modais de novo romaneio e de detalhes. */
function rom_modais(array $setores, array $caixas, $isConf)
{
    $nivel = user_level();
    ?>
<div class="modal modal-lg" id="modalRomaneio" role="dialog" aria-modal="true" aria-labelledby="rom-title">
    <div class="modal-dialog">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="rom-title">Novo romaneio</h2>
                <p class="modal-sub">Descreva o que precisa sair do arquivo ou envie novas caixas para entrada.</p>
            </div>
            <button class="icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="formRomaneio" class="modal-body" novalidate>
            <div class="form-grid">
                <div class="field">
                    <label for="r-tipo">Tipo de movimentação<span class="req">*</span></label>
                    <select id="r-tipo" name="tipo" required>
                        <option value="Saída">Saída do arquivo</option>
                        <option value="Entrada">Entrada de novas caixas (requer aprovação)</option>
                    </select>
                </div>
                <?php if ($nivel >= NIVEL_ARQUIVO): ?>
                <div class="field">
                    <label for="r-setor">Setor<?= $isConf ? '<span class="req">*</span>' : '' ?></label>
                    <select id="r-setor" name="setor_id" <?= $isConf ? 'required' : '' ?>><?= setor_options($setores, $isConf ? '' : (string) user_setor(), 'Selecione o setor') ?></select>
                    <span class="help">Setor responsável pelo romaneio.</span>
                </div>
                <?php else: ?>
                <div class="field">
                    <label>Setor</label>
                    <input type="text" value="<?= e(setor_nome(user_setor()) ?: '—') ?>" readonly>
                </div>
                <?php endif; ?>
                <div class="field">
                    <label for="r-data">Data da movimentação</label>
                    <input type="date" id="r-data" name="data_saida" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="field" data-only="Saída">
                    <label for="r-retorno">Retorno previsto</label>
                    <input type="date" id="r-retorno" name="data_retorno" min="<?= date('Y-m-d') ?>">
                    <span class="help">Quem registrou o romaneio e os administradores são avisados na véspera do vencimento.</span>
                </div>

                <div class="field span-2" data-only="Saída">
                    <label for="r-desc-saida">Descrição<span class="req">*</span></label>
                    <textarea id="r-desc-saida" name="descricao" rows="4" maxlength="2000" required placeholder="Ex.: Retirar as caixas de contratos do RH de 2019 (admissões de janeiro a junho)."></textarea>
                    <span class="help">Descreva o que precisa sair do arquivo. A equipe do Arquivo separa o material.</span>
                </div>

                <div class="field span-2" data-only="Entrada" hidden>
                    <label>Novas caixas</label>
                    <div id="newBoxes"></div>
                    <button type="button" class="btn btn-secondary" id="addBox"><i class="fa-solid fa-plus"></i>Adicionar caixa</button>
                    <span class="help">As caixas recebem número automaticamente quando o romaneio é aprovado por um administrador.</span>
                </div>
            </div>
        </form>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-modal-close>Cancelar</button>
            <button type="submit" form="formRomaneio" class="btn btn-primary"><i class="fa-solid fa-check"></i><span id="romSubmitLabel">Registrar saída</span></button>
        </div>
    </div>
</div>

<template id="newBoxTpl">
    <div class="repeat-card">
        <div class="repeat-card-head"><span>Caixa <span data-n></span></span>
            <button type="button" class="act is-danger" data-remove title="Remover caixa" aria-label="Remover caixa"><i class="fa-regular fa-trash-can"></i></button>
        </div>
        <div class="form-grid">
            <div class="field span-2">
                <label>Conteúdo<span class="req">*</span></label>
                <input type="text" name="new_caixas[IDX][conteudo]" required placeholder="Ex.: Notas fiscais 2024">
            </div>
            <?php if ($nivel == NIVEL_SETOR): ?>
            <input type="hidden" name="new_caixas[IDX][setor_id]" value="<?= (int) user_setor() ?>">
            <?php else: ?>
            <div class="field">
                <label>Setor de origem</label>
                <select name="new_caixas[IDX][setor_id]"><?= setor_options($setores, '', 'Mesmo setor do romaneio') ?></select>
            </div>
            <div class="field"></div>
            <?php endif; ?>
            <div class="field">
                <label>Data início</label>
                <input type="date" name="new_caixas[IDX][data_inicio]">
            </div>
            <div class="field">
                <label>Data fim</label>
                <input type="date" name="new_caixas[IDX][data_fim]">
            </div>
            <div class="field span-2">
                <label>Observação</label>
                <textarea name="new_caixas[IDX][observacao]" rows="2"></textarea>
            </div>
        </div>
    </div>
</template>

<div class="modal modal-lg" id="modalDetalhes" role="dialog" aria-modal="true" aria-labelledby="det-title">
    <div class="modal-dialog">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="det-title">Romaneio</h2>
                <p class="modal-sub" id="det-sub"></p>
            </div>
            <button class="icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="det-body"></div>
        <div class="modal-footer" id="det-foot"></div>
    </div>
</div>
<?php
}

/** Tabela de romaneios. $isConf: página de gestão (mostra setor e ações administrativas). */
function rom_table(array $rows, $isConf)
{
    $showSetor = $isConf || user_level() >= NIVEL_ARQUIVO;
    $canManage = user_level() >= NIVEL_ARQUIVO;
    $canApprove = user_level() >= NIVEL_ADMIN;
    ob_start(); ?>
<div class="table-wrap">
    <table class="table responsive rom-table">
        <thead>
            <tr>
                <th>Nº</th>
                <th>Tipo</th>
                <th>Solicitante<?= $showSetor ? ' / setor' : '' ?></th>
                <th>Itens</th>
                <th>Data</th>
                <th>Retorno previsto</th>
                <th>Situação</th>
                <th class="col-actions"><span class="sr-only">Ações</span></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $s = rom_status($r); ?>
            <tr data-id="<?= (int) $r['id'] ?>" class="<?= e($s[2]) ?>">
                <td class="col-primary" data-label="Nº"><span class="tag-id"><?= e(fmt_id($r['id'])) ?></span></td>
                <td data-label="Tipo"><span class="nowrap"><i class="fa-solid <?= $r['tipo'] === 'Saída' ? 'fa-arrow-right-from-bracket' : 'fa-arrow-right-to-bracket' ?> muted"></i> <?= e($r['tipo']) ?></span></td>
                <td data-label="Solicitante"><span class="cell-title"><?= e($r['usuario_nome'] ?: '—') ?></span><?php if ($showSetor): ?><span class="cell-sub"><?= e($r['setor_nome'] ?: '—') ?></span><?php endif; ?></td>
                <td data-label="Itens" class="wrap-text"><?= rom_descricao($r) ?></td>
                <td data-label="Data" class="nowrap"><?= e(fmt_date($r['data_saida'])) ?></td>
                <td data-label="Retorno previsto" class="nowrap"><?= $r['tipo'] === 'Saída' ? e(fmt_date($r['data_retorno'])) : '<span class="muted">—</span>' ?></td>
                <td data-label="Situação"><span class="badge <?= e($s[1]) ?>"><?= e($s[0]) ?></span><?= $s[3] ? '<span class="prazo-note">' . e($s[3]) . '</span>' : '' ?></td>
                <td class="col-actions">
                    <div class="row-actions">
                        <button type="button" class="act" data-rom-view="<?= (int) $r['id'] ?>" title="Ver detalhes" aria-label="Ver detalhes"><i class="fa-regular fa-eye"></i></button>
                        <a class="act" href="<?= app_base() ?>includes/romaneio_pdf.php?id=<?= (int) $r['id'] ?>" target="_blank" rel="noopener" title="Imprimir romaneio" aria-label="Imprimir romaneio"><i class="fa-solid fa-print"></i></a>
                        <?php if ($isConf && $canApprove && $r['tipo'] === 'Entrada' && $r['aprovado'] !== 'Sim'): ?>
                            <button type="button" class="act" data-rom-act="aprovar" data-id="<?= (int) $r['id'] ?>" title="Aprovar entrada" aria-label="Aprovar entrada"><i class="fa-solid fa-circle-check"></i></button>
                        <?php endif; ?>
                        <?php if ($isConf && $canManage && $r['tipo'] === 'Saída' && in_array((string) $r['status'], ['Pendente', ''], true)): ?>
                            <button type="button" class="act" data-rom-act="concluir" data-id="<?= (int) $r['id'] ?>" title="Registrar devolução (concluir)" aria-label="Registrar devolução"><i class="fa-solid fa-rotate-left"></i></button>
                        <?php endif; ?>
                        <?php if ($isConf && $canManage): ?>
                            <button type="button" class="act is-danger" data-rom-act="excluir" data-id="<?= (int) $r['id'] ?>" title="Excluir" aria-label="Excluir romaneio"><i class="fa-regular fa-trash-can"></i></button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
    return ob_get_clean();
}

function rom_script_config($isConf)
{
    $cfg = [
        'base'       => app_base(),
        'today'      => date('Y-m-d'),
        'isConf'     => (bool) $isConf,
        'canManage'  => user_level() >= NIVEL_ARQUIVO,
        'canApprove' => user_level() >= NIVEL_ADMIN,
    ];
    return '<script>window.ROM = ' . json_encode($cfg) . ';</script>'
        . '<script src="' . app_base() . 'script/romaneios.js?v=' . RD_VERSION . '"></script>';
}
