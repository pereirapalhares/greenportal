<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
// Dicas dos botões em Administração › Textos e idiomas (cadastro automático ao abrir como ADMINISTRADOR).
$tt = static fn(string $chave, array $padrao): string => htmlspecialchars(IdiomaService::t($chave, $padrao), ENT_QUOTES, 'UTF-8');

ManutencaoLegalCadastroGrid::render([
    // Migração 341: Severidade × Probabilidade = Risco do item (obrigatório ao incluir; ver Ajuda de Requisitos legais)
    'risk_area' => 'INFRAESTRUTURA',
    'menu' => 'MINHAS_INFRAESTRUTURAS',
    'title' => ['pt' => 'Minhas Infraestruturas', 'en' => 'My Infrastructure', 'es' => 'Mis Infraestructuras'],
    'transaction' => 'minhas_infraestruturas',
    'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'table' => 'ml_infraestrutura', 'view' => 'vw_minhas_infraestrutura_grid',
    'primary' => 'CODINFRAESTRUTURA', 'primary_auto' => true,
    'fragment_class' => 'modulo-legal-infraestrutura-grid-fragment',
    'user_scope_field' => 'CODUSUARIO', 'force_user_scope' => true,
    // Migração 361: botão Responsáveis (registro selecionado); telas Minhas = Responsável + atribuições + regras do módulo
    'resp_area' => 'INFRAESTRUTURA', 'resp_minhas' => 'INFRAESTRUTURA',
    'relacionamentos_endpoint' => 'api/modulo_legal_relacionamentos.php',
    'extras' => '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-ml-relacionamento="infraestrutura_atributos" disabled title="' . $tt('minhas_infraestruturas.botao.atributos', ['pt' => 'Atributos', 'en' => 'Attributes', 'es' => 'Atributos']) . '" aria-label="' . $tt('minhas_infraestruturas.botao.atributos', ['pt' => 'Atributos', 'en' => 'Attributes', 'es' => 'Atributos']) . '"><i class="bi bi-list-columns"></i></button><button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-ml-relacionamento="infraestrutura_palavras_chaves" disabled title="' . $tt('minhas_infraestruturas.botao.palavras_chave', ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave']) . '" aria-label="' . $tt('minhas_infraestruturas.botao.palavras_chave', ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave']) . '"><i class="bi bi-tags"></i></button>',
    'legacy_order' => ['CODINFRAESTRUTURA', 'DSCINFRAESTRUTURA', 'ARQUIVO', 'ATRIBUTOS', 'PALAVRAS_CHAVE'],
    'button_options' => ['save' => true, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true],
    'fields' => [
        'CODINFRAESTRUTURA' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        'DSCINFRAESTRUTURA' => ['label' => ['pt' => 'Descrição', 'en' => 'Description', 'es' => 'Descripción'], 'type' => 'textarea', 'filter' => 'text', 'width' => 420],
        'ARQUIVO' => ['label' => ['pt' => 'Arquivos', 'en' => 'Files', 'es' => 'Archivos'], 'readonly' => true, 'filter' => 'none', 'width' => 120],
        'ATRIBUTOS' => ['label' => ['pt' => 'Atributos', 'en' => 'Attributes', 'es' => 'Atributos'], 'readonly' => true, 'filter' => 'text', 'width' => 420],
        'PALAVRAS_CHAVE' => ['label' => ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave'], 'readonly' => true, 'filter' => 'text', 'width' => 360],
        'CODUSUARIO' => ['label' => ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable'], 'hidden' => true, 'readonly' => true, 'filter' => 'none']
    ]
]);
echo '' . IdiomaService::script([], require dirname(__DIR__) . '/components/textos_relacionamentos.php') . '<script src="../assets/js/modulo-legal-relacionamentos.js?v=2"></script><script src="../assets/js/modulo-legal-arquivos.js?v=2"></script>';
