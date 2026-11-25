<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';

/*
 * Migração 412 (onda 3 da Revisão G, 25/11/2026, item INF-119): plano de manutenção da infraestrutura selecionada
 * (botão "Plano de manutenção" de Infraestrutura e Minhas infraestruturas). Próxima data = realizada + periodicidade;
 * status calculado na view (Realizada, Atrasada, Próxima em 7 dias, Programada, Sem data). Evidências no GED.
 */
ManutencaoLegalCadastroGrid::render([
    'menu' => 'MODULOLEGAL_INFRAESTRUTURA',
    'title' => ['pt' => 'Plano de manutenção', 'en' => 'Maintenance plan', 'es' => 'Plan de mantenimiento'],
    'transaction' => 'ml_infraestrutura_manutencoes',
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'ml_infraestrutura_manutencoes',
    'view' => 'vw_ml_infraestrutura_manutencoes_grid',
    'primary' => 'CODMANUTENCAO',
    'primary_auto' => true,
    'fragment_class' => 'ml-infraestrutura-manutencoes-grid-fragment',
    'context_fields' => ['CODINFRAESTRUTURA'],
    'button_options' => ['save' => true, 'new' => true, 'duplicate' => true, 'delete' => true, 'reload' => true, 'export' => true, 'columns' => true],
    'legacy_order' => ['ATIVIDADE', 'PERIODICIDADE_MESES', 'CODUSUARIO', 'DATA_PREVISTA', 'DATA_REALIZADA', 'PROXIMA_MANUTENCAO', 'STATUS_MANUTENCAO', 'ARQUIVO', 'OBSERVACAO'],
    'fields' => [
        'CODMANUTENCAO' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'readonly' => true, 'hidden' => true, 'filter' => 'none'],
        'CODINFRAESTRUTURA' => ['label' => ['pt' => 'Infraestrutura', 'en' => 'Infrastructure', 'es' => 'Infraestructura'], 'readonly' => true, 'hidden' => true],
        'ATIVIDADE' => ['label' => ['pt' => 'Atividade de manutenção', 'en' => 'Maintenance activity', 'es' => 'Actividad de mantenimiento'], 'type' => 'textarea', 'filter' => 'text', 'width' => 320, 'required' => true],
        'PERIODICIDADE_MESES' => ['label' => ['pt' => 'Periodicidade (meses)', 'en' => 'Frequency (months)', 'es' => 'Periodicidad (meses)'], 'type' => 'number', 'filter' => 'text', 'width' => 130],
        'CODUSUARIO' => ['label' => ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable'], 'filter' => 'select', 'width' => 180, 'allow_empty' => true, 'foreign' => ['view' => 'vw_usuarios_grid', 'value' => 'codusuario', 'label' => 'nome', 'filters' => ['ativo' => 1], 'description' => 'nome']],
        'DATA_PREVISTA' => ['label' => ['pt' => 'Data prevista', 'en' => 'Planned date', 'es' => 'Fecha prevista'], 'type' => 'date', 'filter' => 'text', 'width' => 140],
        'DATA_REALIZADA' => ['label' => ['pt' => 'Data realizada', 'en' => 'Done on', 'es' => 'Fecha realizada'], 'type' => 'date', 'filter' => 'text', 'width' => 140],
        'PROXIMA_MANUTENCAO' => ['label' => ['pt' => 'Próxima manutenção', 'en' => 'Next maintenance', 'es' => 'Próximo mantenimiento'], 'readonly' => true, 'filter' => 'text', 'width' => 140,
            'tooltip' => ['pt' => 'Data realizada + periodicidade (meses). Para programar, inclua uma nova linha com essa data prevista.', 'en' => 'Done date + frequency (months). To schedule it, add a new line with that planned date.', 'es' => 'Fecha realizada + periodicidad (meses). Para programarlo, agregue una nueva línea con esa fecha prevista.']],
        'STATUS_MANUTENCAO' => ['label' => ['pt' => 'Status', 'en' => 'Status', 'es' => 'Estado'], 'readonly' => true, 'filter' => 'select', 'width' => 120,
            'options' => ['Realizada' => ['pt' => 'Realizada', 'en' => 'Done', 'es' => 'Realizada'], 'Atrasada' => ['pt' => 'Atrasada', 'en' => 'Late', 'es' => 'Atrasada'],
                'Próxima' => ['pt' => 'Próxima', 'en' => 'Due soon', 'es' => 'Próxima'], 'Programada' => ['pt' => 'Programada', 'en' => 'Scheduled', 'es' => 'Programada'],
                'Sem data' => ['pt' => 'Sem data', 'en' => 'No date', 'es' => 'Sin fecha']]],
        'ARQUIVO' => ['label' => ['pt' => 'Evidências', 'en' => 'Evidence', 'es' => 'Evidencias'], 'readonly' => true, 'filter' => 'none', 'width' => 120],
        'OBSERVACAO' => ['label' => ['pt' => 'Observação', 'en' => 'Notes', 'es' => 'Observación'], 'type' => 'textarea', 'filter' => 'text', 'width' => 280],
    ],
]);

echo '<script src="../assets/js/modulo-legal-arquivos.js?v=12"></script>';
