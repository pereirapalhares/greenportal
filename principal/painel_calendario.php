<?php
declare(strict_types=1);

/*
 * Calendário de pendências (ícone "Pendências do dia" da barra lateral e botão Calendário de Próximos prazos).
 * Visões Dia, Mês e Ano; cada pendência fica no dia em que vence e abre a transação em popup (90% da tela, sempre na frente).
 * Dados: vw_painel_calendario (migração 290) via classes/PainelLateralService.php::calendario() — o que está sob a
 * responsabilidade do usuário: requisitos, licenças (vencimento, entrega periódica e prazo de reação), medições, planos de
 * ação de NC, tarefas, responsabilidades, avaliações de fornecedor e eventos da agenda.
 * ?dados=1&ano=AAAA devolve os itens do ano (JSON); a tela carrega cada ano uma vez.
 * Quem aparece: ADM DA EMPRESA vê todos os usuários da empresa; os demais veem as próprias pendências e as dos
 * subordinados em todos os níveis (usuarios.codusuario_superior) — PainelLateralService::equipe(). O número do ícone da
 * barra lateral continua contando só as do próprio usuário.
 * Parâmetros de abertura: ?visao=dia|mes|ano&data=AAAA-MM-DD (padrão: mês de hoje, com o dia de hoje selecionado).
 */

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$json = isset($_GET['dados']);
if (empty($_SESSION['autenticado'])) {
    if ($json) { http_response_code(401); exit; }
    header('Location: ../index.php?erro=sessao_expirada'); exit;
}
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PainelLateralService.php';

try { $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION); } catch (Throwable) { http_response_code(503); exit($json ? '' : 'Banco de dados indisponível.'); }
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) {
    if ($json) { http_response_code(401); exit; }
    header('Location: ../index.php?erro=sessao_expirada'); exit;
}
$idioma = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';
$uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);

if ($json) {
    $ano = max(2000, min(2100, (int)($_GET['ano'] ?? date('Y'))));
    $itens = (new PainelLateralService($pdo, $uid))->calendario($ano, $idioma, true);
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'ano' => $ano, 'itens' => $itens], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
$equipe = (new PainelLateralService($pdo, $uid))->equipe();
session_write_close();

$L = static fn(string $pt, string $en, string $es): string => ['pt' => $pt, 'en' => $en, 'es' => $es][$idioma];
$descricao = (count($equipe) <= 1 ? $L('O que está sob a sua responsabilidade, no dia em que vence.', 'What is under your responsibility, on its due date.', 'Lo que está bajo su responsabilidad, en el día en que vence.')
    : (PainelLateralService::admEmpresa() ? $L('Pendências de todos os usuários da empresa, no dia em que vencem.', 'Pending items of all company users, on their due date.', 'Pendientes de todos los usuarios de la empresa, en el día en que vencen.')
    : $L('As suas pendências e as da sua equipe, no dia em que vencem.', "Your pending items and your team's, on their due date.", 'Sus pendientes y los de su equipo, en el día en que vencen.')))
    . ' ' . $L('Clique numa pendência para abrir a transação.', 'Click an item to open its screen.', 'Haga clic en un pendiente para abrir la transacción.');
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$visao = in_array((string)($_GET['visao'] ?? ''), ['dia', 'mes', 'ano'], true) ? (string)$_GET['visao'] : 'mes';
$data = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['data'] ?? '')) ? (string)$_GET['data'] : date('Y-m-d');
$tipos = [];
foreach (PainelLateralService::TIPOS_PRAZO as $k => [$rot, $ic]) $tipos[$k] = ['rotulo' => $rot[$idioma] ?? $rot['pt'], 'icone' => $ic];
$T = [
    'eu' => $uid, 'equipe' => count($equipe) > 1, 'idioma' => $idioma, 'hoje' => date('Y-m-d'), 'visao' => $visao, 'data' => $data, 'tipos' => $tipos,
    'meses' => ['pt' => ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'],
                'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
                'es' => ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']][$idioma],
    'semana' => ['pt' => ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'], 'en' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], 'es' => ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb']][$idioma],
    'semanaLonga' => ['pt' => ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'],
                      'en' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
                      'es' => ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado']][$idioma],
    'txt' => [
        'ATRASADO' => $L('Atrasado', 'Overdue', 'Atrasado'), 'HOJE' => $L('Vence hoje', 'Due today', 'Vence hoy'), 'PROXIMO' => $L('No prazo', 'On schedule', 'En plazo'),
        'mais' => $L('+%d mais', '+%d more', '+%d más'), 'nenhum' => $L('Nenhuma pendência neste dia.', 'No pending items on this day.', 'Ningún pendiente en este día.'),
        'pendencias' => $L('%d pendência(s)', '%d pending item(s)', '%d pendiente(s)'), 'carregando' => $L('Carregando…', 'Loading…', 'Cargando…'),
        'erro' => $L('Não foi possível carregar o calendário.', 'Could not load the calendar.', 'No fue posible cargar el calendario.'),
        'responsavel' => $L('Responsável', 'Responsible', 'Responsable'),
        'semLink' => $L('Sem tela para abrir ou sem permissão.', 'No screen to open or no permission.', 'Sin pantalla para abrir o sin permiso.'),
        'pendenciasDe' => $L('Pendências de %s', 'Pending items on %s', 'Pendientes del %s'), 'verDia' => $L('Ver o dia', 'Open day', 'Ver el día'),
        'semAno' => $L('Nenhuma pendência neste ano.', 'No pending items this year.', 'Ningún pendiente en este año.'),
        'foraJanela' => $L('O calendário mostra os prazos de 3 anos para trás até 3 anos para a frente.', 'The calendar shows deadlines from 3 years back to 3 years ahead.', 'El calendario muestra los plazos de 3 años atrás hasta 3 años adelante.'),
    ],
];
?>
<!doctype html>
<html lang="<?= $e($idioma) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($L('Calendário de pendências', 'Pending items calendar', 'Calendario de pendientes')) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<link href="../assets/css/app.css" rel="stylesheet"><link href="../assets/css/main.css?v=15" rel="stylesheet"><link href="../assets/css/painel-lateral.css?v=3" rel="stylesheet">
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?></head>
<body><main class="dashboard-content painel-lista cal-pagina">
<section class="dashboard-panel">
<div class="panel-title"><div><h3><?= $e($L('Calendário de pendências', 'Pending items calendar', 'Calendario de pendientes')) ?></h3>
<p><?= $e($descricao) ?></p></div>
<a class="btn btn-sm btn-outline-secondary" href="painel_prazos.php"><i class="bi bi-list-ul me-1"></i><?= $e($L('Lista de prazos', 'Deadline list', 'Lista de plazos')) ?></a></div>

<div class="cal-barra">
  <div class="btn-group btn-group-sm" role="group">
    <button type="button" class="btn btn-outline-secondary" data-cal-nav="-1" title="<?= $e($L('Anterior', 'Previous', 'Anterior')) ?>"><i class="bi bi-chevron-left"></i></button>
    <button type="button" class="btn btn-outline-secondary" data-cal-nav="0"><?= $e($L('Hoje', 'Today', 'Hoy')) ?></button>
    <button type="button" class="btn btn-outline-secondary" data-cal-nav="1" title="<?= $e($L('Próximo', 'Next', 'Siguiente')) ?>"><i class="bi bi-chevron-right"></i></button>
  </div>
  <strong class="cal-titulo" id="calTitulo"></strong>
  <select class="form-select form-select-sm cal-filtro" id="calFiltro" aria-label="<?= $e($L('Tipo', 'Type', 'Tipo')) ?>">
    <option value=""><?= $e($L('Todos os tipos', 'All types', 'Todos los tipos')) ?></option>
    <?php foreach ($tipos as $k => $t): ?><option value="<?= $e($k) ?>"><?= $e($t['rotulo']) ?></option><?php endforeach; ?>
  </select>
  <?php if (count($equipe) > 1): ?><select class="form-select form-select-sm cal-filtro" id="calPessoa" aria-label="<?= $e($L('Responsável', 'Responsible', 'Responsable')) ?>">
    <option value=""><?= $e(PainelLateralService::admEmpresa() ? $L('Todos os usuários', 'All users', 'Todos los usuarios') : $L('Eu e minha equipe', 'Me and my team', 'Yo y mi equipo')) ?> (<?= count($equipe) ?>)</option>
    <option value="<?= $uid ?>"><?= $e($L('Só as minhas', 'Only mine', 'Solo las mías')) ?></option>
    <?php foreach ($equipe as $id => $nome): if ($id === $uid) continue; ?><option value="<?= (int)$id ?>"><?= $e($nome) ?></option><?php endforeach; ?>
  </select><?php endif; ?>
  <div class="btn-group btn-group-sm" role="group" aria-label="<?= $e($L('Visão', 'View', 'Vista')) ?>">
    <button type="button" class="btn btn-outline-success" data-cal-visao="dia"><?= $e($L('Dia', 'Day', 'Día')) ?></button>
    <button type="button" class="btn btn-outline-success" data-cal-visao="mes"><?= $e($L('Mês', 'Month', 'Mes')) ?></button>
    <button type="button" class="btn btn-outline-success" data-cal-visao="ano"><?= $e($L('Ano', 'Year', 'Año')) ?></button>
  </div>
</div>
<div class="cal-legenda"><span class="cal-pt atrasado"></span><?= $e($T['txt']['ATRASADO']) ?><span class="cal-pt hoje"></span><?= $e($T['txt']['HOJE']) ?><span class="cal-pt proximo"></span><?= $e($T['txt']['PROXIMO']) ?><small class="ms-auto text-muted"><?= $e($T['txt']['foraJanela']) ?></small></div>
<div id="calCorpo" class="cal-corpo" aria-live="polite"></div>
</section></main>
<script>window.GP_CALENDARIO = <?= json_encode($T, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;</script>
<script src="../assets/js/painel-lateral.js?v=2"></script>
<script src="../assets/js/painel-calendario.js?v=2"></script>
</body></html>
