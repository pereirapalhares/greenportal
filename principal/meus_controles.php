<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
ManutencaoLegalCadastroGrid::render([
    // Migração 341: Severidade × Probabilidade = Risco do item (obrigatório ao incluir; ver Ajuda de Requisitos legais)
    'risk_area' => 'CONTROLES',
    'menu' => 'MEUS_CONTROLES', 'title' => ['pt' => 'Meus Controles', 'en' => 'My Controls', 'es' => 'Mis Controles'],
    'transaction' => 'meus_controles', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT', 'table' => 'documentos', 'view' => 'vw_meus_controles_grid',
    'primary' => 'CODDOCUMENTO', 'primary_auto' => true, 'fragment_class' => 'controle-documentos-grid-fragment',
    'default_filters' => ['CODUSUARIO' => 0], 'user_scope_field' => 'CODUSUARIO', 'force_user_scope' => true,
    // Migração 361: botão Responsáveis (registro selecionado); telas Minhas = Responsável + atribuições + regras do módulo
    'resp_area' => 'CONTROLES', 'resp_minhas' => 'CONTROLES',
    'relacionamentos_endpoint' => 'api/controle_documentos_relacionamentos.php', 'legacy_order' => ['CODDOCUMENTO', 'CODIGO', 'TITULO', 'ARQUIVO'],
    'button_options' => ['save' => true, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true],
    'fields' => [
        'CODDOCUMENTO' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        'CODIGO' => ['label' => ['pt' => 'Código do documento', 'en' => 'Document code', 'es' => 'Código del documento'], 'filter' => 'text', 'width' => 300],
        'TITULO' => ['label' => ['pt' => 'Título', 'en' => 'Title', 'es' => 'Título'], 'filter' => 'text', 'width' => 400, 'type' => 'textarea'],
        'ARQUIVO' => ['label' => ['pt' => 'Arquivos', 'en' => 'Files', 'es' => 'Archivos'], 'readonly' => true, 'filter' => 'none', 'width' => 120],
        'UNIDADES' => ['label' => ['pt' => 'Unidades', 'en' => 'Units', 'es' => 'Unidades'], 'readonly' => true, 'filter' => 'text'],
        'ATIVIDADES' => ['label' => ['pt' => 'Atividades', 'en' => 'Activities', 'es' => 'Actividades'], 'readonly' => true, 'filter' => 'text'],
        'ASPECTOS' => ['label' => ['pt' => 'Aspectos', 'en' => 'Aspects', 'es' => 'Aspectos'], 'readonly' => true, 'filter' => 'text'],
        'IMPACTOS' => ['label' => ['pt' => 'Impactos', 'en' => 'Impacts', 'es' => 'Impactos'], 'readonly' => true, 'filter' => 'text'],
        'PERIGOS' => ['label' => ['pt' => 'Perigos', 'en' => 'Hazards', 'es' => 'Peligros'], 'readonly' => true, 'filter' => 'text'],
        'RISCOS' => ['label' => ['pt' => 'Riscos', 'en' => 'Risks', 'es' => 'Riesgos'], 'readonly' => true, 'filter' => 'text'],
        'PALAVRAS_CHAVES' => ['label' => ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave'], 'readonly' => true, 'filter' => 'text'],
        'RESPONSAVEIS' => ['label' => ['pt' => 'Responsáveis', 'en' => 'Responsible parties', 'es' => 'Responsables'], 'readonly' => true, 'filter' => 'text'],
        'ASPECTOS_SOCIAIS' => ['label' => ['pt' => 'Aspectos sociais', 'en' => 'Social aspects', 'es' => 'Aspectos sociales'], 'readonly' => true, 'filter' => 'text'],
        'IMPACTOS_SOCIAIS' => ['label' => ['pt' => 'Impactos sociais', 'en' => 'Social impacts', 'es' => 'Impactos sociales'], 'readonly' => true, 'filter' => 'text'],
        'CODUSUARIO' => ['label' => ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
    ],
]);
echo '<script src="../assets/js/modulo-legal-arquivos.js?v=2"></script>';
