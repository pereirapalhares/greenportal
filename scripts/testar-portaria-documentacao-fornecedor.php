<?php
declare(strict_types=1);

/*
 * Teste (somente leitura) da Portaria › Documentação de Fornecedor depois da migração 260.
 * Em cada cliente confere:
 *  - grid x consulta do legado: mesmos códigos (distintos), sem repetição;
 *  - cores por situação; usuários com unidade;
 *  - restrição por unidade: para um usuário de exemplo, a view restrita só traz as unidades dele e a
 *    procedure sp_portaria_docfornecedor_permitido concorda (1 para registro da unidade, 0 para outra unidade);
 *  - menu com a URL da tela; permissões de PORTARIA, HOMOLOGACAO e ADM_DA_EMPRESA;
 *  - pasta do GED de um registro com arquivos.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\testar-portaria-documentacao-fornecedor.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$pares = ['gatlas_dbp' => 'green_atlas', 'gmodelo_dbp' => 'green_modelo', 'gmodelo2_dbp' => 'green_modelo'];
$env = GreenPortalDatabase::env();
$saida = ['teste' => 'portaria documentação de fornecedor', 'ok' => true, 'bancos' => []];
$falha = static function (array &$r, string $msg) use (&$saida): void { $r['FALHAS'][] = $msg; $saida['ok'] = false; };

foreach ($pares as $banco => $legado) {
    $r = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $um = static function (string $sql, array $p = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($p); $v = $s->fetchColumn(); $s->closeCursor(); return $v; };
        $todos = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $v = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $v; };

        $grid = (int)$um('SELECT COUNT(*) FROM vw_portaria_documentacao_fornecedor_grid');
        $distintos = (int)$um('SELECT COUNT(DISTINCT CODFORNECEDORXCRITERIOS) FROM vw_portaria_documentacao_fornecedor_grid');
        $legadoDistintos = (int)$um("SELECT COUNT(DISTINCT fc.codfornecedorxcriterios) FROM fo_fornecedorxcriterios fc
            JOIN fo_fornecedores f ON f.codfornecedor = fc.codfornecedor JOIN unidades u ON u.codunidade = f.codunidade
            JOIN fo_fornecedoresxtipofornecedores ft ON ft.codfornecedor = f.codfornecedor AND ft.codtipofornecedor = fc.codtipo
            JOIN fo_tipofornecedoresxcriteriofornecedores tc ON tc.codtipofornecedor = fc.codtipo AND tc.codcriteriofornecedor = fc.codcriterio
            JOIN fo_tipofornecedores t ON t.codtipofornecedor = tc.codtipofornecedor JOIN fo_criteriofornecedores c ON c.codcriteriofornecedor = tc.codcriteriofornecedor");
        $r['grid'] = ['linhas' => $grid, 'codigos_distintos' => $distintos, 'consulta_legado_codigos_distintos' => $legadoDistintos];
        if ($grid !== $distintos) $falha($r, 'grid com códigos repetidos');
        if ($distintos !== $legadoDistintos) $falha($r, "grid ($distintos) diferente da consulta do legado ($legadoDistintos)");
        $r['cores'] = array_column($todos('SELECT SITUACAO_COR c, COUNT(*) n FROM vw_portaria_documentacao_fornecedor_grid GROUP BY SITUACAO_COR ORDER BY n DESC'), 'n', 'c');
        $r['amostra_por_cor'] = $todos('SELECT SITUACAO_COR, MIN(SITUACAO) situacao, MIN(DATADEVALIDADE) validade_min, MAX(DATADEVALIDADE) validade_max FROM vw_portaria_documentacao_fornecedor_grid GROUP BY SITUACAO_COR');

        // Restrição por unidade
        $r['usuarios_com_unidade'] = (int)$um('SELECT COUNT(DISTINCT CODUSUARIO) FROM vw_portaria_usuario_unidades');
        $usuario = (int)$um('SELECT a.CODUSUARIO_ACESSO FROM vw_portaria_documentacao_fornecedor_acesso a GROUP BY a.CODUSUARIO_ACESSO ORDER BY COUNT(DISTINCT a.CODUNIDADE) ASC, COUNT(*) DESC LIMIT 1');
        if ($usuario > 0) {
            $unidades = array_map('intval', array_column($todos('SELECT CODUNIDADE FROM vw_portaria_usuario_unidades WHERE CODUSUARIO = ?', [$usuario]), 'CODUNIDADE'));
            $fora = (int)$um('SELECT COUNT(*) FROM vw_portaria_documentacao_fornecedor_acesso WHERE CODUSUARIO_ACESSO = ? AND CODUNIDADE NOT IN (' . implode(',', $unidades ?: [0]) . ')', [$usuario]);
            $dele = (int)$um('SELECT COUNT(*) FROM vw_portaria_documentacao_fornecedor_acesso WHERE CODUSUARIO_ACESSO = ?', [$usuario]);
            $r['usuario_exemplo'] = ['codusuario' => $usuario, 'unidades' => $unidades, 'linhas_que_ve' => $dele, 'linhas_de_outras_unidades' => $fora];
            if ($fora) $falha($r, 'view restrita trouxe unidade que não é do usuário');
            $dentro = (int)$um('SELECT CODFORNECEDORXCRITERIOS FROM vw_portaria_documentacao_fornecedor_acesso WHERE CODUSUARIO_ACESSO = ? LIMIT 1', [$usuario]);
            $outro = (int)$um('SELECT CODFORNECEDORXCRITERIOS FROM vw_portaria_documentacao_fornecedor_grid WHERE CODUNIDADE NOT IN (' . implode(',', $unidades ?: [0]) . ') LIMIT 1');
            $proc = static function (int $id) use ($pdo, $usuario): int { $s = $pdo->prepare('CALL sp_portaria_docfornecedor_permitido(?, ?)'); $s->execute([$id, $usuario]); $v = (int)$s->fetchColumn(); $s->closeCursor(); while ($s->nextRowset()) {} return $v; };
            $r['procedure'] = ['registro_da_unidade' => $dentro ? $proc($dentro) : 'sem registro', 'registro_de_outra_unidade' => $outro ? $proc($outro) : 'sem registro'];
            if ($dentro && $proc($dentro) < 1) $falha($r, 'procedure negou registro da unidade do usuário');
            if ($outro && $proc($outro) > 0) $falha($r, 'procedure liberou registro de outra unidade');
        } else $r['usuario_exemplo'] = 'nenhum usuário com unidade que tenha documentos';

        // Menu e permissões
        $menu = $todos("SELECT codmenu, url FROM menus WHERE codigo = 'PORTARIA_DOCUMENTACAO_FORNECEDOR'");
        $r['menu'] = $menu[0] ?? 'não existe';
        if (!$menu || $menu[0]['url'] !== '/principal/portaria_documentacaofornecedor.php') $falha($r, 'menu sem a URL da tela');
        if ($menu) {
            $cols = array_map('strtolower', array_column($todos("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'permissoes'"), 'COLUMN_NAME'));
            $sel = implode(', ', array_map(static fn($c) => "pm.$c", array_intersect(['pode_visualizar', 'pode_visualizar_arquivos', 'pode_importar_arquivos', 'pode_excluir', 'pode_exportar_excel'], $cols)));
            foreach (['PORTARIA', 'HOMOLOGACAO', 'ADM_DA_EMPRESA'] as $perfil) {
                $p = $todos("SELECT $sel FROM permissoes pm JOIN perfis pf ON pf.codperfil = pm.codperfil WHERE UPPER(pf.codigo) = ? AND pm.codmenu = ?", [$perfil, (int)$menu[0]['codmenu']]);
                $r['permissoes'][$perfil] = $p[0] ?? 'sem permissão gravada';
            }
        }

        // GED
        $raiz = rtrim((string)($env['GGED_LEGACY_ROOT'] ?? 'E:\\projetoGreen\\GED'), '\\/') . DIRECTORY_SEPARATOR . $legado . DIRECTORY_SEPARATOR . 'fo_fornecedorxcriterios';
        $comArquivo = null;
        foreach (array_slice(glob($raiz . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [], 0, 200) as $d) {
            $id = (int)basename($d);
            if ($id > 0 && (int)$um('SELECT COUNT(*) FROM vw_portaria_documentacao_fornecedor_grid WHERE CODFORNECEDORXCRITERIOS = ?', [$id])) { $comArquivo = ['codigo' => $id, 'arquivos' => count(array_filter(glob($d . DIRECTORY_SEPARATOR . '*') ?: [], 'is_file'))]; break; }
        }
        $r['ged'] = ['pasta' => $raiz, 'existe' => is_dir($raiz), 'exemplo_no_grid' => $comArquivo ?? 'nenhum'];
    } catch (Throwable $e) { $falha($r, 'ERRO: ' . $e->getMessage()); }
    $saida['bancos'][$banco] = $r;
}
$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents(dirname(__DIR__) . '/storage/logs/teste_portaria_documentacao_fornecedor.json', $json);
echo $json . PHP_EOL;
exit($saida['ok'] ? 0 : 1);
