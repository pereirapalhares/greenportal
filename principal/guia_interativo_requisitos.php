<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit('Sessão expirada.'); }
define('GREEN_GUIA_USUARIO', true);
$conteudo = require __DIR__ . '/guia_usuario_conteudo.php';
$legal = array_values(array_filter((array)($conteudo['modulos'] ?? []), static fn(array $m): bool => (string)($m['id'] ?? '') === 'legal'))[0] ?? [];
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
$pt = static fn(mixed $v): string => is_array($v) ? (string)($v['pt'] ?? '') : (string)$v;
$podeVer = static function (string $codigo): bool { return $codigo === '' || !empty(PermissaoTransacao::obter($codigo)['pode_visualizar']); };
$tarefas = [];
foreach ((array)($legal['tarefas'] ?? []) as $tarefa) {
    $transacao = strtoupper(trim((string)($tarefa['transacao'] ?? '')));
    if ($transacao !== 'REQUISITOS_LEGAIS' && stripos((string)($tarefa['titulo']['pt'] ?? $tarefa['titulo'] ?? ''), 'Excel') === false) continue;
    if (!$podeVer($transacao)) continue;
    $tarefas[] = ['titulo' => $tarefa['titulo'], 'passos' => array_values(array_map($pt, (array)($tarefa['passos'] ?? []))), 'transacao' => $transacao];
}
$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$json = static fn(mixed $v): string => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$usuario = (string)($_SESSION['usuario']['nome'] ?? '');
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ajuda - Requisitos Legais</title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2">
<link rel="stylesheet" href="../assets/css/design-system.css?v=3">
<style>
:root{--help-blue:var(--gp-primary,#247a5b);--help-soft:var(--gp-primary-soft,#e8f5ee);--help-line:var(--gp-border,#dce9e2);--help-text:var(--gp-text,#203f35);--help-muted:var(--gp-muted,#71808c);--help-surface:var(--gp-surface,#fff);--help-shadow:var(--gp-shadow-md,0 10px 28px rgba(22,72,55,.18))}
*{box-sizing:border-box}html,body{height:100%;margin:0;overflow:hidden}body{background:var(--help-surface);color:var(--help-text);font-family:var(--gp-font-body,system-ui,sans-serif);font-size:13px}.help{height:100%;display:flex;flex-direction:column}.help-header{display:flex;align-items:center;gap:9px;padding:12px 14px;border-bottom:1px solid var(--help-line);background:var(--help-surface);box-shadow:var(--help-shadow)}.help-header i{color:var(--help-blue);font-size:18px}.help-header h1{flex:1;margin:0;font-size:16px;font-weight:700}.help-close{border:0;background:transparent;color:var(--help-text);font-size:19px;line-height:1;cursor:pointer}.help-filter{padding:11px 14px;border-bottom:1px solid var(--help-line);display:flex;align-items:center;gap:8px}.help-filter label{color:var(--help-muted);white-space:nowrap}.help-filter select{min-width:160px;border:1px solid var(--help-line);border-radius:6px;padding:6px 8px;background:var(--help-surface);color:var(--help-text)}.help-content{min-height:0;flex:1;overflow:auto;padding:12px 14px}.help-kicker{margin:0 0 10px;color:var(--help-muted);font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:700}.help-card{border:1px solid var(--help-line);border-radius:10px;padding:12px;margin-bottom:9px;background:var(--help-surface);box-shadow:0 2px 8px rgba(22,72,55,.05)}.help-card h2{margin:0 0 4px;font-size:14px;font-weight:700}.help-card-meta{color:var(--help-muted);font-size:11px;margin-bottom:9px}.help-card-actions{display:flex;align-items:center;gap:8px}.help-card button,.help-footer button,.step-actions button{border:1px solid var(--help-blue);border-radius:7px;padding:7px 11px;background:var(--help-surface);color:var(--help-blue);font-weight:600;cursor:pointer}.help-card button.primary,.step-actions button.primary{background:var(--help-blue);color:#fff}.help-card button i{margin-right:5px}.help-link{border:0!important;padding:7px 2px!important;color:var(--help-blue)!important;background:transparent!important}.help-all{width:100%;text-align:left}.help-footer{border-top:1px solid var(--help-line);padding:10px 14px;display:flex;gap:8px}.help-footer button{flex:1;border-color:var(--help-line);color:var(--help-muted)}.help-footer button:disabled{opacity:.55}.step-view{display:none;height:100%;flex-direction:column}.step-view.active{display:flex}.step-body{min-height:0;flex:1;overflow:auto;padding:16px 14px}.step-owner{display:flex;align-items:center;gap:8px;margin-bottom:17px;padding:10px;background:var(--help-soft);border-radius:8px}.step-owner i{font-size:17px;color:var(--help-muted)}.step-owner strong{display:block;font-size:12px}.step-owner span{color:var(--help-muted);font-size:11px}.step-label{margin:0 0 6px;color:var(--help-muted);font-size:11px;text-transform:uppercase;font-weight:700}.step-title{margin:0 0 12px;font-size:16px;line-height:1.3}.step-instruction{margin:0 0 12px;font-size:13px;line-height:1.55}.step-see{padding:10px 11px;border:1px solid var(--help-blue);border-radius:8px;background:var(--help-soft);color:var(--help-text);margin-bottom:12px}.step-see strong{display:block;margin-bottom:2px}.step-actions{display:flex;gap:7px;flex-wrap:wrap}.step-actions button{font-weight:600}.step-nav{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 14px;border-top:1px solid var(--help-line)}.step-nav span{color:var(--help-muted);font-size:11px}.step-nav button{min-width:82px}.step-back{border:0;background:transparent;color:var(--help-muted);cursor:pointer;padding:5px 0}.empty{padding:20px 5px;color:var(--help-muted)}
</style>
<style>.help-context{border-top:1px solid var(--help-line);margin-top:12px;padding-top:12px}.help-context h3{margin:0 0 4px;font-size:12px}.help-context p{margin:0 0 10px;color:var(--help-muted);font-size:11px}</style>
</head>
<body>
<main class="help" data-help-drawer data-tour-url="<?= $esc('/greenportal/principal/modulolegal_requisitoslegais.php') ?>">
  <header class="help-header"><i class="bi bi-question-circle"></i><h1>Ajuda - Requisitos Legais</h1><button class="help-close" type="button" data-help-close aria-label="Fechar">×</button></header>
  <section class="help-home" data-help-home>
    <div class="help-filter"><label for="help-role">Mostrar ajuda como</label><select id="help-role"><option>Administrador / gestor</option><option>Responsável</option></select></div>
    <div class="help-content"><p class="help-kicker">Guias passo a passo</p>
      <?php foreach ($tarefas as $index => $tarefa): $titulo = $pt($tarefa['titulo']); $steps = $tarefa['passos']; ?>
      <article class="help-card" data-help-card data-help-index="<?= (int)$index ?>"><h2><?= $esc($titulo) ?></h2><div class="help-card-meta"><?= count($steps) ?> passos · Requisitos Legais</div><div class="help-card-actions"><button type="button" class="primary" data-help-start="<?= (int)$index ?>"><i class="bi bi-play"></i>Começar</button><button type="button" class="help-link" data-help-steps="<?= (int)$index ?>">Ver os passos <i class="bi bi-chevron-down"></i></button></div></article>
      <?php endforeach; ?>
      <button type="button" class="help-card help-all" data-help-all><i class="bi bi-compass me-1"></i>Ver todos os guias</button>
      <div class="help-context"><h3>O que é esta tela</h3><p>Requisitos Legais é o local onde a empresa avalia as obrigações aplicáveis, define como irá atendê-las e acompanha as evidências.</p><h3>Antes de começar</h3><p>Tenha a legislação vinculada à unidade e selecione uma linha do grid antes de usar as associações e avaliações.</p><h3>O que acontece depois</h3><p>As situações, responsáveis, evidências e tarefas passam a alimentar o acompanhamento do Módulo Legal.</p><h3>Dúvidas comuns</h3><p>Se um botão estiver desabilitado, selecione uma linha ou solicite a permissão correspondente ao administrador.</p></div>
    </div>
    <footer class="help-footer"><button type="button" disabled><i class="bi bi-book me-1"></i>Passo a passo no manual</button><button type="button" disabled><i class="bi bi-chat-question me-1"></i>Perguntar ao assistente</button></footer>
  </section>
  <section class="step-view" data-help-step-view>
    <div class="step-body"><button type="button" class="step-back" data-help-back><i class="bi bi-arrow-left"></i> Voltar</button><div class="step-owner"><i class="bi bi-person"></i><div><strong><?= $esc($usuario ?: 'Usuário atual') ?></strong><span>Requisitos Legais</span></div></div><p class="step-label" data-step-label></p><h2 class="step-title" data-step-title></h2><p class="step-instruction" data-step-text></p><div class="step-see"><strong><i class="bi bi-eye me-1"></i>Você deve ver</strong><span data-step-see></span></div><div class="step-actions"><button type="button" data-step-show><i class="bi bi-hand-index-thumb me-1"></i>Mostre-me</button><button type="button" class="primary" data-step-screen><i class="bi bi-box-arrow-up-right me-1"></i>Ir para a tela</button></div></div><footer class="step-nav"><span data-step-count></span><span><button type="button" data-step-prev>← Voltar</button> <button type="button" class="primary" data-step-next>Próximo →</button></span></footer>
  </section>
</main>
<script>window.greenGuideRequirements=<?= $json($tarefas) ?>;</script>
<script src="../assets/js/guia-drawer.js?v=8"></script>
</body>
</html>
