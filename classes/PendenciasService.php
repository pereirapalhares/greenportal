<?php
declare(strict_types=1);

/** Consulta transversal de pendências restrita ao usuário e ao tenant atual. */
final class PendenciasService
{
    public function __construct(private readonly PDO $pdo) {}

    public function listar(int $userId, int $limit = 200): array
    {
        if ($userId <= 0) return [];
        $limit = max(1, min(1000, $limit)); $items = [];
        $queries = [
            ['sql'=>"SELECT codtarefa AS id,titulo,descricao,data_limite AS vencimento,status,'TAREFA' AS tipo,origem_transacao AS transacao FROM green_tasks WHERE codusuario_destino=:usuario AND status IN ('PENDENTE','PROCESSANDO') ORDER BY data_limite IS NULL,data_limite,prioridade DESC,criado_em LIMIT {$limit}",'tipo'=>'TAREFA'],
            ['sql'=>"SELECT codeventoagenda AS id,titulo,descricao,inicio AS vencimento,status,'AGENDA' AS tipo,origem_transacao AS transacao FROM agenda_eventos WHERE codusuario_responsavel=:usuario AND status='ATIVO' AND inicio<=DATE_ADD(CURRENT_TIMESTAMP,INTERVAL 30 DAY) ORDER BY inicio LIMIT {$limit}",'tipo'=>'AGENDA'],
            ['sql'=>"SELECT CODORIGEM AS id,CONCAT('Responsabilidade ',MODULO_NOME) AS titulo,CONCAT('Vencimento em ',DATE_FORMAT(VALIDO_ATE,'%d/%m/%Y')) AS descricao,VALIDO_ATE AS vencimento,'ATIVA' AS status,'RESPONSABILIDADE' AS tipo,'responsabilidade_worker.php' AS transacao FROM vw_responsabilidade_efetiva WHERE CODUSUARIO_DESTINO=:usuario AND VALIDO_ATE IS NOT NULL AND VALIDO_ATE<=DATE_ADD(CURRENT_DATE,INTERVAL 30 DAY) ORDER BY VALIDO_ATE LIMIT {$limit}",'tipo'=>'RESPONSABILIDADE'],
            ['sql'=>"SELECT codalerta AS id,titulo,mensagem AS descricao,criado_em AS vencimento,'NOVO' AS status,'ALERTA' AS tipo,'painel_aprovacoes.php' AS transacao FROM vw_alertas_grid WHERE codusuario=:usuario AND lido=0 ORDER BY criado_em DESC LIMIT {$limit}",'tipo'=>'ALERTA'],
        ];
        // Migração 380 (GER-8): com transferência de responsabilidades em vigor, tarefas e agenda (módulo AGENDA) e as
        // responsabilidades da origem vão para o destino; os alertas já chegam redirecionados (gatilho na tabela alertas).
        $donos = [$userId => ''];
        if (is_file(__DIR__ . '/TransferenciaResponsabilidade.php')) {
            require_once __DIR__ . '/TransferenciaResponsabilidade.php';
            try {
                foreach (TransferenciaResponsabilidade::recebidas($this->pdo, $userId) as $t)
                    if (in_array('AGENDA', TransferenciaResponsabilidade::modulosDe($t), true)) $donos[(int)$t['CODUSUARIO_ORIGEM']] = (string)$t['ORIGEM'];
                $transferiuAgenda = TransferenciaResponsabilidade::destinoDe($this->pdo, $userId, 'AGENDA') !== null;
            } catch (Throwable) { $transferiuAgenda = false; }
        } else $transferiuAgenda = false;
        foreach ($queries as $query) {
            foreach ($donos as $dono => $nomeDono) {
                if ($query['tipo'] === 'ALERTA' && $dono !== $userId) continue;
                if ($query['tipo'] !== 'ALERTA' && $dono === $userId && $transferiuAgenda) continue;
                $s=$this->pdo->prepare($query['sql']); $s->execute(['usuario'=>$dono]);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) { if ($nomeDono !== '') $row['titulo'] = (string)$row['titulo'] . ' (de ' . $nomeDono . ')'; $items[]=$this->item($row,$query['tipo']); }
            }
        }
        usort($items,static fn(array $a,array $b):int=>strcmp($a['vencimento_ordenacao'],$b['vencimento_ordenacao'])); return $items;
    }

    public function contar(int $userId): array { $counts=['TAREFA'=>0,'AGENDA'=>0,'RESPONSABILIDADE'=>0,'ALERTA'=>0,'TOTAL'=>0]; foreach($this->listar($userId,1000) as $item){$counts[$item['tipo']]++;$counts['TOTAL']++;} return $counts; }
    private function item(array $row,string $type):array{$due=trim((string)($row['vencimento']??''));return ['id'=>(int)($row['id']??0),'tipo'=>$type,'titulo'=>(string)($row['titulo']??''),'descricao'=>(string)($row['descricao']??''),'status'=>(string)($row['status']??''),'vencimento'=>$due,'vencimento_ordenacao'=>$due===''?'9999-12-31 23:59:59':$due,'transacao'=>(string)($row['transacao']??'')];}
}
