<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) — por que as contagens da Homologação diferem entre legado e greenportal.
 * Complementa diagnostico-homologacao-empresa.php:
 *  - o que cada tela do greenportal deixa de fora (filtro da view) e por quê;
 *  - vínculos sem chave comum (tipo func. × critério/treinamento, fornecedor × usuário): repetidos no legado,
 *    faltando ou sobrando no greenportal;
 *  - fornecedores que o legado mostra mais de uma vez na Gestão de Fornecedores (um por tipo).
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-homologacao-diferencas.php [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$banco = 'gcrown_dbp';
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--banco=')) $banco = substr($a, 8);
$leg = HomologacaoBancos::legado($banco);
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (string $sql, array $p = []) use ($pdo): array { try { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; } catch (Throwable $e) { return [['erro' => mb_substr($e->getMessage(), 0, 200)]]; } };
$v = static function (string $sql, array $p = []) use ($q) { $r = $q($sql, $p); return $r ? (isset($r[0]['erro']) ? $r[0]['erro'] : array_values($r[0])[0]) : null; };
$L = static fn(string $t): string => '`' . $leg . '`.`' . $t . '`';
$G = static fn(string $t): string => '`' . $banco . '`.`' . $t . '`';
$rel = ['banco' => $banco, 'legado' => $leg, 'gerado_em' => date('c')];

// 1. Filtro das views das telas
foreach (['vw_homologacao_fornecedor_documentos', 'vw_portaria_documentacao_fornecedor_grid', 'vw_homologacao_funcionarios_grid', 'vw_portaria_documentacao_funcionario_grid'] as $vw) {
    $def = (string)$v('SELECT VIEW_DEFINITION FROM VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$banco, $vw]);
    $i = strripos($def, ' where ');
    $rel['filtro_das_views'][$vw] = $i === false ? '(sem WHERE)' : mb_substr(preg_replace('/`' . preg_quote($banco, '/') . '`\./', '', substr($def, $i)) ?? '', 0, 600);
}
// 2. Documentos do fornecedor fora da tela
$rel['documentos_fora_da_tela'] = $q("SELECT CASE WHEN f.CODFORNECEDOR IS NULL THEN 'fornecedor inexistente' WHEN COALESCE(f.ATIVADOFOR, '') <> 'Sim' THEN 'fornecedor desativado' ELSE 'fornecedor ativo' END motivo,
    COUNT(*) total, SUM(EXISTS (SELECT 1 FROM " . $G('vw_homologacao_fornecedor_documentos') . " v WHERE v.codfornecedorxcriterios = d.codfornecedorxcriterios)) na_tela
    FROM " . $G('fo_fornecedorxcriterios') . " d LEFT JOIN " . $G('fo_fornecedores') . " f ON f.CODFORNECEDOR = d.codfornecedor GROUP BY 1");
$rel['documentos_fornecedor_ativo_fora_da_tela'] = $q("SELECT CASE WHEN NOT EXISTS (SELECT 1 FROM " . $G('fo_fornecedoresxtipofornecedores') . " ft WHERE ft.codfornecedor = d.codfornecedor AND ft.codtipofornecedor = d.codtipo) THEN 'tipo não está mais no fornecedor'
      WHEN NOT EXISTS (SELECT 1 FROM " . $G('fo_tipofornecedoresxcriteriofornecedores') . " tc WHERE tc.codtipofornecedor = d.codtipo AND tc.codcriteriofornecedor = d.codcriterio) THEN 'critério não está mais no tipo'
      ELSE 'outro' END motivo, COUNT(*) q
    FROM " . $G('fo_fornecedorxcriterios') . " d JOIN " . $G('fo_fornecedores') . " f ON f.CODFORNECEDOR = d.codfornecedor AND f.ATIVADOFOR = 'Sim'
    WHERE NOT EXISTS (SELECT 1 FROM " . $G('vw_homologacao_fornecedor_documentos') . " v WHERE v.codfornecedorxcriterios = d.codfornecedorxcriterios) GROUP BY 1");
// 3. Funcionários fora da tela
$rel['funcionarios_fora_da_tela'] = $q("SELECT CASE WHEN f.CODFORNECEDOR IS NULL THEN 'fornecedor inexistente' WHEN COALESCE(f.ATIVADOFOR, '') <> 'Sim' THEN 'fornecedor desativado' ELSE 'fornecedor ativo' END motivo, COUNT(*) q
    FROM " . $G('fo_funcionarios') . " u LEFT JOIN " . $G('fo_fornecedores') . " f ON f.CODFORNECEDOR = u.CODFORNECEDOR
    WHERE NOT EXISTS (SELECT 1 FROM " . $G('vw_homologacao_funcionarios_grid') . " v WHERE v.CODFUNCIONARIO = u.CODFUNCIONARIO) GROUP BY 1");
$rel['documentos_funcionario_fora_da_tela'] = $q("SELECT CASE WHEN u.CODFUNCIONARIO IS NULL THEN 'funcionário inexistente' WHEN f.CODFORNECEDOR IS NULL THEN 'fornecedor inexistente' WHEN COALESCE(f.ATIVADOFOR, '') <> 'Sim' THEN 'fornecedor desativado'
      WHEN NOT EXISTS (SELECT 1 FROM " . $G('fo_tipofuncionariosxcriteriofuncionarios') . " tc WHERE tc.codtipofuncionario = d.codtipofuncionario AND tc.codcriteriofuncionario = d.codcriteriofuncionario) THEN 'critério não está no tipo'
      ELSE 'outro' END motivo, COUNT(*) q
    FROM " . $G('fo_funcionarioxtiposxcriterios') . " d LEFT JOIN " . $G('fo_funcionarios') . " u ON u.CODFUNCIONARIO = d.codfuncionario LEFT JOIN " . $G('fo_fornecedores') . " f ON f.CODFORNECEDOR = u.CODFORNECEDOR
    WHERE NOT EXISTS (SELECT 1 FROM " . $G('vw_portaria_documentacao_funcionario_grid') . " v WHERE v.CODIGO = d.codigo) GROUP BY 1");
// 4. Vínculos sem chave comum
$vinculos = [
    'fo_tipofuncionariosxcriteriofuncionarios' => ['codtipofuncionario', 'codcriteriofuncionario', 'fo_tipofuncionarios', 'codtipofuncionario', 'fo_criteriofuncionarios', 'codcriteriofuncionario'],
    'fo_tipofuncionariosxtipotreinamentos' => ['codtipofuncionario', 'codtipotreinamento', 'fo_tipofuncionarios', 'codtipofuncionario', 'fo_tipotreinamentos', 'codtipotreinamento'],
    'fo_fornecedorxusuario' => ['codfornecedor', 'codusuario', 'fo_fornecedores', 'codfornecedor', null, null],
];
foreach ($vinculos as $t => [$a, $b, $pa, $ka, $pb, $kb]) {
    $semPaiL = "NOT EXISTS (SELECT 1 FROM " . $L($pa) . " p WHERE p.$ka = x.$a)" . ($pb ? " OR NOT EXISTS (SELECT 1 FROM " . $L($pb) . " p2 WHERE p2.$kb = x.$b)" : '');
    $rel['vinculos'][$t] = [
        'legado_linhas' => (int)$v("SELECT COUNT(*) FROM " . $L($t)),
        'legado_pares_distintos' => (int)$v("SELECT COUNT(*) FROM (SELECT DISTINCT $a, $b FROM " . $L($t) . ") z"),
        'legado_pares_repetidos' => (int)$v("SELECT COUNT(*) FROM (SELECT $a, $b FROM " . $L($t) . " GROUP BY $a, $b HAVING COUNT(*) > 1) z"),
        'legado_sem_pai' => (int)$v("SELECT COUNT(*) FROM " . $L($t) . " x WHERE $semPaiL"),
        'greenportal_linhas' => (int)$v("SELECT COUNT(*) FROM " . $G($t)),
        'pares_do_legado_que_faltam' => (int)$v("SELECT COUNT(*) FROM (SELECT DISTINCT $a, $b FROM " . $L($t) . ") x WHERE NOT EXISTS (SELECT 1 FROM " . $G($t) . " g WHERE g.$a = x.$a AND g.$b = x.$b)"),
        'pares_que_faltam_com_pai_no_greenportal' => (int)$v("SELECT COUNT(*) FROM (SELECT DISTINCT $a, $b FROM " . $L($t) . ") x WHERE NOT EXISTS (SELECT 1 FROM " . $G($t) . " g WHERE g.$a = x.$a AND g.$b = x.$b)
            AND EXISTS (SELECT 1 FROM " . $G($pa) . " p WHERE p.$ka = x.$a)" . ($pb ? " AND EXISTS (SELECT 1 FROM " . $G($pb) . " p2 WHERE p2.$kb = x.$b)" : '')),
        'so_no_greenportal' => (int)$v("SELECT COUNT(*) FROM " . $G($t) . " g WHERE NOT EXISTS (SELECT 1 FROM " . $L($t) . " x WHERE x.$a = g.$a AND x.$b = g.$b)"),
    ];
}
// 5. Gestão de Fornecedores do legado repete o fornecedor por tipo
$rel['legado_gestao_linhas_com_repeticao_por_tipo'] = (int)$v("SELECT COUNT(*) FROM " . $L('fo_fornecedores') . " f LEFT JOIN " . $L('fo_fornecedoresxtipofornecedores') . " t ON t.codfornecedor = f.CODFORNECEDOR");
// 6. Documentos repetidos no legado (fornecedor + tipo + critério)
$rel['documentos_repetidos_legado'] = (int)$v("SELECT COALESCE(SUM(c - 1), 0) FROM (SELECT COUNT(*) c FROM " . $L('fo_fornecedorxcriterios') . " GROUP BY codfornecedor, codtipo, codcriterio HAVING COUNT(*) > 1) z");
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
