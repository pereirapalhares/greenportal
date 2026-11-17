<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';

$_GET['filtros'] = json_encode([
    'CODLEGISLACAO' => (int)($_GET['CODLEGISLACAO'] ?? 0),
    'CODUNIDADE' => (int)($_GET['CODUNIDADE'] ?? 0),
]);

ManutencaoLegalCadastroGrid::render([
    'context_crud' => true,
    'context_crud_keys' => ['CODLEGISLACAO', 'CODUNIDADE'],
    'menu' => 'REQUISITOS_LEGAIS',
    'title' => ['pt' => 'Licenças', 'en' => 'Licenses', 'es' => 'Licencias'],
    'transaction' => 'manutencaolegalrequisitoslegais_licencas',
    'questionnaire_context' => 'LEGAL',
    'questionnaire_origin_fields' => ['CODLEGISLACAO', 'CODUNIDADE'],
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'legislacaoxlicencas',
    'view' => 'vw_modulolegal_requisitos_licencas_grid',
    'primary' => 'CODLICENCA',
    'unique_context_field' => 'CODLICENCA',
    'association_read_only' => true,
    'association_include_with_edit' => true,
    'primary_editable' => false,
    'fragment_class' => 'licencas-grid-fragment',
    'button_options' => ['save' => true, 'new' => true, 'duplicate' => false, 'delete' => true, 'reload' => true, 'export' => true, 'columns' => true],
    'legacy_order' => ['DSCUNIDADE', 'CODLICENCA', 'DATA_VALIDADE', 'DATA_REACAO', 'DATA_PROTOCOLO', 'CODUSUARIO', 'ARQUIVOS', 'CODLISTA', 'EMPROTOCOLO', 'PROTOCOLADO_EM', 'PROTOCOLADO_NO_PRAZO', 'QTD_DIAS_EM_PROTOCOLO', 'CODSTATUS', 'OBSERVACAO', 'CODCLASSIFICACAO', 'CODFREQUENCIA', 'QTDDIAS', 'QTDDIAS2'],
    'fields' => [
        'CODLICENCA' => ['label' => ['pt' => 'Descrição da licença', 'en' => 'License description', 'es' => 'Descripción de la licencia'], 'readonly' => true, 'new_editable' => true, 'display_from' => 'DSCLICENCA', 'foreign' => ['view' => 'vw_licencas_grid', 'value' => 'CODLICENCA', 'label' => 'DSCLICENCA', 'filters' => ['CODUNIDADE' => (int)($_GET['CODUNIDADE'] ?? 0)]]],
        'DSCUNIDADE' => ['label' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'], 'readonly' => true],
        'DSCLICENCA' => ['label' => ['pt' => 'Descrição da licença', 'en' => 'License description', 'es' => 'Descripción de la licencia'], 'readonly' => true, 'hidden' => true, 'filter' => 'none'],
        'DATA_VALIDADE' => ['label' => ['pt' => 'Data de vencimento', 'en' => 'Due date', 'es' => 'Fecha de vencimiento'], 'readonly' => true],
        'DATA_REACAO' => ['label' => ['pt' => 'Data de reação', 'en' => 'Reaction date', 'es' => 'Fecha de reacción'], 'readonly' => true],
        'DATA_PROTOCOLO' => ['label' => ['pt' => 'Data do protocolo', 'en' => 'Protocol date', 'es' => 'Fecha de protocolo'], 'readonly' => true],
        'CODUSUARIO' => ['label' => ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable'], 'readonly' => true, 'display_from' => 'RESPONSAVEL'],
        'ARQUIVOS' => ['label' => ['pt' => 'Arquivos', 'en' => 'Files', 'es' => 'Archivos'], 'readonly' => true, 'ged' => ['type' => 'licencas', 'key' => 'CODLICENCA', 'folder' => 'licencas', 'title' => ['pt' => 'Arquivos da licença', 'en' => 'License files', 'es' => 'Archivos de la licencia']], 'filter' => 'none', 'width' => 110],
        'CODLISTA' => ['label' => ['pt' => 'Lista de distribuição', 'en' => 'Distribution list', 'es' => 'Lista de distribución'], 'readonly' => true],
        'EMPROTOCOLO' => ['label' => ['pt' => 'Em protocolo?', 'en' => 'On protocol?', 'es' => '¿En protocolo?'], 'readonly' => true],
        'PROTOCOLADO_EM' => ['label' => ['pt' => 'Protocolado em', 'en' => 'Protocolized on', 'es' => 'Protocolizado en'], 'readonly' => true],
        'PROTOCOLADO_NO_PRAZO' => ['label' => ['pt' => 'Protocolado no prazo?', 'en' => 'Protocolized on time?', 'es' => '¿Protocolizado en plazo?'], 'readonly' => true],
        'QTD_DIAS_EM_PROTOCOLO' => ['label' => ['pt' => 'Dias em protocolo', 'en' => 'Days under protocol', 'es' => 'Días en protocolo'], 'readonly' => true],
        'CODSTATUS' => ['label' => ['pt' => 'Status do protocolo', 'en' => 'Protocol status', 'es' => 'Estado del protocolo'], 'readonly' => true, 'display_from' => 'DSCSTATUS'],
        'OBSERVACAO' => ['label' => ['pt' => 'Observação', 'en' => 'Notes', 'es' => 'Observación'], 'readonly' => true],
        'CODCLASSIFICACAO' => ['label' => ['pt' => 'Classificação', 'en' => 'Classification', 'es' => 'Clasificación'], 'readonly' => true, 'display_from' => 'DSCCLASSIFICACAO'],
        'CODFREQUENCIA' => ['label' => ['pt' => 'Frequência', 'en' => 'Frequency', 'es' => 'Frecuencia'], 'readonly' => true, 'display_from' => 'DSCFREQUENCIA'],
        'QTDDIAS' => ['label' => ['pt' => 'Qtd. dias vencimento', 'en' => 'Validity days', 'es' => 'Días hasta vencimiento'], 'readonly' => true],
        'QTDDIAS2' => ['label' => ['pt' => 'Qtd. dias reação', 'en' => 'Reaction days', 'es' => 'Días hasta reacción'], 'readonly' => true],
        'CODLEGISLACAO' => ['label' => ['pt' => 'Código da legislação', 'en' => 'Legislation code', 'es' => 'Código de legislación']],
        'CODUNIDADE' => ['label' => ['pt' => 'Código da unidade', 'en' => 'Unit code', 'es' => 'Código de unidad']],
    ],
]);
// Mesma barra de Licenças: botões em grupos e uma única dica nativa (title) por botão.
require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.licencas-grid-fragment', [
    'correlacionamentos' => ['[data-license-condicionantes]', '[data-license-palavras-chave]', '[data-license-responsabilidades]', '[data-license-arquivos]'],
    'avaliacao' => ['[data-context-id^="questionario-"]'],
]);
?>
<script src="../assets/js/modulo-legal-arquivos.js?v=14"></script>
<link href="../assets/css/licencas-vencimento.css?v=2" rel="stylesheet">
<script src="../assets/js/licencas-vencimento.js?v=3"></script>
