<?php
declare(strict_types=1);

/**
 * Serviço transversal de tarefas por tenant.
 *
 * A classe não conhece o Agendador do Windows: pode ser chamada pelo worker,
 * por uma API ou por uma transação web. O banco recebido já deve ser o banco
 * operacional do tenant autenticado.
 */
final class TaskService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function enqueue(array $task): array
    {
        $key = trim((string)($task['chave_idempotencia'] ?? ''));
        if ($key === '') {
            throw new InvalidArgumentException('A chave de idempotência da tarefa é obrigatória.');
        }

        $sql = <<<'SQL'
INSERT INTO green_tasks
    (chave_idempotencia, tipo, origem_transacao, origem_entidade, origem_id, codunidade,
     titulo, descricao, prioridade, codusuario_destino, codgrupo_destino,
     data_limite, criado_por, status, disponivel_em)
VALUES
    (:chave, :tipo, :transacao, :entidade, :origem_id, :codunidade, :titulo, :descricao,
     :prioridade, :codusuario, :codgrupo, :data_limite, :criado_por, 'PENDENTE',
     COALESCE(:disponivel_em, CURRENT_TIMESTAMP))
ON DUPLICATE KEY UPDATE codtarefa = LAST_INSERT_ID(codtarefa)
SQL;
        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            'chave' => substr($key, 0, 191),
            'tipo' => substr(strtoupper(trim((string)($task['tipo'] ?? 'GENERICA'))), 0, 50),
            'transacao' => $this->nullableString($task['origem_transacao'] ?? null, 150),
            'entidade' => $this->nullableString($task['origem_entidade'] ?? null, 100),
            'origem_id' => $this->nullableString($task['origem_id'] ?? null, 100),
            'codunidade' => $this->nullableInt($task['codunidade'] ?? null),
            'titulo' => substr(trim((string)($task['titulo'] ?? 'Tarefa')), 0, 180),
            'descricao' => (string)($task['descricao'] ?? ''),
            'prioridade' => max(0, min(9, (int)($task['prioridade'] ?? 0))),
            'codusuario' => $this->nullableInt($task['codusuario_destino'] ?? null),
            'codgrupo' => $this->nullableInt($task['codgrupo_destino'] ?? null),
            'data_limite' => $this->nullableDateTime($task['data_limite'] ?? null),
            'criado_por' => $this->nullableInt($task['criado_por'] ?? null),
            'disponivel_em' => $this->nullableDateTime($task['disponivel_em'] ?? null),
        ]);

        $id = (int)$this->pdo->lastInsertId();
        if ($id <= 0) {
            $find = $this->pdo->prepare('SELECT codtarefa FROM green_tasks WHERE chave_idempotencia = :chave');
            $find->execute(['chave' => substr($key, 0, 191)]);
            $id = (int)$find->fetchColumn();
        }
        if ($id <= 0) {
            throw new RuntimeException('Não foi possível localizar a tarefa criada.');
        }
        return $this->find($id) ?? throw new RuntimeException('Tarefa não localizada após gravação.');
    }

    public function claim(?string $type = null): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $where = "status = 'PENDENTE' AND disponivel_em <= CURRENT_TIMESTAMP
                AND (bloqueado_ate IS NULL OR bloqueado_ate <= CURRENT_TIMESTAMP)";
            $params = [];
            if ($type !== null && trim($type) !== '') {
                $where .= ' AND tipo = :tipo';
                $params['tipo'] = strtoupper(trim($type));
            }
            $query = $this->pdo->prepare(
                "SELECT * FROM green_tasks WHERE {$where}
                 ORDER BY prioridade DESC, criado_em, codtarefa LIMIT 1 FOR UPDATE SKIP LOCKED"
            );
            $query->execute($params);
            $task = $query->fetch();
            if (!$task) {
                $this->pdo->commit();
                return null;
            }

            $update = $this->pdo->prepare(
                "UPDATE green_tasks
                    SET status='PROCESSANDO', tentativas=tentativas+1,
                        bloqueado_ate=DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE),
                        atualizado_em=CURRENT_TIMESTAMP
                  WHERE codtarefa=:id AND status='PENDENTE'"
            );
            $update->execute(['id' => (int)$task['codtarefa']]);
            $this->history((int)$task['codtarefa'], 'PROCESSANDO', null);
            $this->pdo->commit();
            return $this->find((int)$task['codtarefa']);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function complete(int $id, ?string $detail = null): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE green_tasks SET status='CONCLUIDA', bloqueado_ate=NULL,
             processado_em=CURRENT_TIMESTAMP, atualizado_em=CURRENT_TIMESTAMP,
             erro=NULL WHERE codtarefa=:id AND status='PROCESSANDO'"
        );
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() > 0) {
            $this->history($id, 'CONCLUIDA', $detail);
        }
    }

    public function fail(int $id, string $error, int $maxAttempts = 3): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE green_tasks
                SET status=IF(tentativas < :max_attempts, 'PENDENTE', 'ERRO'),
                    disponivel_em=IF(tentativas < :max_attempts,
                        DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 MINUTE), disponivel_em),
                    bloqueado_ate=NULL, erro=:erro, atualizado_em=CURRENT_TIMESTAMP
              WHERE codtarefa=:id AND status='PROCESSANDO'"
        );
        $statement->execute([
            'max_attempts' => max(1, $maxAttempts),
            'erro' => substr($error, 0, 2000),
            'id' => $id,
        ]);
        if ($statement->rowCount() > 0) {
            $task = $this->find($id);
            $this->history($id, (string)($task['status'] ?? 'ERRO'), $error);
        }
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM green_tasks WHERE codtarefa=:id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function pendingCount(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM green_tasks WHERE status IN ('PENDENTE','PROCESSANDO')")->fetchColumn();
    }

    private function history(int $id, string $status, ?string $detail): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO green_task_history (codtarefa, status, detalhe) VALUES (:id,:status,:detalhe)'
        );
        $statement->execute(['id' => $id, 'status' => $status, 'detalhe' => $detail]);
    }

    private function nullableString(mixed $value, int $length): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : substr($value, 0, $length);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int)$value;
    }

    private function nullableDateTime(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
}
