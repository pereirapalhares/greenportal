<?php
declare(strict_types=1);

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'guniversal_dbp', 'GUNIVERSAL');
$catalogo = $pdo->query(
    "SELECT q.codigo, v.status, COUNT(p.codpergunta) AS perguntas
       FROM amb_questionario q
       JOIN amb_questionario_versao v ON v.codquestionario=q.codquestionario
       LEFT JOIN amb_questionario_pergunta p ON p.codversao=v.codversao AND p.ativo=1
      WHERE q.codigo='LEGAL_REQUIREMENTS'
      GROUP BY q.codigo,v.status"
)->fetchAll(PDO::FETCH_ASSOC);
$migration = $pdo->prepare(
    'SELECT codigo_migracao,status,detalhes FROM green_universal_migrations
      WHERE codigo_migracao=:codigo'
);
$migration->execute(['codigo' => 'GREEN_QUESTIONNAIRE_LEGAL_SANITIZATION_203']);
echo json_encode([
    'questionario' => $catalogo,
    'saneamentos' => (int)$pdo->query('SELECT COUNT(*) FROM amb_questionario_pergunta_saneamento')->fetchColumn(),
    'destinos_distintos' => (int)$pdo->query('SELECT COUNT(DISTINCT codpergunta_destino) FROM amb_questionario_pergunta_saneamento WHERE codpergunta_destino IS NOT NULL')->fetchColumn(),
    'vinculos' => (int)$pdo->query('SELECT COUNT(*) FROM amb_questionario_pergunta_legado_vinculo')->fetchColumn(),
    'migracao' => $migration->fetch(PDO::FETCH_ASSOC),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
