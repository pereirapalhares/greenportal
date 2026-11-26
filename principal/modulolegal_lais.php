<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
// Textos de Administração › Textos e idiomas (cadastro automático ao abrir como ADMINISTRADOR).
$tt = static fn(string $chave, array $padrao): string => htmlspecialchars(IdiomaService::t($chave, $padrao), ENT_QUOTES, 'UTF-8');

$contextFields = 'CODLAIS,CODUNIDADE,CODASPECTOSOCIAL,CODIMPACTOSOCIAL,CODPARTE';
$extras = '<button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-id="lais-requisitos" data-context-url="/principal/modulolegallais_requisitoslegais.php" data-context-fields="' . $contextFields . '" data-context-title="' . $tt('modulolegal_lais.botao.requisitos', ['pt' => 'Requisitos legais', 'en' => 'Legal requirements', 'es' => 'Requisitos legales']) . '" disabled title="' . $tt('modulolegal_lais.botao.requisitos', ['pt' => 'Requisitos legais', 'en' => 'Legal requirements', 'es' => 'Requisitos legales']) . '"><i class="bi bi-journal-check"></i></button>'
    . '<button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-id="lais-controles" data-context-url="/principal/modulolegallais_controlesdocumentos.php" data-context-fields="' . $contextFields . '" data-context-title="' . $tt('modulolegal_lais.botao.controles', ['pt' => 'Controles e documentos', 'en' => 'Controls and documents', 'es' => 'Controles y documentos']) . '" disabled title="' . $tt('modulolegal_lais.botao.controles', ['pt' => 'Controles e documentos', 'en' => 'Controls and documents', 'es' => 'Controles y documentos']) . '"><i class="bi bi-file-earmark-text"></i></button>'
    . '<button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-id="lais-objetivos" data-context-url="/principal/modulolegallais_objetivosmetas.php" data-context-fields="' . $contextFields . '" data-context-title="' . $tt('modulolegal_lais.botao.objetivos', ['pt' => 'Objetivos e metas', 'en' => 'Objectives and targets', 'es' => 'Objetivos y metas']) . '" disabled title="' . $tt('modulolegal_lais.botao.objetivos', ['pt' => 'Objetivos e metas', 'en' => 'Objectives and targets', 'es' => 'Objetivos y metas']) . '"><i class="bi bi-bullseye"></i></button>';

$order = ['linha','CODUNIDADE','CODPARTE','MODO_DA_OCORRENCIA','CODASPECTOSOCIAL','SITUACAO','TEMPO','INFLUENCIA','CLASSIFICACAO','CODIMPACTOSOCIAL','ESCALA','SEVERIDADE','DURACAO','RESULTADO','DISPOSICAOATENUANTE','FILTROB','FILTROC','FILTROD','CODLAIS'];
$labels = ['', 'Unidade', 'Partes Interessadas', 'Modo de Ocorrência', 'Aspecto Social', 'Situação (N / A / AC)', 'Tempo (A / P / F)', 'Influência (D / I)', 'Classificação (A / B)', 'Impacto Social', 'Escala', 'Severidade', 'Duração', 'Resultado', 'Disposição ou Atenuante', 'Aspectos Significativos', 'Atendimento Legislação', 'Visão Partes Interessadas', 'Código técnico'];
$labelsEn = ['', 'Unit', 'Stakeholders', 'Occurrence mode', 'Social aspect', 'Situation (N / A / AC)', 'Time (C / P / F)', 'Influence (D / I)', 'Classification (A / B)', 'Social impact', 'Scale', 'Severity', 'Duration', 'Result', 'Disposition or mitigation', 'Significant aspects', 'Legal compliance', 'Stakeholder view', 'Technical code'];
$labelsEs = ['', 'Unidad', 'Partes interesadas', 'Modo de ocurrencia', 'Aspecto social', 'Situación (N / A / AC)', 'Tiempo (A / P / F)', 'Influencia (D / I)', 'Clasificación (A / B)', 'Impacto social', 'Escala', 'Severidad', 'Duración', 'Resultado', 'Disposición o atenuante', 'Aspectos significativos', 'Cumplimiento legal', 'Visión de las partes interesadas', 'Código técnico'];
$widths = [50,100,200,200,300,70,70,70,70,200,70,70,70,70,200,100,100,100,0];
$filters = ['none','select','select','text','select','select','select','select','select','select','select','select','select','none','text','none','select','select','none'];
$legacySearch = ['','#select_filter','#select_filter','#text_filter','#select_filter','#select_filter','#select_filter','#select_filter','#select_filter','#select_filter','#select_filter','#select_filter','#select_filter','','#text_filter','','#select_filter','#select_filter',''];
$legacySort = ['na','str','str','str','str','str','str','str','str','str','str','str','str','str','str','str','str','str','str'];
$legacyTypes = ['cntr','coro','coro','edtxt','coro','coro','coro','coro','coro','coro','coro','coro','coro','ro','edtxt','ro','coro','coro','ro'];
$fields = [];
foreach ($order as $i => $field) {
    $fields[$field] = ['label' => ['pt' => $labels[$i], 'en' => $labelsEn[$i] ?? '', 'es' => $labelsEs[$i] ?? ''], 'filter' => $filters[$i], 'width' => $widths[$i]];
    if (in_array($field, ['linha','CODLAIS'], true)) { $fields[$field]['hidden'] = true; $fields[$field]['readonly'] = true; }
}
$fields['MODO_DA_OCORRENCIA']['type'] = 'textarea';
$fields['DISPOSICAOATENUANTE']['type'] = 'textarea';
$fields['RESULTADO']['readonly'] = true;
$fields['FILTROB']['readonly'] = true;
$fields['CODUNIDADE'] += ['lazy_foreign' => false];
$fields['CODPARTE'] += ['lazy_foreign' => false];
$fields['CODASPECTOSOCIAL'] += ['lazy_foreign' => false];
$fields['CODIMPACTOSOCIAL'] += ['lazy_foreign' => false];
$fields['CODUNIDADE']['foreign'] = ['view' => 'vw_unidades_grid', 'value' => 'CODUNIDADE', 'label' => 'DSCUNIDADE', 'filters' => ['usado_em_manutencao_legal' => 'Sim'], 'description' => 'DSCUNIDADE'];
$fields['CODPARTE']['foreign'] = ['view' => 'lais_partes_interessadas', 'value' => 'CODPARTE', 'label' => 'DSCPARTE', 'description' => 'DSCPARTE'];
$fields['CODASPECTOSOCIAL']['foreign'] = ['view' => 'lais_aspectossociais', 'value' => 'CODASPECTOSOCIAL', 'label' => 'DSCASPECTOSOCIAL', 'description' => 'DSCASPECTOSOCIAL'];
$fields['CODIMPACTOSOCIAL']['foreign'] = ['view' => 'lais_impactossociais', 'value' => 'CODIMPACTOSOCIAL', 'label' => 'DSCIMPACTOSOCIAL', 'description' => 'DSCIMPACTOSOCIAL'];
$fields['SITUACAO']['options'] = ['N' => ['pt'=>'Normal','en'=>'Normal','es'=>'Normal'], 'A' => ['pt'=>'Anormal','en'=>'Abnormal','es'=>'Anormal'], 'AC' => ['pt'=>'Acidental','en'=>'Accidental','es'=>'Accidental']];
$fields['TEMPO']['options'] = ['A' => ['pt'=>'Atual','en'=>'Present','es'=>'Actual'], 'P' => ['pt'=>'Passado','en'=>'Past','es'=>'Pasado'], 'F' => ['pt'=>'Futuro','en'=>'Future','es'=>'Futuro']];
$fields['INFLUENCIA']['options'] = ['D' => ['pt'=>'Direto','en'=>'Direct','es'=>'Directo'], 'I' => ['pt'=>'Indireto','en'=>'Indirect','es'=>'Indirecto']];
$fields['CLASSIFICACAO']['options'] = ['A' => ['pt'=>'Adverso','en'=>'Adverse','es'=>'Adverso'], 'B' => ['pt'=>'Benéfico','en'=>'Beneficial','es'=>'Benéfico']];
foreach (['ESCALA','SEVERIDADE','DURACAO'] as $field) $fields[$field]['options'] = ['1' => '1', '2' => '2', '3' => '3'];
foreach (['FILTROC','FILTROD'] as $field) $fields[$field]['options'] = ['' => '', 'X' => 'X'];

ManutencaoLegalCadastroGrid::render([
    'menu' => 'LAIS', 'title' => ['pt' => 'LAIS', 'en' => 'LAIS', 'es' => 'LAIS'],
    'transaction' => 'modulolegal_lais', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'fragment_class' => 'modulo-lais-grid-fragment', 'button_options' => ['save' => true],
    'extras' => $extras, 'table' => 'lais', 'view' => 'vw_lais_grid', 'primary' => 'CODLAIS', 'primary_auto' => true,
    'legacy_order' => $order, 'legacy_fixed' => true, 'legacy_search' => $legacySearch, 'legacy_sort' => $legacySort, 'legacy_types' => $legacyTypes, 'legacy_labels' => $labels, 'legacy_widths' => $widths,
    'fields' => $fields,
]);

// Barra de botões em grupos (Operações | Correlacionamentos | Avaliação), com dica nativa.
require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.modulo-lais-grid-fragment', [
    'correlacionamentos' => ['[data-context-id="lais-requisitos"]', '[data-context-id="lais-controles"]'],
    'avaliacao' => ['[data-context-id="lais-objetivos"]', '[data-context-id^="questionario-"]'],
]);
?>
<script src="../assets/js/associacoes-contagem.js?v=6"></script>
