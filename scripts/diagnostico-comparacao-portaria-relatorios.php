<?php
declare(strict_types=1);

/*
 * Diagnóstico (SÓ LEITURA) — Portaria e relatórios da Homologação: legado × greenportal (20/10/2026).
 * As telas do legado (grid_tabulacao) não carregam no navegador na Crown; aqui a consulta de cada transação do legado
 * (hpr_configuracoes.configuracoes_transacoes.cquery, com #DATABASE# = banco legado, #USUARIO_LOGADO# = admin do legado e
 * #WHERE# = filtro do perfil TODOS) é contada direto no banco legado e comparada com o que o greenportal mostra:
 *   foFornecedorxCriterios(+Elis)      × Portaria › Documentação de fornecedor (vw_portaria_documentacao_fornecedor_grid)
 *   foFuncionariosXCriterios(+Elis)    × Portaria › Documentação de funcionário (vw_portaria_documentacao_funcionario_grid)
 *   foRelatorioDocumentosexigidosFornecedor × Relatórios › Documentos do fornecedor (DOCUMENTO do Painel)
 *   foFuncionariosLista (relatório unificado) × Relatórios › Relatório unificado (sp_fo_relatorio_unificado)
 *   fo_relatorio_planodeacao (plano de ação de fornecedores ativos) × Relatórios › Plano de ação (PLANO do Painel)
 * Mostra também contagens do greenportal com as regras do legado (ex.: o relatório de documentos do legado só traz documentos
 * com os dois responsáveis — inner join nos usuários), para explicar as diferenças.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-comparacao-portaria-relatorios.php [--banco=gatlas_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

try { $alvos = HomologacaoBancos::lista($argv ?? []); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci'); // views do greenportal comparam texto na collation da aplicação
$q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$v = static fn(string $sql, array $p = []) => ($r = $q($sql, $p)) ? array_values($r[0])[0] : null;
$existe = static fn(string $db, string $t): bool => (int)$v('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t]) > 0;
$tenta = static function (callable $f) { try { return $f(); } catch (Throwable $e) { return ['erro' => mb_substr($e->getMessage(), 0, 220)]; } };

// Consultas das transações (iguais para todas as empresas; #DATABASE# troca o banco)
$transacoes = ['foFornecedorxCriterios', 'foFornecedorxCriteriosElis', 'foFuncionariosXCriterios', 'foFuncionariosXCriteriosElis', 'foRelatorioDocumentosexigidosFornecedor'];
$cq = []; $filtros = [];
// Relatório unificado: a transação pode ter outro nome; procura as de fo_funcionarios com "Lista" no nome.
$unificado = array_column($q("SELECT dsctransacao FROM hpr_configuracoes.configuracoes_transacoes WHERE dsctransacao LIKE '%Funcionario%Lista%' OR dsctransacao LIKE '%Lista%Funcionario%'"), 'dsctransacao');
$transacoes = array_merge($transacoes, $unificado);
foreach ($q("SELECT codtransacao, dsctransacao, cquery FROM hpr_configuracoes.configuracoes_transacoes WHERE dsctransacao IN ('" . implode("','", $transacoes) . "')") as $t) $cq[$t['dsctransacao']] = ['cod' => (int)$t['codtransacao'], 'sql' => (string)$t['cquery']];
foreach ($q("SELECT codtransacao, perfil, dsccondicao FROM hpr_configuracoes.configuracoes_grupoxfiltro") as $f) if (strtoupper(trim((string)$f['perfil'])) === 'TODOS') $filtros[(int)$f['codtransacao']][] = (string)$f['dsccondicao'];

$contarLegado = static function (string $nome, string $leg, int $admin) use ($cq, $filtros, $v): mixed {
    if (!isset($cq[$nome])) return 'transação não encontrada no hpr_configuracoes';
    $sql = $cq[$nome]['sql'];
    $cond = $filtros[$cq[$nome]['cod']] ?? [];
    $where = $cond ? (preg_match('/\bwhere\b/i', $sql) ? ' AND ' : ' WHERE ') . implode(' AND ', $cond) : '';
    $sql = str_replace(['#DATABASE#', '#USUARIO_LOGADO#', '#WHERE#'], [$leg, (string)$admin, $where], $sql);
    $sql = preg_replace('/\border\s+by\b.*$/is', '', $sql) ?? $sql;
    // Conta sem calcular as colunas (algumas funções do legado, como getDSCUNIDADE, não existem em todos os bancos):
    // troca a lista de colunas por 1 (ou pela 1ª coluna, quando a consulta é DISTINCT).
    if (preg_match('/^\s*select\s+(distinct\s+)?(.*?)\bfrom\b/is', $sql, $m)) {
        $primeira = trim(explode(',', $m[2])[0]);
        $primeira = preg_replace('/\s+as\s+\w+$/i', '', $primeira) ?? $primeira;
        $sql = 'SELECT ' . ($m[1] !== '' ? 'DISTINCT ' . $primeira : '1') . ' ' . substr($sql, strlen($m[0]) - 4);
    }
    return (int)$v("SELECT COUNT(*) FROM ($sql) x");
};

// Códigos distintos de documento da Portaria de fornecedor do legado, comparados com a view do greenportal (por código).
$compararPortaria = static function (string $leg, int $admin, string $banco) use ($cq, $v, $q): array {
    if (!isset($cq['foFornecedorxCriterios'])) return ['erro' => 'transação não encontrada'];
    $sql = str_replace(['#DATABASE#', '#USUARIO_LOGADO#', '#WHERE#'], [$leg, (string)$admin, ''], $cq['foFornecedorxCriterios']['sql']);
    $sql = preg_replace('/\border\s+by\b.*$/is', '', $sql) ?? $sql;
    $sql = preg_replace('/^\s*select\s+.*?\bfrom\b/is', 'SELECT DISTINCT fo_fornecedorxcriterios.codfornecedorxcriterios AS c FROM', $sql, 1) ?? $sql;
    $G = "`$banco`";
    $r = ['legado_codigos_distintos' => (int)$v("SELECT COUNT(*) FROM ($sql) x"),
          'so_no_legado' => (int)$v("SELECT COUNT(*) FROM ($sql) x WHERE x.c NOT IN (SELECT CODFORNECEDORXCRITERIOS FROM $G.vw_portaria_documentacao_fornecedor_grid)"),
          'so_no_greenportal_nas_mesmas_unidades' => (int)$v("SELECT COUNT(*) FROM $G.vw_portaria_documentacao_fornecedor_grid g WHERE g.CODUNIDADE IN (SELECT codunidade FROM green_atlas_seguranca.usuariosxunidades WHERE codusuario = ?) AND g.CODFORNECEDORXCRITERIOS NOT IN ($sql)", [$admin])];
    // Motivo das linhas só no legado
    $r['so_no_legado_motivo'] = $q("SELECT CASE WHEN d.codfornecedorxcriterios IS NULL THEN 'documento não existe no greenportal'
              WHEN NOT EXISTS (SELECT 1 FROM $G.fo_fornecedoresxtipofornecedores ft WHERE ft.codfornecedor = d.codfornecedor AND ft.codtipofornecedor = d.codtipo) THEN 'tipo não está no fornecedor no greenportal'
              WHEN NOT EXISTS (SELECT 1 FROM $G.fo_tipofornecedoresxcriteriofornecedores tc WHERE tc.codtipofornecedor = d.codtipo AND tc.codcriteriofornecedor = d.codcriterio) THEN 'critério não está no tipo no greenportal'
              ELSE 'outro' END AS motivo, COUNT(*) AS n
          FROM ($sql) x LEFT JOIN $G.fo_fornecedorxcriterios d ON d.codfornecedorxcriterios = x.c
         WHERE x.c NOT IN (SELECT CODFORNECEDORXCRITERIOS FROM $G.vw_portaria_documentacao_fornecedor_grid) GROUP BY 1");
    return $r;
};

$saida = ['gerado_em' => date('c'), 'bancos' => []];
foreach ($alvos as $banco) {
    $leg = (string)HomologacaoBancos::legado($banco);
    $seg = $leg . '_seguranca';
    $G = "`$banco`";
    $admin = (int)($tenta(fn() => $v("SELECT codusuario FROM `$seg`.usuarios WHERE LOWER(userid) = 'admin' LIMIT 1")) ?: 0);
    $r = ['legado' => $leg, 'admin_legado_codusuario' => $admin,
          'admin_legado_unidades' => $tenta(fn() => (int)$v("SELECT COUNT(*) FROM `$seg`.usuariosxunidades WHERE codusuario = ?", [$admin]))];

    // Portaria — documentação de fornecedor
    $r['portaria_fornecedor'] = [
        'legado_foFornecedorxCriterios_admin' => $tenta(fn() => $contarLegado('foFornecedorxCriterios', $leg, $admin)),
        'legado_foFornecedorxCriteriosElis' => $tenta(fn() => $contarLegado('foFornecedorxCriteriosElis', $leg, $admin)),
        'greenportal_administrador' => $tenta(fn() => (int)$v("SELECT COUNT(*) FROM $G.vw_portaria_documentacao_fornecedor_grid")),
        'greenportal_nas_unidades_do_admin_legado' => $tenta(fn() => (int)$v("SELECT COUNT(*) FROM $G.vw_portaria_documentacao_fornecedor_grid g WHERE g.CODUNIDADE IN (SELECT codunidade FROM `$seg`.usuariosxunidades WHERE codusuario = ?)", [$admin])),
        // A consulta do legado tem green_atlas_seguranca fixo: só na Atlas a comparação por código faz sentido.
        'atlas_por_codigo' => $banco === 'gatlas_dbp' ? $tenta(fn() => $compararPortaria($leg, $admin, $banco)) : 'só na Atlas (a consulta do legado usa as unidades da Atlas em todas as empresas)',
    ];
    // Portaria — documentação de funcionário
    $r['portaria_funcionario'] = [
        'legado_foFuncionariosXCriterios_admin' => $tenta(fn() => $contarLegado('foFuncionariosXCriterios', $leg, $admin)),
        'legado_foFuncionariosXCriteriosElis' => $tenta(fn() => $contarLegado('foFuncionariosXCriteriosElis', $leg, $admin)),
        'greenportal_administrador' => $tenta(fn() => (int)$v("SELECT COUNT(*) FROM $G.vw_portaria_documentacao_funcionario_grid")),
        'greenportal_nas_unidades_do_admin_legado' => $tenta(fn() => (int)$v("SELECT COUNT(*) FROM $G.vw_portaria_documentacao_funcionario_grid g WHERE g.CODUNIDADE IN (SELECT codunidade FROM `$seg`.usuariosxunidades WHERE codusuario = ?)", [$admin])),
    ];
    // Relatório — documentos do fornecedor
    $r['relatorio_documentos_fornecedor'] = [
        'legado' => $tenta(fn() => $contarLegado('foRelatorioDocumentosexigidosFornecedor', $leg, $admin)),
        'greenportal' => $tenta(fn() => (int)$v("SELECT COUNT(*) FROM $G.vw_homologacao_painel_itens WHERE CATEGORIA = 'DOCUMENTO'")),
        'greenportal_com_os_dois_responsaveis' => $tenta(fn() => (int)$v("SELECT COUNT(*) FROM $G.vw_homologacao_painel_itens i WHERE i.CATEGORIA = 'DOCUMENTO'
            AND EXISTS (SELECT 1 FROM `$seg`.usuarios u1 WHERE u1.codusuario = i.CODUSUARIO_EMPRESA) AND EXISTS (SELECT 1 FROM `$seg`.usuarios u2 WHERE u2.codusuario = i.CODUSUARIO_FORNECEDOR)")),
        'obs' => 'o legado só traz documentos com responsável interno e responsável do fornecedor cadastrados (inner join nos usuários)',
    ];
    // Relatório unificado
    $r['relatorio_unificado'] = [
        'legado' => $unificado ? array_combine($unificado, array_map(fn($t) => $tenta(fn() => $contarLegado($t, $leg, $admin)), $unificado)) : 'transação do unificado não encontrada no hpr_configuracoes',
        'greenportal' => $tenta(function () use ($banco, $env) {
            $p = GreenPortalDatabase::connect($env, $banco, 'GPT'); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $s = $p->prepare('CALL sp_fo_relatorio_unificado(0, 0, NULL, NULL, \'\', NULL)'); $s->execute();
            $n = count($s->fetchAll(PDO::FETCH_ASSOC)); while ($s->nextRowset()) { } $s->closeCursor(); return $n;
        }),
    ];
    // Plano de ação (fo_relatorio_planodeacao: planos de fornecedores ativos)
    $r['relatorio_plano_de_acao'] = [
        'legado' => $tenta(fn() => (int)$v("SELECT COUNT(*) FROM `$leg`.fo_planodeacao p JOIN `$leg`.fo_fornecedores f ON f.codfornecedor = p.codfornecedor WHERE COALESCE(f.ativadofor, '') IN ('', 'Sim')")),
        'greenportal' => $tenta(fn() => (int)$v("SELECT COUNT(*) FROM $G.vw_homologacao_painel_itens WHERE CATEGORIA = 'PLANO'")),
    ];
    $saida['bancos'][$banco] = $r;
}
$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'diagnostico_comparacao_portaria_relatorios.json', $json);
echo $json . PHP_EOL;
