<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/components/grid_local.php';
require_once __DIR__ . '/LegislacaoAssociacoes.php';

/*
 * Migração 387 — moldura padrão dos popups da Legislação (URL de pesquisa, Legislações filhas, Alterações, CheckList,
 * Palavras-chave): cabeçalho da lei + grid padrão do portal (components/grid_local.php: barra de ícones com Ajuda, "Buscar
 * no grid", filtro/ordenação por coluna, paginação, ações da linha selecionada na barra) + formulários em modal.
 * <html data-popup-cheio>: o popup fica sempre com 90% de quem chamou (popups.js não encolhe pela altura do conteúdo).
 * JS comum: window.LP.enviar(dados) → Promise do JSON; LP.confirmar(titulo, texto, fn) pela mensagem padrão do portal;
 * LP.gravado(r) avisa o grid de trás (postMessage) e recarrega a página com a mensagem; LP.aviso(texto, tipo).
 */
final class LegislacaoPopup
{
    public static function inicio(string $titulo, array $lei, string $explicacao, string $css = ''): void
    {
        $L = LegislacaoAssociacoes::class;
        echo '<!doctype html><html lang="' . $L::h(IdiomaService::idioma()) . '" data-popup-cheio><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $L::h($titulo . ' — ' . $L::rotulo($lei)) . '</title>'
            . '<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">';
        greenPortalGridLocalHead();
        echo '<style>body{display:flex;flex-direction:column;padding:10px 12px;gap:8px;background:#fff}'
            . 'html,body{height:100%!important;overflow:hidden!important}.lp-cab{flex:0 0 auto}.lp-cab h2{font-size:14px;margin:0;display:flex;align-items:center;gap:6px}.lp-cab .lp-sub{font-size:11px;color:var(--gp-muted,#71877d);margin-top:2px}'
            . '.lp-aviso{flex:0 0 auto;font-size:12px;min-height:0}.lp-aviso:empty{display:none}'
            . '.setup-fragment.grid-local-fragment{height:auto!important;flex:1 1 0!important;min-height:0!important;display:flex;flex-direction:column;margin:0;overflow:hidden!important;padding:0!important;background:transparent}.grid-local-fragment>.crud-toolbar,.grid-local-fragment>.grid-pagination,.grid-local-fragment>.alert{flex:0 0 auto}'
            . '.grid-local-fragment .menu-grid-form{flex:1 1 auto;min-height:0;display:flex;flex-direction:column}.grid-local-fragment .menu-grid-scroll{flex:1 1 auto;min-height:0;max-height:none!important;overflow:auto!important}.grid-local-fragment .menu-batch-grid thead th{position:sticky;top:0;z-index:1}'
            . '.lp-modal .modal-body{font-size:12px}.lp-modal label{font-size:11px;color:#4f675d;margin-bottom:2px}'
            . '.lp-lista{max-height:46vh;overflow:auto;border:1px solid #dce9e2;border-radius:8px}.lp-lista .lp-item{display:flex;gap:8px;align-items:flex-start;padding:6px 8px;border-bottom:1px solid #eef4f1;font-size:12px}.lp-lista .lp-item:last-child{border-bottom:0}.lp-lista .lp-item:hover{background:#f2f8f5}.lp-lista .lp-item .lp-txt{flex:1 1 auto;min-width:0;white-space:pre-wrap}.lp-lista .lp-vazio{padding:16px;text-align:center;color:#71877d}'
            . '.lp-link{word-break:break-all}.lp-pre{white-space:pre-wrap}'
            . $css . '</style></head><body>';
        echo '<div class="lp-cab"><h2><i class="bi bi-journal-text"></i>' . $L::h($titulo) . ' — ' . $L::h($L::rotulo($lei)) . ' <span class="text-muted fw-normal" style="font-size:11px">' . $L::h((string)($lei['CODIGO'] ?? ''))
            . (trim((string)($lei['situacao_da_lei'] ?? '')) !== '' ? ' · ' . $L::h(LegislacaoTextos::valor((string)$lei['situacao_da_lei'])) : '') . '</span></h2>'
            . '<div class="lp-sub">' . $explicacao . '</div></div><div class="lp-aviso" data-lp-aviso></div>';
    }

    /** Botão da barra (só ícone + dica, no padrão). $linha = ação da linha selecionada (data-grid-acao-linha). */
    public static function botao(string $icone, string $dica, string $atributos, bool $linha = false, string $cor = 'outline-primary'): string
    {
        $L = LegislacaoAssociacoes::class;
        return '<button type="button" class="btn btn-sm btn-' . $cor . ' grid-crud-button" ' . $atributos . ($linha ? ' disabled' : '') . ' title="' . $L::h($dica) . '" aria-label="' . $L::h($dica) . '"><i class="bi ' . $L::h($icone) . '"></i></button>';
    }

    public static function fim(int $id, string $tipo, string $js, string $scripts = ''): void
    {
        $L = LegislacaoAssociacoes::class;
        greenPortalGridLocalScripts();
        // textos do JavaScript dos popups (greenT): grupo comum + os da Legislação (classes/LegislacaoTextos.php)
        require_once __DIR__ . '/LegislacaoTextos.php';
        echo IdiomaService::script(['comum', LegislacaoTextos::GRUPO], LegislacaoTextos::js() + (function_exists('greenPortalListaDuplaTextos') ? greenPortalListaDuplaTextos() : []));
        echo '<script src="../assets/js/ajuda-transacao.js?v=1"></script>' . $scripts . '<script>'
            . '(function(){var $=window.jQuery,id=' . $id . ',csrf=' . json_encode($L::csrf()) . ',url=window.location.pathname+window.location.search;'
            . 'var host=function(){try{return window.top&&window.top.GreenPortalPopups?window.top:(window.parent&&window.parent.GreenPortalPopups?window.parent:null);}catch(e){return null;}};'
            . 'var LP=window.LP={tipo:' . json_encode($tipo) . ',id:id,'
            . 'T:function(k,p){return typeof window.greenT==="function"?window.greenT(k,p):p;},'
            . 'esc:function(v){return $("<div>").text(v==null?"":String(v)).html();},'
            . 'aviso:function(t,k){var $a=$("[data-lp-aviso]");if(!t){$a.empty();return;}$a.html(\'<div class="alert alert-\'+(k||"info")+\' py-1 px-2 mb-0">\'+LP.esc(t)+"</div>");},'
            . 'enviar:function(d,$b){if($b)$b.prop("disabled",true);return new Promise(function(ok,falha){$.ajax({url:url,method:"POST",dataType:"json",data:$.extend({ajax:"1",CODLEGISLACAO:id,csrf:csrf},d)})'
            . '.done(function(r){if(r&&r.ok)ok(r);else{LP.aviso((r&&r.message)||LP.T("comum.msg.nao_concluido","Não foi possível concluir."),"danger");falha(r);}})'
            . '.fail(function(x){LP.aviso((x.responseJSON&&x.responseJSON.message)||LP.T("comum.msg.nao_concluido","Não foi possível concluir."),"danger");falha(x);})'
            . '.always(function(){if($b)$b.prop("disabled",false);});});},'
            . 'confirmar:function(titulo,texto,fn){var h=host();if(h)h.GreenPortalPopups.mensagem({titulo:titulo,mensagem:texto,icone:"bi-exclamation-triangle text-warning",confirmar:fn});else fn();},'
            . 'gravado:function(r){try{var p=window.parent;if(r&&r.resumo&&p&&p!==window){p.postMessage(r.resumo,"*");for(var i=0;i<p.frames.length;i++)if(p.frames[i]!==window)p.frames[i].postMessage(r.resumo,"*");}}catch(e){}try{sessionStorage.setItem("lp-msg",(r&&r.message)||"");}catch(e){}window.location.reload();},'
            . 'linha:function($b){return $b.closest("tr");}};'
            . 'try{var m=sessionStorage.getItem("lp-msg");if(m){sessionStorage.removeItem("lp-msg");LP.aviso(m,"success");setTimeout(function(){LP.aviso("");},6000);}}catch(e){}'
            . '}());</script><script>' . $js . '</script></body></html>';
    }
}
