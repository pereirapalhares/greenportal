<?php
declare(strict_types=1);

/*
 * Aprovações (ícone da barra lateral): o que espera uma decisão do usuário — sugestões da análise por IA, não conformidades
 * "Com o Gestor", perfis de atribuições enviados pela equipe, substituições e delegações de responsabilidade (para quem
 * pode aprovar), documentos de fornecedor "Pendente com a empresa" e fluxos de aprovação abertos.
 * Dados: vw_painel_aprovacoes (migração 290) via classes/PainelLateralService.php. Cada item abre a tela de decisão em popup.
 * c579 (16/11/2026): uma sub-aba por tipo, cada uma com o grid padrão (DashboardGrid / grid local): barra de ícones, busca,
 * filtro e ordenação por coluna, paginação e rolagem dentro do grid; Abrir / duplo clique abrem a tela de decisão.
 */

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PainelLateralService.php';
require_once dirname(__DIR__) . '/classes/DashboardGrid.php';

try { $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION); } catch (Throwable) { http_response_code(503); exit('Banco de dados indisponível.'); }
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
$idioma = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';
$L = static fn(string $pt, string $en, string $es): string => ['pt' => $pt, 'en' => $en, 'es' => $es][$idioma];
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$itens = (new PainelLateralService($pdo, (int)$_SESSION['usuario']['codusuario']))->aprovacoes();
session_write_close();

$porTipo = [];
foreach ($itens as $i) $porTipo[$i['TIPO']][] = $i;
$data = static function (?string $d) use ($idioma): string { if (!$d) return ''; $t = strtotime($d); return $t ? date($idioma === 'en' ? 'm/d/Y' : 'd/m/Y', $t) : ''; };
?>
<!doctype html>
<html lang="<?= $e($idioma) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<link href="../assets/css/painel-lateral.css?v=2" rel="stylesheet"><?php DashboardGrid::head(); ?>
<style>html,body{height:auto}.painel-lista .menu-grid-scroll{max-height:calc(100vh - 230px);min-height:160px;overflow:auto}</style>
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?></head>
<body><main class="dashboard-content painel-lista">
<section class="dashboard-panel"><div class="panel-title"><div><h3><?= $e($L('Aprovações', 'Approvals', 'Aprobaciones')) ?></h3>
<p><?= $e($L('O que está esperando uma decisão sua.', 'What is waiting for your decision.', 'Lo que espera una decisión suya.')) ?></p></div><i class="bi bi-check2-square"></i></div>
<?php if (!$itens): ?><div class="empty-dashboard"><i class="bi bi-check2-circle"></i><strong><?= $e($L('Nenhuma aprovação pendente', 'No pending approvals', 'Ninguna aprobación pendiente')) ?></strong><span><?= $e($L('Quando algo precisar da sua decisão, aparece aqui.', 'When something needs your decision, it shows here.', 'Cuando algo necesite su decisión, aparecerá aquí.')) ?></span></div>
<?php else:
$abas = [];
foreach ($porTipo as $tipo => $lista) {
    $tp = PainelLateralService::TIPOS_APROVACAO[$tipo] ?? [[$idioma => $tipo], 'bi-dot'];
    $rotulo = (string)($tp[0][$idioma] ?? $tp[0]['pt'] ?? $tipo);
    $rows = [];
    foreach ($lista as $i) {
        $desde = substr((string)($i['DESDE'] ?? ''), 0, 19);
        $rows[] = ['cells' => [
            'ITEM' => ['text' => (string)$i['TITULO'], 'title' => (string)$i['TITULO']],
            'DETALHE' => ['text' => (string)$i['DETALHE'], 'title' => (string)$i['DETALHE']],
            'DESDE' => ['text' => $data($i['DESDE'] ?? null), 'sort' => $desde],
        ], 'open' => $i['LINK'] !== '' ? ['url' => (string)$i['LINK'], 'title' => $rotulo . ' - ' . $i['TITULO']] : []];
    }
    $abas[] = ['id' => strtolower((string)$tipo), 'icone' => (string)$tp[1], 'rotulo' => $rotulo, 'contagem' => count($lista),
        'html' => DashboardGrid::grid('apr-' . strtolower((string)$tipo), [
            'ITEM' => ['label' => $L('Item', 'Item', 'Ítem')],
            'DETALHE' => ['label' => $L('Detalhe', 'Detail', 'Detalle')],
            'DESDE' => ['label' => $L('Desde', 'Since', 'Desde'), 'filter' => 'none', 'width' => '110px'],
        ], $rows, ['transacao' => 'painel_aprovacoes', 'ordem' => ['DESDE', 'desc'], 'por_pagina' => 50])];
}
echo DashboardGrid::subabas('aprovacoes', $abas, true);
endif; ?>
</section></main>
<?php DashboardGrid::scripts(); ?></body></html>
