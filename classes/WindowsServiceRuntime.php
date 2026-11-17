<?php
declare(strict_types=1);

/** Runtime comum para workers PHP executados pelo Agendador do Windows. */
final class WindowsServiceRuntime
{
    public function __construct(private readonly array $env, private readonly string $service, private readonly bool $dryRun = false) {}

    public function databases(?string $database = null): array
    {
        $configured = array_values(array_filter(array_map('trim', explode(',', (string)($this->env['TASK_WORKER_DATABASES'] ?? '')))));
        if ($database !== null) {
            $list = [$database];
        } elseif ($configured) {
            $list = $configured;
        } else {
            $list = [];
            try {
                $universal = GreenPortalDatabase::connect($this->env, (string)($this->env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
                $q = $universal->query("SELECT DISTINCT banco_novo FROM empresa_migracao_config WHERE banco_novo IS NOT NULL AND banco_novo <> '' AND status_migracao <> 'EM_CONSTRUCAO' ORDER BY banco_novo");
                $candidatos = array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
                if ($candidatos) {
                    $info = GreenPortalDatabase::connect($this->env, 'information_schema', 'GPT');
                    $placeholders = implode(',', array_fill(0, count($candidatos), '?'));
                    $s = $info->prepare("SELECT SCHEMA_NAME FROM SCHEMATA WHERE SCHEMA_NAME IN ({$placeholders})");
                    $s->execute($candidatos);
                    $existentes = array_flip(array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN)));
                    $list = array_values(array_filter($candidatos, static fn(string $db): bool => isset($existentes[$db])));
                }
            } catch (Throwable) {
                $list = ['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'];
            }
        }
        $list = array_values(array_unique($list));
        foreach ($list as $db) {
            if (!preg_match('/^g[a-z0-9_]+_dbp$/i', $db)) {
                throw new InvalidArgumentException('Banco não permitido: ' . $db);
            }
        }
        return $list;
    }

    public function run(PDO $pdo, string $database, callable $handler): array
    {
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime';
        if (!is_dir($dir)) @mkdir($dir, 0770, true);
        $lock = fopen($dir . DIRECTORY_SEPARATOR . 'green-' . $this->service . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['status' => 'IGNORADO', 'quantidade' => 0, 'motivo' => 'execucao_em_andamento'];
        }
        // Início pelo relógio do MySQL (c584): o PHP dos serviços está em UTC e o fim já era gravado pelo MySQL
        try { $started = (string)$pdo->query('SELECT NOW()')->fetchColumn(); } catch (Throwable) { $started = date('Y-m-d H:i:s'); }
        // Disjuntor por empresa (c584): 3 erros seguidos nesta empresa deixam o serviço sem rodar nela por 30 minutos; as outras seguem
        $suspensa = $this->disjuntor($database, null);
        if ($suspensa !== null) {
            flock($lock, LOCK_UN);
            fclose($lock);
            return ['status' => 'IGNORADO', 'quantidade' => 0, 'motivo' => 'empresa_suspensa', 'ate' => $suspensa];
        }
        $status = 'CONCLUIDA';
        $quantity = 0;
        $details = [];
        try {
            if (!$this->dryRun) $quantity = (int)$handler($pdo);
            else $details['dry_run'] = true;
        } catch (Throwable $e) {
            $status = 'ERRO';
            $details['erro'] = $e->getMessage();
        } finally {
            $q = $pdo->prepare('INSERT INTO green_service_runs (servico,banco_dados,status,inicio_em,fim_em,quantidade,detalhes) VALUES (:servico,:banco,:status,:inicio,CURRENT_TIMESTAMP,:quantidade,:detalhes)');
            $q->execute(['servico' => $this->service, 'banco' => $database, 'status' => $status, 'inicio' => $started, 'quantidade' => $quantity, 'detalhes' => json_encode($details, JSON_UNESCAPED_UNICODE)]);
            if (!$this->dryRun) $this->disjuntor($database, $status === 'ERRO');
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return ['status' => $status, 'quantidade' => $quantity, 'detalhes' => $details];
    }

    /**
     * Disjuntor por empresa, em storage/runtime/disjuntor-<serviço>.json (só é lido e gravado com a trava do serviço).
     * $falhou null: só consulta — devolve até quando a empresa está suspensa, ou null. true/false: registra o resultado.
     */
    private function disjuntor(string $database, ?bool $falhou): ?string
    {
        $arquivo = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'disjuntor-' . preg_replace('/[^a-z0-9_-]/i', '', $this->service) . '.json';
        $dados = is_file($arquivo) ? (json_decode((string)@file_get_contents($arquivo), true) ?: []) : [];
        $agora = time();
        $item = $dados[$database] ?? ['falhas' => 0, 'ate' => 0];
        if ($falhou === null) return (int)$item['ate'] > $agora ? date('c', (int)$item['ate']) : null;
        if ($falhou) {
            $item['falhas'] = (int)$item['falhas'] + 1;
            if ($item['falhas'] >= 3) { $item['ate'] = $agora + 1800; $item['falhas'] = 0; }
            $dados[$database] = $item;
        } elseif (isset($dados[$database])) {
            unset($dados[$database]);
        } else {
            return null;
        }
        @file_put_contents($arquivo, json_encode($dados, JSON_PRETTY_PRINT), LOCK_EX);
        return null;
    }

    public function dryRun(): bool { return $this->dryRun; }
}
