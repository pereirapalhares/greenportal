<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';

$_GET['filtros'] = json_encode([
    'CODLEGISLACAO' => (int)($_GET['CODLEGISLACAO'] ?? 0),
    'CODUNIDADE' => (int)($_GET['CODUNIDADE'] ?? 0),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

ManutencaoLegalCadastroGrid::render([
    'context_crud' => true,
    'context_crud_keys' => ['CODLEGISLACAO', 'CODUNIDADE'],
    'association_read_only' => true,
    'menu' => 'REQUISITOS_LEGAIS',
    'title' => ['pt' => 'Controles e documentos', 'en' => 'Controls and documents', 'es' => 'Controles y documentos'],
    'transaction' => 'manutencaolegalrequisitoslegais_controles',
    'questionnaire_context' => 'LEGAL',
    'questionnaire_origin_fields' => ['CODLEGISLACAO', 'CODUNIDADE'],
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'legislacaoxdocumentos',
    'view' => 'vw_modulolegal_requisitos_controles_grid',
    'primary' => 'CODDOCUMENTO',
    'unique_context_field' => 'CODDOCUMENTO',
    'primary_editable' => false,
    'fragment_class' => 'controle-documentos-grid-fragment',
    'button_options' => ['save' => true, 'new' => true, 'duplicate' => false, 'delete' => true, 'reload' => true, 'export' => true, 'columns' => true],
    'legacy_fixed' => true,
    'legacy_order' => ['UNIDADES', 'CODIGO', 'TITULO', 'ARQUIVO', 'ATIVIDADES', 'ASPECTOS', 'IMPACTOS', 'PERIGOS', 'RISCOS', 'PALAVRAS_CHAVES', 'RESPONSAVEIS', 'ASPECTOS_SOCIAIS', 'IMPACTOS_SOCIAIS', 'CODDOCUMENTO'],
    'fields' => [
        'CODDOCUMENTO' => [
            'label' => ['pt' => 'Controle', 'en' => 'Control', 'es' => 'Control'],
            'foreign' => ['view' => 'vw_controle_documentos_grid', 'value' => 'CODDOCUMENTO', 'label' => 'TITULO', 'description' => 'TITULO'],
        ],
        'CODIGO' => ['label' => ['pt' => 'Código do documento', 'en' => 'Document code', 'es' => 'Código del documento'], 'readonly' => true, 'filter' => 'text', 'width' => 240],
        'TITULO' => ['label' => ['pt' => 'Título', 'en' => 'Title', 'es' => 'Título'], 'readonly' => true, 'show_foreign_description' => true, 'filter' => 'text', 'width' => 420, 'type' => 'textarea'],
        'ARQUIVO' => ['label' => ['pt' => 'Arquivo', 'en' => 'File', 'es' => 'Archivo'], 'readonly' => true, 'filter' => 'text', 'width' => 150],
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
        'CODLEGISLACAO' => ['label' => ['pt' => 'Código da legislação', 'en' => 'Legislation code', 'es' => 'Código de legislación']],
        'CODUNIDADE' => ['label' => ['pt' => 'Código da unidade', 'en' => 'Unit code', 'es' => 'Código de unidad']],
    ],
]);
?>
<script src="../assets/js/modulo-legal-arquivos.js?v=10"></script>
