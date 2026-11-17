<?php
declare(strict_types=1);

/*
 * Barra lateral direita do portal: Pendências, Aprovações, Próximos prazos, Ajuda e Pendências do dia (abre o calendário,
 * principal/painel_calendario.php; o número é o de pendências que vencem hoje).
 * Números de classes/PainelLateralService.php (views da migração 290 e o serviço do painel de pendências), os mesmos das
 * telas que cada ícone abre. ?contagens=1 devolve só os números (JSON), usados para atualizar a barra a cada 5 minutos.
 */

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit; }

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PainelLateralService.php';

try { $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION); } catch (Throwable) { http_response_code(503); exit; }
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { http_response_code(401); exit; }
$uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$idioma = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';
$n = (new PainelLateralService($pdo, $uid))->contagens();
session_write_close();

if (isset($_GET['contagens'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true] + $n);
    exit;
}

$script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/principal/main_pendencias.php'));
$baseUrl = dirname(dirname($script));
$baseUrl = $baseUrl === '/' || $baseUrl === '.' ? '' : rtrim($baseUrl, '/');
$e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$L = static fn(string $pt, string $en, string $es): string => ['pt' => $pt, 'en' => $en, 'es' => $es][$idioma];

$prazosTitulo = $L('Próximos prazos', 'Upcoming deadlines', 'Próximos plazos');
$prazosDica = $prazosTitulo . ': ' . $n['prazos'] . ' ' . $L('nos próximos 30 dias', 'in the next 30 days', 'en los próximos 30 días')
    . ($n['atrasados'] ? ' · ' . $n['atrasados'] . ' ' . $L('atrasados', 'overdue', 'atrasados') : '');
$items = [
    ['id' => 'rail-pendencias-v3', 'chave' => 'pendencias', 'icon' => 'bi-list-check', 'label' => $L('Pendências', 'Pending items', 'Pendientes'), 'badge' => $n['pendencias'], 'url' => $baseUrl . '/principal/painel_pendencias.php'],
    ['id' => 'rail-aprovacoes', 'chave' => 'aprovacoes', 'icon' => 'bi-check2-square', 'label' => $L('Aprovações', 'Approvals', 'Aprobaciones'), 'badge' => $n['aprovacoes'], 'url' => $baseUrl . '/principal/painel_aprovacoes.php'],
    ['id' => 'rail-prazos', 'chave' => 'prazos', 'icon' => 'bi-calendar-event', 'label' => $prazosTitulo, 'dica' => $prazosDica, 'badge' => $n['prazos'] + $n['atrasados'], 'alerta' => $n['atrasados'] > 0, 'url' => $baseUrl . '/principal/painel_prazos.php'],
    ['id' => 'rail-ajuda', 'chave' => '', 'icon' => 'bi-question-circle', 'label' => $L('Ajuda — Guia do usuário', 'Help — User guide', 'Ayuda — Guía del usuario'), 'badge' => 0, 'url' => $baseUrl . '/principal/guia_usuario.php', 'titulo' => $L('Guia do usuário', 'User guide', 'Guía del usuario')],
    ['id' => 'rail-calendario', 'chave' => 'hoje', 'icon' => 'bi-calendar3', 'label' => $L('Pendências do dia', "Today's pending items", 'Pendientes de hoy'), 'badge' => $n['hoje'], 'url' => $baseUrl . '/principal/painel_calendario.php', 'titulo' => $L('Calendário de pendências', 'Pending items calendar', 'Calendario de pendientes')],
];
?>
<!doctype html>
<html lang="<?= $e($idioma) ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link href="<?= $e($baseUrl) ?>/assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="<?= $e($baseUrl) ?>/assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina($baseUrl ?: '..'); ?><style>
html,body{height:100%;margin:0;background:var(--gp-surface,#fff)}
.rail{height:100%;display:flex;flex-direction:column;align-items:center;gap:10px;padding:16px 6px;box-sizing:border-box}
.rail-button{position:relative;width:34px;height:34px;display:grid;place-items:center;border:0;border-radius:9px;color:var(--gp-muted);background:transparent;font-size:15px;cursor:pointer}
.rail-button:hover,.rail-button:focus-visible{color:var(--gp-primary);background:var(--gp-primary-soft);outline:0}
/* c576 (16/11/2026): contador em círculo pequeno (16px) com borda branca; acima de 99 vira "99+" (pílula) */
.rail-badge{position:absolute;top:-4px;right:-5px;box-sizing:border-box;min-width:16px;height:16px;padding:0;border-radius:50%;border:1.5px solid var(--gp-surface,#fff);color:#fff;background:var(--gp-primary-dark,#2f7a5c);font-family:inherit;font-size:8.5px;font-weight:700;line-height:13px;letter-spacing:-.2px;text-align:center;box-shadow:0 1px 2px rgba(0,0,0,.15);pointer-events:none}
.rail-badge.largo{padding:0 3px;border-radius:8px}
.rail-badge.alerta{background:var(--bs-danger,#c94f3d)}
.rail-badge.zero,.rail-day.zero{display:none}
.rail-day{position:absolute;right:-4px;bottom:-3px;box-sizing:border-box;min-width:14px;height:14px;border-radius:50%;border:1.5px solid var(--gp-surface,#fff);color:#fff;background:var(--gp-primary,#3ba179);font-family:inherit;font-size:8px;font-weight:700;line-height:11px;text-align:center;pointer-events:none}
.rail-refresh{margin-top:auto;color:var(--gp-muted);font-size:11px;text-align:center}.rail-refresh i{display:block;font-size:12px}
@media(max-width:700px){.rail{padding-top:10px}}
</style></head>
<body><main class="rail" aria-label="<?= $e($L('Informações importantes', 'Important information', 'Información importante')) ?>">
<?php foreach ($items as $item): $dica = $item['dica'] ?? ($item['label'] . ($item['badge'] ? ': ' . $item['badge'] : '')); ?>
<button type="button" class="rail-button" data-rail-url="<?= $e($item['url']) ?>" data-rail-id="<?= $e($item['id']) ?>" data-rail-title="<?= $e($item['titulo'] ?? $item['label']) ?>" data-rail-label="<?= $e($item['label']) ?>" title="<?= $e($dica) ?>" aria-label="<?= $e($dica) ?>"><i class="bi <?= $e($item['icon']) ?>" aria-hidden="true"></i><?php if ($item['chave'] === 'hoje'): ?><span class="rail-day<?= $item['badge'] > 0 ? '' : ' zero' ?>" data-rail-count="hoje"><?= (int)$item['badge'] > 99 ? '99+' : (int)$item['badge'] ?></span><?php elseif ($item['chave'] !== ''): ?><span class="rail-badge<?= $item['badge'] > 0 ? '' : ' zero' ?><?= !empty($item['alerta']) ? ' alerta' : '' ?><?= (int)$item['badge'] > 99 ? ' largo' : '' ?>" data-rail-count="<?= $e($item['chave']) ?>"><?= (int)$item['badge'] > 99 ? '99+' : (int)$item['badge'] ?></span><?php endif; ?></button>
<?php endforeach; ?>
<div class="rail-refresh" title="<?= $e($L('Atualização automática a cada 5 minutos', 'Automatic refresh every 5 minutes', 'Actualización automática cada 5 minutos')) ?>"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i><small>5 min</small></div>
</main><script>(function(){
document.querySelectorAll('[data-rail-url]').forEach(function(button){button.addEventListener('click',function(){var url=button.getAttribute('data-rail-url'),id=button.getAttribute('data-rail-id'),title=button.getAttribute('data-rail-title');try{if(window.parent&&typeof window.parent.greenPortalOpenTab==='function'){window.parent.greenPortalOpenTab(id,url,title);return;}}catch(error){}window.location.href=url;});});
var T={atrasados:<?= json_encode($L('atrasados', 'overdue', 'atrasados')) ?>,proximos:<?= json_encode($L('nos próximos 30 dias', 'in the next 30 days', 'en los próximos 30 días')) ?>};
function marcar(chave,valor,dica){var el=document.querySelector('[data-rail-count="'+chave+'"]');if(!el)return;el.textContent=valor>99?'99+':String(valor);el.classList.toggle('zero',!(valor>0));el.classList.toggle('largo',valor>99);var b=el.closest('button');if(b){var t=dica||(b.getAttribute('data-rail-label')+(valor>0?': '+valor:''));b.title=t;b.setAttribute('aria-label',t);}}
function atualizar(){fetch(window.location.pathname+'?contagens=1',{credentials:'same-origin',cache:'no-store'}).then(function(r){if(r.status===401){window.location.reload();return null;}return r.ok?r.json():null;}).then(function(n){if(!n||!n.ok)return;marcar('pendencias',n.pendencias);marcar('aprovacoes',n.aprovacoes);
 var lp=document.querySelector('[data-rail-count="prazos"]');marcar('prazos',n.prazos+n.atrasados,(lp?lp.closest('button').getAttribute('data-rail-label'):'')+': '+n.prazos+' '+T.proximos+(n.atrasados?' · '+n.atrasados+' '+T.atrasados:''));if(lp)lp.classList.toggle('alerta',n.atrasados>0);marcar('hoje',n.hoje);}).catch(function(){});}
window.setInterval(atualizar,300000);
document.addEventListener('visibilitychange',function(){if(!document.hidden)atualizar();});
})();</script></body></html>
