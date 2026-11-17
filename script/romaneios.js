/* Romaneios — interações das páginas romaneios.php e romaneio-conf.php */
(function () {
    'use strict';
    var CFG = window.ROM || {};
    var form = document.getElementById('formRomaneio');
    var tipo = document.getElementById('r-tipo');
    var newBoxes = document.getElementById('newBoxes');
    var tpl = document.getElementById('newBoxTpl');
    var idx = 0;
    var esc = App.esc;

    function fmtDate(d) {
        if (!d) return '—';
        var p = String(d).slice(0, 10).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : d;
    }

    function fmtPer(a, b) { return (a || b) ? fmtDate(a) + ' – ' + fmtDate(b) : 'Sem período'; }

    /* ---------- Formulário ---------- */
    function syncTipo() {
        var t = tipo.value;
        Array.prototype.forEach.call(form.querySelectorAll('[data-only]'), function (el) {
            el.hidden = el.getAttribute('data-only') !== t;
            Array.prototype.forEach.call(el.querySelectorAll('input, select, textarea'), function (i) { i.disabled = el.hidden; });
        });
        document.getElementById('romSubmitLabel').textContent = t === 'Entrada' ? 'Enviar para aprovação' : 'Registrar saída';
        if (t === 'Entrada' && !newBoxes.children.length) addBox();
    }

    function renumber() {
        Array.prototype.forEach.call(newBoxes.querySelectorAll('[data-n]'), function (s, i) { s.textContent = i + 1; });
    }

    function addBox() {
        var html = tpl.innerHTML.replace(/IDX/g, String(idx++));
        newBoxes.insertAdjacentHTML('beforeend', html);
        renumber();
        var last = newBoxes.lastElementChild;
        var inp = last && last.querySelector('input[type=text]');
        if (inp && newBoxes.children.length > 1) inp.focus();
    }

    if (form) {
        tipo.addEventListener('change', syncTipo);
        document.getElementById('addBox').addEventListener('click', addBox);
        newBoxes.addEventListener('click', function (e) {
            var b = e.target.closest('[data-remove]');
            if (!b) return;
            b.closest('.repeat-card').remove();
            renumber();
        });

        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var t = tipo.value;
            var ini = form.elements.data_saida.value;
            if (!form.reportValidity()) return;
            if (t === 'Saída') {
                var ret = form.elements.data_retorno.value;
                var dS = document.getElementById('r-desc-saida');
                if (!dS.value.trim()) { App.toast('Descreva o que deve sair do arquivo.', 'error'); dS.focus(); return; }
                if (ini && ret && ret < ini) { App.toast('O retorno previsto não pode ser anterior à data da movimentação.', 'error'); form.elements.data_retorno.focus(); return; }
            } else {
                if (!newBoxes.children.length) { App.toast('Adicione pelo menos uma caixa.', 'error'); return; }
                var bad = Array.prototype.some.call(newBoxes.children, function (card) {
                    var a = card.querySelector('[name$="[data_inicio]"]').value, b = card.querySelector('[name$="[data_fim]"]').value;
                    if (a && b && b < a) { card.querySelector('[name$="[data_fim]"]').focus(); return true; }
                    return false;
                });
                if (bad) { App.toast('Em uma das caixas a data fim é anterior à data início.', 'error'); return; }
            }
            App.submitForm(form, { url: CFG.base + 'includes/romaneios_save.php', modal: 'modalRomaneio' });
        });
    }

    function openNew() {
        if (!form) return;
        App.resetForm(form);
        form.elements.data_saida.value = CFG.today;
        newBoxes.innerHTML = '';
        idx = 0;
        tipo.value = 'Saída';
        syncTipo();
        App.openModal('modalRomaneio');
        setTimeout(function () { var d = document.getElementById('r-desc-saida'); if (d) d.focus(); }, 80);
    }
    var btnNovo = document.getElementById('btnNovo');
    if (btnNovo) btnNovo.addEventListener('click', openNew);

    /* ---------- Detalhes ---------- */
    function statusOf(r) {
        if (r.tipo === 'Entrada') return r.aprovado === 'Sim' ? ['Aprovado', 'badge-info'] : ['Aguardando aprovação', 'badge-warning'];
        if (r.status === 'Concluído') return ['Concluído', 'badge-success'];
        if ((r.status === 'Pendente' || !r.status) && r.data_retorno && r.data_retorno < CFG.today) return ['Atrasado', 'badge-danger'];
        return [r.status || 'Pendente', 'badge-warning'];
    }

    function showDetails(id) {
        var body = document.getElementById('det-body');
        var foot = document.getElementById('det-foot');
        document.getElementById('det-title').textContent = 'Romaneio #' + String(id).padStart(4, '0');
        document.getElementById('det-sub').textContent = '';
        body.innerHTML = '<div class="loading-block"><div class="spinner"></div>Carregando…</div>';
        foot.innerHTML = '';
        App.openModal('modalDetalhes');
        App.api(CFG.base + 'includes/romaneios_get.php?id=' + id).then(function (d) {
            var r = d.data;
            var st = statusOf(r);
            document.getElementById('det-sub').textContent = (r.tipo === 'Saída' ? 'Saída do arquivo' : 'Entrada de caixas') + ' · ' + (r.setor_nome || 'Sem setor');
            var h = '<div class="flex mb-2"><span class="badge ' + st[1] + '">' + esc(st[0]) + '</span></div>';
            h += '<dl class="dl">';
            h += '<dt>Solicitante</dt><dd>' + esc(r.usuario_nome || '—') + '</dd>';
            h += '<dt>Setor</dt><dd>' + esc(r.setor_nome || '—') + '</dd>';
            h += '<dt>Data da movimentação</dt><dd>' + fmtDate(r.data_saida) + '</dd>';
            if (r.tipo === 'Saída') h += '<dt>Retorno previsto</dt><dd>' + fmtDate(r.data_retorno) + '</dd>';
            h += '</dl>';
            if (r.descricao) {
                h += '<h3 class="mt-3 mb-2" style="font-size:15px">Descrição</h3><div class="note-box is-desc">' + esc(r.descricao) + '</div>';
            }
            if (r.caixas_detalhes && r.caixas_detalhes.length) {
                h += '<h3 class="mt-3 mb-2" style="font-size:15px">Caixas (' + r.caixas_detalhes.length + ')</h3><ul class="box-list">';
                r.caixas_detalhes.forEach(function (c) {
                    var num = (!c.numero || c.numero === '-') ? 'Sem nº' : c.numero;
                    h += '<li><span class="tag-id">' + esc(num) + '</span><span>' + esc(c.conteudo || 'Sem conteúdo') +
                        '<span class="cell-sub">' + fmtPer(c.data_inicio, c.data_fim) +
                        ((c.coluna || c.fileira) ? ' · ' + esc([c.coluna, c.fileira].filter(Boolean).join(' · ')) : '') + '</span></span></li>';
                });
                h += '</ul>';
            } else if (r.entrada_itens && r.entrada_itens.length) {
                h += '<h3 class="mt-3 mb-2" style="font-size:15px">Caixas propostas (' + r.entrada_itens.length + ')</h3><ul class="box-list">';
                r.entrada_itens.forEach(function (c, i) {
                    h += '<li><span class="tag-id is-muted">' + (i + 1) + '</span><span><strong>' + esc(c.conteudo || 'Sem descrição') + '</strong>' +
                        '<span class="cell-sub">' + esc(c.setor_nome || r.setor_nome || '—') + ' · ' + fmtPer(c.data_inicio, c.data_fim) +
                        (c.observacao ? ' · ' + esc(c.observacao) : '') + '</span></span></li>';
                });
                h += '</ul>';
            }
            body.innerHTML = h;

            var f = '<a class="btn btn-secondary" href="' + CFG.base + 'includes/romaneio_pdf.php?id=' + r.id + '" target="_blank" rel="noopener"><i class="fa-solid fa-print"></i>Imprimir</a>';
            if (CFG.canManage && r.tipo === 'Saída' && (r.status === 'Pendente' || !r.status)) f += '<button type="button" class="btn btn-success" data-rom-act="concluir" data-id="' + r.id + '"><i class="fa-solid fa-check"></i>Registrar devolução</button>';
            if (CFG.canApprove && r.tipo === 'Entrada' && r.aprovado !== 'Sim') f += '<button type="button" class="btn btn-primary" data-rom-act="aprovar" data-id="' + r.id + '"><i class="fa-solid fa-circle-check"></i>Aprovar entrada</button>';
            foot.innerHTML = f;
        }).catch(function (e) {
            if (e.status === 404 || e.status === 403) {
                App.closeModal('modalDetalhes');
                App.toast(e.message, 'error');
                return;
            }
            body.innerHTML = '<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span>' + esc(e.message) + '</span></div>';
        });
    }

    /* ---------- Ações ---------- */
    var actions = {
        concluir: { title: 'Registrar devolução?', message: 'O romaneio será concluído. Se houver caixas vinculadas, elas voltam para o arquivo.', confirmText: 'Registrar devolução', danger: false, url: 'includes/romaneios_concluir.php' },
        aprovar: { title: 'Aprovar entrada?', message: 'As caixas descritas serão cadastradas no arquivo com numeração automática.', confirmText: 'Aprovar e criar caixas', danger: false, url: 'includes/romaneios_aprovar.php' },
        excluir: { title: 'Excluir romaneio?', message: 'O romaneio será removido. Se for uma saída, as caixas voltam a constar no arquivo. Esta ação não pode ser desfeita.', confirmText: 'Excluir romaneio', danger: true, url: 'includes/romaneios_delete.php' }
    };
    document.addEventListener('click', function (e) {
        var det = e.target.closest('[data-rom-view]');
        if (det) { showDetails(det.getAttribute('data-rom-view')); return; }
        var b = e.target.closest('[data-rom-act]');
        if (!b) return;
        var a = actions[b.getAttribute('data-rom-act')];
        if (!a) return;
        App.action({ title: a.title, message: a.message, confirmText: a.confirmText, danger: a.danger,
            url: CFG.base + a.url + '?id=' + encodeURIComponent(b.getAttribute('data-id')), button: b });
    });

    /* ---------- Links diretos (?id=N, ?novo=1) ---------- */
    var qs = new URLSearchParams(location.search);
    var qid = parseInt(qs.get('id'), 10);
    if (qid > 0) {
        var row = document.querySelector('tr[data-id="' + qid + '"]');
        if (row) { row.classList.add('is-target'); row.scrollIntoView({ block: 'center' }); }
        showDetails(qid);
    } else if (qs.get('novo') === '1') {
        openNew();
    }
    // Remove os parâmetros de abertura para que um recarregamento não reabra o modal
    if ((qs.has('id') || qs.has('novo')) && window.history.replaceState) {
        qs.delete('id'); qs.delete('novo');
        var rest = qs.toString();
        history.replaceState(null, '', location.pathname + (rest ? '?' + rest : ''));
    }
})();
