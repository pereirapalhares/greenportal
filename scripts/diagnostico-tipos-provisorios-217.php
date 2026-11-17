<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) — origem dos tipos de funcionário provisórios "Função não identificada (código N) -
 * migração 217" de gatlas_dbp. Procura o nome original de cada código:
 *  1. em green_modelo.fo_tipofuncionarios (mesmo código);
 *  2. em TODOS os bancos do servidor que tenham uma tabela com a coluna codtipofuncionario e alguma coluna de texto
 *     (cópias de segurança, históricos, tabelas de funcionários com cargo/função etc.);
 *  3. em green_atlas: funcionários que usam o código (nome, fornecedor, datas) e os critérios/treinamentos que o
 *     tipo tinha, para ajudar a identificar a função;
 *  4. tipos existentes com os mesmos critérios e treinamentos (candidatos a "mesma função").
 * Nada é gravado. Saída: tela + storage/logs/diagnostico_tipos_provisorios_217.json
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-tipos-provisorios-217.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'gatlas_dbp', 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
$linhas = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$q = static fn(string $n): string => '`' . str_replace('`', '``', $n) . '`';

$prov = $linhas("SELECT codtipofuncionario AS cod FROM gatlas_dbp.fo_tipofuncionarios WHERE COALESCE(DSCTIPO, '') LIKE '%- migra%o 217' ORDER BY 1");
$codigos = array_map('intval', array_column($prov, 'cod'));
$lista = implode(',', $codigos ?: [0]);
$saida = ['diagnostico' => 'tipos provisórios 217', 'data' => date('c'), 'provisorios' => count($codigos), 'fontes' => [], 'por_codigo' => []];
foreach ($codigos as $c) $saida['por_codigo'][$c] = ['nomes_encontrados' => []];

// 1 e 2. Qualquer tabela do servidor com codtipofuncionario + coluna de texto
$tabelas = $linhas("SELECT c.TABLE_SCHEMA s, c.TABLE_NAME t, c.COLUMN_NAME k FROM information_schema.COLUMNS c
    JOIN information_schema.TABLES tb ON tb.TABLE_SCHEMA = c.TABLE_SCHEMA AND tb.TABLE_NAME = c.TABLE_NAME AND tb.TABLE_TYPE = 'BASE TABLE'
    WHERE LOWER(c.COLUMN_NAME) = 'codtipofuncionario' AND c.TABLE_SCHEMA NOT IN ('mysql','sys','information_schema','performance_schema','gatlas_dbp')
    ORDER BY 1, 2");
foreach ($tabelas as $tb) {
    $texto = $linhas("SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND DATA_TYPE IN ('varchar','char','text','mediumtext','longtext','tinytext')
        AND (LOWER(COLUMN_NAME) REGEXP 'dsc|tipo|func|cargo|descr|nome_tipo|ocupa') ORDER BY ORDINAL_POSITION", [$tb['s'], $tb['t']]);
    $fonte = "{$tb['s']}.{$tb['t']}";
    try {
        $n = (int)$linhas('SELECT COUNT(*) n FROM ' . $q($tb['s']) . '.' . $q($tb['t']) . ' WHERE ' . $q($tb['k']) . " IN ($lista)")[0]['n'];
    } catch (Throwable $e) { $saida['fontes'][$fonte] = 'erro: ' . $e->getMessage(); continue; }
    $saida['fontes'][$fonte] = ['linhas_com_os_codigos' => $n, 'colunas_texto' => array_column($texto, 'c')];
    if (!$n || !$texto) continue;
    foreach ($texto as $col) {
        $r = $linhas('SELECT ' . $q($tb['k']) . ' AS cod, ' . $q($col['c']) . ' AS nome, COUNT(*) AS qtd FROM ' . $q($tb['s']) . '.' . $q($tb['t'])
            . ' WHERE ' . $q($tb['k']) . " IN ($lista) AND COALESCE(" . $q($col['c']) . ", '') <> '' AND " . $q($col['c']) . " NOT LIKE '%migra%o 217%'"
            . ' GROUP BY 1, 2 ORDER BY 1, 3 DESC');
        foreach ($r as $x) $saida['por_codigo'][(int)$x['cod']]['nomes_encontrados'][] = "$fonte.{$col['c']}: {$x['nome']} ({$x['qtd']})";
    }
}

// 3. Funcionários do legado Atlas que usam o código, e o que o tipo exigia
foreach ($codigos as $c) {
    $info = &$saida['por_codigo'][$c];
    try {
        $info['funcionarios_legado'] = $linhas('SELECT f.CODFUNCIONARIO, f.NOME, f.CODFORNECEDOR, fo.RAZAOSOCIAL FROM green_atlas.fo_funcionarios f
            LEFT JOIN green_atlas.fo_fornecedores fo ON fo.CODFORNECEDOR = f.CODFORNECEDOR WHERE f.CODTIPOFUNCIONARIO = ? ORDER BY f.NOME LIMIT 5', [$c]);
    } catch (Throwable $e) { $info['funcionarios_legado'] = 'erro: ' . $e->getMessage(); }
    try {
        $info['criterios_do_tipo'] = array_column($linhas('SELECT DISTINCT CONCAT_WS(" / ", NULLIF(c.tipo_de_documento, ""), c.DSCRITERIO) d FROM green_atlas.fo_tipofuncionariosxcriteriofuncionarios v
            JOIN green_atlas.fo_criteriofuncionarios c ON c.codcriteriofuncionario = v.codcriteriofuncionario WHERE v.codtipofuncionario = ? ORDER BY 1', [$c]), 'd');
        $info['treinamentos_do_tipo'] = array_column($linhas('SELECT DISTINCT t.DSCTIPO d FROM green_atlas.fo_tipofuncionariosxtipotreinamentos v
            JOIN green_atlas.fo_tipotreinamentos t ON t.CODTIPOTREINAMENTO = v.codtipotreinamento WHERE v.codtipofuncionario = ? ORDER BY 1', [$c]), 'd');
        $info['treinamentos_dos_funcionarios'] = array_column($linhas('SELECT t.DSCTIPO d, COUNT(*) n FROM green_atlas.fo_funcionarioxtiposxtreinamentos v
            JOIN green_atlas.fo_tipotreinamentos t ON t.CODTIPOTREINAMENTO = v.CODTIPOTREINAMENTO WHERE v.CODTIPOFUNCIONARIO = ? GROUP BY t.DSCTIPO ORDER BY 2 DESC LIMIT 6', [$c]), 'd');
    } catch (Throwable $e) { $info['vinculos'] = 'erro: ' . $e->getMessage(); }
    // 4. Tipos existentes com exatamente os mesmos critérios e treinamentos
    try {
        $info['tipos_com_mesmos_vinculos'] = array_map(static fn($x) => $x['cod'] . ' ' . $x['dsc'], $linhas("SELECT t.codtipofuncionario cod, t.DSCTIPO dsc FROM gatlas_dbp.fo_tipofuncionarios t
            WHERE t.codtipofuncionario <> ? AND COALESCE(t.DSCTIPO,'') NOT LIKE '%migra%o 217'
              AND (SELECT GROUP_CONCAT(DISTINCT codcriteriofuncionario ORDER BY codcriteriofuncionario) FROM gatlas_dbp.fo_tipofuncionariosxcriteriofuncionarios WHERE codtipofuncionario = t.codtipofuncionario)
                  <=> (SELECT GROUP_CONCAT(DISTINCT codcriteriofuncionario ORDER BY codcriteriofuncionario) FROM gatlas_dbp.fo_tipofuncionariosxcriteriofuncionarios WHERE codtipofuncionario = ?)
              AND (SELECT GROUP_CONCAT(DISTINCT codtipotreinamento ORDER BY codtipotreinamento) FROM gatlas_dbp.fo_tipofuncionariosxtipotreinamentos WHERE codtipofuncionario = t.codtipofuncionario)
                  <=> (SELECT GROUP_CONCAT(DISTINCT codtipotreinamento ORDER BY codtipotreinamento) FROM gatlas_dbp.fo_tipofuncionariosxtipotreinamentos WHERE codtipofuncionario = ?)
            ORDER BY 2 LIMIT 5", [$c, $c, $c]));
    } catch (Throwable $e) { $info['tipos_com_mesmos_vinculos'] = 'erro: ' . $e->getMessage(); }
    unset($info);
}

$resumo = ['com_nome_encontrado' => 0, 'sem_nome' => 0];
foreach ($saida['por_codigo'] as $c => $i) $i['nomes_encontrados'] ? $resumo['com_nome_encontrado']++ : $resumo['sem_nome']++;
$saida['resumo'] = $resumo;

$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents(dirname(__DIR__) . '/storage/logs/diagnostico_tipos_provisorios_217.json', $json);
echo $json . PHP_EOL;
