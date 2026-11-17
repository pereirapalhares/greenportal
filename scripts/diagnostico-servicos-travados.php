<?php
declare(strict_types=1);

/*
 * Diagnóstico (só leitura) de serviços do Agendador parados ou travados.
 *   - Consultas abertas no MySQL há mais de 30 segundos (information_schema.PROCESSLIST), com o que estão esperando;
 *   - transações abertas (INNODB_TRX) e esperas de bloqueio;
 *   - por empresa: as últimas execuções de cada serviço (green_service_runs), com erro, e há quanto tempo cada serviço
 *     não termina uma execução;
 *   - fila de OCR e de IA: quantos pedidos em cada situação e o mais antigo pendente.
 * Não grava nada. Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-servicos-travados.php
 * Resultado também em storage\logs\diagnostico_servicos_travados.json
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/WindowsServiceRuntime.php';

$env = GreenPortalDatabase::env();
$corta = static fn($v, int $n = 300): string => mb_strimwidth(trim(preg_replace('/\s+/u', ' ', (string)$v) ?? ''), 0, $n, '…', 'UTF-8');
$rel = ['gerado_em_php' => date('d/m/Y H:i:s'), 'fuso_php' => date_default_timezone_get()];

try {
    $is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
    $is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $rel['agora_mysql'] = $is->query("SELECT NOW() AS agora, @@global.time_zone AS fuso, @@system_time_zone AS fuso_sistema")->fetch(PDO::FETCH_ASSOC);
    $rel['consultas_abertas'] = array_map(static fn($r) => ['id' => $r['ID'], 'banco' => $r['DB'], 'segundos' => (int)$r['TIME'], 'comando' => $r['COMMAND'],
        'estado' => $r['STATE'], 'sql' => $corta($r['INFO'] ?? '', 400)],
        $is->query("SELECT ID, DB, TIME, COMMAND, STATE, INFO FROM PROCESSLIST WHERE COMMAND <> 'Sleep' AND TIME >= 30 AND ID <> CONNECTION_ID() ORDER BY TIME DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC));
    $rel['conexoes_por_banco'] = $is->query("SELECT COALESCE(DB, '(nenhum)') AS banco, COMMAND, COUNT(*) AS qtd, MAX(TIME) AS maior_tempo FROM PROCESSLIST GROUP BY 1, 2 ORDER BY qtd DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
    try {
        $rel['transacoes_abertas'] = array_map(static fn($r) => ['thread' => $r['trx_mysql_thread_id'], 'desde' => $r['trx_started'], 'estado' => $r['trx_state'],
            'esperando_desde' => $r['trx_wait_started'], 'linhas_bloqueadas' => (int)$r['trx_rows_locked'], 'sql' => $corta($r['trx_query'] ?? '', 300)],
            $is->query("SELECT trx_mysql_thread_id, trx_started, trx_state, trx_wait_started, trx_rows_locked, trx_query FROM INNODB_TRX ORDER BY trx_started LIMIT 30")->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { $rel['transacoes_abertas'] = 'sem acesso: ' . $e->getMessage(); }
} catch (Throwable $e) { $rel['erro_information_schema'] = $e->getMessage(); }

try { $bancos = (new WindowsServiceRuntime($env, 'ia', true))->databases(null); } catch (Throwable $e) { $bancos = []; $rel['erro_bancos'] = $e->getMessage(); }
foreach ($bancos as $banco) {
    $r = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $q = static function (string $sql) use ($pdo): array { $s = $pdo->query($sql); $x = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $x; };
        $r['ultima_por_servico'] = $q("SELECT servico, MAX(fim_em) AS ultima_execucao, TIMESTAMPDIFF(MINUTE, MAX(fim_em), NOW()) AS minutos_sem_executar,
                SUM(status = 'ERRO' AND fim_em >= NOW() - INTERVAL 6 HOUR) AS erros_6h, SUM(fim_em >= NOW() - INTERVAL 6 HOUR) AS execucoes_6h
              FROM green_service_runs WHERE fim_em >= NOW() - INTERVAL 3 DAY GROUP BY servico ORDER BY servico");
        $erros = $q("SELECT servico, status, inicio_em, fim_em, detalhes FROM green_service_runs WHERE status = 'ERRO' AND fim_em >= NOW() - INTERVAL 12 HOUR ORDER BY fim_em DESC LIMIT 15");
        $r['erros_12h'] = array_map(static fn($x) => ['servico' => $x['servico'], 'inicio' => $x['inicio_em'], 'fim' => $x['fim_em'], 'detalhes' => $corta($x['detalhes'], 500)], $erros);
        $r['execucoes_ia_hoje'] = $q("SELECT inicio_em, fim_em, TIMESTAMPDIFF(SECOND, inicio_em, fim_em) AS segundos, status, quantidade FROM green_service_runs
              WHERE servico IN ('ia', 'ocr') AND fim_em >= CURDATE() ORDER BY fim_em DESC LIMIT 12");
        foreach (['vw_green_ocr_jobs' => 'fila_ocr', 'vw_green_ia_document_jobs' => 'fila_ia'] as $v => $k) {
            try { $r[$k] = $q("SELECT status, COUNT(*) AS qtd FROM `$v` GROUP BY status"); } catch (Throwable $e) { $r[$k] = 'sem a view'; }
        }
    } catch (Throwable $e) { $r['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $r;
}

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$logs = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logs)) @mkdir($logs, 0775, true);
@file_put_contents($logs . DIRECTORY_SEPARATOR . 'diagnostico_servicos_travados.json', $json);
echo $json . PHP_EOL;
