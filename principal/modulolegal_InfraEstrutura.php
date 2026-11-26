<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
// Dicas dos botões em Administração › Textos e idiomas (cadastro automático ao abrir como ADMINISTRADOR).
$tt = static fn(string $chave, array $padrao): string => htmlspecialchars(IdiomaService::t($chave, $padrao), ENT_QUOTES, 'UTF-8');

$pendingMode = !empty($_GET['minhas_pendencias']);

$relacionamentos = '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-context-child data-context-id="infraestrutura-manutencoes" data-context-url="/principal/ml_infraestrutura_manutencoes.php" data-context-fields="CODINFRAESTRUTURA" data-context-title="' . htmlspecialchars(IdiomaService::t('modulolegal_infraestrutura.botao.manutencao', ['pt' => 'Plano de manutenção', 'en' => 'Maintenance plan', 'es' => 'Plan de mantenimiento']), ENT_QUOTES, 'UTF-8') . '" disabled title="' . htmlspecialchars(IdiomaService::t('modulolegal_infraestrutura.botao.manutencao', ['pt' => 'Plano de manutenção', 'en' => 'Maintenance plan', 'es' => 'Plan de mantenimiento']), ENT_QUOTES, 'UTF-8') . '" aria-label="' . htmlspecialchars(IdiomaService::t('modulolegal_infraestrutura.botao.manutencao', ['pt' => 'Plano de manutenção', 'en' => 'Maintenance plan', 'es' => 'Plan de mantenimiento']), ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-tools"></i></button>' . '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-ml-relacionamento="infraestrutura_unidades" disabled title="' . htmlspecialchars(IdiomaService::t('modulolegal_infraestrutura.botao.unidades', ['pt' => 'Unidades', 'en' => 'Units', 'es' => 'Unidades']), ENT_QUOTES, 'UTF-8') . '" aria-label="' . htmlspecialchars(IdiomaService::t('modulolegal_infraestrutura.botao.unidades', ['pt' => 'Unidades', 'en' => 'Units', 'es' => 'Unidades']), ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-building"></i></button>' . '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-ml-relacionamento="infraestrutura_atributos" disabled title="' . $tt('modulolegal_infraestrutura.botao.atributos', ['pt' => 'Atributos', 'en' => 'Attributes', 'es' => 'Atributos']) . '" aria-label="' . $tt('modulolegal_infraestrutura.botao.atributos', ['pt' => 'Atributos', 'en' => 'Attributes', 'es' => 'Atributos']) . '"><i class="bi bi-list-columns"></i></button><button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-ml-relacionamento="infraestrutura_palavras_chaves" disabled title="' . $tt('modulolegal_infraestrutura.botao.palavras_chave', ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave']) . '" aria-label="' . $tt('modulolegal_infraestrutura.botao.palavras_chave', ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave']) . '"><i class="bi bi-tags"></i></button><button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-context-child data-context-id="infraestrutura-responsabilidades" data-context-url="/principal/infraestrutura_ml_responsabilidades.php" data-context-fields="CODINFRAESTRUTURA" data-context-title="' . $tt('modulolegal_infraestrutura.botao.responsabilidades', ['pt' => 'Responsabilidades', 'en' => 'Responsibilities', 'es' => 'Responsabilidades']) . '" disabled title="' . $tt('modulolegal_infraestrutura.botao.responsabilidades', ['pt' => 'Responsabilidades', 'en' => 'Responsibilities', 'es' => 'Responsabilidades']) . '" aria-label="' . $tt('modulolegal_infraestrutura.botao.responsabilidades', ['pt' => 'Responsabilidades', 'en' => 'Responsibilities', 'es' => 'Responsabilidades']) . '"><i class="bi bi-people"></i></button>';

ManutencaoLegalCadastroGrid::render([
    // Migração 341: Severidade × Probabilidade = Risco do item (obrigatório ao incluir; ver Ajuda de Requisitos legais)
    'risk_area' => 'INFRAESTRUTURA',
    'menu' => 'MODULOLEGAL_INFRAESTRUTURA',
    'title' => ['pt' => 'InfraEstrutura', 'en' => 'Infrastructure', 'es' => 'Infraestructura'],
    'transaction' => 'modulolegal_infraestrutura',
    'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'table' => 'ml_infraestrutura', 'view' => $pendingMode ? 'vw_minhas_infraestrutura_grid' : 'vw_modulolegal_infraestrutura_grid',
    'primary' => 'CODINFRAESTRUTURA', 'primary_auto' => true,
    'fragment_class' => 'modulo-legal-infraestrutura-grid-fragment',
    'relacionamentos_endpoint' => 'api/modulo_legal_relacionamentos.php',
    'extras' => $relacionamentos,
    // 25/11/2026: grade enxuta + ficha do registro em grupos (botão Detalhes, duplo clique ou ao incluir).
    'columns_version' => 20261125,
    'default_visible' => ['UNIDADES', 'DSCINFRAESTRUTURA', 'CODTIPOINFRAESTRUTURA', 'SETOR', 'CLASSE', 'SITUACAO_INFRA', 'MANUTENCOES_ATRASADAS', 'PROXIMA_MANUTENCAO_PREVISTA', 'ARQUIVO', 'RISCO_CLASSE'],
    'ficha' => [
        'grupos' => [
            ['titulo' => ['pt' => 'Identificação', 'en' => 'Identification', 'es' => 'Identificación'], 'campos' => ['DSCINFRAESTRUTURA', 'CODTIPOINFRAESTRUTURA', 'SETOR', 'CLASSE', 'PROPRIEDADE', 'CONDICOES', 'SITUACAO_INFRA', 'ARQUIVO']],
            ['titulo' => ['pt' => 'Manutenção', 'en' => 'Maintenance', 'es' => 'Mantenimiento'], 'campos' => ['MANUTENCOES', 'MANUTENCOES_ATRASADAS', 'PROXIMA_MANUTENCAO_PREVISTA']],
            ['titulo' => ['pt' => 'Aplicação e risco', 'en' => 'Scope and risk', 'es' => 'Aplicación y riesgo'], 'campos' => ['UNIDADES', ['botao' => '[data-resp-registro]', 'rotulo' => ['pt' => 'Responsáveis', 'en' => 'Responsible parties', 'es' => 'Responsables'], 'acao' => ['pt' => 'Ver e associar responsáveis', 'en' => 'View and assign', 'es' => 'Ver y asociar'], 'dica' => ['pt' => 'Um ou mais responsáveis.', 'en' => 'One or more people.', 'es' => 'Uno o más responsables.']], 'CONTROLE_INFLUENCIA', 'ATRIBUTOS', 'RISCO_SEVERIDADE', 'RISCO_PROBABILIDADE', 'RISCO_CLASSE']],
            ['titulo' => ['pt' => 'Observação', 'en' => 'Notes', 'es' => 'Observación'], 'campos' => ['OBSERVACAO']],
        ],
        'condicoes' => [],
    ],
    'legacy_order' => ['CODINFRAESTRUTURA', 'DSCINFRAESTRUTURA', 'CODTIPOINFRAESTRUTURA', 'UNIDADES', 'SETOR', 'CONDICOES', 'CLASSE', 'PROPRIEDADE', 'SITUACAO_INFRA', 'CONTROLE_INFLUENCIA', 'OBSERVACAO', 'MANUTENCOES', 'MANUTENCOES_ATRASADAS', 'PROXIMA_MANUTENCAO_PREVISTA', 'ATRIBUTOS', 'PALAVRAS_CHAVE'],
    'user_scope_field' => 'CODUSUARIO', 'force_user_scope' => $pendingMode,
    // Migração 361: botão Responsáveis (registro selecionado); telas Minhas = Responsável + atribuições + regras do módulo
    'resp_area' => 'INFRAESTRUTURA', 'resp_minhas' => $pendingMode ? 'INFRAESTRUTURA' : '',
    'button_options' => $pendingMode ? ['save' => true, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true] : ['save' => true, 'new' => true, 'duplicate' => true, 'delete' => true, 'reload' => true, 'export' => true, 'columns' => true],
    'fields' => [
        'CODINFRAESTRUTURA' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        // Migração 408 (onda 3 da Revisão G, 24/11/2026): Tipo (só os ativos na lista) — define quem responde pela regra de Responsáveis e avisos do módulo.
        'CODTIPOINFRAESTRUTURA' => ['label' => ['pt' => 'Tipo de infraestrutura', 'en' => 'Infrastructure type', 'es' => 'Tipo de infraestructura'], 'foreign' => ['view' => 'ml_tipos_infraestrutura', 'value' => 'CODTIPOINFRAESTRUTURA', 'label' => 'DSCTIPOINFRAESTRUTURA', 'description' => 'DSCTIPOINFRAESTRUTURA', 'filters' => ['ATIVO' => 1]], 'filter' => 'select', 'width' => 200, 'allow_empty' => true],
        // Migração 411: unidades do registro (botão Unidades); contam para a regra Tipo + Unidade de Responsáveis e avisos
        'UNIDADES' => ['label' => ['pt' => 'Unidades', 'en' => 'Units', 'es' => 'Unidades'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        // Migração 409 (onda 3 da Revisão G, 24/11/2026): campos novos da planilha (itens 119).
        'SETOR' => ['label' => ['pt' => 'Setor', 'en' => 'Sector', 'es' => 'Sector'], 'filter' => 'text', 'width' => 180],
        'CONDICOES' => ['label' => ['pt' => 'Condições', 'en' => 'Conditions', 'es' => 'Condiciones'], 'filter' => 'text', 'width' => 240],
        'CLASSE' => ['label' => ['pt' => 'Classe', 'en' => 'Class', 'es' => 'Clase'], 'options' => ['' => '', 'A' => 'A', 'B' => 'B', 'C' => 'C'], 'filter' => 'select', 'width' => 100],
        'PROPRIEDADE' => ['label' => ['pt' => 'Propriedade', 'en' => 'Ownership', 'es' => 'Propiedad'], 'options' => ['' => '', 'Própria' => ['pt' => 'Própria', 'en' => 'Owned', 'es' => 'Propia'], 'Terceiros' => ['pt' => 'Terceiros', 'en' => 'Third party', 'es' => 'Terceros'], 'Alugada' => ['pt' => 'Alugada', 'en' => 'Rented', 'es' => 'Alquilada']], 'filter' => 'select', 'width' => 140],
        'SITUACAO_INFRA' => ['label' => ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación'], 'options' => ['' => '', 'Em operação' => ['pt' => 'Em operação', 'en' => 'In operation', 'es' => 'En operación'], 'Em manutenção' => ['pt' => 'Em manutenção', 'en' => 'Under maintenance', 'es' => 'En mantenimiento'], 'Desativada' => ['pt' => 'Desativada', 'en' => 'Deactivated', 'es' => 'Desactivada']], 'filter' => 'select', 'width' => 160],
        'CONTROLE_INFLUENCIA' => ['label' => ['pt' => 'Controle / influência', 'en' => 'Control / influence', 'es' => 'Control / influencia'], 'options' => ['' => '', 'Controle' => ['pt' => 'Controle', 'en' => 'Control', 'es' => 'Control'], 'Influência' => ['pt' => 'Influência', 'en' => 'Influence', 'es' => 'Influencia']], 'filter' => 'select', 'width' => 140],
        'OBSERVACAO' => ['label' => ['pt' => 'Observação', 'en' => 'Notes', 'es' => 'Observación'], 'type' => 'textarea', 'filter' => 'text', 'width' => 320],
        // Migração 412: resumo do plano de manutenção (botão Plano de manutenção)
        'MANUTENCOES' => ['label' => ['pt' => 'Manutenções', 'en' => 'Maintenance items', 'es' => 'Mantenimientos'], 'readonly' => true, 'filter' => 'text', 'width' => 110],
        'MANUTENCOES_ATRASADAS' => ['label' => ['pt' => 'Manutenções atrasadas', 'en' => 'Late maintenance', 'es' => 'Mantenimientos atrasados'], 'readonly' => true, 'filter' => 'text', 'width' => 130],
        'PROXIMA_MANUTENCAO_PREVISTA' => ['label' => ['pt' => 'Próxima manutenção', 'en' => 'Next maintenance', 'es' => 'Próximo mantenimiento'], 'readonly' => true, 'filter' => 'text', 'width' => 140],
        'DSCINFRAESTRUTURA' => ['label' => ['pt' => 'Descrição', 'en' => 'Description', 'es' => 'Descripción'], 'type' => 'textarea', 'filter' => 'text', 'width' => 420],
        'ARQUIVO' => ['label' => ['pt' => 'Arquivos', 'en' => 'Files', 'es' => 'Archivos'], 'readonly' => true, 'filter' => 'none', 'width' => 120],
        'ATRIBUTOS' => ['label' => ['pt' => 'Atributos', 'en' => 'Attributes', 'es' => 'Atributos'], 'readonly' => true, 'filter' => 'text', 'width' => 420],
        'PALAVRAS_CHAVE' => ['label' => ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave'], 'readonly' => true, 'filter' => 'text', 'width' => 360],
        // readonly (12/11/2026): ml_infraestrutura não tem CODUSUARIO; sem isso a inclusão falhava ("Campo nao autorizado")
        'CODUSUARIO' => ['label' => ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
    ],
]);

echo '' . IdiomaService::script([], require dirname(__DIR__) . '/components/textos_relacionamentos.php') . '<script src="../assets/js/modulo-legal-relacionamentos.js?v=3"></script>';
echo '<script src="../assets/js/modulo-legal-arquivos.js?v=12"></script>';
echo '<script src="../assets/js/unidades-combo.js?v=5"></script><script src="../assets/js/ficha-registro.js?v=3"></script><script src="../assets/js/associacoes-contagem.js?v=6"></script>';
