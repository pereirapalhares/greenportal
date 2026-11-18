<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/AdministracaoTextos.php';

/*
 * Segurança › Usuários › "Idioma e foto do usuário selecionado" (GER-1/GER-18, 12/11/2026).
 * O ADMINISTRADOR e o ADM DA EMPRESA definem o idioma do cadastro de um usuário (vale no próximo login dele) e podem
 * remover a foto dele (só o próprio usuário envia foto, em Profile). O ADM DA EMPRESA não altera usuários ADMINISTRADOR.
 * Mesmo controle da tela de usuários (perfil + transação SEGURANCA_USUARIOS). Auditoria: SEGURANCA_USUARIOS.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/AuditService.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/UsuarioImagem.php';

$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$idioma = IdiomaService::idioma();
$L = static fn(string $pt, string $en, string $es): string => ['pt' => $pt, 'en' => $en, 'es' => $es][$idioma] ?? $pt;
$responder = static function (array $d, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
$negar = static function () use ($post, $responder, $L): never {
    $m = $L(adm_t('Perfil sem permissão de acesso!'), adm_t('Profile without access permission!'), adm_t('¡Perfil sin permiso de acceso!'));
    if ($post) $responder(['ok' => false, 'message' => $m], 403);
    http_response_code(403); exit($m);
};
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) { http_response_code(401); exit; }
$pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { http_response_code(401); exit; }
$perfil = strtoupper(trim((string)($_SESSION['usuario']['codigo_perfil'] ?? '')));
$admPlataforma = $perfil === 'ADMINISTRADOR';
if (!$admPlataforma && !in_array($perfil, ['ADM DA EMPRESA', 'ADM EMPRESA', 'ADM_DA_EMPRESA'], true)) $negar();
$perm = PermissaoTransacao::obter('SEGURANCA_USUARIOS');
if (!$admPlataforma && empty($perm['pode_visualizar'])) $negar();
$podeEditar = $admPlataforma || !empty($perm['pode_editar']);

$cod = max(0, (int)($_GET['codusuario'] ?? $_POST['codusuario'] ?? 0));
$alvo = null;
if ($cod > 0) {
    $s = $pdo->prepare('CALL sp_crud_select(:t, :f, NULL, 1, 0)');
    $s->execute(['t' => 'vw_usuarios_grid', 'f' => json_encode(['codusuario' => ['value' => (string)$cod, 'exact' => true]])]);
    $alvo = $s->fetch(PDO::FETCH_ASSOC) ?: null; $s->closeCursor();
    if ($alvo && (int)$alvo['codusuario'] !== $cod) $alvo = null;
}
if (!$alvo || (!$admPlataforma && strtoupper(trim((string)($alvo['perfil_codigo'] ?? ''))) === 'ADMINISTRADOR')) {
    if ($post) $responder(['ok' => false, 'message' => $L(adm_t('Usuário não encontrado.'), adm_t('User not found.'), adm_t('Usuario no encontrado.'))], 404);
    http_response_code(404); exit($L(adm_t('Selecione um usuário na lista.'), adm_t('Select a user in the list.'), adm_t('Seleccione un usuario en la lista.')));
}
$banco = TenantContext::database($_SESSION);
$_SESSION['seg_usuario_pref_csrf'] ??= bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['seg_usuario_pref_csrf'];

if ($post) {
    if (!$podeEditar) $negar();
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $responder(['ok' => false, 'message' => $L(adm_t('A tela ficou desatualizada. Feche e abra de novo.'), adm_t('This screen is out of date. Close and open it again.'), adm_t('La pantalla quedó desactualizada. Ciérrela y ábrala de nuevo.'))], 419);
    try {
        if ((string)($_POST['acao'] ?? '') === 'foto_remover') {
            UsuarioImagem::removerFoto($banco, $cod);
            AuditService::record($pdo, 'SEGURANCA_USUARIOS', 'FOTO_REMOVER', 'SUCESSO', 'usuarios', (string)$cod, []);
            $responder(['ok' => true, 'foto' => 0, 'message' => $L(adm_t('Foto removida.'), adm_t('Photo removed.'), adm_t('Foto eliminada.'))]);
        }
        $anterior = UsuarioImagem::idioma($pdo, $cod);
        $novo = UsuarioImagem::salvarIdioma($pdo, $cod, (string)($_POST['idioma'] ?? ''));
        AuditService::record($pdo, 'SEGURANCA_USUARIOS', 'IDIOMA', 'SUCESSO', 'usuarios', (string)$cod, ['de' => $anterior, 'para' => $novo]);
        $responder(['ok' => true, 'message' => $L(adm_t('Idioma salvo. Vale a partir do próximo login do usuário.'), adm_t('Language saved. It applies from the user\'s next sign-in.'), adm_t('Idioma guardado. Vale desde el próximo inicio de sesión del usuario.'))]);
    } catch (InvalidArgumentException) {
        $responder(['ok' => false, 'message' => $L(adm_t('Escolha um idioma.'), adm_t('Choose a language.'), adm_t('Elija un idioma.'))], 422);
    } catch (Throwable $e) {
        error_log('seguranca_usuario_preferencias: ' . $e->getMessage());
        $responder(['ok' => false, 'message' => $L(adm_t('Não foi possível salvar.'), adm_t('Could not save.'), adm_t('No fue posible guardar.'))], 500);
    }
}

$idiomaAtual = UsuarioImagem::idioma($pdo, $cod);
$foto = UsuarioImagem::fotoVersao($banco, $cod);
$theme = in_array((string)($_SESSION['layout_tema'] ?? ''), ['verde', 'azul', 'cinza'], true) ? (string)$_SESSION['layout_tema'] : 'verde';
session_write_close();
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="<?= $idioma === 'pt' ? 'pt-BR' : $e($idioma) ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($L(adm_t('Idioma e foto do usuário'), adm_t('User language and photo'), adm_t('Idioma y foto del usuario'))) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2">
<link rel="stylesheet" href="../assets/css/app.css?v=4"><link rel="stylesheet" href="../assets/css/main.css?v=18"><link rel="stylesheet" href="../assets/css/minha-conta.css?v=3">
<style>
.up-cab{display:flex;align-items:center;gap:14px;margin-bottom:16px}
.up-av{width:72px;height:72px;border-radius:50%;display:grid;place-items:center;color:#fff;background:var(--gp-primary,#3ba179) center/cover no-repeat;font-weight:700;font-size:26px}
.up-av.tem-foto{color:transparent;background-color:#fff}
.up-cab strong{display:block;font-size:15px}.up-cab small{color:#6b7a73}
.up-campo{max-width:320px}
</style>
</head>
<body class="account-iframe-page theme-<?= $e($theme) ?>">
<main class="account-iframe-content">
  <div class="up-cab">
    <span class="up-av<?= $foto ? ' tem-foto' : '' ?>" id="upAv"<?= $foto ? ' style="background-image:url(usuario_foto.php?u=' . $cod . '&amp;v=' . $foto . ')"' : '' ?>><?= $e(mb_strtoupper(mb_substr((string)$alvo['nome'], 0, 1))) ?></span>
    <div><strong><?= $e($alvo['nome']) ?></strong><small><?= $e($alvo['userid'] ?? '') ?></small>
      <?php if ($podeEditar): ?><div class="mt-1"><button type="button" class="btn btn-sm btn-outline-danger" data-up-remover<?= $foto ? '' : ' hidden' ?>><i class="bi bi-trash me-1"></i><?= $e($L(adm_t('Remover foto'), adm_t('Remove photo'), adm_t('Eliminar foto'))) ?></button></div><?php endif; ?>
      <?php if (!$foto): ?><div class="small text-muted mt-1"><?= $e($L(adm_t('Sem foto. Só o próprio usuário envia a foto, em Profile.'), adm_t('No photo. Only the user uploads a photo, in Profile.'), adm_t('Sin foto. Solo el propio usuario envía la foto, en Perfil.'))) ?></div><?php endif; ?>
    </div>
  </div>
  <form id="upForm" class="up-campo">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="codusuario" value="<?= $cod ?>">
    <label class="form-label" for="upIdioma"><?= $e($L(adm_t('Idioma do portal'), adm_t('Portal language'), adm_t('Idioma del portal'))) ?></label>
    <select class="form-select form-select-sm" id="upIdioma" name="idioma"<?= $podeEditar ? '' : ' disabled' ?>>
      <option value=""<?= $idiomaAtual === null ? ' selected' : '' ?> disabled><?= $e($L(adm_t('Não definido (usa o escolhido no login)'), 'Not set (uses the one chosen at sign-in)', adm_t('No definido (usa el elegido al iniciar sesión)'))) ?></option>
      <?php foreach (IdiomaService::NOMES as $c => $n): ?><option value="<?= $e($c) ?>"<?= $c === $idiomaAtual ? ' selected' : '' ?>><?= $e($n) ?></option><?php endforeach; ?>
    </select>
    <div class="form-text"><?= $e($L(adm_t('Vale a partir do próximo login do usuário. Ele também pode trocar em Profile ou no menu de idioma do cabeçalho.'), adm_t('Applies from the user\'s next sign-in. They can also change it in Profile or in the header language menu.'), adm_t('Vale desde el próximo inicio de sesión del usuario. También puede cambiarlo en Perfil o en el menú de idioma del encabezado.'))) ?></div>
    <?php if ($podeEditar): ?><button type="submit" class="btn btn-sm btn-success mt-3"><i class="bi bi-check2 me-1"></i><?= $e($L('Salvar', 'Save', 'Guardar')) ?></button><?php endif; ?>
  </form>
  <div class="account-message mt-2" id="upMsg" role="status"></div>
</main>
<script>
(function () {
  var form = document.getElementById('upForm'), msg = document.getElementById('upMsg'), erro = <?= json_encode($L(adm_t('Não foi possível salvar.'), adm_t('Could not save.'), adm_t('No fue posible guardar.'))) ?>;
  function aviso(t, e) { msg.textContent = t; msg.className = 'account-message mt-2 ' + (e ? 'text-danger' : 'text-success'); }
  function post(d, depois) {
    fetch(window.location.pathname, { method: 'POST', body: d, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (j) { if (!j.ok) return aviso(j.message || erro, true); aviso(j.message, false); if (depois) depois(j); })
      .catch(function () { aviso(erro, true); });
  }
  form.addEventListener('submit', function (ev) { ev.preventDefault(); post(new FormData(form)); });
  var rem = document.querySelector('[data-up-remover]');
  if (rem) rem.addEventListener('click', function () {
    var d = new FormData(); d.append('csrf', form.querySelector('[name=csrf]').value); d.append('codusuario', form.querySelector('[name=codusuario]').value); d.append('acao', 'foto_remover');
    post(d, function () { var av = document.getElementById('upAv'); av.classList.remove('tem-foto'); av.style.backgroundImage = ''; rem.hidden = true;
      try { (window.top || window).postMessage({ type: 'greenportal-user-photo', versao: 0, codusuario: <?= $cod ?> }, window.location.origin); } catch (e) {} });
  });
}());
</script>
</body>
</html>
