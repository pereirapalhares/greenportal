<?php
declare(strict_types=1);

/*
 * Migração 324 — Não Conformidades: desempenho (21/10/2026). Base: scripts/diagnostico-nc-performance.php na Crown.
 *  1. vlistadeocorrenciasnc (Lista geral): a coluna ENCERRADO ("Plano de ação encerrado?") chamava getStatusPlanoAcaoNC
 *     linha a linha (≈1 ms por ocorrência: 2 s na 1ª página, 11 s sem filtro, 28 s para montar o filtro da coluna). Passa a ser
 *     calculada de uma vez, com a MESMA regra da função (agregado de nc_causas × nc_planodeacao). Antes de trocar, o script
 *     compara o resultado novo com a função em todas as ocorrências; se uma só divergir, o banco não é alterado.
 *  2. vminhasocorrenciasnc (Minhas ocorrências): sai o DISTINCT, que obrigava a montar a view inteira (25 s no filtro de
 *     Gestor). A Lista geral tem uma linha por ocorrência e os envolvidos já vêm sem repetição (UNION), então o resultado
 *     é o mesmo; o script confere isso antes.
 *  3. Índices das tabelas nc_*: cria nc_planodeacao (CODCAUSA, STATUS), usado pela regra do plano encerrado, e retira índices
 *     repetidos (mesmas colunas) ou cobertos por outro índice que começa pelas mesmas colunas. Só índices comuns: chave
 *     primária e únicos ficam.
 * A função getStatusPlanoAcaoNC continua (as procedures do fluxo a usam). Definições antigas das views ficam em
 * storage/logs/migracao_324_views_<banco>.sql (para voltar, se preciso). Não altera dados. Pode ser executada mais de uma vez.
 * Versão 2 (21/10/2026): a regra do CODCAUSA nulo saiu do SELECT (subconsulta) para o FROM (nc_nulo). Com a subconsulta no
 *   SELECT o MySQL montava a Lista geral inteira a cada consulta (Minhas ocorrências ficou mais lenta na Crown). Rodar de novo ajusta.
 * --simular só confere e mede. Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-324-nc-desempenho.php [--simular] [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$simular = in_array('--simular', $argv ?? [], true);
try { $alvos = HomologacaoBancos::lista($argv ?? []); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
$env = GreenPortalDatabase::env();
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$is->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$todas = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$valor = static function (PDO $pdo, string $sql, array $p = []) use ($todas) { $r = $todas($pdo, $sql, $p); return $r ? reset($r[0]) : null; };
$medir = static function (PDO $pdo, string $sql) use ($todas): string {
    try { $t = hrtime(true); $n = count($todas($pdo, $sql)); return round((hrtime(true) - $t) / 1e6) . " ms ($n linhas)"; }
    catch (Throwable $e) { return 'erro: ' . mb_substr($e->getMessage(), 0, 160); }
};

// Mesma regra de getStatusPlanoAcaoNC: 'Não' sem ação com status; 'Não' se houver causa sem ação (o NOT IN da função não
// conta nenhuma quando existe ação com CODCAUSA nulo); 'Não' se alguma ação estiver abaixo de 100%; senão 'Sim'.
const M324_AGREGADO = "(select `c`.`CODOCORRENCIA` AS `CODOCORRENCIA`, count(`p`.`STATUS`) AS `PLANOS`, coalesce(sum(`p`.`STATUS` < 100), 0) AS `ABERTOS`,
  count(case when `p`.`CODPLANOACAO` is null then 1 end) AS `SEM_PLANO`
  from (`nc_causas` `c` left join `nc_planodeacao` `p` on((`p`.`CODCAUSA` = `c`.`CODCAUSA`))) group by `c`.`CODOCORRENCIA`)";
const M324_ENCERRADO = "(case when (coalesce(`nc_st`.`PLANOS`, 0) = 0) then 'Não'
  when ((`nc_st`.`SEM_PLANO` > 0) and (`nc_nulo`.`TEM_NULO` = 0)) then 'Não'
  when (`nc_st`.`ABERTOS` > 0) then 'Não' else 'Sim' end)";
// Ação com CODCAUSA nulo (regra do NOT IN). Fica no FROM, não no SELECT: subconsulta no SELECT impede o MySQL de
// incorporar a view à consulta da tela (versão 1 da 324 fazia assim e montava a Lista inteira a cada consulta).
const M324_NULO = "(select count(0) AS `TEM_NULO` from `nc_planodeacao` where (`nc_planodeacao`.`CODCAUSA` is null))";
const M324_ENCERRADO_V1 = '/\(case when \(coalesce\(`nc_st`\.`PLANOS`.*?\bend\)(?=\s+AS\s+`ENCERRADO`)/is';
const M324_FUNCAO = '/(?:`[^`]+`\.)?`getStatusPlanoAcaoNC`\(((?:`[^`]+`\.)?`nc_indicacao_ocorrencia`\.`CODOCORRENCIA`)\)/i';

$rel = ['ok' => true, 'migracao' => '324', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
$dirLog = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dirLog)) @mkdir($dirLog, 0775, true);

foreach ($alvos as $banco) {
    $views = [];
    foreach ($todas($is, "SELECT TABLE_NAME, VIEW_DEFINITION, SECURITY_TYPE FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('vlistadeocorrenciasnc', 'vminhasocorrenciasnc')", [$banco]) as $v) $views[$v['TABLE_NAME']] = $v;
    if (!isset($views['vlistadeocorrenciasnc'])) { $rel['bancos'][$banco] = 'sem a Lista geral de NC (vlistadeocorrenciasnc): nada a fazer'; continue; }
    $info = ['antes' => [], 'depois' => []];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $pdo->exec('SET SESSION max_execution_time = 120000');
        $consultas = [
            'lista_pagina_25' => "SELECT * FROM vlistadeocorrenciasnc WHERE ETAPA <> 'Finalizado' ORDER BY CODOCORRENCIA DESC LIMIT 25",
            'lista_pagina_25_sem_filtro' => 'SELECT * FROM vlistadeocorrenciasnc ORDER BY CODOCORRENCIA DESC LIMIT 25',
            'lista_opcoes_encerrado' => "SELECT DISTINCT ENCERRADO FROM vlistadeocorrenciasnc WHERE ENCERRADO IS NOT NULL",
            'minhas_opcoes_gestor' => "SELECT DISTINCT NOME_GESTOR FROM vminhasocorrenciasnc WHERE NOME_GESTOR IS NOT NULL AND LENGTH(TRIM(NOME_GESTOR)) > 0",
        ];
        $uid = (int)$valor($pdo, "SELECT CODUSUARIO_GESTOR FROM nc_indicacao_ocorrencia WHERE CODUSUARIO_GESTOR > 0 AND COALESCE(ETAPA,'') <> 'Finalizado' GROUP BY CODUSUARIO_GESTOR ORDER BY COUNT(*) DESC LIMIT 1");
        $consultas['minhas_pagina_25'] = "SELECT * FROM vminhasocorrenciasnc WHERE CODUSUARIO_ENVOLVIDO = $uid ORDER BY CODOCORRENCIA DESC LIMIT 25";
        $consultas['minhas_contagem'] = "SELECT COUNT(*) FROM vminhasocorrenciasnc WHERE CODUSUARIO_ENVOLVIDO = $uid";

        // 1. Lista geral
        $lista = $views['vlistadeocorrenciasnc'];
        $def = (string)$lista['VIEW_DEFINITION'];
        $novaLista = null;
        $juntar = ' left join ' . M324_NULO . ' `nc_nulo` on((1 = 1))';
        if (str_contains($def, '`nc_nulo`')) $info['lista_geral'] = 'já otimizada';
        elseif (str_contains($def, '`nc_st`')) {
            // Versão 1 da 324 já aplicada: troca só a expressão de ENCERRADO e acrescenta o nc_nulo
            if (preg_match_all(M324_ENCERRADO_V1, $def) !== 1) $info['lista_geral'] = 'não alterada: ENCERRADO da versão 1 não está no formato esperado';
            else {
                $novaLista = preg_replace(M324_ENCERRADO_V1, str_replace('$', '\\$', M324_ENCERRADO), $def, 1) . $juntar;
                $info['lista_geral'] = $simular ? 'seria ajustada (versão 1 → 2: a view volta a ser incorporada à consulta)' : 'ajustada (versão 1 → 2)';
            }
        }
        elseif (preg_match_all(M324_FUNCAO, $def, $m) !== 1) $info['lista_geral'] = 'não alterada: a coluna ENCERRADO não está no formato esperado';
        elseif (preg_match('/\b(where|group\s+by|order\s+by|limit|having|union)\b/i', $def)) $info['lista_geral'] = 'não alterada: a view tem WHERE/GROUP/UNION (formato diferente do esperado)';
        else {
            $arg = $m[1][0];
            $novaLista = preg_replace(M324_FUNCAO, str_replace('$', '\\$', M324_ENCERRADO), $def, 1) . ' left join ' . M324_AGREGADO . ' `nc_st` on((`nc_st`.`CODOCORRENCIA` = ' . $arg . '))' . $juntar;
            $info['lista_geral'] = $simular ? 'seria otimizada (ENCERRADO sem a função linha a linha)' : 'otimizada';
        }
        if ($novaLista !== null) {
            // Confere a regra nova contra a função em todas as ocorrências
            $divergentes = (int)$valor($pdo, 'SELECT COUNT(*) FROM nc_indicacao_ocorrencia LEFT JOIN ' . M324_AGREGADO . ' `nc_st` ON `nc_st`.`CODOCORRENCIA` = nc_indicacao_ocorrencia.CODOCORRENCIA
                LEFT JOIN ' . M324_NULO . ' `nc_nulo` ON 1 = 1 WHERE NOT (getStatusPlanoAcaoNC(nc_indicacao_ocorrencia.CODOCORRENCIA) <=> ' . M324_ENCERRADO . ')');
            $info['lista_geral_divergencias_com_a_funcao'] = $divergentes;
            if ($divergentes > 0) { $novaLista = null; $info['lista_geral'] = 'não alterada: a regra nova diverge da função em ' . $divergentes . ' ocorrências'; $rel['ok'] = false; }
        }
        // 2. Minhas ocorrências
        $novaMinhas = null;
        if (isset($views['vminhasocorrenciasnc'])) {
            $dm = (string)$views['vminhasocorrenciasnc']['VIEW_DEFINITION'];
            if (!preg_match('/^\s*select\s+distinct\s+/i', $dm)) $info['minhas_ocorrencias'] = 'já sem DISTINCT';
            else {
                $n = (int)$valor($pdo, 'SELECT COUNT(*) FROM nc_indicacao_ocorrencia');
                $nv = (int)$valor($pdo, 'SELECT COUNT(*) FROM vlistadeocorrenciasnc');
                if ($n !== $nv) $info['minhas_ocorrencias'] = "não alterada: a Lista geral tem $nv linhas para $n ocorrências";
                else { $novaMinhas = preg_replace('/^\s*select\s+distinct\s+/i', 'select ', $dm, 1); $info['minhas_ocorrencias'] = ($simular ? 'seria' : 'foi') . ' tirado o DISTINCT (Lista geral com uma linha por ocorrência: ' . $n . ')'; }
            }
        }
        // 3. Índices
        $idx = [];
        foreach ($todas($is, "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS colunas FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'nc\\_%' GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE ORDER BY TABLE_NAME, INDEX_NAME", [$banco]) as $i)
            $idx[$i['TABLE_NAME']][$i['INDEX_NAME']] = ['cols' => strtolower((string)$i['colunas']), 'comum' => $i['INDEX_NAME'] !== 'PRIMARY' && (int)$i['NON_UNIQUE'] === 1];
        $criar = [];
        $temCausaStatus = false;
        foreach ($idx['nc_planodeacao'] ?? [] as $i) if (str_starts_with($i['cols'], 'codcausa,status')) $temCausaStatus = true;
        if (isset($idx['nc_planodeacao']) && !$temCausaStatus) { $criar['nc_planodeacao'][] = 'ADD INDEX `ix_nc_plano_causa_status` (`CODCAUSA`, `STATUS`)'; $idx['nc_planodeacao']['ix_nc_plano_causa_status'] = ['cols' => 'codcausa,status', 'comum' => true]; }
        $retirar = [];
        foreach ($idx as $t => $lista_idx) {
            $vistos = [];
            foreach ($lista_idx as $nome => $i) {
                if (!$i['comum']) continue;
                $coberto = false;
                foreach ($lista_idx as $outro => $j) {
                    if ($outro === $nome) continue;
                    // outro índice começa pelas mesmas colunas e é maior (cobre este), ou é igual e já foi mantido / é PK ou único
                    if (str_starts_with($j['cols'] . ',', $i['cols'] . ',') && strlen($j['cols']) > strlen($i['cols'])) { $coberto = true; break; }
                    if ($j['cols'] === $i['cols'] && (!$j['comum'] || isset($vistos[$outro]))) { $coberto = true; break; }
                }
                if ($coberto) $retirar[$t][] = $nome; else $vistos[$nome] = true;
            }
        }
        $info['indices_criados'] = array_map(static fn($l) => array_map(static fn($s) => preg_replace('/^ADD INDEX `([^`]+)`.*$/', '$1', $s), $l), $criar);
        $info['indices_retirados'] = $retirar;

        foreach ($consultas as $k => $sql) $info['antes'][$k] = $medir($pdo, $sql);
        if (!$simular) {
            $backup = "-- Migração 324: definições anteriores ($banco, " . date('c') . ")\n";
            foreach ($views as $n => $v) $backup .= "CREATE OR REPLACE SQL SECURITY {$v['SECURITY_TYPE']} VIEW `$n` AS {$v['VIEW_DEFINITION']};\n";
            // Guarda só a 1ª vez (a definição original, antes de qualquer versão da 324)
            $arqBackup = $dirLog . DIRECTORY_SEPARATOR . 'migracao_324_views_' . $banco . '.sql';
            if (!is_file($arqBackup)) @file_put_contents($arqBackup, $backup);
            foreach (array_unique(array_merge(array_keys($criar), array_keys($retirar))) as $t) {
                $partes = array_merge($criar[$t] ?? [], array_map(static fn($n) => 'DROP INDEX `' . str_replace('`', '', $n) . '`', $retirar[$t] ?? []));
                if ($partes) $pdo->exec('ALTER TABLE `' . $t . '` ' . implode(', ', $partes));
            }
            if ($novaLista !== null) $pdo->exec("CREATE OR REPLACE SQL SECURITY {$lista['SECURITY_TYPE']} VIEW `vlistadeocorrenciasnc` AS " . $novaLista);
            if ($novaMinhas !== null) $pdo->exec("CREATE OR REPLACE SQL SECURITY {$views['vminhasocorrenciasnc']['SECURITY_TYPE']} VIEW `vminhasocorrenciasnc` AS " . $novaMinhas);
            foreach ($consultas as $k => $sql) $info['depois'][$k] = $medir($pdo, $sql);
            $info['conferencia'] = [
                'ocorrencias' => (int)$valor($pdo, 'SELECT COUNT(*) FROM nc_indicacao_ocorrencia'),
                'lista_geral' => (int)$valor($pdo, 'SELECT COUNT(*) FROM vlistadeocorrenciasnc'),
                'encerrado_sim' => (int)$valor($pdo, "SELECT COUNT(*) FROM vlistadeocorrenciasnc WHERE ENCERRADO = 'Sim'"),
            ];
        } else unset($info['depois']);
        $rel['bancos'][$banco] = $info;
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = $info + ['erro' => $e->getMessage()]; }
}
$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
@file_put_contents($dirLog . DIRECTORY_SEPARATOR . 'migracao_324_nc_desempenho' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
