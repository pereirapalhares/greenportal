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
    // 25/11/2026: grade enxuta — só as colunas para achar e acompanhar o documento; o resto fica em "Colunas" e na ficha
    // do registro (botão Detalhes, duplo clique ou ao incluir), com os campos em grupos.
    'legacy_fixed' => false,
    'columns_version' => 20261125,
    'default_visible' => ['UNIDADES', 'CODIGO', 'TITULO', 'ARQUIVO', 'CODTIPOCONTROLE', 'VERSAO', 'DATA_VENCIMENTO', 'RISCO_CLASSE'],
    'ficha' => [
        'grupos' => [
            ['titulo' => ['pt' => 'Identificação', 'en' => 'Identification', 'es' => 'Identificación'], 'campos' => ['CODIGO', 'TITULO', 'CODTIPOCONTROLE', 'VERSAO', 'ORIGEM', 'EMITENTE_EXTERNO', 'EMITENTE', 'DOCUMENTO_SUPERIOR', 'ARQUIVO']],
            ['titulo' => ['pt' => 'Aprovação', 'en' => 'Approval', 'es' => 'Aprobación'], 'campos' => ['REVISOR', 'APROVADOR', 'DATA_EMISSAO']],
            ['titulo' => ['pt' => 'Validade', 'en' => 'Validity', 'es' => 'Validez'], 'campos' => ['DATA_VENCIMENTO', 'DIAS_REACAO', 'DATA_REACAO_CONTROLE', 'NOTIFICAR_ALTERACAO']],
            ['titulo' => ['pt' => 'Guarda e descarte', 'en' => 'Retention and disposal', 'es' => 'Conservación y descarte'], 'campos' => ['PRAZO_GUARDA', 'FORMA_DESCARTE']],
            ['titulo' => ['pt' => 'Aplicação e risco', 'en' => 'Scope and risk', 'es' => 'Aplicación y riesgo'], 'campos' => ['UNIDADES', ['botao' => '[data-resp-registro]', 'rotulo' => ['pt' => 'Responsáveis', 'en' => 'Responsible parties', 'es' => 'Responsables'], 'acao' => ['pt' => 'Ver e associar responsáveis', 'en' => 'View and assign', 'es' => 'Ver y asociar'], 'dica' => ['pt' => 'Um ou mais responsáveis.', 'en' => 'One or more people.', 'es' => 'Uno o más responsables.']], 'NECESSITA_TREINAMENTO', 'CONTROLE_INFLUENCIA', 'RISCO_SEVERIDADE', 'RISCO_PROBABILIDADE', 'RISCO_CLASSE']],
            ['titulo' => ['pt' => 'Observação', 'en' => 'Notes', 'es' => 'Observación'], 'campos' => ['OBSERVACAO']],
        ],
        // O campo da esquerda só aparece na ficha quando o da direita está preenchido.
        'condicoes' => ['EMITENTE_EXTERNO' => ['campo' => 'ORIGEM', 'valor' => 'Externo'], 'DIAS_REACAO' => 'DATA_VENCIMENTO', 'DATA_REACAO_CONTROLE' => 'DATA_VENCIMENTO', 'FORMA_DESCARTE' => 'PRAZO_GUARDA'],
    ],
    'legacy_order' => ['UNIDADES', 'CODIGO', 'TITULO', 'ARQUIVO', 'CODTIPOCONTROLE', 'VERSAO', 'ORIGEM', 'EMITENTE_EXTERNO', 'EMITENTE', 'REVISOR', 'APROVADOR', 'DOCUMENTO_SUPERIOR', 'DATA_EMISSAO', 'DATA_VENCIMENTO', 'DIAS_REACAO', 'DATA_REACAO_CONTROLE', 'NECESSITA_TREINAMENTO', 'NOTIFICAR_ALTERACAO', 'PRAZO_GUARDA', 'FORMA_DESCARTE', 'CONTROLE_INFLUENCIA', 'OBSERVACAO', 'ATIVIDADES', 'ASPECTOS', 'IMPACTOS', 'PERIGOS', 'RISCOS', 'PALAVRAS_CHAVES', 'RESPONSAVEIS', 'ASPECTOS_SOCIAIS', 'IMPACTOS_SOCIAIS', 'CODDOCUMENTO'],
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
        // Migração 408 (onda 3 da Revisão G, 24/11/2026): Tipo (só os ativos na lista) — define quem responde pela regra de Responsáveis e avisos do módulo.
        'CODTIPOCONTROLE' => ['label' => ['pt' => 'Tipo de controle', 'en' => 'Control type', 'es' => 'Tipo de control'], 'foreign' => ['view' => 'ml_tipos_controle', 'value' => 'CODTIPOCONTROLE', 'label' => 'DSCTIPOCONTROLE', 'description' => 'DSCTIPOCONTROLE', 'filters' => ['ATIVO' => 1]], 'filter' => 'select', 'width' => 200, 'allow_empty' => true],
        // Migração 409 (onda 3 da Revisão G, 24/11/2026): campos novos da planilha (itens 89).
        'VERSAO' => ['label' => ['pt' => 'Versão', 'en' => 'Version', 'es' => 'Versión'], 'default_new' => '00', 'insert_value' => '00', 'readonly' => true, 'tooltip' => ['pt' => "Controlada pelo sistema: 00 na criação do documento.\nSobe sozinha (01, 02…) quando um arquivo é enviado como nova versão em Arquivos.", 'en' => "Managed by the system: 00 when the document is created.\nGoes up automatically (01, 02…) when a file is uploaded as a new version in Files.", 'es' => "Controlada por el sistema: 00 al crear el documento.\nSube sola (01, 02…) cuando se envía un archivo como nueva versión en Archivos."], 'filter' => 'text', 'width' => 110],
        'ORIGEM' => ['label' => ['pt' => 'Origem', 'en' => 'Origin', 'es' => 'Origen'], 'default_new' => 'Interno', 'options' => ['' => '', 'Interno' => ['pt' => 'Interno', 'en' => 'Internal', 'es' => 'Interno'], 'Externo' => ['pt' => 'Externo', 'en' => 'External', 'es' => 'Externo']], 'filter' => 'select', 'width' => 140],
        'EMITENTE_EXTERNO' => ['label' => ['pt' => 'Emitido por (externo)', 'en' => 'Issued by (external)', 'es' => 'Emitido por (externo)'], 'filter' => 'text', 'width' => 200, 'tooltip' => ['pt' => 'Para documentos de origem Externa: empresa ou pessoa que emitiu o documento (ex.: cliente, laboratório, órgão).', 'en' => 'For External documents: company or person that issued the document (e.g. customer, laboratory, agency).', 'es' => 'Para documentos de origen Externo: empresa o persona que emitió el documento (ej.: cliente, laboratorio, organismo).']],
        'EMITENTE' => ['label' => ['pt' => 'Emitente', 'en' => 'Issuer', 'es' => 'Emisor'], 'readonly' => true, 'insert_value' => '{usuario}', 'default_new' => '{usuario}', 'tooltip' => ['pt' => 'Preenchido pelo sistema: usuário que criou o documento.', 'en' => 'Filled in by the system: user who created the document.', 'es' => 'Completado por el sistema: usuario que creó el documento.'], 'filter' => 'text', 'width' => 180],
        // 25/11/2026: Revisor, Aprovador, Documento de nível superior, Prazo de guarda e Forma de descarte viram listas (pedido do usuário);
        // Revisor/Aprovador = pessoas da empresa (vw_resp_regras_pessoas: ativos, sem contas de fornecedor); valores já gravados que não estão na lista (ex.: vindos da IA ou de documento externo) continuam aparecendo e podem ser mantidos.
        'REVISOR' => ['label' => ['pt' => 'Revisor', 'en' => 'Reviewer', 'es' => 'Revisor'], 'options' => ['' => ''], 'options_sources' => [['table' => 'vw_resp_regras_pessoas', 'field' => 'nome'], ['table' => 'documentos', 'field' => 'REVISOR']], 'filter' => 'select', 'width' => 180],
        'APROVADOR' => ['label' => ['pt' => 'Aprovador', 'en' => 'Approver', 'es' => 'Aprobador'], 'options' => ['' => ''], 'options_sources' => [['table' => 'vw_resp_regras_pessoas', 'field' => 'nome'], ['table' => 'documentos', 'field' => 'APROVADOR']], 'filter' => 'select', 'width' => 180],
        'DOCUMENTO_SUPERIOR' => ['label' => ['pt' => 'Documento de nível superior', 'en' => 'Higher-level document', 'es' => 'Documento de nivel superior'], 'options' => ['' => ''], 'options_sources' => [['table' => 'documentos', 'field' => 'CODIGO'], ['table' => 'documentos', 'field' => 'DOCUMENTO_SUPERIOR']], 'filter' => 'select', 'width' => 220,
            'tooltip' => ['pt' => 'Código do controle de nível superior (lista com os controles da empresa)', 'en' => 'Code of the higher-level control (list of the company controls)', 'es' => 'Código del control de nivel superior (lista con los controles de la empresa)']],
        'DATA_EMISSAO' => ['label' => ['pt' => 'Data de criação', 'en' => 'Creation date', 'es' => 'Fecha de creación'], 'readonly' => true, 'insert_value' => '{hoje}', 'default_new' => '{hoje}', 'tooltip' => ['pt' => 'Preenchida pelo sistema: data em que o documento foi criado.', 'en' => 'Filled in by the system: date the document was created.', 'es' => 'Completada por el sistema: fecha en que se creó el documento.'], 'type' => 'date', 'filter' => 'text', 'width' => 150],
        'DATA_VENCIMENTO' => ['label' => ['pt' => 'Data de vencimento', 'en' => 'Expiry date', 'es' => 'Fecha de vencimiento'], 'type' => 'date', 'filter' => 'text', 'width' => 150],
        'DIAS_REACAO' => ['label' => ['pt' => 'Dias de reação', 'en' => 'Reaction days', 'es' => 'Días de reacción'], 'default_new' => '30', 'type' => 'number', 'filter' => 'text', 'width' => 120],
        'DATA_REACAO_CONTROLE' => ['label' => ['pt' => 'Data de reação', 'en' => 'Reaction date', 'es' => 'Fecha de reacción'], 'readonly' => true, 'filter' => 'text', 'width' => 150],
        'NECESSITA_TREINAMENTO' => ['label' => ['pt' => 'Necessita treinamento?', 'en' => 'Requires training?', 'es' => '¿Requiere capacitación?'], 'options' => ['' => '', 'Sim' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí'], 'Não' => ['pt' => 'Não', 'en' => 'No', 'es' => 'No']], 'filter' => 'select', 'width' => 140],
        'NOTIFICAR_ALTERACAO' => ['label' => ['pt' => 'Notificar alteração?', 'en' => 'Notify changes?', 'es' => '¿Notificar cambios?'], 'default_new' => 'Sim', 'options' => ['' => '', 'Sim' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí'], 'Não' => ['pt' => 'Não', 'en' => 'No', 'es' => 'No']], 'filter' => 'select', 'width' => 140],
        'PRAZO_GUARDA' => ['label' => ['pt' => 'Prazo de guarda', 'en' => 'Retention period', 'es' => 'Plazo de conservación'], 'options' => ['' => '', '1 ano' => ['pt' => '1 ano', 'en' => '1 year', 'es' => '1 año'], '2 anos' => ['pt' => '2 anos', 'en' => '2 years', 'es' => '2 años'], '3 anos' => ['pt' => '3 anos', 'en' => '3 years', 'es' => '3 años'], '5 anos' => ['pt' => '5 anos', 'en' => '5 years', 'es' => '5 años'], '10 anos' => ['pt' => '10 anos', 'en' => '10 years', 'es' => '10 años'], '20 anos' => ['pt' => '20 anos', 'en' => '20 years', 'es' => '20 años'], 'Enquanto vigente' => ['pt' => 'Enquanto vigente', 'en' => 'While in force', 'es' => 'Mientras vigente'], 'Permanente' => ['pt' => 'Permanente', 'en' => 'Permanent', 'es' => 'Permanente']], 'options_sources' => [['table' => 'documentos', 'field' => 'PRAZO_GUARDA']], 'filter' => 'select', 'width' => 160],
        'FORMA_DESCARTE' => ['label' => ['pt' => 'Forma de descarte', 'en' => 'Disposal method', 'es' => 'Forma de descarte'], 'options' => ['' => '', 'Fragmentação' => ['pt' => 'Fragmentação', 'en' => 'Shredding', 'es' => 'Trituración'], 'Incineração' => ['pt' => 'Incineração', 'en' => 'Incineration', 'es' => 'Incineración'], 'Reciclagem' => ['pt' => 'Reciclagem', 'en' => 'Recycling', 'es' => 'Reciclaje'], 'Exclusão eletrônica' => ['pt' => 'Exclusão eletrônica', 'en' => 'Electronic deletion', 'es' => 'Eliminación electrónica'], 'Devolução ao emitente' => ['pt' => 'Devolução ao emitente', 'en' => 'Return to issuer', 'es' => 'Devolución al emisor'], 'Não se aplica' => ['pt' => 'Não se aplica', 'en' => 'Not applicable', 'es' => 'No aplica']], 'options_sources' => [['table' => 'documentos', 'field' => 'FORMA_DESCARTE']], 'filter' => 'select', 'width' => 190],
        'CONTROLE_INFLUENCIA' => ['label' => ['pt' => 'Controle / influência', 'en' => 'Control / influence', 'es' => 'Control / influencia'], 'options' => ['' => '', 'Controle' => ['pt' => 'Controle', 'en' => 'Control', 'es' => 'Control'], 'Influência' => ['pt' => 'Influência', 'en' => 'Influence', 'es' => 'Influencia']], 'filter' => 'select', 'width' => 140],
        'OBSERVACAO' => ['label' => ['pt' => 'Observação', 'en' => 'Notes', 'es' => 'Observación'], 'type' => 'textarea', 'filter' => 'text', 'width' => 320],
        'TITULO' => [
            'label' => ['pt' => 'Título', 'en' => 'Title', 'es' => 'Título'],
            'filter' => 'text', 'width' => 400, 'type' => 'textarea',
        ],
        // 25/11/2026: contagem de arquivos do GED (botão), não é digitável — na linha nova aparecia como campo de texto livre (igual a Meus controles)
        'ARQUIVO' => [
            'label' => ['pt' => 'Arquivos', 'en' => 'Files', 'es' => 'Archivos'],
            'readonly' => true, 'filter' => 'none', 'width' => 120,
        ],
        'UNIDADES' => ['label' => ['pt' => 'Unidades', 'en' => 'Units', 'es' => 'Unidades'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        'ATIVIDADES' => ['label' => ['pt' => 'Atividades', 'en' => 'Activities', 'es' => 'Actividades'], 'readonly' => true, 'filter' => 'text', 'width' => 260],
        'ASPECTOS' => ['label' => ['pt' => 'Aspectos', 'en' => 'Aspects', 'es' => 'Aspectos'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        'IMPACTOS' => ['label' => ['pt' => 'Impactos', 'en' => 'Impacts', 'es' => 'Impactos'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        'PERIGOS' => ['label' => ['pt' => 'Perigos', 'en' => 'Hazards', 'es' => 'Peligros'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        'RISCOS' => ['label' => ['pt' => 'Riscos', 'en' => 'Risks', 'es' => 'Riesgos'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        'PALAVRAS_CHAVES' => ['label' => ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        'RESPONSAVEIS' => ['label' => ['pt' => 'Responsáveis', 'en' => 'Responsible parties', 'es' => 'Responsables'], 'hidden' => true, 'readonly' => true, 'filter' => 'text', 'width' => 240],
        'ASPECTOS_SOCIAIS' => ['label' => ['pt' => 'Aspectos sociais', 'en' => 'Social aspects', 'es' => 'Aspectos sociales'], 'readonly' => true, 'filter' => 'text', 'width' => 240],
        'IMPACTOS_SOCIAIS' => ['label' => ['pt' => 'Impactos sociais', 'en' => 'Social impacts', 'es' => 'Impactos sociales'], 'readonly' => true, 'filter' => 'text', 'width' => 240],
        // readonly (24/11/2026): documentos não tem CODUSUARIO (vem das views de responsabilidade); sem isso a gravação de qualquer
        // alteração falhava com "Um dos campos não pode ser alterado nesta tela" (mesma correção de Treinamentos em 12/11/2026)
        'CODUSUARIO' => ['label' => ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
    ],
]);
?>
<script src="../assets/js/modulo-legal-arquivos.js?v=10"></script>
<script src="../assets/js/unidades-combo.js?v=5"></script><script src="../assets/js/ficha-registro.js?v=3"></script><script src="../assets/js/associacoes-contagem.js?v=6"></script>
