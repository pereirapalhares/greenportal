<?php
declare(strict_types=1);

/*
 * Diagnóstico das NCs do tipo Fornecedor (CODTIPONAOCONFORMIDADE = 1) — etapa 3 da reconstrução (21/10/2026). SOMENTE LEITURA.
 * Mostra: volume por etapa; preenchimento dos campos próprios do tipo (fornecedor, lote, quantidade, retenção, devolução,
 * classificação do fornecedor); de onde vem o fornecedor (fo_fornecedores e a tabela antiga FORNECEDOR); quem são os
 * responsáveis (perfil, se estão ligados ao fornecedor da NC em Fornecedor x Usuários); permissões dos perfis de fornecedor
 * nas telas de NC; listas de distribuição da categoria Fornecedores; e candidatos para o teste com um fornecedor externo.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-nc-fornecedor.php [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$banco = 'gcrown_dbp';
foreach ($argv ?? [] as $a) if (preg_match('/^--banco=(g[a-z0-9_]+_dbp)$/', $a, $m)) $banco = $m[1];
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
$q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(); $s->closeCursor(); return $r; };
$v = static function (string $sql, array $p = []) use ($q) { $r = $q($sql, $p); return $r ? reset($r[0]) : null; };
$tenta = static function (callable $f) { try { return $f(); } catch (Throwable $e) { return 'erro: ' . mb_substr($e->getMessage(), 0, 200); } };
$existe = static fn(string $t): bool => (int)$v('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]) > 0;
$T = 'nc_indicacao_ocorrencia o WHERE o.CODTIPONAOCONFORMIDADE = 1';
$ab = " AND COALESCE(o.ETAPA, '') <> 'Finalizado'";

$r = ['banco' => $banco, 'legado' => HomologacaoBancos::legado($banco)];
$r['volume'] = [
    'total' => (int)$v("SELECT COUNT(*) FROM $T"),
    'abertas' => (int)$v("SELECT COUNT(*) FROM $T$ab"),
    'criadas_2026' => (int)$v("SELECT COUNT(*) FROM $T AND o.data_de_criacao >= '2026-01-01'"),
    'abertas_por_etapa' => $q("SELECT o.ETAPA, COUNT(*) AS n FROM $T$ab GROUP BY o.ETAPA ORDER BY n DESC"),
];
$campos = ['CODFORNECEDOR' => "o.CODFORNECEDOR > 0", 'PRODUTO' => "TRIM(COALESCE(o.PRODUTO,'')) <> ''", 'CODIGODOPRODUTO' => "TRIM(COALESCE(o.CODIGODOPRODUTO,'')) <> ''",
    'QTDENVOLVIDA' => "TRIM(COALESCE(o.QTDENVOLVIDA,'')) <> ''", 'TAMANHOLOTEENVOLVIDO' => "TRIM(COALESCE(o.TAMANHOLOTEENVOLVIDO,'')) <> ''",
    'houve_retencao_produto' => "TRIM(COALESCE(o.houve_retencao_produto,'')) <> ''", 'havera_devolucao_material' => "TRIM(COALESCE(o.havera_devolucao_material,'')) <> ''",
    'PARADANALINHA' => "TRIM(COALESCE(o.PARADANALINHA,'')) <> ''", 'CODTIPOOCORRENCIA' => 'o.CODTIPOOCORRENCIA > 0', 'CODSETOR' => 'o.codsetor > 0',
    'LISTADEDISTRIBUICAO' => "o.listadedistribuicao REGEXP '^[1-9][0-9]*$'", 'data_de_conhecimento_fornecedor' => 'o.data_de_conhecimento_fornecedor IS NOT NULL',
    'data_de_reposta_do_fornecedor' => 'o.data_de_reposta_do_fornecedor IS NOT NULL'];
foreach ($campos as $c => $cond) $r['campos_preenchidos_2026'][$c] = $tenta(fn() => (int)$v("SELECT COUNT(*) FROM $T AND o.data_de_criacao >= '2026-01-01' AND $cond"));
$r['valores'] = [
    'houve_retencao_produto' => $tenta(fn() => $q("SELECT o.houve_retencao_produto AS valor, COUNT(*) AS n FROM $T GROUP BY 1")),
    'havera_devolucao_material' => $tenta(fn() => $q("SELECT o.havera_devolucao_material AS valor, COUNT(*) AS n FROM $T GROUP BY 1")),
    'PARADANALINHA' => $tenta(fn() => $q("SELECT o.PARADANALINHA AS valor, COUNT(*) AS n FROM $T GROUP BY 1")),
    'classificacao' => $tenta(fn() => $q("SELECT o.CODTIPOOCORRENCIA AS cod, c.dscclassificacao AS classificacao_fornecedor, t.DSCTIPOOCORRENCIA AS classificacao_empresa, COUNT(*) AS n
        FROM nc_indicacao_ocorrencia o LEFT JOIN nc_classificacao_fornecedor c ON c.codclassificacao = o.CODTIPOOCORRENCIA LEFT JOIN nc_tipoocorrencia t ON t.CODTIPOOCORRENCIA = o.CODTIPOOCORRENCIA
        WHERE o.CODTIPONAOCONFORMIDADE = 1 GROUP BY 1, 2, 3 ORDER BY n DESC")),
];
$r['fornecedor_da_nc'] = [
    'tabela_antiga_FORNECEDOR' => $existe('FORNECEDOR') ? (int)$v('SELECT COUNT(*) FROM FORNECEDOR') . ' linhas' : 'não existe',
    'nc_com_fornecedor_em_fo_fornecedores' => (int)$v("SELECT COUNT(*) FROM $T AND EXISTS (SELECT 1 FROM fo_fornecedores f WHERE f.CODFORNECEDOR = o.CODFORNECEDOR)"),
    'nc_com_fornecedor_so_na_tabela_antiga' => $existe('FORNECEDOR') ? (int)$v("SELECT COUNT(*) FROM $T AND o.CODFORNECEDOR > 0 AND NOT EXISTS (SELECT 1 FROM fo_fornecedores f WHERE f.CODFORNECEDOR = o.CODFORNECEDOR) AND EXISTS (SELECT 1 FROM FORNECEDOR x WHERE x.CODFORNECEDOR = o.CODFORNECEDOR)") : 0,
    'nc_com_fornecedor_inexistente' => (int)$v("SELECT COUNT(*) FROM $T AND o.CODFORNECEDOR > 0 AND NOT EXISTS (SELECT 1 FROM fo_fornecedores f WHERE f.CODFORNECEDOR = o.CODFORNECEDOR)" . ($existe('FORNECEDOR') ? ' AND NOT EXISTS (SELECT 1 FROM FORNECEDOR x WHERE x.CODFORNECEDOR = o.CODFORNECEDOR)' : '')),
    'fornecedores_ativos' => (int)$v("SELECT COUNT(*) FROM fo_fornecedores WHERE ativadofor = 'Sim'"),
    'nc_abertas_de_fornecedor_desativado' => (int)$v("SELECT COUNT(*) FROM $T$ab AND EXISTS (SELECT 1 FROM fo_fornecedores f WHERE f.CODFORNECEDOR = o.CODFORNECEDOR AND COALESCE(f.ativadofor,'') <> 'Sim')"),
];
// Papéis: perfil de cada envolvido nas NCs de fornecedor abertas
$papel = static fn(string $col) => $tenta(fn() => $q("SELECT COALESCE(p.codigo, '(sem perfil)') AS perfil, COUNT(DISTINCT o.$col) AS usuarios, COUNT(*) AS ncs_abertas
    FROM nc_indicacao_ocorrencia o LEFT JOIN usuarios u ON u.codusuario = o.$col LEFT JOIN perfis p ON p.codperfil = u.codperfil
    WHERE o.CODTIPONAOCONFORMIDADE = 1$ab AND o.$col > 0 GROUP BY 1 ORDER BY ncs_abertas DESC"));
$r['perfis_dos_envolvidos_abertas'] = ['emitente' => $papel('CODUSUARIO_EMISSOR'), 'gestor' => $papel('CODUSUARIO_GESTOR'), 'responsavel' => $papel('CODUSUARIO_RESPONSAVEL')];
$r['responsavel_ligado_ao_fornecedor_da_nc'] = $tenta(fn() => $q("SELECT
    SUM(o.CODUSUARIO_RESPONSAVEL > 0) AS com_responsavel,
    SUM(EXISTS (SELECT 1 FROM fo_fornecedorxusuario fu WHERE fu.CODUSUARIO = o.CODUSUARIO_RESPONSAVEL)) AS responsavel_e_usuario_de_algum_fornecedor,
    SUM(EXISTS (SELECT 1 FROM fo_fornecedorxusuario fu WHERE fu.CODUSUARIO = o.CODUSUARIO_RESPONSAVEL AND fu.CODFORNECEDOR = o.CODFORNECEDOR)) AS ligado_ao_fornecedor_da_nc
    FROM $T$ab")[0] ?? []);
$r['acoes_do_plano_abertas'] = $tenta(fn() => $q("SELECT COALESCE(pf.codigo, '(sem perfil)') AS perfil_do_responsavel_da_acao, COUNT(*) AS acoes
    FROM nc_planodeacao pa JOIN nc_indicacao_ocorrencia o ON o.CODOCORRENCIA = pa.CODOCORRENCIA AND o.CODTIPONAOCONFORMIDADE = 1 AND COALESCE(o.ETAPA,'') <> 'Finalizado'
    LEFT JOIN usuarios u ON u.codusuario = pa.CODUSUARIO LEFT JOIN perfis pf ON pf.codperfil = u.codperfil GROUP BY 1 ORDER BY acoes DESC"));
// Permissões dos perfis de fornecedor nas telas de NC
$r['permissoes_nc_perfis_fornecedor'] = $tenta(function () use ($q) {
    $out = [];
    foreach ($q("SELECT * FROM vw_permissoes_grid") as $l) {
        $txt = strtoupper(implode('|', array_map('strval', $l)));
        if (!str_contains($txt, 'NAO_CONFORMIDADES')) continue;
        if (!preg_match('/\|(FORNECEDOR|FORNECEDORES|FORNECEDOR_GESTOR)\|/', '|' . $txt . '|')) continue;
        $out[] = array_filter($l, static fn($x) => $x !== null && $x !== '' && $x !== '0' && $x !== 0);
    }
    return $out ?: 'nenhuma linha de NC para perfis de fornecedor em vw_permissoes_grid';
});
$r['perfis_existentes'] = $tenta(fn() => $q("SELECT codperfil, codigo FROM perfis WHERE codigo LIKE '%FORNEC%' OR codigo IN ('GESTORES','USUARIOS','VISITANTE')"));
// Listas de distribuição (Crown separa as da categoria Fornecedores)
$r['listas'] = [
    'categorias' => $tenta(fn() => $q('SELECT * FROM nc_categorias')),
    'listas_categoria_fornecedores' => $tenta(fn() => (int)$v("SELECT COUNT(DISTINCT lc.CODLISTA) FROM nc_listadistribuicaoxcategoria lc JOIN nc_categorias c ON c.CODCATEGORIA = lc.CODCATEGORIA WHERE c.dsccategoria = 'Fornecedores'")),
    'listas_total' => (int)$v('SELECT COUNT(*) FROM nc_listadistribuicao'),
    'nc_fornecedor_abertas_com_lista_fornecedores' => $tenta(fn() => (int)$v("SELECT COUNT(*) FROM $T$ab AND o.listadedistribuicao IN (SELECT CAST(lc.CODLISTA AS CHAR) FROM nc_listadistribuicaoxcategoria lc JOIN nc_categorias c ON c.CODCATEGORIA = lc.CODCATEGORIA WHERE c.dsccategoria = 'Fornecedores')")),
];
$r['classificacoes_fornecedor'] = $tenta(fn() => $q('SELECT codclassificacao, dscclassificacao FROM nc_classificacao_fornecedor ORDER BY dscclassificacao'));
// Candidatos para o teste: usuários de fornecedor responsáveis por NC aberta, ligados ao fornecedor da NC
$r['candidatos_teste_fornecedor_externo'] = $tenta(fn() => $q("SELECT u.userid, p.codigo AS perfil, u.ativo, COUNT(*) AS ncs_abertas_como_responsavel, MIN(o.CODFORNECEDOR) AS codfornecedor
    FROM nc_indicacao_ocorrencia o JOIN usuarios u ON u.codusuario = o.CODUSUARIO_RESPONSAVEL LEFT JOIN perfis p ON p.codperfil = u.codperfil
    WHERE o.CODTIPONAOCONFORMIDADE = 1$ab AND EXISTS (SELECT 1 FROM fo_fornecedorxusuario fu WHERE fu.CODUSUARIO = o.CODUSUARIO_RESPONSAVEL AND fu.CODFORNECEDOR = o.CODFORNECEDOR)
    GROUP BY u.userid, p.codigo, u.ativo ORDER BY ncs_abertas_como_responsavel DESC LIMIT 8"));

$json = json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'diagnostico_nc_fornecedor.json', $json);
echo $json . PHP_EOL;
