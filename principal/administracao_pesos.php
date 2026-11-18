<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/AdministracaoTextos.php';
/*
 * Administração › Pesos do ranking de fornecedores (menu ADMINISTRACAO_PESOS, migração 289).
 *   Pesos dos componentes da nota (soma 100), faixas A/B/C/D, penalidades das não conformidades e "Recalcular agora";
 *   bloqueio de fornecedores com motivo obrigatório (e desbloqueio, também com motivo) e os históricos.
 * Acesso: ADMINISTRADOR, ADM DA EMPRESA e GESTOR (migração 289) ou quem tiver a permissão no menu; alterar exige Editar.
 * Leitura só por views; gravação só por procedures (sp_fornecedor_ranking_config_salvar, sp_fornecedor_ranking_solicitar,
 * sp_fornecedor_bloquear, sp_fornecedor_desbloquear). O cálculo é feito só pelo serviço agendado (fornecedor-ranking-worker).
 * Migração 369: sub-abas (Configuração da nota | Bloqueio de fornecedores | Histórico de bloqueios | Histórico dos pesos) e as
 * listas no grid padrão (DashboardGrid / grid local), sem a página rolar com tudo empilhado.
 */
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/FornecedorRanking.php';
require_once dirname(__DIR__) . '/classes/DashboardGrid.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$pdo = TransactionSecurity::connect(FornecedorRanking::MENU_PESOS);
$permissao = PermissaoTransacao::obter(FornecedorRanking::MENU_PESOS);
$podeEditar = !empty($permissao['pode_editar']) || FornecedorRanking::perfil() === 'ADMINISTRADOR';
$T = static fn(string $chave, array $textos): string => IdiomaService::t('administracao_pesos.' . $chave, $textos);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
if (empty($_SESSION['administracao_pesos_csrf'])) $_SESSION['administracao_pesos_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['administracao_pesos_csrf'];
$usuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);

// ------------------------------------------------------------------ ações (JSON)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $responder = static function (bool $ok, string $mensagem) { echo json_encode(['ok' => $ok, 'message' => $mensagem], JSON_UNESCAPED_UNICODE); exit; };
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $responder(false, $T('msg.csrf', ['pt' => 'Token de segurança inválido. Recarregue a página.', 'en' => 'Invalid security token. Reload the page.', 'es' => 'Token de seguridad inválido. Recargue la página.']));
    if (!$podeEditar) $responder(false, $T('msg.sem_permissao', ['pt' => 'Seu perfil não pode alterar o ranking de fornecedores.', 'en' => 'Your profile cannot change the supplier ranking.', 'es' => 'Su perfil no puede cambiar el ranking de proveedores.']));
    $num = static fn(string $c): float => (float)str_replace(',', '.', (string)($_POST[$c] ?? '0'));
    try {
        switch ((string)($_POST['action'] ?? '')) {
            case 'salvar':
                $r = FornecedorRanking::chamar($pdo, 'sp_fornecedor_ranking_config_salvar', [$num('peso_doc'), $num('peso_func'), $num('peso_nc'), $num('peso_aud'),
                    $num('faixa_a'), $num('faixa_b'), $num('faixa_c'), $num('pen_aberta'), $num('pen_atrasada'), $num('pen_fechada'), (int)$num('janela'), $usuario]);
                $msg = ['OK' => ['pt' => 'Configuração salva. O serviço de ranking recalcula as notas em até 15 minutos.', 'en' => 'Settings saved. The ranking service recalculates the scores within 15 minutes.', 'es' => 'Configuración guardada. El servicio de ranking recalcula las notas en hasta 15 minutos.'],
                    'PESOS_SOMA' => ['pt' => 'A soma dos pesos precisa ser 100.', 'en' => 'The weights must add up to 100.', 'es' => 'La suma de los pesos debe ser 100.'],
                    'FAIXAS' => ['pt' => 'As faixas precisam ser A > B > C > 0 e A no máximo 100.', 'en' => 'Bands must be A > B > C > 0 with A at most 100.', 'es' => 'Las franjas deben ser A > B > C > 0 y A como máximo 100.'],
                    'VALORES' => ['pt' => 'Valores inválidos: pesos e penalidades de 0 a 100, janela de 1 a 60 meses.', 'en' => 'Invalid values: weights and penalties 0–100, window 1–60 months.', 'es' => 'Valores inválidos: pesos y penalidades de 0 a 100, ventana de 1 a 60 meses.']];
                $responder($r === 'OK', $T('msg.config_' . strtolower($r ?: 'erro'), $msg[$r] ?? ['pt' => 'Não foi possível salvar.', 'en' => 'Could not save.', 'es' => 'No fue posible guardar.']));
            case 'recalcular':
                $s = $pdo->prepare('CALL sp_fornecedor_ranking_solicitar()'); $s->execute(); $s->closeCursor();
                $responder(true, $T('msg.recalcular', ['pt' => 'Recálculo pedido. O serviço de ranking (Agendador do Windows) atualiza as notas em até 15 minutos.', 'en' => 'Recalculation requested. The ranking service (Windows Task Scheduler) updates the scores within 15 minutes.', 'es' => 'Recálculo solicitado. El servicio de ranking (Programador de tareas) actualiza las notas en hasta 15 minutos.']));
            case 'bloquear':
            case 'desbloquear':
                $bloquear = $_POST['action'] === 'bloquear';
                $r = FornecedorRanking::chamar($pdo, $bloquear ? 'sp_fornecedor_bloquear' : 'sp_fornecedor_desbloquear', [(int)($_POST['codfornecedor'] ?? 0), trim((string)($_POST['motivo'] ?? '')), $usuario]);
                $msg = ['OK' => $bloquear ? ['pt' => 'Fornecedor bloqueado.', 'en' => 'Supplier blocked.', 'es' => 'Proveedor bloqueado.'] : ['pt' => 'Fornecedor desbloqueado.', 'en' => 'Supplier unblocked.', 'es' => 'Proveedor desbloqueado.'],
                    'ATUALIZADO' => ['pt' => 'Motivo do bloqueio atualizado.', 'en' => 'Block reason updated.', 'es' => 'Motivo del bloqueo actualizado.'],
                    'MOTIVO' => ['pt' => 'Escreva o motivo (pelo menos 10 caracteres).', 'en' => 'Write the reason (at least 10 characters).', 'es' => 'Escriba el motivo (al menos 10 caracteres).'],
                    'NAO_ENCONTRADO' => ['pt' => 'Fornecedor não encontrado.', 'en' => 'Supplier not found.', 'es' => 'Proveedor no encontrado.'],
                    'NAO_BLOQUEADO' => ['pt' => 'Este fornecedor não está bloqueado.', 'en' => 'This supplier is not blocked.', 'es' => 'Este proveedor no está bloqueado.']];
                $responder(in_array($r, ['OK', 'ATUALIZADO'], true), $T('msg.bloq_' . strtolower($r ?: 'erro'), $msg[$r] ?? ['pt' => 'Não foi possível concluir.', 'en' => 'Could not complete.', 'es' => 'No fue posible concluir.']));
        }
    } catch (Throwable $erro) { error_log('administracao_pesos: ' . $erro->getMessage()); $responder(false, $T('msg.erro', ['pt' => 'Não foi possível concluir a operação.', 'en' => 'The operation could not be completed.', 'es' => 'No fue posible concluir la operación.'])); }
    $responder(false, $T('msg.acao', ['pt' => 'Ação inválida.', 'en' => 'Invalid action.', 'es' => 'Acción inválida.']));
}

$cfg = FornecedorRanking::ler($pdo, 'vw_fornecedor_ranking_config');
$cfg = $cfg[0] ?? null;
$fornecedores = FornecedorRanking::ler($pdo, 'vw_fornecedor_ranking') ?? [];
usort($fornecedores, static fn($a, $b) => [$b['BLOQUEADO'] === 'Sim', $a['RAZAOSOCIAL']] <=> [$a['BLOQUEADO'] === 'Sim', $b['RAZAOSOCIAL']]);
$histBloq = FornecedorRanking::ler($pdo, 'vw_fornecedor_bloqueios_historico') ?? [];
usort($histBloq, static fn($a, $b) => (int)$b['CODHISTORICO'] <=> (int)$a['CODHISTORICO']);
$histCfg = FornecedorRanking::ler($pdo, 'vw_fornecedor_ranking_config_historico') ?? [];
usort($histCfg, static fn($a, $b) => (int)$b['CODHISTORICO'] <=> (int)$a['CODHISTORICO']);
$data = static fn($v): string => $v ? date('d/m/Y H:i', strtotime((string)$v)) : '–';
$v = static fn(string $c): string => $cfg ? rtrim(rtrim(number_format((float)$cfg[$c], 2, '.', ''), '0'), '.') : '';
$bloqueados = count(array_filter($fornecedores, static fn($f) => $f['BLOQUEADO'] === 'Sim'));
$acao = ['BLOQUEADO' => ['Bloqueado', 'danger'], 'ALTERADO' => [adm_t('Motivo alterado'), 'warning'], 'DESBLOQUEADO' => ['Desbloqueado', 'success']];
?>
<!doctype html><html lang="<?= IdiomaService::idioma() === 'pt' ? 'pt-BR' : $e(IdiomaService::idioma()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $e($T('titulo', ['pt' => 'Pesos do ranking de fornecedores', 'en' => 'Supplier ranking weights', 'es' => 'Pesos del ranking de proveedores'])) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2"><link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2"><link rel="stylesheet" href="../assets/css/app.css?v=3"><link rel="stylesheet" href="../assets/css/main.css?v=15"><?php DashboardGrid::head(); ?>
<style>
body{background:var(--gp-surface)}.rp{padding:12px 16px;color:var(--gp-text);font-size:12.5px}.rp h2{font-size:16px;margin:0 0 2px}.rp .sub{font-size:11.5px;color:var(--gp-muted);margin-bottom:12px}
.rp-grade{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}.rp-card{background:#fff;border:1px solid var(--gp-border);border-radius:10px;padding:12px 14px}
.rp-card h3{font-size:13px;margin:0 0 8px;color:var(--gp-primary)}.rp-linha{display:flex;align-items:center;gap:8px;margin-bottom:6px}.rp-linha label{flex:1 1 auto;margin:0}
.rp-linha input{width:86px;text-align:right;height:auto!important;padding:4px 8px!important;font-size:12.5px}.rp-linha .un{width:52px;color:var(--gp-muted);font-size:11px}
.rp-soma{font-weight:600}.rp-soma.erro{color:#c0392b}.rp-nota{font-size:11px;color:var(--gp-muted);margin-top:6px;line-height:1.45}
.rp-faixa{display:inline-block;width:22px;height:22px;border-radius:5px;color:#fff;font-weight:700;text-align:center;line-height:22px;margin-right:4px}
.rp-acoes{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0 4px}.rp-status{font-size:11.5px;color:var(--gp-muted)}
.rp table{width:100%;font-size:12px;background:#fff;border:1px solid var(--gp-border);border-radius:8px}.rp th{color:var(--gp-muted);font-weight:600;background:#fff;position:sticky;top:0;z-index:1}
.rp td,.rp th{padding:6px 8px;border-bottom:1px solid var(--gp-border);vertical-align:top}.rp-rolagem{max-height:420px;overflow:auto}.rp .motivo{max-width:420px;white-space:pre-wrap}
.rp-filtros{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px}.rp-filtros input,.rp-filtros select{height:auto!important;padding:5px 9px!important;font-size:12px;max-width:320px}
tr.rp-bloqueado td{background:#fdf0ef}.rp details{margin-top:12px}.rp summary{color:var(--gp-primary);cursor:pointer}
.rp-dialogo{position:fixed;inset:0;background:rgba(20,30,25,.45);display:none;align-items:center;justify-content:center;z-index:50}.rp-dialogo.aberto{display:flex}
.rp-dialogo .caixa{background:#fff;border-radius:10px;width:min(560px,92vw);padding:16px 18px;box-shadow:0 10px 30px rgba(0,0,0,.25)}.rp-dialogo h4{font-size:14px;margin:0 0 4px}
.rp-dialogo textarea{width:100%;min-height:110px;font-size:12.5px}.rp-dialogo .conta{font-size:11px;color:var(--gp-muted)}
</style><?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?></head>
<body><main class="rp">
<h2><i class="bi bi-sliders me-1"></i><?= $e($T('titulo', ['pt' => 'Pesos do ranking de fornecedores', 'en' => 'Supplier ranking weights', 'es' => 'Pesos del ranking de proveedores'])) ?></h2>
<div class="sub"><?= $e($T('sub', ['pt' => 'Defina quanto cada item pesa na nota (0 a 100) dos fornecedores, as faixas A, B, C e D e quais fornecedores ficam bloqueados. A nota é recalculada pelo serviço agendado uma vez por dia e sempre que esta configuração muda.', 'en' => 'Set how much each item weighs in the supplier score (0 to 100), the A, B, C and D bands and which suppliers are blocked. The score is recalculated by the scheduled service once a day and whenever these settings change.', 'es' => 'Defina cuánto pesa cada ítem en la nota (0 a 100) de los proveedores, las franjas A, B, C y D y qué proveedores quedan bloqueados. El servicio programado recalcula la nota una vez al día y cada vez que cambia esta configuración.'])) ?></div>
<?php if ($cfg === null): ?><div class="alert alert-warning small"><?= $e($T('sem_migracao', ['pt' => 'Ranking de fornecedores ainda não instalado nesta empresa (rode a migração 289).', 'en' => 'Supplier ranking not installed for this company yet (run migration 289).', 'es' => 'Ranking de proveedores aún no instalado en esta empresa (ejecute la migración 289).'])) ?></div></main></body></html><?php exit; endif; ?>
<?php ob_start(); ?>
<form data-rp-form autocomplete="off">
<div class="rp-grade">
  <section class="rp-card"><h3><i class="bi bi-pie-chart me-1"></i><?= $e($T('pesos', ['pt' => 'Pesos da nota', 'en' => 'Score weights', 'es' => 'Pesos de la nota'])) ?></h3>
    <?php foreach ([['peso_doc', 'PESO_DOC_FORNECEDOR', ['pt' => 'Documentação do fornecedor', 'en' => 'Supplier documents', 'es' => 'Documentación del proveedor']],
        ['peso_func', 'PESO_DOC_FUNCIONARIOS', ['pt' => 'Documentação dos funcionários', 'en' => 'Employee documents', 'es' => 'Documentación de los empleados']],
        ['peso_nc', 'PESO_NC', ['pt' => 'Não conformidades', 'en' => 'Nonconformities', 'es' => 'No conformidades']],
        ['peso_aud', 'PESO_AUDITORIA', ['pt' => 'Auditorias', 'en' => 'Audits', 'es' => 'Auditorías']]] as [$campo, $col, $rot]): ?>
    <div class="rp-linha"><label for="<?= $campo ?>"><?= $e($T('campo.' . $campo, $rot)) ?></label><input class="form-control" type="number" min="0" max="100" step="0.5" id="<?= $campo ?>" name="<?= $campo ?>" value="<?= $e($v($col)) ?>" data-peso <?= $podeEditar ? '' : 'disabled' ?>><span class="un">%</span></div>
    <?php endforeach; ?>
    <div class="rp-linha"><span style="flex:1"><?= $e($T('soma', ['pt' => 'Soma (precisa ser 100)', 'en' => 'Total (must be 100)', 'es' => 'Suma (debe ser 100)'])) ?></span><span class="rp-soma" data-rp-soma>100</span><span class="un">%</span></div>
    <div class="rp-nota"><?= $e($T('nota_pesos', ['pt' => 'Se o fornecedor não tem um item (ex.: sem funcionários ou sem auditoria), o peso dele é dividido entre os outros. Sem documentos, funcionários e auditoria, fica "Sem dados".', 'en' => 'If a supplier lacks an item (e.g. no employees or no audit), its weight is split among the others. With no documents, employees or audit, it shows "No data".', 'es' => 'Si el proveedor no tiene un ítem (ej.: sin empleados o sin auditoría), su peso se divide entre los demás. Sin documentos, empleados ni auditoría, queda "Sin datos".'])) ?></div>
  </section>
  <section class="rp-card"><h3><i class="bi bi-bar-chart-steps me-1"></i><?= $e($T('faixas', ['pt' => 'Faixas', 'en' => 'Bands', 'es' => 'Franjas'])) ?></h3>
    <?php foreach ([['faixa_a', 'FAIXA_A', 'A'], ['faixa_b', 'FAIXA_B', 'B'], ['faixa_c', 'FAIXA_C', 'C']] as [$campo, $col, $f]): ?>
    <div class="rp-linha"><label for="<?= $campo ?>"><span class="rp-faixa" style="background:<?= FornecedorRanking::CORES[$f] ?>"><?= $f ?></span><?= $e($T('nota_minima', ['pt' => 'nota a partir de', 'en' => 'score from', 'es' => 'nota desde'])) ?></label><input class="form-control" type="number" min="1" max="100" step="0.5" id="<?= $campo ?>" name="<?= $campo ?>" value="<?= $e($v($col)) ?>" <?= $podeEditar ? '' : 'disabled' ?>><span class="un"></span></div>
    <?php endforeach; ?>
    <div class="rp-linha"><label><span class="rp-faixa" style="background:<?= FornecedorRanking::CORES['D'] ?>">D</span><?= $e($T('abaixo_c', ['pt' => 'abaixo da faixa C', 'en' => 'below band C', 'es' => 'debajo de la franja C'])) ?></label></div>
    <div class="rp-nota"><?= $e($T('nota_faixa_d', ['pt' => 'A faixa D é só informativa: o fornecedor só fica bloqueado quando alguém o bloqueia abaixo, com o motivo.', 'en' => 'Band D is informational only: a supplier is blocked only when someone blocks it below, with the reason.', 'es' => 'La franja D es solo informativa: el proveedor queda bloqueado solo cuando alguien lo bloquea abajo, con el motivo.'])) ?></div>
  </section>
  <section class="rp-card"><h3><i class="bi bi-exclamation-octagon me-1"></i><?= $e($T('ncs', ['pt' => 'Não conformidades (desconto na nota)', 'en' => 'Nonconformities (score deduction)', 'es' => 'No conformidades (descuento en la nota)'])) ?></h3>
    <?php foreach ([['pen_aberta', 'NC_PENALIDADE_ABERTA', ['pt' => 'Por NC aberta', 'en' => 'Per open NC', 'es' => 'Por NC abierta'], 'pts'],
        ['pen_atrasada', 'NC_PENALIDADE_ATRASADA', ['pt' => 'Mais, por NC aberta atrasada', 'en' => 'Plus, per overdue open NC', 'es' => 'Más, por NC abierta atrasada'], 'pts'],
        ['pen_fechada', 'NC_PENALIDADE_FECHADA', ['pt' => 'Por NC fechada na janela', 'en' => 'Per NC closed in the window', 'es' => 'Por NC cerrada en la ventana'], 'pts'],
        ['janela', 'NC_JANELA_MESES', ['pt' => 'Janela das NCs fechadas', 'en' => 'Closed NC window', 'es' => 'Ventana de NC cerradas'], 'meses']] as [$campo, $col, $rot, $un]): ?>
    <div class="rp-linha"><label for="<?= $campo ?>"><?= $e($T('campo.' . $campo, $rot)) ?></label><input class="form-control" type="number" min="<?= $campo === 'janela' ? 1 : 0 ?>" max="<?= $campo === 'janela' ? 60 : 100 ?>" step="<?= $campo === 'janela' ? 1 : 0.5 ?>" id="<?= $campo ?>" name="<?= $campo ?>" value="<?= $e($v($col)) ?>" <?= $podeEditar ? '' : 'disabled' ?>><span class="un"><?= $e($T('un.' . $un, $un === 'pts' ? ['pt' => 'pontos', 'en' => 'points', 'es' => 'puntos'] : ['pt' => 'meses', 'en' => 'months', 'es' => 'meses'])) ?></span></div>
    <?php endforeach; ?>
    <div class="rp-nota"><?= $e($T('nota_ncs', ['pt' => 'O componente parte de 100 e perde pontos pelas não conformidades do fornecedor. Atrasada: passou da conclusão prevista ou está aberta há mais de 90 dias.', 'en' => 'The component starts at 100 and loses points for the supplier nonconformities. Overdue: past the planned completion or open for more than 90 days.', 'es' => 'El componente parte de 100 y pierde puntos por las no conformidades del proveedor. Atrasada: pasó la conclusión prevista o está abierta hace más de 90 días.'])) ?></div>
  </section>
</div>
<div class="rp-acoes">
  <?php if ($podeEditar): ?><button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i><?= $e($T('salvar', ['pt' => 'Salvar configuração', 'en' => 'Save settings', 'es' => 'Guardar configuración'])) ?></button>
  <button type="button" class="btn btn-sm btn-outline-secondary" data-rp-recalcular><i class="bi bi-arrow-repeat me-1"></i><?= $e($T('recalcular', ['pt' => 'Recalcular agora', 'en' => 'Recalculate now', 'es' => 'Recalcular ahora'])) ?></button><?php endif; ?>
  <span class="rp-status"><?= $e($T('ultimo', ['pt' => 'Último cálculo', 'en' => 'Last calculation', 'es' => 'Último cálculo'])) ?>: <b><?= $e($data($cfg['ULTIMO_CALCULO'])) ?></b>
  <?php if ((int)$cfg['RECALCULAR'] === 1): ?> · <span class="text-warning-emphasis"><i class="bi bi-hourglass-split"></i> <?= $e($T('pendente', ['pt' => 'novo cálculo pedido (em até 15 minutos)', 'en' => 'new calculation requested (within 15 minutes)', 'es' => 'nuevo cálculo solicitado (en hasta 15 minutos)'])) ?></span><?php endif; ?>
  <?php if ($cfg['ALTERADO_EM']): ?> · <?= $e($T('alterado', ['pt' => 'alterado por', 'en' => 'changed by', 'es' => 'cambiado por'])) ?> <?= $e($cfg['ALTERADO_POR']) ?> <?= $e($T('em', ['pt' => 'em', 'en' => 'on', 'es' => 'el'])) ?> <?= $e($data($cfg['ALTERADO_EM'])) ?><?php endif; ?></span>
</div>
</form>
<?php $htmlConfig = (string)ob_get_clean();
// Bloqueio de fornecedores (grid padrão)
$semDadosTxt = $T('sem_dados', ['pt' => 'Sem dados', 'en' => 'No data', 'es' => 'Sin datos']);
$rotBloq = $T('bloqueado', ['pt' => 'Bloqueado', 'en' => 'Blocked', 'es' => 'Bloqueado']); $rotLib = $T('liberado', ['pt' => 'Liberado', 'en' => 'Allowed', 'es' => 'Liberado']);
$rows = [];
foreach ($fornecedores as $f) {
    $bloq = $f['BLOQUEADO'] === 'Sim'; $cod = (int)$f['CODFORNECEDOR']; $nome = (string)$f['RAZAOSOCIAL'];
    $botoes = '';
    if ($podeEditar) {
        $botoes = $bloq
            ? '<button type="button" class="btn btn-sm btn-outline-secondary py-0" data-rp-bloq="bloquear" data-cod="' . $cod . '" data-nome="' . $e($nome) . '" data-motivo="' . $e($f['MOTIVO_BLOQUEIO']) . '"><i class="bi bi-pencil"></i> ' . $e($T('alterar_motivo', ['pt' => 'Motivo', 'en' => 'Reason', 'es' => 'Motivo'])) . '</button> '
              . '<button type="button" class="btn btn-sm btn-outline-success py-0" data-rp-bloq="desbloquear" data-cod="' . $cod . '" data-nome="' . $e($nome) . '"><i class="bi bi-unlock"></i> ' . $e($T('desbloquear', ['pt' => 'Desbloquear', 'en' => 'Unblock', 'es' => 'Desbloquear'])) . '</button>'
            : '<button type="button" class="btn btn-sm btn-outline-danger py-0" data-rp-bloq="bloquear" data-cod="' . $cod . '" data-nome="' . $e($nome) . '"><i class="bi bi-lock"></i> ' . $e($T('bloquear', ['pt' => 'Bloquear', 'en' => 'Block', 'es' => 'Bloquear'])) . '</button>';
    }
    $fx = (string)($f['FAIXA'] ?? '');
    $rows[] = ['cells' => [
        'FORNECEDOR' => ['text' => $nome . ' ' . (string)$f['CNPJ'], 'html' => '<b>' . $e($nome) . '</b>' . (trim((string)$f['CNPJ']) !== '' ? ' <span class="text-muted small">' . $e($f['CNPJ']) . '</span>' : ''), 'title' => $nome],
        'UNIDADE' => (string)$f['UNIDADE'],
        'FAIXA' => ['text' => $fx !== '' ? $fx : $semDadosTxt, 'sort' => $fx !== '' ? $fx : 'Z', 'html' => $fx !== '' ? '<span class="rp-faixa" style="background:' . (FornecedorRanking::CORES[$fx] ?? '#888') . '">' . $e($fx) . '</span>' : '<span class="text-muted">' . $e($semDadosTxt) . '</span>'],
        'NOTA' => ['text' => $f['NOTA'] !== null ? FornecedorRanking::numero($f['NOTA']) : '', 'sort' => (string)($f['NOTA'] ?? -1)],
        'SITUACAO' => ['text' => $bloq ? $rotBloq : $rotLib, 'html' => $bloq ? '<span class="badge text-bg-danger">' . $e($rotBloq) . '</span>' : '<span class="text-muted">' . $e($rotLib) . '</span>'],
        'MOTIVO' => $bloq ? ['text' => (string)$f['MOTIVO_BLOQUEIO'] . ' · ' . $f['BLOQUEADO_POR'] . ' · ' . $data($f['BLOQUEADO_EM']), 'title' => (string)$f['MOTIVO_BLOQUEIO'],
            'html' => $e($f['MOTIVO_BLOQUEIO']) . ' <span class="text-muted small">(' . $e($f['BLOQUEADO_POR']) . ' · ' . $e($data($f['BLOQUEADO_EM'])) . ')</span>'] : '',
        'ACOES' => ['text' => '', 'html' => $botoes],
    ], 'attrs' => $bloq ? 'class="rp-bloqueado"' : ''];
}
$colunasBloq = [
    'FORNECEDOR' => ['label' => $T('col.fornecedor', ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor'])],
    'UNIDADE' => ['label' => $T('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), 'filter' => 'select', 'width' => '150px'],
    'FAIXA' => ['label' => $T('col.faixa', ['pt' => 'Faixa', 'en' => 'Band', 'es' => 'Franja']), 'filter' => 'select', 'width' => '80px'],
    'NOTA' => ['label' => $T('col.nota', ['pt' => 'Nota', 'en' => 'Score', 'es' => 'Nota']), 'filter' => 'none', 'width' => '70px'],
    'SITUACAO' => ['label' => $T('col.situacao_curta', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']), 'filter' => 'select', 'width' => '100px'],
    'MOTIVO' => ['label' => $T('col.motivo', ['pt' => 'Motivo', 'en' => 'Reason', 'es' => 'Motivo'])],
];
if ($podeEditar) $colunasBloq['ACOES'] = ['label' => '', 'filter' => 'none', 'width' => '190px'];
$gridBloq = '<div class="rp-nota mb-2">' . $e($T('nota_bloqueio', ['pt' => 'O fornecedor bloqueado aparece como Bloqueado, com o motivo, no ranking e no cadastro de fornecedores. Bloquear e desbloquear exigem o motivo, que fica no histórico.', 'en' => 'A blocked supplier shows as Blocked, with the reason, in the ranking and the supplier records. Blocking and unblocking require the reason, which is kept in the history.', 'es' => 'El proveedor bloqueado aparece como Bloqueado, con el motivo, en el ranking y en el registro de proveedores. Bloquear y desbloquear exigen el motivo, que queda en el historial.'])) . '</div>'
    . DashboardGrid::grid('rpFornecedores', $colunasBloq, $rows, ['transacao' => 'administracao_pesos',
        'vazio' => $T('nenhum', ['pt' => 'Nenhum fornecedor ativo.', 'en' => 'No active supplier.', 'es' => 'Ningún proveedor activo.'])]);
// Históricos
$dataSort = static fn($v): array => ['text' => $data($v), 'sort' => $v ? date('Y-m-d H:i:s', strtotime((string)$v)) : ''];
$rowsHb = [];
foreach (array_slice($histBloq, 0, 500) as $h) {
    [$rotA, $corA] = $acao[$h['ACAO']] ?? [$h['ACAO'], 'secondary'];
    $txtA = $T('acao.' . strtolower((string)$h['ACAO']), ['pt' => $rotA, 'en' => $rotA, 'es' => $rotA]);
    $rowsHb[] = ['cells' => ['EM' => $dataSort($h['EM']), 'ACAO' => ['text' => $txtA, 'html' => '<span class="badge text-bg-' . $corA . '">' . $e($txtA) . '</span>'],
        'FORNECEDOR' => (string)$h['RAZAOSOCIAL'], 'USUARIO' => (string)$h['USUARIO'], 'MOTIVO' => ['text' => (string)$h['MOTIVO'], 'title' => (string)$h['MOTIVO']]]];
}
$gridHb = $histBloq ? DashboardGrid::grid('rpHistBloq', [
    'EM' => ['label' => $T('col.em', ['pt' => 'Em', 'en' => 'On', 'es' => 'En']), 'filter' => 'none', 'width' => '130px'],
    'ACAO' => ['label' => $T('col.acao', ['pt' => 'Ação', 'en' => 'Action', 'es' => 'Acción']), 'filter' => 'select', 'width' => '130px'],
    'FORNECEDOR' => ['label' => $T('col.fornecedor', ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor'])],
    'USUARIO' => ['label' => $T('col.usuario', ['pt' => 'Usuário', 'en' => 'User', 'es' => 'Usuario']), 'filter' => 'select', 'width' => '160px'],
    'MOTIVO' => ['label' => $T('col.motivo', ['pt' => 'Motivo', 'en' => 'Reason', 'es' => 'Motivo'])],
], $rowsHb, ['transacao' => 'administracao_pesos', 'ordem' => ['EM', 'desc']]) : '';
$rowsHc = [];
foreach (array_slice($histCfg, 0, 200) as $h) $rowsHc[] = ['cells' => ['EM' => $dataSort($h['EM']), 'USUARIO' => (string)$h['USUARIO'],
    'ANTES' => ['text' => (string)$h['ANTES'], 'title' => (string)$h['ANTES']], 'DEPOIS' => ['text' => (string)$h['DEPOIS'], 'title' => (string)$h['DEPOIS']]]];
$gridHc = $histCfg ? DashboardGrid::grid('rpHistCfg', [
    'EM' => ['label' => $T('col.em', ['pt' => 'Em', 'en' => 'On', 'es' => 'En']), 'filter' => 'none', 'width' => '130px'],
    'USUARIO' => ['label' => $T('col.usuario', ['pt' => 'Usuário', 'en' => 'User', 'es' => 'Usuario']), 'filter' => 'select', 'width' => '160px'],
    'ANTES' => ['label' => $T('col.antes', ['pt' => 'Antes', 'en' => 'Before', 'es' => 'Antes'])],
    'DEPOIS' => ['label' => $T('col.depois', ['pt' => 'Depois', 'en' => 'After', 'es' => 'Después'])],
], $rowsHc, ['transacao' => 'administracao_pesos', 'ordem' => ['EM', 'desc']]) : '';
echo DashboardGrid::subabas('administracao-pesos', [
    ['id' => 'config', 'icone' => 'bi-pie-chart', 'rotulo' => $T('aba.config', ['pt' => 'Configuração da nota', 'en' => 'Score settings', 'es' => 'Configuración de la nota']), 'html' => $htmlConfig],
    ['id' => 'bloqueio', 'icone' => 'bi-slash-circle', 'rotulo' => $T('bloqueio', ['pt' => 'Bloqueio de fornecedores', 'en' => 'Supplier blocking', 'es' => 'Bloqueo de proveedores']), 'html' => $gridBloq, 'contagem' => $bloqueados],
    ['id' => 'hist-bloq', 'icone' => 'bi-clock-history', 'rotulo' => $T('hist_bloq', ['pt' => 'Histórico de bloqueios', 'en' => 'Block history', 'es' => 'Historial de bloqueos']), 'html' => $gridHb, 'contagem' => count($histBloq)],
    ['id' => 'hist-cfg', 'icone' => 'bi-clock-history', 'rotulo' => $T('hist_cfg', ['pt' => 'Histórico dos pesos', 'en' => 'Weight history', 'es' => 'Historial de los pesos']), 'html' => $gridHc, 'contagem' => count($histCfg)],
], true); ?>
</main>

<div class="rp-dialogo" data-rp-dialogo role="dialog" aria-modal="true"><div class="caixa">
<h4 data-rp-dialogo-titulo></h4><div class="text-muted small mb-2" data-rp-dialogo-nome></div>
<label class="form-label fw-semibold small mb-1" for="rpMotivo" data-rp-dialogo-rotulo></label>
<textarea id="rpMotivo" class="form-control" maxlength="2000" data-rp-motivo></textarea><div class="conta" data-rp-conta></div>
<div class="d-flex justify-content-end gap-2 mt-3"><button type="button" class="btn btn-sm btn-outline-secondary" data-rp-cancelar><?= $e($T('cancelar', ['pt' => 'Cancelar', 'en' => 'Cancel', 'es' => 'Cancelar'])) ?></button><button type="button" class="btn btn-sm" data-rp-confirmar></button></div>
</div></div>
<?php DashboardGrid::scripts(); ?>
<script>
(function () {
    'use strict';
    var CSRF = <?= json_encode($csrf) ?>;
    var T = <?= json_encode([
        'titulo' => $T('titulo', ['pt' => 'Pesos do ranking de fornecedores', 'en' => 'Supplier ranking weights', 'es' => 'Pesos del ranking de proveedores']),
        'erro' => $T('msg.erro', ['pt' => 'Não foi possível concluir a operação.', 'en' => 'The operation could not be completed.', 'es' => 'No fue posible concluir la operación.']),
        'soma' => $T('msg.config_pesos_soma', ['pt' => 'A soma dos pesos precisa ser 100.', 'en' => 'The weights must add up to 100.', 'es' => 'La suma de los pesos debe ser 100.']),
        'bloquear' => $T('dlg.bloquear', ['pt' => 'Bloquear fornecedor', 'en' => 'Block supplier', 'es' => 'Bloquear proveedor']),
        'alterar' => $T('dlg.alterar', ['pt' => 'Alterar motivo do bloqueio', 'en' => 'Change block reason', 'es' => 'Cambiar motivo del bloqueo']),
        'desbloquear' => $T('dlg.desbloquear', ['pt' => 'Desbloquear fornecedor', 'en' => 'Unblock supplier', 'es' => 'Desbloquear proveedor']),
        'motivo_bloq' => $T('dlg.motivo_bloq', ['pt' => 'Por que o fornecedor está bloqueado? (obrigatório)', 'en' => 'Why is the supplier blocked? (required)', 'es' => '¿Por qué el proveedor está bloqueado? (obligatorio)']),
        'motivo_desbloq' => $T('dlg.motivo_desbloq', ['pt' => 'Por que o fornecedor está sendo liberado? (obrigatório)', 'en' => 'Why is the supplier being unblocked? (required)', 'es' => '¿Por qué se libera al proveedor? (obligatorio)']),
        'minimo' => $T('dlg.minimo', ['pt' => 'mínimo de 10 caracteres', 'en' => 'at least 10 characters', 'es' => 'mínimo de 10 caracteres']),
        'recalcular' => $T('dlg.recalcular', ['pt' => 'Pedir agora um novo cálculo das notas? O serviço agendado recalcula em até 15 minutos.', 'en' => 'Request a new score calculation now? The scheduled service recalculates within 15 minutes.', 'es' => '¿Solicitar ahora un nuevo cálculo de las notas? El servicio programado recalcula en hasta 15 minutos.']),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    function host() { var w = window; for (var i = 0; i < 6 && w; i++) { try { if (w.GreenPortalPopups && w.jQuery) return w; } catch (e) { break; } if (w === w.parent) break; w = w.parent; } return null; }
    function mensagem(texto, ok, confirmar) {
        var h = host();
        if (h && h.GreenPortalPopups.mensagem) h.GreenPortalPopups.mensagem({ titulo: T.titulo, mensagem: texto, icone: ok ? 'bi-check-circle' : (confirmar ? 'bi-question-circle' : 'bi-exclamation-triangle'), confirmar: confirmar });
        else if (!confirmar || window.confirm(texto)) { if (confirmar) confirmar(); }
    }
    function enviar(dados, recarregar) {
        dados.csrf = CSRF;
        return fetch(window.location.pathname, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(dados) })
            .then(function (r) { return r.json(); })
            .then(function (r) { mensagem((r && r.message) || T.erro, r && r.ok); if (r && r.ok && recarregar) window.setTimeout(function () { window.location.reload(); }, 900); return r; })
            .catch(function () { mensagem(T.erro, false); });
    }
    // Soma dos pesos
    var form = document.querySelector('[data-rp-form]'), somaEl = document.querySelector('[data-rp-soma]');
    function soma() { var s = 0; document.querySelectorAll('[data-peso]').forEach(function (i) { s += parseFloat(String(i.value).replace(',', '.')) || 0; }); s = Math.round(s * 100) / 100; somaEl.textContent = String(s).replace('.', ','); somaEl.classList.toggle('erro', Math.abs(s - 100) > 0.01); return s; }
    soma();
    document.querySelectorAll('[data-peso]').forEach(function (i) { i.addEventListener('input', soma); });
    if (form) form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (Math.abs(soma() - 100) > 0.01) { mensagem(T.soma, false); return; }
        var dados = { action: 'salvar' };
        new FormData(form).forEach(function (v, k) { dados[k] = v; });
        enviar(dados, true);
    });
    var rec = document.querySelector('[data-rp-recalcular]');
    if (rec) rec.addEventListener('click', function () { mensagem(T.recalcular, false, function () { enviar({ action: 'recalcular' }, true); }); });
    // Bloquear / desbloquear com motivo
    var dlg = document.querySelector('[data-rp-dialogo]'), motivo = document.querySelector('[data-rp-motivo]'), conta = document.querySelector('[data-rp-conta]'),
        btnOk = document.querySelector('[data-rp-confirmar]'), atual = null;
    function contar() { var n = motivo.value.trim().length; conta.textContent = n + ' / 2000 · ' + T.minimo; btnOk.disabled = n < 10; }
    motivo.addEventListener('input', contar);
    document.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-rp-bloq]'); if (!b) return;
        var acao = b.getAttribute('data-rp-bloq'), alterar = acao === 'bloquear' && b.hasAttribute('data-motivo');
        atual = { acao: acao, cod: b.getAttribute('data-cod') };
        dlg.querySelector('[data-rp-dialogo-titulo]').textContent = acao === 'desbloquear' ? T.desbloquear : (alterar ? T.alterar : T.bloquear);
        dlg.querySelector('[data-rp-dialogo-nome]').textContent = b.getAttribute('data-nome');
        dlg.querySelector('[data-rp-dialogo-rotulo]').textContent = acao === 'desbloquear' ? T.motivo_desbloq : T.motivo_bloq;
        motivo.value = alterar ? b.getAttribute('data-motivo') : '';
        btnOk.className = 'btn btn-sm ' + (acao === 'desbloquear' ? 'btn-success' : 'btn-danger');
        btnOk.textContent = acao === 'desbloquear' ? T.desbloquear : (alterar ? T.alterar : T.bloquear);
        contar(); dlg.classList.add('aberto'); motivo.focus();
    });
    document.querySelector('[data-rp-cancelar]').addEventListener('click', function () { dlg.classList.remove('aberto'); });
    dlg.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') dlg.classList.remove('aberto'); });
    btnOk.addEventListener('click', function () {
        if (!atual || motivo.value.trim().length < 10) return;
        btnOk.disabled = true;
        enviar({ action: atual.acao, codfornecedor: atual.cod, motivo: motivo.value.trim() }, true).then(function () { dlg.classList.remove('aberto'); btnOk.disabled = false; });
    });
}());
</script>
</body></html>
