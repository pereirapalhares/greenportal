<?php
declare(strict_types=1);

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'guniversal_dbp', 'GUNIVERSAL');
$query = $pdo->prepare(
    "SELECT p.codpergunta,p.codigo_hierarquico,p.texto_pt
       FROM amb_questionario_pergunta p
       JOIN amb_questionario_versao v ON v.codversao=p.codversao
       JOIN amb_questionario q ON q.codquestionario=v.codquestionario
      WHERE q.codigo='LEGAL_REQUIREMENTS' AND v.versao=1 AND p.codigo_hierarquico LIKE :prefix
      ORDER BY p.codigo_hierarquico,p.ordem
      LIMIT 10"
);
$query->execute(['prefix' => '1.1%']);
$total = $pdo->query(
    "SELECT COUNT(*)
       FROM amb_questionario_pergunta p
       JOIN amb_questionario_versao v ON v.codversao=p.codversao
       JOIN amb_questionario q ON q.codquestionario=v.codquestionario
      WHERE q.codigo='LEGAL_REQUIREMENTS' AND v.versao=1 AND p.codigo_hierarquico LIKE '1.1%'"
)->fetchColumn();
$prefixes = $pdo->query(
    "SELECT p.codigo_hierarquico,COUNT(*) AS quantidade
       FROM amb_questionario_pergunta p
       JOIN amb_questionario_versao v ON v.codversao=p.codversao
       JOIN amb_questionario q ON q.codquestionario=v.codquestionario
      WHERE q.codigo='LEGAL_REQUIREMENTS' AND v.versao=1 AND p.codigo_hierarquico IS NOT NULL
      GROUP BY p.codigo_hierarquico ORDER BY p.codigo_hierarquico LIMIT 30"
)->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['total_prefixo_1_1' => (int)$total, 'amostra' => $query->fetchAll(PDO::FETCH_ASSOC), 'prefixos' => $prefixes], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
