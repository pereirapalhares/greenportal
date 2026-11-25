<?php
declare(strict_types=1);

/*
 * Migração 408 — Onda 3 da Revisão G (24/11/2026), 1ª parte: campo Tipo em Controle (CON-89), Treinamento (TRE-104) e
 * Infraestrutura (INF-119), base da regra Tipo × Responsável desses módulos (CON-85/87, TRE-101/103, INF-116/118, na 361).
 *
 * Universal: menus "Tipos de controle", "Tipos de treinamento" e "Tipos de infraestrutura" logo depois da tela de cada módulo
 * (ML_TIPOS_CONTROLE, ML_TIPOS_TREINAMENTO, ML_TIPOS_INFRAESTRUTURA), replicados nas empresas.
 * Cada empresa:
 *   1. catálogos ml_tipos_controle / ml_tipos_treinamento / ml_tipos_infraestrutura (código, descrição, ativo);
 *   2. colunas documentos.CODTIPOCONTROLE, ml_treinamentos.CODTIPOTREINAMENTO, ml_infraestrutura.CODTIPOINFRAESTRUTURA (com índice);
 *   3. views das telas (vw_controle_documentos_grid, vw_meus_controles_grid, vw_modulolegal_treinamentos_grid,
 *      vw_minhas_treinamentos_grid, vw_modulolegal_infraestrutura_grid, vw_minhas_infraestrutura_grid) passam a ter o código e a
 *      descrição do tipo: a definição anterior vira <view>_base408 (e vai para storage/logs) e a view nova é base + tipo;
 *   4. permissões dos menus novos = as da tela do módulo, em cada perfil.
 * Depois rodar a 361 de novo (scripts\aplicar-migracao-407-responsaveis-legal.php) para as regras de Responsáveis e avisos
 * desses módulos passarem a ter o filtro por Tipo e para refazer as views das telas "Minhas".
 * Pode ser executada mais de uma vez. Depois: sair e entrar no portal (o menu fica na sessão).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-408-tipos-pilares.php [--simular] [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';
require_once dirname(__DIR__) . '/classes/ResponsaveisRegras.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$rel = ['ok' => true, 'migracao' => '408', 'modo' => 'APLICAR' . ($simular ? ' (SIMULACAO)' : ''), 'universal' => [], 'bancos' => []];

// módulo => [tabela, chave, coluna do tipo, catálogo, descrição do tipo, views das telas, menu do módulo, menu novo, url, nomes]
$MOD = [
    'CONTROLES' => ['documentos', 'CODDOCUMENTO', 'CODTIPOCONTROLE', 'ml_tipos_controle', 'DSCTIPOCONTROLE', ['vw_controle_documentos_grid', 'vw_meus_controles_grid'],
        'CONTROLE_DOCUMENTOS', 'ML_TIPOS_CONTROLE', '/principal/ml_tipos_controle.php', ['pt' => 'Tipos de controle', 'en' => 'Control types', 'es' => 'Tipos de control']],
    'TREINAMENTOS' => ['ml_treinamentos', 'CODTREINAMENTO', 'CODTIPOTREINAMENTO', 'ml_tipos_treinamento', 'DSCTIPOTREINAMENTO', ['vw_modulolegal_treinamentos_grid', 'vw_minhas_treinamentos_grid'],
        'MODULOLEGAL_TREINAMENTOS', 'ML_TIPOS_TREINAMENTO', '/principal/ml_tipos_treinamento.php', ['pt' => 'Tipos de treinamento', 'en' => 'Training types', 'es' => 'Tipos de capacitación']],
    'INFRAESTRUTURA' => ['ml_infraestrutura', 'CODINFRAESTRUTURA', 'CODTIPOINFRAESTRUTURA', 'ml_tipos_infraestrutura', 'DSCTIPOINFRAESTRUTURA', ['vw_modulolegal_infraestrutura_grid', 'vw_minhas_infraestrutura_grid'],
        'MODULOLEGAL_INFRAESTRUTURA', 'ML_TIPOS_INFRAESTRUTURA', '/principal/ml_tipos_infraestrutura.php', ['pt' => 'Tipos de infraestrutura', 'en' => 'Infrastructure types', 'es' => 'Tipos de infraestructura']],
];
$menuExato = static function (PDO $p, string $codigo): ?array {
    foreach (MigracaoCrud::selecionar($p, 'menus', ['codigo' => $codigo]) as $m) if (strcasecmp((string)(array_change_key_case($m)['codigo'] ?? ''), $codigo) === 0) return $m;
    return null;
};

// ---------------------------------------------------------------- 1) universal: menus dos catálogos
$cat = new MenuCatalogoService($env);
try {
    $u = $cat->universal();
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach ($MOD as $area => [, , , , , , $menuMod, $menuNovo, $url, $nome]) {
        $existe = $menuExato($u, $menuNovo);
        $mod = $menuExato($u, $menuMod);
        if (!$mod) { $rel['universal']['menus'][$area] = 'ignorado: menu ' . $menuMod . ' não existe no catálogo'; continue; }
        $mod = array_change_key_case($mod);
        $dados = ['codmenu_pai' => $mod['codmenu_pai'] !== null ? (int)$mod['codmenu_pai'] : null, 'modulo' => $mod['modulo'] ?? null, 'codigo' => $menuNovo,
            'label' => $nome['pt'], 'label_pt' => $nome['pt'], 'label_en' => $nome['en'], 'label_es' => $nome['es'], 'url' => $url,
            'nivel' => (int)($mod['nivel'] ?? 1), 'icone' => 'bi-bookmarks', 'ativo' => 1];
        if (!$existe) $dados['ordem'] = (int)($mod['ordem'] ?? 0) + 2;
        if ($simular) { $rel['universal']['menus'][$area] = $existe ? 'já existe (codmenu ' . $existe['codmenu'] . ')' : 'será criado ao lado de ' . $menuMod; continue; }
        $g = $cat->gravar($dados, $existe ? (int)$existe['codmenu'] : null);
        $rel['universal']['menus'][$area] = ($existe ? 'atualizado' : 'criado') . ' (codmenu ' . $g['codmenu'] . ')';
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['erro'] = $e->getMessage(); }

// ---------------------------------------------------------------- 2) empresas
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'usuarios') AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 2 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && $b !== 'glegislacao_dbp' && ($soBanco === null || $b === $soBanco)));

foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4');
        ResponsaveisRegras::limparCache();
        foreach ($MOD as $area => [$tab, $pk, $col, $catalogo, $dsc, $views, $menuMod, $menuNovo]) {
            $r = [];
            $c = ResponsaveisRegras::colunas($pdo, $tab);
            if (!isset($c[strtolower($pk)])) { $info[$area] = 'ignorado: sem a tabela ' . $tab; continue; }
            // 1. catálogo
            if (!ResponsaveisRegras::existe($pdo, $catalogo)) {
                $r['catalogo'] = $simular ? 'será criado' : 'criado';
                if (!$simular) $pdo->exec("CREATE TABLE IF NOT EXISTS `$catalogo` (`$col` INT NOT NULL AUTO_INCREMENT, `$dsc` VARCHAR(200) NOT NULL,
                    `ATIVO` TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (`$col`), UNIQUE KEY `uk_{$catalogo}_dsc` (`$dsc`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } else $r['catalogo'] = 'já existe (' . (int)$pdo->query("SELECT COUNT(*) FROM `$catalogo`")->fetchColumn() . ' tipo(s))';
            // 2. coluna no cadastro
            if (!isset($c[strtolower($col)])) {
                $r['coluna'] = $simular ? "$tab.$col será criada" : "$tab.$col criada";
                if (!$simular) { $pdo->exec("ALTER TABLE `$tab` ADD COLUMN `$col` INT NULL, ADD KEY `ix_{$tab}_$col` (`$col`)"); }
            } else $r['coluna'] = "$tab.$col já existe";
            ResponsaveisRegras::limparCache();
            // 3. views das telas: <view>_base408 (definição anterior) + tipo
            foreach ($views as $v) {
                $base = $v . '_base408';
                if (!ResponsaveisRegras::existe($pdo, $v)) { $r['views'][$v] = 'não existe nesta empresa'; continue; }
                $temBase = ResponsaveisRegras::existe($pdo, $base);
                $corpo = ResponsaveisRegras::corpoView($pdo, $v);
                if (!$temBase) {
                    $vc = ResponsaveisRegras::colunas($pdo, $v);
                    if (isset($vc[strtolower($col)])) { $r['views'][$v] = 'já tem o tipo'; continue; }
                    if (!isset($vc[strtolower($pk)])) { $r['views'][$v] = 'ignorada: sem a coluna ' . $pk; continue; }
                    if ($corpo === null) { $r['views'][$v] = 'ignorada: definição em formato inesperado'; continue; }
                }
                if ($simular) { $r['views'][$v] = $temBase ? 'será refeita sobre ' . $base : 'passará a ter o tipo (anterior em ' . $base . ')'; continue; }
                if (!$temBase) {
                    @file_put_contents($logDir . DIRECTORY_SEPARATOR . "migracao_408_{$v}_anterior_$banco.sql", "CREATE OR REPLACE VIEW `$v` AS $corpo;\n");
                    $pdo->exec("CREATE OR REPLACE VIEW `$base` AS $corpo");
                    ResponsaveisRegras::limparCache();
                }
                $bc = ResponsaveisRegras::colunas($pdo, $base);
                $pdo->exec("CREATE OR REPLACE VIEW `$v` AS SELECT b.*, x.`$col` AS `$col`, t.`$dsc` AS `$dsc`
  FROM `$base` b LEFT JOIN `$tab` x ON x.`$pk` = b.`" . $bc[strtolower($pk)] . "` LEFT JOIN `$catalogo` t ON t.`$col` = x.`$col`");
                $t0 = microtime(true); $n = (int)$pdo->query("SELECT COUNT(*) FROM `$v`")->fetchColumn();
                $r['views'][$v] = sprintf('ok (%d linha(s), %.2fs)', $n, microtime(true) - $t0);
            }
            // 4. permissões do menu novo = as da tela do módulo
            $novo = $menuExato($pdo, $menuNovo); $orig = $menuExato($pdo, $menuMod);
            if (!$novo || !$orig) $r['permissoes'] = !$novo ? ($simular ? 'o menu será replicado ao aplicar' : 'menu ainda não replicado neste banco') : 'sem o menu ' . $menuMod;
            else {
                $cont = 0;
                foreach (['permissoes', 'perfil_menus'] as $pt) {
                    if (!MigracaoCrud::existe($pdo, $pt)) continue;
                    $cols = MigracaoCrud::colunas($pdo, $pt);
                    if (!in_array('codmenu', $cols, true) || !in_array('codperfil', $cols, true)) continue;
                    foreach (MigracaoCrud::selecionar($pdo, $pt, ['codmenu' => (int)$orig['codmenu']]) as $p) {
                        $p = array_change_key_case($p);
                        if ((int)($p['codmenu'] ?? 0) !== (int)$orig['codmenu']) continue;
                        $flags = array_filter($p, static fn($k) => str_starts_with((string)$k, 'pode_'), ARRAY_FILTER_USE_KEY);
                        if (!array_filter($flags)) continue;
                        if (MigracaoCrud::primeiro($pdo, $pt, ['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$novo['codmenu']])) continue;
                        if (!$simular) MigracaoCrud::inserir($pdo, $pt, ['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$novo['codmenu']] + $flags);
                        $cont++;
                    }
                }
                $r['permissoes'] = $cont . ' permissão(ões) ' . ($simular ? 'a criar' : 'criada(s)') . ' (as mesmas de ' . $menuMod . ')';
            }
            $info[$area] = $r;
        }
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}
$rel['proximo_passo'] = 'rodar scripts\\aplicar-migracao-407-responsaveis-legal.php (refaz as views da 361 com o filtro por Tipo)';
$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents($logDir . DIRECTORY_SEPARATOR . 'migracao_408_tipos_pilares' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
