<?php
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), 'gatlas_dbp', 'GPT');
$userId = (int)($argv[1] ?? 4);
$rows = $pdo->query("SELECT codexportacaogrid,status,arquivo,erro,solicitado_em FROM exportacoes_grid WHERE codusuario={$userId} AND transacao='seguranca_usuarios' ORDER BY codexportacaogrid DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL;
