<?php
declare(strict_types=1);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
session_start();

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'UsuarioSessao.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'MenuService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'ApplicationIdentity.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'TenantContext.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'AuditService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'IdiomaService.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'popupmensagem.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'popuptransacao.php';

$script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/principal/main.php'));
$baseUrl = str_replace('\\', '/', dirname(dirname($script)));
$baseUrl = $baseUrl === '/' || $baseUrl === '.' ? '' : rtrim($baseUrl, '/');

if (empty($_SESSION['autenticado'])) {
    header('Location: ' . $baseUrl . '/index.php');
    exit;
}

$config = [];
foreach (file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    if (!array_key_exists(trim($key), $config)) $config[trim($key)] = trim(trim($value), "\"'");
}
$tenantDatabase = TenantContext::database($_SESSION);
$sessionPdo = new PDO('mysql:host=' . ($config['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($config['GPT_DB_PORT'] ?? '3306') . ';dbname=' . $tenantDatabase . ';charset=utf8mb4', $config['GPT_DB_USERNAME'] ?? '', $config['GPT_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (!UsuarioSessao::atualizar($sessionPdo, $_SESSION)) {
    header('Location: ' . $baseUrl . '/index.php?erro=sessao_expirada');
    exit;
}
AuditService::record($sessionPdo, 'MAIN', 'ACESSAR', 'SUCESSO', 'sessao', (string)($_SESSION['usuario']['codusuario'] ?? ''), ['path' => (string)($_SERVER['REQUEST_URI'] ?? '')]);

$usuario = $_SESSION['usuario'];
// Migração 343: link "Abrir no portal" do e-mail de alerta (abrir_alerta.php): só alerta do próprio usuário; abre a tela como aba e marca lido
$abrirAlertaJs = null;
if (($codAbrir = max(0, (int)($_GET['abrir_alerta'] ?? 0))) > 0) {
    try {
        $s = $sessionPdo->prepare('CALL sp_crud_select(?, ?, NULL, 1, 0)');
        $s->execute(['vw_alertas_grid', json_encode(['codusuario' => ['value' => (string)(int)($usuario['codusuario'] ?? 0), 'exact' => true], 'codalerta' => ['value' => (string)$codAbrir, 'exact' => true]])]);
        $al = $s->fetch(PDO::FETCH_ASSOC) ?: null; $s->closeCursor();
        if ($al && (int)$al['codalerta'] === $codAbrir && (int)$al['codusuario'] === (int)($usuario['codusuario'] ?? 0)) {
            $lk = trim((string)($al['link'] ?? ''));
            if ($lk === '' || !preg_match('#^/[A-Za-z0-9_./-]+(\?[^\s"\'<>]*)?$#', $lk) || str_contains($lk, '..') || str_starts_with($lk, '//')) $lk = '/principal/home_alertas.php';
            $titulo = trim((string)($al['titulo'] ?? '')); $titulo = mb_strlen($titulo) > 40 ? mb_substr($titulo, 0, 40) . '…' : $titulo;
            $abrirAlertaJs = ['id' => 'alerta-' . $codAbrir, 'url' => $baseUrl . $lk, 'titulo' => $titulo !== '' ? $titulo : 'Alerta'];
            require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'AlertasService.php';
            (new AlertasService($sessionPdo, (int)$usuario['codusuario']))->marcarLido($codAbrir);
        }
    } catch (Throwable $e) { error_log('[abrir alerta] ' . $e->getMessage()); }
}
$tenant = $_SESSION['tenant'];
// GER-1 (12/11/2026): logo da empresa e foto do usuário no cabeçalho (classes/UsuarioImagem.php)
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'UsuarioImagem.php';
$logoVersao = 0; $fotoVersao = 0;
try { $logoVersao = UsuarioImagem::logoVersao($tenantDatabase); $fotoVersao = UsuarioImagem::fotoVersao($tenantDatabase, (int)($usuario['codusuario'] ?? 0)); } catch (Throwable $e) { error_log('[cabecalho imagens] ' . $e->getMessage()); }
$podeTrocarLogo = in_array(strtoupper(trim((string)($usuario['codigo_perfil'] ?? ''))), ['ADMINISTRADOR', 'ADM_DA_EMPRESA', 'ADM DA EMPRESA', 'ADM EMPRESA'], true);
$mainTexts = [
    'main_marca_texto' => ApplicationIdentity::LABEL,
    'main_cabecalho_texto' => '',
    'main_rodape_texto' => ApplicationIdentity::LABEL . ' - Todos os direitos reservados.',
];
$layoutTema = 'verde';
$sessionTimeoutMinutes = 30;
$mainSetup = $sessionPdo->prepare('CALL sp_crud_select(:tabela, :filtros, NULL, 1000, 0)');
$mainSetup->execute(['tabela' => 'vw_setup_empresa_grid', 'filtros' => json_encode(['idioma' => 'pt'], JSON_UNESCAPED_UNICODE)]);
foreach ($mainSetup->fetchAll(PDO::FETCH_ASSOC) as $setupRow) {
    $setupKey = (string)$setupRow['chave'];
    $setupValue = (string)$setupRow['valor'];
    if ($setupKey === 'main_cabecalho_texto' && mb_strtolower(trim($setupValue), 'UTF-8') === 'área de trabalho') {
        $setupValue = '';
    }
    if (array_key_exists($setupKey, $mainTexts) && $setupValue !== '') {
        $mainTexts[$setupKey] = $setupValue;
    }
    if ($setupKey === 'layout_tema' && in_array($setupValue, ['verde', 'azul', 'cinza'], true)) $layoutTema = $setupValue;
    if ($setupKey === 'sessao_timeout_minutos') $sessionTimeoutMinutes = max(1, min(1440, (int)$setupValue));
}
$mainSetup->closeCursor();
// Textos de Configurações › Main no idioma do usuário, quando a empresa os cadastrou nesse idioma.
if (IdiomaService::idioma() !== 'pt') {
    try {
        $mainSetupIdioma = $sessionPdo->prepare('CALL sp_crud_select(:tabela, :filtros, NULL, 1000, 0)');
        $mainSetupIdioma->execute(['tabela' => 'vw_setup_empresa_grid', 'filtros' => json_encode(['idioma' => IdiomaService::idioma()], JSON_UNESCAPED_UNICODE)]);
        foreach ($mainSetupIdioma->fetchAll(PDO::FETCH_ASSOC) as $setupRow) {
            $setupKey = (string)$setupRow['chave']; $setupValue = trim((string)$setupRow['valor']);
            if (in_array($setupKey, ['main_marca_texto', 'main_rodape_texto'], true) && $setupValue !== '') $mainTexts[$setupKey] = $setupValue;
        }
        $mainSetupIdioma->closeCursor();
    } catch (Throwable) {
        // Sem cadastro no idioma: vale o texto em português.
    }
}
if (trim($mainTexts['main_marca_texto']) === 'Green Portal') $mainTexts['main_marca_texto'] = ApplicationIdentity::LABEL;
if (trim($mainTexts['main_rodape_texto']) === 'Green Portal - Todos os direitos reservados.') $mainTexts['main_rodape_texto'] = ApplicationIdentity::LABEL . ' - Todos os direitos reservados.';
// Rodapé padrão ("<marca> - Todos os direitos reservados."): a frase segue o idioma; a marca cadastrada pela empresa é mantida (Administração › Textos e idiomas, grupo PORTAL).
if (preg_match('/^(.*?)\s*-\s*Todos os direitos reservados\.?$/u', trim($mainTexts['main_rodape_texto']), $rodapePadrao)) $mainTexts['main_rodape_texto'] = $rodapePadrao[1] . ' - ' . IdiomaService::t('portal.rodape_direitos', ['pt' => 'Todos os direitos reservados.', 'en' => 'All rights reserved.', 'es' => 'Todos los derechos reservados.']);
$userThemeStatement = $sessionPdo->prepare('CALL sp_crud_select(:tabela, :filtros, NULL, 1, 0)');
$userThemeStatement->execute(['tabela' => 'preferencias_usuario', 'filtros' => json_encode(['codusuario' => (int)$usuario['codusuario'], 'chave' => 'layout_tema'], JSON_UNESCAPED_UNICODE)]);
$userThemeRows = $userThemeStatement->fetchAll(PDO::FETCH_ASSOC);
$userThemeStatement->closeCursor();
if (in_array((string)($userThemeRows[0]['valor'] ?? ''), ['verde', 'azul', 'cinza'], true)) $layoutTema = (string)$userThemeRows[0]['valor'];
$defaultTransactionStatement = $sessionPdo->prepare('CALL sp_crud_select(:tabela, :filtros, NULL, 1, 0)');
$defaultTransactionStatement->execute(['tabela' => 'preferencias_usuario', 'filtros' => json_encode(['codusuario' => (int)$usuario['codusuario'], 'chave' => 'default_transacao'], JSON_UNESCAPED_UNICODE)]);
$defaultTransactionRows = $defaultTransactionStatement->fetchAll(PDO::FETCH_ASSOC);
$defaultTransactionStatement->closeCursor();
$defaultTransaction = (string)($defaultTransactionRows[0]['valor'] ?? 'menu-dashboard');

// Textos do cabeçalho e do menu (Administração › Textos e idiomas, grupo PORTAL) e escolha de idioma ao lado do usuário.
$idiomaAtual = IdiomaService::idioma();
$P = static fn(string $chave, array $padrao): string => htmlspecialchars(IdiomaService::t('portal.' . $chave, $padrao), ENT_QUOTES, 'UTF-8');
if (empty($_SESSION['idioma_troca_csrf'])) $_SESSION['idioma_troca_csrf'] = bin2hex(random_bytes(32));
// Pesquisa de documentos no cabeçalho (migração 264): Atlas e Modelo, para quem tem Visualizar em BUSCA_DOCUMENTOS.
$buscaLiberada = in_array((string)($_SESSION['tenant']['banco_dados'] ?? ''), ['gatlas_dbp', 'gmodelo_dbp'], true)
    && (strtoupper((string)($usuario['codigo_perfil'] ?? '')) === 'ADMINISTRADOR'
        || (bool)array_filter((array)($_SESSION['permissoes'] ?? []), static fn($p) => strtoupper((string)($p['menu_codigo'] ?? '')) === 'BUSCA_DOCUMENTOS' && !empty($p['pode_visualizar'])));
IdiomaService::nomeGrupo('portal', ['pt' => 'Cabeçalho e menu do portal', 'en' => 'Portal header and menu', 'es' => 'Encabezado y menú del portal']);
IdiomaService::nomeGrupo('login', ['pt' => 'Tela de login', 'en' => 'Sign-in screen', 'es' => 'Pantalla de acceso']);
// A tela de login não tem usuário identificado: os textos dela entram no cadastro automático quando um ADMINISTRADOR abre o portal.
if (strtoupper((string)($usuario['codigo_perfil'] ?? '')) === 'ADMINISTRADOR') {
    foreach ((require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'textos_login.php') as $chaveLogin => $padraoLogin) IdiomaService::textos($chaveLogin, $padraoLogin);
}
$menuByParent = (new MenuService($sessionPdo, (string)($_SESSION['idioma'] ?? 'pt'), $usuario, (array)($_SESSION['permissoes'] ?? [])))->carregar();
// Assistente "Como posso ajudar?" (migração 225): só aparece para os perfis liberados em ia_assistente_config.
$assistenteLiberado = false;
try {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'AssistenteAplicacaoService.php';
    $assistenteLiberado = (new AssistenteAplicacaoService($sessionPdo, $usuario, (array)($_SESSION['permissoes'] ?? []), IdiomaService::idioma()))->liberado();
} catch (Throwable $erroAssistente) {
    error_log('main.php assistente: ' . $erroAssistente->getMessage());
}
if ($assistenteLiberado && empty($_SESSION['assistente_ia_csrf'])) $_SESSION['assistente_ia_csrf'] = bin2hex(random_bytes(32));
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($_SESSION['idioma'] === 'pt' ? 'pt-BR' : $_SESSION['idioma'], ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="application-name" content="<?php echo htmlspecialchars(ApplicationIdentity::LABEL, ENT_QUOTES, 'UTF-8'); ?>">
    <title><?php echo htmlspecialchars(ApplicationIdentity::title('Principal'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/x-icon" href="<?php echo $baseUrl; ?>/imagens/GreenIco.ico?v=2">
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/app.css?v=6" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/main.css?v=17" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/design-system.css?v=3" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/popups.css?v=1" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/menu-scroll.css?v=2" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/alertas.css?v=2" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/minha-conta.css?v=3" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/footer.css" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/painel-direito.css" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/layout-estrutura.css?v=3" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/idioma-cabecalho.css?v=1" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/busca-cabecalho.css?v=2" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/cabecalho-imagens.css?v=1" rel="stylesheet">
    <?php if ($assistenteLiberado): ?><link href="<?php echo $baseUrl; ?>/assets/css/assistente.css?v=3" rel="stylesheet"><?php endif; ?>
</head>
<body class="portal-shell theme-<?php echo htmlspecialchars($layoutTema, ENT_QUOTES, 'UTF-8'); ?>">
<div class="portal-page">
    <header class="portal-header">
        <div class="header-brand-group">
            <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="<?php echo $P('expandir_menu', ['pt' => 'Expandir menu', 'en' => 'Expand menu', 'es' => 'Expandir menú']); ?>"><i class="bi bi-layout-sidebar-inset-reverse"></i></button>
            <a class="brand" href="<?php echo $baseUrl; ?>/principal/main.php" aria-label="Green Portal">
                <span class="brand-mark"><img src="<?php echo $baseUrl; ?>/imagens/greenlogo.jpeg" alt=""></span>
                <span class="brand-name"><?php echo htmlspecialchars($mainTexts['main_marca_texto'], ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
        </div>
        <?php /* Texto ao lado da marca ("| Mais Gestão. Mais conformidade.") retirado do cabeçalho a pedido. */ ?>
        <div class="header-actions">
            <?php if ($buscaLiberada): ?>
            <?php /* Lupa: abre (ou mostra) a aba "Pesquisa de documentos", que carrega vazia. */ ?>
            <button class="header-icon-button header-search-button" type="button" data-busca-cabecalho data-busca-url="<?php echo $baseUrl; ?>/principal/busca_documentos.php" data-busca-titulo="<?php echo $P('pesquisa_aba', ['pt' => 'Pesquisa de documentos', 'en' => 'Document search', 'es' => 'Búsqueda de documentos']); ?>" title="<?php echo $P('pesquisa_rotulo', ['pt' => 'Pesquisar documentos', 'en' => 'Search documents', 'es' => 'Buscar documentos']); ?>" aria-label="<?php echo $P('pesquisa_rotulo', ['pt' => 'Pesquisar documentos', 'en' => 'Search documents', 'es' => 'Buscar documentos']); ?>"><i class="bi bi-search" aria-hidden="true"></i></button>
            <?php endif; ?>
            <div class="dropdown alert-menu">
                <button class="header-icon-button" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?php echo $P('alertas', ['pt' => 'Alertas', 'en' => 'Alerts', 'es' => 'Alertas']); ?>">
                    <i class="bi bi-bell"></i><span class="alert-badge d-none" id="alertBadge">0</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end alert-dropdown">
                    <div class="sino-abas" role="tablist"><button type="button" class="sino-aba ativa" data-sino-aba="alertas" role="tab" aria-selected="true"><?php echo $P('alertas', ['pt' => 'Alertas', 'en' => 'Alerts', 'es' => 'Alertas']); ?> <span class="sino-aba-num" id="sinoNumAlertas"></span></button><button type="button" class="sino-aba" data-sino-aba="pendencias" role="tab" aria-selected="false"><?php echo $P('pendencias', ['pt' => 'Pendências', 'en' => 'Pending items', 'es' => 'Pendientes']); ?> <span class="sino-aba-num" id="sinoNumPendencias"></span></button></div><div class="alert-dropdown-header" data-sino-painel="alertas"><strong><?php echo $P('alertas', ['pt' => 'Alertas', 'en' => 'Alerts', 'es' => 'Alertas']); ?></strong><span class="alert-header-actions"><span id="alertCountLabel"><?php echo $P('alertas_zero', ['pt' => '0 novos', 'en' => '0 new', 'es' => '0 nuevas']); ?></span><button type="button" class="alert-clear-button" data-clear-alerts aria-label="<?php echo $P('limpar_alertas', ['pt' => 'Limpar alertas', 'en' => 'Clear alerts', 'es' => 'Borrar alertas']); ?>"><i class="bi bi-trash3"></i></button></span></div>
                    <div class="alert-list" id="alertList" data-sino-painel="alertas"><div class="alert-empty" id="alertEmpty"><i class="bi bi-check2-circle"></i><span><?php echo $P('sem_alertas', ['pt' => 'Nenhum alerta novo', 'en' => 'No new alerts', 'es' => 'Ninguna alerta nueva']); ?></span></div></div>
                    <div class="sino-pendencias" id="sinoPendencias" data-sino-painel="pendencias" hidden data-url="<?php echo htmlspecialchars($baseUrl . '/principal/api/pendencias_resumo.php', ENT_QUOTES, 'UTF-8'); ?>" data-home-url="<?php echo htmlspecialchars($baseUrl . '/principal/home_pendencias.php', ENT_QUOTES, 'UTF-8'); ?>"><div class="sino-pend-lista" id="sinoPendLista"><div class="alert-empty"><span><?php echo $P('carregando', ['pt' => 'Carregando...', 'en' => 'Loading...', 'es' => 'Cargando...']); ?></span></div></div><button type="button" class="sino-pend-todas" data-sino-todas><?php echo $P('pendencias_todas', ['pt' => 'Ver todas as minhas pendências', 'en' => 'See all my pending items', 'es' => 'Ver todos mis pendientes']); ?> <i class="bi bi-arrow-right"></i></button></div>
                </div>
            </div>
            <div class="header-divider"></div>
            <?php if ($assistenteLiberado): ?>
            <button class="header-icon-button header-assistant-button" type="button" data-assistente-alternar aria-controls="assistentePainel" title="<?php echo $P('assistente', ['pt' => 'Como posso ajudar?', 'en' => 'How can I help?', 'es' => '¿Cómo puedo ayudar?']); ?>" aria-label="<?php echo $P('assistente', ['pt' => 'Como posso ajudar?', 'en' => 'How can I help?', 'es' => '¿Cómo puedo ayudar?']); ?>"><i class="bi bi-stars" aria-hidden="true"></i></button>
            <?php endif; ?>
            <button class="header-icon-button header-help-button" type="button" data-open-ajuda title="<?php echo $P('ajuda_f1', ['pt' => 'Ajuda desta tela (F1)', 'en' => 'Help for this screen (F1)', 'es' => 'Ayuda de esta pantalla (F1)']); ?>" aria-label="<?php echo $P('ajuda_f1', ['pt' => 'Ajuda desta tela (F1)', 'en' => 'Help for this screen (F1)', 'es' => 'Ayuda de esta pantalla (F1)']); ?>"><i class="bi bi-question-circle" aria-hidden="true"></i></button>
            <a class="header-icon-button header-guide-link" href="<?php echo $baseUrl; ?>/principal/guia_usuario.php" data-open-user-guide aria-label="<?php echo $P('guia', ['pt' => 'Guia do usuário', 'en' => 'User guide', 'es' => 'Guía del usuario']); ?>">
                <i class="bi bi-book-half" aria-hidden="true"></i>
            </a>
            <div class="dropdown header-language-menu" data-idioma-csrf="<?php echo htmlspecialchars((string)$_SESSION['idioma_troca_csrf'], ENT_QUOTES, 'UTF-8'); ?>" data-idioma-api="<?php echo htmlspecialchars($baseUrl . '/principal/api/idioma.php', ENT_QUOTES, 'UTF-8'); ?>">
                <button class="header-icon-button header-language-button" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?php echo $P('idioma', ['pt' => 'Idioma', 'en' => 'Language', 'es' => 'Idioma']); ?>"><i class="bi bi-translate" aria-hidden="true"></i><span class="header-language-code"><?php echo strtoupper($idiomaAtual); ?></span></button>
                <div class="dropdown-menu dropdown-menu-end header-language-dropdown">
                    <div class="header-language-title"><?php echo $P('idioma', ['pt' => 'Idioma', 'en' => 'Language', 'es' => 'Idioma']); ?></div>
                    <?php foreach (IdiomaService::NOMES as $codigoIdioma => $nomeIdioma): ?>
                    <button type="button" class="dropdown-item<?php echo $codigoIdioma === $idiomaAtual ? ' active' : ''; ?>" data-trocar-idioma="<?php echo $codigoIdioma; ?>" aria-pressed="<?php echo $codigoIdioma === $idiomaAtual ? 'true' : 'false'; ?>"><span class="header-language-sigla"><?php echo strtoupper($codigoIdioma); ?></span><span><?php echo htmlspecialchars($nomeIdioma, ENT_QUOTES, 'UTF-8'); ?></span><i class="bi bi-check2" aria-hidden="true"></i></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php /* 19/11/2026: logo e nome da empresa ao lado esquerdo do nome do usuário (antes ficava no início dos ícones). */ ?>
            <div class="header-divider"></div>
            <div class="company-chip<?php echo $logoVersao ? ' tem-logo' : ''; ?><?php echo $podeTrocarLogo ? ' pode-trocar' : ''; ?>"<?php if ($podeTrocarLogo): ?> role="button" tabindex="0" data-empresa-logo-abrir="<?php echo htmlspecialchars($baseUrl . '/principal/empresa_logo.php', ENT_QUOTES, 'UTF-8'); ?>" data-empresa-logo-titulo="<?php echo $P('logo_empresa', ['pt' => 'Logo da empresa', 'en' => 'Company logo', 'es' => 'Logo de la empresa']); ?>" title="<?php echo $P('logo_trocar', ['pt' => 'Clique para enviar ou trocar o logo da empresa', 'en' => 'Click to upload or change the company logo', 'es' => 'Haga clic para enviar o cambiar el logo de la empresa']); ?>"<?php endif; ?>><span class="company-logo"<?php echo $logoVersao ? '' : ' hidden'; ?>><img src="<?php echo $logoVersao ? htmlspecialchars($baseUrl . '/principal/empresa_logo_img.php?v=' . $logoVersao, ENT_QUOTES, 'UTF-8') : ''; ?>" alt="<?php echo htmlspecialchars((string)$tenant['nome'], ENT_QUOTES, 'UTF-8'); ?>"></span><span class="company-avatar"<?php echo $logoVersao ? ' hidden' : ''; ?>><?php echo strtoupper(substr((string)$tenant['nome'], 0, 1)); ?></span><span><small><?php echo $P('empresa', ['pt' => 'Empresa', 'en' => 'Company', 'es' => 'Empresa']); ?></small><strong><?php echo htmlspecialchars((string)$tenant['nome'], ENT_QUOTES, 'UTF-8'); ?></strong></span></div>
            <?php /* Menu do usuário: cada item abre a transação em popup (90% da tela, sempre na frente) — assets/js/minha-conta.js */ ?>
            <div class="dropdown user-dropdown">
                <div class="user-menu" role="button" tabindex="0" data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="menu" data-user-account><span class="user-avatar<?php echo $fotoVersao ? ' tem-foto' : ''; ?>" data-user-avatar="<?php echo (int)($usuario['codusuario'] ?? 0); ?>"<?php echo $fotoVersao ? ' style="background-image:url(' . htmlspecialchars($baseUrl . '/principal/usuario_foto.php?u=' . (int)$usuario['codusuario'] . '&v=' . $fotoVersao, ENT_QUOTES, 'UTF-8') . ')"' : ''; ?>><?php echo strtoupper(substr((string)$usuario['nome'], 0, 1)); ?></span><span><strong><?php echo htmlspecialchars((string)$usuario['nome'], ENT_QUOTES, 'UTF-8'); ?></strong><small><?php echo htmlspecialchars((string)$usuario['email'], ENT_QUOTES, 'UTF-8'); ?></small></span><i class="bi bi-chevron-down user-chevron"></i></div>
                <div class="dropdown-menu dropdown-menu-end user-account-dropdown" role="menu">
                    <div class="user-account-dropdown-header"><strong><?php echo htmlspecialchars((string)$usuario['nome'], ENT_QUOTES, 'UTF-8'); ?></strong><small><?php echo htmlspecialchars((string)$usuario['email'], ENT_QUOTES, 'UTF-8'); ?></small></div>
                    <?php foreach ([
                        ['profile', 'bi-person-vcard', $P('conta_profile', ['pt' => 'Profile', 'en' => 'Profile', 'es' => 'Perfil']), '/principal/meu_perfil.php'],
                        ['senha', 'bi-key', $P('conta_senha', ['pt' => 'Alterar senha', 'en' => 'Change password', 'es' => 'Cambiar contraseña']), '/principal/minha_conta.php?aba=senha'],
                        ['layout', 'bi-palette', $P('conta_layout', ['pt' => 'Layout', 'en' => 'Layout', 'es' => 'Diseño']), '/principal/setup_layout.php?pessoal=1'],
                        ['atribuicoes', 'bi-person-lines-fill', $P('conta_atribuicoes', ['pt' => 'Perfil de atribuições', 'en' => 'Responsibilities profile', 'es' => 'Perfil de atribuciones']), '/principal/perfil_atribuicoes.php'],
                        ['alertas_email', 'bi-envelope', $P('conta_alertas_email', ['pt' => 'Alertas por e-mail', 'en' => 'Alerts by e-mail', 'es' => 'Alertas por correo']), '/principal/minha_conta_alertas_email.php'],
                    ] as [$contaChave, $contaIcone, $contaRotulo, $contaUrl]): ?>
                    <button type="button" class="dropdown-item" role="menuitem" data-conta-abrir="<?php echo htmlspecialchars($baseUrl . $contaUrl, ENT_QUOTES, 'UTF-8'); ?>" data-conta-titulo="<?php echo $contaRotulo; ?>" data-conta-item="<?php echo $contaChave; ?>"><i class="bi <?php echo $contaIcone; ?>" aria-hidden="true"></i><span><?php echo $contaRotulo; ?></span></button>
                    <?php endforeach; ?>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item user-account-sair" role="menuitem" href="<?php echo $baseUrl; ?>/credenciais/sair.php"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span><?php echo $P('sair', ['pt' => 'Sair', 'en' => 'Sign out', 'es' => 'Salir']); ?></span></a>
                </div>
            </div>
        </div>
    </header>
    <div class="portal-layout" id="portalLayout">
        <aside class="portal-sidebar">
        <div class="menu-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" id="menuSearch" placeholder="<?php echo $P('buscar_menu', ['pt' => 'Buscar no menu', 'en' => 'Search the menu', 'es' => 'Buscar en el menú']); ?>" aria-label="<?php echo $P('buscar_menu', ['pt' => 'Buscar no menu', 'en' => 'Search the menu', 'es' => 'Buscar en el menú']); ?>" autocomplete="off"></div>
        <nav class="portal-menu" aria-label="<?php echo $P('menu_principal', ['pt' => 'Menu principal', 'en' => 'Main menu', 'es' => 'Menú principal']); ?>">
            <?php require __DIR__ . DIRECTORY_SEPARATOR . 'menu.php'; ?>
        </nav>
        <?php /* 16/11/2026: o Sair da barra lateral saiu (fica no menu do usuário, no topo); o menu usa a altura toda. */ ?>
    </aside>
        <section class="portal-workspace">
        <main class="workspace-content" id="workspaceContent">
            <div class="workspace-tabs" id="workspaceTabs" role="tablist"></div>
            <div class="workspace-tab-panes" id="workspaceTabPanes"></div>
            <button type="button" class="d-none" data-open-tab="home" data-tab-url="<?php echo htmlspecialchars($baseUrl . '/principal/home.php?v=20261012-2', ENT_QUOTES, 'UTF-8'); ?>" data-tab-title="<?php echo htmlspecialchars(['pt' => 'Home', 'en' => 'Home', 'es' => 'Inicio'][$_SESSION['idioma'] ?? 'pt'] ?? 'Home', ENT_QUOTES, 'UTF-8'); ?>"></button>
        </main>
        </section>
        <aside class="portal-right-rail portal-right-rail-frame" aria-label="<?php echo $P('informacoes_importantes', ['pt' => 'Informações importantes', 'en' => 'Important information', 'es' => 'Información importante']); ?>">
            <iframe src="<?php echo htmlspecialchars($baseUrl . '/principal/main_pendencias.php?v=20260822-2', ENT_QUOTES, 'UTF-8'); ?>" loading="eager"></iframe>
        </aside>
    </div>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'footer.php'; ?>
</div>
<?php /* Migração 353: ADMINISTRADOR vê no canto da tela quando um serviço do orquestrador está parado ou suspenso. */ require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'aviso_servicos.php'; greenPortalAvisoServicos($config, (array)$usuario, $baseUrl); ?>
<div class="toast-container position-fixed bottom-0 end-0 p-4"><div class="toast" id="constructionToast" role="alert"><div class="toast-body"><i class="bi bi-tools me-2"></i><span id="constructionMessage"><?php echo $P('em_construcao', ['pt' => 'Em construção', 'en' => 'Under construction', 'es' => 'En construcción']); ?></span></div></div></div>
<?php echo greenPortalPopupMensagem(); echo greenPortalPopupTransacao(); ?>
<?php if (false): ?>
<div class="modal fade" id="userAccountModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Minha conta</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"><div class="account-section"><h6>Alterar senha</h6><form id="accountPasswordForm"><input type="text" name="username" autocomplete="username" tabindex="-1" aria-hidden="true" class="visually-hidden"><input type="password" name="senha_atual" class="form-control form-control-sm mb-2" placeholder="Senha atual" autocomplete="current-password" required><input type="password" name="nova_senha" class="form-control form-control-sm mb-2" placeholder="Nova senha" minlength="4" autocomplete="new-password" required><input type="password" name="confirmar_senha" class="form-control form-control-sm" placeholder="Confirmar nova senha" minlength="4" autocomplete="new-password" required><button class="btn btn-sm btn-success mt-3" type="submit">Salvar senha</button></form></div><hr><div class="account-section"><h6>Meu layout</h6><div class="account-themes"><label><input type="radio" name="account_layout_tema" value="verde" checked> Verde pastel</label><label><input type="radio" name="account_layout_tema" value="azul"> Azul pastel</label><label><input type="radio" name="account_layout_tema" value="cinza"> Cinza pastel</label></div><button type="button" class="btn btn-sm btn-outline-success mt-3" data-save-account-layout>Salvar layout</button></div><hr><div class="account-section"><h6>Tela inicial</h6><select id="accountDefaultTransaction" class="form-select form-select-sm" aria-label="Escolher tela inicial"></select><button type="button" class="btn btn-sm btn-outline-success mt-3" data-save-account-default>Salvar tela inicial</button></div><div class="account-message mt-3" id="accountMessage" role="status"></div></div></div></div></div>
<?php endif; ?>
<?php if ($assistenteLiberado): ?>
<div class="offcanvas offcanvas-end assistente-painel" tabindex="-1" id="assistentePainel" data-bs-scroll="true" data-bs-backdrop="false" aria-labelledby="assistenteTitulo" data-api="<?php echo htmlspecialchars($baseUrl . '/principal/api/assistente_ia.php', ENT_QUOTES, 'UTF-8'); ?>" data-csrf="<?php echo htmlspecialchars((string)$_SESSION['assistente_ia_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
    <div class="offcanvas-header">
        <div class="assistente-titulo"><i class="bi bi-stars" aria-hidden="true"></i><div><strong id="assistenteTitulo"><?php echo $P('assistente', ['pt' => 'Como posso ajudar?', 'en' => 'How can I help?', 'es' => '¿Cómo puedo ayudar?']); ?></strong><small data-assistente-tela></small></div></div>
        <button type="button" class="assistente-icone" data-assistente-minhas-topo hidden aria-label="<?php echo $P('assistente_minhas', ['pt' => 'Minhas solicitações', 'en' => 'My requests', 'es' => 'Mis solicitudes']); ?>"><i class="bi bi-lightbulb" aria-hidden="true"></i></button>
        <button type="button" class="assistente-icone" data-assistente-nova title="<?php echo $P('assistente_nova', ['pt' => 'Nova conversa', 'en' => 'New conversation', 'es' => 'Nueva conversación']); ?>" aria-label="<?php echo $P('assistente_nova', ['pt' => 'Nova conversa', 'en' => 'New conversation', 'es' => 'Nueva conversación']); ?>"><i class="bi bi-plus-square" aria-hidden="true"></i></button>
        <button type="button" class="assistente-icone" data-bs-dismiss="offcanvas" aria-label="<?php echo $P('fechar', ['pt' => 'Fechar', 'en' => 'Close', 'es' => 'Cerrar']); ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="offcanvas-body">
        <div class="assistente-mensagens" data-assistente-mensagens role="log" aria-live="polite"></div>
        <form class="assistente-entrada" data-assistente-form autocomplete="off">
            <textarea rows="2" maxlength="1000" placeholder="<?php echo $P('assistente_placeholder', ['pt' => 'Pergunte sobre o uso do sistema...', 'en' => 'Ask about using the system...', 'es' => 'Pregunte sobre el uso del sistema...']); ?>" aria-label="<?php echo $P('assistente_pergunta', ['pt' => 'Sua pergunta', 'en' => 'Your question', 'es' => 'Su pregunta']); ?>"></textarea>
            <button type="submit" title="<?php echo $P('assistente_enviar', ['pt' => 'Enviar', 'en' => 'Send', 'es' => 'Enviar']); ?>" aria-label="<?php echo $P('assistente_enviar', ['pt' => 'Enviar', 'en' => 'Send', 'es' => 'Enviar']); ?>"><i class="bi bi-send" aria-hidden="true"></i></button>
        </form>
        <div class="assistente-rodape"><span data-assistente-uso></span><?php echo $P('assistente_aviso', ['pt' => 'Respostas geradas por IA podem conter erros. Não informe dados pessoais.', 'en' => 'AI-generated answers may contain errors. Do not share personal data.', 'es' => 'Las respuestas generadas por IA pueden contener errores. No informe datos personales.']); ?></div>
    </div>
</div>
<?php endif; ?>
<script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script>
    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script>
    <script src="../assets/js/popups.js?v=20"></script>
<script src="<?php echo $baseUrl; ?>/assets/js/guia-usuario.js?v=2"></script>
<script src="<?php echo $baseUrl; ?>/assets/js/ajuda.js?v=9"></script>
<script>window.greenPortalBaseUrl = <?php echo json_encode($baseUrl, JSON_UNESCAPED_SLASHES); ?>; window.greenPortalIdioma = <?php echo json_encode((string)($_SESSION['idioma'] ?? 'pt')); ?>; window.greenPortalTimeoutSeconds = <?php echo (int)$sessionTimeoutMinutes * 60; ?>; window.greenPortalDefaultTransaction = <?php echo json_encode($defaultTransaction); ?>; window.greenPortalPermissions = <?php echo json_encode(array_values((array)($_SESSION['permissoes'] ?? [])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;</script>
<script src="<?php echo $baseUrl; ?>/assets/js/sessao-expirada.js?v=1"></script>
<script src="<?php echo $baseUrl; ?>/assets/js/acentuacao.js?v=2"></script>
<?php echo IdiomaService::script([], [
    'portal.js.mensagem' => ['pt' => 'Mensagem', 'en' => 'Message', 'es' => 'Mensaje'],
    'portal.js.transacao' => ['pt' => 'Transação', 'en' => 'Transaction', 'es' => 'Transacción'],
    'portal.js.carregando' => ['pt' => 'Carregando...', 'en' => 'Loading...', 'es' => 'Cargando...'],
    'portal.js.redimensionar' => ['pt' => 'Redimensionar', 'en' => 'Resize', 'es' => 'Redimensionar'],
    'portal.js.maximizar' => ['pt' => 'Maximizar', 'en' => 'Maximize', 'es' => 'Maximizar'],
    'portal.js.minimizar' => ['pt' => 'Minimizar', 'en' => 'Minimize', 'es' => 'Minimizar'],
    'portal.js.restaurar' => ['pt' => 'Restaurar', 'en' => 'Restore', 'es' => 'Restaurar'],
    'portal.js.fechar' => ['pt' => 'Fechar', 'en' => 'Close', 'es' => 'Cerrar'],
    'portal.js.recolher_menu' => ['pt' => 'Recolher menu', 'en' => 'Collapse menu', 'es' => 'Contraer menú'],
    'portal.js.expandir_menu' => ['pt' => 'Expandir menu', 'en' => 'Expand menu', 'es' => 'Expandir menú'],
    'portal.js.em_construcao' => ['pt' => 'Em construção', 'en' => 'Under construction', 'es' => 'En construcción'],
    'portal.js.abrir_calendario' => ['pt' => 'Abrir calendário', 'en' => 'Open calendar', 'es' => 'Abrir calendario'],
    'portal.js.alertas_novo' => ['pt' => '{n} novo', 'en' => '{n} new', 'es' => '{n} nueva'],
    'portal.js.alertas_novos' => ['pt' => '{n} novos', 'en' => '{n} new', 'es' => '{n} nuevas'],
    'portal.js.sem_alertas' => ['pt' => 'Nenhum alerta novo', 'en' => 'No new alerts', 'es' => 'Ninguna alerta nueva'],
    'portal.js.minha_conta' => ['pt' => 'Minha conta', 'en' => 'My account', 'es' => 'Mi cuenta'],
] + ($assistenteLiberado ? [
    'assistente.js.boas_vindas' => ['pt' => 'Olá! Pergunte como fazer algo no sistema, onde fica uma tela ou o que significa uma mensagem. Eu só sugiro telas que o seu perfil pode abrir.', 'en' => 'Hi! Ask how to do something in the system, where a screen is or what a message means. I only suggest screens your profile can open.', 'es' => '¡Hola! Pregunte cómo hacer algo en el sistema, dónde está una pantalla o qué significa un mensaje. Solo sugiero pantallas que su perfil puede abrir.'],
    'assistente.js.sugestoes' => ['pt' => 'Sugestões', 'en' => 'Suggestions', 'es' => 'Sugerencias'],
    'assistente.js.uso' => ['pt' => '{u} de {l} perguntas hoje', 'en' => '{u} of {l} questions today', 'es' => '{u} de {l} preguntas hoy'],
    'assistente.js.tela' => ['pt' => 'Tela: {tela}', 'en' => 'Screen: {tela}', 'es' => 'Pantalla: {tela}'],
    'assistente.js.comecar_guia' => ['pt' => 'Começar o guia: {guia}', 'en' => 'Start the guide: {guia}', 'es' => 'Comenzar la guía: {guia}'],
    'assistente.js.abrir' => ['pt' => 'Abrir {tela}', 'en' => 'Open {tela}', 'es' => 'Abrir {tela}'],
    'assistente.js.necessidade' => ['pt' => 'Necessidade anotada para a equipe do produto: {texto}', 'en' => 'Need recorded for the product team: {texto}', 'es' => 'Necesidad registrada para el equipo del producto: {texto}'],
    'assistente.js.util' => ['pt' => 'Ajudou', 'en' => 'Helpful', 'es' => 'Me ayudó'],
    'assistente.js.nao_util' => ['pt' => 'Não ajudou', 'en' => 'Not helpful', 'es' => 'No me ayudó'],
    'assistente.js.sol_registrar' => ['pt' => 'Registrar como sugestão', 'en' => 'Register as a suggestion', 'es' => 'Registrar como sugerencia'],
    'assistente.js.sol_registrada' => ['pt' => 'Registrada como solicitação #{n}.', 'en' => 'Registered as request #{n}.', 'es' => 'Registrada como solicitud #{n}.'],
    'assistente.js.sol_ver' => ['pt' => 'Ver minhas solicitações', 'en' => 'View my requests', 'es' => 'Ver mis solicitudes'],
    'assistente.js.sol_minhas' => ['pt' => 'Minhas solicitações', 'en' => 'My requests', 'es' => 'Mis solicitudes'],
    'assistente.js.sol_redigindo' => ['pt' => 'Redigindo a solicitação...', 'en' => 'Writing the request...', 'es' => 'Redactando la solicitud...'],
    'assistente.js.sol_revise' => ['pt' => 'Revise o texto antes de registrar. A equipe do produto vai ler exatamente isto.', 'en' => 'Review the text before registering. The product team will read exactly this.', 'es' => 'Revise el texto antes de registrar. El equipo del producto leerá exactamente esto.'],
    'assistente.js.sol_titulo' => ['pt' => 'Título', 'en' => 'Title', 'es' => 'Título'],
    'assistente.js.sol_descricao' => ['pt' => 'Descrição', 'en' => 'Description', 'es' => 'Descripción'],
    'assistente.js.sol_beneficio' => ['pt' => 'Para que você precisa', 'en' => 'Why you need it', 'es' => 'Para qué lo necesita'],
    'assistente.js.sol_prioridade' => ['pt' => 'Importância para você', 'en' => 'Importance to you', 'es' => 'Importancia para usted'],
    'assistente.js.sol_baixa' => ['pt' => 'Baixa', 'en' => 'Low', 'es' => 'Baja'],
    'assistente.js.sol_media' => ['pt' => 'Média', 'en' => 'Medium', 'es' => 'Media'],
    'assistente.js.sol_alta' => ['pt' => 'Alta', 'en' => 'High', 'es' => 'Alta'],
    'assistente.js.sol_cancelar' => ['pt' => 'Cancelar', 'en' => 'Cancel', 'es' => 'Cancelar'],
    'assistente.js.sol_confirmar' => ['pt' => 'Confirmar registro', 'en' => 'Confirm registration', 'es' => 'Confirmar registro'],
    'assistente.js.erro_conexao' => ['pt' => 'Não foi possível falar com o assistente. Verifique a conexão e tente novamente.', 'en' => 'Could not reach the assistant. Check the connection and try again.', 'es' => 'No fue posible hablar con el asistente. Verifique la conexión e inténtelo de nuevo.'],
] : [])); ?>
<script src="<?php echo $baseUrl; ?>/assets/js/alertas.js?v=147"></script>
<script src="<?php echo $baseUrl; ?>/assets/js/sino-pendencias.js?v=3"></script>
<script src="<?php echo $baseUrl; ?>/assets/js/cabecalho-imagens.js?v=1"></script>
<script src="<?php echo $baseUrl; ?>/assets/js/footer.js?v=2"></script>
<script src="<?php echo $baseUrl; ?>/assets/js/minha-conta.js?v=5"></script>
<script src="<?php echo $baseUrl; ?>/assets/js/main.js?v=14"></script>
<script src="<?php echo $baseUrl; ?>/assets/js/filtro-atraso.js?v=2"></script>
<script>window.greenPortalFiltroChave = <?php echo json_encode(substr(md5((string)$tenantDatabase . ':' . (int)($usuario['codusuario'] ?? 0)), 0, 12)); ?>;</script>
<script src="<?php echo $baseUrl; ?>/assets/js/filtro-memoria.js?v=1"></script>
<script src="<?php echo $baseUrl; ?>/assets/js/filtros-favoritos.js?v=1"></script>
<script src="<?php echo $baseUrl; ?>/assets/js/status-icones.js?v=6"></script>
<script src="<?php echo $baseUrl; ?>/assets/js/fluxo-etapas.js?v=6"></script>
<script src="<?php echo $baseUrl; ?>/assets/js/legislacao-categorias.js?v=1"></script>
<?php if ($abrirAlertaJs): ?><script>(function(){var a=<?php echo json_encode($abrirAlertaJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;try{history.replaceState(null,'',location.pathname);}catch(e){}window.setTimeout(function(){if(typeof window.greenPortalOpenTab==='function')window.greenPortalOpenTab(a.id,a.url,a.titulo);},300);}());</script><?php endif; ?>
<?php if ($buscaLiberada): ?><script src="<?php echo $baseUrl; ?>/assets/js/busca-cabecalho.js?v=2"></script><?php endif; ?>
<script src="<?php echo $baseUrl; ?>/assets/js/idioma-cabecalho.js?v=1"></script>
<?php /* Perfil de atribuições: questionário em popup no login enquanto estiver pendente (migração 268) */ if (empty($_SESSION['perfil_atribuicoes_csrf'])) $_SESSION['perfil_atribuicoes_csrf'] = bin2hex(random_bytes(32)); ?>
<script>window.PA_LOGIN = <?php echo json_encode(['api' => $baseUrl . '/principal/api/perfil_atribuicoes.php', 'url' => $baseUrl . '/principal/perfil_atribuicoes.php?popup=1', 'csrf' => (string)$_SESSION['perfil_atribuicoes_csrf'], 'titulo' => IdiomaService::t('portal.perfil_atribuicoes', ['pt' => 'Meu perfil de atribuições', 'en' => 'My responsibilities profile', 'es' => 'Mi perfil de atribuciones'])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?>;</script>
<script src="<?php echo $baseUrl; ?>/assets/js/perfil-atribuicoes-login.js?v=1"></script>
<?php if ($assistenteLiberado): ?><script src="<?php echo $baseUrl; ?>/assets/js/assistente.js?v=5"></script><?php endif; ?>
</body>
</html>
