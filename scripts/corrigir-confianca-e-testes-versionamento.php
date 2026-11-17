<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$targets = ['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'];
$result = [];

foreach ($targets as $database) {
    $pdo = null;
    try {
        $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
        $pdo->beginTransaction();

        $normalize = $pdo->exec("UPDATE li_licencas SET CONFIANCA_EXTRACAO=ROUND(CONFIANCA_EXTRACAO*100,2) WHERE CONFIANCA_EXTRACAO>0 AND CONFIANCA_EXTRACAO<=1");
        $normalizeJson = $pdo->exec("UPDATE li_licencas SET DADOS_EXTRACAO_JSON=JSON_SET(DADOS_EXTRACAO_JSON,'$.confianca',ROUND(CAST(JSON_UNQUOTE(JSON_EXTRACT(DADOS_EXTRACAO_JSON,'$.confianca')) AS DECIMAL(10,4))*100,2)) WHERE JSON_VALID(DADOS_EXTRACAO_JSON) AND JSON_EXTRACT(DADOS_EXTRACAO_JSON,'$.confianca') IS NOT NULL AND CAST(JSON_UNQUOTE(JSON_EXTRACT(DADOS_EXTRACAO_JSON,'$.confianca')) AS DECIMAL(10,4))>0 AND CAST(JSON_UNQUOTE(JSON_EXTRACT(DADOS_EXTRACAO_JSON,'$.confianca')) AS DECIMAL(10,4))<=1");

        $canceled = 0;
        if ($database === 'gatlas_dbp') {
            $stmt = $pdo->prepare("SELECT CODLICENCA, VERSAO FROM li_licencas WHERE CODLICENCA IN (762,763) AND CODLICENCA_BASE=43 AND STATUS_VERSAO IN ('PROCESSANDO','PENDENTE') FOR UPDATE");
            $stmt->execute();
            $testVersions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($testVersions) {
                $ids = array_map(static fn(array $row): int => (int)$row['CODLICENCA'], $testVersions);
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $update = $pdo->prepare("UPDATE li_licencas SET STATUS_VERSAO='CANCELADA', STATUS_EXTRACAO='CANCELADA' WHERE CODLICENCA IN ($marks)");
                $update->execute($ids);
                $canceled = $update->rowCount();
                foreach ($testVersions as $testVersion) {
                    try {
                        $audit = $pdo->prepare("INSERT INTO logs_auditoria(codusuario,transacao,acao,entidade,registro_id,resultado,detalhes) VALUES(NULL,'corrigir-confianca-e-testes-versionamento','CANCELAR_VERSAO_TESTE','li_licencas',:registro,'SUCESSO',:detalhes)");
                        $audit->execute(['registro'=>(string)$testVersion['CODLICENCA'], 'detalhes'=>json_encode(['motivo'=>'Versao criada durante teste E2E interrompido','versao'=>$testVersion['VERSAO']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
                    } catch (Throwable) {}
                }
            }
        }

        $migration = $pdo->prepare("INSERT INTO registros_migracao(codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes) VALUES('GREEN_LICENSE_VERSION_CONFIDENCE_FIX','li_licencas;teste E2E','li_licencas','DADOS','4.0.1','CONCLUIDA',:quantity,NOW(),NOW(),:details) ON DUPLICATE KEY UPDATE status='CONCLUIDA',quantidade_registros=VALUES(quantidade_registros),concluida_em=NOW(),detalhes=VALUES(detalhes)");
        $migration->execute(['quantity'=>(int)$normalize + (int)$normalizeJson + $canceled, 'details'=>json_encode(['confiancas_normalizadas'=>(int)$normalize,'jsons_normalizados'=>(int)$normalizeJson,'versoes_teste_canceladas'=>$canceled,'idempotente'=>true], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $pdo->commit();
        $result[$database] = ['ok'=>true,'confiancas_normalizadas'=>(int)$normalize,'jsons_normalizados'=>(int)$normalizeJson,'versoes_teste_canceladas'=>$canceled];
    } catch (Throwable $error) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        $result[$database] = ['ok'=>false,'erro'=>$error->getMessage()];
    }
}

echo json_encode(['ok'=>count(array_filter($result, static fn(array $item): bool => !empty($item['ok']))) === count($targets),'resultado'=>$result], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL;
