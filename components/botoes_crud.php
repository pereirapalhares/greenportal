<?php
declare(strict_types=1);

/**
 * Barra padronizada de ações dos grids.
 *
 * O componente gera somente HTML e referências visuais. As ações continuam
 * sendo tratadas pelo JavaScript específico de cada transação.
 */
require_once dirname(__DIR__) . '/classes/IdiomaService.php';

if (!function_exists('greenPortalCrudButtons')) {
    function greenPortalCrudButtons(array $options = []): void
    {
        $language = (string)($options['language'] ?? ($_SESSION['idioma'] ?? 'pt'));
        $language = in_array($language, ['pt', 'en', 'es'], true) ? $language : 'pt';
        $formId = (string)($options['form_id'] ?? '');
        $permission = is_array($options['permission'] ?? null) ? $options['permission'] : [];
        $permissionTitle = (string)($options['permission_title'] ?? 'Perfil sem permissão de acesso!');
        $extras = (string)($options['extras'] ?? '');
        $transaction = trim((string)($options['transaction'] ?? ''));
        $newAttribute = (string)($options['new_attribute'] ?? 'data-new-cadastro');
        $duplicateAttribute = (string)($options['duplicate_attribute'] ?? 'data-duplicate-cadastro');
        $deleteAttribute = (string)($options['delete_attribute'] ?? 'data-delete-cadastro');
        $include = static fn(string $key): bool => ($options[$key] ?? true) !== false;
        $allowed = static fn(string $key): bool => !array_key_exists($key, $permission) || (bool)$permission[$key];
        // Textos do grupo COMUM (Administração › Textos e idiomas); os do código são o padrão.
        $padrao = [
            'save' => ['comum.botao.salvar', ['pt' => 'Salvar', 'en' => 'Save', 'es' => 'Guardar']],
            'new' => ['comum.botao.novo', ['pt' => 'Novo', 'en' => 'New', 'es' => 'Nuevo']],
            'duplicate' => ['comum.botao.duplicar', ['pt' => 'Duplicar', 'en' => 'Duplicate', 'es' => 'Duplicar']],
            'delete' => ['comum.botao.excluir', ['pt' => 'Excluir', 'en' => 'Delete', 'es' => 'Eliminar']],
            'reload' => ['comum.botao.recarregar', ['pt' => 'Recarregar', 'en' => 'Reload', 'es' => 'Recargar']],
            'export' => ['comum.botao.exportar', ['pt' => 'Exportar Excel', 'en' => 'Export to Excel', 'es' => 'Exportar a Excel']],
            'columns' => ['comum.botao.colunas', ['pt' => 'Colunas', 'en' => 'Columns', 'es' => 'Columnas']],
            'help' => ['comum.botao.ajuda', ['pt' => 'Ajuda', 'en' => 'Help', 'es' => 'Ayuda']],
        ];
        $escapeLabel = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $labels = [];
        foreach ($padrao as $acao => [$chave, $textos]) {
            $texto = class_exists('IdiomaService') ? IdiomaService::t($chave, $textos) : ($textos[$language] ?? $textos['pt']);
            $labels[$acao] = $escapeLabel($texto);
        }
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $lockedTitle = $escape($permissionTitle);

        echo '<link rel="stylesheet" href="../assets/css/botoes-crud.css?v=4">';
        echo '<div class="crud-actions crud-actions-standard" data-crud-buttons>';
        if ($include('save')) {
            $locked = !$allowed('pode_editar') && !$allowed('pode_incluir');
            echo '<button class="btn btn-sm btn-success grid-crud-button" type="submit" form="' . $escape($formId) . '" data-crud-action="save" data-save-cadastro' . ($locked ? ' disabled data-permission-locked="1"' : '') . ' title="' . ($locked ? $lockedTitle : $labels['save']) . '" aria-label="' . $labels['save'] . '"><i class="bi bi-check2"></i></button>';
        }
        if ($include('new')) {
            $locked = !$allowed('pode_incluir');
            echo '<button class="btn btn-sm btn-outline-success grid-crud-button" type="button" data-crud-action="new" ' . $newAttribute . ($locked ? ' disabled data-permission-locked="1"' : '') . ' title="' . ($locked ? $lockedTitle : $labels['new']) . '" aria-label="' . $labels['new'] . '"><i class="bi bi-plus-lg"></i></button>';
        }
        if ($include('duplicate')) {
            $locked = !$allowed('pode_incluir');
            echo '<button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-crud-action="duplicate" ' . $duplicateAttribute . ' disabled' . ($locked ? ' data-permission-locked="1"' : '') . ' title="' . ($locked ? $lockedTitle : $labels['duplicate']) . '" aria-label="' . $labels['duplicate'] . '"><i class="bi bi-copy"></i></button>';
        }
        if ($include('delete')) {
            $locked = !$allowed('pode_excluir');
            echo '<button class="btn btn-sm btn-outline-danger grid-crud-button" type="button" data-crud-action="delete" ' . $deleteAttribute . ' disabled' . ($locked ? ' data-permission-locked="1"' : '') . ' title="' . ($locked ? $lockedTitle : $labels['delete']) . '" aria-label="' . $labels['delete'] . '"><i class="bi bi-trash"></i></button>';
        }
        if ($include('reload')) {
            echo '<button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-crud-action="reload" data-reload-grid title="' . $labels['reload'] . '" aria-label="' . $labels['reload'] . '"><i class="bi bi-arrow-clockwise"></i></button>';
        }
        if ($include('export')) {
            $locked = !$allowed('pode_exportar_excel');
            echo '<button class="btn btn-sm btn-outline-success grid-crud-button" type="button" data-crud-action="export" data-export-cadastro' . ($locked ? ' disabled data-permission-locked="1"' : '') . ' title="' . ($locked ? $lockedTitle : $labels['export']) . '" aria-label="' . $labels['export'] . '"><i class="bi bi-file-earmark-excel"></i></button>';
        }
        if ($include('columns')) {
            echo '<button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-crud-action="columns" data-columns title="' . $labels['columns'] . '" aria-label="' . $labels['columns'] . '"><i class="bi bi-layout-three-columns"></i></button>';
        }
        if ($transaction !== '') {
            echo '<button class="btn btn-sm btn-outline-info grid-crud-button" type="button" data-open-transacao-ajuda data-help-transaction="' . $escape($transaction) . '" title="' . $labels['help'] . '" aria-label="' . $labels['help'] . '"><i class="bi bi-question-circle"></i></button>';
        }
        if ($extras !== '') echo '<span class="crud-context-separator" aria-hidden="true">|</span><span class="crud-context-actions" data-crud-context-actions>' . $extras . '</span>';
        if ($transaction !== '') echo '<script src="../assets/js/ajuda-transacao.js?v=1"></script>';
        // Barra em grupos rotulados (Operações | Associações | Avaliação | Outros) em todas as telas;
        // telas com configuração própria (greenPortalToolbarGrupos) têm prioridade.
        if (($options['grupos'] ?? true) !== false) {
            require_once __DIR__ . '/toolbar_grupos.php';
            greenPortalToolbarGruposAuto();
        }
        echo '</div>';
    }
}
