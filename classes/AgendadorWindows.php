<?php
declare(strict_types=1);

/**
 * Leitura da situação das tarefas do Agendador do Windows (pasta \GreenSolutions\).
 * Primeiro pelo COM (Schedule.Service, sem abrir processo); se não der, pelo schtasks.exe com tempo máximo.
 * Usada pelo monitor de serviços e pelo vigia do orquestrador (migração 353). Tirada de scripts/service-monitor.php.
 */
final class AgendadorWindows
{
    /** Tarefas do orquestrador: nome => trilha (0 = vigia). */
    public const TAREFAS_ORQUESTRADOR = [
        'GreenPortal-Orquestrador-T1' => 1,
        'GreenPortal-Orquestrador-T2' => 2,
        'GreenPortal-Orquestrador-T3' => 3,
        'GreenPortal-Orquestrador-Vigia' => 0,
    ];

    /**
     * @param string[] $nomes
     * @return array<string, array{TaskName:string,TaskPath:string,State:string,LastRunTime:?string,NextRunTime:?string,LastTaskResult:int}>
     *         só as tarefas encontradas; as ausentes não aparecem (NAO_INSTALADO para quem chamou)
     */
    public static function situacao(array $nomes, string $pasta = '\\GreenSolutions\\'): array
    {
        $erroCom = '';
        try {
            $linhas = self::situacaoCom($pasta, $nomes);
            if (count($linhas) > 0 || count($nomes) === 0) return $linhas;
        } catch (Throwable $e) {
            $erroCom = self::erroAgendador($e->getMessage());
        }
        $linhas = [];
        [$saida, $erro, $codigo] = self::comando(['schtasks.exe', '/Query', '/FO', 'CSV', '/NH', '/V'], 15);
        $msg = self::limparErro($erro !== '' ? $erro : $saida);
        if ($codigo !== 0 || preg_match('/access denied|acesso negado|permission denied|cannot find the path|n[aã]o foi poss[ií]vel localizar o caminho/i', $msg)) {
            throw new RuntimeException($erroCom !== '' ? $erroCom : ($msg ?: 'Acesso negado ao Agendador.'));
        }
        foreach (preg_split('/\R/', trim($saida)) ?: [] as $linha) {
            $c = str_getcsv($linha);
            if (count($c) < 6) continue;
            $completo = str_replace('/', '\\', trim((string)$c[0], " \t\r\n\""));
            foreach ($nomes as $nome) {
                if (!str_ends_with($completo, '\\' . $nome)) continue;
                $linhas[$nome] = [
                    'TaskName' => $nome,
                    'TaskPath' => str_contains($completo, '\\') ? substr($completo, 0, strrpos($completo, '\\') + 1) : $pasta,
                    'State' => self::estadoTexto((string)($c[2] ?? '')),
                    'LastRunTime' => self::data((string)($c[4] ?? '')),
                    'NextRunTime' => self::data((string)($c[1] ?? '')),
                    'LastTaskResult' => self::resultado((string)($c[5] ?? '')),
                ];
                break;
            }
        }
        // Nenhuma encontrada costuma ser leitura que falhou (idioma, permissão), não as tarefas apagadas: não grava "não encontrada".
        // Se as tarefas sumirem de fato, as trilhas param e o aviso de "sem sinal de vida" cobre.
        if (count($linhas) === 0 && count($nomes) > 0) throw new RuntimeException('Nenhuma tarefa GreenPortal foi localizada pelo Agendador. Confira se foram registradas como SYSTEM.' . ($erroCom !== '' ? ' (COM: ' . $erroCom . ')' : ''));
        return $linhas;
    }

    private static function situacaoCom(string $pasta, array $nomes): array
    {
        if (!class_exists('COM')) throw new RuntimeException('A extensão COM do PHP não está disponível.');
        $servico = new COM('Schedule.Service');
        $servico->Connect();
        $tarefas = $servico->GetFolder('\\' . trim($pasta, '\\'))->GetTasks(1);
        $quero = array_fill_keys($nomes, true);
        $linhas = [];
        for ($i = 1; $i <= (int)$tarefas->Count; $i++) {
            $t = $tarefas->Item($i);
            $nome = trim((string)$t->Name);
            if (!isset($quero[$nome])) continue;
            $linhas[$nome] = [
                'TaskName' => $nome,
                'TaskPath' => (string)$t->Path,
                'State' => match ((int)$t->State) { 1 => 'DISABLED', 2 => 'QUEUED', 3 => 'READY', 4 => 'RUNNING', default => 'DESCONHECIDO' },
                'LastRunTime' => self::dataCom($t->LastRunTime),
                'NextRunTime' => self::dataCom($t->NextRunTime),
                'LastTaskResult' => (int)$t->LastTaskResult,
            ];
        }
        unset($tarefas, $servico);
        return $linhas;
    }

    /** @return array{0:string,1:string,2:int} saída, erro, código de saída */
    private static function comando(array $cmd, int $segundos): array
    {
        $pipes = [];
        $p = @proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true, 'create_process_group' => true]);
        if (!is_resource($p)) throw new RuntimeException('Não foi possível iniciar a consulta do Agendador.');
        if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
        foreach ([1, 2] as $i) if (isset($pipes[$i]) && is_resource($pipes[$i])) stream_set_blocking($pipes[$i], false);
        $out = ''; $err = ''; $fim = microtime(true) + max(1, $segundos); $st = ['running' => true];
        do {
            if (isset($pipes[1]) && is_resource($pipes[1])) $out .= (string)stream_get_contents($pipes[1]);
            if (isset($pipes[2]) && is_resource($pipes[2])) $err .= (string)stream_get_contents($pipes[2]);
            $st = proc_get_status($p);
            if (!$st['running']) break;
            usleep(50000);
        } while (microtime(true) < $fim);
        if (($st['running'] ?? false) === true) {
            $pid = (int)($st['pid'] ?? 0);
            @proc_terminate($p);
            if ($pid > 0) @exec('taskkill.exe /PID ' . $pid . ' /T /F 2>NUL');
            foreach ([1, 2] as $i) if (isset($pipes[$i]) && is_resource($pipes[$i])) @fclose($pipes[$i]);
            @proc_close($p);
            throw new RuntimeException('A consulta do Agendador passou de ' . $segundos . ' segundos.');
        }
        if (isset($pipes[1]) && is_resource($pipes[1])) $out .= (string)stream_get_contents($pipes[1]);
        if (isset($pipes[2]) && is_resource($pipes[2])) $err .= (string)stream_get_contents($pipes[2]);
        foreach ([1, 2] as $i) if (isset($pipes[$i]) && is_resource($pipes[$i])) fclose($pipes[$i]);
        return [trim($out), trim($err), proc_close($p)];
    }

    private static function limparErro(string $txt): string
    {
        $txt = html_entity_decode(trim($txt), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $txt = preg_replace('/_x[0-9A-F]{4}_/i', ' ', strip_tags($txt)) ?: '';
        return trim((string)preg_replace('/\s+/', ' ', $txt));
    }

    private static function erroAgendador(string $txt): string
    {
        $txt = self::limparErro($txt);
        if ($txt === '' || preg_match('/Source:\s*Unknown|Description:\s*Unknown/i', $txt)) {
            return 'Não foi possível acessar o Agendador do Windows neste contexto. Rode como SYSTEM ou confira as tarefas num PowerShell como Administrador.';
        }
        return $txt;
    }

    private static function estadoTexto(string $v): string
    {
        $v = mb_strtolower(trim($v), 'UTF-8');
        if (str_contains($v, 'running') || str_contains($v, 'execu')) return 'RUNNING';
        if (str_contains($v, 'ready') || str_contains($v, 'pronto')) return 'READY';
        if (str_contains($v, 'disabled') || str_contains($v, 'desabil') || str_contains($v, 'desativ')) return 'DISABLED';
        return strtoupper(trim($v)) ?: 'DESCONHECIDO';
    }

    private static function resultado(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-' || strcasecmp($v, 'N/A') === 0) return -1;
        if (str_starts_with(strtolower($v), '0x')) return (int)hexdec(substr($v, 2));
        return (int)$v;
    }

    private static function data(string $v): ?string
    {
        $v = trim($v, " \t\r\n\"\xEF\xBB\xBF");
        if ($v === '' || preg_match('/^(N\/A|Never|Nunca|Not Scheduled|Nao agendada|Não agendada)$/i', $v)) return null;
        foreach (['d/m/Y H:i:s', 'm/d/Y h:i:s A', 'm/d/Y H:i:s', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s.uP', 'Y-m-d\TH:i:sP'] as $f) {
            $d = DateTimeImmutable::createFromFormat($f, $v);
            if ($d instanceof DateTimeImmutable) return $d->format('Y-m-d H:i:s');
        }
        try { return (new DateTimeImmutable($v))->format('Y-m-d H:i:s'); } catch (Throwable) { return null; }
    }

    private static function dataCom(mixed $v): ?string
    {
        if ($v instanceof DateTimeInterface) return $v->format('Y-m-d H:i:s');
        $d = self::data((string)$v);
        // O COM devolve 30/12/1899 para "nunca rodou"
        return ($d !== null && str_starts_with($d, '1899-')) ? null : $d;
    }
}
