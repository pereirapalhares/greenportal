<?php
declare(strict_types=1);
/*
 * Barra direita › Pendências (cards). c577 (16/11/2026): reescrita — antes contava direto nas tabelas (SQL direto) e alguns
 * cards abriam telas com erro ou sem acesso:
 *   - Situações legais abria Situação quanto ao cumprimento com o filtro ATENDIDO <> 'S', que dá erro de colação no banco
 *     (agora: Requisitos legais a resolver, em Meus requisitos legais, situação consolidada diferente de Atendido);
 *   - Pendências universais abria uma tela que exige a permissão do menu PAINEL_PENDENCIAS_UNIVERSAL (403 para quem não tem);
 *   - Aprovações contava os alertas não lidos e abria a tela de Aprovações, que mostra outra coisa (números diferentes).
 * Agora cada card conta com sp_crud_select na MESMA view e com os MESMOS filtros da tela que abre (o número bate com a lista)
 * e só aparece para quem tem permissão de abrir a tela de destino.
 * c578: os cards vêm de classes/PendenciasPainel.php, que também dá o número do ícone (a soma dos cards); Aprovações saiu
 * (tem ícone próprio na barra).
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/PendenciasPainel.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
try { $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION); } catch (Throwable) { http_response_code(503); exit('Banco de dados indisponível.'); }
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
$uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$cards = PendenciasPainel::cards($pdo, $uid);
session_write_close();
$e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pendências</title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css?v=6" rel="stylesheet"><link href="../assets/css/main.css?v=18" rel="stylesheet">
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?>
<style>.pending-page{padding:12px}.pending-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}.pending-header h2{font-size:18px;margin:0;color:var(--gp-primary-dark)}.pending-header p{font-size:11px;color:var(--gp-muted);margin:3px 0 0}.pending-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.pending-card{display:flex;align-items:center;gap:12px;width:100%;padding:16px;border:1px solid var(--gp-border);border-radius:12px;background:#fff;color:var(--gp-text);text-align:left;box-shadow:0 3px 10px rgba(30,70,54,.05);transition:transform .15s,box-shadow .15s}.pending-card:hover{transform:translateY(-2px);box-shadow:0 7px 18px rgba(30,70,54,.12)}.pending-card-icon{width:42px;height:42px;flex:0 0 42px;border-radius:10px;display:grid;place-items:center;font-size:18px}.pending-card-icon.green{background:var(--gp-primary-soft);color:var(--gp-primary)}.pending-card-icon.blue{background:#e5f0f8;color:#4f87b4}.pending-card-icon.orange{background:#fff0df;color:#c27a35}.pending-card-body{min-width:0;flex:1}.pending-card-title{display:block;font-size:13px;font-weight:600}.pending-card-description{display:block;margin-top:3px;font-size:10px;color:var(--gp-muted)}.pending-card.zero .pending-card-count{color:var(--gp-muted)}.pending-card-count{display:block;font-size:28px;line-height:1;color:var(--gp-primary-dark);margin-bottom:3px}.pending-card-arrow{color:var(--gp-muted)}.pending-empty{padding:48px 20px;text-align:center;color:var(--gp-muted)}.pending-empty i{display:block;font-size:28px;margin-bottom:8px}.pending-empty strong,.pending-empty span{display:block}.pending-empty span{font-size:11px;margin-top:4px}@media(max-width:600px){.pending-page{padding:8px}.pending-grid{grid-template-columns:1fr}}</style></head><body><main class="dashboard-content pending-page"><header class="pending-header"><div><h2><i class="bi bi-list-check me-2"></i>Pendências</h2><p>Itens associados ao usuário logado que precisam de atenção. O número do ícone de Pendências é a soma destes cards.</p></div></header><?php if ($cards): ?><div class="pending-grid"><?php foreach ($cards as $card): ?><button type="button" class="pending-card<?= (int)$card['count'] > 0 ? '' : ' zero' ?>" data-pending-url="<?= $e($card['url']) ?>" data-pending-transaction="<?= $e($card['transaction']) ?>" data-pending-title="<?= $e($card['title']) ?>" aria-label="Abrir <?= $e($card['title']) ?>"><span class="pending-card-icon <?= $e($card['class']) ?>"><i class="bi <?= $e($card['icon']) ?>"></i></span><span class="pending-card-body"><span class="pending-card-count"><?= (int)$card['count'] ?></span><span class="pending-card-title"><?= $e($card['title']) ?></span><span class="pending-card-description"><?= $e($card['description']) ?></span></span><i class="bi bi-chevron-right pending-card-arrow"></i></button><?php endforeach; ?></div><?php else: ?><div class="pending-empty"><i class="bi bi-inbox"></i><strong>Nenhuma pendência encontrada</strong><span>Não há pendências disponíveis para o seu usuário ou perfil.</span></div><?php endif; ?></main><script>(function(){document.querySelectorAll('[data-pending-url]').forEach(function(card){card.addEventListener('click',function(){var url=new URL(card.getAttribute('data-pending-url'),window.location.href).href,id=card.getAttribute('data-pending-transaction'),title=card.getAttribute('data-pending-title');try{if(window.parent&&typeof window.parent.greenPortalOpenTab==='function'){window.parent.greenPortalOpenTab(id,url,title);return;}}catch(e){}window.location.href=url;});});})();</script></body></html>
