<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) { http_response_code(401); exit(IdiomaService::t('home.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.'])); }
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
$pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), (string)$_SESSION['tenant']['banco_dados'], 'GPT');
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { http_response_code(401); exit(IdiomaService::t('home.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.'])); }
$idioma = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt','en','es'], true) ? (string)$_SESSION['idioma'] : 'pt';
$perfil = strtoupper(trim((string)($_SESSION['usuario']['codigo_perfil'] ?? '')));
$perfil = preg_replace('/[_-]+/', ' ', $perfil) ?? $perfil;
$perfil = preg_replace('/\s+/', ' ', $perfil) ?? $perfil;
$e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$manager = in_array($perfil, ['ADMINISTRADOR', 'ADM DA EMPRESA', 'ADM EMPRESA', 'GESTOR'], true)
    || !empty(PermissaoTransacao::obter('DASHBOARD')['pode_visualizar_total']);
$tabs = [
    ['id'=>'agenda','label'=>IdiomaService::t('home.aba.agenda_curta', ['pt' => 'Agenda', 'en' => 'Agenda', 'es' => 'Agenda']),'icon'=>'bi-calendar2-week','src'=>'eventos.php?modo=agenda'],
    ['id'=>'pendencias','label'=>IdiomaService::t('home.aba.pendencias', ['pt' => 'Minhas pendências', 'en' => 'My pending items', 'es' => 'Mis pendientes']),'icon'=>'bi-list-check','src'=>'home_pendencias.php'],
    // Migração 358 (12/11/2026, pedido do usuário): Minhas atividades como aba da Home (não fica no menu); a tela mostra só as
    // abas dos módulos que o perfil pode ver.
    ['id'=>'minhas-atividades','label'=>IdiomaService::t('home.aba.minhas_atividades', ['pt' => 'Minhas atividades', 'en' => 'My work', 'es' => 'Mis actividades']),'icon'=>'bi-person-workspace','src'=>'minhas_atividades.php'],
    ['id'=>'alertas','label'=>IdiomaService::t('home.aba.alertas', ['pt' => 'Alertas', 'en' => 'Alerts', 'es' => 'Alertas']),'icon'=>'bi-bell','src'=>'home_alertas.php'],
    ['id'=>'dashboard','label'=>IdiomaService::t('home.aba.dashboard', ['pt' => 'Dashboard pessoal', 'en' => 'Personal dashboard', 'es' => 'Panel personal']),'icon'=>'bi-person-vcard','src'=>'dashboard.php?home_usuario=1'],
];
$dashboardTab = array_pop($tabs);
if (is_array($dashboardTab) && ($dashboardTab['id'] ?? '') === 'dashboard') array_unshift($tabs, $dashboardTab);
// Painel do gestor (equipe do usuário) para GESTOR e para ADM DA EMPRESA / ADMINISTRADOR (que também atuam como gestor);
// o dashboard da empresa inteira fica só para ADM DA EMPRESA / ADMINISTRADOR ou quem tem visão total do DASHBOARD.
$admEmpresa = in_array($perfil, ['ADMINISTRADOR', 'ADM DA EMPRESA', 'ADM EMPRESA'], true) || !empty(PermissaoTransacao::obter('DASHBOARD')['pode_visualizar_total']);
if ($manager) $tabs[] = ['id'=>'dashboard-gestor','label'=>IdiomaService::t('home.aba.painel_gestor', ['pt' => 'Minha equipe (Gestor)', 'en' => 'My team (Manager)', 'es' => 'Mi equipo (Gestor)']),'icon'=>'bi-diagram-2','src'=>'dashboard_gestor.php'];
// Migração 365 (GER-18.1–18.3): Gestão de pendências para ADM e gestores (a tela confere o escopo: ADM = todos; gestor = a equipe)
if ($manager && is_file(__DIR__ . '/gestao_pendencias.php')) $tabs[] = ['id'=>'gestao-pendencias','label'=>IdiomaService::t('home.aba.gestao_pendencias', ['pt' => 'Gestão de pendências', 'en' => 'Pending items management', 'es' => 'Gestión de pendientes']),'icon'=>'bi-ui-checks-grid','src'=>'gestao_pendencias.php'];
// Migração 380 (GER-8): Transferência de responsabilidades para ADM e gestores (a tela confere o escopo: ADM = todos; gestor = a equipe)
if ($manager && is_file(__DIR__ . '/transferencia_responsabilidades.php')) $tabs[] = ['id'=>'transferencias','label'=>IdiomaService::t('home.aba.transferencias', ['pt' => 'Transferências', 'en' => 'Handovers', 'es' => 'Transferencias']),'icon'=>'bi-arrow-left-right','src'=>'transferencia_responsabilidades.php'];
// Revisão G item 5.3: Painel de bordo (quem fez e quem não fez, por módulo, com bandeiras) para ADM e gestores (o gestor vê a equipe)
if ($manager && is_file(__DIR__ . '/painel_bordo.php')) $tabs[] = ['id'=>'painel-bordo','label'=>IdiomaService::t('home.aba.painel_bordo', ['pt' => 'Painel de bordo', 'en' => 'Management dashboard', 'es' => 'Panel de control']),'icon'=>'bi-flag','src'=>'painel_bordo.php'];
if ($admEmpresa) $tabs[] = ['id'=>'dashboard-geral','label'=>IdiomaService::t('home.aba.dashboard_empresa', ['pt' => 'Dashboard da empresa', 'en' => 'Company dashboard', 'es' => 'Panel de la empresa']),'icon'=>'bi-speedometer2','src'=>'dashboard_admin.php?home_gestor=1'];
?>
<!doctype html><html lang="<?= $e($idioma) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css?v=3" rel="stylesheet"><link href="../assets/css/main.css?v=15" rel="stylesheet">
<style>
body{background:var(--gp-surface)}.home-shell{height:100vh;display:flex;flex-direction:column;padding:0;color:var(--gp-text)}.home-tabs{display:flex;gap:4px;overflow-x:auto;border-bottom:1px solid var(--gp-border)}.home-tab{border:1px solid transparent;border-bottom:0;background:transparent;color:var(--gp-muted);border-radius:8px 8px 0 0;padding:9px 13px;font-size:12px;white-space:nowrap}.home-tab:hover{background:var(--gp-primary-soft);color:var(--gp-primary)}.home-tab.active{background:#fff;border-color:var(--gp-border);color:var(--gp-primary);font-weight:600}.home-tab i{margin-right:5px}.home-panes{flex:1;min-height:0;background:#fff;border:1px solid var(--gp-border);border-top:0;border-radius:0 0 9px 9px;overflow:hidden}.home-pane{height:100%;display:none}.home-pane.active{display:block}.home-pane iframe{display:block;width:100%;height:100%;min-height:520px;border:0}@media(max-width:700px){.home-tab{padding:8px 9px;font-size:11px}}
</style><?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?></head><body><main class="home-shell">
<nav class="home-tabs" role="tablist" aria-label="<?= htmlspecialchars(IdiomaService::t('home.abas', ['pt' => 'Visões da Home', 'en' => 'Home views', 'es' => 'Vistas de inicio']), ENT_QUOTES, 'UTF-8') ?>"><?php foreach($tabs as $index=>$tab): ?><button class="home-tab<?= $index===0?' active':'' ?>" type="button" role="tab" aria-selected="<?= $index===0?'true':'false' ?>" data-home-tab="<?= $e($tab['id']) ?>" data-home-src="<?= $e($tab['src']) ?>"><i class="bi <?= $e($tab['icon']) ?>"></i><?= $e($tab['label']) ?></button><?php endforeach; ?></nav>
<section class="home-panes"><?php foreach($tabs as $index=>$tab): ?><div class="home-pane<?= $index===0?' active':'' ?>" data-home-pane="<?= $e($tab['id']) ?>"><iframe title="<?= $e($tab['label']) ?>" data-home-frame data-src="<?= $e($tab['src']) ?>"<?= $index===0?' src="'.$e($tab['src']).'"':'' ?>></iframe></div><?php endforeach; ?></section>
</main><script>(function(){var buttons=[].slice.call(document.querySelectorAll('[data-home-tab]'));function activate(id){buttons.forEach(function(button){var tabId=button.getAttribute('data-home-tab'),active=tabId===id;button.classList.toggle('active',active);button.setAttribute('aria-selected',active?'true':'false');var pane=document.querySelector('[data-home-pane="'+tabId+'"]');if(pane){pane.classList.toggle('active',active);var frame=pane.querySelector('[data-home-frame]');if(active&&frame&&!frame.getAttribute('src'))frame.setAttribute('src',frame.getAttribute('data-src'));}});}buttons.forEach(function(button){button.addEventListener('click',function(){activate(button.getAttribute('data-home-tab'));});});}());</script></body></html>
