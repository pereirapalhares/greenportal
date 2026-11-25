<?php
declare(strict_types=1);
// Migração 408 (onda 3 da Revisão G, 24/11/2026): catálogo de Tipos de treinamento — o Tipo do cadastro de Treinamentos e das regras de
// Responsáveis e avisos do módulo (Tipo + unidade → responsáveis). Só o catálogo da empresa.
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
ManutencaoLegalCadastroGrid::render(['fragment_class' => 'ml-tipos-treinamento-grid-fragment',
    'menu' => 'ML_TIPOS_TREINAMENTO', 'title' => ['pt' => 'Tipos de treinamento', 'en' => 'Training types', 'es' => 'Tipos de capacitación'],
    'transaction' => 'ml_tipos_treinamento', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'table' => 'ml_tipos_treinamento', 'view' => 'ml_tipos_treinamento', 'primary' => 'CODTIPOTREINAMENTO', 'primary_auto' => true,
    'default_sort' => ['column' => 'DSCTIPOTREINAMENTO', 'direction' => 'asc'],
    'legacy_order' => ['DSCTIPOTREINAMENTO', 'ATIVO', 'CODTIPOTREINAMENTO'],
    'fields' => [
        'CODTIPOTREINAMENTO' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'readonly' => true, 'hidden' => true, 'filter' => 'none'],
        'DSCTIPOTREINAMENTO' => ['label' => ['pt' => 'Tipo de treinamento', 'en' => 'Training type', 'es' => 'Tipo de capacitación'], 'filter' => 'text', 'width' => 420],
        'ATIVO' => ['label' => ['pt' => 'Ativo', 'en' => 'Active', 'es' => 'Activo'], 'options' => ['1' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí'], '0' => ['pt' => 'Não', 'en' => 'No', 'es' => 'No']], 'filter' => 'select', 'width' => 100],
    ],
]);
require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.ml-tipos-treinamento-grid-fragment', []);
// Tipo novo começa ativo (a lista Ativo vem em ordem: Não, Sim).
echo "<script>document.addEventListener('click', function (ev) { if (!ev.target.closest || !ev.target.closest('[data-new-cadastro]')) return; window.setTimeout(function () { var s = document.querySelector('.menu-batch-grid tbody tr[data-key^=\"novo_\"] select[name$=\"[ATIVO]\"]'); if (s && s.value !== '1') { s.value = '1'; s.dispatchEvent(new Event('change', { bubbles: true })); } }, 30); }, true);</script>";
