<?php
declare(strict_types=1);
/*
 * Módulo Legal › Potenciais de gestão por IA. Migração 370 (16/11/2026): sub-abas Potenciais sugeridos | Solicitações, as duas
 * listas no grid padrão (DashboardGrid / grid local); Aprovar e Rejeitar na barra do grid (linha selecionada, só Sugerido),
 * com a confirmação do modal; sem diálogo nativo do navegador.
 */

require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/DashboardGrid.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
$env = [];
foreach (file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line); if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2); $env[trim($key)] = trim(trim($value), "\"'");
}
$dsn = static fn(string $database): string => 'mysql:host=' . ($env['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['GPT_DB_PORT'] ?? '3306') . ';dbname=' . $database . ';charset=utf8mb4';
$database = (string)($_SESSION['tenant']['banco_dados'] ?? ($env['GPT_DB_DATABASE'] ?? 'gmodelo_dbp'));
$pdo = new PDO($dsn($database), $env['GPT_DB_USERNAME'] ?? '', $env['GPT_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
$permission = PermissaoTransacao::obter('MODULOLEGAL_POTENCIAIS');
if (!$permission['pode_visualizar']) { http_response_code(403); exit('Acesso nao autorizado.'); }
$uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$idioma = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt','en','es'], true) ? (string)$_SESSION['idioma'] : 'pt';
$json = static fn(mixed $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$call = static function (PDO $connection, string $sql, array $params = []): array { $st = $connection->prepare($sql); $st->execute($params); $rows = $st->fetchAll(PDO::FETCH_ASSOC); $st->closeCursor(); return $rows; };
$labels = [
    'pt' => ['title'=>'Potenciais de gestão por IA','request'=>'Solicitar análise','legal'=>'Requisito legal','requests'=>'Solicitações','potentials'=>'Potenciais sugeridos','status'=>'Status','requested'=>'Solicitada em','started'=>'Iniciada em','finished'=>'Concluída em','type'=>'Tipo','title_field'=>'Título','confidence'=>'Confiança','content_language'=>'Idioma','approve'=>'Aprovar','reject'=>'Rejeitar','reload'=>'Atualizar','select'=>'Selecione um requisito legal','no_rows'=>'Nenhum registro encontrado.','question_approve'=>'Deseja aprovar o potencial selecionado?','question_reject'=>'Deseja rejeitar o potencial selecionado?','confirm'=>'Confirmar','cancel'=>'Cancelar','required'=>'Selecione um requisito legal antes de solicitar a análise.','done'=>'Operação realizada com sucesso.'],
    'en' => ['title'=>'AI management potentials','request'=>'Request analysis','legal'=>'Legal requirement','requests'=>'Requests','potentials'=>'Suggested potentials','status'=>'Status','requested'=>'Requested at','started'=>'Started at','finished'=>'Completed at','type'=>'Type','title_field'=>'Title','confidence'=>'Confidence','content_language'=>'Language','approve'=>'Approve','reject'=>'Reject','reload'=>'Refresh','select'=>'Select a legal requirement','no_rows'=>'No records found.','question_approve'=>'Do you want to approve the selected potential?','question_reject'=>'Do you want to reject the selected potential?','confirm'=>'Confirm','cancel'=>'Cancel','required'=>'Select a legal requirement before requesting analysis.','done'=>'Operation completed successfully.'],
    'es' => ['title'=>'Potenciales de gestión por IA','request'=>'Solicitar análisis','legal'=>'Requisito legal','requests'=>'Solicitudes','potentials'=>'Potenciales sugeridos','status'=>'Estado','requested'=>'Solicitada en','started'=>'Iniciada en','finished'=>'Concluida en','type'=>'Tipo','title_field'=>'Título','confidence'=>'Confianza','content_language'=>'Idioma','approve'=>'Aprobar','reject'=>'Rechazar','reload'=>'Actualizar','select'=>'Seleccione un requisito legal','no_rows'=>'No se encontraron registros.','question_approve'=>'¿Desea aprobar el potencial seleccionado?','question_reject'=>'¿Desea rechazar el potencial seleccionado?','confirm'=>'Confirmar','cancel'=>'Cancelar','required'=>'Seleccione un requisito legal antes de solicitar el análisis.','done'=>'Operación realizada con éxito.'],
][$idioma];
$message = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'request' && $permission['pode_incluir']) {
            $legalId = (int)($_POST['codlegislacao'] ?? 0);
            if (!$legalId) throw new RuntimeException($labels['required']);
            $legalRows = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela'=>'vw_modulolegal_requisitos_grid','filtros'=>$json(['CODLEGISLACAO'=>$legalId])]);
            if (!$legalRows) throw new RuntimeException($labels['required']);
            $call($pdo, 'CALL sp_ia_potencial_solicitar(:usuario,:idioma,:contexto)', ['usuario'=>$uid,'idioma'=>$idioma,'contexto'=>$json($legalRows[0])]);
            $message = $labels['done'];
        } elseif (in_array($action, ['approve','reject'], true) && $permission['pode_editar']) {
            $id = (int)($_POST['codpotencial'] ?? 0); if (!$id) throw new RuntimeException($labels['no_rows']);
            $call($pdo, 'CALL sp_ia_potencial_validar(:potencial,:usuario,:status)', ['potencial'=>$id,'usuario'=>$uid,'status'=>$action === 'approve' ? 'APROVADO' : 'REJEITADO']);
            $message = $labels['done'];
        }
    } catch (Throwable $exception) { $error = $exception->getMessage(); }
}
$legalRows = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela'=>'vw_modulolegal_requisitos_grid','filtros'=>$json([])]);
$requestRows = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela'=>'vw_ml_potenciais_solicitacoes_grid','filtros'=>$json([])]);
$potentialRows = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela'=>'vw_ml_potenciais_grid','filtros'=>$json([])]);
$e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$date = static function (mixed $value) use ($idioma): string { if (!$value) return ''; try { return (new DateTime((string)$value))->format($idioma === 'en' ? 'm/d/Y H:i' : 'd/m/Y H:i'); } catch (Throwable) { return (string)$value; } };
$status = static function (mixed $value) use ($idioma): string { $map=['PENDENTE'=>['pt'=>'Pendente','en'=>'Pending','es'=>'Pendiente'],'PROCESSANDO'=>['pt'=>'Processando','en'=>'Processing','es'=>'Procesando'],'CONCLUIDA'=>['pt'=>'Concluída','en'=>'Completed','es'=>'Concluida'],'ERRO'=>['pt'=>'Erro','en'=>'Error','es'=>'Error'],'SUGERIDO'=>['pt'=>'Sugerido','en'=>'Suggested','es'=>'Sugerido'],'APROVADO'=>['pt'=>'Aprovado','en'=>'Approved','es'=>'Aprobado'],'REJEITADO'=>['pt'=>'Rejeitado','en'=>'Rejected','es'=>'Rechazado']]; $key=(string)$value; return $map[$key][$idioma] ?? $key; };
$dataSort = static fn(mixed $v): array => ['text' => $date($v), 'sort' => $v ? (string)$v : ''];
// Potenciais sugeridos
$rowsPot = []; $sugeridos = 0;
foreach ($potentialRows as $row) {
    $sug = ($row['status'] ?? '') === 'SUGERIDO'; if ($sug) $sugeridos++;
    $id = (int)($row['codpotencial'] ?? 0);
    $acoes = $sug && $permission['pode_editar'] ? greenPortalGridLocalLinha('<button type="button" data-linha-acao="aprovar" data-validar="approve" data-pot="' . $id . '"></button><button type="button" data-linha-acao="rejeitar" data-validar="reject" data-pot="' . $id . '"></button>') : '';
    $rowsPot[] = ['cells' => [
        'COD' => ['text' => (string)$id, 'sort' => (string)$id, 'html' => $id . $acoes],
        'LEGAL' => (string)($row['codrequisitolegal'] ?? ''),
        'TIPO' => (string)($row['tipo_potencial'] ?? ''),
        'TITULO' => ['text' => (string)($row['titulo'] ?? ''), 'title' => (string)($row['descricao'] ?? $row['titulo'] ?? '')],
        'CONF' => (string)($row['confianca_ia'] ?? ''),
        'IDIOMA' => strtoupper((string)($row['idioma_conteudo'] ?? '')),
        'STATUS' => ['text' => $status($row['status'] ?? ''), 'html' => '<span class="badge text-bg-' . ($sug ? 'warning' : (($row['status'] ?? '') === 'APROVADO' ? 'success' : 'secondary')) . '">' . $e($status($row['status'] ?? '')) . '</span>'],
        'CRIADO' => $dataSort($row['criado_em'] ?? ''),
    ], 'grupos' => $sug ? 'SUGERIDO' : ''];
}
$extrasPot = $permission['pode_editar']
    ? '<button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-grid-acao-linha="aprovar" disabled title="' . $e($labels['approve']) . '" aria-label="' . $e($labels['approve']) . '"><i class="bi bi-check2"></i></button>'
      . '<button type="button" class="btn btn-sm btn-outline-danger grid-crud-button" data-grid-acao-linha="rejeitar" disabled title="' . $e($labels['reject']) . '" aria-label="' . $e($labels['reject']) . '"><i class="bi bi-x-lg"></i></button>'
    : '';
$gridPot = DashboardGrid::grid('potGrid', [
    'COD' => ['label' => 'Código', 'width' => '80px'],
    'LEGAL' => ['label' => $labels['legal'], 'width' => '110px'],
    'TIPO' => ['label' => $labels['type'], 'filter' => 'select', 'width' => '150px'],
    'TITULO' => ['label' => $labels['title_field']],
    'CONF' => ['label' => $labels['confidence'], 'filter' => 'none', 'width' => '90px'],
    'IDIOMA' => ['label' => $labels['content_language'], 'filter' => 'select', 'width' => '80px'],
    'STATUS' => ['label' => $labels['status'], 'filter' => 'select', 'width' => '110px'],
    'CRIADO' => ['label' => 'Criado em', 'filter' => 'none', 'width' => '125px'],
], $rowsPot, ['transacao' => 'modulolegal_potenciais', 'ordem' => ['CRIADO', 'desc'], 'vazio' => $labels['no_rows'], 'extras' => $extrasPot,
    'grupo_inicial' => $sugeridos > 0 ? ['SUGERIDO', $status('SUGERIDO')] : []]);
// Solicitações
$rowsReq = [];
foreach ($requestRows as $row) $rowsReq[] = ['cells' => [
    'COD' => ['text' => (string)($row['codsolicitacao'] ?? ''), 'sort' => (string)(int)($row['codsolicitacao'] ?? 0)],
    'STATUS' => $status($row['status'] ?? ''), 'IDIOMA' => strtoupper((string)($row['idioma'] ?? '')),
    'SOLIC' => $dataSort($row['solicitada_em'] ?? ''), 'INICIO' => $dataSort($row['iniciada_em'] ?? ''), 'FIM' => $dataSort($row['concluida_em'] ?? ''),
    'ERRO' => ['text' => (string)($row['erro'] ?? ''), 'title' => (string)($row['erro'] ?? '')],
]];
ob_start(); ?>
<form method="post" class="d-flex gap-2 flex-wrap align-items-center mb-2" id="requestForm"><input type="hidden" name="action" value="request">
<select class="form-select form-select-sm" name="codlegislacao" id="codlegislacao" style="min-width:360px;max-width:640px"<?= $permission['pode_incluir'] ? '' : ' disabled' ?>><option value=""><?= $e($labels['select']) ?></option><?php foreach ($legalRows as $legal): ?><option value="<?= $e($legal['CODLEGISLACAO'] ?? '') ?>"><?= $e(($legal['CODIGO'] ?? '') . ' — ' . ($legal['PADRAO'] ?? $legal['DSCASSUNTO'] ?? '')) ?></option><?php endforeach; ?></select>
<button class="btn btn-sm btn-primary" type="submit" data-testid="request-ai"<?= $permission['pode_incluir'] ? '' : ' disabled' ?>><i class="bi bi-stars me-1"></i><?= $e($labels['request']) ?></button>
<span class="text-danger small" id="requestAviso" hidden><?= $e($labels['required']) ?></span></form>
<?php
$gridReq = ob_get_clean() . DashboardGrid::grid('reqGrid', [
    'COD' => ['label' => 'Código', 'width' => '80px'],
    'STATUS' => ['label' => $labels['status'], 'filter' => 'select', 'width' => '120px'],
    'IDIOMA' => ['label' => 'Idioma', 'filter' => 'select', 'width' => '80px'],
    'SOLIC' => ['label' => $labels['requested'], 'filter' => 'none', 'width' => '125px'],
    'INICIO' => ['label' => $labels['started'], 'filter' => 'none', 'width' => '125px'],
    'FIM' => ['label' => $labels['finished'], 'filter' => 'none', 'width' => '125px'],
    'ERRO' => ['label' => 'Erro'],
], $rowsReq, ['transacao' => 'modulolegal_potenciais', 'ordem' => ['SOLIC', 'desc'], 'vazio' => $labels['no_rows']]);
?><!doctype html><html lang="<?= $e($idioma) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $e($labels['title']) ?></title><link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2"><link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2"><?php DashboardGrid::head(); ?><style>html,body{height:auto}.pot{padding:12px 16px}.pot h2{font-size:16px;margin:0 0 8px}</style></head>
<body><main class="pot"><h2><i class="bi bi-stars me-1"></i><?= $e($labels['title']) ?></h2>
<?php if ($message): ?><div class="alert alert-success py-2"><?= $e($message) ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger py-2"><?= $e($error) ?></div><?php endif; ?>
<?= DashboardGrid::subabas('potenciais', [
    ['id' => 'potenciais', 'icone' => 'bi-lightbulb', 'rotulo' => $labels['potentials'], 'html' => $gridPot, 'contagem' => $sugeridos],
    ['id' => 'solicitacoes', 'icone' => 'bi-send', 'rotulo' => $labels['requests'], 'html' => $gridReq, 'contagem' => count($requestRows)],
], true) ?>
</main>
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Confirmação</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="confirmText"></div><div class="modal-footer"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= $e($labels['cancel']) ?></button><form method="post" id="validationForm"><input type="hidden" name="action" id="validationAction"><input type="hidden" name="codpotencial" id="validationPotential"><button type="submit" class="btn btn-sm btn-primary"><?= $e($labels['confirm']) ?></button></form></div></div></div></div>
<?php DashboardGrid::scripts(); ?>
<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script>
<script>document.addEventListener('DOMContentLoaded',function(){var modal=new bootstrap.Modal(document.getElementById('confirmModal'));var Q={approve:<?= json_encode($labels['question_approve'],JSON_UNESCAPED_UNICODE) ?>,reject:<?= json_encode($labels['question_reject'],JSON_UNESCAPED_UNICODE) ?>};document.addEventListener('click',function(ev){var b=ev.target.closest('[data-validar]');if(!b)return;var acao=b.getAttribute('data-validar');document.getElementById('validationAction').value=acao;document.getElementById('validationPotential').value=b.getAttribute('data-pot');document.getElementById('confirmText').textContent=Q[acao]+' (#'+b.getAttribute('data-pot')+')';modal.show();});var f=document.getElementById('requestForm');if(f)f.addEventListener('submit',function(ev){var ok=!!document.getElementById('codlegislacao').value;document.getElementById('requestAviso').hidden=ok;if(!ok)ev.preventDefault();});});</script>
</body></html>
