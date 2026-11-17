<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/WindowsServiceRuntime.php';

$env = GreenPortalDatabase::env();
$database = null; $limit = 10; $dryRun = false;
foreach ($argv ?? [] as $argument) {
    if (str_starts_with((string)$argument, '--database=')) $database = substr((string)$argument, 11);
    if (str_starts_with((string)$argument, '--limit=')) $limit = max(1, min(50, (int)substr((string)$argument, 8)));
    if ((string)$argument === '--dry-run') $dryRun = true;
}
$runtime = new WindowsServiceRuntime($env, 'export', $dryRun);
$php = PHP_BINARY;
$worker = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'ExportacaoGridWorker.php';
$root = dirname(__DIR__);
$result = ['application' => 'GreenSolutions', 'version' => '4.0.1', 'service' => 'export', 'dry_run' => $dryRun, 'tenants' => []];

foreach ($runtime->databases($database) as $tenantDatabase) {
    $pdo = GreenPortalDatabase::connect($env, $tenantDatabase, 'GPT');
    $run = $runtime->run($pdo, $tenantDatabase, static function (PDO $connection) use ($tenantDatabase, $php, $worker, $root, $limit): int {
        $stale = $connection->prepare('CALL sp_crud_select(:tabela,:filtros,NULL,100,0)');
        $stale->execute(['tabela' => 'exportacoes_grid', 'filtros' => json_encode(['status' => 'PROCESSANDO'], JSON_UNESCAPED_UNICODE)]);
        $staleJobs = $stale->fetchAll(PDO::FETCH_ASSOC);
        $stale->closeCursor();
        $limiteStale = time() - 1800;
        foreach ($staleJobs as $staleJob) {
            $iniciada = strtotime((string)($staleJob['iniciada_em'] ?? ''));
            $staleId = (int)($staleJob['codexportacaogrid'] ?? 0);
            if ($staleId <= 0 || $iniciada <= 0 || $iniciada > $limiteStale) continue;
            $recover = $connection->prepare('CALL sp_crud_update(:tabela,:chave,:dados)');
            $recover->execute([
                'tabela' => 'exportacoes_grid',
                'chave' => json_encode(['codexportacaogrid' => $staleId]),
                'dados' => json_encode(['status' => 'ERRO', 'concluido_em' => date('Y-m-d H:i:s'), 'erro' => 'Solicitação recuperada automaticamente após exceder 30 minutos em processamento.'], JSON_UNESCAPED_UNICODE),
            ]);
            $recover->closeCursor();
        }
        $select = $connection->prepare('CALL sp_crud_select(:tabela,:filtros,NULL,:limite,0)');
        $select->execute(['tabela' => 'exportacoes_grid', 'filtros' => json_encode(['status' => 'SOLICITADA'], JSON_UNESCAPED_UNICODE), 'limite' => $limit]);
        $jobs = $select->fetchAll(PDO::FETCH_ASSOC); $select->closeCursor(); $processed = 0;
        foreach (array_slice($jobs, 0, $limit) as $job) {
            $jobId = (int)($job['codexportacaogrid'] ?? 0); if ($jobId <= 0) continue;
            $log = $root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'exportacao_' . $jobId . '.log';
            $pipes = [];
            // Ambiente completo do processo (getenv): no Windows $_ENV costuma vir vazio e, sem SYSTEMROOT, o worker não consegue abrir o socket do MySQL (2002).
            $workerEnvironment = array_merge(getenv() ?: [], $_ENV, ['GREENPORTAL_EXPORT_FROM_SCHEDULER' => '1']);
            $process = @proc_open([$php, $worker, (string)$jobId, $tenantDatabase], [['pipe', 'r'], ['file', $log, 'ab'], ['file', $log, 'ab']], $pipes, $root, $workerEnvironment, ['bypass_shell' => true, 'create_process_group' => true]);
            if (!is_resource($process)) throw new RuntimeException('Não foi possível iniciar o worker da exportação ' . $jobId . '.');
            if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            $exitCode = proc_close($process); if ($exitCode !== 0) throw new RuntimeException('Worker da exportação ' . $jobId . ' finalizou com código ' . $exitCode . '.');
            $processed++;
        }
        return $processed;
    });
    $result['tenants'][$tenantDatabase] = $run;
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
