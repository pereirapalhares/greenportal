<?php
declare(strict_types=1);

final class AiApprovedTaskService
{
    public function __construct(private readonly PDO $pdo) {}

    public function enqueueApproved(int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));
        $sql = "SELECT p.codpotencial,p.tipo_potencial,p.titulo,p.descricao,p.justificativa_ia,
                       p.confianca_ia,p.codregistro_destino,s.codusuario,s.contexto_json
                  FROM ml_potenciais p
                  INNER JOIN ml_potenciais_solicitacoes s ON s.codsolicitacao=p.codsolicitacao
                 WHERE p.status='APROVADO'
                   AND s.codusuario IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM green_tasks t
                                    WHERE t.chave_idempotencia=CONCAT('IA-POTENCIAL:',p.codpotencial))
                 ORDER BY p.validado_em,p.codpotencial
                 LIMIT {$limit}";
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $tasks = new TaskService($this->pdo);
        require_once __DIR__ . '/ResponsavelAutomatico.php';
        $auto = ResponsavelAutomatico::disponivel($this->pdo) ? new ResponsavelAutomatico($this->pdo) : null;
        $count = 0;
        foreach ($rows as $row) {
            $context = json_decode((string)($row['contexto_json'] ?? '{}'), true);
            $context = is_array($context) ? $context : [];
            $potentialId = (int)$row['codpotencial'];
            $userId = (int)$row['codusuario'];
            $unitId = max(0, (int)($context['CODUNIDADE'] ?? 0));
            $confidence = is_numeric($row['confianca_ia'] ?? null) ? (float)$row['confianca_ia'] : 0.0;
            $title = trim((string)($row['titulo'] ?? '')) ?: 'Potencial aprovado pela IA';
            $description = trim(implode("\n\n", array_filter([
                'Tipo: ' . (string)($row['tipo_potencial'] ?? '—'),
                (string)($row['descricao'] ?? ''),
                (string)($row['justificativa_ia'] ?? ''),
                'Registro convertido: ' . (string)($row['codregistro_destino'] ?? '—'),
            ])));
            $deadline = $this->deadline($context['DATA_LIMITE'] ?? null);
            // Migração 345 (Gestão de tarefas, etapa 3): o responsável vem do perfil de atribuições (quem executa o pilar na
            // unidade, o menos carregado); sem ninguém no perfil, fica com quem pediu a análise e o ADM DA EMPRESA é avisado
            $solicitante = $userId;
            $escolha = $auto ? $auto->paraTarefa((string)($row['tipo_potencial'] ?? ''), $unitId, $userId) : null;
            if ($escolha) {
                $userId = (int)$escolha['usuario'];
                $description .= "\n\nResponsável: " . ($escolha['encontrado'] ? $escolha['nome'] . ' — ' : '') . $escolha['motivo'];
            }
            $task = $tasks->enqueue([
                'chave_idempotencia' => 'IA-POTENCIAL:' . $potentialId,
                'tipo' => 'IA_APROVACAO',
                'origem_transacao' => 'modulolegal_potenciais.php',
                'origem_entidade' => 'ml_potenciais',
                'origem_id' => (string)$potentialId,
                'codunidade' => $unitId > 0 ? $unitId : null,
                'titulo' => 'Tratar potencial IA: ' . $title,
                'descricao' => $description,
                'prioridade' => $confidence >= 80 ? 8 : 5,
                'codusuario_destino' => $userId,
                'data_limite' => $deadline,
                'criado_por' => $solicitante,
            ]);
            $taskId = (int)$task['codtarefa'];
            if ($escolha) { try { $auto->registrarTarefa($taskId, $title, $unitId, $escolha, $solicitante); } catch (Throwable $e) { error_log('[responsável automático] ' . $e->getMessage()); } }
            $this->alert($userId, $potentialId, $taskId, $title);
            $this->audit($userId, $potentialId, $taskId, $unitId, $row);
            $count++;
        }
        return $count;
    }

    private function alert(int $userId, int $potentialId, int $taskId, string $title): void
    {
        $link = '/principal/painel_pendencias.php?codtarefa=' . $taskId;
        // Não repete o alerta do mesmo link para o mesmo usuário
        $st = $this->pdo->prepare("CALL sp_alerta_criar(:usuario,:titulo,:mensagem,:link,'USUARIO_LINK')");
        $st->execute(['usuario'=>$userId,'titulo'=>'Tarefa criada a partir de IA','mensagem'=>'O potencial aprovado "'.mb_substr($title,0,140).'" gerou uma tarefa para tratamento.','link'=>$link]);
        $st->closeCursor();
    }

    private function audit(int $userId, int $potentialId, int $taskId, int $unitId, array $row): void
    {
        $st = $this->pdo->prepare('CALL sp_auditoria_registrar(:usuario,:transacao,:acao,:entidade,:registro,:resultado,:detalhes)');
        $st->execute(['usuario'=>$userId,'transacao'=>'modulolegal_potenciais.php','acao'=>'CRIAR_TAREFA','entidade'=>'ml_potenciais','registro'=>(string)$potentialId,'resultado'=>'SUCESSO','detalhes'=>json_encode(['codtarefa'=>$taskId,'codunidade'=>$unitId,'tipo_potencial'=>$row['tipo_potencial']??null,'origem'=>'IA_APROVADA','application'=>'GreenSolutions','version'=>'4.0.1'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $st->closeCursor();
    }

    private function deadline(mixed $value): string
    {
        $value = trim((string)$value);
        if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2})?$/', $value)) return strlen($value) === 10 ? $value . ' 23:59:59' : $value;
        return date('Y-m-d H:i:s', time() + 7 * 86400);
    }
}
