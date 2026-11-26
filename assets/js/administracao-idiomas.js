/*
 * Administração › Textos e idiomas — edição dos textos das telas (PT/EN/ES) em rascunho, com publicação.
 * API: api/administracao_idiomas.php
 */
(function () {
    'use strict';

    var raiz = document.querySelector('[data-idiomas]');
    if (!raiz) return;
    var UI = {};
    try { UI = JSON.parse(document.getElementById('tiTextos').textContent || '{}'); } catch (e) { UI = {}; }
    var API = 'api/administracao_idiomas.php';
    var IDIOMAS = ['pt', 'en', 'es'];
    var idiomaTela = raiz.getAttribute('data-idioma') || 'pt';
    var podeEditar = raiz.getAttribute('data-pode-editar') === '1';
    var csrf = raiz.getAttribute('data-csrf') || '';

    var estado = { grupos: [], textos: [], situacao: {}, previa: false, edicoes: {}, grupo: '', busca: '', filtros: {}, ocupado: false };

    var el = {
        status: raiz.querySelector('[data-status]'),
        alerta: raiz.querySelector('[data-alerta]'),
        grupos: raiz.querySelector('[data-grupos]'),
        linhas: raiz.querySelector('[data-linhas]'),
        busca: raiz.querySelector('[data-busca]'),
        previa: raiz.querySelector('[data-previa]'),
        salvar: raiz.querySelector('[data-acao="salvar"]'),
        publicar: raiz.querySelector('[data-acao="publicar"]'),
        descartar: raiz.querySelector('[data-acao="descartar"]'),
        historico: raiz.querySelector('[data-acao="historico"]'),
        traduzir: raiz.querySelector('[data-acao="traduzir-ia"]')
    };

    // ---------- utilitários ----------
    function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function chaveEd(codtexto, idioma) { return codtexto + '|' + idioma; }
    function nomeGrupo(g) { return g ? (g.nome[idiomaTela] || g.nome.pt) : ''; }
    function grupoPorCodigo(cod) { for (var i = 0; i < estado.grupos.length; i++) if (estado.grupos[i].codgrupo === cod) return estado.grupos[i]; return null; }
    function dataHora(v) {
        if (!v) return '';
        var m = String(v).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if (!m) return v;
        return idiomaTela === 'en' ? (m[2] + '/' + m[3] + '/' + m[1] + ' ' + m[4] + ':' + m[5]) : (m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5]);
    }
    function formatar(t, vals) { return String(t || '').replace(/\{(\w+)\}/g, function (_, k) { return vals[k] == null ? '' : vals[k]; }); }

    function alerta(tipo, texto) {
        if (!texto) { el.alerta.className = 'ti-alert d-none'; el.alerta.textContent = ''; return; }
        el.alerta.className = 'ti-alert is-' + tipo;
        el.alerta.textContent = texto;
        if (tipo === 'success') window.setTimeout(function () { if (el.alerta.textContent === texto) alerta('', ''); }, 7000);
    }

    function chamar(acao, dados, metodo) {
        metodo = metodo || 'GET';
        var opcoes = { method: metodo, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
        var url = API + '?action=' + encodeURIComponent(acao);
        if (metodo === 'POST') {
            var corpo = new URLSearchParams();
            corpo.set('action', acao); corpo.set('csrf', csrf);
            Object.keys(dados || {}).forEach(function (k) { corpo.set(k, dados[k]); });
            opcoes.body = corpo;
        } else if (dados) {
            Object.keys(dados).forEach(function (k) { url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(dados[k]); });
        }
        return fetch(url, opcoes).then(function (r) {
            return r.json().catch(function () { return { ok: false, message: UI.erro }; }).then(function (j) {
                if (!r.ok || !j || !j.ok) { var erro = new Error((j && j.message) || UI.erro); erro.status = r.status; throw erro; }
                return j;
            });
        });
    }

    // ---------- estado de cada tradução ----------
    function valorAtual(t, idioma) {
        var k = chaveEd(t.codtexto, idioma);
        return Object.prototype.hasOwnProperty.call(estado.edicoes, k) ? estado.edicoes[k] : t.texto[idioma];
    }
    function sujo(t, idioma) {
        var k = chaveEd(t.codtexto, idioma);
        return Object.prototype.hasOwnProperty.call(estado.edicoes, k) && estado.edicoes[k] !== t.texto[idioma];
    }
    function pendente(t, idioma) { return t.publicado[idioma] === null || t.texto[idioma] !== t.publicado[idioma]; }
    function linhaSuja(t) { return IDIOMAS.some(function (i) { return sujo(t, i); }); }
    function linhaPendente(t) { return IDIOMAS.some(function (i) { return pendente(t, i); }); }
    function linhaFaltando(t) { return IDIOMAS.some(function (i) { return String(valorAtual(t, i)).trim() === ''; }); }
    function totalSujos() { var n = 0; estado.textos.forEach(function (t) { if (linhaSuja(t)) n++; }); return n; }

    function validar(idioma, valor) {
        var v = String(valor || '').trim();
        if (idioma === 'pt' && v === '') return UI.pt_obrigatorio;
        if (/[<>]/.test(v)) return UI.sem_tags;
        return '';
    }

    // ---------- desenho ----------
    function desenharStatus() {
        var s = estado.situacao || {}, partes = [];
        if (s.numero) partes.push('<span class="ti-pill"><i class="bi bi-cloud-check"></i>' + esc(UI.publicado) + ': ' + esc(UI.publicacao) + ' ' + esc(s.numero) + ' · ' + esc(dataHora(s.publicado_em)) + (s.publicado_por ? ' · ' + esc(UI.por) + ' ' + esc(s.publicado_por) : '') + '</span>');
        else partes.push('<span class="ti-pill">' + esc(UI.nunca) + '</span>');
        var sujos = totalSujos();
        if (sujos) partes.push('<span class="ti-pill is-dirty"><span class="ti-dot is-dirty"></span>' + sujos + ' ' + esc(UI.st_sujo) + '</span>');
        var pend = estado.textos.filter(linhaPendente).length;
        if (pend) partes.push('<span class="ti-pill is-pending"><span class="ti-dot is-pending"></span>' + pend + ' ' + esc(UI.textos) + ': ' + esc(UI.st_pendente) + '</span>');
        if (!sujos && !pend) partes.push('<span class="ti-pill is-ok"><i class="bi bi-check2"></i>' + esc(UI.st_igual) + '</span>');
        if (estado.previa) partes.push('<span class="ti-pill is-preview"><i class="bi bi-eye"></i>' + esc(UI.previa_on) + '</span>');
        if (!podeEditar) partes.push('<span class="ti-pill"><i class="bi bi-lock"></i>' + esc(UI.st_leitura) + '</span>');
        el.status.innerHTML = partes.join('');
        el.salvar.disabled = !podeEditar || !sujos || estado.ocupado;
        el.publicar.disabled = !podeEditar || estado.ocupado;
        el.descartar.disabled = !podeEditar || !pend || estado.ocupado;
        if (el.traduzir) el.traduzir.disabled = !podeEditar || estado.ocupado;
        var qtd = { sujo: 0, pendente: 0, faltando: 0 };
        visiveisPorGrupo().forEach(function (t) { if (linhaSuja(t)) qtd.sujo++; if (linhaPendente(t)) qtd.pendente++; if (linhaFaltando(t)) qtd.faltando++; });
        Object.keys(qtd).forEach(function (k) { var b = raiz.querySelector('[data-qtd="' + k + '"]'); if (b) b.textContent = qtd[k]; });
        desenharGrupos();
    }

    function desenharGrupos() {
        var html = '', total = estado.textos.length;
        var marca = function (lista) {
            if (lista.some(linhaSuja)) return '<span class="ti-dot is-dirty" title="' + esc(UI.b_sujo) + '"></span>';
            if (lista.some(linhaPendente)) return '<span class="ti-dot is-pending" title="' + esc(UI.b_pendente) + '"></span>';
            return '';
        };
        html += '<button type="button" class="ti-group' + (estado.grupo === '' ? ' is-selected' : '') + '" data-grupo="" role="option" aria-selected="' + (estado.grupo === '') + '"><i class="bi bi-collection"></i><span class="ti-group-name">' + esc(UI.todos_grupos) + '</span>' + marca(estado.textos) + '<span class="ti-group-count">' + total + '</span></button>';
        estado.grupos.forEach(function (g) {
            var lista = estado.textos.filter(function (t) { return t.grupo === g.codgrupo; });
            var sel = estado.grupo === g.codgrupo;
            html += '<button type="button" class="ti-group' + (sel ? ' is-selected' : '') + '" data-grupo="' + esc(g.codgrupo) + '" role="option" aria-selected="' + sel + '"><i class="bi ' + (g.codgrupo === 'MENUS' ? 'bi-list' : (g.transacao ? 'bi-window' : 'bi-globe2')) + '"></i><span class="ti-group-name">' + esc(nomeGrupo(g)) + (g.transacao ? '<small>' + esc(g.transacao) + '</small>' : '') + '</span>' + marca(lista) + '<span class="ti-group-count">' + lista.length + '</span></button>';
        });
        el.grupos.innerHTML = html;
    }

    function visiveisPorGrupo() {
        return estado.textos.filter(function (t) { return !estado.grupo || t.grupo === estado.grupo; });
    }

    function visiveis() {
        var busca = estado.busca.toLowerCase();
        return visiveisPorGrupo().filter(function (t) {
            if (estado.filtros.sujo && !linhaSuja(t)) return false;
            if (estado.filtros.pendente && !linhaPendente(t)) return false;
            if (estado.filtros.faltando && !linhaFaltando(t)) return false;
            if (!busca) return true;
            var alvo = [t.chave, t.descricao].concat(IDIOMAS.map(function (i) { return valorAtual(t, i); })).join(' ').toLowerCase();
            return alvo.indexOf(busca) >= 0;
        });
    }

    function celula(t, idioma) {
        var valor = valorAtual(t, idioma), classes = ['ti-campo'];
        if (sujo(t, idioma)) classes.push('is-dirty'); else if (pendente(t, idioma)) classes.push('is-pending');
        if (String(valor).trim() === '') classes.push('is-missing');
        var pub = t.publicado[idioma];
        var mostrarPub = pub !== null && pub !== valor;
        return '<td><textarea class="' + classes.join(' ') + '" rows="1" data-campo data-codtexto="' + t.codtexto + '" data-lang="' + idioma + '" maxlength="1000" lang="' + idioma + '"' + (podeEditar ? '' : ' readonly') + (String(valor).trim() === '' && idioma !== 'pt' ? ' placeholder="' + esc(valorAtual(t, 'pt')) + '" title="' + esc(UI.b_faltando) + '"' : '') + '>' + esc(valor) + '</textarea>'
            + '<div class="ti-pub" data-pub="' + t.codtexto + '|' + idioma + '"' + (mostrarPub ? '' : ' hidden') + '>' + esc(UI.publicado_era) + ' <b>' + esc(pub === null ? UI.h_novo : (pub === '' ? UI.h_vazio : pub)) + '</b></div></td>';
    }

    function situacaoHtml(t) {
        var html = '';
        if (linhaSuja(t)) html += '<span class="ti-dot is-dirty" title="' + esc(UI.b_sujo) + '"></span>';
        else if (linhaPendente(t)) html += '<span class="ti-dot is-pending" title="' + esc(UI.b_pendente) + '"></span>';
        else html += '<span class="ti-dot" title="' + esc(UI.b_ok) + '"></span>';
        if (IDIOMAS.some(function (i) { return String(valorAtual(t, i)).trim() === ''; })) html += '<span class="ti-dot is-missing" title="' + esc(UI.b_faltando) + '"></span>';
        if (podeEditar && (linhaSuja(t) || linhaPendente(t))) html += '<button type="button" class="ti-undo" data-desfazer="' + t.codtexto + '" title="' + esc(UI.desfazer) + '" aria-label="' + esc(UI.desfazer) + '"><i class="bi bi-arrow-counterclockwise"></i></button>';
        return html;
    }

    function desenharTabela() {
        var lista = visiveis(), html = '', grupoAtual = null;
        if (!lista.length) { el.linhas.innerHTML = '<tr><td colspan="5" class="ti-empty">' + esc(UI.nenhum) + '</td></tr>'; desenharStatus(); return; }
        lista.forEach(function (t) {
            if (!estado.grupo && t.grupo !== grupoAtual) {
                grupoAtual = t.grupo;
                html += '<tr class="ti-sep"><td colspan="5">' + esc(nomeGrupo(grupoPorCodigo(t.grupo)) || t.grupo) + '</td></tr>';
            }
            html += '<tr data-linha="' + t.codtexto + '">'
                + '<td><div class="ti-onde-desc">' + esc(t.descricao || t.chave) + '</div><div class="ti-onde-meta"><span class="ti-tipo">' + esc(UI['tipo_' + t.tipo] || t.tipo) + '</span><span class="ti-chave">' + esc(t.chave) + '</span></div></td>'
                + celula(t, 'pt') + celula(t, 'en') + celula(t, 'es')
                + '<td class="ti-sit" data-sit="' + t.codtexto + '">' + situacaoHtml(t) + '</td></tr>';
        });
        el.linhas.innerHTML = html;
        ajustarTodas(el.linhas.querySelectorAll('textarea[data-campo]'));
        desenharStatus();
    }

    function ajustarAltura(ta) { ta.style.height = 'auto'; ta.style.height = Math.max(30, ta.scrollHeight + 2) + 'px'; }
    // Ajusta todas de uma vez (primeiro lê, depois grava) para não recalcular o layout a cada campo.
    function ajustarTodas(lista) {
        var campos = Array.prototype.slice.call(lista);
        campos.forEach(function (ta) { ta.style.height = 'auto'; });
        var alturas = campos.map(function (ta) { return ta.scrollHeight; });
        campos.forEach(function (ta, i) { ta.style.height = Math.max(30, alturas[i] + 2) + 'px'; });
    }

    function textoPorCodigo(cod) { for (var i = 0; i < estado.textos.length; i++) if (estado.textos[i].codtexto === cod) return estado.textos[i]; return null; }

    // Atualiza só a célula editada (sem redesenhar a tabela, para não perder o foco).
    function atualizarCelula(ta) {
        var cod = Number(ta.getAttribute('data-codtexto')), idioma = ta.getAttribute('data-lang'), t = textoPorCodigo(cod);
        if (!t) return;
        var valor = ta.value, k = chaveEd(cod, idioma);
        if (valor === t.texto[idioma]) delete estado.edicoes[k]; else estado.edicoes[k] = valor;
        ta.classList.toggle('is-dirty', sujo(t, idioma));
        ta.classList.toggle('is-pending', !sujo(t, idioma) && pendente(t, idioma));
        ta.classList.toggle('is-missing', valor.trim() === '');
        var erro = validar(idioma, valor);
        ta.classList.toggle('is-invalid', !!erro);
        ta.title = erro || (valor.trim() === '' && idioma !== 'pt' ? UI.b_faltando : '');
        var pubEl = el.linhas.querySelector('[data-pub="' + cod + '|' + idioma + '"]'), pub = t.publicado[idioma];
        if (pubEl) pubEl.hidden = !(pub !== null && pub !== valor);
        var sit = el.linhas.querySelector('[data-sit="' + cod + '"]');
        if (sit) sit.innerHTML = situacaoHtml(t);
        ajustarAltura(ta);
        desenharStatus();
    }

    // ---------- ações ----------
    function aplicarResposta(r) {
        if (r.textos) estado.textos = r.textos;
        if (r.situacao) estado.situacao = r.situacao;
        if (r.grupos) estado.grupos = r.grupos;
        if (typeof r.previa === 'boolean') { estado.previa = r.previa; if (el.previa) el.previa.checked = r.previa; }
    }

    function carregar() {
        return chamar('carregar').then(function (r) {
            aplicarResposta(r);
            estado.edicoes = {};
            desenharTabela();
        }).catch(function (e) {
            el.linhas.innerHTML = '<tr><td colspan="5" class="ti-empty">' + esc(e.message) + '</td></tr>';
            alerta('error', e.message);
        });
    }

    function ocupado(sim) { estado.ocupado = sim; desenharStatus(); }

    function salvar() {
        if (!podeEditar || estado.ocupado) return Promise.resolve(false);
        var itens = [], erros = [];
        estado.textos.forEach(function (t) {
            IDIOMAS.forEach(function (i) {
                if (!sujo(t, i)) return;
                var valor = estado.edicoes[chaveEd(t.codtexto, i)], erro = validar(i, valor);
                if (erro) erros.push((t.descricao || t.chave) + ' (' + i.toUpperCase() + '): ' + erro);
                itens.push({ codtexto: t.codtexto, idioma: i, texto: valor, revisao: t.revisao[i] });
            });
        });
        if (!itens.length) { alerta('info', UI.nada_salvar); return Promise.resolve(true); }
        if (erros.length) { alerta('error', erros.slice(0, 8).join('\n')); return Promise.resolve(false); }
        ocupado(true);
        return chamar('salvar', { itens: JSON.stringify(itens) }, 'POST').then(function (r) {
            aplicarResposta(r);
            estado.edicoes = {};
            desenharTabela();
            alerta('success', UI.salvo);
            return true;
        }).catch(function (e) { alerta('error', e.message); return false; }).then(function (ok) { ocupado(false); return ok; });
    }

    function abrirPublicar() {
        if (totalSujos()) { alerta('info', UI.salve_antes); return; }
        var linhas = [];
        estado.textos.forEach(function (t) {
            IDIOMAS.forEach(function (i) {
                if (!pendente(t, i)) return;
                linhas.push('<tr><td>' + esc(t.descricao || t.chave) + '<div class="ti-chave">' + esc(t.chave) + '</div></td><td><span class="ti-flag">' + i.toUpperCase() + '</span></td><td class="ti-antes">' + esc(t.publicado[i] === null ? UI.h_novo : (t.publicado[i] || UI.h_vazio)) + '</td><td class="ti-depois">' + esc(t.texto[i] || UI.h_vazio) + '</td></tr>');
            });
        });
        if (!linhas.length) { alerta('info', UI.pub_nada); return; }
        document.querySelector('[data-pub-lista]').innerHTML = '<table><thead><tr><th>' + esc(UI.c_texto) + '</th><th></th><th>' + esc(UI.h_antes) + '</th><th>' + esc(UI.h_depois) + '</th></tr></thead><tbody>' + linhas.join('') + '</tbody></table>';
        document.getElementById('tiObservacao').value = '';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('tiModalPublicar')).show();
    }

    function publicar() {
        var botao = document.querySelector('[data-confirmar-publicacao]');
        botao.disabled = true; ocupado(true);
        chamar('publicar', { observacao: document.getElementById('tiObservacao').value || '' }, 'POST').then(function (r) {
            aplicarResposta(r);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('tiModalPublicar')).hide();
            desenharTabela();
            alerta('success', formatar(UI.publicado_ok, { n: r.publicacao.numero, q: r.publicacao.qtd }));
        }).catch(function (e) { alerta('error', e.message); }).then(function () { botao.disabled = false; ocupado(false); });
    }

    function descartar(codtextos) {
        ocupado(true);
        return chamar('descartar', { codtextos: codtextos ? JSON.stringify(codtextos) : 'todos' }, 'POST').then(function (r) {
            aplicarResposta(r);
            desenharTabela();
            alerta('success', formatar(UI.descartado_ok, { q: r.descartados }));
        }).catch(function (e) { alerta('error', e.message); }).then(function () { ocupado(false); });
    }

    function desfazerLinha(cod) {
        var t = textoPorCodigo(cod);
        if (!t) return;
        IDIOMAS.forEach(function (i) { delete estado.edicoes[chaveEd(cod, i)]; });
        if (linhaPendente(t)) descartar([cod]); else desenharTabela();
    }

    function abrirHistorico() {
        var corpo = document.querySelector('[data-historico]'), itens = document.querySelector('[data-historico-itens]');
        corpo.innerHTML = '<tr><td colspan="6">' + esc(UI.carregando) + '</td></tr>';
        itens.classList.add('d-none'); itens.innerHTML = '';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('tiModalHistorico')).show();
        chamar('publicacoes').then(function (r) {
            if (!r.publicacoes.length) { corpo.innerHTML = '<tr><td colspan="6">' + esc(UI.h_nenhum) + '</td></tr>'; return; }
            corpo.innerHTML = r.publicacoes.map(function (p) {
                return '<tr data-pub-linha="' + p.codpublicacao + '"><td><b>' + p.numero + '</b>' + (p.vigente ? ' <span class="badge text-bg-success">' + esc(UI.publicado) + '</span>' : '') + '</td><td>' + esc(dataHora(p.publicado_em)) + '</td><td>' + esc(p.publicado_por) + '</td><td>' + esc(p.origem === 'CARGA_INICIAL' ? UI.h_carga : p.observacao) + '</td><td class="text-end">' + (p.origem === 'CARGA_INICIAL' ? '—' : p.qtd) + '</td><td class="text-end">' + (p.origem === 'CARGA_INICIAL' ? '' : '<button type="button" class="btn btn-sm btn-outline-secondary py-0" data-ver-publicacao="' + p.codpublicacao + '">' + esc(UI.h_ver) + '</button>') + '</td></tr>';
            }).join('');
        }).catch(function (e) { corpo.innerHTML = '<tr><td colspan="6" class="text-danger">' + esc(e.message) + '</td></tr>'; });
    }

    function verPublicacao(cod) {
        var itens = document.querySelector('[data-historico-itens]');
        Array.prototype.forEach.call(document.querySelectorAll('[data-pub-linha]'), function (tr) { tr.classList.toggle('is-open', tr.getAttribute('data-pub-linha') === String(cod)); });
        itens.classList.remove('d-none'); itens.innerHTML = esc(UI.carregando);
        chamar('itens', { codpublicacao: cod }).then(function (r) {
            itens.innerHTML = '<table><thead><tr><th>' + esc(UI.c_texto) + '</th><th></th><th>' + esc(UI.h_antes) + '</th><th>' + esc(UI.h_depois) + '</th></tr></thead><tbody>' + r.itens.map(function (i) {
                return '<tr><td>' + esc(i.descricao || i.chave) + '<div class="ti-chave">' + esc(i.chave) + '</div></td><td><span class="ti-flag">' + esc(i.idioma.toUpperCase()) + '</span></td><td class="ti-antes">' + esc(i.anterior === null ? UI.h_novo : (i.anterior || UI.h_vazio)) + '</td><td class="ti-depois">' + esc(i.novo || UI.h_vazio) + '</td></tr>';
            }).join('') + '</tbody></table>';
        }).catch(function (e) { itens.innerHTML = '<span class="text-danger">' + esc(e.message) + '</span>'; });
    }

    // ---------- eventos ----------
    // Revisão G item 17: a IA traduz o PT dos textos visíveis que estão sem EN/ES; as traduções entram como edição (rascunho
    // só ao Salvar), para revisar antes de publicar.
    function traduzirIa() {
        var lista = visiveis().map(function (t) {
            var falta = ['en', 'es'].filter(function (i) { return String(valorAtual(t, i)).trim() === ''; });
            return falta.length && String(valorAtual(t, 'pt')).trim() !== '' ? { codtexto: t.codtexto, pt: valorAtual(t, 'pt'), idiomas: falta, descricao: t.descricao || t.chave } : null;
        }).filter(Boolean);
        if (!lista.length) { alerta('info', UI.ia_nada || 'Nenhum texto visível está sem inglês ou espanhol. Use o filtro "Faltando" ou um grupo.'); return; }
        var lotes = [], total = lista.length, feitos = 0, traduzidos = 0;
        for (var i = 0; i < lista.length && i < 400; i += 40) lotes.push(lista.slice(i, i + 40));
        ocupado(true);
        var proximo = function () {
            if (!lotes.length) {
                ocupado(false); desenharTabela(); desenharStatus();
                alerta('success', formatar(UI.ia_feito || '{n} tradução(ões) da IA na tela. Confira e clique em Salvar; depois Publicar.', { n: traduzidos }));
                return;
            }
            var lote = lotes.shift();
            alerta('info', formatar(UI.ia_andamento || 'A IA está traduzindo… {f} de {t}', { f: feitos, t: Math.min(total, 400) }));
            chamar('traduzir_ia', { itens: JSON.stringify(lote) }, 'POST').then(function (r) {
                (r.traducoes || []).forEach(function (x) {
                    var t = textoPorCodigo(Number(x.codtexto)); if (!t) return;
                    if (String(valorAtual(t, x.idioma)).trim() !== '') return;
                    estado.edicoes[chaveEd(t.codtexto, x.idioma)] = x.texto; traduzidos++;
                });
                feitos += lote.length; proximo();
            }).catch(function (e) { ocupado(false); desenharTabela(); desenharStatus(); alerta('error', e.message); });
        };
        proximo();
    }
    if (el.traduzir) el.traduzir.addEventListener('click', traduzirIa);

    el.linhas.addEventListener('input', function (ev) { if (ev.target.matches('textarea[data-campo]')) atualizarCelula(ev.target); });
    el.linhas.addEventListener('keydown', function (ev) { if (ev.target.matches('textarea[data-campo]') && ev.key === 'Enter') ev.preventDefault(); });
    el.linhas.addEventListener('click', function (ev) { var b = ev.target.closest('[data-desfazer]'); if (b) desfazerLinha(Number(b.getAttribute('data-desfazer'))); });
    el.grupos.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-grupo]');
        if (!b) return;
        estado.grupo = b.getAttribute('data-grupo') || '';
        desenharTabela();
    });
    var buscaTimer = null;
    el.busca.addEventListener('input', function () { window.clearTimeout(buscaTimer); buscaTimer = window.setTimeout(function () { estado.busca = el.busca.value.trim(); desenharTabela(); }, 200); });
    Array.prototype.forEach.call(raiz.querySelectorAll('[data-filtro]'), function (b) {
        b.addEventListener('click', function () {
            var f = b.getAttribute('data-filtro');
            estado.filtros[f] = !estado.filtros[f];
            b.setAttribute('aria-pressed', estado.filtros[f] ? 'true' : 'false');
            desenharTabela();
        });
    });
    el.salvar.addEventListener('click', salvar);
    el.publicar.addEventListener('click', abrirPublicar);
    el.historico.addEventListener('click', abrirHistorico);
    el.descartar.addEventListener('click', function () { bootstrap.Modal.getOrCreateInstance(document.getElementById('tiModalDescartar')).show(); });
    document.querySelector('[data-confirmar-publicacao]').addEventListener('click', publicar);
    document.querySelector('[data-confirmar-descarte]').addEventListener('click', function () {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('tiModalDescartar')).hide();
        estado.edicoes = {};
        descartar(null);
    });
    document.querySelector('[data-historico]').addEventListener('click', function (ev) { var b = ev.target.closest('[data-ver-publicacao]'); if (b) verPublicacao(Number(b.getAttribute('data-ver-publicacao'))); });
    if (el.previa) el.previa.addEventListener('change', function () {
        var ativa = el.previa.checked;
        chamar('previa', { ativa: ativa ? '1' : '0' }, 'POST').then(function (r) { aplicarResposta(r); desenharStatus(); }).catch(function (e) { el.previa.checked = !ativa; alerta('error', e.message); });
    });
    document.addEventListener('keydown', function (ev) {
        if ((ev.ctrlKey || ev.metaKey) && String(ev.key).toLowerCase() === 's') { ev.preventDefault(); salvar(); }
    });
    window.addEventListener('beforeunload', function (ev) { if (totalSujos()) { ev.preventDefault(); ev.returnValue = UI.sair_sem_salvar; return UI.sair_sem_salvar; } });

    carregar();
}());
