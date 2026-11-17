<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$target = GreenPortalDatabase::connect($env, 'ggdamericana_dbp', 'GPT');
$base = 'E:' . DIRECTORY_SEPARATOR . 'projetoGreen' . DIRECTORY_SEPARATOR . 'GED' . DIRECTORY_SEPARATOR . 'green_gd_americana' . DIRECTORY_SEPARATOR . 'licencas';

$licenseIds = array_map('intval', $target->query('SELECT CODLICENCA FROM li_licencas ORDER BY CODLICENCA')->fetchAll(PDO::FETCH_COLUMN));
$withFiles = 0;
$fileCount = 0;
$withoutFolder = [];
$withoutFiles = [];

foreach ($licenseIds as $id) {
    $folder = $base . DIRECTORY_SEPARATOR . $id;
    if (!is_dir($folder)) {
        $withoutFolder[] = $id;
        continue;
    }
    $files = [];
    foreach (scandir($folder) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $folder . DIRECTORY_SEPARATOR . $name;
        if (is_file($path)) {
            $files[] = $name;
        }
    }
    if (!$files) {
        $withoutFiles[] = $id;
        continue;
    }
    $withFiles++;
    $fileCount += count($files);
}

$details = json_encode([
    'empresa' => 'Goodyear Americana',
    'raiz_ged' => 'E:\\projetoGreen\\GED',
    'identificador_legado' => 'green_gd_americana',
    'padrao' => 'green_gd_americana\\licencas\\<CODLICENCA>\\<arquivo>',
    'origem' => 'arquivos fisicos ja existentes no GED legado',
    'acao' => 'nenhum arquivo movido ou copiado; acesso por link resolvido pelo GedStorageService',
    'licencas_avaliadas' => count($licenseIds),
    'licencas_com_arquivos' => $withFiles,
    'arquivos_localizados' => $fileCount,
    'licencas_sem_pasta' => count($withoutFolder),
    'licencas_com_pasta_sem_arquivo' => count($withoutFiles),
    'licencas_sem_pasta_codigos' => $withoutFolder,
    'licencas_com_pasta_sem_arquivo_codigos' => $withoutFiles,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$audit = $target->prepare("INSERT INTO registros_migracao (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes) VALUES ('GREEN_GOODYEAR_GED_270','E:\\projetoGreen\\GED\\green_gd_americana\\licencas','ggdamericana_dbp;GedStorageService','GED_LINK','1.0.0','CONCLUIDA',:q,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,:details) ON DUPLICATE KEY UPDATE status='CONCLUIDA',quantidade_registros=VALUES(quantidade_registros),concluida_em=CURRENT_TIMESTAMP,detalhes=VALUES(detalhes)");
$audit->execute(['q' => $fileCount, 'details' => $details]);

echo json_encode([
    'ok' => true,
    'migracao' => 270,
    'licencas_avaliadas' => count($licenseIds),
    'licencas_com_arquivos' => $withFiles,
    'arquivos_localizados' => $fileCount,
    'licencas_sem_pasta' => count($withoutFolder),
    'status' => 'CONCLUIDA',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
