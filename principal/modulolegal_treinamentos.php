<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
// Dicas dos botões em Administração › Textos e idiomas (cadastro automático ao abrir como ADMINISTRADOR).
$tt = static fn(string $chave, array $padrao): string => htmlspecialchars(IdiomaService::t($chave, $padrao), ENT_QUOTES, 'UTF-8');

$language = strtolower((string)($_SESSION['idioma'] ?? $_SESSION['usuario']['idioma'] ?? 'pt'));
$pendingMode = !empty($_GET['minhas_pendencias']);
$responsibilityLabel = IdiomaService::t('modulolegal_treinamentos.botao.responsabilidades', ['pt' => 'Responsabilidades', 'en' => 'Responsibilities', 'es' => 'Responsabilidades']);
$relacionamentos = '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-ml-relacionamento="treinamentos_palavras_chaves" disabled title="' . $tt('modulolegal_treinamentos.botao.palavras_chave', ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave']) . '" aria-label="' . $tt('modulolegal_treinamentos.botao.palavras_chave', ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave']) . '"><i class="bi bi-tags"></i></button>';
$relacionamentos .= '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-context-child data-context-id="treinamento-responsabilidades" data-context-url="/principal/treinamento_ml_responsabilidades.php" data-context-fields="CODTREINAMENTO" data-context-title="' . htmlspecialchars($responsibilityLabel, ENT_QUOTES, 'UTF-8') . '" disabled title="' . htmlspecialchars($responsibilityLabel, ENT_QUOTES, 'UTF-8') . '" aria-label="' . htmlspecialchars($responsibilityLabel, ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-people"></i></button>';

ManutencaoLegalCadastroGrid::render([
    // Migração 341: Severidade × Probabilidade = Risco do item (obrigatório ao incluir; ver Ajuda de Requisitos legais)
    'risk_area' => 'TREINAMENTOS',
    'menu' => 'MODULOLEGAL_TREINAMENTOS',
    'title' => ['pt' => 'Treinamentos', 'en' => 'Training', 'es' => 'Capacitaciones'],
    'transaction' => 'modulolegal_treinamentos',
    'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'table' => 'ml_treinamentos', 'view' => $pendingMode ? 'vw_minhas_treinamentos_grid' : 'vw_modulolegal_treinamentos_grid',
    'primary' => 'CODTREINAMENTO', 'primary_auto' => true,
    'fragment_class' => 'modulo-legal-treinamentos-grid-fragment',
    'relacionamentos_endpoint' => 'api/modulo_legal_relacionamentos.php',
    'extras' => $relacionamentos,
    'legacy_order' => ['CODTREINAMENTO', 'DSCTREINAMENTO', 'PALAVRAS_CHAVE'],
    'user_scope_field' => 'CODUSUARIO', 'force_user_scope' => $pendingMode,
    // Migração 361: botão Responsáveis (registro selecionado); telas Minhas = Responsável + atribuições + regras do módulo
    'resp_area' => 'TREINAMENTOS', 'resp_minhas' => $pendingMode ? 'TREINAMENTOS' : '',
    'button_options' => $pendingMode ? ['save' => true, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true] : ['save' => true, 'new' => true, 'duplicate' => true, 'delete' => true, 'reload' => true, 'export' => true, 'columns' => true],
    'fields' => [
        'CODTREINAMENTO' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        'DSCTREINAMENTO' => ['label' => ['pt' => 'Descrição', 'en' => 'Description', 'es' => 'Descripción'], 'type' => 'textarea', 'filter' => 'text', 'width' => 420],
        'ARQUIVO' => ['label' => ['pt' => 'Arquivos', 'en' => 'Files', 'es' => 'Archivos'], 'readonly' => true, 'filter' => 'none', 'width' => 120],
        'PALAVRAS_CHAVE' => ['label' => ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave'], 'readonly' => true, 'filter' => 'text', 'width' => 360],
        // readonly (12/11/2026): ml_treinamentos não tem CODUSUARIO (vem das views de responsabilidade); sem isso a inclusão falhava com
        // "Campo nao autorizado na operacao de inclusao"
        'CODUSUARIO' => ['label' => ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
    ],
]);

echo '' . IdiomaService::script([], require dirname(__DIR__) . '/components/textos_relacionamentos.php') . '<script src="../assets/js/modulo-legal-relacionamentos.js?v=2"></script>';
echo '<script src="../assets/js/modulo-legal-arquivos.js?v=2"></script>';
