<?php
declare(strict_types=1);
/*
 * Dashboard pessoal (usuário logado), em abas — mesmo padrão do dashboard da empresa (dashboard_admin.php):
 *   Visão geral · Meu atendimento legal · Minhas licenças e entregas · Minhas tarefas · Minhas não conformidades · Meus fornecedores
 *   (as duas últimas e os cartões delas na Visão geral: migração 366, classes/DashboardNcFornecedores.php)
 * Migração 368: listas no grid padrão do portal (classes/DashboardGrid.php) e os quadros de cada aba em sub-abas.
 * Só itens do usuário logado: requisitos em que ele é responsável, licenças e medições atribuídas a ele (direto, por
 * responsabilidade ou por grupo), planos de ação, alertas, perfil de atribuições e análises de IA.
 * Todas as abas são carregadas quando abertas (dashboard.php?aba=...). Dados só por views/tabelas lidas com sp_crud_select.
 * Cada número ou linha abre a transação em popup já filtrada no caso (assets/js/dashboard-abas.js).
 */
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }

$config = [];
foreach (file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line); if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2); $config[trim($key)] = trim(trim($value), "\"'");
}
$database = (string)($_SESSION['tenant']['banco_dados'] ?? '');
if ($database === '' || !preg_match('/^[A-Za-z0-9_]+$/', $database)) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
$pdo = new PDO('mysql:host=' . ($config['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($config['GPT_DB_PORT'] ?? '3306') . ';dbname=' . $database . ';charset=utf8mb4',
    $config['GPT_DB_USERNAME'] ?? '', $config['GPT_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/DashboardEquipe.php';
require_once dirname(__DIR__) . '/classes/DashboardNcFornecedores.php';
require_once dirname(__DIR__) . '/classes/DashboardGrid.php';
require_once dirname(__DIR__) . '/classes/HomologacaoFornecedorAcesso.php';
require_once dirname(__DIR__) . '/classes/MinhasPendencias.php';
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
$homeUsuario = !empty($_GET['home_usuario']);
// ADMINISTRADOR e ADM DA EMPRESA usam o dashboard da empresa em abas (dashboard_admin.php)
if (in_array(strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')), ['ADMINISTRADOR', 'ADM_DA_EMPRESA'], true) && !$homeUsuario && empty($_GET['aba']) && empty($_GET['usuario'])) {
    header('Location: dashboard_admin.php'); exit;
}
$pdo = $homeUsuario ? TransactionSecurity::connectAuthenticated('HOME_DASHBOARD') : TransactionSecurity::connect('DASHBOARD');
$aba = preg_replace('/[^a-z]/', '', (string)($_GET['aba'] ?? ''));

// ---------------------------------------------------------------- auxiliares
$uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$nomeUsuario = trim((string)($_SESSION['usuario']['nome'] ?? $_SESSION['usuario']['userid'] ?? ''));
$T = static fn(string $chave, array $textos): string => IdiomaService::t('dashboard.pessoal.' . $chave, $textos);
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
$podeRequisitos = $pode('LEGISLACAO', 'MODULOLEGAL_REQUISITOSLEGAIS', 'REQUISITOS_LEGAIS');
$podeLicencas = $pode('LICENCAS', 'MINHAS_LICENCAS', 'LEGISLACAO');
$podeMedicao = $pode('MM_MONITORAMENTO', 'MEDICAO_MONITORAMENTO');
$podeTreinamentos = $pode('MODULOLEGAL_TREINAMENTOS', 'MEUS_TREINAMENTOS');
$podeInfra = $pode('MODULOLEGAL_INFRAESTRUTURA', 'MINHAS_INFRAESTRUTURAS');
$podeControles = $pode('CONTROLE_DOCUMENTOS', 'MODULOLEGAL_CONTROLES', 'MEUS_CONTROLES');
$podeIa = $pode('IA_POTENCIAIS', 'MODULOLEGAL_POTENCIAIS');
$podeNc = $pode('NAO_CONFORMIDADES', 'NAO_CONFORMIDADES_LISTA_OCORRENCIAS', 'NAO_CONFORMIDADES_MINHAS_OCORRENCIAS');
// Migração 366: fornecedores sob a responsabilidade do usuário (o fornecedor externo não tem esta aba)
$podeFo = $pode('HOMOLOGACAO_FORNECEDOR_FORNECEDOR') && !HomologacaoFornecedorAcesso::externo();
// Painel de outra pessoa (aberto pelo Painel do gestor): só o gestor dela (direto ou acima) ou ADMINISTRADOR / ADM DA EMPRESA
$vendoOutro = false;
$alvo = (int)($_GET['usuario'] ?? 0);
if ($alvo > 0 && $alvo !== $uid) {
    $perfilN = strtoupper(trim((string)($_SESSION['usuario']['codigo_perfil'] ?? '')));
    $perfilN = preg_replace('/\s+/', ' ', preg_replace('/[_-]+/', ' ', $perfilN) ?? $perfilN) ?? $perfilN;
    $pessoas = DashboardEquipe::pessoas($sel);
    $permitido = in_array($perfilN, ['ADMINISTRADOR', 'ADM DA EMPRESA', 'ADM EMPRESA'], true) || isset(DashboardEquipe::membros($sel, $uid, $pessoas)[$alvo]);
    if (!$permitido || !isset($pessoas[$alvo])) { http_response_code(403); exit($e($T('erro.equipe', ['pt' => 'Esta pessoa não faz parte da sua equipe.', 'en' => 'This person is not part of your team.', 'es' => 'Esta persona no forma parte de su equipo.']))); }
    $uid = $alvo; $vendoOutro = true; $nomeUsuario = DashboardEquipe::nome($pessoas[$alvo]);
}
$data = static function (mixed $v): ?string { $v = substr(trim((string)$v), 0, 10); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && $v !== '0000-00-00' ? $v : null; };
$hoje = date('Y-m-d');
$dias = static fn(?string $d): ?int => $d === null ? null : (int)round((strtotime($d) - strtotime(date('Y-m-d'))) / 86400);
$fmt = static fn(?string $d): string => $d === null ? '–' : date('d/m/Y', strtotime($d));
$pct = static fn(int $p, int $t): string => $t > 0 ? number_format(100 * $p / $t, 1, ',', '.') . '%' : '–';
$abrir = static function (string $pagina, array $filtros = [], string $titulo = '', array $outros = []) use ($e): string {
    $q = $outros + ($filtros ? ['filtros' => json_encode($filtros, JSON_UNESCAPED_UNICODE)] : []);
    return ' data-abrir-transacao="' . $e($pagina . ($q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '')) . '" data-titulo="' . $e($titulo) . '"';
};
$cartao = static fn(string $rotulo, mixed $valor, string $icone, string $tom, string $extra = '', string $abaAlvo = '', string $atributos = ''): string =>
    '<article class="admin-card ' . $tom . ($abaAlvo !== '' ? ' dash-link" data-ir-aba="' . $abaAlvo : '') . ($atributos !== '' ? ' dash-link"' . $atributos . ' title="' . $e($T('abrir_dica', ['pt' => 'Clique para abrir', 'en' => 'Click to open', 'es' => 'Haga clic para abrir'])) : '') . '"><i class="bi ' . $icone . '"></i><strong>' . $valor . '</strong><span>' . $rotulo . '</span>' . ($extra !== '' ? '<small>' . $extra . '</small>' : '') . '</article>';
$numLink = static fn(int $n, string $atributos, string $classe = ''): string => $n > 0 ? '<a class="dash-num' . ($classe !== '' ? ' ' . $classe : '') . '"' . $atributos . '>' . $n . '</a>' : '0';
$vazio = static fn(string $texto): string => '<div class="dash-nota">' . $texto . '</div>';
$rotSit = ['S' => $T('sit.s', ['pt' => 'Atendido', 'en' => 'Compliant', 'es' => 'Atendido']), 'PA' => $T('sit.pa', ['pt' => 'Parcialmente atendido', 'en' => 'Partially compliant', 'es' => 'Parcialmente atendido']),
    'N' => $T('sit.n', ['pt' => 'Não atendido', 'en' => 'Not compliant', 'es' => 'No atendido']), 'AI' => $T('sit.ai', ['pt' => 'Indefinido', 'en' => 'Undefined', 'es' => 'Indefinido'])];
$valorSit = ['S' => 'Atendido', 'PA' => 'Parcialmente atendido', 'N' => 'Não atendido', 'AI' => 'Indefinido'];
$corSit = ['S' => '#2f8a5f', 'PA' => '#d39e00', 'N' => '#c95b55', 'AI' => '#9aa8a1'];
$barra = static function (array $c) use ($corSit): string {
    $t = array_sum($c); if ($t <= 0) return '';
    $h = '<div class="dash-barra">';
    foreach (['S', 'PA', 'N', 'AI'] as $k) if (($c[$k] ?? 0) > 0) $h .= '<span style="width:' . number_format(100 * $c[$k] / $t, 2, '.', '') . '%;background:' . $corSit[$k] . '" title="' . (int)$c[$k] . '"></span>';
    return $h . '</div>';
};
$tituloReq = $T('titulo.requisitos', ['pt' => 'Meus requisitos legais', 'en' => 'My legal requirements', 'es' => 'Mis requisitos legales']);
$tituloLic = $T('titulo.licencas', ['pt' => 'Minhas licenças', 'en' => 'My licenses', 'es' => 'Mis licencias']);
$tituloMed = $T('titulo.medicoes', ['pt' => 'Minhas medições', 'en' => 'My measurements', 'es' => 'Mis mediciones']);
$filtroMeu = ['LEI_REVOGADA' => 'NAO', 'RESPONSAVEL_IDS' => ',' . $uid . ','];
$abrirReq = static fn(array $f, string $sub) => $abrir('modulolegal_requisitoslegais.php', $filtroMeu + $f, $tituloReq . ($sub !== '' ? ' - ' . $sub : ''));
$abrirLic = static fn(int $cod, string $nome) => $abrir('manutencaolegal_licencas.php', ['CODLICENCA' => $cod], $tituloLic . ' - ' . mb_strimwidth($nome, 0, 70, '…'), ['minhas_pendencias' => 1]);
$abrirMed = static fn(int $cod, string $nome) => $abrir('medicaoemonitoramento_mm_monitoramento.php', ['CODMONITORAMENTO' => $cod], $tituloMed . ' - ' . mb_strimwidth($nome, 0, 70, '…'), ['minhas_pendencias' => 1]);

// ---------------------------------------------------------------- dados (só do usuário logado)
// Requisitos em que o usuário é responsável (mesmo filtro "Responsável" do grid de Requisitos legais)
$meusRequisitos = static function () use ($pdo, $podeRequisitos, $filtroMeu): ?array {
    if (!$podeRequisitos) return null;
    // sp_crud_select devolve no máximo 1000 linhas por chamada: lê em lotes e descarta repetidos (requisito + unidade)
    foreach (['vw_modulolegal_requisitos_situacao_grid', 'vw_modulolegal_requisitos_filtro_grid'] as $view) {
        $rows = DashboardEquipe::lerTudo($pdo, $view, $filtroMeu);
        if ($rows !== null) return $rows;
    }
    return null;
};
$contarSit = static function (array $rows): array {
    $c = ['S' => 0, 'PA' => 0, 'N' => 0, 'AI' => 0];
    $mapa = ['Atendido' => 'S', 'Parcialmente atendido' => 'PA', 'Não atendido' => 'N'];
    foreach ($rows as $r) $c[$mapa[(string)($r['SITUACAO_CONSOLIDADA'] ?? '')] ?? 'AI']++;
    return $c;
};
// Licenças da versão ativa atribuídas ao usuário (direto, responsabilidade ou grupo), com a faixa do dashboard da empresa
$minhasLicencas = static function () use ($sel, $podeLicencas, $uid): ?array {
    if (!$podeLicencas) return null;
    $porUsuario = DashboardEquipe::licencas($sel, [$uid => true]);
    return $porUsuario === null ? null : ($porUsuario[$uid] ?? []);
};
// Medições atribuídas ao usuário: atrasada = data de reação passada
$minhasMedicoes = static function () use ($sel, $podeMedicao, $uid): ?array {
    if (!$podeMedicao) return null;
    $porUsuario = DashboardEquipe::medicoes($sel, [$uid => true]);
    return $porUsuario === null ? null : ($porUsuario[$uid] ?? []);
};
// Planos de ação do usuário ainda abertos
$meusPlanos = static function () use ($sel, $podeNc, $uid, $data, $dias, $hoje): ?array {
    if (!$podeNc) return null;
    $rows = $sel('vw_dashboard_planos_acao', ['CODUSUARIO' => $uid], 5000);
    if ($rows === null) return null;
    $out = [];
    foreach ($rows as $r) {
        if (in_array(strtoupper(trim((string)($r['STATUS'] ?? ''))), ['CONCLUIDO', 'CONCLUÍDO', 'S', '1', '2'], true)) continue;
        $fim = $data($r['DATAFINAL'] ?? null);
        $out[] = ['ACAO' => (string)($r['ACAO'] ?? ''), 'FIM' => $fim, 'DIAS' => $dias($fim), 'ATRASADO' => $fim !== null && $fim < $hoje, 'OCORRENCIA' => (string)($r['CODOCORRENCIA'] ?? '')];
    }
    usort($out, static fn($a, $b) => [(int)!$a['ATRASADO'], $a['FIM'] ?? '9999'] <=> [(int)!$b['ATRASADO'], $b['FIM'] ?? '9999']);
    return $out;
};
$contar = static function (string $view, array $filtros) use ($pdo): ?int {
    try { $s = $pdo->prepare('CALL sp_crud_count(:t, :f, :b)'); $s->execute(['t' => $view, 'f' => json_encode((object)$filtros, JSON_UNESCAPED_UNICODE), 'b' => '']); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return (int)($r[0]['total_registros'] ?? 0); }
    catch (Throwable) { return null; }
};
// Migração 288: EM_PROTOCOLO = vencida com a renovação protocolada no prazo (continua válida); fora do prazo = VENCIDA
// Migração 367: o cartão filtra a tabela #id da mesma aba (assets/js/dashboard-abas.js)
$filtro = static fn(string $id, string $valor, string $rotulo): string => ' data-dash-filtro="' . $e($id) . '" data-dash-valor="' . $e($valor) . '" data-dash-rotulo="' . $e($rotulo) . '"';
$faixasLic = ['VENCIDA' => [$T('fx.vencida2', ['pt' => 'Vencidas (sem protocolo ou protocolo fora do prazo)', 'en' => 'Expired (no renewal filed or filed late)', 'es' => 'Vencidas (sin protocolo o protocolo fuera de plazo)']), 'bi-calendar-x', 'red', 'danger'],
    'PROT_SEM_PRAZO' => [$T('fx.prot_sem_prazo', ['pt' => 'Em protocolo (prazo não informado)', 'en' => 'Renewal filed (deadline not informed)', 'es' => 'En protocolo (plazo no informado)']), 'bi-hourglass-bottom', 'orange', 'warning'],
    'ENTREGA_ATRASADA' => [$T('fx.ent_atr', ['pt' => 'Entregas periódicas atrasadas', 'en' => 'Overdue periodic submissions', 'es' => 'Entregas periódicas atrasadas']), 'bi-send-x', 'red', 'danger'],
    'VENCE_30' => [$T('fx.30', ['pt' => 'Vencem em até 30 dias', 'en' => 'Expire within 30 days', 'es' => 'Vencen en hasta 30 días']), 'bi-alarm', 'orange', 'warning'],
    'ENTREGA_30' => [$T('fx.ent_30', ['pt' => 'Entregas com prazo em até 30 dias', 'en' => 'Submissions due within 30 days', 'es' => 'Entregas con plazo en hasta 30 días']), 'bi-send', 'orange', 'warning'],
    'EM_PROTOCOLO' => [$T('fx.protocolo2', ['pt' => 'Válidas (em protocolo)', 'en' => 'Valid (renewal filed)', 'es' => 'Válidas (en protocolo)']), 'bi-hourglass-split', 'blue', 'primary'],
    'VENCE_60' => [$T('fx.60', ['pt' => 'Vencem em 31 a 60 dias', 'en' => 'Expire in 31 to 60 days', 'es' => 'Vencen en 31 a 60 días']), 'bi-calendar-event', 'orange', 'secondary'],
    'VENCE_90' => [$T('fx.90', ['pt' => 'Vencem em 61 a 90 dias', 'en' => 'Expire in 61 to 90 days', 'es' => 'Vencen en 61 a 90 días']), 'bi-calendar3', 'green', 'secondary'],
    'SEM_VALIDADE' => [$T('fx.sem', ['pt' => 'Sem data de validade', 'en' => 'No expiry date', 'es' => 'Sin fecha de validez']), 'bi-question-circle', 'gray', 'secondary']];
$faixasMed = ['ATRASADA' => [$T('mfx.atrasada', ['pt' => 'Atrasada', 'en' => 'Overdue', 'es' => 'Atrasada']), 'danger'], 'PROXIMA' => [$T('mfx.proxima', ['pt' => 'Próximos 30 dias', 'en' => 'Next 30 days', 'es' => 'Próximos 30 días']), 'warning'],
    'EM_DIA' => [$T('mfx.em_dia', ['pt' => 'Em dia', 'en' => 'On schedule', 'es' => 'Al día']), 'success'], 'SEM_DATA' => [$T('mfx.sem', ['pt' => 'Sem data de reação', 'en' => 'No reaction date', 'es' => 'Sin fecha de reacción']), 'secondary']];
$semPermissao = $vazio($e($T('sem_permissao', ['pt' => 'Seu perfil não tem acesso a esta informação.', 'en' => 'Your profile has no access to this information.', 'es' => 'Su perfil no tiene acceso a esta información.'])));
$colunas = static fn(array $titulos): string => '<thead><tr>' . implode('', array_map(static fn($t) => '<th' . (str_starts_with($t, '#') ? ' class="num">' . substr($t, 1) : '>' . $t) . '</th>', $titulos)) . '</tr></thead>';

// ---------------------------------------------------------------- abas (fragmentos)
if ($aba !== '') {
    header('Content-Type: text/html; charset=utf-8');

    // ======== Visão geral
    if ($aba === 'geral') {
        $req = $meusRequisitos(); $lic = $minhasLicencas(); $med = $minhasMedicoes(); $planos = $meusPlanos();
        $sit = $req !== null ? $contarSit($req) : null;
        $licAtencao = $lic !== null ? count(array_filter($lic, static fn($l) => in_array((string)$l['FAIXA'], ['VENCIDA', 'PROT_SEM_PRAZO', 'ENTREGA_ATRASADA', 'VENCE_30', 'ENTREGA_30'], true))) : null;
        $medAtr = $med !== null ? count(array_filter($med, static fn($m) => $m['FAIXA'] === 'ATRASADA')) : null;
        $planAtr = $planos !== null ? count(array_filter($planos, static fn($p) => $p['ATRASADO'])) : null;
        $alertas = $contar('vw_alertas_grid', ['codusuario' => $uid, 'lido' => 0]);
        $perfilAtr = ($sel('vw_perfil_atribuicoes_situacao', ['codusuario' => $uid], 1) ?? [])[0] ?? null;
        $iaPend = null;
        if ($podeIa) { $iaRows = $sel('ml_potenciais_solicitacoes', ['codusuario' => $uid], 5000); if ($iaRows !== null) $iaPend = count(array_filter($iaRows, static fn($r) => !in_array(strtoupper(trim((string)($r['status'] ?? ''))), ['CONCLUIDA', 'CONCLUÍDA', 'CONCLUIDO', 'CONCLUÍDO', 'CANCELADA'], true))); }
        $h = '<div class="admin-cards">';
        if ($sit !== null) {
            $h .= $cartao($e($T('card.requisitos', ['pt' => 'Meus requisitos legais', 'en' => 'My legal requirements', 'es' => 'Mis requisitos legales'])), array_sum($sit), 'bi-journal-text', 'blue', $pct($sit['S'], array_sum($sit) - $sit['AI']) . ' ' . $e($T('card.atendidos', ['pt' => 'atendidos', 'en' => 'compliant', 'es' => 'atendidos'])), '', array_sum($sit) ? $abrirReq([], '') : '');
            $h .= $cartao($e($rotSit['N']), $sit['N'], 'bi-x-circle', 'red', '', '', $abrirReq(['SITUACAO_CONSOLIDADA' => ['value' => 'Não atendido', 'exact' => true]], $rotSit['N']));
        }
        if ($licAtencao !== null) $h .= $cartao($e($T('card.lic_atencao', ['pt' => 'Licenças e entregas em atenção', 'en' => 'Licenses and submissions needing attention', 'es' => 'Licencias y entregas en atención'])), $licAtencao, 'bi-calendar-x', 'orange', count($lic) . ' ' . $e($T('card.lic_total', ['pt' => 'sob minha responsabilidade', 'en' => 'under my responsibility', 'es' => 'bajo mi responsabilidad'])), 'licencas');
        if ($medAtr !== null) $h .= $cartao($e($T('card.med_atr', ['pt' => 'Medições atrasadas', 'en' => 'Late measurements', 'es' => 'Mediciones atrasadas'])), $medAtr, 'bi-graph-down', $medAtr ? 'red' : 'green', count($med) . ' ' . $e($T('card.med_total', ['pt' => 'medições minhas', 'en' => 'my measurements', 'es' => 'mis mediciones'])), 'tarefas');
        if ($planAtr !== null) $h .= $cartao($e($T('card.planos', ['pt' => 'Planos de ação atrasados', 'en' => 'Late action plans', 'es' => 'Planes de acción atrasados'])), $planAtr, 'bi-kanban', $planAtr ? 'red' : 'green', count($planos) . ' ' . $e($T('card.planos_total', ['pt' => 'em aberto', 'en' => 'open', 'es' => 'abiertos'])), 'tarefas');
        if ($alertas !== null) $h .= $cartao($e($T('card.alertas', ['pt' => 'Alertas não lidos', 'en' => 'Unread alerts', 'es' => 'Alertas no leídas'])), $alertas, 'bi-bell', $alertas ? 'orange' : 'green', '', '', $vendoOutro ? '' : $abrir('alertas.php', [], $T('card.alertas', ['pt' => 'Alertas não lidos', 'en' => 'Unread alerts', 'es' => 'Alertas no leídas'])));
        if ($perfilAtr !== null && (string)($perfilAtr['aplicar_questionario'] ?? '1') !== '0') {
            $st = (string)($perfilAtr['ultimo_status'] ?? '');
            $rotPerfil = !empty($perfilAtr['vigente_codperfilatrib']) ? $T('pa.aprovado', ['pt' => 'Aprovado', 'en' => 'Approved', 'es' => 'Aprobado'])
                : ($st === 'PENDENTE_APROVACAO' ? $T('pa.pendente', ['pt' => 'Aguardando aprovação', 'en' => 'Awaiting approval', 'es' => 'Esperando aprobación'])
                : ($st === 'DEVOLVIDO' ? $T('pa.devolvido', ['pt' => 'Devolvido para correção', 'en' => 'Returned for correction', 'es' => 'Devuelto para corrección']) : $T('pa.sem', ['pt' => 'Não preenchido', 'en' => 'Not filled in', 'es' => 'No completado'])));
            $tomPerfil = !empty($perfilAtr['vigente_codperfilatrib']) ? 'green' : ($st === 'DEVOLVIDO' ? 'red' : 'orange');
            $h .= $cartao($e($T('card.perfil', ['pt' => 'Meu perfil de atribuições', 'en' => 'My duties profile', 'es' => 'Mi perfil de atribuciones'])), '<span class="dash-texto">' . $e($rotPerfil) . '</span>', 'bi-person-badge', $tomPerfil, !empty($perfilAtr['valido_ate']) ? $e($T('pa.valido', ['pt' => 'válido até', 'en' => 'valid until', 'es' => 'válido hasta'])) . ' ' . $e($fmt($data($perfilAtr['valido_ate']))) : '', '', ($vendoOutro ? (!empty($perfilAtr['ultimo_codperfilatrib']) || !empty($perfilAtr['vigente_codperfilatrib']) ? $abrir('perfil_atribuicoes_gestao.php', [], $T('card.perfil_de', ['pt' => 'Perfil de atribuições', 'en' => 'Duties profile', 'es' => 'Perfil de atribuciones']) . ' - ' . $nomeUsuario, ['id' => (int)($perfilAtr['ultimo_codperfilatrib'] ?: $perfilAtr['vigente_codperfilatrib'])]) : '') : $abrir('perfil_atribuicoes.php', [], $T('card.perfil', ['pt' => 'Meu perfil de atribuições', 'en' => 'My duties profile', 'es' => 'Mi perfil de atribuciones']), ['popup' => 1])));
        }
        if ($iaPend !== null) $h .= $cartao($e($T('card.ia', ['pt' => 'Análises de IA em aberto', 'en' => 'Open AI analyses', 'es' => 'Análisis de IA abiertos'])), $iaPend, 'bi-stars', 'purple', '', '', $vendoOutro ? '' : $abrir('modulolegal_potenciais.php', [], $T('card.ia', ['pt' => 'Análises de IA em aberto', 'en' => 'Open AI analyses', 'es' => 'Análisis de IA abiertos'])));
        $h .= (new DashboardNcFornecedores($pdo))->cartoesPessoal($uid, $podeNc, $podeFo);
        $h .= '</div>';
        // Próximos prazos: o que está atrasado e o que vence nos próximos 30 dias
        $prazos = [];
        foreach ($lic ?? [] as $l) if (in_array((string)$l['FAIXA'], ['VENCIDA', 'PROT_SEM_PRAZO', 'ENTREGA_ATRASADA', 'VENCE_30', 'ENTREGA_30'], true))
            $prazos[] = ['quando' => $data($l['DATA_VALIDADE']), 'tipo' => (string)$l['NATUREZA'] === 'ENTREGA_PERIODICA' ? $T('tipo.entrega', ['pt' => 'Entrega periódica', 'en' => 'Periodic submission', 'es' => 'Entrega periódica']) : $T('tipo.licenca', ['pt' => 'Licença', 'en' => 'License', 'es' => 'Licencia']),
                'icone' => 'bi-file-earmark-text', 'texto' => (string)$l['LICENCA'], 'unidade' => (string)$l['UNIDADE'], 'atr' => $abrirLic((int)$l['CODLICENCA'], (string)$l['LICENCA'])];
        foreach ($med ?? [] as $m) if (in_array($m['FAIXA'], ['ATRASADA', 'PROXIMA'], true))
            $prazos[] = ['quando' => $m['REACAO'], 'tipo' => $T('tipo.medicao', ['pt' => 'Medição', 'en' => 'Measurement', 'es' => 'Medición']), 'icone' => 'bi-activity', 'texto' => $m['NOME'], 'unidade' => $m['UNIDADE'], 'atr' => $abrirMed($m['COD'], $m['NOME'])];
        foreach ($planos ?? [] as $p) if ($p['FIM'] !== null && $p['DIAS'] <= 30)
            $prazos[] = ['quando' => $p['FIM'], 'tipo' => $T('tipo.plano', ['pt' => 'Plano de ação', 'en' => 'Action plan', 'es' => 'Plan de acción']), 'icone' => 'bi-kanban', 'texto' => $p['ACAO'], 'unidade' => $p['OCORRENCIA'] !== '' ? $T('tipo.ocorrencia', ['pt' => 'Ocorrência', 'en' => 'Occurrence', 'es' => 'Ocurrencia']) . ' ' . $p['OCORRENCIA'] : '',
                'atr' => $abrir('naoconformidades_minhasocorrencias.php', [], $T('titulo.nc', ['pt' => 'Minhas não conformidades', 'en' => 'My nonconformities', 'es' => 'Mis no conformidades']))];
        usort($prazos, static fn($a, $b) => strcmp($a['quando'] ?? '9999', $b['quando'] ?? '9999'));
        $linhasPrazo = [];
        foreach ($prazos as $p) {
            $d = $dias($p['quando']);
            $linhasPrazo[] = ['cells' => ['PRAZO' => DashboardGrid::prazo($p['quando']), 'DIAS' => DashboardGrid::numero($d ?? '', $d !== null && $d < 0 ? 'status-danger' : ''),
                'TIPO' => ['text' => $p['tipo'], 'html' => '<i class="bi ' . $p['icone'] . ' me-1"></i>' . $e($p['tipo'])], 'ITEM' => ['text' => $p['texto'], 'title' => $p['texto']], 'UNIDADE' => $p['unidade']],
                'open' => DashboardGrid::abrirDe($p['atr'])];
        }
        $h .= DashboardGrid::subabas('geral', [['id' => 'prazos', 'icone' => 'bi-calendar-check', 'rotulo' => $T('prazos.titulo', ['pt' => 'Meus prazos: atrasados e próximos 30 dias', 'en' => 'My deadlines: overdue and next 30 days', 'es' => 'Mis plazos: atrasados y próximos 30 días']), 'contagem' => count($prazos),
            'html' => DashboardGrid::grid('dash-prazos', [
                'PRAZO' => ['label' => $T('col.prazo', ['pt' => 'Prazo', 'en' => 'Due', 'es' => 'Plazo']), 'width' => '120px'],
                'DIAS' => ['label' => $T('col.dias', ['pt' => 'Dias', 'en' => 'Days', 'es' => 'Días']), 'width' => '80px'],
                'TIPO' => ['label' => $T('col.tipo', ['pt' => 'Tipo', 'en' => 'Type', 'es' => 'Tipo']), 'filter' => 'select', 'width' => '160px'],
                'ITEM' => ['label' => $T('col.item', ['pt' => 'Item', 'en' => 'Item', 'es' => 'Ítem'])],
                'UNIDADE' => ['label' => $T('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), 'filter' => 'select', 'width' => '200px'],
            ], $linhasPrazo, ['vazio' => $T('prazos.nada', ['pt' => 'Nada atrasado nem vencendo nos próximos 30 dias.', 'en' => 'Nothing overdue or due in the next 30 days.', 'es' => 'Nada atrasado ni por vencer en los próximos 30 días.']), 'transacao' => 'dashboard'])]]);
        exit($h);
    }

    // ======== Meu atendimento legal
    if ($aba === 'legal') {
        if (!$podeRequisitos) exit($semPermissao);
        $req = $meusRequisitos();
        if ($req === null) exit($vazio($e($T('legal.indisponivel', ['pt' => 'Indicadores indisponíveis nesta empresa.', 'en' => 'Indicators not available for this company.', 'es' => 'Indicadores no disponibles en esta empresa.']))));
        $tot = $contarSit($req); $total = array_sum($tot);
        $semEv = count(array_filter($req, static fn($r) => in_array((string)($r['SITUACAO_CALCULADA'] ?? ''), ['', 'Sem evidências vinculadas'], true)));
        $unidades = [];
        $mapaSit = ['Atendido' => 'S', 'Parcialmente atendido' => 'PA', 'Não atendido' => 'N'];
        foreach ($req as $r) { $u = (string)($r['UNIDADE'] ?? ''); $unidades[$u] ??= ['S' => 0, 'PA' => 0, 'N' => 0, 'AI' => 0]; $unidades[$u][$mapaSit[(string)($r['SITUACAO_CONSOLIDADA'] ?? '')] ?? 'AI']++; }
        uksort($unidades, 'strnatcasecmp');
        $naoAt = array_values(array_filter($req, static fn($r) => (string)($r['SITUACAO_CONSOLIDADA'] ?? '') === 'Não atendido'));
        $diverg = array_values(array_filter($req, static fn($r) => (string)($r['DIVERGENCIA'] ?? '') === 'Sim'));
        $relatorios = $sel('exportacoes_grid', ['codusuario' => $uid, 'transacao' => 'modulolegal_relatorio_atendimento'], 1000) ?? [];
        usort($relatorios, static fn($a, $b) => (int)$b['codexportacaogrid'] <=> (int)$a['codexportacaogrid']);
        $h = '<div class="admin-cards dash-cards-6">' . $cartao($e($T('legal.meus', ['pt' => 'Requisitos sob minha responsabilidade', 'en' => 'Requirements under my responsibility', 'es' => 'Requisitos bajo mi responsabilidad'])), $total, 'bi-journal-text', 'blue', '', '', $total ? $abrirReq([], '') : '');
        foreach (['S' => ['bi-check-circle', 'green'], 'PA' => ['bi-dash-circle', 'orange'], 'N' => ['bi-x-circle', 'red'], 'AI' => ['bi-question-circle', 'gray']] as $k => [$ic, $tom])
            $h .= $cartao($e($rotSit[$k]), $tot[$k], $ic, $tom, $pct($tot[$k], $total), '', $abrirReq(['SITUACAO_CONSOLIDADA' => ['value' => $valorSit[$k], 'exact' => true]], $rotSit[$k]));
        $h .= $cartao($e($T('legal.pct', ['pt' => '% atendido (com situação definida)', 'en' => '% compliant (defined status)', 'es' => '% atendido (situación definida)'])), $pct($tot['S'], $total - $tot['AI']), 'bi-graph-up', 'green', '', '', ' data-ir-ancora="dash-legal-unidade"')
            . '</div><div class="dash-barra-grande">' . $barra($tot) . '</div>'
            . '<p class="dash-nota">' . $e($T('legal.nota', ['pt' => 'Requisitos em que você é o responsável. Situação consolidada: a calculada pelos itens de gestão vinculados ou, sem itens, a avaliação manual.', 'en' => 'Requirements you are responsible for. Consolidated status: calculated from linked management items or, without items, the manual assessment.', 'es' => 'Requisitos de los que usted es responsable. Situación consolidada: la calculada por los ítems de gestión vinculados o, sin ítems, la evaluación manual.']))
            . ' ' . $semEv . ' ' . $e($T('legal.sem_ev', ['pt' => 'sem evidências vinculadas', 'en' => 'without linked evidence', 'es' => 'sin evidencias vinculadas'])) . ' · ' . count($diverg) . ' ' . $e($T('legal.div', ['pt' => 'com divergência', 'en' => 'divergent', 'es' => 'con divergencia'])) . '.</p>';
        $h .= '<div class="dash-acoes">' . ($total && !$vendoOutro ? '<button type="button" class="btn btn-sm btn-success" data-gerar-relatorio="' . $e(json_encode($filtroMeu)) . '"><i class="bi bi-file-earmark-bar-graph me-1"></i>' . $e($T('legal.gerar', ['pt' => 'Gerar meu Relatório de Atendimento Legal (PDF e Excel)', 'en' => 'Generate my Legal Compliance Report (PDF and Excel)', 'es' => 'Generar mi Informe de Cumplimiento Legal (PDF y Excel)'])) . '</button>' : '')
            . '<button type="button" class="btn btn-sm btn-outline-secondary"' . $abrirReq([], '') . '><i class="bi bi-box-arrow-up-right me-1"></i>' . $e($T('legal.abrir', ['pt' => 'Abrir meus requisitos', 'en' => 'Open my requirements', 'es' => 'Abrir mis requisitos'])) . '</button>';
        if ($relatorios && !$vendoOutro) {
            $h .= '<span class="dash-relatorios"><span class="text-muted">' . $e($T('legal.ultimos', ['pt' => 'Meus últimos relatórios:', 'en' => 'My latest reports:', 'es' => 'Mis últimos informes:'])) . '</span>';
            foreach (array_slice($relatorios, 0, 6) as $r) {
                $arq = (string)($r['arquivo'] ?? ''); $quando = $r['concluido_em'] ?? $r['solicitado_em'] ?? '';
                $rot = (str_ends_with(strtolower($arq), '.pdf') ? 'PDF' : (str_ends_with(strtolower($arq), '.xlsx') ? 'Excel' : '')) . ' ' . ($quando ? date('d/m H:i', strtotime((string)$quando)) : '');
                $h .= (string)$r['status'] === 'CONCLUIDA' && $arq !== '' ? (str_ends_with(strtolower($arq), '.pdf') ? '<a href="..' . $e($arq) . '" data-abrir-arquivo data-titulo="' . $e(basename($arq)) . '"><i class="bi bi-file-earmark-pdf me-1"></i>' : '<a href="..' . $e($arq) . '" download><i class="bi bi-download me-1"></i>') . $e(trim($rot)) . '</a>'
                    : '<span class="text-muted"><i class="bi bi-hourglass-split me-1"></i>' . $e($T('legal.gerando', ['pt' => 'gerando', 'en' => 'generating', 'es' => 'generando'])) . '</span>';
            }
            $h .= '</span>';
        }
        $h .= '</div>';
        if (!$total) exit($h . $vazio($e($T('legal.nenhum', ['pt' => 'Você não é responsável por nenhum requisito legal.', 'en' => 'You are not responsible for any legal requirement.', 'es' => 'Usted no es responsable de ningún requisito legal.']))));
        $qu = '<article class="admin-panel" id="dash-legal-unidade"><h3><i class="bi bi-buildings me-1"></i>' . $e($T('legal.por_unidade', ['pt' => 'Por unidade', 'en' => 'By unit', 'es' => 'Por unidad'])) . '</h3><table class="admin-table">'
            . $colunas([$e($T('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'])), '#' . $e($T('col.total', ['pt' => 'Total', 'en' => 'Total', 'es' => 'Total'])), '#' . $e($rotSit['S']), '#' . $e($T('col.parcial', ['pt' => 'Parcial', 'en' => 'Partial', 'es' => 'Parcial'])), '#' . $e($rotSit['N']), '#' . $e($T('legal.pct_curto', ['pt' => '% atendido*', 'en' => '% compliant*', 'es' => '% atendido*'])), '']) . '<tbody>';
        foreach ($unidades as $nome => $c) {
            $f = ['UNIDADE' => ['value' => $nome, 'exact' => true]]; $tt = array_sum($c);
            $qu .= '<tr class="dash-clique"' . $abrirReq($f, $nome) . '><td>' . $e($nome) . '</td><td class="num">' . $tt . '</td>';
            foreach (['S' => '', 'PA' => '', 'N' => 'status-danger'] as $k => $cls) $qu .= '<td class="num">' . $numLink($c[$k], $abrirReq($f + ['SITUACAO_CONSOLIDADA' => ['value' => $valorSit[$k], 'exact' => true]], $nome . ' - ' . $rotSit[$k]), $cls) . '</td>';
            $qu .= '<td class="num">' . $pct($c['S'], $tt - $c['AI']) . '</td><td style="width:28%">' . $barra($c) . '</td></tr>';
        }
        $qu .= '</tbody></table></article>';
        $sub = [];
        // Migração 368: listas de requisitos no grid padrão (abrem a situação do requisito)
        $lista = static function (string $titulo, string $icone, array $linhas, string $vazioTxt, string $id) use ($T): array {
            $rows = [];
            foreach ($linhas as $r) {
                $norma = trim(($r['DSCTIPO'] ?? '') . ' ' . ($r['NR'] ?? '') . (($r['ANO'] ?? '') !== '' ? '/' . $r['ANO'] : ''));
                $rows[] = ['cells' => ['UNIDADE' => (string)($r['UNIDADE'] ?? ''), 'CODIGO' => (string)($r['CODIGO'] ?? ''), 'NORMA' => ['text' => $norma, 'title' => $norma], 'ATENDIDO' => (string)($r['ATENDIDO'] ?? ''),
                    'CALCULADA' => (string)($r['SITUACAO_CALCULADA'] ?? ''), 'MOTIVO' => ['text' => (string)($r['MOTIVO_CALCULO'] ?? ''), 'title' => (string)($r['MOTIVO_CALCULO'] ?? '')]],
                    'open' => DashboardGrid::abrirSituacao((int)$r['CODLEGISLACAO'], (int)$r['CODUNIDADE'], (string)($r['CODIGO'] ?? ''))];
            }
            return ['id' => $id, 'icone' => $icone, 'rotulo' => $titulo, 'contagem' => count($linhas), 'html' => DashboardGrid::grid($id, [
                'UNIDADE' => ['label' => $T('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), 'filter' => 'select', 'width' => '160px'],
                'CODIGO' => ['label' => $T('col.requisito', ['pt' => 'Requisito', 'en' => 'Requirement', 'es' => 'Requisito']), 'width' => '110px'],
                'NORMA' => ['label' => $T('col.norma', ['pt' => 'Norma', 'en' => 'Standard', 'es' => 'Norma'])],
                'ATENDIDO' => ['label' => $T('col.avaliacao', ['pt' => 'Avaliação', 'en' => 'Assessment', 'es' => 'Evaluación']), 'filter' => 'select', 'width' => '140px'],
                'CALCULADA' => ['label' => $T('col.calculada', ['pt' => 'Calculada', 'en' => 'Calculated', 'es' => 'Calculada']), 'filter' => 'select', 'width' => '150px'],
                'MOTIVO' => ['label' => $T('col.motivo', ['pt' => 'Motivo', 'en' => 'Reason', 'es' => 'Motivo'])],
            ], $rows, ['vazio' => $vazioTxt, 'transacao' => 'dashboard'])];
        };
        // Migração 341: as duas dimensões (% de atendimento e risco) dos meus requisitos e as pendências críticas sob minha responsabilidade
        if ($req && array_key_exists('RISCO_REQUISITO', $req[0])) {
            $pcts = array_values(array_filter(array_map(static fn($r) => (string)($r['PCT_ATENDIMENTO'] ?? '') === '' ? null : (float)$r['PCT_ATENDIMENTO'], $req), static fn($v) => $v !== null));
            $porRisco = ['Crítico' => 0, 'Alto' => 0, 'Moderado' => 0, 'Baixo' => 0, 'Não avaliado' => 0];
            foreach ($req as $r) { $k = (string)($r['RISCO_REQUISITO'] ?? ''); if (isset($porRisco[$k])) $porRisco[$k]++; }
            $comPend = array_values(array_filter($req, static fn($r) => (int)($r['PENDENCIAS_CRITICAS'] ?? 0) > 0));
            $nPend = array_sum(array_map(static fn($r) => (int)($r['PENDENCIAS_CRITICAS'] ?? 0), $req));
            $semRiscoMeus = array_sum(array_map(static fn($r) => (int)($r['ITENS_SEM_RISCO'] ?? 0), $req));
            $meusItensPend = $sel('vw_dash_legal_pendencias_criticas', ['CODUSUARIO_ITEM' => $uid], 500) ?? [];
            $rotR = ['Crítico' => $T('risco.critico', ['pt' => 'Crítico', 'en' => 'Critical', 'es' => 'Crítico']), 'Alto' => $T('risco.alto', ['pt' => 'Alto', 'en' => 'High', 'es' => 'Alto']), 'Moderado' => $T('risco.moderado', ['pt' => 'Moderado', 'en' => 'Moderate', 'es' => 'Moderado'])];
            $h .= '<h3 class="dash-secao mt-3"><i class="bi bi-shield-exclamation me-1"></i>' . $e($T('risco.titulo_meu', ['pt' => 'Meus requisitos: atendimento e risco', 'en' => 'My requirements: compliance and risk', 'es' => 'Mis requisitos: cumplimiento y riesgo'])) . '</h3><div class="admin-cards dash-cards-6">'
                . $cartao($e($T('risco.pct_medio', ['pt' => '% de atendimento médio', 'en' => 'Average compliance %', 'es' => '% de cumplimiento promedio'])), $pcts ? rtrim(rtrim(number_format(array_sum($pcts) / count($pcts), 1, ',', '.'), '0'), ',') . '%' : '–', 'bi-graph-up-arrow', 'green', count($pcts) . ' ' . $e($T('risco.com_itens', ['pt' => 'com itens aplicáveis', 'en' => 'with applicable items', 'es' => 'con ítems aplicables'])), '', ' data-ir-ancora="dash-legal-unidade"')
                . $cartao($e($T('risco.pendencias', ['pt' => 'Pendências críticas — ação imediata', 'en' => 'Critical pending — immediate action', 'es' => 'Pendientes críticos — acción inmediata'])), $nPend, 'bi-exclamation-octagon', $nPend ? 'red' : 'green', count($comPend) . ' ' . $e($T('risco.requisitos', ['pt' => 'requisito(s)', 'en' => 'requirement(s)', 'es' => 'requisito(s)'])), '', ' data-ir-ancora="dash-meus-pend"');
            foreach ($rotR as $k => $rot) $h .= $cartao($e($T('risco.req', ['pt' => 'Risco', 'en' => 'Risk', 'es' => 'Riesgo'])) . ' ' . $e($rot), $porRisco[$k], $k === 'Crítico' ? 'bi-fire' : 'bi-exclamation-triangle', $k === 'Crítico' ? 'red' : 'orange', '', '', $abrirReq(['RISCO_REQUISITO' => ['value' => $k, 'exact' => true]], $rot));
            $h .= $cartao($e($T('risco.itens_sem', ['pt' => 'Itens com risco não avaliado', 'en' => 'Items with risk not assessed', 'es' => 'Ítems con riesgo no evaluado'])), $semRiscoMeus, 'bi-question-diamond', $semRiscoMeus ? 'gray' : 'green', '', '', $abrirReq(['RISCO_REQUISITO' => ['value' => 'Não avaliado', 'exact' => true]], $T('risco.nao_avaliado', ['pt' => 'Risco não avaliado', 'en' => 'Risk not assessed', 'es' => 'Riesgo no evaluado']))) . '</div>';
            $sub['pend'] = $lista($T('risco.lista_meus_pend', ['pt' => 'Meus requisitos com pendência crítica (ação imediata)', 'en' => 'My requirements with critical pending items (immediate action)', 'es' => 'Mis requisitos con pendientes críticos (acción inmediata)']), 'bi-exclamation-octagon', $comPend, $T('risco.lista_meus_pend_vazia', ['pt' => 'Nenhum requisito com pendência crítica.', 'en' => 'No requirement with critical pending items.', 'es' => 'Ningún requisito con pendientes críticos.']), 'dash-meus-pend');
            if ($meusItensPend) {
                $vistos = []; $rowsItens = [];
                foreach ($meusItensPend as $p) {
                    $k = $p['CODLEGISLACAO'] . ':' . $p['CODUNIDADE'] . ':' . $p['AREA'] . ':' . $p['ITEM_ID']; if (isset($vistos[$k])) continue; $vistos[$k] = true;
                    $rowsItens[] = ['cells' => ['UNIDADE' => (string)$p['UNIDADE'], 'CODIGO' => (string)($p['CODIGO'] ?? ''), 'ITEM' => ['text' => (string)$p['ITEM'], 'title' => (string)$p['ITEM']], 'MOTIVO' => ['text' => (string)$p['MOTIVO'], 'title' => (string)$p['MOTIVO']]],
                        'open' => DashboardGrid::abrirSituacao((int)$p['CODLEGISLACAO'], (int)$p['CODUNIDADE'], (string)($p['CODIGO'] ?? ''))];
                }
                $sub['itens'] = ['id' => 'itens', 'icone' => 'bi-person-exclamation', 'rotulo' => $T('risco.meus_itens', ['pt' => 'Itens sob minha responsabilidade com pendência crítica', 'en' => 'Items under my responsibility with critical pending', 'es' => 'Ítems bajo mi responsabilidad con pendiente crítico']), 'contagem' => count($rowsItens),
                    'html' => DashboardGrid::grid('dash-meus-itens', [
                        'UNIDADE' => ['label' => $T('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), 'filter' => 'select', 'width' => '160px'],
                        'CODIGO' => ['label' => $T('col.requisito', ['pt' => 'Requisito', 'en' => 'Requirement', 'es' => 'Requisito']), 'width' => '110px'],
                        'ITEM' => ['label' => $T('col.item', ['pt' => 'Item', 'en' => 'Item', 'es' => 'Ítem'])],
                        'MOTIVO' => ['label' => $T('col.motivo', ['pt' => 'Motivo', 'en' => 'Reason', 'es' => 'Motivo'])],
                    ], $rowsItens, ['transacao' => 'dashboard'])];
            }
        }
        $sub = ['nao' => $lista($T('legal.lista_nao', ['pt' => 'Meus requisitos não atendidos', 'en' => 'My non-compliant requirements', 'es' => 'Mis requisitos no atendidos']), 'bi-x-octagon', $naoAt, $T('legal.lista_nao_vazia', ['pt' => 'Nenhum requisito não atendido.', 'en' => 'No non-compliant requirement.', 'es' => 'Ningún requisito no atendido.']), 'dash-req-nao')] + $sub;
        $sub['div'] = $lista($T('legal.lista_div', ['pt' => 'Divergências para eu decidir (minha avaliação diferente da calculada)', 'en' => 'Divergences for me to decide (my assessment differs from calculated)', 'es' => 'Divergencias para decidir (mi evaluación distinta de la calculada)']), 'bi-exclamation-triangle', $diverg, $T('legal.lista_div_vazia', ['pt' => 'Nenhuma divergência pendente.', 'en' => 'No pending divergence.', 'es' => 'Ninguna divergencia pendiente.']), 'dash-req-div');
        $sub['unidade'] = ['id' => 'unidade', 'icone' => 'bi-buildings', 'rotulo' => $T('legal.por_unidade', ['pt' => 'Por unidade', 'en' => 'By unit', 'es' => 'Por unidad']), 'html' => $qu];
        $ordemSub = ['nao' => 0, 'div' => 1, 'pend' => 2, 'itens' => 3, 'unidade' => 4];
        uksort($sub, static fn($a, $b) => ($ordemSub[$a] ?? 9) <=> ($ordemSub[$b] ?? 9));
        $h .= DashboardGrid::subabas('legal', array_values($sub));
        exit($h);
    }

    // ======== Minhas licenças e entregas
    if ($aba === 'licencas') {
        if (!$podeLicencas) exit($semPermissao);
        $lic = $minhasLicencas();
        if ($lic === null) exit($vazio($e($T('lic.indisponivel', ['pt' => 'Indicadores de licenças indisponíveis (rode a migração 285).', 'en' => 'License indicators not available (run migration 285).', 'es' => 'Indicadores de licencias no disponibles (ejecute la migración 285).']))));
        $cont = array_fill_keys(array_keys($faixasLic), 0); $vigentes = 0; $periodicas = 0;
        foreach ($lic as $l) { $f = (string)$l['FAIXA']; if (isset($cont[$f])) $cont[$f]++; if ($f === 'VIGENTE') $vigentes++; if ($l['NATUREZA'] === 'ENTREGA_PERIODICA') $periodicas++; }
        $h = '<div class="admin-cards dash-cards-4">' . $cartao($e($T('lic.total', ['pt' => 'Licenças e documentos sob minha responsabilidade', 'en' => 'Licenses and documents under my responsibility', 'es' => 'Licencias y documentos bajo mi responsabilidad'])), count($lic), 'bi-files', 'blue', $periodicas . ' ' . $e($T('lic.periodicas', ['pt' => 'entregas periódicas', 'en' => 'periodic submissions', 'es' => 'entregas periódicas'])), '', count($lic) ? $abrir('minhas_licencas.php', [], $tituloLic) : '')
            . $cartao($e($rotVig = $T('lic.vigentes', ['pt' => 'Vigentes (mais de 90 dias)', 'en' => 'Valid (over 90 days)', 'es' => 'Vigentes (más de 90 días)'])), $vigentes, 'bi-check-circle', 'green', '', '', $filtro('dash-lic-minhas', 'VIGENTE', $rotVig));
        foreach ($faixasLic as $f => [$rot, $ic, $tom]) $h .= $cartao($e($rot), $cont[$f], $ic, $tom, '', '', $filtro('dash-lic-minhas', $f, $rot));
        $h .= '</div>';
        // Situação das licenças (migração 288): Válidas x Válidas (em protocolo) x Vencidas, sem as entregas periódicas
        if ($lic && array_key_exists('SITUACAO_LICENCA', $lic[0])) {
            $sitCont = ['VALIDA' => 0, 'PROTOCOLO' => 0, 'PROT_SEM_PRAZO' => 0, 'VENCIDA' => 0, 'SEM_DATA' => 0];
            foreach ($lic as $l) { $s = (string)($l['SITUACAO_LICENCA'] ?? ''); if (isset($sitCont[$s])) $sitCont[$s]++; }
            $sitRot = ['VALIDA' => [$T('sit.valida', ['pt' => 'Válidas', 'en' => 'Valid', 'es' => 'Válidas']), 'bi-patch-check', 'green'],
                'PROTOCOLO' => [$T('sit.protocolo', ['pt' => 'Válidas (em protocolo)', 'en' => 'Valid (renewal filed)', 'es' => 'Válidas (en protocolo)']), 'bi-hourglass-split', 'blue'],
                'PROT_SEM_PRAZO' => [$T('sit.prot_sem_prazo', ['pt' => 'Em protocolo (prazo não informado)', 'en' => 'Renewal filed (deadline not informed)', 'es' => 'En protocolo (plazo no informado)']), 'bi-hourglass-bottom', 'orange'],
                'VENCIDA' => [$T('sit.vencida', ['pt' => 'Vencidas', 'en' => 'Expired', 'es' => 'Vencidas']), 'bi-calendar-x', 'red'],
                'SEM_DATA' => [$T('sit.sem_data', ['pt' => 'Sem data de vencimento', 'en' => 'No due date', 'es' => 'Sin fecha de vencimiento']), 'bi-question-circle', 'gray']];
            if ($sitCont['PROT_SEM_PRAZO'] === 0) unset($sitRot['PROT_SEM_PRAZO']); // só aparece quando há (decisão de 27/10/2026)
            $sitTotal = array_sum($sitCont);
            $h .= '<article class="admin-panel mt-2"><h3><i class="bi bi-shield-check me-1"></i>' . $e($T('sit.titulo', ['pt' => 'Situação das licenças: válidas x em protocolo x vencidas', 'en' => 'License status: valid x renewal filed x expired', 'es' => 'Situación de las licencias: válidas x en protocolo x vencidas'])) . ' (' . $sitTotal . ')</h3><div class="admin-cards ' . (count($sitRot) > 4 ? 'dash-cards-6' : 'dash-cards-4') . '">';
            foreach ($sitRot as $s => [$rot, $ic, $tom])
                $h .= $cartao($e($rot), $sitCont[$s], $ic, $tom, $sitTotal > 0 ? number_format(100 * $sitCont[$s] / $sitTotal, 1, ',', '.') . '%' : '', '',
                    !$vendoOutro ? $abrir('minhas_licencas.php', ['SITUACAO_LICENCA' => ['value' => $s, 'exact' => true]], $tituloLic . ' - ' . $rot) : '');
            $h .= '</div></article>';
        }
        // Migração 367: a tabela traz todas (Vigentes e "Vencem em 61 a 90 dias" escondidas no padrão) para os cartões filtrarem
        $todasLic = array_values(array_filter($lic, static fn($l) => isset($faixasLic[(string)$l['FAIXA']]) || (string)$l['FAIXA'] === 'VIGENTE'));
        $atencao = array_values(array_filter($todasLic, static fn($l) => !in_array((string)$l['FAIXA'], ['VENCE_90', 'VIGENTE'], true)));
        $ordem = array_flip(['VENCIDA', 'PROT_SEM_PRAZO', 'ENTREGA_ATRASADA', 'VENCE_30', 'ENTREGA_30', 'EM_PROTOCOLO', 'VENCE_60', 'SEM_VALIDADE', 'VENCE_90', 'VIGENTE']);
        usort($todasLic, static fn($a, $b) => [$ordem[$a['FAIXA']] ?? 9, (int)$a['DIAS']] <=> [$ordem[$b['FAIXA']] ?? 9, (int)$b['DIAS']]);
        $faixasLic['VIGENTE'] = [$rotVig, 'bi-check-circle', 'green', 'success'];
        // Migração 368: grid padrão; abre filtrado nas que precisam de ação (Vigentes e 61–90 dias aparecem ao tirar o filtro ou pelo cartão)
        $rowsLic = [];
        foreach (array_slice($todasLic, 0, 5000) as $l) {
            $f = (string)$l['FAIXA'];
            $rowsLic[] = ['cells' => ['SITUACAO' => ['text' => $faixasLic[$f][0], 'html' => '<span class="badge text-bg-' . $faixasLic[$f][3] . '">' . $e($faixasLic[$f][0]) . '</span>'], 'UNIDADE' => (string)$l['UNIDADE'],
                'LICENCA' => ['text' => (string)$l['LICENCA'], 'title' => (string)$l['LICENCA']], 'TIPO' => (string)$l['TIPO_DOCUMENTAL'], 'VALIDADE' => DashboardGrid::prazo($data($l['DATA_VALIDADE'])),
                'DIAS' => DashboardGrid::numero($l['DIAS'] !== null ? (int)$l['DIAS'] : '', $l['DIAS'] !== null && (int)$l['DIAS'] < 0 ? 'status-danger' : '')],
                'open' => DashboardGrid::abrirDe($abrirLic((int)$l['CODLICENCA'], (string)$l['LICENCA'])),
                'grupos' => $f . (in_array($f, ['VENCE_90', 'VIGENTE'], true) ? '' : ' ACAO')];
        }
        $rotAcao = $T('lic.atencao', ['pt' => 'Precisam da minha ação', 'en' => 'Need my action', 'es' => 'Necesitan mi acción']);
        $h .= DashboardGrid::subabas('licencas', [['id' => 'acao', 'icone' => 'bi-exclamation-diamond', 'rotulo' => $T('lic.minhas_todas', ['pt' => 'Minhas licenças e entregas', 'en' => 'My licenses and submissions', 'es' => 'Mis licencias y entregas']), 'contagem' => count($atencao),
            'html' => DashboardGrid::grid('dash-lic-minhas', [
                'SITUACAO' => ['label' => $T('col.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']), 'filter' => 'select', 'width' => '200px'],
                'UNIDADE' => ['label' => $T('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), 'filter' => 'select', 'width' => '160px'],
                'LICENCA' => ['label' => $T('col.licenca', ['pt' => 'Licença / documento', 'en' => 'License / document', 'es' => 'Licencia / documento'])],
                'TIPO' => ['label' => $T('col.tipo_doc', ['pt' => 'Tipo documental', 'en' => 'Document type', 'es' => 'Tipo documental']), 'filter' => 'select', 'width' => '180px'],
                'VALIDADE' => ['label' => $T('col.validade', ['pt' => 'Validade / prazo', 'en' => 'Expiry / due', 'es' => 'Validez / plazo']), 'width' => '120px'],
                'DIAS' => ['label' => $T('col.dias', ['pt' => 'Dias', 'en' => 'Days', 'es' => 'Días']), 'width' => '70px'],
            ], $rowsLic, ['vazio' => $T('lic.nada', ['pt' => 'Nenhuma licença ou entrega sua precisando de ação.', 'en' => 'None of your licenses or submissions need action.', 'es' => 'Ninguna de sus licencias o entregas necesita acción.']), 'transacao' => 'dashboard', 'grupo_inicial' => ['ACAO', $rotAcao]])]]);
        exit($h);
    }

    // ======== Minhas tarefas
    if ($aba === 'tarefas') {
        $med = $minhasMedicoes(); $planos = $meusPlanos();
        $h = '<div class="admin-cards dash-cards-4">';
        if ($med !== null) {
            $mc = array_count_values(array_column($med, 'FAIXA'));
            $h .= $cartao($e($T('tar.medicoes', ['pt' => 'Minhas medições', 'en' => 'My measurements', 'es' => 'Mis mediciones'])), count($med), 'bi-activity', 'blue', (int)($mc['ATRASADA'] ?? 0) . ' ' . $e($T('tar.atrasadas', ['pt' => 'atrasadas', 'en' => 'overdue', 'es' => 'atrasadas'])) . ' · ' . (int)($mc['PROXIMA'] ?? 0) . ' ' . $e($T('tar.proximas', ['pt' => 'nos próximos 30 dias', 'en' => 'in the next 30 days', 'es' => 'en los próximos 30 días'])), '', $abrir('medicaoemonitoramento_mm_monitoramento.php', [], $tituloMed, ['minhas_pendencias' => 1]));
        }
        if ($planos !== null) $h .= $cartao($e($T('tar.planos', ['pt' => 'Meus planos de ação em aberto', 'en' => 'My open action plans', 'es' => 'Mis planes de acción abiertos'])), count($planos), 'bi-kanban', 'orange', count(array_filter($planos, static fn($p) => $p['ATRASADO'])) . ' ' . $e($T('tar.atrasados', ['pt' => 'atrasados', 'en' => 'overdue', 'es' => 'atrasados'])), '', $abrir('naoconformidades_minhasocorrencias.php', [], $T('titulo.nc', ['pt' => 'Minhas não conformidades', 'en' => 'My nonconformities', 'es' => 'Mis no conformidades'])));
        foreach ([[$podeTreinamentos, 'vw_minhas_treinamentos_grid', 'meus_treinamentos.php', $T('tar.treinamentos', ['pt' => 'Meus treinamentos', 'en' => 'My training', 'es' => 'Mis capacitaciones']), 'bi-person-video3'],
            [$podeInfra, 'vw_minhas_infraestrutura_grid', 'minhas_infraestruturas.php', $T('tar.infra', ['pt' => 'Minha infraestrutura', 'en' => 'My infrastructure', 'es' => 'Mi infraestructura']), 'bi-building-gear'],
            [$podeControles, 'vw_meus_controles_grid', 'meus_controles.php', $T('tar.controles', ['pt' => 'Meus controles', 'en' => 'My controls', 'es' => 'Mis controles']), 'bi-shield-check']] as [$ok, $view, $pagina, $rot, $ic]) {
            if (!$ok) continue;
            $n = $contar($view, ['CODUSUARIO' => $uid]);
            if ($n !== null) $h .= $cartao($e($rot), $n, $ic, 'green', '', '', $abrir($pagina, [], $rot));
        }
        $h .= '</div>';
        // Migração 368: grids padrão em sub-abas
        $subT = [];
        if ($med !== null) {
            $lista = array_values(array_filter($med, static fn($m) => $m['FAIXA'] !== 'EM_DIA'));
            $ordem = ['ATRASADA' => 0, 'PROXIMA' => 1, 'SEM_DATA' => 2];
            usort($lista, static fn($a, $b) => [$ordem[$a['FAIXA']] ?? 9, $a['REACAO'] ?? '9999'] <=> [$ordem[$b['FAIXA']] ?? 9, $b['REACAO'] ?? '9999']);
            $rowsMed = [];
            foreach ($lista as $m) $rowsMed[] = ['cells' => ['SITUACAO' => ['text' => $faixasMed[$m['FAIXA']][0], 'html' => '<span class="badge text-bg-' . $faixasMed[$m['FAIXA']][1] . '">' . $e($faixasMed[$m['FAIXA']][0]) . '</span>'],
                'UNIDADE' => (string)$m['UNIDADE'], 'MEDICAO' => ['text' => (string)$m['NOME'], 'title' => (string)$m['NOME']], 'ULTIMA' => DashboardGrid::prazo($m['ULTIMA'], false), 'REACAO' => DashboardGrid::prazo($m['REACAO']),
                'DIAS' => DashboardGrid::numero($m['DIAS'] ?? '')], 'open' => DashboardGrid::abrirDe($abrirMed($m['COD'], $m['NOME'])), 'grupos' => (string)$m['FAIXA']];
            $subT[] = ['id' => 'medicoes', 'icone' => 'bi-activity', 'rotulo' => $T('tar.med_titulo', ['pt' => 'Medições que precisam da minha ação', 'en' => 'Measurements needing my action', 'es' => 'Mediciones que necesitan mi acción']), 'contagem' => count($lista),
                'html' => DashboardGrid::grid('dash-medicoes', [
                    'SITUACAO' => ['label' => $T('col.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']), 'filter' => 'select', 'width' => '150px'],
                    'UNIDADE' => ['label' => $T('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), 'filter' => 'select', 'width' => '160px'],
                    'MEDICAO' => ['label' => $T('col.medicao', ['pt' => 'Medição / parâmetros', 'en' => 'Measurement / parameters', 'es' => 'Medición / parámetros'])],
                    'ULTIMA' => ['label' => $T('col.ultima', ['pt' => 'Última medição', 'en' => 'Last measurement', 'es' => 'Última medición']), 'width' => '120px'],
                    'REACAO' => ['label' => $T('col.reacao', ['pt' => 'Data de reação', 'en' => 'Reaction date', 'es' => 'Fecha de reacción']), 'width' => '120px'],
                    'DIAS' => ['label' => $T('col.dias', ['pt' => 'Dias', 'en' => 'Days', 'es' => 'Días']), 'width' => '70px'],
                ], $rowsMed, ['vazio' => $T('tar.med_nada', ['pt' => 'Todas as suas medições estão em dia.', 'en' => 'All your measurements are on schedule.', 'es' => 'Todas sus mediciones están al día.']), 'transacao' => 'dashboard'])];
        }
        if ($planos !== null) {
            $linkNc = DashboardGrid::abrirDe($abrir('naoconformidades_minhasocorrencias.php', [], $T('titulo.nc', ['pt' => 'Minhas não conformidades', 'en' => 'My nonconformities', 'es' => 'Mis no conformidades'])));
            $rowsPl = [];
            foreach ($planos as $p) $rowsPl[] = ['cells' => ['PRAZO' => DashboardGrid::prazo($p['FIM']), 'DIAS' => DashboardGrid::numero($p['DIAS'] ?? '', $p['ATRASADO'] ? 'status-danger' : ''),
                'ACAO' => ['text' => (string)$p['ACAO'], 'title' => (string)$p['ACAO']], 'OCORRENCIA' => ['text' => (string)$p['OCORRENCIA'], 'title' => (string)$p['OCORRENCIA']]], 'open' => $linkNc, 'grupos' => $p['ATRASADO'] ? 'ATRASADO' : 'NO_PRAZO'];
            $subT[] = ['id' => 'planos', 'icone' => 'bi-kanban', 'rotulo' => $T('tar.planos_titulo', ['pt' => 'Meus planos de ação em aberto', 'en' => 'My open action plans', 'es' => 'Mis planes de acción abiertos']), 'contagem' => count($planos),
                'html' => DashboardGrid::grid('dash-planos', [
                    'PRAZO' => ['label' => $T('col.prazo', ['pt' => 'Prazo', 'en' => 'Due', 'es' => 'Plazo']), 'width' => '120px'],
                    'DIAS' => ['label' => $T('col.dias', ['pt' => 'Dias', 'en' => 'Days', 'es' => 'Días']), 'width' => '70px'],
                    'ACAO' => ['label' => $T('col.acao', ['pt' => 'Ação', 'en' => 'Action', 'es' => 'Acción'])],
                    'OCORRENCIA' => ['label' => $T('col.ocorrencia', ['pt' => 'Ocorrência', 'en' => 'Occurrence', 'es' => 'Ocurrencia'])],
                ], $rowsPl, ['vazio' => $T('tar.planos_nada', ['pt' => 'Nenhum plano de ação em aberto.', 'en' => 'No open action plan.', 'es' => 'Ningún plan de acción abierto.']), 'transacao' => 'dashboard'])];
        }
        $h .= DashboardGrid::subabas('tarefas', $subT);
        if ($med === null && $planos === null && !$podeTreinamentos && !$podeInfra && !$podeControles) $h .= $semPermissao;
        exit($h);
    }
    // ======== Migração 366: Minhas não conformidades e Meus fornecedores
    if ($aba === 'nc') exit($podeNc ? (new DashboardNcFornecedores($pdo))->ncPessoal($uid) : $semPermissao);
    if ($aba === 'fornecedores') {
        if (!$podeFo) exit($semPermissao);
        $mp = new MinhasPendencias($pdo, $uid, false);
        $pendForn = array_values(array_filter($mp->itens(), static fn(array $i): bool => str_starts_with((string)$i['url'], 'homologacaofornecedor')));
        exit((new DashboardNcFornecedores($pdo))->fornecedoresPessoal($uid, $pendForn));
    }
    http_response_code(404); exit('');
}

// ---------------------------------------------------------------- página
$abas = ['geral' => ['bi-grid-1x2', $T('aba.geral', ['pt' => 'Visão geral', 'en' => 'Overview', 'es' => 'Visión general'])]];
if ($podeRequisitos) $abas['legal'] = ['bi-journal-check', $T('aba.legal', ['pt' => 'Meu atendimento legal', 'en' => 'My legal compliance', 'es' => 'Mi cumplimiento legal'])];
if ($podeLicencas) $abas['licencas'] = ['bi-file-earmark-text', $T('aba.licencas', ['pt' => 'Minhas licenças e entregas', 'en' => 'My licenses and submissions', 'es' => 'Mis licencias y entregas'])];
if ($podeMedicao || $podeNc || $podeTreinamentos || $podeInfra || $podeControles) $abas['tarefas'] = ['bi-list-check', $T('aba.tarefas', ['pt' => 'Minhas tarefas', 'en' => 'My tasks', 'es' => 'Mis tareas'])];
$dnf366 = new DashboardNcFornecedores($pdo);
if ($podeNc && $dnf366->sel('vw_dash_nc_resumo', [], 1) !== null) $abas['nc'] = ['bi-exclamation-diamond', $T('aba.nc', ['pt' => 'Minhas não conformidades', 'en' => 'My nonconformities', 'es' => 'Mis no conformidades'])];
if ($podeFo && $dnf366->sel('vw_dash_fornecedor_resumo', [], 1) !== null) $abas['fornecedores'] = ['bi-truck', $T('aba.fornecedores', ['pt' => 'Meus fornecedores', 'en' => 'My suppliers', 'es' => 'Mis proveedores'])];
$fonte = 'dashboard.php?' . http_build_query(array_filter(['home_usuario' => $homeUsuario ? 1 : null, 'usuario' => $vendoOutro ? $uid : null]));
?>
<!doctype html><html lang="<?= IdiomaService::idioma() === 'pt' ? 'pt-BR' : IdiomaService::idioma() ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $e($T('titulo', ['pt' => 'Meu dashboard', 'en' => 'My dashboard', 'es' => 'Mi panel'])) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2"><link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2"><link rel="stylesheet" href="../assets/css/app.css?v=3"><link rel="stylesheet" href="../assets/css/main.css?v=15">
<?php DashboardGrid::head(); ?><link rel="stylesheet" href="../assets/css/dashboard-abas.css?v=4"><style>.admin-card .dash-texto{font-size:15px;line-height:1.25;display:block}</style>
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?></head>
<body><main class="admin-dashboard" data-dash-fonte="<?= $e($fonte) ?>" data-dash-chave="<?= $vendoOutro ? 'greenportal.dashboard.pessoa.aba' : 'greenportal.dashboard.pessoal.aba' ?>" data-dash-textos="<?= $e(json_encode([
    'relatorio' => $T('legal.relatorio_titulo', ['pt' => 'Relatório de Atendimento Legal', 'en' => 'Legal Compliance Report', 'es' => 'Informe de Cumplimiento Legal']),
    'erro' => $T('legal.relatorio_erro', ['pt' => 'Não foi possível solicitar o relatório.', 'en' => 'The report could not be requested.', 'es' => 'No fue posible solicitar el informe.']),
    'erro_aba' => $T('erro_aba', ['pt' => 'Não foi possível carregar os indicadores desta aba.', 'en' => 'The indicators of this tab could not be loaded.', 'es' => 'No fue posible cargar los indicadores de esta pestaña.']),
    'situacao' => $T('legal.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']),
    'filtro' => $T('filtro', ['pt' => 'Mostrando', 'en' => 'Showing', 'es' => 'Mostrando']),
    'limpar_filtro' => $T('limpar_filtro', ['pt' => 'Mostrar como antes', 'en' => 'Show as before', 'es' => 'Mostrar como antes']),
], JSON_UNESCAPED_UNICODE)) ?>">
<header class="admin-head"><div><h2><i class="bi bi-person-workspace me-2"></i><?= $vendoOutro ? $e($T('titulo_de', ['pt' => 'Painel de', 'en' => 'Dashboard of', 'es' => 'Panel de']) . ' ' . $nomeUsuario) : $e($T('titulo', ['pt' => 'Meu dashboard', 'en' => 'My dashboard', 'es' => 'Mi panel'])) ?></h2><small><?= $vendoOutro ? $e($T('subtitulo_de', ['pt' => 'O que está sob a responsabilidade desta pessoa (visão do gestor)', 'en' => 'What is under this person\'s responsibility (manager view)', 'es' => 'Lo que está bajo la responsabilidad de esta persona (vista del gestor)'])) : $e($T('subtitulo', ['pt' => 'O que está sob a sua responsabilidade', 'en' => 'What is under your responsibility', 'es' => 'Lo que está bajo su responsabilidad'])) . ' · ' . $e($nomeUsuario) ?></small></div></header>
<ul class="nav dash-abas" role="tablist">
<?php foreach ($abas as $id => [$ic, $rot]): ?><li class="nav-item" role="presentation"><button class="nav-link<?= $id === 'geral' ? ' active' : '' ?>" type="button" role="tab" data-aba="<?= $id ?>"><i class="bi <?= $ic ?> me-1"></i><?= $e($rot) ?></button></li><?php endforeach; ?>
</ul>
<?php foreach (array_keys($abas) as $id): ?><section data-painel="<?= $id ?>"<?= $id === 'geral' ? '' : ' hidden' ?>><div class="dash-carregando"><span class="spinner-border spinner-border-sm me-2"></span><?= $e($T('carregando', ['pt' => 'Carregando indicadores...', 'en' => 'Loading indicators...', 'es' => 'Cargando indicadores...'])) ?></div></section><?php endforeach; ?>
</main>
<?php DashboardGrid::scripts(); ?>
<script src="../assets/js/dashboard-abas.js?v=6"></script>
</body></html>
