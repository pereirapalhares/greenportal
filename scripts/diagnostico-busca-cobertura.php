<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) da cobertura da Pesquisa de documentos, em gatlas_dbp e gmodelo_dbp:
 *  1. Vínculos Requisito legal ↔ evidência que NÃO entraram na pesquisa, por motivo:
 *     - legislação fora dos Requisitos legais da empresa (não está na tela de requisitos: não aplicável, revogada, outra empresa...);
 *     - requisito existe, mas não na unidade da ligação;
 *     - evidência (licença / controle / treinamento / infraestrutura) fora da tela dela.
 *  2. Arquivos nas pastas do GED sem cadastro na pesquisa, por motivo:
 *     - o registro não existe mais (excluído) — arquivos órfãos;
 *     - o registro existe, mas não aparece na tela de origem (filtro da view do grid).
 *  Com exemplos de cada caso. Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-busca-cobertura.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/GedStorageService.php';
require_once dirname(__DIR__) . '/classes/BuscaIndexadorService.php';

$env = GreenPortalDatabase::env();
$saida = ['diagnostico' => 'cobertura da pesquisa de documentos', 'bancos' => []];

// tipo => [tabela base, coluna da chave / pasta, colunas para os exemplos]
const DIAG_BASE = [
    'licencas' => ['li_licencas', 'CODLICENCA', ['DSCLICENCA', 'CODLICENCA_BASE']],
    'controles' => ['documentos', 'CODDOCUMENTO', ['CODIGO', 'TITULO']],
    'treinamentos' => ['ml_treinamenos', 'codtreinamento', ['dsctreinamento']],
    'infraestrutura' => ['ml_infraestrutura', 'codinfraestrutura', ['dscinfraestrutura']],
    'fornecedorcriterios' => ['fo_fornecedorxcriterios', 'codfornecedorxcriterios', ['codfornecedor', 'codcriterio', 'codunidade']],
    'funcionariocriterios' => ['fo_funcionarioxtiposxcriterios', 'codigo', ['codfuncionario', 'codtipofuncionario', 'codcriterio']],
    'funcionariotreinamentos' => ['fo_funcionarioxtiposxtreinamentos', 'codigo', ['codfuncionario', 'codtipotreinamento']],
];
// tabela de ligação => [coluna da evidência, tipo no índice]
const DIAG_LIGACOES = [
    'legislacaoxlicencas' => ['CODLICENCA', 'licencas'],
    'legislacaoxdocumentos' => ['CODDOCUMENTO', 'controles'],
    'legislacaoxtreinamentos' => ['CODTREINAMENTO', 'treinamentos'],
    'legislacaoxinfraestruturas' => ['CODINFRAESTRUTURA', 'infraestrutura'],
];

foreach (['gatlas_dbp', 'gmodelo_dbp'] as $banco) {
    $r = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $todos = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $v = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $v; };
        $um = static fn(string $sql, array $p = []) => $todos($sql, $p)[0] ?? [];
        $cols = static fn(string $t): array => array_map('strtolower', array_column($todos('SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]), 'c'));

        // 1. Vínculos
        $r['vinculos'] = [];
        foreach (DIAG_LIGACOES as $tabela => [$col, $tipo]) {
            $c = $cols($tabela);
            if (!$c || !in_array('codlegislacao', $c, true) || !in_array(strtolower($col), $c, true)) { $r['vinculos'][$tabela] = 'tabela não existe neste banco'; continue; }
            $unid = in_array('codunidade', $c, true) ? 'COALESCE(x.codunidade, 0)' : '0';
            $base = $tipo === 'licencas' && in_array('codlicenca_base', $cols('li_licencas'), true);
            $ref = $base ? 'COALESCE(NULLIF(l.CODLICENCA_BASE, 0), x.CODLICENCA)' : "x.`$col`";
            $from = "FROM `$tabela` x" . ($base ? ' LEFT JOIN `li_licencas` l ON l.CODLICENCA = x.CODLICENCA' : '');
            $evid = $tipo === 'licencas'
                ? "EXISTS (SELECT 1 FROM busca_indice e WHERE e.tipo = 'licencas' AND e.item = 'REGISTRO' AND e.pasta = $ref)"
                : "EXISTS (SELECT 1 FROM busca_indice e WHERE e.tipo = '$tipo' AND e.item = 'REGISTRO' AND e.chave = CONVERT(CAST($ref AS CHAR) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci)";
            $legis = "EXISTS (SELECT 1 FROM busca_indice q WHERE q.tipo = 'requisitos' AND q.item = 'REGISTRO' AND q.ref_legislacao = x.CODLEGISLACAO)";
            $naUnid = "EXISTS (SELECT 1 FROM busca_indice q WHERE q.tipo = 'requisitos' AND q.item = 'REGISTRO' AND q.ref_legislacao = x.CODLEGISLACAO AND ($unid = 0 OR q.ref_unidade = $unid))";
            $motivo = "CASE WHEN NOT $legis THEN 'legislacao_fora_dos_requisitos' WHEN NOT $naUnid THEN 'requisito_em_outra_unidade' WHEN NOT $evid THEN 'evidencia_fora_da_tela' ELSE 'ok' END";
            $cont = array_column($todos("SELECT $motivo AS motivo, COUNT(*) n $from GROUP BY motivo"), 'n', 'motivo');
            $ex = [];
            foreach (array_keys($cont) as $m) {
                if ($m === 'ok') continue;
                $ex[$m] = $todos("SELECT x.CODLEGISLACAO AS codlegislacao, $unid AS codunidade, $ref AS evidencia,
                        (SELECT GROUP_CONCAT(DISTINCT q.ref_unidade) FROM busca_indice q WHERE q.tipo = 'requisitos' AND q.item = 'REGISTRO' AND q.ref_legislacao = x.CODLEGISLACAO) AS unidades_do_requisito
                    $from WHERE $motivo = ? LIMIT 5", [$m]);
            }
            $r['vinculos'][$tabela] = ['ligacoes' => array_sum($cont), 'por_motivo' => $cont, 'exemplos' => $ex];
        }

        // 2. Arquivos sem cadastro na pesquisa
        $indexador = new BuscaIndexadorService($pdo, $env, $banco);
        $r['arquivos_sem_cadastro'] = [];
        foreach (BuscaIndexadorService::PASTAS as $tipo => $pasta) {
            $lista = $indexador->listarArquivos($pasta);
            if (!$lista) { $r['arquivos_sem_cadastro'][$tipo] = 'nenhum arquivo nas pastas'; continue; }
            $pastasIdx = array_flip(array_map('intval', array_column($todos("SELECT DISTINCT pasta FROM busca_indice WHERE tipo = ? AND item = 'REGISTRO' AND pasta IS NOT NULL", [$tipo]), 'pasta')));
            $sem = [];
            foreach ($lista as $a) if (!isset($pastasIdx[(int)$a['p']])) $sem[(int)$a['p']][] = $a['a'];
            if (!$sem) { $r['arquivos_sem_cadastro'][$tipo] = ['arquivos_nas_pastas' => count($lista), 'sem_cadastro' => 0]; continue; }
            [$tabela, $chave, $extras] = DIAG_BASE[$tipo];
            $c = $cols($tabela);
            $existe = [];
            if ($c) {
                $colsChave = [$chave];
                if ($tipo === 'licencas' && in_array('codlicenca_base', $c, true)) $colsChave[] = 'CODLICENCA_BASE';
                foreach (array_chunk(array_keys($sem), 500) as $lote) {
                    $in = implode(',', array_map('intval', $lote));
                    foreach ($colsChave as $cc) foreach ($todos("SELECT DISTINCT `$cc` AS id FROM `$tabela` WHERE `$cc` IN ($in)") as $x) $existe[(int)$x['id']] = true;
                }
            }
            $orfas = array_diff_key($sem, $existe); $fora = array_intersect_key($sem, $existe);
            $contar = static fn(array $g): int => array_sum(array_map('count', $g));
            $exFora = [];
            if ($fora && $c) {
                $sel = implode(', ', array_map(static fn($x) => "`$x`", array_values(array_filter($extras, static fn($x) => in_array(strtolower($x), $c, true)))));
                foreach (array_slice(array_keys($fora), 0, 5) as $id) $exFora[] = ['pasta' => $id, 'arquivos' => array_slice($fora[$id], 0, 3)] + $um("SELECT " . ($sel ?: "`$chave`") . " FROM `$tabela` WHERE `$chave` = ? LIMIT 1", [$id]);
            }
            $r['arquivos_sem_cadastro'][$tipo] = [
                'arquivos_nas_pastas' => count($lista),
                'registro_excluido' => ['pastas' => count($orfas), 'arquivos' => $contar($orfas), 'exemplos' => array_map(static fn($id) => ['pasta' => $id, 'arquivos' => array_slice($orfas[$id], 0, 3)], array_slice(array_keys($orfas), 0, 5))],
                'registro_fora_da_tela' => ['pastas' => count($fora), 'arquivos' => $contar($fora), 'exemplos' => $exFora],
            ];
        }
    } catch (Throwable $e) { $r['ERRO'] = $e->getMessage(); }
    $saida['bancos'][$banco] = $r;
}
$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
@file_put_contents(dirname(__DIR__) . '/storage/logs/diagnostico_busca_cobertura.json', $json);
echo $json . PHP_EOL;
