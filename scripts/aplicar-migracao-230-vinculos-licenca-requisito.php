<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env=GreenPortalDatabase::env();$summary=[];
foreach(['gatlas_dbp','gmodelo_dbp','gmodelo2_dbp'] as $database){$pdo=GreenPortalDatabase::connect($env,$database,'GPT');$q=$pdo->query("SELECT r.CODLEGISLACAO,r.CODLICENCA,r.codunidade,d.coddocumento FROM legislacaoxlicencas r JOIN content_document_links d ON d.tipo_entidade='LICENCA' AND d.entidade_id=r.CODLICENCA AND d.codunidade IN (r.codunidade,0) WHERE d.status='ATIVO'");$ins=$pdo->prepare("INSERT IGNORE INTO content_document_links(coddocumento,tipo_entidade,entidade_id,codrequisitolegal,codunidade,papel,origem_vinculo,status) VALUES(?,?,?,?,?,'EVIDENCIA','MIGRACAO','ATIVO')");$n=0;foreach($q as $row){$ins->execute([(int)$row['coddocumento'],'LICENCA_REQUISITO',(int)$row['CODLICENCA'],(int)$row['CODLEGISLACAO'],(int)$row['codunidade']]);$n+=(int)$ins->rowCount();}$summary[$database]=['vinculos_criados'=>$n,'requisitos_licencas'=>(int)$pdo->query('SELECT COUNT(*) FROM legislacaoxlicencas')->fetchColumn()];}
echo json_encode(['ok'=>true,'migration'=>230,'targets'=>$summary],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
