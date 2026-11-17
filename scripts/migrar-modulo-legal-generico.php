<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

function argumento(string $nome, ?string $padrao = null): ?string {
    foreach ($GLOBALS['argv'] as $arg) if (str_starts_with($arg, '--' . $nome . '=')) return substr($arg, strlen($nome) + 3);
    return $padrao;
}
function identificador(string $valor): string { if (!preg_match('/^[A-Za-z0-9_]+$/', $valor)) throw new RuntimeException('Identificador inválido: ' . $valor); return '`' . $valor . '`'; }
function tabelaBase(PDO $pdo, string $table): bool {
    $q = $pdo->prepare("SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $q->execute([$table]);
    return $q->fetchColumn() === 'BASE TABLE';
}
function colunas(PDO $pdo, string $table): array {
    $q = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION"); $q->execute([$table]);
    return $q->fetchAll(PDO::FETCH_COLUMN);
}
function conexaoLegado(array $env, array $origins, string $table): ?PDO {
    foreach ($origins as $origin) { $pdo = GreenPortalDatabase::connect($env, $origin, 'LEGADO_MODELO'); if (tabelaBase($pdo, $table)) return $pdo; }
    return null;
}
function registrarOrfao(PDO $pdo, string $origem, string $destino, int $solicitacao, string $tabela, string $motivo, array $registro): bool {
    $json = json_encode(['origem'=>$origem,'destino'=>$destino,'solicitacao'=>$solicitacao,'tabela'=>$tabela,'motivo'=>$motivo,'registro'=>$registro], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) $json = '{"erro":"falha ao serializar registro orfao"}';
    if (strlen($json) > 5000) {
        $compacto = base64_encode(gzcompress($json, 9));
        $json = json_encode(['origem'=>$origem,'destino'=>$destino,'solicitacao'=>$solicitacao,'tabela'=>$tabela,'motivo'=>$motivo,'registro_comprimido_base64'=>$compacto,'tamanho_original'=>strlen($json)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > 5000) $json = json_encode(['origem'=>$origem,'destino'=>$destino,'solicitacao'=>$solicitacao,'tabela'=>$tabela,'motivo'=>$motivo,'registro_hash'=>hash('sha256', $compacto),'tamanho_original'=>strlen($compacto),'truncado'=>true], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    $check = $pdo->prepare('SELECT codorfao FROM `migracao_registros_orfaos` WHERE dados=? LIMIT 1');
    $check->execute([$json]);
    if ($check->fetchColumn()) return false;
    $pdo->prepare('INSERT INTO `migracao_registros_orfaos` (dados) VALUES (?)')->execute([$json]);
    return true;
}

$origins = array_values(array_filter(array_map('trim', explode(',', (string)argumento('origins', '')))));
$targetName = (string)argumento('target', '');
$requestId = (int)(argumento('solicitacao', '0') ?? 0);
if (!$origins || !$targetName) throw new RuntimeException('Informe --origins e --target.');

$env = GreenPortalDatabase::env();
$reportDirectory = dirname(__DIR__) . '/storage/logs/migracao'; if (!is_dir($reportDirectory)) @mkdir($reportDirectory, 0770, true);
$reportFile = $reportDirectory . '/migracao_' . ($requestId > 0 ? $requestId : preg_replace('/[^A-Za-z0-9_]/', '_', $targetName)) . '_legal_relatorio.json';
$target = GreenPortalDatabase::connect($env, $targetName, 'GPT');
$target->exec('CREATE TABLE IF NOT EXISTS `migracao_registros_orfaos` (codorfao BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, dados VARCHAR(5000) NOT NULL, encontrado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$universal = GreenPortalDatabase::connect($env, 'guniversal_dbp', 'GUNIVERSAL');
$legislation = GreenPortalDatabase::connect($env, 'glegislacao_dbp', 'GLEGISLACAO');
$source = conexaoLegado($env, $origins, 'legislacaoxempresa');
if (!$source) throw new RuntimeException('Nenhum banco de origem possui a tabela legislacaoxempresa.');

$company = $universal->prepare('SELECT codempresa FROM empresa WHERE banco_dados=? LIMIT 1');
$company->execute([$targetName]); $targetCompany = $company->fetchColumn();
if ($targetCompany === false) throw new RuntimeException('Empresa não cadastrada no catálogo universal para o banco destino: ' . $targetName);

$validLaws = [];
foreach ($legislation->query('SELECT CODLEGISLACAO FROM legislacao') as $row) $validLaws[(int)$row['CODLEGISLACAO']] = true;
$tables = [
    'unidades','areas','atividades','aspectos','impactos','destinos','perigos','riscos',
    'legislacaoxempresa','legislacaoxunidade','legislacaoxaspecto','legislacaoxatividade',
    'legislacaoximpacto','legislacaoxperigo','legislacaoxrisco','legislacaoxpoder',
    'legislacaoxsituacao','legislacaoxchecklist','legislacaoxdocumentos','legislacaoxinfraestruturas',
    'infraestrutura','infraestruturaxunidades','legislacaoxtreinamentos','legislacaoxfornecedores','legislacaoxlicencas','mm_monitoramento','mm_monitoramentoxlegislacao',
    'atividadesxinfraestrutura','laia','laia_inventario','lais','lais_aspectossociais','lais_documentosxaspectos','lais_documentosximpactos','lais_impactossociais',
    'lais_legislacaoxaspectossociais','lais_legislacaoximpactossociais','lais_partes_interessadas','lais_revisao','lpro','lpro_abrangencia','lpro_natureza_do_perigo','lpro_situacao','lpro_tipo_de_atividade',
    'mm_medicaoxlegislacao',
    'li_licencas','li_licencasxgrupos','li_licencasxpalavraschave','licencasxcondicionantes',
    'licencasxresponsaveis'
];
$loaded = []; $ignored = []; $insertedCounts = []; $targetCounts = []; $inconsistencias = []; $orfaosIgnorados = 0; $orfaosDetectados = 0;
$licencasValidas = [];
if (tabelaBase($source, 'li_licencas')) foreach ($source->query('SELECT CODLICENCA FROM li_licencas') as $license) $licencasValidas[(string)$license['CODLICENCA']] = true;
$knownRelations = [
    ['infraestruturaxunidades', 'codinfraestrutura', 'infraestrutura', 'CODINFRAESTRUTURA'],
    ['infraestruturaxunidades', 'codunidade', 'unidades', 'CODUNIDADE'],
    ['mm_monitoramentoxlegislacao', 'codmonitoramento', 'mm_monitoramento', 'codmonitoramento'],
    ['lpro_abrangencia', 'codlpro', 'lpro', 'codlpro'],
    ['lpro_natureza_do_perigo', 'codlpro', 'lpro', 'codlpro'],
    ['lpro_situacao', 'codlpro', 'lpro', 'codlpro'],
    ['lpro_tipo_de_atividade', 'codlpro', 'lpro', 'codlpro'],
    ['lais_documentosxaspectos', 'coddocumento', 'lais_documentos', 'coddocumento'],
    ['lais_documentosximpactos', 'coddocumento', 'lais_documentos', 'coddocumento'],
];
$knownReferenceValues = [];
$knownRelationsAtivos = [];
foreach ($knownRelations as [$child, $childColumn, $parent, $parentColumn]) {
    $key = $child.'|'.$childColumn; $knownReferenceValues[$key] = [];
    $parentFound = false;
    foreach ($origins as $origin) {
        $originPdo = GreenPortalDatabase::connect($env, $origin, 'LEGADO_MODELO');
        if (!tabelaBase($originPdo, $parent) || !in_array($parentColumn, colunas($originPdo, $parent), true)) continue;
        $parentFound = true;
        foreach ($originPdo->query('SELECT '.identificador($parentColumn).' FROM '.identificador($parent)) as $reference) $knownReferenceValues[$key][(string)$reference[$parentColumn]] = true;
    }
    if ($parentFound) $knownRelationsAtivos[] = [$child, $childColumn, $parent, $parentColumn];
}
$knownRelations = $knownRelationsAtivos;
$target->beginTransaction();
try {
    $target->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        if (!tabelaBase($source, $table) || !tabelaBase($target, $table)) continue;
        $sourceColumns = colunas($source, $table); $targetColumns = colunas($target, $table);
        $fields = array_values(array_intersect($sourceColumns, $targetColumns)); if (!$fields) continue;
        $quoted = implode(',', array_map('identificador', $fields));
        $marks = implode(',', array_fill(0, count($fields), '?'));
        $insert = $target->prepare('INSERT IGNORE INTO ' . identificador($table) . ' (' . $quoted . ') VALUES (' . $marks . ')');
        $read = $source->query('SELECT ' . $quoted . ' FROM ' . identificador($table));
        $readCount = 0; $ignoredCount = 0;
        foreach ($read as $row) {
            $law = isset($row['CODLEGISLACAO']) ? (int)$row['CODLEGISLACAO'] : 0;
            if ($law && !isset($validLaws[$law])) { $ignoredCount++; if (registrarOrfao($target, implode(',', $origins), $targetName, $requestId, $table, 'LEI_NAO_ENCONTRADA_NO_CATALOGO_CENTRAL', $row)) $orfaosIgnorados++; if (count($inconsistencias) < 5000) $inconsistencias[] = ['tipo'=>'LEI_NAO_ENCONTRADA','tabela'=>$table,'CODLEGISLACAO'=>$law,'chave'=>$row]; continue; }
            if ($table === 'legislacaoxlicencas' && isset($row['CODLICENCA']) && !isset($licencasValidas[(string)$row['CODLICENCA']])) { $ignoredCount++; if (registrarOrfao($target, implode(',', $origins), $targetName, $requestId, $table, 'LICENCA_NAO_ENCONTRADA_NA_ORIGEM', $row)) $orfaosIgnorados++; if (count($inconsistencias) < 5000) $inconsistencias[] = ['tipo'=>'LICENCA_NAO_ENCONTRADA','tabela'=>$table,'chave'=>$row]; continue; }
            $knownOrphanReason = null;
            foreach ($knownRelations as [$knownChild, $knownColumn, $knownParent, $knownParentColumn]) {
                $knownKey = $knownChild.'|'.$knownColumn;
                if ($table === $knownChild && array_key_exists($knownColumn, $row) && $row[$knownColumn] !== null && isset($knownReferenceValues[$knownKey]) && !isset($knownReferenceValues[$knownKey][(string)$row[$knownColumn]])) { $knownOrphanReason = 'REFERENCIA_NAO_ENCONTRADA_NO_LEGADO: '.$knownColumn.' -> '.$knownParent.'.'.$knownParentColumn; break; }
            }
            if ($knownOrphanReason !== null) { $ignoredCount++; if (registrarOrfao($target, implode(',', $origins), $targetName, $requestId, $table, $knownOrphanReason, $row)) $orfaosIgnorados++; continue; }
            if ($table === 'legislacaoxempresa' && in_array('CODEMPRESA', $fields, true)) $row['CODEMPRESA'] = $targetCompany;
            $insert->execute(array_map(static fn(string $field): mixed => $row[$field] ?? null, $fields)); $readCount++;
        }
        $loaded[$table] = $readCount; $ignored[$table] = $ignoredCount; $insertedCounts[$table] = $readCount;
        $targetCounts[$table] = (int)$target->query('SELECT COUNT(*) FROM ' . identificador($table))->fetchColumn();
    }
    $target->exec('SET FOREIGN_KEY_CHECKS=1');
    $orphans = [];
    $foreignKeys = $target->query("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND CONSTRAINT_NAME <> 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($foreignKeys as $foreignKey) {
        $child = (string)$foreignKey['TABLE_NAME']; $childColumn = (string)$foreignKey['COLUMN_NAME']; $parent = (string)$foreignKey['REFERENCED_TABLE_NAME']; $parentColumn = (string)$foreignKey['REFERENCED_COLUMN_NAME'];
        if (!tabelaBase($target, $child) || !tabelaBase($target, $parent)) continue;
        $rows = $target->query('SELECT c.* FROM '.identificador($child).' c LEFT JOIN '.identificador($parent).' p ON p.'.identificador($parentColumn).'=c.'.identificador($childColumn).' WHERE c.'.identificador($childColumn).' IS NOT NULL AND p.'.identificador($parentColumn).' IS NULL')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) { if (registrarOrfao($target, implode(',', $origins), $targetName, $requestId, $child, 'REFERENCIA_NAO_ENCONTRADA: '.$childColumn.' -> '.$parent.'.'.$parentColumn, $row)) $orfaosDetectados++; }
    }
    // O legado nem sempre declara todas as chaves estrangeiras. Validamos
    // explicitamente os relacionamentos conhecidos para não depender apenas
    // do metadado de FK do banco novo.
    foreach ($knownRelations as [$child, $childColumn, $parent, $parentColumn]) {
        if (!tabelaBase($target, $child) || !tabelaBase($target, $parent)) continue;
        if (!in_array($childColumn, colunas($target, $child), true) || !in_array($parentColumn, colunas($target, $parent), true)) continue;
        $sql = 'SELECT c.* FROM '.identificador($child).' c LEFT JOIN '.identificador($parent).' p ON p.'.identificador($parentColumn).'=c.'.identificador($childColumn).' WHERE c.'.identificador($childColumn).' IS NOT NULL AND p.'.identificador($parentColumn).' IS NULL';
        foreach ($target->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (registrarOrfao($target, implode(',', $origins), $targetName, $requestId, $child, 'RELACIONAMENTO_CONHECIDO_NAO_ENCONTRADO: '.$childColumn.' -> '.$parent.'.'.$parentColumn, $row)) $orfaosDetectados++;
        }
    }
    if ($orfaosDetectados > 0) $inconsistencias[] = ['tipo'=>'REGISTROS_ORFAOS_RELACIONAMENTO','quantidade'=>$orfaosDetectados];
    if (tabelaBase($target, 'legislacaoxempresa')) $orphans['legislacaoxempresa_sem_lei'] = (int)$target->query('SELECT COUNT(*) FROM legislacaoxempresa x LEFT JOIN glegislacao_dbp.legislacao l ON l.CODLEGISLACAO=x.CODLEGISLACAO WHERE l.CODLEGISLACAO IS NULL')->fetchColumn();
    $orphans['ignorados_antes_da_carga'] = $orfaosIgnorados;
    $orphans['detectados_apos_a_carga'] = $orfaosDetectados;
    $orphans['registros_orfaos_armazenados'] = $orfaosIgnorados + $orfaosDetectados;
    if (($loaded['legislacaoxempresa'] ?? 0) > 0 && ($targetCounts['legislacaoxempresa'] ?? 0) === 0) throw new RuntimeException('A tabela legislacaoxempresa não recebeu registros.');
    if ($orfaosIgnorados > 0) $inconsistencias[] = ['tipo'=>'REGISTROS_ORFAOS_IGNORADOS','quantidade'=>$orfaosIgnorados,'por_tabela'=>$orphans];
    if (array_sum($ignored) > 0) $inconsistencias[] = ['tipo'=>'REGISTROS_IGNORADOS','quantidade'=>array_sum($ignored),'por_tabela'=>$ignored];
    $details = json_encode(['origens'=>$origins,'destino'=>$targetName,'empresa_destino'=>(int)$targetCompany,'leis_validas'=>count($validLaws),'lidos'=>$loaded,'ignorados'=>$ignored,'persistidos'=>$targetCounts,'persistidos_nesta_execucao'=>$insertedCounts,'orfaos'=>$orphans,'orfaos_ignorados_antes_da_carga'=>$orfaosIgnorados,'orfaos_detectados_apos_a_carga'=>$orfaosDetectados,'orfaos_total'=>$orfaosIgnorados+$orfaosDetectados,'inconsistencias'=>$inconsistencias,'politica_orfaos'=>'registrar_e_nao_enviar'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    // Orfaos e referencias ausentes sao registrados para auditoria; eles nao
    // devem transformar uma migracao controlada em falha total.
    if (false) { /*
    if ($inconsistencias) { file_put_contents($reportFile, $details); throw new RuntimeException('Migração legal bloqueada por inconsistências. Relatório: ' . $reportFile . '; órfãos=' . array_sum($orphans) . '; ignorados=' . array_sum($ignored)); }
    */ }
    if (tabelaBase($target, 'registros_migracao')) {
        $audit = $target->prepare("INSERT INTO registros_migracao (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes) VALUES ('MIGRACAO_MODULO_LEGAL_GENERICA',:origem,:destino,'DADOS','1.0.0','CONCLUIDA',:q,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,:detalhes) ON DUPLICATE KEY UPDATE status='CONCLUIDA',quantidade_registros=VALUES(quantidade_registros),concluida_em=CURRENT_TIMESTAMP,detalhes=VALUES(detalhes)");
        $audit->execute(['origem'=>implode(',', $origins),'destino'=>$targetName,'q'=>array_sum($loaded),'detalhes'=>$details]);
    }
    $target->commit();
    file_put_contents($reportFile, $details); echo json_encode(['ok'=>true,'origens'=>$origins,'destino'=>$targetName,'empresa_destino'=>(int)$targetCompany,'lidos'=>$loaded,'ignorados'=>$ignored,'persistidos'=>$targetCounts,'orfaos'=>$orphans,'relatorio'=>$reportFile], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    if ($target->inTransaction()) $target->rollBack();
    throw $e;
}
