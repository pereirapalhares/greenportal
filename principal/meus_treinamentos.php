<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
// Dicas dos botões em Administração › Textos e idiomas (cadastro automático ao abrir como ADMINISTRADOR).
$tt = static fn(string $chave, array $padrao): string => htmlspecialchars(IdiomaService::t($chave, $padrao), ENT_QUOTES, 'UTF-8');

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
    'extras' => '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-ml-relacionamento="treinamentos_palavras_chaves" disabled title="' . $tt('meus_treinamentos.botao.palavras_chave', ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave']) . '" aria-label="' . $tt('meus_treinamentos.botao.palavras_chave', ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave']) . '"><i class="bi bi-tags"></i></button>',
    'legacy_order' => ['CODTREINAMENTO', 'DSCTREINAMENTO', 'ARQUIVO', 'PALAVRAS_CHAVE'],
    'button_options' => ['save' => true, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true],
    'fields' => [
        'CODTREINAMENTO' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        'DSCTREINAMENTO' => ['label' => ['pt' => 'Descrição', 'en' => 'Description', 'es' => 'Descripción'], 'type' => 'textarea', 'filter' => 'text', 'width' => 420],
        'ARQUIVO' => ['label' => ['pt' => 'Arquivos', 'en' => 'Files', 'es' => 'Archivos'], 'readonly' => true, 'filter' => 'none', 'width' => 120],
        'PALAVRAS_CHAVE' => ['label' => ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave'], 'readonly' => true, 'filter' => 'text', 'width' => 360],
        'CODUSUARIO' => ['label' => ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable'], 'hidden' => true, 'readonly' => true, 'filter' => 'none']
    ]
]);
echo '' . IdiomaService::script([], require dirname(__DIR__) . '/components/textos_relacionamentos.php') . '<script src="../assets/js/modulo-legal-relacionamentos.js?v=2"></script><script src="../assets/js/modulo-legal-arquivos.js?v=2"></script>';
