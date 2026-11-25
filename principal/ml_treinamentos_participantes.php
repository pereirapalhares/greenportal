<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';

/*
 * Migração 410 (onda 3 da Revisão G, 25/11/2026, item TRE-104): trabalhadores treinados do treinamento selecionado
 * (botão "Treinados" de Treinamentos e Meus treinamentos). Validade = realização + reciclagem do treinamento;
 * reação = validade − dias de reação (sem dias, 30); status calculado na view. Certificados no GED.
 */
$classificacoes = ['' => '',
    'Aprovado' => ['pt' => 'Aprovado', 'en' => 'Passed', 'es' => 'Aprobado'],
    'Reprovado' => ['pt' => 'Reprovado', 'en' => 'Failed', 'es' => 'Reprobado'],
    'Participou' => ['pt' => 'Participou', 'en' => 'Attended', 'es' => 'Participó']];

ManutencaoLegalCadastroGrid::render([
    'menu' => 'MODULOLEGAL_TREINAMENTOS',
    'title' => ['pt' => 'Treinados', 'en' => 'Trained workers', 'es' => 'Capacitados'],
    'transaction' => 'ml_treinamentos_participantes',
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'ml_treinamentos_participantes',
    'view' => 'vw_ml_treinamentos_participantes_grid',
    'primary' => 'CODPARTICIPANTE',
    'primary_auto' => true,
    'fragment_class' => 'ml-treinamentos-participantes-grid-fragment',
    'context_fields' => ['CODTREINAMENTO'],
    'button_options' => ['save' => true, 'new' => true, 'duplicate' => true, 'delete' => true, 'reload' => true, 'export' => true, 'columns' => true],
    'legacy_order' => ['MATRICULA', 'NOME', 'CARGO', 'SETOR', 'CODUSUARIO', 'DATA_REALIZACAO', 'VALIDADE_TREINADO', 'DIAS_REACAO', 'REACAO_TREINADO', 'STATUS_TREINADO', 'CLASSIFICACAO', 'ARQUIVO', 'OBSERVACAO'],
    'fields' => [
        'CODPARTICIPANTE' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'readonly' => true, 'hidden' => true, 'filter' => 'none'],
        'CODTREINAMENTO' => ['label' => ['pt' => 'Treinamento', 'en' => 'Training', 'es' => 'Capacitación'], 'readonly' => true, 'hidden' => true],
        'MATRICULA' => ['label' => ['pt' => 'Matrícula', 'en' => 'Employee ID', 'es' => 'Legajo'], 'filter' => 'text', 'width' => 120],
        'NOME' => ['label' => ['pt' => 'Nome', 'en' => 'Name', 'es' => 'Nombre'], 'filter' => 'text', 'width' => 240, 'required' => true],
        'CARGO' => ['label' => ['pt' => 'Cargo', 'en' => 'Job title', 'es' => 'Cargo'], 'filter' => 'text', 'width' => 180],
        'SETOR' => ['label' => ['pt' => 'Setor', 'en' => 'Sector', 'es' => 'Sector'], 'filter' => 'text', 'width' => 160],
        'CODUSUARIO' => ['label' => ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable'], 'filter' => 'select', 'width' => 180, 'allow_empty' => true, 'foreign' => ['view' => 'vw_usuarios_grid', 'value' => 'codusuario', 'label' => 'nome', 'filters' => ['ativo' => 1], 'description' => 'nome']],
        'DATA_REALIZACAO' => ['label' => ['pt' => 'Data de realização', 'en' => 'Completion date', 'es' => 'Fecha de realización'], 'type' => 'date', 'filter' => 'text', 'width' => 150],
        'VALIDADE_TREINADO' => ['label' => ['pt' => 'Validade', 'en' => 'Valid until', 'es' => 'Validez'], 'readonly' => true, 'filter' => 'text', 'width' => 130,
            'tooltip' => ['pt' => 'Data de realização + reciclagem do treinamento (meses)', 'en' => 'Completion date + training refresher period (months)', 'es' => 'Fecha de realización + reciclaje de la capacitación (meses)']],
        'DIAS_REACAO' => ['label' => ['pt' => 'Dias de reação', 'en' => 'Reaction days', 'es' => 'Días de reacción'], 'type' => 'number', 'filter' => 'text', 'width' => 120,
            'tooltip' => ['pt' => 'Quantos dias antes da validade o treinado passa a "A vencer" (vazio = 30)', 'en' => 'Days before expiry when the worker becomes "Due soon" (empty = 30)', 'es' => 'Días antes de la validez en que pasa a "Por vencer" (vacío = 30)']],
        'REACAO_TREINADO' => ['label' => ['pt' => 'Data de reação', 'en' => 'Reaction date', 'es' => 'Fecha de reacción'], 'readonly' => true, 'filter' => 'text', 'width' => 130],
        'STATUS_TREINADO' => ['label' => ['pt' => 'Status', 'en' => 'Status', 'es' => 'Estado'], 'readonly' => true, 'filter' => 'select', 'width' => 130,
            'options' => ['Não realizado' => ['pt' => 'Não realizado', 'en' => 'Not done', 'es' => 'No realizado'], 'Reprovado' => ['pt' => 'Reprovado', 'en' => 'Failed', 'es' => 'Reprobado'],
                'Realizado' => ['pt' => 'Realizado', 'en' => 'Done', 'es' => 'Realizado'], 'Válido' => ['pt' => 'Válido', 'en' => 'Valid', 'es' => 'Válido'],
                'A vencer' => ['pt' => 'A vencer', 'en' => 'Due soon', 'es' => 'Por vencer'], 'Vencido' => ['pt' => 'Vencido', 'en' => 'Expired', 'es' => 'Vencido']]],
        'CLASSIFICACAO' => ['label' => ['pt' => 'Classificação', 'en' => 'Result', 'es' => 'Clasificación'], 'options' => $classificacoes, 'filter' => 'select', 'width' => 140],
        'ARQUIVO' => ['label' => ['pt' => 'Certificados', 'en' => 'Certificates', 'es' => 'Certificados'], 'readonly' => true, 'filter' => 'none', 'width' => 120],
        'OBSERVACAO' => ['label' => ['pt' => 'Observação', 'en' => 'Notes', 'es' => 'Observación'], 'type' => 'textarea', 'filter' => 'text', 'width' => 280],
    ],
]);

echo '<script src="../assets/js/modulo-legal-arquivos.js?v=12"></script>';
