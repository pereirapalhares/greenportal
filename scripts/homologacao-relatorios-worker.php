<?php
declare(strict_types=1);

/*
 * Serviço do envio semanal de "Meus relatórios" (Relatórios de Homologação, migração 314) — roda SÓ pelo Agendador de Tarefas
 * do Windows (\GreenSolutions\GreenPortal-RelatoriosHomologacao, segundas-feiras às 07:20, como SYSTEM;
 * scripts/criar-agendamento-relatorios-homologacao.ps1).
 * Em cada empresa: CALL sp_fo_relatorio_visoes_semana(semana ISO, 0) lista as visões com envio ligado ainda não enviadas na semana;
 * para cada uma executa o relatório com os filtros salvos e o escopo do dono (classes/HomologacaoRelatorios.php — as mesmas regras
 * da tela), cria um alerta em Home › Alertas (sp_alerta_criar) com o resumo e o link que abre a visão, e marca a visão como enviada
 * (CALL ... (semana, codvisao)), para não repetir.
 * Migração 316: o escopo do dono vem de vw_homologacao_usuario_acesso (0 vê tudo, 1 fornecedor externo, 2 interno restrito às
 * suas unidades), a mesma regra da tela; antes da migração vale só o "externo" gravado na visão.
 * Uso: php scripts\homologacao-relatorios-worker.php [--banco=gatlas_dbp] [--semana=2026-43] [--simular]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoRelatorios.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null; $semana = date('o-W');
foreach ($args as $a) {
    if (preg_match('/^--banco=(g[a-z0-9_]+_dbp)$/', $a, $m)) $soBanco = $m[1];
    if (preg_match('/^--semana=(\d{4}-\d{2})$/', $a, $m)) $semana = $m[1];
}
$env = GreenPortalDatabase::env();
$universal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$saida = ['servico' => 'homologacao-relatorios', 'semana' => $semana, 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'inicio' => date('c'), 'bancos' => []];

$runtime = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime';
if (!is_dir($runtime)) @mkdir($runtime, 0770, true);
$trava = fopen($runtime . DIRECTORY_SEPARATOR . 'green-homologacao-relatorios.lock', 'c');
if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) exit(0);

try {
    $is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
    $s = $is->query("SELECT ROUTINE_SCHEMA FROM information_schema.ROUTINES WHERE ROUTINE_NAME = 'sp_fo_relatorio_visoes_semana' AND ROUTINE_SCHEMA LIKE 'g%\\_dbp' ORDER BY ROUTINE_SCHEMA");
    $bancos = array_values(array_filter($s->fetchAll(PDO::FETCH_COLUMN), static fn($b) => $b !== $universal && preg_match('/^g[a-z0-9_]+_dbp$/', (string)$b) && ($soBanco === null || $b === $soBanco)));
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }

$chamar = static function (PDO $pdo, string $sql, array $p): array {
    $st = $pdo->prepare($sql); $st->execute($p); $r = $st->fetchAll(PDO::FETCH_ASSOC); while ($st->nextRowset()) { } $st->closeCursor(); return $r;
};
$R = new HomologacaoRelatorios('pt');
$fmt = static fn(int $n): string => number_format($n, 0, ',', '.');
foreach ($bancos as $banco) {
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $visoes = $chamar($pdo, 'CALL sp_fo_relatorio_visoes_semana(?, 0)', [$semana]);
        if (!$visoes) { $saida['bancos'][$banco] = ['status' => 'NADA_A_FAZER (sem visões com envio semanal pendente)']; continue; }
        $alertas = 0; $erros = [];
        // Modo de acesso de cada dono (migração 316); sem a view, só o "externo" da visão.
        $modoDono = null;
        try { $modoDono = $pdo->prepare('SELECT MODO FROM vw_homologacao_usuario_acesso WHERE CODUSUARIO = ?'); } catch (Throwable) { $modoDono = null; }
        foreach ($visoes as $v) {
            $cod = (int)$v['CODVISAO']; $u = (int)$v['CODUSUARIO'];
            if ($cod < 1 || $u < 1) continue;
            try {
                $externo = (int)$v['EXTERNO'] === 1;
                $p = json_decode((string)($v['PARAMETROS'] ?? '{}'), true);
                $pedido = HomologacaoRelatorios::normalizarPedido(is_array($p) ? $p : ['relatorio' => (string)$v['RELATORIO']], $externo);
                $modo = $externo ? 1 : 0;
                if ($modoDono) {
                    try { $modoDono->execute([$u]); $m = $modoDono->fetchColumn(); $modoDono->closeCursor(); if ($m !== false) $modo = (int)$m; } catch (Throwable) { }
                }
                $res = $R->executar($pdo, $u, $modo, $pedido);
                if ($res['unificado']) {
                    $c = $res['contagem'];
                    $msg = 'Seu relatório "' . $v['NOME'] . '" (' . $R->nome('unificado') . '): ' . $fmt(count($res['linhas'])) . ' funcionário(s) — '
                        . $fmt($c['bloq']) . ' bloqueado(s), ' . $fmt($c['vence']) . ' vencem em breve, ' . $fmt($c['lib']) . ' liberado(s).';
                } else {
                    $verm = count(array_filter($res['linhas'], static fn($l) => $l['cor'] === 'VERMELHO'));
                    $amar = count(array_filter($res['linhas'], static fn($l) => $l['cor'] === 'AMARELO'));
                    $msg = 'Seu relatório "' . $v['NOME'] . '" (' . $R->nome($pedido['relatorio']) . '): ' . $fmt(count($res['linhas'])) . ' item(ns) — '
                        . $fmt($verm) . ' pedem ação, ' . $fmt($amar) . ' vencem em breve ou estão com a empresa.';
                }
                if (!$simular) {
                    $r = $chamar($pdo, 'CALL sp_alerta_criar(?, ?, ?, ?, ?)', [$u, 'Relatório semanal: ' . mb_substr((string)$v['NOME'], 0, 140), mb_substr($msg, 0, 1000),
                        '/principal/homologacaofornecedores_relatorios.php?visao=' . $cod . '&semana=' . $semana, 'USUARIO_LINK']);
                    if ((int)($r[0]['codalerta'] ?? 0) > 0) $alertas++;
                    $chamar($pdo, 'CALL sp_fo_relatorio_visoes_semana(?, ?)', [$semana, $cod]);
                }
            } catch (Throwable $e) { $erros[$cod] = $e->getMessage(); }
        }
        $saida['bancos'][$banco] = ['status' => $erros ? 'CONCLUIDA_COM_ERROS' : 'CONCLUIDA', 'visoes' => count($visoes), 'alertas_criados' => $alertas] + ($erros ? ['erros' => $erros] : []);
    } catch (Throwable $e) { $saida['bancos'][$banco] = ['status' => 'ERRO', 'erro' => $e->getMessage()]; }
}
$saida['fim'] = date('c');
$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$logs = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logs)) @mkdir($logs, 0775, true);
@file_put_contents($logs . DIRECTORY_SEPARATOR . 'homologacao_relatorios_' . $semana . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
flock($trava, LOCK_UN);
fclose($trava);
exit(0);
