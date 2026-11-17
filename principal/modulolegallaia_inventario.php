<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';

ManutencaoLegalCadastroGrid::render([
    'menu' => 'LAIA',
    'title' => [
        'pt' => 'LAIA - Inventário',
        'en' => 'LAIA - Inventory',
        'es' => 'LAIA - Inventario',
    ],
    'transaction' => 'modulolegal_laia_inventario',
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'laia_inventario',
    'view' => 'vw_laia_inventario_grid',
    'primary' => 'CODINVENTARIO',
    'context_fields' => ['CODLAIA', 'CODUNIDADE'],
    'legacy_fixed' => true,
    'legacy_order' => ['CODINVENTARIO', 'CODLAIA', 'CODUNIDADE', 'DSCUNIDADE', 'QTD', 'DSCPROCEDIMENTO', 'ORIGEM', 'entrada_material'],
    'button_options' => ['save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true],
    'fields' => [
        'CODINVENTARIO' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'readonly' => true, 'hidden' => true, 'filter' => 'none'],
        'CODLAIA' => ['label' => ['pt' => 'Código LAIA', 'en' => 'LAIA code', 'es' => 'Código LAIA'], 'readonly' => true, 'hidden' => true, 'filter' => 'none'],
        'CODUNIDADE' => ['label' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'], 'readonly' => true, 'hidden' => true, 'filter' => 'none'],
        'DSCUNIDADE' => ['label' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'], 'readonly' => true, 'filter' => 'text', 'width' => 180],
        'QTD' => ['label' => ['pt' => 'Quantidade', 'en' => 'Quantity', 'es' => 'Cantidad'], 'readonly' => true, 'filter' => 'text', 'width' => 140],
        'DSCPROCEDIMENTO' => ['label' => ['pt' => 'Procedimento', 'en' => 'Procedure', 'es' => 'Procedimiento'], 'readonly' => true, 'filter' => 'text', 'width' => 280],
        'ORIGEM' => ['label' => ['pt' => 'Origem', 'en' => 'Origin', 'es' => 'Origen'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        'entrada_material' => ['label' => ['pt' => 'Entrada de material', 'en' => 'Material input', 'es' => 'Entrada de material'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
    ],
]);
