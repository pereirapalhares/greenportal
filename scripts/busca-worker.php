<?php
declare(strict_types=1);

/*
 * Serviço de indexação da Pesquisa de documentos (BUSCA_WORKER), agendado no Agendador de Tarefas do Windows a cada
 * 10 minutos (tarefa \GreenSolutions\GreenPortal-BuscaWorker; scripts\criar-agendamento-busca.ps1).
 * Em cada banco (padrão: gatlas_dbp e gmodelo_dbp; .env BUSCA_WORKER_DATABASES muda a lista) atualiza o índice
 * (classes/BuscaIndexadorService.php) e registra a execução em green_service_runs (serviço "busca").
 * Migração 346: depois, os vetores da busca pelo sentido (classes/BuscaSemantica.php) e a reavaliação das leis alteradas
 * (classes/LeiReavaliacao.php).
 * Uma execução por vez (trava em storage/runtime/green-busca.lock).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\busca-worker.php [--database=gatlas_dbp] [--dry-run]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/WindowsServiceRuntime.php';
require_once dirname(__DIR__) . '/classes/BuscaIndexadorService.php';
require_once dirname(__DIR__) . '/classes/BuscaSemantica.php';
require_once dirname(__DIR__) . '/classes/LeiReavaliacao.php';

@set_time_limit(0);
$env = GreenPortalDatabase::env();
$database = null; $dry = false;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--database=')) $database = substr($arg, 11);
    if ($arg === '--dry-run') $dry = true;
}
$bancos = $database !== null ? [$database] : array_values(array_filter(array_map('trim', explode(',', (string)($env['BUSCA_WORKER_DATABASES'] ?? '')))));
if (!$bancos) {
    // Sem lista no .env: todas as empresas em que a migração 264 foi aplicada (tabela busca_indice)
    try {
        $is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
        $bancos = $is->query("SELECT DISTINCT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME = 'busca_indice' AND TABLE_SCHEMA LIKE 'g%\\_dbp' ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
        $bancos = array_values(array_filter($bancos, static fn($b) => $b !== (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp')));
    } catch (Throwable) { $bancos = []; }
    if (!$bancos) $bancos = ['gatlas_dbp', 'gmodelo_dbp'];
}
$runtime = new WindowsServiceRuntime($env, 'busca', $dry);
$result = ['application' => 'GreenSolutions', 'version' => '4.0.1', 'service' => 'busca', 'dry_run' => $dry, 'inicio' => date('c'), 'targets' => []];
$ok = true;
foreach ($bancos as $db) {
    try {
        $runtime->databases($db);   // valida o nome do banco
        $pdo = GreenPortalDatabase::connect($env, $db, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $detalhe = [];
        $t0 = microtime(true);
        $result['targets'][$db] = $runtime->run($pdo, $db, function (PDO $p) use ($env, $db, &$detalhe): int {
            $r = (new BuscaIndexadorService($p, $env, $db))->executar();
            // Migração 346: vetores da busca pelo sentido (itens novos ou alterados) e reavaliação das leis alteradas;
            // empresa sem a 346 é ignorada; erro aqui não interrompe a indexação por palavra
            if (BuscaSemantica::disponivel($p)) {
                $tx = microtime(true); try { $r['semantica'] = (new BuscaSemantica($p, $env))->indexar(max(50, (int)($env['BUSCA_SEMANTICA_LOTE'] ?? 1000) ?: 1000)); }
                catch (Throwable $e) { $r['semantica'] = ['erro' => $e->getMessage()]; error_log('[busca semântica] ' . $db . ': ' . $e->getMessage()); }
                $r['tempos_seg']['semantica'] = round(microtime(true) - $tx, 2);
            }
            if (LeiReavaliacao::disponivel($p)) {
                $tx = microtime(true); try { $r['reavaliacao_leis'] = (new LeiReavaliacao($p, $env))->processar(); }
                catch (Throwable $e) { $r['reavaliacao_leis'] = ['erro' => $e->getMessage()]; error_log('[reavaliação de leis] ' . $db . ': ' . $e->getMessage()); }
                $r['tempos_seg']['reavaliacao_leis'] = round(microtime(true) - $tx, 2);
            }
            $detalhe = $r;
            return (int)$r['total'] + (int)($r['semantica']['vetorizados'] ?? 0) + (int)($r['reavaliacao_leis']['alteracoes'] ?? 0);
        });
        $result['targets'][$db]['indexacao'] = $detalhe;
        $result['targets'][$db]['segundos'] = round(microtime(true) - $t0, 1);
        if (($result['targets'][$db]['status'] ?? '') === 'ERRO') $ok = false;
    } catch (Throwable $e) {
        $ok = false;
        $result['targets'][$db] = ['status' => 'ERRO', 'erro' => $e->getMessage()];
    }
}
$result['fim'] = date('c');
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($ok ? 0 : 1);
