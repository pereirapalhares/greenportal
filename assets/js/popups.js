(function (window, $) {
    'use strict';

    // Regra dos popups do portal:
    //  - todo popup de transação/conteúdo abre com 90% da largura e da altura da janela que o chamou
    //    (a aba/iframe de onde veio o clique ou o popup de trás, recursivamente) e centralizado sobre ela;
    //  - o popup aberto por último fica sempre na frente (inclusive mensagens e confirmações);
    //  - vale também para os modais das telas (dentro dos iframes): o portal instala a regra em cada iframe carregado.
    //  - altura: popup com conteúdo próprio (formulário, lista, texto) tem a altura do conteúdo — termina logo abaixo do
    //    último campo — limitada a 90% da janela; passando disso, barra de rolagem vertical no corpo (título e botões fixos).
    //    Popup que carrega outra tela (iframe) não tem altura própria: fica com os 90%.
    // Caixas de diálogo (mensagem, confirmação, escolha de colunas, "carregando") mantêm o tamanho próprio, mas sempre na frente.

    function T(chave, padrao) {
        return typeof window.greenT === 'function' ? window.greenT('portal.js.' + chave, padrao) : padrao;
    }

    function TA(chave, padrao) {
        return String(T(chave, padrao)).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }

    function modal(id) {
        var element = document.getElementById(id);
        return element && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(element) : null;
    }

    var sequencia = 0;

    var CSS = '.popup-90:not(.popup-maximized):not(.popup-minimized)>.modal-dialog{width:var(--p90-w)!important;max-width:var(--p90-w)!important;height:var(--p90-h)!important;max-height:var(--p90-h)!important;min-height:0!important;margin:var(--p90-y) 0 0 var(--p90-x)!important}'
        + '.popup-90:not(.popup-minimized)>.modal-dialog>.modal-content{height:100%!important;max-height:none!important;display:flex;flex-direction:column;overflow:hidden}'
        + '.popup-90.popup-maximized>.modal-dialog>.modal-content{height:100vh!important}'
        + '.popup-90:not(.popup-minimized)>.modal-dialog>.modal-content>.modal-body,.popup-90:not(.popup-minimized)>.modal-dialog>.modal-content>form>.modal-body{flex:1 1 auto;min-height:0!important;max-height:none!important;overflow:auto}'
        + '.popup-90:not(.popup-minimized)>.modal-dialog>.modal-content>form{display:flex;flex-direction:column;flex:1 1 auto;min-height:0}'
        + '.popup-90 .modal-body:has(>iframe){overflow:hidden!important}'
        + '.popup-90 .modal-body>iframe{width:100%!important;height:100%!important;border:0;display:block}'
        // conteúdo próprio: altura pelo conteúdo, no máximo 90%
        + '.popup-90.popup-ajusta:not(.popup-maximized):not(.popup-minimized)>.modal-dialog{height:auto!important;max-height:var(--p90-h)!important}'
        + '.popup-90.popup-ajusta:not(.popup-maximized):not(.popup-minimized)>.modal-dialog>.modal-content{height:auto!important;max-height:var(--p90-h)!important}';

    function estilo(doc) {
        if (!doc || !doc.head || doc.getElementById('popup-90-css')) return;
        var st = doc.createElement('style'); st.id = 'popup-90-css'; st.textContent = CSS; doc.head.appendChild(st);
    }

    function ehDialogo(el) {
        return el.hasAttribute('data-popup-mensagem') || el.hasAttribute('data-popup-livre') || el.classList.contains('grid-loading-modal');
    }

    // Popup de conteúdo (transação, documento, visualizador...) segue os 90%; caixa de diálogo pequena mantém o tamanho.
    function ehConteudo(el) {
        if (ehDialogo(el)) return false;
        if (el.hasAttribute('data-popup-transacao') || el.hasAttribute('data-popup-transacao-independent') || el.hasAttribute('data-popup-90')) return true;
        if (el.classList.contains('green-portal-popup-transacao') || el.classList.contains('requisitos-modal-90')) return true;
        var d = el.querySelector(':scope > .modal-dialog');
        return !!(d && /\bmodal-(lg|xl|fullscreen)\b/.test(d.className));
    }

    // O popup carrega outra tela (iframe no corpo)? Então não tem altura própria e fica com os 90%.
    function temTela(el) {
        if (el.hasAttribute('data-popup-transacao') || el.hasAttribute('data-popup-transacao-independent') || el.classList.contains('green-portal-popup-transacao')) return true;
        return !!el.querySelector('.modal-body iframe, .modal-content > iframe');
    }

    // Popup com outra tela (iframe): se a página tem altura natural (formulário curto, perfil, senha), o popup encolhe até
    // o fim do conteúdo; tela que ocupa a altura toda (grid) continua com os 90%. Só na mesma origem (sem acesso, fica 90%).
    function ajustarTela(el) {
        if (!el.classList.contains('popup-90') || el.classList.contains('popup-maximized') || el.classList.contains('popup-minimized')) return;
        var f = el.querySelector('.modal-body iframe, .modal-content > iframe'), d = el.querySelector(':scope > .modal-dialog');
        if (!f || !d) return;
        var doc; try { doc = f.contentDocument; } catch (e) { return; }
        if (!doc || !doc.body || doc.readyState === 'loading') return;
        // Documento que não é página (PDF, imagem: visualizador do navegador) não tem altura natural: fica com os 90% (c528)
        if (doc.contentType && !/html/i.test(doc.contentType)) return;
        // Fim do conteúdo = o ponto mais baixo dos elementos visíveis da página (o body pode ter altura mínima de 100%)
        var cw = f.contentWindow, alto = 0, sy = cw.scrollY || 0;
        (function medir(pai, nivel) {
            Array.prototype.forEach.call(pai.children, function (x) {
                if (/^(SCRIPT|STYLE|LINK|META|TEMPLATE)$/.test(x.tagName)) return;
                var cs = cw.getComputedStyle(x);
                if (cs.display === 'none' || cs.position === 'fixed' || x.classList.contains('modal')) return;
                var r = x.getBoundingClientRect();
                if (!r.height) return;
                // contêiner que estica até o fim da página (flex/100%): mede os filhos dele
                if (nivel < 3 && r.bottom >= cw.innerHeight - 2 && x.children.length) { medir(x, nivel + 1); return; }
                alto = Math.max(alto, r.bottom + sy + (parseFloat(cs.marginBottom) || 0));
            });
        }(doc.body, 0));
        alto = Math.ceil(alto + (parseFloat(cw.getComputedStyle(doc.body).paddingBottom) || 0) + 8);
        if (!alto || alto >= cw.innerHeight - 2) return;                       // ocupa a altura toda: fica com 90%
        var corpo = f.parentElement, extra = 0;
        Array.prototype.forEach.call(d.querySelectorAll(':scope > .modal-content > :not(.modal-body)'), function (x) { extra += x.offsetHeight; });
        if (corpo && corpo !== d) { var cs = getComputedStyle(corpo); extra += (parseFloat(cs.paddingTop) || 0) + (parseFloat(cs.paddingBottom) || 0); }
        var max = parseFloat(el.style.getPropertyValue('--p90-h')) || d.offsetHeight;
        var h = Math.min(max, Math.max(160, alto + extra + 2));
        if (h < max - 4) el.style.setProperty('--p90-h', Math.round(h) + 'px');
    }

    function ordem(el) { return Number(el.getAttribute('data-popup-ordem')) || 0; }

    function abertos(doc) {
        return Array.prototype.slice.call(doc.querySelectorAll('.modal.show, .modal[data-popup-abrindo]')).sort(function (a, b) { return ordem(a) - ordem(b); });
    }

    // Janela de quem chamou: o iframe com o foco (aba ou popup onde houve o clique), senão o popup de trás, senão a janela.
    function origem(doc, el) {
        var ae = doc.activeElement;
        if (ae && ae.tagName === 'IFRAME' && !el.contains(ae)) {
            var r = ae.getBoundingClientRect();
            if (r.width > 60 && r.height > 60) return ae;
        }
        var pais = abertos(doc).filter(function (m) { return m !== el && !ehDialogo(m); });
        return pais.length ? pais[pais.length - 1].querySelector(':scope > .modal-dialog') : null;
    }

    function dimensionar(el, win) {
        var vw = win.innerWidth, vh = win.innerHeight, o = el._p90Origem, r = null, b;
        if (o && o.isConnected) { b = o.getBoundingClientRect(); if (b.width > 60 && b.height > 60) r = b; }
        if (!r) r = { left: 0, top: 0, width: vw, height: vh };
        var a = el._p90Area && el._p90Area.width > 0 ? el._p90Area : r;
        var w = Math.round(Math.min(a.width, vw) * 0.9), h = Math.round(Math.min(a.height, vh) * 0.9);
        w = Math.max(Math.min(420, vw - 16), w); h = Math.max(Math.min(300, vh - 16), h);
        var x = Math.round(Math.max(8, Math.min(vw - w - 8, (r.left || 0) + (r.width - w) / 2)));
        var y = Math.round(Math.max(8, Math.min(vh - h - 8, (r.top || 0) + (r.height - h) / 2)));
        el.style.setProperty('--p90-w', w + 'px'); el.style.setProperty('--p90-h', h + 'px');
        el.style.setProperty('--p90-x', x + 'px'); el.style.setProperty('--p90-y', y + 'px');
        el.classList.add('popup-90');
        el.classList.toggle('popup-ajusta', !temTela(el));
        if (temTela(el)) {
            ajustarTela(el);
            var f = el.querySelector('.modal-body iframe, .modal-content > iframe');
            if (f && !f._p90Ajuste) {
                f._p90Ajuste = 1;
                var refazer = function () { if (el.classList.contains('show') || el.hasAttribute('data-popup-abrindo')) dimensionar(el, win); };
                var vigiar = function () {
                    // conteúdo que chega depois (AJAX): reavalia a altura quando a página muda
                    try {
                        if (f._p90Obs) f._p90Obs.disconnect();
                        var t = null, cd = f.contentDocument;
                        if (!cd || !cd.body || !win.MutationObserver) return;
                        f._p90Obs = new win.MutationObserver(function () { win.clearTimeout(t); t = win.setTimeout(refazer, 200); });
                        f._p90Obs.observe(cd.body, { childList: true, subtree: true });
                    } catch (e) {}
                };
                f.addEventListener('load', function () { win.setTimeout(refazer, 60); vigiar(); });
                vigiar();
                el.addEventListener('hidden.bs.modal', function () { if (f._p90Obs) { f._p90Obs.disconnect(); f._p90Obs = null; } });
            }
        }
    }

    // Empilhamento pela ordem de abertura: o último aberto fica na frente, com o fundo escuro logo atrás dele.
    function empilhar(doc, win) {
        var backdrops = Array.prototype.slice.call(doc.querySelectorAll('.modal-backdrop')), bi = 0;
        abertos(doc).forEach(function (m, i) {
            var z = 1050 + i * 20, inst = null;
            m.style.zIndex = String(z); m.setAttribute('data-popup-level', String(i));
            try { inst = win.bootstrap && win.bootstrap.Modal.getInstance(m); } catch (e) { inst = null; }
            var temFundo = !(inst && inst._config && inst._config.backdrop === false);
            if (temFundo && backdrops[bi]) { backdrops[bi].style.zIndex = String(z - 10); bi++; }
        });
    }

    function instalar(win) {
        var doc;
        try { doc = win.document; if (!doc || doc.__greenPopups) return; doc.__greenPopups = 1; } catch (e) { return; }
        estilo(doc.head ? doc : null);
        if (!doc.head) doc.addEventListener('DOMContentLoaded', function () { estilo(doc); });
        doc.addEventListener('show.bs.modal', function (ev) {
            var m = ev.target;
            if (ev.defaultPrevented || !m || !m.classList || !m.classList.contains('modal')) return;
            m.setAttribute('data-popup-ordem', String(++sequencia)); m.setAttribute('data-popup-abrindo', '1');
            if (ehConteudo(m) && !m.hasAttribute('data-popup-livre-tamanho')) { estilo(doc); m._p90Origem = origem(doc, m); dimensionar(m, win); }
            m.style.zIndex = String(1050 + abertos(doc).length * 20);
            win.setTimeout(function () { empilhar(doc, win); }, 0);
        }, true);
        doc.addEventListener('shown.bs.modal', function (ev) {
            if (ev.target && ev.target.removeAttribute) ev.target.removeAttribute('data-popup-abrindo');
            if (ev.target && ev.target.classList && ev.target.classList.contains('popup-90')) ev.target.classList.toggle('popup-ajusta', !temTela(ev.target));
            empilhar(doc, win);
        }, true);
        doc.addEventListener('hidden.bs.modal', function (ev) {
            var m = ev.target;
            if (m && m.removeAttribute) { m.removeAttribute('data-popup-abrindo'); m.removeAttribute('data-popup-livre-tamanho'); m._p90Origem = null; m._p90Area = null; }
            win.setTimeout(function () { empilhar(doc, win); }, 0);
        }, true);
        win.addEventListener('resize', function () {
            Array.prototype.forEach.call(doc.querySelectorAll('.modal.show.popup-90'), function (m) { dimensionar(m, win); });
        });
        // iframes (abas, popups e iframes internos das telas): cada documento carregado recebe a mesma regra
        doc.addEventListener('load', function (ev) {
            if (ev.target && ev.target.tagName === 'IFRAME') { try { instalar(ev.target.contentWindow); } catch (e) {} }
        }, true);
        Array.prototype.forEach.call(doc.querySelectorAll('iframe'), function (f) {
            try { if (f.contentDocument && f.contentDocument.readyState !== 'loading') instalar(f.contentWindow); } catch (e) {}
        });
    }

    function visibleParent() {
        var lista = abertos(document).filter(function (m) { return m.matches('[data-popup-transacao-independent], [data-popup-transacao], [data-popup-mensagem]'); });
        return $(lista.length ? lista[lista.length - 1] : []);
    }

    function stackPopups() { empilhar(document, window); }

    function viewport() {
        return { width: Math.max(320, window.innerWidth - 24), height: Math.max(240, window.innerHeight - 24) };
    }

    // Tamanho livre (depois que o usuário arrasta o canto): mantém o que ele definiu
    function fit($modal) {
        if (!$modal || !$modal.length || $modal.hasClass('popup-90') || $modal.hasClass('popup-maximized') || $modal.hasClass('popup-minimized')) return;
        var $dialog = $modal.find('.modal-dialog');
        $modal.find('.modal-body').css({ minHeight: 0, maxHeight: Math.max(120, $dialog.outerHeight() - (($modal.find('.modal-header').outerHeight() || 48) + ($modal.find('.modal-footer').outerHeight() || 0))) + 'px', overflow: 'auto' });
    }

    function controls(redimensionavel) {
        if (!redimensionavel) return '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="' + TA('fechar', 'Fechar') + '"></button>';
        return '<div class="d-flex align-items-center gap-1 ms-auto"><button type="button" class="btn btn-sm btn-outline-secondary" data-popup-minimize title="' + TA('minimizar', 'Minimizar') + '" aria-label="' + TA('minimizar', 'Minimizar') + '"><i class="bi bi-dash-lg"></i></button><button type="button" class="btn btn-sm btn-outline-secondary" data-popup-maximize title="' + TA('maximizar', 'Maximizar') + '" aria-label="' + TA('maximizar', 'Maximizar') + '"><i class="bi bi-arrows-fullscreen"></i></button><button type="button" class="btn-close ms-1" data-bs-dismiss="modal" aria-label="' + TA('fechar', 'Fechar') + '"></button></div>';
    }

    function bindResize($modal, instance, options) {
        if (!options.redimensionavel) return;
        var $dialog = $modal.find('.modal-dialog'), resizing = false, startX, startY, startWidth, startHeight;
        function stopResize() { if (!resizing) return; resizing = false; $(document).off('mousemove.popupResize mouseup.popupResize'); }
        $modal.on('mousedown.popupResize', '.popup-resize-handle', function (event) {
            if ($modal.hasClass('popup-maximized') || $modal.hasClass('popup-minimized')) return;
            event.preventDefault();
            if ($modal.hasClass('popup-90')) {
                // A partir daqui o tamanho é do usuário: congela posição e tamanho atuais e sai dos 90%
                var el = $modal[0];
                $dialog.css({ width: $dialog.outerWidth() + 'px', maxWidth: $dialog.outerWidth() + 'px', height: $dialog.outerHeight() + 'px', maxHeight: $dialog.outerHeight() + 'px', minHeight: 0, margin: el.style.getPropertyValue('--p90-y') + ' 0 0 ' + el.style.getPropertyValue('--p90-x') });
                $modal.removeClass('popup-90').attr('data-popup-livre-tamanho', '1');
                $dialog.find('.modal-content').css({ height: '100%', display: 'flex', flexDirection: 'column' });
                $dialog.find('.modal-body').css({ flex: '1 1 auto', minHeight: 0 });
            }
            resizing = true; startX = event.clientX; startY = event.clientY; startWidth = $dialog.outerWidth(); startHeight = $dialog.outerHeight();
            $(document).on('mousemove.popupResize', function (moveEvent) {
                if (!resizing) return;
                var view = viewport(), width = Math.max(420, Math.min(view.width, startWidth + moveEvent.clientX - startX)), height = Math.max(260, Math.min(view.height, startHeight + moveEvent.clientY - startY));
                $dialog.css({ width: width + 'px', maxWidth: width + 'px', height: height + 'px', maxHeight: height + 'px' });
            }).on('mouseup.popupResize', stopResize);
        });
        $modal.on('click.popupResize', '[data-popup-minimize]', function () {
            var minimized = !$modal.hasClass('popup-minimized'); $modal.toggleClass('popup-minimized', minimized);
            $(this).attr({ title: minimized ? T('restaurar', 'Restaurar') : T('minimizar', 'Minimizar'), 'aria-label': minimized ? T('restaurar', 'Restaurar') : T('minimizar', 'Minimizar') }).find('i').attr('class', minimized ? 'bi bi-plus-lg' : 'bi bi-dash-lg');
        });
        $modal.on('click.popupResize', '[data-popup-maximize]', function () {
            var maximized = !$modal.hasClass('popup-maximized'); $modal.toggleClass('popup-maximized', maximized).removeClass('popup-minimized');
            $(this).attr({ title: maximized ? T('restaurar', 'Restaurar') : T('maximizar', 'Maximizar'), 'aria-label': maximized ? T('restaurar', 'Restaurar') : T('maximizar', 'Maximizar') }).find('i').attr('class', maximized ? 'bi bi-fullscreen-exit' : 'bi bi-arrows-fullscreen');
        });
        $modal.find('.popup-resize-handle').on('keydown', function (event) { if (event.key === 'Escape') stopResize(); });
    }

    instalar(window);

    window.GreenPortalPopups = {
        mensagem: function (opcoes) {
            opcoes = opcoes || {}; var id = opcoes.id || 'greenPortalMensagemModal', $modal = $('#' + id); if (!$modal.length) return;
            $modal.find('[data-popup-mensagem-title]').text(opcoes.titulo || T('mensagem', 'Mensagem'));
            $modal.find('[data-popup-mensagem-body]').html(opcoes.html || $('<div>').text(opcoes.mensagem || '').html());
            $modal.find('[data-popup-mensagem-icon]').attr('class', 'bi me-2 ' + (opcoes.icone || 'bi-info-circle'));
            var $confirm = $modal.find('[data-popup-mensagem-confirm]'), instance = modal(id);
            $confirm.toggleClass('d-none', typeof opcoes.confirmar !== 'function').off('click').on('click', function () { if (instance) instance.hide(); if (typeof opcoes.confirmar === 'function') opcoes.confirmar(); });
            if (instance) {
                // Mensagem já aberta e reaproveitada: vai para a frente de novo
                if ($modal.hasClass('show')) { $modal.attr('data-popup-ordem', String(++sequencia)); stackPopups(); }
                instance.show();
            }
        },
        // Popup único do portal (#greenPortalTransacaoModal). Chamado de dentro de outro popup, abre um popup próprio
        // empilhado na frente, em vez de reaproveitar o único. O tamanho (90% de quem chamou) é aplicado na abertura.
        transacao: function (opcoes) {
            opcoes = opcoes || {}; var id = opcoes.id || 'greenPortalTransacaoModal', $modal = $('#' + id); if (!$modal.length) return;
            var $pai = visibleParent();
            if (!opcoes.id && $pai.length) return this.transacaoIndependente({ titulo: opcoes.titulo, conteudo: opcoes.conteudo, area: opcoes.area, redimensionavel: true });
            $modal.find('[data-popup-transacao-title]').text(opcoes.titulo || T('transacao', 'Transação')).end().find('[data-popup-transacao-body]').empty().append(opcoes.conteudo || $('<div>').text(T('carregando', 'Carregando...')));
            var instance = modal(id); if (!instance) return;
            $modal[0]._p90Area = opcoes.area || null;
            $modal.off('hidden.bs.modal.popupCleanup').on('hidden.bs.modal.popupCleanup', function () { $(this).find('[data-popup-transacao-body]').empty(); });
            instance.show();
            return $modal;
        },
        // Mantido por compatibilidade: hoje todo popup já abre com 90% de quem chamou. opcoes: titulo, url | conteudo, area
        transacao90: function (opcoes) {
            opcoes = opcoes || {};
            var conteudo = opcoes.conteudo || $('<iframe>').attr('src', opcoes.url || 'about:blank');
            return this.transacaoIndependente({ titulo: opcoes.titulo, conteudo: conteudo, area: opcoes.area, redimensionavel: true });
        },
        // Popup próprio empilhado (opcoes: titulo, conteudo, redimensionavel, area). compacto/amplo não mudam mais o tamanho.
        transacaoIndependente: function (opcoes) {
            opcoes = opcoes || {};
            var id = 'greenPortalTransacaoModal_' + Date.now() + '_' + Math.floor(Math.random() * 10000);
            var options = { redimensionavel: !!opcoes.redimensionavel };
            var $modal = $('<div class="modal fade" tabindex="-1" data-popup-transacao-independent="1"></div>').attr('id', id);
            if (visibleParent().length) $modal.addClass('popup-child');
            var resizeHandle = options.redimensionavel ? '<span class="popup-resize-handle" title="' + TA('redimensionar', 'Redimensionar') + '" aria-label="' + TA('redimensionar', 'Redimensionar') + '" tabindex="0"></span>' : '';
            $modal.addClass(options.redimensionavel ? 'green-portal-popup-resizable' : '').html('<div class="modal-dialog"><div class="modal-content"><div class="modal-header py-2"><h5 class="modal-title"></h5>' + controls(options.redimensionavel) + '</div><div class="modal-body p-0"></div>' + resizeHandle + '</div></div>');
            $modal.find('.modal-title').text(opcoes.titulo || T('transacao', 'Transação'));
            $modal.find('.modal-body').append(opcoes.conteudo || $('<div>').text(T('carregando', 'Carregando...')));
            $modal.appendTo('body');
            $modal[0]._p90Area = opcoes.area || null;
            var instance = modal(id); if (!instance) return;
            bindResize($modal, instance, options);
            $modal.on('hidden.bs.modal', function () { instance.dispose(); $modal.remove(); });
            instance.show();
            window.setTimeout(function () { fit($modal); }, 0);
            return $modal;
        }
    };
}(window, jQuery));
