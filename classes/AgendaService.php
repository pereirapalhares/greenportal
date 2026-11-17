<?php
declare(strict_types=1);

require_once __DIR__ . '/TaskService.php';

/** Agenda universal por tenant; ev_eventos legado permanece compatível. */
final class AgendaService
{
    public function __construct(private readonly PDO $pdo, private readonly TaskService $tasks)
    {
    }

    public function createEvent(array $event): int
    {
        $key = trim((string)($event['chave_idempotencia'] ?? ''));
        $title = trim((string)($event['titulo'] ?? ''));
        $start = trim((string)($event['inicio'] ?? ''));
        if ($key === '' || $title === '' || $start === '') throw new InvalidArgumentException('Evento requer chave, título e início.');
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO agenda_eventos
 (chave_idempotencia,codusuario_criador,codusuario_responsavel,titulo,descricao,localizacao,inicio,fim,status,origem_transacao,origem_entidade,origem_id,codrecorrencia)
VALUES (:chave,:criador,:responsavel,:titulo,:descricao,:localizacao,:inicio,:fim,'ATIVO',:transacao,:entidade,:origem_id,:recorrencia)
ON DUPLICATE KEY UPDATE codeventoagenda=LAST_INSERT_ID(codeventoagenda)
SQL);
        $statement->execute([
            'chave'=>substr($key,0,191),'criador'=>$this->nullableInt($event['codusuario_criador']??null),
            'responsavel'=>$this->nullableInt($event['codusuario_responsavel']??null),'titulo'=>substr($title,0,180),
            'descricao'=>(string)($event['descricao']??''),'localizacao'=>$this->nullableString($event['localizacao']??null,255),
            'inicio'=>$start,'fim'=>$this->nullableString($event['fim']??null,19),'transacao'=>$this->nullableString($event['origem_transacao']??null,150),
            'entidade'=>$this->nullableString($event['origem_entidade']??null,100),'origem_id'=>$this->nullableString($event['origem_id']??null,100),
            'recorrencia'=>$this->nullableInt($event['codrecorrencia']??null),
        ]);
        $id=(int)$this->pdo->lastInsertId();
        if($id<=0){$q=$this->pdo->prepare('SELECT codeventoagenda FROM agenda_eventos WHERE chave_idempotencia=:chave');$q->execute(['chave'=>substr($key,0,191)]);$id=(int)$q->fetchColumn();}
        if($id<=0)throw new RuntimeException('Evento não localizado após gravação.');
        return $id;
    }

    /**
     * Sincroniza uma origem legada com a agenda universal.
     * A chave da origem é única para que a sincronização possa ser repetida
     * sem criar eventos duplicados.
     */
    public function syncSource(string $sourceKey, array $event, bool $preserveCompleted = false): int
    {
        $key = trim($sourceKey);
        $title = trim((string)($event['titulo'] ?? ''));
        $start = trim((string)($event['inicio'] ?? ''));
        if ($key === '' || $title === '' || $start === '') {
            throw new InvalidArgumentException('Origem de agenda requer chave, título e início.');
        }

        $completedSql = $preserveCompleted ? "IF(status='CONCLUIDO',status,'ATIVO')" : "'ATIVO'";
        $mutableSql = $preserveCompleted ? "IF(status='CONCLUIDO',codusuario_responsavel,VALUES(codusuario_responsavel))" : "VALUES(codusuario_responsavel)";
        $titleSql = $preserveCompleted ? "IF(status='CONCLUIDO',titulo,VALUES(titulo))" : "VALUES(titulo)";
        $descriptionSql = $preserveCompleted ? "IF(status='CONCLUIDO',descricao,VALUES(descricao))" : "VALUES(descricao)";
        $locationSql = $preserveCompleted ? "IF(status='CONCLUIDO',localizacao,VALUES(localizacao))" : "VALUES(localizacao)";
        $startSql = $preserveCompleted ? "IF(status='CONCLUIDO',inicio,VALUES(inicio))" : "VALUES(inicio)";
        $endSql = $preserveCompleted ? "IF(status='CONCLUIDO',fim,VALUES(fim))" : "VALUES(fim)";
        $transactionSql = $preserveCompleted ? "IF(status='CONCLUIDO',origem_transacao,VALUES(origem_transacao))" : "VALUES(origem_transacao)";
        $entitySql = $preserveCompleted ? "IF(status='CONCLUIDO',origem_entidade,VALUES(origem_entidade))" : "VALUES(origem_entidade)";
        $originIdSql = $preserveCompleted ? "IF(status='CONCLUIDO',origem_id,VALUES(origem_id))" : "VALUES(origem_id)";
        $sql = <<<SQL
INSERT INTO agenda_eventos
 (chave_idempotencia,codusuario_criador,codusuario_responsavel,titulo,descricao,localizacao,inicio,fim,status,origem_transacao,origem_entidade,origem_id,codrecorrencia)
VALUES (:chave,:criador,:responsavel,:titulo,:descricao,:localizacao,:inicio,:fim,'ATIVO',:transacao,:entidade,:origem_id,NULL)
ON DUPLICATE KEY UPDATE
 codeventoagenda=LAST_INSERT_ID(codeventoagenda),
 codusuario_responsavel={$mutableSql},
 titulo={$titleSql}, descricao={$descriptionSql}, localizacao={$locationSql},
 inicio={$startSql}, fim={$endSql}, status={$completedSql},
 origem_transacao={$transactionSql}, origem_entidade={$entitySql}, origem_id={$originIdSql}
SQL;
        $statement = $this->pdo->prepare($sql);
        $responsible = $this->nullableInt($event['codusuario_responsavel'] ?? null);
        $creator = $this->nullableInt($event['codusuario_criador'] ?? $responsible);
        $statement->execute([
            'chave' => substr($key, 0, 191),
            'criador' => $creator,
            'responsavel' => $responsible,
            'titulo' => substr($title, 0, 180),
            'descricao' => (string)($event['descricao'] ?? ''),
            'localizacao' => $this->nullableString($event['localizacao'] ?? null, 255),
            'inicio' => $start,
            'fim' => $this->nullableString($event['fim'] ?? null, 19),
            'transacao' => $this->nullableString($event['origem_transacao'] ?? null, 150),
            'entidade' => $this->nullableString($event['origem_entidade'] ?? null, 100),
            'origem_id' => $this->nullableString($event['origem_id'] ?? null, 100),
        ]);
        $id = (int)$this->pdo->lastInsertId();
        if ($id <= 0) {
            $lookup = $this->pdo->prepare('SELECT codeventoagenda FROM agenda_eventos WHERE chave_idempotencia=:chave');
            $lookup->execute(['chave' => substr($key, 0, 191)]);
            $id = (int)$lookup->fetchColumn();
        }
        if ($id <= 0) throw new RuntimeException('Evento de origem não localizado após sincronização.');
        return $id;
    }

    /** Cancela o evento universal correspondente à origem, sem apagar histórico. */
    public function cancelSource(string $sourceKey, bool $activeOnly = false): int
    {
        $statusFilter = $activeOnly ? "status='ATIVO'" : "status<>'CANCELADO'";
        $statement = $this->pdo->prepare("UPDATE agenda_eventos SET status='CANCELADO' WHERE chave_idempotencia=:chave AND {$statusFilter}");
        $statement->execute(['chave' => substr(trim($sourceKey), 0, 191)]);
        return $statement->rowCount();
    }

    public function listForUser(int $userId, ?string $from=null, ?string $to=null): array
    {
        $where='status<>\'CANCELADO\' AND (codusuario_criador=:usuario OR codusuario_responsavel=:usuario)';$params=['usuario'=>$userId];
        if($from!==null){$where.=' AND inicio>=:inicio';$params['inicio']=$from;}if($to!==null){$where.=' AND inicio<:fim';$params['fim']=$to;}
        $q=$this->pdo->prepare("SELECT * FROM agenda_eventos WHERE {$where} ORDER BY inicio,codeventoagenda");$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createRecurring(array $data): int
    {
        $frequency=strtoupper(trim((string)($data['frequencia']??'')));if(!in_array($frequency,['DIARIA','SEMANAL','MENSAL'],true))throw new InvalidArgumentException('Frequência inválida.');
        $key=trim((string)($data['chave_idempotencia']??''));$title=trim((string)($data['titulo']??''));$next=trim((string)($data['proxima_execucao']??''));
        if($key===''||$title===''||$next==='')throw new InvalidArgumentException('Recorrência requer chave, título e próxima execução.');
        $q=$this->pdo->prepare("INSERT INTO agenda_recorrencias (chave_idempotencia,codusuario_criador,codusuario_responsavel,titulo,descricao,localizacao,frequencia,intervalo,proxima_execucao,termina_em,ativo) VALUES (:chave,:criador,:responsavel,:titulo,:descricao,:localizacao,:frequencia,:intervalo,:proxima,:termina,1) ON DUPLICATE KEY UPDATE codrecorrencia=LAST_INSERT_ID(codrecorrencia)");$q->execute(['chave'=>substr($key,0,191),'criador'=>$this->nullableInt($data['codusuario_criador']??null),'responsavel'=>$this->nullableInt($data['codusuario_responsavel']??null),'titulo'=>substr($title,0,180),'descricao'=>(string)($data['descricao']??''),'localizacao'=>$this->nullableString($data['localizacao']??null,255),'frequencia'=>$frequency,'intervalo'=>max(1,min(365,(int)($data['intervalo']??1))),'proxima'=>$next,'termina'=>$this->nullableString($data['termina_em']??null,19)]);$id=(int)$this->pdo->lastInsertId();if($id<=0){$s=$this->pdo->prepare('SELECT codrecorrencia FROM agenda_recorrencias WHERE chave_idempotencia=:chave');$s->execute(['chave'=>substr($key,0,191)]);$id=(int)$s->fetchColumn();}return $id;
    }

    public function materializeDueOccurrences(int $days=1): int
    {
        $limit=(new DateTimeImmutable('now'))->modify('+' . max(0,min(30,$days)) . ' days')->format('Y-m-d H:i:s');$q=$this->pdo->prepare('SELECT * FROM agenda_recorrencias WHERE ativo=1 AND proxima_execucao<=:limite AND (termina_em IS NULL OR proxima_execucao<=termina_em) ORDER BY proxima_execucao,codrecorrencia');$q->execute(['limite'=>$limit]);$created=0;
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $rec){$date=(string)$rec['proxima_execucao'];$key='AGENDA_RECORRENCIA:'.$rec['codrecorrencia'].':'.$date;$this->pdo->beginTransaction();try{$insert=$this->pdo->prepare('INSERT IGNORE INTO agenda_execucoes (codrecorrencia,referencia) VALUES (:rec,:referencia)');$insert->execute(['rec'=>(int)$rec['codrecorrencia'],'referencia'=>$date]);if($insert->rowCount()>0){$eventId=$this->createEvent(['chave_idempotencia'=>$key,'codusuario_criador'=>$rec['codusuario_criador'],'codusuario_responsavel'=>$rec['codusuario_responsavel'],'titulo'=>$rec['titulo'],'descricao'=>$rec['descricao'],'localizacao'=>$rec['localizacao'],'inicio'=>$date,'origem_transacao'=>'agenda_worker.php','origem_entidade'=>'agenda_recorrencias','origem_id'=>$rec['codrecorrencia'],'codrecorrencia'=>$rec['codrecorrencia']]);if(!empty($rec['codusuario_responsavel'])){$this->tasks->enqueue(['chave_idempotencia'=>'AGENDA_NOTIFICACAO:'.$eventId,'tipo'=>'NOTIFICACAO','origem_transacao'=>'agenda_worker.php','origem_entidade'=>'agenda_eventos','origem_id'=>$eventId,'titulo'=>'Novo evento na agenda','descricao'=>$rec['titulo'].' está programado para '.$date.'.','codusuario_destino'=>$rec['codusuario_responsavel'],'data_limite'=>$date,'criado_por'=>$rec['codusuario_criador']]);}$created++;}$next=$this->nextDate(new DateTimeImmutable($date),(string)$rec['frequencia'],(int)$rec['intervalo']);$u=$this->pdo->prepare('UPDATE agenda_recorrencias SET ultima_execucao=:ultima,proxima_execucao=:proxima,ativo=IF(termina_em IS NOT NULL AND :proxima2>termina_em,0,ativo),atualizado_em=CURRENT_TIMESTAMP WHERE codrecorrencia=:id');$nextValue=$next->format('Y-m-d H:i:s');$u->execute(['ultima'=>$date,'proxima'=>$nextValue,'proxima2'=>$nextValue,'id'=>(int)$rec['codrecorrencia']]);$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}}
        return $created;
    }

    private function nextDate(DateTimeImmutable $date,string $frequency,int $interval):DateTimeImmutable{return match($frequency){ 'DIARIA'=>$date->modify('+' . $interval . ' days'), 'SEMANAL'=>$date->modify('+' . $interval . ' weeks'), 'MENSAL'=>$date->modify('+' . $interval . ' months'), default=>throw new InvalidArgumentException('Frequência inválida.')};}
    private function nullableInt(mixed $value):?int{return $value===null||$value===''?null:(int)$value;}
    private function nullableString(mixed $value,int $length):?string{$value=trim((string)$value);return $value===''?null:substr($value,0,$length);}
}
