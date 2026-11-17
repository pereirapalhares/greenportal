<?php
declare(strict_types=1);

/*
 * Profile (menu do usuário no cabeçalho): o próprio usuário altera os seus dados básicos — nome, e-mail e os demais campos
 * de UsuarioContaService::CAMPOS_PERFIL que existirem na tabela usuarios da empresa. Login, perfil de acesso, grupo e
 * superior aparecem só para leitura (quem altera é o administrador em Segurança › Usuários).
 * Ao salvar, avisa a página principal para atualizar o nome e o e-mail do cabeçalho na hora.
 * GER-1/GER-18 (12/11/2026): Idioma do cadastro (vale depois do login; decisão "Cadastro manda") e Foto do usuário
 * (enviada pelo próprio usuário, até 2 MB, recortada em quadrado; classes/UsuarioImagem.php). Auditoria: PERFIL_USUARIO.
 */

session_start();
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/UsuarioContaService.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/UsuarioImagem.php';
require_once dirname(__DIR__) . '/classes/AuditService.php';

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$sessao = ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'];
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) {
    http_response_code(401);
    if ($post) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', $sessao)], JSON_UNESCAPED_UNICODE); }
    else echo htmlspecialchars(IdiomaService::t('comum.erro.sessao', $sessao), ENT_QUOTES, 'UTF-8');
    exit;
}
$pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { http_response_code(401); exit(IdiomaService::t('comum.erro.sessao', $sessao)); }
$uid = (int)$_SESSION['usuario']['codusuario'];
$service = new UsuarioContaService($pdo, $uid);
$idioma = IdiomaService::idioma();
$L = static fn(string $pt, string $en, string $es): string => ['pt' => $pt, 'en' => $en, 'es' => $es][$idioma] ?? $pt;

if ($post) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!hash_equals((string)($_SESSION['perfil_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) throw new InvalidArgumentException($L('A tela ficou desatualizada. Feche e abra de novo.', 'This screen is out of date. Close and open it again.', 'La pantalla quedó desactualizada. Ciérrela y ábrala de nuevo.'));
        $banco = TenantContext::database($_SESSION);
        $acao = (string)($_POST['acao'] ?? '');
        if ($acao === 'foto' || $acao === 'foto_remover') {
            if ($acao === 'foto') {
                try { UsuarioImagem::salvarFoto($banco, $uid, (array)($_FILES['foto'] ?? [])); }
                catch (InvalidArgumentException $erroFoto) {
                    throw new InvalidArgumentException(['TAMANHO' => $L('A foto passa de 2 MB.', 'The photo is larger than 2 MB.', 'La foto supera 2 MB.'),
                        'TIPO' => $L('Envie uma foto JPG, PNG ou WebP.', 'Upload a JPG, PNG or WebP photo.', 'Envíe una foto JPG, PNG o WebP.'),
                        'DIMENSAO' => $L('A foto é grande demais (máximo 6000 × 6000 pixels).', 'The photo is too large (maximum 6000 × 6000 pixels).', 'La foto es demasiado grande (máximo 6000 × 6000 píxeles).')][$erroFoto->getMessage()]
                        ?? $L('Escolha o arquivo da foto.', 'Choose the photo file.', 'Elija el archivo de la foto.'));
                }
                clearstatcache();
            } else UsuarioImagem::removerFoto($banco, $uid);
            AuditService::record($pdo, 'PERFIL_USUARIO', $acao === 'foto' ? 'FOTO_ENVIAR' : 'FOTO_REMOVER', 'SUCESSO', 'usuarios', (string)$uid, []);
            $versao = UsuarioImagem::fotoVersao($banco, $uid);
            echo json_encode(['ok' => true, 'foto' => $versao, 'codusuario' => $uid, 'message' => $versao ? $L('Foto salva.', 'Photo saved.', 'Foto guardada.') : $L('Foto removida.', 'Photo removed.', 'Foto eliminada.')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $gravados = $service->salvarPerfil((array)($_POST['perfil'] ?? []));
        $idiomaNovo = strtolower(trim((string)($_POST['idioma'] ?? '')));
        if ($idiomaNovo !== '') {
            $anterior = UsuarioImagem::idioma($pdo, $uid);
            UsuarioImagem::salvarIdioma($pdo, $uid, $idiomaNovo);
            if ($anterior !== $idiomaNovo) AuditService::record($pdo, 'PERFIL_USUARIO', 'IDIOMA', 'SUCESSO', 'usuarios', (string)$uid, ['de' => $anterior, 'para' => $idiomaNovo]);
            $gravados['idioma'] = $idiomaNovo;
            if ($idiomaNovo !== (string)($_SESSION['idioma'] ?? 'pt')) { $_SESSION['idioma'] = $idiomaNovo; IdiomaService::lembrarIdioma($idiomaNovo); $gravados['idioma_mudou'] = true; }
        }
        if (isset($gravados['nome'])) $_SESSION['usuario']['nome'] = $gravados['nome'];
        if (isset($gravados['email'])) $_SESSION['usuario']['email'] = $gravados['email'];
        echo json_encode(['ok' => true, 'dados' => $gravados, 'message' => $L('Dados salvos com sucesso.', 'Your details were saved.', 'Datos guardados con éxito.')], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $erro) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $erro instanceof InvalidArgumentException ? $erro->getMessage() : $L('Não foi possível salvar os dados.', 'Could not save your details.', 'No fue posible guardar los datos.')], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$_SESSION['perfil_csrf'] ??= bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['perfil_csrf'];
$campos = $service->camposPerfil();
$idiomaCadastro = UsuarioImagem::idioma($pdo, $uid) ?? $idioma;
$fotoVersao = UsuarioImagem::fotoVersao(TenantContext::database($_SESSION), $uid);
$info = [];
try {
    $s = $pdo->prepare('CALL sp_crud_select(:t, :f, NULL, 1, 0)');
    $s->execute(['t' => 'vw_usuarios_grid', 'f' => json_encode(['codusuario' => ['value' => (string)$uid, 'exact' => true]])]);
    $info = $s->fetchAll(PDO::FETCH_ASSOC)[0] ?? []; $s->closeCursor();
} catch (Throwable) {}
session_write_close();
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$data = static function (mixed $v) use ($idioma): string { $t = $v ? strtotime((string)$v) : false; return $t && (int)date('Y', $t) > 1900 ? date($idioma === 'en' ? 'm/d/Y H:i' : 'd/m/Y H:i', $t) : '—'; };
$leitura = [
    [$L('Login', 'Login', 'Usuario'), $info['userid'] ?? ($_SESSION['usuario']['login'] ?? '')],
    [$L('Empresa', 'Company', 'Empresa'), $_SESSION['tenant']['nome'] ?? ''],
    [$L('Perfil de acesso', 'Access profile', 'Perfil de acceso'), $info['perfil_nome'] ?? ''],
    [$L('Grupo', 'Group', 'Grupo'), $info['grupo_nome'] ?? ''],
    [$L('Superior', 'Manager', 'Superior'), $info['usuario_superior_nome'] ?? ''],
    [$L('Último acesso', 'Last sign-in', 'Último acceso'), $data($info['ultimo_acesso'] ?? null)],
];
$theme = in_array((string)($_SESSION['layout_tema'] ?? ''), ['verde', 'azul', 'cinza'], true) ? (string)$_SESSION['layout_tema'] : 'verde';
?><!doctype html>
<html lang="<?= $idioma === 'pt' ? 'pt-BR' : $e($idioma) ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($L('Profile', 'Profile', 'Perfil')) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2">
<link rel="stylesheet" href="../assets/css/app.css?v=4"><link rel="stylesheet" href="../assets/css/main.css?v=18"><link rel="stylesheet" href="../assets/css/minha-conta.css?v=3">
<style>
.perfil-grade{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:22px;align-items:start;max-width:1000px}
.perfil-campos{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 16px}
.perfil-campos .perfil-largo{grid-column:1/-1}
.perfil-campos .form-control{padding:6px 10px;min-height:34px;background-image:none}
.perfil-info{padding:14px 16px;border:1px solid var(--gp-border,#dfe7e3);border-radius:10px;background:#fff}
.perfil-info dl{margin:0;display:grid;grid-template-columns:auto 1fr;gap:6px 12px;font-size:12px}
.perfil-info dt{color:#5e776d;font-weight:600}.perfil-info dd{margin:0;color:#23332c;word-break:break-word}
.perfil-info p{margin:12px 0 0;color:#6b7a73;font-size:11px}
.perfil-cabeca{display:flex;align-items:center;gap:12px;margin-bottom:18px}
.perfil-avatar{width:64px;height:64px;flex:0 0 64px;display:grid;place-items:center;border-radius:50%;color:#fff;background:var(--gp-primary,#3ba179);font-weight:700;font-size:18px}
.perfil-avatar.tem-foto{background:#fff center/cover no-repeat;color:transparent}
.perfil-foto-acoes{display:flex;gap:6px;margin-top:6px}.perfil-foto-acoes .btn{padding:2px 8px;font-size:11px}
.perfil-cabeca strong{display:block;font-size:15px;color:#23332c}.perfil-cabeca small{color:#6b7a73;font-size:12px}
@media(max-width:760px){.perfil-grade{grid-template-columns:1fr}.perfil-campos{grid-template-columns:1fr}}
</style>
</head>
<body class="account-iframe-page theme-<?= $e($theme) ?>">
<main class="account-iframe-content">
  <div class="perfil-cabeca"><span class="perfil-avatar<?= $fotoVersao ? ' tem-foto' : '' ?>" id="perfilAvatar"<?= $fotoVersao ? ' style="background-image:url(usuario_foto.php?u=' . $uid . '&amp;v=' . $fotoVersao . ')"' : '' ?>><?= $e(mb_strtoupper(mb_substr((string)($campos['nome']['valor'] ?? $_SESSION['usuario']['nome'] ?? '?'), 0, 1))) ?></span>
    <div><strong id="perfilNome"><?= $e($campos['nome']['valor'] ?? ($_SESSION['usuario']['nome'] ?? '')) ?></strong><small><?= $e($L('Mantenha os seus dados atualizados. Eles aparecem nos alertas, e-mails e relatórios do portal.', 'Keep your details up to date. They show in the portal alerts, e-mails and reports.', 'Mantenga sus datos actualizados. Aparecen en las alertas, correos e informes del portal.')) ?></small><div class="perfil-foto-acoes"><input type="file" id="perfilFotoArquivo" accept="image/jpeg,image/png,image/webp" hidden><button type="button" class="btn btn-outline-success" data-foto-trocar><i class="bi bi-camera me-1"></i><?= $e($L('Trocar foto', 'Change photo', 'Cambiar foto')) ?></button><button type="button" class="btn btn-outline-danger" data-foto-remover<?= $fotoVersao ? '' : ' hidden' ?>><i class="bi bi-trash me-1"></i><?= $e($L('Remover foto', 'Remove photo', 'Eliminar foto')) ?></button></div></div></div>
  <div class="perfil-grade">
    <form id="perfilForm" novalidate>
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <div class="perfil-campos">
      <?php foreach ($campos as $chave => $c): $largo = in_array($chave, ['nome', 'email'], true); ?>
        <div class="<?= $largo ? 'perfil-largo' : '' ?>">
          <label class="form-label" for="perfil_<?= $e($chave) ?>"><?= $e($c['rotulo']) ?><?= $chave === 'nome' ? ' *' : '' ?></label>
          <input class="form-control form-control-sm" id="perfil_<?= $e($chave) ?>" name="perfil[<?= $e($chave) ?>]" type="<?= $e($c['tipo']) ?>" value="<?= $e($c['valor']) ?>" autocomplete="<?= $e($c['auto']) ?>"<?= $c['max'] > 0 ? ' maxlength="' . (int)$c['max'] . '"' : '' ?><?= $chave === 'nome' ? ' required' : '' ?>>
        </div>
      <?php endforeach; ?>
        <div>
          <label class="form-label" for="perfil_idioma"><?= $e($L('Idioma do portal', 'Portal language', 'Idioma del portal')) ?></label>
          <select class="form-select form-select-sm" id="perfil_idioma" name="idioma"><?php foreach (IdiomaService::NOMES as $cod => $nomeIdioma): ?><option value="<?= $e($cod) ?>"<?= $cod === $idiomaCadastro ? ' selected' : '' ?>><?= $e($nomeIdioma) ?></option><?php endforeach; ?></select>
          <div class="form-text"><?= $e($L('Vale a partir do próximo login (e já nesta sessão ao salvar).', 'Applies from your next sign-in (and to this session when you save).', 'Vale desde el próximo inicio de sesión (y en esta sesión al guardar).')) ?></div>
        </div>
      </div>
      <button class="btn btn-sm btn-success mt-3" type="submit"><i class="bi bi-check2 me-1"></i><?= $e($L('Salvar', 'Save', 'Guardar')) ?></button>
      <div class="account-message mt-2" id="perfilMensagem" role="status"></div>
    </form>
    <aside class="perfil-info">
      <dl><?php foreach ($leitura as [$rot, $val]): ?><dt><?= $e($rot) ?></dt><dd><?= $e($val !== '' ? $val : '—') ?></dd><?php endforeach; ?></dl>
      <p><i class="bi bi-info-circle me-1"></i><?= $e($L('Login, perfil de acesso, grupo e superior são alterados pelo administrador em Segurança › Usuários.', 'Login, access profile, group and manager are changed by the administrator in Security › Users.', 'Usuario, perfil de acceso, grupo y superior los cambia el administrador en Seguridad › Usuarios.')) ?></p>
    </aside>
  </div>
</main>
<script>
(function () {
  var form = document.getElementById('perfilForm'), msg = document.getElementById('perfilMensagem');
  function aviso(texto, erro) { msg.textContent = texto; msg.className = 'account-message mt-2 ' + (erro ? 'text-danger' : 'text-success'); }
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var nome = form.querySelector('[name="perfil[nome]"]'), email = form.querySelector('[name="perfil[email]"]');
    if (nome && !nome.value.trim()) { nome.focus(); return aviso(<?= json_encode($L('Informe o seu nome.', 'Enter your name.', 'Informe su nombre.')) ?>, true); }
    if (email && email.value.trim() && !email.checkValidity()) { email.focus(); return aviso(<?= json_encode($L('Informe um e-mail válido.', 'Enter a valid e-mail.', 'Informe un correo válido.')) ?>, true); }
    var botao = form.querySelector('[type=submit]'); botao.disabled = true;
    fetch(window.location.pathname, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (j) {
        if (!j.ok) return aviso(j.message || <?= json_encode($L('Não foi possível salvar os dados.', 'Could not save your details.', 'No fue posible guardar los datos.')) ?>, true);
        aviso(j.message, false);
        if (j.dados && j.dados.nome) { document.getElementById('perfilNome').textContent = j.dados.nome; document.getElementById('perfilAvatar').textContent = j.dados.nome.charAt(0).toUpperCase(); }
        try { (window.top || window).postMessage({ type: 'greenportal-account-profile', dados: j.dados || {} }, window.location.origin); } catch (e) {}
        if (j.dados && j.dados.idioma_mudou) { try { (window.top || window).location.reload(); } catch (e) { window.location.reload(); } }
      })
      .catch(function () { aviso(<?= json_encode($L('Não foi possível salvar os dados.', 'Could not save your details.', 'No fue posible guardar los datos.')) ?>, true); })
      .finally(function () { botao.disabled = false; });
  });
  // Foto do usuário
  var arq = document.getElementById('perfilFotoArquivo'), av = document.getElementById('perfilAvatar'), btRem = document.querySelector('[data-foto-remover]');
  var erroFoto = <?= json_encode($L('Não foi possível salvar a foto.', 'Could not save the photo.', 'No fue posible guardar la foto.')) ?>;
  function enviarFoto(dados) {
    fetch(window.location.pathname, { method: 'POST', body: dados, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (j) {
        if (!j.ok) return aviso(j.message || erroFoto, true);
        aviso(j.message, false);
        if (j.foto) { av.classList.add('tem-foto'); av.style.backgroundImage = 'url(usuario_foto.php?u=' + j.codusuario + '&v=' + j.foto + ')'; btRem.hidden = false; }
        else { av.classList.remove('tem-foto'); av.style.backgroundImage = ''; btRem.hidden = true; }
        try { (window.top || window).postMessage({ type: 'greenportal-user-photo', versao: j.foto || 0, codusuario: j.codusuario }, window.location.origin); } catch (e) {}
      })
      .catch(function () { aviso(erroFoto, true); });
  }
  document.querySelector('[data-foto-trocar]').addEventListener('click', function () { arq.click(); });
  arq.addEventListener('change', function () {
    if (!arq.files.length) return;
    if (arq.files[0].size > <?= UsuarioImagem::FOTO_MAX ?>) { arq.value = ''; return aviso(<?= json_encode($L('A foto passa de 2 MB.', 'The photo is larger than 2 MB.', 'La foto supera 2 MB.')) ?>, true); }
    var d = new FormData(); d.append('csrf', form.querySelector('[name=csrf]').value); d.append('acao', 'foto'); d.append('foto', arq.files[0]); arq.value = ''; enviarFoto(d);
  });
  btRem.addEventListener('click', function () { var d = new FormData(); d.append('csrf', form.querySelector('[name=csrf]').value); d.append('acao', 'foto_remover'); enviarFoto(d); });
}());
</script>
</body>
</html>
