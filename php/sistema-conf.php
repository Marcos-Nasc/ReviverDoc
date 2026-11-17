<?php
require_once '../includes/layout.php';
require_level(NIVEL_ADMIN);

$config_path = __DIR__ . '/config.json';
$config = app_config();
$campos = ['email_notificacao', 'backup_path', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'A página ficou aberta por muito tempo. Tente salvar novamente.'];
        redirect_to('sistema-conf.php');
    }
    if (($_POST['acao'] ?? '') === 'corrigir_numeros') {
        try {
            $pdo->beginTransaction();
            $prox = proximo_numero_caixa();
            $up = $pdo->prepare('UPDATE caixas SET numero = ? WHERE id = ?');
            $feitos = [];
            foreach ($pdo->query('SELECT id, numero FROM caixas ORDER BY id') as $c) {
                $n = trim((string) $c['numero']);
                if (numero_caixa_invalido($n) && !preg_match('/^-\s*\d/', $n)) {
                    $up->execute([(string) $prox, $c['id']]);
                    log_activity('editar', 'caixa', (int) $c['id'], "Número da caixa corrigido: {$n} → {$prox}");
                    $feitos[] = $prox;
                    $prox++;
                }
            }
            $pdo->commit();
            $_SESSION['flash'] = ['type' => 'success', 'text' => $feitos
                ? count($feitos) . ' caixa(s) renumerada(s): nº ' . reset($feitos) . (count($feitos) > 1 ? ' a ' . end($feitos) : '') . '. Reimprima as etiquetas dessas caixas.'
                : 'Nenhuma caixa precisava de correção.'];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash'] = ['type' => 'error', 'text' => db_error_message($e, 'Não foi possível corrigir os números das caixas.')];
        }
        redirect_to('sistema-conf.php#numeros');
    }
    if (($_POST['acao'] ?? '') === 'limpar_erros') {
        @unlink(RD_ERROS_LOG);
        @unlink(RD_ERROS_LOG . '.1');
        $_SESSION['flash'] = ['type' => 'success', 'text' => 'Registro de erros limpo.'];
        redirect_to('sistema-conf.php#erros');
    }
    $novo = $config;
    foreach ($campos as $c) {
        if (!array_key_exists($c, $_POST)) continue;
        $v = trim((string) $_POST[$c]);
        if ($c === 'smtp_pass' && $v === '') continue; // vazio = manter a senha atual
        $novo[$c] = $v;
    }
    $erros = [];
    if (($novo['email_notificacao'] ?? '') !== '') {
        $lista = emails_lista($novo['email_notificacao']);
        $partes = array_filter(preg_split('/[\s,;]+/', $novo['email_notificacao']));
        if (!$lista || count($lista) < count(array_unique(array_map('strtolower', $partes)))) $erros[] = 'Confira o e-mail de notificação: use endereços válidos separados por vírgula.';
        else $novo['email_notificacao'] = implode(', ', $lista);
    }
    if (($novo['smtp_port'] ?? '') !== '' && !ctype_digit((string) $novo['smtp_port'])) $erros[] = 'A porta SMTP deve ser um número.';
    if (!in_array($novo['smtp_secure'] ?? '', ['tls', 'ssl', ''], true)) $novo['smtp_secure'] = 'tls';
    if ($erros) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => implode(' ', $erros)];
    } elseif (@file_put_contents($config_path, json_encode($novo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'Não foi possível gravar php/config.json. Verifique a permissão de escrita do arquivo no servidor.'];
    } else {
        log_activity('editar', 'sistema', null, 'Configurações de e-mail/sistema alteradas');
        $_SESSION['flash'] = ['type' => 'success', 'text' => 'Configurações salvas.'];
    }
    redirect_to('sistema-conf.php');
}
$v = function ($k) use ($config) { return e($config[$k] ?? ''); };
require_once '../includes/planilha_livros.php';
$planilhaOk = is_file(PLANILHA_LIVROS);
$planilhaTotal = 0;
$planilhaErro = '';
if ($planilhaOk) {
    try { $planilhaTotal = count(planilha_livros()); } catch (Exception $e) { $planilhaErro = $e->getMessage(); }
}
$temSenha = !empty($config['smtp_pass']);
$sec = $config['smtp_secure'] ?? 'tls';

layout_start('Sistema e e-mail', [
    'active'   => 'sistema',
    'subtitle' => 'Para onde vão os avisos do sistema e como os e-mails são enviados.',
    'back'     => ['href' => 'configuracoes.php', 'label' => 'Configurações'],
]);
?>
<form method="POST" class="stack" style="max-width:860px" autocomplete="off">
    <?= csrf_field() ?>
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title">Notificações</h2></div>
        <div class="panel-body form-grid">
            <div class="field span-2">
                <label for="email_notificacao">E-mail que recebe os avisos</label>
                <input type="text" inputmode="email" id="email_notificacao" name="email_notificacao" value="<?= $v('email_notificacao') ?>" placeholder="arquivo.geral@empresa.com.br">
                <span class="help">Recebe um e-mail a cada nova solicitação de pesquisa e quando um romaneio vence no dia seguinte. Para mais de um endereço, separe por vírgula.</span>
            </div>
            <div class="field span-2">
                <label for="backup_path">Pasta para backup (opcional)</label>
                <input type="text" id="backup_path" name="backup_path" value="<?= $v('backup_path') ?>" placeholder="Deixe em branco para apenas baixar o arquivo">
                <span class="help">Se informada e gravável pelo servidor, uma cópia de cada backup também é salva nesta pasta.</span>
            </div>
        </div>
    </section>
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title">Servidor de e-mail (SMTP)</h2></div>
        <div class="panel-body form-grid">
            <div class="field"><label for="smtp_host">Servidor</label><input type="text" id="smtp_host" name="smtp_host" value="<?= $v('smtp_host') ?>" placeholder="smtp.gmail.com"></div>
            <div class="field"><label for="smtp_port">Porta</label><input type="text" inputmode="numeric" id="smtp_port" name="smtp_port" value="<?= $v('smtp_port') ?>" placeholder="587"></div>
            <div class="field"><label for="smtp_secure">Segurança</label>
                <select id="smtp_secure" name="smtp_secure">
                    <option value="tls" <?= $sec === 'tls' ? 'selected' : '' ?>>TLS (porta 587)</option>
                    <option value="ssl" <?= $sec === 'ssl' ? 'selected' : '' ?>>SSL (porta 465)</option>
                    <option value="" <?= $sec === '' ? 'selected' : '' ?>>Nenhuma</option>
                </select></div>
            <div class="field"><label for="smtp_user">Usuário (e-mail de envio)</label><input type="text" id="smtp_user" name="smtp_user" value="<?= $v('smtp_user') ?>" autocomplete="off"></div>
            <div class="field span-2"><label for="smtp_pass">Senha</label>
                <input type="password" id="smtp_pass" name="smtp_pass" value="" placeholder="<?= $temSenha ? '•••••••• (salva — deixe em branco para manter)' : 'Senha ou senha de app' ?>" autocomplete="new-password">
                <span class="help">Por segurança, a senha salva não é exibida. No Gmail, use uma senha de app da mesma conta informada em Usuário.</span></div>
            <div class="field span-2" id="testeDetalhe" hidden>
                <label>Resposta do servidor de e-mail</label>
                <pre class="mono" style="white-space:pre-wrap;max-height:260px;overflow:auto;font-size:.78rem;margin:0;padding:.75rem;border:1px solid var(--border);border-radius:8px;background:var(--surface-2,#f6f7f9)"></pre>
            </div>
        </div>
        <div class="panel-body" style="padding-top:0"><span class="help">O teste usa os dados digitados acima, mesmo antes de salvar.</span></div>
        <div class="panel-foot flex between">
            <button type="button" class="btn btn-secondary" id="btnTeste"><i class="fa-regular fa-paper-plane"></i>Enviar e-mail de teste</button>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i>Salvar configurações</button>
        </div>
    </section>
</form>

<section class="panel mt-2" style="max-width:860px">
    <div class="panel-head"><h2 class="panel-title">Planilha de livros (sugestão de livro)</h2></div>
    <div class="panel-body stack">
        <?php if ($planilhaErro): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($planilhaErro) ?></span></div>
        <?php elseif ($planilhaOk): ?>
            <p><strong><?= fmt_num($planilhaTotal) ?> livros</strong> carregados de <code>planilha_arquivo.xlsx</code> · atualizada em <?= e(date('d/m/Y H:i', filemtime(PLANILHA_LIVROS))) ?>.</p>
        <?php else: ?>
            <div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation"></i><span>A planilha <code>planilha_arquivo.xlsx</code> não está na pasta do sistema. Envie-a abaixo para ativar a sugestão de livro.</span></div>
        <?php endif; ?>
        <form id="formPlanilha" class="stack" novalidate>
            <label class="file-drop">
                <span class="file-drop-icon" style="color:var(--success)"><i class="fa-solid fa-file-excel"></i></span>
                <span class="file-drop-text"><strong data-file-label="Clique ou arraste a nova planilha (.xlsx)">Clique ou arraste a nova planilha (.xlsx)</strong><span>Mesmas colunas: ID_UNICO, Nº DO LIVRO, SETOR (DONO), PERÍODO, MÊS 1, MÊS 2, ANO 1, ANO 2. A versão anterior fica guardada.</span></span>
                <input type="file" name="planilha" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
            </label>
            <div><button type="submit" class="btn btn-secondary"><i class="fa-solid fa-upload"></i>Atualizar planilha</button></div>
        </form>
    </div>
</section>
<?php
$cxQuebradas = [];
$cxNegativas = [];
foreach ($pdo->query('SELECT c.id, c.numero, c.conteudo, s.nome AS setor FROM caixas c LEFT JOIN setores s ON s.id = c.setor_id ORDER BY c.id') as $c) {
    $n = trim((string) $c['numero']);
    if (!numero_caixa_invalido($n)) continue;
    if (preg_match('/^-\s*\d/', $n)) $cxNegativas[] = $c; else $cxQuebradas[] = $c;
}
$proxPrevisto = proximo_numero_caixa();
?>
<section class="panel mt-2" id="numeros" style="max-width:860px">
    <div class="panel-head"><h2 class="panel-title">Numeração das caixas</h2></div>
    <div class="panel-body stack">
        <p>Próximo número de caixa: <strong><?= (int) $proxPrevisto ?></strong>.</p>
        <?php if (!$cxQuebradas && !$cxNegativas): ?>
            <p class="text-muted">Nenhuma caixa com número quebrado.</p>
        <?php endif; ?>
        <?php if ($cxQuebradas): ?>
            <div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation"></i><span><?= count($cxQuebradas) ?> caixa(s) ficaram com número quebrado (ex.: 1,8E+19). A correção dá a elas números novos em sequência, a partir de <?= (int) $proxPrevisto ?>, na ordem em que foram criadas. Depois, reimprima as etiquetas.</span></div>
            <div class="table-wrap"><table class="table"><thead><tr><th>Número atual</th><th>Novo número</th><th>Setor</th><th>Conteúdo</th></tr></thead><tbody>
            <?php $pv = $proxPrevisto; foreach ($cxQuebradas as $c): ?>
                <tr><td data-label="Número atual"><?= e($c['numero']) ?></td><td data-label="Novo número"><span class="tag-id"><?= $pv++ ?></span></td><td data-label="Setor"><?= e($c['setor'] ?: '—') ?></td><td data-label="Conteúdo"><?= e($c['conteudo']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <form method="post" action="sistema-conf.php" data-confirm-numeros><?= csrf_field() ?><input type="hidden" name="acao" value="corrigir_numeros">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-arrow-down-1-9"></i>Corrigir numeração</button></form>
        <?php endif; ?>
        <?php if ($cxNegativas): ?>
            <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i><span>Estas caixas têm número negativo, que causava o problema. Elas não atrapalham mais a numeração, mas confira e corrija o número de cada uma:</span></div>
            <ul>
            <?php foreach ($cxNegativas as $c): ?>
                <li><a href="caixas-conf.php?editar=<?= (int) $c['id'] ?>">Caixa <?= e($c['numero']) ?></a> · <?= e($c['setor'] ?: '—') ?> · <?= e($c['conteudo']) ?></li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>
<?php
$errosLinhas = is_file(RD_ERROS_LOG) ? array_slice(array_reverse(file(RD_ERROS_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []), 0, 40) : [];
?>
<section class="panel mt-2" id="erros" style="max-width:860px">
    <div class="panel-head"><h2 class="panel-title">Registro de erros</h2></div>
    <div class="panel-body stack">
        <?php if (!$errosLinhas): ?>
            <p class="text-muted">Nenhum erro registrado.</p>
        <?php else: ?>
            <p>Últimos erros do servidor (mais recentes primeiro). Se algo falhar, copie a linha correspondente e envie para o suporte.</p>
            <pre style="white-space:pre-wrap;word-break:break-word;font-size:12.5px;max-height:360px;overflow:auto;background:var(--surface-2,#f4f5f8);padding:12px;border-radius:8px;margin:0"><?= e(implode("\n", $errosLinhas)) ?></pre>
            <form method="post" action="sistema-conf.php"><?= csrf_field() ?><input type="hidden" name="acao" value="limpar_erros">
                <button type="submit" class="btn btn-secondary"><i class="fa-solid fa-broom"></i>Limpar registro</button></form>
        <?php endif; ?>
    </div>
</section>
<?php layout_end(['scripts' => <<<'JS'
<script>
var fn = document.querySelector('[data-confirm-numeros]');
if (fn) fn.addEventListener('submit', function (ev) {
    if (fn.dataset.ok) return;
    ev.preventDefault();
    App.confirm({ title: 'Corrigir numeração', message: 'As caixas listadas receberão números novos. Deseja continuar?', confirmText: 'Corrigir', danger: false })
        .then(function (sim) { if (sim) { fn.dataset.ok = '1'; fn.submit(); } });
});
document.getElementById('formPlanilha').addEventListener('submit', function (ev) {
    ev.preventDefault();
    App.submitForm(this, { url: '../includes/planilha_upload.php' });
});
document.getElementById('btnTeste').addEventListener('click', function () {
    var b = this; App.busy(b, true);
    var f = b.form, box = document.getElementById('testeDetalhe'), dados = {};
    ['email_notificacao', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass'].forEach(function (n) { if (f.elements[n]) dados[n] = f.elements[n].value; });
    box.hidden = true;
    App.api('../includes/sistema_teste_email.php', { method: 'POST', data: dados })
        .then(function (d) { App.toast(d.message, 'success'); })
        .catch(function (e) {
            App.toast(e.message, 'error');
            var det = e.data && (e.data.detalhe || (e.data.data && e.data.data.detalhe));
            if (det) { box.querySelector('pre').textContent = det; box.hidden = false; }
        })
        .finally(function () { App.busy(b, false); });
});
</script>
JS
]);
