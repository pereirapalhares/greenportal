<?php
declare(strict_types=1);

/*
 * Home › Gestão de pendências (migração 365; GER-18.1 deletar, 18.2 não gerar e 18.3 limpar pendências, com log).
 * Para ADMINISTRADOR / ADM DA EMPRESA (todos os usuários) e gestores (a equipe: usuarios.codusuario_superior, todos os níveis).
 * Escolhe a pessoa, vê as pendências dela (as mesmas de Home › Minhas pendências, inclusive as já escondidas) e:
 *  - Ocultar as selecionadas (1 = 18.1; várias = 18.3 limpar em lote) — voltam se o prazo mudar;
 *  - Limpar todas as filtradas (18.3);
 *  - Não gerar mais para o registro (18.2) ou para a pessoa até uma data (18.2);
 *  - Reativar (aba Ocultas e desligadas) e consultar o Log.
 * Motivo obrigatório, log (pendencias_gestao_log) e alerta ao responsável (classes/PendenciasGestao.php).
 */
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$sair = static function (int $c, string $m): never { http_response_code($c); exit(htmlspecialchars($m, ENT_QUOTES, 'UTF-8')); };
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $sair(401, IdiomaService::t('home.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.']));
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/HomologacaoFornecedorAcesso.php';
require_once dirname(__DIR__) . '/classes/MinhasPendencias.php';
require_once dirname(__DIR__) . '/classes/PendenciasGestao.php';
require_once dirname(__DIR__) . '/classes/DashboardGrid.php';
$pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), (string)$_SESSION['tenant']['banco_dados'], 'GPT');
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $sair(401, 'Sessão expirada.');
$idioma = IdiomaService::idioma();
$t = static fn(string $c, array $p): string => IdiomaService::t('gestao_pendencias.' . $c, $p);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$autor = (int)($_SESSION['usuario']['codusuario'] ?? 0);
if (!PendenciasGestao::disponivel($pdo)) $sair(503, $t('sem_migracao', ['pt' => 'A Gestão de pendências ainda não foi ativada nesta empresa (migração 365).', 'en' => 'Pending items management is not active for this company yet (migration 365).', 'es' => 'La Gestión de pendientes aún no fue activada en esta empresa (migración 365).']));
$escopo = PendenciasGestao::escopo($pdo, $autor);
$adm = PendenciasGestao::perfilAdm();
if (!$adm && count($escopo) < 2) $sair(403, $t('negado', ['pt' => 'A Gestão de pendências é para administradores e gestores com equipe (usuários com você como superior).', 'en' => 'Pending items management is for administrators and managers with a team.', 'es' => 'La Gestión de pendientes es para administradores y gestores con equipo.']));
if (empty($_SESSION['gp365_csrf'])) $_SESSION['gp365_csrf'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['gp365_csrf'];

$dono = (int)($_REQUEST['dono'] ?? 0);
if (!isset($escopo[$dono])) $dono = 0;
// c572 (16/11/2026): o combo mostra só quem tem alguma pendência (inclusive ocultadas), com a quantidade. A contagem lê cada
// view uma vez para todos (MinhasPendencias::contarPorUsuario, < 1 s na Crown — c573) e fica 3 minutos na sessão
// (o link "Atualizar lista" refaz).
$chaveCont = 'gp373_contagem_' . md5(implode(',', array_keys($escopo)));
$cont = $_SESSION[$chaveCont] ?? null;
if (!is_array($cont) || ($cont['em'] ?? 0) < time() - 180 || isset($_GET['recontar'])) {
    $cont = ['em' => time(), 'n' => MinhasPendencias::contarPorUsuario($pdo, array_keys($escopo))];
    $_SESSION[$chaveCont] = $cont;
}
$comPendencia = array_intersect_key($escopo, $cont['n']);
$aba = in_array($_GET['aba'] ?? '', ['pendencias', 'regras', 'log'], true) ? (string)$_GET['aba'] : 'pendencias';
$msg = mb_substr(trim((string)($_GET['ok'] ?? '')), 0, 300); $erro = '';

// ---------------------------------------------------------------- pendências da pessoa (todas, com a regra que esconde)
$itens = [];
$carregarItens = static function () use ($pdo, $dono): array {
    if ($dono <= 0) return [];
    $mp = new MinhasPendencias($pdo, $dono, false);
    $lista = [];
    foreach ($mp->itens(true) as $it) $lista[PendenciasGestao::chave($it)] = $it;
    return $lista;
};

// ---------------------------------------------------------------- ações (POST)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão da tela expirada: recarregue a página.');
        $acao = (string)($_POST['acao'] ?? '');
        $motivo = (string)($_POST['motivo'] ?? '');
        if ($acao === 'reativar') {
            $cod = (int)($_POST['codgestao'] ?? 0);
            $r = PendenciasGestao::ler($pdo, 'vw_pendencias_gestao_grid', ['CODGESTAO' => ['value' => (string)$cod, 'exact' => true]], 1)[0] ?? null;
            $donoRegra = (int)($r['CODUSUARIO_DONO'] ?? 0);
            if (!$r || (!$adm && !($donoRegra > 0 ? isset($escopo[$donoRegra]) : isset($escopo[(int)$r['CODUSUARIO_AUTOR']])))) throw new RuntimeException('Esta regra não é da sua equipe.');
            PendenciasGestao::reativar($pdo, $autor, $cod, $motivo);
            $msg = 'Reativado. As pendências voltam a aparecer.';
            $aba = 'regras';
        } else {
            if ($dono <= 0) throw new RuntimeException('Escolha a pessoa.');
            $itens = $carregarItens();
            $sel = array_values(array_filter(array_map('strval', (array)json_decode((string)($_POST['itens'] ?? '[]'), true)), static fn($c) => isset($itens[$c])));
            if ($acao === 'ocultar') {
                if (!$sel) throw new RuntimeException('Selecione ao menos uma pendência.');
                $n = PendenciasGestao::ocultar($pdo, $autor, $dono, array_map(static fn($c) => $itens[$c], $sel), $motivo);
                $msg = $n ? ($n === 1 ? '1 pendência ocultada.' : $n . ' pendências ocultadas (limpeza em lote).') : 'As pendências selecionadas já estavam escondidas.';
            } elseif ($acao === 'registro') {
                if (count($sel) !== 1) throw new RuntimeException('Selecione uma pendência só para desligar o registro dela.');
                $msg = PendenciasGestao::naoGerarRegistro($pdo, $autor, $dono, $itens[$sel[0]], $motivo) ? 'O registro não gera mais pendências (para ninguém), até ser reativado.' : 'Este registro já estava desligado.';
            } elseif ($acao === 'usuario') {
                $ate = trim((string)($_POST['ate'] ?? '')) ?: null;
                $msg = PendenciasGestao::naoGerarUsuario($pdo, $autor, $dono, $ate, $motivo) ? 'As pendências de ' . $escopo[$dono] . ' foram desligadas' . ($ate ? ' até ' . date('d/m/Y', strtotime($ate)) : ' até serem reativadas') . '.' : 'As pendências desta pessoa já estavam desligadas.';
            } else throw new RuntimeException('Ação inválida.');
        }
        PendenciasGestao::limparCache();
        header('Location: gestao_pendencias.php?' . http_build_query(['dono' => $dono ?: null, 'aba' => $aba, 'ok' => $msg]));
        exit;
    } catch (Throwable $ex) {
        $erro = $ex instanceof RuntimeException ? $ex->getMessage() : 'Não foi possível concluir. Avise o administrador.';
        if (!($ex instanceof RuntimeException)) error_log('gestao_pendencias: ' . $ex->getMessage());
    }
    PendenciasGestao::limparCache();
}
$itens = $carregarItens();

// situação de cada pendência
$linhas = [];
foreach ($itens as $chave => $it) {
    $r = PendenciasGestao::regraDo($pdo, $it, $dono);
    $sit = $r === null ? 'visivel' : ['OCULTAR' => 'oculta', 'NAO_GERAR_REGISTRO' => 'registro', 'NAO_GERAR_USUARIO' => 'usuario'][$r['TIPO']] ?? 'oculta';
    [$rt, $rc] = PendenciasGestao::registroDeUrl((string)$it['url']);
    $linhas[] = ['chave' => $chave, 'pilar' => (string)$it['pilar'], 'titulo' => (string)$it['titulo'], 'motivo' => (string)$it['motivo'],
        'prazo' => MinhasPendencias::formatar($it['prazo']), 'sinal' => MinhasPendencias::sinal($it['prazo']), 'sit' => $sit, 'regra' => $r, 'temRegistro' => $rt !== '' && $rc !== ''];
}
$pilares = array_values(array_unique(array_column($linhas, 'pilar'))); sort($pilares);
$usuarioDesligado = PendenciasGestao::regras($pdo)['usuario'][$dono] ?? null;

// regras e log do escopo
$naEquipe = static fn(array $r): bool => $adm || ((int)($r['CODUSUARIO_DONO'] ?? 0) > 0 ? isset($escopo[(int)$r['CODUSUARIO_DONO']]) : isset($escopo[(int)($r['CODUSUARIO_AUTOR'] ?? 0)]));
$regras = array_values(array_filter(PendenciasGestao::ler($pdo, 'vw_pendencias_gestao_grid', [], 3), $naEquipe));
usort($regras, static fn($a, $b) => [(int)$b['EM_VIGOR'], (string)$b['CRIADO_EM']] <=> [(int)$a['EM_VIGOR'], (string)$a['CRIADO_EM']]);
$log = array_values(array_filter(PendenciasGestao::ler($pdo, 'vw_pendencias_gestao_log', [], 3), $naEquipe));
usort($log, static fn($a, $b) => (int)$b['CODLOG'] <=> (int)$a['CODLOG']);
$log = array_slice($log, 0, 500);

$rotSit = ['visivel' => 'Visível', 'oculta' => 'Oculta', 'registro' => 'Registro sem pendências', 'usuario' => 'Pessoa desligada'];
$rotTipo = ['OCULTAR' => 'Oculta', 'NAO_GERAR_REGISTRO' => 'Registro sem pendências', 'NAO_GERAR_USUARIO' => 'Pessoa desligada'];
$rotAcao = ['OCULTAR' => 'Ocultou', 'LIMPAR' => 'Limpou (lote)', 'NAO_GERAR_REGISTRO' => 'Desligou o registro', 'NAO_GERAR_USUARIO' => 'Desligou a pessoa', 'REATIVAR' => 'Reativou'];
$dt = static fn(?string $d): string => $d ? (strtotime($d) ? date(strlen($d) > 10 ? 'd/m/Y H:i' : 'd/m/Y', strtotime($d)) : '') : '';
// c574 (16/11/2026): os três quadros no grid padrão (grid local: barra de ícones, Buscar no grid, filtro e ordenação por coluna,
// paginação e rolagem dentro do grid). Pendências: abre só com as visíveis (filtro removível); marcar na coluna da direita.
$rowsPend = [];
foreach ($linhas as $l) {
    $esc = $l['sit'] !== 'visivel';
    $rotulo = $esc ? $rotSit[$l['sit']] : ($l['sinal'] === 'vencido' ? 'Vencida' : 'Visível');
    $dica = $esc ? ($l['regra']['MOTIVO'] ?? '') . ' — ' . PendenciasGestao::nome($pdo, (int)($l['regra']['CODUSUARIO_AUTOR'] ?? 0)) : '';
    $rowsPend[] = ['cells' => [
        'SITUACAO' => ['text' => $rotulo, 'title' => $dica, 'html' => '<span class="gp-sit ' . $e($esc ? $l['sit'] : $l['sinal']) . '">' . $e($rotulo) . '</span>'],
        'PILAR' => $l['pilar'],
        'PENDENCIA' => ['text' => $l['titulo'], 'title' => $l['titulo']],
        'MOTIVO' => ['text' => $l['motivo'], 'title' => $l['motivo']],
        'PRAZO' => ['text' => $l['prazo'], 'html' => greenPortalGridLocalPrazo($l['prazo'], $esc ? '' : $l['sinal']), 'sort' => ($itens[$l['chave']]['prazo'] ?? null) instanceof DateTimeInterface ? $itens[$l['chave']]['prazo']->format('Y-m-d') : '9999-12-31'],
    ], 'actions' => $esc ? '' : '<input class="form-check-input" type="checkbox" data-grid-marcar aria-label="Marcar">',
       'grupos' => $esc ? 'ESCONDIDA' : 'VISIVEL', 'attrs' => 'data-chave="' . $e($l['chave']) . '" data-registro="' . ($l['temRegistro'] ? '1' : '0') . '" data-sit="' . ($esc ? 'esc' : 'visivel') . '"' . ($esc ? ' class="gp-esc"' : '')];
}
$botoesPend = '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-gp-acao="ocultar" title="Ocultar as pendências marcadas (voltam se o prazo mudar)" aria-label="Ocultar selecionadas"><i class="bi bi-eye-slash"></i></button>'
    . '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-gp-acao="limpar" title="Limpar todas as filtradas: ocultar todas as pendências visíveis com a busca e os filtros atuais" aria-label="Limpar todas as filtradas"><i class="bi bi-eraser"></i></button>'
    . '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-gp-acao="registro" title="Não gerar para o registro: o registro da pendência marcada não gera mais pendências para ninguém" aria-label="Não gerar para o registro"><i class="bi bi-bell-slash"></i></button>'
    . '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-gp-acao="usuario" title="Não gerar para a pessoa: nada gera pendências para esta pessoa até a data escolhida"' . ($usuarioDesligado ? ' disabled' : '') . ' aria-label="Não gerar para a pessoa"><i class="bi bi-person-slash"></i></button>';
ob_start();
if ($dono > 0) greenPortalGridLocal([
    'transaction' => 'gestao_pendencias', 'id' => 'gpGrid', 'fragment_class' => 'gp-grid', 'page_size' => 50,
    'columns' => [
        'SITUACAO' => ['label' => 'Situação', 'filter' => 'select', 'width' => '150px'],
        'PILAR' => ['label' => 'Pilar', 'filter' => 'select', 'width' => '170px'],
        'PENDENCIA' => ['label' => 'Pendência'],
        'MOTIVO' => ['label' => 'O que fazer'],
        'PRAZO' => ['label' => 'Prazo', 'filter' => 'none', 'width' => '105px'],
    ],
    'rows' => array_map(static fn($r) => ['attrs' => trim('data-grupo="' . $r['grupos'] . '" ' . $r['attrs'])] + $r, $rowsPend),
    'actions_label' => 'Marcar as linhas desta página', 'select_all' => true, 'extras' => $botoesPend,
    'buttons' => ['reload' => true, 'columns' => true], 'empty' => 'Nenhuma pendência para ' . ($escopo[$dono] ?? '') . '.',
    'default_sort' => ['PRAZO', 'asc'], 'initial_group' => ['VISIVEL', 'Visíveis'],
]);
$gridPend = (string)ob_get_clean();
$rowsReg = [];
foreach ($regras as $r) {
    $vigor = (int)$r['EM_VIGOR'] === 1;
    $sitR = $vigor ? 'Em vigor' . ($r['ATE'] ? ' até ' . $dt($r['ATE']) : '') : ((int)$r['ATIVO'] === 1 ? 'Venceu' : 'Reativada ' . $dt($r['REATIVADO_EM']));
    $pend = ($r['PILAR'] ? $r['PILAR'] . ' — ' : '') . $r['TITULO'];
    $rowsReg[] = ['cells' => [
        'SITUACAO' => ['text' => $sitR, 'html' => '<span class="gp-sit ' . ($vigor ? '' : 'r0') . '">' . $e($sitR) . '</span>'],
        'TIPO' => $rotTipo[$r['TIPO']] ?? $r['TIPO'],
        'PESSOA' => $r['TIPO'] === 'NAO_GERAR_REGISTRO' ? 'Todos' : (string)$r['DONO'],
        'PENDENCIA' => ['text' => $pend . ($r['ASSINATURA'] ? ' (prazo ' . $dt($r['ASSINATURA']) . ')' : ''), 'title' => $pend, 'html' => $e($pend) . ($r['ASSINATURA'] ? ' <span class="small text-muted">prazo ' . $e($dt($r['ASSINATURA'])) . '</span>' : '')],
        'MOTIVO' => ['text' => (string)$r['MOTIVO'], 'title' => (string)$r['MOTIVO']],
        'POR' => (string)$r['AUTOR'],
        'EM' => ['text' => $dt($r['CRIADO_EM']), 'sort' => (string)$r['CRIADO_EM']],
        'ACOES' => ['text' => '', 'html' => (int)$r['ATIVO'] === 1 ? '<button type="button" class="btn btn-sm btn-outline-primary py-0" data-gp-reativar="' . (int)$r['CODGESTAO'] . '" title="Volta a mostrar / gerar as pendências"><i class="bi bi-arrow-counterclockwise me-1"></i>Reativar</button>' : ''],
    ], 'grupos' => $vigor ? 'VIGOR' : ''];
}
$emVigor = count(array_filter($regras, static fn($r) => (int)$r['EM_VIGOR'] === 1));
$gridReg = DashboardGrid::grid('gpRegras', [
    'SITUACAO' => ['label' => 'Situação', 'filter' => 'select', 'width' => '150px'], 'TIPO' => ['label' => 'Tipo', 'filter' => 'select', 'width' => '160px'],
    'PESSOA' => ['label' => 'Pessoa', 'filter' => 'select', 'width' => '160px'], 'PENDENCIA' => ['label' => 'Pendência / registro'], 'MOTIVO' => ['label' => 'Motivo'],
    'POR' => ['label' => 'Por', 'filter' => 'select', 'width' => '140px'], 'EM' => ['label' => 'Em', 'filter' => 'none', 'width' => '120px'],
    'ACOES' => ['label' => '', 'filter' => 'none', 'width' => '105px'],
], $rowsReg, ['transacao' => 'gestao_pendencias', 'ordem' => ['EM', 'desc'], 'por_pagina' => 50, 'vazio' => 'Nenhuma pendência ocultada ou desligada.',
    'grupo_inicial' => $emVigor > 0 ? ['VIGOR', 'Em vigor'] : []]);
$rowsLog = [];
foreach ($log as $l) $rowsLog[] = ['cells' => [
    'QUANDO' => ['text' => $dt($l['CRIADO_EM']), 'sort' => (string)$l['CRIADO_EM']], 'QUEM' => (string)$l['AUTOR'], 'ACAO' => $rotAcao[$l['ACAO']] ?? $l['ACAO'],
    'DONO' => (string)($l['DONO'] ?: ((int)$l['CODUSUARIO_DONO'] ? '#' . (int)$l['CODUSUARIO_DONO'] : 'Todos')),
    'QTD' => ['text' => (string)(int)$l['QTD'], 'sort' => (string)(int)$l['QTD']],
    'MOTIVO' => ['text' => (string)$l['MOTIVO'], 'title' => (string)$l['MOTIVO']],
    'DETALHE' => ['text' => mb_strimwidth((string)$l['DETALHE'], 0, 600, '…'), 'title' => mb_strimwidth((string)$l['DETALHE'], 0, 1500, '…')],
]];
$gridLog = DashboardGrid::grid('gpLog', [
    'QUANDO' => ['label' => 'Quando', 'filter' => 'none', 'width' => '120px'], 'QUEM' => ['label' => 'Quem', 'filter' => 'select', 'width' => '150px'],
    'ACAO' => ['label' => 'Ação', 'filter' => 'select', 'width' => '150px'], 'DONO' => ['label' => 'De quem', 'filter' => 'select', 'width' => '150px'],
    'QTD' => ['label' => 'Qtd.', 'filter' => 'none', 'width' => '60px'], 'MOTIVO' => ['label' => 'Motivo'], 'DETALHE' => ['label' => 'Detalhe'],
], $rowsLog, ['transacao' => 'gestao_pendencias', 'ordem' => ['QUANDO', 'desc'], 'por_pagina' => 50, 'vazio' => 'Nada registrado ainda.']);
$theme = in_array((string)($_SESSION['layout_tema'] ?? ''), ['verde', 'azul', 'cinza'], true) ? (string)$_SESSION['layout_tema'] : 'verde';
session_write_close();
?><!doctype html><html lang="<?= $idioma === 'pt' ? 'pt-BR' : $e($idioma) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gestão de pendências</title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><?php DashboardGrid::head(); ?>
<style>
/* app.css (login) deixa todo .form-control com 49px e recuo de 45px: aqui volta ao tamanho do Bootstrap */
.form-control { height: auto; padding: .25rem .5rem; }
textarea.form-control { min-height: 4.5rem; }
body{background:#fff;color:var(--gp-text);font-size:13px}.gp{padding:14px 16px}
.gp-top{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px}.gp-top .form-select{width:auto;min-width:280px}
.gp-desc{color:var(--gp-muted);max-width:1000px;margin-bottom:10px}
.gp-barra{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:8px 0}.gp-barra .form-select,.gp-barra .form-control{width:auto;min-width:170px}
.gp-tab{width:100%;border-collapse:collapse}.gp-tab th{position:sticky;top:0;background:var(--gp-surface,#f6f8f7);font-weight:600;font-size:12px;border-bottom:1px solid var(--gp-border);padding:7px 8px;text-align:left}
.gp-tab td{border-bottom:1px solid var(--gp-border);padding:6px 8px;vertical-align:top}.gp-tab tr.esc td{color:var(--gp-muted);background:#fafbfa}
.gp-sit{display:inline-block;border-radius:10px;padding:1px 8px;font-size:11px;white-space:nowrap;background:#dff2e7;color:#226b40}
.gp-sit.oculta,.gp-sit.registro,.gp-sit.usuario,.gp-sit.r0{background:#eceff1;color:#56636b}.gp-sit.vencido{background:#fde4e1;color:#a5281b}.gp-sit.a-vencer{background:#fff1d1;color:#8a5a00}
.gp-vazio{color:var(--gp-muted);padding:18px 4px}.gp-motivo{max-width:360px;white-space:pre-line}
/* c574: grids com rolagem própria (cabeçalho fixo) dentro da área da tela */
html,body{height:auto}.gp .menu-grid-scroll{max-height:calc(100vh - 290px);min-height:160px;overflow:auto}.gp #gp-regras .menu-grid-scroll,.gp #gp-log .menu-grid-scroll{max-height:calc(100vh - 230px)}
.gp-grid tr.gp-esc td{color:var(--gp-muted);background:#fafbfa}
</style></head>
<body class="theme-<?= $e($theme) ?>" data-transacao="gestao_pendencias"><main class="gp">
<div class="d-flex align-items-center gap-2 mb-1"><h5 class="mb-0"><i class="bi bi-ui-checks-grid me-2"></i>Gestão de pendências</h5>
  <button type="button" class="btn btn-sm btn-link p-0 ms-1" data-open-ajuda title="Ajuda desta tela (F1)"><i class="bi bi-question-circle me-1"></i>Ajuda</button></div>
<div class="gp-desc small">Escolha a pessoa para ver as pendências dela (as mesmas de Minhas pendências, do sino e da Agenda). Você pode <b>ocultar</b> uma ou várias — voltam se o prazo mudar —, <b>desligar um registro</b> (não gera pendência para ninguém) ou <b>desligar a pessoa</b> até uma data. O registro no módulo não muda. Motivo obrigatório; tudo fica no Log e o responsável recebe um alerta. <b>A lista de pessoas mostra somente os usuários que têm alguma pendência</b> (contando também as ocultadas), com a quantidade entre parênteses.</div>
<?php if ($msg): ?><div class="alert alert-success py-2 small"><?= $e($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-warning py-2 small"><?= $e($erro) ?></div><?php endif; ?>

<ul class="nav nav-tabs" role="tablist">
  <?php foreach (['pendencias' => 'Pendências da pessoa', 'regras' => 'Ocultas e desligadas (' . count(array_filter($regras, static fn($r) => (int)$r['EM_VIGOR'] === 1)) . ')', 'log' => 'Log'] as $k => $rot): ?>
  <li class="nav-item"><button class="nav-link<?= $aba === $k ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#gp-<?= $k ?>" type="button"><?= $e($rot) ?></button></li>
  <?php endforeach; ?>
</ul>
<div class="tab-content pt-2">
<!-- pendências -->
<section class="tab-pane fade<?= $aba === 'pendencias' ? ' show active' : '' ?>" id="gp-pendencias">
  <form method="get" class="gp-top">
    <label class="small text-muted" for="gpDono">Pessoa</label>
    <select class="form-select form-select-sm" id="gpDono" name="dono" onchange="this.form.submit()">
      <option value="">— escolha —</option>
      <?php foreach ($comPendencia + ($dono > 0 && !isset($comPendencia[$dono]) ? [$dono => $escopo[$dono]] : []) as $id => $nome): ?><option value="<?= (int)$id ?>"<?= $id === $dono ? ' selected' : '' ?>><?= $e($nome . ($id === $autor ? ' (você)' : '') . ' (' . (int)($cont['n'][$id] ?? 0) . ')') ?></option><?php endforeach; ?>
    </select>
    <span class="small text-muted"><?= count($comPendencia) ?> com pendência de <?= $adm ? count($escopo) . ' usuário(s) ativos' : count($escopo) . ' pessoa(s) da sua equipe' ?> · contagem <?= ($m = intdiv(time() - (int)$cont['em'], 60)) < 1 ? 'agora' : 'há ' . $m . ' min' ?></span>
    <a class="small" href="gestao_pendencias.php?<?= $e(http_build_query(['dono' => $dono ?: null, 'recontar' => 1])) ?>" title="Contar de novo as pendências de cada pessoa"><i class="bi bi-arrow-clockwise"></i> Atualizar lista</a>
  </form>
  <?php if ($dono <= 0): ?><div class="gp-vazio">Escolha a pessoa para ver as pendências.</div>
  <?php else: ?>
  <?php if ($usuarioDesligado): ?><div class="alert alert-secondary py-2 small"><i class="bi bi-pause-circle me-1"></i>As pendências de <?= $e($escopo[$dono]) ?> estão desligadas <?= $usuarioDesligado['ATE'] ? 'até ' . $e($dt($usuarioDesligado['ATE'])) : 'até serem reativadas' ?> (por <?= $e(PendenciasGestao::nome($pdo, (int)$usuarioDesligado['CODUSUARIO_AUTOR'])) ?>: <?= $e($usuarioDesligado['MOTIVO']) ?>). Para reativar, use a aba Ocultas e desligadas.</div><?php endif; ?>
  <small class="text-danger d-block mb-1" id="gpAviso" role="status"></small>
  <?= $gridPend ?>
  <?php endif; ?>
</section>

<!-- regras -->
<section class="tab-pane fade<?= $aba === 'regras' ? ' show active' : '' ?>" id="gp-regras">
  <?= $gridReg ?>
</section>

<!-- log -->
<section class="tab-pane fade<?= $aba === 'log' ? ' show active' : '' ?>" id="gp-log">
  <?= $gridLog ?>
</section>
</div>

<!-- janela do motivo -->
<div class="modal fade" id="gpModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content" id="gpForm">
  <div class="modal-header py-2"><h6 class="modal-title" id="gpModalTitulo">Motivo</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="dono" value="<?= (int)$dono ?>">
    <input type="hidden" name="acao" id="gpAcao"><input type="hidden" name="itens" id="gpItens"><input type="hidden" name="codgestao" id="gpCod">
    <p class="small" id="gpModalTexto"></p>
    <div class="mb-2" id="gpAteBox" hidden><label class="form-label" for="gpAte">Voltar a gerar depois de (opcional)</label><input type="date" class="form-control form-control-sm" name="ate" id="gpAte" min="<?= date('Y-m-d') ?>"></div>
    <label class="form-label" for="gpMotivo">Motivo (obrigatório; vai para o Log e para o aviso ao responsável)</label>
    <textarea class="form-control form-control-sm" name="motivo" id="gpMotivo" rows="3" required minlength="5" maxlength="1000"></textarea>
  </div>
  <div class="modal-footer py-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check2 me-1"></i>Confirmar</button></div>
</form></div></div>

</main>
<?php DashboardGrid::scripts(); ?>
<script>
(function () {
  // c574: ações sobre o grid padrão (gpGrid). Marcadas = caixas da coluna da direita nas linhas filtradas (todas as páginas);
  // "Limpar todas as filtradas" = linhas visíveis que passam na busca e nos filtros do grid, de todas as páginas.
  var grid = document.querySelector('.gp-grid'), nome = <?= json_encode($dono > 0 ? ($escopo[$dono] ?? '') : '', JSON_UNESCAPED_UNICODE) ?>;
  function avisar(t) { var a = document.getElementById('gpAviso'); if (a) { a.textContent = t; setTimeout(function () { if (a.textContent === t) a.textContent = ''; }, 5000); } }
  function filtradas() { return grid && typeof grid.gridLocalFiltradas === 'function' ? grid.gridLocalFiltradas() : []; }
  function texto(tr, col) { var c = tr.querySelector('td[data-column="' + col + '"]'); return c ? c.textContent.trim() : ''; }
  var modal = window.bootstrap ? new bootstrap.Modal(document.getElementById('gpModal')) : null;
  function abrir(acao, chaves, titulo, txt) {
    document.getElementById('gpAcao').value = acao; document.getElementById('gpItens').value = JSON.stringify(chaves || []);
    document.getElementById('gpModalTitulo').textContent = titulo; document.getElementById('gpModalTexto').textContent = txt;
    document.getElementById('gpAteBox').hidden = acao !== 'usuario'; document.getElementById('gpMotivo').value = '';
    if (modal) modal.show();
  }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-gp-acao]');
    if (b) {
      ev.preventDefault();
      var acao = b.getAttribute('data-gp-acao'), lista = filtradas(),
          marcadas = lista.filter(function (tr) { var c = tr.querySelector('[data-grid-marcar]'); return c && c.checked; });
      if (acao === 'ocultar') {
        if (!marcadas.length) return avisar('Marque (coluna da direita) as pendências a ocultar.');
        return abrir('ocultar', marcadas.map(function (tr) { return tr.dataset.chave; }), marcadas.length === 1 ? 'Ocultar pendência' : 'Limpar ' + marcadas.length + ' pendências',
          marcadas.length + ' pendência(s) de ' + nome + ' vão sumir de Minhas pendências, do sino e da Agenda. Voltam se o prazo mudar.');
      }
      if (acao === 'limpar') {
        var vis = lista.filter(function (tr) { return tr.dataset.sit === 'visivel'; });
        if (!vis.length) return avisar('Não há pendências visíveis com estes filtros.');
        return abrir('ocultar', vis.map(function (tr) { return tr.dataset.chave; }), 'Limpar ' + vis.length + ' pendências',
          'Todas as ' + vis.length + ' pendências visíveis com a busca e os filtros atuais de ' + nome + ' (em todas as páginas do grid) vão ser ocultadas, sem serem resolvidas. Voltam se o prazo mudar.');
      }
      if (acao === 'registro') {
        if (marcadas.length !== 1) return avisar('Marque uma pendência só (coluna da direita).');
        if (marcadas[0].dataset.registro !== '1') return avisar('Esta pendência não tem um registro identificável; use Ocultar.');
        return abrir('registro', [marcadas[0].dataset.chave], 'Não gerar pendências para o registro',
          'O registro de "' + texto(marcadas[0], 'PENDENCIA') + '" não vai mais gerar pendências para ninguém, até ser reativado. O registro no módulo não muda.');
      }
      if (acao === 'usuario') return abrir('usuario', [], 'Não gerar pendências para ' + nome, 'Nada vai gerar pendências para ' + nome + ' até a data escolhida (ou até ser reativado). Use, por exemplo, em férias ou afastamento.');
      return;
    }
    var r = ev.target.closest('[data-gp-reativar]');
    if (r) { abrir('reativar', [], 'Reativar', 'As pendências voltam a aparecer (e a ser geradas) para quem é responsável.'); document.getElementById('gpCod').value = r.getAttribute('data-gp-reativar'); }
  });
}());
</script></body></html>
