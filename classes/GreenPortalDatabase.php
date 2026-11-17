<?php
declare(strict_types=1);

final class GreenPortalDatabase
{
    public static function env(): array
    {
        $values = [];
        $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim(trim($value), "\"'");
        }
        return $values;
    }

    public static function connect(array $env, string $database, string $prefix): PDO
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new InvalidArgumentException('Nome de banco de dados invalido.');
        }
        $dsn = 'mysql:host=' . ($env[$prefix . '_DB_HOST'] ?? '127.0.0.1') .
            ';port=' . ($env[$prefix . '_DB_PORT'] ?? '3306') .
            ';dbname=' . $database . ';charset=utf8mb4';
        return new PDO($dsn, $env[$prefix . '_DB_USERNAME'] ?? '', $env[$prefix . '_DB_PASSWORD'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Tempo máximo para conectar (c584): com o MySQL sem responder, a tela ou o serviço recebe o erro em vez de esperar para sempre
            PDO::ATTR_TIMEOUT => max(2, (int)($env['DB_CONNECT_TIMEOUT'] ?? 10)),
            // Migração 359: quem está gravando (para o histórico obrigatório gravado pelos gatilhos trg_hist_*)
            self::atributoInit() => self::initCommand(),
        ]);
    }

    /**
     * Migração 359 (históricos obrigatórios): define @gp_usuario (usuário logado) e @gp_origem ("tela:<arquivo>" no portal,
     * "cli:<script>" nas rotinas) na conexão; os gatilhos trg_hist_* gravam esses valores no historico_alteracoes.
     */
    /** Constante do comando inicial do MySQL: Pdo\Mysql::ATTR_INIT_COMMAND (PHP 8.4+; a de PDO é obsoleta no 8.5). */
    public static function atributoInit(): int
    {
        return defined('Pdo\\Mysql::ATTR_INIT_COMMAND') ? (int)constant('Pdo\\Mysql::ATTR_INIT_COMMAND') : (int)constant('PDO::MYSQL_ATTR_INIT_COMMAND');
    }

    public static function initCommand(): string
    {
        $uid = isset($_SESSION) && is_array($_SESSION) ? (int)($_SESSION['usuario']['codusuario'] ?? 0) : 0;
        $script = (string)preg_replace('/[^A-Za-z0-9_.\-]/', '', basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '')));
        $origem = (PHP_SAPI === 'cli' ? 'cli:' : 'tela:') . ($script !== '' ? $script : '?');
        return 'SET @gp_usuario = ' . ($uid > 0 ? (string)$uid : 'NULL') . ", @gp_origem = '" . substr($origem, 0, 120) . "'";
    }

    public static function call(PDO $pdo, string $procedure, array $params = []): array
    {
        $statement = $pdo->prepare($procedure);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        return $rows;
    }
}
