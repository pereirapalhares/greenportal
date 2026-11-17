<?php
declare(strict_types=1);

/**
 * Barra de botões em grupos rotulados — padrão do portal: Operações | Associações | Avaliação | Outros.
 *
 *  - Operações: botões padrão do grid (salvar, novo, duplicar, excluir, recarregar, exportar, colunas, ajuda).
 *  - Associações: vínculos do registro selecionado com outros cadastros (antes "Correlacionamentos").
 *  - Avaliação: questionários, objetivos e metas, auditorias.
 *  - Outros: qualquer botão não classificado (o script cria o grupo sozinho).
 *
 * Emite o CSS, a configuração e o script do componente assets/js/toolbar-grupos.js. Os botões não são
 * criados aqui: o script apenas os move para os grupos (mesmos atributos, eventos e permissões).
 *
 * greenPortalToolbarGrupos($fragmento, $grupos): configuração explícita de uma tela.
 *   $grupos: ['associacoes' => [seletores...], 'avaliacao' => [...]]; 'correlacionamentos' é aceito como
 *   sinônimo de 'associacoes'. Operações já inclui os botões padrão; basta passar os extras.
 * greenPortalToolbarGruposAuto(): configuração automática, emitida pela barra padrão (components/botoes_crud.php)
 *   em todas as telas; vale para as barras que não têm configuração explícita.
 */
require_once dirname(__DIR__) . '/classes/IdiomaService.php';

if (!function_exists('greenPortalToolbarGrupos')) {
    function greenPortalToolbarGruposRotulos(): array
    {
        $padrao = [
            'operacoes' => ['pt' => 'Operações', 'en' => 'Operations', 'es' => 'Operaciones'],
            'associacoes' => ['pt' => 'Associações', 'en' => 'Associations', 'es' => 'Asociaciones'],
            'avaliacao' => ['pt' => 'Avaliação', 'en' => 'Assessment', 'es' => 'Evaluación'],
            'outros' => ['pt' => 'Outros', 'en' => 'Others', 'es' => 'Otros'],
        ];
        $idioma = (string)($_SESSION['idioma'] ?? 'pt');
        $r = [];
        foreach ($padrao as $id => $textos) {
            $r[$id] = class_exists('IdiomaService') ? IdiomaService::t('comum.grupo.' . $id, $textos) : ($textos[$idioma] ?? $textos['pt']);
        }
        return $r;
    }

    function greenPortalToolbarGruposConfig(string $fragmento, array $grupos, bool $auto): array
    {
        $rotulos = greenPortalToolbarGruposRotulos();
        $padraoOperacoes = ['[data-crud-action="save"]', '[data-crud-action="new"]', '[data-crud-action="duplicate"]', '[data-crud-action="delete"]', '[data-crud-action="reload"]', '[data-crud-action="export"]', '[data-crud-action="columns"]', '[data-open-transacao-ajuda]'];
        if (isset($grupos['correlacionamentos'])) {
            $grupos['associacoes'] = array_merge((array)($grupos['associacoes'] ?? []), (array)$grupos['correlacionamentos']);
            unset($grupos['correlacionamentos']);
        }
        $config = ['fragmento' => $fragmento, 'auto' => $auto, 'rotuloOutros' => $rotulos['outros'], 'grupos' => []];
        foreach (['operacoes', 'associacoes', 'avaliacao'] as $id) {
            $botoes = (array)($grupos[$id] ?? []);
            if ($id === 'operacoes') $botoes = array_merge($padraoOperacoes, $botoes);
            $config['grupos'][] = ['id' => $id, 'rotulo' => $rotulos[$id], 'botoes' => array_values($botoes)];
        }
        return $config;
    }

    function greenPortalToolbarGruposEmitir(array $config): void
    {
        static $recursos = false;
        // JSON sem JSON_UNESCAPED_UNICODE: os acentos saem como \u.... e não são afetados
        // pela correção automática de acentuação do portal.
        echo '<script type="application/json" data-toolbar-grupos-config>' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
        if (!$recursos) {
            $recursos = true;
            echo '<link href="../assets/css/toolbar-grupos.css?v=3" rel="stylesheet">';
            echo '<script src="../assets/js/toolbar-grupos.js?v=8"></script>';
        }
    }

    function greenPortalToolbarGrupos(string $fragmento, array $grupos): void
    {
        greenPortalToolbarGruposEmitir(greenPortalToolbarGruposConfig($fragmento, $grupos, false));
    }

    function greenPortalToolbarGruposAuto(): void
    {
        static $emitido = false;
        if ($emitido) return;
        $emitido = true;
        // Telas com barra própria (sem botoes_crud.php): as operações são reconhecidas pelo ícone.
        $icones = ['bi-check2', 'bi-plus-lg', 'bi-copy', 'bi-trash', 'bi-arrow-clockwise', 'bi-file-earmark-excel', 'bi-layout-three-columns',
            'bi-question-circle', 'bi-upload', 'bi-download', 'bi-x-lg', 'bi-save', 'bi-pencil', 'bi-pencil-square', 'bi-eye'];
        greenPortalToolbarGruposEmitir(greenPortalToolbarGruposConfig('', [
            'operacoes' => array_map(static fn(string $i): string => 'button:has(> .' . $i . '):not([data-context-child])', $icones),
            'associacoes' => [
                '[data-context-child]:not([data-context-id^="questionario"]):not([data-context-url*="objetivosmetas"])',
                '[data-license-condicionantes]', '[data-license-palavras-chave]', '[data-license-responsabilidades]',
                '[data-license-requisitos]', '[data-license-arquivos]', '[data-control-relacionamento]', '[data-open-requisito-popup]',
            ],
            'avaliacao' => [
                '[data-context-id^="questionario"]', '[data-context-url*="objetivosmetas"]', '[data-open-questionnaire]',
            ],
        ], true));
    }
}
