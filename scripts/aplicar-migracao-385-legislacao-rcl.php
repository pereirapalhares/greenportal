<?php
declare(strict_types=1);

/*
 * Migração 385 — RCL Completa / RCL Padrão em Manutenção Legal › Legislação (17/11/2026).
 * glegislacao_dbp: views vw_legislacao_rcl (lei × assunto, com o mês da "Data atualização") e vw_legislacao_rcl_alteracoes.
 * Cada empresa migrada: tabela legislacaoxrevisoes (chave, dscrevisao, data) quando ainda não existe — histórico do dia gravado
 * a cada RCL gerado, como no legado. Tela: principal/manutencaolegal_legislacao_rcl.php (classes/LegislacaoRcl.php).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-385-legislacao-rcl.php [--simular]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/LegislacaoEmpresas.php';

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$rel = ['ok' => true, 'migracao' => '385', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR'];
try {
    $leg = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
    $leg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($simular) $rel['legislacao'] = 'serão criadas/recriadas: vw_legislacao_rcl, vw_legislacao_rcl_alteracoes';
    else {
        $n = 0;
        foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents(dirname(__DIR__) . '/database/385_legislacao_rcl.sql')) as $bloco) {
            $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
            if ($cmd === '') continue;
            $leg->exec($cmd); $n++;
        }
        $rel['legislacao'] = ['blocos' => $n, 'linhas_10_2026' => (int)$leg->query("SELECT COUNT(*) FROM vw_legislacao_rcl WHERE MES_ATUALIZACAO = '2026-10'")->fetchColumn()];
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['legislacao'] = 'ERRO: ' . $e->getMessage(); }
foreach (LegislacaoEmpresas::migradas() as $cod => $emp) {
    try {
        $pdo = LegislacaoEmpresas::pdo($cod);
        if (MigracaoCrud::existe($pdo, 'legislacaoxrevisoes')) { $rel['empresas'][$emp['banco']] = 'legislacaoxrevisoes já existe (' . count(MigracaoCrud::selecionar($pdo, 'legislacaoxrevisoes')) . ' registros)'; continue; }
        if ($simular) { $rel['empresas'][$emp['banco']] = 'legislacaoxrevisoes será criada'; continue; }
        $pdo->exec("CREATE TABLE `legislacaoxrevisoes` (`chave` INT NOT NULL AUTO_INCREMENT, `dscrevisao` LONGTEXT NULL, `data` DATE NULL, PRIMARY KEY (`chave`), KEY `ix_lxr_data` (`data`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Histórico das revisões do RCL (migração 385)'");
        $rel['empresas'][$emp['banco']] = 'legislacaoxrevisoes criada';
    } catch (Throwable $e) { $rel['ok'] = false; $rel['empresas'][$emp['banco']] = 'ERRO: ' . $e->getMessage(); }
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
