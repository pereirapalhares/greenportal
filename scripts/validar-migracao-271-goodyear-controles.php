<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env=GreenPortalDatabase::env();$legacy=GreenPortalDatabase::connect($env,'green_gd_americana','LEGADO_MODELO');$target=GreenPortalDatabase::connect($env,'ggdamericana_dbp','GPT');
$source=$legacy->query('SELECT CODDOCUMENTO,CODIGO,TITULO,ARQUIVO FROM documentos ORDER BY CODDOCUMENTO')->fetchAll(PDO::FETCH_ASSOC);$mismatch=[];
foreach($source as $row){$q=$target->prepare('SELECT CODIGO,TITULO,ARQUIVO FROM documentos WHERE CODDOCUMENTO=:id');$q->execute(['id'=>(int)$row['CODDOCUMENTO']]);$dest=$q->fetch(PDO::FETCH_ASSOC);if(!$dest||array_map('strval',$dest)!==array_map('strval',['CODIGO'=>$row['CODIGO'],'TITULO'=>$row['TITULO'],'ARQUIVO'=>$row['ARQUIVO']??'']))$mismatch[]=(int)$row['CODDOCUMENTO'];}
$orphans=(int)$target->query('SELECT COUNT(*) FROM content_document_links l LEFT JOIN documentos d ON l.tipo_entidade="CONTROLE" AND l.entidade_id=d.CODDOCUMENTO WHERE l.tipo_entidade="CONTROLE" AND d.CODDOCUMENTO IS NULL')->fetchColumn();
$audit=$target->query("SELECT codigo_migracao,status,quantidade_registros FROM registros_migracao WHERE codigo_migracao='GREEN_GOODYEAR_CONTROLES_271'")->fetch(PDO::FETCH_ASSOC);
echo json_encode(['ok'=>!$mismatch&&$orphans===0&&$audit&&$audit['status']==='CONCLUIDA','documentos_origem'=>count($source),'divergencias'=>$mismatch,'vinculos_controle_orfaos'=>$orphans,'auditoria'=>$audit],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
