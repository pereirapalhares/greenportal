<?php
declare(strict_types=1);

/*
 * Logo da empresa (GER-1, 12/11/2026 — decisão do usuário: cabeçalho + login, trocado pelo ADMINISTRADOR e pelo ADM DA
 * EMPRESA). Popup aberto pelo nome da empresa no cabeçalho e por Setup › Layout. PNG/JPG/WebP até 1 MB; a imagem é
 * regravada como PNG (UsuarioImagem). Cada troca/remoção fica na auditoria (EMPRESA_LOGO).
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/AuditService.php';
require_once dirname(__DIR__) . '/classes/UsuarioImagem.php';

$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$idioma = IdiomaService::idioma();
$L = static fn(string $pt, string $en, string $es): string => ['pt' => $pt, 'en' => $en, 'es' => $es][$idioma] ?? $pt;
$responder = static function (array $d, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) {
    if ($post) $responder(['ok' => false, 'message' => $L('Sua sessão expirou. Entre novamente no portal.', 'Your session has expired. Please sign in again.', 'Su sesión expiró. Vuelva a entrar al portal.')], 401);
    http_response_code(401); exit;
}
$pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { http_response_code(401); exit; }
$perfil = strtoupper(trim((string)($_SESSION['usuario']['codigo_perfil'] ?? '')));
if (!in_array($perfil, ['ADMINISTRADOR', 'ADM_DA_EMPRESA', 'ADM DA EMPRESA', 'ADM EMPRESA'], true)) {
    if ($post) $responder(['ok' => false, 'message' => $L('Perfil sem permissão de acesso!', 'Profile without access permission!', '¡Perfil sin permiso de acceso!')], 403);
    http_response_code(403); exit($L('Perfil sem permissão de acesso!', 'Profile without access permission!', '¡Perfil sin permiso de acceso!'));
}
$banco = TenantContext::database($_SESSION);
$_SESSION['empresa_logo_csrf'] ??= bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['empresa_logo_csrf'];

if ($post) {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $responder(['ok' => false, 'message' => $L('A tela ficou desatualizada. Feche e abra de novo.', 'This screen is out of date. Close and open it again.', 'La pantalla quedó desactualizada. Ciérrela y ábrala de nuevo.')], 419);
    try {
        if ((string)($_POST['acao'] ?? '') === 'remover') {
            $removeu = UsuarioImagem::removerLogo($banco);
            AuditService::record($pdo, 'EMPRESA_LOGO', 'REMOVER', 'SUCESSO', 'empresa', (string)($_SESSION['tenant']['codempresa'] ?? ''), ['tinha_logo' => $removeu]);
            $responder(['ok' => true, 'versao' => 0, 'message' => $L('Logo removido. O cabeçalho volta a mostrar a inicial da empresa.', 'Logo removed. The header shows the company initial again.', 'Logo eliminado. El encabezado vuelve a mostrar la inicial de la empresa.')]);
        }
        UsuarioImagem::salvarLogo($banco, (array)($_FILES['logo'] ?? []));
        clearstatcache();
        AuditService::record($pdo, 'EMPRESA_LOGO', 'ENVIAR', 'SUCESSO', 'empresa', (string)($_SESSION['tenant']['codempresa'] ?? ''), ['tamanho' => (int)($_FILES['logo']['size'] ?? 0)]);
        $responder(['ok' => true, 'versao' => UsuarioImagem::logoVersao($banco), 'message' => $L('Logo salvo. Ele aparece no cabeçalho e na tela de login da empresa.', 'Logo saved. It shows in the header and on the company sign-in screen.', 'Logo guardado. Aparece en el encabezado y en la pantalla de inicio de sesión de la empresa.')]);
    } catch (InvalidArgumentException $e) {
        $msg = ['TAMANHO' => $L('O arquivo passa de 1 MB.', 'The file is larger than 1 MB.', 'El archivo supera 1 MB.'),
                'TIPO' => $L('Envie uma imagem JPG, PNG ou WebP.', 'Upload a JPG, PNG or WebP image.', 'Envíe una imagen JPG, PNG o WebP.'),
                'DIMENSAO' => $L('A imagem é grande demais (máximo 6000 × 6000 pixels).', 'The image is too large (maximum 6000 × 6000 pixels).', 'La imagen es demasiado grande (máximo 6000 × 6000 píxeles).')][$e->getMessage()]
            ?? $L('Escolha o arquivo da imagem.', 'Choose the image file.', 'Elija el archivo de la imagen.');
        $responder(['ok' => false, 'message' => $msg], 422);
    } catch (Throwable $e) {
        error_log('empresa_logo: ' . $e->getMessage());
        AuditService::record($pdo, 'EMPRESA_LOGO', 'ENVIAR', 'ERRO', 'empresa', (string)($_SESSION['tenant']['codempresa'] ?? ''), []);
        $responder(['ok' => false, 'message' => $L('Não foi possível salvar o logo.', 'Could not save the logo.', 'No fue posible guardar el logo.')], 500);
    }
}

$versao = UsuarioImagem::logoVersao($banco);
$nomeEmpresa = (string)($_SESSION['tenant']['nome'] ?? '');
$theme = in_array((string)($_SESSION['layout_tema'] ?? ''), ['verde', 'azul', 'cinza'], true) ? (string)$_SESSION['layout_tema'] : 'verde';
session_write_close();
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="<?= $idioma === 'pt' ? 'pt-BR' : $e($idioma) ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($L('Logo da empresa', 'Company logo', 'Logo de la empresa')) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2">
<link rel="stylesheet" href="../assets/css/app.css?v=4"><link rel="stylesheet" href="../assets/css/main.css?v=18"><link rel="stylesheet" href="../assets/css/minha-conta.css?v=3">
<style>
.logo-caixa{display:flex;align-items:center;justify-content:center;width:100%;max-width:520px;height:190px;border:1px dashed #b9cfc5;border-radius:12px;background:#fff repeating-conic-gradient(#f3f6f4 0 25%,#fff 0 50%) 0 0/18px 18px}
.logo-caixa img{max-width:480px;max-height:160px}
.logo-vazio{color:#6b7a73;font-size:13px;text-align:center}
.logo-acoes{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 6px}
.logo-dica{color:#6b7a73;font-size:12px;max-width:520px}
</style>
</head>
<body class="account-iframe-page theme-<?= $e($theme) ?>">
<main class="account-iframe-content">
  <h6 class="mb-1"><?= $e($L('Logo da empresa', 'Company logo', 'Logo de la empresa')) ?> — <?= $e($nomeEmpresa) ?></h6>
  <p class="logo-dica"><?= $e($L('O logo aparece no cabeçalho do portal, ao lado do logo do Green, e na tela de login quando esta empresa é escolhida.', 'The logo shows in the portal header, next to the Green logo, and on the sign-in screen when this company is chosen.', 'El logo aparece en el encabezado del portal, junto al logo de Green, y en la pantalla de inicio de sesión cuando se elige esta empresa.')) ?></p>
  <div class="logo-caixa" id="logoCaixa">
    <?php if ($versao): ?><img src="empresa_logo_img.php?v=<?= $versao ?>" alt="<?= $e($L('Logo da empresa', 'Company logo', 'Logo de la empresa')) ?>">
    <?php else: ?><div class="logo-vazio"><i class="bi bi-image fs-3 d-block mb-1"></i><?= $e($L('Nenhum logo enviado', 'No logo uploaded', 'Ningún logo enviado')) ?></div><?php endif; ?>
  </div>
  <form id="logoForm" class="logo-acoes">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="file" name="logo" id="logoArquivo" accept="image/png,image/jpeg,image/webp" hidden>
    <button type="button" class="btn btn-sm btn-success" data-logo-escolher><i class="bi bi-upload me-1"></i><?= $e($L('Enviar logo', 'Upload logo', 'Enviar logo')) ?></button>
    <button type="button" class="btn btn-sm btn-outline-danger" data-logo-remover<?= $versao ? '' : ' disabled' ?>><i class="bi bi-trash me-1"></i><?= $e($L('Remover logo', 'Remove logo', 'Eliminar logo')) ?></button>
  </form>
  <p class="logo-dica"><?= $e($L('PNG (de preferência com fundo transparente), JPG ou WebP, até 1 MB. A imagem é reduzida para no máximo 480 × 160 pixels.', 'PNG (preferably with a transparent background), JPG or WebP, up to 1 MB. The image is reduced to at most 480 × 160 pixels.', 'PNG (de preferencia con fondo transparente), JPG o WebP, hasta 1 MB. La imagen se reduce a un máximo de 480 × 160 píxeles.')) ?></p>
  <div class="account-message mt-2" id="logoMensagem" role="status"></div>
</main>
<script>
(function () {
  var form = document.getElementById('logoForm'), arquivo = document.getElementById('logoArquivo'), msg = document.getElementById('logoMensagem'), caixa = document.getElementById('logoCaixa');
  var remover = form.querySelector('[data-logo-remover]'), vazio = <?= json_encode('<div class="logo-vazio"><i class="bi bi-image fs-3 d-block mb-1"></i>' . $e($L('Nenhum logo enviado', 'No logo uploaded', 'Ningún logo enviado')) . '</div>') ?>;
  var erroPadrao = <?= json_encode($L('Não foi possível salvar o logo.', 'Could not save the logo.', 'No fue posible guardar el logo.')) ?>;
  function aviso(t, erro) { msg.textContent = t; msg.className = 'account-message mt-2 ' + (erro ? 'text-danger' : 'text-success'); }
  function avisarPortal(versao) { try { (window.top || window).postMessage({ type: 'greenportal-empresa-logo', versao: versao }, window.location.origin); } catch (e) {} }
  function enviar(dados) {
    form.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
    fetch(window.location.pathname, { method: 'POST', body: dados, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (j) {
        if (!j.ok) return aviso(j.message || erroPadrao, true);
        aviso(j.message, false);
        caixa.innerHTML = j.versao ? '<img src="empresa_logo_img.php?v=' + j.versao + '" alt="">' : vazio;
        avisarPortal(j.versao || 0);
      })
      .catch(function () { aviso(erroPadrao, true); })
      .finally(function () { form.querySelector('[data-logo-escolher]').disabled = false; remover.disabled = !caixa.querySelector('img'); });
  }
  form.querySelector('[data-logo-escolher]').addEventListener('click', function () { arquivo.click(); });
  arquivo.addEventListener('change', function () {
    if (!arquivo.files.length) return;
    if (arquivo.files[0].size > <?= UsuarioImagem::LOGO_MAX ?>) { arquivo.value = ''; return aviso(<?= json_encode($L('O arquivo passa de 1 MB.', 'The file is larger than 1 MB.', 'El archivo supera 1 MB.')) ?>, true); }
    var d = new FormData(form); arquivo.value = ''; enviar(d);
  });
  remover.addEventListener('click', function () {
    var d = new FormData(); d.append('csrf', form.querySelector('[name=csrf]').value); d.append('acao', 'remover'); enviar(d);
  });
}());
</script>
</body>
</html>
