/*
 * Licenças — cor da linha conforme o vencimento (manutencaolegal_licencas.php,
 * Minhas Licenças e manutencaolegalrequisitoslegais_licencas.php).
 *
 *  - Vencida (vencimento < hoje) ............................ vermelho suave
 *  - Vencida com renovação protocolada no prazo ............ azul suave ("Válida (em protocolo)", migração 288)
 *  - Vencida, mas "Em protocolo? = Sim" (sem a migração 288) amarelo suave
 *  - Em protocolo (prazo não informado): sem as datas ...... amarelo suave (decisão de 27/10/2026)
 *  - Em atenção (reação <= hoje <= vencimento) .............. amarelo suave
 *  - Em dia (hoje < reação, ou sem reação e não vencida) .... verde suave
 *  - Sem data de vencimento ................................. sem cor
 *
 * Com a coluna "Situação da licença" (calculada no servidor: VALIDA, PROTOCOLO, VENCIDA...) a cor segue essa situação;
 * sem ela, as datas são lidas da própria linha (campo de data ou texto dd/mm/aaaa).
 * Textos com acento escritos como \u.... para não sofrerem a correção automática.
 */
(function (window, document) {
    'use strict';
    var FRAGMENTO = '.licencas-grid-fragment';
    var CLASSES = ['lic-vencida', 'lic-atencao', 'lic-emdia', 'lic-protocolo'];
    var idioma = String(window.greenPortalIdioma || document.documentElement.getAttribute('lang') || 'pt').slice(0, 2).toLowerCase();
    var TEXTOS = {
        pt: { vencida: 'Vencida', atencao: 'Em atenção (prazo de reação ou em protocolo)', emdia: 'Em dia', semdata: 'Sem data de vencimento', protocolo: 'V\u00e1lida (em protocolo): vencida com a renova\u00e7\u00e3o protocolada no prazo' },
        en: { vencida: 'Expired', atencao: 'Attention (reaction period or filed for renewal)', emdia: 'Up to date', semdata: 'No due date', protocolo: 'Valid (renewal filed on time)' },
        es: { vencida: 'Vencida', atencao: 'En atención (plazo de reacción o en protocolo)', emdia: 'Al día', semdata: 'Sin fecha de vencimiento', protocolo: 'V\u00e1lida (en protocolo)' }
    };
    var T = TEXTOS[idioma] || TEXTOS.pt;
    // Textos de Administração › Textos e idiomas (window.greenT), quando cadastrados.
    if (typeof window.greenT === 'function') ['vencida', 'atencao', 'emdia', 'semdata', 'protocolo'].forEach(function (k) { T[k] = window.greenT('manutencaolegal_licencas.legenda.' + k, T[k]); });

    function hojeIso() {
        var d = new Date();
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }
    // Converte o conteúdo da célula para aaaa-mm-dd (ou '' quando não há data válida).
    function dataDaCelula(td) {
        if (!td) return '';
        var campo = td.querySelector('input[type="date"]');
        var bruto = campo ? String(campo.value || '') : String(td.textContent || '').trim();
        var m = bruto.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (m) return m[1] + '-' + m[2] + '-' + m[3];
        m = bruto.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
        if (m) return m[3] + '-' + m[2] + '-' + m[1];
        return '';
    }
    function emProtocolo(td) {
        if (!td) return false;
        var sel = td.querySelector('select');
        var v = sel ? String(sel.value || '') : String(td.textContent || '').trim();
        v = v.toUpperCase();
        return v === 'S' || v === 'SIM' || v === 'YES' || v === 'SI' || v === 'SÍ';
    }
    function situacaoServidor(tr) {
        var td = tr.querySelector('[data-column="SITUACAO_LICENCA"]');
        if (!td) return null;
        var campo = td.querySelector('[data-raw-value]');
        return String(campo ? campo.getAttribute('data-raw-value') : td.textContent || '').trim().toUpperCase();
    }
    function situacao(tr, hoje) {
        var sit = situacaoServidor(tr);
        if (sit === 'VENCIDA') return 'lic-vencida';
        if (sit === 'PROTOCOLO') return 'lic-protocolo';
        if (sit === 'PROT_SEM_PRAZO') return 'lic-atencao'; // em protocolo sem as datas para saber o prazo (27/10/2026)
        if (sit === 'SEM_DATA') return '';
        var venc = dataDaCelula(tr.querySelector('[data-column="DATA_VALIDADE"]'));
        if (!venc) return '';
        if (venc < hoje) return emProtocolo(tr.querySelector('[data-column="EMPROTOCOLO"]')) ? 'lic-atencao' : 'lic-vencida';
        var reacao = dataDaCelula(tr.querySelector('[data-column="DATA_REACAO"]'));
        if (reacao && reacao <= hoje) return 'lic-atencao';
        return 'lic-emdia';
    }
    function colorir(fragmento) {
        var hoje = hojeIso();
        Array.prototype.forEach.call(fragmento.querySelectorAll('.menu-batch-grid tbody tr'), function (tr) {
            var nova = situacao(tr, hoje);
            CLASSES.forEach(function (c) { if (c !== nova && tr.classList.contains(c)) tr.classList.remove(c); });
            if (nova && !tr.classList.contains(nova)) tr.classList.add(nova);
        });
        legenda(fragmento);
    }
    // Indicadores (sem rótulo, só dica nativa) ao lado da paginação.
    function legenda(fragmento) {
        var pag = fragmento.querySelector('.grid-pagination');
        if (!pag || pag.querySelector('[data-lic-legenda]')) return;
        var el = document.createElement('span');
        el.className = 'lic-legenda';
        el.setAttribute('data-lic-legenda', '');
        var itens = [['lic-vencida', T.vencida], ['lic-protocolo', T.protocolo], ['lic-atencao', T.atencao], ['lic-emdia', T.emdia], ['lic-semdata', T.semdata]];
        if (!fragmento.querySelector('[data-column="SITUACAO_LICENCA"]')) itens.splice(1, 1);
        itens.forEach(function (par) {
            var i = document.createElement('i');
            i.className = 'lic-legenda-item ' + par[0];
            i.title = par[1];
            i.setAttribute('aria-label', par[1]);
            i.setAttribute('role', 'img');
            el.appendChild(i);
        });
        pag.appendChild(el);
    }
    function iniciar() {
        Array.prototype.forEach.call(document.querySelectorAll(FRAGMENTO), function (fragmento) {
            var agendado = null;
            var rodar = function () { agendado = null; colorir(fragmento); };
            var agendar = function () { if (!agendado) agendado = window.setTimeout(rodar, 80); };
            rodar();
            window.setTimeout(rodar, 600);
            // Linhas novas, troca de página e alteração das datas ou do protocolo.
            var corpo = fragmento.querySelector('.menu-batch-grid');
            if (corpo && window.MutationObserver) new MutationObserver(function (ms) {
                for (var i = 0; i < ms.length; i++) if (ms[i].type === 'childList' && ms[i].target.nodeName !== 'TR') { agendar(); return; }
            }).observe(corpo, { childList: true, subtree: true });
            fragmento.addEventListener('change', function (ev) {
                var td = ev.target && ev.target.closest ? ev.target.closest('[data-column="DATA_VALIDADE"],[data-column="DATA_REACAO"],[data-column="EMPROTOCOLO"]') : null;
                if (td) agendar();
            });
            if (window.jQuery) window.jQuery(fragmento).on('gridPageLoaded', agendar);
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();
}(window, document));
