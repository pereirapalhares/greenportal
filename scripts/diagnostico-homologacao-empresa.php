<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) — Homologação de Fornecedores: legado × greenportal de uma empresa.
 * Para cada tela do módulo: linhas no legado (tabelas fo_*) e no greenportal (tabela e view que a tela lê),
 * distribuição de situações, ativos/desativados, órfãos e o que a tela do greenportal deixa de mostrar.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-homologacao-empresa.php [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$banco = 'gcrown_dbp';
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--banco=')) $banco = substr($a, 8);
$leg = HomologacaoBancos::legado($banco);
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$v = static function (string $sql, array $p = []) use ($q) { try { $r = $q($sql, $p); return $r ? array_values($r[0])[0] : null; } catch (Throwable $e) { return 'ERRO: ' . mb_substr($e->getMessage(), 0, 160); } };
$n = static fn(string $s): string => '`' . str_replace('`', '``', $s) . '`';
$existe = static fn(string $db, string $t): bool => (bool)$v('SELECT COUNT(*) FROM TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t]);
$coluna = static fn(string $db, string $t, string $c): ?string => $v('SELECT COLUMN_NAME FROM COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND LOWER(COLUMN_NAME) = LOWER(?) LIMIT 1', [$db, $t, $c]);
$conta = static function (string $db, string $t, string $where = '') use ($existe, $v, $n) { return $existe($db, $t) ? (int)$v('SELECT COUNT(*) FROM ' . $n($db) . '.' . $n($t) . ($where ? " WHERE $where" : '')) : 'não existe'; };
$porValor = static function (string $db, string $t, string $c) use ($existe, $coluna, $q, $n): array|string {
    if (!$existe($db, $t) || !($col = $coluna($db, $t, $c))) return 'não existe';
    return array_column($q('SELECT COALESCE(NULLIF(TRIM(' . $n($col) . "), ''), '(vazio)') v, COUNT(*) q FROM " . $n($db) . '.' . $n($t) . ' GROUP BY 1 ORDER BY 2 DESC LIMIT 15'), 'q', 'v');
};

$rel = ['diagnostico' => 'homologação de fornecedores legado × greenportal', 'banco' => $banco, 'legado' => $leg, 'gerado_em' => date('c'), 'telas' => []];
if (!$leg) { $rel['erro'] = 'banco legado não encontrado'; echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL; exit(1); }

// tela => [tabela, view do greenportal, filtro do legado (admin)]
$telas = [
    'Gestão de Fornecedores (ativos)' => ['fo_fornecedores', 'vw_homologacao_fornecedores_ativos_grid', "ATIVADOFOR = 'Sim'"],
    'Fornecedores desativados' => ['fo_fornecedores', 'vw_homologacao_fornecedores_desativados_grid', "COALESCE(ATIVADOFOR, '') <> 'Sim'"],
    'Tipos de fornecedores' => ['fo_tipofornecedores', 'vw_homologacao_tipos_fornecedores_cadastro_grid', ''],
    'Critérios dos fornecedores' => ['fo_criteriofornecedores', 'vw_homologacao_criterios_fornecedores_grid', ''],
    'Tipo × critério (nível de exigência)' => ['fo_tipofornecedoresxcriteriofornecedores', '', ''],
    'Tipo × palavras-chave' => ['fo_tipofornecedoresxpalavraschave', '', ''],
    'Fornecedor × tipo (auditoria)' => ['fo_fornecedoresxtipofornecedores', '', ''],
    'Documentos do fornecedor' => ['fo_fornecedorxcriterios', 'vw_homologacao_fornecedor_documentos', ''],
    'Histórico de alterações de documentos' => ['fo_fornecedorxcriterios_historico', 'vw_homologacao_fornecedor_documentos_historico', ''],
    'Usuários do fornecedor' => ['fo_fornecedorxusuario', '', ''],
    'Contatos' => ['fo_contatos', '', ''],
    'Certificados' => ['fo_certificados', '', ''],
    'Produtos químicos' => ['fo_produtoquimico', '', ''],
    'Equipamentos' => ['fo_equipamentos', '', ''],
    'Plano de ação do fornecedor' => ['fo_planodeacao', '', ''],
    'Gestão de Funcionários' => ['fo_funcionarios', 'vw_homologacao_funcionarios_grid', ''],
    'Tipos de funcionários' => ['fo_tipofuncionarios', 'vw_homologacao_tipos_funcionarios_cadastro_grid', ''],
    'Critérios dos funcionários' => ['fo_criteriofuncionarios', 'vw_homologacao_criterios_funcionarios_grid', ''],
    'Tipos de treinamento' => ['fo_tipotreinamentos', 'vw_homologacao_tipos_treinamentos_grid', ''],
    'Tipo func. × critério' => ['fo_tipofuncionariosxcriteriofuncionarios', '', ''],
    'Tipo func. × treinamento' => ['fo_tipofuncionariosxtipotreinamentos', '', ''],
    'Documentos do funcionário' => ['fo_funcionarioxtiposxcriterios', 'vw_portaria_documentacao_funcionario_grid', ''],
    'Treinamentos do funcionário' => ['fo_funcionarioxtiposxtreinamentos', '', ''],
    'Portaria › Documentação de fornecedor' => ['fo_fornecedorxcriterios', 'vw_portaria_documentacao_fornecedor_grid', ''],
    'Painel de Homologação (itens)' => ['', 'vw_homologacao_painel_itens', ''],
];
foreach ($telas as $nome => [$tab, $view, $filtro]) {
    $linha = [];
    if ($tab) {
        $linha['legado'] = $conta($leg, $tab, $filtro);
        $linha['greenportal_tabela'] = $conta($banco, $tab, $filtro);
    }
    if ($view) $linha['greenportal_tela'] = $conta($banco, $view);
    $rel['telas'][$nome] = $linha;
}
// distribuições que a comparação de telas usa
$rel['situacao_documentos'] = ['legado' => $porValor($leg, 'fo_fornecedorxcriterios', 'situacao'), 'greenportal' => $porValor($banco, 'fo_fornecedorxcriterios', 'situacao')];
$rel['protocolo_documentos'] = ['legado' => $porValor($leg, 'fo_fornecedorxcriterios', 'emprotocolo'), 'greenportal' => $porValor($banco, 'fo_fornecedorxcriterios', 'emprotocolo')];
$rel['nivel_exigencia'] = ['legado' => $porValor($leg, 'fo_tipofornecedoresxcriteriofornecedores', 'nivel_de_exigencia'), 'greenportal' => $porValor($banco, 'fo_tipofornecedoresxcriteriofornecedores', 'nivel_de_exigencia')];
$rel['atende_documentos_funcionario'] = ['legado' => $porValor($leg, 'fo_funcionarioxtiposxcriterios', 'atendeaocriterio'), 'greenportal' => $porValor($banco, 'fo_funcionarioxtiposxcriterios', 'atendeaocriterio')];
$rel['ativado_fornecedor'] = ['legado' => $porValor($leg, 'fo_fornecedores', 'ATIVADOFOR'), 'greenportal' => $porValor($banco, 'fo_fornecedores', 'ATIVADOFOR')];
$rel['necessita_auditoria'] = ['legado' => $porValor($leg, 'fo_tipofornecedores', 'NECESSITA_AUDITORIA'), 'greenportal' => $porValor($banco, 'fo_tipofornecedores', 'NECESSITA_AUDITORIA')];
// Crown: a pendência da Home do legado (variante _crown) mostra só "Pendente com a empresa", sem filtro de fornecedor ativo
$rel['pendencias_home_legado_crown'] = [
    'documentos_pendente_com_a_empresa' => $conta($leg, 'fo_fornecedorxcriterios', "situacao = 'Pendente com a empresa'"),
    'documentos_pendente_com_a_empresa_greenportal' => $conta($banco, 'fo_fornecedorxcriterios', "situacao = 'Pendente com a empresa'"),
];
// fornecedores do legado que não estão na tela do greenportal (ativos)
if ($existe($leg, 'fo_fornecedores') && $existe($banco, 'fo_fornecedores')) {
    $L = $n($leg) . '.`fo_fornecedores`'; $G = $n($banco) . '.`fo_fornecedores`';
    $rel['fornecedores'] = [
        'so_no_legado' => (int)$v("SELECT COUNT(*) FROM $L l WHERE NOT EXISTS (SELECT 1 FROM $G g WHERE g.CODFORNECEDOR = l.CODFORNECEDOR)"),
        'so_no_greenportal' => (int)$v("SELECT COUNT(*) FROM $G g WHERE NOT EXISTS (SELECT 1 FROM $L l WHERE l.CODFORNECEDOR = g.CODFORNECEDOR)"),
        'ativado_diferente' => (int)$v("SELECT COUNT(*) FROM $L l JOIN $G g ON g.CODFORNECEDOR = l.CODFORNECEDOR WHERE NOT (COALESCE(l.ATIVADOFOR, '') <=> COALESCE(g.ATIVADOFOR, ''))"),
    ];
    // amostra: fornecedores ativos com mais documentos (para conferir na tela)
    if ($existe($leg, 'fo_fornecedorxcriterios')) {
        $rel['amostra_fornecedores'] = $q("SELECT l.CODFORNECEDOR, l.RAZAOSOCIAL, (SELECT COUNT(*) FROM " . $n($leg) . ".fo_fornecedorxcriterios d WHERE d.codfornecedor = l.CODFORNECEDOR) docs_legado,
            (SELECT COUNT(*) FROM " . $n($banco) . ".fo_fornecedorxcriterios d WHERE d.codfornecedor = l.CODFORNECEDOR) docs_greenportal,
            (SELECT COUNT(*) FROM " . $n($leg) . ".fo_funcionarios f WHERE f.CODFORNECEDOR = l.CODFORNECEDOR) func_legado,
            (SELECT COUNT(*) FROM " . $n($banco) . ".fo_funcionarios f WHERE f.CODFORNECEDOR = l.CODFORNECEDOR) func_greenportal
            FROM $L l WHERE l.ATIVADOFOR = 'Sim' ORDER BY docs_legado DESC LIMIT 5");
    }
}
// documentos: diferenças de valor por coluna (mesma chave)
if ($existe($leg, 'fo_fornecedorxcriterios') && $existe($banco, 'fo_fornecedorxcriterios')) {
    $L = $n($leg) . '.`fo_fornecedorxcriterios`'; $G = $n($banco) . '.`fo_fornecedorxcriterios`';
    $dif = [];
    foreach (['situacao', 'datadevalidade', 'emprotocolo', 'enviadoodocumento', 'codusuario', 'codusuario_fornecedor', 'observacao', 'incluidocontrato', 'datadereacao'] as $c) {
        if (!$coluna($leg, 'fo_fornecedorxcriterios', $c) || !$coluna($banco, 'fo_fornecedorxcriterios', $c)) { $dif[$c] = 'coluna ausente'; continue; }
        $x = (int)$v("SELECT COUNT(*) FROM $L l JOIN $G g ON g.codfornecedorxcriterios = l.codfornecedorxcriterios WHERE NOT (CAST(g.$c AS CHAR) <=> CAST(l.$c AS CHAR))");
        if ($x) $dif[$c] = $x;
    }
    $rel['documentos_valores_diferentes'] = $dif ?: 'nenhum';
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
