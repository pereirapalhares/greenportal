<?php
declare(strict_types=1);

/*
 * Serviço do Ranking de fornecedores — roda SÓ pelo Agendador de Tarefas do Windows
 * (\GreenSolutions\GreenPortal-RankingFornecedores, a cada 15 minutos, como SYSTEM; scripts/criar-agendamento-ranking.ps1).
 * Em cada empresa com o ranking (migração 289) chama sp_fornecedor_ranking_atualizar, que recalcula a nota de todos os
 * fornecedores quando foi pedido (pesos alterados ou "Recalcular agora" na tela Administração › Pesos do ranking) ou na
 * primeira execução de cada dia; nas demais execuções não faz nada.
 * Uso: php scripts\fornecedor-ranking-worker.php [--banco=gatlas_dbp] [--forcar]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/WindowsServiceRuntime.php';

$args = $argv ?? [];
$forcar = in_array('--forcar', $args, true);
$soBanco = null;
foreach ($args as $a) if (preg_match('/^--banco=(g[a-z0-9_]+_dbp)$/', $a, $m)) $soBanco = $m[1];
$env = GreenPortalDatabase::env();
$universal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$saida = ['servico' => 'fornecedor-ranking', 'inicio' => date('c'), 'bancos' => []];

$runtime = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime';
if (!is_dir($runtime)) @mkdir($runtime, 0770, true);
$trava = fopen($runtime . DIRECTORY_SEPARATOR . 'green-fornecedor-ranking.lock', 'c');
if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) exit(0);   // execução anterior ainda em andamento

try {
    $is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
    $s = $is->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME = 'fo_ranking_config' AND TABLE_SCHEMA LIKE 'g%\\_dbp' ORDER BY TABLE_SCHEMA");
    $bancos = array_values(array_filter($s->fetchAll(PDO::FETCH_COLUMN), static fn($b) => $b !== $universal && preg_match('/^g[a-z0-9_]+_dbp$/', (string)$b) && ($soBanco === null || $b === $soBanco)));
    $comLog = array_flip($is->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME = 'green_service_runs'")->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }

foreach ($bancos as $banco) {
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $st = $pdo->prepare('CALL sp_crud_select(:t, NULL, NULL, 1, 0)'); $st->execute(['t' => 'vw_fornecedor_ranking_config']);
        $cfg = $st->fetch(PDO::FETCH_ASSOC) ?: []; $st->closeCursor();
        $precisa = $forcar || (int)($cfg['RECALCULAR'] ?? 1) === 1 || empty($cfg['ULTIMO_CALCULO']) || substr((string)$cfg['ULTIMO_CALCULO'], 0, 10) < date('Y-m-d');
        if (!$precisa) { $saida['bancos'][$banco] = 'em dia'; continue; }
        $calcular = static function (PDO $pdo) use ($forcar): int {
            $st = $pdo->prepare('CALL sp_fornecedor_ranking_atualizar(:f)'); $st->execute(['f' => $forcar ? 1 : 0]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: []; $st->closeCursor();
            return (int)($r['fornecedores'] ?? 0);
        };
        if (isset($comLog[$banco])) $saida['bancos'][$banco] = (new WindowsServiceRuntime($env, 'fornecedor-ranking-' . $banco))->run($pdo, $banco, $calcular);
        else $saida['bancos'][$banco] = ['status' => 'CONCLUIDA', 'quantidade' => $calcular($pdo)];
    } catch (Throwable $e) { $saida['bancos'][$banco] = ['status' => 'ERRO', 'erro' => $e->getMessage()]; }
}
$saida['fim'] = date('c');
echo json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
flock($trava, LOCK_UN);
fclose($trava);
exit(0);
