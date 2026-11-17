<?php
declare(strict_types=1);

/*
 * Migração 318 — Homologação de Fornecedores (20/10/2026), ajustes da comparação com o legado:
 *  1. aba Auditoria mostra a data de validade zerada do legado (0000-00-00) em branco, como o legado
 *     (vw_homologacao_fornecedor_auditoria_grid, database/300_protocolo_ged_homologacao.sql);
 *  2. planilha do relatório "Documentos do fornecedor" com as colunas do Excel "Fornecedores + Critérios" do legado
 *     (sp_fo_relatorio_itens devolve o responsável pelo fornecedor do cadastro; database/314_relatorios_homologacao.sql).
 * Só view e procedimento (não mexe em dados). Pode ser executada mais de uma vez.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-318-data-auditoria.php [--simular] [--banco=gatlas_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$simular = in_array('--simular', $argv ?? [], true);
try { $alvos = HomologacaoBancos::lista($argv ?? []); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
$env = GreenPortalDatabase::env();
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$view = null;
foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents(dirname(__DIR__) . '/database/300_protocolo_ged_homologacao.sql')) as $b) {
    $limpo = trim(preg_replace('/^\s*--.*$/m', '', $b) ?? '');
    if (str_contains($limpo, 'VIEW `vw_homologacao_fornecedor_auditoria_grid`')) $view = $limpo;
}
if ($view === null || !str_contains($view, "> '1900-01-01'")) { fwrite(STDERR, "database/300 não está na versão da migração 318.\n"); exit(1); }
$itens = [];
foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents(dirname(__DIR__) . '/database/314_relatorios_homologacao.sql')) as $b) {
    $limpo = trim(preg_replace('/^\s*--.*$/m', '', $b) ?? '');
    if (str_contains($limpo, '`sp_fo_relatorio_itens`')) $itens[] = $limpo;
}
if (count($itens) !== 2 || !str_contains(implode("\n", $itens), 'RESPONSAVEL_CADASTRO')) { fwrite(STDERR, "database/314 não está na versão da migração 318.\n"); exit(1); }

$rel = ['ok' => true, 'migracao' => '318', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach ($alvos as $banco) {
    $s = $is->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'fo_fornecedoresxtipofornecedores'");
    $s->execute([$banco]);
    if (!(int)$s->fetchColumn()) { if ($banco !== 'gmodelo2_dbp') { $rel['ok'] = false; $rel['bancos'][$banco] = ['erro' => 'banco ou tabela não existe']; } continue; }
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $zeradas = (int)$pdo->query("SELECT COUNT(*) FROM fo_fornecedoresxtipofornecedores WHERE datavalidade IS NOT NULL AND NOT (datavalidade > '1900-01-01')")->fetchColumn();
        if (!$simular) { $pdo->exec($view); foreach ($itens as $b) $pdo->exec($b); }
        $rel['bancos'][$banco] = ['datas_zeradas_que_aparecem_em_branco' => $zeradas, 'view_e_procedimento' => $simular ? 'seriam recriados' : 'recriados'];
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = ['erro' => $e->getMessage()]; }
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
