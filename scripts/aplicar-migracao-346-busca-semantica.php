<?php
declare(strict_types=1);

/*
 * Migração 346 — Busca semântica (pesquisa pelo sentido) e reavaliação quando uma lei muda (LEG-006 da apresentação Atlas).
 *
 * Universal: menu Módulo Legal › Reavaliação por mudança de lei (MODULOLEGAL_REAVALIACAO_LEIS, ao lado de Requisitos legais,
 *            replicado nas empresas), preço do modelo de vetores no taxímetro (Administração › Consumo de IA) e a Ajuda.
 * Cada empresa com a Pesquisa de documentos (busca_indice, migração 264):
 *   1. database/346_busca_semantica.sql — vetores (busca_embedding), reavaliações (lei_reavaliacao*), procedures e views;
 *   2. views montadas conforme o banco: vw_busca_semantica_textos (texto dos itens + texto lido dos arquivos pelo OCR),
 *      vw_lei_alteracoes (alterações da base de legislação, .env GLEGISLACAO_DB_DATABASE) e vw_lei_reav_responsaveis;
 *   3. sp_busca_pesquisar_negocios de novo (database/264_busca_documentos.sql, com o filtro "semantico");
 *   4. a marca da reavaliação: as alterações que já existem não geram tarefas;
 *   5. relatório: itens com texto e a estimativa do custo da primeira vetorização.
 * A vetorização é feita pelo serviço da Pesquisa de documentos (GreenPortal-BuscaWorker, a cada 10 minutos), até
 * BUSCA_SEMANTICA_LOTE itens por execução (padrão 1000), e passa pelo taxímetro e pelo limite mensal de IA.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-346-busca-semantica.php [--simular] [--banco=gatlas_dbp]
 * Pode ser executada mais de uma vez (a marca não muda).
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';
require_once dirname(__DIR__) . '/classes/BuscaSemantica.php';
require_once dirname(__DIR__) . '/classes/LeiReavaliacao.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$bancoLeg = preg_replace('/[^A-Za-z0-9_]/', '', (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp')) ?: 'glegislacao_dbp';
$modelo = trim((string)($env['GPT_AI_EMBEDDING_MODEL'] ?? '')) ?: 'text-embedding-3-small';
$rel = ['ok' => true, 'migracao' => '346', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'configuracao' => [], 'universal' => [], 'bancos' => []];
$rel['configuracao'] = ['modelo' => $modelo . ' (' . ((int)($env['GPT_AI_EMBEDDING_DIMENSOES'] ?? 256) ?: 256) . ' dimensões)',
    'chave_ia' => (new BuscaSemantica(GreenPortalDatabase::connect($env, 'information_schema', 'GPT'), $env))->configurada() ? 'configurada' : 'NÃO configurada: a busca semântica e a comparação pelo sentido ficam desligadas',
    'base_legislacao' => $bancoLeg];

// ---------------------------------------------------------------- universal: menu, preço e ajuda
$cat = new MenuCatalogoService($env);
try {
    $u = $cat->universal();
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $req = MigracaoCrud::primeiro($u, 'menus', ['codigo' => 'REQUISITOS_LEGAIS']);
    $existe = MigracaoCrud::primeiro($u, 'menus', ['codigo' => LeiReavaliacao::MENU]);
    if ($existe) { if (!$simular) $cat->replicar(LeiReavaliacao::MENU, null, false); $rel['universal']['menu'] = 'já existia (codmenu ' . $existe['codmenu'] . ')'; }
    elseif (!$req) { $rel['universal']['menu'] = 'ERRO: menu REQUISITOS_LEGAIS não encontrado no universal'; $rel['ok'] = false; }
    elseif ($simular) $rel['universal']['menu'] = 'será criado ao lado de Requisitos legais: Reavaliação por mudança de lei';
    else {
        $req = array_change_key_case($req);
        $g = $cat->gravar(['codmenu_pai' => $req['codmenu_pai'] !== null ? (int)$req['codmenu_pai'] : null, 'modulo' => $req['modulo'] ?? null, 'codigo' => LeiReavaliacao::MENU,
            'label' => 'Reavaliação por mudança de lei', 'label_pt' => 'Reavaliação por mudança de lei', 'label_en' => 'Review on law changes', 'label_es' => 'Revisión por cambio de ley',
            'url' => LeiReavaliacao::TELA, 'nivel' => (int)($req['nivel'] ?? 1), 'ordem' => (int)($req['ordem'] ?? 0) + 2, 'icone' => 'bi-arrow-repeat', 'ativo' => 1]);
        $rel['universal']['menu'] = 'criado (codmenu ' . $g['codmenu'] . ')';
    }
    // Preço do modelo de vetores (US$ por 1 milhão de tokens; sem saída) — só se ainda não houver
    if (MigracaoCrud::existe($u, 'ia_precos')) {
        $tem = false;
        foreach (MigracaoCrud::selecionar($u, 'ia_precos') as $p) if (strcasecmp((string)($p['MODELO'] ?? $p['modelo'] ?? ''), $modelo) === 0) $tem = true;
        if ($tem) $rel['universal']['preco'] = 'já cadastrado (' . $modelo . ')';
        elseif ($simular) $rel['universal']['preco'] = 'será cadastrado: ' . $modelo;
        else {
            $usd = ['text-embedding-3-small' => 0.02, 'text-embedding-3-large' => 0.13, 'text-embedding-ada-002' => 0.10][$modelo] ?? 0.02;
            $s = $u->prepare('CALL sp_ia_preco_salvar(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $s->execute([0, $modelo, 'Vetores da busca semântica', $usd, $usd, 0, '2024-01-01', 1, 'https://openai.com/api/pricing', 'MIGRACAO_346']); while ($s->nextRowset()) {} $s->closeCursor();
            $rel['universal']['preco'] = 'cadastrado: ' . $modelo . ' a US$ ' . $usd . ' por 1 milhão de tokens';
        }
    } else $rel['universal']['preco'] = 'sem ia_precos (migração 340): o custo não é calculado';
    $rel['universal']['ajuda'] = gravarAjuda346($u, require dirname(__DIR__) . '/database/346_ajuda_busca_semantica.php', $simular);
} catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['erro'] = $e->getMessage(); }

// ---------------------------------------------------------------- base de legislação: colunas
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$colsLeg = static function (string $t) use ($geral, $bancoLeg): array {
    $s = $geral->prepare('SELECT LOWER(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $s->execute([$bancoLeg, $t]); return array_flip($s->fetchAll(PDO::FETCH_COLUMN));
};
$cAlt = $colsLeg('legislacaoxalteracao'); $cLei = $colsLeg('legislacao'); $cTipo = $colsLeg('tipos');
$viewAlteracoes = null;
if (isset($cAlt['codalteracao'], $cAlt['codlegislacao'], $cLei['codlegislacao'])) {
    $c = static fn(array $cols, string $alias, array $nomes, string $padrao = "''"): string => (function () use ($cols, $alias, $nomes, $padrao) { foreach ($nomes as $n) if (isset($cols[strtolower($n)])) return "$alias.`$n`"; return $padrao; })();
    $tipoLei = isset($cLei['codtipo'], $cTipo['codtipo'], $cTipo['dsctipo']) ? 't.`DSCTIPO`' : "''";
    $joinTipo = isset($cLei['codtipo'], $cTipo['codtipo'], $cTipo['dsctipo']) ? "LEFT JOIN `$bancoLeg`.`tipos` t ON t.`CODTIPO` = l.`CODTIPO`" : '';
    $nr = $c($cLei, 'l', ['NR', 'NUMERO']); $ano = $c($cLei, 'l', ['ANO']);
    $ementa = $c($cLei, 'l', ['EMENTA', 'DSCLEGISLACAO', 'DESCRICAO', 'RESUMO', 'CODIGO', 'PADRAO']);
    $txt = static fn(string $e, int $n): string => "CONVERT(LEFT(CONVERT($e USING utf8mb4), $n) USING utf8mb4) COLLATE utf8mb4_unicode_ci";
    $viewAlteracoes = "CREATE OR REPLACE VIEW `vw_lei_alteracoes` AS
SELECT CAST(a.`codalteracao` AS SIGNED) AS `CODALTERACAO`, CAST(a.`codlegislacao` AS SIGNED) AS `CODLEGISLACAO`,
       " . $txt("TRIM(CONCAT_WS(' ', NULLIF(TRIM($tipoLei), ''), CONCAT_WS('/', NULLIF(TRIM($nr), ''), NULLIF(TRIM($ano), ''))))", 500) . " AS `LEI`,
       " . $txt($ementa, 2000) . " AS `EMENTA`,
       " . $txt($c($cAlt, 'a', ['tipo']), 200) . " AS `TIPO`, " . $txt($c($cAlt, 'a', ['descricao']), 4000) . " AS `DESCRICAO`,
       " . (isset($cAlt['data_da_alteracao']) ? "(CASE WHEN YEAR(a.`data_da_alteracao`) > 0 THEN DATE(a.`data_da_alteracao`) END)" : 'NULL') . " AS `DATA_ALTERACAO`,
       IF(EXISTS (SELECT 1 FROM `legislacaoxempresa` e WHERE e.`CODLEGISLACAO` = a.`codlegislacao`), 1, 0) AS `EH_REQUISITO`
  FROM `$bancoLeg`.`legislacaoxalteracao` a
  JOIN `$bancoLeg`.`legislacao` l ON l.`CODLEGISLACAO` = a.`codlegislacao`
  $joinTipo";
} else $rel['configuracao']['alteracoes'] = "sem $bancoLeg.legislacaoxalteracao / legislacao: a reavaliação por mudança de lei fica sem alterações";

// ---------------------------------------------------------------- empresas
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('busca_indice', 'green_tasks', 'alertas') AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 3 ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && $b !== $bancoLeg && !str_ends_with((string)$b, '_seguranca_dbp') && ($soBanco === null || $b === $soBanco)));
if (!$bancos) $rel['bancos'] = ['aviso' => 'nenhuma empresa com a Pesquisa de documentos (migração 264)'];
$sql = (string)file_get_contents(dirname(__DIR__) . '/database/346_busca_semantica.sql');
$sql264 = (string)file_get_contents(dirname(__DIR__) . '/database/264_busca_documentos.sql');
$blocos264 = array_values(array_filter(preg_split('/^-- @@\s*$/m', $sql264), static fn($b) => str_contains($b, 'PROCEDURE IF EXISTS `sp_busca_pesquisar_negocios`') || str_contains($b, 'CREATE PROCEDURE `sp_busca_pesquisar_negocios`')));
foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $cols = static function (string $t) use ($pdo): array { $s = $pdo->prepare('SELECT LOWER(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'); $s->execute([$t]); $r = array_flip($s->fetchAll(PDO::FETCH_COLUMN)); $s->closeCursor(); return $r; };
        // Texto de cada item: registros (nome, título, descrição e texto do cadastro) e arquivos com texto lido pelo OCR
        $cOcr = $cols('green_ocr_jobs');
        $temOcr = isset($cOcr['entidade'], $cOcr['registro_id'], $cOcr['arquivo'], $cOcr['texto'], $cOcr['status']);
        $tabelaGed = "CASE i.`ged_tipo` WHEN 'licencas' THEN 'li_licencas' WHEN 'controles' THEN 'documentos' WHEN 'treinamentos' THEN 'ml_treinamentos' WHEN 'infraestrutura' THEN 'ml_infraestrutura'
            WHEN 'fornecedorcriterios' THEN 'fo_fornecedorxcriterios' WHEN 'funcionariocriterios' THEN 'fo_funcionarioxtiposxcriterios' WHEN 'funcionariotreinamentos' THEN 'fo_funcionarioxtiposxtreinamentos' ELSE i.`ged_tipo` END";
        $u8 = static fn(string $e): string => "CONVERT($e USING utf8mb4) COLLATE utf8mb4_unicode_ci";
        $rotulo = "CASE i.`tipo` WHEN 'licencas' THEN 'Licença' WHEN 'controles' THEN 'Controle de documento' WHEN 'requisitos' THEN 'Requisito legal' WHEN 'treinamentos' THEN 'Treinamento'
            WHEN 'infraestrutura' THEN 'Infraestrutura' WHEN 'fornecedorcriterios' THEN 'Documento de fornecedor' WHEN 'funcionariocriterios' THEN 'Documento de funcionário'
            WHEN 'funcionariotreinamentos' THEN 'Treinamento de funcionário' ELSE i.`tipo` END";
        $textoReg = "CONCAT_WS(' | ', $rotulo, " . $u8('i.`grupo_nome`') . ', ' . $u8('i.`nome`') . ', ' . $u8('i.`titulo`') . ', ' . $u8('i.`descricao`') . ', ' . $u8('LEFT(i.`texto`, 3000)') . ')';
        $viewTextos = $temOcr
            ? "CREATE OR REPLACE VIEW `vw_busca_semantica_textos` AS
SELECT x.`codbusca`, x.`texto`, SHA1(x.`texto`) AS `hash` FROM (
  SELECT i.`codbusca`, CASE WHEN i.`item` = 'REGISTRO' THEN $textoReg
         ELSE CONCAT_WS(' | ', 'Arquivo', " . $u8('i.`grupo_nome`') . ', ' . $u8('i.`nome`') . ', ' . $u8('i.`titulo`') . ', ' . $u8('LEFT(o.`texto`, 5000)') . ") END AS `texto`
    FROM `busca_indice` i
    LEFT JOIN (SELECT CONVERT(`entidade` USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `entidade`, CONVERT(`registro_id` USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `registro_id`,
                      CONVERT(`arquivo` USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `arquivo`, MAX(`codjob`) AS `codjob`
                 FROM `green_ocr_jobs` WHERE `status` = 'CONCLUIDA' AND `texto` IS NOT NULL AND CHAR_LENGTH(`texto`) > 40 GROUP BY 1, 2, 3) uo
           ON i.`item` = 'ARQUIVO' AND uo.`arquivo` = " . $u8('i.`arquivo`') . " AND uo.`entidade` = " . $u8($tabelaGed) . "
          AND uo.`registro_id` IN (" . $u8('CAST(i.`ged_valor` AS CHAR)') . ', ' . $u8('CAST(i.`pasta` AS CHAR)') . ")
    LEFT JOIN `green_ocr_jobs` o ON o.`codjob` = uo.`codjob`
   WHERE i.`item` = 'REGISTRO' OR o.`codjob` IS NOT NULL) x"
            : "CREATE OR REPLACE VIEW `vw_busca_semantica_textos` AS
SELECT i.`codbusca`, $textoReg AS `texto`, SHA1($textoReg) AS `hash` FROM `busca_indice` i WHERE i.`item` = 'REGISTRO'";
        $cSit = $cols('legislacaoxsituacao');
        $viewResp = isset($cSit['codlegislacao'], $cSit['codusuario'])
            ? "CREATE OR REPLACE VIEW `vw_lei_reav_responsaveis` AS
SELECT DISTINCT s.`CODLEGISLACAO`, " . (isset($cSit['codunidade']) ? 's.`codunidade`' : 'NULL') . " AS `CODUNIDADE`, s.`CODUSUARIO`
  FROM `legislacaoxsituacao` s JOIN `usuarios` u ON u.`codusuario` = s.`CODUSUARIO` AND COALESCE(u.`ativo`, 0) = 1
 WHERE COALESCE(s.`CODUSUARIO`, 0) > 0"
            : "CREATE OR REPLACE VIEW `vw_lei_reav_responsaveis` AS SELECT CAST(NULL AS SIGNED) AS `CODLEGISLACAO`, CAST(NULL AS SIGNED) AS `CODUNIDADE`, CAST(NULL AS SIGNED) AS `CODUSUARIO` FROM DUAL WHERE 1 = 0";
        $viewAlt = MigracaoCrud::existe($pdo, 'legislacaoxempresa') && $viewAlteracoes ? $viewAlteracoes
            : "CREATE OR REPLACE VIEW `vw_lei_alteracoes` AS SELECT CAST(NULL AS SIGNED) AS `CODALTERACAO`, CAST(NULL AS SIGNED) AS `CODLEGISLACAO`, '' AS `LEI`, '' AS `EMENTA`, '' AS `TIPO`, '' AS `DESCRICAO`, CAST(NULL AS DATE) AS `DATA_ALTERACAO`, 0 AS `EH_REQUISITO` FROM DUAL WHERE 1 = 0";
        if ($simular) {
            // Estimativa do custo da primeira vetorização (caracteres / 4 = tokens)
            $consulta = str_replace('CREATE OR REPLACE VIEW `vw_busca_semantica_textos` AS', '', $viewTextos);
            $s = $pdo->query('SELECT COUNT(*) AS N, COALESCE(SUM(CHAR_LENGTH(LEFT(z.texto, 6000))), 0) AS C FROM (' . $consulta . ') z'); $e = $s->fetch(PDO::FETCH_ASSOC); $s->closeCursor();
            $tokens = (int)ceil((int)$e['C'] / 4);
            $info['itens_com_texto'] = (int)$e['N'] . ' item(ns) a vetorizar' . ($temOcr ? ' (cadastros + arquivos com texto lido pelo OCR)' : ' (só cadastros: sem a fila de OCR)');
            $usd = $tokens * 0.02 / 1000000;
            $info['estimativa'] = number_format($tokens, 0, ',', '.') . ' tokens ≈ ' . ($usd < 0.01 ? 'menos de US$ 0,01' : 'US$ ' . number_format($usd, 2, ',', '.')) . ' (uma vez; depois só o que mudar)';
            $info['modo'] = 'simulação: nada gravado'; $rel['bancos'][$banco] = $info; continue;
        }
        // 1. Parte fixa
        $n = 0;
        foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) { $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco)); if ($cmd === '') continue; $pdo->exec($cmd); $n++; }
        $info['blocos'] = $n;
        // 2. Views do banco
        $pdo->exec($viewTextos); $pdo->exec($viewResp); $pdo->exec($viewAlt);
        // 3. Pesquisa com o filtro "semantico"
        foreach ($blocos264 as $b) { $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $b)); if ($cmd !== '') $pdo->exec($cmd); }
        $info['pesquisa'] = 'sp_busca_pesquisar_negocios regravada (filtro semantico)';
        // 4. Marca da reavaliação
        $marca = MigracaoCrud::primeiro($pdo, 'lei_reav_controle', ['CHAVE' => 'ULTIMA_ALTERACAO']);
        if (!$marca) {
            $s = $pdo->query('SELECT COALESCE(MAX(CODALTERACAO), 0) FROM vw_lei_alteracoes'); $ult = (int)$s->fetchColumn(); $s->closeCursor();
            $s = $pdo->prepare('CALL sp_lei_reav_marca(?)'); $s->execute([(string)$ult]); while ($s->nextRowset()) {} $s->closeCursor();
            $info['reavaliacao'] = 'marca gravada: alterações a partir do nº ' . ($ult + 1) . ' geram reavaliação';
        } else $info['reavaliacao'] = 'marca mantida: a partir do nº ' . ((int)$marca['VALOR'] + 1);
        $s = $pdo->query('CALL sp_busca_semantica_resumo()'); $rs = $s->fetch(PDO::FETCH_ASSOC); while ($s->nextRowset()) {} $s->closeCursor();
        $info['vetores'] = (int)$rs['VETORES'] . ' de ' . (int)$rs['ITENS_COM_TEXTO'] . ' item(ns) com texto (o serviço da Pesquisa de documentos vetoriza o restante)';
        // 5. Permissão do menu novo = a de Requisitos legais
        $menuNovo = MigracaoCrud::primeiro($pdo, 'menus', ['codigo' => LeiReavaliacao::MENU]);
        $menuReq = MigracaoCrud::primeiro($pdo, 'menus', ['codigo' => 'REQUISITOS_LEGAIS']);
        if (!$menuNovo || !$menuReq) $info['permissao'] = 'menu ainda não replicado neste banco';
        else {
            $cont = 0;
            foreach (['permissoes', 'perfil_menus'] as $t) {
                if (!MigracaoCrud::existe($pdo, $t)) continue;
                $ct = MigracaoCrud::colunas($pdo, $t);
                if (!in_array('codmenu', $ct, true) || !in_array('codperfil', $ct, true)) continue;
                foreach (MigracaoCrud::selecionar($pdo, $t, ['codmenu' => (int)$menuReq['codmenu']]) as $p) {
                    $p = array_change_key_case($p);
                    $flags = array_filter($p, static fn($k) => str_starts_with((string)$k, 'pode_'), ARRAY_FILTER_USE_KEY);
                    if (!array_filter($flags)) continue;
                    if (MigracaoCrud::primeiro($pdo, $t, ['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$menuNovo['codmenu']])) continue;
                    MigracaoCrud::inserir($pdo, $t, ['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$menuNovo['codmenu']] + $flags);
                    $cont++;
                }
            }
            $info['permissao'] = $cont . ' permissão(ões) criada(s) (as mesmas de Requisitos legais)';
        }
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);

/** Ajuda: trecho entre <!-- ml346 --> e <!-- /ml346 --> no fim do texto atual (só esse trecho é trocado ao rodar de novo). */
function gravarAjuda346(PDO $u, array $textos, bool $simular): array
{
    if (!MigracaoCrud::existe($u, 'ajuda_transacoes')) return ['aviso' => 'sem ajuda_transacoes no universal'];
    $out = [];
    foreach ($textos as $transacao => $d) {
        $atual = MigracaoCrud::primeiro($u, 'ajuda_transacoes', ['transacao' => $transacao]);
        $pt = (string)($atual['texto_pt'] ?? '');
        $assinatura = trim(html_entity_decode(strip_tags((string)preg_replace('/^.*?<strong>(.*?)<\/strong>.*$/s', '$1', $d['texto']['pt'])), ENT_QUOTES, 'UTF-8'));
        if ($atual !== null && !str_contains($pt, '<!-- ml346 -->') && $assinatura !== '' && str_contains(html_entity_decode(strip_tags($pt), ENT_QUOTES, 'UTF-8'), $assinatura)) { $out[$transacao] = 'já tem o texto (editado na tela de ajuda): não alterada'; continue; }
        $dados = [];
        foreach (['pt', 'en', 'es'] as $lg) {
            $velho = trim((string)preg_replace('/\s*<!-- ml346 -->.*?<!-- \/ml346 -->/s', '', (string)($atual['texto_' . $lg] ?? '')));
            $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . "<!-- ml346 -->\n" . $d['texto'][$lg] . "\n<!-- /ml346 -->";
            if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
        }
        if ($atual !== null) { $igual = true; foreach ($dados as $k => $v) if ((string)($atual[$k] ?? '') !== $v) $igual = false; if ($igual) { $out[$transacao] = 'igual'; continue; } }
        if ($simular) { $out[$transacao] = $atual === null ? 'será incluída' : 'será atualizada'; continue; }
        $r = MigracaoCrud::salvar($u, 'ajuda_transacoes', ['transacao' => $transacao], $dados + ['ativo' => 1]);
        $out[$transacao] = $r === 'alterado' ? 'atualizada' : 'incluída';
    }
    return $out;
}
