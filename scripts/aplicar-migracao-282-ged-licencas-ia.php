<?php
declare(strict_types=1);

/*
 * Migração 282 — Etapa 2 do levantamento de SQL direto (claude/levantamento-sql-direto.md): GED, licenças e IA.
 *
 * Universal (guniversal_dbp): vw_empresas_ativas (bancos das empresas ativas, usada pelo serviço de IA).
 * Em cada empresa (bancos g*_dbp com menus, perfis, permissoes e usuarios; os *_seguranca_dbp ficam de fora):
 *   database/282_ged_licencas_ia.sql — views de leitura (análises de IA, licenças, OCR, documentos de funcionários, potenciais)
 *   e procedures de gravação (análises de IA do GED, ativação de versão da licença, fila de OCR, confirmação de documentos de
 *   funcionários). Cada view só é criada quando as tabelas dela existem no banco ("-- requer:"); as que faltam são informadas.
 *
 * Rodar ANTES de publicar o código da etapa 2. Empresa nova: rodar de novo.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-282-ged-licencas-ia.php [--simular]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

const M282_PROCEDURES = ['sp_ged_analise_ia_iniciar', 'sp_ged_analise_ia_concluir', 'sp_ged_analise_ia_falhar', 'sp_ged_analise_ia_encerrar',
    'sp_ged_licenca_vincular_documento', 'sp_licenca_ativar_versao', 'sp_ocr_job_enfileirar', 'sp_ocr_job_reservar', 'sp_ocr_job_concluir',
    'sp_ocr_job_falhar', 'sp_fo_documento_ia_descartar', 'sp_fo_documento_ia_confirmar', 'sp_fo_sinc_protecao_registrar'];

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$relatorio = ['ok' => true, 'migracao' => '282', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal' => [], 'bancos' => []];

// ---------------------------------------------------------------- Universal
try {
    $u = GreenPortalDatabase::connect($env, $bancoUniversal, 'GUNIVERSAL');
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (!MigracaoCrud::existe($u, 'empresa')) throw new RuntimeException('tabela empresa não existe no universal');
    if (!$simular) $u->exec("CREATE OR REPLACE VIEW `vw_empresas_ativas` AS SELECT `codempresa`, `banco_dados` FROM `empresa`
        WHERE `status` = 'ATIVA' AND `banco_dados` IS NOT NULL AND `banco_dados` <> ''");
    $relatorio['universal']['views'] = ['vw_empresas_ativas'];
} catch (Throwable $e) { $relatorio['ok'] = false; $relatorio['universal']['erro'] = $e->getMessage(); }

// ---------------------------------------------------------------- Empresas
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes', 'usuarios') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp') && preg_match('/^g[a-z0-9_]+_dbp$/', (string)$b)));
$temLegislacao = (bool)$geral->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'glegislacao_dbp' AND TABLE_NAME = 'legislacao'")->fetchColumn();
$sql = (string)file_get_contents(dirname(__DIR__) . '/database/282_ged_licencas_ia.sql');
foreach ($bancos as $banco) {
    $info = ['views' => [], 'views_nao_criadas' => []];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
            $requer = preg_match('/^-- requer:\s*(.+)$/m', $bloco, $m) ? array_map('trim', explode(',', $m[1])) : [];
            $cmd = strtr(trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco)), ['{{LEGISLACAO}}' => '`glegislacao_dbp`.`legislacao`']);
            if ($cmd === '') continue;
            $view = preg_match('/VIEW\s+`(\w+)`/', $cmd, $v) ? $v[1] : null;
            $faltam = array_values(array_filter($requer, static fn($t) => $t === '{{LEGISLACAO}}' ? !$temLegislacao : !MigracaoCrud::existe($pdo, $t)));
            if ($view && $faltam) { $info['views_nao_criadas'][$view] = 'sem ' . implode(', ', array_map(static fn($t) => $t === '{{LEGISLACAO}}' ? 'glegislacao_dbp.legislacao' : $t, $faltam)); continue; }
            if (!$simular) $pdo->exec($cmd);
            if ($view) $info['views'][] = $view;
        }
        if (!$simular) {
            if ($criadas = MigracaoCrud::garantir($pdo)) $info['procedures_crud_criadas'] = $criadas;
            if (MigracaoCrud::existe($pdo, 'ged_analises_ia') && !in_array('hash_arquivo', MigracaoCrud::colunas($pdo, 'ged_analises_ia'), true))
                $info['aviso'] = 'ged_analises_ia sem hash_arquivo/analise_origem: rode antes a migração 223';
            $s = $pdo->query("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = 'PROCEDURE'");
            $tem = $s->fetchAll(PDO::FETCH_COLUMN);
            $s->closeCursor();
            $faltamP = array_values(array_diff(M282_PROCEDURES, $tem));
            $info['procedures'] = $faltamP ? ['faltam' => $faltamP] : 'gravadas (' . count(M282_PROCEDURES) . ')';
            if ($faltamP) $relatorio['ok'] = false;
        }
        $info['views'] = count($info['views']) . ' gravadas';
        if (!$info['views_nao_criadas']) unset($info['views_nao_criadas']);
    } catch (Throwable $e) { $relatorio['ok'] = false; $info['erro'] = $e->getMessage(); }
    $relatorio['bancos'][$banco] = $info;
}
echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
