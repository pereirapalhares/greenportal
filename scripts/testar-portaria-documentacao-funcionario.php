<?php
declare(strict_types=1);

/*
 * Teste (somente leitura) da Portaria › Documentação de Funcionário depois da migração 262.
 * Em cada cliente confere: grid x consulta do legado (mesmos códigos, sem repetição), cores por situação,
 * restrição por unidade (view restrita + sp_portaria_docfuncionario_permitido), procedure de leitura da IA,
 * prompt FUNCIONARIO_DOCUMENTO, menu com a URL da tela, permissões e pasta do GED com um registro do grid.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\testar-portaria-documentacao-funcionario.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$pares = ['gatlas_dbp' => 'green_atlas', 'gmodelo_dbp' => 'green_modelo', 'gmodelo2_dbp' => 'green_modelo'];
$env = GreenPortalDatabase::env();
$saida = ['teste' => 'portaria documentação de funcionário', 'ok' => true, 'bancos' => []];
$falha = static function (array &$r, string $msg) use (&$saida): void { $r['FALHAS'][] = $msg; $saida['ok'] = false; };

foreach ($pares as $banco => $legado) {
    $r = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $um = static function (string $sql, array $p = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($p); $v = $s->fetchColumn(); $s->closeCursor(); return $v; };
        $todos = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $v = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $v; };
        $proc = static function (string $sql, array $p) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $v = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); while ($s->nextRowset()) {} return $v; };

        $grid = (int)$um('SELECT COUNT(*) FROM vw_portaria_documentacao_funcionario_grid');
        $dist = (int)$um('SELECT COUNT(DISTINCT CODIGO) FROM vw_portaria_documentacao_funcionario_grid');
        $leg = (int)$um("SELECT COUNT(DISTINCT r.codigo) FROM fo_funcionarioxtiposxcriterios r
            LEFT JOIN fo_funcionarios f ON f.CODFUNCIONARIO = r.codfuncionario LEFT JOIN fo_fornecedores p ON p.codfornecedor = f.CODFORNECEDOR
            LEFT JOIN unidades u ON u.codunidade = p.codunidade WHERE f.NOME IS NOT NULL AND u.DSCUNIDADE IS NOT NULL");
        $r['grid'] = ['linhas' => $grid, 'codigos_distintos' => $dist, 'consulta_legado' => $leg];
        if ($grid !== $dist) $falha($r, 'grid com códigos repetidos');
        if ($dist !== $leg) $falha($r, "grid ($dist) diferente da consulta do legado ($leg)");
        $r['cores'] = array_column($todos('SELECT SITUACAO_COR c, COUNT(*) n FROM vw_portaria_documentacao_funcionario_grid GROUP BY SITUACAO_COR ORDER BY n DESC'), 'n', 'c');
        $r['amostra_por_cor'] = $todos('SELECT SITUACAO_COR, MIN(ATENDEAOCRITERIO) atende, MIN(SITUACAO) situacao, MIN(DATADEVALIDADE) validade_min, MAX(DATADEVALIDADE) validade_max FROM vw_portaria_documentacao_funcionario_grid GROUP BY SITUACAO_COR');

        $usuario = (int)$um('SELECT CODUSUARIO_ACESSO FROM vw_portaria_documentacao_funcionario_acesso GROUP BY CODUSUARIO_ACESSO ORDER BY COUNT(DISTINCT CODUNIDADE) ASC, COUNT(*) DESC LIMIT 1');
        if ($usuario > 0) {
            $unidades = array_map('intval', array_column($todos('SELECT CODUNIDADE FROM vw_portaria_usuario_unidades WHERE CODUSUARIO = ?', [$usuario]), 'CODUNIDADE'));
            $lista = implode(',', $unidades ?: [0]);
            $fora = (int)$um("SELECT COUNT(*) FROM vw_portaria_documentacao_funcionario_acesso WHERE CODUSUARIO_ACESSO = ? AND CODUNIDADE NOT IN ($lista)", [$usuario]);
            $r['usuario_exemplo'] = ['codusuario' => $usuario, 'unidades' => $unidades, 'linhas_que_ve' => (int)$um('SELECT COUNT(*) FROM vw_portaria_documentacao_funcionario_acesso WHERE CODUSUARIO_ACESSO = ?', [$usuario]), 'linhas_de_outras_unidades' => $fora];
            if ($fora) $falha($r, 'view restrita trouxe unidade que não é do usuário');
            $dentro = (int)$um('SELECT CODIGO FROM vw_portaria_documentacao_funcionario_acesso WHERE CODUSUARIO_ACESSO = ? LIMIT 1', [$usuario]);
            $outro = (int)$um("SELECT CODIGO FROM vw_portaria_documentacao_funcionario_grid WHERE CODUNIDADE NOT IN ($lista) LIMIT 1");
            $ok = static fn(int $id): int => (int)($proc('CALL sp_portaria_docfuncionario_permitido(?, ?)', [$id, $usuario])[0]['PERMITIDO'] ?? 0);
            $r['procedure_unidade'] = ['registro_da_unidade' => $dentro ? $ok($dentro) : 'sem registro', 'registro_de_outra_unidade' => $outro ? $ok($outro) : 'sem registro'];
            if ($dentro && $ok($dentro) < 1) $falha($r, 'procedure negou registro da unidade do usuário');
            if ($outro && $ok($outro) > 0) $falha($r, 'procedure liberou registro de outra unidade');
        } else $r['usuario_exemplo'] = 'nenhum usuário com unidade que tenha documentos';

        $cod = (int)$um('SELECT CODIGO FROM vw_portaria_documentacao_funcionario_grid LIMIT 1');
        if ($cod) { $x = $proc('CALL sp_docfuncionario_ia_registro(?)', [$cod])[0] ?? null; $r['ia_registro'] = $x ? ['codigo' => $cod, 'funcionario' => $x['FUNCIONARIO'], 'criterio' => $x['DSCRITERIO']] : 'procedure não devolveu'; if (!$x) $falha($r, 'sp_docfuncionario_ia_registro sem retorno'); }
        $r['prompt_FUNCIONARIO_DOCUMENTO'] = (int)$um("SELECT COUNT(*) FROM ia_prompts WHERE codigo = 'FUNCIONARIO_DOCUMENTO'") ? 'existe' : 'NÃO EXISTE';

        $menu = $todos("SELECT codmenu, url FROM menus WHERE codigo = 'PORTARIA_DOCUMENTACAO_FUNCIONARIO'");
        $r['menu'] = $menu[0] ?? 'não existe';
        if (!$menu || $menu[0]['url'] !== '/principal/portaria_documentacaofuncionario.php') $falha($r, 'menu sem a URL da tela');
        if ($menu) foreach (['PORTARIA', 'HOMOLOGACAO', 'ADM_DA_EMPRESA'] as $perfil) {
            $p = $todos('SELECT pm.pode_visualizar, pm.pode_visualizar_arquivos, pm.pode_importar_arquivos, pm.pode_excluir, pm.pode_editar FROM permissoes pm JOIN perfis pf ON pf.codperfil = pm.codperfil WHERE UPPER(pf.codigo) = ? AND pm.codmenu = ?', [$perfil, (int)$menu[0]['codmenu']]);
            $r['permissoes'][$perfil] = $p[0] ?? 'sem permissão gravada';
        }

        $raiz = rtrim((string)($env['GGED_LEGACY_ROOT'] ?? 'E:\\projetoGreen\\GED'), '\\/') . DIRECTORY_SEPARATOR . $legado . DIRECTORY_SEPARATOR . 'fo_funcionarioxtiposxcriterios';
        $ex = null; $vistas = 0; $noGrid = 0;
        foreach (glob($raiz . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $d) {
            $id = (int)basename($d); if ($id < 1) continue; $vistas++;
            if ((int)$um('SELECT COUNT(*) FROM vw_portaria_documentacao_funcionario_grid WHERE CODIGO = ?', [$id])) { $noGrid++; $ex ??= ['codigo' => $id, 'arquivos' => count(array_filter(glob($d . DIRECTORY_SEPARATOR . '*') ?: [], 'is_file'))]; }
            if ($vistas >= 3000) break;
        }
        $r['ged'] = ['pasta' => $raiz, 'existe' => is_dir($raiz), 'pastas' => $vistas, 'pastas_de_registros_do_grid' => $noGrid, 'exemplo' => $ex ?? 'nenhum'];
    } catch (Throwable $e) { $falha($r, 'ERRO: ' . $e->getMessage()); }
    $saida['bancos'][$banco] = $r;
}
$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents(dirname(__DIR__) . '/storage/logs/teste_portaria_documentacao_funcionario.json', $json);
echo $json . PHP_EOL;
exit($saida['ok'] ? 0 : 1);
