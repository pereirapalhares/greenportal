<?php
declare(strict_types=1);
require_once __DIR__ . '/WorkflowService.php';

/** Conteúdo universal por tenant, sem substituir o GED legado. */
final class ContentService
{
    private WorkflowService $workflow;
    public function __construct(private readonly PDO $pdo){$this->workflow=new WorkflowService($pdo,new TaskService($pdo));}
    public function createDocument(array $d,int $user):int
    {
        $q=$this->pdo->prepare('INSERT INTO content_documents (chave_idempotencia,codigo,titulo,descricao,tipo_documento,codusuario_criador,codunidade,origem_entidade,origem_id,status) VALUES (:chave,:codigo,:titulo,:descricao,:tipo,:usuario,:unidade,:entidade,:origem,:status) ON DUPLICATE KEY UPDATE coddocumento=LAST_INSERT_ID(coddocumento)');
        $q->execute(['chave'=>$d['chave_idempotencia'],'codigo'=>$d['codigo'],'titulo'=>$d['titulo'],'descricao'=>$d['descricao']??null,'tipo'=>$d['tipo_documento']??null,'usuario'=>$user,'unidade'=>$d['codunidade']??null,'entidade'=>$d['origem_entidade']??null,'origem'=>$d['origem_id']??null,'status'=>'RASCUNHO']);
        $id=(int)$this->pdo->lastInsertId();if($id<=0){$s=$this->pdo->prepare('SELECT coddocumento FROM content_documents WHERE chave_idempotencia=:chave');$s->execute(['chave'=>$d['chave_idempotencia']]);$id=(int)$s->fetchColumn();}if($id<=0)throw new RuntimeException('Conteúdo não localizado.');$this->audit('CRIACAO',$id,$user,['codigo'=>$d['codigo']]);return $id;
    }
    public function createVersion(int $documentId,array $d,int $user):int
    {
        $existing=$this->pdo->prepare('SELECT codversaodocumento FROM content_versions WHERE chave_idempotencia=:chave LIMIT 1');$existing->execute(['chave'=>$d['chave_idempotencia']]);$existingId=(int)$existing->fetchColumn();if($existingId>0)return $existingId;
        $v=(int)$this->pdo->query('SELECT COALESCE(MAX(versao),0)+1 FROM content_versions WHERE coddocumento='.(int)$documentId)->fetchColumn();
        $q=$this->pdo->prepare('INSERT INTO content_versions (coddocumento,chave_idempotencia,versao,nome_arquivo,mime_type,tamanho_bytes,hash_sha256,storage_key,observacao,codusuario) VALUES (:documento,:chave,:versao,:arquivo,:mime,:tamanho,:hash,:storage,:observacao,:usuario) ON DUPLICATE KEY UPDATE codversaodocumento=LAST_INSERT_ID(codversaodocumento)');
        $q->execute(['documento'=>$documentId,'chave'=>$d['chave_idempotencia'],'versao'=>$v,'arquivo'=>$d['nome_arquivo']??null,'mime'=>$d['mime_type']??null,'tamanho'=>$d['tamanho_bytes']??null,'hash'=>$d['hash_sha256']??null,'storage'=>$d['storage_key']??null,'observacao'=>$d['observacao']??null,'usuario'=>$user]);
        $id=(int)$this->pdo->lastInsertId();if($id<=0){$s=$this->pdo->prepare('SELECT codversaodocumento FROM content_versions WHERE chave_idempotencia=:chave');$s->execute(['chave'=>$d['chave_idempotencia']]);$id=(int)$s->fetchColumn();}$u=$this->pdo->prepare("UPDATE content_documents SET versao_atual=GREATEST(versao_atual,:versao),status='RASCUNHO' WHERE coddocumento=:documento");$u->execute(['versao'=>$v,'documento'=>$documentId]);$this->audit('NOVA_VERSAO',$documentId,$user,['versao'=>$v]);return $id;
    }
    public function submitForApproval(int $documentId,int $user):int{$u=$this->pdo->prepare("UPDATE content_documents SET status='EM_REVISAO' WHERE coddocumento=:id");$u->execute(['id'=>$documentId]);$i=$this->workflow->start('CONTENT_APPROVAL','content_documents',(string)$documentId,$user,$user);$this->audit('ENVIO_APROVACAO',$documentId,$user,['instancia'=>$i]);return $i;}
    public function approve(int $documentId,int $user,string $reason):void{$i=$this->instance($documentId);if(!$i)throw new RuntimeException('Workflow de conteúdo não localizado.');$this->workflow->transition((int)$i['codinstancia'],'APROVAR',$user,$reason);$q=$this->pdo->prepare("UPDATE content_documents SET status='APROVADO' WHERE coddocumento=:id");$q->execute(['id'=>$documentId]);$this->audit('APROVACAO',$documentId,$user,['instancia'=>(int)$i['codinstancia']]);}
    public function find(int $id):?array{$q=$this->pdo->prepare('SELECT * FROM content_documents WHERE coddocumento=:id');$q->execute(['id'=>$id]);$r=$q->fetch();return $r?:null;}
    public function versions(int $id):array{$q=$this->pdo->prepare('SELECT * FROM content_versions WHERE coddocumento=:id ORDER BY versao');$q->execute(['id'=>$id]);return $q->fetchAll();}
    private function instance(int $id):?array{$q=$this->pdo->prepare("SELECT i.* FROM workflow_instancias i JOIN workflow_definicoes d ON d.codworkflow=i.codworkflow WHERE BINARY d.codigo=BINARY 'CONTENT_APPROVAL' AND BINARY i.origem_entidade=BINARY 'content_documents' AND i.origem_id=:id ORDER BY i.codinstancia DESC LIMIT 1");$q->execute(['id'=>(string)$id]);$r=$q->fetch();return $r?:null;}
    private function audit(string $action,int $id,int $user,array $details):void{$q=$this->pdo->prepare('CALL sp_auditoria_registrar(:usuario,:transacao,:acao,:entidade,:registro,:resultado,:detalhes)');$q->execute(['usuario'=>$user,'transacao'=>'ContentService','acao'=>$action,'entidade'=>'content_documents','registro'=>(string)$id,'resultado'=>'SUCESSO','detalhes'=>json_encode($details,JSON_UNESCAPED_UNICODE)]);$q->closeCursor();}
}
