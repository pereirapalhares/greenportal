<?php
declare(strict_types=1);

final class LegislacaoGridService
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->exec('SET SESSION group_concat_max_len = 16777216');
    }

    public function call(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        return $rows;
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
