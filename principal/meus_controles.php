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
    'relacionamentos_endpoint' => 'api/controle_documentos_relacionamentos.php', 'legacy_order' => ['CODDOCUMENTO', 'CODIGO', 'TITULO', 'ARQUIVO', 'CODTIPOCONTROLE', 'VERSAO', 'ORIGEM', 'EMITENTE', 'REVISOR', 'APROVADOR', 'DOCUMENTO_SUPERIOR', 'DATA_EMISSAO', 'DATA_VENCIMENTO', 'DIAS_REACAO', 'DATA_REACAO_CONTROLE', 'NECESSITA_TREINAMENTO', 'NOTIFICAR_ALTERACAO', 'PRAZO_GUARDA', 'FORMA_DESCARTE', 'CONTROLE_INFLUENCIA', 'OBSERVACAO'],
    'button_options' => ['save' => true, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true],
    'fields' => [
        'CODDOCUMENTO' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        'CODIGO' => ['label' => ['pt' => 'Código do documento', 'en' => 'Document code', 'es' => 'Código del documento'], 'filter' => 'text', 'width' => 300],
        // Migração 408 (onda 3 da Revisão G, 24/11/2026): Tipo (só os ativos na lista) — define quem responde pela regra de Responsáveis e avisos do módulo.
        'CODTIPOCONTROLE' => ['label' => ['pt' => 'Tipo de controle', 'en' => 'Control type', 'es' => 'Tipo de control'], 'foreign' => ['view' => 'ml_tipos_controle', 'value' => 'CODTIPOCONTROLE', 'label' => 'DSCTIPOCONTROLE', 'description' => 'DSCTIPOCONTROLE', 'filters' => ['ATIVO' => 1]], 'filter' => 'select', 'width' => 200, 'allow_empty' => true],
        // Migração 409 (onda 3 da Revisão G, 24/11/2026): campos novos da planilha (itens 89).
        'VERSAO' => ['label' => ['pt' => 'Versão', 'en' => 'Version', 'es' => 'Versión'], 'filter' => 'text', 'width' => 110],
        'ORIGEM' => ['label' => ['pt' => 'Origem', 'en' => 'Origin', 'es' => 'Origen'], 'options' => ['' => '', 'Interno' => ['pt' => 'Interno', 'en' => 'Internal', 'es' => 'Interno'], 'Externo' => ['pt' => 'Externo', 'en' => 'External', 'es' => 'Externo']], 'filter' => 'select', 'width' => 140],
        'EMITENTE' => ['label' => ['pt' => 'Emitente', 'en' => 'Issuer', 'es' => 'Emisor'], 'filter' => 'text', 'width' => 180],
        // 25/11/2026: Revisor, Aprovador, Documento de nível superior, Prazo de guarda e Forma de descarte viram listas (pedido do usuário);
        // Revisor/Aprovador = pessoas da empresa (vw_resp_regras_pessoas: ativos, sem contas de fornecedor); valores já gravados que não estão na lista (ex.: vindos da IA ou de documento externo) continuam aparecendo e podem ser mantidos.
        'REVISOR' => ['label' => ['pt' => 'Revisor', 'en' => 'Reviewer', 'es' => 'Revisor'], 'options' => ['' => ''], 'options_sources' => [['table' => 'vw_resp_regras_pessoas', 'field' => 'nome'], ['table' => 'documentos', 'field' => 'REVISOR']], 'filter' => 'select', 'width' => 180],
        'APROVADOR' => ['label' => ['pt' => 'Aprovador', 'en' => 'Approver', 'es' => 'Aprobador'], 'options' => ['' => ''], 'options_sources' => [['table' => 'vw_resp_regras_pessoas', 'field' => 'nome'], ['table' => 'documentos', 'field' => 'APROVADOR']], 'filter' => 'select', 'width' => 180],
        'DOCUMENTO_SUPERIOR' => ['label' => ['pt' => 'Documento de nível superior', 'en' => 'Higher-level document', 'es' => 'Documento de nivel superior'], 'options' => ['' => ''], 'options_sources' => [['table' => 'documentos', 'field' => 'CODIGO'], ['table' => 'documentos', 'field' => 'DOCUMENTO_SUPERIOR']], 'filter' => 'select', 'width' => 220,
            'tooltip' => ['pt' => 'Código do controle de nível superior (lista com os controles da empresa)', 'en' => 'Code of the higher-level control (list of the company controls)', 'es' => 'Código del control de nivel superior (lista con los controles de la empresa)']],
        'DATA_EMISSAO' => ['label' => ['pt' => 'Data de emissão', 'en' => 'Issue date', 'es' => 'Fecha de emisión'], 'type' => 'date', 'filter' => 'text', 'width' => 150],
        'DATA_VENCIMENTO' => ['label' => ['pt' => 'Data de vencimento', 'en' => 'Expiry date', 'es' => 'Fecha de vencimiento'], 'type' => 'date', 'filter' => 'text', 'width' => 150],
        'DIAS_REACAO' => ['label' => ['pt' => 'Dias de reação', 'en' => 'Reaction days', 'es' => 'Días de reacción'], 'type' => 'number', 'filter' => 'text', 'width' => 120],
        'DATA_REACAO_CONTROLE' => ['label' => ['pt' => 'Data de reação', 'en' => 'Reaction date', 'es' => 'Fecha de reacción'], 'readonly' => true, 'filter' => 'text', 'width' => 150],
        'NECESSITA_TREINAMENTO' => ['label' => ['pt' => 'Necessita treinamento?', 'en' => 'Requires training?', 'es' => '¿Requiere capacitación?'], 'options' => ['' => '', 'Sim' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí'], 'Não' => ['pt' => 'Não', 'en' => 'No', 'es' => 'No']], 'filter' => 'select', 'width' => 140],
        'NOTIFICAR_ALTERACAO' => ['label' => ['pt' => 'Notificar alteração?', 'en' => 'Notify changes?', 'es' => '¿Notificar cambios?'], 'options' => ['' => '', 'Sim' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí'], 'Não' => ['pt' => 'Não', 'en' => 'No', 'es' => 'No']], 'filter' => 'select', 'width' => 140],
        'PRAZO_GUARDA' => ['label' => ['pt' => 'Prazo de guarda', 'en' => 'Retention period', 'es' => 'Plazo de conservación'], 'options' => ['' => '', '1 ano' => ['pt' => '1 ano', 'en' => '1 year', 'es' => '1 año'], '2 anos' => ['pt' => '2 anos', 'en' => '2 years', 'es' => '2 años'], '3 anos' => ['pt' => '3 anos', 'en' => '3 years', 'es' => '3 años'], '5 anos' => ['pt' => '5 anos', 'en' => '5 years', 'es' => '5 años'], '10 anos' => ['pt' => '10 anos', 'en' => '10 years', 'es' => '10 años'], '20 anos' => ['pt' => '20 anos', 'en' => '20 years', 'es' => '20 años'], 'Enquanto vigente' => ['pt' => 'Enquanto vigente', 'en' => 'While in force', 'es' => 'Mientras vigente'], 'Permanente' => ['pt' => 'Permanente', 'en' => 'Permanent', 'es' => 'Permanente']], 'options_sources' => [['table' => 'documentos', 'field' => 'PRAZO_GUARDA']], 'filter' => 'select', 'width' => 160],
        'FORMA_DESCARTE' => ['label' => ['pt' => 'Forma de descarte', 'en' => 'Disposal method', 'es' => 'Forma de descarte'], 'options' => ['' => '', 'Fragmentação' => ['pt' => 'Fragmentação', 'en' => 'Shredding', 'es' => 'Trituración'], 'Incineração' => ['pt' => 'Incineração', 'en' => 'Incineration', 'es' => 'Incineración'], 'Reciclagem' => ['pt' => 'Reciclagem', 'en' => 'Recycling', 'es' => 'Reciclaje'], 'Exclusão eletrônica' => ['pt' => 'Exclusão eletrônica', 'en' => 'Electronic deletion', 'es' => 'Eliminación electrónica'], 'Devolução ao emitente' => ['pt' => 'Devolução ao emitente', 'en' => 'Return to issuer', 'es' => 'Devolución al emisor'], 'Não se aplica' => ['pt' => 'Não se aplica', 'en' => 'Not applicable', 'es' => 'No aplica']], 'options_sources' => [['table' => 'documentos', 'field' => 'FORMA_DESCARTE']], 'filter' => 'select', 'width' => 190],
        'CONTROLE_INFLUENCIA' => ['label' => ['pt' => 'Controle / influência', 'en' => 'Control / influence', 'es' => 'Control / influencia'], 'options' => ['' => '', 'Controle' => ['pt' => 'Controle', 'en' => 'Control', 'es' => 'Control'], 'Influência' => ['pt' => 'Influência', 'en' => 'Influence', 'es' => 'Influencia']], 'filter' => 'select', 'width' => 140],
        'OBSERVACAO' => ['label' => ['pt' => 'Observação', 'en' => 'Notes', 'es' => 'Observación'], 'type' => 'textarea', 'filter' => 'text', 'width' => 320],
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
echo '<script src="../assets/js/unidades-combo.js?v=4"></script>';
