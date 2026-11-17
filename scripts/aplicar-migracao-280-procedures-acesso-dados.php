<?php
declare(strict_types=1);

/*
 * Migração 280 — views e procedures para as consultas que ainda eram feitas direto nas tabelas.
 *
 * Universal (guniversal_dbp):
 *   - vw_menus_arvore (codmenu, codmenu_pai, codigo): árvore de menus usada por ModuloEmpresaService (módulo do menu);
 *   - vw_empresa_modulos_situacao (codempresa, codigo_modulo, ativo): módulos contratados por empresa (migração 265).
 * Em cada empresa (bancos g*_dbp com menus, perfis, permissoes e usuarios; os *_seguranca_dbp ficam de fora):
 *   database/280_procedures_acesso_dados.sql —
 *     sp_ged_licenca_requisito_contar, sp_ged_licenca_versoes, sp_ged_analises_ia_listar,
 *     sp_ged_analises_ia_situacao (principal/api/gestao_arquivos.php) e sp_usuario_perfis_legado (classes/UsuarioSessao.php);
 *   e as procedures sp_crud_* que faltarem (classes/MigracaoCrud.php).
 *
 * Rodar ANTES de publicar o código que passa a usar essas views e procedures. Empresa nova: rodar de novo.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-280-procedures-acesso-dados.php [--simular]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

const M280_PROCEDURES = ['sp_ged_licenca_requisito_contar', 'sp_ged_licenca_versoes', 'sp_ged_analises_ia_listar',
    'sp_ged_analises_ia_situacao', 'sp_usuario_perfis_legado'];

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$relatorio = ['ok' => true, 'migracao' => '280', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal' => [], 'bancos' => []];

// ---------------------------------------------------------------- Universal
try {
    $u = GreenPortalDatabase::connect($env, $bancoUniversal, 'GUNIVERSAL');
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (!MigracaoCrud::existe($u, 'menus')) throw new RuntimeException('tabela menus não existe no universal');
    $temModulos = MigracaoCrud::existe($u, 'empresa_modulos');
    if (!$simular) {
        $u->exec('CREATE OR REPLACE VIEW `vw_menus_arvore` AS SELECT `codmenu`, `codmenu_pai`, `codigo` FROM `menus`');
        if ($temModulos) $u->exec('CREATE OR REPLACE VIEW `vw_empresa_modulos_situacao` AS SELECT `codempresa`, `codigo_modulo`, `ativo` FROM `empresa_modulos`');
    }
    $relatorio['universal']['views'] = $temModulos ? ['vw_menus_arvore', 'vw_empresa_modulos_situacao'] : ['vw_menus_arvore', '(sem empresa_modulos: rode antes a migração 265)'];
    if (!$temModulos) $relatorio['ok'] = false;
} catch (Throwable $e) { $relatorio['ok'] = false; $relatorio['universal']['erro'] = $e->getMessage(); }

// ---------------------------------------------------------------- Empresas
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes', 'usuarios') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp') && preg_match('/^g[a-z0-9_]+_dbp$/', (string)$b)));
$sql = (string)file_get_contents(dirname(__DIR__) . '/database/280_procedures_acesso_dados.sql');
foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($simular) $info['simulacao'] = 'procedures serão gravadas';
        else {
            if ($criadas = MigracaoCrud::garantir($pdo)) $info['procedures_crud_criadas'] = $criadas;
            foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
                $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
                if ($cmd !== '') $pdo->exec($cmd);
            }
            $s = $pdo->query("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = 'PROCEDURE'");
            $tem = $s->fetchAll(PDO::FETCH_COLUMN);
            $s->closeCursor();
            $faltam = array_values(array_diff(M280_PROCEDURES, $tem));
            $info['procedures'] = $faltam ? ['faltam' => $faltam] : 'gravadas (' . count(M280_PROCEDURES) . ')';
            if ($faltam) $relatorio['ok'] = false;
            // Tabelas que só existem em alguns bancos: a procedure fica pronta, a tela trata a ausência
            $info['tabelas_ausentes'] = array_values(array_filter(['logs_auditoria', 'legislacaoxlicencas', 'li_licencas', 'ged_analises_ia', 'atlas_usuarios_perfis_legado'],
                static fn($t) => !MigracaoCrud::existe($pdo, $t))) ?: 'nenhuma';
        }
    } catch (Throwable $e) { $relatorio['ok'] = false; $info['erro'] = $e->getMessage(); }
    $relatorio['bancos'][$banco] = $info;
}
echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
