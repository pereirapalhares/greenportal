<?php
declare(strict_types=1);
/*
 * Painel do gestor (slide 12 da apresentação Atlas Schindler 2026): a equipe do usuário logado — subordinados diretos e
 * indiretos pelo campo gestor do cadastro de usuários —, em abas no padrão dos outros dashboards:
 *   Minha equipe · Atendimento legal da equipe · Prazos da equipe
 * Cada pessoa abre o painel dela (dashboard.php?usuario=...) em popup; requisitos abrem filtrados pelo responsável.
 * Dados só por views lidas com sp_crud_select (classes/DashboardEquipe.php).
 * Migração 369: listas no grid padrão (DashboardGrid / grid local) e, no Atendimento legal, os quadros em sub-abas
 * (Por responsável | Não atendidos | Divergências), como nos dashboards pessoal e administrativo (migração 368).
 */
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) { http_response_code(403); exit(IdiomaService::t('dashboard.erro.acesso', ['pt' => 'Acesso não autorizado.', 'en' => 'Unauthorized access.', 'es' => 'Acceso no autorizado.'])); }
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/DashboardEquipe.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioImagem.php';
require_once dirname(__DIR__) . '/classes/DashboardGrid.php';
$pdo = TransactionSecurity::connectAuthenticated('PAINEL_GESTOR');
$aba = preg_replace('/[^a-z]/', '', (string)($_GET['aba'] ?? ''));

// ---------------------------------------------------------------- auxiliares
$uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$T = static fn(string $chave, array $textos): string => IdiomaService::t('dashboard.gestor.' . $chave, $textos);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$sel = static function (string $view, array $filtros = [], int $limite = 1000) use ($pdo): ?array {
    try {
        $linhas = [];
        for ($offset = 0; ; $offset += 1000) {
            $s = $pdo->prepare('CALL sp_crud_select(:t, :f, NULL, :l, :o)');
            $s->execute(['t' => $view, 'f' => json_encode((object)$filtros, JSON_UNESCAPED_UNICODE), 'l' => min(1000, $limite - count($linhas)), 'o' => $offset]);
            $lote = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
            array_push($linhas, ...$lote);
            if (count($lote) < 1000 || count($linhas) >= $limite) break;
        }
        return $linhas;
    } catch (Throwable) { return null; }
};
$pode = static fn(string ...$transacoes): bool => (bool)array_filter($transacoes, static fn($t) => !empty(PermissaoTransacao::obter($t)['pode_visualizar']));
$podeTotal = static fn(string $t): bool => !empty(PermissaoTransacao::obter($t)['pode_visualizar_total']);
$podeRequisitos = $pode('LEGISLACAO', 'MODULOLEGAL_REQUISITOSLEGAIS', 'REQUISITOS_LEGAIS');
// Datas no formato do idioma (dd/mm/aaaa; mm/dd/aaaa em inglês), como nos grids do portal.
$fmt = static fn(?string $d): string => $d === null || strtotime($d) === false ? '–' : date(IdiomaService::idioma() === 'en' ? 'm/d/Y' : 'd/m/Y', strtotime($d));
$pct = static fn(int $p, int $t): string => $t > 0 ? number_format(100 * $p / $t, 1, ',', '.') . '%' : '–';
$abrir = static function (string $pagina, array $filtros = [], string $titulo = '', array $outros = []) use ($e): string {
    $q = $outros + ($filtros ? ['filtros' => json_encode($filtros, JSON_UNESCAPED_UNICODE)] : []);
    return ' data-abrir-transacao="' . $e($pagina . ($q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '')) . '" data-titulo="' . $e($titulo) . '"';
};
$cartao = static fn(string $rotulo, mixed $valor, string $icone, string $tom, string $extra = '', string $abaAlvo = '', string $atributos = ''): string =>
    '<article class="admin-card ' . $tom . ($abaAlvo !== '' ? ' dash-link" data-ir-aba="' . $abaAlvo : '') . ($atributos !== '' ? ' dash-link"' . $atributos . ' title="' . $e($T('abrir_dica', ['pt' => 'Clique para abrir', 'en' => 'Click to open', 'es' => 'Haga clic para abrir'])) : '') . '"><i class="bi ' . $icone . '"></i><strong>' . $valor . '</strong><span>' . $rotulo . '</span>' . ($extra !== '' ? '<small>' . $extra . '</small>' : '') . '</article>';
$numLink = static fn(int $n, string $atributos, string $classe = ''): string => $n > 0 ? '<a class="dash-num' . ($classe !== '' ? ' ' . $classe : '') . '"' . $atributos . '>' . $n . '</a>' : '0';
$vazio = static fn(string $texto): string => '<div class="dash-nota">' . $texto . '</div>';
$colunas = static fn(array $titulos): string => '<thead><tr>' . implode('', array_map(static fn($t) => '<th' . (str_starts_with($t, '#') ? ' class="num">' . substr($t, 1) : '>' . $t) . '</th>', $titulos)) . '</tr></thead>';
$rotSit = ['S' => $T('sit.s', ['pt' => 'Atendido', 'en' => 'Compliant', 'es' => 'Atendido']), 'PA' => $T('sit.pa', ['pt' => 'Parcialmente atendido', 'en' => 'Partially compliant', 'es' => 'Parcialmente atendido']),
    'N' => $T('sit.n', ['pt' => 'Não atendido', 'en' => 'Not compliant', 'es' => 'No atendido']), 'AI' => $T('sit.ai', ['pt' => 'Indefinido', 'en' => 'Undefined', 'es' => 'Indefinido'])];
$valorSit = ['S' => 'Atendido', 'PA' => 'Parcialmente atendido', 'N' => 'Não atendido', 'AI' => 'Indefinido'];
$mapaSit = ['Atendido' => 'S', 'Parcialmente atendido' => 'PA', 'Não atendido' => 'N'];
$corSit = ['S' => '#2f8a5f', 'PA' => '#d39e00', 'N' => '#c95b55', 'AI' => '#9aa8a1'];
$barra = static function (array $c) use ($corSit): string {
    $t = array_sum($c); if ($t <= 0) return '';
    $h = '<div class="dash-barra">';
    foreach (['S', 'PA', 'N', 'AI'] as $k) if (($c[$k] ?? 0) > 0) $h .= '<span style="width:' . number_format(100 * $c[$k] / $t, 2, '.', '') . '%;background:' . $corSit[$k] . '" title="' . (int)$c[$k] . '"></span>';
    return $h . '</div>';
};
$rotPerfil = ['APROVADO' => [$T('pa.aprovado', ['pt' => 'Aprovado', 'en' => 'Approved', 'es' => 'Aprobado']), 'success'], 'PENDENTE_APROVACAO' => [$T('pa.pendente', ['pt' => 'Aguardando minha aprovação', 'en' => 'Awaiting my approval', 'es' => 'Esperando mi aprobación']), 'warning'],
    'DEVOLVIDO' => [$T('pa.devolvido', ['pt' => 'Devolvido', 'en' => 'Returned', 'es' => 'Devuelto']), 'danger'], 'VENCIDO' => [$T('pa.vencido', ['pt' => 'Revisão vencida', 'en' => 'Review overdue', 'es' => 'Revisión vencida']), 'danger'],
    'SEM_ENVIO' => [$T('pa.sem', ['pt' => 'Não preenchido', 'en' => 'Not filled in', 'es' => 'No completado']), 'secondary'], 'NAO_APLICA' => [$T('pa.na', ['pt' => 'Não se aplica', 'en' => 'Not applicable', 'es' => 'No aplica']), 'light']];
$tituloReq = $T('titulo.requisitos', ['pt' => 'Requisitos legais', 'en' => 'Legal requirements', 'es' => 'Requisitos legales']);
$tituloPerfil = $T('titulo.perfil', ['pt' => 'Perfil de atribuições', 'en' => 'Duties profile', 'es' => 'Perfil de atribuciones']);
// Painel da pessoa (dashboard pessoal na visão do gestor)
$abrirPessoa = static fn(int $id, string $nome) => $abrir('dashboard.php', [], $T('titulo.painel_de', ['pt' => 'Painel de', 'en' => 'Dashboard of', 'es' => 'Panel de']) . ' ' . $nome, ['home_usuario' => 1, 'usuario' => $id]);
// Requisitos de uma pessoa (mesmo filtro "Responsável" do grid de Requisitos legais)
$abrirReqPessoa = static fn(int $id, string $nome, array $f = [], string $sub = '') => $abrir('modulolegal_requisitoslegais.php', ['LEI_REVOGADA' => 'NAO', 'RESPONSAVEL_IDS' => ',' . $id . ','] + $f, $tituloReq . ' - ' . $nome . ($sub !== '' ? ' - ' . $sub : ''));

$pessoas = DashboardEquipe::pessoas($sel);
$equipe = DashboardEquipe::membros($sel, $uid, $pessoas);
$diretos = array_filter($equipe, static fn($p) => (int)($p['codgestor'] ?? 0) === $uid);
// Requisitos da equipe: os que têm alguém da equipe como responsável (uma leitura só: a view é pesada)
$requisitosEquipe = static function () use ($pdo, $podeRequisitos, $equipe): ?array {
    if (!$podeRequisitos || !$equipe) return $podeRequisitos ? [] : null;
    // Equipe pequena: lê só os requisitos de cada pessoa (filtro Responsável); equipe grande: lê todos uma vez
    foreach (['vw_modulolegal_requisitos_situacao_grid', 'vw_modulolegal_requisitos_filtro_grid'] as $view) {
        $lidos = [];
        if (count($equipe) <= 8) {
            foreach (array_keys($equipe) as $id) {
                $rows = DashboardEquipe::lerTudo($pdo, $view, ['LEI_REVOGADA' => 'NAO', 'RESPONSAVEL_IDS' => ',' . $id . ',']);
                if ($rows === null) { $lidos = null; break; }
                foreach ($rows as $r) $lidos[(int)$r['CODLEGISLACAO'] . ':' . (int)$r['CODUNIDADE']] = $r;
            }
        } else $lidos = DashboardEquipe::lerTudo($pdo, $view, ['LEI_REVOGADA' => 'NAO']);
        if ($lidos === null) continue;
        $out = [];
        foreach ($lidos as $r) { $resp = array_values(array_filter(DashboardEquipe::responsaveis($r), static fn($id) => isset($equipe[$id]))); if ($resp) { $r['_RESP'] = $resp; $out[] = $r; } }
        return $out;
    }
    return null;
};
$semEquipe = '<div class="alert alert-light border small"><i class="bi bi-info-circle me-1"></i>' . $e($T('sem_equipe', ['pt' => 'Nenhuma pessoa tem você como gestor no cadastro de usuários. A equipe vem do campo "Gestor" de cada usuário (Segurança › Usuários).', 'en' => 'Nobody has you as manager in the user records. The team comes from each user\'s "Manager" field (Security › Users).', 'es' => 'Nadie lo tiene como gestor en el registro de usuarios. El equipo viene del campo "Gestor" de cada usuario (Seguridad › Usuarios).'])) . '</div>';

// ---------------------------------------------------------------- abas (fragmentos)
if ($aba !== '') {
    header('Content-Type: text/html; charset=utf-8');
    if (!$equipe) exit($semEquipe);

    // ======== Minha equipe
    if ($aba === 'geral') {
        $lic = DashboardEquipe::licencas($sel, $equipe) ?? [];
        $med = DashboardEquipe::medicoes($sel, $equipe) ?? [];
        $req = $requisitosEquipe();
        $porPessoa = [];
        foreach ($equipe as $id => $p) $porPessoa[$id] = ['REQ' => 0, 'N' => 0, 'DIV' => 0, 'LIC' => count($lic[$id] ?? []), 'LIC_ATR' => 0, 'LIC_30' => 0, 'MED' => count($med[$id] ?? []), 'MED_ATR' => 0, 'PERFIL' => DashboardEquipe::perfil($p)];
        foreach ($lic as $id => $ls) foreach ($ls as $l) { $f = (string)$l['FAIXA']; if (in_array($f, ['VENCIDA', 'ENTREGA_ATRASADA'], true)) $porPessoa[$id]['LIC_ATR']++; elseif (in_array($f, ['VENCE_30', 'ENTREGA_30', 'PROT_SEM_PRAZO'], true)) $porPessoa[$id]['LIC_30']++; }
        foreach ($med as $id => $ms) foreach ($ms as $m) if ($m['FAIXA'] === 'ATRASADA') $porPessoa[$id]['MED_ATR']++;
        foreach ($req ?? [] as $r) foreach ($r['_RESP'] as $id) {
            $porPessoa[$id]['REQ']++;
            if ((string)($r['SITUACAO_CONSOLIDADA'] ?? '') === 'Não atendido') $porPessoa[$id]['N']++;
            if ((string)($r['DIVERGENCIA'] ?? '') === 'Sim') $porPessoa[$id]['DIV']++;
        }
        $soma = static fn(string $k) => array_sum(array_column($porPessoa, $k));
        $comAtraso = count(array_filter($porPessoa, static fn($x) => $x['LIC_ATR'] + $x['MED_ATR'] + $x['N'] > 0));
        $perfis = array_count_values(array_column($porPessoa, 'PERFIL'));
        $h = '<div class="admin-cards">'
            . $cartao($e($T('card.pessoas', ['pt' => 'Pessoas na minha equipe', 'en' => 'People in my team', 'es' => 'Personas en mi equipo'])), count($equipe), 'bi-people', 'blue', count($diretos) . ' ' . $e($T('card.diretos', ['pt' => 'diretas', 'en' => 'direct', 'es' => 'directas'])), '', ' data-dash-filtro="dg-equipe" data-dash-valor="*"')
            . $cartao($e($T('card.atencao', ['pt' => 'Pessoas com algo atrasado', 'en' => 'People with something overdue', 'es' => 'Personas con algo atrasado'])), $comAtraso, 'bi-flag', $comAtraso ? 'red' : 'green', '', '', ' data-dash-filtro="dg-equipe" data-dash-valor="ATRASO" data-dash-rotulo="' . $e($T('card.atencao', ['pt' => 'Pessoas com algo atrasado', 'en' => 'People with something overdue', 'es' => 'Personas con algo atrasado'])) . '"')
            . $cartao($e($T('card.lic', ['pt' => 'Licenças vencidas / entregas atrasadas', 'en' => 'Expired licenses / late submissions', 'es' => 'Licencias vencidas / entregas atrasadas'])), $soma('LIC_ATR'), 'bi-calendar-x', $soma('LIC_ATR') ? 'red' : 'green', $soma('LIC_30') . ' ' . $e($T('card.lic30', ['pt' => 'vencem em 30 dias', 'en' => 'due in 30 days', 'es' => 'vencen en 30 días'])), 'prazos')
            . $cartao($e($T('card.med', ['pt' => 'Medições atrasadas', 'en' => 'Late measurements', 'es' => 'Mediciones atrasadas'])), $soma('MED_ATR'), 'bi-graph-down', $soma('MED_ATR') ? 'red' : 'green', '', 'prazos');
        if ($req !== null) $h .= $cartao($e($T('card.req_n', ['pt' => 'Requisitos não atendidos', 'en' => 'Non-compliant requirements', 'es' => 'Requisitos no atendidos'])), count(array_filter($req, static fn($r) => (string)($r['SITUACAO_CONSOLIDADA'] ?? '') === 'Não atendido')), 'bi-x-circle', 'red', count($req) . ' ' . $e($T('card.req_total', ['pt' => 'requisitos da equipe', 'en' => 'team requirements', 'es' => 'requisitos del equipo'])), 'legal')
            . $cartao($e($T('card.div', ['pt' => 'Divergências a decidir', 'en' => 'Divergences to decide', 'es' => 'Divergencias a decidir'])), count(array_filter($req, static fn($r) => (string)($r['DIVERGENCIA'] ?? '') === 'Sim')), 'bi-exclamation-triangle', 'orange', '', 'legal');
        $h .= $cartao($e($T('card.pa_pend', ['pt' => 'Perfis aguardando minha aprovação', 'en' => 'Profiles awaiting my approval', 'es' => 'Perfiles esperando mi aprobación'])), (int)($perfis['PENDENTE_APROVACAO'] ?? 0), 'bi-person-up', ($perfis['PENDENTE_APROVACAO'] ?? 0) ? 'orange' : 'green', '', '', ($perfis['PENDENTE_APROVACAO'] ?? 0) ? $abrir('perfil_atribuicoes_gestao.php', [], $tituloPerfil) : '')
            . $cartao($e($T('card.pa_sem', ['pt' => 'Perfis não preenchidos ou devolvidos', 'en' => 'Profiles not filled in or returned', 'es' => 'Perfiles no completados o devueltos'])), (int)($perfis['SEM_ENVIO'] ?? 0) + (int)($perfis['DEVOLVIDO'] ?? 0) + (int)($perfis['VENCIDO'] ?? 0), 'bi-person-dash', 'gray');
        $h .= '</div>';
        uasort($porPessoa, static fn($a, $b) => ($b['LIC_ATR'] * 3 + $b['MED_ATR'] * 2 + $b['N'] * 2 + $b['DIV'] + $b['LIC_30']) <=> ($a['LIC_ATR'] * 3 + $a['MED_ATR'] * 2 + $a['N'] * 2 + $a['DIV'] + $a['LIC_30']));
        // GER-18 (12/11/2026): foto do usuário (Profile) ao lado do nome; sem foto, a inicial
        $bancoFotos = TenantContext::database($_SESSION);
        $fotoPessoa = static function (int $id, string $nome) use ($bancoFotos, $e): string {
            $v = UsuarioImagem::fotoVersao($bancoFotos, $id);
            $st = 'display:inline-grid;place-items:center;width:26px;height:26px;border-radius:50%;margin-right:6px;vertical-align:middle;font-size:11px;font-weight:700;';
            if ($v) return '<span aria-hidden="true" style="' . $st . 'background:#fff center/cover no-repeat url(usuario_foto.php?u=' . $id . '&amp;v=' . $v . ')"></span>';
            return '<span aria-hidden="true" style="' . $st . 'background:var(--gp-primary-soft,#dff2e9);color:var(--gp-primary,#3ba179)">' . $e(mb_strtoupper(mb_substr($nome, 0, 1))) . '</span>';
        };
        $rowsEq = []; $ordem = 0;
        $num = static fn(int $v, string $link = '', string $cls = ''): array => ['text' => (string)$v, 'sort' => (string)$v,
            'html' => $link !== '' ? $numLink($v, $link, $cls) : ($v > 0 && $cls !== '' ? '<b class="' . $cls . '">' . $v . '</b>' : (string)$v)];
        foreach ($porPessoa as $id => $x) {
            $p = $equipe[$id]; $nome = DashboardEquipe::nome($p);
            [$rot, $cor] = $rotPerfil[$x['PERFIL']] ?? [$x['PERFIL'], 'secondary'];
            $perfilId = (int)(($p['ultimo_codperfilatrib'] ?? 0) ?: ($p['vigente_codperfilatrib'] ?? 0));
            $badge = '<span class="badge text-bg-' . $cor . '">' . $e($rot) . '</span>';
            if ($perfilId > 0) $badge = '<a class="dash-num"' . $abrir('perfil_atribuicoes_gestao.php', [], $tituloPerfil . ' - ' . $nome, ['id' => $perfilId]) . '>' . $badge . '</a>';
            $grupos = trim(($x['LIC_ATR'] + $x['MED_ATR'] + $x['N'] > 0 ? 'ATRASO ' : '') . 'P_' . $x['PERFIL']);
            $rowsEq[] = ['cells' => [
                'ORDEM' => ['text' => (string)(++$ordem), 'sort' => (string)$ordem],
                'PESSOA' => ['text' => $nome, 'html' => $fotoPessoa($id, $nome) . '<b>' . $e($nome) . '</b>'],
                'GESTOR' => (string)($p['gestor_nome'] ?? ''),
                'PERFIL' => ['text' => $rot, 'html' => $badge],
                'REQ' => $num($x['REQ'], $abrirReqPessoa($id, $nome)),
                'N' => $num($x['N'], $abrirReqPessoa($id, $nome, ['SITUACAO_CONSOLIDADA' => ['value' => 'Não atendido', 'exact' => true]], $rotSit['N']), 'status-danger'),
                'DIV' => $num($x['DIV'], $abrirReqPessoa($id, $nome, ['DIVERGENCIA' => ['value' => 'Sim', 'exact' => true]], $T('col.div', ['pt' => 'Divergências', 'en' => 'Divergences', 'es' => 'Divergencias'])), 'status-warning'),
                'LIC' => $num($x['LIC']), 'LIC_ATR' => $num($x['LIC_ATR'], '', 'status-danger'), 'LIC_30' => $num($x['LIC_30'], '', 'status-warning'),
                'MED' => $num($x['MED']), 'MED_ATR' => $num($x['MED_ATR'], '', 'status-danger'),
            ], 'open' => DashboardGrid::abrirDe($abrirPessoa($id, $nome)), 'grupos' => $grupos];
        }
        $n80 = ['filter' => 'none', 'width' => '80px'];
        $h .= DashboardGrid::subabas('gestor-equipe', [['id' => 'pessoas', 'icone' => 'bi-person-lines-fill', 'rotulo' => $T('equipe.titulo_curto', ['pt' => 'Por pessoa', 'en' => 'By person', 'es' => 'Por persona']),
            'html' => DashboardGrid::grid('dg-equipe', [
                'ORDEM' => ['label' => '#', 'filter' => 'none', 'width' => '45px'],
                'PESSOA' => ['label' => $T('col.pessoa', ['pt' => 'Pessoa', 'en' => 'Person', 'es' => 'Persona'])],
                'GESTOR' => ['label' => $T('col.gestor', ['pt' => 'Gestor direto', 'en' => 'Direct manager', 'es' => 'Gestor directo']), 'filter' => 'select', 'width' => '160px'],
                'PERFIL' => ['label' => $tituloPerfil, 'filter' => 'select', 'width' => '180px'],
                'REQ' => ['label' => $T('col.req', ['pt' => 'Requisitos', 'en' => 'Requirements', 'es' => 'Requisitos'])] + $n80,
                'N' => ['label' => $rotSit['N']] + $n80,
                'DIV' => ['label' => $T('col.div', ['pt' => 'Divergências', 'en' => 'Divergences', 'es' => 'Divergencias'])] + $n80,
                'LIC' => ['label' => $T('col.lic', ['pt' => 'Licenças', 'en' => 'Licenses', 'es' => 'Licencias'])] + $n80,
                'LIC_ATR' => ['label' => $T('col.lic_atr', ['pt' => 'Vencidas / atrasadas', 'en' => 'Expired / late', 'es' => 'Vencidas / atrasadas'])] + $n80,
                'LIC_30' => ['label' => $T('col.lic30', ['pt' => 'Vencem em 30 dias', 'en' => 'Due in 30 days', 'es' => 'Vencen en 30 días'])] + $n80,
                'MED' => ['label' => $T('col.med', ['pt' => 'Medições', 'en' => 'Measurements', 'es' => 'Mediciones'])] + $n80,
                'MED_ATR' => ['label' => $T('col.med_atr', ['pt' => 'Medições atrasadas', 'en' => 'Late measurements', 'es' => 'Mediciones atrasadas'])] + $n80,
            ], $rowsEq, ['transacao' => 'dashboard_gestor', 'ordem' => ['ORDEM', 'asc']])]]);
        exit($h);
    }

    // ======== Atendimento legal da equipe
    if ($aba === 'legal') {
        $req = $requisitosEquipe();
        if ($req === null) exit($vazio($e($T('sem_permissao', ['pt' => 'Seu perfil não tem acesso a esta informação.', 'en' => 'Your profile has no access to this information.', 'es' => 'Su perfil no tiene acceso a esta información.']))));
        $tot = ['S' => 0, 'PA' => 0, 'N' => 0, 'AI' => 0]; $porPessoa = [];
        foreach ($req as $r) {
            $k = $mapaSit[(string)($r['SITUACAO_CONSOLIDADA'] ?? '')] ?? 'AI'; $tot[$k]++;
            foreach ($r['_RESP'] as $id) { $porPessoa[$id] ??= ['S' => 0, 'PA' => 0, 'N' => 0, 'AI' => 0]; $porPessoa[$id][$k]++; }
        }
        $total = array_sum($tot);
        $h = '<div class="admin-cards dash-cards-6">' . $cartao($e($T('legal.total', ['pt' => 'Requisitos da equipe', 'en' => 'Team requirements', 'es' => 'Requisitos del equipo'])), $total, 'bi-journal-text', 'blue');
        foreach (['S' => ['bi-check-circle', 'green'], 'PA' => ['bi-dash-circle', 'orange'], 'N' => ['bi-x-circle', 'red'], 'AI' => ['bi-question-circle', 'gray']] as $k => [$ic, $tom]) $h .= $cartao($e($rotSit[$k]), $tot[$k], $ic, $tom, $pct($tot[$k], $total));
        $h .= $cartao($e($T('legal.pct', ['pt' => '% atendido (com situação definida)', 'en' => '% compliant (defined status)', 'es' => '% atendido (situación definida)'])), $pct($tot['S'], $total - $tot['AI']), 'bi-graph-up', 'green') . '</div><div class="dash-barra-grande">' . $barra($tot) . '</div>'
            . '<p class="dash-nota">' . $e($T('legal.nota', ['pt' => 'Requisitos em que alguém da sua equipe é o responsável. Um requisito com dois responsáveis da equipe conta uma vez no total e uma vez para cada pessoa.', 'en' => 'Requirements for which someone in your team is responsible. A requirement with two team owners counts once in the total and once for each person.', 'es' => 'Requisitos de los que alguien de su equipo es responsable. Un requisito con dos responsables del equipo cuenta una vez en el total y una vez para cada persona.'])) . '</p>';
        uksort($porPessoa, static fn($a, $b) => strnatcasecmp(DashboardEquipe::nome($equipe[$a]), DashboardEquipe::nome($equipe[$b])));
        $rowsP = [];
        foreach ($porPessoa as $id => $c) {
            $nome = DashboardEquipe::nome($equipe[$id]); $tt = array_sum($c);
            $cells = ['PESSOA' => $nome, 'TOTAL' => ['text' => (string)$tt, 'sort' => (string)$tt, 'html' => $numLink($tt, $abrirReqPessoa($id, $nome))]];
            foreach (['S' => '', 'PA' => '', 'N' => 'status-danger'] as $k => $cls) $cells[$k] = ['text' => (string)$c[$k], 'sort' => (string)$c[$k], 'html' => $numLink($c[$k], $abrirReqPessoa($id, $nome, ['SITUACAO_CONSOLIDADA' => ['value' => $valorSit[$k], 'exact' => true]], $rotSit[$k]), $cls)];
            $den = $tt - $c['AI'];
            $cells['PCT'] = ['text' => $pct($c['S'], $den), 'sort' => (string)($den > 0 ? round(100 * $c['S'] / $den, 2) : -1)];
            $cells['BARRA'] = ['text' => '', 'html' => $barra($c)];
            $rowsP[] = ['cells' => $cells, 'open' => DashboardGrid::abrirDe($abrirReqPessoa($id, $nome))];
        }
        $n80 = ['filter' => 'none', 'width' => '80px'];
        $gridPessoas = DashboardGrid::grid('dg-legal-pessoas', [
            'PESSOA' => ['label' => $T('col.pessoa', ['pt' => 'Pessoa', 'en' => 'Person', 'es' => 'Persona'])],
            'TOTAL' => ['label' => $T('col.total', ['pt' => 'Total', 'en' => 'Total', 'es' => 'Total'])] + $n80,
            'S' => ['label' => $rotSit['S']] + $n80,
            'PA' => ['label' => $T('col.parcial', ['pt' => 'Parcial', 'en' => 'Partial', 'es' => 'Parcial'])] + $n80,
            'N' => ['label' => $rotSit['N']] + $n80,
            'PCT' => ['label' => $T('legal.pct_curto', ['pt' => '% atendido*', 'en' => '% compliant*', 'es' => '% atendido*'])] + $n80,
            'BARRA' => ['label' => '', 'filter' => 'none', 'width' => '24%'],
        ], $rowsP, ['transacao' => 'dashboard_gestor', 'vazio' => $T('legal.nenhum', ['pt' => 'Ninguém da equipe é responsável por requisitos legais.', 'en' => 'No one in the team is responsible for legal requirements.', 'es' => 'Nadie del equipo es responsable de requisitos legales.'])]);
        $lista = static function (string $id, string $titulo, string $icone, array $linhas, string $vazioTxt) use ($T, $equipe): array {
            $rows = [];
            foreach (array_slice($linhas, 0, 300) as $r) {
                $resp = implode(', ', array_map(static fn($i) => DashboardEquipe::nome($equipe[$i]), $r['_RESP']));
                $rows[] = ['cells' => ['RESP' => $resp, 'UNIDADE' => (string)($r['UNIDADE'] ?? ''), 'CODIGO' => ['text' => (string)($r['CODIGO'] ?? ''), 'html' => '<b>' . htmlspecialchars((string)($r['CODIGO'] ?? ''), ENT_QUOTES, 'UTF-8') . '</b>'],
                    'AVALIACAO' => (string)($r['ATENDIDO'] ?? ''), 'CALCULADA' => (string)($r['SITUACAO_CALCULADA'] ?? ''), 'MOTIVO' => ['text' => (string)($r['MOTIVO_CALCULO'] ?? ''), 'title' => (string)($r['MOTIVO_CALCULO'] ?? '')]],
                    'open' => DashboardGrid::abrirSituacao((int)$r['CODLEGISLACAO'], (int)$r['CODUNIDADE'], (string)($r['CODIGO'] ?? ''))];
            }
            return ['id' => $id, 'icone' => $icone, 'rotulo' => $titulo, 'contagem' => count($linhas), 'html' => DashboardGrid::grid('dg-' . $id, [
                'RESP' => ['label' => $T('col.responsavel', ['pt' => 'Responsável', 'en' => 'Owner', 'es' => 'Responsable']), 'filter' => 'select', 'width' => '170px'],
                'UNIDADE' => ['label' => $T('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), 'filter' => 'select', 'width' => '150px'],
                'CODIGO' => ['label' => $T('col.requisito', ['pt' => 'Requisito', 'en' => 'Requirement', 'es' => 'Requisito']), 'width' => '120px'],
                'AVALIACAO' => ['label' => $T('col.avaliacao', ['pt' => 'Avaliação', 'en' => 'Assessment', 'es' => 'Evaluación']), 'filter' => 'select', 'width' => '150px'],
                'CALCULADA' => ['label' => $T('col.calculada', ['pt' => 'Calculada', 'en' => 'Calculated', 'es' => 'Calculada']), 'filter' => 'select', 'width' => '150px'],
                'MOTIVO' => ['label' => $T('col.motivo', ['pt' => 'Motivo', 'en' => 'Reason', 'es' => 'Motivo'])],
            ], $rows, ['transacao' => 'dashboard_gestor', 'vazio' => $vazioTxt])];
        };
        $h .= DashboardGrid::subabas('gestor-legal', [
            ['id' => 'pessoas', 'icone' => 'bi-person-lines-fill', 'rotulo' => $T('legal.por_pessoa', ['pt' => 'Por responsável', 'en' => 'By owner', 'es' => 'Por responsable']), 'html' => $gridPessoas, 'contagem' => count($porPessoa)],
            $lista('legal-nao', $T('legal.lista_nao', ['pt' => 'Requisitos não atendidos da equipe', 'en' => 'Team non-compliant requirements', 'es' => 'Requisitos no atendidos del equipo']), 'bi-x-octagon', array_values(array_filter($req, static fn($r) => (string)($r['SITUACAO_CONSOLIDADA'] ?? '') === 'Não atendido')), $T('legal.lista_nao_vazia', ['pt' => 'Nenhum requisito não atendido.', 'en' => 'No non-compliant requirement.', 'es' => 'Ningún requisito no atendido.'])),
            $lista('legal-div', $T('legal.lista_div', ['pt' => 'Divergências que a equipe precisa decidir', 'en' => 'Divergences the team needs to decide', 'es' => 'Divergencias que el equipo debe decidir']), 'bi-exclamation-triangle', array_values(array_filter($req, static fn($r) => (string)($r['DIVERGENCIA'] ?? '') === 'Sim')), $T('legal.lista_div_vazia', ['pt' => 'Nenhuma divergência pendente.', 'en' => 'No pending divergence.', 'es' => 'Ninguna divergencia pendiente.'])),
        ]);
        exit($h);
    }

    // ======== Prazos da equipe (sinais de atenção): atrasados e próximos 30 dias
    if ($aba === 'prazos') {
        $lic = DashboardEquipe::licencas($sel, $equipe) ?? [];
        $med = DashboardEquipe::medicoes($sel, $equipe) ?? [];
        $licTotal = $podeTotal('LICENCAS'); $medTotal = $podeTotal('MM_MONITORAMENTO') || $podeTotal('MEDICAO_MONITORAMENTO');
        $rotLic = ['VENCIDA' => [$T('fx.vencida3', ['pt' => 'Vencida (sem protocolo ou protocolo fora do prazo)', 'en' => 'Expired (no renewal filed or filed late)', 'es' => 'Vencida (sin protocolo o fuera de plazo)']), 'danger'], 'ENTREGA_ATRASADA' => [$T('fx.ent_atr', ['pt' => 'Entrega atrasada', 'en' => 'Late submission', 'es' => 'Entrega atrasada']), 'danger'],
            'PROT_SEM_PRAZO' => [$T('fx.prot_sem_prazo', ['pt' => 'Em protocolo (prazo não informado)', 'en' => 'Renewal filed (deadline not informed)', 'es' => 'En protocolo (plazo no informado)']), 'warning'],
            'VENCE_30' => [$T('fx.30', ['pt' => 'Vence em até 30 dias', 'en' => 'Expires within 30 days', 'es' => 'Vence en hasta 30 días']), 'warning'], 'ENTREGA_30' => [$T('fx.ent_30', ['pt' => 'Entrega em até 30 dias', 'en' => 'Submission within 30 days', 'es' => 'Entrega en hasta 30 días']), 'warning']];
        $rotMed = ['ATRASADA' => [$T('mfx.atrasada', ['pt' => 'Medição atrasada', 'en' => 'Late measurement', 'es' => 'Medición atrasada']), 'danger'], 'PROXIMA' => [$T('mfx.proxima', ['pt' => 'Medição em até 30 dias', 'en' => 'Measurement within 30 days', 'es' => 'Medición en hasta 30 días']), 'warning']];
        $itens = []; $visto = [];
        foreach ($lic as $id => $ls) foreach ($ls as $l) {
            $f = (string)$l['FAIXA']; if (!isset($rotLic[$f])) continue;
            $nome = DashboardEquipe::nome($equipe[$id]);
            $itens[] = ['quando' => DashboardEquipe::data($l['DATA_VALIDADE']), 'dias' => $l['DIAS'], 'badge' => $rotLic[$f], 'item' => (string)$l['LICENCA'], 'unidade' => (string)$l['UNIDADE'], 'pessoa' => $nome,
                'atr' => $licTotal ? $abrir('manutencaolegal_licencas.php', ['CODLICENCA' => (int)$l['CODLICENCA']], $T('titulo.licencas', ['pt' => 'Licenças', 'en' => 'Licenses', 'es' => 'Licencias']) . ' - ' . mb_strimwidth((string)$l['LICENCA'], 0, 70, '…')) : $abrirPessoa($id, $nome)];
        }
        foreach ($med as $id => $ms) foreach ($ms as $m) {
            if (!isset($rotMed[$m['FAIXA']])) continue;
            $nome = DashboardEquipe::nome($equipe[$id]);
            $itens[] = ['quando' => $m['REACAO'], 'dias' => $m['DIAS'], 'badge' => $rotMed[$m['FAIXA']], 'item' => $m['NOME'], 'unidade' => $m['UNIDADE'], 'pessoa' => $nome,
                'atr' => $medTotal ? $abrir('medicaoemonitoramento_mm_monitoramento.php', ['CODMONITORAMENTO' => $m['COD']], $T('titulo.medicoes', ['pt' => 'Medição e monitoramento', 'en' => 'Measurement and monitoring', 'es' => 'Medición y monitoreo']) . ' - ' . mb_strimwidth($m['NOME'], 0, 70, '…')) : $abrirPessoa($id, $nome)];
        }
        usort($itens, static fn($a, $b) => strcmp($a['quando'] ?? '9999', $b['quando'] ?? '9999'));
        $atr = count(array_filter($itens, static fn($i) => $i['badge'][1] === 'danger'));
        $h = '<div class="admin-cards dash-cards-4">' . $cartao($e($T('prazos.atrasados', ['pt' => 'Atrasados', 'en' => 'Overdue', 'es' => 'Atrasados'])), $atr, 'bi-flag', $atr ? 'red' : 'green', '', '', ' data-dash-filtro="dg-prazos" data-dash-valor="ATRASADO" data-dash-rotulo="' . $e($T('prazos.atrasados', ['pt' => 'Atrasados', 'en' => 'Overdue', 'es' => 'Atrasados'])) . '"')
            . $cartao($e($T('prazos.proximos', ['pt' => 'Vencem nos próximos 30 dias', 'en' => 'Due in the next 30 days', 'es' => 'Vencen en los próximos 30 días'])), count($itens) - $atr, 'bi-alarm', 'orange', '', '', ' data-dash-filtro="dg-prazos" data-dash-valor="PROXIMO" data-dash-rotulo="' . $e($T('prazos.proximos', ['pt' => 'Vencem nos próximos 30 dias', 'en' => 'Due in the next 30 days', 'es' => 'Vencen en los próximos 30 días'])) . '"') . '</div>';
        $rowsPz = [];
        foreach (array_slice($itens, 0, 500) as $i) {
            $grav = $i['badge'][1] === 'danger' ? 'ATRASADO' : 'PROXIMO';
            $rowsPz[] = ['cells' => ['SITUACAO' => ['text' => (string)$i['badge'][0], 'html' => '<span class="badge text-bg-' . $i['badge'][1] . '">' . $e($i['badge'][0]) . '</span>'],
                'PRAZO' => ['text' => $fmt($i['quando']), 'sort' => (string)($i['quando'] ?? '9999-12-31')],
                'DIAS' => DashboardGrid::numero($i['dias'] !== null && $i['dias'] !== '' ? (int)$i['dias'] : '', $i['dias'] !== null && $i['dias'] !== '' && (int)$i['dias'] < 0 ? 'status-danger' : ''),
                'PESSOA' => (string)$i['pessoa'], 'ITEM' => ['text' => (string)$i['item'], 'title' => (string)$i['item']], 'UNIDADE' => (string)$i['unidade']],
                'open' => DashboardGrid::abrirDe($i['atr']), 'grupos' => $grav];
        }
        $h .= DashboardGrid::subabas('gestor-prazos', [['id' => 'prazos', 'icone' => 'bi-calendar-check', 'rotulo' => $T('prazos.titulo', ['pt' => 'Prazos da equipe: atrasados e próximos 30 dias', 'en' => 'Team deadlines: overdue and next 30 days', 'es' => 'Plazos del equipo: atrasados y próximos 30 días']),
            'html' => DashboardGrid::grid('dg-prazos', [
                'SITUACAO' => ['label' => $T('col.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']), 'filter' => 'select', 'width' => '230px'],
                'PRAZO' => ['label' => $T('col.prazo', ['pt' => 'Prazo', 'en' => 'Due', 'es' => 'Plazo']), 'filter' => 'none', 'width' => '100px'],
                'DIAS' => ['label' => $T('col.dias', ['pt' => 'Dias', 'en' => 'Days', 'es' => 'Días']), 'filter' => 'none', 'width' => '70px'],
                'PESSOA' => ['label' => $T('col.pessoa', ['pt' => 'Pessoa', 'en' => 'Person', 'es' => 'Persona']), 'filter' => 'select', 'width' => '170px'],
                'ITEM' => ['label' => $T('col.item', ['pt' => 'Item', 'en' => 'Item', 'es' => 'Ítem'])],
                'UNIDADE' => ['label' => $T('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), 'filter' => 'select', 'width' => '150px'],
            ], $rowsPz, ['transacao' => 'dashboard_gestor', 'ordem' => ['PRAZO', 'asc'],
                'vazio' => $T('prazos.nada', ['pt' => 'Nada atrasado nem vencendo nos próximos 30 dias na sua equipe.', 'en' => 'Nothing overdue or due in the next 30 days in your team.', 'es' => 'Nada atrasado ni por vencer en los próximos 30 días en su equipo.'])])]]);
        exit($h);
    }
    http_response_code(404); exit('');
}

// ---------------------------------------------------------------- página
$abas = ['geral' => ['bi-people', $T('aba.geral', ['pt' => 'Minha equipe', 'en' => 'My team', 'es' => 'Mi equipo'])]];
if ($podeRequisitos) $abas['legal'] = ['bi-journal-check', $T('aba.legal', ['pt' => 'Atendimento legal da equipe', 'en' => 'Team legal compliance', 'es' => 'Cumplimiento legal del equipo'])];
$abas['prazos'] = ['bi-flag', $T('aba.prazos', ['pt' => 'Prazos da equipe', 'en' => 'Team deadlines', 'es' => 'Plazos del equipo'])];
$nomeGestor = trim((string)($_SESSION['usuario']['nome'] ?? $_SESSION['usuario']['userid'] ?? ''));
?>
<!doctype html><html lang="<?= IdiomaService::idioma() === 'pt' ? 'pt-BR' : IdiomaService::idioma() ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $e($T('titulo', ['pt' => 'Painel do gestor', 'en' => 'Manager dashboard', 'es' => 'Panel del gestor'])) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2"><link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2"><link rel="stylesheet" href="../assets/css/app.css?v=5"><link rel="stylesheet" href="../assets/css/main.css?v=18">
<?php DashboardGrid::head(); ?><link rel="stylesheet" href="../assets/css/dashboard-abas.css?v=4">
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?></head>
<body><main class="admin-dashboard" data-dash-fonte="dashboard_gestor.php" data-dash-chave="greenportal.dashboard.gestor.aba" data-dash-textos="<?= $e(json_encode([
    'relatorio' => $T('relatorio', ['pt' => 'Relatório de Atendimento Legal', 'en' => 'Legal Compliance Report', 'es' => 'Informe de Cumplimiento Legal']),
    'erro' => $T('erro', ['pt' => 'Não foi possível concluir a ação.', 'en' => 'The action could not be completed.', 'es' => 'No fue posible completar la acción.']),
    'erro_aba' => $T('erro_aba', ['pt' => 'Não foi possível carregar os indicadores desta aba.', 'en' => 'The indicators of this tab could not be loaded.', 'es' => 'No fue posible cargar los indicadores de esta pestaña.']),
    'situacao' => $T('situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']),
], JSON_UNESCAPED_UNICODE)) ?>">
<header class="admin-head"><div><h2><i class="bi bi-diagram-2 me-2"></i><?= $e($T('titulo', ['pt' => 'Painel do gestor', 'en' => 'Manager dashboard', 'es' => 'Panel del gestor'])) ?></h2><small><?= $e($T('subtitulo', ['pt' => 'Sua equipe: o que vai bem, o que exige atenção e quem precisa de ação', 'en' => 'Your team: what is going well, what needs attention and who needs to act', 'es' => 'Su equipo: lo que va bien, lo que requiere atención y quién debe actuar'])) ?> · <?= $e($nomeGestor) ?> · <?= count($equipe) ?> <?= $e($T('pessoas', ['pt' => 'pessoas', 'en' => 'people', 'es' => 'personas'])) ?></small></div></header>
<?php if (!$equipe): ?><?= $semEquipe ?><?php else: ?>
<ul class="nav dash-abas" role="tablist">
<?php foreach ($abas as $id => [$ic, $rot]): ?><li class="nav-item" role="presentation"><button class="nav-link<?= $id === 'geral' ? ' active' : '' ?>" type="button" role="tab" data-aba="<?= $id ?>"><i class="bi <?= $ic ?> me-1"></i><?= $e($rot) ?></button></li><?php endforeach; ?>
</ul>
<?php foreach (array_keys($abas) as $id): ?><section data-painel="<?= $id ?>"<?= $id === 'geral' ? '' : ' hidden' ?>><div class="dash-carregando"><span class="spinner-border spinner-border-sm me-2"></span><?= $e($T('carregando', ['pt' => 'Carregando indicadores...', 'en' => 'Loading indicators...', 'es' => 'Cargando indicadores...'])) ?></div></section><?php endforeach; ?>
<?php endif; ?>
</main>
<?php DashboardGrid::scripts(); ?>
<script src="../assets/js/dashboard-abas.js?v=6"></script>
</body></html>
