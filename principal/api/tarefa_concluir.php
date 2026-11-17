<?php
declare(strict_types=1);

/*
 * Concluir tarefa (migração 345; Home › Agenda): o responsável conclui a tarefa (green_tasks) pela procedure sp_tarefa_concluir,
 * que confere se ele é o destinatário e se a tarefa está pendente. Quem APROVA o pilar no perfil de atribuições (registro da
 * indicação automática) recebe um alerta para conferir.
 * POST codtarefa, csrf (sessão tarefa_concluir_csrf). Resposta JSON {ok, message, aprovadores}.
 */
require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
require_once dirname(__DIR__, 2) . '/classes/ResponsavelAutomatico.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$json = static function (array $d, int $status = 200): never { http_response_code($status); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
$T = static fn(string $c, array $p): string => IdiomaService::t('home_agenda.tarefa.' . $c, $p);
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok' => false, 'message' => $T('sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.'])], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $json(['ok' => false, 'message' => 'POST'], 405);
if (!hash_equals((string)($_SESSION['tarefa_concluir_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) $json(['ok' => false, 'message' => $T('csrf', ['pt' => 'A tela expirou: recarregue a Agenda.', 'en' => 'The screen expired: reload the Agenda.', 'es' => 'La pantalla expiró: recargue la Agenda.'])], 419);
$tarefa = max(0, (int)($_POST['codtarefa'] ?? 0));
if ($tarefa <= 0) $json(['ok' => false, 'message' => $T('invalida', ['pt' => 'Tarefa inválida.', 'en' => 'Invalid task.', 'es' => 'Tarea inválida.'])], 400);
try {
    $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $json(['ok' => false, 'message' => $T('sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.'])], 401);
    $uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);
    $s = $pdo->prepare('CALL sp_tarefa_concluir(?, ?, ?)');
    $s->execute([$tarefa, $uid, mb_substr(trim((string)($_POST['observacao'] ?? '')), 0, 2000)]);
    $linha = [];
    do { try { $x = $s->fetchAll(PDO::FETCH_ASSOC); if ($x) $linha = $x[0]; } catch (Throwable) {} } while ($s->nextRowset());
    $s->closeCursor();
    $avisados = 0;
    if ($linha && trim((string)($linha['APROVADORES'] ?? '')) !== '') {
        try { $avisados = (new ResponsavelAutomatico($pdo))->avisarAprovadores($linha, trim((string)($_SESSION['usuario']['nome'] ?? '')) ?: 'O responsável'); }
        catch (Throwable $e) { error_log('[tarefa concluir] ' . $e->getMessage()); }
    }
    try { $a = $pdo->prepare('CALL sp_auditoria_registrar(?, ?, ?, ?, ?, ?, ?)'); $a->execute([$uid, 'home_agenda', 'CONCLUIR_TAREFA', 'green_tasks', (string)$tarefa, 'SUCESSO', json_encode(['aprovadores_avisados' => $avisados])]); $a->closeCursor(); } catch (Throwable) {}
    $json(['ok' => true, 'aprovadores' => $avisados, 'message' => $avisados > 0
        ? strtr($T('ok_aprov', ['pt' => 'Tarefa concluída. {n} pessoa(s) que aprovam este pilar foram avisadas para conferir.', 'en' => 'Task completed. {n} approver(s) were notified.', 'es' => 'Tarea concluida. Se avisó a {n} aprobador(es).']), ['{n}' => (string)$avisados])
        : $T('ok', ['pt' => 'Tarefa concluída.', 'en' => 'Task completed.', 'es' => 'Tarea concluida.'])]);
} catch (PDOException $e) {
    $msg = (string)($e->errorInfo[2] ?? $e->getMessage());
    $json(['ok' => false, 'message' => preg_match('/^(Tarefa|Só o responsável|A tarefa)/u', $msg) ? $msg : $T('erro', ['pt' => 'Não foi possível concluir a tarefa.', 'en' => 'The task could not be completed.', 'es' => 'No fue posible concluir la tarea.'])], 422);
} catch (Throwable $e) {
    error_log('[tarefa concluir] ' . $e->getMessage());
    $json(['ok' => false, 'message' => $T('erro', ['pt' => 'Não foi possível concluir a tarefa.', 'en' => 'The task could not be completed.', 'es' => 'No fue posible concluir la tarea.'])], 500);
}
