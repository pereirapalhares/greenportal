<?php
declare(strict_types=1);
session_start();
require_once dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'UsuarioSessao.php';
require_once dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'GreenPortalDatabase.php';

$script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/principal/setup/login.php'));
$baseUrl = basename($script) === 'setup_login.php' ? dirname(dirname($script)) : dirname(dirname(dirname($script)));
$baseUrl = $baseUrl === '/' || $baseUrl === '.' ? '' : rtrim($baseUrl, '/');

if (empty($_SESSION['autenticado'])) {
    header('Location: ' . $baseUrl . '/index.php');
    exit;
}
if (($_SESSION['usuario']['codigo_perfil'] ?? '') !== 'ADMINISTRADOR') {
    http_response_code(403);
    exit('Acesso negado.');
}

$sessionConfig = [];
foreach (file(dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) { if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue; [$key, $value] = explode('=', $line, 2); if (!array_key_exists(trim($key), $sessionConfig)) $sessionConfig[trim($key)] = trim(trim($value), "\"'"); }
$sessionPdo = GreenPortalDatabase::connect($sessionConfig, (string)$_SESSION['tenant']['banco_dados'], 'GPT');
if (!UsuarioSessao::atualizar($sessionPdo, $_SESSION)) { header('Location: ' . $baseUrl . '/index.php?erro=sessao_expirada'); exit; }
if (($_SESSION['usuario']['codigo_perfil'] ?? '') !== 'ADMINISTRADOR') { http_response_code(403); exit('Acesso negado.'); }

function setupEnv(): array
{
    $values = [];
    foreach (file(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        if (!array_key_exists(trim($key), $values)) $values[trim($key)] = trim(trim($value), "\"'");
    }
    return $values;
}

$config = setupEnv();
$tenantDatabase = (string)($_SESSION['tenant']['banco_dados'] ?? '');
$pdo = GreenPortalDatabase::connect($config, $tenantDatabase, 'GPT');

$fields = [
    'login_eyebrow' => ['label' => 'Texto superior', 'default' => 'Bem-vindo de volta'],
    'login_titulo' => ['label' => 'Título', 'default' => 'Acesse seu portal'],
    'login_subtitulo' => ['label' => 'Subtítulo', 'default' => 'Entre com seus dados para continuar.'],
    'login_label_empresa' => ['label' => 'Label empresa', 'default' => 'Empresa'],
    'login_label_usuario' => ['label' => 'Label usuário', 'default' => 'Usuário'],
    'login_label_senha' => ['label' => 'Label senha', 'default' => 'Senha'],
    'login_botao_entrar' => ['label' => 'Botão entrar', 'default' => 'Entrar'],
    'login_marketing_eyebrow' => ['label' => 'Texto superior marketing', 'default' => 'Um novo jeito de gerenciar'],
    'login_marketing_titulo' => ['label' => 'Título marketing', 'default' => 'Sistemas de gestão. Mais conformidade.'],
    'login_marketing_texto' => ['label' => 'Texto marketing', 'default' => 'Sistemas de Gestão em conformidade com as normas ISO 14001, OHSAS 18001 e ISO 16001.'],
];

$messages = [];
$values = [];
try {
    foreach (GreenPortalDatabase::call($pdo, 'CALL sp_crud_select(:tabela, :filtros, NULL, 100, 0)', [
        'tabela' => 'vw_setup_empresa_grid', 'filtros' => json_encode(['idioma' => 'pt'], JSON_UNESCAPED_UNICODE)
    ]) as $row) {
        $values[(string)$row['chave']] = ['value' => (string)($row['valor'] ?? ''), 'id' => (int)$row['codsetupempresa']];
    }

    foreach ($fields as $key => $field) {
        if (!isset($values[$key])) $values[$key] = ['value' => $field['default'], 'id' => null];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach ($fields as $key => $field) {
            $value = trim((string)($_POST[$key] ?? ''));
            if ($values[$key]['id']) {
                GreenPortalDatabase::call($pdo, 'CALL sp_crud_update(:tabela, :chave, :dados)', [
                    'tabela' => 'setup_empresa',
                    'chave' => json_encode(['codsetupempresa' => $values[$key]['id']], JSON_UNESCAPED_UNICODE),
                    'dados' => json_encode(['valor' => $value, 'idioma' => 'pt'], JSON_UNESCAPED_UNICODE)
                ]);
            } else {
                GreenPortalDatabase::call($pdo, 'CALL sp_crud_insert(:tabela, :dados)', [
                    'tabela' => 'setup_empresa',
                    'dados' => json_encode(['chave' => $key, 'valor' => $value, 'idioma' => 'pt'], JSON_UNESCAPED_UNICODE)
                ]);
            }
            $values[$key]['value'] = $value;
        }
        $messages[] = ['type' => 'success', 'text' => 'Configurações da tela de login salvas com sucesso.'];
    }
} catch (Throwable $exception) {
    error_log('Green Portal setup login error: ' . $exception->getMessage());
    $messages[] = ['type' => 'danger', 'text' => 'Não foi possível salvar as configurações.'];
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Green Portal | Setup Login</title>
    <link rel="icon" type="image/x-icon" href="<?php echo $baseUrl; ?>/imagens/GreenIco.ico">
    <link href="../../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/app.css" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/main.css?v=15" rel="stylesheet">
</head>
<body class="portal-shell setup-page">
<div class="portal-page">
    <header class="portal-header"><div class="header-brand-group"><button class="sidebar-toggle" type="button" onclick="history.back()" aria-label="Voltar"><i class="bi bi-arrow-left"></i></button><a class="brand" href="<?php echo $baseUrl; ?>/principal/main.php"><span class="brand-mark"><img src="<?php echo $baseUrl; ?>/imagens/greenlogo.jpeg" alt=""></span><span class="brand-name">Green <strong>Portal</strong></span></a></div><div class="header-actions"><div class="user-menu"><span class="user-avatar">A</span><span><strong><?php echo htmlspecialchars((string)$_SESSION['usuario']['nome'], ENT_QUOTES, 'UTF-8'); ?></strong><small>Administrador</small></span></div></div></header>
    <main class="setup-content">
        <div class="setup-title"><div><p class="eyebrow">Configuração do sistema</p><h1>Setup / Login</h1><p>Personalize os textos exibidos na tela de acesso da empresa <?php echo htmlspecialchars((string)$_SESSION['tenant']['nome'], ENT_QUOTES, 'UTF-8'); ?>.</p></div><a class="btn btn-outline-success" href="<?php echo $baseUrl; ?>/principal/main.php"><i class="bi bi-arrow-left me-2"></i>Voltar</a></div>
        <?php foreach ($messages as $message): ?><div class="alert alert-<?php echo $message['type']; ?>" role="alert"><?php echo htmlspecialchars($message['text'], ENT_QUOTES, 'UTF-8'); ?></div><?php endforeach; ?>
        <form method="post" id="setupLoginForm" class="setup-form">
            <div class="setup-card"><div class="setup-card-heading"><i class="bi bi-box-arrow-in-right"></i><div><h2>Textos do login</h2><p>Idioma: Português (PT)</p></div></div><div class="setup-fields">
                <?php foreach ($fields as $key => $field): ?><div class="setup-field <?php echo $key === 'login_marketing_texto' ? 'setup-field-wide' : ''; ?>"><label for="<?php echo $key; ?>"><?php echo htmlspecialchars($field['label'], ENT_QUOTES, 'UTF-8'); ?></label><?php if ($key === 'login_marketing_texto'): ?><textarea id="<?php echo $key; ?>" name="<?php echo $key; ?>" rows="3"><?php echo htmlspecialchars($values[$key]['value'], ENT_QUOTES, 'UTF-8'); ?></textarea><?php else: ?><input type="text" id="<?php echo $key; ?>" name="<?php echo $key; ?>" value="<?php echo htmlspecialchars($values[$key]['value'], ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?></div><?php endforeach; ?>
            </div></div>
            <div class="setup-actions"><button class="btn btn-outline-success" type="button" data-preview-login><i class="bi bi-eye me-2"></i>Preview</button><button class="btn btn-login setup-save" type="submit"><i class="bi bi-check2 me-2"></i>Salvar</button></div>
        </form>
    </main>
    <footer class="portal-footer"><span>&copy; <?php echo date('Y'); ?> Green Portal</span><span><?php echo htmlspecialchars((string)$_SESSION['tenant']['nome'], ENT_QUOTES, 'UTF-8'); ?></span></footer>
</div>
<div class="modal fade" id="loginPreviewModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Preview da tela de login</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"><div class="preview-login"><div class="preview-login-form"><span class="eyebrow" id="previewEyebrow">Bem-vindo de volta</span><h2 id="previewTitle">Acesse seu portal</h2><p id="previewSubtitle">Entre com seus dados para continuar.</p><div class="preview-input">Empresa</div><div class="preview-input">Usuário</div><div class="preview-input">Senha</div><button type="button" id="previewButton">Entrar</button></div><div class="preview-login-marketing"><span class="eyebrow light-eyebrow" id="previewMarketingEyebrow">Um novo jeito de gerenciar</span><h2 id="previewMarketingTitle">Sistemas de gestão. Mais conformidade.</h2><p id="previewMarketingText">Sistemas de Gestão em conformidade com as normas ISO 14001, OHSAS 18001 e ISO 16001.</p></div></div></div></div></div></div>
<script src="../../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo $baseUrl; ?>/assets/js/setup-login.js"></script>
</body>
</html>
