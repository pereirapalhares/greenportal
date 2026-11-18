/*
 * Não Conformidades › Administração (migração 333): apoio das telas de listas de distribuição, participantes e e-mails.
 *  - GPNcAdmin.abrir(url, titulo): popup padrão do portal (GreenPortalPopups.transacaoIndependente); ao fechar, recarrega a tela
 *    que abriu (para o grid mostrar o que foi gravado);
 *  - GPNcAdmin.gravar(acao, dados): POST em api/nc_administracao.php com o token da tela (<body data-csrf>);
 *  - GPNcAdmin.aviso(texto, tipo): mensagem no topo da tela ([data-nc-aviso]).
 */
(function (window, document) {
    'use strict';
    function T(k, p) { return typeof window.greenT === 'function' ? window.greenT('naoconformidades.js.' + k, p) : p; }
    var host = window.top || window;

    function abrir(url, titulo) {
        var abs = new URL(url, window.location.href).toString();
        if (host.GreenPortalPopups && host.jQuery && typeof host.GreenPortalPopups.transacaoIndependente === 'function') {
            var frame = host.jQuery('<iframe>', { title: titulo || '', style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', abs);
            var $m = host.GreenPortalPopups.transacaoIndependente({ titulo: titulo || '', conteudo: frame, amplo: true, redimensionavel: true });
            if ($m && $m.on) $m.on('hidden.bs.modal', function () { window.location.reload(); });
        } else {
            window.open(abs, '_blank');
        }
    }

    function gravar(acao, dados) {
        var corpo = Object.assign({ acao: acao }, dados || {});
        var base = (document.body.getAttribute('data-api') || 'api/nc_administracao.php');
        return fetch(base, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.body.getAttribute('data-csrf') || '' },
            body: JSON.stringify(corpo)
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, erro: T('resposta_invalida_cod', 'Resposta inválida do servidor ({status}).').replace('{status}', r.status) }; });
        }).then(function (j) {
            if (!j || !j.ok) throw new Error((j && j.erro) || T('nao_gravou', 'Não foi possível gravar.'));
            return j.resultado || {};
        });
    }

    function aviso(texto, tipo) {
        var el = document.querySelector('[data-nc-aviso]');
        if (!el) { if (texto) window.alert(texto); return; }
        el.className = 'alert py-2 mb-2 alert-' + (tipo || 'danger');
        el.textContent = texto || '';
        el.hidden = !texto;
        if (texto) el.scrollIntoView({ block: 'nearest' });
    }

    // Botões da barra que abrem o popup: [data-nc-abrir="url"] (título em data-nc-titulo);
    // [data-nc-abrir-selecionado]: abre a linha selecionada do grid (tr[data-nc-url]); duplo clique na linha também.
    document.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-nc-abrir]');
        if (b && !b.disabled) { ev.preventDefault(); abrir(b.getAttribute('data-nc-abrir'), b.getAttribute('data-nc-titulo')); return; }
        var s = ev.target.closest('[data-nc-abrir-selecionado]');
        if (s && !s.disabled) {
            ev.preventDefault();
            var tr = document.querySelector('tr[data-row].selected[data-nc-url]');
            if (tr) abrir(tr.getAttribute('data-nc-url'), tr.getAttribute('data-nc-titulo'));
            return;
        }
        var tr2 = ev.target.closest('tr[data-row][data-nc-url]');
        if (tr2) {
            var botao = document.querySelector('[data-nc-abrir-selecionado]');
            if (botao) botao.disabled = false;
        }
    });
    document.addEventListener('dblclick', function (ev) {
        var tr = ev.target.closest('tr[data-row][data-nc-url]');
        if (tr) abrir(tr.getAttribute('data-nc-url'), tr.getAttribute('data-nc-titulo'));
    });

    // Fecha o popup em que a tela está (ex.: depois de excluir a lista)
    function fechar() {
        var f = window.frameElement, m = f && f.closest ? f.closest('.modal') : null;
        if (m && host.bootstrap && host.bootstrap.Modal) host.bootstrap.Modal.getOrCreateInstance(m).hide();
        else window.close();
    }

    // Confirmação no popup de mensagem do portal (GreenPortalPopups.mensagem); fora do portal, confirm do navegador
    function confirmar(titulo, texto, fn) {
        if (host.GreenPortalPopups && typeof host.GreenPortalPopups.mensagem === 'function' && host.document.getElementById('greenPortalMensagemModal')) {
            host.GreenPortalPopups.mensagem({ titulo: titulo, mensagem: texto, icone: 'bi-exclamation-triangle', confirmar: fn });
        } else if (window.confirm(texto)) fn();
    }

    window.GPNcAdmin = { abrir: abrir, gravar: gravar, aviso: aviso, fechar: fechar, confirmar: confirmar };
}(window, document));
