<?php
declare(strict_types=1);

require_once __DIR__ . '/classes/GreenPortalDatabase.php';
require_once __DIR__ . '/classes/ApplicationIdentity.php';
require_once __DIR__ . '/classes/IdiomaService.php';

// Idioma da tela de login: escolha na própria tela, última escolha neste navegador ou idioma do navegador.
$idiomaEscolhido = isset($_GET['idioma']) ? (string)$_GET['idioma'] : null;
$idioma = IdiomaService::idiomaPreferido($idiomaEscolhido);
if ($idiomaEscolhido !== null) IdiomaService::lembrarIdioma($idioma);
IdiomaService::usarIdioma($idioma);
$textosLogin = require __DIR__ . '/components/textos_login.php';
$TL = static fn(string $chave): string => IdiomaService::t($chave, $textosLogin[$chave] ?? '');
$eL = static fn(string $chave): string => htmlspecialchars(IdiomaService::t($chave, $textosLogin[$chave] ?? ''), ENT_QUOTES, 'UTF-8');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$empresasAtivas = [];
// Migração 343: link "Abrir no portal" do e-mail de alerta (principal/abrir_alerta.php): empresa já escolhida e, depois do login, a tela do alerta
$empresaLink = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($_GET['empresa'] ?? '')) ?? '';
$abrirAlerta = max(0, (int)($_GET['abrir_alerta'] ?? 0));
$loginTheme = 'verde';
$loginTexts = [
    'login_marca' => ApplicationIdentity::LABEL,
    'login_eyebrow' => 'Bem-vindo de volta',
    'login_titulo' => 'Acesse seu portal',
    'login_subtitulo' => 'Entre com seus dados para continuar.',
    'login_label_empresa' => 'Empresa',
    'login_label_usuario' => 'Usuário',
    'login_label_senha' => 'Senha',
    'login_botao_entrar' => 'Entrar',
    'login_marketing_eyebrow' => 'Um novo jeito de gerenciar',
    'login_marketing_titulo' => 'Sistemas de gestão. Mais conformidade.',
    'login_marketing_texto' => 'Sistemas de Gestão em conformidade com as normas ISO 14001, OHSAS 18001 e ISO 16001, incluindo a integração destes diversos sistemas.',
];
$loginTexts += [
    'login_marketing_texto_secundario' => 'Completo suporte ao gerenciamento dos resíduos e efluentes industriais existentes, levantamento da legislação ambiental e trabalhista aplicável e orientação para adequar a empresa aos requisitos que não estejam sendo plenamente atendidos.',
    'login_marketing_top_note' => 'Gestão inteligente para o seu negócio',
    'login_marketing_feature_1' => 'Integração dos sistemas de gestão',
    'login_marketing_feature_2' => 'Gestão de resíduos e efluentes industriais',
    'login_marketing_feature_3' => 'Orientação ambiental e trabalhista',
    'login_marketing_rodape_esquerdo' => ApplicationIdentity::NAME,
    'login_marketing_rodape_direito' => 'GESTÃO QUE TRANSFORMA',
];
// Padrões no idioma escolhido (Administração › Textos e idiomas, grupo LOGIN); Configurações › Login vale sobre eles.
foreach (array_keys($loginTexts) as $chaveLogin) {
    $chaveIdioma = 'login.' . substr($chaveLogin, 6);
    if (isset($textosLogin[$chaveIdioma])) $loginTexts[$chaveLogin] = $TL($chaveIdioma);
}
try {
    $env = [];
    foreach (file(__DIR__ . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (!array_key_exists($key, $env)) {
            $env[$key] = trim(trim($value), "\"'");
        }
    }
    $pdo = GreenPortalDatabase::connect($env, $env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp', 'GUNIVERSAL');
    $empresasAtivas = GreenPortalDatabase::call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela' => 'vw_empresas_login_migradas', 'filtros' => json_encode([], JSON_UNESCAPED_UNICODE)]);
    usort($empresasAtivas, static fn(array $a, array $b): int => strcasecmp((string)($a['nome'] ?? ''), (string)($b['nome'] ?? '')));
    $setupDatabase = $env['GREEN_PORTAL_CENTRAL_DATABASE'] ?? 'gmodelo_dbp';
    $setupPdo = $setupDatabase === ($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp') ? $pdo : GreenPortalDatabase::connect($env, $setupDatabase, 'GPT');
    foreach (GreenPortalDatabase::call($setupPdo, 'CALL sp_crud_select(:tabela, :filtros, NULL, 1000, 0)', ['tabela' => 'vw_setup_empresa_grid', 'filtros' => json_encode(['idioma' => 'pt'], JSON_UNESCAPED_UNICODE)]) as $setupRow) {
        if ($idioma === 'pt' && array_key_exists((string)$setupRow['chave'], $loginTexts) && (string)$setupRow['valor'] !== '') {
            $loginTexts[(string)$setupRow['chave']] = (string)$setupRow['valor'];
        }
        // Antes da autenticação não existe preferência individual disponível. O login usa o tema-base do portal;
        // depois da autenticação, main.php aplica a preferência do usuário.
    }
    if ($idioma !== 'pt') {
        // Textos da empresa cadastrados para o idioma escolhido (Configurações › Login).
        foreach (GreenPortalDatabase::call($setupPdo ?? $pdo, 'CALL sp_crud_select(:tabela, :filtros, NULL, 1000, 0)', ['tabela' => 'vw_setup_empresa_grid', 'filtros' => json_encode(['idioma' => $idioma], JSON_UNESCAPED_UNICODE)]) as $setupRow) {
            if (array_key_exists((string)$setupRow['chave'], $loginTexts) && !in_array((string)$setupRow['chave'], ['login_marca', 'login_marketing_rodape_esquerdo'], true) && (string)$setupRow['valor'] !== '') {
                $loginTexts[(string)$setupRow['chave']] = (string)$setupRow['valor'];
            }
        }
    }
    if (trim($loginTexts['login_marca']) === 'Green Portal') $loginTexts['login_marca'] = ApplicationIdentity::LABEL;
    if (trim($loginTexts['login_marketing_rodape_esquerdo']) === 'GREEN PORTAL') $loginTexts['login_marketing_rodape_esquerdo'] = ApplicationIdentity::NAME;
    if ($setupPdo !== $pdo) $setupPdo = null;
} catch (Throwable $exception) {
    error_log('Green Portal company list error: ' . $exception->getMessage());
}
?>
<!doctype html>
<html lang="<?php echo $idioma === 'pt' ? 'pt-BR' : $idioma; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Acesso ao <?php echo htmlspecialchars(ApplicationIdentity::LABEL, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="application-name" content="<?php echo htmlspecialchars(ApplicationIdentity::LABEL, ENT_QUOTES, 'UTF-8'); ?>">
    <title><?php echo htmlspecialchars(ApplicationIdentity::title($TL('login.titulo_pagina')), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/x-icon" href="imagens/GreenIco.ico">

    <link href="assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet">
    <link href="assets/css/app.css?v=3" rel="stylesheet">
    <link href="assets/css/login-themes.css" rel="stylesheet">
    <link href="assets/css/design-system.css?v=4" rel="stylesheet">
</head>
<body class="login-page-body portal-shell theme-<?php echo htmlspecialchars($loginTheme, ENT_QUOTES, 'UTF-8'); ?> login-theme-<?php echo htmlspecialchars($loginTheme, ENT_QUOTES, 'UTF-8'); ?>">
<main class="login-page">
    <section class="login-panel" aria-labelledby="login-title">
        <div class="login-content">
            <a class="brand" href="/" aria-label="Green Portal - início">
                <span class="brand-mark" aria-hidden="true">
                    <img src="imagens/greenlogo.jpeg" alt="">
                </span>
                <span class="brand-name"><?php echo htmlspecialchars($loginTexts['login_marca'], ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
            <div class="login-empresa-logo" id="loginEmpresaLogo" hidden style="margin:-4px 0 14px"><img alt="" style="max-height:56px;max-width:220px;object-fit:contain"></div>

            <div class="login-heading">
                <?php if (isset($_GET['erro'])): ?>
                    <?php $loginError = preg_replace('/[^a-z0-9_]/i', '', (string)($_GET['erro'] ?? '')); $loginErrorKey = array_key_exists('login.erro_' . $loginError, $textosLogin) ? 'login.erro_' . $loginError : 'login.erro'; ?>
                    <div class="alert alert-danger login-alert" role="alert"><?php echo $loginError === 'sessao_expirada' ? $eL('login.erro_sessao') : $eL($loginErrorKey); ?></div>
                <?php endif; ?>
            </div>

            <?php if ($abrirAlerta > 0): ?><div class="alert alert-info login-alert" role="status"><?php echo htmlspecialchars(isset($_GET['outra_empresa']) ? IdiomaService::t('login.alerta_outra_empresa', ['pt' => 'Este alerta é de outra empresa: entre nela para abrir.', 'en' => 'This alert belongs to another company: sign in to it to open it.', 'es' => 'Esta alerta es de otra empresa: entre en ella para abrirla.']) : IdiomaService::t('login.alerta_entrar', ['pt' => 'Entre para abrir o alerta do e-mail.', 'en' => 'Sign in to open the alert from the e-mail.', 'es' => 'Entre para abrir la alerta del correo.']), ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <form class="login-form" action="credenciais/logar.php" method="post">
                <?php if ($abrirAlerta > 0): ?><input type="hidden" name="abrir_alerta" value="<?php echo $abrirAlerta; ?>"><?php endif; ?>
                <div class="mb-3">
                    <label class="form-label" for="empresa"><?php echo htmlspecialchars($loginTexts['login_label_empresa'], ENT_QUOTES, 'UTF-8'); ?></label>
                    <div class="input-wrap">
                        <span class="input-icon" aria-hidden="true">⌂</span>
                        <select class="form-select form-control" id="empresa" name="empresa" autocomplete="organization" required>
                            <option value="" disabled <?php echo empty($empresasAtivas) ? 'selected' : ''; ?>><?php echo $eL('login.selecione_empresa'); ?></option>
                            <?php foreach ($empresasAtivas as $empresa): ?>
                                <option value="<?php echo htmlspecialchars((string)$empresa['codigo'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($empresaLink !== '' ? strcasecmp((string)$empresa['codigo'], $empresaLink) === 0 : $empresa['codigo'] === 'MODELO') ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$empresa['nome'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="userid"><?php echo htmlspecialchars($loginTexts['login_label_usuario'], ENT_QUOTES, 'UTF-8'); ?></label>
                    <div class="input-wrap">
                        <span class="input-icon" aria-hidden="true">○</span>
                        <input class="form-control" type="text" id="userid" name="userid" placeholder="<?php echo $eL('login.placeholder_usuario'); ?>" autocomplete="username" required>
                    </div>
                </div>

                <div class="mb-2">
                    <label class="form-label" for="senha"><?php echo htmlspecialchars($loginTexts['login_label_senha'], ENT_QUOTES, 'UTF-8'); ?></label>
                    <div class="input-wrap">
                        <span class="input-icon lock-icon" aria-hidden="true">◆</span>
                        <input class="form-control password-input" type="password" id="senha" name="senha" placeholder="<?php echo $eL('login.placeholder_senha'); ?>" autocomplete="current-password" required>
                        <button class="password-toggle" type="button" data-password-toggle aria-label="<?php echo $eL('login.mostrar_senha'); ?>" data-texto-mostrar="<?php echo $eL('login.mostrar'); ?>" data-texto-ocultar="<?php echo $eL('login.ocultar'); ?>" data-dica-mostrar="<?php echo $eL('login.mostrar_senha'); ?>" data-dica-ocultar="<?php echo $eL('login.ocultar_senha'); ?>"><?php echo $eL('login.mostrar'); ?></button>
                    </div>
                </div>

                <div class="language-row">
                    <label class="form-label" for="idioma"><?php echo $eL('login.label_idioma'); ?></label>
                    <select class="form-select language-select" id="idioma" name="idioma" aria-label="<?php echo $eL('login.dica_idioma'); ?>" data-recarregar-idioma>
                        <?php foreach (IdiomaService::NOMES as $codigoIdioma => $nomeIdioma): ?>
                        <option value="<?php echo $codigoIdioma; ?>"<?php echo $codigoIdioma === $idioma ? ' selected' : ''; ?>><?php echo strtoupper($codigoIdioma); ?>&nbsp;&ndash;&nbsp;<?php echo htmlspecialchars($nomeIdioma, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-options">
                    <label class="remember-me" for="lembrar">
                        <input type="checkbox" id="lembrar" name="lembrar" value="1">
                        <span class="custom-check" aria-hidden="true"></span>
                        <span><?php echo $eL('login.lembrar'); ?></span>
                    </label>
                    <a href="#" class="form-link"><?php echo $eL('login.esqueci'); ?></a>
                </div>

                <button class="btn btn-login" type="submit"><?php echo htmlspecialchars($loginTexts['login_botao_entrar'], ENT_QUOTES, 'UTF-8'); ?> <span aria-hidden="true">→</span></button>
            </form>

            <p class="login-footer">© <?php echo date('Y'); ?> <?php echo htmlspecialchars(ApplicationIdentity::LABEL, ENT_QUOTES, 'UTF-8'); ?>. <?php echo $eL('login.direitos'); ?></p>
        </div>
    </section>

    <section class="marketing-panel" aria-label="Informações do <?php echo htmlspecialchars(ApplicationIdentity::LABEL, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="marketing-glow marketing-glow-one"></div>
        <div class="marketing-glow marketing-glow-two"></div>
        <div class="marketing-content">
            <div class="top-note"><span class="pulse-dot"></span> <?php echo htmlspecialchars($loginTexts['login_marketing_top_note'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="marketing-copy">
                <p class="eyebrow light-eyebrow"><?php echo htmlspecialchars($loginTexts['login_marketing_eyebrow'], ENT_QUOTES, 'UTF-8'); ?></p>
                <h2><?php echo nl2br(htmlspecialchars($loginTexts['login_marketing_titulo'], ENT_QUOTES, 'UTF-8')); ?></h2>
                <p class="marketing-text"><?php echo htmlspecialchars($loginTexts['login_marketing_texto'], ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="marketing-text marketing-text-secondary"><?php echo htmlspecialchars($loginTexts['login_marketing_texto_secundario'], ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="feature-list">
                <div class="feature-item"><span class="feature-icon">✓</span><span><?php echo htmlspecialchars($loginTexts['login_marketing_feature_1'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div class="feature-item"><span class="feature-icon">✓</span><span><?php echo htmlspecialchars($loginTexts['login_marketing_feature_2'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div class="feature-item"><span class="feature-icon">✓</span><span><?php echo htmlspecialchars($loginTexts['login_marketing_feature_3'], ENT_QUOTES, 'UTF-8'); ?></span></div>
            </div>
            <div class="leaf-art" aria-hidden="true">
                <span class="leaf leaf-a"></span><span class="leaf leaf-b"></span><span class="leaf leaf-c"></span>
                <span class="leaf leaf-d"></span><span class="leaf leaf-e"></span>
            </div>
            <div class="marketing-bottom"><span><?php echo htmlspecialchars($loginTexts['login_marketing_rodape_esquerdo'], ENT_QUOTES, 'UTF-8'); ?></span><span><?php echo htmlspecialchars($loginTexts['login_marketing_rodape_direito'], ENT_QUOTES, 'UTF-8'); ?></span></div>
        </div>
    </section>
</main>

<script src="assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script>
<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script>
<script src="assets/js/app.js?v=2"></script>
<script>
    (function () {
        var texts = <?php echo json_encode($loginTexts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        var setText = function (selector, value) { var nodes = document.querySelectorAll(selector); nodes.forEach(function (node) { node.textContent = value || ''; }); };
        setText('.top-note', texts.login_marketing_top_note);
        setText('.marketing-text-secondary', texts.login_marketing_texto_secundario);
        var features = document.querySelectorAll('.feature-list .feature-item span:last-child');
        [texts.login_marketing_feature_1, texts.login_marketing_feature_2, texts.login_marketing_feature_3].forEach(function (value, index) { if (features[index]) features[index].textContent = value || ''; });
        var footer = document.querySelectorAll('.marketing-bottom span');
        if (footer[0]) footer[0].textContent = texts.login_marketing_rodape_esquerdo || '';
        if (footer[1]) footer[1].textContent = texts.login_marketing_rodape_direito || '';
    }());
</script>
<script>
    (function () {
        var storageKey = 'greenportal.login.context';
        var company = document.getElementById('empresa');
        var user = document.getElementById('userid');
        var form = document.querySelector('.login-form');
        if (!company || !user || !form) return;
        try {
            var saved = JSON.parse(window.localStorage.getItem(storageKey) || '{}');
            if (saved.empresa && Array.prototype.some.call(company.options, function (option) { return option.value === saved.empresa; })) company.value = saved.empresa;
            if (saved.userid) user.value = saved.userid;
        } catch (error) {}
        form.addEventListener('submit', function () {
            try { window.localStorage.setItem(storageKey, JSON.stringify({ empresa: company.value, userid: user.value })); } catch (error) {}
        });
        // GER-1 (12/11/2026): logo do cliente na tela de login, quando a empresa escolhida tem logo (Setup › Layout / cabeçalho).
        var logoBox = document.getElementById('loginEmpresaLogo'), logoImg = logoBox ? logoBox.querySelector('img') : null;
        function mostrarLogo() {
            if (!logoImg) return;
            logoBox.hidden = true;
            if (!company.value) { logoImg.removeAttribute('src'); return; }
            logoImg.src = 'logo_empresa.php?e=' + encodeURIComponent(company.value);
        }
        if (logoImg) {
            logoImg.addEventListener('load', function () { logoBox.hidden = false; });
            logoImg.addEventListener('error', function () { logoBox.hidden = true; });
            company.addEventListener('change', mostrarLogo);
            mostrarLogo();
        }
    }());
</script>
</body>
</html>
