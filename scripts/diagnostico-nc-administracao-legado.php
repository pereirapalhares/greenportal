<?php
declare(strict_types=1);

/*
 * Diagnóstico: menu Não Conformidades › Administração do legado (22/10/2026). SOMENTE LEITURA — não grava em nenhum banco.
 * Para a proposta da Administração de NC no greenportal (usuários: ADM DA EMPRESA e ADMINISTRADOR). Levanta:
 *  1. a árvore do menu Não Conformidades do legado (green_seguranca.menus), com URL de cada item;
 *  2. quais grupos da empresa veem cada item da Administração e quantos usuários ativos há nesses grupos;
 *  3. o tamanho e o uso de cada cadastro de NC (linhas, quantas em uso nas ocorrências/ações);
 *  4. sinais de uso das correções administrativas (NCs com etapa fora do fluxo, sem gestor, com usuário inativo etc.);
 *  5. listas de distribuição (tamanho, usuários inativos) e campos por tipo.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-nc-administracao-legado.php [--origem=green_crown]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$origem = 'green_crown';
foreach ($argv ?? [] as $a) if (preg_match('/^--origem=([A-Za-z0-9_]+)$/', $a, $m)) $origem = $m[1];
$seg = $origem . '_seguranca';
$pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), 'information_schema', 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('SET NAMES utf8mb4');
$q = static function (string $sql, array $p = []) use ($pdo): array { try { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(); $s->closeCursor(); return $r; } catch (Throwable $e) { return ['erro' => mb_substr($e->getMessage(), 0, 250)]; } };
$v = static function (string $sql, array $p = []) use ($q) { $r = $q($sql, $p); return isset($r['erro']) ? $r['erro'] : ($r ? reset($r[0]) : null); };
$O = '`' . $origem . '`'; $S = '`' . $seg . '`';
$existe = static fn(string $banco, string $t): bool => (bool)$v('SELECT COUNT(*) FROM TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$banco, $t]);
$rel = ['diagnostico' => 'administração de NC no legado', 'origem' => $origem, 'gerado_em' => date('c')];

// 1. árvore do menu de NC
$menus = $q('SELECT CODMENU, CODMENUSUPERIOR, DSCMENU, URL, ORDEM0 FROM green_seguranca.menus');
if (isset($menus['erro'])) { $rel['menu'] = $menus; $menus = []; }
$porId = []; $filhos = [];
foreach ($menus as $m) { $porId[(int)$m['CODMENU']] = $m; if ((int)$m['CODMENU'] !== (int)$m['CODMENUSUPERIOR']) $filhos[(int)$m['CODMENUSUPERIOR']][] = (int)$m['CODMENU']; }
foreach ($filhos as &$f) usort($f, static fn($a, $b) => ((float)($porId[$a]['ORDEM0'] ?? 0) <=> (float)($porId[$b]['ORDEM0'] ?? 0)) ?: $a <=> $b); unset($f);
$raizes = array_values(array_filter(array_keys($porId), static fn($id) => (int)$porId[$id]['CODMENU'] === (int)$porId[$id]['CODMENUSUPERIOR'] && preg_match('/conform/iu', (string)$porId[$id]['DSCMENU'])));
$arvore = [];
$andar = static function (int $id, string $caminho, int $nivel) use (&$andar, &$arvore, $porId, $filhos): void {
    $m = $porId[$id]; $nome = trim((string)$m['DSCMENU']); $cam = $caminho === '' ? $nome : "$caminho › $nome";
    $arvore[] = ['codmenu' => $id, 'nivel' => $nivel, 'caminho' => $cam, 'url' => trim((string)$m['URL'])];
    if ($nivel < 6) foreach ($filhos[$id] ?? [] as $c) $andar($c, $cam, $nivel + 1);
};
foreach ($raizes as $r) $andar($r, '', 0);
$rel['menu_nao_conformidades'] = array_map(static fn($x) => str_repeat('  ', $x['nivel']) . $x['caminho'] . ' [' . $x['codmenu'] . ']' . ($x['url'] !== '' ? ' → ' . $x['url'] : ''), $arvore);

// 2. Administração: grupos com acesso e usuários ativos
$admin = array_values(array_filter($arvore, static fn($x) => preg_match('/administra/iu', $x['caminho'])));
$colUsu = $q('SELECT COLUMN_NAME c FROM COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$seg, 'usuarios']);
$ativo = in_array('ativo', array_map('strtolower', array_column(isset($colUsu['erro']) ? [] : $colUsu, 'c')), true) ? " AND UPPER(LEFT(COALESCE(u.ativo,'S'),1)) IN ('S','1','Y')" : '';
$rel['administracao_acesso'] = [];
foreach ($admin as $x) {
    $grupos = $q("SELECT g.CODGRUPO, g.DSCGRUPO, (SELECT COUNT(DISTINCT ug.codusuario) FROM $S.usuariosxgrupos ug JOIN $S.usuarios u ON u.codusuario = ug.codusuario WHERE ug.codgrupo = g.CODGRUPO$ativo) AS usuarios
        FROM $S.gruposxmenus gm JOIN $S.grupos g ON g.CODGRUPO = gm.CODGRUPO WHERE gm.CODMENU = ? ORDER BY g.DSCGRUPO", [$x['codmenu']]);
    $rel['administracao_acesso'][$x['caminho']] = ['url' => $x['url'], 'grupos' => $grupos];
}

// 3. cadastros de NC: tamanho e uso
$cad = [
    'nc_tipo_de_naoconformidade' => ['CODTIPONAOCONFORMIDADE', 'nc_indicacao_ocorrencia.CODTIPONAOCONFORMIDADE'],
    'nc_tipoocorrencia' => ['CODTIPOOCORRENCIA', 'nc_indicacao_ocorrencia.CODTIPOOCORRENCIA'],
    'nc_classificacao_fornecedor' => ['codclassificacao', 'nc_indicacao_ocorrencia.CODTIPOOCORRENCIA'],
    'nc_tipoacao' => ['CODTIPOACAO', 'nc_indicacao_ocorrencia.CODTIPOACAO'],
    'nc_origemacao' => ['CODORIGEMACAO', 'nc_indicacao_ocorrencia.CODORIGEMACAO'],
    'nc_siglagestao' => ['CODSIGLAGESTAO', 'nc_indicacao_ocorrencia.CODSIGLAGESTAO'],
    'nc_setores' => ['codsetor', 'nc_indicacao_ocorrencia.CODSETOR'],
    'nc_tipos_causas' => ['CODTIPOCAUSA', 'nc_causas.CODTIPOCAUSA'],
    'nc_listadistribuicao' => ['CODLISTA', 'nc_indicacao_ocorrencia.LISTADEDISTRIBUICAO'],
    'nc_listadistribuicaoxusuarios' => [null, null], 'nc_listadistribuicaoxcategorias' => [null, null],
    'nc_tipo_de_naoconformidadexcampos' => [null, null], 'nc_clientes' => [null, null], 'nc_produtos' => [null, null],
    'nc_tipodetalhe' => [null, null], 'nc_detalheproduto' => [null, null], 'nc_tipodenc' => [null, null], 'nc_etapas' => [null, null],
    'politicaemails' => [null, null], 'politicaemailsxempresas' => [null, null],
];
$rel['cadastros'] = [];
foreach ($cad as $t => [$pk, $uso]) {
    if (!$existe($origem, $t)) { $rel['cadastros'][$t] = 'não existe'; continue; }
    $item = ['linhas' => (int)$v("SELECT COUNT(*) FROM $O.`$t`")];
    $cols = $q('SELECT COLUMN_NAME c FROM COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$origem, $t]);
    $item['colunas'] = isset($cols['erro']) ? $cols : array_column($cols, 'c');
    if ($pk && $uso) {
        [$tu, $cu] = explode('.', $uso);
        $item['em_uso'] = (int)$v("SELECT COUNT(DISTINCT c.`$pk`) FROM $O.`$t` c WHERE EXISTS (SELECT 1 FROM $O.`$tu` u WHERE u.`$cu` = c.`$pk`)");
        $item['usados_desde_2025'] = $tu === 'nc_indicacao_ocorrencia' ? (int)$v("SELECT COUNT(DISTINCT u.`$cu`) FROM $O.`$tu` u WHERE u.data_de_criacao >= '2025-01-01'") : null;
    }
    if ($item['linhas'] > 0 && $item['linhas'] <= 40) $item['amostra'] = $q("SELECT * FROM $O.`$t` LIMIT 40");
    $rel['cadastros'][$t] = $item;
}

// 4. sinais de correções administrativas
$W = "COALESCE(o.ETAPA,'') <> 'Finalizado'";
$rel['situacoes_que_pedem_correcao'] = [
    'etapas_gravadas' => $q("SELECT o.ETAPA, COUNT(*) n FROM $O.nc_indicacao_ocorrencia o GROUP BY 1 ORDER BY n DESC"),
    'abertas_sem_gestor' => (int)$v("SELECT COUNT(*) FROM $O.nc_indicacao_ocorrencia o WHERE $W AND COALESCE(o.CODUSUARIO_GESTOR,0) = 0"),
    'abertas_analise_sem_responsavel' => (int)$v("SELECT COUNT(*) FROM $O.nc_indicacao_ocorrencia o WHERE o.ETAPA IN ('Em Analise da Causa Raiz','Plano de Ação') AND COALESCE(o.CODUSUARIO_RESPONSAVEL,0) = 0"),
    'abertas_com_gestor_inativo' => (int)$v("SELECT COUNT(*) FROM $O.nc_indicacao_ocorrencia o JOIN $S.usuarios u ON u.codusuario = o.CODUSUARIO_GESTOR WHERE $W" . str_replace(' AND ', ' AND NOT (', $ativo) . ($ativo ? ')' : ' AND 0')),
    'abertas_com_responsavel_inativo' => (int)$v("SELECT COUNT(*) FROM $O.nc_indicacao_ocorrencia o JOIN $S.usuarios u ON u.codusuario = o.CODUSUARIO_RESPONSAVEL WHERE $W" . str_replace(' AND ', ' AND NOT (', $ativo) . ($ativo ? ')' : ' AND 0')),
    'acoes_abertas_com_responsavel_inativo' => (int)$v("SELECT COUNT(*) FROM $O.nc_planodeacao p JOIN $S.usuarios u ON u.codusuario = p.CODUSUARIO WHERE COALESCE(p.STATUS,0) < 100" . str_replace(' AND ', ' AND NOT (', $ativo) . ($ativo ? ')' : ' AND 0')),
    'abertas_por_idade' => $q("SELECT CASE WHEN o.data_de_criacao >= NOW() - INTERVAL 90 DAY THEN 'até 90 dias' WHEN o.data_de_criacao >= NOW() - INTERVAL 1 YEAR THEN '90 dias a 1 ano'
        WHEN o.data_de_criacao >= NOW() - INTERVAL 3 YEAR THEN '1 a 3 anos' ELSE 'mais de 3 anos' END faixa, COUNT(*) n FROM $O.nc_indicacao_ocorrencia o WHERE $W GROUP BY 1 ORDER BY MIN(o.data_de_criacao) DESC"),
];

// 5. listas de distribuição
if ($existe($origem, 'nc_listadistribuicaoxusuarios')) $rel['listas_distribuicao'] = [
    'listas' => (int)$v("SELECT COUNT(*) FROM $O.nc_listadistribuicao"),
    'listas_sem_usuario' => (int)$v("SELECT COUNT(*) FROM $O.nc_listadistribuicao l WHERE NOT EXISTS (SELECT 1 FROM $O.nc_listadistribuicaoxusuarios x WHERE x.CODLISTA = l.CODLISTA)"),
    'vinculos_com_usuario_inativo' => (int)$v("SELECT COUNT(*) FROM $O.nc_listadistribuicaoxusuarios x JOIN $S.usuarios u ON u.codusuario = x.CODUSUARIO WHERE 1" . str_replace(' AND ', ' AND NOT (', $ativo) . ($ativo ? ')' : ' AND 0')),
    'maiores' => $q("SELECT l.DSCLISTA, COUNT(x.CODUSUARIO) usuarios FROM $O.nc_listadistribuicao l LEFT JOIN $O.nc_listadistribuicaoxusuarios x ON x.CODLISTA = l.CODLISTA GROUP BY l.CODLISTA, l.DSCLISTA ORDER BY usuarios DESC LIMIT 10"),
];
$rel['campos_por_tipo'] = $q("SELECT x.CODTIPONAOCONFORMIDADE tipo, COUNT(*) campos FROM $O.nc_tipo_de_naoconformidadexcampos x GROUP BY 1 ORDER BY 1");

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'diagnostico_nc_administracao_' . $origem . '.json', $json);
echo $json . PHP_EOL;
