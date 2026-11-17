<?php
declare(strict_types=1);

/*
 * Migração 245 — Homologação de Fornecedores › Critérios dos funcionários
 * (transação homologacaofornecedores_criteriosfuncionarios, legado configuracoes/fo_criteriofuncionarios.php).
 *
 * Pares origem -> destino (mesmo par do sincronismo de FO):
 *   green_modelo -> gmodelo_dbp
 *   green_atlas  -> gatlas_dbp
 *   green_modelo -> gmodelo2_dbp (somente se o banco existir)
 *
 * Para cada par:
 *  1. Confere a tabela fo_criteriofuncionarios na origem e no destino (colunas, chave, auto incremento).
 *     Se faltar no destino, cria com a mesma estrutura da origem.
 *  2. Descobre as tabelas do destino que usam o critério (coluna codcriteriofuncionario ou chave estrangeira).
 *  3. Cria as views e procedures de database/245_homologacao_criterios_funcionarios.sql e o gatilho que
 *     impede excluir critério em uso.
 *  4. A chave passa a ser gerada pelo banco (AUTO_INCREMENT), começando em 100000: critérios criados no
 *     sistema novo nunca usam um código que o legado possa criar depois.
 *  5. Migra (CALL sp_fo_criteriofuncionarios_migrar) os critérios que só existem na origem e compara
 *     (CALL sp_fo_criteriofuncionarios_comparar) origem x destino.
 *  6. Cadastra a transação no menu de cada cliente (para aparecer em Segurança › Permissões), sem liberar
 *     nenhum perfil: o acesso é dado em Permissões. ADMINISTRADOR tem acesso por regra do sistema.
 * No guniversal_dbp: menu HOMOLOGACAO_CRITERIOS_FUNCIONARIOS abaixo de Homologação de Fornecedores e ajuda da tela.
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-245-criterios-funcionarios.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-245-criterios-funcionarios.php
 * Pode ser executada mais de uma vez. Relatório também em storage\logs\migracao_245_criterios_funcionarios.json.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

define('M245_PARES', HomologacaoBancos::pares($argv ?? [])); // [legado, banco] — classes/HomologacaoBancos.php (--banco= para só alguns)
const M245_TABELA = 'fo_criteriofuncionarios';
const M245_CODIGO = 'HOMOLOGACAO_CRITERIOS_FUNCIONARIOS';
const M245_TRANSACAO = 'homologacaofornecedores_criteriosfuncionarios';
const M245_INICIO_CHAVE = 100000;

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$geral->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$universal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$linhas = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$valor = static function (PDO $pdo, string $sql, array $p = []) use ($linhas): mixed { $r = $linhas($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; };
$existeTabela = static fn(string $banco, string $tabela): bool => (bool)$valor($geral, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'", [$banco, $tabela]);
$colunas = static function (string $banco, string $tabela) use ($linhas, $geral): array {
    $out = [];
    foreach ($linhas($geral, 'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$banco, $tabela]) as $c) $out[strtolower($c['COLUMN_NAME'])] = $c;
    return $out;
};
$q = static fn(string $n): string => '`' . str_replace('`', '``', $n) . '`';

$relatorio = ['ok' => true, 'migracao' => '245', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'transacao' => M245_TRANSACAO, 'menu' => M245_CODIGO, 'pares' => []];
$template = (string)file_get_contents(dirname(__DIR__) . '/database/245_homologacao_criterios_funcionarios.sql');

// ---------------------------------------------------------------- análise e aplicação por par
foreach (M245_PARES as [$origem, $destino]) {
    $par = "$origem -> $destino";
    $info = ['origem' => $origem, 'destino' => $destino];
    if (!$valor($geral, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$destino])) {
        if ($destino === 'gmodelo2_dbp') continue; // opcional
        $info['erro'] = "banco $destino não existe";
        $relatorio['ok'] = false; $relatorio['pares'][$par] = $info; continue;
    }
    if (!$existeTabela($origem, M245_TABELA)) { $info['erro'] = "$origem." . M245_TABELA . ' não existe'; $relatorio['ok'] = false; $relatorio['pares'][$par] = $info; continue; }

    try {
        $pdo = GreenPortalDatabase::connect($env, $destino, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');

        // 1. Estrutura
        $tabelaNoDestino = $existeTabela($destino, M245_TABELA);
        $cO = $colunas($origem, M245_TABELA);
        $cD = $tabelaNoDestino ? $colunas($destino, M245_TABELA) : $cO;
        $info['estrutura'] = [
            'tabela_no_destino' => $tabelaNoDestino ? 'existe' : 'NÃO EXISTE (será criada igual à origem)',
            'colunas_origem' => array_values(array_map(static fn($c) => $c['COLUMN_NAME'] . ' ' . $c['COLUMN_TYPE'], $cO)),
            'so_na_origem' => array_values(array_map(static fn($c) => $c['COLUMN_NAME'], array_diff_key($cO, $cD))),
            'so_no_destino' => array_values(array_map(static fn($c) => $c['COLUMN_NAME'], array_diff_key($cD, $cO))),
        ];
        foreach (['codcriteriofuncionario', 'dscriterio', 'tipo_de_documento'] as $obrigatoria) {
            if (!isset($cD[$obrigatoria])) throw new RuntimeException("coluna $obrigatoria não existe em $destino." . M245_TABELA);
        }
        $pk = $cD['codcriteriofuncionario'];
        $autoIncremento = stripos((string)$pk['EXTRA'], 'auto_increment') !== false;
        $info['estrutura']['chave'] = $pk['COLUMN_NAME'] . ' ' . $pk['COLUMN_TYPE'] . ($pk['COLUMN_KEY'] === 'PRI' ? ' (primária)' : ' (NÃO É CHAVE PRIMÁRIA)') . ($autoIncremento ? ', auto incremento' : ', sem auto incremento (será ajustado)');
        if ($pk['COLUMN_KEY'] !== 'PRI') throw new RuntimeException('codcriteriofuncionario não é a chave primária em ' . $destino);

        // 2. Tabelas que usam o critério (coluna codcriteriofuncionario ou chave estrangeira)
        $refs = [];
        {
            foreach ($linhas($geral, "SELECT c.TABLE_NAME t, c.COLUMN_NAME c FROM information_schema.COLUMNS c JOIN information_schema.TABLES tb ON tb.TABLE_SCHEMA = c.TABLE_SCHEMA AND tb.TABLE_NAME = c.TABLE_NAME AND tb.TABLE_TYPE = 'BASE TABLE'
                WHERE c.TABLE_SCHEMA = ? AND LOWER(c.COLUMN_NAME) = 'codcriteriofuncionario' AND c.TABLE_NAME <> ? AND c.TABLE_NAME NOT LIKE '%bkp%' AND c.TABLE_NAME NOT LIKE '%backup%' AND c.TABLE_NAME NOT LIKE 'fo\\_sinc\\_%'", [$destino, M245_TABELA]) as $r) $refs[$r['t'] . '.' . $r['c']] = [$r['t'], $r['c']];
            foreach ($linhas($geral, 'SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME = ?', [$destino, $destino, M245_TABELA]) as $r) $refs[$r['t'] . '.' . $r['c']] = [$r['t'], $r['c']];
        }
        ksort($refs);
        $info['tabelas_que_usam_o_criterio'] = array_keys($refs);

        // Situação origem x destino (somente leitura, antes de qualquer alteração)
        $T = $q($destino) . '.' . $q(M245_TABELA); $S = $q($origem) . '.' . $q(M245_TABELA);
        $info['antes'] = [
            'origem' => (int)$valor($geral, "SELECT COUNT(*) FROM $S"),
            'destino' => $tabelaNoDestino ? (int)$valor($geral, "SELECT COUNT(*) FROM $T") : 0,
            'so_na_origem' => $tabelaNoDestino ? (int)$valor($geral, "SELECT COUNT(*) FROM $S o WHERE NOT EXISTS (SELECT 1 FROM $T d WHERE d.codcriteriofuncionario = o.codcriteriofuncionario)") : (int)$valor($geral, "SELECT COUNT(*) FROM $S"),
            'so_no_destino' => $tabelaNoDestino ? (int)$valor($geral, "SELECT COUNT(*) FROM $T d WHERE NOT EXISTS (SELECT 1 FROM $S o WHERE o.codcriteriofuncionario = d.codcriteriofuncionario)") : 0,
        ];

        if ($simular) { $relatorio['pares'][$par] = $info; continue; }

        // 1b. Tabela ausente no destino: mesma estrutura da origem
        if (!$tabelaNoDestino) $pdo->exec("CREATE TABLE $T LIKE $S");

        // 4. Chave gerada pelo banco, a partir de 100000
        if (!$autoIncremento) {
            $pdo->exec("ALTER TABLE $T MODIFY " . $q($pk['COLUMN_NAME']) . ' ' . $pk['COLUMN_TYPE'] . ' NOT NULL AUTO_INCREMENT');
            $info['ajustes'][] = 'chave passou a ser AUTO_INCREMENT';
        }
        $maior = (int)$valor($pdo, 'SELECT COALESCE(MAX(codcriteriofuncionario), 0) FROM ' . $q(M245_TABELA));
        $proximo = (int)$valor($geral, 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$destino, M245_TABELA]);
        if ($proximo < M245_INICIO_CHAVE && $maior < M245_INICIO_CHAVE) {
            $pdo->exec('ALTER TABLE ' . $q(M245_TABELA) . ' AUTO_INCREMENT = ' . M245_INICIO_CHAVE);
            $info['ajustes'][] = 'novos critérios do sistema novo começam no código ' . M245_INICIO_CHAVE;
        }

        // 3. Views, procedures e gatilho (refs descobertas no próprio banco)
        $usoExpr = $refs ? implode(' + ', array_map(static fn($r) => '(SELECT COUNT(*) FROM ' . $q($r[0]) . ' u WHERE u.' . $q($r[1]) . ' = c.codcriteriofuncionario)', $refs)) : '0';
        // Nome legível de cada uso na mensagem de exclusão bloqueada.
        $rotulos = ['fo_tipofuncionariosxcriteriofuncionarios' => 'tipos de funcionário', 'fo_funcionarioxtiposxcriterios' => 'critérios dos funcionários',
            'fo_funcionariosxcriterios' => 'funcionários', 'fo_tipofuncxcriteriofuncxtreinamento' => 'treinamentos por tipo de funcionário'];
        $validacoes = '';
        foreach ($refs as [$t, $c]) {
            $rotulo = str_replace("'", "''", $rotulos[strtolower($t)] ?? $t);
            $validacoes .= "    SELECT COUNT(*) INTO v_qtd FROM " . $q($t) . ' WHERE ' . $q($c) . " = p_codcriterio;\n"
                . "    IF v_qtd > 0 THEN SET v_onde = CONCAT(v_onde, IF(v_onde = '', '', ', '), '" . $rotulo . " (', v_qtd, ')'); END IF;\n";
        }
        $sqlTenant = str_replace(['{{USO_EXPR}}', '{{VALIDACOES}}'], [$usoExpr, $validacoes], $template);
        $comandos = 0;
        foreach (preg_split('/^-- @@\s*$/m', $sqlTenant) as $bloco) {
            $semComentario = trim(preg_replace('/^\s*--.*$/m', '', $bloco) ?? '');
            if ($semComentario === '') continue;
            $pdo->exec($semComentario);
            $comandos++;
        }
        $info['objetos'] = ['comandos' => $comandos, 'views' => ['vw_homologacao_criterios_funcionarios_grid', 'vw_homologacao_criterios_funcionarios_tipos_documento'],
            'procedures' => ['sp_fo_criteriofuncionario_validar_exclusao', 'sp_fo_criteriofuncionarios_comparar', 'sp_fo_criteriofuncionarios_migrar'], 'gatilho' => 'trg_fo_criteriofuncionarios_antes_excluir'];

        // 5. Migração e comparação pelas procedures
        $s = $pdo->prepare('CALL sp_fo_criteriofuncionarios_migrar(?)'); $s->execute([$origem]);
        $info['migrados_agora'] = (int)($s->fetchColumn() ?: 0); $s->closeCursor();
        $s = $pdo->prepare('CALL sp_fo_criteriofuncionarios_comparar(?)'); $s->execute([$origem]);
        $dif = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
        $resumo = ['SO_NA_ORIGEM' => 0, 'SO_NO_DESTINO' => 0, 'DIFERENTE' => 0];
        foreach ($dif as $d) $resumo[$d['situacao']]++;
        $info['depois'] = ['destino' => (int)$valor($pdo, 'SELECT COUNT(*) FROM vw_homologacao_criterios_funcionarios_grid'), 'comparacao' => $resumo, 'exemplos' => array_slice($dif, 0, 15)];

        // 6. Menu do cliente (para Segurança › Permissões); nenhum perfil é liberado aqui
        $info['menu_cliente'] = 'pendente';
        $relatorio['pares'][$par] = $info;
        $relatorio['_tenants'][$destino] = $pdo;
    } catch (Throwable $e) {
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
        $relatorio['pares'][$par] = $info;
    }
}

// ---------------------------------------------------------------- menu universal, menus dos clientes e ajuda
$menu = ['pt' => 'Critérios dos funcionários', 'en' => 'Employee criteria', 'es' => 'Criterios de los empleados', 'url' => '/principal/' . M245_TRANSACAO . '.php', 'icone' => 'bi-list-check'];
$codmenu = (int)$valor($universal, 'SELECT codmenu FROM menus WHERE codigo = ?', [M245_CODIGO]);
$pai = (int)$valor($universal, "SELECT codmenu FROM menus WHERE codigo = 'HOMOLOGACAO_FORNECEDORES' LIMIT 1");
if ($pai < 1) { $relatorio['ok'] = false; $relatorio['erro_menu'] = 'Menu HOMOLOGACAO_FORNECEDORES não encontrado em guniversal_dbp.menus'; }
$relatorio['menu_universal'] = $codmenu > 0 ? "já existe (codmenu $codmenu)" : 'será criado';

if (!$simular && $pai > 0) {
    $tenants = $relatorio['_tenants'] ?? [];
    if ($codmenu < 1) {
        $codmenu = (int)$valor($universal, 'SELECT COALESCE(MAX(codmenu), 0) FROM menus');
        foreach ($tenants as $pdo) $codmenu = max($codmenu, (int)$valor($pdo, 'SELECT COALESCE(MAX(codmenu), 0) FROM menus'));
        $codmenu++;
        $ordem = (int)$valor($universal, 'SELECT COALESCE(MAX(ordem), 0) + 1 FROM menus WHERE codmenu_pai = ?', [$pai]);
    } else {
        $ordem = (int)$valor($universal, 'SELECT ordem FROM menus WHERE codmenu = ?', [$codmenu]);
    }
    $linha = ['codmenu' => $codmenu, 'codmenu_pai' => $pai, 'modulo' => 'HOMOLOGACAO_FORNECEDORES', 'codigo' => M245_CODIGO, 'label' => $menu['pt'], 'label_pt' => $menu['pt'], 'label_en' => $menu['en'], 'label_es' => $menu['es'],
        'url' => $menu['url'], 'nivel' => 2, 'ordem' => $ordem, 'icone' => $menu['icone'], 'ativo' => 1];
    $gravarMenu = static function (PDO $pdo, string $banco, array $linha) use ($colunas, $valor): void {
        $dados = array_intersect_key($linha, $colunas($banco, 'menus'));
        $outro = $valor($pdo, 'SELECT codigo FROM menus WHERE codmenu = ?', [$linha['codmenu']]);
        if ($outro !== null && strtoupper((string)$outro) !== M245_CODIGO) throw new RuntimeException("codmenu {$linha['codmenu']} já usado por $outro em $banco");
        $campos = array_keys($dados);
        $upd = implode(', ', array_map(static fn($c) => "`$c` = VALUES(`$c`)", array_diff($campos, ['codmenu'])));
        $pdo->prepare('INSERT INTO menus (`' . implode('`, `', $campos) . '`) VALUES (' . implode(', ', array_fill(0, count($campos), '?')) . ") ON DUPLICATE KEY UPDATE $upd")->execute(array_values($dados));
    };
    try {
        $gravarMenu($universal, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), $linha);
        $relatorio['menu_universal'] = "gravado (codmenu $codmenu, ordem $ordem)";
    } catch (Throwable $e) { $relatorio['ok'] = false; $relatorio['menu_universal'] = 'ERRO: ' . $e->getMessage(); }
    foreach ($tenants as $banco => $pdo) {
        $par = array_key_first(array_filter($relatorio['pares'], static fn($p) => ($p['destino'] ?? '') === $banco));
        try {
            $paiCliente = (int)$valor($pdo, "SELECT codmenu FROM menus WHERE codigo = 'HOMOLOGACAO_FORNECEDORES' LIMIT 1") ?: $pai;
            $gravarMenu($pdo, $banco, ['codmenu_pai' => $paiCliente] + $linha);
            $relatorio['pares'][$par]['menu_cliente'] = "gravado (codmenu $codmenu); acesso: liberar em Segurança › Permissões";
            if ($colunas($banco, 'registros_migracao')) {
                $det = $relatorio['pares'][$par]; unset($det['estrutura']['colunas_origem']);
                $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
                    VALUES ('HOMOLOGACAO_CRITERIOS_FUNCIONARIOS_245', ?, ?, 'ESTRUTURA', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                    ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
                    ->execute(["$det[origem]." . M245_TABELA, "$banco." . M245_TABELA . '; views; procedures; menu', (int)($det['depois']['destino'] ?? 0), json_encode($det, JSON_UNESCAPED_UNICODE)]);
            }
        } catch (Throwable $e) { $relatorio['ok'] = false; $relatorio['pares'][$par]['menu_cliente'] = 'ERRO: ' . $e->getMessage(); }
    }

    // Ajuda da tela (botão "?" e base do assistente)
    $ajuda = require dirname(__DIR__) . '/database/245_ajuda_criterios_funcionarios.php';
    $existe = (int)$valor($universal, 'SELECT COUNT(*) FROM ajuda_transacoes WHERE transacao = ?', [M245_TRANSACAO]);
    $sql = $existe
        ? 'UPDATE ajuda_transacoes SET titulo_pt = ?, titulo_en = ?, titulo_es = ?, texto_pt = ?, texto_en = ?, texto_es = ?, ativo = 1 WHERE transacao = ?'
        : 'INSERT INTO ajuda_transacoes (titulo_pt, titulo_en, titulo_es, texto_pt, texto_en, texto_es, ativo, transacao) VALUES (?, ?, ?, ?, ?, ?, 1, ?)';
    $universal->prepare($sql)->execute([$ajuda['titulo']['pt'], $ajuda['titulo']['en'], $ajuda['titulo']['es'], $ajuda['texto']['pt'], $ajuda['texto']['en'], $ajuda['texto']['es'], M245_TRANSACAO]);
    $relatorio['ajuda'] = $existe ? 'atualizada' : 'incluída';
}
unset($relatorio['_tenants']);

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_245_criterios_funcionarios' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
