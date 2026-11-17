<?php
declare(strict_types=1);

/**
 * Grid local no padrão do portal (barra de ícones + Ajuda, "Buscar no grid", filtro e ordenação por coluna, paginação na barra),
 * para telas cujas linhas já vêm prontas do servidor e juntam várias fontes (Home › Minhas pendências, Agenda, Alertas).
 * O comportamento fica em assets/js/grid-local.js. Uso (dentro do <body>):
 *   greenPortalGridLocal([
 *     'transaction' => 'home_pendencias', 'fragment_class' => 'home-pendencias-grid-fragment', 'id' => 'homePendenciasGrid',
 *     'columns' => ['PILAR' => ['label' => 'Pilar', 'filter' => 'select', 'width' => '170px'], ...],   // filter: text | select | none
 *     'actions_label' => 'Abrir',                                                                        // coluna de ações (opcional)
 *     'rows' => [['cells' => ['PILAR' => ['text' => 'Licenças'], 'PRAZO' => ['html' => '...', 'sort' => '2026-10-18']], 'actions' => '<button ...>'], ...],
 *     'empty' => 'Nenhum item.', 'default_sort' => ['PRAZO', 'asc'], 'notice' => '', 'buttons' => ['reload' => true],
 *   ]);
 * Opcional (padrão dos grids do portal, c479 — usado pela Agenda):
 *   'open_button' => true          botão Abrir na barra, habilitado ao selecionar a linha (clique seleciona, duplo clique abre);
 *                                  cada linha traz 'open' => ['url' => '...', 'title' => '...'] (sem url: o botão fica desabilitado)
 *   'cell_boxes' => true           células no formato dos campos do grid padrão (caixa com borda, texto em uma linha e dica)
 *   'buttons' => ['columns' => true]  botão Colunas (mostrar/ocultar colunas; a escolha fica gravada neste navegador)
 * c537 (padrão das telas de NC):
 *   botão da barra com data-grid-acao-linha="editar" (em 'extras', ou o Novo/Excluir padrão por 'buttons' => ['delete' => true,
 *   'delete_attribute' => 'data-grid-acao-linha="excluir"']) aciona o elemento [data-linha-acao="editar"] da linha selecionada;
 *   'double_click_action' => 'editar'  duplo clique na linha aciona essa ação;
 *   'select_all' => true               caixa no cabeçalho da coluna de ações marca as [data-grid-marcar] das linhas visíveis.
 * c568: 'initial_group' => ['VALOR', 'rótulo'] filtra as linhas com data-grupo contendo VALOR ao abrir (removível; grid-local.js v6).
 * greenPortalGridLocalLinha($acoes): HTML escondido com os botões da linha (cada um com data-linha-acao) para pôr numa célula.
 */
require_once __DIR__ . '/botoes_crud.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';

if (!function_exists('greenPortalGridLocalHead')) {
    /** CSS do grid padrão (no <head>, depois do Bootstrap). */
    function greenPortalGridLocalHead(): void
    {
        echo '<link href="../assets/css/app.css?v=5" rel="stylesheet"><link href="../assets/css/design-system.css?v=3" rel="stylesheet"><link href="../assets/css/main.css?v=18" rel="stylesheet">';
        require_once __DIR__ . '/pagina_padrao.php';
        greenPortalEstiloPagina();
        echo '<style>html,body{height:100%}body{margin:0}'
            . '.grid-local-fragment .menu-batch-grid{table-layout:fixed;width:100%;font-size:11px}'
            . '.grid-local-fragment .menu-batch-grid td{min-width:0;word-wrap:break-word}'
            . '.grid-local-fragment th.grid-row-actions-heading{width:70px}.grid-local-fragment td.grid-local-acoes{text-align:center;white-space:nowrap}'
            . '.grid-local-prazo{display:inline-flex;align-items:center;gap:5px;white-space:nowrap}'
            . '.grid-local-prazo::before{content:"";width:8px;height:8px;border-radius:50%;background:transparent;flex:0 0 auto}'
            . '.grid-local-prazo[data-sinal="vencido"]::before{background:#d64545}.grid-local-prazo[data-sinal="a-vencer"]::before{background:#e0a800}.grid-local-prazo[data-sinal="no-prazo"]::before{background:#2e9d6a}'
            . '.grid-local-prazo[data-sinal="vencido"]{color:#b42318;font-weight:600}'
            . '.grid-local-vazio td{padding:28px!important;text-align:center;color:var(--gp-muted,#71877d)}'
            // Células no formato dos campos do grid padrão (input .grid-input do ManutencaoLegalCadastroGrid).
            . '.grid-local-campo{display:block;min-height:30px;padding:5px 6px;border:1px solid #dce9e2;border-radius:8px;background:#fbfdfc;color:#294a3f;font-size:11px;line-height:18px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
            // c537: o app.css (tela de login) dá ao .form-control altura de 49px e recuo de 45px; nas páginas com grid volta ao Bootstrap
            . '.gp-pagina .form-control{height:auto;padding:.375rem .75rem}.gp-pagina .form-control.form-control-sm{padding:.25rem .5rem}.gp-pagina textarea.form-control{height:auto}'
            . '.grid-local-caixas table.menu-batch-grid{width:100%!important;min-width:0!important;table-layout:fixed!important}.grid-local-fragment tbody tr[data-row]{cursor:pointer}.grid-local-fragment tbody tr.selected .grid-local-campo{background:#fff;border-color:#9fd3bb}</style>';
    }

    /** Data com o sinal de prazo (vermelho vencido, amarelo até 30 dias, verde no prazo). $sinal: vencido | a-vencer | no-prazo | ''. */
    function greenPortalGridLocalPrazo(string $texto, string $sinal = ''): string
    {
        if ($texto === '') return '';
        $titulos = [
            'vencido' => IdiomaService::t('home.prazo.vencido', ['pt' => 'Vencido', 'en' => 'Overdue', 'es' => 'Vencido']),
            'a-vencer' => IdiomaService::t('home.prazo.a_vencer', ['pt' => 'Vence em até 30 dias', 'en' => 'Due within 30 days', 'es' => 'Vence en hasta 30 días']),
            'no-prazo' => IdiomaService::t('home.prazo.no_prazo', ['pt' => 'No prazo', 'en' => 'On time', 'es' => 'En plazo']),
        ];
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        return '<span class="grid-local-prazo" data-sinal="' . $e($sinal) . '"' . (isset($titulos[$sinal]) ? ' title="' . $e($titulos[$sinal]) . '"' : '') . '>' . $e($texto) . '</span>';
    }

    /** Botão "Abrir" padrão (ícone), que abre a tela de origem em popup do portal. */
    function greenPortalGridLocalAbrir(string $url, string $tituloPopup, string $rotulo = ''): string
    {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $abrir = IdiomaService::t('home.abrir', ['pt' => 'Abrir', 'en' => 'Open', 'es' => 'Abrir']);
        return '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-grid-abrir="' . $e($url) . '" data-grid-titulo="' . $e($tituloPopup) . '" title="' . $e($abrir) . '" aria-label="' . $e($abrir . ($rotulo !== '' ? ': ' . $rotulo : '')) . '"><i class="bi bi-box-arrow-up-right"></i></button>';
    }

    function greenPortalGridLocal(array $cfg): void
    {
        $e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $t = static fn(string $chave, array $padrao): string => IdiomaService::t($chave, $padrao);
        $columns = (array)($cfg['columns'] ?? []);
        $rows = (array)($cfg['rows'] ?? []);
        $actions = (string)($cfg['actions_label'] ?? '');
        $sort = (array)($cfg['default_sort'] ?? []);
        $buttons = (array)($cfg['buttons'] ?? []) + ['save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => false, 'columns' => false];
        $boxes = !empty($cfg['cell_boxes']);
        $extras = (string)($cfg['extras'] ?? '');
        if (!empty($cfg['open_button'])) {
            $abrir = $t('home.abrir', ['pt' => 'Abrir', 'en' => 'Open', 'es' => 'Abrir']);
            $dica = $t('comum.grid.selecione_abrir', ['pt' => 'Selecione uma linha para abrir', 'en' => 'Select a row to open', 'es' => 'Seleccione una fila para abrir']);
            $extras = '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-grid-abrir-selecionado disabled title="' . $e($dica) . '" data-titulo-ativo="' . $e($abrir) . '" data-titulo-inativo="' . $e($dica) . '" aria-label="' . $e($abrir) . '"><i class="bi bi-box-arrow-up-right"></i></button>' . $extras;
        }
        $buscar = $t('comum.busca.coluna', ['pt' => 'Buscar', 'en' => 'Search', 'es' => 'Buscar']);
        $buscaGrid = $t('comum.busca.grid', ['pt' => 'Buscar no grid', 'en' => 'Search the grid', 'es' => 'Buscar en la cuadrícula']);
        $colspan = count($columns) + ($actions !== '' ? 1 : 0);
        $class = trim('setup-fragment menu-grid-fragment grid-local-fragment ' . (string)($cfg['fragment_class'] ?? '') . ($boxes ? ' grid-local-caixas' : ''));
        echo '<section class="' . $e($class) . '" data-grid-local data-transacao="' . $e($cfg['transaction'] ?? '') . '" data-page-size="' . (int)($cfg['page_size'] ?? 25) . '"'
            . ($sort ? ' data-default-sort-column="' . $e($sort[0] ?? '') . '" data-default-sort-direction="' . $e($sort[1] ?? 'asc') . '"' : '')
            // 'open_in_tab' (c517): Abrir / duplo clique abre a tela como aba do portal (main.php), não em popup
            . (!empty($cfg['open_in_tab']) ? ' data-abrir-aba' : '')
            // c537: ação do duplo clique quando a linha não tem 'open' (ex.: 'editar' → [data-linha-acao="editar"] da linha)
            . (($cfg['double_click_action'] ?? '') !== '' ? ' data-acao-duplo="' . $e($cfg['double_click_action']) . '"' : '')
            // c568 (dashboards): filtro de grupo inicial (linhas com data-grupo), removível na barra
            . (($cfg['initial_group'][0] ?? '') !== '' ? ' data-grupo-inicial="' . $e($cfg['initial_group'][0]) . '" data-grupo-inicial-rotulo="' . $e($cfg['initial_group'][1] ?? '') . '"' : '') . '>';
        echo '<div class="crud-toolbar menu-grid-toolbar">';
        greenPortalCrudButtons(['transaction' => (string)($cfg['transaction'] ?? ''), 'extras' => $extras] + $buttons);
        echo '<div class="crud-search"><i class="bi bi-search"></i><input type="search" placeholder="' . $e($buscaGrid) . '" aria-label="' . $e($buscaGrid) . '"></div></div>';
        if (($cfg['notice'] ?? '') !== '') echo '<div class="alert alert-warning py-2 mb-2" role="alert">' . $e($cfg['notice']) . '</div>';
        echo '<div class="setup-form menu-grid-form"><div class="menu-grid-scroll"><table class="table table-sm menu-batch-grid" id="' . $e($cfg['id'] ?? 'gridLocal') . '"><thead><tr>';
        foreach ($columns as $key => $col) {
            $label = (string)($col['label'] ?? $key);
            $filter = (string)($col['filter'] ?? 'text');
            echo '<th data-column="' . $e($key) . '"' . (!empty($col['width']) ? ' style="width:' . $e($col['width']) . '"' : '') . '><div class="column-heading"><span>' . $e($label) . '</span>';
            if ($filter === 'select') {
                $opcoes = [];
                foreach ($rows as $r) { $v = trim((string)($r['cells'][$key]['text'] ?? '')); if ($v !== '') $opcoes[$v] = true; }
                $opcoes = array_keys($opcoes); natcasesort($opcoes);
                echo '<select class="column-filter form-select form-select-sm" data-column-filter="' . $e($key) . '" aria-label="' . $e($label) . '"><option value="">' . $e($t('comum.filtro.todos', ['pt' => 'Todos', 'en' => 'All', 'es' => 'Todos'])) . '</option>';
                foreach ($opcoes as $o) echo '<option value="' . $e($o) . '">' . $e($o) . '</option>';
                echo '</select>';
            } elseif ($filter !== 'none') {
                echo '<input class="column-filter" type="search" data-column-filter="' . $e($key) . '" placeholder="' . $e($buscar) . '" aria-label="' . $e($label) . '">';
            }
            echo '</div></th>';
        }
        // c537: 'select_all' => true põe no cabeçalho da coluna de ações a caixa que marca as linhas visíveis ([data-grid-marcar])
        if ($actions !== '') echo '<th class="grid-row-actions-heading">' . (!empty($cfg['select_all']) ? '<input class="form-check-input" type="checkbox" data-grid-marcar-pagina title="' . $e($actions) . '" aria-label="' . $e($actions) . '">' : '<span>' . $e($actions) . '</span>') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $open = (array)($r['open'] ?? []);
            echo '<tr data-row' . (!empty($r['attrs']) ? ' ' . $r['attrs'] : '')
                . ((string)($open['url'] ?? '') !== '' ? ' data-grid-url="' . $e($open['url']) . '" data-grid-titulo="' . $e($open['title'] ?? '') . '"' : '') . '>';
            foreach ($columns as $key => $col) {
                $c = (array)($r['cells'][$key] ?? []);
                $texto = (string)($c['text'] ?? '');
                $conteudo = isset($c['html']) ? (string)$c['html'] : $e($texto);
                if ($boxes) $conteudo = '<div class="grid-local-campo" title="' . $e($c['title'] ?? $texto) . '">' . $conteudo . '</div>';
                echo '<td data-column="' . $e($key) . '"' . (isset($c['sort']) ? ' data-sort-value="' . $e($c['sort']) . '"' : '') . (!$boxes && !empty($c['title']) ? ' title="' . $e($c['title']) . '"' : '') . '>'
                    . $conteudo . '</td>';
            }
            if ($actions !== '') echo '<td class="grid-local-acoes">' . (string)($r['actions'] ?? '') . '</td>';
            echo '</tr>';
        }
        $vazio = (string)($cfg['empty'] ?? '');
        $semFiltro = $t('comum.msg.nenhum_filtro', ['pt' => 'Nenhum registro encontrado com esses filtros.', 'en' => 'No records match these filters.', 'es' => 'Ningún registro con estos filtros.']);
        echo '<tr class="grid-local-vazio" data-vazio' . ($rows ? ' hidden' : '') . '><td colspan="' . $colspan . '"><i class="bi bi-check2-circle fs-4 d-block mb-1"></i><span data-vazio-texto data-sem-filtro="' . $e($vazio) . '" data-com-filtro="' . $e($semFiltro) . '">' . $e($vazio) . '</span></td></tr>';
        echo '</tbody></table></div></div>';
        echo '<nav class="grid-pagination" aria-label="' . $e($t('comum.paginacao.rotulo', ['pt' => 'Paginação', 'en' => 'Pagination', 'es' => 'Paginación'])) . '"><button type="button" class="btn btn-sm btn-outline-secondary grid-page-prev" title="' . $e($t('comum.paginacao.anterior', ['pt' => 'Anterior', 'en' => 'Previous', 'es' => 'Anterior'])) . '"><i class="bi bi-chevron-left"></i></button><span class="grid-page-status"></span><button type="button" class="btn btn-sm btn-outline-secondary grid-page-next" title="' . $e($t('comum.paginacao.proxima', ['pt' => 'Próxima', 'en' => 'Next', 'es' => 'Siguiente'])) . '"><i class="bi bi-chevron-right"></i></button></nav>';
        echo '</section>';
    }

    /** c537: botões da linha, escondidos, acionados pela barra (data-grid-acao-linha). $botoes: html dos botões com data-linha-acao. */
    function greenPortalGridLocalLinha(string $botoes): string
    {
        return $botoes === '' ? '' : '<span class="d-none" data-linha-acoes>' . $botoes . '</span>';
    }

    /** Scripts do grid local (no fim do <body>). */
    function greenPortalGridLocalScripts(array $textosExtras = []): void
    {
        echo IdiomaService::script(['comum'], $textosExtras + [
            'comum.colunas.titulo' => ['pt' => 'Colunas do grid', 'en' => 'Grid columns', 'es' => 'Columnas de la cuadrícula'],
            'comum.botao.aplicar' => ['pt' => 'Aplicar', 'en' => 'Apply', 'es' => 'Aplicar'],
            'comum.paginacao.pagina' => ['pt' => 'Página', 'en' => 'Page', 'es' => 'Página'],
            'comum.paginacao.de' => ['pt' => 'de', 'en' => 'of', 'es' => 'de'],
            'comum.paginacao.registros' => ['pt' => 'registros', 'en' => 'records', 'es' => 'registros'],
        ]);
        echo '<script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script><script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script><script src="../assets/js/grid-local.js?v=7"></script>';
    }
}
