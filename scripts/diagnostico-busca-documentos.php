<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) para a Pesquisa de Documentos (busca universal), Fase 1, em gatlas_dbp e gmodelo_dbp.
 * Para cada módulo: view do grid (existe? colunas e tipos, quantas linhas, exemplo de linha) e pastas do GED
 * (nas duas raízes: GGED_STORAGE_LOCAL_ROOT e GGED_LEGACY_ROOT), com quantas pastas/arquivos e extensões.
 * Também: suporte a FULLTEXT (ngram), tabelas de serviço (green_service_runs) e catálogo de serviços (guniversal_dbp.servicos).
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-busca-documentos.php
 * Saída: tela (resumo) + storage/logs/diagnostico_busca_documentos.json (completo)
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$modulos = [
    'licencas' => ['views' => ['vw_licencas_grid'], 'pasta' => 'licencas'],
    'controles' => ['views' => ['vw_controle_documentos_grid'], 'pasta' => 'documentos'],
    'requisitos' => ['views' => ['vw_modulolegal_requisitos_grid'], 'pasta' => null],
    'treinamentos' => ['views' => ['vw_modulolegal_treinamentos_grid'], 'pasta' => 'treinamentos'],
    'infraestrutura' => ['views' => ['vw_modulolegal_infraestrutura_grid'], 'pasta' => 'infraestrutura'],
    'fornecedorcriterios' => ['views' => ['vw_portaria_documentacao_fornecedor_grid'], 'pasta' => 'fo_fornecedorxcriterios'],
    'funcionariocriterios' => ['views' => ['vw_portaria_documentacao_funcionario_grid'], 'pasta' => 'fo_funcionarioxtiposxcriterios'],
    'funcionariotreinamentos' => ['views' => ['vw_portaria_documentacao_treinamento_grid'], 'pasta' => 'fo_funcionarioxtiposxtreinamentos'],
];
$bases = ['gatlas_dbp' => 'green_atlas', 'gmodelo_dbp' => 'green_modelo'];
$raizes = ['local' => rtrim((string)($env['GGED_STORAGE_LOCAL_ROOT'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage'), '\\/'), 'legado' => rtrim((string)($env['GGED_LEGACY_ROOT'] ?? 'E:\\projetoGreen\\GED'), '\\/')];
$saida = ['diagnostico' => 'busca de documentos', 'data' => date('c'), 'raizes_ged' => $raizes];
$curto = static fn($v) => is_string($v) && mb_strlen($v) > 80 ? mb_substr($v, 0, 80) . '…' : $v;

foreach ($bases as $banco => $legado) {
    $r = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $v = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $v; };
        foreach ($modulos as $tipo => $m) {
            $info = [];
            foreach ($m['views'] as $v) {
                $cols = $q('SELECT COLUMN_NAME c, DATA_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$v]);
                if (!$cols) { $info[$v] = 'NÃO EXISTE'; continue; }
                $t0 = microtime(true);
                $n = (int)$q("SELECT COUNT(*) n FROM `$v`")[0]['n'];
                $ex = $q("SELECT * FROM `$v` LIMIT 1")[0] ?? [];
                $info[$v] = ['linhas' => $n, 'segundos_contagem' => round(microtime(true) - $t0, 2), 'colunas' => implode(', ', array_map(static fn($c) => $c['c'] . ':' . $c['t'], $cols)), 'exemplo' => array_map($curto, $ex)];
            }
            if ($m['pasta']) {
                foreach ($raizes as $nomeRaiz => $raiz) {
                    $dir = $raiz . DIRECTORY_SEPARATOR . $legado . DIRECTORY_SEPARATOR . $m['pasta'];
                    if (!is_dir($dir)) { $info['ged_' . $nomeRaiz] = 'não existe'; continue; }
                    $sub = glob($dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
                    $arq = 0; $ext = []; $bytes = 0;
                    foreach ($sub as $s) foreach (glob($s . DIRECTORY_SEPARATOR . '*') ?: [] as $f) { if (!is_file($f)) continue; $arq++; $bytes += (int)@filesize($f); $e = strtolower(pathinfo($f, PATHINFO_EXTENSION)) ?: '(sem)'; $ext[$e] = ($ext[$e] ?? 0) + 1; }
                    arsort($ext);
                    $info['ged_' . $nomeRaiz] = count($sub) . ' pastas, ' . $arq . ' arquivos, ' . round($bytes / 1048576) . ' MB; extensões: ' . implode(', ', array_map(static fn($k, $v) => "$k=$v", array_keys(array_slice($ext, 0, 10, true)), array_slice($ext, 0, 10, true)));
                }
            }
            $r['modulos'][$tipo] = $info;
        }
        $r['licencas_versoes'] = $q("SELECT COUNT(*) total, SUM(CODLICENCA_BASE IS NOT NULL AND CODLICENCA_BASE <> CODLICENCA) versoes_antigas_ou_novas FROM li_licencas")[0] ?? null;
        $r['documentosxunidades'] = (int)($q("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documentosxunidades'")[0]['n'] ?? 0) ? (int)$q('SELECT COUNT(*) n FROM documentosxunidades')[0]['n'] : 'não existe';
        $r['green_service_runs'] = (int)$q("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'green_service_runs'")[0]['n'] ? 'existe' : 'não existe';
        $r['vw_portaria_usuario_unidades'] = (int)$q("SELECT COUNT(*) n FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vw_portaria_usuario_unidades'")[0]['n'] ? 'existe' : 'não existe';
        $r['mysql'] = $q("SELECT VERSION() versao, @@innodb_ft_min_token_size min_token, @@ngram_token_size ngram")[0] ?? null;
    } catch (Throwable $e) { $r['erro'] = $e->getMessage(); }
    $saida['bancos'][$banco] = $r;
}
try {
    $u = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $saida['universal']['servicos'] = $u->query('SELECT * FROM servicos ORDER BY codservico LIMIT 40')->fetchAll(PDO::FETCH_ASSOC);
    $saida['universal']['colunas_servicos'] = implode(', ', $u->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'servicos' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN));
    $saida['universal']['menu_busca'] = $u->query("SELECT codmenu, codigo, url, codmenu_pai FROM menus WHERE codigo LIKE '%BUSCA%' OR codigo LIKE '%PESQUISA%'")->fetchAll(PDO::FETCH_ASSOC);
    $saida['universal']['max_codmenu'] = (int)$u->query('SELECT MAX(codmenu) FROM menus')->fetchColumn();
} catch (Throwable $e) { $saida['universal']['erro'] = $e->getMessage(); }

$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
@file_put_contents(dirname(__DIR__) . '/storage/logs/diagnostico_busca_documentos.json', $json);
// Na tela, sem as colunas e exemplos (estão no arquivo)
$tela = $saida;
foreach ($tela['bancos'] ?? [] as $b => $info) foreach ($info['modulos'] ?? [] as $t => $mod) foreach ($mod as $k => $v) if (is_array($v)) $tela['bancos'][$b]['modulos'][$t][$k] = ['linhas' => $v['linhas'], 'segundos' => $v['segundos_contagem']];
echo json_encode($tela, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
echo "Detalhes (colunas e exemplos): storage\\logs\\diagnostico_busca_documentos.json" . PHP_EOL;
