<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
// Dicas dos botões em Administração › Textos e idiomas (cadastro automático ao abrir como ADMINISTRADOR).
$tt = static fn(string $chave, array $padrao): string => htmlspecialchars(IdiomaService::t($chave, $padrao), ENT_QUOTES, 'UTF-8');

// Migração 410: trabalhadores treinados do treinamento selecionado (validade, status e certificados)
$treinadosLabel = IdiomaService::t('meus_treinamentos.botao.treinados', ['pt' => 'Treinados', 'en' => 'Trained workers', 'es' => 'Capacitados']);

ManutencaoLegalCadastroGrid::render([
    // Migração 341: Severidade × Probabilidade = Risco do item (obrigatório ao incluir; ver Ajuda de Requisitos legais)
    'risk_area' => 'TREINAMENTOS',
    'menu' => 'MEUS_TREINAMENTOS',
    'title' => ['pt' => 'Meus Treinamentos', 'en' => 'My Training', 'es' => 'Mis Capacitaciones'],
    'transaction' => 'meus_treinamentos',
    'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'table' => 'ml_treinamentos', 'view' => 'vw_minhas_treinamentos_grid',
    'primary' => 'CODTREINAMENTO', 'primary_auto' => true,
    'fragment_class' => 'modulo-legal-treinamentos-grid-fragment',
    'user_scope_field' => 'CODUSUARIO', 'force_user_scope' => true,
    // Migração 361: botão Responsáveis (registro selecionado); telas Minhas = Responsável + atribuições + regras do módulo
    'resp_area' => 'TREINAMENTOS', 'resp_minhas' => 'TREINAMENTOS',
    'relacionamentos_endpoint' => 'api/modulo_legal_relacionamentos.php',
    'extras' => '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-ml-relacionamento="treinamentos_unidades" disabled title="' . htmlspecialchars(IdiomaService::t('meus_treinamentos.botao.unidades', ['pt' => 'Unidades', 'en' => 'Units', 'es' => 'Unidades']), ENT_QUOTES, 'UTF-8') . '" aria-label="' . htmlspecialchars(IdiomaService::t('meus_treinamentos.botao.unidades', ['pt' => 'Unidades', 'en' => 'Units', 'es' => 'Unidades']), ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-building"></i></button>' . '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-ml-relacionamento="treinamentos_palavras_chaves" disabled title="' . $tt('meus_treinamentos.botao.palavras_chave', ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave']) . '" aria-label="' . $tt('meus_treinamentos.botao.palavras_chave', ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave']) . '"><i class="bi bi-tags"></i></button>' . '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-context-child data-context-id="treinamento-treinados" data-context-url="/principal/ml_treinamentos_participantes.php" data-context-fields="CODTREINAMENTO" data-context-title="' . htmlspecialchars($treinadosLabel, ENT_QUOTES, 'UTF-8') . '" disabled title="' . htmlspecialchars($treinadosLabel, ENT_QUOTES, 'UTF-8') . '" aria-label="' . htmlspecialchars($treinadosLabel, ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-person-check"></i></button>',
    'legacy_order' => ['CODTREINAMENTO', 'DSCTREINAMENTO', 'CODTIPOTREINAMENTO', 'UNIDADES', 'CARGA_HORARIA', 'PERIODO_RECICLAGEM', 'CONTROLE_INFLUENCIA', 'OBSERVACAO', 'TREINADOS', 'TREINADOS_A_VENCER', 'TREINADOS_VENCIDOS', 'ARQUIVO', 'PALAVRAS_CHAVE'],
    'button_options' => ['save' => true, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true],
    'fields' => [
        'CODTREINAMENTO' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        // Migração 408 (onda 3 da Revisão G, 24/11/2026): Tipo (só os ativos na lista) — define quem responde pela regra de Responsáveis e avisos do módulo.
        'CODTIPOTREINAMENTO' => ['label' => ['pt' => 'Tipo de treinamento', 'en' => 'Training type', 'es' => 'Tipo de capacitación'], 'foreign' => ['view' => 'ml_tipos_treinamento', 'value' => 'CODTIPOTREINAMENTO', 'label' => 'DSCTIPOTREINAMENTO', 'description' => 'DSCTIPOTREINAMENTO', 'filters' => ['ATIVO' => 1]], 'filter' => 'select', 'width' => 200, 'allow_empty' => true],
        // Migração 411: unidades do registro (botão Unidades); contam para a regra Tipo + Unidade de Responsáveis e avisos
        'UNIDADES' => ['label' => ['pt' => 'Unidades', 'en' => 'Units', 'es' => 'Unidades'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        // Migração 409 (onda 3 da Revisão G, 24/11/2026): campos novos da planilha (itens 104).
        'CARGA_HORARIA' => ['label' => ['pt' => 'Carga horária (h)', 'en' => 'Workload (h)', 'es' => 'Carga horaria (h)'], 'type' => 'number', 'filter' => 'text', 'width' => 120],
        'PERIODO_RECICLAGEM' => ['label' => ['pt' => 'Reciclagem (meses)', 'en' => 'Refresher (months)', 'es' => 'Reciclaje (meses)'], 'type' => 'number', 'filter' => 'text', 'width' => 140],
        'CONTROLE_INFLUENCIA' => ['label' => ['pt' => 'Controle / influência', 'en' => 'Control / influence', 'es' => 'Control / influencia'], 'options' => ['' => '', 'Controle' => ['pt' => 'Controle', 'en' => 'Control', 'es' => 'Control'], 'Influência' => ['pt' => 'Influência', 'en' => 'Influence', 'es' => 'Influencia']], 'filter' => 'select', 'width' => 140],
        'OBSERVACAO' => ['label' => ['pt' => 'Observação', 'en' => 'Notes', 'es' => 'Observación'], 'type' => 'textarea', 'filter' => 'text', 'width' => 320],
        // Migração 410: resumo dos treinados (botão Treinados)
        'TREINADOS' => ['label' => ['pt' => 'Treinados', 'en' => 'Trained', 'es' => 'Capacitados'], 'readonly' => true, 'filter' => 'text', 'width' => 100],
        'TREINADOS_A_VENCER' => ['label' => ['pt' => 'Treinados a vencer', 'en' => 'Trained due soon', 'es' => 'Capacitados por vencer'], 'readonly' => true, 'filter' => 'text', 'width' => 120],
        'TREINADOS_VENCIDOS' => ['label' => ['pt' => 'Treinados vencidos', 'en' => 'Trained expired', 'es' => 'Capacitados vencidos'], 'readonly' => true, 'filter' => 'text', 'width' => 120],
        'DSCTREINAMENTO' => ['label' => ['pt' => 'Descrição', 'en' => 'Description', 'es' => 'Descripción'], 'type' => 'textarea', 'filter' => 'text', 'width' => 420],
        'ARQUIVO' => ['label' => ['pt' => 'Arquivos', 'en' => 'Files', 'es' => 'Archivos'], 'readonly' => true, 'filter' => 'none', 'width' => 120],
        'PALAVRAS_CHAVE' => ['label' => ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave'], 'readonly' => true, 'filter' => 'text', 'width' => 360],
        'CODUSUARIO' => ['label' => ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable'], 'hidden' => true, 'readonly' => true, 'filter' => 'none']
    ]
]);
echo '' . IdiomaService::script([], require dirname(__DIR__) . '/components/textos_relacionamentos.php') . '<script src="../assets/js/modulo-legal-relacionamentos.js?v=3"></script><script src="../assets/js/modulo-legal-arquivos.js?v=12"></script>';
echo '<script src="../assets/js/unidades-combo.js?v=4"></script>';
