<?php
declare(strict_types=1);

/** Validação final somente leitura da Crown: GED, órfãos e permissões. */
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$banco = 'gcrown_dbp';
$legado = HomologacaoBancos::legado($banco) ?: 'green_crown';
$env = GreenPortalDatabase::env();
$target = GreenPortalDatabase::connect($env, $banco, 'GPT');
$universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$scalar = static function (PDO $pdo, string $sql, array $params = []): int {
    $s = $pdo->prepare($sql); $s->execute($params); $value = $s->fetchColumn(); $s->closeCursor(); return (int)$value;
};
$table = static function (PDO $pdo, string $name) use ($scalar): bool {
    return $scalar($pdo, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$name]) > 0;
};
$gedRoot = rtrim((string)($env['GGED_LEGACY_ROOT'] ?? 'E:\\projetoGreen\\GED'), '\\/') . DIRECTORY_SEPARATOR . $legado;
$fileCount = 0; $folderCount = 0;
if (is_dir($gedRoot)) {
    // Validação rápida: não varre milhões de arquivos do GED; confirma a raiz e os primeiros níveis.
    foreach (scandir($gedRoot) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $gedRoot . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) $folderCount++;
        elseif (is_file($path)) $fileCount++;
    }
}
$orfaos = [];
if ($table($target, 'li_condicionantes') && $table($target, 'li_licencas')) {
    $orfaos['condicionantes_historicos_sem_licenca'] = $scalar($target, 'SELECT COUNT(*) FROM li_condicionantes c WHERE NOT EXISTS (SELECT 1 FROM li_licencas l WHERE l.CODLICENCA = c.CODLICENCA)');
    $orfaos['condicionantes_sem_licenca'] = $table($target, 'migracao_orfaos_decisoes') ? $scalar($target, "SELECT COUNT(*) FROM li_condicionantes c LEFT JOIN li_licencas l ON l.CODLICENCA=c.CODLICENCA LEFT JOIN migracao_orfaos_decisoes d ON d.tipo='CONDICIONANTE_SEM_LICENCA' AND d.registro_id=c.CODCONDICIONANTE WHERE l.CODLICENCA IS NULL AND d.coddecisao IS NULL") : $orfaos['condicionantes_historicos_sem_licenca'];
}
if ($table($target, 'li_planodeacao') && $table($target, 'li_condicionantes')) $orfaos['planos_sem_condicionante'] = $scalar($target, 'SELECT COUNT(*) FROM li_planodeacao p WHERE NOT EXISTS (SELECT 1 FROM li_condicionantes c WHERE c.CODCONDICIONANTE = p.CODCONDICIONANTE)');
if ($table($target, 'li_condicionantes') && $table($target, 'usuarios')) {
    $orfaos['responsaveis_historicos_inexistentes'] = $scalar($target, 'SELECT COUNT(*) FROM li_condicionantes c WHERE COALESCE(c.CODUSUARIO, 0) > 0 AND NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.codusuario = c.CODUSUARIO)');
    $orfaos['responsaveis_inexistentes'] = $orfaos['responsaveis_historicos_inexistentes'];
}
$orfaos['registros_orfaos_auditados'] = $table($target, 'migracao_registros_orfaos') ? $scalar($target, 'SELECT COUNT(*) FROM migracao_registros_orfaos') : 0;
$orfaos['registros_orfaos_duplicados'] = $table($target, 'migracao_registros_orfaos') ? max(0, $orfaos['registros_orfaos_auditados'] - $scalar($target, 'SELECT COUNT(DISTINCT dados) FROM migracao_registros_orfaos')) : 0;
$orfaos['decisoes_administrativas'] = $table($target, 'migracao_orfaos_decisoes') ? $scalar($target, 'SELECT COUNT(*) FROM migracao_orfaos_decisoes') : 0;
$univMenus = [];
$menusCentralReservados = ['ADMINISTRACAO_MIGRACAO_MENUS'];
if ($table($universal, 'menus')) foreach ($universal->query('SELECT codigo, codmenu FROM menus') as $row) $univMenus[strtoupper((string)$row['codigo'])] = (int)$row['codmenu'];
$menuDiff = 0; $menuMissing = 0; $missingCodes = []; $centralMissing = [];
if ($table($target, 'menus')) foreach ($target->query('SELECT codigo, codmenu FROM menus') as $row) {
    $code = strtoupper((string)$row['codigo']);
    if (!isset($univMenus[$code]) || $univMenus[$code] !== (int)$row['codmenu']) $menuDiff++;
}
foreach ($univMenus as $code => $_) {
    $s = $target->prepare('SELECT COUNT(*) FROM menus WHERE UPPER(codigo) = ?'); $s->execute([$code]);
    if ((int)$s->fetchColumn() === 0) {
        if (in_array($code, $menusCentralReservados, true)) $centralMissing[] = $code;
        else { $menuMissing++; $missingCodes[] = $code; }
    }
    $s->closeCursor();
}
$result = [
    'ok' => true,
    'banco' => $banco,
    'legado' => $legado,
    'ged' => ['path' => $gedRoot, 'existe' => is_dir($gedRoot), 'arquivos' => $fileCount, 'pastas' => $folderCount, 'politica' => 'somente link; nenhum arquivo movido'],
    'orfaos' => $orfaos,
    'permissoes' => ['menus_divergentes_do_universal' => $menuDiff, 'menus_universais_ausentes' => $menuMissing, 'codigos_ausentes' => $missingCodes, 'menus_centrais_reservados_ausentes' => $centralMissing],
    'gerado_em' => date(DATE_ATOM),
];
$result['ok'] = $result['ged']['existe'] && $menuDiff === 0 && $menuMissing === 0;
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
file_put_contents($dir . DIRECTORY_SEPARATOR . 'homologacao-final-crown.json', $json . PHP_EOL, LOCK_EX);
echo $json . PHP_EOL;
exit($result['ok'] ? 0 : 1);
