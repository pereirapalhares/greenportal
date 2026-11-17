<?php
declare(strict_types=1);

/*
 * Migração 281 — Etapa 1 do levantamento de SQL direto (claude/levantamento-sql-direto.md): auditoria e alertas.
 *
 * Em cada empresa (bancos g*_dbp com menus, perfis, permissoes e usuarios; os *_seguranca_dbp ficam de fora):
 *   database/281_auditoria_alertas.sql — sp_auditoria_registrar (a mesma da migração 246, agora em todos os bancos),
 *   sp_alerta_criar, sp_alertas_retencao, sp_exportacoes_retencao_listar e a view vw_alertas_resumo.
 *
 * Rodar ANTES de publicar o código da etapa 1. Empresa nova: rodar de novo.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-281-auditoria-alertas.php [--simular]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

const M281_PROCEDURES = ['sp_auditoria_registrar', 'sp_alerta_criar', 'sp_alertas_retencao', 'sp_exportacoes_retencao_listar'];

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$relatorio = ['ok' => true, 'migracao' => '281', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];

// ---------------------------------------------------------------- Empresas
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes', 'usuarios') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp') && preg_match('/^g[a-z0-9_]+_dbp$/', (string)$b)));
$sql = (string)file_get_contents(dirname(__DIR__) . '/database/281_auditoria_alertas.sql');
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
            $faltam = array_values(array_diff(M281_PROCEDURES, $tem));
            $info['procedures'] = $faltam ? ['faltam' => $faltam] : 'gravadas (' . count(M281_PROCEDURES) . ')';
            if ($faltam) $relatorio['ok'] = false;
            $info['view'] = MigracaoCrud::existe($pdo, 'vw_alertas_resumo') ? 'vw_alertas_resumo gravada' : 'vw_alertas_resumo faltando';
            $info['tabelas_ausentes'] = array_values(array_filter(['logs_auditoria', 'alertas', 'exportacoes_grid'], static fn($t) => !MigracaoCrud::existe($pdo, $t))) ?: 'nenhuma';
        }
    } catch (Throwable $e) { $relatorio['ok'] = false; $info['erro'] = $e->getMessage(); }
    $relatorio['bancos'][$banco] = $info;
}
echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
