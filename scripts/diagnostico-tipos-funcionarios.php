<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) para a transação Tipos de Funcionários (legado foTiposdeFuncionarios, codtransacao 142).
 * Em cada par origem → destino mostra: estrutura e contagem de fo_tipofuncionarios e das tabelas de vínculo
 * (critérios, treinamentos, palavras-chave), os cadastros provisórios "- migração 217" com o uso de cada um,
 * registros só na origem / só no destino / diferentes, e o maior código (para a faixa 100000).
 * Nada é gravado.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-tipos-funcionarios.php
 * Saída: tela + storage/logs/diagnostico_tipos_funcionarios.json
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$pares = [['green_modelo', 'gmodelo_dbp'], ['green_atlas', 'gatlas_dbp'], ['green_modelo', 'gmodelo2_dbp']];
$tabelas = [
    'fo_tipofuncionarios',
    'fo_tipofuncionariosxcriteriofuncionarios',
    'fo_tipofuncionariosxtipotreinamentos',
    'fo_tipofuncionariosxpalavraschave',
    'fo_tipofuncxcriteriofuncxtreinamento',
];

$env = GreenPortalDatabase::env();
$saida = ['diagnostico' => 'tipos de funcionários', 'data' => date('c'), 'bancos' => []];

$linhas = static function (PDO $pdo, string $sql, array $p = []): array {
    $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r;
};
$q = static fn(string $n): string => '`' . str_replace('`', '``', $n) . '`';

foreach ($pares as [$origem, $destino]) {
    $r = ['origem' => $origem];
    try { $pdo = GreenPortalDatabase::connect($env, $destino, 'GPT'); }
    catch (Throwable $e) { if ($destino !== 'gmodelo2_dbp') $saida['bancos'][$destino] = ['erro' => $e->getMessage()]; continue; }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    try {
        // Estrutura e contagem
        foreach ($tabelas as $t) {
            foreach ([$origem, $destino] as $b) {
                $cols = $linhas($pdo, 'SELECT COLUMN_NAME c, COLUMN_TYPE t, COLUMN_KEY k, EXTRA e FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$b, $t]);
                if (!$cols) { $r['tabelas'][$t][$b] = 'NÃO EXISTE'; continue; }
                $n = (int)$pdo->query('SELECT COUNT(*) FROM ' . $q($b) . '.' . $q($t))->fetchColumn();
                $ai = $linhas($pdo, 'SELECT AUTO_INCREMENT a FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$b, $t])[0]['a'] ?? null;
                $r['tabelas'][$t][$b] = [
                    'registros' => $n, 'auto_increment' => $ai,
                    'colunas' => array_map(static fn($c) => $c['c'] . ' ' . $c['t'] . ($c['k'] ? " [{$c['k']}]" : '') . ($c['e'] ? " {$c['e']}" : ''), $cols),
                ];
            }
        }
        // Gatilhos/FKs do destino que tocam as tabelas
        $r['chaves_estrangeiras_destino'] = array_map(static fn($f) => "{$f['TABLE_NAME']}.{$f['COLUMN_NAME']} → {$f['REFERENCED_TABLE_NAME']}.{$f['REFERENCED_COLUMN_NAME']}",
            $linhas($pdo, "SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL AND (TABLE_NAME IN ('" . implode("','", $tabelas) . "') OR REFERENCED_TABLE_NAME = 'fo_tipofuncionarios')", [$destino]));

        // Quem usa CODTIPOFUNCIONARIO no destino
        $usos = array_column($linhas($pdo, "SELECT TABLE_NAME FROM information_schema.COLUMNS c JOIN information_schema.TABLES t USING (TABLE_SCHEMA, TABLE_NAME)
            WHERE c.TABLE_SCHEMA = ? AND c.COLUMN_NAME = 'codtipofuncionario' AND t.TABLE_TYPE = 'BASE TABLE' AND c.TABLE_NAME <> 'fo_tipofuncionarios' AND c.TABLE_NAME NOT LIKE '%bkp%'", [$destino]), 'TABLE_NAME');
        $r['tabelas_que_usam_codtipofuncionario'] = $usos;

        // Provisórios da migração 217 e uso de cada um
        $prov = $linhas($pdo, "SELECT CODTIPOFUNCIONARIO cod, DSCTIPO dsc FROM " . $q($destino) . ".fo_tipofuncionarios WHERE COALESCE(DSCTIPO, '') LIKE '%- migra%o 217' ORDER BY 1");
        foreach ($prov as &$p) {
            $p['na_origem'] = $linhas($pdo, 'SELECT DSCTIPO d FROM ' . $q($origem) . '.fo_tipofuncionarios WHERE CODTIPOFUNCIONARIO = ?', [$p['cod']])[0]['d'] ?? '(não existe)';
            foreach ($usos as $u) {
                $n = (int)$linhas($pdo, 'SELECT COUNT(*) n FROM ' . $q($destino) . '.' . $q($u) . ' WHERE CODTIPOFUNCIONARIO = ?', [$p['cod']])[0]['n'];
                if ($n) $p['uso'][$u] = $n;
            }
            $p['uso'] ??= 'sem uso';
        }
        unset($p);
        $r['provisorios_217'] = $prov;

        // Comparação origem x destino do cadastro
        $r['comparacao'] = [
            'so_na_origem' => $linhas($pdo, 'SELECT o.CODTIPOFUNCIONARIO cod, o.DSCTIPO dsc FROM ' . $q($origem) . '.fo_tipofuncionarios o LEFT JOIN ' . $q($destino) . '.fo_tipofuncionarios d USING (CODTIPOFUNCIONARIO) WHERE d.CODTIPOFUNCIONARIO IS NULL'),
            'so_no_destino' => $linhas($pdo, 'SELECT d.CODTIPOFUNCIONARIO cod, d.DSCTIPO dsc FROM ' . $q($destino) . '.fo_tipofuncionarios d LEFT JOIN ' . $q($origem) . '.fo_tipofuncionarios o USING (CODTIPOFUNCIONARIO) WHERE o.CODTIPOFUNCIONARIO IS NULL'),
            'diferentes' => $linhas($pdo, 'SELECT o.CODTIPOFUNCIONARIO cod, o.DSCTIPO origem, d.DSCTIPO destino FROM ' . $q($origem) . '.fo_tipofuncionarios o JOIN ' . $q($destino) . '.fo_tipofuncionarios d USING (CODTIPOFUNCIONARIO) WHERE NOT (o.DSCTIPO <=> d.DSCTIPO)'),
            'maior_codigo_destino' => $pdo->query('SELECT MAX(CODTIPOFUNCIONARIO) FROM ' . $q($destino) . '.fo_tipofuncionarios')->fetchColumn(),
        ];
        // Amostra de vínculos (para desenhar as telas de correlação)
        foreach (array_slice($tabelas, 1) as $t) {
            if (is_array($r['tabelas'][$t][$destino] ?? null)) {
                $r['amostra'][$t] = $linhas($pdo, 'SELECT * FROM ' . $q($destino) . '.' . $q($t) . ' LIMIT 3');
            }
        }
    } catch (Throwable $e) {
        $r['erro'] = $e->getMessage();
    }
    $saida['bancos'][$destino] = $r;
}

$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents(dirname(__DIR__) . '/storage/logs/diagnostico_tipos_funcionarios.json', $json);
echo $json . PHP_EOL;
