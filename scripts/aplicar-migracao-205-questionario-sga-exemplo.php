<?php
declare(strict_types=1);

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'guniversal_dbp', 'GUNIVERSAL');
$version = $pdo->prepare(
    "SELECT v.codversao,v.status
       FROM amb_questionario q
       JOIN amb_questionario_versao v ON v.codquestionario=q.codquestionario
      WHERE q.codigo='SGA_COMPLIANCE' AND v.versao=1
      LIMIT 1"
);
$version->execute();
$row = $version->fetch(PDO::FETCH_ASSOC);
if (!$row) throw new RuntimeException('Questionario SGA_COMPLIANCE versao 1 nao encontrado.');
if ((string)$row['status'] !== 'RASCUNHO') throw new RuntimeException('A versao do SGA nao esta em rascunho; nenhuma alteracao foi feita.');

$sql = file_get_contents(__DIR__ . '/../database/205_questionario_sga_exemplo.sql');
if ($sql === false) throw new RuntimeException('SQL do exemplo SGA nao encontrado.');
$pdo->exec($sql);
$count = $pdo->prepare('SELECT COUNT(*) FROM amb_questionario_pergunta WHERE codversao=:versao AND codigo LIKE \'SGA_%\' AND ativo=1');
$count->execute(['versao' => (int)$row['codversao']]);
echo json_encode([
    'ok' => true,
    'migration' => '205',
    'questionario' => 'SGA_COMPLIANCE',
    'versao' => 1,
    'status' => 'RASCUNHO',
    'perguntas_sga' => (int)$count->fetchColumn(),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
