<?php
declare(strict_types=1);

/*
 * Serviço do resumo semanal da Homologação de Fornecedores — roda SÓ pelo Agendador de Tarefas do Windows
 * (\GreenSolutions\GreenPortal-ResumoHomologacao, segundas-feiras às 07:10, como SYSTEM;
 * scripts/criar-agendamento-resumo-homologacao.ps1). Requer a migração 298.
 * Em cada empresa: CALL sp_fo_painel_resumo_semanal(semana ISO, 0) lista os responsáveis (empresa ou fornecedor) com itens
 * vermelhos ou amarelos que não desligaram o resumo; para cada um cria um alerta em Home › Alertas (sp_alerta_criar) com o
 * link do Painel filtrado por "Sou eu"; depois registra a semana (CALL ... (semana, 1)), para não repetir.
 * Uso: php scripts\homologacao-resumo-worker.php [--banco=gatlas_dbp] [--semana=2026-42] [--simular]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null; $semana = date('o-W');
foreach ($args as $a) {
    if (preg_match('/^--banco=(g[a-z0-9_]+_dbp)$/', $a, $m)) $soBanco = $m[1];
    if (preg_match('/^--semana=(\d{4}-\d{2})$/', $a, $m)) $semana = $m[1];
}
$env = GreenPortalDatabase::env();
$universal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$saida = ['servico' => 'homologacao-resumo', 'semana' => $semana, 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'inicio' => date('c'), 'bancos' => []];

$runtime = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime';
if (!is_dir($runtime)) @mkdir($runtime, 0770, true);
$trava = fopen($runtime . DIRECTORY_SEPARATOR . 'green-homologacao-resumo.lock', 'c');
if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) exit(0);

try {
    $is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
    $s = $is->query("SELECT ROUTINE_SCHEMA FROM information_schema.ROUTINES WHERE ROUTINE_NAME = 'sp_fo_painel_resumo_semanal' AND ROUTINE_SCHEMA LIKE 'g%\\_dbp' ORDER BY ROUTINE_SCHEMA");
    $bancos = array_values(array_filter($s->fetchAll(PDO::FETCH_COLUMN), static fn($b) => $b !== $universal && preg_match('/^g[a-z0-9_]+_dbp$/', (string)$b) && ($soBanco === null || $b === $soBanco)));
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }

$chamar = static function (PDO $pdo, string $sql, array $p): array {
    $st = $pdo->prepare($sql); $st->execute($p); $r = $st->fetchAll(PDO::FETCH_ASSOC); while ($st->nextRowset()) { } $st->closeCursor(); return $r;
};
foreach ($bancos as $banco) {
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $lista = $chamar($pdo, 'CALL sp_fo_painel_resumo_semanal(?, 0)', [$semana]);
        if (!$lista) { $saida['bancos'][$banco] = ['status' => 'NADA_A_FAZER (semana já enviada ou sem pendências)']; continue; }
        $alertas = 0;
        if (!$simular) {
            foreach ($lista as $l) {
                $u = (int)$l['CODUSUARIO']; if ($u < 1) continue;
                $v = (int)$l['VERMELHOS']; $a = (int)$l['AMARELOS']; $p = (int)$l['VENCEM30'];
                $msg = "Suas pendências na homologação de fornecedores: $v vermelho(s), $a amarelo(s)" . ($p ? ", $p vencendo nos próximos 30 dias" : '') . '. Abra o Painel de Homologação para ver a lista.';
                $r = $chamar($pdo, 'CALL sp_alerta_criar(?, ?, ?, ?, ?)', [$u, 'Resumo semanal da homologação', $msg, '/principal/homologacaofornecedores_painel.php?responsavel=EU&consulta=todos&semana=' . $semana, 'USUARIO_LINK']);
                if ((int)($r[0]['codalerta'] ?? 0) > 0) $alertas++;
            }
            $chamar($pdo, 'CALL sp_fo_painel_resumo_semanal(?, 1)', [$semana]);
        }
        $saida['bancos'][$banco] = ['status' => 'CONCLUIDA', 'responsaveis' => count($lista), 'alertas_criados' => $alertas];
    } catch (Throwable $e) { $saida['bancos'][$banco] = ['status' => 'ERRO', 'erro' => $e->getMessage()]; }
}
$saida['fim'] = date('c');
$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'homologacao_resumo_' . $semana . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
flock($trava, LOCK_UN);
fclose($trava);
exit(0);
