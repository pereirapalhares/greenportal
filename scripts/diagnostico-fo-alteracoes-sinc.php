<?php
declare(strict_types=1);

/*
 * Diagnóstico SOMENTE LEITURA: investiga por que o sincronismo fo_* alterou tantos registros no
 * Modelo e no Modelo2 (gmodelo_dbp / gmodelo2_dbp). Não grava nada.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-fo-alteracoes-sinc.php > diagnostico-sinc.json
 */

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$pdo = new PDO('mysql:host=' . ($env['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['GPT_DB_PORT'] ?? '3306') . ';charset=utf8mb4', $env['GPT_DB_USERNAME'] ?? '', $env['GPT_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$q = static function (string $sql, array $p = []) use ($pdo): array { try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); } catch (Throwable $e) { return ['ERRO' => $e->getMessage()]; } };
$out = [];
$bancos = ['green_modelo', 'gmodelo_dbp', 'gmodelo2_dbp', 'green_atlas', 'gatlas_dbp'];

// 1) Migrações registradas depois da migração das fo_* (procura conversões de texto/charset/datas).
foreach (['gmodelo_dbp', 'gmodelo2_dbp', 'gatlas_dbp'] as $db) {
    $out['registros_migracao'][$db] = $q("SELECT codigo_migracao, tipo, status, quantidade_registros, concluida_em, LEFT(detalhes, 200) detalhes FROM `$db`.registros_migracao ORDER BY codregistromigracao DESC LIMIT 60");
}
$out['universal_migrations'] = $q('SELECT codigo_migracao, status, executado_em, LEFT(detalhes, 200) detalhes FROM guniversal_dbp.green_universal_migrations ORDER BY 1 DESC LIMIT 40');

// 2) Charset/collation das colunas de texto das tabelas fo_* em cada banco.
foreach ($bancos as $db) {
    $out['charset_colunas'][$db] = $q("SELECT CHARACTER_SET_NAME, COLLATION_NAME, COUNT(*) colunas FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'fo\\_%' AND CHARACTER_SET_NAME IS NOT NULL GROUP BY 1, 2", [$db]);
}

// 3) Amostras de texto (tabelas de referência pequenas) nos três bancos do Modelo, em hexadecimal para ver a codificação.
foreach (['fo_tipofornecedores' => ['codtipofornecedor', 'dsctipo'], 'fo_criteriofornecedores' => ['codcriteriofornecedor', 'dsccriterio'], 'fo_tipofuncionarios' => ['codtipofuncionario', 'dsctipo']] as $t => [$k, $c]) {
    foreach (['green_modelo', 'gmodelo_dbp', 'gmodelo2_dbp'] as $db) {
        $cols = array_map('strtolower', array_column($q('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t]), 'COLUMN_NAME'));
        $txt = in_array($c, $cols, true) ? $c : ($cols[1] ?? $k);
        $out['amostras'][$t][$db] = $q("SELECT `$k` id, `$txt` texto, HEX(`$txt`) hex FROM `$db`.`$t` WHERE `$txt` REGEXP '[^ -~]' ORDER BY `$k` LIMIT 5");
    }
}

// 4) Sinais de texto com acentuação quebrada (mojibake) e datas zeradas por banco.
foreach ($bancos as $db) {
    $out['mojibake_fornecedores'][$db] = $q("SELECT COUNT(*) n FROM `$db`.fo_fornecedores WHERE razaosocial LIKE '%Ã%' OR razaosocial LIKE '%Â%' OR razaosocial LIKE '%?%'");
    $out['mojibake_criterios'][$db] = $q("SELECT COUNT(*) n FROM `$db`.fo_criteriofornecedores WHERE CONCAT_WS(' ', dsccriterio) LIKE '%Ã%' OR CONCAT_WS(' ', dsccriterio) LIKE '%?%'");
    $out['datas_zeradas_funcionarios'][$db] = $q("SELECT SUM(DATA_VALIDADE = '0000-00-00') validade_zerada, SUM(DATA_VALIDADE IS NULL) validade_nula FROM `$db`.fo_funcionarios");
}

// 5) Exclusões feitas pelo sincronismo (cópias guardadas).
foreach (['gmodelo_dbp', 'gmodelo2_dbp', 'gatlas_dbp'] as $db) {
    $out['sinc_exclusoes'][$db] = $q("SELECT tabela, COUNT(*) n, MAX(excluido_em) ultima FROM `$db`.fo_sinc_exclusoes GROUP BY tabela");
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
