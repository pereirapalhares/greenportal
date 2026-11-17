<?php
declare(strict_types=1);

[$script, $input, $output, $source, $target] = array_pad($argv, 5, '');
if ($input === '' || $output === '' || $source === '' || $target === '') {
    fwrite(STDERR, "Uso: php transformar-dump-restauracao-homologacao.php input output source target\n");
    exit(2);
}
$in = fopen($input, 'rb');
$out = fopen($output, 'wb');
if (!is_resource($in) || !is_resource($out)) {
    fwrite(STDERR, "Nao foi possivel abrir o dump.\n");
    exit(2);
}
while (($line = fgets($in)) !== false) {
    $line = str_replace(
        ["`{$source}`", "CREATE DATABASE /*!32312 IF NOT EXISTS*/ `{$source}`"],
        ["`{$target}`", "CREATE DATABASE /*!32312 IF NOT EXISTS*/ `{$target}`"],
        $line
    );
    fwrite($out, $line);
}
fclose($in);
fclose($out);
