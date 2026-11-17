<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
ManutencaoLegalCadastroGrid::render([
    'menu' => 'TIPOS_DOCUMENTOS_LICENCAS',
    'title' => ['pt' => 'Tipos documentais de licenças', 'en' => 'License document types', 'es' => 'Tipos documentales de licencias'],
    'transaction' => 'manutencaolegal_tiposdocumentos',
    'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'table' => 'li_tipos_documentos', 'view' => 'li_tipos_documentos',
    'primary' => 'CODTIPODOCUMENTO',
    'default_sort' => ['column' => 'ORDEM', 'direction' => 'asc'],
    'fields' => [
        'CODTIPODOCUMENTO' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'readonly' => true],
        'DSCTIPODOCUMENTO' => ['label' => ['pt' => 'Tipo documental', 'en' => 'Document type', 'es' => 'Tipo documental'], 'type' => 'textarea', 'width' => 320],
        // Natureza (migração 284): entrega periódica não vira "vencida sem protocolo" na situação legal calculada
        'NATUREZA' => ['label' => ['pt' => 'Natureza', 'en' => 'Nature', 'es' => 'Naturaleza'], 'options' => ['LICENCA' => ['pt' => 'Licença (renovação com protocolo)', 'en' => 'License (renewal with protocol)', 'es' => 'Licencia (renovación con protocolo)'], 'ENTREGA_PERIODICA' => ['pt' => 'Entrega periódica (relatório, declaração, inventário)', 'en' => 'Periodic submission (report, declaration, inventory)', 'es' => 'Entrega periódica (informe, declaración, inventario)']], 'filter' => 'select', 'width' => 260],
        'ORDEM' => ['label' => ['pt' => 'Ordem', 'en' => 'Order', 'es' => 'Orden'], 'type' => 'number', 'width' => 90],
        'ATIVO' => ['label' => ['pt' => 'Ativo', 'en' => 'Active', 'es' => 'Activo'], 'options' => ['1' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí'], '0' => ['pt' => 'Não', 'en' => 'No', 'es' => 'No']], 'filter' => 'select', 'width' => 90],
    ],
]);
