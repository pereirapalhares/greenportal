/*
 * GreenSolutions — barra de botões em grupos rotulados (Operações | Associações |
 * Avaliação | Outros). Componente reutilizável; configuração em components/toolbar_grupos.php.
 * Configurações explícitas (por tela) têm prioridade; a automática (botoes_crud.php)
 * vale para as demais barras padrão (.crud-toolbar).
 *
 * Uso: na página da transação, depois do grid, declarar
 *   <script type="application/json" data-toolbar-grupos-config>{
 *     "fragmento": ".licencas-grid-fragment",
 *     "grupos": [ { "id": "operacoes", "rotulo": "Operações", "botoes": ["[data-crud-action=\"save\"]", ...] }, ... ]
 *   }</script>
 * e incluir este arquivo e assets/css/toolbar-grupos.css.
 *
 * Os botões são apenas movidos (mesmos atributos, eventos e permissões). Roda
 * depois de grid-pagination.js, que reorganiza a barra padrão, e novamente a
 * cada recarga do grid. Grupos sem nenhum botão ficam ocultos.
 */
(function (window, document) {
    'use strict';
    if (window.__greenToolbarGrupos) return;
    window.__greenToolbarGrupos = true;
    // Uma única dica por botão, no mesmo padrão de Requisitos legais: a dica
    // nativa do navegador (atributo "title"). As dicas do Bootstrap criadas por
    // outros scripts são removidas. Botão desabilitado dentro do envoltório de
    // dica (que recebe o mouse no lugar do botão) tem o "title" só no envoltório.
    function unificarDicas(acoes) {
        var bs = window.bootstrap && window.bootstrap.Tooltip ? window.bootstrap.Tooltip : null;
        Array.prototype.forEach.call(acoes.querySelectorAll('.tb-grupo button, .grid-pagination button'), function (botao) {
            var envoltorio = botao.parentElement && botao.parentElement.hasAttribute('data-disabled-tooltip-wrapper') ? botao.parentElement : null;
            var host = envoltorio || botao, outro = envoltorio ? botao : null;
            var texto = botao.getAttribute('title') || (envoltorio && envoltorio.getAttribute('title')) || botao.getAttribute('data-bs-original-title') || (envoltorio && envoltorio.getAttribute('data-bs-original-title')) || botao.getAttribute('aria-label') || (botao.textContent || '').replace(/\s+/g, ' ').trim();
            [botao, envoltorio].forEach(function (el) {
                if (!el) return;
                if (bs) { var dica = bs.getInstance(el); if (dica) dica.dispose(); }
                if (el.hasAttribute('data-bs-toggle')) el.removeAttribute('data-bs-toggle');
                if (el.hasAttribute('data-bs-original-title')) el.removeAttribute('data-bs-original-title');
            });
            if (!texto) return;
            if (!botao.getAttribute('aria-label')) botao.setAttribute('aria-label', texto);
            if (host.getAttribute('title') !== texto) host.setAttribute('title', texto);
            if (outro && outro.hasAttribute('title')) outro.removeAttribute('title');
        });
    }
    // Fragmentos de uma configuração: a explícita usa o seletor da tela; a automática pega toda barra
    // padrão (.crud-toolbar) que não pertence a uma tela com configuração explícita.
    function fragmentosDe(cfg) {
        if (!cfg.auto) return Array.prototype.slice.call(document.querySelectorAll(cfg.fragmento || '.menu-grid-fragment'));
        return Array.prototype.map.call(document.querySelectorAll('.crud-toolbar'), function (barra) { return barra.parentElement; })
            .filter(function (f, i, todos) { return f && todos.indexOf(f) === i && !f.closest('[data-tb-explicito]'); });
    }
    function aplicar(cfg) {
        var fragmentos = fragmentosDe(cfg);
        Array.prototype.forEach.call(fragmentos, function (fragmento) {
            if (!cfg.auto) fragmento.setAttribute('data-tb-explicito', '1');
            var acoes = fragmento.querySelector('.crud-toolbar .crud-actions');
            if (!acoes) return;
            acoes.classList.add('tb-grupos');
            (cfg.grupos || []).forEach(function (grupo) {
                var bloco = acoes.querySelector('[data-tb-grupo="' + grupo.id + '"]');
                if (!bloco) {
                    bloco = document.createElement('div');
                    bloco.className = 'tb-grupo tb-grupo-' + grupo.id;
                    bloco.setAttribute('role', 'group');
                    bloco.setAttribute('data-tb-grupo', grupo.id);
                    var rotulo = document.createElement('span');
                    rotulo.className = 'tb-grupo-rotulo';
                    rotulo.id = 'tbGrupo_' + grupo.id + '_' + Math.random().toString(36).slice(2, 7);
                    rotulo.textContent = grupo.rotulo || '';
                    var botoes = document.createElement('div');
                    botoes.className = 'tb-grupo-botoes';
                    bloco.setAttribute('aria-labelledby', rotulo.id);
                    bloco.appendChild(rotulo);
                    bloco.appendChild(botoes);
                }
                var destino = bloco.querySelector('.tb-grupo-botoes');
                (grupo.botoes || []).forEach(function (seletor) {
                    Array.prototype.forEach.call(fragmento.querySelectorAll('.crud-toolbar ' + seletor), function (botao) {
                        // Um botão já agrupado não troca de grupo; a exceção é o grupo "Outros" da configuração automática
                        // (barras que entram no fragmento da tela depois do carregamento, como a de Legislação x Empresas).
                        var grupoAtual = botao.closest('.tb-grupo');
                        if (grupoAtual && grupoAtual !== bloco && grupoAtual.getAttribute('data-tb-grupo') !== 'outros') return;
                        botao.classList.add('grid-crud-button');
                        // Botão desabilitado fica dentro de um <span> de dica (manutencao-legal-cadastro.js):
                        // move-se o <span> junto, para a dica continuar funcionando e não sobrar espaço vazio.
                        var item = botao.parentElement && botao.parentElement.hasAttribute('data-disabled-tooltip-wrapper') ? botao.parentElement : botao;
                        // Só move quando necessário, para não disparar o observador à toa.
                        if (item.parentNode !== destino) destino.appendChild(item);
                    });
                });
                var vazio = !destino.children.length;
                if (bloco.hidden !== vazio) bloco.hidden = vazio;
                if (bloco.parentNode !== acoes) acoes.appendChild(bloco);
            });
            // Separadores antigos e contêineres que ficaram vazios.
            Array.prototype.forEach.call(acoes.querySelectorAll('.crud-context-separator'), function (el) { el.remove(); });
            // Envoltórios de dica que ficaram sem botão (o botão foi habilitado ou movido).
            Array.prototype.forEach.call(acoes.querySelectorAll('[data-disabled-tooltip-wrapper]'), function (el) {
                if (el.querySelector('button')) return;
                try { if (window.bootstrap && window.bootstrap.Tooltip) { var dica = window.bootstrap.Tooltip.getInstance(el); if (dica) dica.dispose(); } } catch (e) { /* sem dica */ }
                el.remove();
            });
            Array.prototype.forEach.call(acoes.querySelectorAll('.crud-context-actions,[data-crud-context-actions]'), function (el) { if (!el.querySelector('button')) el.remove(); });
            // Envoltórios de dica soltos (fora dos grupos) que ainda têm botão também entram na verificação abaixo.
            // Botões não previstos na configuração continuam visíveis, num grupo sem rótulo.
            var soltos = Array.prototype.filter.call(acoes.children, function (el) { return el.tagName === 'BUTTON' || el.hasAttribute('data-disabled-tooltip-wrapper') || el.classList.contains('crud-context-actions') || el.hasAttribute('data-crud-context-actions'); });
            if (soltos.length) {
                var outros = acoes.querySelector('[data-tb-grupo="outros"]');
                if (!outros) {
                    outros = document.createElement('div');
                    outros.className = 'tb-grupo tb-grupo-outros';
                    outros.setAttribute('data-tb-grupo', 'outros');
                    var rotuloOutros = document.createElement('span');
                    rotuloOutros.className = 'tb-grupo-rotulo';
                    rotuloOutros.textContent = cfg.rotuloOutros || 'Outros';
                    var botoesOutros = document.createElement('div');
                    botoesOutros.className = 'tb-grupo-botoes';
                    outros.setAttribute('role', 'group');
                    outros.appendChild(rotuloOutros);
                    outros.appendChild(botoesOutros);
                }
                soltos.forEach(function (el) { outros.querySelector('.tb-grupo-botoes').appendChild(el); });
                if (outros.parentNode !== acoes) acoes.appendChild(outros);
            }
            // A paginação fica sempre no fim.
            Array.prototype.forEach.call(acoes.querySelectorAll(':scope > [data-pagination-separator], :scope > .crud-pagination-separator'), function (el) { el.remove(); });
            // Ordem final: grupos na ordem configurada, grupo "outros" e paginação por último.
            var ordem = (cfg.grupos || []).map(function (g) { return acoes.querySelector(':scope > [data-tb-grupo="' + g.id + '"]'); })
                .concat([acoes.querySelector(':scope > [data-tb-grupo="outros"]'), acoes.querySelector(':scope > .grid-pagination')])
                .filter(Boolean);
            var atual = Array.prototype.filter.call(acoes.children, function (el) { return ordem.indexOf(el) >= 0; });
            if (atual.length !== ordem.length || atual.some(function (el, i) { return el !== ordem[i]; })) ordem.forEach(function (el) { acoes.appendChild(el); });
            unificarDicas(acoes);
        });
    }
    function iniciar() {
        // Todas as configurações da página: explícitas primeiro (marcam as telas delas), depois a automática.
        var cfgs = [];
        Array.prototype.forEach.call(document.querySelectorAll('script[data-toolbar-grupos-config]'), function (nodo) {
            try { cfgs.push(JSON.parse(nodo.textContent || '{}')); } catch (e) { /* configuração inválida */ }
        });
        if (!cfgs.length) return;
        cfgs.sort(function (a, b) { return (a.auto ? 1 : 0) - (b.auto ? 1 : 0); });
        var rodar = function () { cfgs.forEach(aplicar); };
        rodar();
        window.setTimeout(rodar, 0);
        window.setTimeout(rodar, 400);
        // Outros scripts envolvem/desenvolvem botões desabilitados depois do carregamento.
        var agendado = null;
        Array.prototype.forEach.call(document.querySelectorAll('.crud-toolbar'), function (barra) {
            if (!window.MutationObserver) return;
            new MutationObserver(function () { if (agendado) return; agendado = window.setTimeout(function () { agendado = null; rodar(); }, 60); }).observe(barra, { childList: true, subtree: true, attributes: true, attributeFilter: ['title', 'disabled'] });
        });
        window.addEventListener('load', rodar);
        if (window.jQuery) window.jQuery(document).on('gridPageLoaded', function () { window.setTimeout(rodar, 0); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();
}(window, document));
