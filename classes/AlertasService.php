<?php
declare(strict_types=1);

final class AlertasService
{
    public function __construct(private PDO $pdo, private int $codusuario)
    {
    }

    private function call(string $procedure, array $params): array
    {
        $statement = $this->pdo->prepare($procedure);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        return $rows;
    }

    public function listar(int $limite = 50): array
    {
        $alerts = $this->call('CALL sp_crud_select(:tabela, :filtros, NULL, :limite, 0)', [
            'tabela' => 'vw_alertas_grid',
            'filtros' => json_encode(['codusuario' => $this->codusuario], JSON_UNESCAPED_UNICODE),
            'limite' => max(1, min(500, $limite)),
        ]);
        usort($alerts, static function (array $a, array $b): int {
            $dateA = (string)($a['criado_em'] ?? $a['data_criacao'] ?? '');
            $dateB = (string)($b['criado_em'] ?? $b['data_criacao'] ?? '');
            $comparison = strcmp($dateB, $dateA);
            return $comparison !== 0
                ? $comparison
                : ((int)($b['codalerta'] ?? 0) <=> (int)($a['codalerta'] ?? 0));
        });
        return $alerts;
    }

    /** Marca como lido um alerta do próprio usuário (abriu o link). */
    public function marcarLido(int $codalerta): bool
    {
        if ($codalerta <= 0) return false;
        $doUsuario = $this->call('CALL sp_crud_select(:tabela, :filtros, NULL, 1, 0)', [
            'tabela' => 'vw_alertas_grid',
            'filtros' => json_encode(['codusuario' => $this->codusuario, 'codalerta' => $codalerta], JSON_UNESCAPED_UNICODE),
        ]);
        if (!$doUsuario || (int)($doUsuario[0]['codalerta'] ?? 0) !== $codalerta) return false;
        if ((int)($doUsuario[0]['lido'] ?? 0) === 1) return true;
        $this->call('CALL sp_crud_update(:tabela, :chave, :dados)', [
            'tabela' => 'alertas',
            'chave' => json_encode(['codalerta' => $codalerta], JSON_UNESCAPED_UNICODE),
            'dados' => json_encode(['lido' => 1], JSON_UNESCAPED_UNICODE),
        ]);
        return true;
    }

    public function limpar(): int
    {
        $alerts = $this->listar(500);
        foreach ($alerts as $alert) {
            $this->call('CALL sp_crud_delete(:tabela, :chave)', [
                'tabela' => 'alertas',
                'chave' => json_encode(['codalerta' => (int)$alert['codalerta']], JSON_UNESCAPED_UNICODE),
            ]);
        }
        return count($alerts);
    }
}
