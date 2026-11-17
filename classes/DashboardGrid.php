<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/components/grid_local.php';

/**
 * Migração 368 (16/11/2026) — listas dos dashboards no grid padrão do portal e quadros em sub-abas.
 * grid():    grid local (components/grid_local.php + assets/js/grid-local.js v6): barra de ícones (Abrir, Recarregar, Colunas,
 *            Ajuda), "Buscar no grid", filtro e ordenação por coluna, 25 por página; Abrir / duplo clique abrem o registro em popup.
 *            Cada linha pode ter 'grupos' (ex.: 'GESTOR MAIS1ANO') para os cartões filtrarem (data-dash-filtro="id-do-grid").
 * subabas(): um quadro por vez abaixo dos cartões (o usuário não precisa rolar a página); o cartão abre a sub-aba do grid.
 * O dashboard (assets/js/dashboard-abas.js) monta os grids das abas carregadas depois (window.greenPortalGridLocal.iniciar).
 * Migração 369: vale também para páginas comuns (ex.: homologacaofornecedor_ranking_detalhe.php) — head() traz o CSS das
 * sub-abas e scripts() o assets/js/subabas.js; subabas($id, $abas, true) lembra a última sub-aba aberta.
 */
final class DashboardGrid
{
    private static function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

    /**
     * @param array $colunas chave => ['label' => ..., 'filter' => text|select|none, 'width' => '120px']
     * @param array $linhas  [['cells' => [chave => 'texto' | ['text' => , 'html' => , 'sort' => , 'title' => ]],
     *                        'open' => ['url' => , 'title' => ], 'grupos' => 'A B'], ...]
     * @param array $op      'vazio', 'ordem' => [coluna, asc|desc], 'transacao' (Ajuda), 'aviso', 'por_pagina',
     *                       'grupo_inicial' => ['GRUPO', 'rótulo'] (filtro já aplicado ao abrir, removível),
     *                       'extras' (HTML de botões da barra; data-grid-acao-linha="x" aciona [data-linha-acao="x"] da linha
     *                       selecionada — greenPortalGridLocalLinha()), 'acao_duplo' (ação do duplo clique sem 'open')
     */
    public static function grid(string $id, array $colunas, array $linhas, array $op = []): string
    {
        $rows = [];
        foreach ($linhas as $l) {
            $cells = [];
            foreach ((array)($l['cells'] ?? []) as $k => $c) $cells[$k] = is_array($c) ? $c : ['text' => (string)$c];
            $rows[] = ['cells' => $cells, 'open' => (array)($l['open'] ?? []),
                'attrs' => trim(((string)($l['grupos'] ?? '') !== '' ? 'data-grupo="' . self::e($l['grupos']) . '"' : '') . ' ' . (string)($l['attrs'] ?? ''))];
        }
        ob_start();
        greenPortalGridLocal([
            'transaction' => (string)($op['transacao'] ?? ''), 'id' => $id, 'fragment_class' => 'dash-grid',
            'columns' => $colunas, 'rows' => $rows, 'open_button' => true, 'cell_boxes' => false,
            'empty' => (string)($op['vazio'] ?? IdiomaService::t('dashboard.grid.vazio', ['pt' => 'Nenhum registro.', 'en' => 'No records.', 'es' => 'Ningún registro.'])),
            'default_sort' => (array)($op['ordem'] ?? []), 'notice' => (string)($op['aviso'] ?? ''), 'page_size' => (int)($op['por_pagina'] ?? 25),
            'buttons' => ['reload' => true, 'columns' => true, 'grupos' => false],
            'initial_group' => (array)($op['grupo_inicial'] ?? []),
            // Migração 370: botões extras na barra (ex.: Aprovar/Rejeitar com data-grid-acao-linha) e ação do duplo clique
            'extras' => (string)($op['extras'] ?? ''), 'double_click_action' => (string)($op['acao_duplo'] ?? ''),
        ]);
        return (string)ob_get_clean();
    }

    /** [url, title] de um atributo data-abrir-transacao/data-titulo (helpers $abrir dos dashboards). */
    public static function abrirDe(string $atributos): array
    {
        $u = preg_match('/data-abrir-transacao="([^"]*)"/', $atributos, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
        $t = preg_match('/data-titulo="([^"]*)"/', $atributos, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
        return $u !== '' ? ['url' => $u, 'title' => $t] : [];
    }

    /** Abrir a situação do requisito (mesma tela do data-situacao). */
    public static function abrirSituacao(int $codLegislacao, int $codUnidade, string $codigo): array
    {
        return ['url' => 'manutencaolegalrequisitoslegais_situacao.php?CODLEGISLACAO=' . $codLegislacao . '&CODUNIDADE=' . $codUnidade,
            'title' => IdiomaService::t('dashboard.legal.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']) . ' - ' . $codigo];
    }

    /** Data no formato do idioma com o sinal de prazo (vencido / até 30 dias / no prazo) e a chave de ordenação. */
    public static function prazo(mixed $data, bool $comSinal = true): array
    {
        $v = substr(trim((string)$data), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || $v <= '1900-01-01') return ['text' => '', 'sort' => '9999-12-31'];
        $txt = date(IdiomaService::idioma() === 'en' ? 'm/d/Y' : 'd/m/Y', strtotime($v));
        $hoje = date('Y-m-d');
        $sinal = !$comSinal ? '' : ($v < $hoje ? 'vencido' : ($v <= date('Y-m-d', strtotime('+30 days')) ? 'a-vencer' : 'no-prazo'));
        return ['text' => $txt, 'html' => greenPortalGridLocalPrazo($txt, $sinal), 'sort' => $v];
    }

    /** Número com cor (status-danger/status-warning) mantendo a ordenação numérica. */
    public static function numero(int|float|string $n, string $classe = ''): array
    {
        return ['text' => (string)$n, 'html' => $classe !== '' && (float)$n > 0 ? '<span class="' . self::e($classe) . '">' . self::e($n) . '</span>' : self::e($n), 'sort' => (string)$n];
    }

    /**
     * Sub-abas: [['id' => , 'icone' => 'bi-...', 'rotulo' => , 'html' => , 'contagem' => null|int], ...]. A primeira abre.
     * Sub-aba com html vazio não aparece.
     */
    public static function subabas(string $id, array $abas, bool $lembrar = false): string
    {
        $abas = array_values(array_filter($abas, static fn($a) => (string)($a['html'] ?? '') !== ''));
        if (!$abas) return '';
        if (count($abas) === 1) return '<div class="dash-subabas-unica mt-2">' . $abas[0]['html'] . '</div>';
        $h = '<div class="dash-subabas mt-2" data-subabas="' . self::e($id) . '"' . ($lembrar ? ' data-lembrar' : '') . '><nav class="dash-subabas-nav" role="tablist">';
        foreach ($abas as $i => $a) {
            $h .= '<button type="button" role="tab" class="dash-subaba' . ($i === 0 ? ' active' : '') . '" data-subaba="' . self::e($a['id']) . '">'
                . '<i class="bi ' . self::e($a['icone'] ?? 'bi-table') . '"></i> ' . self::e($a['rotulo'])
                . (isset($a['contagem']) && $a['contagem'] !== null ? ' <span class="dash-subaba-n">' . (int)$a['contagem'] . '</span>' : '') . '</button>';
        }
        $h .= '</nav>';
        foreach ($abas as $i => $a) $h .= '<div class="dash-subaba-painel" data-subaba-painel="' . self::e($a['id']) . '"' . ($i === 0 ? '' : ' hidden') . '>' . $a['html'] . '</div>';
        return $h . '</div>';
    }

    /** Quadro simples (resumos, matrizes, gráfico) para pôr numa sub-aba. */
    public static function quadro(string $titulo, string $icone, string $html, string $id = ''): string
    {
        return '<article class="admin-panel"' . ($id !== '' ? ' id="' . self::e($id) . '"' : '') . '><h3><i class="bi ' . self::e($icone) . ' me-1"></i>' . self::e($titulo) . '</h3>' . $html . '</article>';
    }

    /** CSS e scripts do grid padrão para a página do dashboard (cabeçalho e fim do body). */
    public static function head(): void
    {
        greenPortalGridLocalHead();
        echo '<style>.dash-grid.menu-grid-fragment{margin:0}.dash-subabas-unica .menu-grid-scroll,.dash-subabas .menu-grid-scroll{max-height:none}'
            // sub-abas (também em assets/css/dashboard-abas.css, para as páginas que não usam o dashboard)
            . '.dash-subabas-nav{display:flex;flex-wrap:wrap;gap:4px;border-bottom:1px solid #d9e6df;margin-bottom:8px}'
            . '.dash-subaba{border:1px solid transparent;border-bottom:0;background:none;padding:6px 12px;border-radius:8px 8px 0 0;color:#4f675d;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:5px;margin-bottom:-1px}'
            . '.dash-subaba:hover{color:#1f4d3a;background:#f2f8f5}.dash-subaba.active{background:#fff;border-color:#d9e6df;color:#1f4d3a}'
            . '.dash-subaba-n{background:#e6f1eb;color:#1f4d3a;border-radius:10px;padding:0 7px;font-size:11px}.dash-subaba.active .dash-subaba-n{background:#1f7a52;color:#fff}</style>';
    }

    public static function scripts(): void
    {
        greenPortalGridLocalScripts(['comum.filtro.limpar' => ['pt' => 'Limpar filtro', 'en' => 'Clear filter', 'es' => 'Limpiar filtro']]);
        echo '<script src="../assets/js/ajuda-transacao.js?v=1"></script><script src="../assets/js/subabas.js?v=2"></script>';
    }
}
