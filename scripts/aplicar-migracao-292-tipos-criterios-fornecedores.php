<?php
declare(strict_types=1);

/*
 * Migração 292 — Homologação de Fornecedores, etapa 1: Tipos de Fornecedores (com Critérios + nível de exigência e
 * Palavras-chave) e Critérios dos Fornecedores, mantidos no sistema novo.
 * Legado: hpr_configuracoes foTiposdeFornecedor (138; abas 139 Critérios, 140 Nível de exigência, 141 Palavras chave) e
 * configuracoes/fo_criteriofornecedores.php.
 *
 *  1. Cadastros pela migração 248 (database/248_cadastros_homologacao.php, itens fo_criteriofornecedores e
 *     fo_tipofornecedores): view do grid, validação de exclusão + gatilho (o tipo leva junto critérios e palavras-chave),
 *     comparação/migração com o legado, chave a partir de 100000, menu (HOMOLOGACAO_CRITERIOS_FORNECEDORES e
 *     HOMOLOGACAO_TIPOS_FORNECEDORES) e ajuda. Roda o script da 248 para cada cadastro.
 *  2. Em cada banco de cliente (gmodelo_dbp, gatlas_dbp, gmodelo2_dbp se existir):
 *     - cópia bkp292_<tabela> dos vínculos (uma única vez);
 *     - remove vínculos de tipo ou critério inexistente;
 *     - padroniza o nível de exigência (Obrigatório / Desejável; o resto fica vazio = não informado);
 *     - remove vínculos repetidos (tipo + critério / tipo + palavra): fica o de nível mais forte e menor chave;
 *     - índices únicos (tipo + item);
 *     - views e procedures das duas listas (database/292_tipos_fornecedores_vinculos.sql);
 *     - registro em registros_migracao (HOMOLOGACAO_TIPOS_CRITERIOS_FORNECEDORES_292).
 *  3. O sincronismo de FO deixa de sincronizar as 4 tabelas (SINC_MESTRE_NOVO em scripts/sincronizar-fo-legado.php).
 * Acesso: nenhum perfil é liberado aqui. Use a migração 247, por exemplo:
 *   php scripts\aplicar-migracao-247-permissoes-transacao.php --transacao=HOMOLOGACAO_TIPOS_FORNECEDORES --perfis=ADM_DA_EMPRESA,HOMOLOGACAO
 *   php scripts\aplicar-migracao-247-permissoes-transacao.php --transacao=HOMOLOGACAO_CRITERIOS_FORNECEDORES --perfis=ADM_DA_EMPRESA,HOMOLOGACAO
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-292-tipos-criterios-fornecedores.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-292-tipos-criterios-fornecedores.php
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

define('M292_BANCOS', HomologacaoBancos::lista($argv ?? [])); // classes/HomologacaoBancos.php (--banco= para só alguns)
const M292_VINCULOS = [
    'fo_tipofornecedoresxcriteriofornecedores' => ['item' => 'codcriteriofornecedor', 'origem' => 'fo_criteriofornecedores', 'origem_pk' => 'codcriteriofornecedor', 'indice' => 'uk_tipofornec_criterio'],
    'fo_tipofornecedoresxpalavraschave' => ['item' => 'codpalavrachave', 'origem' => null, 'origem_pk' => null, 'indice' => 'uk_tipofornec_palavrachave'],
];

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$template = (string)file_get_contents(dirname(__DIR__) . '/database/292_tipos_fornecedores_vinculos.sql');

$linhas = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$valor = static function (PDO $pdo, string $sql, array $p = []) use ($linhas): mixed { $r = $linhas($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; };
$q = static fn(string $n): string => '`' . str_replace('`', '``', $n) . '`';
$existeTabela = static fn(string $b, string $t): bool => (bool)$valor($geral, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'", [$b, $t]);
$coluna = static fn(string $b, string $t, string $c): ?string => $valor($geral, 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND LOWER(COLUMN_NAME) = LOWER(?)', [$b, $t, $c]);

$relatorio = ['ok' => true, 'migracao' => '292', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'cadastros_248' => [], 'bancos' => []];

// 1. Cadastros pela 248 (um processo por cadastro, para reaproveitar o script sem alterar o comportamento dele)
foreach (['fo_criteriofornecedores', 'fo_tipofornecedores'] as $cad) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'aplicar-migracao-248-cadastros-homologacao.php')
        . ' --cadastro=' . $cad . ' --banco=' . implode(',', M292_BANCOS) . ($simular ? ' --simular' : ''); // só os bancos desta migração
    $saida = []; $codigo = 0;
    exec($cmd . ' 2>&1', $saida, $codigo);
    $json = json_decode(implode("\n", $saida), true);
    $relatorio['cadastros_248'][$cad] = is_array($json) ? ($json['cadastros'][$cad] ?? $json) : ['saida' => implode("\n", array_slice($saida, -20))];
    if ($codigo !== 0) $relatorio['ok'] = false;
}

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
$relatorio['palavras_chave'] = $palavrasInfo;

// 2. Vínculos em cada banco
$nivelExpr = static fn(string $c): string => "CASE WHEN LOWER(TRIM($c)) IN ('obrigatório', 'obrigatorio', 'obrigat?rio', 'o') THEN 1 WHEN LOWER(TRIM($c)) IN ('desejável', 'desejavel', 'desej?vel', 'd') THEN 2 ELSE 3 END";
foreach (M292_BANCOS as $banco) {
    $info = [];
    if (!$valor($geral, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$banco])) {
        if ($banco !== 'gmodelo2_dbp') { $relatorio['ok'] = false; $relatorio['bancos'][$banco] = ['erro' => 'banco não existe']; }
        continue;
    }
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        foreach (['fo_tipofornecedores', 'fo_criteriofornecedores'] as $t) if (!$existeTabela($banco, $t)) throw new RuntimeException("tabela $t não existe");
        $gat = (int)$valor($geral, "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME IN ('trg_fo_tipofornecedores_antes_excluir', 'trg_fo_criteriofornecedores_antes_excluir')", [$banco]);
        $info['pre_requisito_248'] = $gat === 2 ? 'OK (gatilhos de exclusão existem)' : ($simular ? 'serão criados pela 248' : "ATENÇÃO: só $gat de 2 gatilhos");

        foreach (M292_VINCULOS as $t => $v) {
            if (!$existeTabela($banco, $t)) { $info['vinculos'][$t] = 'tabela não existe'; continue; }
            $pk = (string)$valor($geral, "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_KEY = 'PRI' LIMIT 1", [$banco, $t]);
            $T = $q($t); $item = $q($v['item']);
            $ehCrit = $t === 'fo_tipofornecedoresxcriteriofornecedores';
            $r = ['registros' => (int)$valor($pdo, "SELECT COUNT(*) FROM $T")];
            $orfaoTipo = "NOT EXISTS (SELECT 1 FROM fo_tipofornecedores x WHERE x.codtipofornecedor = v.codtipofornecedor)";
            $orfaoItem = $v['origem'] ? ' OR NOT EXISTS (SELECT 1 FROM ' . $q($v['origem']) . ' o WHERE o.' . $q($v['origem_pk']) . " = v.$item)" : '';
            $r['orfaos'] = (int)$valor($pdo, "SELECT COUNT(*) FROM $T v WHERE v.codtipofornecedor IS NULL OR v.$item IS NULL OR $orfaoTipo$orfaoItem");
            if ($ehCrit) $r['niveis_antes'] = $linhas($pdo, "SELECT COALESCE(nivel_de_exigencia, '(nulo)') AS nivel, COUNT(*) AS qtd FROM $T GROUP BY 1 ORDER BY 2 DESC");
            if ($pk === '') {
                $r['repetidos'] = 'sem chave primária (só informado)';
            } else {
                $ordemMelhor = $ehCrit
                    ? '(' . $nivelExpr('o.nivel_de_exigencia') . ' < ' . $nivelExpr('v.nivel_de_exigencia') . ' OR (' . $nivelExpr('o.nivel_de_exigencia') . ' = ' . $nivelExpr('v.nivel_de_exigencia') . ' AND o.' . $q($pk) . ' < v.' . $q($pk) . '))'
                    : 'o.' . $q($pk) . ' < v.' . $q($pk);
                $r['repetidos'] = (int)$valor($pdo, "SELECT COUNT(*) FROM $T v WHERE EXISTS (SELECT 1 FROM $T o WHERE o.codtipofornecedor = v.codtipofornecedor AND o.$item = v.$item AND $ordemMelhor)");
            }
            $temIndice = (bool)$valor($geral, 'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?', [$banco, $t, $v['indice']]);
            $r['indice_unico'] = $temIndice ? 'já existe' : ($simular ? 'será criado' : '');
            if (!$simular) {
                $bkp = 'bkp292_' . $t;
                if (!$existeTabela($banco, $bkp)) { $pdo->exec('CREATE TABLE ' . $q($bkp) . " AS SELECT * FROM $T"); $r['copia'] = "$bkp criada"; } else $r['copia'] = "$bkp já existia (mantida)";
                $r['removidos_orfaos'] = $pdo->exec("DELETE v FROM $T v WHERE v.codtipofornecedor IS NULL OR v.$item IS NULL OR $orfaoTipo$orfaoItem");
                if ($ehCrit) {
                    $r['niveis_padronizados'] = $pdo->exec("UPDATE $T SET nivel_de_exigencia = ELT(" . $nivelExpr('nivel_de_exigencia') . ", 'Obrigatório', 'Desejável', '')
                        WHERE NOT (CONVERT(COALESCE(nivel_de_exigencia, '') USING utf8mb4) COLLATE utf8mb4_bin <=> CONVERT(ELT(" . $nivelExpr('nivel_de_exigencia') . ", 'Obrigatório', 'Desejável', '') USING utf8mb4) COLLATE utf8mb4_bin)");
                }
                if ($pk !== '') {
                    $ordemMelhor = $ehCrit
                        ? '(' . $nivelExpr('o.nivel_de_exigencia') . ' < ' . $nivelExpr('v.nivel_de_exigencia') . ' OR (' . $nivelExpr('o.nivel_de_exigencia') . ' = ' . $nivelExpr('v.nivel_de_exigencia') . ' AND o.' . $q($pk) . ' < v.' . $q($pk) . '))'
                        : 'o.' . $q($pk) . ' < v.' . $q($pk);
                    $r['removidos_repetidos'] = $pdo->exec("DELETE v FROM $T v JOIN $T o ON o.codtipofornecedor = v.codtipofornecedor AND o.$item = v.$item AND $ordemMelhor");
                }
                if (!$temIndice) { $pdo->exec("ALTER TABLE $T ADD UNIQUE KEY " . $q($v['indice']) . " (codtipofornecedor, $item)"); $r['indice_unico'] = 'criado'; }
                $r['registros_depois'] = (int)$valor($pdo, "SELECT COUNT(*) FROM $T");
                if ($ehCrit) $r['niveis_depois'] = $linhas($pdo, "SELECT COALESCE(NULLIF(nivel_de_exigencia, ''), '(não informado)') AS nivel, COUNT(*) AS qtd FROM $T GROUP BY 1 ORDER BY 2 DESC");
            }
            $info['vinculos'][$t] = $r;
        }

        if (!$simular) {
            $sql = str_replace('{{PALAVRAS}}', $palavrasSql, $template);
            $n = 0;
            foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
                $limpo = trim(preg_replace('/^\s*--.*$/m', '', $bloco) ?? '');
                if ($limpo === '') continue;
                $pdo->exec($limpo); $n++;
            }
            $info['objetos'] = ['comandos' => $n, 'views' => ['vw_homologacao_tipofornecedor_opcoes', 'vw_homologacao_tipofornecedor_vinculos', 'vw_homologacao_tipofornecedor_criterios'],
                'procedures' => ['sp_fo_tipofornecedor_vinculos_listar', 'sp_fo_tipofornecedor_vinculos_gravar', 'sp_fo_tipofornecedor_nivel_gravar']];
            $info['opcoes'] = $linhas($pdo, 'SELECT relacao, COUNT(*) AS qtd FROM vw_homologacao_tipofornecedor_opcoes GROUP BY relacao');
            // Conferência pelas procedures (leitura) num tipo qualquer
            $um = $valor($pdo, 'SELECT MIN(codtipofornecedor) FROM fo_tipofornecedores');
            if ($um !== null) {
                $s = $pdo->prepare('CALL sp_fo_tipofornecedor_vinculos_listar(?, ?)'); $s->execute(['CRITERIOS', $um]);
                $s->fetchAll(); $s->nextRowset(); $itens = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
                $info['conferencia'] = ['tipo' => (int)$um, 'opcoes' => count($itens), 'vinculados' => count(array_filter($itens, static fn($i) => (int)$i['vinculado'] === 1))];
            }
            if ($valor($geral, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'registros_migracao'", [$banco])) {
                $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
                    VALUES ('HOMOLOGACAO_TIPOS_CRITERIOS_FORNECEDORES_292', 'fo_tipofornecedoresx*', ?, 'ESTRUTURA', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                    ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
                    ->execute(["$banco: vínculos dos tipos de fornecedor; views; procedures", (int)($info['vinculos']['fo_tipofornecedoresxcriteriofornecedores']['registros_depois'] ?? 0), json_encode($info, JSON_UNESCAPED_UNICODE)]);
            }
        }
    } catch (Throwable $e) {
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $relatorio['bancos'][$banco] = $info;
}

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_292_tipos_criterios_fornecedores' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
