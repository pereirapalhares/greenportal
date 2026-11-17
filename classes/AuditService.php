<?php
declare(strict_types=1);

/** Auditoria operacional com correlacao por requisicao e preservacao do tenant. */
final class AuditService
{
    public static function correlationId(): string
    {
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['correlation_id'])) return (string)$_SESSION['correlation_id'];
        $id = bin2hex(random_bytes(16));
        if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['correlation_id'] = $id;
        return $id;
    }

    public static function record(PDO $pdo, string $transaction, string $action, string $result, ?string $entity = null, ?string $recordId = null, array $details = []): void
    {
        $details['correlation_id'] = self::correlationId();
        $details['application'] = 'GreenSolutions';
        $details['version'] = '4.0.1';
        $details['tenant_database'] = (string)($_SESSION['tenant']['banco_dados'] ?? '');
        $sql = 'CALL sp_auditoria_registrar(:usuario,:transacao,:acao,:entidade,:registro,:resultado,:detalhes)';
        try {
            $statement = $pdo->prepare($sql);
            $statement->execute([
                'usuario' => (int)($_SESSION['usuario']['codusuario'] ?? 0) ?: null,
                'transacao' => substr($transaction, 0, 150),
                'acao' => substr($action, 0, 30),
                'entidade' => $entity === null ? null : substr($entity, 0, 100),
                'registro' => $recordId === null ? null : substr($recordId, 0, 100),
                'resultado' => substr($result, 0, 20),
                'detalhes' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $statement->closeCursor();
        } catch (Throwable $exception) {
            error_log('AuditService error: ' . $exception->getMessage());
        }
    }
}
