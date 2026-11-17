<?php
declare(strict_types=1);

require_once __DIR__ . '/TaskService.php';

/** Motor universal de fluxos versionados por tenant. */
final class WorkflowService
{
    public function __construct(private readonly PDO $pdo, private readonly TaskService $tasks) {}

    public function start(string $workflowCode, string $entity, string $recordId, int $startedBy, ?int $responsible = null): int
    {
        $definition=$this->definition($workflowCode);if(!$definition)throw new InvalidArgumentException('Workflow não publicado.');
        $key='WORKFLOW:'.$definition['codworkflow'].':'.$entity.':'.$recordId;
        $q=$this->pdo->prepare("INSERT INTO workflow_instancias (codworkflow,chave_idempotencia,origem_entidade,origem_id,etapa_atual,status,codusuario_iniciador,codusuario_responsavel) VALUES (:workflow,:chave,:entidade,:origem,'INICIO','ATIVA',:iniciador,:responsavel) ON DUPLICATE KEY UPDATE codinstancia=LAST_INSERT_ID(codinstancia)");$q->execute(['workflow'=>(int)$definition['codworkflow'],'chave'=>$key,'entidade'=>$entity,'origem'=>$recordId,'iniciador'=>$startedBy,'responsavel'=>$responsible]);$created=$q->rowCount()>0;$id=(int)$this->pdo->lastInsertId();if($id<=0){$s=$this->pdo->prepare('SELECT codinstancia FROM workflow_instancias WHERE chave_idempotencia=:chave');$s->execute(['chave'=>$key]);$id=(int)$s->fetchColumn();}if($id<=0)throw new RuntimeException('Instância não localizada.');if($created)$this->history($id,'INICIO',null,'INICIO',$startedBy,'Fluxo iniciado.');return $id;
    }

    public function transition(int $instanceId,string $action,int $userId,?string $reason=null):string
    {
        $q=$this->pdo->prepare('SELECT i.*,d.codigo AS workflow_codigo FROM workflow_instancias i JOIN workflow_definicoes d ON d.codworkflow=i.codworkflow WHERE i.codinstancia=:id FOR UPDATE');$this->pdo->beginTransaction();try{$q->execute(['id'=>$instanceId]);$instance=$q->fetch(PDO::FETCH_ASSOC);if(!$instance)throw new InvalidArgumentException('Instância não localizada.');$t=$this->pdo->prepare("SELECT * FROM workflow_transicoes WHERE codworkflow=:workflow AND etapa_origem=:origem AND acao=:acao AND ativo=1 LIMIT 1");$t->execute(['workflow'=>$instance['codworkflow'],'origem'=>$instance['etapa_atual'],'acao'=>strtoupper($action)]);$transition=$t->fetch(PDO::FETCH_ASSOC);if(!$transition)throw new InvalidArgumentException('Transição não autorizada para a etapa atual.');if(!empty($transition['requer_motivo'])&&trim((string)$reason)==='')throw new InvalidArgumentException('Esta transição exige motivo.');$new=(string)$transition['etapa_destino'];$status=$new==='FINALIZADO'?'CONCLUIDA':($new==='CANCELADO'?'CANCELADA':'ATIVA');$u=$this->pdo->prepare('UPDATE workflow_instancias SET etapa_atual=:etapa,status=:status,atualizado_em=CURRENT_TIMESTAMP,finalizado_em=IF(:finalizado=1,CURRENT_TIMESTAMP,finalizado_em) WHERE codinstancia=:id');$u->execute(['etapa'=>$new,'status'=>$status,'finalizado'=>$status==='CONCLUIDA'||$status==='CANCELADA'?1:0,'id'=>$instanceId]);$this->history($instanceId,$action,(string)$instance['etapa_atual'],$new,$userId,$reason);$this->pdo->commit();return $new;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}}

    public function definition(string $code):?array{$q=$this->pdo->prepare("SELECT * FROM workflow_definicoes WHERE codigo=:codigo AND publicado=1 AND ativo=1 ORDER BY versao DESC LIMIT 1");$q->execute(['codigo'=>strtoupper($code)]);$r=$q->fetch(PDO::FETCH_ASSOC);return $r?:null;}
    public function history(int $instanceId,string $action,?string $from, string $to,int $userId,?string $reason):void{$q=$this->pdo->prepare('INSERT INTO workflow_historico (codinstancia,acao,etapa_anterior,etapa_nova,codusuario,motivo) VALUES (:instancia,:acao,:anterior,:nova,:usuario,:motivo)');$q->execute(['instancia'=>$instanceId,'acao'=>strtoupper($action),'anterior'=>$from,'nova'=>$to,'usuario'=>$userId,'motivo'=>$reason]);}
}
