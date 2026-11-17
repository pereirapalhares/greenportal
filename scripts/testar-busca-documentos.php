<?php
declare(strict_types=1);

/*
 * Teste (somente leitura) da Pesquisa de documentos (migração 264 + serviço busca-worker), em gatlas_dbp e gmodelo_dbp:
 *  - índice x fonte: registros por tipo iguais aos da vw_busca_fonte_registros; arquivos indexados por tipo;
 *  - pesquisa como ADMINISTRADOR por uma palavra de um título real (tem de achar o registro);
 *  - permissões: sem transação nenhuma não acha nada; Portaria só nas unidades do usuário; Licenças sem
 *    "Visualizar total" só as do responsável;
 *  - arquivo de exemplo existe no disco (mesma pasta que a janela de arquivos usa);
 *  - pesquisa agrupada pelo negócio (sp_busca_pesquisar_negocios): o grupo do registro achado aparece; vínculos do catálogo
 *    busca_relacao: um vínculo sorteado aparece nos dois negócios (lado A e lado B), usando o filtro "Ver na pesquisa";
 *  - dicionário: termos por base e situação; um termo aprovado sorteado expande a pesquisa.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\testar-busca-documentos.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/GedStorageService.php';
require_once dirname(__DIR__) . '/classes/BuscaIndexadorService.php';

$env = GreenPortalDatabase::env();
$saida = ['teste' => 'pesquisa de documentos', 'ok' => true, 'bancos' => []];
$falha = static function (array &$r, string $m) use (&$saida): void { $r['FALHAS'][] = $m; $saida['ok'] = false; };

foreach (['gatlas_dbp', 'gmodelo_dbp'] as $banco) {
    $r = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $todos = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $v = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); while ($s->nextRowset()) {} return $v; };
        $pesquisar = static function (string $termo, array $filtros, array $perms, int $admin, int $usuario, int $limite = 200) use ($pdo): array {
            $s = $pdo->prepare('CALL sp_busca_pesquisar(?, ?, ?, ?, ?, ?, ?, ?)');
            $s->execute([$termo, json_encode($filtros ?: new stdClass()), json_encode($perms), $admin, $usuario, 'relevancia', $limite, 0]);
            $sets = []; do { $sets[] = $s->fetchAll(PDO::FETCH_ASSOC); } while ($s->nextRowset()); $s->closeCursor();
            return ['total' => (int)($sets[0][0]['total'] ?? 0), 'itens' => $sets[1] ?? [], 'tipos' => $sets[2] ?? []];
        };

        // Índice x fonte
        $fonte = array_column($todos('SELECT tipo, COUNT(*) n FROM vw_busca_fonte_registros GROUP BY tipo'), 'n', 'tipo');
        $resumo = $todos('CALL sp_busca_resumo()');
        $r['indice'] = [];
        foreach ($fonte as $tipo => $n) {
            $x = array_values(array_filter($resumo, static fn($y) => $y['tipo'] === $tipo))[0] ?? ['registros' => 0, 'arquivos' => 0];
            $r['indice'][$tipo] = ['fonte' => (int)$n, 'registros' => (int)$x['registros'], 'arquivos' => (int)$x['arquivos']];
            if ((int)$x['registros'] !== (int)$n) $falha($r, "$tipo: índice com {$x['registros']} registros, fonte com $n (rode scripts\\busca-worker.php)");
        }
        $r['ultima_execucao_servico'] = $resumo[0]['ultima_execucao'] ?? 'nunca';

        // Pesquisa como ADMINISTRADOR por uma palavra de um título real
        $amostra = $todos("SELECT tipo, chave, titulo FROM busca_indice WHERE item = 'REGISTRO' AND titulo REGEXP '[[:alpha:]]{6,}' ORDER BY codbusca DESC LIMIT 1")[0] ?? null;
        if ($amostra) {
            preg_match('/\p{L}{6,}/u', (string)$amostra['titulo'], $m);
            $palavra = $m[0] ?? '';
            $res = $pesquisar('+"' . $palavra . '"', [], [], 1, 0);
            $achou = (bool)array_filter($res['itens'], static fn($i) => $i['tipo'] === $amostra['tipo'] && (string)$i['chave'] === (string)$amostra['chave']);
            $r['pesquisa_admin'] = ['palavra' => $palavra, 'resultados' => $res['total'], 'achou_o_registro' => $achou, 'por_tipo' => array_column($res['tipos'], 'qtd', 'valor')];
            if (!$achou && $res['total'] < 200) $falha($r, "pesquisa por \"$palavra\" não achou o registro {$amostra['tipo']} {$amostra['chave']}");
        } else $r['pesquisa_admin'] = 'índice vazio';

        // Sem permissão nenhuma: nada
        $nada = $pesquisar('', [], [], 0, 0);
        $r['sem_permissao'] = $nada['total'];
        if ($nada['total'] !== 0) $falha($r, 'usuário sem transações achou itens');

        // Portaria: só as unidades do usuário
        $u = $todos('SELECT CODUSUARIO u, GROUP_CONCAT(CODUNIDADE) uns FROM vw_portaria_usuario_unidades GROUP BY CODUSUARIO ORDER BY COUNT(*) ASC LIMIT 1')[0] ?? null;
        if ($u) {
            $uns = array_map('intval', explode(',', (string)$u['uns']));
            $res = $pesquisar('', ['tipos' => ['fornecedorcriterios', 'funcionariocriterios', 'funcionariotreinamentos']], [['m' => 'PORTARIA_DOCUMENTACAO_FORNECEDOR', 't' => 1], ['m' => 'PORTARIA_DOCUMENTACAO_FUNCIONARIO', 't' => 1]], 0, (int)$u['u'], 200);
            $fora = 0; foreach ($res['itens'] as $i) { $cu = $todos('SELECT codunidade FROM busca_indice WHERE codbusca = ?', [$i['codbusca']])[0]['codunidade'] ?? null; if (!in_array((int)$cu, $uns, true)) $fora++; }
            $r['portaria_unidade'] = ['usuario' => (int)$u['u'], 'unidades' => $uns, 'resultados' => $res['total'], 'de_outras_unidades_na_amostra' => $fora];
            if ($fora) $falha($r, 'Portaria: pesquisa trouxe item de unidade que não é do usuário');
        }

        // Licenças sem "Visualizar total": só as do responsável
        $resp = $todos("SELECT codusuario, COUNT(*) n FROM busca_indice WHERE tipo = 'licencas' AND item = 'REGISTRO' AND codusuario > 0 GROUP BY codusuario ORDER BY n LIMIT 1")[0] ?? null;
        if ($resp) {
            $res = $pesquisar('', ['tipos' => ['licencas'], 'item' => 'REGISTRO'], [['m' => 'LICENCAS', 't' => 0]], 0, (int)$resp['codusuario'], 200);
            $r['licencas_responsavel'] = ['usuario' => (int)$resp['codusuario'], 'dele' => (int)$resp['n'], 'achou' => $res['total']];
            if ($res['total'] !== (int)$resp['n']) $falha($r, 'Licenças sem Visualizar total: esperado ' . $resp['n'] . ', achou ' . $res['total']);
        }

        // Arquivo de exemplo existe no disco
        $arq = $todos("SELECT tipo, chave, arquivo, pasta FROM busca_indice WHERE item = 'ARQUIVO' ORDER BY codbusca DESC LIMIT 1")[0] ?? null;
        if ($arq) {
            $storage = new GedStorageService($pdo, $env, ['tenant' => ['banco_dados' => $banco]]);
            $caminho = $storage->resolve(BuscaIndexadorService::PASTAS[$arq['tipo']], (int)$arq['pasta'], (string)$arq['arquivo']);
            $r['arquivo_exemplo'] = ['tipo' => $arq['tipo'], 'chave' => $arq['chave'], 'arquivo' => $arq['arquivo'], 'existe' => $caminho !== null];
            if ($caminho === null) $falha($r, 'arquivo indexado não encontrado na pasta do GED');
        } else $r['arquivo_exemplo'] = 'nenhum arquivo indexado';

        // Pesquisa agrupada pelo negócio
        $negocios = static function (string $termo, array $filtros, array $perms, int $admin, int $usuario) use ($pdo): array {
            $s = $pdo->prepare('CALL sp_busca_pesquisar_negocios(?, ?, ?, ?, ?, ?, ?, ?)');
            $s->execute([$termo, json_encode($filtros ?: new stdClass()), json_encode($perms), $admin, $usuario, 'relevancia', 100, 0]);
            $sets = []; do { $sets[] = $s->fetchAll(PDO::FETCH_ASSOC); } while ($s->nextRowset()); $s->closeCursor();
            return ['total' => (int)($sets[0][0]['total'] ?? 0), 'grupos' => $sets[1] ?? [], 'itens' => $sets[2] ?? [], 'vinculos' => $sets[3] ?? []];
        };
        $r['grupos_por_tipo'] = array_column($todos('SELECT grupo_tipo, COUNT(DISTINCT grupo_chave) n FROM busca_indice GROUP BY grupo_tipo'), 'n', 'grupo_tipo');
        if ($todos("SELECT 1 FROM busca_indice WHERE grupo_tipo IS NULL LIMIT 1")) $falha($r, 'há itens sem grupo no índice (rode scripts\\busca-worker.php)');
        if ($amostra) {
            $res = $negocios('+"' . $palavra . '"', ['tipos' => [$amostra['tipo']]], [], 1, 0);
            $grupo = $todos("SELECT grupo_tipo, grupo_chave FROM busca_indice WHERE tipo = ? AND item = 'REGISTRO' AND chave = ?", [$amostra['tipo'], $amostra['chave']])[0] ?? null;
            $achou = $grupo && (bool)array_filter($res['grupos'], static fn($g) => $g['grupo_tipo'] === $grupo['grupo_tipo'] && (string)$g['grupo_chave'] === (string)$grupo['grupo_chave']);
            $r['pesquisa_negocios'] = ['palavra' => $palavra, 'grupos' => $res['total'], 'achou_o_grupo' => $achou];
            if (!$achou && $res['total'] < 100) $falha($r, "pesquisa agrupada por \"$palavra\" não trouxe o grupo do registro {$amostra['tipo']} {$amostra['chave']}");
        }
        $nada = $negocios('', [], [], 0, 0);
        if ($nada['total'] !== 0) $falha($r, 'pesquisa agrupada: usuário sem transações achou grupos');
        // Vínculos entre negócios (catálogo busca_relacao): cada relação nos dois sentidos, com "Ver na pesquisa" (filtro grupo)
        $r['relacoes'] = [];
        foreach ($todos('SELECT codigo, tipo_a, tipo_b, fonte, ativo FROM busca_relacao ORDER BY ordem, codigo') as $rel) {
            $r['relacoes'][$rel['codigo']] = ['lados' => $rel['tipo_a'] . ' ↔ ' . $rel['tipo_b'], 'ativo' => (int)$rel['ativo'],
                'vinculos' => (int)$todos('SELECT COUNT(*) n FROM busca_vinculo WHERE relacao = ?', [$rel['codigo']])[0]['n']];
        }
        $v = $todos('SELECT relacao, tipo_a, chave_a, tipo_b, chave_b FROM busca_vinculo ORDER BY RAND() LIMIT 1')[0] ?? null;
        if ($v) {
            $lado = static function (string $gt, string $gc, string $lado, string $nt, string $nc) use ($negocios, $v): array {
                $res = $negocios('', ['grupo' => ['t' => $gt, 'c' => $gc]], [], 1, 0);
                $sec = array_values(array_filter($res['vinculos'], static fn($x) => $x['relacao'] === $v['relacao'] && $x['lado'] === $lado));
                $achou = (bool)array_filter($sec, static fn($x) => $x['nb_tipo'] === $nt && (string)$x['nb_chave'] === $nc);
                return ['grupos' => $res['total'], 'achou' => $achou, 'total_secao' => (int)($sec[0]['total_grupo'] ?? 0)];
            };
            $a = $lado($v['tipo_a'], $v['chave_a'], 'A', $v['tipo_b'], $v['chave_b']);
            $b = $lado($v['tipo_b'], $v['chave_b'], 'B', $v['tipo_a'], $v['chave_a']);
            $r['vinculo_exemplo'] = ['relacao' => $v['relacao'], 'a' => $v['tipo_a'] . ' ' . $v['chave_a'], 'b' => $v['tipo_b'] . ' ' . $v['chave_b'],
                'b_no_negocio_a' => $a['achou'], 'a_no_negocio_b' => $b['achou']];
            // até 30 por seção: só é falha se a seção mostra todos
            if ($a['grupos'] !== 1 || $b['grupos'] !== 1) $falha($r, 'Ver na pesquisa (filtro grupo) não trouxe exatamente o negócio pedido');
            if (!$a['achou'] && $a['total_secao'] <= 30) $falha($r, "vínculo {$v['relacao']}: {$v['tipo_b']} {$v['chave_b']} não apareceu no negócio {$v['tipo_a']} {$v['chave_a']}");
            if (!$b['achou'] && $b['total_secao'] <= 30) $falha($r, "vínculo {$v['relacao']}: {$v['tipo_a']} {$v['chave_a']} não apareceu no negócio {$v['tipo_b']} {$v['chave_b']}");
        } else $r['vinculo_exemplo'] = 'nenhum vínculo neste banco';

        // Dicionário: bases e situações; um termo aprovado expande a pesquisa (aviso no 9º resultado da procedure)
        $r['dicionario'] = [];
        foreach ($todos('SELECT base, status, COUNT(*) n FROM vw_busca_dicionario GROUP BY base, status') as $x) $r['dicionario'][$x['base']][$x['status']] = (int)$x['n'];
        $ap = $todos("SELECT termo, significado FROM vw_busca_dicionario WHERE status = 'APROVADO' ORDER BY RAND() LIMIT 1")[0] ?? null;
        if ($ap) {
            $s = $pdo->prepare('CALL sp_busca_pesquisar_negocios(?, ?, ?, ?, ?, ?, ?, ?)');
            $s->execute(['+"' . str_replace('"', ' ', $ap['termo']) . '"', '{}', '[]', 1, 0, 'relevancia', 20, 0]);
            $sets = []; do { try { $sets[] = $s->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable) { $sets[] = []; } } while ($s->nextRowset()); $s->closeCursor();
            $usou = (bool)array_filter($sets[8] ?? [], static fn($d) => $d['termo'] === $ap['termo']);
            $r['dicionario_exemplo'] = ['termo' => $ap['termo'] . ' = ' . $ap['significado'], 'grupos' => (int)($sets[0][0]['total'] ?? 0), 'expandiu' => $usou];
            if (!$usou) $falha($r, "dicionário: a pesquisa por {$ap['termo']} não usou o termo aprovado");
        } else $r['dicionario_exemplo'] = 'nenhum termo aprovado ainda (revise as sugestões na tela Dicionário da pesquisa)';
    } catch (Throwable $e) { $falha($r, 'ERRO: ' . $e->getMessage()); }
    $saida['bancos'][$banco] = $r;
}
$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents(dirname(__DIR__) . '/storage/logs/teste_busca_documentos.json', $json);
echo $json . PHP_EOL;
exit($saida['ok'] ? 0 : 1);
