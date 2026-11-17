<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';

$pendingMode = !empty($_GET['minhas_pendencias']);

require_once dirname(__DIR__) . '/classes/IdiomaService.php';

// Botões de correlacionamento: textos em Administração › Textos e idiomas (modulolegal_controle.botao.*).
$controleRelacionamentos = '';
foreach ([
    ['key' => 'unidades', 'label' => ['pt' => 'Unidades', 'en' => 'Units', 'es' => 'Unidades'], 'icon' => 'bi-building'],
    ['key' => 'atividades', 'label' => ['pt' => 'Atividades', 'en' => 'Activities', 'es' => 'Actividades'], 'icon' => 'bi-list-task'],
    ['key' => 'aspectos', 'label' => ['pt' => 'Aspectos', 'en' => 'Aspects', 'es' => 'Aspectos'], 'icon' => 'bi-diagram-3'],
    ['key' => 'impactos', 'label' => ['pt' => 'Impactos', 'en' => 'Impacts', 'es' => 'Impactos'], 'icon' => 'bi-exclamation-diamond'],
    ['key' => 'perigos', 'label' => ['pt' => 'Perigos', 'en' => 'Hazards', 'es' => 'Peligros'], 'icon' => 'bi-shield-exclamation'],
    ['key' => 'riscos', 'label' => ['pt' => 'Riscos', 'en' => 'Risks', 'es' => 'Riesgos'], 'icon' => 'bi-shield'],
    ['key' => 'palavras_chaves', 'label' => ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave'], 'icon' => 'bi-tags'],
    ['key' => 'aspectos_sociais', 'label' => ['pt' => 'Aspectos sociais', 'en' => 'Social aspects', 'es' => 'Aspectos sociales'], 'icon' => 'bi-people'],
    ['key' => 'impactos_sociais', 'label' => ['pt' => 'Impactos sociais', 'en' => 'Social impacts', 'es' => 'Impactos sociales'], 'icon' => 'bi-graph-up-arrow'],
] as $relation) {
    $rotulo = htmlspecialchars(IdiomaService::t('modulolegal_controle.botao.' . $relation['key'], $relation['label']), ENT_QUOTES, 'UTF-8');
    $controleRelacionamentos .= '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-control-relacionamento="' . htmlspecialchars($relation['key'], ENT_QUOTES, 'UTF-8') . '" disabled title="' . $rotulo . '" aria-label="' . $rotulo . '"><i class="bi ' . htmlspecialchars($relation['icon'], ENT_QUOTES, 'UTF-8') . '"></i></button>';
}

ManutencaoLegalCadastroGrid::render([
    // Migração 341: Severidade × Probabilidade = Risco do item (obrigatório ao incluir; ver Ajuda de Requisitos legais)
    'risk_area' => 'CONTROLES',
    'menu' => 'CONTROLE_DOCUMENTOS',
    'title' => IdiomaService::textos('modulolegal_controle.titulo', ['pt' => 'Controle', 'en' => 'Control', 'es' => 'Control']),
    'transaction' => 'modulolegal_controle',
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'documentos',
    'view' => $pendingMode ? 'vw_meus_controles_grid' : 'vw_controle_documentos_grid',
    'primary' => 'CODDOCUMENTO',
    'primary_auto' => true,
    'fragment_class' => 'controle-documentos-grid-fragment',
    'relacionamentos_endpoint' => 'api/controle_documentos_relacionamentos.php',
    'responsabilidades_endpoint' => 'api/controle_documentos_responsabilidades.php',
    'extras' => $controleRelacionamentos,
    'legacy_fixed' => true,
    'legacy_order' => ['UNIDADES', 'CODIGO', 'TITULO', 'ARQUIVO', 'ATIVIDADES', 'ASPECTOS', 'IMPACTOS', 'PERIGOS', 'RISCOS', 'PALAVRAS_CHAVES', 'RESPONSAVEIS', 'ASPECTOS_SOCIAIS', 'IMPACTOS_SOCIAIS', 'CODDOCUMENTO'],
    'user_scope_field' => 'CODUSUARIO',
    'force_user_scope' => $pendingMode,
    // Migração 361: botão Responsáveis (registro selecionado); telas Minhas = Responsável + atribuições + regras do módulo
    'resp_area' => 'CONTROLES', 'resp_minhas' => $pendingMode ? 'CONTROLES' : '',
    'button_options' => $pendingMode ? [
        'save' => true, 'new' => false, 'duplicate' => false, 'delete' => false,
        'reload' => true, 'export' => true, 'columns' => true,
    ] : [
        'save' => true, 'new' => true, 'duplicate' => true, 'delete' => true,
        'reload' => true, 'export' => true, 'columns' => true,
    ],
    'fields' => [
        'CODDOCUMENTO' => [
            'label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'],
            'hidden' => true, 'readonly' => true, 'filter' => 'none',
        ],
        'CODIGO' => [
            'label' => ['pt' => 'Código do documento', 'en' => 'Document code', 'es' => 'Código del documento'],
            'filter' => 'text', 'width' => 300,
        ],
        'TITULO' => [
            'label' => ['pt' => 'Título', 'en' => 'Title', 'es' => 'Título'],
            'filter' => 'text', 'width' => 400, 'type' => 'textarea',
        ],
        'ARQUIVO' => [
            'label' => ['pt' => 'Arquivo', 'en' => 'File', 'es' => 'Archivo'],
            'filter' => 'text', 'width' => 150,
        ],
        'UNIDADES' => ['label' => ['pt' => 'Unidades', 'en' => 'Units', 'es' => 'Unidades'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        'ATIVIDADES' => ['label' => ['pt' => 'Atividades', 'en' => 'Activities', 'es' => 'Actividades'], 'readonly' => true, 'filter' => 'text', 'width' => 260],
        'ASPECTOS' => ['label' => ['pt' => 'Aspectos', 'en' => 'Aspects', 'es' => 'Aspectos'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        'IMPACTOS' => ['label' => ['pt' => 'Impactos', 'en' => 'Impacts', 'es' => 'Impactos'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        'PERIGOS' => ['label' => ['pt' => 'Perigos', 'en' => 'Hazards', 'es' => 'Peligros'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        'RISCOS' => ['label' => ['pt' => 'Riscos', 'en' => 'Risks', 'es' => 'Riesgos'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        'PALAVRAS_CHAVES' => ['label' => ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        'RESPONSAVEIS' => ['label' => ['pt' => 'Responsáveis', 'en' => 'Responsible parties', 'es' => 'Responsables'], 'readonly' => true, 'filter' => 'text', 'width' => 240],
        'ASPECTOS_SOCIAIS' => ['label' => ['pt' => 'Aspectos sociais', 'en' => 'Social aspects', 'es' => 'Aspectos sociales'], 'readonly' => true, 'filter' => 'text', 'width' => 240],
        'IMPACTOS_SOCIAIS' => ['label' => ['pt' => 'Impactos sociais', 'en' => 'Social impacts', 'es' => 'Impactos sociales'], 'readonly' => true, 'filter' => 'text', 'width' => 240],
        'CODUSUARIO' => ['label' => ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable'], 'hidden' => true, 'filter' => 'none'],
    ],
]);
?>
<script src="../assets/js/modulo-legal-arquivos.js?v=10"></script>
