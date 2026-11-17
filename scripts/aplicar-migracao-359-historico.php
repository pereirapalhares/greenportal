<?php
declare(strict_types=1);

/*
 * Migração 359 — Históricos obrigatórios (item 3 da análise do 01_Mudancas_Gerais: GER-16; MM-77, CON-93, TRE-108, INF-123, HOM-141).
 * Decisões do usuário (12/11/2026): registrar pelo banco (gatilhos) tudo o que mudar, de qualquer origem, inclusive o
 * sincronismo; só os campos que mudaram; quem vê a tela vê o histórico.
 *
 * Em cada empresa:
 *   1. database/359_historico_alteracoes.sql: tabela historico_alteracoes e vw_historico_alteracoes;
 *   2. para cada tabela de classes/HistoricoAlteracoes::TABELAS que existir (mm_monitoramento, documentos, ml_treinamentos,
 *      ml_infraestrutura, fo_fornecedores, fo_funcionarios): gatilhos trg_hist_<tabela>_ai / _au / _ad, montados com as
 *      colunas atuais da tabela (rodar de novo depois de criar colunas novas). Gatilhos de outros nomes que a tabela já tiver
 *      não são alterados (o MySQL permite vários por evento); a saída lista quais existem.
 * Não há histórico de antes da migração. --remover apaga os gatilhos trg_hist_* (a tabela de histórico fica).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-359-historico.php [--simular] [--banco=gcrown_dbp] [--remover]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HistoricoAlteracoes.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$remover = in_array('--remover', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$rel = ['ok' => true, 'migracao' => '359', 'modo' => $simular ? 'SIMULACAO' : ($remover ? 'REMOVER GATILHOS' : 'APLICAR'), 'bancos' => []];

$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes', 'usuarios') AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp') && ($soBanco === null || $b === $soBanco)));
$sql = (string)file_get_contents(dirname(__DIR__) . '/database/359_historico_alteracoes.sql');

foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (!$simular && !$remover) {
            foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
                $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
                if ($cmd !== '') $pdo->exec($cmd);
            }
            $info['historico'] = 'historico_alteracoes e vw_historico_alteracoes ok';
        }
        foreach (array_keys(HistoricoAlteracoes::TABELAS) as $tabela) {
            $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = ?');
            $s->execute([$tabela, 'BASE TABLE']); $existe = (int)$s->fetchColumn() > 0; $s->closeCursor();
            if (!$existe) { $info['tabelas'][$tabela] = 'não existe nesta empresa'; continue; }
            $chave = HistoricoAlteracoes::chave($pdo, $tabela);
            $outros = HistoricoAlteracoes::gatilhosExistentes($pdo, $tabela);
            if ($chave === null) { $info['tabelas'][$tabela] = 'IGNORADA: sem chave primária de uma coluna'; continue; }
            $cols = HistoricoAlteracoes::colunas($pdo, $tabela);
            $ddl = HistoricoAlteracoes::gatilhos($tabela, $chave, $cols);
            $txt = count($cols) . ' campo(s), chave ' . $chave . ($outros ? '; outros gatilhos mantidos: ' . implode(', ', $outros) : '');
            if ($simular) { $info['tabelas'][$tabela] = 'serão criados 3 gatilhos (' . $txt . ')'; continue; }
            foreach (array_keys($ddl) as $nome) $pdo->exec('DROP TRIGGER IF EXISTS `' . $nome . '`');
            if ($remover) { $info['tabelas'][$tabela] = 'gatilhos removidos'; continue; }
            foreach ($ddl as $cmd) $pdo->exec($cmd);
            $info['tabelas'][$tabela] = 'gatilhos criados (' . $txt . ')';
        }
        if ($simular) $info['modo'] = 'simulação: nada gravado';
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_359_historico' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
