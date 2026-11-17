<?php
declare(strict_types=1);

require_once __DIR__ . '/IdiomaService.php';
require_once __DIR__ . '/DashboardGrid.php';

/**
 * Migração 366 — Não Conformidades e Fornecedores nos dashboards (16/11/2026).
 *   Dashboard da empresa (dashboard_admin.php): aba "Não conformidades", situação da homologação na aba "Fornecedores" e
 *   dois resumos na Visão geral.
 *   Dashboard pessoal (dashboard.php): abas "Minhas não conformidades" e "Meus fornecedores" e cartões na Visão geral.
 * Leitura só por views com sp_crud_select / sp_crud_count (vw_dash_nc_abertas, vw_dash_nc_mes, vw_dash_nc_resumo,
 * vw_dash_fornecedores, vw_dash_fornecedor_itens, vw_dash_fornecedor_resumo; vw_nc_acoes_vencidas da 334).
 * Cada linha ou número abre a tela em popup (assets/js/dashboard-abas.js: data-abrir-transacao / data-ir-aba).
 * Migração 368: listas no grid padrão (DashboardGrid / grid-local.js) e quadros em sub-abas abaixo dos cartões.
 */
final class DashboardNcFornecedores
{
    public const FASES = ['CRIACAO', 'GESTOR', 'ANALISE', 'EFICACIA'];

    public function __construct(private PDO $pdo) {}

    // ------------------------------------------------------------------ leitura
    public function sel(string $view, array $filtros = [], int $limite = 20000): ?array
    {
        try {
            $linhas = [];
            for ($offset = 0; ; $offset += 1000) {
                $s = $this->pdo->prepare('CALL sp_crud_select(:t, :f, NULL, :l, :o)');
                $s->execute(['t' => $view, 'f' => json_encode((object)$filtros, JSON_UNESCAPED_UNICODE), 'l' => min(1000, $limite - count($linhas)), 'o' => $offset]);
                $lote = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
                array_push($linhas, ...$lote);
                if (count($lote) < 1000 || count($linhas) >= $limite) break;
            }
            return $linhas;
        } catch (Throwable) { return null; }
    }

    public function contar(string $view, array $filtros = []): ?int
    {
        try {
            $s = $this->pdo->prepare('CALL sp_crud_count(:t, :f, :b)');
            $s->execute(['t' => $view, 'f' => json_encode((object)$filtros, JSON_UNESCAPED_UNICODE), 'b' => '']);
            $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
            return (int)($r[0]['total_registros'] ?? 0);
        } catch (Throwable) { return null; }
    }

    private static function exato(int|string $v): array { return ['value' => (string)$v, 'exact' => true]; }

    // ------------------------------------------------------------------ apoio de tela
    private static function t(string $k, array $p, array $troca = []): string
    {
        return strtr(IdiomaService::t('dashboard.ncfo.' . $k, $p), $troca);
    }
    private static function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
    private static function abrir(string $url, string $titulo): string
    {
        return ' data-abrir-transacao="' . self::e($url) . '" data-titulo="' . self::e($titulo) . '"';
    }
    /** Migração 367: o cartão filtra a tabela #id da mesma aba (assets/js/dashboard-abas.js). */
    private static function filtro(string $id, string $valor, string $rotulo): string
    {
        return ' data-dash-filtro="' . self::e($id) . '" data-dash-valor="' . self::e($valor) . '" data-dash-rotulo="' . self::e($rotulo) . '"';
    }
    private static function urlFiltro(string $pagina, array $filtros = [], array $outros = []): string
    {
        $q = $outros + ($filtros ? ['filtros' => json_encode($filtros, JSON_UNESCAPED_UNICODE)] : []);
        return $pagina . ($q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '');
    }
    private static function cartao(string $rotulo, int|string $valor, string $icone, string $tom, string $extra = '', string $abaAlvo = '', string $atributos = ''): string
    {
        $cls = 'admin-card ' . $tom . ($abaAlvo !== '' || $atributos !== '' ? ' dash-link' : '');
        return '<article class="' . $cls . '"' . ($abaAlvo !== '' ? ' data-ir-aba="' . self::e($abaAlvo) . '"' : '') . $atributos
            . ($atributos !== '' ? ' title="' . self::e(self::t('abrir', ['pt' => 'Clique para abrir', 'en' => 'Click to open', 'es' => 'Haga clic para abrir'])) . '"' : '')
            . '><i class="bi ' . $icone . '"></i><strong>' . $valor . '</strong><span>' . self::e($rotulo) . '</span>' . ($extra !== '' ? '<small>' . $extra . '</small>' : '') . '</article>';
    }
    private static function semMigracao(): string
    {
        return '<div class="alert alert-warning small mb-0"><i class="bi bi-info-circle me-1"></i>' . self::e(self::t('sem_migracao', ['pt' => 'Os indicadores desta aba ainda não estão disponíveis nesta empresa (migração 366).', 'en' => 'The indicators of this tab are not available for this company yet (migration 366).', 'es' => 'Los indicadores de esta pestaña aún no están disponibles en esta empresa (migración 366).'])) . '</div>';
    }
    private static function vazio(string $texto): string { return '<div class="dash-nota">' . self::e($texto) . '</div>'; }
    private static function data(mixed $v): string
    {
        $v = substr(trim((string)$v), 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && $v > '1900-01-01' ? date('d/m/Y', strtotime($v)) : '–';
    }
    private static function th(array $cols): string
    {
        $h = '<thead><tr>';
        foreach ($cols as $c) $h .= str_starts_with($c, '#') ? '<th class="num">' . self::e(substr($c, 1)) . '</th>' : '<th>' . self::e($c) . '</th>';
        return $h . '</tr></thead>';
    }
    private static function barraMini(int $n, int $max, string $cor = '#4d87ad'): string
    {
        $w = $max > 0 ? max(2, (int)round(100 * $n / $max)) : 0;
        return '<div class="dash-barra" style="min-width:60px"><span style="width:' . $w . '%;background:' . $cor . '"></span></div>';
    }

    public static function rotuloFase(string $f): string
    {
        return match ($f) {
            'CRIACAO' => self::t('fase.criacao', ['pt' => 'Em criação / devolvida ao emitente', 'en' => 'Being created / returned to issuer', 'es' => 'En creación / devuelta al emisor']),
            'GESTOR' => self::t('fase.gestor', ['pt' => 'Com o gestor', 'en' => 'With the manager', 'es' => 'Con el gestor']),
            'ANALISE' => self::t('fase.analise', ['pt' => 'Análise da causa e plano de ação', 'en' => 'Root cause analysis and action plan', 'es' => 'Análisis de causa y plan de acción']),
            'EFICACIA' => self::t('fase.eficacia', ['pt' => 'Avaliação da eficácia', 'en' => 'Effectiveness review', 'es' => 'Evaluación de la eficacia']),
            default => self::t('fase.outra', ['pt' => 'Outra etapa', 'en' => 'Other stage', 'es' => 'Otra etapa']),
        };
    }
    private static function oQueFazer(string $f): string
    {
        return match ($f) {
            'CRIACAO' => self::t('fazer.criacao', ['pt' => 'Completar ou corrigir e enviar ao gestor', 'en' => 'Complete or fix and send to the manager', 'es' => 'Completar o corregir y enviar al gestor']),
            'GESTOR' => self::t('fazer.gestor', ['pt' => 'Avaliar: enviar ao responsável, recusar ou encerrar', 'en' => 'Review: send to the owner, refuse or close', 'es' => 'Evaluar: enviar al responsable, rechazar o cerrar']),
            'ANALISE' => self::t('fazer.analise', ['pt' => 'Registrar as causas e o plano de ação e enviar', 'en' => 'Record the causes and the action plan and send', 'es' => 'Registrar las causas y el plan de acción y enviar']),
            'EFICACIA' => self::t('fazer.eficacia', ['pt' => 'Avaliar a eficácia das ações', 'en' => 'Review the effectiveness of the actions', 'es' => 'Evaluar la eficacia de las acciones']),
            default => '',
        };
    }
    private static function abrirNcGrid(array $r): array { return DashboardGrid::abrirDe(self::abrirNc($r)); }
    private static function abrirNc(array $r): string
    {
        $aba = ['GESTOR' => 'gestor', 'ANALISE' => 'analise', 'EFICACIA' => 'eficacia'][(string)$r['FASE']] ?? '';
        return self::abrir(self::urlFiltro('naoconformidades_ocorrencia.php', [], ['codigo' => (int)$r['CODOCORRENCIA']] + ($aba ? ['aba' => $aba] : [])),
            self::t('nc', ['pt' => 'Não conformidade', 'en' => 'Nonconformity', 'es' => 'No conformidad']) . ' ' . $r['NUMERO']);
    }
    private static function faixaIdade(int $dias): string
    {
        return $dias <= 30 ? 'A30' : ($dias <= 90 ? 'A90' : ($dias <= 365 ? 'A365' : 'MAIS'));
    }

    // ================================================================== DASHBOARD DA EMPRESA
    /** Resumos da Visão geral (dois painéis no padrão .dash-resumo). Vazio se a 366 não estiver aplicada. */
    public function resumosEmpresa(bool $temNc, bool $temFo): string
    {
        $ver = self::e(self::t('ver_aba', ['pt' => 'ver aba', 'en' => 'open tab', 'es' => 'ver pestaña']));
        $linha = static fn(string $rot, int $n, string $classe = ''): string => '<div class="linha"><span>' . self::e($rot) . '</span><b class="' . $classe . '">' . $n . '</b></div>';
        $h = '';
        if ($temNc && ($r = ($this->sel('vw_dash_nc_resumo', [], 1) ?? [])[0] ?? null)) {
            $h .= '<article class="admin-panel dash-resumo"><h3><i class="bi bi-exclamation-diamond me-1"></i>' . self::e(self::t('aba.nc', ['pt' => 'Não conformidades', 'en' => 'Nonconformities', 'es' => 'No conformidades']))
                . '<a data-ir-aba="nc">' . $ver . ' <i class="bi bi-arrow-right"></i></a></h3>'
                . $linha(self::t('r.abertas', ['pt' => 'Abertas', 'en' => 'Open', 'es' => 'Abiertas']), (int)$r['ABERTAS'])
                . $linha(self::t('r.gestor', ['pt' => 'Esperando o gestor (avaliar ou eficácia)', 'en' => 'Waiting for the manager (review or effectiveness)', 'es' => 'Esperando al gestor (evaluar o eficacia)']), (int)$r['GESTOR'] + (int)$r['EFICACIA'], ((int)$r['GESTOR'] + (int)$r['EFICACIA']) ? 'status-warning' : 'status-ok')
                . $linha(self::t('r.mais_ano', ['pt' => 'Abertas há mais de 1 ano', 'en' => 'Open for more than 1 year', 'es' => 'Abiertas hace más de 1 año']), (int)$r['MAIS_1_ANO'], (int)$r['MAIS_1_ANO'] ? 'status-danger' : 'status-ok')
                . '</article>';
        }
        if ($temFo && ($r = ($this->sel('vw_dash_fornecedor_resumo', [], 1) ?? [])[0] ?? null)) {
            $h .= '<article class="admin-panel dash-resumo"><h3><i class="bi bi-truck me-1"></i>' . self::e(self::t('aba.fo', ['pt' => 'Fornecedores', 'en' => 'Suppliers', 'es' => 'Proveedores']))
                . '<a data-ir-aba="fornecedores">' . $ver . ' <i class="bi bi-arrow-right"></i></a></h3>'
                . $linha(self::t('r.ativos', ['pt' => 'Fornecedores ativos', 'en' => 'Active suppliers', 'es' => 'Proveedores activos']), (int)$r['ATIVOS'])
                . $linha(self::t('r.aval', ['pt' => 'Avaliação vencida ou sem data', 'en' => 'Evaluation overdue or without date', 'es' => 'Evaluación vencida o sin fecha']), (int)$r['AVALIACAO_VENCIDA'] + (int)$r['SEM_DATA_AVALIACAO'], ((int)$r['AVALIACAO_VENCIDA'] + (int)$r['SEM_DATA_AVALIACAO']) ? 'status-warning' : 'status-ok')
                . $linha(self::t('r.sem_resp_ativo', ['pt' => 'Sem responsável ativo', 'en' => 'Without an active owner', 'es' => 'Sin responsable activo']), (int)$r['SEM_RESPONSAVEL'], (int)$r['SEM_RESPONSAVEL'] ? 'status-danger' : 'status-ok')
                . $linha(self::t('r.nc_forn', ['pt' => 'NCs de fornecedor abertas', 'en' => 'Open supplier NCs', 'es' => 'NC de proveedor abiertas']), (int)$r['NC_ABERTAS'])
                . '</article>';
        }
        return $h;
    }

    /** Aba "Não conformidades" do Dashboard da empresa. */
    public function ncEmpresa(): string
    {
        $abertas = $this->sel('vw_dash_nc_abertas');
        if ($abertas === null) return self::semMigracao();
        $porFase = array_fill_keys(self::FASES, 0); $mais1 = 0; $atrasadas = 0; $novas = 0;
        $tipos = []; $unid = []; $pessoas = []; $semPessoa = 0;
        foreach ($abertas as $r) {
            $f = (string)$r['FASE']; $porFase[$f] = ($porFase[$f] ?? 0) + 1;
            $idade = (int)$r['IDADE_DIAS'];
            if ($idade > 365) $mais1++; if ($idade <= 30) $novas++; if ((int)$r['ATRASADA']) $atrasadas++;
            $tp = (string)$r['TIPO'] !== '' ? (string)$r['TIPO'] : '–';
            $tipos[$tp][$f] = ($tipos[$tp][$f] ?? 0) + 1;
            $tipos[$tp]['_IDADE_' . self::faixaIdade($idade)] = ($tipos[$tp]['_IDADE_' . self::faixaIdade($idade)] ?? 0) + 1;
            $u = (string)$r['DSCUNIDADE'] !== '' ? (string)$r['DSCUNIDADE'] : '–';
            $unid[$u] = ($unid[$u] ?? 0) + 1;
            $p = (int)($r['CODUSUARIO_ACAO'] ?? 0);
            if ($p <= 0) { $semPessoa++; continue; }
            $pessoas[$p] ??= ['nome' => (string)$r['USUARIO_ACAO'], 'ativo' => (int)$r['USUARIO_ACAO_ATIVO'], 'total' => 0, 'GESTOR' => 0, 'ANALISE' => 0, 'EFICACIA' => 0, 'CRIACAO' => 0, 'parada90' => 0, 'maxParada' => 0];
            $pessoas[$p]['total']++; $pessoas[$p][$f] = ($pessoas[$p][$f] ?? 0) + 1;
            if ((int)$r['DIAS_PARADA'] > 90) $pessoas[$p]['parada90']++;
            $pessoas[$p]['maxParada'] = max($pessoas[$p]['maxParada'], (int)$r['DIAS_PARADA']);
        }
        $acoesVencidas = $this->contar('vw_nc_acoes_vencidas');
        $lista = self::urlFiltro('naoconformidades_listageralocorrencias.php');
        $tLista = self::t('lista', ['pt' => 'Lista geral de ocorrências', 'en' => 'General occurrence list', 'es' => 'Lista general de ocurrencias']);

        $h = '<div class="admin-cards">'
            . self::cartao(self::t('c.abertas', ['pt' => 'Não conformidades abertas', 'en' => 'Open nonconformities', 'es' => 'No conformidades abiertas']), count($abertas), 'bi-exclamation-diamond', 'blue', $novas . ' ' . self::e(self::t('c.novas', ['pt' => 'nos últimos 30 dias', 'en' => 'in the last 30 days', 'es' => 'en los últimos 30 días'])), '', self::abrir($lista, $tLista))
            . self::cartao(self::rotuloFase('CRIACAO'), $porFase['CRIACAO'], 'bi-pencil-square', 'gray', '', '', self::filtro('dash-nc-lista', 'CRIACAO', self::rotuloFase('CRIACAO')))
            . self::cartao(self::rotuloFase('GESTOR'), $porFase['GESTOR'], 'bi-person-check', $porFase['GESTOR'] ? 'orange' : 'green', '', '', self::filtro('dash-nc-lista', 'GESTOR', self::rotuloFase('GESTOR')))
            . self::cartao(self::rotuloFase('ANALISE'), $porFase['ANALISE'], 'bi-diagram-3', 'orange', '', '', self::filtro('dash-nc-lista', 'ANALISE', self::rotuloFase('ANALISE')))
            . self::cartao(self::rotuloFase('EFICACIA'), $porFase['EFICACIA'], 'bi-clipboard-check', $porFase['EFICACIA'] ? 'orange' : 'green', '', '', self::filtro('dash-nc-lista', 'EFICACIA', self::rotuloFase('EFICACIA')))
            . self::cartao($rMais1 = self::t('c.mais_ano', ['pt' => 'Abertas há mais de 1 ano', 'en' => 'Open for more than 1 year', 'es' => 'Abiertas hace más de 1 año']), $mais1, 'bi-hourglass-bottom', $mais1 ? 'red' : 'green', '', '', self::filtro('dash-nc-lista', 'MAIS1ANO', $rMais1))
            . self::cartao($rPrev = self::t('c.prevista', ['pt' => 'Data prevista de conclusão vencida', 'en' => 'Planned completion date passed', 'es' => 'Fecha prevista de conclusión vencida']), $atrasadas, 'bi-calendar-x', $atrasadas ? 'red' : 'green', '', '', self::filtro('dash-nc-lista', 'ATRASADA', $rPrev))
            . self::cartao(self::t('c.acoes_vencidas', ['pt' => 'Ações de plano vencidas', 'en' => 'Overdue action plan items', 'es' => 'Acciones de plan vencidas']), $acoesVencidas ?? '–', 'bi-kanban', ($acoesVencidas ?? 0) ? 'red' : 'green', '', '', ' data-ir-ancora="dash-nc-acoes"')
            . '</div>';

        $cards = $h; $h = '';
        // Tipo × fase e tempo em aberto
        $faixas = ['A30' => '≤ 30 d', 'A90' => '31–90 d', 'A365' => '91 d–1 ano', 'MAIS' => '> 1 ano'];
        $h .= '<div class="admin-grid dash-grid-2"><article class="admin-panel"><h3><i class="bi bi-grid-3x3 me-1"></i>' . self::e(self::t('p.tipo_fase', ['pt' => 'Abertas por tipo e etapa', 'en' => 'Open by type and stage', 'es' => 'Abiertas por tipo y etapa'])) . '</h3>'
            . '<div class="dash-rolagem"><table class="admin-table dash-matriz">' . self::th(array_merge([self::t('col.tipo', ['pt' => 'Tipo', 'en' => 'Type', 'es' => 'Tipo'])],
                array_map(static fn($f) => '#' . self::rotuloFase($f), self::FASES), array_map(static fn($r) => '#' . $r, $faixas), ['#' . self::t('col.total', ['pt' => 'Total', 'en' => 'Total', 'es' => 'Total'])])) . '<tbody>';
        ksort($tipos, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($tipos as $tp => $c) {
            $tot = array_sum(array_intersect_key($c, array_flip(self::FASES))) + (int)($c['OUTRA'] ?? 0);
            $h .= '<tr><td><a class="dash-num"' . self::abrir(self::urlFiltro('naoconformidades_listageralocorrencias.php', ['DSCTIPONAOCONFORMIDADE' => self::exato($tp)]), $tLista . ' - ' . $tp) . '>' . self::e($tp) . '</a></td>';
            foreach (self::FASES as $f) $h .= '<td class="num">' . ((int)($c[$f] ?? 0) ?: '·') . '</td>';
            foreach (array_keys($faixas) as $fx) { $n = (int)($c['_IDADE_' . $fx] ?? 0); $h .= '<td class="num' . ($fx === 'MAIS' && $n ? ' dash-matriz-alerta' : '') . '">' . ($n ?: '·') . '</td>'; }
            $h .= '<td class="num"><b>' . $tot . '</b></td></tr>';
        }
        $h .= '</tbody></table></div><p class="dash-nota">' . self::e(self::t('p.tipo_nota', ['pt' => 'As colunas de tempo contam desde a abertura da ocorrência. Clique no tipo para abrir a lista geral.', 'en' => 'Time columns count from when the occurrence was opened. Click the type to open the general list.', 'es' => 'Las columnas de tiempo cuentan desde la apertura. Haga clic en el tipo para abrir la lista general.'])) . '</p></article>';

        // Abertas e finalizadas por mês
        $meses = $this->sel('vw_dash_nc_mes', [], 20) ?? [];
        usort($meses, static fn($a, $b) => strcmp((string)$a['MES'], (string)$b['MES']));
        $h .= '<article class="admin-panel"><h3><i class="bi bi-bar-chart me-1"></i>' . self::e(self::t('p.mes', ['pt' => 'Abertas × finalizadas por mês (12 meses)', 'en' => 'Opened × closed per month (12 months)', 'es' => 'Abiertas × finalizadas por mes (12 meses)'])) . '</h3>' . self::grafico($meses) . '</article></div>';

        $sTipo = $h; $h = '';
        // Por unidade e pessoas com mais NCs esperando ação
        arsort($unid); $maxU = max($unid ?: [0]);
        $h .= '<div class="admin-grid dash-grid-2"><article class="admin-panel"><h3><i class="bi bi-person-exclamation me-1"></i>' . self::e(self::t('p.pessoas', ['pt' => 'Com quem estão as NCs abertas', 'en' => 'Who holds the open NCs', 'es' => 'Con quién están las NC abiertas'])) . '</h3>'
            . '<div class="dash-rolagem"><table class="admin-table">' . self::th([self::t('col.pessoa', ['pt' => 'Pessoa', 'en' => 'Person', 'es' => 'Persona']), '#' . self::t('col.total', ['pt' => 'Total', 'en' => 'Total', 'es' => 'Total']),
                '#' . self::t('col.emitente', ['pt' => 'Emitente', 'en' => 'Issuer', 'es' => 'Emisor']), '#' . self::t('col.gestor', ['pt' => 'Gestor', 'en' => 'Manager', 'es' => 'Gestor']), '#' . self::t('col.analise', ['pt' => 'Análise/plano', 'en' => 'Analysis/plan', 'es' => 'Análisis/plan']), '#' . self::t('col.eficacia', ['pt' => 'Eficácia', 'en' => 'Effectiveness', 'es' => 'Eficacia']),
                '#' . self::t('col.parada90', ['pt' => 'Paradas > 90 d', 'en' => 'Idle > 90 d', 'es' => 'Paradas > 90 d']), '#' . self::t('col.max', ['pt' => 'Mais antiga (dias)', 'en' => 'Oldest (days)', 'es' => 'Más antigua (días)'])]) . '<tbody>';
        uasort($pessoas, static fn($a, $b) => [$b['total'], $a['nome']] <=> [$a['total'], $b['nome']]);
        foreach (array_slice($pessoas, 0, 40, true) as $p) {
            $h .= '<tr><td>' . self::e($p['nome']) . ($p['ativo'] ? '' : ' <span class="badge text-bg-danger">' . self::e(self::t('inativo', ['pt' => 'inativo', 'en' => 'inactive', 'es' => 'inactivo'])) . '</span>') . '</td><td class="num"><b>' . $p['total'] . '</b></td>'
                . '<td class="num">' . ($p['CRIACAO'] ?: '·') . '</td><td class="num">' . ($p['GESTOR'] ?: '·') . '</td><td class="num">' . ($p['ANALISE'] ?: '·') . '</td><td class="num">' . ($p['EFICACIA'] ?: '·') . '</td>'
                . '<td class="num' . ($p['parada90'] ? ' status-danger' : '') . '">' . ($p['parada90'] ?: '·') . '</td><td class="num">' . $p['maxParada'] . '</td></tr>';
        }
        if ($semPessoa) $h .= '<tr><td><i>' . self::e(self::t('sem_pessoa', ['pt' => 'Sem pessoa definida para a etapa', 'en' => 'No person set for the stage', 'es' => 'Sin persona definida para la etapa'])) . '</i></td><td class="num status-danger"><b>' . $semPessoa . '</b></td><td colspan="6"></td></tr>';
        $h .= '</tbody></table></div><p class="dash-nota">' . self::e(self::t('p.pessoas_nota', ['pt' => 'Quem age em cada etapa: emitente (criação/devolvida), gestor (avaliar e eficácia), responsável (análise e plano). "Parada" = dias desde a última passagem de etapa.', 'en' => 'Who acts at each stage: issuer (creation/returned), manager (review and effectiveness), owner (analysis and plan). "Idle" = days since the last stage change.', 'es' => 'Quién actúa en cada etapa: emisor (creación/devuelta), gestor (evaluar y eficacia), responsable (análisis y plan). "Parada" = días desde el último cambio de etapa.'])) . '</p></article>';
        $h .= '<article class="admin-panel"><h3><i class="bi bi-buildings me-1"></i>' . self::e(self::t('p.unidade', ['pt' => 'Abertas por unidade', 'en' => 'Open by unit', 'es' => 'Abiertas por unidad'])) . '</h3><div class="dash-rolagem"><table class="admin-table">'
            . self::th([self::t('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), '', '#' . self::t('col.total', ['pt' => 'Total', 'en' => 'Total', 'es' => 'Total'])]) . '<tbody>';
        foreach ($unid as $u => $n) $h .= '<tr><td>' . self::e($u) . '</td><td style="width:40%">' . self::barraMini($n, $maxU) . '</td><td class="num"><b>' . $n . '</b></td></tr>';
        $h .= '</tbody></table></div></article></div>';

        $sPessoas = $h; $h = '';
        // Paradas há mais tempo; os cartões filtram esta tabela (até 150 por cartão; todas na Lista geral)
        usort($abertas, static fn($a, $b) => (int)$b['DIAS_PARADA'] <=> (int)$a['DIAS_PARADA']);
        $grupos = static fn(array $r): array => array_values(array_filter([(string)$r['FASE'], (int)$r['IDADE_DIAS'] > 365 ? 'MAIS1ANO' : '', (int)$r['ATRASADA'] ? 'ATRASADA' : '']));
        $escolhidas = []; $porGrupo = [];
        foreach ($abertas as $i => $r) {
            $entra = $i < 100;
            foreach ($grupos($r) as $g) if (($porGrupo[$g] = ($porGrupo[$g] ?? 0) + 1) <= 150) $entra = true;
            if ($entra) $escolhidas[] = $r + ['_oculta' => $i >= 100];
        }
        $h .= '<article class="admin-panel"><h3><i class="bi bi-alarm me-1"></i>' . self::e(self::t('p.paradas', ['pt' => 'Paradas há mais tempo (100 primeiras)', 'en' => 'Idle the longest (first 100)', 'es' => 'Paradas hace más tiempo (100 primeras)'])) . '</h3>'
            . self::tabelaNc($escolhidas, true, 'dash-nc-lista', $grupos, self::t('p.paradas_top', ['pt' => 'As 100 paradas há mais tempo', 'en' => 'The 100 idle the longest', 'es' => 'Las 100 paradas hace más tiempo']))
            . '<p class="dash-nota">' . self::e(self::t('p.paradas_nota', ['pt' => 'Os cartões acima filtram esta lista (até 150 por cartão, as paradas há mais tempo). Para ver todas, use a Lista geral de ocorrências.', 'en' => 'The cards above filter this list (up to 150 per card, idle the longest). To see all, use the general occurrence list.', 'es' => 'Las tarjetas de arriba filtran esta lista (hasta 150 por tarjeta). Para ver todas, use la Lista general de ocurrencias.'])) . '</p></article>';
        $sParadas = $h;
        $acoes = $this->painelAcoesVencidas(null);
        return $cards . DashboardGrid::subabas('nc', [
            ['id' => 'paradas', 'icone' => 'bi-alarm', 'rotulo' => self::t('sa.paradas', ['pt' => 'Paradas há mais tempo', 'en' => 'Idle the longest', 'es' => 'Paradas hace más tiempo']), 'html' => $sParadas],
            ['id' => 'pessoas', 'icone' => 'bi-person-exclamation', 'rotulo' => self::t('sa.pessoas', ['pt' => 'Com quem estão e por unidade', 'en' => 'Who holds them and by unit', 'es' => 'Con quién están y por unidad']), 'html' => $sPessoas],
            ['id' => 'tipo', 'icone' => 'bi-grid-3x3', 'rotulo' => self::t('sa.tipo', ['pt' => 'Por tipo e etapa · por mês', 'en' => 'By type and stage · by month', 'es' => 'Por tipo y etapa · por mes']), 'html' => $sTipo],
            ['id' => 'acoes', 'icone' => 'bi-kanban', 'rotulo' => self::t('sa.acoes', ['pt' => 'Ações de plano vencidas', 'en' => 'Overdue action items', 'es' => 'Acciones de plan vencidas']), 'html' => $acoes],
        ]);
    }

    /** Ações de plano das NCs vencidas (vw_nc_acoes_vencidas, 334): todas ou só as de um usuário. */
    private function painelAcoesVencidas(?int $uid): string
    {
        $acoes = $this->sel('vw_nc_acoes_vencidas', $uid ? ['CODUSUARIO' => self::exato($uid)] : [], 5000);
        if ($acoes === null) return '';
        usort($acoes, static fn($a, $b) => (int)$b['DIAS_ATRASO'] <=> (int)$a['DIAS_ATRASO']);
        $rows = [];
        foreach ($acoes as $a) {
            $resp = (string)$a['RESPONSAVEL'] . ((int)$a['RESPONSAVEL_ATIVO'] ? '' : ' (' . self::t('inativo', ['pt' => 'inativo', 'en' => 'inactive', 'es' => 'inactivo']) . ')');
            $rows[] = ['cells' => ['NUMERO' => (string)$a['NUMERO'], 'ACAO' => ['text' => (string)$a['ACAO'], 'title' => (string)$a['ACAO']], 'RESPONSAVEL' => $resp, 'ETAPA' => (string)$a['ETAPA'],
                'PRAZO' => DashboardGrid::prazo($a['DATAFINAL']), 'PCT' => DashboardGrid::numero((int)$a['STATUS']), 'ATRASO' => DashboardGrid::numero((int)$a['DIAS_ATRASO'], 'status-danger')],
                'open' => ['url' => self::urlFiltro('naoconformidades_ocorrencia.php', [], ['codigo' => (int)$a['CODOCORRENCIA'], 'aba' => 'analise']), 'title' => self::t('nc', ['pt' => 'Não conformidade', 'en' => 'Nonconformity', 'es' => 'No conformidad']) . ' ' . $a['NUMERO']]];
        }
        $cols = ['NUMERO' => ['label' => self::t('col.numero', ['pt' => 'Número', 'en' => 'Number', 'es' => 'Número']), 'width' => '100px'],
            'ACAO' => ['label' => self::t('col.acao', ['pt' => 'Ação', 'en' => 'Action', 'es' => 'Acción'])]];
        if (!$uid) $cols['RESPONSAVEL'] = ['label' => self::t('col.responsavel', ['pt' => 'Responsável', 'en' => 'Owner', 'es' => 'Responsable']), 'filter' => 'select', 'width' => '190px'];
        $cols += ['ETAPA' => ['label' => self::t('col.etapa', ['pt' => 'Etapa', 'en' => 'Stage', 'es' => 'Etapa']), 'filter' => 'select', 'width' => '170px'],
            'PRAZO' => ['label' => self::t('col.prazo', ['pt' => 'Prazo', 'en' => 'Due', 'es' => 'Plazo']), 'width' => '110px'],
            'PCT' => ['label' => self::t('col.progresso', ['pt' => '% feito', 'en' => '% done', 'es' => '% hecho']), 'width' => '80px'],
            'ATRASO' => ['label' => self::t('col.atraso', ['pt' => 'Atraso (dias)', 'en' => 'Delay (days)', 'es' => 'Atraso (días)']), 'width' => '100px']];
        return '<div id="dash-nc-acoes">' . DashboardGrid::grid($uid ? 'dash-nc-acoes-minhas' : 'dash-nc-acoes-todas', $cols, $rows,
            ['vazio' => self::t('acoes_nada', ['pt' => 'Nenhuma ação de plano vencida.', 'en' => 'No overdue action item.', 'es' => 'Ninguna acción de plan vencida.']), 'transacao' => $uid ? 'dashboard' : 'dashboard_admin']) . '</div>';
    }

    private static function grafico(array $meses): string
    {
        if (!$meses) return self::vazio('–');
        $max = 1; foreach ($meses as $m) $max = max($max, (int)$m['ABERTAS'], (int)$m['FINALIZADAS']);
        $w = 560; $hgt = 190; $base = 160; $passo = $w / count($meses); $bw = max(6, $passo / 2 - 5);
        $svg = '<svg viewBox="0 0 ' . $w . ' ' . $hgt . '" style="width:100%;height:auto" role="img"><line x1="0" y1="' . $base . '" x2="' . $w . '" y2="' . $base . '" stroke="#d9e3de"/>';
        foreach ($meses as $i => $m) {
            $x = $i * $passo + 4;
            foreach ([['ABERTAS', '#c98a3a', 0], ['FINALIZADAS', '#2f8a5f', $bw + 2]] as [$k, $cor, $dx]) {
                $v = (int)$m[$k]; $alt = (int)round(140 * $v / $max);
                $svg .= '<rect x="' . number_format($x + $dx, 1, '.', '') . '" y="' . ($base - $alt) . '" width="' . number_format($bw, 1, '.', '') . '" height="' . $alt . '" fill="' . $cor . '"><title>' . self::e($m['MES'] . ': ' . $v) . '</title></rect>';
                if ($v) $svg .= '<text x="' . number_format($x + $dx + $bw / 2, 1, '.', '') . '" y="' . ($base - $alt - 3) . '" font-size="8" text-anchor="middle" fill="#55665f">' . $v . '</text>';
            }
            $svg .= '<text x="' . number_format($x + $bw, 1, '.', '') . '" y="' . ($base + 14) . '" font-size="9" text-anchor="middle" fill="#6b7d75">' . self::e(substr((string)$m['MES'], 5, 2) . '/' . substr((string)$m['MES'], 2, 2)) . '</text>';
        }
        $svg .= '</svg>';
        return $svg . '<div class="dash-nota"><span class="dash-risco-ponto" style="background:#c98a3a"></span>' . self::e(self::t('g.abertas', ['pt' => 'Abertas no mês', 'en' => 'Opened in the month', 'es' => 'Abiertas en el mes']))
            . ' &nbsp; <span class="dash-risco-ponto" style="background:#2f8a5f"></span>' . self::e(self::t('g.finalizadas', ['pt' => 'Finalizadas no mês', 'en' => 'Closed in the month', 'es' => 'Finalizadas en el mes'])) . '</div>';
    }

    /** Lista de NCs no grid padrão. Linhas com '_oculta' ficam fora do filtro inicial ($rotuloTop); os cartões filtram por grupo. */
    private static function tabelaNc(array $linhas, bool $comPessoa, string $id, ?callable $grupos = null, string $rotuloTop = ''): string
    {
        $temTop = false; $rows = [];
        foreach ($linhas as $r) {
            $g = $grupos ? $grupos($r) : [];
            if (empty($r['_oculta'])) $g[] = 'TOP'; else $temTop = true;
            $parada = (int)$r['DIAS_PARADA'];
            $rows[] = ['cells' => ['NUMERO' => (string)$r['NUMERO'], 'TIPO' => (string)$r['TIPO'], 'ETAPA' => (string)$r['ETAPA'],
                'QUEM' => $comPessoa ? ((string)$r['USUARIO_ACAO'] !== '' ? (string)$r['USUARIO_ACAO'] : '–') : self::oQueFazer((string)$r['FASE']),
                'UNIDADE' => (string)$r['DSCUNIDADE'], 'SUMARIO' => ['text' => (string)$r['SUMARIO'], 'title' => (string)$r['SUMARIO']],
                'ABERTA' => DashboardGrid::prazo($r['CRIADA_EM'], false), 'PARADA' => DashboardGrid::numero($parada, $parada > 90 ? 'status-danger' : ($parada > 30 ? 'status-warning' : ''))],
                'open' => self::abrirNcGrid($r), 'grupos' => implode(' ', $g)];
        }
        return DashboardGrid::grid($id, [
            'NUMERO' => ['label' => self::t('col.numero', ['pt' => 'Número', 'en' => 'Number', 'es' => 'Número']), 'width' => '100px'],
            'TIPO' => ['label' => self::t('col.tipo', ['pt' => 'Tipo', 'en' => 'Type', 'es' => 'Tipo']), 'filter' => 'select', 'width' => '140px'],
            'ETAPA' => ['label' => self::t('col.etapa', ['pt' => 'Etapa', 'en' => 'Stage', 'es' => 'Etapa']), 'filter' => 'select', 'width' => '170px'],
            'QUEM' => ['label' => $comPessoa ? self::t('col.com_quem', ['pt' => 'Com quem', 'en' => 'With whom', 'es' => 'Con quién']) : self::t('col.fazer', ['pt' => 'O que fazer', 'en' => 'What to do', 'es' => 'Qué hacer']), 'filter' => 'select', 'width' => '200px'],
            'UNIDADE' => ['label' => self::t('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), 'filter' => 'select', 'width' => '150px'],
            'SUMARIO' => ['label' => self::t('col.sumario', ['pt' => 'Ocorrência', 'en' => 'Occurrence', 'es' => 'Ocurrencia'])],
            'ABERTA' => ['label' => self::t('col.aberta', ['pt' => 'Aberta em', 'en' => 'Opened on', 'es' => 'Abierta el']), 'width' => '100px'],
            'PARADA' => ['label' => self::t('col.parada', ['pt' => 'Parada (dias)', 'en' => 'Idle (days)', 'es' => 'Parada (días)']), 'width' => '95px'],
        ], $rows, ['vazio' => self::t('nada', ['pt' => 'Nenhuma não conformidade aqui.', 'en' => 'No nonconformity here.', 'es' => 'Ninguna no conformidad aquí.']), 'transacao' => $comPessoa ? 'dashboard_admin' : 'dashboard',
            'grupo_inicial' => $temTop ? ['TOP', $rotuloTop] : []]);
    }

    /** Bloco "Situação da homologação" da aba Fornecedores do Dashboard da empresa (antes do ranking). */
    public function fornecedoresEmpresa(array $subExtras = []): string
    {
        $forn = $this->sel('vw_dash_fornecedores');
        if ($forn === null) return self::semMigracao();
        $itens = $this->sel('vw_dash_fornecedor_itens', [], 100) ?? [];
        $c = ['sem_resp' => 0, 'aval_venc' => 0, 'aval_30' => 0, 'sem_data' => 0, 'nc' => 0, 'forn_doc' => 0];
        $semResp = [];
        foreach ($forn as $f) {
            if ((int)($f['CODUSUARIO_RESPONSAVEL'] ?? 0) <= 0 || !(int)$f['RESPONSAVEL_ATIVO']) { $c['sem_resp']++; $semResp[] = $f; }
            $a = (string)$f['AVALIACAO'];
            if ($a === 'VENCIDA') $c['aval_venc']++; elseif ($a === 'VENCE_30') $c['aval_30']++; elseif ($a === 'SEM_DATA') $c['sem_data']++;
            $c['nc'] += (int)$f['NC_ABERTAS'];
            if ((int)$f['DOC_VERMELHO'] > 0) $c['forn_doc']++;
        }
        $porCat = [];
        foreach ($itens as $i) $porCat[(string)$i['CATEGORIA']][(string)$i['COR']] = ['n' => (int)$i['TOTAL'], 'f' => (int)$i['FORNECEDORES']];
        $n = static fn(string $cat, string $cor): int => (int)($porCat[$cat][$cor]['n'] ?? 0);
        $func = $n('DOCFUNC', 'VERMELHO') + $n('INTEGRACAO', 'VERMELHO') + $n('TREINAMENTO', 'VERMELHO');
        $painel = self::urlFiltro('homologacaofornecedores_painel.php');
        $tPainel = self::t('painel', ['pt' => 'Painel de Homologação', 'en' => 'Homologation panel', 'es' => 'Panel de Homologación']);

        $h = '<h3 class="dash-secao"><i class="bi bi-clipboard2-pulse me-1"></i>' . self::e(self::t('fo.situacao', ['pt' => 'Situação da homologação (fornecedores ativos)', 'en' => 'Homologation status (active suppliers)', 'es' => 'Situación de la homologación (proveedores activos)'])) . '</h3>'
            . '<div class="admin-cards">'
            . self::cartao(self::t('fo.ativos', ['pt' => 'Fornecedores ativos', 'en' => 'Active suppliers', 'es' => 'Proveedores activos']), count($forn), 'bi-truck', 'blue', '', '', self::abrir($painel, $tPainel))
            . self::cartao($rSR = self::t('fo.sem_resp', ['pt' => 'Sem responsável (ou responsável inativo)', 'en' => 'Without owner (or inactive owner)', 'es' => 'Sin responsable (o responsable inactivo)']), $c['sem_resp'], 'bi-person-x', $c['sem_resp'] ? 'red' : 'green', '', '', self::filtro('dash-fo-lista', 'SEM_RESP', $rSR))
            . self::cartao($rAV = self::t('fo.aval_venc', ['pt' => 'Avaliação vencida', 'en' => 'Evaluation overdue', 'es' => 'Evaluación vencida']), $c['aval_venc'], 'bi-calendar-x', $c['aval_venc'] ? 'red' : 'green', $c['aval_30'] . ' ' . self::e(self::t('fo.aval_30', ['pt' => 'vencem em 30 dias', 'en' => 'due in 30 days', 'es' => 'vencen en 30 días'])), '', self::filtro('dash-fo-lista', 'VENCIDA', $rAV))
            . self::cartao($rSD = self::t('fo.sem_data', ['pt' => 'Sem data da próxima avaliação', 'en' => 'No next evaluation date', 'es' => 'Sin fecha de la próxima evaluación']), $c['sem_data'], 'bi-question-circle', $c['sem_data'] ? 'orange' : 'green', '', '', self::filtro('dash-fo-lista', 'SEM_DATA', $rSD))
            . self::cartao($rDV = self::t('fo.doc_venc', ['pt' => 'Documentos vencidos ou reprovados', 'en' => 'Expired or rejected documents', 'es' => 'Documentos vencidos o rechazados']), $n('DOCUMENTO', 'VERMELHO'), 'bi-file-earmark-x', $n('DOCUMENTO', 'VERMELHO') ? 'red' : 'green', $c['forn_doc'] . ' ' . self::e(self::t('fo.fornecedores', ['pt' => 'fornecedores', 'en' => 'suppliers', 'es' => 'proveedores'])), '', self::filtro('dash-fo-lista', 'DOC_VERMELHO', $rDV))
            . self::cartao($rD3 = self::t('fo.doc_30', ['pt' => 'Documentos a vencer (prazo de reação)', 'en' => 'Documents about to expire (reaction time)', 'es' => 'Documentos por vencer (plazo de reacción)']), $n('DOCUMENTO', 'AMARELO'), 'bi-file-earmark-medical', $n('DOCUMENTO', 'AMARELO') ? 'orange' : 'green', '', '', self::filtro('dash-fo-lista', 'DOC_AMARELO', $rD3))
            . self::cartao($rDS = self::t('fo.doc_sem', ['pt' => 'Documentos sem situação', 'en' => 'Documents without status', 'es' => 'Documentos sin situación']), $n('DOCUMENTO', 'BRANCO'), 'bi-file-earmark', 'gray', '', '', self::filtro('dash-fo-lista', 'DOC_BRANCO', $rDS))
            . self::cartao($rFU = self::t('fo.func', ['pt' => 'Pendências de funcionários (documentos, integração, treinamentos)', 'en' => 'Employee items pending (documents, onboarding, training)', 'es' => 'Pendientes de empleados (documentos, integración, capacitaciones)']), $func, 'bi-person-vcard', $func ? 'red' : 'green', '', '', self::filtro('dash-fo-lista', 'FUNC', $rFU))
            . '</div>';

        $cards = $h; $h = '';
        // Itens por categoria e cor
        $cats = ['DOCUMENTO' => self::t('cat.documento', ['pt' => 'Documentos do fornecedor', 'en' => 'Supplier documents', 'es' => 'Documentos del proveedor']),
            'AUDITORIA' => self::t('cat.auditoria', ['pt' => 'Auditorias', 'en' => 'Audits', 'es' => 'Auditorías']),
            'NIVEL' => self::t('cat.nivel', ['pt' => 'Nível de serviço / avaliação', 'en' => 'Service level / evaluation', 'es' => 'Nivel de servicio / evaluación']),
            'DOCFUNC' => self::t('cat.docfunc', ['pt' => 'Documentos de funcionários', 'en' => 'Employee documents', 'es' => 'Documentos de empleados']),
            'INTEGRACAO' => self::t('cat.integracao', ['pt' => 'Integração de funcionários', 'en' => 'Employee onboarding', 'es' => 'Integración de empleados']),
            'TREINAMENTO' => self::t('cat.treinamento', ['pt' => 'Treinamentos de funcionários', 'en' => 'Employee training', 'es' => 'Capacitaciones de empleados'])];
        $cores = ['VERMELHO' => ['#c55b55', self::t('cor.vermelho', ['pt' => 'Vencido / reprovado', 'en' => 'Expired / rejected', 'es' => 'Vencido / rechazado'])], 'AMARELO' => ['#d39e00', self::t('cor.amarelo', ['pt' => 'A vencer / com a empresa', 'en' => 'About to expire / with the company', 'es' => 'Por vencer / con la empresa'])],
            'VERDE' => ['#2f8a5f', self::t('cor.verde', ['pt' => 'Em dia', 'en' => 'Up to date', 'es' => 'Al día'])], 'BRANCO' => ['#b9c4bf', self::t('cor.branco', ['pt' => 'Sem situação', 'en' => 'No status', 'es' => 'Sin situación'])], 'NEUTRO' => ['#e3e8e5', self::t('cor.neutro', ['pt' => 'Não se aplica', 'en' => 'Not applicable', 'es' => 'No aplica'])]];
        $h .= '<div class="admin-grid dash-grid-2"><article class="admin-panel"><h3><i class="bi bi-bar-chart-steps me-1"></i>' . self::e(self::t('fo.itens', ['pt' => 'Itens da homologação por situação', 'en' => 'Homologation items by status', 'es' => 'Ítems de la homologación por situación'])) . '</h3>'
            . '<table class="admin-table">' . self::th(array_merge([self::t('col.item', ['pt' => 'Item', 'en' => 'Item', 'es' => 'Ítem']), ''], array_map(static fn($c) => '#' . $c[1], $cores))) . '<tbody>';
        foreach ($cats as $cat => $rot) {
            if (empty($porCat[$cat])) continue;
            $h .= '<tr><td>' . self::e($rot) . '</td><td style="width:28%"><div class="dash-barra">';
            $tot = 0; foreach (array_keys($cores) as $cor) $tot += $n($cat, $cor);
            foreach ($cores as $cor => [$hex]) if ($n($cat, $cor)) $h .= '<span style="width:' . number_format(100 * $n($cat, $cor) / max(1, $tot), 2, '.', '') . '%;background:' . $hex . '" title="' . $n($cat, $cor) . '"></span>';
            $h .= '</div></td>';
            foreach (array_keys($cores) as $cor) $h .= '<td class="num' . ($cor === 'VERMELHO' && $n($cat, $cor) ? ' status-danger' : '') . '">' . ($n($cat, $cor) ?: '·') . '</td>';
            $h .= '</tr>';
        }
        $h .= '</tbody></table><p class="dash-nota">' . self::e(self::t('fo.itens_nota', ['pt' => 'Mesma regra de cores do Painel de Homologação. Clique em "Fornecedores ativos" para abrir o Painel com os filtros.', 'en' => 'Same color rule as the Homologation panel. Click "Active suppliers" to open the panel with filters.', 'es' => 'Misma regla de colores del Panel de Homologación. Haga clic en "Proveedores activos" para abrir el Panel con filtros.'])) . '</p></article>';

        // Sem responsável
        $h .= '<article class="admin-panel" id="dash-fo-sem-resp"><h3><i class="bi bi-person-x me-1"></i>' . self::e(self::t('fo.sem_resp_lista', ['pt' => 'Fornecedores sem responsável ativo', 'en' => 'Suppliers without an active owner', 'es' => 'Proveedores sin responsable activo'])) . ' (' . count($semResp) . ')</h3>';
        usort($semResp, static fn($a, $b) => strnatcasecmp((string)$a['RAZAOSOCIAL'], (string)$b['RAZAOSOCIAL']));
        $h .= self::tabelaFornecedores($semResp, true, 'dash-fo-semresp');
        $h .= '</article></div>';
        $sItens = $h; $h = '';

        // Os que mais precisam de atenção
        $peso = static fn(array $f): int => 3 * (int)$f['DOC_VERMELHO'] + 2 * (int)$f['FUNC_VERMELHO'] + 3 * (int)$f['AUD_VERMELHO'] + 4 * (int)$f['NC_ABERTAS'] + (int)$f['DOC_AMARELO'] + ($f['AVALIACAO'] === 'VENCIDA' ? 3 : 0);
        $todos = $forn;
        usort($todos, static fn($a, $b) => [$peso($b), $a['RAZAOSOCIAL']] <=> [$peso($a), $b['RAZAOSOCIAL']]);
        foreach ($todos as $i => &$f) $f['_oculta'] = $i >= 50 || $peso($f) === 0;
        unset($f);
        $h .= '<article class="admin-panel"><h3><i class="bi bi-exclamation-triangle me-1"></i>' . self::e(self::t('fo.atencao', ['pt' => 'Fornecedores que mais precisam de atenção (50 primeiros)', 'en' => 'Suppliers needing the most attention (first 50)', 'es' => 'Proveedores que más necesitan atención (50 primeros)'])) . '</h3>'
            . self::tabelaFornecedores($todos, true, 'dash-fo-lista', self::t('fo.top50', ['pt' => 'Os 50 que mais precisam de atenção', 'en' => 'The 50 needing the most attention', 'es' => 'Los 50 que más necesitan atención']))
            . '<p class="dash-nota">' . self::e(self::t('fo.atencao_nota', ['pt' => 'Os cartões acima filtram esta lista (todos os fornecedores ativos).', 'en' => 'The cards above filter this list (all active suppliers).', 'es' => 'Las tarjetas de arriba filtran esta lista (todos los proveedores activos).'])) . '</p></article>';
        return $cards . DashboardGrid::subabas('fo', [
            ['id' => 'atencao', 'icone' => 'bi-exclamation-triangle', 'rotulo' => self::t('sa.fo_atencao', ['pt' => 'Fornecedores', 'en' => 'Suppliers', 'es' => 'Proveedores']), 'contagem' => count($forn), 'html' => $h],
            ['id' => 'itens', 'icone' => 'bi-bar-chart-steps', 'rotulo' => self::t('sa.fo_itens', ['pt' => 'Itens por situação e sem responsável', 'en' => 'Items by status and without owner', 'es' => 'Ítems por situación y sin responsable']), 'html' => $sItens],
            ...$subExtras,
        ]);
    }

    private static function abrirFornecedor(array $f): string
    {
        return self::abrir(self::urlFiltro('homologacaofornecedor_fornecedor.php', ['CODFORNECEDOR' => (string)(int)$f['CODFORNECEDOR']]), self::t('fornecedor', ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor']) . ' - ' . $f['RAZAOSOCIAL']);
    }

    private static function rotuloAvaliacao(array $f): string
    {
        return match ((string)$f['AVALIACAO']) {
            'VENCIDA' => '<span class="status-danger">' . self::data($f['PROXIMA_AVALIACAO']) . '</span>',
            'VENCE_30' => '<span class="status-warning">' . self::data($f['PROXIMA_AVALIACAO']) . '</span>',
            'SEM_DATA' => '<span class="text-muted">' . self::e(self::t('sem_data', ['pt' => 'sem data', 'en' => 'no date', 'es' => 'sin fecha'])) . '</span>',
            default => self::data($f['PROXIMA_AVALIACAO']),
        };
    }

    private static function gruposFornecedor(array $f): string
    {
        $g = [(string)$f['AVALIACAO']];
        if ((int)($f['CODUSUARIO_RESPONSAVEL'] ?? 0) <= 0 || !(int)$f['RESPONSAVEL_ATIVO']) $g[] = 'SEM_RESP';
        foreach (['DOC_VERMELHO', 'DOC_AMARELO', 'DOC_BRANCO'] as $k) if ((int)$f[$k] > 0) $g[] = $k;
        if ((int)$f['FUNC_VERMELHO'] > 0) $g[] = 'FUNC';
        if ((int)$f['NC_ABERTAS'] > 0) $g[] = 'NC';
        return implode(' ', $g);
    }

    private static function tabelaFornecedores(array $linhas, bool $comResponsavel, string $id, string $rotuloTop = ''): string
    {
        $temTop = false; $rows = [];
        foreach ($linhas as $f) {
            $g = self::gruposFornecedor($f);
            if (empty($f['_oculta'])) $g .= ' TOP'; else $temTop = true;
            $aval = ['VENCIDA' => self::t('av.vencida', ['pt' => 'Vencida', 'en' => 'Overdue', 'es' => 'Vencida']), 'VENCE_30' => self::t('av.30', ['pt' => 'Vence em 30 dias', 'en' => 'Due in 30 days', 'es' => 'Vence en 30 días']),
                'SEM_DATA' => self::t('av.sem', ['pt' => 'Sem data', 'en' => 'No date', 'es' => 'Sin fecha']), 'EM_DIA' => self::t('av.dia', ['pt' => 'Em dia', 'en' => 'Up to date', 'es' => 'Al día'])][(string)$f['AVALIACAO']] ?? '';
            $p = DashboardGrid::prazo($f['PROXIMA_AVALIACAO']);
            $cells = ['FORNECEDOR' => ['text' => (string)$f['RAZAOSOCIAL'], 'title' => (string)$f['RAZAOSOCIAL']], 'UNIDADE' => (string)$f['DSCUNIDADE']];
            if ($comResponsavel) $cells['RESPONSAVEL'] = (int)($f['CODUSUARIO_RESPONSAVEL'] ?? 0) <= 0 ? '–' : (string)$f['RESPONSAVEL'] . ((int)$f['RESPONSAVEL_ATIVO'] ? '' : ' (' . self::t('inativo', ['pt' => 'inativo', 'en' => 'inactive', 'es' => 'inactivo']) . ')');
            $cells += ['AVALIACAO' => ['text' => $aval, 'html' => ($p['text'] !== '' ? $p['html'] : '<span class="text-muted">' . self::e($aval) . '</span>'), 'sort' => $p['sort']],
                'DOC_V' => DashboardGrid::numero((int)$f['DOC_VERMELHO'], 'status-danger'), 'DOC_A' => DashboardGrid::numero((int)$f['DOC_AMARELO'], 'status-warning'),
                'DOC_B' => DashboardGrid::numero((int)$f['DOC_BRANCO']), 'FUNC' => DashboardGrid::numero((int)$f['FUNC_VERMELHO'], 'status-danger'), 'NC' => DashboardGrid::numero((int)$f['NC_ABERTAS'], 'status-warning')];
            $rows[] = ['cells' => $cells, 'open' => DashboardGrid::abrirDe(self::abrirFornecedor($f)), 'grupos' => trim($g)];
        }
        $cols = ['FORNECEDOR' => ['label' => self::t('col.fornecedor', ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor'])],
            'UNIDADE' => ['label' => self::t('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), 'filter' => 'select', 'width' => '150px']];
        if ($comResponsavel) $cols['RESPONSAVEL'] = ['label' => self::t('col.responsavel', ['pt' => 'Responsável', 'en' => 'Owner', 'es' => 'Responsable']), 'filter' => 'select', 'width' => '180px'];
        $cols += ['AVALIACAO' => ['label' => self::t('col.avaliacao', ['pt' => 'Próxima avaliação', 'en' => 'Next evaluation', 'es' => 'Próxima evaluación']), 'width' => '120px'],
            'DOC_V' => ['label' => self::t('col.doc_venc', ['pt' => 'Doc. vencidos', 'en' => 'Expired docs', 'es' => 'Doc. vencidos']), 'filter' => 'none', 'width' => '85px'],
            'DOC_A' => ['label' => self::t('col.doc_30', ['pt' => 'Doc. a vencer', 'en' => 'Docs to expire', 'es' => 'Doc. por vencer']), 'filter' => 'none', 'width' => '85px'],
            'DOC_B' => ['label' => self::t('col.doc_sem', ['pt' => 'Doc. sem situação', 'en' => 'Docs no status', 'es' => 'Doc. sin situación']), 'filter' => 'none', 'width' => '85px'],
            'FUNC' => ['label' => self::t('col.func', ['pt' => 'Funcionários', 'en' => 'Employees', 'es' => 'Empleados']), 'filter' => 'none', 'width' => '85px'],
            'NC' => ['label' => self::t('col.nc', ['pt' => 'NCs abertas', 'en' => 'Open NCs', 'es' => 'NC abiertas']), 'filter' => 'none', 'width' => '80px']];
        return DashboardGrid::grid($id, $cols, $rows, ['vazio' => self::t('fo.nada', ['pt' => 'Nenhum fornecedor aqui.', 'en' => 'No supplier here.', 'es' => 'Ningún proveedor aquí.']),
            'transacao' => $comResponsavel ? 'dashboard_admin' : 'dashboard', 'grupo_inicial' => $temTop ? ['TOP', $rotuloTop] : []]);
    }

    // ================================================================== DASHBOARD PESSOAL
    /** NCs abertas que esperam a ação do usuário. */
    public function minhasNc(int $uid): ?array
    {
        return $this->sel('vw_dash_nc_abertas', ['CODUSUARIO_ACAO' => self::exato($uid)], 5000);
    }

    /** Cartões da Visão geral pessoal (NC e fornecedores). $podeNc / $podeFo: permissões já conferidas pela página. */
    public function cartoesPessoal(int $uid, bool $podeNc, bool $podeFo): string
    {
        $h = '';
        if ($podeNc && ($minhas = $this->minhasNc($uid)) !== null) {
            $paradas = count(array_filter($minhas, static fn($r) => (int)$r['DIAS_PARADA'] > 30));
            $h .= self::cartao(self::t('pc.nc', ['pt' => 'Não conformidades esperando minha ação', 'en' => 'Nonconformities waiting for me', 'es' => 'No conformidades esperando mi acción']), count($minhas), 'bi-exclamation-diamond', count($minhas) ? 'orange' : 'green',
                $paradas . ' ' . self::e(self::t('pc.paradas30', ['pt' => 'paradas há mais de 30 dias', 'en' => 'idle for more than 30 days', 'es' => 'paradas hace más de 30 días'])), 'nc');
        }
        if ($podeFo && ($meus = $this->sel('vw_dash_fornecedores', ['CODUSUARIO_RESPONSAVEL' => self::exato($uid)], 5000)) !== null && $meus) {
            $atencao = count(array_filter($meus, static fn($f) => (int)$f['DOC_VERMELHO'] + (int)$f['FUNC_VERMELHO'] + (int)$f['AUD_VERMELHO'] > 0 || in_array($f['AVALIACAO'], ['VENCIDA', 'SEM_DATA'], true)));
            $h .= self::cartao(self::t('pc.fo', ['pt' => 'Meus fornecedores com pendência', 'en' => 'My suppliers with pending items', 'es' => 'Mis proveedores con pendientes']), $atencao, 'bi-truck', $atencao ? 'orange' : 'green',
                count($meus) . ' ' . self::e(self::t('pc.fo_total', ['pt' => 'sob minha responsabilidade', 'en' => 'under my responsibility', 'es' => 'bajo mi responsabilidad'])), 'fornecedores');
        }
        return $h;
    }

    /** Aba "Minhas não conformidades". */
    public function ncPessoal(int $uid): string
    {
        $minhas = $this->minhasNc($uid);
        if ($minhas === null) return self::semMigracao();
        $porFase = array_fill_keys(self::FASES, 0);
        foreach ($minhas as $r) $porFase[(string)$r['FASE']] = ($porFase[(string)$r['FASE']] ?? 0) + 1;
        $emitidas = $this->sel('vw_dash_nc_abertas', ['CODUSUARIO_EMISSOR' => self::exato($uid)], 2000) ?? [];
        $emitidasOutros = array_values(array_filter($emitidas, static fn($r) => (int)($r['CODUSUARIO_ACAO'] ?? 0) !== $uid));
        $acoes = $this->contar('vw_nc_acoes_vencidas', ['CODUSUARIO' => self::exato($uid)]);
        $minhasLista = self::urlFiltro('naoconformidades_minhasocorrencias.php');
        $tMinhas = self::t('minhas', ['pt' => 'Minhas não conformidades', 'en' => 'My nonconformities', 'es' => 'Mis no conformidades']);
        $h = '<div class="admin-cards dash-cards-6">'
            . self::cartao(self::t('pn.esperando', ['pt' => 'Esperando minha ação', 'en' => 'Waiting for me', 'es' => 'Esperando mi acción']), count($minhas), 'bi-exclamation-diamond', count($minhas) ? 'orange' : 'green', '', '', self::abrir($minhasLista, $tMinhas))
            . self::cartao($r1 = self::t('pn.criacao', ['pt' => 'Para completar ou corrigir (emitente)', 'en' => 'To complete or fix (issuer)', 'es' => 'Para completar o corregir (emisor)']), $porFase['CRIACAO'], 'bi-pencil-square', $porFase['CRIACAO'] ? 'orange' : 'green', '', '', self::filtro('dash-nc-minhas', 'CRIACAO', $r1))
            . self::cartao($r2 = self::t('pn.gestor', ['pt' => 'Para avaliar como gestor', 'en' => 'To review as manager', 'es' => 'Para evaluar como gestor']), $porFase['GESTOR'], 'bi-person-check', $porFase['GESTOR'] ? 'orange' : 'green', '', '', self::filtro('dash-nc-minhas', 'GESTOR', $r2))
            . self::cartao($r3 = self::t('pn.analise', ['pt' => 'Análise da causa e plano de ação', 'en' => 'Root cause analysis and action plan', 'es' => 'Análisis de causa y plan de acción']), $porFase['ANALISE'], 'bi-diagram-3', $porFase['ANALISE'] ? 'orange' : 'green', '', '', self::filtro('dash-nc-minhas', 'ANALISE', $r3))
            . self::cartao($r4 = self::t('pn.eficacia', ['pt' => 'Para avaliar a eficácia', 'en' => 'Effectiveness to review', 'es' => 'Para evaluar la eficacia']), $porFase['EFICACIA'], 'bi-clipboard-check', $porFase['EFICACIA'] ? 'orange' : 'green', '', '', self::filtro('dash-nc-minhas', 'EFICACIA', $r4))
            . self::cartao(self::t('pn.acoes', ['pt' => 'Minhas ações de plano vencidas', 'en' => 'My overdue action items', 'es' => 'Mis acciones de plan vencidas']), $acoes ?? '–', 'bi-kanban', ($acoes ?? 0) ? 'red' : 'green', '', '', ' data-ir-ancora="dash-nc-acoes"')
            . '</div>';
        usort($minhas, static fn($a, $b) => (int)$b['DIAS_PARADA'] <=> (int)$a['DIAS_PARADA']);
        usort($emitidasOutros, static fn($a, $b) => (int)$b['DIAS_PARADA'] <=> (int)$a['DIAS_PARADA']);
        return $h . DashboardGrid::subabas('nc-pessoal', [
            ['id' => 'esperando', 'icone' => 'bi-hand-index', 'rotulo' => self::t('pn.lista', ['pt' => 'Esperando a minha ação', 'en' => 'Waiting for my action', 'es' => 'Esperando mi acción']), 'contagem' => count($minhas),
                'html' => self::tabelaNc(array_slice($minhas, 0, 3000), false, 'dash-nc-minhas', static fn(array $r): array => [(string)$r['FASE']])],
            ['id' => 'emitidas', 'icone' => 'bi-send', 'rotulo' => self::t('pn.emitidas', ['pt' => 'Abertas por mim, com outras pessoas', 'en' => 'Opened by me, with other people', 'es' => 'Abiertas por mí, con otras personas']), 'contagem' => count($emitidasOutros),
                'html' => self::tabelaNc(array_slice($emitidasOutros, 0, 3000), true, 'dash-nc-emitidas')],
            ['id' => 'acoes', 'icone' => 'bi-kanban', 'rotulo' => self::t('pn.acoes_lista', ['pt' => 'Minhas ações de plano vencidas', 'en' => 'My overdue action items', 'es' => 'Mis acciones de plan vencidas']), 'contagem' => $acoes, 'html' => $this->painelAcoesVencidas($uid)],
        ]);
    }

    /**
     * Aba "Meus fornecedores": pendências de Homologação de Home › Minhas pendências (mesmas regras, já sem as ocultadas na
     * Gestão de pendências) e os fornecedores sob a responsabilidade do usuário.
     */
    public function fornecedoresPessoal(int $uid, array $pendencias): string
    {
        $meus = $this->sel('vw_dash_fornecedores', ['CODUSUARIO_RESPONSAVEL' => self::exato($uid)], 5000);
        if ($meus === null) return self::semMigracao();
        $hoje = new DateTimeImmutable('today');
        $venc = 0; $prox = 0; $sem = 0;
        foreach ($pendencias as $p) {
            $d = $p['prazo'] ?? null;
            if (!$d instanceof DateTimeInterface) $sem++;
            elseif ($d < $hoje) $venc++;
            elseif ($d <= $hoje->modify('+30 days')) $prox++;
        }
        $docVenc = array_sum(array_map(static fn($f) => (int)$f['DOC_VERMELHO'], $meus));
        $func = array_sum(array_map(static fn($f) => (int)$f['FUNC_VERMELHO'], $meus));
        $nc = array_sum(array_map(static fn($f) => (int)$f['NC_ABERTAS'], $meus));
        $h = '<div class="admin-cards">'
            . self::cartao($rM = self::t('pf.meus', ['pt' => 'Fornecedores sob minha responsabilidade', 'en' => 'Suppliers under my responsibility', 'es' => 'Proveedores bajo mi responsabilidad']), count($meus), 'bi-truck', 'blue', '', '', self::filtro('dash-fo-meus', '*', $rM))
            . self::cartao(self::t('pf.pend', ['pt' => 'Pendências de fornecedores', 'en' => 'Supplier pending items', 'es' => 'Pendientes de proveedores']), count($pendencias), 'bi-list-check', count($pendencias) ? 'orange' : 'green', '', '', self::abrir('home_pendencias.php', self::t('pf.minhas', ['pt' => 'Minhas pendências', 'en' => 'My pending items', 'es' => 'Mis pendientes'])))
            . self::cartao($rV = self::t('pf.venc', ['pt' => 'Vencidas', 'en' => 'Overdue', 'es' => 'Vencidas']), $venc, 'bi-calendar-x', $venc ? 'red' : 'green', '', '', self::filtro('dash-fo-pend', 'VENC', $rV))
            . self::cartao($rP = self::t('pf.prox', ['pt' => 'Vencem em 30 dias', 'en' => 'Due in 30 days', 'es' => 'Vencen en 30 días']), $prox, 'bi-alarm', $prox ? 'orange' : 'green', '', '', self::filtro('dash-fo-pend', 'PROX', $rP))
            . self::cartao($rS = self::t('pf.sem', ['pt' => 'Sem data (ex.: próxima avaliação)', 'en' => 'Without date (e.g. next evaluation)', 'es' => 'Sin fecha (ej.: próxima evaluación)']), $sem, 'bi-question-circle', $sem ? 'orange' : 'green', '', '', self::filtro('dash-fo-pend', 'SEM', $rS))
            . self::cartao($rD = self::t('pf.doc', ['pt' => 'Documentos vencidos ou reprovados', 'en' => 'Expired or rejected documents', 'es' => 'Documentos vencidos o rechazados']), $docVenc, 'bi-file-earmark-x', $docVenc ? 'red' : 'green', '', '', self::filtro('dash-fo-meus', 'DOC_VERMELHO', $rD))
            . self::cartao($rF = self::t('pf.func', ['pt' => 'Pendências de funcionários', 'en' => 'Employee items pending', 'es' => 'Pendientes de empleados']), $func, 'bi-person-vcard', $func ? 'red' : 'green', '', '', self::filtro('dash-fo-meus', 'FUNC', $rF))
            . self::cartao($rN = self::t('pf.nc', ['pt' => 'NCs abertas dos meus fornecedores', 'en' => 'Open NCs of my suppliers', 'es' => 'NC abiertas de mis proveedores']), $nc, 'bi-exclamation-diamond', $nc ? 'orange' : 'green', '', '', self::filtro('dash-fo-meus', 'NC', $rN))
            . '</div>';
        // Pendências (as de Minhas pendências)
        usort($pendencias, static function ($a, $b) {
            $da = $a['prazo'] instanceof DateTimeInterface ? $a['prazo']->format('Y-m-d') : '9999'; $db = $b['prazo'] instanceof DateTimeInterface ? $b['prazo']->format('Y-m-d') : '9999';
            return strcmp($da, $db);
        });
        $rowsP = [];
        foreach ($pendencias as $p) {
            $d = $p['prazo'] instanceof DateTimeInterface ? $p['prazo'] : null;
            $g = $d === null ? 'SEM' : ($d < $hoje ? 'VENC' : ($d <= $hoje->modify('+30 days') ? 'PROX' : 'DEPOIS'));
            $rowsP[] = ['cells' => ['PRAZO' => DashboardGrid::prazo($d ? $d->format('Y-m-d') : ''), 'PENDENCIA' => ['text' => (string)$p['titulo'], 'title' => (string)$p['titulo']],
                'FAZER' => ['text' => (string)$p['motivo'], 'title' => (string)$p['motivo']]], 'open' => ['url' => (string)$p['url'], 'title' => (string)$p['titulo']], 'grupos' => $g];
        }
        usort($meus, static fn($a, $b) => [(int)$b['DOC_VERMELHO'] + (int)$b['FUNC_VERMELHO'] + (int)$b['NC_ABERTAS'], $a['RAZAOSOCIAL']] <=> [(int)$a['DOC_VERMELHO'] + (int)$a['FUNC_VERMELHO'] + (int)$a['NC_ABERTAS'], $b['RAZAOSOCIAL']]);
        return $h . DashboardGrid::subabas('fo-pessoal', [
            ['id' => 'pend', 'icone' => 'bi-list-check', 'rotulo' => self::t('pf.lista', ['pt' => 'O que preciso fazer nos fornecedores', 'en' => 'What I need to do on suppliers', 'es' => 'Lo que necesito hacer en los proveedores']), 'contagem' => count($pendencias),
                'html' => DashboardGrid::grid('dash-fo-pend', [
                    'PRAZO' => ['label' => self::t('col.prazo', ['pt' => 'Prazo', 'en' => 'Due', 'es' => 'Plazo']), 'width' => '120px'],
                    'PENDENCIA' => ['label' => self::t('col.pendencia', ['pt' => 'Pendência', 'en' => 'Pending item', 'es' => 'Pendiente'])],
                    'FAZER' => ['label' => self::t('col.fazer', ['pt' => 'O que fazer', 'en' => 'What to do', 'es' => 'Qué hacer']), 'filter' => 'select'],
                ], $rowsP, ['vazio' => self::t('pf.nada', ['pt' => 'Nenhuma pendência de fornecedor.', 'en' => 'No supplier pending item.', 'es' => 'Ningún pendiente de proveedor.']), 'transacao' => 'dashboard', 'ordem' => ['PRAZO', 'asc']])],
            ['id' => 'meus', 'icone' => 'bi-truck', 'rotulo' => self::t('pf.meus_lista', ['pt' => 'Meus fornecedores', 'en' => 'My suppliers', 'es' => 'Mis proveedores']), 'contagem' => count($meus),
                'html' => self::tabelaFornecedores($meus, false, 'dash-fo-meus')],
        ]);
    }
}
