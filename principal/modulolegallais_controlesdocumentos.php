<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
$incoming = json_decode((string)($_GET['filtros'] ?? '{}'), true);
$incoming = is_array($incoming) ? $incoming : [];
$filters = [];
foreach (['CODASPECTOSOCIAL', 'CODIMPACTOSOCIAL'] as $field) if (isset($incoming[$field]) && trim((string)$incoming[$field]) !== '') $filters[$field] = trim((string)$incoming[$field]);
if (isset($incoming['CODUNIDADE']) && trim((string)$incoming['CODUNIDADE']) !== '') $filters['codunidade'] = trim((string)$incoming['CODUNIDADE']);
$_GET['filtros'] = json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

ManutencaoLegalCadastroGrid::render([
    'menu' => 'LAIS_CONTROLES_DOCUMENTOS', 'title' => ['pt' => 'LAIS - Controles e documentos', 'en' => 'LAIS - Controls and documents', 'es' => 'LAIS - Controles y documentos'],
    'transaction' => 'moduloleglais_controlesdocumentos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT', 'table' => 'lais_documentosxaspectos', 'view' => 'vw_lais_controles_documentos_grid', 'primary' => 'CODDOCUMENTO',
    'context_or_fields' => ['CODASPECTOSOCIAL', 'CODIMPACTOSOCIAL'], 'button_options' => ['save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true],
    'legacy_order' => ['CODIGO', 'TITULO', 'ARQUIVO', 'CODDOCUMENTO', 'CODASPECTOSOCIAL', 'CODIMPACTOSOCIAL', 'codunidade'], 'legacy_fixed' => true,
    'fields' => [
        'CODIGO' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'readonly' => true, 'filter' => 'text', 'width' => 180],
        'TITULO' => ['label' => ['pt' => 'Título', 'en' => 'Title', 'es' => 'Título'], 'readonly' => true, 'filter' => 'text', 'width' => 260],
        'ARQUIVO' => ['label' => ['pt' => 'Arquivo', 'en' => 'File', 'es' => 'Archivo'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        'CODDOCUMENTO' => ['label' => ['pt' => 'Código técnico', 'en' => 'Technical code', 'es' => 'Código técnico'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        'CODASPECTOSOCIAL' => ['label' => ['pt' => 'Aspecto social', 'en' => 'Social aspect', 'es' => 'Aspecto social'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        'CODIMPACTOSOCIAL' => ['label' => ['pt' => 'Impacto social', 'en' => 'Social impact', 'es' => 'Impacto social'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        'codunidade' => ['label' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
    ],
]);
?>
<script>
(function(){
  var fragment=document.querySelector('.cadastro-legal-fragment'); if(!fragment)return;
  var actions=fragment.querySelector('.crud-actions'); if(!actions)return;
  var button=document.createElement('button'); button.type='button'; button.className='btn btn-sm btn-outline-success grid-crud-button'; button.disabled=true; button.title='Gerenciar vínculos de documentos'; button.setAttribute('data-controle-documentos-vinculos','1'); button.innerHTML='<i class="bi bi-link-45deg me-1"></i>Vínculos'; actions.appendChild(button);
  var selected=null;
  fragment.querySelector('.menu-batch-grid')?.addEventListener('click',function(e){var row=e.target.closest('tbody tr');if(!row)return;selected=row;button.disabled=false;});
  button.addEventListener('click',function(){if(!selected)return;var val=function(c){var el=selected.querySelector('[data-column="'+c+'"] input,[data-column="'+c+'"] select');return el?el.value.trim():'';};var doc=val('CODDOCUMENTO')||selected.getAttribute('data-key'),unit=val('codunidade'),aspect=val('CODASPECTOSOCIAL'),impact=val('CODIMPACTOSOCIAL');var url=(window.top.greenPortalBaseUrl||'')+'/principal/controle_documentos_vinculos.php?coddocumento='+encodeURIComponent(doc)+'&codunidade='+encodeURIComponent(unit)+'&codaspecto='+encodeURIComponent(aspect)+'&codimpacto='+encodeURIComponent(impact);var host=window.top||window;if(host.GreenPortalPopups&&host.jQuery){var frame=host.jQuery('<iframe>',{title:'Vínculos de documentos',style:'width:100%;height:100%;border:0;display:block;'}).attr('src',url);host.GreenPortalPopups.transacaoIndependente({titulo:'Vínculos de documentos',conteudo:frame,amplo:true,redimensionavel:true});}else window.open(url,'_blank');});
}());
</script>
