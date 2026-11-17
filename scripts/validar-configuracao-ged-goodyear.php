<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env=GreenPortalDatabase::env();
$central=GreenPortalDatabase::connect($env,'guniversal_dbp','GUNIVERSAL');
$q=$central->query("SELECT e.codigo,e.nome,e.banco_dados,e.ged,c.banco_legado,c.banco_novo,c.status_migracao,c.sincronismo_ativo FROM empresa e JOIN empresa_migracao_config c ON c.codempresa=e.codempresa WHERE c.banco_novo='ggdamericana_dbp' LIMIT 1");
$row=$q->fetch(PDO::FETCH_ASSOC);
$root='E:\\projetoGreen\\GED';$legacy=(string)($row['banco_legado']??'');$base=$root.DIRECTORY_SEPARATOR.$legacy.DIRECTORY_SEPARATOR.'licencas';
$folders=is_dir($base)?array_values(array_filter(scandir($base),static fn(string $v):bool=>ctype_digit($v))):[];$files=0;$withFiles=0;$missing=[];
$target=GreenPortalDatabase::connect($env,'ggdamericana_dbp','GPT');
foreach($target->query('SELECT CODLICENCA FROM li_licencas ORDER BY CODLICENCA') as $license){$id=(int)$license['CODLICENCA'];$folder=$base.DIRECTORY_SEPARATOR.$id;if(!is_dir($folder)){$missing[]=$id;continue;}$items=array_values(array_filter(scandir($folder),function(string $v) use ($folder):bool{return $v!=='.'&&$v!=='..'&&is_file($folder.DIRECTORY_SEPARATOR.$v);}));if($items){$withFiles++;$files+=count($items);}}
echo json_encode(['empresa'=>$row,'root'=>$root,'base_licencas'=>$base,'pastas_licencas'=>count($folders),'licencas_com_arquivos'=>$withFiles,'arquivos'=>$files,'licencas_sem_pasta'=>$missing],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
