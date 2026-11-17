<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$targets = ['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'];
$viewSql = <<<'SQL'
CREATE OR REPLACE VIEW vw_modulolegal_requisitos_filtro_grid AS
SELECT v.*,
       CASE WHEN
           EXISTS (
               SELECT 1 FROM legislacaoxlicencas r
                WHERE r.CODLEGISLACAO=v.CODLEGISLACAO AND r.codunidade=v.CODUNIDADE
                  AND NOT EXISTS (
                      SELECT 1 FROM content_document_links e
                       WHERE e.codrequisitolegal=r.CODLEGISLACAO
                         AND e.codunidade IN (r.codunidade,0)
                         AND e.entidade_id=r.CODLICENCA
                         AND e.tipo_entidade='LICENCA_REQUISITO'
                         AND e.status='ATIVO'
                  )
           )
           OR EXISTS (
               SELECT 1 FROM legislacaoxdocumentos r
                WHERE r.codlegislacao=v.CODLEGISLACAO AND r.codunidade=v.CODUNIDADE
                  AND NOT EXISTS (
                      SELECT 1 FROM content_document_links e
                       WHERE e.codrequisitolegal=r.codlegislacao
                         AND e.codunidade IN (r.codunidade,0)
                         AND e.entidade_id=r.coddocumento
                         AND e.tipo_entidade='CONTROLE_REQUISITO'
                         AND e.status='ATIVO'
                  )
           )
           OR EXISTS (
               SELECT 1 FROM legislacaoxtreinamentos r
                WHERE r.CODLEGISLACAO=v.CODLEGISLACAO AND r.CODUNIDADE=v.CODUNIDADE
                  AND NOT EXISTS (
                      SELECT 1 FROM content_document_links e
                       WHERE e.codrequisitolegal=r.CODLEGISLACAO
                         AND e.codunidade IN (r.CODUNIDADE,0)
                         AND e.entidade_id=r.CODTREINAMENTO
                         AND e.tipo_entidade='TREINAMENTO_REQUISITO'
                         AND e.status='ATIVO'
                  )
           )
           OR EXISTS (
               SELECT 1 FROM legislacaoxinfraestruturas r
                WHERE r.CODLEGISLACAO=v.CODLEGISLACAO AND r.CODUNIDADE=v.CODUNIDADE
                  AND NOT EXISTS (
                      SELECT 1 FROM content_document_links e
                       WHERE e.codrequisitolegal=r.CODLEGISLACAO
                         AND e.codunidade IN (r.CODUNIDADE,0)
                         AND e.entidade_id=r.CODINFRAESTRUTURA
                         AND e.tipo_entidade='INFRAESTRUTURA_REQUISITO'
                         AND e.status='ATIVO'
                  )
           )
           OR EXISTS (
               SELECT 1 FROM mm_monitoramentoxlegislacao r
               INNER JOIN mm_monitoramento m ON m.CODMONITORAMENTO=r.CODMONITORAMENTO
                WHERE r.CODLEGISLACAO=v.CODLEGISLACAO AND m.codunidade=v.CODUNIDADE
                  AND NOT EXISTS (
                      SELECT 1 FROM content_document_links e
                       WHERE e.codrequisitolegal=r.CODLEGISLACAO
                         AND e.codunidade IN (m.codunidade,0)
                         AND e.entidade_id=r.CODMONITORAMENTO
                         AND e.tipo_entidade='MONITORAMENTO_REQUISITO'
                         AND e.status='ATIVO'
                  )
           )
       THEN 1 ELSE 0 END AS SEM_EVIDENCIA
  FROM vw_modulolegal_requisitos_grid v
SQL;

$menuPdo = GreenPortalDatabase::connect($env, $env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp', 'GUNIVERSAL');
$menuId = (int)$menuPdo->query("SELECT codmenu FROM menus WHERE codigo='REQUISITOS_SEM_EVIDENCIA' LIMIT 1")->fetchColumn();
if ($menuId > 0) {
    $menuPdo->prepare('UPDATE menus SET ativo=0 WHERE codmenu=?')->execute([$menuId]);
}

$result = [];
foreach ($targets as $database) {
    try {
        $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
        $pdo->exec($viewSql);
        if ($menuId > 0) {
            foreach (['permissoes', 'perfil_menus'] as $table) {
                try { $pdo->prepare("DELETE FROM `{$table}` WHERE codmenu=?")->execute([$menuId]); } catch (Throwable) { }
            }
        }
        $count = (int)$pdo->query("SELECT COUNT(*) FROM vw_modulolegal_requisitos_filtro_grid WHERE SEM_EVIDENCIA=1")->fetchColumn();
        $migration = $pdo->prepare("INSERT INTO registros_migracao(codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes) VALUES('GREEN_PHASE5_EVIDENCE_FILTER_232','vw_requisitos_sem_evidencia_grid;content_document_links;legislacaoxlicencas;legislacaoxdocumentos;legislacaoxtreinamentos;legislacaoxinfraestruturas;mm_monitoramentoxlegislacao','vw_modulolegal_requisitos_filtro_grid;modulolegal_requisitoslegais','ESTRUTURA','4.0.2','CONCLUIDA',?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,?) ON DUPLICATE KEY UPDATE status='CONCLUIDA',quantidade_registros=VALUES(quantidade_registros),concluida_em=CURRENT_TIMESTAMP,detalhes=VALUES(detalhes)");
        $migration->execute([$count, json_encode(['filtro'=>'SEM_EVIDENCIA','tipos'=>['LICENCA_REQUISITO','CONTROLE_REQUISITO','TREINAMENTO_REQUISITO','INFRAESTRUTURA_REQUISITO','MONITORAMENTO_REQUISITO'],'menu_antigo_desativado'=>true], JSON_UNESCAPED_UNICODE)]);
        $result[$database] = ['ok'=>true, 'requisitos_sem_evidencia'=>$count];
    } catch (Throwable $e) {
        $result[$database] = ['ok'=>false, 'erro'=>$e->getMessage()];
    }
}
echo json_encode(['ok'=>!array_filter($result, static fn(array $r): bool => empty($r['ok'])), 'migration'=>232, 'targets'=>$result], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL;
