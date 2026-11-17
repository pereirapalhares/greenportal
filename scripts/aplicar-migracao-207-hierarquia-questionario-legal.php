<?php
declare(strict_types=1);

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'guniversal_dbp', 'GUNIVERSAL');

$column = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema=DATABASE() AND table_name='amb_questionario_pergunta' AND column_name='codigo_hierarquico'"
);
$column->execute();
if ((int)$column->fetchColumn() === 0) {
    $pdo->exec("ALTER TABLE amb_questionario_pergunta ADD COLUMN codigo_hierarquico VARCHAR(100) NULL AFTER codigo");
}
$index = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema=DATABASE() AND table_name='amb_questionario_pergunta' AND index_name='ix_amb_questionario_pergunta_hierarquia'"
);
$index->execute();
if ((int)$index->fetchColumn() === 0) {
    $pdo->exec('ALTER TABLE amb_questionario_pergunta ADD KEY ix_amb_questionario_pergunta_hierarquia (codversao,codigo_hierarquico)');
}

$pdo->exec(
    'CREATE OR REPLACE VIEW vw_amb_questionario_perguntas AS
     SELECT p.codpergunta,p.codversao,p.codbloco,p.codigo,p.codigo_hierarquico,p.texto_pt,p.texto_en,p.texto_es,
            p.tipo,p.obrigatoria,p.ordem,p.peso,p.ativo,o.codopcao,o.codigo AS codigo_opcao,
            o.rotulo_pt,o.rotulo_en,o.rotulo_es,o.valor,o.pontuacao,o.ordem AS ordem_opcao
       FROM amb_questionario_pergunta p
       LEFT JOIN amb_questionario_opcao o ON o.codpergunta=p.codpergunta AND o.ativo=1
      WHERE p.ativo=1'
);

$questions = $pdo->query(
    "SELECT p.codpergunta,p.texto_pt
       FROM amb_questionario_pergunta p
       JOIN amb_questionario_versao v ON v.codversao=p.codversao
       JOIN amb_questionario q ON q.codquestionario=v.codquestionario
      WHERE q.codigo='LEGAL_REQUIREMENTS' AND v.versao=1 AND p.ativo=1 AND p.codigo_hierarquico IS NULL"
)->fetchAll(PDO::FETCH_ASSOC);
$update = $pdo->prepare('UPDATE amb_questionario_pergunta SET codigo_hierarquico=:hierarquia WHERE codpergunta=:pergunta AND codigo_hierarquico IS NULL');
$updated = 0;
foreach ($questions as $question) {
    $text = trim((string)$question['texto_pt']);
    if (!preg_match('/^\s*(\d+(?:\.\d+)*\.?)(?=\s|[-–—:)]+|$)/u', $text, $match)) continue;
    $update->execute(['hierarquia' => $match[1], 'pergunta' => (int)$question['codpergunta']]);
    $updated += $update->rowCount();
}

$migration = $pdo->prepare(
    "INSERT INTO green_universal_migrations(codigo_migracao,versao,status,detalhes)
     VALUES ('GREEN_QUESTIONNAIRE_LEGAL_HIERARCHY_207','4.0.1','CONCLUIDA',:detalhes)
     ON DUPLICATE KEY UPDATE status=VALUES(status),detalhes=VALUES(detalhes),executado_em=CURRENT_TIMESTAMP"
);
$migration->execute(['detalhes' => json_encode([
    'campo' => 'codigo_hierarquico',
    'questionario' => 'LEGAL_REQUIREMENTS',
    'prefixos_identificados' => $updated,
    'padrao' => '1. / 1.1. / 1.1.1.',
    'texto_original_preservado' => true,
    'idempotente' => true,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

echo json_encode(['ok' => true, 'migration' => '207', 'prefixos_identificados' => $updated], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
