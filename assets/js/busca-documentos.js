/* Pesquisa de documentos (principal/busca_documentos.php).
   Consulta principal/api/busca_documentos.php e monta: chips dos filtros, Refinar por (com contagens), grid, menu de
   contexto (Abrir na transação, Visualizar, Arquivos, Download, Propriedades, Copiar link/nome) e paginação.
   A tela abre vazia: só pesquisa quando o usuário clica em Pesquisar (ou Enter) ou em Aplicar da pesquisa Avançada.
   Limpar volta ao estado inicial (sem pesquisar). Grid e paginação no padrão da aplicação (menu-batch-grid / grid-pagination).
   "Abrir" usa a aba do portal (window.top.greenPortalOpenTab); Visualizar/Arquivos usam a janela do portal (GreenPortalPopups). */
(function ($) {
    'use strict';
    var T = window.BUSCA_TEXTOS || {};
    var host = window.top || window;
    var base = String(host.greenPortalBaseUrl || '');
    // Migração 346: chave "Também pelo sentido" (lembrada no navegador)
    var sentidoLigado = true;
    try { sentidoLigado = window.localStorage.getItem('busca_sentido') !== '0'; } catch (e) { sentidoLigado = true; }
    var estado = { q: String(window.BUSCA_Q_INICIAL || ''), tipos: [], formatos: [], situacoes: [], unidades: [], item: '', validade_ate: '', ordem: 'relevancia', pagina: 1 };
    var vazio = function () { return { q: '', tipos: [], formatos: [], situacoes: [], unidades: [], item: '', validade_ate: '', ordem: 'relevancia', pagina: 1, grupo: null, literal: false }; };
    var ultimo = null, requisicao = null, itens = [];
    var $b = $('[data-busca]');
    var esc = function (v) { return $('<div>').text(String(v == null ? '' : v)).html(); };
    var fmt = function (texto, valores) { return String(texto || '').replace(/\{(\w+)\}/g, function (m, k) { return valores[k] != null ? valores[k] : m; }); };
    var data = function (v) { if (!v) return ''; var m = String(v).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/); return m ? m[3] + '/' + m[2] + '/' + m[1] + (m[4] ? ' ' + m[4] + ':' + m[5] : '') : String(v); };
    var tamanho = function (n) { if (n == null) return ''; n = Number(n); return n < 1024 ? n + ' B' : (n < 1048576 ? Math.round(n / 1024) + ' KB' : (n / 1048576).toFixed(1).replace('.', ',') + ' MB'); };
    var tipoNome = function (t) { return T['tipo.' + t] || t; };
    var cssEsc = function (v) { return String(v).replace(/["\\]/g, '\\$&'); };
    var url = function (u) { return u ? base + u : ''; };
    var urlAbsoluta = function (u) { try { return new URL(url(u), host.location.href).toString(); } catch (e) { return url(u); } };
    var icone = function (it) {
        if (it.item !== 'ARQUIVO') return 'bi-card-text';
        var f = String(it.formato || '');
        if (f === 'pdf') return 'bi-file-earmark-pdf text-danger';
        if (/^(docx?|odt|rtf)$/.test(f)) return 'bi-file-earmark-word text-primary';
        if (/^(xlsx?|xlsm|ods|csv)$/.test(f)) return 'bi-file-earmark-excel text-success';
        if (/^(pptx?|odp)$/.test(f)) return 'bi-file-earmark-slides text-warning';
        if (/^(png|jpe?g|jfif|gif|bmp|tiff?|emz|webp)$/.test(f)) return 'bi-file-earmark-image text-info';
        if (/^(msg|eml)$/.test(f)) return 'bi-envelope';
        if (/^(zip|rar|7z)$/.test(f)) return 'bi-file-earmark-zip';
        return 'bi-file-earmark';
    };
    // Formas a destacar: as que a pesquisa usou de fato (API: destaques; sigla = só palavra inteira), sem diferença de acento
    var ACENTOS = { a: 'aáàâãä', e: 'eéèêë', i: 'iíìîï', o: 'oóòôõö', u: 'uúùûü', c: 'cç', n: 'nñ' };
    var semAcento = function (t) { return String(t).normalize('NFD').replace(/[\u0300-\u036f]/g, ''); };
    var padrao = function (t) {
        return semAcento(t).toLowerCase().split('').map(function (ch) {
            if (ACENTOS[ch]) return '[' + ACENTOS[ch] + ']';
            return esc(ch).replace(/[.*+?^${}()|[\]\\\/]/g, '\\$&');
        }).join('');
    };
    var reDestaque = null, chaveDestaque = null;
    var termos = function () {
        if (ultimo && ultimo.destaques && ultimo.destaques.length) return ultimo.destaques;
        return estado.q.replace(/["+\-<>()~*@]/g, ' ').split(/\s+/).filter(function (x) { return x.length >= 2; }).map(function (x) { return { alt: x, sigla: 0 }; });
    };
    var destacar = function (texto) {
        var h = esc(texto), ts = termos(), chave = JSON.stringify(ts);
        if (!ts.length) return h;
        if (chave !== chaveDestaque) {
            var partes = {};
            ts.forEach(function (t) {
                var p = padrao(t.alt); if (!p) return;
                partes[Number(t.sigla) ? '(?<![\\p{L}\\p{N}])' + p + '(?![\\p{L}\\p{N}])' : p] = 1;
            });
            var lista = Object.keys(partes);
            try { reDestaque = lista.length ? new RegExp('(' + lista.join('|') + ')', 'giu') : null; } catch (e) { reDestaque = null; }
            chaveDestaque = chave;
        }
        return reDestaque ? h.replace(reDestaque, '<mark>$1</mark>') : h;
    };

    // Título da aba do portal: "Pesquisa - <texto>"
    function tituloAba() {
        try {
            var nome = window.name ? String(window.name).replace(/^greenportal-/, '') : '';
            var tab = nome ? host.document.getElementById('tab-' + nome) : null;
            if (tab) { var l = tab.querySelector('.workspace-tab-label'); if (l) l.textContent = estado.q ? (T.titulo || '') + ' - ' + estado.q : (T.titulo || ''); }
        } catch (e) { /* aba de outro domínio ou fora do portal */ }
    }

    if (!estado.grupo) estado.grupo = null;
    var temCriterio = function () { return !!(estado.grupo || estado.q || estado.item || estado.validade_ate || estado.tipos.length || estado.formatos.length || estado.situacoes.length || estado.unidades.length); };

    // Estado inicial: grid vazio com a orientação, sem facetas, sem paginação
    function telaInicial(mensagem) {
        if (requisicao) requisicao.abort();
        ultimo = null; itens = [];
        $b.find('[data-busca-linhas]').html('<tr><td colspan="9" class="busca-aviso">' + esc(mensagem || T.inicio_vazio) + '</td></tr>');
        $b.find('[data-busca-facetas]').empty();
        $b.find('[data-busca-contagem], [data-busca-indice]').text('');
        $b.find('[data-busca-paginas], [data-busca-expandir-todos], [data-busca-dicionario]').prop('hidden', true);
        $b.find('[data-busca-exportar]').prop('disabled', true);
        chips(); tituloAba(); iconesOrdem();
    }

    // Ordenação pelo cabeçalho (feita pelo banco): estado.ordem = <coluna>_asc|_desc; nome/validade/modificado vêm da Avançada
    function ordemAtual() {
        var o = String(estado.ordem || 'relevancia'), m = o.match(/^(\w+?)(?:_(asc|desc))?$/);
        if (!m || m[1] === 'relevancia') return null;
        return { col: m[1], desc: m[2] ? m[2] === 'desc' : m[1] === 'modificado' };
    }
    function iconesOrdem() {
        var o = ordemAtual();
        $b.find('th[data-busca-col]').each(function () {
            var $th = $(this), ativo = !!o && o.col === $th.attr('data-busca-col');
            $th.removeClass('sort-asc sort-desc').attr('aria-sort', ativo ? (o.desc ? 'descending' : 'ascending') : 'none');
            $th.find('.column-sort-icon').removeClass('bi-arrow-up bi-arrow-down bi-arrow-down-up').addClass(ativo ? (o.desc ? 'bi-arrow-down' : 'bi-arrow-up') : 'bi-arrow-down-up');
            if (ativo) $th.addClass(o.desc ? 'sort-desc' : 'sort-asc');
        });
    }

    // Largura das colunas: arrastar a borda direita do título; guardada neste navegador
    var CHAVE_LARGURAS = 'greenportal.busca_documentos.larguras';
    function congelarLarguras() {
        var $tab = $b.find('[data-busca-grid]'), total = 0;
        $tab.find('thead th').each(function () { var w = $(this).outerWidth(); $(this).css('width', w + 'px'); total += w; });
        $tab.css({ width: total + 'px', minWidth: '100%' });
    }
    function salvarLarguras() {
        var l = {}; $b.find('th[data-busca-col]').each(function () { l[$(this).attr('data-busca-col')] = Math.round($(this).outerWidth()); });
        try { window.localStorage.setItem(CHAVE_LARGURAS, JSON.stringify(l)); } catch (e) { /* armazenamento indisponível */ }
    }
    function aplicarLarguras() {
        var l = null; try { l = JSON.parse(window.localStorage.getItem(CHAVE_LARGURAS) || 'null'); } catch (e) { l = null; }
        if (!l || typeof l !== 'object') return;
        var $tab = $b.find('[data-busca-grid]'), total = 0;
        $tab.find('thead th').each(function () { var c = $(this).attr('data-busca-col'), w = c && Number(l[c]) > 0 ? Math.max(60, Number(l[c])) : $(this).outerWidth(); $(this).css('width', w + 'px'); total += w; });
        $tab.css({ width: total + 'px', minWidth: '100%' });
    }

    function pesquisar() {
        iconesOrdem();
        if (estado.pagina === 1 && !estado.grupo) abertos = {};
        if (!temCriterio()) { telaInicial(T.sem_criterio); return; }
        if (requisicao) requisicao.abort();
        var p = { q: estado.q, item: estado.item, validade_ate: estado.validade_ate, ordem: estado.ordem, pagina: estado.pagina, por_pagina: 25 };
        ['tipos', 'formatos', 'situacoes', 'unidades'].forEach(function (k) { p[k] = estado[k]; });
        if (estado.grupo) { p.grupo_tipo = estado.grupo.tipo; p.grupo_chave = estado.grupo.chave; }
        if (estado.literal) p.literal = 1;
        p.sentido = sentidoLigado ? 1 : 0;
        $b.find('[data-busca-linhas]').html('<tr><td colspan="9" class="busca-aviso"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' + esc(T.carregando) + '</td></tr>');
        requisicao = $.ajax({ url: 'api/busca_documentos.php', data: p, dataType: 'json', traditional: false })
            .done(function (r) { ultimo = r; render(r); })
            .fail(function (x) { if (x.statusText === 'abort') return; var m = (x.responseJSON || {}).message || 'Erro'; $b.find('[data-busca-linhas]').html('<tr><td colspan="9" class="busca-aviso text-danger">' + esc(m) + '</td></tr>'); })
            .always(function () { requisicao = null; });
        chips(); tituloAba();
    }

    // Resultado agrupado pelo negócio: uma linha por grupo (cadastro, fornecedor ou funcionário) que expande e mostra
    // os vínculos (requisito → evidências / evidência → requisitos) e os itens (registros e arquivos).
    var abertos = {};
    var ICONE_TIPO = { licencas: 'bi-patch-check', controles: 'bi-file-earmark-text', requisitos: 'bi-journal-bookmark', treinamentos: 'bi-mortarboard',
        infraestrutura: 'bi-building', fornecedor: 'bi-truck', funcionario: 'bi-person-badge', fornecedorcriterios: 'bi-card-checklist',
        funcionariocriterios: 'bi-card-checklist', funcionariotreinamentos: 'bi-mortarboard' };
    var iconeItem = function (it) { return it.item === 'ARQUIVO' ? icone(it) : (ICONE_TIPO[it.tipo] || 'bi-card-text'); };
    var situacaoHtml = function (texto, cor) {
        return texto ? '<span class="pdf-situacao"><i class="pdf-legenda-cor pdf-cor-' + esc(cor || 'sem-situacao') + '" aria-hidden="true"></i><span>' + esc(texto) + '</span></span>' : '<span class="text-muted">—</span>';
    };
    var botaoMenu = function (i) { return '<td class="busca-col-acoes"><button type="button" class="btn btn-sm btn-link" data-busca-menu-abrir data-i="' + i + '" title="' + esc(T.mais_acoes) + '" aria-label="' + esc(T.mais_acoes) + '"><i class="bi bi-three-dots-vertical"></i></button></td>'; };

    // Linha de item (registro, arquivo ou vínculo) dentro do grupo
    function linhaItem(it, i, grupoId, nivel, classe) {
        var titulo = it.item === 'ARQUIVO' ? it.titulo : (it.descricao || '');
        var tituloHtml = it.trecho ? trechoHtml(it.trecho) : destacar(titulo);
        return '<tr class="busca-filho busca-nivel-' + nivel + (classe ? ' ' + classe : '') + (it.encontrado ? ' busca-encontrado' : '') + '" data-i="' + i + '" data-pai="' + esc(grupoId) + '" tabindex="0">'
            + '<td class="busca-col-nome" title="' + esc(it.nome) + '"><i class="bi ' + iconeItem(it) + ' busca-icone" aria-hidden="true"></i><a href="#" data-busca-acao="abrir" data-i="' + i + '">' + destacar(it.nome) + '</a>'
            + (it.negocio ? '<button type="button" class="busca-pivo" data-busca-acao="ver-pesquisa" data-i="' + i + '" title="' + esc(T.m_ver_pesquisa) + '" aria-label="' + esc(T.m_ver_pesquisa) + '"><i class="bi bi-diagram-3" aria-hidden="true"></i></button>' : '') + '</td>'
            + '<td class="busca-col-titulo" title="' + esc(it.trecho ? T.encontrado_em + ': ' + it.trecho : [it.titulo, it.descricao].filter(Boolean).join(' — ')) + '">' + tituloHtml + '</td>'
            + '<td class="busca-col-tipo"><span class="busca-tipo">' + esc(tipoNome(it.tipo)) + '</span>' + (it.item === 'ARQUIVO' ? ' <span class="busca-formato">' + esc(String(it.formato || '').toUpperCase()) + '</span>' : '') + '</td>'
            + '<td>' + situacaoHtml(it.situacao, it.cor) + '</td>'
            + '<td class="busca-col-unidade" title="' + esc(it.unidade || '') + '">' + esc(it.unidade || '') + '</td>'
            + '<td class="busca-col-responsavel" title="' + esc(it.responsavel || '') + '">' + esc(it.responsavel || '') + '</td>'
            + '<td>' + esc(data(it.validade)) + '</td>'
            + '<td>' + esc(data(it.modificado)) + '</td>'
            + botaoMenu(i) + '</tr>';
    }
    // Trecho onde a palavra foi encontrada (campo que não aparece no grid: padrão, observação, palavras-chave...)
    function trechoHtml(t) { return '<span class="busca-trecho"><i class="bi bi-quote" aria-hidden="true"></i>' + destacar(t) + '</span>'; }
    function linhaSecao(grupoId, icone, texto) {
        return '<tr class="busca-secao" data-pai="' + esc(grupoId) + '"><td colspan="9"><i class="bi ' + icone + '" aria-hidden="true"></i> ' + esc(texto) + '</td></tr>';
    }
    function linhaMais(grupoId, i, acao, texto) {
        return '<tr class="busca-mais" data-pai="' + esc(grupoId) + '"><td colspan="9"><a href="#" data-busca-acao="' + acao + '" data-i="' + i + '">' + esc(texto) + ' <i class="bi bi-arrow-right-short" aria-hidden="true"></i></a></td></tr>';
    }

    // Aviso do dicionário: "Resultados também para ASO (Atestado de Saúde Ocupacional) · Pesquisar só o texto "aso""
    function avisoDicionario(r) {
        var $d = $b.find('[data-busca-dicionario]'), usados = r.dicionario || [];
        if (r.literal && estado.q) {
            $d.html('<i class="bi bi-fonts" aria-hidden="true"></i> ' + esc(T.dic_literal_ativo) + ' <a href="#" data-busca-literal="0">' + esc(T.dic_usar) + '</a>').prop('hidden', false);
            return;
        }
        if (!usados.length) { $d.prop('hidden', true).empty(); return; }
        var vistos = {}, partes = [];
        usados.forEach(function (u) { var k = u.termo + '|' + u.significado; if (vistos[k]) return; vistos[k] = 1; partes.push('<strong>' + esc(u.termo) + '</strong> (' + esc(u.significado) + ')'); });
        $d.html('<i class="bi bi-book" aria-hidden="true"></i> ' + esc(T.dic_resultados) + ' ' + partes.join(', ')
            + ' · <a href="#" data-busca-literal="1">' + esc(fmt(T.dic_literal, { q: estado.q })) + '</a>').prop('hidden', false);
    }

    function render(r) {
        itens = [];
        var grupos = r.grupos || [], html = [];
        if (!grupos.length) html.push('<tr><td colspan="9" class="busca-aviso">' + esc(r.indice_vazio ? T.indice_vazio : T.nenhum) + '</td></tr>');
        var semanticos = r.semanticos || [];
        if (!grupos.length && semanticos.length) html[0] = '<tr><td colspan="9" class="busca-aviso">' + esc(T.sentido_so) + '</td></tr>';
        var abrirTodos = grupos.length + semanticos.length <= 3;
        // Migração 346: os parecidos pelo sentido vêm depois, com a semelhança
        var desenhar = function (g) {
            var gi = itens.push($.extend({}, g, { item: 'GRUPO', arquivo: '', titulo: g.detalhe || g.descricao || '' })) - 1;
            var aberto = abertos.hasOwnProperty(g.id) ? abertos[g.id] : abrirTodos;
            var c = g.contagens || {}, resumo = [];
            var portaria = g.tipo === 'fornecedor' || g.tipo === 'funcionario';
            (g.secoes || []).forEach(function (sc) { resumo.push('<span class="busca-resumo" title="' + esc(sc.rotulo) + '"><i class="bi ' + esc(sc.icone) + '" aria-hidden="true"></i>' + esc(fmt(T.r_vinculos, { n: sc.total })) + '</span>'); });
            if (portaria && c.registros) resumo.push('<span class="busca-resumo"><i class="bi bi-card-checklist" aria-hidden="true"></i>' + esc(fmt(T.r_documentos, { n: c.registros })) + '</span>');
            if (c.arquivos) resumo.push('<span class="busca-resumo"><i class="bi bi-paperclip" aria-hidden="true"></i>' + esc(fmt(T.r_arquivos, { n: c.arquivos })) + '</span>');
            if (portaria && c.pendentes) resumo.push('<span class="busca-resumo busca-resumo-alerta"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>' + esc(fmt(T.r_pendentes, { n: c.pendentes })) + '</span>');
            var detalhe = g.detalhe ? '<span class="busca-detalhe">' + destacar(g.detalhe) + '</span>' : (g.descricao ? '<span class="busca-detalhe">' + destacar(g.descricao) + '</span>' : '');
            var situacao = g.situacao ? situacaoHtml(g.situacao, g.cor)
                : (portaria ? situacaoHtml(c.pendentes ? fmt(T.r_pendentes, { n: c.pendentes }) : (c.a_vencer ? fmt(T.r_a_vencer, { n: c.a_vencer }) : T.r_em_dia), c.pendentes ? 'reprovado' : (c.a_vencer ? 'a-vencer' : 'aprovado')) : situacaoHtml('', ''));
            var temFilhos = (g.itens || []).length || (g.secoes || []).length;
            html.push('<tr class="busca-grupo' + (aberto ? ' aberto' : '') + '" data-i="' + gi + '" data-grupo="' + esc(g.id) + '" tabindex="0">'
                + '<td class="busca-col-nome" title="' + esc(g.nome) + '">'
                + (temFilhos ? '<button type="button" class="busca-expandir" data-busca-expandir aria-expanded="' + (aberto ? 'true' : 'false') + '" aria-label="' + esc(T.expandir) + '"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>' : '<span class="busca-expandir-vazio"></span>')
                + '<i class="bi ' + (ICONE_TIPO[g.tipo] || 'bi-card-text') + ' busca-icone" aria-hidden="true"></i><a href="#" data-busca-acao="abrir" data-i="' + gi + '">' + destacar(g.nome || ('#' + g.chave)) + '</a></td>'
                + '<td class="busca-col-titulo"' + (g.trecho ? ' title="' + esc(T.encontrado_em + ': ' + g.trecho) + '"' : '') + '>' + (g.por_sentido ? '<span class="busca-sentido-selo" title="' + esc(T.sentido_dica) + '"><i class="bi bi-stars" aria-hidden="true"></i>' + esc(fmt(T.sentido_pct, { n: Math.round((g.semelhanca || 0) * 100) })) + '</span>' : '') + (g.trecho ? trechoHtml(g.trecho) : detalhe) + resumo.join('') + '</td>'
                + '<td class="busca-col-tipo"><span class="busca-tipo busca-tipo-grupo">' + esc(tipoNome(g.tipo)) + '</span></td>'
                + '<td>' + situacao + '</td>'
                + '<td class="busca-col-unidade" title="' + esc(g.unidade || '') + '">' + esc(g.unidade || '') + '</td>'
                + '<td class="busca-col-responsavel" title="' + esc(g.responsavel || '') + '">' + esc(g.responsavel || '') + '</td>'
                + '<td>' + esc(data(g.validade)) + '</td>'
                + '<td>' + esc(data(g.modificado)) + '</td>'
                + botaoMenu(gi) + '</tr>');
            // Vínculos com Requisitos legais
            (g.secoes || []).forEach(function (sc) {
                html.push(linhaSecao(g.id, sc.icone, sc.rotulo + ' (' + sc.total + ')'));
                sc.itens.forEach(function (v) { html.push(linhaItem($.extend({ item: 'REGISTRO' }, v), itens.push(v) - 1, g.id, 1, 'busca-vinculo')); });
                if (sc.total > sc.itens.length) html.push(linhaMais(g.id, gi, 'abrir', fmt(T.mais_vinculos, { n: sc.total })));
            });
            // Itens do negócio: arquivos do cadastro ou, na Portaria, documentos / treinamentos com os seus arquivos
            var filhos = g.itens || [];
            if (filhos.length) {
                var total = portaria ? (c.registros + c.arquivos) : c.arquivos;
                html.push(linhaSecao(g.id, portaria ? 'bi-card-checklist' : 'bi-paperclip', fmt(portaria ? T.s_documentos : T.s_arquivos, { n: portaria ? c.registros : c.arquivos })));
                filhos.forEach(function (f) { html.push(linhaItem(f, itens.push(f) - 1, g.id, portaria && f.item === 'ARQUIVO' ? 2 : 1, '')); });
                if (total > filhos.length) html.push(g.arquivos && !portaria ? linhaMais(g.id, gi, 'arquivos', fmt(T.mais_arquivos, { n: c.arquivos })) : linhaMais(g.id, gi, 'abrir', T.mais_abrir));
            }
        };
        grupos.forEach(desenhar);
        if (semanticos.length) {
            html.push('<tr class="busca-sentido-titulo"><td colspan="9"><i class="bi bi-stars" aria-hidden="true"></i> ' + esc(fmt(T.sentido_titulo, { q: estado.q })) + '</td></tr>');
            semanticos.forEach(desenhar);
        }
        var $l = $b.find('[data-busca-linhas]').html(html.join(''));
        $l.find('tr[data-pai]').each(function () { var id = $(this).attr('data-pai'); $(this).prop('hidden', !$l.find('tr.busca-grupo[data-grupo="' + cssEsc(id) + '"]').hasClass('aberto')); });
        $b.find('[data-busca-expandir-todos]').prop('hidden', !grupos.length && !semanticos.length);
        $b.find('[data-busca-exportar]').prop('disabled', !grupos.length);
        avisoDicionario(r);
        // Rodapé
        var de = r.total ? (r.pagina - 1) * r.por_pagina + 1 : 0, ate = Math.min(r.total, r.pagina * r.por_pagina);
        $b.find('[data-busca-contagem]').text(fmt(T.registros, { de: de, ate: ate, total: r.total }));
        $b.find('[data-busca-indice]').text(r.indice_atualizado_em ? fmt(T.indice, { data: data(r.indice_atualizado_em) }) : '');
        paginas(r);
        facetas(r.facetas || {});
    }

    // Paginação no padrão dos grids: [<] página/total [>], com página e total de registros na dica
    function paginas(r) {
        var $p = $b.find('[data-busca-paginas]'), atual = Number(r.pagina) || 1, total = Math.max(1, Number(r.paginas) || 1);
        $p.prop('hidden', !r.total);
        $p.find('[data-busca-pag-status]').text(atual + '/' + total).attr('title', T.pag_pagina + ' ' + atual + ' ' + T.pag_de + ' ' + total + ' \u00b7 ' + r.total + ' ' + T.pag_registros);
        $p.find('[data-busca-pag="-1"]').prop('disabled', atual <= 1);
        $p.find('[data-busca-pag="1"]').prop('disabled', atual >= total);
    }

    function facetas(f) {
        var $f = $b.find('[data-busca-facetas]').empty();
        var grupos = [['tipos', T.f_tipos], ['formatos', T.f_formatos], ['situacoes', T.f_situacoes], ['unidades', T.f_unidades]];
        grupos.forEach(function (g) {
            var chave = g[0], linhas = (f[chave] || []).slice(), marcados = estado[chave];
            // Valores marcados que sumiram do conjunto continuam visíveis (contagem 0) para poder desmarcar
            marcados.forEach(function (v) { if (!linhas.some(function (x) { return String(x.valor) === v; })) linhas.push({ valor: v, qtd: 0 }); });
            if (!linhas.length) return;
            var $g = $('<div class="busca-faceta"></div>').append('<div class="busca-faceta-titulo">' + esc(g[1]) + '</div>');
            linhas.forEach(function (x) {
                var v = String(x.valor == null ? '' : x.valor), rotulo;
                if (chave === 'tipos') rotulo = esc(tipoNome(v));
                else if (chave === 'formatos') rotulo = v === 'registro' ? esc(T.registro) : esc(v.toUpperCase() || '—');
                else if (chave === 'situacoes') rotulo = '<i class="pdf-legenda-cor pdf-cor-' + esc(x.cor || 'sem-situacao') + '" aria-hidden="true"></i> ' + esc(v || T.sem_situacao);
                else rotulo = esc(v || T.sem_unidade);
                var id = 'f-' + chave + '-' + Math.random().toString(36).slice(2, 8);
                $g.append('<label class="busca-faceta-item" for="' + id + '"><input type="checkbox" class="form-check-input" id="' + id + '" data-faceta="' + chave + '" value="' + esc(v) + '"' + (marcados.indexOf(v) >= 0 ? ' checked' : '') + '><span class="busca-faceta-rotulo">' + rotulo + '</span><span class="busca-faceta-qtd">' + Number(x.qtd || 0) + '</span></label>');
            });
            $f.append($g);
        });
    }

    function chips() {
        var $c = $b.find('[data-busca-chips-lista]').empty(), tem = false;
        var chip = function (texto, remover) { tem = true; $c.append($('<span class="busca-chip"></span>').append($('<span>').text(texto), $('<button type="button" class="btn-close" aria-label="' + esc(T.limpar) + '"></button>').on('click', function () { remover(); estado.pagina = 1; if (temCriterio()) pesquisar(); else telaInicial(); }))); };
        // O texto pesquisado já aparece no campo de pesquisa: sem caixa "Texto: ..." (só os filtros viram caixas)
        [['tipos', T.f_tipos], ['formatos', T.f_formatos], ['situacoes', T.f_situacoes], ['unidades', T.f_unidades]].forEach(function (g) {
            estado[g[0]].forEach(function (v) {
                var r = g[0] === 'tipos' ? tipoNome(v) : (g[0] === 'formatos' ? (v === 'registro' ? T.registro : v.toUpperCase()) : (v || '—'));
                chip(g[1] + ': ' + r, function () { estado[g[0]] = estado[g[0]].filter(function (x) { return x !== v; }); });
            });
        });
        if (estado.item) chip(T.a_item + ': ' + (estado.item === 'ARQUIVO' ? T.a_arquivos : T.a_registros), function () { estado.item = ''; $b.find('[data-busca-item]').val(''); });
        if (estado.grupo) chip(T.chip_negocio + ': ' + (estado.grupo.nome || estado.grupo.chave), function () { estado.grupo = null; });
        if (estado.validade_ate) chip(T.a_validade + ': ' + data(estado.validade_ate), function () { estado.validade_ate = ''; $b.find('[data-busca-validade]').val(''); });
        $b.find('[data-busca-chips]').prop('hidden', !tem);
    }

    // Ações
    // Área da tela de pesquisa (o popup abre com 90% dela)
    function area() { try { return window.frameElement ? window.frameElement.getBoundingClientRect() : null; } catch (e) { return null; } }
    // Abrir: a transação correspondente (já filtrada no registro) em popup de 90% da área da pesquisa, sem abrir aba nova
    function abrir(it) {
        var titulo = tipoNome(it.tipo) + ' - ' + (it.item === 'ARQUIVO' ? (it.titulo || it.nome) : it.nome), u = new URL(url(it.abrir), window.location.href).toString();
        if (host.GreenPortalPopups && typeof host.GreenPortalPopups.transacao90 === 'function') { host.GreenPortalPopups.transacao90({ titulo: titulo, url: u, area: area() }); return; }
        if (host.GreenPortalPopups && host.jQuery) { janela(titulo, it.abrir, true); return; }
        window.location.href = u;
    }
    function janela(titulo, endereco, amplo) {
        var u = url(endereco);
        if (amplo && host.GreenPortalPopups && typeof host.GreenPortalPopups.transacao90 === 'function') { host.GreenPortalPopups.transacao90({ titulo: titulo, url: new URL(u, window.location.href).toString(), area: area() }); return; }
        if (host.GreenPortalPopups && host.jQuery) {
            var $frame = host.jQuery('<iframe>', { src: new URL(u, window.location.href).toString(), style: 'width:100%;height:100%;border:0;display:block;' });
            if (typeof host.GreenPortalPopups.transacaoIndependente === 'function') host.GreenPortalPopups.transacaoIndependente({ titulo: titulo, conteudo: $frame, compacto: !amplo, amplo: !!amplo, redimensionavel: true });
            else host.GreenPortalPopups.transacao({ titulo: titulo, conteudo: $frame });
        } else window.location.href = u;
    }
    function copiar(texto) {
        var ok = function () { var $m = $b.find('[data-busca-indice]'), antes = $m.text(); $m.text(T.copiado); setTimeout(function () { $m.text(antes); }, 1500); };
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(texto).then(ok, function () { window.prompt('', texto); });
        else window.prompt('', texto);
    }
    function propriedades(it) {
        var linhas = [[T.c_nome, it.nome], [T.c_tipo, tipoNome(it.tipo)], [T.c_titulo, it.titulo], [T.p_descricao, it.descricao], [T.c_situacao, it.situacao],
            [T.c_unidade, it.unidade], [T.c_validade, data(it.validade)], [T.p_chave, it.chave], [T.p_transacao, it.transacao],
            [T.p_tamanho, tamanho(it.tamanho)], [T.c_modificado, data(it.modificado)], [T.p_indexado, data(it.indexado)]];
        var html = '<div style="padding:12px 16px;font:13px/1.45 system-ui,sans-serif"><table style="width:100%;border-collapse:collapse">' + linhas.filter(function (l) { return l[1]; }).map(function (l) {
            return '<tr><th style="text-align:left;vertical-align:top;padding:4px 12px 4px 0;white-space:nowrap;color:#5f6b77;font-weight:600">' + esc(l[0]) + '</th><td style="padding:4px 0;word-break:break-word">' + esc(l[1]) + '</td></tr>';
        }).join('') + '</table></div>';
        if (host.GreenPortalPopups && host.jQuery) {
            var $c = host.jQuery('<div>').html(html);
            if (typeof host.GreenPortalPopups.transacaoIndependente === 'function') host.GreenPortalPopups.transacaoIndependente({ titulo: T.m_propriedades + ' - ' + it.nome, conteudo: $c, compacto: true });
            else host.GreenPortalPopups.transacao({ titulo: T.m_propriedades, conteudo: $c });
        } else { var w = window.open('', '_blank'); if (w) w.document.write(html); }
    }
    var $menu = $b.find('[data-busca-menu]');
    function menu(i, x, y) {
        var it = itens[i]; if (!it) return;
        var op = function (acao, ic, texto) { return '<li role="menuitem" tabindex="-1" data-busca-acao="' + acao + '" data-i="' + i + '"><i class="bi ' + ic + '" aria-hidden="true"></i>' + esc(texto) + '</li>'; };
        var h = '<li class="busca-menu-titulo">' + esc(it.nome) + '</li>' + op('abrir', 'bi-box-arrow-up-right', T.m_abrir);
        if (it.negocio) h += op('ver-pesquisa', 'bi-diagram-3', T.m_ver_pesquisa);
        if (it.ver) h += op('visualizar', 'bi-eye', T.m_visualizar);
        if (it.arquivos) h += op('arquivos', 'bi-folder2-open', T.m_arquivos);
        if (it.baixar) h += op('download', 'bi-download', T.m_download);
        h += '<li class="busca-menu-sep" role="separator"></li>' + op('propriedades', 'bi-info-circle', T.m_propriedades) + op('copiar-link', 'bi-link-45deg', T.m_copiar_link) + op('copiar-nome', 'bi-clipboard', T.m_copiar_nome);
        $menu.html(h).prop('hidden', false);
        var w = $menu.outerWidth(), hh = $menu.outerHeight();
        $menu.css({ left: Math.max(4, Math.min(x, window.innerWidth - w - 8)), top: Math.max(4, Math.min(y, window.innerHeight - hh - 8)) });
        $menu.find('[data-busca-acao]').first().trigger('focus');
    }
    // "Ver na pesquisa" de um vínculo: pesquisa só aquele negócio (com os vínculos dele), para seguir a cadeia
    function verNaPesquisa(n) {
        estado = vazio();
        estado.grupo = { tipo: n.tipo, chave: n.chave, nome: n.nome || '' };
        $b.find('[data-busca-q]').val(''); $b.find('[data-busca-item]').val(''); $b.find('[data-busca-validade]').val(''); $b.find('[data-busca-ordem]').val('relevancia');
        abertos = {}; abertos[n.tipo + ':' + n.chave] = true;
        pesquisar();
    }
    function fecharMenu() { $menu.prop('hidden', true).empty(); }
    function acao(nome, it) {
        fecharMenu();
        if (!it) return;
        if (nome === 'abrir') abrir(it);
        else if (nome === 'ver-pesquisa' && it.negocio) verNaPesquisa(it.negocio);
        else if (nome === 'visualizar' && it.ver) janela(it.nome, it.ver, true);
        else if (nome === 'arquivos' && it.arquivos) janela(T.m_arquivos + ' - ' + (it.titulo || it.nome), it.arquivos, true);
        else if (nome === 'download' && it.baixar) { var a = document.createElement('a'); a.href = url(it.baixar); a.download = it.arquivo || it.nome; document.body.appendChild(a); a.click(); a.remove(); }
        else if (nome === 'propriedades') propriedades(it);
        else if (nome === 'copiar-link') copiar(urlAbsoluta(it.abrir));
        else if (nome === 'copiar-nome') copiar(it.nome);
    }

    // Eventos
    $b.find('[data-busca-sentido]').prop('checked', sentidoLigado);
    $b.on('change', '[data-busca-sentido]', function () {
        sentidoLigado = this.checked;
        try { window.localStorage.setItem('busca_sentido', sentidoLigado ? '1' : '0'); } catch (e) { /* sem armazenamento: vale só nesta tela */ }
        if (estado.q) { estado.pagina = 1; pesquisar(); }
    });
    $b.on('submit', '[data-busca-form]', function (e) {
        e.preventDefault();
        var novo = $.trim(String($b.find('[data-busca-q]').val() || ''));
        estado.q = novo; estado.grupo = null; estado.literal = false; $b.find('[data-busca-q]').val(novo); estado.pagina = 1; pesquisar();
    });
    // Limpar: apaga texto, filtros do Refinar por e da Avançada e volta à tela inicial (sem pesquisar)
    $b.on('click', '[data-busca-limpar]', function () {
        estado = vazio();
        $b.find('[data-busca-q]').val(''); $b.find('[data-busca-item]').val(''); $b.find('[data-busca-validade]').val(''); $b.find('[data-busca-ordem]').val('relevancia');
        telaInicial(); $b.find('[data-busca-q]').trigger('focus');
    });
    $b.on('click', '[data-busca-avancada]', function () { var $p = $b.find('[data-busca-painel]'), abrirPainel = $p.prop('hidden'); $p.prop('hidden', !abrirPainel); $(this).attr('aria-expanded', abrirPainel ? 'true' : 'false'); });
    $b.on('click', '[data-busca-aplicar]', function () { estado.item = String($b.find('[data-busca-item]').val() || ''); estado.validade_ate = String($b.find('[data-busca-validade]').val() || ''); estado.ordem = String($b.find('[data-busca-ordem]').val() || 'relevancia'); estado.pagina = 1; pesquisar(); });
    $b.on('change', '[data-faceta]', function () {
        var k = String($(this).data('faceta')), v = String($(this).val());
        estado[k] = $(this).prop('checked') ? estado[k].concat([v]) : estado[k].filter(function (x) { return x !== v; });
        estado.pagina = 1; if (temCriterio()) pesquisar(); else telaInicial();
    });
    function alternarGrupo($g, abrir) {
        var id = String($g.attr('data-grupo'));
        if (abrir === undefined) abrir = !$g.hasClass('aberto');
        abertos[id] = abrir;
        $g.toggleClass('aberto', abrir).find('[data-busca-expandir]').attr('aria-expanded', abrir ? 'true' : 'false');
        $b.find('[data-busca-linhas] tr[data-pai="' + cssEsc(id) + '"]').prop('hidden', !abrir);
    }
    $b.on('click', '[data-busca-expandir]', function (e) { e.preventDefault(); e.stopPropagation(); alternarGrupo($(this).closest('tr.busca-grupo')); });
    $b.on('keydown', 'tr.busca-grupo', function (e) {
        if (e.key === 'ArrowRight') { e.preventDefault(); alternarGrupo($(this), true); }
        else if (e.key === 'ArrowLeft') { e.preventDefault(); alternarGrupo($(this), false); }
    });
    $b.on('click', '[data-busca-expandir-todos]', function () {
        var abrir = $(this).attr('data-busca-expandir-todos') === '1';
        $b.find('[data-busca-linhas] tr.busca-grupo').each(function () { if ($(this).find('[data-busca-expandir]').length) alternarGrupo($(this), abrir); });
    });
    $b.on('click keydown', 'th[data-busca-col] .busca-ordenar', function (e) {
        if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
        e.preventDefault();
        var col = String($(this).closest('th').attr('data-busca-col')), o = ordemAtual();
        estado.ordem = col + '_' + (o && o.col === col && !o.desc ? 'desc' : 'asc');
        estado.pagina = 1;
        if (ultimo || temCriterio()) pesquisar(); else iconesOrdem();
    });
    $b.on('pointerdown', '[data-busca-redimensionar]', function (e) {
        e.preventDefault(); e.stopPropagation();
        congelarLarguras();
        var $th = $(this).closest('th'), $tab = $b.find('[data-busca-grid]'), inicio = e.clientX, largura = $th.outerWidth(), tabela = $tab.outerWidth();
        $('body').addClass('busca-redimensionando');
        function mover(ev) { var w = Math.max(60, largura + ev.clientX - inicio); $th.css('width', w + 'px'); $tab.css('width', (tabela + w - largura) + 'px'); }
        function parar() { $(document).off('pointermove', mover).off('pointerup pointercancel', parar); $('body').removeClass('busca-redimensionando'); salvarLarguras(); }
        $(document).on('pointermove', mover).on('pointerup pointercancel', parar);
    });
    $b.on('click', '[data-busca-abrir-dicionario]', function () {
        var u = base + '/principal/busca_dicionario.php';
        if (typeof host.greenPortalOpenTab === 'function') host.greenPortalOpenTab('menu-busca_dicionario', u, T.dicionario); else window.open(u, '_blank');
    });
    // Exportar para o Excel (processo padrão): pede a exportação; o serviço gera a planilha e o link chega nos alertas do portal.
    function mensagem(texto, tipo) {
        var $m = $b.find('[data-busca-mensagem]');
        $m.removeClass('busca-mensagem-erro busca-mensagem-ok').addClass(tipo === 'erro' ? 'busca-mensagem-erro' : 'busca-mensagem-ok')
          .html('<i class="bi ' + (tipo === 'erro' ? 'bi-exclamation-triangle' : 'bi-bell') + '" aria-hidden="true"></i> ' + esc(texto)).prop('hidden', false);
        clearTimeout($m.data('t')); $m.data('t', setTimeout(function () { $m.prop('hidden', true); }, 10000));
    }
    $b.on('click', '[data-busca-exportar]', function () {
        if (!temCriterio()) return;
        var $btn = $(this), p = { action: 'exportar', q: estado.q, item: estado.item, validade_ate: estado.validade_ate, ordem: estado.ordem };
        ['tipos', 'formatos', 'situacoes', 'unidades'].forEach(function (k) { p[k] = estado[k]; });
        if (estado.literal) p.literal = 1;
        $btn.prop('disabled', true);
        $.ajax({ url: 'api/busca_documentos.php', data: p, dataType: 'json' })
            .done(function (r) { mensagem(r.message || T.exportar_pedido, 'ok'); })
            .fail(function (x) { mensagem((x.responseJSON || {}).message || 'Erro', 'erro'); })
            .always(function () { $btn.prop('disabled', !ultimo || !ultimo.total); });
    });
    $b.on('click', '[data-busca-literal]', function (e) { e.preventDefault(); estado.literal = $(this).attr('data-busca-literal') === '1'; estado.pagina = 1; pesquisar(); });
    $b.on('click', '[data-busca-pag]', function () {
        if (!ultimo) return;
        var nova = (Number(ultimo.pagina) || 1) + Number($(this).attr('data-busca-pag'));
        if (nova < 1 || nova > (Number(ultimo.paginas) || 1)) return;
        estado.pagina = nova; pesquisar();
    });
    $b.on('click', '[data-busca-linhas] tr[data-i]', function () { $(this).addClass('selected').siblings().removeClass('selected'); });
    $b.on('dblclick', '[data-busca-linhas] tr[data-i]', function () { abrir(itens[Number($(this).data('i'))]); });
    $b.on('keydown', '[data-busca-linhas] tr[data-i]', function (e) { if (e.key === 'Enter') abrir(itens[Number($(this).data('i'))]); if (e.key === 'ContextMenu') { var r = this.getBoundingClientRect(); menu(Number($(this).data('i')), r.left + 40, r.bottom); e.preventDefault(); } });
    $b.on('contextmenu', '[data-busca-linhas] tr[data-i]', function (e) { e.preventDefault(); $(this).addClass('selected').siblings().removeClass('selected'); menu(Number($(this).data('i')), e.clientX, e.clientY); });
    $b.on('click', '[data-busca-menu-abrir]', function (e) { e.stopPropagation(); var r = this.getBoundingClientRect(); menu(Number($(this).data('i')), r.left - 200, r.bottom + 2); });
    $b.on('click', '[data-busca-acao]', function (e) { e.preventDefault(); e.stopPropagation(); acao(String($(this).data('busca-acao')), itens[Number($(this).data('i'))]); });
    $menu.on('keydown', '[data-busca-acao]', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $(this).trigger('click'); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); $(this).nextAll('[data-busca-acao]').first().trigger('focus'); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); $(this).prevAll('[data-busca-acao]').first().trigger('focus'); }
    });
    $(document).on('click', function (e) { if (!$(e.target).closest('[data-busca-menu]').length) fecharMenu(); });
    $(document).on('keydown', function (e) { if (e.key === 'Escape') fecharMenu(); });
    $(window).on('blur resize scroll', fecharMenu);

    // Pesquisa vinda do cabeçalho do portal (mesma aba): window.buscaDocumentosPesquisar('texto')
    window.buscaDocumentosPesquisar = function (texto) { estado.q = String(texto || ''); $b.find('[data-busca-q]').val(estado.q); estado.pagina = 1; pesquisar(); };
    // Abre vazia; só pesquisa direto quando a URL já traz ?q= (ex.: link copiado)
    aplicarLarguras();
    if (estado.q) pesquisar(); else { telaInicial(); iconesOrdem(); $b.find('[data-busca-q]').trigger('focus'); }

    // Dentro da aba do portal o iframe tem altura mínima (main.css) e pode passar da área visível, ficando
    // atrás do rodapé do main.php: a tela usa a altura visível da aba, não a do iframe.
    (function (el) {
        if (!el) return;
        var aba = null;
        try { aba = window.frameElement ? window.frameElement.parentElement : null; } catch (e) { aba = null; }
        if (!aba) return;
        var ajustar = function () {
            var h = aba.clientHeight;
            if (h > 0) el.style.height = Math.min(h, window.innerHeight) + 'px';
        };
        ajustar();
        $(window).on('resize', ajustar);
        try { window.parent.addEventListener('resize', ajustar); } catch (e) { /* outro domínio */ }
        if (window.ResizeObserver) new ResizeObserver(ajustar).observe(aba);
    }($b[0]));
}(jQuery));
