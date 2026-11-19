<?php
declare(strict_types=1);

/*
 * Serviço do sincronismo legado -> greenportal (Administração › Migração › Sincronismo; migração 305).
 * Agendador do Windows: \GreenSolutions\GreenPortal-SincronismoWorker, a cada minuto, como SYSTEM
 * (scripts\criar-agendamento-sincronismo.ps1). Substitui a tarefa diária fixa das 05:00 (sincronizar-fo-legado.bat).
 *
 * Revisão c566: o agendamento diário simula primeiro; até o limite de exclusões a aplicação entra na fila sozinha, acima dele
 * o serviço para e avisa o ADMINISTRADOR (sp_sinc_agendado_continuar).
 * A cada ciclo:
 *   1. pega o bloqueio do serviço (um só por vez; se outro ciclo ainda está rodando, sai);
 *   2. marca o ciclo e, se chegou o horário do dia (sincronismo_config), coloca o sincronismo agendado na fila;
 *   3. executa a fila em ordem: scripts\sincronizar-fo-legado.php com --aplicar / --validar / simulação e --empresa=;
 *      o relatório de cada execução fica em storage\logs\sincronismo\execucao_<n>.json e o resumo na tela.
 * Acesso ao banco só pelas procedures sp_sinc_* do guniversal_dbp.
 *
 * Uso manual (teste): E:\wamp\bin\php\php8.4.15\php.exe scripts\sincronismo-worker.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/AssistenteAplicacaoService.php';

@set_time_limit(0);
date_default_timezone_set('America/Sao_Paulo');
$raiz = dirname(__DIR__);
$dirLog = $raiz . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'sincronismo';
if (!is_dir($dirLog)) @mkdir($dirLog, 0775, true);
$log = static function (string $msg) use ($dirLog): void { @file_put_contents($dirLog . DIRECTORY_SEPARATOR . 'worker.log', '[' . date('Y-m-d H:i:s') . "] $msg" . PHP_EOL, FILE_APPEND); };

$env = GreenPortalDatabase::env();
$central = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$central->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$call = static fn(string $p, array $a = []): array => AssistenteAplicacaoService::chamarProcedure($central, $p, $a);

if ((int)$central->query("SELECT GET_LOCK('greenportal_sincronismo_worker', 0)")->fetchColumn() !== 1) exit(0);
try { $call('sp_sinc_worker_agendar'); }
catch (Throwable $e) { $log('ERRO ao ler o agendamento (a migração 305 foi aplicada?): ' . $e->getMessage()); exit(1); }

// Limpeza: relatórios das execuções com mais de 90 dias e os do sincronizar-fo-legado.php com mais de 30
foreach ([[$dirLog . DIRECTORY_SEPARATOR . 'execucao_*.json', 90], [dirname($dirLog) . DIRECTORY_SEPARATOR . 'sincronismo_*.json', 30]] as [$padrao, $dias])
    foreach (glob($padrao) ?: [] as $f) if (@filemtime($f) < time() - $dias * 86400) @unlink($f);

/** Resumo para a tela a partir do relatório do sincronizar-fo-legado.php. */
function resumoSincronismo(array $j, string $modo): array
{
    $r = ['bancos' => [], 'totais' => ['incluidos' => 0, 'alterados' => 0, 'excluidos' => 0, 'legal_afetados' => 0, 'legal_excluidos' => 0], 'validacao' => null];
    $div = 0; $okVal = 0;
    if ($modo === 'VALIDAR') {
        foreach ((array)($j['bancos'] ?? []) as $b => $v) {
            $st = (string)($v['status'] ?? ($v['erro'] ?? ''));
            $r['bancos'][$b] = ['validacao' => $st];
            str_starts_with($st, 'OK') ? $okVal++ : $div++;
        }
    } else {
        foreach ((array)($j['bancos'] ?? []) as $b => $x) {
            $t = (array)($x['totais'] ?? []);
            $m = (array)($j['mapeadas'][$b]['totais'] ?? []);
            $l = (array)($j['legal_empresas'][$b]['totais'] ?? []);
            $item = ['incluidos' => (int)($t['incluidos'] ?? 0) + (int)($m['incluidos'] ?? 0), 'alterados' => (int)($t['alterados'] ?? 0) + (int)($m['alterados'] ?? 0),
                'excluidos' => (int)($t['excluidos'] ?? 0) + (int)($m['excluidos'] ?? 0), 'legal_afetados' => (int)($l['afetados'] ?? 0), 'legal_excluidos' => (int)($l['excluidos'] ?? 0),
                'ignorados' => (int)($t['ignorados_dependencia'] ?? 0) + (int)($t['ignorados_duplicidade'] ?? 0)];
            if (isset($x['erro'])) $item['erro'] = (string)$x['erro'];
            if (isset($j['validacao'][$b])) { $st = (string)($j['validacao'][$b]['status'] ?? ($j['validacao'][$b]['erro'] ?? '')); $item['validacao'] = $st; str_starts_with($st, 'OK') ? $okVal++ : $div++; }
            foreach ($r['totais'] as $k => $_) $r['totais'][$k] += $item[$k];
            $r['bancos'][$b] = $item;
        }
        $c = (array)($j['legislacao_central']['totais'] ?? []);
        $r['legislacao_central'] = isset($j['legislacao_central']['erro']) ? ['erro' => (string)$j['legislacao_central']['erro']]
            : ['afetados' => (int)($c['afetados'] ?? 0), 'excluidos' => (int)($c['excluidos'] ?? 0)];
        if (isset($j['universal'])) { $un = (array)($j['universal']['totais'] ?? []); $r['universal'] = isset($j['universal']['erro']) ? ['erro' => (string)$j['universal']['erro']] : ['afetados' => (int)($un['afetados'] ?? 0), 'excluidos' => (int)($un['excluidos'] ?? 0)]; }
        if (!empty($j['fora_da_execucao'])) $r['fora_da_execucao'] = $j['fora_da_execucao'];
    }
    if ($okVal + $div > 0) $r['validacao'] = $div ? "DIVERGENTE em $div banco(s)" : 'OK';
    return $r;
}

/** Alerta aos ADMINISTRADOR ativos das empresas com exclusões (ou de todas da simulação, se só a legislação central excluiu). */
function avisarAdministradores(array $env, array $resumo, int $id, int $exclusoes, int $limite, callable $log): void
{
    $bancos = array_keys(array_filter((array)$resumo['bancos'], static fn($b) => (int)($b['excluidos'] ?? 0) + (int)($b['legal_excluidos'] ?? 0) > 0)) ?: array_keys((array)$resumo['bancos']);
    $msg = "O sincronismo agendado de hoje parou na simulação #$id: $exclusoes exclusão(ões) no greenportal, acima do limite de $limite. Nada foi gravado. "
        . 'Abra Administração › Migração › Sincronismo, confira o relatório da simulação e, se estiver certo, clique em Aplicar.';
    foreach ($bancos as $banco) {
        if (!preg_match('/^g[a-z0-9_]+_dbp$/i', (string)$banco)) continue;
        try {
            $pdo = GreenPortalDatabase::connect($env, (string)$banco, 'GPT');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $adm = $pdo->query("SELECT u.codusuario FROM usuarios u JOIN perfis p ON p.codperfil = u.codperfil WHERE COALESCE(u.ativo, 0) = 1 AND UPPER(TRIM(p.codigo)) = 'ADMINISTRADOR'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($adm as $u) { $q = $pdo->prepare("CALL sp_alerta_criar(?, ?, ?, ?, 'NAO')"); $q->execute([(int)$u, 'Sincronismo agendado parado — revisar antes de aplicar', $msg, '/principal/administracao_sincronismo.php']); $q->closeCursor(); }
        } catch (Throwable $e) { $log("aviso: não foi possível alertar os administradores de $banco: " . $e->getMessage()); }
    }
}

$executadas = 0;
while (true) {
    try { $call('sp_sinc_worker_agendar'); } catch (Throwable) {}
    $ex = $call('sp_sinc_worker_reivindicar')[0] ?? null;
    if (!$ex) break;
    $id = (int)$ex['codexecucao']; $modo = (string)$ex['modo']; $empresa = (string)($ex['empresa'] ?? '');
    $saida = $dirLog . DIRECTORY_SEPARATOR . 'execucao_' . $id . '.json';
    $erros = $dirLog . DIRECTORY_SEPARATOR . 'execucao_' . $id . '.stderr.log';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($raiz . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'sincronizar-fo-legado.php')
        . ($modo === 'APLICAR' ? ' --aplicar' : ($modo === 'VALIDAR' ? ' --validar' : ''))
        . ($empresa !== '' && preg_match('/^[a-z0-9_]+$/', $empresa) ? ' --empresa=' . $empresa : '');
    $log("execução #$id ($ex[origem] $modo " . ($empresa ?: 'todas') . ') iniciada');
    $status = 'ERRO'; $resumo = null; $erro = null; $bancos = [];
    try {
        $h = fopen($saida, 'wb'); $eh = fopen($erros, 'wb');
        if (!$h || !$eh) throw new RuntimeException('não foi possível criar o arquivo do relatório em ' . $dirLog);
        $proc = proc_open($cmd, [1 => $h, 2 => $eh], $pipes, $raiz);
        fclose($h); fclose($eh);
        if (!is_resource($proc)) throw new RuntimeException('não foi possível iniciar o sincronismo');
        // Espera o fim mantendo a conexão viva (o sincronismo pode levar horas; a conexão segura o bloqueio do serviço)
        $ultimo = time(); $codigo = -1;
        while (true) {
            $st = proc_get_status($proc);
            if (!$st['running']) { $codigo = (int)$st['exitcode']; break; }
            if (time() - $ultimo >= 60) { try { $central->query('SELECT 1')->fetchAll(); } catch (Throwable) {} $ultimo = time(); }
            usleep(500000);
        }
        $r = proc_close($proc); if ($codigo < 0) $codigo = (int)$r;
        $texto = (string)@file_get_contents($saida);
        $p = strpos($texto, '{');
        $j = $p === false ? null : json_decode(substr($texto, $p), true);
        $stderr = trim((string)@file_get_contents($erros));
        if (!is_array($j)) {
            // Erro fatal do PHP sai na saída padrão (display_errors do CLI): mostra o começo dele na tela
            $fatal = trim((string)preg_replace('/\s+/', ' ', mb_substr($texto, 0, 1200)));
            throw new RuntimeException('o sincronismo terminou sem relatório (código ' . $codigo . ')' . ($stderr !== '' ? ': ' . mb_substr($stderr, 0, 1500) : ($fatal !== '' ? ': ' . $fatal : '')));
        }
        $resumo = resumoSincronismo($j, $modo);
        $bancos = array_keys($resumo['bancos']);
        if (!$bancos && $empresa !== '' && !in_array($empresa, ['legislacao', 'universal'], true)) throw new RuntimeException("a empresa \"$empresa\" não entrou no sincronismo (sem migração concluída ou sem tabelas fo_ no legado)");
        if ($modo === 'VALIDAR') { $status = str_starts_with((string)$resumo['validacao'], 'DIVERGENTE') ? 'CONCLUIDA_COM_ERRO' : 'CONCLUIDA'; if ($status !== 'CONCLUIDA') $erro = 'Validação: ' . $resumo['validacao']; }
        else {
            $status = !empty($j['ok']) ? 'CONCLUIDA' : 'CONCLUIDA_COM_ERRO';
            if ($status !== 'CONCLUIDA') {
                $msgs = [];
                foreach ($resumo['bancos'] as $b => $x) if (isset($x['erro'])) $msgs[] = "$b: " . $x['erro'];
                if (isset($resumo['legislacao_central']['erro'])) $msgs[] = 'legislação central: ' . $resumo['legislacao_central']['erro'];
                if (isset($resumo['universal']['erro'])) $msgs[] = 'universal: ' . $resumo['universal']['erro'];
                foreach ((array)($j['usuarios'] ?? []) as $b => $x) if (isset($x['erro'])) $msgs[] = "$b usuários: " . $x['erro'];
                $erro = mb_substr(implode(' | ', $msgs ?: ['algum banco terminou com erro; veja o relatório']), 0, 4000);
            }
        }
        if ($stderr === '') @unlink($erros);
    } catch (Throwable $e) { $status = 'ERRO'; $erro = $e->getMessage(); }
    try {
        $call('sp_sinc_worker_finalizar', [$id, $status, is_file($saida) ? $saida : null, $resumo !== null ? json_encode($resumo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null, $erro,
            ($modo === 'APLICAR' && $status !== 'ERRO') ? json_encode($bancos) : null]);
    } catch (Throwable $e) { $log("ERRO ao gravar o fim da execução #$id: " . $e->getMessage()); }
    $log("execução #$id terminou: $status" . ($erro ? " — $erro" : ''));
    // Revisão c566 (decisão de 24/10/2026): a simulação do agendamento diário decide se aplica — até o limite de exclusões aplica
    // (nova execução AGENDADO/APLICAR a partir desta simulação); acima, para e avisa o ADMINISTRADOR de cada empresa com exclusões.
    if ((string)$ex['origem'] === 'AGENDADO' && $modo === 'SIMULAR' && $status !== 'ERRO' && is_array($resumo)) {
        try {
            $exclusoes = (int)($resumo['totais']['excluidos'] ?? 0) + (int)($resumo['totais']['legal_excluidos'] ?? 0) + (int)($resumo['legislacao_central']['excluidos'] ?? 0) + (int)($resumo['universal']['excluidos'] ?? 0);
            $dec = $call('sp_sinc_agendado_continuar', [$id, $exclusoes])[0] ?? [];
            $log("simulação agendada #$id: $exclusoes exclusão(ões), limite " . ($dec['limite'] ?? '?') . ' → ' . ($dec['acao'] ?? '?') . (!empty($dec['codexecucao']) ? ' #' . $dec['codexecucao'] : ''));
            if (($dec['acao'] ?? '') === 'PARADO') avisarAdministradores($env, $resumo, $id, $exclusoes, (int)($dec['limite'] ?? 0), $log);
        } catch (Throwable $e) { $log("ERRO ao decidir a aplicação da simulação agendada #$id: " . $e->getMessage()); }
    }
    $executadas++;
}
$central->query("SELECT RELEASE_LOCK('greenportal_sincronismo_worker')")->fetchAll();
exit(0);
