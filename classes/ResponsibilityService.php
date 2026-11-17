<?php
declare(strict_types=1);

require_once __DIR__ . '/TaskService.php';

/** Regras transversais de responsabilidades por tenant. */
final class ResponsibilityService
{
    public function __construct(private readonly PDO $pdo, private readonly ?TaskService $tasks = null)
    {
    }

    public function effectiveForUser(int $userId): array
    {
        if ($userId <= 0) return [];
        $statement = $this->pdo->prepare(
            'SELECT * FROM vw_responsabilidade_efetiva WHERE CODUSUARIO_DESTINO=:usuario ORDER BY PRINCIPAL DESC, PRIORIDADE DESC, CODORIGEM'
        );
        $statement->execute(['usuario' => $userId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $substitution = $this->pdo->prepare(<<<'SQL'
SELECT s.CODSUBSTITUICAO AS CODORIGEM, 'SUBSTITUICAO' AS TIPO_ORIGEM,
       s.CODMODULO, m.CODIGO AS MODULO_CODIGO, m.NOME AS MODULO_NOME,
       a.ENTIDADE, a.REGISTRO_ID, :usuario AS CODUSUARIO_DESTINO,
       u.USERID, u.NOME AS NOME_RESPONSAVEL, a.PRIORIDADE, a.PRINCIPAL,
       s.VALIDO_DE, s.VALIDO_ATE
  FROM responsabilidade_substituicoes s
  INNER JOIN responsabilidade_atribuicoes a ON a.CODATRIBUICAO=s.CODATRIBUICAO
  INNER JOIN responsabilidade_modulos m ON m.CODMODULO=s.CODMODULO AND m.ATIVO=1
  INNER JOIN usuarios u ON u.codusuario=:usuario AND u.ativo=1
 WHERE s.STATUS='ATIVA' AND s.TIPO_DESTINO='USUARIO' AND s.CODUSUARIO=:usuario
   AND s.VALIDO_DE <= CURRENT_DATE AND (s.VALIDO_ATE IS NULL OR s.VALIDO_ATE >= CURRENT_DATE)
UNION ALL
SELECT s.CODSUBSTITUICAO AS CODORIGEM, 'SUBSTITUICAO_GRUPO' AS TIPO_ORIGEM,
       s.CODMODULO, m.CODIGO AS MODULO_CODIGO, m.NOME AS MODULO_NOME,
       a.ENTIDADE, a.REGISTRO_ID, gx.CODUSUARIO AS CODUSUARIO_DESTINO,
       u.USERID, u.NOME AS NOME_RESPONSAVEL, a.PRIORIDADE, a.PRINCIPAL,
       s.VALIDO_DE, s.VALIDO_ATE
  FROM responsabilidade_substituicoes s
  INNER JOIN responsabilidade_atribuicoes a ON a.CODATRIBUICAO=s.CODATRIBUICAO
  INNER JOIN responsabilidade_modulos m ON m.CODMODULO=s.CODMODULO AND m.ATIVO=1
  INNER JOIN vw_responsabilidade_grupoxusuario gx ON gx.CODGRUPO=s.CODGRUPO
  INNER JOIN usuarios u ON u.codusuario=gx.CODUSUARIO AND u.ativo=1
 WHERE s.STATUS='ATIVA' AND s.TIPO_DESTINO='GRUPO'
   AND s.VALIDO_DE <= CURRENT_DATE AND (s.VALIDO_ATE IS NULL OR s.VALIDO_ATE >= CURRENT_DATE)
SQL);
        $substitution->execute(['usuario' => $userId]);
        return array_merge($rows, $substitution->fetchAll(PDO::FETCH_ASSOC));
    }

    public function effectiveForGroup(int $groupId): array
    {
        if ($groupId <= 0) return [];
        $statement = $this->pdo->prepare(
            'SELECT * FROM vw_responsabilidade_atribuicoes_atuais WHERE CODGRUPO=:grupo ORDER BY PRINCIPAL DESC, PRIORIDADE DESC, CODATRIBUICAO'
        );
        $statement->execute(['grupo' => $groupId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activeDelegations(?int $userId = null, ?int $groupId = null): array
    {
        $where = "STATUS='ATIVA' AND VALIDO_DE <= CURRENT_DATE
            AND (VALIDO_ATE IS NULL OR VALIDO_ATE >= CURRENT_DATE)";
        $params = [];
        if ($userId !== null) { $where .= ' AND CODUSUARIO=:usuario'; $params['usuario'] = $userId; }
        if ($groupId !== null) { $where .= ' AND CODGRUPO=:grupo'; $params['grupo'] = $groupId; }
        $statement = $this->pdo->prepare("SELECT * FROM responsabilidade_delegacoes WHERE {$where} ORDER BY VALIDO_DE, CODDELEGACAO");
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function approveDelegation(int $id, int $approvedBy): void
    {
        $this->changeStatus('responsabilidade_delegacoes', 'CODDELEGACAO', $id, 'ATIVA', $approvedBy, 'DELEGACAO_APROVADA');
    }

    public function revokeDelegation(int $id, int $approvedBy): void
    {
        $this->changeStatus('responsabilidade_delegacoes', 'CODDELEGACAO', $id, 'REVOGADA', $approvedBy, 'DELEGACAO_REVOGADA');
    }

    /** Conflito operacional: mais de uma atribuição principal para o mesmo objeto. */
    public function conflicts(): array
    {
        $sql = <<<'SQL'
SELECT CODMODULO, MODULO_CODIGO, ENTIDADE, REGISTRO_ID,
       COUNT(*) AS TOTAL, SUM(PRINCIPAL) AS PRINCIPAIS
  FROM vw_responsabilidade_atribuicoes_atuais
 WHERE ENTIDADE IS NOT NULL AND REGISTRO_ID IS NOT NULL
 GROUP BY CODMODULO, MODULO_CODIGO, ENTIDADE, REGISTRO_ID
HAVING SUM(PRINCIPAL) > 1
 ORDER BY MODULO_CODIGO, ENTIDADE, REGISTRO_ID
SQL;
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Gera uma tarefa por responsável para cada atribuição que vence no horizonte. */
    public function enqueueExpiringTasks(int $days = 30, ?int $createdBy = null): int
    {
        if ($this->tasks === null) throw new LogicException('TaskService é necessário para gerar tarefas.');
        $days = max(0, min(365, $days));
        $sql = <<<'SQL'
SELECT CODORIGEM, TIPO_ORIGEM, CODMODULO, MODULO_CODIGO, MODULO_NOME,
       ENTIDADE, REGISTRO_ID, CODUSUARIO_DESTINO, USERID, NOME_RESPONSAVEL,
       PRIORIDADE, PRINCIPAL, VALIDO_ATE
  FROM vw_responsabilidade_efetiva
 WHERE VALIDO_ATE IS NOT NULL
   AND VALIDO_ATE BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE, INTERVAL :dias DAY)
SQL;
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['dias' => $days]);
        $created = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userId = (int)$row['CODUSUARIO_DESTINO'];
            $origin = (string)$row['CODORIGEM'];
            $due = (string)$row['VALIDO_ATE'];
            $key = 'RESPONSABILIDADE_VENCIMENTO:' . $origin . ':' . $userId . ':' . $due;
            $this->tasks->enqueue([
                'chave_idempotencia' => $key,
                'tipo' => 'NOTIFICACAO',
                'origem_transacao' => 'responsabilidade_worker.php',
                'origem_entidade' => (string)($row['ENTIDADE'] ?: 'responsabilidade_atribuicoes'),
                'origem_id' => $origin,
                'titulo' => 'Responsabilidade próxima do vencimento',
                'descricao' => sprintf(
                    'A responsabilidade %s (%s) atribuída a %s vence em %s.',
                    $origin,
                    (string)($row['MODULO_NOME'] ?: $row['MODULO_CODIGO']),
                    (string)($row['NOME_RESPONSAVEL'] ?: $row['USERID']),
                    $due
                ),
                'prioridade' => (int)$row['PRIORIDADE'],
                'codusuario_destino' => $userId,
                'data_limite' => $due . ' 23:59:59',
                'criado_por' => $createdBy,
            ]);
            $created++;
        }
        return $created;
    }

    public function createSubstitution(array $data, int $requestedBy): int
    {
        $origin = (int)($data['CODATRIBUICAO'] ?? 0);
        $module = (int)($data['CODMODULO'] ?? 0);
        $targetUser = $this->nullableInt($data['CODUSUARIO'] ?? null);
        $targetGroup = $this->nullableInt($data['CODGRUPO'] ?? null);
        $start = trim((string)($data['VALIDO_DE'] ?? date('Y-m-d')));
        $end = trim((string)($data['VALIDO_ATE'] ?? '')) ?: null;
        $reason = trim((string)($data['MOTIVO'] ?? ''));
        if ($origin <= 0 || $module <= 0 || $requestedBy <= 0 || $reason === '') throw new InvalidArgumentException('Dados de substituição incompletos.');
        if (($targetUser === null) === ($targetGroup === null)) throw new InvalidArgumentException('Informe usuário ou grupo substituto.');
        $statement = $this->pdo->prepare(
            'INSERT INTO responsabilidade_substituicoes
             (CODATRIBUICAO,CODMODULO,TIPO_DESTINO,CODUSUARIO,CODGRUPO,VALIDO_DE,VALIDO_ATE,STATUS,MOTIVO,CODUSUARIOSOLICITANTE)
             VALUES (:atribuicao,:modulo,:destino,:usuario,:grupo,:inicio,:fim,\'PENDENTE\',:motivo,:solicitante)'
        );
        $statement->execute([
            'atribuicao' => $origin, 'modulo' => $module,
            'destino' => $targetUser !== null ? 'USUARIO' : 'GRUPO',
            'usuario' => $targetUser, 'grupo' => $targetGroup,
            'inicio' => $start, 'fim' => $end, 'motivo' => substr($reason, 0, 1000), 'solicitante' => $requestedBy,
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $this->audit('SUBSTITUICAO_SOLICITADA', 'responsabilidade_substituicoes', (string)$id, $requestedBy, ['codatribuicao' => $origin]);
        return $id;
    }

    public function approveSubstitution(int $id, int $approvedBy): void
    {
        $this->changeStatus('responsabilidade_substituicoes', 'CODSUBSTITUICAO', $id, 'ATIVA', $approvedBy, 'SUBSTITUICAO_APROVADA');
    }

    public function revokeSubstitution(int $id, int $approvedBy): void
    {
        $this->changeStatus('responsabilidade_substituicoes', 'CODSUBSTITUICAO', $id, 'REVOGADA', $approvedBy, 'SUBSTITUICAO_REVOGADA');
    }

    private function changeStatus(string $table, string $key, int $id, string $status, int $operator, string $action): void
    {
        if ($id <= 0 || $operator <= 0) throw new InvalidArgumentException('Identificadores inválidos para alteração de responsabilidade.');
        $allowedTables = ['responsabilidade_delegacoes', 'responsabilidade_substituicoes'];
        if (!in_array($table, $allowedTables, true)) throw new InvalidArgumentException('Tabela de responsabilidade não permitida.');
        $statement = $this->pdo->prepare("UPDATE `{$table}` SET STATUS=:status, CODUSUARIOAPROVADOR=:usuario, ATUALIZADO_EM=CURRENT_TIMESTAMP WHERE `{$key}`=:id AND STATUS IN ('PENDENTE','ATIVA')");
        $statement->execute(['status' => $status, 'usuario' => $operator, 'id' => $id]);
        if ($statement->rowCount() > 0) $this->audit($action, $table, (string)$id, $operator, ['status' => $status]);
    }

    private function audit(string $action, string $entity, string $record, int $userId, array $details): void
    {
        $statement = $this->pdo->prepare(
            'CALL sp_auditoria_registrar(:usuario,:transacao,:acao,:entidade,:registro,\'SUCESSO\',:detalhes)'
        );
        $details += ['application' => 'GreenSolutions', 'version' => '4.0.1', 'tenant_database' => $this->pdo->query('SELECT DATABASE()')->fetchColumn()];
        $statement->execute([
            'usuario' => $userId, 'transacao' => 'responsabilidade_substituicao.php', 'acao' => $action,
            'entidade' => $entity, 'registro' => $record,
            'detalhes' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $statement->closeCursor();
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int)$value;
    }
}
