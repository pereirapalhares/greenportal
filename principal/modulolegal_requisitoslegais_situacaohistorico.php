<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';

$redirectFilters = json_decode((string)($_GET['filtros'] ?? '{}'), true);
$redirectFilters = is_array($redirectFilters) ? $redirectFilters : [];
$situationCode = (int)($_GET['CODSITUACAO'] ?? ($redirectFilters['CODSITUACAO'] ?? 0));
$legislationId = (int)($_GET['CODLEGISLACAO'] ?? ($redirectFilters['CODLEGISLACAO'] ?? 0));
$_GET['filtros'] = json_encode($situationCode > 0 ? ['CODSITUACAO' => $situationCode] : ['CODLEGISLACAO' => $legislationId]);

ManutencaoLegalCadastroGrid::render([
    'context_crud' => $situationCode > 0,
    'context_crud_keys' => $situationCode > 0 ? ['CODSITUACAO'] : [],
    'menu' => 'REQUISITOS_LEGAIS',
    'title' => ['pt' => 'Histórico da situação', 'en' => 'Situation history', 'es' => 'Historial de situación'],
    'transaction' => 'modulolegal_requisitoslegais_situacaohistorico',
    'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'table' => 'situacaoxhistorico',
    'view' => 'vw_modulolegal_requisitos_situacaohistorico_grid',
    'primary' => 'CODHISTORICO',
    'primary_auto' => true,
    'read_only_grid' => true,
    'button_options' => ['save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => false],
    'legacy_order' => ['DESCRICAO', 'DATAHISTORICO', 'CODUSUARIO', 'CODHISTORICO', 'CODSITUACAO', 'CODIGO', 'UNIDADE', 'CODLEGISLACAO', 'CODUNIDADE', 'USUARIO', 'registroanterior'],
    'fields' => [
        'DESCRICAO' => ['label' => ['pt' => 'Descrição', 'en' => 'Description', 'es' => 'Descripción'], 'readonly' => true, 'type' => 'textarea', 'width' => 520],
        'DATAHISTORICO' => ['label' => ['pt' => 'Data do Histórico', 'en' => 'History date', 'es' => 'Fecha del historial'], 'readonly' => true, 'display_format' => 'date', 'width' => 150],
        'CODUSUARIO' => ['label' => ['pt' => 'Usuário', 'en' => 'User', 'es' => 'Usuario'], 'readonly' => true, 'display_from' => 'USUARIO', 'force_visible' => true, 'width' => 220],
        'CODHISTORICO' => ['label' => ['pt' => 'Código do histórico', 'en' => 'History code', 'es' => 'Código del historial'], 'hidden' => true, 'readonly' => true],
        'CODSITUACAO' => ['label' => ['pt' => 'Código da situação', 'en' => 'Situation code', 'es' => 'Código de situación'], 'hidden' => true, 'readonly' => true],
        'CODIGO' => ['label' => ['pt' => 'Código da legislação', 'en' => 'Legislation code', 'es' => 'Código de legislación'], 'hidden' => true, 'readonly' => true],
        'UNIDADE' => ['label' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'], 'hidden' => true, 'readonly' => true],
        'CODLEGISLACAO' => ['label' => ['pt' => 'Código interno da legislação', 'en' => 'Internal legislation code', 'es' => 'Código interno de legislación'], 'hidden' => true, 'readonly' => true],
        'CODUNIDADE' => ['label' => ['pt' => 'Código interno da unidade', 'en' => 'Internal unit code', 'es' => 'Código interno de unidad'], 'hidden' => true, 'readonly' => true],
        'USUARIO' => ['label' => ['pt' => 'Nome do usuário', 'en' => 'User name', 'es' => 'Nombre del usuario'], 'hidden' => true, 'readonly' => true],
        'registroanterior' => ['label' => ['pt' => 'Registro anterior', 'en' => 'Previous record', 'es' => 'Registro anterior'], 'hidden' => true, 'readonly' => true]
    ]
]);
