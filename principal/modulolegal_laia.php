<?php
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
// Textos de Administração › Textos e idiomas (cadastro automático ao abrir como ADMINISTRADOR).
$tt = static fn(string $chave, array $padrao): string => htmlspecialchars(IdiomaService::t($chave, $padrao), ENT_QUOTES, 'UTF-8');

ManutencaoLegalCadastroGrid::render([
    'menu' => 'LAIA',
    'title' => ['pt' => 'LAIA', 'en' => 'LAIA', 'es' => 'LAIA'],
    'transaction' => 'modulolegal_laia',
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'fragment_class' => 'modulo-laia-grid-fragment',
    'table' => 'laia',
    'view' => 'vw_laia_grid',
    'primary' => 'CODLAIA',
    'primary_auto' => true,
    'legacy_order' => ['linha','CODUNIDADE','CODAREA','CODATIVIDADE','detalhamento','CODASPECTO','SITUACAO','TEMPORALIDADE','INFLUENCIA','CLASSIFICACAO','CODDESTINO','CODIMPACTO','ESCALA','SEVERIDADE','PROBABILIDADE_FREQUENCIA','RESULTADO','DISPOSICAOATENUANTE','FILTROB','FILTROC','FILTROD'],
    'legacy_search' => ['#select_filter','#select_filter','#select_filter','#text_filter','#text_filter','#text_filter','#select_filter','#select_filter','#select_filter','#select_filter','#select_filter','#select_filter','#select_filter','#select_filter','#select_filter','#select_filter','#text_filter','#select_filter','#select_filter','#select_filter'],
    'legacy_labels' => ['', 'Unidade','Área','Fonte/Atividade/Equipamento','Detalhamento','Aspecto','Situação N/A/E','Tempo A/P/F','Influência D/I','Classe A/B','Destino','Impacto','Escala','Severidade','Probabilidade de Frequência','Total','Disposição ou Atenuante','Aspectos Significativos','Atendimento Legislação','Visão Partes Interessadas'],
    'legacy_widths' => [30,160,220,220,300,300,100,100,100,100,120,120,100,100,100,100,300,100,100,100],
    'legacy_sort' => ['str','strc','strc','strc','str','strc','strc','strc','strc','strc','strc','strc','str','str','str','str','str','str','str','str'],
    'legacy_types' => ['cntr','coro','coro','coro','txt','coro','coro','coro','coro','coro','coro','coro','ed','ed','ed','ro','txt','ro','ed','ed'],
    'legacy_fixed' => true,
    'extras' => '<button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-url="/principal/modulolegal_laiarequisitoslegais.php" data-context-fields="CODASPECTO,CODIMPACTO,CODATIVIDADE,CODUNIDADE" data-context-title="' . $tt('modulolegal_laia.botao.requisitos', ['pt' => 'Requisitos legais', 'en' => 'Legal requirements', 'es' => 'Requisitos legales']) . '" disabled title="' . $tt('modulolegal_laia.botao.requisitos', ['pt' => 'Requisitos legais', 'en' => 'Legal requirements', 'es' => 'Requisitos legales']) . '"><i class="bi bi-journal-check"></i></button><button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-url="/principal/modulolegal_controles.php" data-context-fields="CODASPECTO,CODIMPACTO,CODATIVIDADE,CODUNIDADE" data-context-title="' . $tt('modulolegal_laia.botao.controles', ['pt' => 'Controles e documentos', 'en' => 'Controls and documents', 'es' => 'Controles y documentos']) . '" disabled title="' . $tt('modulolegal_laia.botao.controles', ['pt' => 'Controles e documentos', 'en' => 'Controls and documents', 'es' => 'Controles y documentos']) . '"><i class="bi bi-file-earmark-text"></i></button><button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-url="/principal/modulolegal_objetivosmetas.php" data-context-fields="CODASPECTO,CODIMPACTO,CODATIVIDADE,CODUNIDADE" data-context-title="' . $tt('modulolegal_laia.botao.objetivos', ['pt' => 'Objetivos e metas', 'en' => 'Objectives and targets', 'es' => 'Objetivos y metas']) . '" disabled title="' . $tt('modulolegal_laia.botao.objetivos', ['pt' => 'Objetivos e metas', 'en' => 'Objectives and targets', 'es' => 'Objetivos y metas']) . '"><i class="bi bi-bullseye"></i></button><button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-url="/principal/modulolegallaia_inventario.php" data-context-fields="CODLAIA,CODUNIDADE" data-context-title="' . $tt('modulolegal_laia.botao.inventario', ['pt' => 'Inventário', 'en' => 'Inventory', 'es' => 'Inventario']) . '" disabled title="' . $tt('modulolegal_laia.botao.inventario', ['pt' => 'Inventário', 'en' => 'Inventory', 'es' => 'Inventario']) . '"><i class="bi bi-box-seam"></i></button>',
    'fields' => [
        'linha' => ['label' => ['pt' => '', 'en' => '', 'es' => ''], 'hidden' => true, 'readonly' => true, 'filter' => 'none', 'width' => 30],
        'CODUNIDADE' => ['label' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'], 'filter' => 'select', 'width' => 160, 'lazy_foreign' => false],
        'CODAREA' => ['label' => ['pt' => 'Área', 'en' => 'Area', 'es' => 'Área'], 'filter' => 'select', 'width' => 220, 'lazy_foreign' => false],
        'CODATIVIDADE' => ['label' => ['pt' => 'Fonte/Atividade/Equipamento', 'en' => 'Source/Activity/Equipment', 'es' => 'Fuente/Actividad/Equipo'], 'filter' => 'select', 'width' => 220, 'lazy_foreign' => false],
        'detalhamento' => ['label' => ['pt' => 'Detalhamento', 'en' => 'Details', 'es' => 'Detalle'], 'type' => 'textarea', 'filter' => 'text', 'width' => 300],
        'CODASPECTO' => ['label' => ['pt' => 'Aspecto', 'en' => 'Aspect', 'es' => 'Aspecto'], 'filter' => 'select', 'width' => 300, 'lazy_foreign' => false],
        'SITUACAO' => ['label' => ['pt' => 'Situação N/A/E', 'en' => 'Situation N/A/E', 'es' => 'Situación N/A/E'], 'options' => ['NORMAL'=>['pt'=>'Normal','en'=>'Normal','es'=>'Normal'],'ANORMAL'=>['pt'=>'Anormal','en'=>'Abnormal','es'=>'Anormal'],'EMERGENCIAL'=>['pt'=>'Emergencial','en'=>'Emergency','es'=>'Emergencia']], 'filter' => 'select', 'width' => 100],
        'TEMPORALIDADE' => ['label' => ['pt' => 'Tempo A/P/F', 'en' => 'Time P/P/F', 'es' => 'Tiempo A/P/F'], 'options' => ['ATUAL'=>['pt'=>'Atual','en'=>'Present','es'=>'Actual'],'PASSADO'=>['pt'=>'Passado','en'=>'Past','es'=>'Pasado'],'FUTURO'=>['pt'=>'Futuro','en'=>'Future','es'=>'Futuro']], 'filter' => 'select', 'width' => 100],
        'INFLUENCIA' => ['label' => ['pt' => 'Influência D/I', 'en' => 'Influence D/I', 'es' => 'Influencia D/I'], 'options' => ['DIRETO'=>['pt'=>'Direto','en'=>'Direct','es'=>'Directo'],'INDIRETO'=>['pt'=>'Indireto','en'=>'Indirect','es'=>'Indirecto']], 'filter' => 'select', 'width' => 100],
        'CLASSIFICACAO' => ['label' => ['pt' => 'Classe A/B', 'en' => 'Class A/B', 'es' => 'Clase A/B'], 'options' => ['ADVERSO'=>['pt'=>'Adverso','en'=>'Adverse','es'=>'Adverso'],'ANORMAL'=>['pt'=>'Anormal','en'=>'Abnormal','es'=>'Anormal'],'BENÉFICO'=>['pt'=>'Benéfico','en'=>'Beneficial','es'=>'Benéfico']], 'filter' => 'select', 'width' => 100],
        'CODDESTINO' => ['label' => ['pt' => 'Destino', 'en' => 'Destination', 'es' => 'Destino'], 'filter' => 'select', 'width' => 120],
        'CODIMPACTO' => ['label' => ['pt' => 'Impacto', 'en' => 'Impact', 'es' => 'Impacto'], 'filter' => 'select', 'width' => 120, 'lazy_foreign' => false],
        'ESCALA' => ['label' => ['pt' => 'Escala', 'en' => 'Scale', 'es' => 'Escala'], 'filter' => 'select', 'width' => 100],
        'SEVERIDADE' => ['label' => ['pt' => 'Severidade', 'en' => 'Severity', 'es' => 'Severidad'], 'filter' => 'select', 'width' => 100],
        'PROBABILIDADE_FREQUENCIA' => ['label' => ['pt' => 'Probabilidade de Frequência', 'en' => 'Probability/Frequency', 'es' => 'Probabilidad/Frecuencia'], 'filter' => 'select', 'width' => 100],
        'RESULTADO' => ['label' => ['pt' => 'Total', 'en' => 'Total', 'es' => 'Total'], 'readonly' => true, 'filter' => 'select', 'width' => 100],
        'DISPOSICAOATENUANTE' => ['label' => ['pt' => 'Disposição ou Atenuante', 'en' => 'Disposition or mitigation', 'es' => 'Disposición o atenuante'], 'type' => 'textarea', 'filter' => 'text', 'width' => 300],
        'FILTROB' => ['label' => ['pt' => 'Aspectos Significativos', 'en' => 'Significant aspects', 'es' => 'Aspectos significativos'], 'readonly' => true, 'filter' => 'text', 'width' => 100],
        'FILTROC' => ['label' => ['pt' => 'Atendimento Legislação', 'en' => 'Legal compliance', 'es' => 'Cumplimiento legal'], 'filter' => 'select', 'width' => 100],
        'FILTROD' => ['label' => ['pt' => 'Visão Partes Interessadas', 'en' => 'Interested parties view', 'es' => 'Visión de partes interesadas'], 'filter' => 'select', 'width' => 100],
        'CODLAIA' => ['label' => ['pt' => 'Código técnico', 'en' => 'Technical code', 'es' => 'Código técnico'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
    ],
]);

// Barra de botões em grupos (Operações | Correlacionamentos | Avaliação), com dica nativa.
require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.modulo-laia-grid-fragment', [
    'correlacionamentos' => ['[data-context-url$="modulolegal_laiarequisitoslegais.php"]', '[data-context-url$="modulolegal_controles.php"]', '[data-context-url$="modulolegallaia_inventario.php"]'],
    'avaliacao' => ['[data-context-url$="modulolegal_objetivosmetas.php"]', '[data-context-id^="questionario-"]'],
]);
?>
<script src="../assets/js/associacoes-contagem.js?v=6"></script>
