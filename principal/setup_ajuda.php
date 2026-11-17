<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: text/html; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit('Sessão expirada.'); }

$requestedTransaction = (string)($_GET['transacao'] ?? '');
if ($requestedTransaction === '') {
    require_once dirname(__DIR__) . '/classes/AjudaTransacoesCrudGridV2.php';
    AjudaTransacoesCrudGridV2::render();
    exit;
}

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env = GreenPortalDatabase::env();
$language = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';
$transaction = preg_replace('/[^A-Za-z0-9_.-]/', '', $requestedTransaction);
$pdo = GreenPortalDatabase::connect($env, $env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp', 'GUNIVERSAL');
$rows = GreenPortalDatabase::call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'ajuda_transacoes', 'filtros' => json_encode(['transacao' => $transaction, 'ativo' => 1], JSON_UNESCAPED_UNICODE)]);
$row = $rows[0] ?? [];
$title = (string)($row['titulo_' . $language] ?? 'Ajuda');
$text = (string)($row['texto_' . $language] ?? '<p>Não há texto de ajuda cadastrado para esta transação.</p>');
?><!doctype html>
<html lang="<?= htmlspecialchars($language, ENT_QUOTES, 'UTF-8') ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><style>body{padding:1.25rem;background:#fff;color:#111}.help-content{font-size:1rem;line-height:1.6}.help-content a{color:#075e9b}.help-content img{max-width:100%;height:auto}</style></head>
<body data-help-popup data-help-transaction="<?= htmlspecialchars($transaction, ENT_QUOTES, 'UTF-8') ?>"><article class="help-content"><h1 class="h4 mb-3"><i class="bi bi-question-circle me-2"></i><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1><?= $text ?></article></body></html>
