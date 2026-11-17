/* =====================================================================
   ReviverDoc — utilitários de interface (sem dependências)
   Expõe window.App e mantém showAlert()/abrirModal()/fecharModal() por compatibilidade.
   ===================================================================== */
(function () {
    'use strict';

    var meta = function (n) { var m = document.querySelector('meta[name="' + n + '"]'); return m ? m.getAttribute('content') : ''; };
    var CSRF = meta('csrf-token');
    var BASE = meta('app-base') || '';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /* ---------------- Toasts ---------------- */
    function toast(message, type, duration) {
        type = type === 'danger' ? 'error' : (type || 'info');
        var stack = document.querySelector('.toast-stack');
        if (!stack) { stack = document.createElement('div'); stack.className = 'toast-stack'; document.body.appendChild(stack); }
        var icons = { success: 'fa-circle-check', error: 'fa-circle-exclamation', info: 'fa-circle-info', warning: 'fa-triangle-exclamation' };
        var el = document.createElement('div');
        el.className = 'toast toast-' + type;
        el.setAttribute('role', type === 'error' ? 'alert' : 'status');
        el.innerHTML = '<i class="fa-solid ' + (icons[type] || icons.info) + '"></i><span>' + esc(message) + '</span><button class="toast-close" aria-label="Fechar">&times;</button>';
        stack.appendChild(el);
        var remove = function () { el.classList.add('is-leaving'); setTimeout(function () { el.remove(); }, 200); };
        el.querySelector('.toast-close').onclick = remove;
        setTimeout(remove, duration || (type === 'error' ? 6500 : 3800));
    }

    /* Mensagem que sobrevive a um recarregamento */
    function flashNext(message, type) {
        try { sessionStorage.setItem('rd-flash', JSON.stringify({ m: message, t: type || 'success' })); } catch (e) {}
    }
    function reloadWith(message, type) {
        flashNext(message, type);
        // Remove os parâmetros de atalho (?editar=, ?id=, ?nova=...) para não reabrir o formulário após salvar
        var u = new URL(window.location.href);
        var tinha = false;
        ['editar', 'id', 'nova', 'novo', 'chat'].forEach(function (k) {
            if (u.searchParams.has(k)) { u.searchParams.delete(k); tinha = true; }
        });
        if (tinha) window.location.replace(u.pathname + (u.searchParams.toString() ? '?' + u.searchParams.toString() : '') + u.hash);
        else window.location.reload();
    }

    /* ---------------- Modais ---------------- */
    var openStack = [];
    function getModal(m) { return typeof m === 'string' ? document.getElementById(m) : m; }
    function openModal(m) {
        var el = getModal(m);
        if (!el) return;
        el.classList.add('is-open');
        el.style.display = '';
        document.body.classList.add('modal-open');
        openStack.push(el);
        el._lastFocus = document.activeElement;
        setTimeout(function () {
            var f = el.querySelector('[autofocus], input:not([type=hidden]):not([readonly]), select, textarea, .btn-primary');
            if (f) { try { f.focus({ preventScroll: true }); } catch (e) {} }
        }, 40);
    }
    function closeModal(m) {
        var el = getModal(m);
        if (!el) return;
        el.classList.remove('is-open');
        el.style.display = '';
        openStack = openStack.filter(function (x) { return x !== el; });
        if (!openStack.length) document.body.classList.remove('modal-open');
        if (el._lastFocus && el._lastFocus.focus) { try { el._lastFocus.focus({ preventScroll: true }); } catch (e) {} }
        el.dispatchEvent(new CustomEvent('modal:closed'));
    }
    function modalLoading(m, on) {
        var el = getModal(m);
        if (!el) return;
        var ld = el.querySelector('.modal-loading');
        if (!ld) {
            ld = document.createElement('div');
            ld.className = 'modal-loading';
            ld.innerHTML = '<div class="spinner"></div>';
            (el.querySelector('.modal-dialog') || el).appendChild(ld);
        }
        ld.classList.toggle('is-active', !!on);
    }

    document.addEventListener('click', function (ev) {
        var t = ev.target;
        var opener = t.closest('[data-modal-open]');
        if (opener) { ev.preventDefault(); openModal(opener.getAttribute('data-modal-open')); return; }
        var closer = t.closest('[data-modal-close]');
        if (closer) { ev.preventDefault(); closeModal(closer.closest('.modal')); return; }
        if (t.classList && t.classList.contains('modal') && t.classList.contains('is-open') && !t.hasAttribute('data-static')) {
            closeModal(t);
        }
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && openStack.length) {
            var top = openStack[openStack.length - 1];
            if (!top.hasAttribute('data-static')) closeModal(top);
        }
    });

    /* ---------------- Confirmação ---------------- */
    function confirmDialog(opts) {
        opts = typeof opts === 'string' ? { message: opts } : (opts || {});
        return new Promise(function (resolve) {
            var wrap = document.createElement('div');
            wrap.className = 'modal modal-sm';
            wrap.setAttribute('role', 'dialog');
            wrap.setAttribute('aria-modal', 'true');
            var danger = opts.danger !== false;
            wrap.innerHTML =
                '<div class="modal-dialog"><div class="modal-body">' +
                '<div class="confirm-icon' + (danger ? '' : ' is-info') + '"><i class="fa-solid ' + (danger ? 'fa-triangle-exclamation' : 'fa-circle-question') + '"></i></div>' +
                '<h2 class="modal-title">' + esc(opts.title || 'Confirmar ação') + '</h2>' +
                '<p class="confirm-text">' + esc(opts.message || 'Deseja continuar?') + '</p>' +
                '</div><div class="modal-footer">' +
                '<button type="button" class="btn btn-secondary" data-act="no">' + esc(opts.cancelText || 'Cancelar') + '</button>' +
                '<button type="button" class="btn ' + (danger ? 'btn-danger' : 'btn-primary') + '" data-act="yes">' + esc(opts.confirmText || 'Confirmar') + '</button>' +
                '</div></div>';
            document.body.appendChild(wrap);
            var done = function (v) { closeModal(wrap); setTimeout(function () { wrap.remove(); }, 50); resolve(v); };
            wrap.querySelector('[data-act=yes]').onclick = function () { done(true); };
            wrap.querySelector('[data-act=no]').onclick = function () { done(false); };
            wrap.addEventListener('modal:closed', function () { setTimeout(function () { if (wrap.parentNode) { wrap.remove(); resolve(false); } }, 60); });
            openModal(wrap);
            setTimeout(function () { wrap.querySelector('[data-act=' + (danger ? 'no' : 'yes') + ']').focus(); }, 60);
        });
    }

    /* ---------------- Requisições ---------------- */
    function api(url, options) {
        options = options || {};
        var method = (options.method || (options.data ? 'POST' : 'GET')).toUpperCase();
        var body = options.data;
        if (body && !(body instanceof FormData)) {
            var fd = new FormData();
            Object.keys(body).forEach(function (k) {
                var v = body[k];
                if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', x); });
                else if (v !== undefined && v !== null) fd.append(k, v);
            });
            body = fd;
        }
        return fetch(url, {
            method: method,
            body: method === 'GET' ? undefined : body,
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
        }).then(function (res) {
            return res.text().then(function (txt) {
                var data;
                try { data = JSON.parse(txt); } catch (e) {
                    var err = new Error(res.ok ? 'Resposta inesperada do servidor.' : 'O servidor retornou um erro (' + res.status + ').');
                    err.status = res.status; throw err;
                }
                if (res.status === 401 && data.login) {
                    toast(data.message || 'Sessão expirada.', 'error');
                    setTimeout(function () { window.location.href = BASE + 'php/login.php'; }, 1500);
                }
                var ok = res.ok && (data.status === 'ok' || data.status === 'success' || data.status === undefined && !data.error);
                if (!ok) {
                    var e2 = new Error(data.message || data.mensagem || data.error || 'Não foi possível concluir a operação.');
                    e2.status = res.status; e2.data = data; throw e2;
                }
                return data;
            });
        }, function () {
            throw new Error('Sem conexão com o servidor. Verifique sua internet e tente novamente.');
        });
    }

    function busy(btn, on) {
        if (!btn) return;
        btn.disabled = !!on;
        btn.classList.toggle('is-loading', !!on);
        btn.setAttribute('aria-busy', on ? 'true' : 'false');
    }

    /**
     * Envia um formulário via AJAX.
     * opts: url, onSuccess(data), successMessage, reload (bool), modal
     */
    function submitForm(form, opts) {
        opts = opts || {};
        if (typeof form.reportValidity === 'function' && !form.reportValidity()) return Promise.resolve(null);
        var btn = form.querySelector('[type=submit]') || (form.id ? document.querySelector('[type=submit][form="' + form.id + '"]') : null);
        busy(btn, true);
        if (opts.modal) modalLoading(opts.modal, true);
        return api(opts.url || form.getAttribute('action'), { method: 'POST', data: new FormData(form) })
            .then(function (data) {
                if (opts.onSuccess) opts.onSuccess(data);
                var msg = opts.successMessage || data.message || 'Alterações salvas.';
                if (opts.reload !== false) reloadWith(msg, 'success'); else toast(msg, 'success');
                return data;
            })
            .catch(function (err) { toast(err.message, 'error'); return null; })
            .finally(function () { busy(btn, false); if (opts.modal) modalLoading(opts.modal, false); });
    }

    /** Executa uma ação com confirmação prévia (excluir, concluir, aprovar...). */
    function action(opts) {
        return confirmDialog(opts).then(function (yes) {
            if (!yes) return null;
            if (opts.button) busy(opts.button, true);
            return api(opts.url, { method: 'POST', data: opts.data || {} })
                .then(function (data) {
                    var msg = opts.successMessage || data.message || 'Concluído.';
                    if (opts.onSuccess) opts.onSuccess(data);
                    if (opts.reload !== false) reloadWith(msg, 'success'); else toast(msg, 'success');
                    return data;
                })
                .catch(function (err) { toast(err.message, 'error'); return null; })
                .finally(function () { if (opts.button) busy(opts.button, false); });
        });
    }

    /** Preenche campos de um formulário a partir de um objeto (name => valor). */
    function fillForm(form, data) {
        Object.keys(data || {}).forEach(function (k) {
            var el = form.elements[k];
            if (!el) return;
            var v = data[k] == null ? '' : data[k];
            if (el instanceof RadioNodeList) { Array.prototype.forEach.call(el, function (r) { r.checked = String(r.value) === String(v); }); return; }
            if (el.type === 'file') return;
            if (el.type === 'checkbox') { el.checked = !!v && v !== '0'; return; }
            if (el.type === 'datetime-local' && v) v = String(v).replace(' ', 'T').slice(0, 16);
            if (el.type === 'date' && v) v = String(v).slice(0, 10);
            el.value = v;
        });
    }

    function resetForm(form) {
        form.reset();
        Array.prototype.forEach.call(form.querySelectorAll('input[type=hidden]:not([name=_csrf])'), function (h) { h.value = ''; });
        Array.prototype.forEach.call(form.querySelectorAll('[data-file-label]'), function (l) { l.textContent = l.getAttribute('data-file-label'); });
    }

    function debounce(fn, ms) { var t; return function () { var a = arguments, s = this; clearTimeout(t); t = setTimeout(function () { fn.apply(s, a); }, ms); }; }

    /* ---------------- Componentes automáticos ---------------- */
    function initFileDrops(root) {
        Array.prototype.forEach.call((root || document).querySelectorAll('.file-drop'), function (fd) {
            if (fd._init) return; fd._init = true;
            var input = fd.querySelector('input[type=file]');
            var label = fd.querySelector('[data-file-label]');
            if (!input || !label) return;
            input.addEventListener('change', function () {
                label.textContent = input.files.length ? input.files[0].name : label.getAttribute('data-file-label');
            });
            ['dragenter', 'dragover'].forEach(function (e) { fd.addEventListener(e, function () { fd.classList.add('is-drag'); }); });
            ['dragleave', 'drop'].forEach(function (e) { fd.addEventListener(e, function () { fd.classList.remove('is-drag'); }); });
        });
    }

    function initShell() {
        var body = document.body;
        // Menu lateral (celular)
        document.addEventListener('click', function (ev) {
            if (ev.target.closest('[data-sidebar-open]')) body.classList.add('sidebar-open');
            else if (ev.target.closest('[data-sidebar-close]')) body.classList.remove('sidebar-open');
        });
        // Grupos recolhíveis
        Array.prototype.forEach.call(document.querySelectorAll('.nav-group.is-collapsible'), function (g) {
            var key = 'rd-nav-' + g.getAttribute('data-group');
            var btn = g.querySelector('.nav-toggle');
            try {
                var saved = localStorage.getItem(key);
                if (saved === 'open') g.classList.remove('is-collapsed');
                if (saved === 'closed' && !g.querySelector('.is-active')) g.classList.add('is-collapsed');
            } catch (e) {}
            if (btn) {
                btn.setAttribute('aria-expanded', g.classList.contains('is-collapsed') ? 'false' : 'true');
                btn.addEventListener('click', function () {
                    g.classList.toggle('is-collapsed');
                    var open = !g.classList.contains('is-collapsed');
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    try { localStorage.setItem(key, open ? 'open' : 'closed'); } catch (e) {}
                });
            }
        });
        // Dropdowns
        document.addEventListener('click', function (ev) {
            var tg = ev.target.closest('[data-dropdown-toggle]');
            var all = document.querySelectorAll('[data-dropdown].is-open');
            if (tg) {
                var dd = tg.closest('[data-dropdown]');
                var wasOpen = dd.classList.contains('is-open');
                Array.prototype.forEach.call(all, function (d) { d.classList.remove('is-open'); });
                if (!wasOpen) dd.classList.add('is-open');
                return;
            }
            if (!ev.target.closest('.dropdown-menu')) Array.prototype.forEach.call(all, function (d) { d.classList.remove('is-open'); });
        });
        // Tema
        document.addEventListener('click', function (ev) {
            if (!ev.target.closest('[data-theme-toggle]')) return;
            var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            try { localStorage.setItem('rd-theme', next); } catch (e) {}
            document.dispatchEvent(new CustomEvent('theme:changed', { detail: next }));
        });
        // Marcar todas notificações como lidas
        document.addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-notif-read-all]');
            if (!btn) return;
            busy(btn, true);
            api(BASE + 'includes/notificacoes_marcar_todas.php', { method: 'POST', data: {} })
                .then(function () { reloadWith('Notificações marcadas como lidas.', 'success'); })
                .catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
        });
        // Mensagem pendente de recarregamento
        try {
            var f = sessionStorage.getItem('rd-flash');
            if (f) { sessionStorage.removeItem('rd-flash'); f = JSON.parse(f); toast(f.m, f.t); }
        } catch (e) {}
        initFileDrops();
        initSearch();
        initFilterToggles();
        initAvisos();
        // Seleciona todo o texto ao focar nos campos de busca dos filtros
        Array.prototype.forEach.call(document.querySelectorAll('form.filters select'), function (s) {
            s.addEventListener('change', function () { if (s.hasAttribute('data-autosubmit')) s.form.submit(); });
        });
    }

    /* ---------------- Letreiro + mural de avisos (chat geral) ---------------- */
    function initAvisos() {
        var ticker = document.querySelector('[data-ticker]');
        var modal = document.getElementById('modalAvisos');
        if (!ticker || !modal) return;
        var lista = document.getElementById('avisos-lista');
        var form = document.getElementById('avisos-form');
        var txt = form.querySelector('textarea');
        var dot = ticker.querySelector('.ticker-dot');
        var track = ticker.querySelector('.ticker-track');
        var ultimo = parseInt(ticker.getAttribute('data-ultimo'), 10) || 0;
        var timer = null, ultimoNaLista = 0;
        var visto = 0;
        try { visto = parseInt(localStorage.getItem('rd-aviso-visto') || '0', 10); } catch (e) {}
        function marcarVisto(id) { visto = Math.max(visto, id); try { localStorage.setItem('rd-aviso-visto', String(visto)); } catch (e) {} dot.hidden = true; }
        dot.hidden = !(ultimo > visto);
        function ajustarVelocidade() {
            // Duplica os itens para o letreiro rolar sem intervalo vazio (a animação anda 50%)
            Array.prototype.forEach.call(track.querySelectorAll('.ticker-clone'), function (c) { c.remove(); });
            var itens = Array.prototype.slice.call(track.children);
            var vp = ticker.querySelector('.ticker-viewport').clientWidth || 800;
            var largura = track.scrollWidth || 1;
            var vezes = Math.max(1, Math.ceil(vp / largura));
            for (var v = 0; v < vezes; v++) itens.forEach(function (it) { var c = it.cloneNode(true); c.classList.add('ticker-clone'); c.setAttribute('aria-hidden', 'true'); track.appendChild(c); });
            // mantém as duas metades iguais
            if (vezes > 1) { var metade = Array.prototype.slice.call(track.children); metade.forEach(function (it) { var c = it.cloneNode(true); c.classList.add('ticker-clone'); c.setAttribute('aria-hidden', 'true'); track.appendChild(c); }); }
            track.style.setProperty('--ticker-dur', Math.max(20, Math.round(track.scrollWidth / 2 / 60)) + 's');
        }
        ajustarVelocidade();

        function render(avisos, append) {
            if (!append) lista.innerHTML = '';
            if (!append && !avisos.length) {
                lista.innerHTML = '<div class="empty" style="padding:40px 10px"><div class="empty-icon"><i class="fa-solid fa-bullhorn"></i></div><h3>Nenhum aviso ainda</h3><p>Escreva abaixo um recado para todos os usuários.</p></div>';
                return;
            }
            if (append && avisos.length && lista.querySelector('.empty')) lista.innerHTML = '';
            avisos.forEach(function (a) {
                if (lista.querySelector('[data-aviso="' + a.id + '"]')) return;
                var el = document.createElement('div');
                el.className = 'msg ' + (a.proprio ? 'is-self' : 'is-other');
                el.setAttribute('data-aviso', a.id);
                el.innerHTML = (a.proprio ? '' : '<span class="msg-author">' + esc(a.nome) + '</span>') + esc(a.mensagem) +
                    '<span class="msg-meta">' + esc(a.data) + (a.pode_excluir ? '<button type="button" class="aviso-del" data-del="' + a.id + '" title="Remover aviso" aria-label="Remover aviso"><i class="fa-regular fa-trash-can"></i></button>' : '') + '</span>';
                lista.appendChild(el);
                ultimoNaLista = Math.max(ultimoNaLista, a.id);
            });
            lista.scrollTop = lista.scrollHeight;
        }
        function carregar(append) {
            return api(BASE + 'includes/avisos.php' + (append ? '?apos=' + ultimoNaLista : ''))
                .then(function (d) { render(d.avisos || [], append); if (ultimoNaLista) marcarVisto(ultimoNaLista); })
                .catch(function (e) { if (!append) lista.innerHTML = '<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span>' + esc(e.message) + '</span></div>'; });
        }
        function abrir(focar) {
            openModal(modal);
            ultimoNaLista = 0;
            carregar(false).then(function () { if (focar) txt.focus(); });
            clearInterval(timer);
            timer = setInterval(function () { if (!document.hidden) carregar(true); }, 15000);
        }
        modal.addEventListener('modal:closed', function () { clearInterval(timer); });
        document.addEventListener('click', function (ev) {
            var o = ev.target.closest('[data-avisos-open]');
            if (o) { ev.preventDefault(); abrir(o.hasAttribute('data-avisos-focus')); }
        });
        ticker.querySelector('.ticker-viewport').addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); abrir(false); }
        });
        txt.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit')); }
        });
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var m = txt.value.trim();
            if (!m) { txt.focus(); return; }
            var btn = form.querySelector('[type=submit]');
            busy(btn, true);
            api(BASE + 'includes/avisos.php', { method: 'POST', data: { acao: 'enviar', mensagem: m } })
                .then(function () { txt.value = ''; return carregar(true); })
                .then(function () { atualizarLetreiro(); })
                .catch(function (e) { toast(e.message, 'error'); })
                .finally(function () { busy(btn, false); txt.focus(); });
        });
        lista.addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-del]');
            if (!b) return;
            confirmDialog({ title: 'Remover este aviso?', message: 'Ele deixará de aparecer no letreiro e no mural para todos.', confirmText: 'Remover' }).then(function (ok) {
                if (!ok) return;
                api(BASE + 'includes/avisos.php', { method: 'POST', data: { acao: 'excluir', id: b.getAttribute('data-del') } })
                    .then(function () { var el = lista.querySelector('[data-aviso="' + b.getAttribute('data-del') + '"]'); if (el) el.remove(); atualizarLetreiro(); toast('Aviso removido.', 'success'); })
                    .catch(function (e) { toast(e.message, 'error'); });
            });
        });
        function atualizarLetreiro() {
            return api(BASE + 'includes/avisos.php').then(function (d) {
                var av = (d.avisos || []).slice(-8).reverse();
                track.innerHTML = av.length ? av.map(function (a) {
                    return '<span class="ticker-item"><strong>' + esc(a.nome) + ':</strong> ' + esc(a.mensagem) + ' <small>' + esc(a.quando) + '</small></span>';
                }).join('') : '<span class="ticker-item ticker-empty">Nenhum aviso no momento. Clique aqui para escrever o primeiro.</span>';
                var novo = av.length ? av[0].id : 0;
                if (novo > ultimo) { ultimo = novo; if (!modal.classList.contains('is-open')) dot.hidden = !(ultimo > visto); }
                ajustarVelocidade();
            }).catch(function () {});
        }
        // Atualiza o letreiro a cada 2 minutos enquanto a aba estiver visível
        setInterval(function () { if (!document.hidden) atualizarLetreiro(); }, 120000);
    }

    /* Filtros recolhíveis no celular (mostra quantos filtros estão ativos) */
    function initFilterToggles() {
        Array.prototype.forEach.call(document.querySelectorAll('form.filters'), function (f) {
            var active = 0;
            Array.prototype.forEach.call(f.querySelectorAll('input:not([type=hidden]), select'), function (el) { if (el.value) active++; });
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'filters-toggle';
            b.setAttribute('aria-expanded', 'false');
            b.innerHTML = '<span><i class="fa-solid fa-sliders"></i> Filtros' + (active ? '<span class="n">' + active + '</span>' : '') + '</span><i class="fa-solid fa-chevron-down"></i>';
            b.addEventListener('click', function () {
                var open = f.classList.toggle('is-open');
                b.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            f.parentNode.insertBefore(b, f);
        });
    }

    /* ---------------- Busca global ---------------- */
    function initSearch() {
        var box = document.querySelector('[data-search]');
        if (!box) return;
        var input = box.querySelector('.search-input');
        var results = box.querySelector('.search-results');
        var items = [], focus = -1, lastQ = '', ctrl = null;
        var icons = { caixa: 'fa-box-archive', livro: 'fa-book', documento: 'fa-file-lines', solicitacao: 'fa-magnifying-glass', romaneio: 'fa-truck-ramp-box', pagina: 'fa-copy' };

        function close() { results.hidden = true; focus = -1; document.body.classList.remove('search-open'); }
        function render(groups) {
            items = [];
            var html = '';
            groups.forEach(function (g) {
                if (!g.items.length) return;
                html += '<div class="search-group-label">' + esc(g.label) + '</div>';
                g.items.forEach(function (it) {
                    items.push(it);
                    html += '<a class="search-item" href="' + esc(BASE + it.url) + '"><i class="fa-solid ' + (icons[g.type] || 'fa-file') + '"></i><span><span class="search-item-title">' + esc(it.title) + '</span><span class="search-item-sub">' + esc(it.sub || '') + '</span></span></a>';
                });
            });
            results.innerHTML = html || '<div class="search-empty">Nada encontrado para “' + esc(lastQ) + '”.</div>';
            results.hidden = false;
        }
        var run = debounce(function () {
            var q = input.value.trim();
            if (q.length < 2) { close(); return; }
            lastQ = q;
            results.innerHTML = '<div class="search-empty"><div class="spinner" style="margin:auto"></div></div>';
            results.hidden = false;
            if (ctrl && ctrl.abort) ctrl.abort();
            ctrl = window.AbortController ? new AbortController() : null;
            fetch(BASE + 'includes/busca_global.php?q=' + encodeURIComponent(q), { credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined, headers: { 'Accept': 'application/json' } })
                .then(function (r) {
                    if (r.status === 401) {
                        toast('Sua sessão expirou. Entre novamente para continuar.', 'error');
                        setTimeout(function () { window.location.href = BASE + 'php/login.php'; }, 1500);
                        throw { name: 'AbortError' };
                    }
                    return r.json();
                })
                .then(function (d) { if (q === input.value.trim()) render(d.groups || []); })
                .catch(function (e) { if (e.name !== 'AbortError') results.innerHTML = '<div class="search-empty">Não foi possível buscar agora.</div>'; });
        }, 250);
        input.addEventListener('input', run);
        input.addEventListener('focus', function () { if (input.value.trim().length >= 2 && results.innerHTML) results.hidden = false; });
        input.addEventListener('keydown', function (ev) {
            var links = results.querySelectorAll('.search-item');
            if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
                ev.preventDefault();
                if (!links.length) return;
                focus = (focus + (ev.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
                Array.prototype.forEach.call(links, function (l, i) { l.classList.toggle('is-focused', i === focus); });
                links[focus].scrollIntoView({ block: 'nearest' });
            } else if (ev.key === 'Enter' && links.length) {
                ev.preventDefault();
                window.location.href = links[Math.max(focus, 0)].href;
            } else if (ev.key === 'Escape') { input.blur(); close(); }
        });
        document.addEventListener('click', function (ev) {
            if (ev.target.closest('[data-search-open]')) { document.body.classList.add('search-open'); setTimeout(function () { input.focus(); }, 30); return; }
            if (!ev.target.closest('[data-search]')) close();
        });
        document.addEventListener('keydown', function (ev) {
            var tag = (ev.target.tagName || '').toLowerCase();
            if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'k') { ev.preventDefault(); document.body.classList.add('search-open'); input.focus(); input.select(); }
            else if (ev.key === '/' && tag !== 'input' && tag !== 'textarea' && tag !== 'select' && !ev.target.isContentEditable) { ev.preventDefault(); input.focus(); }
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initShell); else initShell();

    window.App = {
        esc: esc, toast: toast, flashNext: flashNext, reloadWith: reloadWith,
        openModal: openModal, closeModal: closeModal, modalLoading: modalLoading,
        confirm: confirmDialog, api: api, busy: busy, submitForm: submitForm, action: action,
        fillForm: fillForm, resetForm: resetForm, debounce: debounce, initFileDrops: initFileDrops,
        base: BASE, csrf: CSRF
    };
    // Compatibilidade com o código anterior
    window.showAlert = function (m, t, d) { toast(m, t, d); };
    window.abrirModal = openModal;
    window.fecharModal = closeModal;
})();
