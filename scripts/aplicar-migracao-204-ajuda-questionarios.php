<?php
declare(strict_types=1);

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'guniversal_dbp', 'GUNIVERSAL');
$sql = file_get_contents(__DIR__ . '/../database/204_ajuda_questionarios.sql');
if ($sql === false) throw new RuntimeException('SQL da ajuda dos questionarios nao encontrado.');
$pdo->exec($sql);
$count = (int)$pdo->query("SELECT COUNT(*) FROM ajuda_transacoes WHERE transacao LIKE 'questionario_%' AND ativo=1")->fetchColumn();
$details = json_encode(['transacoes' => $count, 'multidioma' => true, 'popup' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$stmt = $pdo->prepare(
    "INSERT INTO green_universal_migrations(codigo_migracao,versao,status,detalhes)
     VALUES ('GREEN_QUESTIONNAIRE_HELP_204','4.0.1','CONCLUIDA',:detalhes)
     ON DUPLICATE KEY UPDATE status=VALUES(status),detalhes=VALUES(detalhes),executado_em=CURRENT_TIMESTAMP"
);
$stmt->execute(['detalhes' => $details]);
echo json_encode(['ok' => true, 'migration' => '204', 'ajudas_questionarios' => $count], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
