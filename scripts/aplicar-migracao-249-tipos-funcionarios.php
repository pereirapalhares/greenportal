<?php
declare(strict_types=1);

/*
 * Migração 249 — Tipos de Funcionários (transação homologacaofornecedores_tiposfuncionarios; legado foTiposdeFuncionarios,
 * codtransacao 142, abas 144 Critérios / 146 Treinamentos / 143 Palavras chave).
 *
 * PRÉ-REQUISITO: o cadastro do tipo pela migração 248
 *   php scripts\aplicar-migracao-248-cadastros-homologacao.php --cadastro=fo_tipofuncionarios
 * (view do grid, validação de exclusão, exclusão em cascata dos vínculos, comparação/migração, menu e ajuda).
 *
 * Esta migração, em cada banco de cliente (gmodelo_dbp, gatlas_dbp, gmodelo2_dbp se existir):
 *  1. cópia de segurança das 3 tabelas de vínculo (bkp249_<tabela>, uma única vez);
 *  2. remove vínculos de tipo inexistente (ex.: código -1) e vínculos repetidos (fica o de menor chave);
 *  3. cria índice único (tipo + item) para impedir repetição;
 *  4. views e procedures das duas listas (database/249_tipos_funcionarios_vinculos.sql);
 *  5. exclui os tipos provisórios "... - migração 217" SEM USO por funcionários (os vínculos saem junto, pelo gatilho);
 *  6. registra em registros_migracao (HOMOLOGACAO_TIPOS_FUNCIONARIOS_249).
 * Vínculos com item inexistente (critério/treinamento/palavra excluídos) são só informados.
 * O sincronismo de FO deixa de sincronizar o tipo e os vínculos (SINC_MESTRE_NOVO).
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-249-tipos-funcionarios.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-249-tipos-funcionarios.php
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

define('M249_BANCOS', HomologacaoBancos::lista($argv ?? [])); // classes/HomologacaoBancos.php (--banco= para só alguns)
const M249_VINCULOS = [
    'fo_tipofuncionariosxcriteriofuncionarios' => ['item' => 'codcriteriofuncionario', 'origem' => 'fo_criteriofuncionarios', 'origem_pk' => 'codcriteriofuncionario', 'indice' => 'uk_tipofunc_criterio'],
    'fo_tipofuncionariosxtipotreinamentos' => ['item' => 'codtipotreinamento', 'origem' => 'fo_tipotreinamentos', 'origem_pk' => 'CODTIPOTREINAMENTO', 'indice' => 'uk_tipofunc_treinamento'],
    'fo_tipofuncionariosxpalavraschave' => ['item' => 'codpalavrachave', 'origem' => null, 'origem_pk' => null, 'indice' => 'uk_tipofunc_palavrachave'],
];
const M249_USO_FUNCIONARIOS = ['fo_funcionarios', 'fo_funcionariosxtipofuncionarios', 'fo_funcionarioxtiposxcriterios', 'fo_funcionarioxtiposxtreinamentos'];

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$template = (string)file_get_contents(dirname(__DIR__) . '/database/249_tipos_funcionarios_vinculos.sql');

$linhas = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$valor = static function (PDO $pdo, string $sql, array $p = []) use ($linhas): mixed { $r = $linhas($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; };
$q = static fn(string $n): string => '`' . str_replace('`', '``', $n) . '`';
$existeTabela = static fn(string $b, string $t): bool => (bool)$valor($geral, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'", [$b, $t]);
$coluna = static fn(string $b, string $t, string $c): ?string => $valor($geral, 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND LOWER(COLUMN_NAME) = LOWER(?)', [$b, $t, $c]);

// Palavras-chave: banco universal novo; se não houver, o universal do legado.
$palavras = null; $palavrasInfo = 'não encontrada (lista de palavras-chave ficará vazia)';
foreach ([$bancoUniversal, 'green_universal'] as $b) {
    foreach (['palavras_chaves', 'PALAVRAS_CHAVES'] as $t) {
        if ($existeTabela($b, $t) && $coluna($b, $t, 'CODPALAVRACHAVE') && $coluna($b, $t, 'DSCPALAVRACHAVE')) {
            $palavras = $q($b) . '.' . $q($t);
            $palavrasInfo = "$b.$t (" . (int)$valor($geral, "SELECT COUNT(*) FROM $palavras") . ' palavras)';
            break 2;
        }
    }
}
$palavrasSql = $palavras ?? '(SELECT CAST(NULL AS SIGNED) AS CODPALAVRACHAVE, CAST(NULL AS CHAR(1)) AS DSCPALAVRACHAVE FROM DUAL WHERE 1 = 0)';

$relatorio = ['ok' => true, 'migracao' => '249', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'palavras_chave' => $palavrasInfo, 'bancos' => []];

foreach (M249_BANCOS as $banco) {
    $info = [];
    if (!$valor($geral, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$banco])) {
        if ($banco !== 'gmodelo2_dbp') { $relatorio['ok'] = false; $relatorio['bancos'][$banco] = ['erro' => 'banco não existe']; }
        continue;
    }
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');

        $pre = $valor($geral, "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME = 'trg_fo_tipofuncionarios_antes_excluir'", [$banco]);
        $info['pre_requisito_248'] = $pre ? 'OK (gatilho de exclusão do tipo existe)' : 'FALTA: rode antes a migração 248 com --cadastro=fo_tipofuncionarios';
        if (!$pre && !$simular) throw new RuntimeException('migração 248 do cadastro fo_tipofuncionarios não aplicada neste banco');

        // 1-3. Vínculos: cópia, limpeza, índice único
        foreach (M249_VINCULOS as $t => $v) {
            if (!$existeTabela($banco, $t)) { $info['vinculos'][$t] = 'tabela não existe'; continue; }
            $pk = (string)$valor($geral, "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_KEY = 'PRI' LIMIT 1", [$banco, $t]);
            if ($pk === '') throw new RuntimeException("$banco.$t sem chave primária");
            $T = $q($t); $item = $q($v['item']);
            $r = ['registros' => (int)$valor($pdo, "SELECT COUNT(*) FROM $T")];
            $r['tipo_inexistente'] = $linhas($pdo, "SELECT v.codtipofuncionario AS tipo, COUNT(*) AS qtd FROM $T v WHERE NOT EXISTS (SELECT 1 FROM fo_tipofuncionarios t WHERE t.codtipofuncionario = v.codtipofuncionario) GROUP BY v.codtipofuncionario ORDER BY 1");
            $r['repetidos'] = (int)$valor($pdo, "SELECT COUNT(*) FROM $T v WHERE EXISTS (SELECT 1 FROM $T o WHERE o.codtipofuncionario = v.codtipofuncionario AND o.$item = v.$item AND o." . $q($pk) . ' < v.' . $q($pk) . ')');
            if ($v['origem']) $r['item_inexistente_so_informado'] = (int)$valor($pdo, "SELECT COUNT(*) FROM $T v WHERE NOT EXISTS (SELECT 1 FROM " . $q($v['origem']) . ' o WHERE o.' . $q($v['origem_pk']) . " = v.$item)");
            $temIndice = (bool)$valor($geral, 'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?', [$banco, $t, $v['indice']]);
            $r['indice_unico'] = $temIndice ? 'já existe' : ($simular ? 'será criado' : '');
            if (!$simular) {
                $bkp = 'bkp249_' . $t;
                if (!$existeTabela($banco, $bkp)) { $pdo->exec('CREATE TABLE ' . $q($bkp) . " AS SELECT * FROM $T"); $r['copia'] = "$bkp criada"; } else $r['copia'] = "$bkp já existia (mantida)";
                $r['removidos_tipo_inexistente'] = $pdo->exec("DELETE v FROM $T v WHERE NOT EXISTS (SELECT 1 FROM fo_tipofuncionarios t WHERE t.codtipofuncionario = v.codtipofuncionario)");
                $r['removidos_repetidos'] = $pdo->exec("DELETE v FROM $T v JOIN $T o ON o.codtipofuncionario = v.codtipofuncionario AND o.$item = v.$item AND o." . $q($pk) . ' < v.' . $q($pk));
                if (!$temIndice) { $pdo->exec("ALTER TABLE $T ADD UNIQUE KEY " . $q($v['indice']) . " (codtipofuncionario, $item)"); $r['indice_unico'] = 'criado'; }
                $r['registros_depois'] = (int)$valor($pdo, "SELECT COUNT(*) FROM $T");
            }
            $info['vinculos'][$t] = $r;
        }

        // 4. Views e procedures das listas
        if (!$simular) {
            $sql = str_replace('{{PALAVRAS}}', $palavrasSql, $template);
            $n = 0;
            foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
                $limpo = trim(preg_replace('/^\s*--.*$/m', '', $bloco) ?? '');
                if ($limpo === '') continue;
                $pdo->exec($limpo); $n++;
            }
            $info['objetos'] = ['comandos' => $n, 'views' => ['vw_homologacao_tipofuncionario_opcoes', 'vw_homologacao_tipofuncionario_vinculos', 'vw_homologacao_tipofuncionario_vinculos_resumo'],
                'procedures' => ['sp_fo_tipofuncionario_vinculos_listar', 'sp_fo_tipofuncionario_vinculos_gravar']];
            $info['opcoes'] = $linhas($pdo, 'SELECT relacao, COUNT(*) AS qtd FROM vw_homologacao_tipofuncionario_opcoes GROUP BY relacao');
        }

        // 5. Provisórios da migração 217 sem uso por funcionários
        $usoExpr = [];
        foreach (M249_USO_FUNCIONARIOS as $u) if ($existeTabela($banco, $u)) $usoExpr[] = '(SELECT COUNT(*) FROM ' . $q($u) . ' u WHERE u.codtipofuncionario = t.codtipofuncionario)';
        $usoExpr = $usoExpr ? implode(' + ', $usoExpr) : '0';
        $prov = $linhas($pdo, "SELECT t.codtipofuncionario AS cod, t.DSCTIPO AS dsc, ($usoExpr) AS uso FROM fo_tipofuncionarios t WHERE COALESCE(t.DSCTIPO, '') LIKE '%- migra%o 217' ORDER BY 1");
        $semUso = array_values(array_filter($prov, static fn($p) => (int)$p['uso'] === 0));
        $info['provisorios_217'] = ['total' => count($prov), 'em_uso_mantidos' => count($prov) - count($semUso), 'sem_uso' => array_map(static fn($p) => $p['cod'] . ' ' . $p['dsc'], $semUso)];
        if (!$simular && $semUso) {
            $del = $pdo->prepare('DELETE FROM fo_tipofuncionarios WHERE codtipofuncionario = ?');
            $excluidos = 0;
            foreach ($semUso as $p) { try { $del->execute([$p['cod']]); $excluidos += $del->rowCount(); } catch (PDOException $e) { $info['provisorios_217']['nao_excluidos'][] = $p['cod'] . ': ' . preg_replace('/^.*1644\s*/s', '', $e->getMessage()); } }
            $info['provisorios_217']['excluidos'] = $excluidos;
        }

        // 6. Registro
        if (!$simular && $valor($geral, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'registros_migracao'", [$banco])) {
            $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
                VALUES ('HOMOLOGACAO_TIPOS_FUNCIONARIOS_249', 'fo_tipofuncionariosx*', ?, 'ESTRUTURA', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
                ->execute(["$banco: vínculos dos tipos de funcionário; views; procedures", (int)($info['provisorios_217']['excluidos'] ?? 0), json_encode($info, JSON_UNESCAPED_UNICODE)]);
        }
    } catch (Throwable $e) {
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $relatorio['bancos'][$banco] = $info;
}

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_249_tipos_funcionarios' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
