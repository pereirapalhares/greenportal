<?php
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$file = $argv[1] ?? '';
if (!is_file($file)) { fwrite(STDERR, "Arquivo nao localizado\n"); exit(1); }
$pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), 'gatlas_dbp', 'GPT');
$row = $pdo->query("SELECT perfil_nome,grupo_nome FROM vw_usuarios_grid WHERE userid='admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$zip = new ZipArchive(); $zip->open($file); $text='';
for($i=0;$i<$zip->numFiles;$i++){ $name=$zip->getNameIndex($i); if(str_contains($name,'sharedStrings')||preg_match('#xl/worksheets/sheet\\d+\\.xml#',$name))$text.=' '.html_entity_decode(strip_tags($zip->getFromIndex($i)),ENT_QUOTES|ENT_XML1,'UTF-8'); }
$zip->close();
$profile=(string)($row['perfil_nome']??'');$group=(string)($row['grupo_nome']??'');
$ok=$profile!==''&&$group!==''&&str_contains($text,$profile)&&str_contains($text,$group)&&!preg_match('/(?<![a-z0-9_])senha(?![a-z0-9_])/i',$text);
echo json_encode(['ok'=>$ok,'perfil_esperado'=>$profile,'grupo_esperado'=>$group,'perfil_encontrado'=>str_contains($text,$profile),'grupo_encontrado'=>str_contains($text,$group)],JSON_UNESCAPED_UNICODE).PHP_EOL;exit($ok?0:1);
