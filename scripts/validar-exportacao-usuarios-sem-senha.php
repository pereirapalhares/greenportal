<?php
$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) { fwrite(STDERR, "Arquivo nao localizado\n"); exit(1); }
$zip = new ZipArchive();
if ($zip->open($file) !== true) { fwrite(STDERR, "XLSX invalido\n"); exit(1); }
$parts = [];
for ($i=0; $i<$zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (str_contains($name, 'sharedStrings') || preg_match('#xl/worksheets/sheet\d+\.xml#', $name)) $parts[] = $zip->getFromIndex($i);
}
$zip->close();
$text = strip_tags(implode(' ', $parts));
$hasPassword = preg_match('/(?<![a-z0-9_])(senha|password)(?![a-z0-9_])/i', $text) === 1;
$matches=[]; preg_match_all('/.{0,80}(?:senha|password).{0,80}/i', $text, $matches);
echo json_encode(['ok'=>!$hasPassword,'arquivo'=>basename($file),'senha_encontrada'=>$hasPassword,'contexto'=>$matches[0]??[]], JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($hasPassword ? 1 : 0);
