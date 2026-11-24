/*
 * Ícones de status nos grids dos módulos (onda 1, "visual mais clean" — itens 46, 57, 80, 96, 111, 126, 142 e 161 da
 * planilha Revisão G, 24/11/2026). Troca as linhas pintadas por um ícone pequeno no início da linha, com dica (title),
 * e uma legenda ao lado da paginação. Mesmo conjunto em todos os módulos:
 *
 *   ok ............. verde, visto ............ em dia / atendido / válida / encerrada
 *   atencao ........ amarelo, exclamação ..... prazo de reação atingido / parcialmente atendido
 *   vencido ........ vermelho, relógio ....... prazo vencido
 *   critico ........ vermelho escuro, bandeira não atendido / risco alto / falha
 *   andamento ...... azul, seta .............. em andamento no prazo / em protocolo
 *   nao_avaliado ... cinza, interrogação ..... sem data, não avaliado, sem requisito vinculado
 *   inativo ........ cinza claro, traço ...... desativado
 *
 * Carregado no main.php; instala-se no documento do portal e nos iframes do mesmo endereço (como filtro-memoria.js).
 * Cada regra é ligada ao fragmento do grid (fragment_class) e lê as colunas da própria linha. Para Controles,
 * Treinamentos e Infraestrutura (sem datas no cadastro) a situação vem da avaliação legal de cada item
 * (vw_sl_ev_<pilar>, a mesma da Home do módulo) pela api/status_itens.php.
 * Textos com acento escritos como \u.... para não sofrerem a correção automática.
 */
(function () {
    'use strict';
    var topo = window;
    try { while (topo.parent && topo.parent !== topo && topo.parent.document) topo = topo.parent; } catch (e) { /* outro endere\u00e7o */ }
    var idioma = String(topo.greenPortalIdioma || document.documentElement.getAttribute('lang') || 'pt').slice(0, 2).toLowerCase();
    var BASE = String(topo.greenPortalBaseUrl || '..');

    var TX = {
        pt: { ok: 'Em dia', atencao: 'Em aten\u00e7\u00e3o', vencido: 'Vencido', critico: 'Cr\u00edtico', andamento: 'Em andamento', nao_avaliado: 'N\u00e3o avaliado / sem data', inativo: 'Inativo',
            lic_ok: 'V\u00e1lida', lic_atencao: 'Em aten\u00e7\u00e3o (prazo de rea\u00e7\u00e3o atingido)', lic_vencido: 'Vencida', lic_protocolo: 'V\u00e1lida (em protocolo): renova\u00e7\u00e3o protocolada no prazo', lic_prot_sem: 'Em protocolo (prazo n\u00e3o informado)', lic_sem: 'Sem data de vencimento', lic_entrega: 'Entrega peri\u00f3dica',
            leg_ok: 'Atendido', leg_atencao: 'Parcialmente atendido', leg_critico: 'N\u00e3o atendido', leg_na: 'N\u00e3o avaliado (indefinido)',
            med_ok: 'Medi\u00e7\u00e3o em dia', med_atencao: 'Prazo de rea\u00e7\u00e3o atingido', med_vencido: 'Data da medi\u00e7\u00e3o vencida', med_na: 'Sem data da medi\u00e7\u00e3o',
            nc_ok: 'Encerrada', nc_andamento: 'Em andamento no prazo', nc_vencido: 'Prazo vencido', nc_critico: 'Risco alto',
            fo_ok: 'Avalia\u00e7\u00e3o em dia', fo_atencao: 'Pr\u00f3xima avalia\u00e7\u00e3o em at\u00e9 30 dias', fo_vencido: 'Avalia\u00e7\u00e3o vencida', fo_na: 'Sem data da pr\u00f3xima avalia\u00e7\u00e3o', fo_inativo: 'Fornecedor desativado',
            pi_ok: 'Requisitos legais atendidos', pi_atencao: 'Aten\u00e7\u00e3o em algum requisito legal', pi_vencido: 'Vencido em algum requisito legal', pi_critico: 'Falha em algum requisito legal', pi_na: 'Sem requisito legal vinculado',
            legenda: 'Legenda dos \u00edcones de status' },
        en: { ok: 'Up to date', atencao: 'Attention', vencido: 'Expired', critico: 'Critical', andamento: 'In progress', nao_avaliado: 'Not assessed / no date', inativo: 'Inactive',
            lic_ok: 'Valid', lic_atencao: 'Attention (reaction date reached)', lic_vencido: 'Expired', lic_protocolo: 'Valid (renewal filed on time)', lic_prot_sem: 'Renewal filed (deadline not informed)', lic_sem: 'No due date', lic_entrega: 'Periodic submission',
            leg_ok: 'Met', leg_atencao: 'Partially met', leg_critico: 'Not met', leg_na: 'Not assessed (undefined)',
            med_ok: 'Measurement up to date', med_atencao: 'Reaction date reached', med_vencido: 'Measurement date expired', med_na: 'No measurement date',
            nc_ok: 'Closed', nc_andamento: 'In progress, on time', nc_vencido: 'Deadline expired', nc_critico: 'High risk',
            fo_ok: 'Evaluation up to date', fo_atencao: 'Next evaluation within 30 days', fo_vencido: 'Evaluation expired', fo_na: 'No next evaluation date', fo_inativo: 'Supplier deactivated',
            pi_ok: 'Legal requirements met', pi_atencao: 'Attention in a legal requirement', pi_vencido: 'Expired in a legal requirement', pi_critico: 'Failure in a legal requirement', pi_na: 'No linked legal requirement',
            legenda: 'Status icon legend' },
        es: { ok: 'Al d\u00eda', atencao: 'En atenci\u00f3n', vencido: 'Vencido', critico: 'Cr\u00edtico', andamento: 'En curso', nao_avaliado: 'No evaluado / sin fecha', inativo: 'Inactivo',
            lic_ok: 'V\u00e1lida', lic_atencao: 'En atenci\u00f3n (fecha de reacci\u00f3n alcanzada)', lic_vencido: 'Vencida', lic_protocolo: 'V\u00e1lida (en protocolo)', lic_prot_sem: 'En protocolo (plazo no informado)', lic_sem: 'Sin fecha de vencimiento', lic_entrega: 'Entrega peri\u00f3dica',
            leg_ok: 'Cumplido', leg_atencao: 'Parcialmente cumplido', leg_critico: 'No cumplido', leg_na: 'No evaluado (indefinido)',
            med_ok: 'Medici\u00f3n al d\u00eda', med_atencao: 'Fecha de reacci\u00f3n alcanzada', med_vencido: 'Fecha de medici\u00f3n vencida', med_na: 'Sin fecha de medici\u00f3n',
            nc_ok: 'Cerrada', nc_andamento: 'En curso, en plazo', nc_vencido: 'Plazo vencido', nc_critico: 'Riesgo alto',
            fo_ok: 'Evaluaci\u00f3n al d\u00eda', fo_atencao: 'Pr\u00f3xima evaluaci\u00f3n en hasta 30 d\u00edas', fo_vencido: 'Evaluaci\u00f3n vencida', fo_na: 'Sin fecha de pr\u00f3xima evaluaci\u00f3n', fo_inativo: 'Proveedor desactivado',
            pi_ok: 'Requisitos legales cumplidos', pi_atencao: 'Atenci\u00f3n en alg\u00fan requisito legal', pi_vencido: 'Vencido en alg\u00fan requisito legal', pi_critico: 'Falla en alg\u00fan requisito legal', pi_na: 'Sin requisito legal vinculado',
            legenda: 'Leyenda de los iconos de estado' }
    };
    var T = TX[idioma] || TX.pt;
    function t(k) {
        var padrao = T[k] || TX.pt[k] || k;
        // Textos de Administração › Textos e idiomas, quando cadastrados.
        try { if (typeof topo.greenT === 'function') return topo.greenT('status_icones.' + k, padrao); } catch (e) { /* sem tradu\u00e7\u00e3o */ }
        return padrao;
    }

    var COR = { ok: '#2e9d5b', atencao: '#d99a1e', vencido: '#d64541', critico: '#9f1c1c', andamento: '#3b7dd8', nao_avaliado: '#8a96a0', inativo: '#b9c1c6' };
    var DESENHO = {
        ok: '<path d="M6.3 11 3.4 8.1l1.1-1.1 1.8 1.8 4.6-4.6 1.1 1.1z" fill="#fff"/>',
        atencao: '<rect x="7" y="3.4" width="2" height="6" rx="1" fill="#fff"/><circle cx="8" cy="12" r="1.15" fill="#fff"/>',
        vencido: '<circle cx="8" cy="8" r="4.6" fill="none" stroke="#fff" stroke-width="1.5"/><path d="M8 5.4V8l1.9 1.3" fill="none" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/>',
        critico: '<path d="M5.2 3.3v9.4" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/><path d="M5.8 3.6h5.6L10 5.9l1.4 2.3H5.8z" fill="#fff"/>',
        andamento: '<path d="M6.3 4.6v6.8L11.6 8z" fill="#fff"/>',
        nao_avaliado: '<path d="M6.1 6.2a1.9 1.9 0 1 1 2.6 1.8c-.5.2-.7.5-.7 1v.5" fill="none" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/><circle cx="8" cy="11.9" r="1" fill="#fff"/>',
        inativo: '<rect x="4.3" y="7.2" width="7.4" height="1.6" rx=".8" fill="#fff"/>'
    };
    var ORDEM = ['critico', 'vencido', 'atencao', 'andamento', 'ok', 'nao_avaliado', 'inativo'];
    function svg(estado) {
        return '<svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"><circle cx="8" cy="8" r="8" fill="' + COR[estado] + '"/>' + DESENHO[estado] + '</svg>';
    }

    /* ---------- leitura das células ---------- */
    function celula(tr, col) { return tr.querySelector('td[data-column="' + col + '"]'); }
    function valor(tr, col) {
        var td = celula(tr, col);
        if (!td) return null;
        var bruto = td.querySelector('[data-raw-value]');
        if (bruto && bruto.getAttribute('data-raw-value') !== '') return String(bruto.getAttribute('data-raw-value')).trim();
        var campo = td.querySelector('select, input, textarea');
        if (campo) {
            if (campo.tagName === 'SELECT') { var op = campo.options[campo.selectedIndex]; return String(campo.value || (op ? op.text : '') || '').trim(); }
            return String(campo.value || '').trim();
        }
        return String(td.textContent || '').trim();
    }
    function texto(tr, col) {
        var td = celula(tr, col);
        if (!td) return '';
        var campo = td.querySelector('select, input, textarea');
        if (campo && campo.tagName === 'SELECT') { var op = campo.options[campo.selectedIndex]; return String(op ? op.text : '').trim(); }
        if (campo) return String(campo.value || '').trim();
        return String(td.textContent || '').trim();
    }
    function data(tr, col) {
        var v = valor(tr, col);
        if (!v) return '';
        var m = v.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (m) return m[1] === '0000' ? '' : m[1] + '-' + m[2] + '-' + m[3];
        m = v.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
        if (m) return m[3] === '0000' ? '' : m[3] + '-' + m[2] + '-' + m[1];
        return '';
    }
    function sem(v) { return String(v || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase().trim(); }
    function hoje(somaDias) {
        var d = new Date();
        if (somaDias) d.setDate(d.getDate() + somaDias);
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }
    function sim(v) { v = sem(v); return v === 'S' || v === 'SIM' || v === 'YES' || v === 'SI' || v === '1'; }

    /* ---------- regras por módulo: devolvem [estado, dica] ou null ---------- */
    var REGRAS = [
        { sel: '.licencas-grid-fragment', legenda: [['vencido', 'lic_vencido'], ['andamento', 'lic_protocolo'], ['atencao', 'lic_atencao'], ['ok', 'lic_ok'], ['nao_avaliado', 'lic_sem']],
            estado: function (tr) {
                var sit = sem(valor(tr, 'SITUACAO_LICENCA'));
                var venc = data(tr, 'DATA_VALIDADE'), reacao = data(tr, 'DATA_REACAO'), h = hoje();
                if (sit === 'VENCIDA') return ['vencido', 'lic_vencido'];
                if (sit === 'PROTOCOLO') return ['andamento', 'lic_protocolo'];
                if (sit === 'PROT_SEM_PRAZO') return ['atencao', 'lic_prot_sem'];
                if (sit === 'SEM_DATA') return ['nao_avaliado', 'lic_sem'];
                if (sit === 'ENTREGA') return reacao && reacao <= h ? ['atencao', 'lic_entrega'] : ['ok', 'lic_entrega'];
                if (sit === 'VALIDA') return reacao && reacao <= h ? ['atencao', 'lic_atencao'] : ['ok', 'lic_ok'];
                // Sem a coluna calculada: pelas datas da linha.
                if (!venc) return celula(tr, 'DATA_VALIDADE') ? ['nao_avaliado', 'lic_sem'] : null;
                if (venc < h) return sim(valor(tr, 'EMPROTOCOLO')) ? ['atencao', 'lic_prot_sem'] : ['vencido', 'lic_vencido'];
                if (reacao && reacao <= h) return ['atencao', 'lic_atencao'];
                return ['ok', 'lic_ok'];
            } },
        { sel: '.modulo-legal-requisitos-fragment', legenda: [['critico', 'leg_critico'], ['atencao', 'leg_atencao'], ['ok', 'leg_ok'], ['nao_avaliado', 'leg_na']],
            estado: function (tr) {
                var v = valor(tr, 'SITUACAO_CONSOLIDADA');
                if (v === null) v = valor(tr, 'ATENDIDO');
                if (v === null) return null;
                v = sem(v);
                if (v.indexOf('PARCIAL') === 0) return ['atencao', 'leg_atencao'];
                if (v.indexOf('NAO ATENDIDO') === 0 || v === 'NAO' || v === 'N') return ['critico', 'leg_critico'];
                if (v.indexOf('ATENDIDO') === 0 || v === 'SIM' || v === 'S') return ['ok', 'leg_ok'];
                return ['nao_avaliado', 'leg_na'];
            } },
        { sel: '.medicao-monitoramento-grid-fragment', legenda: [['vencido', 'med_vencido'], ['atencao', 'med_atencao'], ['ok', 'med_ok'], ['nao_avaliado', 'med_na']],
            estado: function (tr) {
                if (!celula(tr, 'DATA_MEDICAO')) return null;
                var venc = data(tr, 'DATA_MEDICAO'), reacao = data(tr, 'DATA_REACAO'), h = hoje();
                if (!venc) return ['nao_avaliado', 'med_na'];
                if (venc < h) return ['vencido', 'med_vencido'];
                if (reacao && reacao <= h) return ['atencao', 'med_atencao'];
                return ['ok', 'med_ok'];
            } },
        { sel: '.nao-conformidades-grid-fragment', legenda: [['critico', 'nc_critico'], ['vencido', 'nc_vencido'], ['andamento', 'nc_andamento'], ['ok', 'nc_ok']],
            estado: function (tr) {
                if (!celula(tr, 'SITUACAO') && !celula(tr, 'ETAPA')) return null;
                var etapa = sem(texto(tr, 'ETAPA')), sit = sem(texto(tr, 'SITUACAO'));
                if (etapa === 'FINALIZADO' || sit.indexOf('ENCERRAD') === 0 || sit.indexOf('CONCLUID') === 0 || sit.indexOf('FINALIZAD') === 0) return ['ok', 'nc_ok'];
                var h = hoje(), prev = data(tr, 'DATA_DE_CONCLUSAO_PREVISTA'), prazo = data(tr, 'PRAZO_ANALISE');
                if ((prev && prev < h) || (prazo && prazo < h) || sit.indexOf('ATRASAD') === 0 || sit.indexOf('VENCID') === 0) return ['vencido', 'nc_vencido'];
                var risco = sem(texto(tr, 'RISCO'));
                if (risco.indexOf('ALTO') === 0 || risco.indexOf('CRITICO') === 0 || risco.indexOf('MUITO ALTO') === 0) return ['critico', 'nc_critico'];
                return ['andamento', 'nc_andamento'];
            } },
        { sel: '.homologacao-fornecedor-grid-fragment', legenda: [['vencido', 'fo_vencido'], ['atencao', 'fo_atencao'], ['ok', 'fo_ok'], ['nao_avaliado', 'fo_na'], ['inativo', 'fo_inativo']],
            estado: function (tr) {
                if (!celula(tr, 'DATA_DA_PROXIMA_AVALIACAO') && !celula(tr, 'ATIVADOFOR')) return null;
                var ativo = valor(tr, 'ATIVADOFOR');
                if (ativo !== null && ativo !== '' && !sim(ativo)) return ['inativo', 'fo_inativo'];
                var prox = data(tr, 'DATA_DA_PROXIMA_AVALIACAO');
                if (!prox) return ['nao_avaliado', 'fo_na'];
                if (prox < hoje()) return ['vencido', 'fo_vencido'];
                if (prox <= hoje(30)) return ['atencao', 'fo_atencao'];
                return ['ok', 'fo_ok'];
            } },
        { sel: '.controle-documentos-grid-fragment', pilar: 'controles', id: 'CODDOCUMENTO' },
        { sel: '.modulo-legal-treinamentos-grid-fragment', pilar: 'treinamentos', id: 'CODTREINAMENTO' },
        { sel: '.modulo-legal-infraestrutura-grid-fragment', pilar: 'infraestrutura', id: 'CODINFRAESTRUTURA' }
    ];
    var LEGENDA_PILAR = [['critico', 'pi_critico'], ['vencido', 'pi_vencido'], ['atencao', 'pi_atencao'], ['ok', 'pi_ok'], ['nao_avaliado', 'pi_na']];
    var PILAR_ESTADO = { CRITICO: ['vencido', 'pi_vencido'], FALHA: ['critico', 'pi_critico'], ATENCAO: ['atencao', 'pi_atencao'], OK: ['ok', 'pi_ok'] };

    /* ---------- situação dos itens dos pilares (uma consulta por janela e pilar, renovada a cada 2 min) ---------- */
    function pilarDados(win, pilar, pronto) {
        var cache = win.__gpStatusPilar || (win.__gpStatusPilar = {});
        var c = cache[pilar];
        if (c && c.dados && Date.now() - c.quando < 120000) return c.dados;
        if (c && c.pedindo) return null;
        cache[pilar] = { pedindo: true, quando: Date.now(), dados: c ? c.dados : null };
        win.fetch(BASE + '/principal/api/status_itens.php?pilar=' + encodeURIComponent(pilar), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) { cache[pilar] = { pedindo: false, quando: Date.now(), dados: j && j.ok ? j.itens || {} : {} }; pronto(); })
            .catch(function () { cache[pilar] = { pedindo: false, quando: Date.now(), dados: {} }; });
        return cache[pilar].dados;
    }

    /* ---------- estilo (injetado em cada documento) ---------- */
    var CSS = [
        'td.gp-st-cel{position:relative;padding-left:24px!important}',
        '.gp-st{position:absolute;left:4px;top:50%;transform:translateY(-50%);display:inline-flex;width:16px;height:16px;line-height:0;cursor:help;z-index:1}',
        '.gp-st svg{display:block;filter:drop-shadow(0 0 .5px rgba(0,0,0,.25))}',
        '.gp-st-legenda{display:inline-flex;align-items:center;gap:6px;margin-left:10px;padding-left:10px;border-left:1px solid var(--gp-border,#dce9e3);height:20px;vertical-align:middle}',
        '.gp-st-legenda span{display:inline-flex;align-items:center;gap:3px;font-size:11px;color:#5b6770;cursor:help;white-space:nowrap}',
        '.gp-st-legenda svg{width:14px;height:14px}',
        '.gp-st-legenda b{font-weight:600;min-width:6px}',
        '.gp-st-legenda span.vazio{opacity:.45}'
    ].join('\n');

    function primeiraVisivel(tr, win) {
        var tds = tr.children;
        for (var i = 0; i < tds.length; i++) {
            var td = tds[i];
            if (td.tagName !== 'TD' || td.hasAttribute('data-grid-row-actions')) continue;
            if (td.querySelector('input[type="checkbox"]') && !td.getAttribute('data-column')) continue;
            if (td.hidden || td.style.display === 'none') continue;
            var cs = win.getComputedStyle(td);
            if (cs.display === 'none' || cs.visibility === 'hidden') continue;
            return td;
        }
        return null;
    }

    function marcar(tr, res, win) {
        var antigo = tr.querySelector('.gp-st');
        var alvo = res ? primeiraVisivel(tr, win) : null;
        var chave = res ? res[0] + '|' + res[1] : '';
        if (antigo && (!res || antigo.parentNode !== alvo || antigo.getAttribute('data-gp-st') !== chave)) {
            if (antigo.parentNode) antigo.parentNode.classList.remove('gp-st-cel');
            antigo.remove();
            antigo = null;
        }
        if (!res || !alvo || antigo) return;
        var s = win.document.createElement('span');
        s.className = 'gp-st gp-st-' + res[0];
        s.setAttribute('data-gp-st', chave);
        s.setAttribute('role', 'img');
        s.title = t(res[1]);
        s.setAttribute('aria-label', s.title);
        s.innerHTML = svg(res[0]);
        alvo.classList.add('gp-st-cel');
        alvo.insertBefore(s, alvo.firstChild);
    }

    function legenda(frag, itens, contagem, win) {
        var pag = frag.querySelector('.grid-pagination');
        if (!pag) return;
        var el = pag.querySelector('.gp-st-legenda');
        if (!el) {
            el = win.document.createElement('span');
            el.className = 'gp-st-legenda';
            el.title = t('legenda');
            el.innerHTML = itens.map(function (p) {
                return '<span data-gp-st-leg="' + p[0] + '" title="' + t(p[1]).replace(/"/g, '&quot;') + '">' + svg(p[0]) + '<b></b></span>';
            }).join('');
            pag.appendChild(el);
        }
        itens.forEach(function (p) {
            var s = el.querySelector('[data-gp-st-leg="' + p[0] + '"]');
            if (!s) return;
            var n = contagem[p[0]] || 0;
            s.querySelector('b').textContent = String(n);
            s.classList.toggle('vazio', n === 0);
        });
    }

    function aplicar(win) {
        var doc = win.document;
        REGRAS.forEach(function (regra) {
            Array.prototype.forEach.call(doc.querySelectorAll(regra.sel), function (frag) {
                var linhas = frag.querySelectorAll('.menu-batch-grid tbody tr');
                if (!linhas.length && !frag.querySelector('.menu-batch-grid')) return;
                var calc = regra.estado, itensLegenda = regra.legenda;
                if (regra.pilar) {
                    var dados = pilarDados(win, regra.pilar, function () { agendarJanela(win, 10); });
                    if (!dados) return;
                    itensLegenda = LEGENDA_PILAR;
                    calc = function (tr) {
                        var id = parseInt(valor(tr, regra.id) || tr.getAttribute('data-key') || '0', 10);
                        if (!id) return null;
                        var st = dados[id];
                        if (!st) return ['nao_avaliado', 'pi_na'];
                        var r = PILAR_ESTADO[st[0]] || ['nao_avaliado', 'pi_na'];
                        return st[1] ? [r[0], r[1], st[1]] : r;
                    };
                }
                var contagem = {}, usadas = 0;
                Array.prototype.forEach.call(linhas, function (tr) {
                    if (!tr.hasAttribute('data-row') && !tr.hasAttribute('data-key')) { marcar(tr, null, win); return; }
                    var res = null;
                    try { res = calc(tr); } catch (e) { res = null; }
                    if (res) { contagem[res[0]] = (contagem[res[0]] || 0) + 1; usadas++; }
                    marcar(tr, res, win);
                    // Motivo da situação (pilares): vai na dica do ícone.
                    if (res && res[2]) { var ic = tr.querySelector('.gp-st'); if (ic) ic.title = t(res[1]) + ' \u2014 ' + res[2]; }
                });
                if (usadas || linhas.length) legenda(frag, itensLegenda, contagem, win);
            });
        });
    }

    function agendarJanela(win, espera) {
        win.clearTimeout(win.__gpStatusTimer);
        win.__gpStatusTimer = win.setTimeout(function () { try { aplicar(win); } catch (e) { /* n\u00e3o interrompe a tela */ } }, espera || 120);
    }

    function instalar(win) {
        var doc;
        try { doc = win.document; } catch (e) { return; }
        if (!doc || doc.__gpStatusIcones) return;
        doc.__gpStatusIcones = true;
        var iniciar = function () {
            if (!doc.head) return;
            if (!doc.getElementById('gp-status-icones-css')) {
                var st = doc.createElement('style');
                st.id = 'gp-status-icones-css';
                st.textContent = CSS;
                doc.head.appendChild(st);
            }
            agendarJanela(win, 50);
            win.setTimeout(function () { agendarJanela(win, 10); }, 800);
            if (win.MutationObserver && doc.body) {
                new win.MutationObserver(function (lista) {
                    for (var i = 0; i < lista.length; i++) {
                        var m = lista[i], alvo = m.target;
                        if (alvo && alvo.closest && alvo.closest('.gp-st, .gp-st-legenda')) continue;
                        var nos = m.addedNodes;
                        var nosso = nos.length > 0 && Array.prototype.every.call(nos, function (n) { return n.nodeType === 1 && n.classList && (n.classList.contains('gp-st') || n.classList.contains('gp-st-legenda')); });
                        if (nosso) continue;
                        if (m.type === 'childList' || m.type === 'attributes') { agendarJanela(win); return; }
                    }
                }).observe(doc.body, { childList: true, subtree: true });
            }
            // Datas e listas alteradas na própria linha.
            doc.addEventListener('change', function (ev) { if (ev.target && ev.target.closest && ev.target.closest('.menu-batch-grid')) agendarJanela(win); }, true);
            // Colunas ocultadas/reexibidas mudam a primeira célula visível.
            doc.addEventListener('click', function () { agendarJanela(win, 400); }, true);
        };
        if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', iniciar); else iniciar();
        doc.addEventListener('load', function (ev) {
            var f = ev.target;
            if (f && f.tagName === 'IFRAME') { try { instalar(f.contentWindow); } catch (e) { /* outro endere\u00e7o */ } }
        }, true);
        var jaAbertos = function () { Array.prototype.forEach.call(doc.querySelectorAll('iframe'), function (f) { try { instalar(f.contentWindow); } catch (e) { /* idem */ } }); };
        if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', jaAbertos); else jaAbertos();
    }
    instalar(window);
}());
