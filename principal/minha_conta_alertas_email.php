<?php
declare(strict_types=1);

/*
 * Minha conta › Alertas por e-mail (migração 343): cada usuário escolhe como recebe os alertas do portal por e-mail —
 * na hora (padrão), resumo diário às 7h (só os ainda não lidos) ou não receber. O e-mail traz "Abrir no portal".
 * Leitura: vw_alertas_email_pref; gravação: sp_alerta_email_pref_salvar.
 */

require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$pdo = TransactionSecurity::connectAuthenticated('MINHA_CONTA_ALERTAS_EMAIL');
$uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$T = static fn(string $c, array $p): string => IdiomaService::t('minha_conta_alertas_email.' . $c, $p);
if (empty($_SESSION['alertas_email_csrf'])) $_SESSION['alertas_email_csrf'] = bin2hex(random_bytes(16));
$msg = ''; $tipo = 'success';
$ler = static function () use ($pdo, $uid): ?array {
    $s = $pdo->prepare('CALL sp_crud_select(?, ?, NULL, 1, 0)');
    $s->execute(['vw_alertas_email_pref', json_encode(['CODUSUARIO' => ['value' => (string)$uid, 'exact' => true]])]);
    $r = $s->fetch(PDO::FETCH_ASSOC) ?: null; $s->closeCursor();
    return $r;
};
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!hash_equals((string)$_SESSION['alertas_email_csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException($T('erro.sessao', ['pt' => 'A tela expirou: abra de novo.', 'en' => 'The screen expired: open it again.', 'es' => 'La pantalla expiró: ábrala de nuevo.']));
        $s = $pdo->prepare('CALL sp_alerta_email_pref_salvar(?, ?)'); $s->execute([$uid, (string)($_POST['modo'] ?? '')]); while ($s->nextRowset()) {} $s->closeCursor();
        AuditService::record($pdo, 'MINHA_CONTA_ALERTAS_EMAIL', 'SALVAR', 'SUCESSO', 'alertas_email_pref', (string)$uid, ['modo' => (string)($_POST['modo'] ?? '')]);
        $msg = $T('salvo', ['pt' => 'Escolha gravada.', 'en' => 'Choice saved.', 'es' => 'Elección grabada.']);
    }
    $pref = $ler();
    $instalado = true;
} catch (PDOException $x) {
    $instalado = !str_contains($x->getMessage(), 'vw_alertas_email_pref') && !str_contains($x->getMessage(), 'sp_alerta_email_pref_salvar');
    $pref = null;
    $msg = $instalado ? ((string)($x->errorInfo[2] ?? $x->getMessage())) : ''; $tipo = 'danger';
} catch (Throwable $x) { $pref = $pref ?? null; $instalado = true; $msg = $x->getMessage(); $tipo = 'danger'; }
$modo = (string)($pref['MODO'] ?? 'IMEDIATO');
$email = trim((string)($pref['EMAIL'] ?? ($_SESSION['usuario']['email'] ?? '')));
$opcoes = [
    'IMEDIATO' => ['bi-lightning-charge', $T('op.imediato', ['pt' => 'Na hora', 'en' => 'Right away', 'es' => 'En el momento']), $T('op.imediato_txt', ['pt' => 'Um e-mail logo depois de cada alerta (os que chegam juntos vêm no mesmo e-mail). Recomendado.', 'en' => 'An e-mail right after each alert (alerts arriving together come in the same e-mail). Recommended.', 'es' => 'Un correo justo después de cada alerta (las que llegan juntas vienen en el mismo correo). Recomendado.'])],
    'RESUMO' => ['bi-calendar-day', $T('op.resumo', ['pt' => 'Resumo diário às 7h', 'en' => 'Daily summary at 7 a.m.', 'es' => 'Resumen diario a las 7h']), $T('op.resumo_txt', ['pt' => 'Um e-mail por dia com os alertas que você ainda não leu no portal.', 'en' => 'One e-mail a day with the alerts you have not read yet.', 'es' => 'Un correo por día con las alertas que aún no leyó.'])],
    'NAO' => ['bi-bell-slash', $T('op.nao', ['pt' => 'Não receber por e-mail', 'en' => 'Do not receive by e-mail', 'es' => 'No recibir por correo']), $T('op.nao_txt', ['pt' => 'Os alertas continuam no sino do portal (Home › Alertas).', 'en' => 'Alerts remain in the portal bell (Home › Alerts).', 'es' => 'Las alertas siguen en la campana del portal (Inicio › Alertas).'])],
];
require_once dirname(__DIR__) . '/components/pagina_padrao.php';
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($T('titulo', ['pt' => 'Alertas por e-mail', 'en' => 'Alerts by e-mail', 'es' => 'Alertas por correo'])) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<link href="../assets/css/app.css?v=5" rel="stylesheet"><link href="../assets/css/design-system.css?v=3" rel="stylesheet"><link href="../assets/css/main.css?v=18" rel="stylesheet">
<?php greenPortalEstiloPagina(); ?>
<style>body{padding:16px;background:#fff}.ae-op{cursor:pointer;border:1px solid #dce9e2;border-radius:8px;padding:10px 12px;display:flex;gap:10px;align-items:flex-start;margin-bottom:8px}.ae-op:has(input:checked){border-color:#2f7d5b;background:#f3faf6}.ae-op i{font-size:18px;color:#2f7d5b}</style>
</head><body>
<h2 class="h5 mb-1"><i class="bi bi-envelope me-1"></i><?= $e($T('titulo', ['pt' => 'Alertas por e-mail', 'en' => 'Alerts by e-mail', 'es' => 'Alertas por correo'])) ?></h2>
<p class="text-muted small mb-3"><?= $e($T('subtitulo', ['pt' => 'Escolha como os alertas do portal chegam no seu e-mail. Cada e-mail traz o botão "Abrir no portal", que abre a tela do alerta (com login, se precisar).', 'en' => 'Choose how the portal alerts reach your e-mail. Each e-mail has an "Open in the portal" button.', 'es' => 'Elija cómo llegan las alertas del portal a su correo. Cada correo trae el botón "Abrir en el portal".'])) ?></p>
<?php if ($msg !== ''): ?><div class="alert alert-<?= $e($tipo) ?> py-2"><?= $e($msg) ?></div><?php endif; ?>
<?php if (!$instalado): ?><div class="alert alert-warning py-2"><?= $e($T('nao_instalado', ['pt' => 'O envio de alertas por e-mail ainda não está instalado nesta empresa (migração 343).', 'en' => 'Alerts by e-mail are not installed for this company yet (migration 343).', 'es' => 'El envío de alertas por correo aún no está instalado (migración 343).'])) ?></div>
<?php else: ?>
<div class="mb-3 small"><span class="text-muted"><?= $e($T('email', ['pt' => 'Seu e-mail no cadastro:', 'en' => 'Your registered e-mail:', 'es' => 'Su correo registrado:'])) ?></span>
  <?= filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? '<strong>' . $e($email) . '</strong>' : '<span class="text-danger">' . $e($T('sem_email', ['pt' => 'nenhum e-mail válido — peça ao administrador para cadastrar (Segurança › Usuários); até lá nada é enviado.', 'en' => 'no valid e-mail — ask the administrator to register it; nothing is sent until then.', 'es' => 'ningún correo válido — pida al administrador que lo registre; hasta entonces no se envía nada.'])) . '</span>' ?></div>
<form method="post">
  <input type="hidden" name="csrf" value="<?= $e($_SESSION['alertas_email_csrf']) ?>">
  <?php foreach ($opcoes as $k => [$ic, $rot, $txt]): ?>
  <label class="ae-op"><input class="form-check-input mt-1" type="radio" name="modo" value="<?= $e($k) ?>"<?= $modo === $k ? ' checked' : '' ?>><i class="bi <?= $e($ic) ?>"></i><span><strong><?= $e($rot) ?></strong><br><small class="text-muted"><?= $e($txt) ?></small></span></label>
  <?php endforeach; ?>
  <p class="small text-muted mt-2"><?= $e($T('nota', ['pt' => 'Os alertas de "Exportação iniciada" não vão por e-mail (o aviso de exportação concluída vai). Alertas com mais de um dia não são enviados.', 'en' => '"Export started" alerts are not e-mailed (the export finished alert is). Alerts older than one day are not sent.', 'es' => 'Las alertas de "Exportación iniciada" no van por correo (la de exportación concluida sí). Las alertas con más de un día no se envían.'])) ?></p>
  <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-check2 me-1"></i><?= $e($T('salvar', ['pt' => 'Salvar', 'en' => 'Save', 'es' => 'Guardar'])) ?></button>
</form>
<?php endif; ?>
</body></html>
