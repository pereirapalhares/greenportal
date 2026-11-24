<?php
declare(strict_types=1);

/*
 * Diagnóstico SOMENTE LEITURA (24/11/2026) para a "Atualização do Access": compara o leis_modelo.mdb com o cadastro de
 * Legislação do portal (glegislacao_dbp), para propor a ligação coluna do Access → campo do portal.
 *  - estrutura da tabela [Legislação Ambiental Aplicável] do modelo (numa CÓPIA) e 5 linhas de exemplo;
 *  - as mesmas leis (pelo código) no portal: colunas de legislacao + tipo/âmbito/assuntos;
 *  - estrutura da mesma tabela num .mdb de unidade do legado (o mais recente encontrado), para ver as colunas extras.
 * Uso: php scripts\diagnostico-access-mapeamento.php storage\diag-access-map.json    Temporário: apague depois.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$saida = (string)($argv[1] ?? '');
$out = [];
$tab = 'Legislação Ambiental Aplicável';
$u8 = static fn($v) => is_string($v) ? (mb_check_encoding($v, 'UTF-8') ? $v : mb_convert_encoding($v, 'UTF-8', 'Windows-1252')) : $v;
$cp = static fn(string $s) => mb_convert_encoding($s, 'Windows-1252', 'UTF-8');

$lerMdb = static function (string $arquivo, int $limite) use ($tab, $u8, $cp): array {
    $copia = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gp_diag_' . getmypid() . '_' . mt_rand() . '.mdb';
    copy($arquivo, $copia);
    $r = ['arquivo' => $arquivo, 'data' => date('d/m/Y H:i', (int)filemtime($arquivo))];
    try {
        $c = odbc_connect('Driver={Microsoft Access Driver (*.mdb, *.accdb)};Dbq=' . $copia . ';ReadOnly=1;', '', '');
        if (!$c) throw new RuntimeException(odbc_errormsg());
        $tabelas = [];
        $t = odbc_tables($c);
        while ($t && ($row = odbc_fetch_array($t))) if (($row['TABLE_TYPE'] ?? '') === 'TABLE') $tabelas[] = $u8($row['TABLE_NAME']);
        $r['tabelas'] = $tabelas;
        $cols = [];
        $cc = odbc_columns($c, null, null, $cp($tab));
        while ($cc && ($row = odbc_fetch_array($cc))) $cols[] = $u8($row['COLUMN_NAME']) . ' ' . $row['TYPE_NAME'] . '(' . $row['COLUMN_SIZE'] . ')';
        $r['colunas'] = $cols;
        $res = odbc_exec($c, $cp("SELECT TOP $limite * FROM [$tab] ORDER BY [N°]"));
        $linhas = [];
        while ($res && ($row = odbc_fetch_array($res))) { $l = []; foreach ($row as $k => $v) $l[$u8($k)] = is_string($v) ? mb_substr($u8($v), 0, 160) : $v; $linhas[] = $l; }
        $r['exemplos'] = $linhas;
        $n = odbc_exec($c, $cp("SELECT COUNT(*) FROM [$tab]")); $r['total'] = $n && odbc_fetch_row($n) ? (int)odbc_result($n, 1) : null;
        odbc_close($c);
    } catch (Throwable $e) { $r['erro'] = $e->getMessage(); }
    @unlink($copia);
    return $r;
};

// modelo mais recente
$modelo = null; $mt = 0;
foreach ((array)glob('E:\\projetoGreen\\GED\\green_access\\leiatu_excel\\*\\leis_modelo.mdb') as $f) if (($t = (int)filemtime($f)) > $mt) { $mt = $t; $modelo = $f; }
if ($modelo) $out['modelo'] = $lerMdb($modelo, 5);
// .mdb de unidade mais recente
$unid = null; $mt = 0;
foreach ((array)glob('E:\\projetoGreen\\GED\\green_access\\leiatu_access\\*\\*.mdb') as $f) if (($t = (int)filemtime($f)) > $mt) { $mt = $t; $unid = $f; }
if ($unid) { $x = $lerMdb($unid, 2); unset($x['exemplos']); $out['unidade_exemplo'] = $x; }
$out['mdb_unidades_legado'] = array_map(static fn($f) => str_replace('E:\\projetoGreen\\GED\\green_access\\leiatu_access\\', '', $f) . ' (' . date('d/m/Y', (int)filemtime($f)) . ')', (array)glob('E:\\projetoGreen\\GED\\green_access\\leiatu_access\\*\\*.mdb'));

// portal: as mesmas leis
try {
    $env = GreenPortalDatabase::env();
    $pdo = GreenPortalDatabase::connect($env, 'glegislacao_dbp', 'GPT');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $out['portal_colunas_legislacao'] = array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='glegislacao_dbp' AND TABLE_NAME='legislacao' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');
    $out['portal_tabelas'] = array_column($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='glegislacao_dbp' ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC), 'TABLE_NAME');
    $codigos = array_values(array_filter(array_map(static fn($l) => (string)($l['N°'] ?? ''), $out['modelo']['exemplos'] ?? [])));
    if ($codigos) {
        $s = $pdo->prepare('SELECT * FROM legislacao WHERE CODIGO IN (' . implode(',', array_fill(0, count($codigos), '?')) . ')');
        $s->execute($codigos);
        $out['portal_mesmas_leis'] = array_map(static fn($l) => array_map(static fn($v) => is_string($v) ? mb_substr($v, 0, 160) : $v, $l), $s->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (Throwable $e) { $out['portal_erro'] = $e->getMessage(); }

$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
if ($saida !== '') { file_put_contents($saida, $json); echo 'gravado em ' . $saida . PHP_EOL; } else echo $json;
