<?php
declare(strict_types=1);

require_once __DIR__ . '/DashboardGrid.php';

/**
 * Migração 381 (16/11/2026) — modelo único das Homes por módulo (GER-1 / GER-4 / GER-13).
 * Cada Home (ex.: principal/home_licencas.php) monta, com estes blocos:
 *   filtros()    barra de filtros no topo (unidade, responsável, período ...) por GET — valem para tudo da página;
 *   cartoes()    números do módulo; o clique filtra o grid da lista (filtro de grupo do grid local) e abre a sub-aba dele;
 *   barras() / colunas()  gráficos simples em HTML (sem biblioteca); cada barra também filtra a lista;
 *   calendario() calendário do mês com os prazos do módulo; o clique no dia filtra a lista de eventos do mês;
 *   DashboardGrid::grid() / subabas()  — grid padrão do portal e sub-abas (um quadro por vez, lembra a última).
 * As linhas dos grids levam em data-grupo os "grupos" (ex.: 'F_VENCIDA U_3 R_11735 M_2026-12') que cartões e barras filtram.
 * MENUS: as Homes de cada módulo (menu criado pela migração 381 como primeiro item do grupo do módulo).
 */
final class HomeModulo
{
    /** módulo => menu da Home, menu da tela do módulo (permissões copiadas dele), página, ajuda, nomes. */
    public const MENUS = [
        'LICENCAS' => [
            'codigo' => 'HOME_LICENCAS', 'menu' => 'LICENCAS', 'url' => '/principal/home_licencas.php', 'transacao' => 'home_licencas',
            'icone' => 'bi-house-door',
            'nome' => ['pt' => 'Home de Licenças', 'en' => 'Licenses home', 'es' => 'Inicio de Licencias'],
        ],
    ];

    private static function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

    public static function t(string $chave, string $pt, string $en, string $es): string
    {
        return IdiomaService::t('home_modulo.' . $chave, ['pt' => $pt, 'en' => $en, 'es' => $es]);
    }

    /** Leitura por sp_crud_select em páginas de 1000 (limite da procedure). null = view inexistente. */
    public static function ler(PDO $pdo, string $view, array $filtros = [], int $max = 50000): ?array
    {
        $out = [];
        for ($ini = 0; $ini < $max; $ini += 1000) {
            try {
                $s = $pdo->prepare('CALL sp_crud_select(:t, :f, NULL, 1000, :o)');
                $s->execute(['t' => $view, 'f' => json_encode($filtros ?: new stdClass(), JSON_UNESCAPED_UNICODE), 'o' => $ini]);
                $r = $s->fetchAll(PDO::FETCH_ASSOC);
                $s->closeCursor();
            } catch (Throwable) { return $ini === 0 ? null : $out; }
            foreach ($r as $l) $out[] = $l;
            if (count($r) < 1000) break;
        }
        return $out;
    }

    /** DashboardGrid::grid com as linhas já na ordem de 'ordem' (o grid local só marca o ícone; a ordem inicial vem do servidor). */
    public static function grid(string $id, array $colunas, array $linhas, array $op = []): string
    {
        [$col, $dir] = array_pad((array)($op['ordem'] ?? []), 2, 'asc');
        if ($col) {
            $v = static function (array $l) use ($col): string { $c = $l['cells'][$col] ?? ''; return is_array($c) ? (string)($c['sort'] ?? $c['text'] ?? '') : (string)$c; };
            usort($linhas, static fn($a, $b) => strnatcasecmp($v($a), $v($b)) * (strtolower((string)$dir) === 'desc' ? -1 : 1));
        }
        return DashboardGrid::grid($id, $colunas, $linhas, $op);
    }

    /** Data Y-m-d válida ou null. */
    public static function data(mixed $v): ?string
    {
        $v = substr(trim((string)$v), 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && $v > '1900-01-01' ? $v : null;
    }

    public static function dataTexto(?string $d): string
    {
        return $d === null ? '' : date(IdiomaService::idioma() === 'en' ? 'm/d/Y' : 'd/m/Y', strtotime($d));
    }

    /** Token de grupo sem espaços (para data-grupo). */
    public static function grupo(string $prefixo, mixed $valor): string
    {
        $v = preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string)$valor);
        return $prefixo . '_' . ($v === '' ? 'NA' : $v);
    }

    /** Períodos de vencimento da barra de filtros. */
    public static function periodos(): array
    {
        return [
            '' => self::t('per.todos', 'Todos os vencimentos', 'All due dates', 'Todos los vencimientos'),
            'vencidos' => self::t('per.vencidos', 'Já vencidos', 'Already due', 'Ya vencidos'),
            '30' => self::t('per.30', 'Vencem em até 30 dias', 'Due within 30 days', 'Vencen en hasta 30 días'),
            '60' => self::t('per.60', 'Vencem em até 60 dias', 'Due within 60 days', 'Vencen en hasta 60 días'),
            '90' => self::t('per.90', 'Vencem em até 90 dias', 'Due within 90 days', 'Vencen en hasta 90 días'),
            '12m' => self::t('per.12m', 'Próximos 12 meses', 'Next 12 months', 'Próximos 12 meses'),
            'ano' => self::t('per.ano', 'Este ano', 'This year', 'Este año'),
            'sem' => self::t('per.sem', 'Sem data de vencimento', 'No due date', 'Sin fecha de vencimiento'),
            'datas' => self::t('per.datas', 'Entre as datas…', 'Between dates…', 'Entre las fechas…'),
        ];
    }

    /** A data cai no período escolhido? ($de/$ate só para 'datas'). */
    public static function noPeriodo(?string $d, string $p, ?string $de = null, ?string $ate = null): bool
    {
        if ($p === '') return true;
        if ($p === 'sem') return $d === null;
        if ($d === null) return false;
        $hoje = date('Y-m-d');
        return match ($p) {
            'vencidos' => $d < $hoje,
            '30', '60', '90' => $d >= $hoje && $d <= date('Y-m-d', strtotime('+' . $p . ' days')),
            '12m' => $d >= $hoje && $d <= date('Y-m-d', strtotime('+12 months')),
            'ano' => substr($d, 0, 4) === date('Y'),
            'datas' => ($de === null || $d >= $de) && ($ate === null || $d <= $ate),
            default => true,
        };
    }

    /**
     * Barra de filtros (GET). $campos: nome => ['rotulo' => , 'opcoes' => [valor => texto], 'todos' => texto]
     * ou nome => ['rotulo' => , 'tipo' => 'date']. $atual: nome => valor. $manter: parâmetros que seguem no formulário (ex.: mes).
     */
    public static function filtros(array $campos, array $atual, array $manter = []): string
    {
        $h = '<form method="get" class="hm-filtros" data-hm-filtros>';
        foreach ($manter as $k => $v) if ($v !== null && $v !== '') $h .= '<input type="hidden" name="' . self::e($k) . '" value="' . self::e($v) . '">';
        foreach ($campos as $nome => $c) {
            $id = 'hmF_' . $nome;
            $oculto = !empty($c['oculto']) ? ' hidden' : '';
            $h .= '<label class="hm-filtro"' . $oculto . ' data-hm-filtro="' . self::e($nome) . '"><span>' . self::e($c['rotulo']) . '</span>';
            if (($c['tipo'] ?? '') === 'date') {
                $h .= '<input type="date" class="form-control form-control-sm" id="' . $id . '" name="' . self::e($nome) . '" value="' . self::e($atual[$nome] ?? '') . '">';
            } else {
                $h .= '<select class="form-select form-select-sm" id="' . $id . '" name="' . self::e($nome) . '">';
                if (isset($c['todos'])) $h .= '<option value="">' . self::e($c['todos']) . '</option>';
                foreach ($c['opcoes'] as $v => $txt) $h .= '<option value="' . self::e($v) . '"' . ((string)($atual[$nome] ?? '') === (string)$v ? ' selected' : '') . '>' . self::e($txt) . '</option>';
                $h .= '</select>';
            }
            $h .= '</label>';
        }
        $ativos = count(array_filter(array_intersect_key($atual, $campos), static fn($v) => (string)$v !== ''));
        $h .= '<button type="submit" class="btn btn-sm btn-success"><i class="bi bi-funnel me-1"></i>' . self::e(self::t('filtrar', 'Filtrar', 'Filter', 'Filtrar')) . '</button>';
        if ($ativos) $h .= '<a class="btn btn-sm btn-outline-secondary" href="?' . self::e(http_build_query(array_filter($manter, static fn($v) => $v !== null && $v !== ''))) . '"><i class="bi bi-x-lg me-1"></i>'
            . self::e(self::t('limpar', 'Limpar filtros', 'Clear filters', 'Limpiar filtros')) . ' (' . $ativos . ')</a>';
        return $h . '</form>';
    }

    /** Atributos que filtram o grid $grid pelo grupo $valor (assets/js/subabas.js v2). */
    public static function alvo(string $grid, string $valor, string $rotulo): string
    {
        return ' data-grid-filtro="' . self::e($grid) . '" data-grid-valor="' . self::e($valor) . '" data-grid-rotulo="' . self::e($rotulo) . '" role="button" tabindex="0"';
    }

    /**
     * Cartões: [['rotulo' => , 'valor' => , 'icone' => 'bi-...', 'tom' => red|orange|green|blue|gray|teal, 'grupo' => 'F_X' (filtra $grid),
     *            'extra' => texto pequeno, 'grid' => outro grid]].
     */
    public static function cartoes(string $grid, array $cartoes): string
    {
        $h = '<div class="hm-cartoes">';
        foreach ($cartoes as $c) {
            $alvo = ($c['grupo'] ?? null) !== null ? self::alvo((string)($c['grid'] ?? $grid), (string)$c['grupo'], (string)$c['rotulo']) : '';
            $h .= '<div class="hm-cartao tom-' . self::e($c['tom'] ?? 'blue') . ($alvo ? ' hm-clica' : '') . '"' . $alvo . ' title="' . self::e($alvo ? self::t('cartao.dica', 'Clique para listar abaixo (clique de novo para tirar o filtro)', 'Click to list below (click again to clear)', 'Haga clic para listar abajo (clic de nuevo para quitar)') : $c['rotulo']) . '">'
                . '<i class="bi ' . self::e($c['icone'] ?? 'bi-circle') . '"></i><div><div class="hm-cartao-n">' . self::e($c['valor']) . '</div><div class="hm-cartao-r">' . self::e($c['rotulo']) . '</div>'
                . (($c['extra'] ?? '') !== '' ? '<div class="hm-cartao-x">' . self::e($c['extra']) . '</div>' : '') . '</div></div>';
        }
        return $h . '</div>';
    }

    /**
     * Barras horizontais. $itens: [['rotulo' => , 'n' => , 'grupo' => , 'tom' => , 'partes' => [['n' => , 'tom' => , 'rotulo' => ]]]].
     * Com 'partes' a barra é empilhada (o total é a soma). $max: quantas barras (o resto vira "Outros", sem filtro).
     */
    public static function barras(string $grid, array $itens, int $max = 12, string $vazio = ''): string
    {
        $itens = array_values(array_filter($itens, static fn($i) => (int)$i['n'] > 0));
        if (!$itens) return '<div class="hm-vazio">' . self::e($vazio !== '' ? $vazio : self::t('grafico.vazio', 'Nada para mostrar com estes filtros.', 'Nothing to show with these filters.', 'Nada para mostrar con estos filtros.')) . '</div>';
        if (count($itens) > $max) {
            $resto = array_slice($itens, $max - 1);
            $itens = array_slice($itens, 0, $max - 1);
            $itens[] = ['rotulo' => self::t('grafico.outros', 'Outros', 'Others', 'Otros') . ' (' . count($resto) . ')', 'n' => array_sum(array_column($resto, 'n')), 'tom' => 'gray'];
        }
        // "Outros" não entra na escala (senão achata as demais barras)
        $topo = max(array_map(static fn($i) => isset($i['grupo']) || count($itens) === 1 ? (int)$i['n'] : 0, $itens)) ?: 1;
        $h = '<div class="hm-barras">';
        foreach ($itens as $i) {
            $alvo = isset($i['grupo']) ? self::alvo($grid, (string)$i['grupo'], (string)$i['rotulo']) : '';
            $larg = min(100, max(1.5, 100 * (int)$i['n'] / $topo));
            $h .= '<div class="hm-barra' . ($alvo ? ' hm-clica' : '') . '"' . $alvo . '><span class="hm-barra-r" title="' . self::e($i['rotulo']) . '">' . self::e($i['rotulo']) . '</span>'
                . '<span class="hm-barra-trilho"><span class="hm-barra-cheia" style="width:' . number_format($larg, 1, '.', '') . '%">';
            if (!empty($i['partes'])) {
                foreach ($i['partes'] as $p) if ((int)$p['n'] > 0)
                    $h .= '<span class="hm-parte tom-' . self::e($p['tom']) . '" style="flex:' . (int)$p['n'] . '" title="' . self::e($p['rotulo'] . ': ' . $p['n']) . '"></span>';
            } else $h .= '<span class="hm-parte tom-' . self::e($i['tom'] ?? 'teal') . '" style="flex:1"></span>';
            $h .= '</span></span><span class="hm-barra-n">' . (int)$i['n'] . '</span></div>';
        }
        return $h . '</div>';
    }

    /** Colunas verticais (ex.: por mês). $itens: [['rotulo' => 'nov/26', 'n' => , 'grupo' => , 'tom' => , 'dica' => ]]. */
    public static function colunas(string $grid, array $itens): string
    {
        $topo = max(1, ...array_map(static fn($i) => (int)$i['n'], $itens ?: [['n' => 0]]));
        $h = '<div class="hm-colunas">';
        foreach ($itens as $i) {
            $alvo = (int)$i['n'] > 0 && isset($i['grupo']) ? self::alvo($grid, (string)$i['grupo'], (string)($i['dica'] ?? $i['rotulo'])) : '';
            $alt = (int)$i['n'] > 0 ? max(3, 100 * (int)$i['n'] / $topo) : 0;
            $h .= '<div class="hm-coluna' . ($alvo ? ' hm-clica' : '') . '"' . $alvo . ' title="' . self::e(($i['dica'] ?? $i['rotulo']) . ': ' . (int)$i['n']) . '">'
                . '<span class="hm-coluna-n">' . ((int)$i['n'] ?: '') . '</span><span class="hm-coluna-trilho"><span class="hm-coluna-cheia tom-' . self::e($i['tom'] ?? 'teal') . '" style="height:' . number_format($alt, 1, '.', '') . '%"></span></span>'
                . '<span class="hm-coluna-r">' . self::e($i['rotulo']) . '</span></div>';
        }
        return $h . '</div>';
    }

    /** Quadro de um gráfico. */
    public static function grafico(string $titulo, string $icone, string $corpo, string $nota = '', string $classe = ''): string
    {
        return '<section class="hm-grafico ' . self::e($classe) . '"><h3><i class="bi ' . self::e($icone) . ' me-1"></i>' . self::e($titulo) . '</h3>' . $corpo
            . ($nota !== '' ? '<div class="hm-nota">' . self::e($nota) . '</div>' : '') . '</section>';
    }

    /** Legenda de cores: [tom => texto]. */
    public static function legenda(array $itens): string
    {
        $h = '<div class="hm-legenda">';
        foreach ($itens as $tom => $txt) $h .= '<span><i class="tom-' . self::e($tom) . '"></i>' . self::e($txt) . '</span>';
        return $h . '</div>';
    }

    /** Nome curto do mês (jan/26). */
    public static function mesCurto(string $ym): string
    {
        static $n = ['pt' => ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'],
            'en' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            'es' => ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic']];
        $l = $n[IdiomaService::idioma()] ?? $n['pt'];
        return $l[(int)substr($ym, 5, 2) - 1] . '/' . substr($ym, 2, 2);
    }

    public static function mesLongo(string $ym): string
    {
        static $n = ['pt' => ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'],
            'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            'es' => ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']];
        $l = $n[IdiomaService::idioma()] ?? $n['pt'];
        return $l[(int)substr($ym, 5, 2) - 1] . ' ' . substr($ym, 0, 4);
    }

    /**
     * Calendário do mês $ym (Y-m). $eventos: [['data' => Y-m-d, 'rotulo' => , 'tom' => ]]. O clique no dia filtra o grid $grid
     * pelo grupo D_Y-m-d. $links: ['ant' => url, 'prox' => url, 'hoje' => url].
     */
    public static function calendario(string $ym, array $eventos, string $grid, array $links): string
    {
        $porDia = [];
        foreach ($eventos as $ev) if (substr((string)$ev['data'], 0, 7) === $ym) $porDia[(string)$ev['data']][] = $ev;
        $ini = $ym . '-01'; $dias = (int)date('t', strtotime($ini)); $sem = (int)date('w', strtotime($ini)); $hoje = date('Y-m-d');
        $nomes = IdiomaService::idioma() === 'en' ? ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] : (IdiomaService::idioma() === 'es' ? ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'] : ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb']);
        $h = '<div class="hm-cal"><div class="hm-cal-topo"><a class="btn btn-sm btn-outline-secondary" href="' . self::e($links['ant']) . '"><i class="bi bi-chevron-left"></i></a>'
            . '<strong>' . self::e(self::mesLongo($ym)) . '</strong><a class="btn btn-sm btn-outline-secondary" href="' . self::e($links['prox']) . '"><i class="bi bi-chevron-right"></i></a>'
            . ($ym !== date('Y-m') ? '<a class="btn btn-sm btn-link" href="' . self::e($links['hoje']) . '">' . self::e(self::t('cal.hoje', 'Mês atual', 'Current month', 'Mes actual')) . '</a>' : '')
            . '<span class="hm-cal-total">' . count(array_merge(...array_values($porDia ?: [[]]))) . ' ' . self::e(self::t('cal.eventos', 'prazo(s) no mês', 'deadline(s) this month', 'plazo(s) en el mes')) . '</span></div>'
            . '<div class="hm-cal-grade">';
        foreach ($nomes as $n) $h .= '<div class="hm-cal-sem">' . self::e($n) . '</div>';
        for ($i = 0; $i < $sem; $i++) $h .= '<div class="hm-cal-dia vazio"></div>';
        for ($d = 1; $d <= $dias; $d++) {
            $data = sprintf('%s-%02d', $ym, $d);
            $evs = $porDia[$data] ?? [];
            $alvo = $evs ? self::alvo($grid, 'D_' . $data, self::dataTexto($data)) : '';
            $h .= '<div class="hm-cal-dia' . ($data === $hoje ? ' hoje' : '') . ($data < $hoje ? ' passado' : '') . ($evs ? ' hm-clica' : '') . '"' . $alvo . '><span class="hm-cal-num">' . $d . '</span>';
            foreach (array_slice($evs, 0, 3) as $ev) $h .= '<span class="hm-cal-ev tom-' . self::e($ev['tom'] ?? 'blue') . '" title="' . self::e($ev['rotulo']) . '">' . self::e($ev['rotulo']) . '</span>';
            if (count($evs) > 3) $h .= '<span class="hm-cal-mais">+' . (count($evs) - 3) . '</span>';
            $h .= '</div>';
        }
        return $h . '</div></div>';
    }

    /** Cabeçalho da Home: título, Ajuda (F1) e descrição. */
    public static function cabecalho(string $icone, string $titulo, string $descricao, string $aviso = ''): string
    {
        return '<div class="hm-cab"><h5><i class="bi ' . self::e($icone) . ' me-2"></i>' . self::e($titulo) . '</h5>'
            . '<button type="button" class="btn btn-sm btn-link p-0" data-open-ajuda title="' . self::e(self::t('ajuda', 'Ajuda desta tela (F1)', 'Help for this screen (F1)', 'Ayuda de esta pantalla (F1)')) . '"><i class="bi bi-question-circle me-1"></i>' . self::e(self::t('ajuda.curto', 'Ajuda', 'Help', 'Ayuda')) . '</button></div>'
            . '<div class="hm-desc">' . $descricao . '</div>'
            . ($aviso !== '' ? '<div class="alert alert-secondary py-1 px-2 small mb-2"><i class="bi bi-person-check me-1"></i>' . self::e($aviso) . '</div>' : '');
    }

    /** CSS (dentro do <head>, depois do Bootstrap). */
    public static function head(): void
    {
        DashboardGrid::head();
        echo '<style>
.form-control{height:auto;padding:.25rem .5rem}
body{background:#fff;color:var(--gp-text,#21332b);font-size:13px}.hm{padding:12px 16px}
.hm-cab{display:flex;align-items:center;gap:10px;margin-bottom:2px}.hm-cab h5{margin:0;color:#1f4d3a}
.hm-desc{color:#5f7268;max-width:1100px;margin-bottom:8px;font-size:12px}
.hm-filtros{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;background:#f4f8f6;border:1px solid #dfe9e4;border-radius:10px;padding:8px 10px;margin-bottom:10px}
.hm-filtro{display:flex;flex-direction:column;gap:2px;font-size:11px;color:#5f7268;margin:0}.hm-filtro[hidden]{display:none}.hm-filtro .form-select,.hm-filtro .form-control{min-width:160px;max-width:260px;font-size:12px}
.hm-cartoes{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;margin-bottom:10px}
.hm-cartao{display:flex;gap:10px;align-items:flex-start;border:1px solid #e1ebe6;border-left:4px solid var(--hm-c);border-radius:10px;padding:8px 10px;background:#fff;min-height:64px}
.hm-cartao>i{font-size:20px;color:var(--hm-c);margin-top:2px}.hm-cartao-n{font-size:20px;font-weight:700;line-height:1.1;color:#1f2d27}.hm-cartao-r{font-size:11.5px;color:#4f675d;line-height:1.25}.hm-cartao-x{font-size:10.5px;color:#7d8f86}
.hm-clica{cursor:pointer}.hm-cartao.hm-clica:hover{box-shadow:0 2px 8px rgba(31,77,58,.15);border-color:var(--hm-c)}
.tom-red{--hm-c:#c0392b}.tom-orange{--hm-c:#e08a00}.tom-yellow{--hm-c:#d4b106}.tom-green{--hm-c:#2e8b57}.tom-blue{--hm-c:#2f6fb3}.tom-gray{--hm-c:#8a99a1}.tom-teal{--hm-c:#1f7a6a}.tom-purple{--hm-c:#7a4fb3}
.hm-graficos{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:10px}
.hm-grafico{border:1px solid #e1ebe6;border-radius:10px;padding:10px 12px;background:#fff;min-width:0}.hm-grafico h3{font-size:13px;font-weight:700;color:#1f4d3a;margin:0 0 8px}
.hm-nota{font-size:11px;color:#7d8f86;margin-top:6px}.hm-vazio{color:#7d8f86;font-size:12px;padding:8px 0}
.hm-barras{display:flex;flex-direction:column;gap:4px}.hm-barra{display:grid;grid-template-columns:minmax(110px,34%) 1fr 42px;gap:8px;align-items:center;font-size:12px;padding:1px 4px;border-radius:6px}
.hm-barra.hm-clica:hover{background:#f0f6f3}.hm-barra-r{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#33473e}.hm-barra-n{text-align:right;font-weight:600}
.hm-barra-trilho{height:14px;background:#f1f4f3;border-radius:7px;overflow:hidden}.hm-barra-cheia{display:flex;height:100%;border-radius:7px;overflow:hidden}.hm-parte{background:var(--hm-c);height:100%}
.hm-colunas{display:flex;gap:4px;align-items:stretch;height:170px;padding-top:4px}.hm-coluna{flex:1;display:flex;flex-direction:column;align-items:center;min-width:0;border-radius:6px;padding:0 1px}
.hm-coluna.hm-clica:hover{background:#f0f6f3}.hm-coluna-n{font-size:11px;font-weight:600;height:16px}.hm-coluna-trilho{flex:1;width:70%;display:flex;align-items:flex-end}
.hm-coluna-cheia{display:block;width:100%;background:var(--hm-c);border-radius:4px 4px 0 0}.hm-coluna-r{font-size:10px;color:#5f7268;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
.hm-legenda{display:flex;flex-wrap:wrap;gap:4px 12px;font-size:11px;color:#5f7268;margin-top:6px}.hm-legenda i{display:inline-block;width:10px;height:10px;border-radius:3px;background:var(--hm-c);margin-right:4px;vertical-align:-1px}
.hm-cal-topo{display:flex;align-items:center;gap:8px;margin-bottom:6px}.hm-cal-topo strong{font-size:14px;color:#1f4d3a;min-width:150px;text-align:center}.hm-cal-total{margin-left:auto;color:#5f7268;font-size:12px}
.hm-cal-grade{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:3px}.hm-cal-sem{font-size:11px;font-weight:600;color:#5f7268;text-align:center;padding:2px}
.hm-cal-dia{border:1px solid #e6eeea;border-radius:7px;min-height:78px;padding:3px 4px;display:flex;flex-direction:column;gap:2px;background:#fff;min-width:0}
.hm-cal-dia.vazio{border:0;background:none}.hm-cal-dia.passado{background:#fafbfb}.hm-cal-dia.hoje{border-color:#1f7a52;box-shadow:inset 0 0 0 1px #1f7a52}
.hm-cal-dia.hm-clica:hover{background:#f0f6f3}.hm-cal-num{font-size:11px;font-weight:700;color:#4f675d}
.hm-cal-ev{display:block;font-size:10.5px;line-height:1.3;padding:0 4px;border-radius:4px;background:color-mix(in srgb,var(--hm-c) 14%,#fff);color:#22322b;border-left:3px solid var(--hm-c);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hm-cal-mais{font-size:10.5px;color:#5f7268}
.hm .menu-grid-scroll{max-height:calc(100vh - 330px);min-height:180px;overflow:auto}
.hm-sit{display:inline-block;border-radius:10px;padding:0 8px;font-size:11px;white-space:nowrap;background:color-mix(in srgb,var(--hm-c) 15%,#fff);color:#22322b;border:1px solid color-mix(in srgb,var(--hm-c) 40%,#fff)}
.hm-mini{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px}
@media (max-width:700px){.hm-graficos{grid-template-columns:1fr}.hm-cal-dia{min-height:54px}.hm-cal-ev{display:none}}
</style>';
    }

    /** Scripts do fim do body (grid padrão, sub-abas, ajuda) e o filtro "Entre as datas…". */
    public static function scripts(): void
    {
        DashboardGrid::scripts();
        echo '<script>(function(){var f=document.querySelector("[data-hm-filtros]");if(!f)return;var p=f.querySelector("[name=p]");'
            . 'function datas(){var on=p&&p.value==="datas";f.querySelectorAll("[data-hm-filtro=de],[data-hm-filtro=ate]").forEach(function(l){l.hidden=!on;});}'
            . 'if(p){p.addEventListener("change",datas);datas();}'
            . 'document.addEventListener("keydown",function(e){if((e.key==="Enter"||e.key===" ")&&e.target.matches&&e.target.matches("[data-grid-filtro]")){e.preventDefault();e.target.click();}});}());</script>';
    }
}
