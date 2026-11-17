<?php
declare(strict_types=1);

/*
 * Migração 248 — cadastros simples de Homologação de Fornecedores (lista em database/248_cadastros_homologacao.php;
 * primeiro item: Tipos de treinamento, transação homologacaofornecedores_tipostreinamentos).
 *
 * Pares origem -> destino (mesmos do sincronismo de FO): green_modelo -> gmodelo_dbp, green_atlas -> gatlas_dbp,
 * green_modelo -> gmodelo2_dbp (se existir). Para cada cadastro e par:
 *  1. confere a tabela na origem e no destino (colunas, chave, auto incremento); cria no destino se faltar;
 *  2. descobre as tabelas que usam o registro (coluna com o nome da chave ou chave estrangeira);
 *  3. cria view do grid, validação de exclusão (procedure + gatilho), procedures de comparação e migração
 *     (modelo database/248_homologacao_cadastro_simples.sql);
 *  4. chave AUTO_INCREMENT a partir de 100000 (registros criados no sistema novo não colidem com o legado);
 *  5. migra o que só existe na origem e compara origem x destino (pelas procedures);
 *  6. cadastra o menu (universal e de cada cliente, para Segurança › Permissões) e a ajuda da tela.
 * Nenhum perfil é liberado aqui (use a migração 247). O sincronismo de FO deixa de sincronizar estas tabelas
 * (SINC_MESTRE_NOVO em scripts/sincronizar-fo-legado.php).
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-248-cadastros-homologacao.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-248-cadastros-homologacao.php
 * Opção: --cadastro=fo_tipotreinamentos (só um). Pode ser executada mais de uma vez.
 * Migração 249: item com 'filhos' (tabelas que pertencem ao registro) — não contam como uso e são excluídas junto
 * pelo gatilho (ex.: critérios, treinamentos e palavras-chave de um tipo de funcionário).
 * Migração 292: 'extras' (colunas a mais na view do grid: nome => expressão sobre o alias c) e 'refs_extra'
 * (tabelas que usam o registro por uma coluna de outro nome, ex.: fo_fornecedorxcriterios.codtipo).
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

define('M248_PARES', HomologacaoBancos::pares($argv ?? [])); // [legado, banco] — classes/HomologacaoBancos.php (--banco= para só alguns)
const M248_INICIO_CHAVE = 100000;

$simular = in_array('--simular', $argv ?? [], true);
$somente = '';
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--cadastro=')) $somente = strtolower(trim(substr($a, 11)));
$cadastros = require dirname(__DIR__) . '/database/248_cadastros_homologacao.php';
if ($somente !== '') $cadastros = array_intersect_key($cadastros, [$somente => true]);
$template = (string)file_get_contents(dirname(__DIR__) . '/database/248_homologacao_cadastro_simples.sql');

$env = GreenPortalDatabase::env();
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');

$linhas = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$valor = static function (PDO $pdo, string $sql, array $p = []) use ($linhas): mixed { $r = $linhas($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; };
$q = static fn(string $n): string => '`' . str_replace('`', '``', $n) . '`';
$existeTabela = static fn(string $b, string $t): bool => (bool)$valor($geral, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'", [$b, $t]);
$colunas = static function (string $b, string $t) use ($linhas, $geral): array {
    $out = [];
    foreach ($linhas($geral, 'SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_KEY, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$b, $t]) as $c) $out[strtolower($c['COLUMN_NAME'])] = $c;
    return $out;
};
$gravarMenu = static function (PDO $pdo, string $banco, array $linha) use ($colunas, $valor): void {
    $dados = array_intersect_key($linha, $colunas($banco, 'menus'));
    $outro = $valor($pdo, 'SELECT codigo FROM menus WHERE codmenu = ?', [$linha['codmenu']]);
    if ($outro !== null && strtoupper((string)$outro) !== $linha['codigo']) throw new RuntimeException("codmenu {$linha['codmenu']} já usado por $outro em $banco");
    $campos = array_keys($dados);
    $upd = implode(', ', array_map(static fn($c) => "`$c` = VALUES(`$c`)", array_diff($campos, ['codmenu'])));
    $pdo->prepare('INSERT INTO menus (`' . implode('`, `', $campos) . '`) VALUES (' . implode(', ', array_fill(0, count($campos), '?')) . ") ON DUPLICATE KEY UPDATE $upd")->execute(array_values($dados));
};

$relatorio = ['ok' => true, 'migracao' => '248', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'cadastros' => []];
foreach ($cadastros as $tabela => $cfg) {
    $pk = $cfg['pk']; $dsc = $cfg['dsc'];
    $rel = ['legado' => $cfg['legado'], 'menu' => $cfg['menu']['codigo'], 'transacao' => $cfg['menu']['transacao'], 'pares' => []];
    $tenants = [];
    foreach (M248_PARES as [$origem, $destino]) {
        $par = "$origem -> $destino";
        $info = [];
        if (!$valor($geral, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$destino])) { if ($destino !== 'gmodelo2_dbp') { $info['erro'] = "banco $destino não existe"; $relatorio['ok'] = false; $rel['pares'][$par] = $info; } continue; }
        try {
            if (!$existeTabela($origem, $tabela)) throw new RuntimeException("$origem.$tabela não existe");
            $pdo = GreenPortalDatabase::connect($env, $destino, 'GPT');
            $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
            $noDestino = $existeTabela($destino, $tabela);
            $cO = $colunas($origem, $tabela); $cD = $noDestino ? $colunas($destino, $tabela) : $cO;
            foreach ([$pk, $dsc] as $c) if (!isset($cD[strtolower($c)])) throw new RuntimeException("coluna $c não existe em $destino.$tabela");
            $chave = $cD[strtolower($pk)];
            if ($chave['COLUMN_KEY'] !== 'PRI') throw new RuntimeException("$pk não é a chave primária de $destino.$tabela");
            $auto = stripos((string)$chave['EXTRA'], 'auto_increment') !== false;
            $info['estrutura'] = ['tabela_no_destino' => $noDestino ? 'existe' : 'NÃO EXISTE (será criada igual à origem)',
                'colunas' => array_values(array_map(static fn($c) => $c['COLUMN_NAME'] . ' ' . $c['COLUMN_TYPE'], $cD)),
                'so_na_origem' => array_values(array_map(static fn($c) => $c['COLUMN_NAME'], array_diff_key($cO, $cD))),
                'so_no_destino' => array_values(array_map(static fn($c) => $c['COLUMN_NAME'], array_diff_key($cD, $cO))),
                'chave' => $chave['COLUMN_NAME'] . ' ' . $chave['COLUMN_TYPE'] . ($auto ? ', auto incremento' : ', sem auto incremento (será ajustado)')];

            // Tabelas que usam o registro
            $refs = [];
            foreach ($linhas($geral, "SELECT c.TABLE_NAME t, c.COLUMN_NAME c FROM information_schema.COLUMNS c JOIN information_schema.TABLES tb ON tb.TABLE_SCHEMA = c.TABLE_SCHEMA AND tb.TABLE_NAME = c.TABLE_NAME AND tb.TABLE_TYPE = 'BASE TABLE'
                WHERE c.TABLE_SCHEMA = ? AND LOWER(c.COLUMN_NAME) = ? AND c.TABLE_NAME <> ? AND c.TABLE_NAME NOT LIKE '%bkp%' AND c.TABLE_NAME NOT LIKE '%backup%' AND c.TABLE_NAME NOT LIKE 'fo\\_sinc\\_%'", [$destino, strtolower($pk), $tabela]) as $r) $refs[$r['t'] . '.' . $r['c']] = [$r['t'], $r['c']];
            foreach ($linhas($geral, 'SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME = ?', [$destino, $destino, $tabela]) as $r) $refs[$r['t'] . '.' . $r['c']] = [$r['t'], $r['c']];
            // Tabelas que pertencem ao registro (config 'filhos', migração 249): não contam como uso; são excluídas junto.
            $filhos = [];
            foreach ((array)($cfg['filhos'] ?? []) as $ft => $fc) {
                foreach (array_keys($refs) as $k) if (strcasecmp($refs[$k][0], (string)$ft) === 0) unset($refs[$k]);
                if ($existeTabela($destino, (string)$ft)) $filhos[] = [(string)$ft, (string)$fc];
            }
            foreach ((array)($cfg['refs_extra'] ?? []) as $rt => $rc) if ($existeTabela($destino, (string)$rt)) $refs[$rt . '.' . $rc] = [(string)$rt, (string)$rc];
            ksort($refs);
            $info['tabelas_que_usam'] = array_keys($refs);
            if ($filhos) $info['excluidas_junto'] = array_map(static fn($f) => $f[0], $filhos);

            $T = $q($destino) . '.' . $q($tabela); $S = $q($origem) . '.' . $q($tabela);
            $info['antes'] = ['origem' => (int)$valor($geral, "SELECT COUNT(*) FROM $S"), 'destino' => $noDestino ? (int)$valor($geral, "SELECT COUNT(*) FROM $T") : 0,
                'so_na_origem' => $noDestino ? (int)$valor($geral, "SELECT COUNT(*) FROM $S o WHERE NOT EXISTS (SELECT 1 FROM $T d WHERE d." . $q($pk) . ' = o.' . $q($pk) . ')') : (int)$valor($geral, "SELECT COUNT(*) FROM $S"),
                'so_no_destino' => $noDestino ? (int)$valor($geral, "SELECT COUNT(*) FROM $T d WHERE NOT EXISTS (SELECT 1 FROM $S o WHERE o." . $q($pk) . ' = d.' . $q($pk) . ')') : 0];
            if ($simular) { $rel['pares'][$par] = $info; continue; }

            if (!$noDestino) $pdo->exec("CREATE TABLE $T LIKE $S");
            if (!$auto) { $pdo->exec("ALTER TABLE $T MODIFY " . $q($chave['COLUMN_NAME']) . ' ' . $chave['COLUMN_TYPE'] . ' NOT NULL AUTO_INCREMENT'); $info['ajustes'][] = 'chave passou a ser AUTO_INCREMENT'; }
            $maior = (int)$valor($pdo, 'SELECT COALESCE(MAX(' . $q($pk) . '), 0) FROM ' . $q($tabela));
            $proximo = (int)$valor($geral, 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$destino, $tabela]);
            if ($proximo < M248_INICIO_CHAVE && $maior < M248_INICIO_CHAVE) { $pdo->exec('ALTER TABLE ' . $q($tabela) . ' AUTO_INCREMENT = ' . M248_INICIO_CHAVE); $info['ajustes'][] = 'novos registros do sistema novo começam no código ' . M248_INICIO_CHAVE; }

            $usoExpr = $refs ? implode(' + ', array_map(static fn($r) => '(SELECT COUNT(*) FROM ' . $q($r[0]) . ' u WHERE u.' . $q($r[1]) . ' = c.' . $q($pk) . ')', $refs)) : '0';
            $validacoes = '';
            foreach ($refs as [$t, $c]) {
                $rotulo = str_replace("'", "''", $cfg['usos'][strtolower($t)] ?? $t);
                $validacoes .= '    SELECT COUNT(*) INTO v_qtd FROM ' . $q($t) . ' WHERE ' . $q($c) . " = p_codigo;\n"
                    . "    IF v_qtd > 0 THEN SET v_onde = CONCAT(v_onde, IF(v_onde = '', '', ', '), '" . $rotulo . " (', v_qtd, ')'); END IF;\n";
            }
            $filhosSql = implode("\n", array_map(static fn($f) => '    DELETE FROM ' . $q($f[0]) . ' WHERE ' . $q($f[1]) . ' = OLD.' . $q($pk) . ';', $filhos));
            $extrasSql = '';
            foreach ((array)($cfg['extras'] ?? []) as $nome => $expr) $extrasSql .= ', ' . $expr . ' AS ' . $q((string)$nome);
            $sql = str_replace(['{{TABELA}}', '{{PK}}', '{{DSC}}', '{{VIEW}}', '{{USO_EXPR}}', '{{VALIDACOES}}', '{{ROTULO}}', '{{FILHOS}}', '{{EXTRAS}}'],
                [$tabela, $pk, $dsc, $cfg['view'], $usoExpr, $validacoes, str_replace("'", "''", $cfg['rotulo']), $filhosSql, $extrasSql], $template);
            $n = 0;
            foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
                $limpo = trim(preg_replace('/^\s*--.*$/m', '', $bloco) ?? '');
                if ($limpo === '') continue;
                $pdo->exec($limpo); $n++;
            }
            $info['objetos'] = ['comandos' => $n, 'view' => $cfg['view'], 'procedures' => ["sp_{$tabela}_validar_exclusao", "sp_{$tabela}_comparar", "sp_{$tabela}_migrar"], 'gatilho' => "trg_{$tabela}_antes_excluir"];

            $s = $pdo->prepare("CALL sp_{$tabela}_migrar(?)"); $s->execute([$origem]); $info['migrados_agora'] = (int)($s->fetchColumn() ?: 0); $s->closeCursor();
            $s = $pdo->prepare("CALL sp_{$tabela}_comparar(?)"); $s->execute([$origem]); $dif = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
            $resumo = ['SO_NA_ORIGEM' => 0, 'SO_NO_DESTINO' => 0, 'DIFERENTE' => 0];
            foreach ($dif as $d) $resumo[$d['situacao']]++;
            $info['depois'] = ['destino' => (int)$valor($pdo, 'SELECT COUNT(*) FROM ' . $q($cfg['view'])), 'comparacao' => $resumo, 'exemplos' => array_slice($dif, 0, 10)];
            $tenants[$destino] = [$pdo, $par];
        } catch (Throwable $e) {
            $relatorio['ok'] = false;
            $info['erro'] = $e->getMessage();
        }
        $rel['pares'][$par] = $info;
    }

    // Menu (universal + clientes) e ajuda
    $m = $cfg['menu'];
    $codmenu = (int)$valor($universal, 'SELECT codmenu FROM menus WHERE codigo = ?', [$m['codigo']]);
    $pai = (int)$valor($universal, "SELECT codmenu FROM menus WHERE codigo = 'HOMOLOGACAO_FORNECEDORES' LIMIT 1");
    $rel['menu_universal'] = $codmenu ? "já existe (codmenu $codmenu)" : 'será criado';
    if (!$simular && $pai > 0) {
        try {
            if ($codmenu < 1) {
                $codmenu = (int)$valor($universal, 'SELECT COALESCE(MAX(codmenu), 0) FROM menus');
                foreach ($tenants as [$pdo]) $codmenu = max($codmenu, (int)$valor($pdo, 'SELECT COALESCE(MAX(codmenu), 0) FROM menus'));
                $codmenu++;
                $ordem = (int)$valor($universal, 'SELECT COALESCE(MAX(ordem), 0) + 1 FROM menus WHERE codmenu_pai = ?', [$pai]);
            } else {
                $ordem = (int)$valor($universal, 'SELECT ordem FROM menus WHERE codmenu = ?', [$codmenu]);
            }
            $linha = ['codmenu' => $codmenu, 'codmenu_pai' => $pai, 'modulo' => 'HOMOLOGACAO_FORNECEDORES', 'codigo' => $m['codigo'], 'label' => $m['pt'], 'label_pt' => $m['pt'], 'label_en' => $m['en'], 'label_es' => $m['es'],
                'url' => '/principal/' . $m['transacao'] . '.php', 'nivel' => 2, 'ordem' => $ordem, 'icone' => $m['icone'], 'ativo' => 1];
            $gravarMenu($universal, $bancoUniversal, $linha);
            $rel['menu_universal'] = "gravado (codmenu $codmenu, ordem $ordem)";
            foreach ($tenants as $banco => [$pdo, $par]) {
                $paiCliente = (int)$valor($pdo, "SELECT codmenu FROM menus WHERE codigo = 'HOMOLOGACAO_FORNECEDORES' LIMIT 1") ?: $pai;
                $gravarMenu($pdo, $banco, ['codmenu_pai' => $paiCliente] + $linha);
                $rel['pares'][$par]['menu_cliente'] = "gravado (codmenu $codmenu); acesso: migração 247 ou Segurança › Permissões";
                if ($colunas($banco, 'registros_migracao')) {
                    $det = $rel['pares'][$par]; unset($det['estrutura']['colunas']);
                    $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
                        VALUES (?, ?, ?, 'ESTRUTURA', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                        ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
                        ->execute([$m['codigo'] . '_248', explode(' -> ', $par)[0] . ".$tabela", "$banco.$tabela; view; procedures; menu", (int)($det['depois']['destino'] ?? 0), json_encode($det, JSON_UNESCAPED_UNICODE)]);
                }
            }
            $a = $cfg['ajuda'];
            $existe = (int)$valor($universal, 'SELECT COUNT(*) FROM ajuda_transacoes WHERE transacao = ?', [$m['transacao']]);
            $universal->prepare($existe
                ? 'UPDATE ajuda_transacoes SET titulo_pt = ?, titulo_en = ?, titulo_es = ?, texto_pt = ?, texto_en = ?, texto_es = ?, ativo = 1 WHERE transacao = ?'
                : 'INSERT INTO ajuda_transacoes (titulo_pt, titulo_en, titulo_es, texto_pt, texto_en, texto_es, ativo, transacao) VALUES (?, ?, ?, ?, ?, ?, 1, ?)')
                ->execute([$a['titulo']['pt'], $a['titulo']['en'], $a['titulo']['es'], $a['texto']['pt'], $a['texto']['en'], $a['texto']['es'], $m['transacao']]);
            $rel['ajuda'] = $existe ? 'atualizada' : 'incluída';
        } catch (Throwable $e) { $relatorio['ok'] = false; $rel['menu_universal'] = 'ERRO: ' . $e->getMessage(); }
    } elseif ($pai < 1) { $relatorio['ok'] = false; $rel['menu_universal'] = 'menu HOMOLOGACAO_FORNECEDORES não encontrado'; }
    $relatorio['cadastros'][$tabela] = $rel;
}

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_248_cadastros_homologacao' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
