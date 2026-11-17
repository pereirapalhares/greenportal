<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env = GreenPortalDatabase::env();
$sql = file_get_contents(dirname(__DIR__) . '/database/283_desativar_servicos_http_exportacao.sql');
if ($sql === false) throw new RuntimeException('Migração 283 não localizada.');
$pdo = GreenPortalDatabase::connect($env, 'guniversal_dbp', 'GUNIVERSAL');
$pdo->exec($sql);
$st = $pdo->query("SELECT codigo,host,porta,script,ativo FROM servicos WHERE codigo IN ('EXPORTACAO_GRID','INTELIGENCIA_ARTIFICIAL') ORDER BY codigo");
echo json_encode(['ok'=>true,'migration'=>'283','services'=>$st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
