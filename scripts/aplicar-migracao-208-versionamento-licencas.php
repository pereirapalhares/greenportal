<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$targets = ['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'];
$legacyNames = ['gatlas_dbp' => 'green_atlas', 'gmodelo_dbp' => 'green_modelo', 'gmodelo2_dbp' => 'green_modelo2'];
$root = rtrim((string)($env['GGED_LEGACY_ROOT'] ?? 'E:' . DIRECTORY_SEPARATOR . 'projetoGreen' . DIRECTORY_SEPARATOR . 'GED'), '\/');
$columns = [
    'DADOS_DOCUMENTO_IA' => 'JSON NULL',
    'ORGAO_EMISSOR' => 'VARCHAR(255) NULL',
    'NUMERO_LICENCA' => 'VARCHAR(120) NULL',
    'CODLICENCA_BASE' => 'INT NULL',
    'VERSAO' => "VARCHAR(20) NOT NULL DEFAULT '1.0'",
    'STATUS_VERSAO' => "VARCHAR(30) NOT NULL DEFAULT 'ATIVA'",
    'CODLICENCA_ANTERIOR' => 'INT NULL',
    'NOME_ARQUIVO' => 'VARCHAR(255) NULL',
    'CAMINHO_ARQUIVO' => 'VARCHAR(1000) NULL',
    'MIME_ARQUIVO' => 'VARCHAR(150) NULL',
    'HASH_ARQUIVO' => 'CHAR(64) NULL',
    'DATA_UPLOAD' => 'DATETIME NULL',
    'CODUSUARIO_UPLOAD' => 'INT NULL',
    'STATUS_EXTRACAO' => "VARCHAR(30) NOT NULL DEFAULT 'NAO_SOLICITADA'",
    'FONTE_EXTRACAO' => 'VARCHAR(30) NULL',
    'CONFIANCA_EXTRACAO' => 'DECIMAL(5,2) NULL',
    'DATA_EXTRACAO' => 'DATETIME NULL',
    'DADOS_EXTRACAO_JSON' => 'JSON NULL',
];

function hasColumn(PDO $pdo, string $table, string $column): bool
{
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:table_name AND column_name=:column_name');
    $q->execute(['table_name' => $table, 'column_name' => $column]);
    return (int)$q->fetchColumn() > 0;
}

function hasIndex(PDO $pdo, string $table, string $index): bool
{
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=:table_name AND index_name=:index_name');
    $q->execute(['table_name' => $table, 'index_name' => $index]);
    return (int)$q->fetchColumn() > 0;
}

function copyLegacyFiles(PDO $pdo, string $database, string $legacyName, string $root): array
{
    $canonicalRoot = $root . DIRECTORY_SEPARATOR . $legacyName . DIRECTORY_SEPARATOR . 'licencas';
    if (!is_dir($canonicalRoot) && !@mkdir($canonicalRoot, 0770, true) && !is_dir($canonicalRoot)) {
        throw new RuntimeException('Nao foi possivel criar o diretorio canonico do GED: ' . $canonicalRoot);
    }
    $fallbackRoot = rtrim((string)(GreenPortalDatabase::env()['GGED_STORAGE_LOCAL_ROOT'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage'), '\/') . DIRECTORY_SEPARATOR . $legacyName . DIRECTORY_SEPARATOR . 'licencas';
    $licenses = $pdo->query('SELECT CODLICENCA,NOME_ARQUIVO,CAMINHO_ARQUIVO,HASH_ARQUIVO,MIME_ARQUIVO FROM li_licencas ORDER BY CODLICENCA')->fetchAll(PDO::FETCH_ASSOC);
    $copied = 0; $metadata = 0; $files = 0;
    $update = $pdo->prepare('UPDATE li_licencas SET NOME_ARQUIVO=COALESCE(NULLIF(NOME_ARQUIVO,\'\'),:name),CAMINHO_ARQUIVO=COALESCE(NULLIF(CAMINHO_ARQUIVO,\'\'),:relative_path),MIME_ARQUIVO=COALESCE(NULLIF(MIME_ARQUIVO,\'\'),:mime),HASH_ARQUIVO=COALESCE(NULLIF(HASH_ARQUIVO,\'\'),:hash),DATA_UPLOAD=COALESCE(DATA_UPLOAD,:uploaded_at) WHERE CODLICENCA=:id');
    foreach ($licenses as $license) {
        $id = (int)$license['CODLICENCA']; if ($id < 1) continue;
        $canonical = $canonicalRoot . DIRECTORY_SEPARATOR . $id;
        if (!is_dir($canonical) && !@mkdir($canonical, 0770, true) && !is_dir($canonical)) throw new RuntimeException('Nao foi possivel criar a pasta da licenca ' . $id . '.');
        $sources = [$canonical]; $fallback = $fallbackRoot . DIRECTORY_SEPARATOR . $id;
        if (strcasecmp($fallback, $canonical) !== 0) $sources[] = $fallback;
        foreach ($sources as $source) {
            if (!is_dir($source)) continue;
            foreach (new DirectoryIterator($source) as $file) {
                if ($file->isDot() || !$file->isFile()) continue;
                $files++;
                $target = $canonical . DIRECTORY_SEPARATOR . $file->getFilename();
                if (realpath($file->getPathname()) !== realpath($target)) {
                    if (!is_file($target)) { if (!@copy($file->getPathname(), $target)) throw new RuntimeException('Nao foi possivel copiar o arquivo da licenca ' . $id . '.'); $copied++; }
                }
            }
        }
        $first = null; foreach (new DirectoryIterator($canonical) as $file) { if (!$file->isDot() && $file->isFile()) { $first = $file; break; } }
        if ($first instanceof SplFileInfo && trim((string)$license['NOME_ARQUIVO']) === '') {
            $path = $first->getPathname(); $hash = hash_file('sha256', $path) ?: null; $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: null;
            $update->execute(['name'=>$first->getFilename(),'relative_path'=>$legacyName . DIRECTORY_SEPARATOR . 'licencas' . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . $first->getFilename(),'mime'=>$mime,'hash'=>$hash,'uploaded_at'=>date('Y-m-d H:i:s', $first->getMTime()),'id'=>$id]);
            $metadata += $update->rowCount();
        }
    }
    return ['files_seen'=>$files,'files_copied'=>$copied,'metadata_updated'=>$metadata];
}

$out = []; foreach ($targets as $database) {
    $pdo = null; $details = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
        foreach ($columns as $column => $definition) if (!hasColumn($pdo, 'li_licencas', $column)) $pdo->exec('ALTER TABLE `li_licencas` ADD COLUMN `' . $column . '` ' . $definition);
        if (!hasIndex($pdo, 'li_licencas', 'ix_li_licencas_versao')) $pdo->exec('CREATE INDEX ix_li_licencas_versao ON li_licencas (CODLICENCA_BASE, STATUS_VERSAO, VERSAO)');
        if (!hasIndex($pdo, 'li_licencas', 'ix_li_licencas_hash')) $pdo->exec('CREATE INDEX ix_li_licencas_hash ON li_licencas (HASH_ARQUIVO)');
        $pdo->exec('UPDATE li_licencas SET CODLICENCA_BASE=CODLICENCA WHERE CODLICENCA_BASE IS NULL');
        $pdo->exec("UPDATE li_licencas SET VERSAO='1.0' WHERE VERSAO IS NULL OR VERSAO=''");
        $pdo->exec("UPDATE li_licencas SET STATUS_VERSAO='ATIVA' WHERE STATUS_VERSAO IS NULL OR STATUS_VERSAO=''");
        $pdo->exec("UPDATE li_licencas SET STATUS_EXTRACAO=IF(DADOS_DOCUMENTO_IA IS NULL,'NAO_SOLICITADA','CONCLUIDA') WHERE STATUS_EXTRACAO IS NULL OR STATUS_EXTRACAO=''");
        $details = ['arquivos_fisicos'=>'Os arquivos legados ja seguem o caminho canonico; nenhuma copia ou movimentacao foi executada.','caminho_canonico'=>$root . DIRECTORY_SEPARATOR . $legacyNames[$database] . DIRECTORY_SEPARATOR . 'licencas'];
        $count = (int)$pdo->query('SELECT COUNT(*) FROM li_licencas')->fetchColumn();
        $migration = $pdo->prepare("INSERT INTO registros_migracao(codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes) VALUES('GREEN_LICENSE_VERSION_GED_208','li_licencas;GED legado','li_licencas;E:/projetoGreen/GED/green_<empresa>/licencas','ESTRUTURA','4.0.1','CONCLUIDA',:quantity,NOW(),NOW(),:details) ON DUPLICATE KEY UPDATE status='CONCLUIDA',quantidade_registros=VALUES(quantidade_registros),concluida_em=NOW(),detalhes=VALUES(detalhes)");
        $migration->execute(['quantity'=>$count,'details'=>json_encode(['database'=>$database,'versoes_em_li_licencas'=>true,'caminho_canonico'=>'E:/projetoGreen/GED/green_<empresa>/licencas/<codlicenca>/<arquivo>','idempotente'=>true]+$details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $out[$database] = ['ok'=>true,'licencas'=>$count]+$details;
    } catch (Throwable $e) {
        $out[$database] = ['ok'=>false,'error'=>$e->getMessage()];
        if ($pdo instanceof PDO) { try { $q=$pdo->prepare("INSERT INTO registros_migracao(codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes) VALUES('GREEN_LICENSE_VERSION_GED_208','li_licencas;GED legado','li_licencas;GED legado','ESTRUTURA','4.0.1','ERRO',0,NOW(),NOW(),:details) ON DUPLICATE KEY UPDATE status='ERRO',concluida_em=NOW(),detalhes=VALUES(detalhes)");$q->execute(['details'=>json_encode(['database'=>$database,'erro'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]); } catch (Throwable) {} }
    }
}
echo json_encode(['ok'=>count(array_filter($out, static fn(array $r): bool => !empty($r['ok'])))===count($targets),'migration'=>'208','targets'=>$out],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
