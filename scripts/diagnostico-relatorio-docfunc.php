<?php
declare(strict_types=1);

/*
 * Diagnóstico (SÓ LEITURA) — Documentos de funcionários: qual regra reproduz o relatório do legado
 * "Gerenciamento de Documentos Exigidos Funcionários" (fo_relatorio_documentacao_exigida_func).
 * Comparação de 19/10/2026 na Crown: legado 4.733 linhas, 573 funcionários × greenportal 5.832, 603 funcionários.
 * O legado não mostra documentos de tipos de funcionário antigos nem de critérios fora do cadastro do tipo.
 * Conta, para funcionários ativos de fornecedores ativos, as linhas de fo_funcionarioxtiposxcriterios por regra:
 *   A  todas (regra atual do greenportal)
 *   B  só do tipo atual do funcionário (x.codtipofuncionario = fu.CODTIPOFUNCIONARIO)
 *   C  só critérios exigidos pelo tipo atual (fo_tipofuncionariosxcriteriofuncionarios)
 *   D  B e C
 *   E  C e o critério existe no cadastro (fo_criteriofuncionarios)
 *   F  B e o critério existe
 *   G  D e o critério existe
 * v2: lê também (só leitura) no banco legado a definição dos relatórios fo_relatorio_* (views ou configuração das transações)
 *     e a quantidade de linhas de cada um, para reproduzir exatamente a regra do legado. Grava em storage/logs.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-relatorio-docfunc.php [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$banco = 'gcrown_dbp';
foreach ($argv ?? [] as $a) if (preg_match('/^--banco=(g[a-z0-9_]+_dbp)$/', $a, $m)) $banco = $m[1];
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$base = "FROM fo_funcionarioxtiposxcriterios x
  JOIN fo_funcionarios fu ON fu.CODFUNCIONARIO = x.codfuncionario
  JOIN fo_fornecedores f ON f.codfornecedor = fu.CODFORNECEDOR
 WHERE UPPER(TRIM(COALESCE(fu.ativo, 'S'))) NOT IN ('N', 'NAO', 'NÃO', '0')
   AND LOWER(TRIM(COALESCE(f.ativadofor, ''))) IN ('sim', 's', '1')";
$tipoAtual = 'x.codtipofuncionario = fu.CODTIPOFUNCIONARIO';
$exigido = 'EXISTS (SELECT 1 FROM fo_tipofuncionariosxcriteriofuncionarios tc WHERE tc.codtipofuncionario = fu.CODTIPOFUNCIONARIO AND tc.codcriteriofuncionario = x.codcriteriofuncionario)';
$existe = 'EXISTS (SELECT 1 FROM fo_criteriofuncionarios cf WHERE cf.codcriteriofuncionario = x.codcriteriofuncionario)';
$regras = ['A' => '1=1', 'B' => $tipoAtual, 'C' => $exigido, 'D' => "$tipoAtual AND $exigido", 'E' => "$exigido AND $existe", 'F' => "$tipoAtual AND $existe", 'G' => "$tipoAtual AND $exigido AND $existe"];
$saida = ['banco' => $banco, 'legado_crown_19_10' => ['linhas' => 4733, 'funcionarios' => 573, 'fornecedores' => 44], 'regras' => []];
foreach ($regras as $k => $w) {
    $r = $pdo->query("SELECT COUNT(*) AS linhas, COUNT(DISTINCT x.codfuncionario) AS funcionarios, COUNT(DISTINCT fu.CODFORNECEDOR) AS fornecedores,
        SUM(UPPER(TRIM(COALESCE(x.atendeaocriterio,''))) = 'SIM') AS sim, SUM(UPPER(TRIM(COALESCE(x.atendeaocriterio,''))) IN ('NÃO','NAO')) AS nao,
        SUM(UPPER(TRIM(COALESCE(x.atendeaocriterio,''))) = 'DESLIGADO') AS desligado, SUM(TRIM(COALESCE(x.atendeaocriterio,'')) = '') AS vazio
        $base AND ($w)")->fetch(PDO::FETCH_ASSOC);
    $saida['regras'][$k] = array_map('intval', $r);
}
// Legado (só leitura): definição e tamanho dos relatórios gerenciais da homologação.
$leg = HomologacaoBancos::legado($banco);
if ($leg) {
    $q = static function (string $sql, array $p = []) use ($pdo): array { try { $st = $pdo->prepare($sql); $st->execute($p); $r = $st->fetchAll(PDO::FETCH_ASSOC); $st->closeCursor(); return $r; } catch (Throwable $e) { return [['erro' => mb_substr($e->getMessage(), 0, 200)]]; } };
    $saida['legado'] = ['banco' => $leg, 'views' => [], 'transacoes' => []];
    foreach ($q("SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND (TABLE_NAME LIKE 'fo\\_relatorio%' OR TABLE_NAME LIKE 'vw\\_fo\\_relatorio%')", [$leg]) as $t) {
        if (isset($t['erro'])) { $saida['legado']['views'] = $t; break; }
        $n = $q('SELECT COUNT(*) AS n FROM `' . $leg . '`.`' . $t['TABLE_NAME'] . '`')[0]['n'] ?? null;
        $def = $t['TABLE_TYPE'] === 'VIEW' ? (string)($q('SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$leg, $t['TABLE_NAME']])[0]['VIEW_DEFINITION'] ?? '') : '';
        $saida['legado']['views'][$t['TABLE_NAME']] = ['tipo' => $t['TABLE_TYPE'], 'linhas' => $n, 'definicao' => str_replace('`' . $leg . '`.', '', $def)];
    }
    // Transações configuradas (grid_tabulacao / cadastros_c): consulta gravada na configuração do legado.
    foreach ($q("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'configuracoes%' AND COLUMN_NAME IN ('CQUERY', 'CTRANSACAO', 'TRANSACAO', 'CTABELA', 'TABELA')", [$leg]) as $c) $cols[$c['TABLE_NAME']][] = $c['COLUMN_NAME'];
    foreach ($cols ?? [] as $tab => $cs) {
        if (!in_array('CQUERY', $cs, true)) continue;
        $chave = array_values(array_diff($cs, ['CQUERY']))[0] ?? null;
        if (!$chave) continue;
        foreach ($q('SELECT `' . $chave . '` AS chave, CQUERY FROM `' . $leg . '`.`' . $tab . '` WHERE `' . $chave . "` IN ('fo_relatorio_documentacao_exigida_func', 'fo_relatorio_treinamento', 'fo_relatorio_integracao', 'foRelatorioDocumentosexigidosFornecedor', 'foFuncionariosLista') OR CQUERY LIKE '%fo_relatorio_documentacao_exigida_func%'") as $r)
            if (!isset($r['erro'])) $saida['legado']['transacoes'][$tab . ':' . $r['chave']] = $r['CQUERY'];
    }
}
$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'diagnostico_relatorio_docfunc_' . $banco . '.json', $json);
echo $json . PHP_EOL;
