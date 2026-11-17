(function ($) {
    'use strict';
    // Assistente da aplicação ("Como posso ajudar?"): painel lateral do portal. Conversa com api/assistente_ia.php.
    var $painel = $('#assistentePainel');
    if (!$painel.length || !window.bootstrap) return;

    function T(chave, padrao) { return typeof window.greenT === 'function' ? window.greenT('assistente.js.' + chave, padrao) : padrao; }
    function F(chave, padrao, valores) { var texto = T(chave, padrao); Object.keys(valores || {}).forEach(function (k) { texto = texto.split('{' + k + '}').join(String(valores[k])); }); return texto; }
    function esc(texto) { return String(texto == null ? '' : texto).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

    var api = String($painel.attr('data-api') || ''), csrf = String($painel.attr('data-csrf') || '');
    var $lista = $painel.find('[data-assistente-mensagens]'), $form = $painel.find('[data-assistente-form]'), $campo = $form.find('textarea'), $enviar = $form.find('[type="submit"]');
    var $uso = $painel.find('[data-assistente-uso]'), $tela = $painel.find('[data-assistente-tela]');
    var estado = { carregado: false, codconversa: null, enviando: false, usados: 0, limite: 0, telaSugestoes: null, solicitacoes: false };
    // Etapa 2: respostas destas categorias podem virar solicitação de melhoria (com confirmação do usuário).
    var REGISTRAVEIS = ['FUNCIONALIDADE_INEXISTENTE', 'MELHORIA', 'INTEGRACAO', 'RELATORIO', 'IA', 'DIFICULDADE_USO'];
    var URL_MINHAS = '/principal/minhas_solicitacoes.php', ABA_MINHAS = 'menu-administracao_minhas_solicitacoes';

    /** Tela aberta no portal: código do menu da aba ativa (as abas do menu têm id "menu-<codigo>"). */
    function telaAtual() {
        var $aba = $('.workspace-tab.active').first(), id = String($aba.attr('data-tab-target') || '');
        return { codigo: id.indexOf('menu-') === 0 ? id.slice(5).toUpperCase() : '', nome: $.trim($aba.find('.workspace-tab-label').text()) };
    }

    function post(dados) {
        return $.ajax({ url: api, method: 'POST', dataType: 'json', data: $.extend({ csrf: csrf, transacao: telaAtual().codigo }, dados) });
    }

    function mensagemErro(xhr) {
        return (xhr && xhr.responseJSON && xhr.responseJSON.message) || T('erro_conexao', 'Não foi possível falar com o assistente. Verifique a conexão e tente novamente.');
    }

    /** Texto da IA → HTML seguro: escapa tudo e só depois aplica negrito e quebras de linha. */
    function formatar(texto) {
        return esc(texto).replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/\r?\n/g, '<br>');
    }

    function atualizarUso() {
        $uso.text(estado.limite ? F('uso', '{u} de {l} perguntas hoje', { u: estado.usados, l: estado.limite }) : '');
    }

    function rolar() { $lista.scrollTop($lista[0].scrollHeight); }

    function boasVindas(sugestoes) {
        $lista.find('[data-assistente-boas-vindas]').remove();
        var $b = $('<div class="assistente-boas-vindas" data-assistente-boas-vindas></div>');
        $b.append($('<p></p>').text(T('boas_vindas', 'Olá! Pergunte como fazer algo no sistema, onde fica uma tela ou o que significa uma mensagem. Eu só sugiro telas que o seu perfil pode abrir.')));
        if (sugestoes && sugestoes.length) {
            var $s = $('<div class="assistente-sugestoes"></div>');
            sugestoes.forEach(function (texto) { $s.append($('<button type="button" class="assistente-sugestao" data-assistente-sugestao></button>').text(texto)); });
            $b.append($('<small class="assistente-sugestoes-titulo"></small>').text(T('sugestoes', 'Sugestões'))).append($s);
        }
        $lista.append($b);
    }

    function adicionarUsuario(texto) {
        $lista.find('[data-assistente-boas-vindas]').remove();
        $lista.append($('<div class="assistente-msg assistente-msg-usuario"></div>').append($('<div class="assistente-balao"></div>').text(texto)));
        rolar();
    }

    function adicionarAssistente(m) {
        var $m = $('<div class="assistente-msg assistente-msg-ia"></div>').attr('data-codmensagem', m.codmensagem || '');
        var $balao = $('<div class="assistente-balao"></div>').html(formatar(m.texto || m.resposta || ''));
        $m.append($balao);
        if (m.acoes && m.acoes.length) {
            var $acoes = $('<div class="assistente-acoes"></div>');
            m.acoes.forEach(function (a) {
                $acoes.append($('<button type="button" class="btn btn-sm assistente-abrir" data-assistente-abrir></button>')
                    .attr({ 'data-aba': a.aba, 'data-url': a.url, 'data-nome': a.nome, title: a.caminho })
                    .append('<i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> ').append($('<span></span>').text(F('abrir', 'Abrir {tela}', { tela: a.nome }))));
            });
            $m.append($acoes);
        }
        // Ajuda interativa (migração 309): o guia passo a passo acompanha a pessoa na tela ("Mostre-me").
        if (m.guias && m.guias.length && window.gpAjuda) {
            var $guias = $('<div class="assistente-acoes"></div>');
            m.guias.forEach(function (g) {
                $guias.append($('<button type="button" class="btn btn-sm assistente-abrir" data-assistente-guia></button>').attr('data-guia', g.id)
                    .append('<i class="bi bi-compass" aria-hidden="true"></i> ').append($('<span></span>').text(F('comecar_guia', 'Começar o guia: {guia}', { guia: g.titulo }))));
            });
            $m.append($guias);
        }
        if (m.funcionalidade_inexistente && m.necessidade) {
            $m.append($('<div class="assistente-necessidade"></div>').append('<i class="bi bi-lightbulb" aria-hidden="true"></i> ').append($('<span></span>').text(F('necessidade', 'Necessidade anotada para a equipe do produto: {texto}', { texto: m.necessidade }))));
        }
        if (estado.solicitacoes && m.codmensagem && (m.funcionalidade_inexistente || REGISTRAVEIS.indexOf(String(m.categoria || '')) >= 0)) {
            $m.append(blocoSolicitacao(m.codsolicitacao));
        }
        if (m.codmensagem) {
            var $av = $('<div class="assistente-avaliacao"></div>');
            $av.append($('<button type="button" data-assistente-avaliar="1"><i class="bi bi-hand-thumbs-up"></i></button>').attr({ title: T('util', 'Ajudou'), 'aria-label': T('util', 'Ajudou') }));
            $av.append($('<button type="button" data-assistente-avaliar="-1"><i class="bi bi-hand-thumbs-down"></i></button>').attr({ title: T('nao_util', 'Não ajudou'), 'aria-label': T('nao_util', 'Não ajudou') }));
            if (m.avaliacao) $av.find('[data-assistente-avaliar="' + m.avaliacao + '"]').addClass('ativo');
            $m.append($av);
        }
        $lista.append($m);
        rolar();
    }

    /** Registrada: mostra o número; senão, o botão que abre o formulário de confirmação. */
    function blocoSolicitacao(codsolicitacao) {
        var $b = $('<div class="assistente-solicitacao" data-assistente-solicitacao></div>');
        if (codsolicitacao) {
            $b.addClass('registrada').append('<i class="bi bi-check2-circle" aria-hidden="true"></i> ')
                .append($('<span></span>').text(F('sol_registrada', 'Registrada como solicitação #{n}.', { n: codsolicitacao })))
                .append(' ').append($('<button type="button" class="btn btn-link btn-sm p-0" data-assistente-minhas></button>').text(T('sol_ver', 'Ver minhas solicitações')));
        } else {
            $b.append($('<button type="button" class="btn btn-sm assistente-registrar" data-assistente-registrar></button>').append('<i class="bi bi-lightbulb" aria-hidden="true"></i> ').append($('<span></span>').text(T('sol_registrar', 'Registrar como sugestão'))));
        }
        return $b;
    }

    function formularioSolicitacao(r) {
        var $f = $('<form class="assistente-sol-form" data-assistente-sol-form></form>');
        $f.append($('<p class="assistente-sol-ajuda"></p>').text(T('sol_revise', 'Revise o texto antes de registrar. A equipe do produto vai ler exatamente isto.')));
        $f.append($('<label></label>').text(T('sol_titulo', 'Título'))).append($('<input type="text" class="form-control form-control-sm" name="titulo" maxlength="200" required>').val(r.titulo || ''));
        $f.append($('<label></label>').text(T('sol_descricao', 'Descrição'))).append($('<textarea class="form-control form-control-sm" name="descricao" rows="4" maxlength="4000" required></textarea>').val(r.descricao || ''));
        $f.append($('<label></label>').text(T('sol_beneficio', 'Para que você precisa'))).append($('<input type="text" class="form-control form-control-sm" name="beneficio" maxlength="500">').val(r.beneficio || ''));
        var $p = $('<select class="form-select form-select-sm" name="prioridade_usuario"></select>');
        [['BAIXA', T('sol_baixa', 'Baixa')], ['MEDIA', T('sol_media', 'Média')], ['ALTA', T('sol_alta', 'Alta')]].forEach(function (o) { $p.append($('<option></option>').val(o[0]).text(o[1])); });
        $p.val(r.prioridade_usuario || 'MEDIA');
        $f.append($('<label></label>').text(T('sol_prioridade', 'Importância para você'))).append($p);
        $f.append($('<input type="hidden" name="categoria">').val(r.categoria || 'MELHORIA')).append($('<input type="hidden" name="modulo">').val(r.modulo || ''));
        $f.append($('<div class="assistente-sol-botoes"></div>')
            .append($('<button type="button" class="btn btn-sm btn-outline-secondary" data-assistente-sol-cancelar></button>').text(T('sol_cancelar', 'Cancelar')))
            .append($('<button type="submit" class="btn btn-sm btn-success"></button>').text(T('sol_confirmar', 'Confirmar registro'))));
        return $f;
    }

    function abrirMinhas() {
        var $menu = $('[data-open-tab]').filter(function () { return String($(this).attr('data-open-tab')) === ABA_MINHAS; }).first();
        if ($menu.length) $menu.trigger('click');
        else if (typeof window.greenPortalOpenTab === 'function') window.greenPortalOpenTab(ABA_MINHAS, (window.greenPortalBaseUrl || '') + URL_MINHAS, T('sol_minhas', 'Minhas solicitações'));
    }

    function adicionarErro(texto) {
        $lista.append($('<div class="assistente-msg assistente-msg-erro"></div>').append($('<div class="assistente-balao"></div>').append('<i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ').append($('<span></span>').text(texto))));
        rolar();
    }

    function digitando(ativo) {
        $lista.find('[data-assistente-digitando]').remove();
        if (ativo) { $lista.append('<div class="assistente-msg assistente-msg-ia" data-assistente-digitando><div class="assistente-balao assistente-digitando"><span></span><span></span><span></span></div></div>'); rolar(); }
    }

    function carregarEstado() {
        var tela = telaAtual();
        $tela.text(tela.nome ? F('tela', 'Tela: {tela}', { tela: tela.nome }) : '');
        if (estado.carregado) {
            // Conversa vazia: as sugestões acompanham a tela aberta.
            if (!estado.codconversa && estado.telaSugestoes !== tela.codigo) post({ action: 'nova' }).done(function (r) { estado.telaSugestoes = tela.codigo; boasVindas(r.sugestoes || []); });
            return;
        }
        $lista.empty();
        digitando(true);
        post({ action: 'estado' }).done(function (r) {
            digitando(false);
            estado.carregado = true; estado.solicitacoes = !!r.solicitacoes; $painel.find('[data-assistente-minhas-topo]').prop('hidden', !estado.solicitacoes); estado.usados = r.usados || 0; estado.limite = r.limite || 0; estado.codconversa = r.codconversa || null; estado.telaSugestoes = tela.codigo;
            atualizarUso();
            if (r.mensagens && r.mensagens.length) r.mensagens.forEach(function (m) { if (m.papel === 'USUARIO') adicionarUsuario(m.texto); else adicionarAssistente(m); });
            else boasVindas(r.sugestoes || []);
        }).fail(function (xhr) { digitando(false); adicionarErro(mensagemErro(xhr)); });
    }

    function perguntar(texto) {
        texto = $.trim(texto);
        if (!texto || estado.enviando) return;
        estado.enviando = true; $enviar.prop('disabled', true); $campo.val('');
        adicionarUsuario(texto);
        digitando(true);
        post({ action: 'perguntar', pergunta: texto, codconversa: estado.codconversa || '' })
            .done(function (r) {
                digitando(false);
                if (!r || !r.ok) { adicionarErro((r && r.message) || mensagemErro()); return; }
                estado.codconversa = r.codconversa; estado.usados = r.usados; estado.limite = r.limite;
                atualizarUso();
                adicionarAssistente(r);
            })
            .fail(function (xhr) { digitando(false); adicionarErro(mensagemErro(xhr)); })
            .always(function () { estado.enviando = false; $enviar.prop('disabled', false); $campo.trigger('focus'); });
    }

    // O botão do cabeçalho abre/fecha o painel por aqui: o main.js converte elementos com title em tooltip
    // (data-bs-toggle="tooltip"), o que anularia um data-bs-toggle="offcanvas" no próprio botão.
    $(document).on('click', '[data-assistente-alternar]', function (e) { e.preventDefault(); window.bootstrap.Offcanvas.getOrCreateInstance($painel[0]).toggle(); });
    $painel.on('show.bs.offcanvas', carregarEstado);
    // Troca de aba com o painel aberto: o subtítulo acompanha a tela.
    $(document).on('click', '.workspace-tab, [data-open-tab], [data-close-tab]', function () {
        window.setTimeout(function () { var tela = telaAtual(); $tela.text(tela.nome ? F('tela', 'Tela: {tela}', { tela: tela.nome }) : ''); }, 50);
    });
    $painel.on('shown.bs.offcanvas', function () { $campo.trigger('focus'); });
    $form.on('submit', function (e) { e.preventDefault(); perguntar($campo.val()); });
    $campo.on('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $form.trigger('submit'); } });
    $painel.on('click', '[data-assistente-sugestao]', function () { perguntar($(this).text()); });
    $painel.on('click', '[data-assistente-nova]', function () {
        var anterior = estado.codconversa;
        estado.codconversa = null; $lista.empty();
        post({ action: 'nova', codconversa: anterior || '' }).done(function (r) { estado.telaSugestoes = telaAtual().codigo; boasVindas(r.sugestoes || []); }).fail(function (xhr) { adicionarErro(mensagemErro(xhr)); });
        $campo.trigger('focus');
    });
    $painel.on('click', '[data-assistente-avaliar]', function () {
        var $b = $(this), nota = Number($b.attr('data-assistente-avaliar')), cod = $b.closest('[data-codmensagem]').attr('data-codmensagem');
        if ($b.hasClass('ativo')) nota = 0;
        $b.siblings().removeClass('ativo'); $b.toggleClass('ativo', nota !== 0);
        post({ action: 'avaliar', codmensagem: cod, nota: nota });
    });
    // Etapa 2 — registrar a resposta como solicitação de melhoria.
    $painel.on('click', '[data-assistente-registrar]', function () {
        var $bloco = $(this).closest('[data-assistente-solicitacao]'), cod = $bloco.closest('[data-codmensagem]').attr('data-codmensagem');
        $bloco.empty().append($('<div class="assistente-sol-carregando"></div>').text(T('sol_redigindo', 'Redigindo a solicitação...')));
        post({ action: 'rascunho_solicitacao', codmensagem: cod }).done(function (r) {
            if (r.ja_registrada) { $bloco.replaceWith(blocoSolicitacao(r.codsolicitacao)); return; }
            $bloco.empty().append(formularioSolicitacao(r.rascunho || {})); rolar();
            $bloco.find('input[name="titulo"]').trigger('focus');
        }).fail(function (xhr) { $bloco.replaceWith(blocoSolicitacao(null)); adicionarErro(mensagemErro(xhr)); });
    });
    $painel.on('click', '[data-assistente-sol-cancelar]', function () { $(this).closest('[data-assistente-solicitacao]').replaceWith(blocoSolicitacao(null)); });
    $painel.on('submit', '[data-assistente-sol-form]', function (e) {
        e.preventDefault();
        var $f = $(this), $bloco = $f.closest('[data-assistente-solicitacao]'), cod = $bloco.closest('[data-codmensagem]').attr('data-codmensagem'), dados = { action: 'registrar_solicitacao', codmensagem: cod };
        $f.serializeArray().forEach(function (c) { dados[c.name] = c.value; });
        $f.find('button').prop('disabled', true);
        post(dados).done(function (r) {
            $bloco.replaceWith(blocoSolicitacao(r.codsolicitacao));
        }).fail(function (xhr) { $f.find('button').prop('disabled', false); $f.find('.assistente-sol-erro').remove(); $f.prepend($('<div class="assistente-sol-erro"></div>').text(mensagemErro(xhr))); });
    });
    $painel.on('click', '[data-assistente-minhas], [data-assistente-minhas-topo]', function () {
        abrirMinhas();
        if (window.innerWidth < 1100) window.bootstrap.Offcanvas.getOrCreateInstance($painel[0]).hide();
    });
    // "Começar o guia": fecha o assistente e abre o modo guiado (assets/js/ajuda.js).
    $painel.on('click', '[data-assistente-guia]', function () {
        var id = String($(this).attr('data-guia') || '');
        if (!id || !window.gpAjuda) return;
        try { bootstrap.Offcanvas.getOrCreateInstance($painel[0]).hide(); } catch (e) {}
        window.gpAjuda.iniciar(id);
    });
    // "Abrir tela": usa o próprio item do menu (mesma aba, mesmo título); sem ele, abre pela URL do catálogo.
    $painel.on('click', '[data-assistente-abrir]', function () {
        var $b = $(this), aba = String($b.attr('data-aba') || ''), $menu = $('[data-open-tab]').filter(function () { return String($(this).attr('data-open-tab')) === aba; }).first();
        if ($menu.length) $menu.trigger('click');
        else if (typeof window.greenPortalOpenTab === 'function') window.greenPortalOpenTab(aba, (window.greenPortalBaseUrl || '') + String($b.attr('data-url') || ''), String($b.attr('data-nome') || ''));
        if (window.innerWidth < 1100) window.bootstrap.Offcanvas.getOrCreateInstance($painel[0]).hide();
    });
}(jQuery));
