/*
 * Ajuda interativa do Green Portal (migração 309) — carregada só na janela principal (principal/main.php).
 *
 *  - Painel de Ajuda (botão ? do cabeçalho, ? da barra de cada tela, F1 ou ?): guias passo a passo DA TELA ABERTA,
 *    "Sobre esta tela", "Nesta tela agora" (informação viva do grid), palavras do assunto, perfil de leitura,
 *    "Esta ajuda foi útil?", manual e assistente.
 *  - Modo guiado (cartão no canto): um passo por vez, "Você deve ver", "Mostre-me" (anel pulsando sobre o botão,
 *    inclusive dentro das janelas), "Ir para a tela" e avanço automático quando a pessoa clica no item certo.
 *    O guia em andamento fica guardado no navegador (continua depois de trocar de tela ou recarregar).
 *  - Conteúdo: principal/api/ajuda.php (Guia do usuário publicado em Administração › Guia do usuário).
 * API pública: window.gpAjuda.abrir(), .iniciar(idGuia), .guias().
 */
(function (W) {
    'use strict';
    if (!W.document || W.top !== W || W.gpAjuda) return;
    var D = W.document, BASE = '', LANG = 'pt'; // lidos em iniciarTudo (o main.php define greenPortalBaseUrl depois deste script)
    var TX = {
        ajuda: ['Ajuda', 'Help', 'Ayuda'], fechar: ['Fechar', 'Close', 'Cerrar'], mostrar_como: ['Mostrar ajuda como', 'Show help as', 'Mostrar ayuda como'],
        geral: ['Geral', 'General', 'General'], guias_tela: ['Guias passo a passo', 'Step-by-step guides', 'Guías paso a paso'],
        nenhum_guia: ['Ainda não há guia passo a passo para esta tela.', 'There is no step-by-step guide for this screen yet.', 'Todavía no hay guía paso a paso para esta pantalla.'],
        relacionados: ['Guias do mesmo assunto', 'Guides on the same subject', 'Guías del mismo asunto'],
        passos: ['passos', 'steps', 'pasos'], comecar: ['Começar', 'Start', 'Comenzar'], continuar: ['Continuar', 'Continue', 'Continuar'],
        ver_passos: ['Ver os passos', 'See the steps', 'Ver los pasos'], ver_todos: ['Ver todos os guias', 'See all guides', 'Ver todas las guías'],
        central: ['Central de guias', 'Guide center', 'Central de guías'], voltar: ['Voltar', 'Back', 'Volver'], buscar: ['Buscar um guia...', 'Search a guide...', 'Buscar una guía...'],
        sobre: ['Sobre esta tela', 'About this screen', 'Sobre esta pantalla'], oque: ['O que é esta tela', 'What this screen is', 'Qué es esta pantalla'],
        pode: ['O que você pode fazer aqui', 'What you can do here', 'Qué puede hacer aquí'], mostrar: ['mostrar onde', 'show where', 'mostrar dónde'],
        antes: ['Antes de começar', 'Before you start', 'Antes de empezar'], depois: ['O que acontece depois', 'What happens next', 'Qué pasa después'],
        agora: ['Nesta tela agora', 'On this screen now', 'En esta pantalla ahora'], duvidas: ['Dúvidas comuns', 'Common questions', 'Preguntas comunes'],
        palavras: ['Palavras deste assunto', 'Words of this subject', 'Palabras de este asunto'], ajuda_tela: ['Ajuda da tela', 'Screen help', 'Ayuda de la pantalla'],
        util: ['Esta ajuda foi útil?', 'Was this help useful?', '¿Le fue útil esta ayuda?'], manual: ['Passo a passo no manual', 'Step by step in the manual', 'Paso a paso en el manual'],
        assistente: ['Perguntar ao assistente', 'Ask the assistant', 'Preguntar al asistente'], assistente_off: ['O assistente não está liberado para o seu perfil.', 'The assistant is not enabled for your profile.', 'El asistente no está habilitado para su perfil.'],
        atalho: ['Atalho: F1 ou ?', 'Shortcut: F1 or ?', 'Atajo: F1 o ?'], carregando: ['Carregando a ajuda...', 'Loading help...', 'Cargando la ayuda...'],
        erro: ['Não foi possível carregar a ajuda.', 'Could not load the help.', 'No fue posible cargar la ayuda.'],
        passo_de: ['Passo {n} de {t}', 'Step {n} of {t}', 'Paso {n} de {t}'], parte: ['Parte {n} de {t}: {p}', 'Part {n} of {t}: {p}', 'Parte {n} de {t}: {p}'],
        voce_deve_ver: ['Você deve ver', 'You should see', 'Usted debe ver'], mostre_me: ['Mostre-me', 'Show me', 'Muéstreme'], ir_tela: ['Ir para a tela', 'Go to the screen', 'Ir a la pantalla'],
        proximo: ['Próximo', 'Next', 'Siguiente'], concluir: ['Concluir', 'Finish', 'Concluir'], minimizar: ['Minimizar', 'Minimize', 'Minimizar'], restaurar: ['Abrir o guia', 'Open the guide', 'Abrir la guía'],
        sair_guia: ['Fechar o guia', 'Close the guide', 'Cerrar la guía'], nao_achei: ['Não encontrei esse item na tela aberta. Use "Ir para a tela" ou confira o passo anterior (às vezes ele só aparece com uma linha selecionada ou com a janela do passo anterior aberta).', 'I could not find this item on the open screen. Use "Go to the screen" or check the previous step (sometimes it only appears with a selected row or with the previous step window open).', 'No encontré este ítem en la pantalla abierta. Use "Ir a la pantalla" o revise el paso anterior (a veces solo aparece con una fila seleccionada o con la ventana del paso anterior abierta).'],
        muito_bem: ['Muito bem! Indo para o próximo passo...', 'Well done! Going to the next step...', '¡Muy bien! Pasando al siguiente paso...'], clique_aqui: ['Clique aqui', 'Click here', 'Haga clic aquí'],
        concluido: ['Guia concluído!', 'Guide completed!', '¡Guía concluida!'], concluido_txt: ['Você terminou todos os passos.', 'You finished all the steps.', 'Usted terminó todos los pasos.'],
        outra_tela: ['Este passo é na tela {t}.', 'This step is on the {t} screen.', 'Este paso es en la pantalla {t}.'], abrindo: ['Abrindo a tela...', 'Opening the screen...', 'Abriendo la pantalla...'],
        linhas: ['{n} linha(s) nesta página do grid', '{n} row(s) on this grid page', '{n} fila(s) en esta página del grid'], selecionada: ['Linha selecionada: {t}', 'Selected row: {t}', 'Fila seleccionada: {t}'],
        sem_selecao: ['Nenhuma linha selecionada: {n} botão(ões) só funcionam com uma linha selecionada.', 'No row selected: {n} button(s) only work with a selected row.', 'Ninguna fila seleccionada: {n} botón(es) solo funcionan con una fila seleccionada.'],
        filtros: ['Filtros em uso: {t}', 'Filters in use: {t}', 'Filtros en uso: {t}'], busca: ['Busca: "{t}"', 'Search: "{t}"', 'Búsqueda: "{t}"'], nao_salvo: ['Há alterações ainda não salvas no grid.', 'There are unsaved changes in the grid.', 'Hay cambios aún no guardados en el grid.'],
        janela: ['Janela aberta: {t}', 'Open window: {t}', 'Ventana abierta: {t}'], inicio: ['Início', 'Home', 'Inicio'], obrigado: ['Obrigado!', 'Thank you!', '¡Gracias!'],
        quem_faz: ['Esta parte é feita por: {p}', 'This part is done by: {p}', 'Esta parte la hace: {p}'], sem_texto: ['Sem texto próprio para esta tela.', 'No specific text for this screen.', 'Sin texto propio para esta pantalla.'],
        // migração 364: modo demonstração
        demo: ['Demonstração', 'Demo', 'Demostración'], pausar: ['Pausar', 'Pause', 'Pausar'], continuar_demo: ['Continuar', 'Resume', 'Continuar'],
        voltar_passo: ['Passo anterior', 'Previous step', 'Paso anterior'], avancar_passo: ['Próximo passo', 'Next step', 'Paso siguiente'], repetir: ['Repetir', 'Replay', 'Repetir'],
        fazer_agora: ['Fazer agora com o guia', 'Do it now with the guide', 'Hacerlo ahora con la guía'], fim_demo: ['Fim da demonstração', 'End of the demo', 'Fin de la demostración'],
        exemplo: ['exemplo', 'example', 'ejemplo'], so_mostra: ['A demonstração só mostra: não preenche, não clica e não grava nada.', 'The demo only shows: it does not fill in, click or save anything.', 'La demostración solo muestra: no completa, no hace clic y no graba nada.'],
        mais_adiante: ['Este item não aparece agora na sua tela: ele surge depois do passo anterior ou depende da configuração da empresa.', 'This item is not on your screen now: it appears after the previous step or depends on the company settings.', 'Este ítem no aparece ahora en su pantalla: surge después del paso anterior o depende de la configuración de la empresa.'],
        sem_demo: ['Demonstração não encontrada no Guia do usuário.', 'Demo not found in the User guide.', 'Demostración no encontrada en la Guía del usuario.']
    };
    var IDX = 0;
    function t(k, v) { var s = (TX[k] || [k])[IDX] || (TX[k] || [k])[0]; if (v) Object.keys(v).forEach(function (x) { s = s.split('{' + x + '}').join(String(v[x])); }); return s; }
    function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function rico(v) { return esc(v).replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>'); }
    function norm(v) { var s = String(v == null ? '' : v); try { s = s.normalize('NFD').replace(/[̀-ͯ]/g, ''); } catch (e) {} return s.replace(/\s+/g, ' ').trim().toLowerCase(); }
    function lsGet(k) { try { var v = W.localStorage.getItem(k); return v ? JSON.parse(v) : null; } catch (e) { return null; } }
    function lsSet(k, v) { try { if (v == null) W.localStorage.removeItem(k); else W.localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
    var CHAVE_GUIA = 'gp.ajuda.guia', CHAVE_PERFIL = 'gp.ajuda.perfil';

    // ------------------------------------------------------------------ estilo
    function css() {
        if (D.getElementById('gpAjudaCss')) return;
        var s = D.createElement('style'); s.id = 'gpAjudaCss';
        s.textContent = [
            '.gpa-panel{position:fixed;top:0;right:0;bottom:0;width:min(400px,100vw);z-index:2147483000;display:flex;flex-direction:column;background:var(--gp-surface,#fff);color:var(--gp-text,#203f35);border-left:1px solid var(--gp-border,#dce9e2);box-shadow:-8px 0 28px rgba(20,60,45,.18);font:13px/1.45 var(--gp-font-body,system-ui,sans-serif);transform:translateX(105%);transition:transform .18s ease}',
            '.gpa-panel.is-open{transform:none}.gpa-panel *{box-sizing:border-box}',
            '.gpa-head{display:flex;align-items:center;gap:9px;padding:12px 14px;border-bottom:1px solid var(--gp-border,#dce9e2)}.gpa-head i.bi-question-circle{color:var(--gp-primary,#247a5b);font-size:18px}.gpa-head h2{flex:1;margin:0;font-size:15px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
            '.gpa-x{border:0;background:transparent;color:var(--gp-muted,#71808c);font-size:18px;line-height:1;cursor:pointer;border-radius:5px;padding:3px 7px}.gpa-x:hover{background:var(--gp-primary-soft,#e8f5ee);color:var(--gp-primary-dark,#185640)}',
            '.gpa-role{display:flex;align-items:center;gap:8px;padding:9px 14px;border-bottom:1px solid var(--gp-border,#dce9e2)}.gpa-role label{color:var(--gp-muted,#71808c);white-space:nowrap}.gpa-role select{flex:1;border:1px solid var(--gp-border,#cfdcd5);border-radius:7px;padding:5px 8px;background:var(--gp-surface,#fff);color:inherit}',
            '.gpa-body{flex:1;overflow:auto;padding:12px 14px}.gpa-kicker{margin:4px 0 8px;color:var(--gp-muted,#71808c);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}',
            '.gpa-card{border:1px solid var(--gp-border,#dce9e2);border-radius:10px;padding:11px 12px;margin-bottom:9px;background:var(--gp-surface,#fff)}.gpa-card h3{margin:0 0 3px;font-size:14px;font-weight:700;display:flex;gap:7px;align-items:flex-start}.gpa-card h3 i{color:var(--gp-primary,#247a5b)}',
            '.gpa-meta{color:var(--gp-muted,#71808c);font-size:12px;margin-bottom:8px}.gpa-resumo{margin:0 0 8px}.gpa-acts{display:flex;gap:8px;align-items:center;flex-wrap:wrap}',
            '.gpa-btn{border:1px solid var(--gp-primary,#247a5b);border-radius:7px;padding:6px 11px;background:var(--gp-surface,#fff);color:var(--gp-primary-dark,#185640);font-weight:600;cursor:pointer;display:inline-flex;gap:6px;align-items:center}.gpa-btn:hover{background:var(--gp-primary-soft,#e8f5ee)}.gpa-btn.primary{background:var(--gp-primary,#247a5b);color:#fff}.gpa-btn.primary:hover{filter:brightness(.95)}.gpa-btn[disabled]{opacity:.5;cursor:not-allowed}',
            '.gpa-link{border:0;background:transparent;color:var(--gp-primary-dark,#185640);cursor:pointer;padding:2px 4px;font-weight:600}.gpa-link:hover{text-decoration:underline}',
            '.gpa-steps{margin:8px 0 0;padding-left:20px}.gpa-steps li{margin-bottom:4px}.gpa-full{display:flex;width:100%;justify-content:flex-start;gap:8px;border:1px solid var(--gp-border,#dce9e2);border-radius:9px;padding:9px 12px;background:var(--gp-surface,#fff);color:inherit;cursor:pointer;margin-bottom:9px;font-weight:600}.gpa-full:hover{background:var(--gp-primary-soft,#e8f5ee)}',
            '.gpa-sec{margin:14px 0 4px}.gpa-sec h4{margin:12px 0 4px;font-size:13px;font-weight:700}.gpa-sec p{margin:0 0 6px}.gpa-sec ul{margin:0 0 6px;padding-left:18px}.gpa-now{border:1px solid var(--gp-border,#dce9e2);border-radius:9px;padding:8px 10px;background:var(--gp-primary-soft,#f2faf6)}.gpa-now div{margin:2px 0}',
            '.gpa-sec details{border-top:1px solid var(--gp-border,#eef2f0);padding:6px 0}.gpa-sec summary{cursor:pointer;font-weight:600}.gpa-html{font-size:13px}.gpa-html p{margin:0 0 6px}',
            '.gpa-search{width:100%;border:1px solid var(--gp-border,#cfdcd5);border-radius:7px;padding:6px 9px;margin-bottom:10px}',
            '.gpa-foot{border-top:1px solid var(--gp-border,#dce9e2);padding:10px 14px;display:flex;flex-direction:column;gap:7px}.gpa-foot .gpa-full{margin:0}.gpa-useful{display:flex;align-items:center;justify-content:space-between}.gpa-useful button{border:0;background:transparent;font-size:17px;cursor:pointer;padding:2px 6px;border-radius:6px;color:var(--gp-muted,#71808c)}.gpa-useful button:hover{background:var(--gp-primary-soft,#e8f5ee)}.gpa-short{color:var(--gp-muted,#71808c);font-size:11px}',
            '.gpa-guide{position:fixed;right:22px;bottom:22px;z-index:2147483600;width:min(390px,calc(100vw - 32px));border:1px solid var(--gp-border,#dce9e2);border-radius:12px;background:var(--gp-surface,#fff);color:var(--gp-text,#203f35);box-shadow:var(--gp-shadow-md,0 12px 32px rgba(20,60,45,.24));font:13px/1.45 var(--gp-font-body,system-ui,sans-serif)}.gpa-guide *{box-sizing:border-box}',
            '.gpa-guide .gpg-head{display:flex;align-items:center;gap:8px;padding:11px 12px 9px}.gpa-guide .gpg-head i.bi-compass{color:var(--gp-primary,#247a5b);font-size:16px}.gpa-guide .gpg-head strong{flex:1;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
            '.gpa-guide .gpg-bar{height:4px;background:var(--gp-border,#e4ece8);margin:0 12px;border-radius:3px;overflow:hidden}.gpa-guide .gpg-bar span{display:block;height:100%;background:var(--gp-primary,#247a5b);transition:width .2s}',
            '.gpa-guide .gpg-body{padding:10px 14px}.gpa-guide .gpg-part{display:flex;gap:8px;align-items:center;padding:7px 9px;border-radius:8px;background:var(--gp-primary-soft,#f2faf6);margin-bottom:9px;font-size:12px}.gpa-guide .gpg-label{color:var(--gp-muted,#71808c);font-size:11px;font-weight:700;text-transform:uppercase;margin-bottom:3px}.gpa-guide .gpg-text{font-size:14px;margin:0 0 10px}',
            '.gpa-guide .gpg-see{border:1px solid var(--gp-primary,#72b99c);border-radius:8px;background:var(--gp-primary-soft,#edfff8);color:var(--gp-primary-dark,#1a765c);padding:8px 10px;margin-bottom:10px}.gpa-guide .gpg-see strong{display:block;margin-bottom:2px}',
            '.gpa-guide .gpg-msg{font-size:12px;margin-bottom:8px;color:var(--gp-warning-dark,#8a5a00)}.gpa-guide .gpg-msg.ok{color:var(--gp-primary-dark,#185640);font-weight:600}',
            '.gpa-guide .gpg-nav{display:flex;align-items:center;justify-content:space-between;padding:9px 14px;border-top:1px solid var(--gp-border,#dce9e2)}.gpa-guide .gpg-nav span{color:var(--gp-muted,#71808c);font-size:12px}',
            '.gpa-guide.is-min{width:auto;max-width:300px;cursor:pointer}.gpa-guide.is-min .gpg-bar,.gpa-guide.is-min .gpg-body,.gpa-guide.is-min .gpg-nav{display:none}.gpa-guide.is-min .gpg-head{padding:8px 12px}',
            '.gpa-ring{position:fixed;z-index:2147483646;pointer-events:none;border:3px solid var(--gp-info,#2f7fd0);border-radius:9px;box-shadow:0 0 0 4px rgba(47,127,208,.25);animation:gpaPulse 1s ease-in-out infinite}.gpa-tip{position:fixed;z-index:2147483647;pointer-events:none;background:var(--gp-info,#2f7fd0);color:#fff;border-radius:6px;padding:3px 8px;font:600 12px/1.3 var(--gp-font-body,system-ui,sans-serif);white-space:nowrap}',
            '@keyframes gpaPulse{0%,100%{box-shadow:0 0 0 3px rgba(47,127,208,.35)}50%{box-shadow:0 0 0 10px rgba(47,127,208,0)}}',
            '.gpa-demo{position:fixed;right:22px;bottom:22px;z-index:2147483647;transition:top .2s,bottom .2s;width:min(400px,calc(100vw - 32px));border:1px solid var(--gp-border,#dce9e2);border-radius:12px;background:var(--gp-surface,#fff);color:var(--gp-text,#203f35);box-shadow:var(--gp-shadow-md,0 12px 32px rgba(20,60,45,.24));font:13px/1.45 var(--gp-font-body,system-ui,sans-serif)}.gpa-demo *{box-sizing:border-box}',
            '.gpa-demo .gpd-head{display:flex;align-items:center;gap:8px;padding:10px 12px 8px}.gpa-demo .gpd-head i.bi-play-circle{color:var(--gp-primary,#247a5b);font-size:17px}.gpa-demo .gpd-head strong{flex:1;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.gpa-demo .gpd-tag{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#fff;background:var(--gp-primary,#247a5b);border-radius:9px;padding:1px 7px}',
            '.gpa-demo .gpd-bar{height:4px;background:var(--gp-border,#e4ece8);margin:0 12px;border-radius:3px;overflow:hidden}.gpa-demo .gpd-bar span{display:block;height:100%;width:0;background:var(--gp-primary,#247a5b)}',
            '.gpa-demo .gpd-body{padding:10px 14px}.gpa-demo .gpd-n{color:var(--gp-muted,#71808c);font-size:11px;font-weight:700;text-transform:uppercase;margin-bottom:3px}.gpa-demo .gpd-text{font-size:14px;margin:0 0 8px}.gpa-demo .gpd-see{border:1px solid var(--gp-primary,#72b99c);border-radius:8px;background:var(--gp-primary-soft,#edfff8);color:var(--gp-primary-dark,#1a765c);padding:7px 10px;margin-bottom:8px}.gpa-demo .gpd-see strong{display:block;margin-bottom:2px}.gpa-demo .gpd-msg{font-size:12px;color:var(--gp-warning-dark,#8a5a00);margin-bottom:6px}.gpa-demo .gpd-nota{font-size:11px;color:var(--gp-muted,#71808c)}',
            '.gpa-demo .gpd-nav{display:flex;align-items:center;gap:4px;padding:8px 12px;border-top:1px solid var(--gp-border,#dce9e2)}.gpa-demo .gpd-nav button{border:1px solid var(--gp-border,#dce9e2);background:var(--gp-surface,#fff);color:var(--gp-primary-dark,#185640);border-radius:7px;padding:4px 9px;cursor:pointer;font-size:15px;line-height:1}.gpa-demo .gpd-nav button:hover{background:var(--gp-primary-soft,#e8f5ee)}.gpa-demo .gpd-nav .gpd-fim{margin-left:auto;font-size:12px;font-weight:600}',
            '.gpa-demo.gpd-cima{bottom:auto;top:76px}','.gpa-ghost{position:fixed;z-index:2147483640;pointer-events:none;overflow:hidden;color:#4f6b60;font:italic 13px/1.4 var(--gp-font-body,system-ui,sans-serif);padding:5px 9px;white-space:pre-wrap;background:rgba(255,255,255,.88);border-radius:6px}.gpa-ghost:after{content:"|";animation:gpaCaret 1s steps(1) infinite}.gpa-ghost small{position:absolute;right:6px;bottom:3px;font:600 10px/1 var(--gp-font-body,system-ui,sans-serif);color:#fff;background:#8aa59a;border-radius:6px;padding:2px 5px;font-style:normal}',
            '@keyframes gpaCaret{50%{opacity:0}}',
            '@media (max-width:640px){.gpa-panel{width:100vw}.gpa-guide,.gpa-demo{right:8px;left:8px;width:auto;bottom:8px}}'
        ].join('');
        D.head.appendChild(s);
    }

    // ------------------------------------------------------------------ dados
    var dados = null, promessa = null;
    function carregar() {
        if (dados) return Promise.resolve(dados);
        if (promessa) return promessa;
        promessa = fetch(BASE + '/principal/api/ajuda.php?action=dados', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || !d.ok) throw new Error((d && d.message) || t('erro'));
            dados = d; return d;
        }).catch(function (e) { promessa = null; throw e; });
        return promessa;
    }
    function guia(id) { return dados ? (dados.guias || []).filter(function (g) { return g.id === id; })[0] || null : null; }
    function perfilNome(k) { var p = (dados && dados.perfis || []).filter(function (x) { return x.chave === k; })[0]; return p ? p.nome : ''; }
    function perfilAtual() { var p = lsGet(CHAVE_PERFIL); return p === null ? String((dados && dados.perfil_padrao) || '') : String(p); }

    // ------------------------------------------------------------------ tela aberta
    function visivel(el) { if (!el || !el.getClientRects || !el.getClientRects().length) return false; try { var st = el.ownerDocument.defaultView.getComputedStyle(el); return st.visibility !== 'hidden' && st.display !== 'none' && Number(st.opacity) > 0.05; } catch (e) { return true; } }
    function janelaDe(frame) { try { return frame && frame.contentWindow && frame.contentWindow.document ? frame.contentWindow : null; } catch (e) { return null; } }
    function modaisAbertos() {
        return Array.prototype.slice.call(D.querySelectorAll('.modal.show')).filter(visivel).sort(function (a, b) { return (Number(W.getComputedStyle(b).zIndex) || 0) - (Number(W.getComputedStyle(a).zIndex) || 0); });
    }
    function abaAtiva() {
        var pane = D.querySelector('.workspace-tab-pane.active'), tab = D.querySelector('.workspace-tab.active .workspace-tab-label');
        return { win: janelaDe(pane && pane.querySelector('iframe')), titulo: tab ? tab.textContent.trim() : '' };
    }
    function chavesDe(win) {
        var c = [];
        if (!win) return c;
        try {
            var d = win.document, b = d.body;
            if (b && b.getAttribute('data-transacao')) c.push(b.getAttribute('data-transacao'));
            Array.prototype.forEach.call(d.querySelectorAll('[data-help-transaction]'), function (el) { var v = el.getAttribute('data-help-transaction'); if (v) c.push(v); });
            if (d.querySelector('[data-open-guide-requisitos], .modulo-legal-requisitos-fragment')) c.push('modulolegal_requisitoslegais');
            var arq = String(win.location.pathname || '').split('/').pop();
            if (arq) c.push('arquivo:' + arq.toLowerCase());
        } catch (e) {}
        return c.filter(function (v, i) { return v && c.indexOf(v) === i; });
    }
    // Migração 363: telas abertas dentro de outra (Home › Minhas atividades › Ações): desce pelo iframe visível que ocupa
    // a maior parte da janela (até 4 níveis); a cadeia vem da mais interna para a de fora.
    function cadeiaDe(win) {
        var cad = [win];
        try {
            for (var nivel = 0; nivel < 4; nivel++) {
                var atual = cad[cad.length - 1], area = (atual.innerWidth || 0) * (atual.innerHeight || 0), prox = null;
                Array.prototype.forEach.call(atual.document.querySelectorAll('iframe'), function (f) {
                    if (prox || !visivel(f)) return;
                    var r = f.getBoundingClientRect();
                    if (area > 0 && r.width * r.height >= area * 0.4) { var w = janelaDe(f); if (w && w.document && w.document.body) prox = w; }
                });
                if (!prox) break;
                cad.push(prox);
            }
        } catch (e) {}
        return cad.reverse();
    }
    function chavesDaCadeia(cad, extra) {
        var c = [];
        cad.forEach(function (w) { c = c.concat(chavesDe(w)); });
        if (extra) c = c.concat(chavesDe(extra));
        return c.filter(function (v, i) { return v && c.indexOf(v) === i; });
    }
    function telaAtual() {
        var aba = abaAtiva(), modais = modaisAbertos();
        for (var i = 0; i < modais.length; i++) {
            var w = janelaDe(modais[i].querySelector('iframe'));
            if (w) { var tt = modais[i].querySelector('.modal-title,[data-popup-transacao-title]'), cm = cadeiaDe(w); return { win: cm[0], titulo: (tt ? tt.textContent.trim() : '') || aba.titulo, chaves: chavesDaCadeia(cm, aba.win), janela: true, abaWin: aba.win, abaTitulo: aba.titulo, cadeia: cm }; }
        }
        if (aba.win) { var ca = cadeiaDe(aba.win); return { win: ca[0], titulo: aba.titulo, chaves: chavesDaCadeia(ca), janela: false, abaWin: aba.win, abaTitulo: aba.titulo, cadeia: ca }; }
        return { win: W, titulo: t('inicio'), chaves: ['HOME'], janela: false };
    }
    // "Sobre esta tela": a tela mais interna primeiro (a ordem das chaves), não a primeira do cadastro que bater
    function infoDaTela(tela) {
        var lista = dados.telas || [];
        for (var i = 0; i < tela.chaves.length; i++) {
            var k = norm(tela.chaves[i]);
            for (var j = 0; j < lista.length; j++) if ((lista[j].chaves || []).map(norm).indexOf(k) >= 0) return lista[j];
        }
        return null;
    }
    function bate(listaGuia, chaves) {
        var a = (listaGuia || []).map(norm), b = (chaves || []).map(norm);
        for (var i = 0; i < a.length; i++) if (b.indexOf(a[i]) >= 0) return true;
        return false;
    }

    // ------------------------------------------------------------------ achar o item na tela ("Mostre-me")
    function documentos(tela) {
        var lista = [], vistos = [];
        function add(w) { try { if (w && w.document && vistos.indexOf(w) < 0) { vistos.push(w); lista.push(w.document); } } catch (e) {} }
        add(tela && tela.win);
        ((tela && tela.cadeia) || []).forEach(add);
        modaisAbertos().forEach(function (m) { add(janelaDe(m.querySelector('iframe'))); });
        add(tela && tela.abaWin);
        add(abaAtiva().win);
        add(W);
        return lista;
    }
    function rotulos(el) { return [el.textContent, el.getAttribute('title'), el.getAttribute('aria-label'), el.getAttribute('data-bs-original-title'), el.getAttribute('data-tab-title'), el.getAttribute('placeholder'), el.value && /^(button|submit)$/i.test(el.type || '') ? el.value : ''].map(norm).filter(Boolean); }
    var CLICAVEIS = 'button,a,[role=button],[role=tab],.nav-link,label,.form-label,th,summary,input[type=button],input[type=submit],.portal-menu-item,.portal-submenu-item,.workspace-tab,[data-crud-action]';
    function porTexto(d, texto, seletor) {
        var alvo = norm(texto); if (!alvo) return null;
        var els = Array.prototype.slice.call(d.querySelectorAll(seletor || CLICAVEIS)).filter(visivel), melhor = null, nota = 0;
        els.forEach(function (el) {
            rotulos(el).forEach(function (r) {
                var n = r === alvo ? 3 : (r.indexOf(alvo) === 0 ? 2 : (r.indexOf(alvo) > 0 && alvo.length > 3 ? 1 : 0));
                if (n > nota || (n === nota && n > 0 && melhor && melhor.contains(el))) { nota = n; melhor = el; }
            });
        });
        if (melhor && /^(LABEL)$/.test(melhor.tagName) && melhor.htmlFor) { var c = d.getElementById(melhor.htmlFor); if (c && visivel(c)) melhor = c; }
        return melhor;
    }
    function linhaPreferida(d) { return d.querySelector('tbody tr.selected, tbody tr.table-active, tbody tr.active, tbody tr.is-selected') || Array.prototype.slice.call(d.querySelectorAll('tbody tr[data-key^="novo"]')).filter(visivel)[0] || null; }
    function buscarEm(d, tipo, valor) {
        var q = function (s) { try { return Array.prototype.slice.call(d.querySelectorAll(s)).filter(visivel)[0] || null; } catch (e) { return null; } };
        switch (tipo) {
            case 'menu': {
                if (d !== D) return null;
                var b = valor && /^[A-Z0-9_]+$/.test(valor) ? D.querySelector('[data-open-tab="menu-' + valor.toLowerCase() + '"]') : porTexto(D, valor, '.portal-menu-item,.portal-submenu-item');
                return b || null;
            }
            case 'acao': {
                var extra = { 'export': ',[data-export-requisitos],[data-export-cadastro]', 'reload': ',[data-reload-grid]', 'columns': ',[data-columns]', 'save': ',[data-confirm-requisitos]' }[valor] || '';
                return q('[data-crud-action="' + valor + '"]' + extra);
            }
            case 'texto': return porTexto(d, valor);
            case 'aba': return porTexto(d, valor, '.nav-link,[role=tab],.workspace-tab,[data-ged-tab]');
            case 'campo': {
                var linha = linhaPreferida(d), sel = '[name$="[' + valor + ']"], [data-column="' + valor + '"] select, [data-column="' + valor + '"] input, [data-column="' + valor + '"] textarea';
                var el = linha ? Array.prototype.slice.call(linha.querySelectorAll(sel)).filter(visivel)[0] : null;
                return el || q(sel) || q('th[data-column="' + valor + '"]') || porTexto(d, valor);
            }
            case 'coluna': return q('th[data-column="' + valor + '"]') || porTexto(d, valor, 'th');
            case 'filtro': return q('[data-column-filter="' + valor + '"]');
            case 'linha': return linhaPreferida(d) || q('tbody tr[data-row]') || q('tbody tr[data-key]') || q('tbody tr[data-name]');
            case 'busca': return q('.crud-search input') || q('input[type=search]:not([data-column-filter])') || q('input[name=busca]');
            case 'css': return q(valor);
        }
        return null;
    }
    function negritos(texto) { var r = [], m, re = /\*\*([^*]+)\*\*/g; while ((m = re.exec(String(texto || '')))) r.push(m[1]); return r; }
    function especificacoes(passo) {
        var s = String(passo.alvo || '').trim(), lista = [];
        if (s) { var i = s.indexOf(':'); lista.push(i > 0 && /^(menu|acao|texto|campo|coluna|filtro|aba|css)$/.test(s.substring(0, i)) ? [s.substring(0, i), s.substring(i + 1).trim()] : (/^(linha|busca)$/.test(s) ? [s, ''] : ['texto', s])); }
        var b = negritos(passo.texto), menu = /\bmenu\b/i.test(passo.texto);
        for (var j = b.length - 1; j >= 0; j--) { if (menu) lista.push(['menu', b[j]]); lista.push(['texto', b[j]]); }
        return lista;
    }
    function achar(passo, tela) {
        var specs = especificacoes(passo), docs = documentos(tela);
        for (var i = 0; i < specs.length; i++) {
            if (specs[i][0] === 'menu') { var m = buscarEm(D, 'menu', specs[i][1]); if (m) return m; continue; }
            for (var j = 0; j < docs.length; j++) { var el = buscarEm(docs[j], specs[i][0], specs[i][1]); if (el) return el; }
        }
        return null;
    }
    function temAlvo(passo) { return especificacoes(passo).length > 0; }
    function expandirMenu(el) {
        var abriu = false, sub = el.closest ? el.closest('[data-submenu]') : null, cadeia = [];
        while (sub) { var pai = sub.previousElementSibling; if (pai && pai.hasAttribute('data-menu-parent')) cadeia.unshift(pai); sub = sub.parentElement ? sub.parentElement.closest('[data-submenu]') : null; }
        cadeia.forEach(function (p) { if (p.getAttribute('aria-expanded') !== 'true' && !p.classList.contains('expanded')) { p.click(); abriu = true; } });
        if (D.body.classList.contains('sidebar-collapsed')) { var tg = D.querySelector('[data-sidebar-toggle]'); if (tg) { tg.click(); abriu = true; } }
        return abriu;
    }
    function retangulo(el) {
        var r = el.getBoundingClientRect(), x = r.left, y = r.top, w = el.ownerDocument.defaultView;
        try { while (w && w !== W && w.frameElement) { var fr = w.frameElement.getBoundingClientRect(); x += fr.left + (w.frameElement.clientLeft || 0); y += fr.top + (w.frameElement.clientTop || 0); w = w.parent; } } catch (e) {}
        return { left: x, top: y, width: r.width, height: r.height };
    }
    var anel = null, dica = null, anelTimer = null;
    function limparAnel() { W.clearInterval(anelTimer); if (anel) anel.remove(); if (dica) dica.remove(); anel = dica = null; }
    function destacar(el, texto, ms) {
        limparAnel(); css();
        try { el.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'smooth' }); } catch (e) {}
        anel = D.createElement('div'); anel.className = 'gpa-ring'; dica = D.createElement('div'); dica.className = 'gpa-tip'; dica.textContent = texto || t('clique_aqui');
        D.body.appendChild(anel); D.body.appendChild(dica);
        var fim = Date.now() + (ms || 4500);
        function pos() {
            if (!anel) return;
            if (Date.now() > fim || !el.isConnected) { limparAnel(); return; }
            var r = retangulo(el); anel.style.left = (r.left - 6) + 'px'; anel.style.top = (r.top - 6) + 'px'; anel.style.width = (r.width + 12) + 'px'; anel.style.height = (r.height + 12) + 'px';
            var acima = r.top > 34; dica.style.left = Math.max(6, Math.min(W.innerWidth - 160, r.left)) + 'px'; dica.style.top = (acima ? r.top - 32 : r.top + r.height + 10) + 'px';
        }
        pos(); anelTimer = W.setInterval(pos, 60);
    }

    // ------------------------------------------------------------------ painel
    var painel = null, telaPainel = null, assinatura = '', vigia = null, modo = 'tela', filtroCentral = '';
    function montarPainel() {
        if (painel) return painel; css();
        painel = D.createElement('aside'); painel.className = 'gpa-panel'; painel.setAttribute('role', 'complementary'); painel.setAttribute('aria-label', t('ajuda'));
        painel.innerHTML = '<div class="gpa-head"><i class="bi bi-question-circle" aria-hidden="true"></i><h2 data-gpa-titulo>' + esc(t('ajuda')) + '</h2><button type="button" class="gpa-x" data-gpa-fechar title="' + esc(t('fechar')) + ' (Esc)" aria-label="' + esc(t('fechar')) + '">×</button></div>'
            + '<div class="gpa-role"><label for="gpaPerfil">' + esc(t('mostrar_como')) + '</label><select id="gpaPerfil" data-gpa-perfil></select></div><div class="gpa-body" data-gpa-corpo></div>'
            + '<div class="gpa-foot"><div class="gpa-useful"><span>' + esc(t('util')) + '</span><span><button type="button" data-gpa-util="1" title="👍" aria-label="👍"><i class="bi bi-hand-thumbs-up"></i></button><button type="button" data-gpa-util="0" title="👎" aria-label="👎"><i class="bi bi-hand-thumbs-down"></i></button></span></div>'
            + '<button type="button" class="gpa-full" data-gpa-manual><i class="bi bi-book"></i>' + esc(t('manual')) + '</button><button type="button" class="gpa-full" data-gpa-assistente><i class="bi bi-chat-dots"></i>' + esc(t('assistente')) + '</button><div class="gpa-short">' + esc(t('atalho')) + '</div></div>';
        D.body.appendChild(painel);
        // As janelas (Bootstrap) prendem o foco; o painel e o cartão ficam fora dessa trava.
        painel.addEventListener('focusin', function (e) { e.stopPropagation(); });
        painel.addEventListener('click', cliquePainel);
        painel.querySelector('[data-gpa-perfil]').addEventListener('change', function () { lsSet(CHAVE_PERFIL, this.value); renderPainel(true); });
        return painel;
    }
    function abrir() {
        montarPainel(); painel.classList.add('is-open'); modo = 'tela';
        painel.querySelector('[data-gpa-corpo]').innerHTML = '<p>' + esc(t('carregando')) + '</p>';
        carregar().then(function () { renderPainel(true); }).catch(function (e) { painel.querySelector('[data-gpa-corpo]').innerHTML = '<p>' + esc(e.message || t('erro')) + '</p>'; });
        W.clearInterval(vigia); vigia = W.setInterval(function () { if (painel.classList.contains('is-open') && modo === 'tela') renderPainel(false); }, 1500);
    }
    function fechar() { if (painel) painel.classList.remove('is-open'); W.clearInterval(vigia); }
    function alternar() { if (painel && painel.classList.contains('is-open')) fechar(); else abrir(); }
    function guiasDaTela(tela, perfil) {
        // migração 363: primeiro os guias da tela mais interna (posição da chave em tela.chaves), depois os completos
        var ch = tela.chaves.map(norm), pos = function (g) { var m = 999; (g.telas || []).forEach(function (x) { var i = ch.indexOf(norm(x)); if (i >= 0 && i < m) m = i; }); return m; };
        return (dados.guias || []).filter(function (g) { return bate(g.telas, tela.chaves) && (!perfil || !g.perfis.length || g.perfis.indexOf(perfil) >= 0); })
            .sort(function (a, b) { return (pos(a) - pos(b)) || ((b.completo ? 1 : 0) - (a.completo ? 1 : 0)); });
    }
    function assuntoDaTela(tela) {
        var codes = tela.chaves.map(function (c) { return String(c).toUpperCase(); });
        return (dados.assuntos || []).filter(function (a) { return (a.menus || []).some(function (m) { return codes.indexOf(m) >= 0; }); })[0]
            || (dados.assuntos || []).filter(function (a) { return (dados.guias || []).some(function (g) { return g.assunto === a.id && bate(g.telas, tela.chaves); }); })[0] || null;
    }
    function cartaoGuia(g) {
        var ativo = lsGet(CHAVE_GUIA), quem = [];
        g.passos.forEach(function (p) { var n = perfilNome(p.quem); if (n && quem.indexOf(n) < 0) quem.push(n); });
        if (!quem.length) g.perfis.forEach(function (k) { var n = perfilNome(k); if (n) quem.push(n); });
        return '<article class="gpa-card"><h3><i class="bi bi-compass"></i><span>' + esc(g.titulo) + '</span></h3><div class="gpa-meta">' + g.passos.length + ' ' + esc(t('passos')) + (quem.length ? ' · ' + esc(quem.join(', ')) : '') + '</div>'
            + (g.resumo ? '<p class="gpa-resumo">' + rico(g.resumo) + '</p>' : '')
            + '<div class="gpa-acts"><button type="button" class="gpa-btn primary" data-gpa-iniciar="' + esc(g.id) + '"><i class="bi bi-play-fill"></i>' + esc(ativo && ativo.id === g.id ? t('continuar') : t('comecar')) + '</button><button type="button" class="gpa-link" data-gpa-ver="' + esc(g.id) + '">' + esc(t('ver_passos')) + ' <i class="bi bi-chevron-down"></i></button></div>'
            + '<div data-gpa-lista="' + esc(g.id) + '" hidden>' + (g.antes ? '<p class="gpa-meta mt-2"><strong>' + esc(t('antes')) + ':</strong> ' + rico(g.antes) + '</p>' : '') + '<ol class="gpa-steps">' + g.passos.map(function (p) { return '<li>' + rico(p.texto) + '</li>'; }).join('') + '</ol></div></article>';
    }
    function agora(tela) {
        var linhas = [];
        try {
            var d = tela.win.document;
            if (tela.janela) linhas.push(t('janela', { t: tela.titulo }));
            var rows = Array.prototype.slice.call(d.querySelectorAll('tbody tr[data-row], tbody tr[data-key]')).filter(visivel);
            if (rows.length) linhas.push(t('linhas', { n: rows.length }));
            var sel = d.querySelector('tbody tr.selected, tbody tr.table-active, tbody tr.active, tbody tr.is-selected');
            if (sel) {
                var cel = Array.prototype.slice.call(sel.querySelectorAll('td')).map(function (td) { var c = td.querySelector('select'); var v = c ? (c.options[c.selectedIndex] || {}).text : (td.querySelector('input,textarea') ? (td.querySelector('input,textarea').value) : td.textContent); return String(v || '').trim(); }).filter(function (v) { return v && v.length > 1 && !/^\d+$/.test(v); }).slice(0, 2);
                if (cel.length) linhas.push(t('selecionada', { t: cel.join(' · ').substring(0, 120) }));
            } else if (rows.length) {
                var travados = Array.prototype.slice.call(d.querySelectorAll('.menu-grid-toolbar button[disabled], .crud-toolbar button[disabled]')).filter(function (b) { return !b.hasAttribute('data-permission-locked'); }).length;
                if (travados) linhas.push(t('sem_selecao', { n: travados }));
            }
            var busca = d.querySelector('.crud-search input');
            if (busca && busca.value.trim()) linhas.push(t('busca', { t: busca.value.trim().substring(0, 60) }));
            var filtros = Array.prototype.slice.call(d.querySelectorAll('[data-column-filter]')).filter(function (f) { return String(f.value || '').trim() !== ''; }).map(function (f) { var th = f.closest('th'), n = th ? (th.querySelector('.column-heading span') || th).textContent.trim() : f.getAttribute('data-column-filter'); return n + ' = ' + (f.tagName === 'SELECT' ? (f.options[f.selectedIndex] || {}).text : f.value); });
            if (filtros.length) linhas.push(t('filtros', { t: filtros.join('; ').substring(0, 160) }));
            if (d.querySelector('tbody tr.is-dirty, tbody tr.dirty, tbody tr[data-dirty="1"], .grid-input.is-dirty')) linhas.push(t('nao_salvo'));
        } catch (e) {}
        return linhas;
    }
    function renderPainel(forcar) {
        if (!painel || !dados) return;
        var tela = telaAtual(), perfil = perfilAtual(), ass = tela.chaves.join('|') + '#' + perfil + '#' + modo + '#' + agora(tela).join('|');
        if (!forcar && ass === assinatura) return;
        assinatura = ass; telaPainel = tela;
        var sel = painel.querySelector('[data-gpa-perfil]');
        sel.innerHTML = '<option value="">' + esc(t('geral')) + '</option>' + (dados.perfis || []).map(function (p) { return '<option value="' + esc(p.chave) + '"' + (p.chave === perfil ? ' selected' : '') + '>' + esc(p.nome) + '</option>'; }).join('');
        painel.querySelector('[data-gpa-titulo]').textContent = t('ajuda') + ' - ' + (tela.titulo || t('inicio'));
        var corpo = painel.querySelector('[data-gpa-corpo]'), h = '';
        if (modo === 'central') { renderCentral(corpo, perfil); return; }
        var lista = guiasDaTela(tela, perfil), assunto = assuntoDaTela(tela);
        h += '<p class="gpa-kicker">' + esc(t('guias_tela')) + '</p>';
        if (lista.length) h += lista.map(cartaoGuia).join('');
        else {
            h += '<p class="gpa-meta">' + esc(t('nenhum_guia')) + '</p>';
            var rel = assunto ? (dados.guias || []).filter(function (g) { return g.assunto === assunto.id && g.completo && (!perfil || !g.perfis.length || g.perfis.indexOf(perfil) >= 0); }).slice(0, 3) : [];
            if (rel.length) h += '<p class="gpa-kicker">' + esc(t('relacionados')) + '</p>' + rel.map(cartaoGuia).join('');
        }
        h += '<button type="button" class="gpa-full" data-gpa-central><i class="bi bi-compass"></i>' + esc(t('ver_todos')) + '</button>';
        var info = infoDaTela(tela), vivo = agora(tela);
        h += '<div class="gpa-sec"><p class="gpa-kicker">' + esc(t('sobre')) + '</p>';
        if (info) {
            if (info.oque) h += '<h4>' + esc(t('oque')) + '</h4><p>' + rico(info.oque) + '</p>';
            if (info.pode && info.pode.length) h += '<h4>' + esc(t('pode')) + '</h4><ul>' + info.pode.map(function (p, i) { return '<li>' + rico(p.texto) + (p.alvo ? ' <button type="button" class="gpa-link" data-gpa-mostrar="' + i + '">' + esc(t('mostrar')) + '</button>' : '') + '</li>'; }).join('') + '</ul>';
            if (info.antes) h += '<h4>' + esc(t('antes')) + '</h4><p>' + rico(info.antes) + '</p>';
            if (info.depois) h += '<h4>' + esc(t('depois')) + '</h4><p>' + rico(info.depois) + '</p>';
        }
        if (vivo.length) h += '<h4>' + esc(t('agora')) + '</h4><div class="gpa-now">' + vivo.map(function (l) { return '<div>' + esc(l) + '</div>'; }).join('') + '</div>';
        if (info && info.duvidas && info.duvidas.length) h += '<h4>' + esc(t('duvidas')) + '</h4>' + info.duvidas.map(function (d) { return '<details><summary>' + esc(d.p) + '</summary><p>' + rico(d.r) + '</p></details>'; }).join('');
        h += '<div data-gpa-ajuda-tela></div>';
        if (assunto && assunto.palavras && assunto.palavras.length) h += '<details><summary>' + esc(t('palavras')) + ' (' + esc(assunto.nome) + ')</summary>' + assunto.palavras.map(function (p) { return '<p><strong>' + esc(p.termo) + ':</strong> ' + rico(p.texto) + '</p>'; }).join('') + '</details>';
        if (!info && !vivo.length) h += '<p class="gpa-meta">' + esc(t('sem_texto')) + '</p>';
        h += '</div>';
        corpo.innerHTML = h;
        corpo._info = info;
        ajudaTransacao(tela);
        var bAss = painel.querySelector('[data-gpa-assistente]'), temAss = !!D.querySelector('[data-assistente-alternar]');
        bAss.disabled = !temAss; bAss.title = temAss ? '' : t('assistente_off');
    }
    var ajudaCache = {};
    function ajudaTransacao(tela) {
        var chaves = tela.chaves.filter(function (c) { return c.indexOf(':') < 0 && c !== 'HOME'; }).slice(0, 6), k = chaves.join(',');
        if (!k) return;
        var pintar = function (r) { var alvo = painel && painel.querySelector('[data-gpa-ajuda-tela]'); if (!alvo || !r || !r.html) return; alvo.innerHTML = '<details><summary>' + esc(t('ajuda_tela')) + (r.titulo ? ': ' + esc(r.titulo) : '') + '</summary><div class="gpa-html">' + r.html + '</div></details>'; Array.prototype.forEach.call(alvo.querySelectorAll('a[href]'), function (a) { a.target = '_blank'; a.rel = 'noopener'; }); };
        if (ajudaCache[k]) { pintar(ajudaCache[k]); return; }
        fetch(BASE + '/principal/api/ajuda.php?action=transacao&chaves=' + encodeURIComponent(k), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) { ajudaCache[k] = r; pintar(r); }).catch(function () {});
    }
    function renderCentral(corpo, perfil) {
        var f = norm(filtroCentral), h = '<div class="gpa-acts" style="margin-bottom:8px"><button type="button" class="gpa-link" data-gpa-voltar><i class="bi bi-arrow-left"></i> ' + esc(t('voltar')) + '</button><strong style="margin-left:auto">' + esc(t('central')) + '</strong></div>'
            + '<input type="search" class="gpa-search" data-gpa-busca placeholder="' + esc(t('buscar')) + '" value="' + esc(filtroCentral) + '">';
        (dados.assuntos || []).forEach(function (a) {
            var gs = (dados.guias || []).filter(function (g) { return g.assunto === a.id && (!perfil || !g.perfis.length || g.perfis.indexOf(perfil) >= 0) && (!f || norm(g.titulo + ' ' + g.resumo + ' ' + g.passos.map(function (p) { return p.texto; }).join(' ')).indexOf(f) >= 0); });
            if (!gs.length) return;
            h += '<p class="gpa-kicker"><i class="bi ' + esc(a.icone || 'bi-circle') + '"></i> ' + esc(a.nome) + '</p>' + gs.map(cartaoGuia).join('');
        });
        corpo.innerHTML = h;
        var inp = corpo.querySelector('[data-gpa-busca]');
        inp.addEventListener('input', function () { filtroCentral = this.value; var pos = this.selectionStart; renderCentral(corpo, perfil); var n = corpo.querySelector('[data-gpa-busca]'); n.focus(); try { n.setSelectionRange(pos, pos); } catch (e) {} });
    }
    function cliquePainel(e) {
        var el = e.target.closest('button'); if (!el) return;
        if (el.hasAttribute('data-gpa-fechar')) return fechar();
        if (el.hasAttribute('data-gpa-iniciar')) { iniciar(el.getAttribute('data-gpa-iniciar')); return fechar(); }
        if (el.hasAttribute('data-gpa-ver')) { var l = painel.querySelector('[data-gpa-lista="' + el.getAttribute('data-gpa-ver') + '"]'); if (l) l.hidden = !l.hidden; return; }
        if (el.hasAttribute('data-gpa-central')) { modo = 'central'; return renderPainel(true); }
        if (el.hasAttribute('data-gpa-voltar')) { modo = 'tela'; return renderPainel(true); }
        if (el.hasAttribute('data-gpa-mostrar')) {
            var info = painel.querySelector('[data-gpa-corpo]')._info, item = info && info.pode[Number(el.getAttribute('data-gpa-mostrar'))];
            if (item) { var alvo = achar({ alvo: item.alvo, texto: '' }, telaPainel || telaAtual()); if (alvo) { expandirMenu(alvo); destacar(alvo, item.texto); } else el.textContent = '—'; }
            return;
        }
        if (el.hasAttribute('data-gpa-util')) {
            var corpo = new FormData(); corpo.append('action', 'feedback'); corpo.append('csrf', dados ? dados.csrf : ''); corpo.append('util', el.getAttribute('data-gpa-util')); corpo.append('tela', ((telaPainel || {}).chaves || [])[0] || '');
            fetch(BASE + '/principal/api/ajuda.php', { method: 'POST', body: corpo, credentials: 'same-origin' }).catch(function () {});
            el.parentNode.innerHTML = '<span class="gpa-meta">' + esc(t('obrigado')) + '</span>'; return;
        }
        if (el.hasAttribute('data-gpa-manual')) { var m = D.querySelector('[data-open-user-guide]'); if (m) m.click(); return; }
        if (el.hasAttribute('data-gpa-assistente')) { var a = D.querySelector('[data-assistente-alternar]'); if (a) { fechar(); a.click(); } }
    }

    // ------------------------------------------------------------------ modo guiado
    var cartao = null, estado = null, esperaClique = null;
    function salvar() { lsSet(CHAVE_GUIA, estado); }
    function partes(g) {
        var lista = [], atual = null;
        g.passos.forEach(function (p, i) { var q = p.quem || ''; if (!atual || atual.quem !== q) { atual = { quem: q, inicio: i }; lista.push(atual); } });
        return lista.filter(function (p) { return p.quem; }).length ? lista : [];
    }
    function iniciar(id) {
        carregar().then(function () {
            var g = guia(id); if (!g) return;
            var ant = lsGet(CHAVE_GUIA);
            estado = ant && ant.id === id ? ant : { id: id, passo: 0, min: false };
            estado.min = false; salvar(); renderCartao();
        });
    }
    function sair() { pararEspera(); limparAnel(); estado = null; salvar(); if (cartao) { cartao.remove(); cartao = null; } }
    function pararEspera() { if (esperaClique) { try { esperaClique.doc.removeEventListener('click', esperaClique.fn, true); } catch (e) {} esperaClique = null; } }
    function irPara(codigo) {
        var b = D.querySelector('[data-open-tab="menu-' + String(codigo).toLowerCase() + '"]');
        if (b) { expandirMenu(b); b.click(); return true; }
        return false;
    }
    function nomeMenu(codigo) { var m = dados && dados.menus && dados.menus[codigo]; return m ? (m.grupo ? m.grupo + ' › ' : '') + m.nome : codigo; }
    function msg(texto, ok) { var m = cartao && cartao.querySelector('[data-gpg-msg]'); if (m) { m.className = 'gpg-msg' + (ok ? ' ok' : ''); m.innerHTML = texto ? rico(texto) : ''; } }
    function renderCartao() {
        pararEspera(); limparAnel();
        if (!estado || !dados) return;
        var g = guia(estado.id); if (!g) { sair(); return; }
        css();
        if (!cartao) {
            cartao = D.createElement('aside'); cartao.className = 'gpa-guide'; cartao.setAttribute('role', 'dialog'); cartao.setAttribute('aria-live', 'polite');
            cartao.addEventListener('focusin', function (e) { e.stopPropagation(); });
            cartao.addEventListener('click', cliqueCartao);
            D.body.appendChild(cartao);
        }
        var total = g.passos.length, n = Math.max(0, Math.min(estado.passo, total)), fim = n >= total;
        cartao.classList.toggle('is-min', !!estado.min);
        if (fim) {
            cartao.innerHTML = '<div class="gpg-head"><i class="bi bi-compass"></i><strong>' + esc(g.titulo) + '</strong><button type="button" class="gpa-x" data-gpg-sair aria-label="' + esc(t('sair_guia')) + '">×</button></div><div class="gpg-bar"><span style="width:100%"></span></div>'
                + '<div class="gpg-body"><p class="gpg-text"><i class="bi bi-check2-circle"></i> <strong>' + esc(t('concluido')) + '</strong> ' + esc(t('concluido_txt')) + '</p>' + (g.depois ? '<div class="gpg-see"><strong>' + esc(t('depois')) + '</strong>' + rico(g.depois) + '</div>' : '')
                + '<div class="gpa-useful"><span>' + esc(t('util')) + '</span><span><button type="button" data-gpg-util="1" aria-label="👍"><i class="bi bi-hand-thumbs-up"></i></button><button type="button" data-gpg-util="0" aria-label="👎"><i class="bi bi-hand-thumbs-down"></i></button></span></div></div>'
                + '<div class="gpg-nav"><button type="button" class="gpa-link" data-gpg-ant>← ' + esc(t('voltar')) + '</button><button type="button" class="gpa-btn primary" data-gpg-sair>' + esc(t('fechar')) + '</button></div>';
            return;
        }
        var p = g.passos[n], ps = partes(g), parteIdx = -1;
        ps.forEach(function (x, i) { if (x.inicio <= n) parteIdx = i; });
        var tela = telaAtual(), telaPasso = p.tela || '';
        if (!telaPasso) for (var i = n; i >= 0; i--) if (g.passos[i].tela) { telaPasso = g.passos[i].tela; break; }
        var naTela = !telaPasso || tela.chaves.map(function (c) { return String(c).toUpperCase(); }).indexOf(telaPasso) >= 0 || (tela.abaWin && chavesDe(tela.abaWin).map(function (c) { return String(c).toUpperCase(); }).indexOf(telaPasso) >= 0);
        var mostrarIr = !!telaPasso && (!!p.tela || !naTela);
        cartao.innerHTML = '<div class="gpg-head" data-gpg-restaurar><i class="bi bi-compass"></i><strong>' + esc(g.titulo) + (estado.min ? ' · ' + (n + 1) + '/' + total : '') + '</strong>'
            + '<button type="button" class="gpa-x" data-gpg-min title="' + esc(estado.min ? t('restaurar') : t('minimizar')) + '" aria-label="' + esc(estado.min ? t('restaurar') : t('minimizar')) + '">' + (estado.min ? '□' : '–') + '</button><button type="button" class="gpa-x" data-gpg-sair title="' + esc(t('sair_guia')) + '" aria-label="' + esc(t('sair_guia')) + '">×</button></div>'
            + '<div class="gpg-bar"><span style="width:' + Math.round((n / total) * 100) + '%"></span></div><div class="gpg-body">'
            + (parteIdx >= 0 && ps[parteIdx].quem ? '<div class="gpg-part"><i class="bi bi-person"></i><div>' + esc(t('parte', { n: parteIdx + 1, t: ps.length, p: perfilNome(ps[parteIdx].quem) || ps[parteIdx].quem })) + '</div></div>' : '')
            + '<div class="gpg-label">' + esc(t('passo_de', { n: n + 1, t: total })) + '</div><p class="gpg-text">' + rico(p.texto) + '</p>'
            + (p.ver ? '<div class="gpg-see"><strong><i class="bi bi-eye"></i> ' + esc(t('voce_deve_ver')) + '</strong>' + rico(p.ver) + '</div>' : '')
            + '<div class="gpg-msg" data-gpg-msg>' + (!naTela && telaPasso ? rico(t('outra_tela', { t: '**' + nomeMenu(telaPasso) + '**' })) : '') + '</div>'
            + '<div class="gpa-acts">' + (temAlvo(p) ? '<button type="button" class="gpa-btn" data-gpg-mostrar><i class="bi bi-display"></i>' + esc(t('mostre_me')) + '</button>' : '') + (mostrarIr ? '<button type="button" class="gpa-btn" data-gpg-ir><i class="bi bi-arrows-angle-expand"></i>' + esc(t('ir_tela')) + '</button>' : '') + '</div></div>'
            + '<div class="gpg-nav"><button type="button" class="gpa-link" data-gpg-ant' + (n === 0 ? ' disabled' : '') + '>← ' + esc(t('voltar')) + '</button><span>' + (n + 1) + ' / ' + total + '</span><button type="button" class="gpa-btn primary" data-gpg-prox>' + esc(n === total - 1 ? t('concluir') : t('proximo')) + ' →</button></div>';
        cartao._tela = telaPasso;
        // O item pode aparecer só depois (janela abrindo, grid carregando): tenta ligar o avanço automático por alguns segundos.
        if (!estado.min && temAlvo(p) && p.auto) {
            var passoAtual = estado.passo, idGuia = estado.id, tentativas = 0;
            (function tentar() {
                if (!estado || estado.passo !== passoAtual || estado.id !== idGuia || esperaClique) return;
                if (armarEspera(p, false) || ++tentativas > 15) return;
                W.setTimeout(tentar, 700);
            }());
        }
    }
    // Avanço automático: quando a pessoa clica no item do passo, o guia confirma e vai para o próximo.
    function armarEspera(p, mostrar) {
        pararEspera();
        var el = achar(p, telaAtual());
        if (!el) return null;
        if (mostrar) { expandirMenu(el); destacar(el); }
        if (!p.auto) return el;
        var doc = el.ownerDocument, passoAtual = estado.passo, idGuia = estado.id;
        var fn = function (ev) {
            if (!estado || estado.passo !== passoAtual || estado.id !== idGuia) { pararEspera(); return; }
            if (!(ev.target === el || el.contains(ev.target))) return;
            pararEspera(); limparAnel(); msg(t('muito_bem'), true);
            W.setTimeout(function () { if (estado && estado.passo === passoAtual && estado.id === idGuia) { estado.passo++; salvar(); renderCartao(); } }, 900);
        };
        doc.addEventListener('click', fn, true);
        esperaClique = { doc: doc, fn: fn };
        return el;
    }
    function mostrarPasso(tentativa) {
        var g = guia(estado.id), p = g && g.passos[estado.passo]; if (!p) return;
        var el = armarEspera(p, true);
        if (el) { msg(''); return; }
        if (!tentativa && cartao._tela && irPara(cartao._tela)) { msg(t('abrindo')); W.setTimeout(function () { mostrarPasso(1); }, 1800); return; }
        msg(t('nao_achei'));
    }
    function cliqueCartao(e) {
        var el = e.target.closest('button,[data-gpg-restaurar]'); if (!el || !estado) return;
        if (el.hasAttribute('data-gpg-sair')) return sair();
        if (el.hasAttribute('data-gpg-min')) { estado.min = !estado.min; salvar(); return renderCartao(); }
        if (el.hasAttribute('data-gpg-restaurar')) { if (estado.min) { estado.min = false; salvar(); renderCartao(); } return; }
        if (el.hasAttribute('data-gpg-prox')) { estado.passo++; salvar(); return renderCartao(); }
        if (el.hasAttribute('data-gpg-ant')) { estado.passo = Math.max(0, estado.passo - 1); salvar(); return renderCartao(); }
        if (el.hasAttribute('data-gpg-mostrar')) return mostrarPasso(0);
        if (el.hasAttribute('data-gpg-ir')) { if (irPara(cartao._tela)) { msg(t('abrindo')); W.setTimeout(renderCartao, 1500); } return; }
        if (el.hasAttribute('data-gpg-util')) {
            var corpo = new FormData(); corpo.append('action', 'feedback'); corpo.append('csrf', dados ? dados.csrf : ''); corpo.append('util', el.getAttribute('data-gpg-util')); corpo.append('guia', estado.id); corpo.append('tela', String(cartao._tela || ''));
            fetch(BASE + '/principal/api/ajuda.php', { method: 'POST', body: corpo, credentials: 'same-origin' }).catch(function () {});
            el.parentNode.innerHTML = '<span class="gpa-meta">' + esc(t('obrigado')) + '</span>';
        }
    }

    // ------------------------------------------------------------------ demonstração (migração 364)
    // Toca um guia sozinho sobre a tela real: o anel vai de item em item, o cartão mostra o passo e, nos campos de texto
    // vazios, o exemplo do passo ("ex.: "…"", "e.g.: "…"", "ej.: "…"") aparece digitado POR CIMA do campo, sem tocar no valor.
    // Não clica, não preenche e não grava nada. Ícone: qualquer elemento com data-ajuda-demo="<id do guia>".
    var demoEstado = null, demoCartao = null, demoGhost = null;
    function exemploDe(texto) { var m = /(?:ex\.|e\.g\.|ej\.):?\s*"([^"]+)"/i.exec(String(texto || '')); return m ? m[1] : ''; }
    function semExemplo(texto) { return String(texto || '').replace(/\s*\((?:ex\.|e\.g\.|ej\.):?\s*"[^"]+"\)/gi, ''); }
    function limparGhost() { if (demoGhost) { W.clearInterval(demoGhost.timer); W.clearInterval(demoGhost.pos); demoGhost.el.remove(); demoGhost = null; } }
    function digitarGhost(alvo, texto) {
        limparGhost();
        var tag = (alvo.tagName || '').toUpperCase(), tipo = String(alvo.type || 'text').toLowerCase();
        if (!texto || !(tag === 'TEXTAREA' || (tag === 'INPUT' && /^(text|search|email|number|tel|url)$/.test(tipo))) || String(alvo.value || '') !== '') return;
        var el = D.createElement('div'), n = 0; el.className = 'gpa-ghost'; D.body.appendChild(el);
        var marca = '<small>' + esc(t('exemplo')) + '</small>';
        demoGhost = { el: el, timer: 0, pos: 0 };
        var pos = function () { if (!alvo.isConnected) return limparGhost(); var r = retangulo(alvo); el.style.left = (r.left + 1) + 'px'; el.style.top = (r.top + 1) + 'px'; el.style.width = Math.max(40, r.width - 2) + 'px'; el.style.height = Math.max(20, r.height - 2) + 'px'; };
        pos(); demoGhost.pos = W.setInterval(pos, 60);
        W.setTimeout(function () {
            if (!demoGhost || demoGhost.el !== el) return;
            demoGhost.timer = W.setInterval(function () { n++; el.innerHTML = esc(texto.substring(0, n)) + marca; if (n >= texto.length) W.clearInterval(demoGhost.timer); }, 38);
        }, 700);
    }
    // Item em aba/sub-aba fechada ou em janela (modal) fechada: abre a aba ou mostra a janela vazia — só navegação, nada é gravado.
    function revelar(p, tela, st) {
        var specs = especificacoes(p).filter(function (x) { return x[0] === 'css'; }), docs = documentos(tela);
        for (var i = 0; i < specs.length; i++) for (var j = 0; j < docs.length; j++) {
            var d = docs[j], el = null;
            try { el = d.querySelector(specs[i][1]); } catch (e) { el = null; }
            if (!el) continue;
            var mudou = false, pane = el.closest('.tab-pane'), win = d.defaultView;
            while (pane) {
                if (!pane.classList.contains('active') && pane.id) { var gat = d.querySelector('[data-bs-target="#' + pane.id + '"],[href="#' + pane.id + '"]'); if (gat) { gat.click(); mudou = true; } }
                pane = pane.parentElement ? pane.parentElement.closest('.tab-pane') : null;
            }
            var modal = el.closest('.modal');
            if (modal && !modal.classList.contains('show') && win && win.bootstrap && win.bootstrap.Modal) {
                try { win.bootstrap.Modal.getOrCreateInstance(modal).show(); st.modal = { m: modal, win: win }; mudou = true; } catch (e) {}
            }
            if (mudou) return true;
        }
        return false;
    }
    function fecharModalDemo(st, manter) {
        if (!st || !st.modal || (manter && st.modal.m.contains(manter))) return;
        try { st.modal.win.bootstrap.Modal.getOrCreateInstance(st.modal.m).hide(); } catch (e) {}
        st.modal = null;
    }
    // Demonstração: quando o passo tem alvo css (campo exato), procura só por ele (não cai no texto em negrito, que pode achar
    // a coluna da grade com o mesmo nome enquanto a janela ainda abre).
    function acharDemo(p, tela) {
        var specs = especificacoes(p).filter(function (x) { return x[0] === 'css'; });
        if (!specs.length) return achar(p, tela);
        var docs = documentos(tela);
        for (var i = 0; i < specs.length; i++) for (var j = 0; j < docs.length; j++) { var el = buscarEm(docs[j], 'css', specs[i][1]); if (el) return el; }
        return null;
    }
    function rotuloDe(el, p) {
        try {
            // o rótulo do mesmo bloco primeiro (a tela pode repetir o id do campo em abas diferentes)
            var d = el.ownerDocument, box = el.closest ? el.closest('.col-12,.col-md-3,.col-md-4,.col-md-6') : null, lb = box ? box.querySelector('label') : null;
            if (!lb && el.id) lb = d.querySelector('label[for="' + el.id + '"]');
            var txt = lb ? lb.textContent : (el.getAttribute('title') || el.getAttribute('aria-label') || el.textContent || '');
            txt = String(txt).replace(/\s+/g, ' ').replace(/\s*\*$/, '').trim();
            if (txt && txt.length <= 60) return txt;
            if (txt) return txt.substring(0, 57) + '…';
        } catch (e) {}
        var b = negritos(p.texto); return b[b.length - 1] || t('clique_aqui');
    }
    function demonstrar(id) {
        css();
        carregar().then(function () {
            var g = guia(id);
            if (!g || !g.passos.length) { montarCartaoDemo(); demoCartao.querySelector('[data-gpd-corpo]').innerHTML = '<p class="gpd-text">' + esc(t('sem_demo')) + '</p>'; return; }
            pararDemo(true);
            if (painel) fechar();
            demoEstado = { g: g, i: 0, pausado: false, timer: 0, inicio: 0, dur: 0, resto: 0 };
            montarCartaoDemo();
            passoDemo();
        }).catch(function () {});
    }
    function montarCartaoDemo() {
        if (demoCartao) return;
        demoCartao = D.createElement('section'); demoCartao.className = 'gpa-demo'; demoCartao.setAttribute('role', 'dialog');
        demoCartao.innerHTML = '<div class="gpd-head"><i class="bi bi-play-circle"></i><strong data-gpd-titulo></strong><span class="gpd-tag">' + esc(t('demo')) + '</span><button type="button" class="gpa-x" data-gpd="fechar" title="' + esc(t('fechar')) + '">&times;</button></div>'
            + '<div class="gpd-bar"><span data-gpd-bar></span></div><div class="gpd-body" data-gpd-corpo></div>'
            + '<div class="gpd-nav"><button type="button" data-gpd="voltar" title="' + esc(t('voltar_passo')) + '"><i class="bi bi-skip-backward-fill"></i></button><button type="button" data-gpd="pausa" title="' + esc(t('pausar')) + '"><i class="bi bi-pause-fill"></i></button><button type="button" data-gpd="avancar" title="' + esc(t('avancar_passo')) + '"><i class="bi bi-skip-forward-fill"></i></button><button type="button" data-gpd="repetir" title="' + esc(t('repetir')) + '"><i class="bi bi-arrow-counterclockwise"></i></button><button type="button" class="gpd-fim" data-gpd="guia" title="' + esc(t('fazer_agora')) + '"><i class="bi bi-compass me-1"></i>' + esc(t('fazer_agora')) + '</button></div>';
        demoCartao.addEventListener('click', cliqueDemo);
        demoCartao.addEventListener('focusin', function (e) { e.stopPropagation(); });
        D.body.appendChild(demoCartao);
    }
    function barraDemo(pct, anim) {
        var b = demoCartao && demoCartao.querySelector('[data-gpd-bar]'); if (!b) return;
        b.style.transition = 'none'; b.style.width = pct.de + '%';
        if (anim) { void b.offsetWidth; b.style.transition = 'width ' + anim + 'ms linear'; b.style.width = pct.para + '%'; }
    }
    function passoDemo() {
        var st = demoEstado; if (!st || !demoCartao) return;
        W.clearTimeout(st.timer); limparGhost(); limparAnel();
        var g = st.g, total = g.passos.length;
        demoCartao.querySelector('[data-gpd-titulo]').textContent = g.titulo;
        if (st.i >= total) { fimDemo(); return; }
        var p = g.passos[st.i], tela = telaAtual(), el = temAlvo(p) ? acharDemo(p, tela) : null, ex = exemploDe(p.texto);
        if (st.tentPasso !== st.i) { st.tentPasso = st.i; st.tent = 0; }
        if (!el && temAlvo(p) && st.tent < 5) {
            // abre a aba/janela do item (na 1ª tentativa) e espera a animação; tenta de novo até 5 vezes
            var abriu = st.tent === 0 && revelar(p, tela, st);
            if (abriu || st.tent > 0) { st.tent++; st.timer = W.setTimeout(function () { if (demoEstado === st) passoDemo(); }, 450); return; }
        }
        if (!el && temAlvo(p)) el = achar(p, tela); // último recurso: o nome em negrito
        fecharModalDemo(st, el);
        var h = '<div class="gpd-n">' + esc(t('passo_de', { n: st.i + 1, t: total })) + '</div><p class="gpd-text">' + rico(semExemplo(p.texto)) + '</p>';
        if (!el && temAlvo(p)) h += '<div class="gpd-msg">' + esc(t('mais_adiante')) + '</div>';
        if (p.ver) h += '<div class="gpd-see"><strong>' + esc(t('voce_deve_ver')) + '</strong>' + rico(p.ver) + '</div>';
        h += '<div class="gpd-nota">' + esc(t('so_mostra')) + '</div>';
        demoCartao.querySelector('[data-gpd-corpo]').innerHTML = h;
        demoCartao.querySelector('[data-gpd="pausa"]').innerHTML = '<i class="bi ' + (st.pausado ? 'bi-play-fill' : 'bi-pause-fill') + '"></i>';
        var dur = Math.max(5000, Math.min(15000, 3800 + semExemplo(p.texto).length * 38 + (p.ver ? p.ver.length * 20 : 0) + ex.length * 40));
        // o cartão sai de cima do item: sobe para o alto da tela se o item estiver atrás dele
        demoCartao.classList.remove('gpd-cima');
        if (el) {
            // cartão do lado oposto ao item (embaixo, ou no alto quando o item está na metade de baixo à direita); só vale para o passo atual
            var passoCobre = st.i, cobre = function () { try { if (!demoCartao || demoEstado !== st || st.i !== passoCobre) return; var ri = retangulo(el), largura = demoCartao.offsetWidth || 400; var direita = ri.left + ri.width > W.innerWidth - largura - 40; demoCartao.classList.toggle('gpd-cima', direita && ri.top + ri.height / 2 > W.innerHeight / 2); } catch (e2) {} };
            W.setTimeout(cobre, 450);
            destacar(el, rotuloDe(el, p), dur + 400);
            if (ex) W.setTimeout(function () { if (demoEstado === st && st.g.passos[st.i] === p) digitarGhost(el, ex); }, 350);
        }
        st.dur = dur; st.resto = dur;
        var de = st.i / total * 100, para = (st.i + 1) / total * 100;
        if (st.pausado) { barraDemo({ de: de }, 0); return; }
        barraDemo({ de: de, para: para }, dur);
        st.inicio = Date.now();
        st.timer = W.setTimeout(function () { if (demoEstado === st) { st.i++; passoDemo(); } }, dur);
    }
    function fimDemo() {
        var st = demoEstado, g = st.g; limparGhost(); limparAnel(); fecharModalDemo(st);
        barraDemo({ de: 100 }, 0);
        demoCartao.querySelector('[data-gpd-corpo]').innerHTML = '<div class="gpd-n">' + esc(t('fim_demo')) + '</div>'
            + (g.depois ? '<p class="gpd-text"><strong>' + esc(t('depois')) + ':</strong> ' + rico(g.depois) + '</p>' : '')
            + '<div class="gpd-nota">' + esc(t('so_mostra')) + '</div>';
        demoCartao.querySelector('[data-gpd="pausa"]').innerHTML = '<i class="bi bi-play-fill"></i>';
        st.pausado = true;
    }
    function pararDemo(manterCartao) {
        if (demoEstado) { W.clearTimeout(demoEstado.timer); fecharModalDemo(demoEstado); }
        limparGhost(); limparAnel(); demoEstado = null;
        if (!manterCartao && demoCartao) { demoCartao.remove(); demoCartao = null; }
    }
    function cliqueDemo(e) {
        var b = e.target.closest('[data-gpd]'); if (!b || !demoEstado) { if (b && b.getAttribute('data-gpd') === 'fechar') pararDemo(); return; }
        var st = demoEstado, acao = b.getAttribute('data-gpd'), total = st.g.passos.length;
        if (acao === 'fechar') return pararDemo();
        if (acao === 'guia') { var id = st.g.id; pararDemo(); iniciar(id); return; }
        if (acao === 'repetir') { st.i = 0; st.pausado = false; return passoDemo(); }
        if (acao === 'voltar') { st.i = Math.max(0, Math.min(st.i, total) - 1); return passoDemo(); }
        if (acao === 'avancar') { st.i = Math.min(total, st.i + 1); return passoDemo(); }
        if (acao === 'pausa') {
            if (st.i >= total) { st.i = 0; st.pausado = false; return passoDemo(); }
            if (st.pausado) {
                st.pausado = false; b.innerHTML = '<i class="bi bi-pause-fill"></i>';
                var de = (st.i + 1 - st.resto / st.dur) / total * 100;
                barraDemo({ de: de, para: (st.i + 1) / total * 100 }, st.resto);
                st.inicio = Date.now() - (st.dur - st.resto);
                st.timer = W.setTimeout(function () { if (demoEstado === st) { st.i++; passoDemo(); } }, st.resto);
            } else {
                st.pausado = true; W.clearTimeout(st.timer); b.innerHTML = '<i class="bi bi-play-fill"></i>';
                st.resto = Math.max(1200, st.dur - (Date.now() - st.inicio));
                barraDemo({ de: (st.i + 1 - st.resto / st.dur) / total * 100 }, 0);
                // anel continua aceso enquanto pausado
                var p = st.g.passos[st.i], el = p && temAlvo(p) ? acharDemo(p, telaAtual()) : null;
                if (el) destacar(el, rotuloDe(el, p), 600000);
            }
        }
    }

    // ------------------------------------------------------------------ atalhos e ligação com as telas
    function teclado(e) {
        var alvo = e.target, tag = alvo && alvo.tagName ? alvo.tagName.toUpperCase() : '';
        var editando = /^(INPUT|TEXTAREA|SELECT)$/.test(tag) || (alvo && alvo.isContentEditable);
        if (e.key === 'F1' || (e.key === '?' && !editando && !e.ctrlKey && !e.altKey && !e.metaKey)) { e.preventDefault(); alternar(); }
        else if (e.key === 'Escape' && demoEstado) pararDemo();
        else if (e.key === 'Escape' && painel && painel.classList.contains('is-open') && !modaisAbertos().length) fechar();
    }
    function cliqueAjuda(e) {
        var dm = e.target && e.target.closest ? e.target.closest('[data-ajuda-demo]') : null;
        if (dm) { e.preventDefault(); e.stopPropagation(); if (e.stopImmediatePropagation) e.stopImmediatePropagation(); demonstrar(dm.getAttribute('data-ajuda-demo')); return; }
        var b = e.target && e.target.closest ? e.target.closest('[data-open-ajuda],[data-open-transacao-ajuda],[data-open-guide-requisitos]') : null;
        if (!b) return;
        e.preventDefault(); e.stopPropagation(); if (e.stopImmediatePropagation) e.stopImmediatePropagation();
        abrir();
    }
    function ligar(win) {
        try {
            var d = win.document;
            if (!d || !d.documentElement || d.documentElement.getAttribute('data-gpa') === '1') return;
            d.documentElement.setAttribute('data-gpa', '1');
            d.addEventListener('keydown', teclado, true);
            d.addEventListener('click', cliqueAjuda, true);
        } catch (e) {}
    }
    function ligarTodas(win, nivel) {
        if (nivel > 5) return;
        ligar(win);
        try { Array.prototype.forEach.call(win.document.querySelectorAll('iframe'), function (f) { var w = janelaDe(f); if (w) ligarTodas(w, nivel + 1); }); } catch (e) {}
    }
    var ultimaTela = '';
    function vigiar() {
        ligarTodas(W, 0);
        // trocou de tela com um guia aberto: refaz o cartão (Ir para a tela / avanço automático)
        if (estado && cartao && !estado.min) { var ch = telaAtual().chaves.join('|'); if (ch !== ultimaTela) { ultimaTela = ch; renderCartao(); } }
    }

    function iniciarTudo() {
        BASE = String(W.greenPortalBaseUrl || ''); LANG = String(W.greenPortalIdioma || 'pt').substring(0, 2); IDX = LANG === 'en' ? 1 : (LANG === 'es' ? 2 : 0);
        css();
        ligarTodas(W, 0);
        W.setInterval(vigiar, 1000);
        // Cada tela/janela nova (iframe) é ligada assim que carrega, para o ? e o F1 já funcionarem nela.
        var ligarFrame = function (f) { if (f.__gpa) return; f.__gpa = 1; f.addEventListener('load', function () { var w = janelaDe(f); if (w) { ligarTodas(w, 1); try { if (w.MutationObserver) observar(w); } catch (e) {} } }); var w = janelaDe(f); if (w && w.document.readyState !== 'loading') ligarTodas(w, 1); };
        var observar = function (win) {
            try {
                new win.MutationObserver(function (ms) { ms.forEach(function (m) { Array.prototype.forEach.call(m.addedNodes, function (n) { if (n.nodeType !== 1) return; if (n.tagName === 'IFRAME') ligarFrame(n); else if (n.querySelectorAll) Array.prototype.forEach.call(n.querySelectorAll('iframe'), ligarFrame); }); }); }).observe(win.document.documentElement, { childList: true, subtree: true });
                Array.prototype.forEach.call(win.document.querySelectorAll('iframe'), ligarFrame);
            } catch (e) {}
        };
        observar(W);
        estado = lsGet(CHAVE_GUIA);
        if (estado && estado.id) carregar().then(function () { if (guia(estado.id)) renderCartao(); else sair(); }).catch(function () {});
    }
    W.gpAjuda = {
        abrir: function () { abrir(); }, fechar: fechar, iniciar: iniciar, demonstrar: demonstrar, pararDemo: function () { pararDemo(); },
        guias: function () { return carregar().then(function (d) { return d.guias || []; }); },
        telaAtual: function () { return telaAtual(); }
    };
    if (D.readyState === 'loading') D.addEventListener('DOMContentLoaded', iniciarTudo); else iniciarTudo();
}(window));
