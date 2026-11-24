<?php
declare(strict_types=1);

/*
 * Diagnóstico SOMENTE LEITURA (23/11/2026): o PHP deste servidor consegue abrir arquivos Access (.mdb)?
 * Necessário para a transação "Atualização do Access" (substituto do clsLegislacao.exe).
 *  - mostra a arquitetura do PHP (32/64 bits) e as extensões odbc, pdo_odbc e com_dotnet;
 *  - lista os drivers ODBC do Access instalados (pelo registro do Windows);
 *  - copia o leis_modelo.mdb mais recente do GED do legado para uma pasta temporária e tenta ler a tabela
 *    [Legislação Ambiental Aplicável] por ODBC e por COM/ADODB (só leitura, na CÓPIA; o original não é aberto).
 * Uso: php scripts\diagnostico-access-driver.php storage\diag-access.json      Temporário: apague depois.
 */

$saida = (string)($argv[1] ?? '');
$out = ['php' => PHP_VERSION, 'bits' => PHP_INT_SIZE * 8, 'sapi' => PHP_SAPI,
    'extensoes' => ['odbc' => extension_loaded('odbc'), 'pdo_odbc' => extension_loaded('pdo_odbc'), 'com_dotnet' => extension_loaded('com_dotnet')]];

// drivers ODBC do Access no registro (64 e 32 bits)
$drivers = [];
foreach (['HKLM\\SOFTWARE\\ODBC\\ODBCINST.INI\\ODBC Drivers', 'HKLM\\SOFTWARE\\WOW6432Node\\ODBC\\ODBCINST.INI\\ODBC Drivers'] as $chave) {
    $txt = @shell_exec('reg query "' . $chave . '" 2>NUL');
    foreach (preg_split('/\R/', (string)$txt) as $l) if (preg_match('/^\s+(.*Access.*?)\s+REG_SZ/i', $l, $m)) $drivers[] = (str_contains($chave, 'WOW6432') ? '32 bits: ' : '64 bits: ') . trim($m[1]);
}
$out['drivers_odbc_access'] = $drivers;
$provedores = [];
foreach (['Microsoft.ACE.OLEDB.16.0', 'Microsoft.ACE.OLEDB.12.0', 'Microsoft.Jet.OLEDB.4.0'] as $p) {
    $txt = @shell_exec('reg query "HKCR\\' . $p . '" 2>NUL');
    $provedores[$p] = trim((string)$txt) !== '';
}
$out['provedores_oledb_registrados'] = $provedores;

// leis_modelo.mdb mais recente do GED do legado
$base = 'E:\\projetoGreen\\GED\\green_access\\leiatu_excel';
$modelo = null; $mtime = 0;
foreach ((array)@glob($base . '\\*\\leis_modelo.mdb') as $f) { $t = (int)@filemtime($f); if ($t > $mtime) { $mtime = $t; $modelo = $f; } }
$out['leis_modelo'] = $modelo ? ['arquivo' => $modelo, 'data' => date('d/m/Y H:i', $mtime), 'bytes' => @filesize($modelo)] : 'não encontrado em ' . $base;

if ($modelo) {
    $copia = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gp_diag_leis_modelo_' . getmypid() . '.mdb';
    @copy($modelo, $copia);
    $sql = 'SELECT COUNT(*) AS n FROM [Legislação Ambiental Aplicável]';
    // ODBC
    if (extension_loaded('odbc')) {
        foreach (['Microsoft Access Driver (*.mdb, *.accdb)', 'Microsoft Access Driver (*.mdb)'] as $drv) {
            $c = @odbc_connect('Driver={' . $drv . '};Dbq=' . $copia . ';ReadOnly=1;', '', '');
            if (!$c) { $out['teste_odbc'][$drv] = 'não conectou: ' . odbc_errormsg(); continue; }
            $r = @odbc_exec($c, mb_convert_encoding($sql, 'Windows-1252', 'UTF-8'));
            $out['teste_odbc'][$drv] = $r ? ('OK, linhas: ' . (odbc_fetch_row($r) ? odbc_result($r, 1) : '?')) : ('conectou, erro na leitura: ' . odbc_errormsg($c));
            odbc_close($c);
        }
    }
    // COM / ADODB
    if (extension_loaded('com_dotnet')) {
        foreach (['Microsoft.ACE.OLEDB.16.0', 'Microsoft.ACE.OLEDB.12.0', 'Microsoft.Jet.OLEDB.4.0'] as $p) {
            try {
                $cn = new COM('ADODB.Connection', null, CP_UTF8);
                $cn->Open('Provider=' . $p . ';Data Source=' . $copia . ';Mode=Read;');
                $rs = $cn->Execute($sql);
                $out['teste_adodb'][$p] = 'OK, linhas: ' . (string)$rs->Fields(0)->Value;
                $rs->Close(); $cn->Close();
            } catch (Throwable $e) { $out['teste_adodb'][$p] = 'falhou: ' . substr($e->getMessage(), 0, 200); }
        }
    }
    @unlink($copia);
}

$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
if ($saida !== '') { file_put_contents($saida, $json); echo 'gravado em ' . $saida . PHP_EOL; } else echo $json;
