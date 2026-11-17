/* Calendário de pendências (principal/painel_calendario.php): visões Dia, Mês e Ano.
   Os itens vêm de painel_calendario.php?dados=1&ano=AAAA (um pedido por ano, guardado em memória). Cada pendência tem
   data-abrir/data-titulo e abre em popup do portal por assets/js/painel-lateral.js (90% da tela, sempre na frente). */
(function () {
    'use strict';
    var C = window.GP_CALENDARIO || {}, X = C.txt || {};
    var estado = { visao: C.visao || 'mes', data: C.data || C.hoje, filtro: '', pessoa: '' };
    var anos = {}, pedidos = {};
    var corpo = document.getElementById('calCorpo'), titulo = document.getElementById('calTitulo'), filtro = document.getElementById('calFiltro'), pessoa = document.getElementById('calPessoa');

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function fmt(s, n) { return String(s).replace('%d', n).replace('%s', n); }
    function d(s) { var p = s.split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])); }
    function iso(dt) { return dt.toISOString().slice(0, 10); }
    function soma(s, dias) { var dt = d(s); dt.setUTCDate(dt.getUTCDate() + dias); return iso(dt); }
    function somaMes(s, m) { var dt = d(s), dia = dt.getUTCDate(); dt.setUTCDate(1); dt.setUTCMonth(dt.getUTCMonth() + m); var fim = new Date(Date.UTC(dt.getUTCFullYear(), dt.getUTCMonth() + 1, 0)).getUTCDate(); dt.setUTCDate(Math.min(dia, fim)); return iso(dt); }
    function dataLonga(s) { var dt = d(s); return C.idioma === 'en' ? C.semanaLonga[dt.getUTCDay()] + ', ' + C.meses[dt.getUTCMonth()] + ' ' + dt.getUTCDate() + ', ' + dt.getUTCFullYear() : C.semanaLonga[dt.getUTCDay()] + ', ' + dt.getUTCDate() + ' de ' + C.meses[dt.getUTCMonth()].toLowerCase() + ' de ' + dt.getUTCFullYear(); }
    function dataCurta(s) { var p = s.split('-'); return C.idioma === 'en' ? p[1] + '/' + p[2] + '/' + p[0] : p[2] + '/' + p[1] + '/' + p[0]; }

    function carregar(ano) {
        if (anos[ano]) return Promise.resolve(anos[ano]);
        if (pedidos[ano]) return pedidos[ano];
        pedidos[ano] = fetch(window.location.pathname + '?dados=1&ano=' + ano, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { if (r.status === 401) { window.location.reload(); throw new Error('401'); } if (!r.ok) throw new Error(String(r.status)); return r.json(); })
            .then(function (j) {
                var porDia = {};
                (j.itens || []).forEach(function (i) { (porDia[i.data] = porDia[i.data] || []).push(i); });
                anos[ano] = porDia; delete pedidos[ano]; return porDia;
            }, function (e) { delete pedidos[ano]; throw e; });
        return pedidos[ano];
    }
    function doDia(s) {
        var lista = (anos[+s.slice(0, 4)] || {})[s] || [];
        return lista.filter(function (i) { return (!estado.filtro || i.tipo === estado.filtro) && (!estado.pessoa || String(i.usuario) === estado.pessoa); });
    }
    function situacaoDia(lista) {
        if (!lista.length) return '';
        for (var k = 0; k < lista.length; k++) if (lista[k].situacao === 'ATRASADO') return 'atrasado';
        for (k = 0; k < lista.length; k++) if (lista[k].situacao === 'HOJE') return 'hoje';
        return 'proximo';
    }
    function resp(i) { return C.equipe && i.responsavel ? X.responsavel + ': ' + i.responsavel : ''; }
    function attrs(i) {
        var t = esc(i.rotulo + ' - ' + i.titulo), r = resp(i) ? ' · ' + resp(i) : '';
        return i.link ? ' data-abrir="' + esc(i.link) + '" data-titulo="' + t + '" tabindex="0" role="link" title="' + esc(i.titulo + (i.detalhe ? ' · ' + i.detalhe : '') + r) + '"'
            : ' title="' + esc(i.titulo + r + ' · ' + X.semLink) + '"';
    }
    function chip(i) {
        return '<span class="cal-chip ' + i.situacao.toLowerCase() + (i.link ? '' : ' sem-link') + '"' + attrs(i) + '><i class="bi ' + esc(i.icone) + '"></i>' + esc(i.titulo) + '</span>';
    }
    function linhaItem(i) {
        return '<div class="cal-item ' + i.situacao.toLowerCase() + (i.link ? '' : ' sem-link') + '"' + attrs(i) + '>'
            + '<span class="cal-item-ic"><i class="bi ' + esc(i.icone) + '"></i></span>'
            + '<span class="cal-item-txt"><strong>' + esc(i.titulo) + (i.link ? ' <i class="bi bi-box-arrow-up-right painel-abrir"></i>' : '') + '</strong>'
            + '<small>' + esc(i.rotulo) + (i.detalhe ? ' · ' + esc(i.detalhe) : '') + '</small>'
            + (resp(i) ? '<small class="cal-resp' + (i.usuario === C.eu ? ' eu' : '') + '"><i class="bi bi-person"></i> ' + esc(i.responsavel) + '</small>' : '') + '</span>'
            + '<span class="cal-item-sit">' + esc(X[i.situacao] || '') + '</span></div>';
    }
    function listaDia(s) {
        var l = doDia(s);
        return '<h4 class="cal-lista-tit">' + esc(fmt(X.pendenciasDe, dataLonga(s))) + ' <span>' + l.length + '</span></h4>'
            + (l.length ? '<div class="cal-lista">' + l.map(linhaItem).join('') + '</div>' : '<div class="cal-vazio">' + esc(X.nenhum) + '</div>');
    }

    function mes() {
        var dt = d(estado.data), ano = dt.getUTCFullYear(), m = dt.getUTCMonth();
        titulo.textContent = C.meses[m] + ' ' + ano;
        var primeiro = new Date(Date.UTC(ano, m, 1)), ini = iso(primeiro), off = primeiro.getUTCDay();
        var h = '<div class="cal-mes-wrap"><div class="cal-mes"><div class="cal-semana">' + C.semana.map(function (s) { return '<span>' + esc(s) + '</span>'; }).join('') + '</div><div class="cal-grade">';
        for (var c = 0; c < 42; c++) {
            var s = soma(ini, c - off), dentro = +s.slice(5, 7) === m + 1, l = dentro ? doDia(s) : [];
            if (c === 35 && !dentro) break;   // não mostra a 6ª semana quando ela é toda do mês seguinte
            h += '<div class="cal-dia' + (dentro ? '' : ' fora') + (s === C.hoje ? ' hoje' : '') + (s === estado.data ? ' sel' : '') + (l.length ? ' tem ' + situacaoDia(l) : '') + '" data-dia="' + s + '">'
                + '<span class="cal-num">' + (+s.slice(8)) + (l.length ? '<em>' + l.length + '</em>' : '') + '</span>'
                + l.slice(0, 3).map(chip).join('')
                + (l.length > 3 ? '<span class="cal-mais" data-ver-dia="' + s + '">' + esc(fmt(X.mais, l.length - 3)) + '</span>' : '') + '</div>';
        }
        h += '</div></div><aside class="cal-lado">' + listaDia(estado.data) + '<button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-ver-dia="' + estado.data + '"><i class="bi bi-calendar-day me-1"></i>' + esc(X.verDia) + '</button></aside></div>';
        corpo.innerHTML = h;
    }
    function dia() {
        titulo.textContent = dataLonga(estado.data);
        corpo.innerHTML = '<div class="cal-dia-visao">' + listaDia(estado.data) + '</div>';
    }
    function ano() {
        var a = +estado.data.slice(0, 4), total = 0;
        titulo.textContent = String(a);
        var h = '<div class="cal-ano">';
        for (var m = 0; m < 12; m++) {
            var primeiro = new Date(Date.UTC(a, m, 1)), off = primeiro.getUTCDay(), fim = new Date(Date.UTC(a, m + 1, 0)).getUTCDate(), nMes = 0, cel = '';
            for (var k = 0; k < off; k++) cel += '<span></span>';
            for (var dd = 1; dd <= fim; dd++) {
                var s = a + '-' + String(m + 1).padStart(2, '0') + '-' + String(dd).padStart(2, '0'), l = doDia(s);
                nMes += l.length;
                cel += '<span class="cal-mini-dia' + (l.length ? ' tem ' + situacaoDia(l) + (l.length >= 5 ? ' muitos' : '') : '') + (s === C.hoje ? ' hoje' : '') + '" data-ver-dia="' + s + '" title="' + esc(dataCurta(s) + (l.length ? ' · ' + fmt(X.pendencias, l.length) : '')) + '">' + dd + '</span>';
            }
            total += nMes;
            h += '<div class="cal-mini"><button type="button" class="cal-mini-tit" data-ver-mes="' + a + '-' + String(m + 1).padStart(2, '0') + '-01">' + esc(C.meses[m]) + (nMes ? ' <span>' + nMes + '</span>' : '') + '</button>'
                + '<div class="cal-mini-sem">' + C.semana.map(function (x) { return '<span>' + esc(x.charAt(0)) + '</span>'; }).join('') + '</div><div class="cal-mini-grade">' + cel + '</div></div>';
        }
        corpo.innerHTML = h + '</div>' + (total ? '' : '<div class="cal-vazio">' + esc(X.semAno) + '</div>');
    }

    function render() {
        document.querySelectorAll('[data-cal-visao]').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-cal-visao') === estado.visao); });
        var a = +estado.data.slice(0, 4);
        if (!anos[a]) corpo.innerHTML = '<div class="cal-vazio">' + esc(X.carregando) + '</div>';
        carregar(a).then(function () {
            if (+estado.data.slice(0, 4) !== a) return;   // o usuário já navegou para outro ano
            ({ dia: dia, mes: mes, ano: ano })[estado.visao]();
        }, function () { corpo.innerHTML = '<div class="cal-vazio text-danger">' + esc(X.erro) + '</div>'; });
        try { history.replaceState(null, '', '?visao=' + estado.visao + '&data=' + estado.data); } catch (e) {}
    }

    document.addEventListener('click', function (ev) {
        var t = ev.target;
        if (t.closest('[data-abrir]')) return;   // painel-lateral.js abre o popup
        var b;
        if ((b = t.closest('[data-cal-nav]'))) {
            var n = +b.getAttribute('data-cal-nav');
            estado.data = n === 0 ? C.hoje : estado.visao === 'dia' ? soma(estado.data, n) : estado.visao === 'mes' ? somaMes(estado.data, n) : somaMes(estado.data, 12 * n);
            return render();
        }
        if ((b = t.closest('[data-cal-visao]'))) { estado.visao = b.getAttribute('data-cal-visao'); return render(); }
        if ((b = t.closest('[data-ver-dia]'))) { estado.data = b.getAttribute('data-ver-dia'); estado.visao = 'dia'; return render(); }
        if ((b = t.closest('[data-ver-mes]'))) { estado.data = b.getAttribute('data-ver-mes'); estado.visao = 'mes'; return render(); }
        if ((b = t.closest('.cal-dia[data-dia]')) && !b.classList.contains('fora')) { estado.data = b.getAttribute('data-dia'); return render(); }
        if ((b = t.closest('.cal-dia.fora[data-dia]'))) { estado.data = b.getAttribute('data-dia'); return render(); }
    });
    document.addEventListener('dblclick', function (ev) {
        var b = ev.target.closest('.cal-dia[data-dia]');
        if (b && !ev.target.closest('[data-abrir]')) { estado.data = b.getAttribute('data-dia'); estado.visao = 'dia'; render(); }
    });
    filtro.addEventListener('change', function () { estado.filtro = filtro.value; render(); });
    if (pessoa) pessoa.addEventListener('change', function () { estado.pessoa = pessoa.value; render(); });
    document.addEventListener('keydown', function (ev) {
        if (ev.target.closest('input,select,textarea')) return;
        if (ev.key === 'ArrowLeft' || ev.key === 'ArrowRight') { var b = document.querySelector('[data-cal-nav="' + (ev.key === 'ArrowLeft' ? -1 : 1) + '"]'); if (b) b.click(); }
    });
    // Ao voltar para a aba (ex.: depois de tratar uma pendência no popup), recarrega o ano visível
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { anos = {}; render(); } });
    render();
}());
