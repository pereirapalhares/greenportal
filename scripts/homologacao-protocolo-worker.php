<?php
declare(strict_types=1);

/*
 * Serviço do protocolo da Homologação de Fornecedores — roda SÓ pelo Agendador de Tarefas do Windows
 * (\GreenSolutions\GreenPortal-ProtocoloHomologacao, todo dia às 06:40, como SYSTEM;
 * scripts/criar-agendamento-protocolo-homologacao.ps1). Requer a migração 300.
 * Regra do legado: documento "Aguardando vencimento do protocolo" volta para "Pendente com o fornecedor" quando chega a data de
 * cobrança (30 dias depois de marcar Em protocolo = Sim/Renovar). Em cada empresa: CALL sp_fo_protocolo_vencidos_processar(0, 1)
 * altera os documentos (e grava o histórico); depois cada responsável (empresa e fornecedor) de fornecedor ativo recebe um alerta
 * em Home › Alertas (sp_alerta_criar) com a lista.
 * Uso: php scripts\homologacao-protocolo-worker.php [--banco=gatlas_dbp] [--simular]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (preg_match('/^--banco=(g[a-z0-9_]+_dbp)$/', $a, $m)) $soBanco = $m[1];
$env = GreenPortalDatabase::env();
$universal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$saida = ['servico' => 'homologacao-protocolo', 'data' => date('Y-m-d'), 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'inicio' => date('c'), 'bancos' => []];

$runtime = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime';
if (!is_dir($runtime)) @mkdir($runtime, 0770, true);
$trava = fopen($runtime . DIRECTORY_SEPARATOR . 'green-homologacao-protocolo.lock', 'c');
if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) exit(0);

try {
    $is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
    $s = $is->query("SELECT ROUTINE_SCHEMA FROM information_schema.ROUTINES WHERE ROUTINE_NAME = 'sp_fo_protocolo_vencidos_processar' AND ROUTINE_SCHEMA LIKE 'g%\\_dbp' ORDER BY ROUTINE_SCHEMA");
    $bancos = array_values(array_filter($s->fetchAll(PDO::FETCH_COLUMN), static fn($b) => $b !== $universal && preg_match('/^g[a-z0-9_]+_dbp$/', (string)$b) && ($soBanco === null || $b === $soBanco)));
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }

$chamar = static function (PDO $pdo, string $sql, array $p): array {
    $st = $pdo->prepare($sql); $st->execute($p); $r = $st->fetchAll(PDO::FETCH_ASSOC); while ($st->nextRowset()) { } $st->closeCursor(); return $r;
};
foreach ($bancos as $banco) {
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $lista = $chamar($pdo, 'CALL sp_fo_protocolo_vencidos_processar(0, ?)', [$simular ? 0 : 1]);
        if (!$lista) { $saida['bancos'][$banco] = ['status' => 'NADA_A_FAZER']; continue; }
        // Agrupa por responsável (empresa e fornecedor), só fornecedores ativos
        $porUsuario = [];
        foreach ($lista as $l) {
            if ((int)$l['ATIVO'] !== 1) continue;
            foreach (['CODUSUARIO_EMPRESA', 'CODUSUARIO_FORNECEDOR'] as $c) {
                $u = (int)($l[$c] ?? 0);
                if ($u > 0) $porUsuario[$u][(int)$l['CODFORNECEDORXCRITERIOS']] = trim((string)$l['RAZAOSOCIAL']) . ': ' . trim((string)$l['DOCUMENTO']);
            }
        }
        $alertas = 0;
        if (!$simular) {
            foreach ($porUsuario as $u => $docs) {
                $n = count($docs);
                $exemplos = array_slice(array_values($docs), 0, 5);
                $msg = "Venceu o prazo do protocolo de $n documento(s) de fornecedor; voltaram para \"Pendente com o fornecedor\": " . implode('; ', $exemplos) . ($n > 5 ? '; ...' : '') . '.';
                $r = $chamar($pdo, 'CALL sp_alerta_criar(?, ?, ?, ?, ?)', [$u, 'Protocolo vencido — documentos de fornecedor', mb_substr($msg, 0, 1000), '/principal/homologacaofornecedores_painel.php?responsavel=EU&consulta=pendforn', 'USUARIO_LINK']);
                if ((int)($r[0]['codalerta'] ?? 0) > 0) $alertas++;
            }
        }
        $saida['bancos'][$banco] = ['status' => $simular ? 'SIMULADO' : 'CONCLUIDA', 'documentos' => count($lista), 'responsaveis' => count($porUsuario), 'alertas_criados' => $alertas,
            'codigos' => array_map(static fn($l) => (int)$l['CODFORNECEDORXCRITERIOS'], $lista)];
    } catch (Throwable $e) { $saida['bancos'][$banco] = ['status' => 'ERRO', 'erro' => $e->getMessage()]; }
}
$saida['fim'] = date('c');
$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$logs = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logs)) @mkdir($logs, 0775, true);
@file_put_contents($logs . DIRECTORY_SEPARATOR . 'homologacao_protocolo_' . date('Y-m-d') . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
flock($trava, LOCK_UN);
fclose($trava);
exit(0);
