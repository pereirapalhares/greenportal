<?php
declare(strict_types=1);

/*
 * Migração 251 — fo_tipofuncionarios do sistema novo igual ao legado.
 * Exclui do destino os tipos que não existem no legado (os provisórios "Função não identificada (código N) -
 * migração 217"), como o legado: os funcionários continuam com o código antigo (o legado não tem chave estrangeira).
 *
 * Pares: green_modelo -> gmodelo_dbp, green_atlas -> gatlas_dbp, green_modelo -> gmodelo2_dbp (se existir).
 * Em cada destino:
 *  1. lista os tipos só no destino com código < 100000 (os >= 100000 são do sistema novo e NUNCA são excluídos);
 *  2. cópia de segurança: bkp251_fo_tipofuncionarios e bkp251_<vínculo> (só as linhas dos códigos excluídos);
 *  3. remove as chaves estrangeiras que apontam para fo_tipofuncionarios (definições gravadas no relatório);
 *  4. retira o gatilho de exclusão (que bloquearia por "em uso"), exclui os vínculos do tipo (critérios,
 *     treinamentos, palavras-chave) e os tipos, e recria o gatilho com a mesma definição;
 *  5. compara origem x destino de novo e registra em registros_migracao (HOMOLOGACAO_TIPOS_FUNCIONARIOS_251).
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-251-tipos-funcionarios-igual-legado.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-251-tipos-funcionarios-igual-legado.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

define('M251_PARES', HomologacaoBancos::pares($argv ?? [])); // [legado, banco] — classes/HomologacaoBancos.php (--banco= para só alguns)
const M251_VINCULOS = ['fo_tipofuncionariosxcriteriofuncionarios', 'fo_tipofuncionariosxtipotreinamentos', 'fo_tipofuncionariosxpalavraschave'];
const M251_LIMITE_NOVO = 100000;

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$linhas = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$valor = static function (PDO $pdo, string $sql, array $p = []) use ($linhas): mixed { $r = $linhas($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; };
$q = static fn(string $n): string => '`' . str_replace('`', '``', $n) . '`';
$existe = static fn(string $b, string $t): bool => (bool)$valor($geral, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'", [$b, $t]);

$relatorio = ['ok' => true, 'migracao' => '251', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach (M251_PARES as [$origem, $destino]) {
    $info = ['origem' => $origem];
    if (!$valor($geral, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$destino])) continue;
    $pdo = null; $gatilho = null; $gatilhoRetirado = false;
    try {
        $pdo = GreenPortalDatabase::connect($env, $destino, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $O = $q($origem) . '.fo_tipofuncionarios';
        $info['antes'] = ['origem' => (int)$valor($pdo, "SELECT COUNT(*) FROM $O"), 'destino' => (int)$valor($pdo, 'SELECT COUNT(*) FROM fo_tipofuncionarios')];
        $alvo = $linhas($pdo, "SELECT d.codtipofuncionario AS cod, d.DSCTIPO AS dsc FROM fo_tipofuncionarios d
            WHERE d.codtipofuncionario < " . M251_LIMITE_NOVO . " AND NOT EXISTS (SELECT 1 FROM $O o WHERE o.codtipofuncionario = d.codtipofuncionario) ORDER BY 1");
        $codigos = array_map('intval', array_column($alvo, 'cod'));
        $lista = implode(',', $codigos ?: [0]);
        $info['a_excluir'] = ['quantidade' => count($codigos), 'tipos' => array_map(static fn($a) => $a['cod'] . ' ' . $a['dsc'], $alvo)];
        $info['novos_mantidos_100000_ou_mais'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM fo_tipofuncionarios WHERE codtipofuncionario >= ' . M251_LIMITE_NOVO);
        $info['diferentes_na_descricao'] = (int)$valor($pdo, "SELECT COUNT(*) FROM fo_tipofuncionarios d JOIN $O o ON o.codtipofuncionario = d.codtipofuncionario
            WHERE NOT (CONVERT(o.DSCTIPO USING utf8mb4) COLLATE utf8mb4_bin <=> CONVERT(d.DSCTIPO USING utf8mb4) COLLATE utf8mb4_bin)");
        $fks = $linhas($pdo, "SELECT k.CONSTRAINT_NAME AS nome, k.TABLE_NAME AS tabela, k.COLUMN_NAME AS coluna, k.REFERENCED_COLUMN_NAME AS ref, r.UPDATE_RULE AS upd, r.DELETE_RULE AS del
            FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
            WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = 'fo_tipofuncionarios'");
        $info['chaves_estrangeiras'] = array_map(static fn($f) => "{$f['tabela']}.{$f['coluna']} -> fo_tipofuncionarios.{$f['ref']} ({$f['nome']}; ON UPDATE {$f['upd']} ON DELETE {$f['del']})", $fks);
        $uso = [];
        foreach (['fo_funcionarios', 'fo_funcionariosxtipofuncionarios', 'fo_funcionarioxtiposxcriterios', 'fo_funcionarioxtiposxtreinamentos'] as $t) {
            if ($existe($destino, $t)) $uso[$t] = (int)$valor($pdo, "SELECT COUNT(*) FROM $t WHERE codtipofuncionario IN ($lista)");
        }
        $info['funcionarios_que_ficam_com_o_codigo_antigo'] = $uso;
        if ($simular || !$codigos) { if (!$codigos) $info['situacao'] = 'já igual ao legado'; $relatorio['bancos'][$destino] = $info; continue; }

        // 2. Cópias
        if (!$existe($destino, 'bkp251_fo_tipofuncionarios')) $pdo->exec("CREATE TABLE bkp251_fo_tipofuncionarios AS SELECT * FROM fo_tipofuncionarios WHERE codtipofuncionario IN ($lista)");
        else $pdo->exec("INSERT INTO bkp251_fo_tipofuncionarios SELECT * FROM fo_tipofuncionarios t WHERE t.codtipofuncionario IN ($lista) AND NOT EXISTS (SELECT 1 FROM bkp251_fo_tipofuncionarios b WHERE b.codtipofuncionario = t.codtipofuncionario)");
        foreach (M251_VINCULOS as $v) {
            if (!$existe($destino, $v)) continue;
            $b = 'bkp251_' . $v;
            if (!$existe($destino, $b)) $pdo->exec("CREATE TABLE $b AS SELECT * FROM $v WHERE codtipofuncionario IN ($lista)");
        }
        $info['copias'] = 'bkp251_fo_tipofuncionarios e bkp251_<vínculos>';

        // 3. Chaves estrangeiras (como no legado, sem chave estrangeira para o tipo)
        foreach ($fks as $f) {
            $pdo->exec('ALTER TABLE ' . $q($f['tabela']) . ' DROP FOREIGN KEY ' . $q($f['nome']));
            $info['chaves_removidas'][] = $f['nome'];
        }

        // 4. Gatilho fora, exclusões, gatilho de volta
        $g = $linhas($pdo, "SHOW TRIGGERS WHERE `Trigger` = 'trg_fo_tipofuncionarios_antes_excluir'");
        if ($g) {
            $def = $linhas($pdo, 'SHOW CREATE TRIGGER trg_fo_tipofuncionarios_antes_excluir')[0] ?? [];
            $gatilho = (string)($def['SQL Original Statement'] ?? '');
            $gatilho = preg_replace('/^CREATE\s+DEFINER\s*=\s*\S+\s+TRIGGER/i', 'CREATE TRIGGER', $gatilho) ?? $gatilho;
            if ($gatilho === '') throw new RuntimeException('não foi possível ler a definição do gatilho');
            $pdo->exec('DROP TRIGGER trg_fo_tipofuncionarios_antes_excluir');
            $gatilhoRetirado = true;
        }
        $pdo->beginTransaction();
        $excl = [];
        foreach (M251_VINCULOS as $v) if ($existe($destino, $v)) $excl[$v] = $pdo->exec("DELETE FROM $v WHERE codtipofuncionario IN ($lista)");
        $excl['fo_tipofuncionarios'] = $pdo->exec("DELETE FROM fo_tipofuncionarios WHERE codtipofuncionario IN ($lista) AND codtipofuncionario < " . M251_LIMITE_NOVO);
        $pdo->commit();
        $info['excluidos'] = $excl;
    } catch (Throwable $e) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
    } finally {
        if ($pdo && $gatilhoRetirado && $gatilho) {
            try { $pdo->exec($gatilho); $info['gatilho'] = 'recriado'; }
            catch (Throwable $e) { $relatorio['ok'] = false; $info['gatilho'] = 'ERRO ao recriar: ' . $e->getMessage() . ' — rode a migração 248 --cadastro=fo_tipofuncionarios'; }
        }
    }
    if (!$simular && empty($info['erro']) && $pdo) {
        $O = $q($origem) . '.fo_tipofuncionarios';
        $info['depois'] = ['origem' => (int)$valor($pdo, "SELECT COUNT(*) FROM $O"), 'destino' => (int)$valor($pdo, 'SELECT COUNT(*) FROM fo_tipofuncionarios'),
            'so_no_destino_legado' => (int)$valor($pdo, "SELECT COUNT(*) FROM fo_tipofuncionarios d WHERE d.codtipofuncionario < " . M251_LIMITE_NOVO . " AND NOT EXISTS (SELECT 1 FROM $O o WHERE o.codtipofuncionario = d.codtipofuncionario)"),
            'so_na_origem' => (int)$valor($pdo, "SELECT COUNT(*) FROM $O o WHERE NOT EXISTS (SELECT 1 FROM fo_tipofuncionarios d WHERE d.codtipofuncionario = o.codtipofuncionario)")];
        if ($valor($geral, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'registros_migracao'", [$destino])) {
            $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
                VALUES ('HOMOLOGACAO_TIPOS_FUNCIONARIOS_251', ?, ?, 'DADOS', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
                ->execute(["$origem.fo_tipofuncionarios", "$destino.fo_tipofuncionarios", (int)($info['excluidos']['fo_tipofuncionarios'] ?? 0), json_encode($info, JSON_UNESCAPED_UNICODE)]);
        }
    }
    $relatorio['bancos'][$destino] = $info;
}

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_251_tipos_funcionarios' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
