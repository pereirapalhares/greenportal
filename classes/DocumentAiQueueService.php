<?php
declare(strict_types=1);

require_once __DIR__ . '/IaTaximetro.php';

final class DocumentAiQueueService
{
    public function __construct(private readonly PDO $pdo, private readonly string $processor = 'GreenPortalIaWorker') {}

    /** Migração 340: novo pedido passa pelo limite mensal de IA da empresa (IaLimiteExcedido); 'ignorar_limite' => true só para pedidos já aceitos antes (ex.: eficácia que esperava o OCR). */
    public function enqueue(array $job): array
    {
        if (empty($job['ignorar_limite'])) IaTaximetro::exigirDisponivel($this->pdo);
        $st=$this->pdo->prepare('CALL sp_ia_document_job_enfileirar(:chave,:tipo,:entidade,:registro,:arquivo,:ged,:usuario,:idioma,:prompt,:texto)');
        $st->execute(['chave'=>substr((string)$job['chave_idempotencia'],0,191),'tipo'=>substr((string)$job['tipo'],0,50),'entidade'=>substr((string)$job['entidade'],0,100),'registro'=>substr((string)$job['registro_id'],0,100),'arquivo'=>basename((string)$job['arquivo']),'ged'=>(int)($job['codanalise_ged']??0),'usuario'=>(int)($job['codusuario']??0),'idioma'=>(string)($job['idioma']??'pt'),'prompt'=>(string)$job['prompt'],'texto'=>(string)$job['texto']]);
        $row=$st->fetch(PDO::FETCH_ASSOC)?:null;$st->closeCursor();if(!$row)throw new RuntimeException('Não foi possível localizar o job de IA.');return $row;
    }
    public function find(int $id,int $usuario=0): ?array { $st=$this->pdo->prepare('SELECT * FROM vw_green_ia_document_jobs WHERE codjob=:id AND (:usuario=0 OR codusuario=:usuario)');$st->execute(['id'=>$id,'usuario'=>$usuario]);$r=$st->fetch(PDO::FETCH_ASSOC);return $r?:null; }
    public function processPending(int $limit=3): int { $n=0;while($n<$limit&&($job=$this->claim())!==null){try{IaTaximetro::contexto($this->pdo,['tipo'=>(string)$job['tipo'],'entidade'=>(string)($job['entidade']??''),'registro'=>(string)($job['registro_id']??''),'codjob'=>(int)$job['codjob'],'codusuario'=>(int)($job['codusuario']??0),'origem'=>'FILA']);$r=InteligenciaArtificialDocumento::analisar((string)$job['texto'],(string)$job['prompt'],(string)$job['idioma']);$this->complete((int)$job['codjob'],$r);if(!empty($job['codanalise_ged'])){require_once __DIR__.'/GedAnaliseIaRegistro.php';$g=new GedAnaliseIaRegistro($this->pdo,(int)($job['codusuario']??0));$g->concluir((int)$job['codanalise_ged'],is_array($r['sugestao']??null)?$r['sugestao']:[],null,'IA_WORKER');}}catch(Throwable $e){$this->fail((int)$job['codjob'],$e->getMessage());} IaTaximetro::limpar(); $this->notificar($job); $n++;}return $n; }
    /** Migração 342: pedido de sugestão de risco (ML_RISCO) terminado grava as sugestões dos itens e, no fim do lote, o alerta. Migrações 336/337: pedido de IA da NC (análise da causa ou avaliação da eficácia) terminado (pronto ou erro) vira alerta para quem pediu; falha aqui não para a fila. */
    /** Migração 347: pedido de documento da homologação (fornecedor/funcionário) terminado: se veio da validação automática pelo modelo do documento, grava a análise e avisa o responsável (classes/HomologacaoDocumentoModelo.php). */
    /** Migração 349: pedido DOC_ADULTERACAO terminado (pronto ou erro) conclui a análise de adulteração do arquivo e avisa o responsável quando o risco é médio ou alto (classes/HomologacaoDocumentoAdulteracao.php). */
    private function notificar(array $job): void { if ((string)($job['tipo'] ?? '') === 'DOC_ADULTERACAO') { try { require_once __DIR__ . '/HomologacaoDocumentoAdulteracao.php'; HomologacaoDocumentoAdulteracao::aoConcluirJob($this->pdo, $job); } catch (Throwable $e) { error_log('IA adulteração: ' . $e->getMessage()); } return; } if (in_array((string)($job['tipo'] ?? ''), ['FORNECEDORCRITERIOS', 'FUNCIONARIOCRITERIOS'], true)) { try { require_once __DIR__ . '/HomologacaoDocumentoModelo.php'; HomologacaoDocumentoModelo::aoConcluirJob($this->pdo, $job); } catch (Throwable $e) { error_log('IA modelo de documento: ' . $e->getMessage()); } return; } if ((string)($job['tipo'] ?? '') === 'ML_RISCO') { try { require_once __DIR__ . '/RequisitoRiscoIa.php'; RequisitoRiscoIa::concluirPedido($this->pdo, (int)$job['codjob']); } catch (Throwable $e) { error_log('IA risco: ' . $e->getMessage()); } return; } if (!in_array((string)($job['tipo'] ?? ''), ['NC_ANALISE_CAUSA', 'NC_EFICACIA'], true)) return; try { $st=$this->pdo->prepare('CALL sp_nc_ia_analise_concluida(:id)');$st->execute(['id'=>(int)$job['codjob']]);do{try{$st->fetchAll();}catch(Throwable){}}while($st->nextRowset());$st->closeCursor(); } catch (Throwable $e) { error_log('IA NC alerta: '.$e->getMessage()); } }
    private function claim(): ?array { $st=$this->pdo->prepare('CALL sp_ia_document_job_reservar(:processador)');$st->execute(['processador'=>$this->processor]);$r=$st->fetch(PDO::FETCH_ASSOC);$st->closeCursor();return $r?:null; }
    private function complete(int $id,array $r): void {$st=$this->pdo->prepare('CALL sp_ia_document_job_concluir(:id,:resultado)');$st->execute(['id'=>$id,'resultado'=>json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$st->closeCursor();}
    private function fail(int $id,string $e): void {$st=$this->pdo->prepare('CALL sp_ia_document_job_falhar(:id,:erro)');$st->execute(['id'=>$id,'erro'=>substr($e,0,2000)]);$st->closeCursor();}
}
