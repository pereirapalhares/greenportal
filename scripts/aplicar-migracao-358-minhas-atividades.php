<?php
declare(strict_types=1);

/*
 * Migração 358 — Minhas atividades (GER-5.1 "telas Minhas" e GER-5.2 "Minhas Ações" do 01_Mudancas_Gerais).
 * Decisões do usuário (12/11/2026): uma tela com abas; "meu" = responsável direto ou por grupo; salvar sim, incluir/excluir não;
 * "Minhas Ações" numa lista única com filtro por módulo.
 *
 * 1. Minhas atividades é uma aba da Home (principal/home.php; decisão do usuário de 12/11/2026, depois da 1ª aplicação), não um
 *    item do menu: o menu MINHAS_ATIVIDADES criado na 1ª aplicação sai do catálogo universal e das empresas, com as permissões.
 * 2. Em cada empresa:
 *    a. database/358_minhas_atividades.sql: vw_ml_requisito_responsaveis, vw_minhas_condicionantes_grid, vw_meus_fornecedores_resp,
 *       vw_meus_funcionarios_resp (bloco que falha por falta do módulo na empresa fica registrado e não impede os outros);
 *    b. vw_meus_fornecedores_grid / vw_meus_funcionarios_grid: as colunas da view da tela de Gestão de fornecedores / funcionários
 *       + CODUSUARIO_ACESSO (quem responde), montadas a partir da definição atual do banco;
 *    c. vw_minhas_acoes: planos de ação de Condicionantes, Fornecedores e NC (só os módulos que a empresa tem; na NC, só as ações
 *       de ocorrências que existem, com o Número/Ano da ocorrência);
 *    d. Requisitos legais: o RESPONSAVEL_IDS de vw_modulolegal_requisitos_grid passa a incluir as atribuições (usuário e grupo),
 *       além do responsável da situação. Guarda a definição anterior em storage/logs e desfaz se a consulta ficar muito mais lenta;
 *    e. (cada aba confere a permissão da tela do módulo; a tela não tem permissão própria).
 * Pode ser executada mais de uma vez. Depois: sair e entrar no portal (o menu fica na sessão).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-358-minhas-atividades.php [--simular] [--banco=gcrown_dbp] [--sem-requisitos]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';

const MENU358 = 'MINHAS_ATIVIDADES';
$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$semRequisitos = in_array('--sem-requisitos', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$rel = ['ok' => true, 'migracao' => '358', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal' => [], 'bancos' => []];
$logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);

// ---------------------------------------------------------------- 1) menu MINHAS_ATIVIDADES: sai (agora é aba da Home)
$cat = new MenuCatalogoService($env);
$menuUniversal = null;
try {
    $u = $cat->universal();
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (MigracaoCrud::selecionar($u, 'menus', ['codigo' => MENU358]) as $m) if (strcasecmp((string)($m['codigo'] ?? ''), MENU358) === 0) $menuUniversal = (int)$m['codmenu'];
    $rel['universal']['menu'] = $menuUniversal ? ($simular ? 'será excluído (codmenu ' . $menuUniversal . ')' : 'a excluir (codmenu ' . $menuUniversal . ')') : 'não existe (ok)';
} catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['erro'] = $e->getMessage(); }

// ---------------------------------------------------------------- 2) empresas
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes', 'usuarios') AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && ($soBanco === null || $b === $soBanco)));
$sql = (string)file_get_contents(dirname(__DIR__) . '/database/358_minhas_atividades.sql');

foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tem = static fn(string $t): bool => MigracaoCrud::existe($pdo, $t);
        if ($simular) {
            $info['modulos'] = ['legal' => $tem('legislacaoxsituacao'), 'licencas' => $tem('li_condicionantes'), 'fornecedores' => $tem('fo_fornecedores'),
                'nc' => $tem('vw_nc_planodeacao'), 'responsabilidades' => $tem('vw_responsabilidade_efetiva')];
            $info['modo'] = 'simulação: nada gravado'; $rel['bancos'][$banco] = $info; continue;
        }
        // a. blocos fixos
        foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
            $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
            if ($cmd === '' || !preg_match('/VIEW `(\w+)`/', $cmd, $m)) continue;
            try { $pdo->exec($cmd); $info['views'][$m[1]] = 'ok'; }
            catch (Throwable $e) { $info['views'][$m[1]] = 'não criada: ' . mb_substr($e->getMessage(), 0, 160); }
        }
        // b. views das telas de fornecedores e funcionários (colunas da view da tela + CODUSUARIO_ACESSO)
        foreach ([['vw_meus_fornecedores_grid', ['vw_homologacao_fornecedores_ativos_grid', 'vw_homologacao_fornecedores_grid'], 'vw_meus_fornecedores_resp', 'CODFORNECEDOR'],
                  ['vw_meus_funcionarios_grid', ['vw_homologacao_funcionarios_grid'], 'vw_meus_funcionarios_resp', 'CODFUNCIONARIO']] as [$nova, $bases, $resp, $chave]) {
            $base = null; foreach ($bases as $b) if ($tem($b)) { $base = $b; break; }
            if ($base === null || !$tem($resp)) { $info['views'][$nova] = 'ignorada (empresa sem Homologação de fornecedores)'; continue; }
            $s = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
            $s->execute([$base]); $cols = $s->fetchAll(PDO::FETCH_COLUMN); $s->closeCursor();
            $cols = array_values(array_filter($cols, static fn($c) => strcasecmp((string)$c, 'CODUSUARIO_ACESSO') !== 0));
            $lista = implode(', ', array_map(static fn($c) => 'g.`' . str_replace('`', '', (string)$c) . '`', $cols));
            try {
                $pdo->exec("CREATE OR REPLACE VIEW `$nova` AS SELECT $lista, r.`CODUSUARIO` AS `CODUSUARIO_ACESSO` FROM `$base` g JOIN `$resp` r ON r.`$chave` = g.`$chave`");
                $info['views'][$nova] = "ok (base $base)";
            } catch (Throwable $e) { $rel['ok'] = false; $info['views'][$nova] = 'ERRO: ' . mb_substr($e->getMessage(), 0, 200); }
        }
        // c. Minhas Ações
        $partes = [];
        $txt = static fn(string $expr): string => "CONVERT($expr USING utf8mb4) COLLATE utf8mb4_0900_ai_ci";
        if ($tem('li_planodeacao') && $tem('li_condicionantes') && $tem('li_licencas'))
            $partes['condicionantes'] = 'SELECT ' . $txt("'CONDICIONANTE'") . ' AS `MODULO`, p.`CODPLANOACAO`, p.`CODCONDICIONANTE` AS `CODORIGEM`, '
                . $txt("LEFT(CONCAT(COALESCE(l.`DSCLICENCA`, CONCAT('Licença ', c.`CODLICENCA`)), ' › ', COALESCE(c.`DSCCONDICIONANTE`, '')), 400)") . ' AS `ORIGEM`, '
                . $txt("COALESCE(p.`ACAO`, '')") . ' AS `ACAO`, p.`DATAINICIAL`, p.`DATAFINAL`, CAST(COALESCE(p.`STATUS`, 0) AS SIGNED) AS `STATUS`, p.`CODUSUARIO`'
                . ' FROM `li_planodeacao` p LEFT JOIN `li_condicionantes` c ON c.`CODCONDICIONANTE` = p.`CODCONDICIONANTE`'
                . ' LEFT JOIN `li_licencas` l ON l.`CODLICENCA` = c.`CODLICENCA` WHERE COALESCE(p.`CODUSUARIO`, 0) > 0';
        if ($tem('fo_planodeacao') && $tem('fo_fornecedores'))
            $partes['fornecedores'] = 'SELECT ' . $txt("'FORNECEDOR'") . ', p.`codplanoacao`, p.`codfornecedor`, '
                . $txt("COALESCE(NULLIF(TRIM(f.`nomefantasia`), ''), NULLIF(TRIM(f.`razaosocial`), ''), CONCAT('Fornecedor ', p.`codfornecedor`))") . ', '
                . $txt("COALESCE(p.`acao`, '')") . ', p.`datainicial`, p.`datafinal`, CAST(COALESCE(p.`status`, 0) AS SIGNED), p.`codusuario`'
                . ' FROM `fo_planodeacao` p LEFT JOIN `fo_fornecedores` f ON f.`codfornecedor` = p.`codfornecedor` WHERE COALESCE(p.`codusuario`, 0) > 0';
        if ($tem('vw_nc_planodeacao') && $tem('nc_indicacao_ocorrencia'))
            $partes['nc'] = 'SELECT ' . $txt("'NC'") . ', p.`CODPLANOACAO`, p.`CODOCORRENCIA`, '
                . $txt("LEFT(CONCAT('NC ', COALESCE(NULLIF(TRIM(o.`NRANO`), ''), p.`CODOCORRENCIA`, '?'), IF(COALESCE(p.`DSCAUSA`, '') = '', '', CONCAT(' › ', p.`DSCAUSA`))), 400)") . ', '
                . $txt("COALESCE(p.`ACAO`, '')") . ', p.`DATAINICIAL`, p.`DATAFINAL`, CAST(COALESCE(p.`STATUS`, 0) AS SIGNED), p.`CODUSUARIO`'
                . ' FROM `vw_nc_planodeacao` p JOIN `nc_indicacao_ocorrencia` o ON o.`CODOCORRENCIA` = p.`CODOCORRENCIA` WHERE COALESCE(p.`CODUSUARIO`, 0) > 0';
            // (12/11/2026) só ações de ocorrências que existem: no legado há ações cuja ocorrência foi excluída (abriam "Ocorrência não encontrada")
        if (!$partes) $info['views']['vw_minhas_acoes'] = 'ignorada (empresa sem planos de ação)';
        else {
            // A primeira parte dá os nomes das colunas; as demais seguem a mesma ordem.
            try { $pdo->exec('CREATE OR REPLACE VIEW `vw_minhas_acoes` AS ' . implode(' UNION ALL ', $partes)); $info['views']['vw_minhas_acoes'] = 'ok (' . implode(', ', array_keys($partes)) . ')'; }
            catch (Throwable $e) { $rel['ok'] = false; $info['views']['vw_minhas_acoes'] = 'ERRO: ' . mb_substr($e->getMessage(), 0, 200); }
        }
        // d. Requisitos legais: RESPONSAVEL_IDS com as atribuições
        if ($semRequisitos) $info['requisitos'] = 'não alterado (--sem-requisitos)';
        elseif (!$tem('vw_modulolegal_requisitos_grid') || !$tem('vw_ml_requisito_responsaveis')) $info['requisitos'] = 'ignorado (empresa sem Requisitos legais)';
        else $info['requisitos'] = requisitos358($pdo, $banco, $logDir);
        // e. menu MINHAS_ATIVIDADES (1ª aplicação): retira as permissões; o menu sai no fim, pelo catálogo
        $cont = 0;
        foreach (MigracaoCrud::selecionar($pdo, 'menus', ['codigo' => MENU358]) as $m) {
            if (strcasecmp((string)($m['codigo'] ?? ''), MENU358) !== 0) continue;
            foreach (MigracaoCrud::selecionar($pdo, 'permissoes', ['codmenu' => (int)$m['codmenu']]) as $p) {
                if ((int)$p['codmenu'] !== (int)$m['codmenu']) continue;
                $d = $pdo->prepare('CALL sp_crud_delete(?, ?)'); $d->execute(['permissoes', json_encode(['codpermissao' => (int)$p['codpermissao']])]); $d->closeCursor();
                $cont++;
            }
        }
        $info['menu_antigo'] = $cont ? $cont . ' permissão(ões) do menu Minhas atividades retirada(s)' : 'sem permissões do menu Minhas atividades';
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}

// ---------------------------------------------------------------- 3) exclui o menu MINHAS_ATIVIDADES do catálogo e das empresas
if ($menuUniversal && !$simular) {
    try { $x = $cat->excluir($menuUniversal); $rel['universal']['menu'] = 'excluído (' . count($x['empresas']) . ' empresa(s))'; }
    catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['menu'] = 'ERRO ao excluir: ' . $e->getMessage(); }
}

/** Troca o RESPONSAVEL_IDS de vw_modulolegal_requisitos_grid pelo de vw_ml_requisito_responsaveis (legado + atribuições). */
function requisitos358(PDO $pdo, string $banco, string $logDir): string
{
    $def = (string)($pdo->query('SHOW CREATE VIEW `vw_modulolegal_requisitos_grid`')->fetch(PDO::FETCH_ASSOC)['Create View'] ?? '');
    if (str_contains($def, 'vw_ml_requisito_responsaveis')) return 'já aplicado';
    $pos = stripos($def, ' AS select ');
    if ($pos === false) return 'NÃO alterado: definição da view em formato inesperado';
    $corpo = substr($def, $pos + 4);
    $antigo = "(case when (`s`.`responsavel_ids` is null) then '' else concat(',',`s`.`responsavel_ids`,',') end) AS `RESPONSAVEL_IDS`";
    $fim = "and (`s`.`codunidade` = `x`.`codunidade`))))";
    if (substr_count($corpo, $antigo) !== 1 || !str_ends_with($corpo, $fim)) return 'NÃO alterado: a view mudou desde a análise (diagnóstico 358); o filtro continua só com o responsável da situação';
    $novo = str_replace($antigo, "(case when (`rs`.`ids` is null) then '' else concat(',',`rs`.`ids`,',') end) AS `RESPONSAVEL_IDS`", $corpo)
        . " left join (select `CODLEGISLACAO`, `CODUNIDADE`, group_concat(distinct `CODUSUARIO` order by `CODUSUARIO` ASC separator ',') AS `ids`"
        . " from `vw_ml_requisito_responsaveis` group by `CODLEGISLACAO`, `CODUNIDADE`) `rs`"
        . " on(((`rs`.`CODLEGISLACAO` = `x`.`CODLEGISLACAO`) and (`rs`.`CODUNIDADE` = `x`.`codunidade`)))";
    @file_put_contents($logDir . DIRECTORY_SEPARATOR . "migracao_358_vw_modulolegal_requisitos_grid_anterior_$banco.sql", 'CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_grid` AS ' . $corpo . ";\n");
    $medir = static function () use ($pdo): float { $t = microtime(true); $pdo->query('SELECT COUNT(*) FROM `vw_modulolegal_requisitos_grid`')->fetchColumn(); return microtime(true) - $t; };
    $antes = $medir();
    $pdo->exec('CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_grid` AS ' . $novo);
    $depois = $medir();
    if ($depois > 5 && $depois > 3 * $antes) {
        $pdo->exec('CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_grid` AS ' . $corpo);
        return sprintf('DESFEITO: a consulta passou de %.1fs para %.1fs; o filtro continua só com o responsável da situação', $antes, $depois);
    }
    return sprintf('ok: o Responsável inclui as atribuições (consulta %.1fs → %.1fs); definição anterior em storage/logs', $antes, $depois);
}

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents($logDir . DIRECTORY_SEPARATOR . 'migracao_358_minhas_atividades' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
