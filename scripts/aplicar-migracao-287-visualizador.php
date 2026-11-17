<?php
declare(strict_types=1);

/*
 * Migração 287 — Visualizador universal de arquivos: fila de conversão no banco universal.
 *   database/287_visualizador_conversao.sql — tabela visualizador_conversao, procedures sp_visualizador_pedir /
 *   sp_visualizador_proximo / sp_visualizador_concluir e a view vw_visualizador_conversao.
 * A conversão é feita só pelo serviço scripts/visualizador-worker.php no Agendador do Windows
 * (scripts/criar-agendamento-visualizador.ps1). No fim, mostra o diagnóstico dos programas de conversão.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-287-visualizador.php [--simular]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$banco = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$relatorio = ['ok' => true, 'migracao' => '287', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'banco' => $banco];
try {
    $pdo = GreenPortalDatabase::connect($env, $banco, 'GUNIVERSAL');
    $sql = (string)file_get_contents(dirname(__DIR__) . '/database/287_visualizador_conversao.sql');
    $blocos = 0;
    foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
        $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
        if ($cmd === '') continue;
        $blocos++;
        if (!$simular) $pdo->exec($cmd);
    }
    $relatorio['comandos'] = $blocos . ($simular ? ' a executar' : ' executados');
    if (!$simular) {
        $relatorio['fila'] = $pdo->query('SELECT status, COUNT(*) FROM vw_visualizador_conversao GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR) ?: 'vazia';
        $relatorio['procedures'] = $pdo->query("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME LIKE 'sp\\_visualizador\\_%' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Throwable $e) { $relatorio['ok'] = false; $relatorio['erro'] = $e->getMessage(); }
echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ($relatorio['ok'] && !$simular) {
    echo PHP_EOL . "Diagnóstico dos programas de conversão:" . PHP_EOL;
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'visualizador-worker.php') . ' --diagnostico');
}
exit($relatorio['ok'] ? 0 : 1);
