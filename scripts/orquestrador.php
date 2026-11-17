<?php
declare(strict_types=1);

/*
 * Orquestrador dos serviços do GreenPortal (migração 352; decisão do usuário de 28/10/2026).
 * Roda SÓ pelo Agendador do Windows, uma tarefa por trilha (scripts\criar-agendamento-orquestrador.ps1):
 *   \GreenSolutions\GreenPortal-Orquestrador-T1  a cada minuto    — rápida: Exportação Excel → Visualizador
 *   \GreenSolutions\GreenPortal-Orquestrador-T2  a cada minuto    — filas e regras: OCR → IA → Agenda → Responsabilidades → …
 *   \GreenSolutions\GreenPortal-Orquestrador-T3  a cada 5 minutos — pesada, um por vez: Sincronismo → Busca → Ranking → Migração → Backup
 * Cada ciclo de uma trilha:
 *   1. pega a trava da trilha (storage\runtime\green-orquestrador-t<N>.lock); se o ciclo anterior ainda roda, sai;
 *   2. registra o sinal de vida (orq_trilhas) e encerra o que ficou do ciclo anterior que morreu (processo órfão);
 *   3. roda, na ordem do cadastro (orq_servicos), cada serviço que está na hora — como processo filho, com tempo máximo:
 *      passou do tempo, o processo (e o que ele abriu) é encerrado e a execução fica TEMPO_ESGOTADO;
 *      3 falhas seguidas suspendem o serviço por 30 minutos (as outras seguem normalmente).
 * Modo da trilha SIMULAR: só registra o que rodaria (para conferir antes de desligar as tarefas antigas).
 * Saída de cada serviço em storage\logs\orquestrador\<CODIGO>.log; resumo em storage\logs\orquestrador\trilha<N>-<AAAAMM>.log.
 * Acesso ao banco só pelas procedures sp_orq_* do universal.
 *
 * Uso:
 *   php scripts\orquestrador.php --trilha=2                     um ciclo da trilha (o que o Agendador executa)
 *   php scripts\orquestrador.php --trilha=2 --servico=AGENDA_WORKER   roda esse serviço agora (fora do horário, mesmo em SIMULAR)
 *   php scripts\orquestrador.php --situacao                     mostra o cadastro e a última execução de cada serviço
 *   php scripts\orquestrador.php --modo=EXECUTAR [--trilha=1]   muda o modo (todas as trilhas, se não informar)
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

date_default_timezone_set('America/Sao_Paulo');
@set_time_limit(0);

$opcoes = [];
foreach (array_slice($argv ?? [], 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) $opcoes[$m[1]] = $m[2] ?? '';
}
$trilha = isset($opcoes['trilha']) ? (int)$opcoes['trilha'] : 0;
$raiz = dirname(__DIR__);
$windows = DIRECTORY_SEPARATOR === '\\';
$dirLog = $raiz . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'orquestrador';
$dirRuntime = $raiz . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime';
foreach ([$dirLog, $dirRuntime] as $d) if (!is_dir($d)) @mkdir($d, 0770, true);
$resumo = static function (string $texto) use ($dirLog, $trilha): void {
    @file_put_contents($dirLog . DIRECTORY_SEPARATOR . 'trilha' . $trilha . '-' . date('Ym') . '.log', date('Y-m-d H:i:s') . ' ' . $texto . PHP_EOL, FILE_APPEND);
};

$env = GreenPortalDatabase::env();
try {
    $u = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
} catch (Throwable $e) { $resumo('ERRO conexão com o universal: ' . $e->getMessage()); fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
$call = static function (string $sql, array $p = []) use ($u): array {
    $st = $u->prepare($sql); $st->execute($p);
    $r = [];
    do { try { $x = $st->fetchAll(PDO::FETCH_ASSOC); if ($x && !$r) $r = $x; } catch (Throwable) {} } while ($st->nextRowset());
    $st->closeCursor();
    return $r;
};
$json = static fn($v): string => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;

// ---------------------------------------------------------------- comandos de manutenção
if (isset($opcoes['modo'])) {
    echo $json($call('CALL sp_orq_modo(?, ?)', [$trilha, strtoupper($opcoes['modo'])]));
    exit(0);
}
if (isset($opcoes['situacao'])) {
    echo $json($call('CALL sp_orq_situacao()'));
    exit(0);
}
if (!in_array($trilha, [1, 2, 3], true)) { fwrite(STDERR, "Informe --trilha=1, 2 ou 3.\n"); exit(1); }

// ---------------------------------------------------------------- processos (Windows e, nos testes, Linux)
/** Nome do programa do processo, ou null se ele não existe mais. */
function orqProcesso(int $pid): ?string
{
    if ($pid <= 0) return null;
    if (DIRECTORY_SEPARATOR === '\\') {
        $saida = [];
        @exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL', $saida);
        foreach ($saida as $linha) {
            $c = str_getcsv($linha);
            if (count($c) >= 2 && (int)$c[1] === $pid) return strtolower(trim($c[0]));
        }
        return null;
    }
    $cmd = @file_get_contents("/proc/$pid/comm");
    return $cmd === false ? null : strtolower(trim($cmd));
}

/** Encerra o processo e tudo o que ele abriu (LibreOffice, mysqldump, sincronizar-fo-legado...). */
function orqEncerrar(int $pid): void
{
    if ($pid <= 0) return;
    if (DIRECTORY_SEPARATOR === '\\') { @exec('taskkill /PID ' . $pid . ' /T /F 2>NUL'); return; }
    @exec('pkill -KILL -P ' . $pid . ' 2>/dev/null; kill -KILL ' . $pid . ' 2>/dev/null');
}

// ---------------------------------------------------------------- ciclo da trilha
$trava = fopen($dirRuntime . DIRECTORY_SEPARATOR . 'green-orquestrador-t' . $trilha . '.lock', 'c');
if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) exit(0);   // o ciclo anterior desta trilha ainda está rodando

$t = $call('CALL sp_orq_ciclo_iniciar(?, ?)', [$trilha, getmypid()])[0] ?? null;
if (!$t) { $resumo('ERRO: trilha não cadastrada (a migração 352 foi aplicada?)'); exit(1); }
if ((int)$t['ativo'] !== 1) { $call('CALL sp_orq_ciclo_fim(?)', [$trilha]); exit(0); }
$forcado = isset($opcoes['servico']) ? strtoupper(trim($opcoes['servico'])) : null;
$simulando = $t['modo'] !== 'EXECUTAR' && $forcado === null;

// O que ficou EXECUTANDO é de um ciclo que morreu (nós temos a trava): encerra o processo, se ainda vivo, e registra
foreach ($call('CALL sp_orq_orfaos(?)', [$trilha]) as $o) {
    $pid = (int)($o['pid'] ?? 0);
    $prog = orqProcesso($pid);
    $morto = false;
    if ($prog !== null && str_starts_with($prog, 'php')) { orqEncerrar($pid); $morto = true; }
    $msg = 'O ciclo anterior da trilha parou com o serviço em execução' . ($morto ? "; o processo $pid foi encerrado" : '');
    $call('CALL sp_orq_execucao_finalizar(?, ?, NULL, ?)', [(int)$o['codexecucao'], 'ABANDONADA', $msg]);
    $resumo("{$o['codigo']} ABANDONADA" . ($morto ? " (processo $pid encerrado)" : ''));
}

if ($forcado !== null) {
    $fila = array_values(array_filter($call('CALL sp_orq_situacao()'), static fn($s) => $s['codigo'] === $forcado && (int)$s['trilha'] === $trilha));
    if (!$fila) { fwrite(STDERR, "Serviço $forcado não está na trilha $trilha.\n"); $call('CALL sp_orq_ciclo_fim(?)', [$trilha]); exit(1); }
} else {
    $fila = $call('CALL sp_orq_devidos(?)', [$trilha]);
}

$feitos = [];
foreach ($fila as $s) {
    $codigo = (string)$s['codigo'];
    try {
        $id = (int)($call('CALL sp_orq_execucao_iniciar(?, ?, ?)', [$codigo, $trilha, $simulando ? 1 : 0])[0]['codexecucao'] ?? 0);
    } catch (Throwable $e) { $resumo("$codigo ERRO ao registrar o início: " . $e->getMessage()); continue; }
    if ($simulando) {
        $call('CALL sp_orq_execucao_finalizar(?, ?, NULL, ?)', [$id, 'SIMULADA', 'Modo SIMULAR: rodaria ' . $s['script'] . ($s['argumentos'] ? ' ' . $s['argumentos'] : '')]);
        $feitos[$codigo] = 'SIMULADA';
        continue;
    }
    [$status, $saida, $msg, $segundos] = orqExecutar($s, $id, $trilha, $raiz, $dirLog, $call);
    try {
        $fim = $call('CALL sp_orq_execucao_finalizar(?, ?, ?, ?)', [$id, $status, $saida, $msg])[0] ?? [];
        if (!empty($fim['suspenso_ate']) && $status !== 'CONCLUIDA') $msg .= " | {$fim['falhas_seguidas']} falhas seguidas: suspenso até {$fim['suspenso_ate']}";
    } catch (Throwable $e) { $msg .= ' | ERRO ao registrar o fim: ' . $e->getMessage(); }
    $resumo(sprintf('%s %s %ds%s', $codigo, $status, $segundos, $status === 'CONCLUIDA' ? '' : ' | ' . mb_substr($msg, 0, 500)));
    $feitos[$codigo] = $status;
}
if ($trilha === 3 && !$simulando) { try { $call('CALL sp_orq_limpar(30)'); } catch (Throwable) {} }
try { $call('CALL sp_orq_ciclo_fim(?)', [$trilha]); } catch (Throwable) {}
flock($trava, LOCK_UN);
fclose($trava);
if ($forcado !== null || isset($opcoes['detalhes'])) echo $json(['trilha' => $trilha, 'modo' => $simulando ? 'SIMULAR' : 'EXECUTAR', 'servicos' => $feitos]);
exit(0);

/**
 * Roda um serviço como processo filho (sem shell; usa o mesmo console do orquestrador, então não abre outro conhost),
 * com a saída no log do serviço. Passou do tempo máximo: encerra o processo e os que ele abriu.
 * @return array{0:string,1:?int,2:string,3:int} status, código de saída, mensagem, segundos
 */
function orqExecutar(array $s, int $id, int $trilha, string $raiz, string $dirLog, callable $call): array
{
    $codigo = (string)$s['codigo'];
    $script = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$s['script']);
    if (!is_file($script)) return ['ERRO', null, 'Script não encontrado: ' . $s['script'], 0];
    $cmd = [PHP_BINARY, $script];
    foreach (preg_split('/\s+/', trim((string)($s['argumentos'] ?? ''))) ?: [] as $a) if ($a !== '') $cmd[] = $a;

    $log = $dirLog . DIRECTORY_SEPARATOR . preg_replace('/[^A-Z0-9_]/', '', $codigo) . '.log';
    if (is_file($log) && filesize($log) > 5 * 1048576) @rename($log, $log . '.1');
    @file_put_contents($log, PHP_EOL . '===== ' . date('Y-m-d H:i:s') . " execução $id" . PHP_EOL, FILE_APPEND);
    clearstatcache();
    $antes = is_file($log) ? (int)filesize($log) : 0;
    $nulo = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

    $inicio = time();
    $limite = max(30, (int)$s['limite_seg']);
    $pipes = [];
    $proc = @proc_open($cmd, [0 => ['file', $nulo, 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $raiz, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return ['ERRO', null, 'Não foi possível iniciar o processo', 0];
    $st = proc_get_status($proc);
    $pid = (int)$st['pid'];
    try { $call('CALL sp_orq_execucao_pid(?, ?)', [$id, $pid]); } catch (Throwable) {}

    $saida = null; $status = 'CONCLUIDA'; $sinal = time();
    while (true) {
        $st = proc_get_status($proc);
        if (!$st['running']) { $saida = (int)$st['exitcode']; break; }
        if (time() - $inicio >= $limite) {
            orqEncerrar($pid);
            @proc_terminate($proc, 9);
            $status = 'TEMPO_ESGOTADO';
            break;
        }
        if (time() - $sinal >= 60) { try { $call('CALL sp_orq_batimento(?, ?)', [$trilha, $codigo]); } catch (Throwable) {} $sinal = time(); }
        usleep(250000);
    }
    $r = proc_close($proc);
    if ($saida === null || $saida < 0) $saida = $status === 'TEMPO_ESGOTADO' ? null : $r;
    $segundos = time() - $inicio;
    if ($status === 'CONCLUIDA' && $saida !== 0) $status = 'ERRO';

    $msg = '';
    if ($status !== 'CONCLUIDA') {
        $fim = '';
        clearstatcache();
        $h = @fopen($log, 'rb');
        if ($h) { $tam = (int)filesize($log); $de = max($antes, $tam - 1500); fseek($h, $de); $fim = (string)fread($h, $tam - $de); fclose($h); }
        $fim = trim((string)preg_replace('/\s+/u', ' ', mb_convert_encoding($fim, 'UTF-8', 'UTF-8')));
        $msg = ($status === 'TEMPO_ESGOTADO' ? "Passou do tempo máximo ($limite s): o processo $pid foi encerrado" : "Terminou com o código $saida")
            . ($fim !== '' ? '. Fim da saída: ' . mb_substr($fim, -1200) : '');
    }
    return [$status, $saida, $msg, $segundos];
}
