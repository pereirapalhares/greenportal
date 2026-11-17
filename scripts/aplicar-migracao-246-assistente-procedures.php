<?php
declare(strict_types=1);

/*
 * Migração 246 — Assistente de IA e Solicitações de melhoria passam a acessar o banco somente por views e procedures.
 *   - guniversal_dbp: database/246_assistente_procedures_universal.sql (ajuda, menus, guia do usuário).
 *   - cada banco de cliente com o assistente (migrações 225/230): database/246_assistente_procedures.sql.
 * Não altera tabelas nem dados. As classes AssistenteAplicacaoService e SolicitacaoMelhoriaService e a API
 * principal/api/assistente_ia.php desta versão dependem destes objetos: rode a migração antes de usar o assistente.
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-246-assistente-procedures.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-246-assistente-procedures.php
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$comandos = static function (string $arquivo): array {
    $sql = (string)file_get_contents(dirname(__DIR__) . '/database/' . $arquivo);
    $saida = [];
    foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
        $limpo = trim(preg_replace('/^\s*--.*$/m', '', $bloco) ?? '');
        if ($limpo !== '') $saida[] = $limpo;
    }
    return $saida;
};
$objetos = static function (array $comandos): array {
    $nomes = [];
    foreach ($comandos as $c) if (preg_match('/^CREATE\s+(?:OR\s+REPLACE\s+)?(VIEW|PROCEDURE)\s+`([^`]+)`/i', $c, $m)) $nomes[] = strtoupper($m[1]) . ' ' . $m[2];
    return $nomes;
};
$executar = static function (PDO $pdo, array $comandos): void { foreach ($comandos as $c) $pdo->exec($c); };
$conferir = static function (PDO $pdo, array $nomes): array {
    $faltando = [];
    foreach ($nomes as $n) {
        [$tipo, $nome] = explode(' ', $n, 2);
        $sql = $tipo === 'VIEW'
            ? 'SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            : "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = 'PROCEDURE' AND ROUTINE_NAME = ?";
        $q = $pdo->prepare($sql); $q->execute([$nome]);
        if (!(int)$q->fetchColumn()) $faltando[] = $n;
    }
    return $faltando;
};

$relatorio = ['ok' => true, 'migracao' => '246', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal' => [], 'bancos' => []];

// guniversal_dbp
$cmdU = $comandos('246_assistente_procedures_universal.sql');
$objU = $objetos($cmdU);
try {
    $universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
    $universal->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    if (!$simular) $executar($universal, $cmdU);
    $relatorio['universal'] = ['objetos' => $objU] + ($simular ? [] : ['faltando' => $conferir($universal, $objU) ?: 'nenhum']);
    if (!$simular && is_array($relatorio['universal']['faltando'])) $relatorio['ok'] = false;
} catch (Throwable $e) {
    $relatorio['ok'] = false;
    $relatorio['universal'] = ['erro' => $e->getMessage()];
}

// bancos de clientes
$cmdT = $comandos('246_assistente_procedures.sql');
$objT = $objetos($cmdT);
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$necessarias = ['ia_assistente_config', 'ia_assistente_conversas', 'ia_assistente_mensagens', 'solicitacoes_melhoria', 'solicitacoes_melhoria_historico', 'logs_auditoria', 'alertas', 'usuarios'];
$lista = "'" . implode("','", $necessarias) . "'";
$bancos = $geral->query("SELECT TABLE_SCHEMA, COUNT(DISTINCT TABLE_NAME) n FROM information_schema.TABLES WHERE TABLE_NAME IN ($lista) AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING SUM(TABLE_NAME = 'ia_assistente_config') > 0 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($bancos as $banco => $n) {
    $info = [];
    try {
        if ((int)$n < count($necessarias)) {
            $q = $geral->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ($lista)");
            $q->execute([$banco]);
            throw new RuntimeException('faltam tabelas: ' . implode(', ', array_diff($necessarias, $q->fetchAll(PDO::FETCH_COLUMN))) . ' (rode antes as migrações 225 e 230)');
        }
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $info['objetos'] = count($objT);
        if (!$simular) {
            $executar($pdo, $cmdT);
            $faltando = $conferir($pdo, $objT);
            $info['faltando'] = $faltando ?: 'nenhum';
            if ($faltando) $relatorio['ok'] = false;
            $q = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registros_migracao'");
            if ((int)$q->fetchColumn()) {
                $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
                    VALUES ('ASSISTENTE_PROCEDURES_246', 'SQL nas classes do assistente', 'views e procedures', 'ESTRUTURA', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                    ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
                    ->execute([count($objT), json_encode(['objetos' => $objT, 'universal' => $objU], JSON_UNESCAPED_UNICODE)]);
            }
        }
    } catch (Throwable $e) {
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $relatorio['bancos'][$banco] = $info;
}
if (!$bancos) { $relatorio['ok'] = false; $relatorio['erro'] = 'nenhum banco de cliente com o assistente (migração 225)'; }
$relatorio['objetos_por_cliente'] = $objT;

echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
