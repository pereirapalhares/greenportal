<?php
declare(strict_types=1);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: text/html; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit('Sessao expirada.'); }

$usuario = (string)($_SESSION['usuario']['nome'] ?? '');
$empresa = (string)($_SESSION['tenant']['nome'] ?? '');
$idioma = (string)($_SESSION['idioma'] ?? 'pt');
$idioma = in_array($idioma, ['pt', 'en', 'es'], true) ? $idioma : 'pt';

define('GREEN_GUIA_USUARIO', true);
$guia = require __DIR__ . '/guia_usuario_conteudo.php';

// Conteúdo publicado no banco universal (Administração › Guia do usuário).
// O arquivo guia_usuario_conteudo.php fornece os textos fixos da tela e serve de reserva.
$preview = (string)($_GET['preview'] ?? '');
$avisoPreview = '';
try {
    require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
    require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
    require_once dirname(__DIR__) . '/classes/GuiaUsuarioService.php';
    $env = GreenPortalDatabase::env();
    $servicoGuia = new GuiaUsuarioService(GreenPortalDatabase::connect($env, $env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp', 'GUNIVERSAL'));
    $fonte = null;
    if ($preview !== '' && !empty(PermissaoTransacao::obter(GuiaUsuarioService::TRANSACAO)['pode_visualizar'])) {
        if ($preview === 'rascunho') {
            $rascunho = $servicoGuia->rascunho();
            if ($rascunho) { $fonte = $rascunho['conteudo']; $avisoPreview = 'rascunho'; }
        } elseif ($preview === 'versao') {
            $versao = $servicoGuia->versao((int)($_GET['codversao'] ?? 0));
            if ($versao) { $fonte = $versao['conteudo']; $avisoPreview = 'versao:' . $versao['versao']['numero']; }
        }
    }
    if ($fonte === null) {
        $publicado = $servicoGuia->publicado();
        if ($publicado) $fonte = $publicado['conteudo'];
    }
    if (is_array($fonte) && !empty($fonte['modulos'])) {
        $fonte = $servicoGuia->normalizar($fonte);
        $guia['perfis'] = $fonte['perfis'];
        $guia['modulos'] = $fonte['modulos'];
    }
} catch (Throwable $erroGuia) {
    error_log('guia_usuario: ' . $erroGuia->getMessage());
}
$guia['modulos'] = array_values(array_filter($guia['modulos'], static fn(array $m): bool => !array_key_exists('ativo', $m) || !empty($m['ativo'])));
if (!isset($guia['perfis']['sistema'])) $guia['perfis']['sistema'] = ['icone' => 'bi-gear', 'nome' => ['pt' => 'Sistema', 'en' => 'System', 'es' => 'Sistema']];

// Links diretos: só para transações ativas no catálogo e que o usuário pode visualizar.
$menusLink = [];
try {
    if (!isset($servicoGuia)) {
        require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
        require_once dirname(__DIR__) . '/classes/GuiaUsuarioService.php';
        $envGuia = GreenPortalDatabase::env();
        $servicoGuia = new GuiaUsuarioService(GreenPortalDatabase::connect($envGuia, $envGuia['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp', 'GUNIVERSAL'));
    }
    $menusLink = $servicoGuia->menusComUrl();
} catch (Throwable $erroMenus) {
    error_log('guia_usuario menus: ' . $erroMenus->getMessage());
}
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
$baseUrl = str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/principal/guia_usuario.php'))));
$baseUrl = ($baseUrl === '/' || $baseUrl === '.') ? '' : rtrim($baseUrl, '/');
$permissaoLink = [];

// O conteúdo do guia segue a mesma matriz de autorização do menu principal.
// ADMINISTRADOR vê o documento completo; os demais perfis veem somente
// instruções ligadas a transações liberadas para a sessão atual.
$perfilSessao = strtoupper(trim((string)($_SESSION['usuario']['codigo_perfil'] ?? '')));
$guiaAdministrador = $perfilSessao === 'ADMINISTRADOR';
$guiaPermissaoCache = [];
$guiaPodeVer = static function (string $codigo) use (&$guiaPermissaoCache, $guiaAdministrador): bool {
    $codigo = strtoupper(trim($codigo));
    if ($codigo === '' || $guiaAdministrador) return true;
    if (!array_key_exists($codigo, $guiaPermissaoCache)) {
        $guiaPermissaoCache[$codigo] = !empty(PermissaoTransacao::obter($codigo)['pode_visualizar']);
    }
    return $guiaPermissaoCache[$codigo];
};

if (!$guiaAdministrador) {
    foreach ($guia['modulos'] as $indice => $modulo) {
        $temTransacoes = false;
        foreach ((array)($modulo['fases'] ?? []) as $fase) foreach ((array)($fase['passos'] ?? []) as $passo) if (trim((string)($passo['transacao'] ?? '')) !== '') $temTransacoes = true;
        foreach ((array)($modulo['tarefas'] ?? []) as $tarefa) if (trim((string)($tarefa['transacao'] ?? '')) !== '') $temTransacoes = true;
        foreach ((array)($modulo['menu'] ?? []) as $item) if (trim((string)($item['transacao'] ?? '')) !== '') $temTransacoes = true;

        $fases = [];
        foreach ((array)($modulo['fases'] ?? []) as $fase) {
            $passos = [];
            foreach ((array)($fase['passos'] ?? []) as $passo) {
                $codigo = trim((string)($passo['transacao'] ?? ''));
                if ($codigo !== '' && !$guiaPodeVer($codigo)) continue;
                if (!$temTransacoes || $codigo !== '' || $guiaPodeVer($codigo)) $passos[] = $passo;
            }
            if ($passos) { $fase['passos'] = $passos; $fases[] = $fase; }
        }
        $tarefas = [];
        foreach ((array)($modulo['tarefas'] ?? []) as $tarefa) {
            $codigo = trim((string)($tarefa['transacao'] ?? ''));
            if ($codigo !== '' && !$guiaPodeVer($codigo)) continue;
            $tarefas[] = $tarefa;
        }
        $menu = [];
        foreach ((array)($modulo['menu'] ?? []) as $item) {
            $codigo = trim((string)($item['transacao'] ?? ''));
            if ($codigo !== '' && !$guiaPodeVer($codigo)) continue;
            $menu[] = $item;
        }
        $guia['modulos'][$indice]['fases'] = $fases;
        $guia['modulos'][$indice]['tarefas'] = $tarefas;
        $guia['modulos'][$indice]['menu'] = $menu;
        if ($temTransacoes && !$fases && !$tarefas && !$menu) unset($guia['modulos'][$indice]);
    }
    $guia['modulos'] = array_values($guia['modulos']);
}

$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$t = static fn($value): string => is_array($value) ? (string)($value[$idioma] ?? $value['pt'] ?? '') : (string)$value;
$ui = static fn(string $key): string => $t($guia['ui'][$key] ?? $key);
$link = static function (array $item, string $estilo) use (&$permissaoLink, $menusLink, $baseUrl, $e, $t, $ui): string {
    $codigo = (string)($item['transacao'] ?? '');
    if ($codigo === '' || !isset($menusLink[$codigo])) return '';
    if (!array_key_exists($codigo, $permissaoLink)) $permissaoLink[$codigo] = !empty(PermissaoTransacao::obter($codigo)['pode_visualizar']);
    if (!$permissaoLink[$codigo]) return '';
    $menu = $menusLink[$codigo];
    $nome = $t($menu['nome']);
    $titulo = sprintf($ui('abrir_titulo'), $nome);
    $rotulo = $estilo === 'tarefa' ? sprintf($ui('abrir_nome'), $nome) : $ui('abrir');
    return '<a class="guide-go guide-go-' . $e($estilo) . '" href="' . $e($baseUrl . $menu['url']) . '" target="_blank" rel="noopener" data-open-transacao'
        . ' data-tab-id="' . $e('menu-' . strtolower($codigo)) . '" data-tab-title="' . $e($nome) . '" title="' . $e($titulo) . '" aria-label="' . $e($titulo) . '">'
        . '<i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span>' . $e($rotulo) . '</span></a>';
};
$perfis = $guia['perfis'];
$modulos = $guia['modulos'];
$moduloInicial = (string)($_GET['modulo'] ?? '');
$ids = array_column($modulos, 'id');
if (!in_array($moduloInicial, $ids, true)) $moduloInicial = (string)($ids[0] ?? '');
?><!doctype html>
<html lang="<?= $e($idioma === 'pt' ? 'pt-BR' : $idioma) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($ui('titulo')) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet">
<link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<style>
:root{
  /* Cores do tema do portal (components/pagina_padrao.php) */
  --g-ink:var(--gp-primary-dark);--g-text:var(--gp-text);--g-muted:var(--gp-muted);--g-line:var(--gp-border);--g-soft:var(--gp-page);--g-card:var(--gp-surface);
  --g-green:var(--gp-primary);--g-green-strong:var(--gp-primary-dark);--g-green-soft:var(--gp-primary-soft);
  --g-auto:#4a6fa5;--g-auto-soft:#eaf0f8;--g-auto-line:#c9d7ea;
  --g-radius:12px;--g-shadow:0 6px 18px rgba(22,72,55,.05);
}
*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{margin:0;background:var(--g-soft);color:var(--g-text);font-family:'DM Sans', system-ui, sans-serif;font-size:13px;line-height:1.55}
button{font:inherit}
.guide{max-width:1080px;margin:0 auto;padding:18px 20px 40px}

/* Cabeçalho */
.guide-top{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px}
.guide-brand{display:flex;align-items:center;gap:12px;min-width:0}
.guide-brand-icon{flex:0 0 auto;display:grid;place-items:center;width:40px;height:40px;border-radius:11px;background:var(--g-green);color:#fff;font-size:18px}
.guide-kicker{margin:0;color:var(--gp-primary);font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
.guide-title{margin:0;color:var(--g-ink);font-size:18px;font-weight:700;line-height:1.2}
.guide-sub{margin:2px 0 0;color:var(--g-muted);font-size:12px}
.guide-search{position:relative;flex:0 1 320px;min-width:220px}
.guide-search i.bi-search{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--g-muted);pointer-events:none}
.guide-search input{width:100%;height:36px;padding:0 34px 0 32px;border:1px solid var(--g-line);border-radius:9px;background:#fff;color:var(--g-ink);outline:none}
.guide-search input:focus{border-color:var(--gp-accent);box-shadow:0 0 0 3px rgba(46,146,109,.12)}
.guide-search-clear{position:absolute;right:5px;top:50%;transform:translateY(-50%);display:none;width:26px;height:26px;border:0;border-radius:6px;background:transparent;color:var(--g-muted)}
.guide-search-clear:hover{background:var(--g-soft);color:var(--g-ink)}
.guide-search.has-value .guide-search-clear{display:grid;place-items:center}
.guide-search input::-webkit-search-cancel-button{display:none;-webkit-appearance:none}
.guide-tab-count{display:none;min-width:20px;padding:1px 6px;border-radius:999px;background:var(--g-green-soft);color:var(--g-green-strong);font-size:11px;font-weight:700;text-align:center}
.guide-tab[aria-selected="true"] .guide-tab-count{background:rgba(255,255,255,.22);color:#fff}
.guide-searching .guide-tab-count{display:inline-block}
.guide-profiles-count{margin-left:auto;color:var(--g-green-strong);font-size:12px;font-weight:600}

/* Abas dos módulos */
.guide-tabs{display:flex;gap:6px;flex-wrap:wrap;padding:5px;margin-bottom:12px;border:1px solid var(--g-line);border-radius:12px;background:#fff}
.guide-tab{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border:0;border-radius:8px;background:transparent;color:var(--g-text);font-weight:600;cursor:pointer;transition:background .15s,color .15s}
.guide-tab:hover{background:var(--g-soft);color:var(--g-ink)}
.guide-tab[aria-selected="true"]{background:var(--g-green);color:#fff}
.guide-tab i{font-size:15px}
.guide-tab:focus-visible,.guide-chip:focus-visible,.guide-subnav a:focus-visible,summary:focus-visible{outline:2px solid var(--gp-accent);outline-offset:2px}

/* Painel do módulo */
.guide-panel[hidden]{display:none}
.guide-preview{display:flex;align-items:center;gap:8px;margin-bottom:10px;padding:8px 12px;border-radius:9px;background:#fff4dc;color:#8a5a00;font-size:12px;font-weight:600}
.guide-summary{margin:-4px 2px 4px;color:var(--g-muted);font-size:12px}

.guide-subnav{position:sticky;top:0;z-index:5;display:flex;gap:4px;flex-wrap:wrap;padding:8px 0;margin-bottom:6px;background:var(--g-soft)}
.guide-subnav a{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:999px;color:var(--g-text);font-size:12px;font-weight:600;text-decoration:none}
.guide-subnav a:hover{background:#fff;color:var(--g-green-strong)}
.guide-subnav a.is-current{background:#fff;color:var(--g-green-strong);box-shadow:inset 0 0 0 1px var(--g-line)}

.guide-section{margin-top:14px;scroll-margin-top:52px}
.guide-section-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px}
.guide-section-title{display:flex;align-items:center;gap:8px;margin:0;color:var(--g-ink);font-size:15px;font-weight:700}
.guide-section-title i{color:var(--g-green)}

/* Legenda A/M */
.guide-legend{display:flex;align-items:center;gap:14px;flex-wrap:wrap;color:var(--g-muted);font-size:12px}
.guide-legend span{display:inline-flex;align-items:center;gap:6px}
.badge-am{flex:0 0 auto;display:inline-grid;place-items:center;width:24px;height:24px;border-radius:7px;font-size:12px;font-weight:700;line-height:1}
.badge-m{background:var(--g-green);color:#fff}
.badge-a{background:var(--g-auto-soft);color:var(--g-auto);box-shadow:inset 0 0 0 1.5px var(--g-auto-line)}

/* Filtro por perfil */
.guide-profiles{display:flex;align-items:center;gap:6px;flex-wrap:wrap;padding:10px 12px;margin-bottom:12px;border:1px solid var(--g-line);border-radius:var(--g-radius);background:#fff}
.guide-profiles-label{margin-right:4px;color:var(--g-muted);font-size:12px;font-weight:600}
.guide-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border:1px solid var(--g-line);border-radius:999px;background:#fff;color:var(--g-text);font-size:12px;font-weight:600;cursor:pointer;transition:all .15s}
.guide-chip:hover{border-color:var(--gp-border);color:var(--g-ink)}
.guide-chip[aria-pressed="true"]{border-color:var(--g-green);background:var(--g-green-soft);color:var(--g-green-strong)}
.guide-profiles-note{flex-basis:100%;margin:4px 0 0;color:var(--g-muted);font-size:11px}

/* Fluxo */
.flow-phase{position:relative;padding-left:0;margin-bottom:6px}
.flow-phase-head{display:flex;align-items:center;gap:10px;margin:14px 0 8px}
.flow-phase-num{display:grid;place-items:center;width:26px;height:26px;border-radius:50%;background:var(--g-ink);color:#fff;font-size:12px;font-weight:700}
.flow-phase-name{margin:0;color:var(--g-ink);font-size:13px;font-weight:700}
.flow-phase-count{color:var(--g-muted);font-size:11px}
.flow-steps{position:relative;margin:0;padding:0 0 0 12px;list-style:none}
.flow-steps::before{content:"";position:absolute;left:12px;top:6px;bottom:6px;width:2px;background:var(--g-line)}
.flow-step{position:relative;display:grid;grid-template-columns:auto 1fr auto;gap:12px;align-items:start;margin:0 0 8px 14px;padding:11px 14px;border:1px solid var(--g-line);border-radius:var(--g-radius);background:#fff;transition:opacity .2s,box-shadow .2s}
.flow-step::before{content:"";position:absolute;left:-15px;top:22px;width:13px;height:2px;background:var(--g-line)}
.flow-step.is-auto{border-style:dashed;border-color:var(--g-auto-line);background:#fbfcfe}
.flow-step:hover{box-shadow:var(--g-shadow)}
.flow-step.is-dimmed{opacity:.32}
.flow-step.is-mine{border-color:var(--gp-accent);box-shadow:0 0 0 3px rgba(46,146,109,.08)}
.flow-step-badges{display:flex;flex-direction:column;align-items:center;gap:6px;padding-top:1px}
.flow-step-title{margin:0 0 2px;color:var(--g-ink);font-size:13px;font-weight:700}
.flow-step-text{margin:0;color:var(--g-text)}
.flow-step-where{display:inline-flex;align-items:flex-start;gap:6px;margin-top:7px;padding:3px 9px;border-radius:7px;background:var(--g-soft);color:var(--g-text);font-size:11px}
.flow-step-where i{margin-top:1px;color:var(--g-green)}
.flow-step-links{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:7px}
.flow-step-links .flow-step-where{margin-top:0}
.flow-step-links:empty{display:none}
.guide-go{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border:1px solid var(--g-green);border-radius:7px;background:#fff;color:var(--g-green-strong);font-size:11px;font-weight:700;text-decoration:none;white-space:nowrap;transition:background .15s,color .15s}
.guide-go:hover,.guide-go:focus-visible{background:var(--g-green);color:#fff}
.guide-go:focus-visible{outline:2px solid var(--gp-accent);outline-offset:2px}
.guide-go i{font-size:11px}
.howto-go{padding:0 16px 14px 36px}
.guide-go-tarefa{padding:5px 12px;font-size:12px}
.flow-step.is-auto .flow-step-where i{color:var(--g-auto)}
.flow-step-who{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;background:var(--g-green-soft);color:var(--g-green-strong);font-size:11px;font-weight:700;white-space:nowrap}
.flow-step.is-auto .flow-step-who{background:var(--g-auto-soft);color:var(--g-auto)}

/* Como fazer */
.howto-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;align-items:start}
.howto{border:1px solid var(--g-line);border-radius:var(--g-radius);background:#fff}
.howto summary{display:flex;align-items:center;gap:10px;padding:12px 14px;color:var(--g-ink);font-weight:700;cursor:pointer;list-style:none}
.howto summary::-webkit-details-marker{display:none}
.howto summary i.lead{color:var(--g-green);font-size:15px}
.howto summary .chev{margin-left:auto;color:var(--g-muted);transition:transform .2s}
.howto[open] summary .chev{transform:rotate(180deg)}
.howto[open] summary{border-bottom:1px solid var(--g-line)}
.howto ol{margin:0;padding:12px 16px 14px 36px}
.howto li{margin-bottom:5px;padding-left:3px}
.howto li::marker{color:var(--g-green);font-weight:700}

/* Onde encontrar */
.where-list{margin:0;padding:0;list-style:none;border:1px solid var(--g-line);border-radius:var(--g-radius);background:#fff;overflow:hidden}
.where-item{display:grid;grid-template-columns:minmax(220px,38%) 1fr auto;gap:14px;align-items:center;padding:10px 14px;border-top:1px solid var(--g-line)}
.where-item:first-child{border-top:0}
.where-path{color:var(--g-ink);font-weight:700}
.where-path i{margin-right:6px;color:var(--g-green)}
.where-text{color:var(--g-text)}

/* Termos */
.terms-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.term{padding:12px 14px;border:1px solid var(--g-line);border-radius:var(--g-radius);background:#fff}
.term h3{margin:0 0 3px;color:var(--g-ink);font-size:13px;font-weight:700}
.term p{margin:0;color:var(--g-text);font-size:12px}
.term.is-tip{border-color:var(--gp-border);background:var(--gp-surface)}
.term.is-tip h3 i{color:var(--g-green)}

.guide-empty{display:none;padding:22px;border:1px dashed var(--g-line);border-radius:var(--g-radius);background:#fff;color:var(--g-muted);text-align:center}
.guide-panel.no-results .guide-empty{display:block}
.is-filtered-out{display:none !important}
mark{padding:0 1px;border-radius:3px;background:#fff1b8;color:inherit}

.guide-foot{display:flex;gap:10px;align-items:flex-start;margin-top:22px;padding:12px 14px;border-radius:var(--g-radius);background:var(--gp-primary-soft);color:var(--g-text);font-size:12px}
.guide-foot i{color:var(--g-green);font-size:15px}
.guide-foot-user{margin-left:auto;color:var(--g-muted);text-align:right;white-space:nowrap}
.guide-quick-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 14px;padding:10px 12px;border:1px solid var(--g-line);border-radius:var(--g-radius);background:#fff}
.guide-quick-actions-label{margin-right:auto;color:var(--g-ink);font-weight:700}
.guide-quick-actions small{width:100%;color:var(--g-muted);font-size:11px}
.guide-quick-actions .btn{display:inline-flex;align-items:center;gap:6px}

@media (max-width:760px){
  .guide{padding:12px 12px 30px}
  .guide-search{flex:1 1 100%}
  .flow-step{grid-template-columns:auto 1fr}
  .flow-step-side{grid-column:2}
  .howto-grid{grid-template-columns:1fr}
  .terms-grid{grid-template-columns:1fr 1fr}
  .where-item{grid-template-columns:1fr;gap:4px}
  .where-go:empty{display:none}
  .guide-foot{flex-wrap:wrap}
  .guide-foot-user{margin-left:0;text-align:left;white-space:normal}
}
@media (max-width:480px){.terms-grid{grid-template-columns:1fr}}
@media (prefers-reduced-motion:reduce){html{scroll-behavior:auto}*{transition:none !important}}
</style>
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?></head>
<body>
<main class="guide" data-guide data-initial="<?= $e($moduloInicial) ?>" data-label-steps="<?= $e($ui('etapas')) ?>">
  <header class="guide-top">
    <div class="guide-brand">
      <span class="guide-brand-icon" aria-hidden="true"><i class="bi bi-book-half"></i></span>
      <div>
        <p class="guide-kicker">GreenSolutions</p>
        <h1 class="guide-title"><?= $e($ui('titulo')) ?></h1>
        <p class="guide-sub"><?= $e($ui('subtitulo')) ?></p>
      </div>
    </div>
    <div class="guide-search" data-search-box>
      <i class="bi bi-search" aria-hidden="true"></i>
      <input type="search" data-search placeholder="<?= $e($ui('buscar_placeholder')) ?>" aria-label="<?= $e($ui('buscar')) ?>" autocomplete="off">
      <button type="button" class="guide-search-clear" data-search-clear aria-label="<?= $e($ui('limpar')) ?>" title="<?= $e($ui('limpar')) ?>"><i class="bi bi-x-lg"></i></button>
    </div>
  </header>

<?php if ($avisoPreview !== ''): ?>
  <div class="guide-preview" role="status"><i class="bi bi-eye" aria-hidden="true"></i><?= $e($avisoPreview === 'rascunho' ? $ui('preview_rascunho') : sprintf($ui('preview_versao'), substr($avisoPreview, 7))) ?></div>
<?php endif; ?>
<?php if (in_array('legal', $ids, true)): ?>
  <div class="guide-quick-actions" data-legal-guide-actions data-tour-url="<?= $e($baseUrl . '/principal/modulolegal_requisitoslegais.php') ?>">
    <span class="guide-quick-actions-label"><i class="bi bi-bank me-1" aria-hidden="true"></i>Módulo Legal · Requisitos Legais</span>
    <button type="button" class="btn btn-sm btn-success" data-guide-start><i class="bi bi-play-circle"></i>Começar aqui</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-guide-step-by-step><i class="bi bi-list-ol"></i>Ver passo a passo</button>
    <button type="button" class="btn btn-sm btn-outline-primary" data-guide-show-me><i class="bi bi-hand-index-thumb"></i>Mostre-me</button>
    <small>O modo “Mostre-me” abre a tela real, destaca os controles e seleciona apenas uma linha para demonstrar o fluxo. Nenhum dado é gravado automaticamente.</small>
  </div>
<?php endif; ?>
<?php if (!$modulos): ?>
  <div class="guide-empty" style="display:block"><?= $e($ui('sem_conteudo')) ?></div>
<?php endif; ?>
  <nav class="guide-tabs" role="tablist" aria-label="<?= $e($ui('titulo')) ?>">
<?php foreach ($modulos as $modulo): $id = (string)$modulo['id']; $sel = $id === $moduloInicial; ?>
    <button class="guide-tab" type="button" role="tab" id="tab-<?= $e($id) ?>" aria-controls="panel-<?= $e($id) ?>" aria-selected="<?= $sel ? 'true' : 'false' ?>" tabindex="<?= $sel ? '0' : '-1' ?>" data-tab="<?= $e($id) ?>">
      <i class="bi <?= $e((string)$modulo['icone']) ?>" aria-hidden="true"></i><span><?= $e($t($modulo['nome'])) ?></span><span class="guide-tab-count" data-tab-count aria-hidden="true"></span>
    </button>
<?php endforeach; ?>
  </nav>

<?php foreach ($modulos as $modulo):
    $id = (string)$modulo['id'];
    $total = 0; foreach ($modulo['fases'] as $fase) $total += count($fase['passos']);
?>
  <section class="guide-panel" role="tabpanel" id="panel-<?= $e($id) ?>" aria-labelledby="tab-<?= $e($id) ?>" data-panel="<?= $e($id) ?>"<?= $id === $moduloInicial ? '' : ' hidden' ?>>
    <p class="guide-summary"><?= $e($t($modulo['resumo'])) ?></p>

    <nav class="guide-subnav" aria-label="<?= $e($t($modulo['nome'])) ?>">
      <a href="#<?= $e($id) ?>-fluxo" data-subnav><i class="bi bi-signpost-split" aria-hidden="true"></i><?= $e($ui('secao_fluxo')) ?></a>
      <a href="#<?= $e($id) ?>-tarefas" data-subnav><i class="bi bi-list-check" aria-hidden="true"></i><?= $e($ui('secao_tarefas')) ?></a>
      <a href="#<?= $e($id) ?>-menu" data-subnav><i class="bi bi-compass" aria-hidden="true"></i><?= $e($ui('secao_menu')) ?></a>
      <a href="#<?= $e($id) ?>-termos" data-subnav><i class="bi bi-lightbulb" aria-hidden="true"></i><?= $e($ui('secao_termos')) ?></a>
    </nav>

    <section class="guide-section" id="<?= $e($id) ?>-fluxo" data-section>
      <div class="guide-section-head">
        <h3 class="guide-section-title"><i class="bi bi-signpost-split" aria-hidden="true"></i><?= $e($ui('secao_fluxo')) ?> <span class="flow-phase-count">· <?= $total ?> <?= $e($ui('etapas')) ?></span></h3>
        <div class="guide-legend" aria-label="<?= $e($ui('legenda')) ?>">
          <span><b class="badge-am badge-m">M</b><?= $e($ui('manual')) ?> — <?= $e($ui('manual_desc')) ?></span>
          <span><b class="badge-am badge-a">A</b><?= $e($ui('automatico')) ?> — <?= $e($ui('automatico_desc')) ?></span>
        </div>
      </div>

      <div class="guide-profiles" role="group" aria-label="<?= $e($ui('ver_perfil')) ?>">
        <span class="guide-profiles-label"><?= $e($ui('ver_perfil')) ?></span>
        <button type="button" class="guide-chip" data-profile="" aria-pressed="true"><i class="bi bi-people" aria-hidden="true"></i><?= $e($ui('todos')) ?></button>
<?php foreach ($modulo['perfis'] as $perfilId): $perfil = $perfis[$perfilId] ?? null; if (!$perfil) continue; ?>
        <button type="button" class="guide-chip" data-profile="<?= $e($perfilId) ?>" aria-pressed="false"><i class="bi <?= $e((string)$perfil['icone']) ?>" aria-hidden="true"></i><?= $e($t($perfil['nome'])) ?></button>
<?php endforeach; ?>
        <button type="button" class="guide-chip" data-profile="sistema" aria-pressed="false"><i class="bi <?= $e((string)$perfis['sistema']['icone']) ?>" aria-hidden="true"></i><?= $e($t($perfis['sistema']['nome'])) ?></button>
        <span class="guide-profiles-count" data-profile-count aria-live="polite"></span>
<?php if (!empty($modulo['perfis_nota'])): ?>
        <p class="guide-profiles-note"><?= $e($t($modulo['perfis_nota'])) ?></p>
<?php endif; ?>
      </div>

<?php $numero = 0; foreach ($modulo['fases'] as $f => $fase): ?>
      <div class="flow-phase" data-filter-group>
        <div class="flow-phase-head">
          <span class="flow-phase-num"><?= $f + 1 ?></span>
          <h4 class="flow-phase-name"><?= $e($t($fase['nome'])) ?></h4>
          <span class="flow-phase-count"><?= count($fase['passos']) ?> <?= $e($ui('etapas')) ?></span>
        </div>
        <ol class="flow-steps">
<?php foreach ($fase['passos'] as $passo):
        $numero++;
        $auto = ($passo['tipo'] ?? 'M') === 'A';
        $perfil = $perfis[$passo['perfil']] ?? $perfis['sistema'];
?>
          <li class="flow-step<?= $auto ? ' is-auto' : '' ?>" data-step data-filter-item data-profile="<?= $e((string)$passo['perfil']) ?>">
            <div class="flow-step-badges">
              <b class="badge-am <?= $auto ? 'badge-a' : 'badge-m' ?>" title="<?= $e($auto ? $ui('automatico') . ' — ' . $ui('automatico_desc') : $ui('manual') . ' — ' . $ui('manual_desc')) ?>"><?= $auto ? 'A' : 'M' ?></b>
            </div>
            <div>
              <h5 class="flow-step-title" data-text><span class="visually-hidden"><?= $e($ui('etapa')) ?> <?= $numero ?>: </span><?= $e($t($passo['titulo'])) ?></h5>
              <p class="flow-step-text" data-text><?= $e($t($passo['texto'])) ?></p>
<?php if ($t($passo['onde'] ?? '') !== ''): ?>
              <div class="flow-step-links"><span class="flow-step-where" data-text><i class="bi <?= $auto ? 'bi-gear' : 'bi-geo-alt' ?>" aria-hidden="true"></i><span><span class="visually-hidden"><?= $e($ui('onde')) ?>: </span><?= $e($t($passo['onde'])) ?></span></span><?= $link($passo, 'etapa') ?></div>
<?php else: ?>
              <div class="flow-step-links"><?= $link($passo, 'etapa') ?></div>
<?php endif; ?>
            </div>
            <div class="flow-step-side"><span class="flow-step-who"><i class="bi <?= $e((string)$perfil['icone']) ?>" aria-hidden="true"></i><?= $e($t($perfil['nome'])) ?></span></div>
          </li>
<?php endforeach; ?>
        </ol>
      </div>
<?php endforeach; ?>
    </section>

    <section class="guide-section" id="<?= $e($id) ?>-tarefas" data-section>
      <div class="guide-section-head"><h3 class="guide-section-title"><i class="bi bi-list-check" aria-hidden="true"></i><?= $e($ui('secao_tarefas')) ?></h3></div>
      <div class="howto-grid" data-filter-group>
<?php foreach ($modulo['tarefas'] as $tarefa): ?>
        <details class="howto" data-filter-item data-howto>
          <summary data-text><i class="bi bi-question-circle lead" aria-hidden="true"></i><span><?= $e($t($tarefa['titulo'])) ?></span><i class="bi bi-chevron-down chev" aria-hidden="true"></i></summary>
          <ol>
<?php foreach ($tarefa['passos'] as $item): ?>
            <li data-text><?= $e($t($item)) ?></li>
<?php endforeach; ?>
          </ol>
<?php $go = $link($tarefa, 'tarefa'); if ($go !== ''): ?>
          <div class="howto-go"><?= $go ?></div>
<?php endif; ?>
        </details>
<?php endforeach; ?>
      </div>
    </section>

    <section class="guide-section" id="<?= $e($id) ?>-menu" data-section>
      <div class="guide-section-head"><h3 class="guide-section-title"><i class="bi bi-compass" aria-hidden="true"></i><?= $e($ui('secao_menu')) ?></h3></div>
      <ul class="where-list" data-filter-group>
<?php foreach ($modulo['menu'] as $item): ?>
        <li class="where-item" data-filter-item>
          <span class="where-path" data-text><i class="bi bi-folder2-open" aria-hidden="true"></i><?= $e($t($item['caminho'])) ?></span>
          <span class="where-text" data-text><?= $e($t($item['texto'])) ?></span>
          <span class="where-go"><?= $link($item, 'menu') ?></span>
        </li>
<?php endforeach; ?>
      </ul>
    </section>

    <section class="guide-section" id="<?= $e($id) ?>-termos" data-section>
      <div class="guide-section-head"><h3 class="guide-section-title"><i class="bi bi-lightbulb" aria-hidden="true"></i><?= $e($ui('secao_termos')) ?></h3></div>
      <div class="terms-grid" data-filter-group>
<?php foreach ($modulo['termos'] as $termo): $termoTexto = $t($termo['termo']); $dica = !empty($termo['dica']) || in_array($termoTexto, ['Dica', 'Tip', 'Consejo'], true); ?>
        <article class="term<?= $dica ? ' is-tip' : '' ?>" data-filter-item>
          <h3 data-text><?php if ($dica): ?><i class="bi bi-lightbulb-fill me-1" aria-hidden="true"></i><?php endif; ?><?= $e($termoTexto) ?></h3>
          <p data-text><?= $e($t($termo['texto'])) ?></p>
        </article>
<?php endforeach; ?>
      </div>
    </section>

    <div class="guide-empty" role="status"><i class="bi bi-search me-1" aria-hidden="true"></i><?= $e($ui('sem_resultado')) ?></div>
  </section>
<?php endforeach; ?>

  <footer class="guide-foot">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    <span><?= $e($ui('nota_permissao')) ?></span>
    <span class="guide-foot-user"><?= $e($ui('usuario')) ?>: <?= $e($usuario) ?><br><?= $e($ui('empresa')) ?>: <?= $e($empresa) ?></span>
  </footer>
</main>
<script>
(function () {
  'use strict';
  var root = document.querySelector('[data-guide]');
  if (!root) return;
  var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-tab]'));
  var panels = Array.prototype.slice.call(root.querySelectorAll('[data-panel]'));
  var search = root.querySelector('[data-search]');
  var searchBox = root.querySelector('[data-search-box]');
  var storageKey = 'greenGuiaUsuarioAba';

  function store(key, value) { try { if (value === undefined) return window.localStorage.getItem(key); window.localStorage.setItem(key, value); } catch (e) { return null; } return null; }
  function normalize(text) { return String(text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
  function activePanel() { return panels.filter(function (p) { return !p.hidden; })[0] || panels[0]; }

  function selectTab(id, focus) {
    tabs.forEach(function (tab) {
      var on = tab.getAttribute('data-tab') === id;
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
      tab.tabIndex = on ? 0 : -1;
      if (on && focus) tab.focus();
    });
    panels.forEach(function (panel) { panel.hidden = panel.getAttribute('data-panel') !== id; });
    store(storageKey, id);
    applySearch();
    window.scrollTo(0, 0);
  }

  tabs.forEach(function (tab, index) {
    tab.addEventListener('click', function () { selectTab(tab.getAttribute('data-tab'), false); });
    tab.addEventListener('keydown', function (event) {
      var next = null;
      if (event.key === 'ArrowRight') next = tabs[(index + 1) % tabs.length];
      if (event.key === 'ArrowLeft') next = tabs[(index - 1 + tabs.length) % tabs.length];
      if (event.key === 'Home') next = tabs[0];
      if (event.key === 'End') next = tabs[tabs.length - 1];
      if (next) { event.preventDefault(); selectTab(next.getAttribute('data-tab'), true); }
    });
  });

  // Link direto: abre a transação em uma aba do portal (como o menu) e fecha o guia.
  root.addEventListener('click', function (event) {
    var go = event.target.closest ? event.target.closest('[data-open-transacao]') : null;
    if (!go) return;
    var host = null;
    try { host = window.top && window.top.greenPortalOpenTab ? window.top : null; } catch (e) { host = null; }
    if (!host) return; // fora do portal o link abre em nova janela (comportamento padrão)
    event.preventDefault();
    host.greenPortalOpenTab(go.getAttribute('data-tab-id'), go.href, go.getAttribute('data-tab-title'));
    try {
      var modalEl = window.frameElement && window.frameElement.closest ? window.frameElement.closest('.modal') : null;
      if (modalEl && host.bootstrap && host.bootstrap.Modal) { var inst = host.bootstrap.Modal.getInstance(modalEl); if (inst) inst.hide(); }
    } catch (e) { /* mantém o guia aberto se não for possível fechar */ }
  });

  // Filtro por perfil: destaca as etapas do perfil escolhido.
  root.addEventListener('click', function (event) {
    var chip = event.target.closest ? event.target.closest('[data-profile].guide-chip') : null;
    if (!chip) return;
    var panel = chip.closest('[data-panel]'), profile = chip.getAttribute('data-profile');
    panel.querySelectorAll('.guide-chip').forEach(function (c) { c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'); });
    panel.querySelectorAll('[data-step]').forEach(function (step) {
      var mine = profile !== '' && step.getAttribute('data-profile') === profile;
      step.classList.toggle('is-mine', mine);
      step.classList.toggle('is-dimmed', profile !== '' && !mine);
    });
    var counter = panel.querySelector('[data-profile-count]');
    if (counter) counter.textContent = profile === '' ? '' : panel.querySelectorAll('[data-step].is-mine').length + ' ' + (root.getAttribute('data-label-steps') || '');
  });

  // Destaque do item atual no submenu.
  function updateSubnav() {
    var panel = activePanel(); if (!panel) return;
    var links = panel.querySelectorAll('[data-subnav]'), current = null;
    var visible = Array.prototype.filter.call(panel.querySelectorAll('[data-section]'), function (section) { return section.offsetParent !== null; });
    visible.forEach(function (section, index) { if (index === 0 || section.getBoundingClientRect().top < 90) current = section.id; });
    if (visible.length && window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) current = visible[visible.length - 1].id;
    links.forEach(function (link) { link.classList.toggle('is-current', link.getAttribute('href') === '#' + current); });
  }
  window.addEventListener('scroll', updateSubnav, { passive: true });

  // Busca dentro da aba ativa.
  var originals = new WeakMap();
  function highlight(element, term) {
    if (!originals.has(element)) originals.set(element, element.innerHTML);
    var html = originals.get(element);
    if (!term) { element.innerHTML = html; return; }
    var walker = document.createElement('div'); walker.innerHTML = html;
    var nodes = [], it = document.createTreeWalker(walker, NodeFilter.SHOW_TEXT, null), node;
    while ((node = it.nextNode())) nodes.push(node);
    nodes.forEach(function (textNode) {
      var text = textNode.nodeValue, norm = normalize(text), pos = norm.indexOf(term);
      if (pos < 0) return;
      var frag = document.createDocumentFragment(), last = 0;
      while (pos >= 0) {
        frag.appendChild(document.createTextNode(text.slice(last, pos)));
        var mark = document.createElement('mark'); mark.textContent = text.slice(pos, pos + term.length); frag.appendChild(mark);
        last = pos + term.length; pos = norm.indexOf(term, last);
      }
      frag.appendChild(document.createTextNode(text.slice(last)));
      textNode.parentNode.replaceChild(frag, textNode);
    });
    element.innerHTML = walker.innerHTML;
  }

  function applySearch() {
    var term = normalize((search && search.value || '').trim());
    if (searchBox) searchBox.classList.toggle('has-value', term !== '');
    panels.forEach(function (panel) {
      var visible = 0;
      panel.querySelectorAll('[data-filter-item]').forEach(function (item) {
        var match = term === '' || normalize(item.textContent).indexOf(term) >= 0;
        item.classList.toggle('is-filtered-out', !match);
        item.querySelectorAll('[data-text]').forEach(function (el) { highlight(el, match ? term : ''); });
        if (item.hasAttribute('data-howto')) {
          if (term !== '') { item.open = match; item.setAttribute('data-search-open', '1'); }
          else if (item.getAttribute('data-search-open') === '1') { item.open = false; item.removeAttribute('data-search-open'); }
        }
        if (match) visible++;
      });
      panel.querySelectorAll('[data-filter-group]').forEach(function (group) {
        var any = group.querySelector('[data-filter-item]:not(.is-filtered-out)');
        group.classList.toggle('is-filtered-out', !any);
      });
      panel.querySelectorAll('[data-section]').forEach(function (section) {
        section.classList.toggle('is-filtered-out', term !== '' && !section.querySelector('[data-filter-item]:not(.is-filtered-out)'));
      });
      panel.classList.toggle('no-results', term !== '' && visible === 0);
      var tab = root.querySelector('[data-tab="' + panel.getAttribute('data-panel') + '"] [data-tab-count]');
      if (tab) tab.textContent = String(visible);
    });
    root.classList.toggle('guide-searching', term !== '');
    updateSubnav();
  }

  if (search) {
    var timer = null;
    search.addEventListener('input', function () { window.clearTimeout(timer); timer = window.setTimeout(applySearch, 120); });
    search.addEventListener('keydown', function (event) { if (event.key === 'Escape') { search.value = ''; applySearch(); } });
  }
  var clear = root.querySelector('[data-search-clear]');
  if (clear) clear.addEventListener('click', function () { search.value = ''; applySearch(); search.focus(); });

  var fromUrl = new URLSearchParams(window.location.search).get('modulo');
  var saved = store(storageKey);
  var initial = fromUrl && tabs.some(function (t) { return t.getAttribute('data-tab') === fromUrl; }) ? fromUrl
    : (saved && tabs.some(function (t) { return t.getAttribute('data-tab') === saved; }) ? saved : root.getAttribute('data-initial'));
  selectTab(initial, false);
}());
</script>
<script src="../assets/js/guia-interativo.js?v=2"></script>
</body>
</html>
