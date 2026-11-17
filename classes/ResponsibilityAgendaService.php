<?php
declare(strict_types=1);

require_once __DIR__ . '/AgendaService.php';

/** Sincroniza responsabilidades dos cinco pilares com a agenda universal. */
final class ResponsibilityAgendaService
{
    public function __construct(private readonly PDO $pdo, private readonly AgendaService $agenda)
    {
    }

    /**
     * Cria/atualiza agendas para os responsáveis efetivos e cancela agendas
     * que deixaram de existir, sem apagar o histórico.
     */
    public function synchronizeFivePillars(): array
    {
        $definitions = [
            'LICENCAS' => [
                'view' => 'vw_minhas_licencas_grid', 'key' => 'CODLICENCA', 'title' => 'DSCLICENCA',
                'dates' => ['DATA_REACAO', 'DATA_VALIDADE'], 'entity' => 'li_licencas',
                'transaction' => 'manutencaolegal_licencas.php', 'label' => 'Licença',
            ],
            'MEDICAO_MONITORAMENTO' => [
                'view' => 'vw_minhas_monitoramentos_grid', 'key' => 'CODMONITORAMENTO', 'title' => 'PARAMETROS',
                'dates' => ['DATA_REACAO', 'DATA_MEDICAO'], 'entity' => 'mm_monitoramento',
                'transaction' => 'medicaoemonitoramento_mm_monitoramento.php', 'label' => 'Medição e Monitoramento',
            ],
            'TREINAMENTO' => [
                'view' => 'vw_minhas_treinamentos_grid', 'key' => 'CODTREINAMENTO', 'title' => 'DSCTREINAMENTO',
                'dates' => [], 'entity' => 'ml_treinamentos',
                'transaction' => 'modulolegal_treinamentos.php', 'label' => 'Treinamento',
            ],
            'INFRAESTRUTURA' => [
                'view' => 'vw_minhas_infraestrutura_grid', 'key' => 'CODINFRAESTRUTURA', 'title' => 'DSCINFRAESTRUTURA',
                'dates' => [], 'entity' => 'ml_infraestrutura',
                'transaction' => 'modulolegal_InfraEstrutura.php', 'label' => 'Infraestrutura',
            ],
            'CONTROLE' => [
                'view' => 'vw_meus_controles_grid', 'key' => 'CODDOCUMENTO', 'title' => 'TITULO',
                'dates' => [], 'entity' => 'documentos',
                'transaction' => 'modulolegal_controle.php', 'label' => 'Controle',
            ],
        ];

        $desired = [];
        $syncedPrefixes = [];
        $createdOrUpdated = 0;
        $cancelled = 0;
        $errors = [];
        $activeUsers = [];
        try {
            $activeUsers = array_fill_keys(array_map('intval', $this->pdo->query('SELECT codusuario FROM usuarios WHERE ativo=1')->fetchAll(PDO::FETCH_COLUMN)), true);
        } catch (Throwable $exception) {
            return ['criados_ou_atualizados' => 0, 'cancelados' => 0, 'erros' => ['usuarios' => $exception->getMessage()]];
        }

        foreach ($definitions as $pillar => $definition) {
            $prefix = 'RESPONSABILIDADE_PILAR:' . $pillar . ':';
            try {
                $rows = $this->pdo->query('SELECT * FROM `' . $definition['view'] . '`')->fetchAll(PDO::FETCH_ASSOC);
                $syncedPrefixes[] = $prefix;
            } catch (Throwable $exception) {
                // Mantém agendas existentes quando um tenant ainda não possui
                // uma view opcional do pilar.
                $errors[$pillar] = $exception->getMessage();
                continue;
            }

            foreach ($rows as $row) {
                $recordId = (int)($row[$definition['key']] ?? 0);
                $userId = (int)($row['CODUSUARIO'] ?? 0);
                if ($recordId <= 0 || $userId <= 0 || !isset($activeUsers[$userId])) continue;

                $sourceKey = $prefix . $recordId . ':' . $userId;
                $desired[$sourceKey] = true;
                $start = $this->firstDate($row, $definition['dates']);
                $title = trim((string)($row[$definition['title']] ?? ''));
                if ($title === '') $title = $definition['label'] . ' #' . $recordId;
                $description = 'Responsabilidade de ' . $definition['label'] . ': ' . $title;

                try {
                    $this->agenda->syncSource($sourceKey, [
                        'codusuario_responsavel' => $userId,
                        'titulo' => mb_substr($definition['label'] . ': ' . $title, 0, 180),
                        'descricao' => $description,
                        'inicio' => $start,
                        'origem_transacao' => $definition['transaction'],
                        'origem_entidade' => $definition['entity'],
                        'origem_id' => (string)$recordId,
                    ], true);
                    $createdOrUpdated++;
                } catch (Throwable $exception) {
                    $errors[$sourceKey] = $exception->getMessage();
                }
            }
        }

        foreach ($syncedPrefixes as $prefix) {
            $statement = $this->pdo->prepare("SELECT chave_idempotencia FROM agenda_eventos WHERE chave_idempotencia LIKE :prefix AND status='ATIVO'");
            $statement->execute(['prefix' => $prefix . '%']);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $sourceKey) {
                $sourceKey = (string)$sourceKey;
                if (isset($desired[$sourceKey])) continue;
                try {
                    $cancelled += $this->agenda->cancelSource($sourceKey, true);
                } catch (Throwable $exception) {
                    $errors[$sourceKey] = $exception->getMessage();
                }
            }
        }

        return [
            'criados_ou_atualizados' => $createdOrUpdated,
            'cancelados' => $cancelled,
            'erros' => $errors,
        ];
    }

    private function firstDate(array $row, array $fields): string
    {
        foreach ($fields as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value === '' || str_starts_with($value, '0000-00-00')) continue;
            $value = str_replace('T', ' ', $value);
            foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y'] as $format) {
                $date = DateTimeImmutable::createFromFormat($format, $value);
                $check = DateTimeImmutable::getLastErrors();
                if ($date && ($check === false || (($check['warning_count'] ?? 0) === 0 && ($check['error_count'] ?? 0) === 0))) {
                    return $date->format(strlen($format) <= 5 ? 'Y-m-d 09:00:00' : 'Y-m-d H:i:s');
                }
            }
        }
        return date('Y-m-d 09:00:00');
    }
}
