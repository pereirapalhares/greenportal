<?php
declare(strict_types=1);

/*
 * Migração 289 — Ranking de fornecedores (Homologação de fornecedores).
 * Em cada empresa com fo_fornecedores:
 *   1. database/289_ranking_fornecedores.sql: configuração (pesos, faixas, penalidades), nota gravada e histórico diário,
 *      bloqueios com motivo e as procedures (cálculo, configuração, bloquear/desbloquear, pedido de recálculo);
 *   2. view vw_fornecedor_ranking_calculo (as regras de "documento em dia" são as mesmas da Portaria — views das migrações
 *      260, 262 e 263); views de leitura das telas (ranking, configuração, históricos, detalhe);
 *   3. menus ADMINISTRACAO_PESOS (Administração › Pesos do ranking de fornecedores) e HOMOLOGACAO_FORNECEDOR_RANKING
 *      (Homologação › Ranking de fornecedores), com o mesmo codmenu no universal e nas empresas. Acesso completo para
 *      ADMINISTRADOR, ADM DA EMPRESA e GESTOR; Ranking também para quem já vê o cadastro de Fornecedores;
 *   4. primeiro cálculo (depois o serviço scripts/fornecedor-ranking-worker.php, no Agendador do Windows, recalcula
 *      uma vez por dia e quando os pesos mudam).
 * Nota (0–100) = média ponderada dos componentes que o fornecedor tem:
 *   documentação do fornecedor   % dos critérios exigidos em dia (aprovado ou vence em até 30 dias; "não se aplica" fora)
 *   documentação dos funcionários % dos critérios e treinamentos dos funcionários em dia (desligados/afastados fora)
 *   não conformidades            100 − penalidades (abertas, abertas atrasadas, fechadas na janela de meses)
 *   auditorias                   nota da auditoria de homologação (ou aprovada = 100 / reprovada = 0; vencida vale metade)
 * Componente sem dados (ex.: sem funcionários, sem auditoria) sai da conta e o peso dele é dividido entre os outros.
 * Fornecedor sem documentos exigidos, sem funcionários e sem auditoria fica "Sem dados" (sem nota e sem faixa).
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-289-ranking-fornecedores.php [--simular] [--banco=gatlas_dbp]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

const M289_MENU_PESOS = 'ADMINISTRACAO_PESOS';
const M289_MENU_RANKING = 'HOMOLOGACAO_FORNECEDOR_RANKING';
const M289_MENU_FORNECEDORES = 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR';
const M289_PERFIS = ['ADMINISTRADOR', 'ADM DA EMPRESA', 'ADM EMPRESA', 'GESTOR'];

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$relatorio = ['ok' => true, 'migracao' => '289', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes', 'usuarios') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && preg_match('/^g[a-z0-9_]+_dbp$/', (string)$b)));
$bancos = array_values(array_intersect(HomologacaoBancos::lista($argv ?? []), $bancos)); // só os bancos da homologação (--banco= filtra; pode repetir ou separar por vírgula)
$sql = (string)file_get_contents(dirname(__DIR__) . '/database/289_ranking_fornecedores.sql');
$perfilNorm = static fn(string $c): string => trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/[_-]+/', ' ', strtoupper(trim($c)))));

// ------------------------------------------------------------------ menus (mesmo codmenu no universal e nas empresas)
$u = GreenPortalDatabase::connect($env, $bancoUniversal, 'GUNIVERSAL');
$u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$clientes = [];
foreach ($bancos as $b) { $p = GreenPortalDatabase::connect($env, $b, 'GPT'); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $clientes[$b] = $p; }
// As leituras e gravações de menus são por sp_crud_*: garante as quatro procedures no universal e nas empresas
if (!$simular) { MigracaoCrud::garantir($u); foreach ($clientes as $p) MigracaoCrud::garantir($p); }
$proximoCodmenu = static function () use ($u, $clientes): int {
    $m = MigracaoCrud::maximo($u, 'menus', 'codmenu');
    foreach ($clientes as $p) $m = max($m, MigracaoCrud::maximo($p, 'menus', 'codmenu'));
    return $m + 1;
};
$irmao = static fn(string $campo, string $valor): ?array => MigracaoCrud::primeiro($u, 'menus', [$campo => $valor]);
$montarMenu = static function (string $codigo, ?array $referencia, array $label, string $url, string $icone, int $reservado = 0) use ($u, $proximoCodmenu): array {
    $cod = (int)(MigracaoCrud::primeiro($u, 'menus', ['codigo' => $codigo])['codmenu'] ?? 0);
    if ($cod < 1) $cod = max($reservado + 1, $proximoCodmenu());
    $pai = $referencia ? ((int)($referencia['codmenu_pai'] ?? 0) ?: null) : null;
    $ordem = 1;
    if ($pai) foreach (MigracaoCrud::selecionar($u, 'menus', ['codmenu_pai' => $pai]) as $m) if ((int)$m['codmenu'] !== $cod) $ordem = max($ordem, (int)$m['ordem'] + 1);
    return ['codmenu' => $cod, 'codmenu_pai' => $pai, 'modulo' => (string)($referencia['modulo'] ?? 'ADMINISTRACAO'), 'codigo' => $codigo, 'label' => $label['pt'],
        'label_pt' => $label['pt'], 'label_en' => $label['en'], 'label_es' => $label['es'], 'url' => $url, 'nivel' => $pai ? 2 : 1, 'ordem' => $ordem, 'icone' => $icone, 'ativo' => 1];
};
$menuPesos = $montarMenu(M289_MENU_PESOS, $irmao('url', '/principal/administracao_idiomas.php'),
    ['pt' => 'Pesos do ranking de fornecedores', 'en' => 'Supplier ranking weights', 'es' => 'Pesos del ranking de proveedores'], '/principal/administracao_pesos.php', 'bi-sliders');
$menuRanking = $montarMenu(M289_MENU_RANKING, $irmao('codigo', M289_MENU_FORNECEDORES),
    ['pt' => 'Ranking de fornecedores', 'en' => 'Supplier ranking', 'es' => 'Ranking de proveedores'], '/principal/homologacaofornecedor_ranking.php', 'bi-trophy', $menuPesos['codmenu']);
$relatorio['menus'] = [M289_MENU_PESOS => 'codmenu ' . $menuPesos['codmenu'] . ' (pai ' . ($menuPesos['codmenu_pai'] ?? 'topo') . ')',
    M289_MENU_RANKING => 'codmenu ' . $menuRanking['codmenu'] . ' (pai ' . ($menuRanking['codmenu_pai'] ?? 'topo') . ')'];
$gravarMenu = static function (PDO $pdo, array $linha): void {
    if (!empty($linha['codmenu_pai']) && !MigracaoCrud::primeiro($pdo, 'menus', ['codmenu' => $linha['codmenu_pai']])) $linha['codmenu_pai'] = null;
    $dados = array_intersect_key($linha, array_flip(MigracaoCrud::colunas($pdo, 'menus')));
    $atual = MigracaoCrud::primeiro($pdo, 'menus', ['codmenu' => $linha['codmenu']]);
    if ($atual && strtoupper((string)($atual['codigo'] ?? '')) !== strtoupper((string)$linha['codigo'])) throw new RuntimeException("codmenu {$linha['codmenu']} já usado por {$atual['codigo']}");
    if ($atual) MigracaoCrud::atualizar($pdo, 'menus', ['codmenu' => $linha['codmenu']], array_diff_key($dados, ['codmenu' => 1]));
    else MigracaoCrud::inserir($pdo, 'menus', $dados);
};
if (!$simular) {
    try { $gravarMenu($u, $menuPesos); $gravarMenu($u, $menuRanking); }
    catch (Throwable $e) { $relatorio['ok'] = false; $relatorio['menus']['erro_universal'] = $e->getMessage(); }
    if (MigracaoCrud::colunas($u, 'servicos')) {
        try {
            MigracaoCrud::salvar($u, 'servicos', ['codigo' => 'FORNECEDOR_RANKING'],
                ['nome_pt' => 'Ranking de fornecedores', 'nome_en' => 'Supplier ranking', 'nome_es' => 'Ranking de proveedores',
                 'descricao_pt' => 'Calcula a nota e a faixa (A, B, C, D) de cada fornecedor uma vez por dia e quando os pesos mudam.',
                 'descricao_en' => 'Calculates each supplier score and band (A, B, C, D) once a day and when the weights change.',
                 'descricao_es' => 'Calcula la nota y la franja (A, B, C, D) de cada proveedor una vez al día y cuando cambian los pesos.',
                 'host' => 'TASK_SCHEDULER', 'script' => 'scripts/fornecedor-ranking-worker.php', 'ativo' => 1], ['porta' => 0, 'ordem' => 13]);
        } catch (Throwable $e) { $relatorio['servico_erro'] = $e->getMessage(); }
    }
}

foreach ($clientes as $banco => $pdo) {
    $info = [];
    try {
        $cols = [];
        foreach ($pdo->query('SELECT LOWER(TABLE_NAME) t, LOWER(COLUMN_NAME) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $r) $cols[$r['t']][$r['c']] = true;
        $tem = static function (string $t, string ...$c) use (&$cols): bool {
            if (!isset($cols[strtolower($t)])) return false;
            foreach ($c as $x) if (!isset($cols[strtolower($t)][strtolower($x)])) return false;
            return true;
        };
        if (!$tem('fo_fornecedores', 'codfornecedor', 'razaosocial')) { $relatorio['bancos'][$banco] = ['ignorado' => 'sem homologação de fornecedores (fo_fornecedores)']; continue; }

        // ---------------------------------------------------------- componentes do cálculo
        $vazio = static fn(string $colunas): string => "SELECT $colunas FROM DUAL WHERE 1 = 0";
        $ativo = $tem('fo_fornecedores', 'ativadofor')
            ? "UPPER(TRIM(COALESCE(f.`ativadofor`, 'S'))) NOT IN ('N', 'NAO', 'NÃO', '0', 'INATIVO', 'FALSE')" : 'TRUE';
        $ativo .= " AND COALESCE(f.`razaosocial`, '') NOT LIKE '%migra__o 217%'";
        $unid = $tem('fo_fornecedores', 'codunidade') ? 'f.`codunidade`' : 'NULL';
        $avisos = [];

        if ($tem('vw_portaria_documentacao_fornecedor_grid', 'CODFORNECEDOR', 'SITUACAO_COR'))
            $doc = "SELECT `CODFORNECEDOR`, SUM(`SITUACAO_COR` <> 'nao-aplica') AS total, SUM(`SITUACAO_COR` IN ('aprovado', 'a-vencer')) AS ok
                      FROM `vw_portaria_documentacao_fornecedor_grid` GROUP BY `CODFORNECEDOR`";
        else { $doc = $vazio('0 AS CODFORNECEDOR, 0 AS total, 0 AS ok'); $avisos[] = 'sem a view da Portaria de fornecedores (migração 260): documentação do fornecedor fora da nota'; }

        $partesFunc = [];
        if ($tem('vw_portaria_documentacao_funcionario_grid', 'CODFORNECEDOR', 'CODFUNCIONARIO', 'SITUACAO_COR'))
            $partesFunc[] = "SELECT `CODFORNECEDOR`, `CODFUNCIONARIO`, `SITUACAO_COR` AS cor FROM `vw_portaria_documentacao_funcionario_grid`";
        if ($tem('vw_portaria_documentacao_treinamento_grid', 'CODFUNCIONARIO', 'SITUACAO_COR') && $tem('fo_funcionarios', 'CODFUNCIONARIO', 'CODFORNECEDOR')) {
            $desligado = $tem('fo_funcionarioxtiposxcriterios', 'codfuncionario', 'atendeaocriterio')
                ? "\n                 WHERE NOT EXISTS (SELECT 1 FROM `fo_funcionarioxtiposxcriterios` x WHERE x.`codfuncionario` = t.`CODFUNCIONARIO` AND x.`atendeaocriterio` IN ('Desligado', 'Afastado'))" : '';
            $partesFunc[] = "SELECT fu.`CODFORNECEDOR`, t.`CODFUNCIONARIO`, t.`SITUACAO_COR` AS cor
                  FROM `vw_portaria_documentacao_treinamento_grid` t JOIN `fo_funcionarios` fu ON fu.`CODFUNCIONARIO` = t.`CODFUNCIONARIO`$desligado";
        }
        if ($partesFunc)
            $func = "SELECT `CODFORNECEDOR`, SUM(cor <> 'nao-aplica') AS total, SUM(cor IN ('aprovado', 'a-vencer')) AS ok,
                            COUNT(DISTINCT IF(cor <> 'nao-aplica', `CODFUNCIONARIO`, NULL)) AS funcionarios
                       FROM (" . implode("\n UNION ALL ", $partesFunc) . ") fx GROUP BY `CODFORNECEDOR`";
        else { $func = $vazio('0 AS CODFORNECEDOR, 0 AS total, 0 AS ok, 0 AS funcionarios'); $avisos[] = 'sem as views da Portaria de funcionários (migrações 262/263): documentação dos funcionários fora da nota'; }

        $ncOk = $tem('nc_indicacao_ocorrencia', 'CODFORNECEDOR', 'CODTIPONAOCONFORMIDADE', 'DATA_OCORRENCIA');
        $aberta = 'FALSE';
        if ($ncOk) {
            $cond = [];
            if ($tem('nc_indicacao_ocorrencia', 'ETAPA')) $cond[] = "COALESCE(o.`ETAPA`, '') <> 'Finalizado'";
            if ($tem('nc_indicacao_ocorrencia', 'SITUACAO')) $cond[] = "COALESCE(o.`SITUACAO`, '') <> 'Finalizado'";
            $aberta = $cond ? '(' . implode(' AND ', $cond) . ')' : 'FALSE';
            $prevista = $tem('nc_indicacao_ocorrencia', 'DATA_DE_CONCLUSAO_PREVISTA') ? "(o.`DATA_DE_CONCLUSAO_PREVISTA` > '1900-01-01' AND o.`DATA_DE_CONCLUSAO_PREVISTA` < CURDATE()) OR " : '';
            $atrasada = "($aberta AND ($prevista o.`DATA_OCORRENCIA` < CURDATE() - INTERVAL 90 DAY))";
            $nc = "SELECT o.`CODFORNECEDOR`, SUM($aberta) AS abertas, SUM($atrasada) AS atrasadas,
                          SUM(NOT $aberta AND o.`DATA_OCORRENCIA` >= CURDATE() - INTERVAL c.`nc_janela_meses` MONTH) AS fechadas
                     FROM `nc_indicacao_ocorrencia` o JOIN `fo_ranking_config` c ON c.`id` = 1
                    WHERE o.`CODTIPONAOCONFORMIDADE` = 1 AND o.`CODFORNECEDOR` > 0 GROUP BY o.`CODFORNECEDOR`";
        } else { $nc = $vazio('0 AS CODFORNECEDOR, 0 AS abertas, 0 AS atrasadas, 0 AS fechadas'); $avisos[] = 'sem não conformidades de fornecedor (nc_indicacao_ocorrencia): todos com 100 nesse componente'; }

        if ($tem('fo_fornecedoresxtipofornecedores', 'codfornecedor') && ($tem('fo_fornecedoresxtipofornecedores', 'NOTA') || $tem('fo_fornecedoresxtipofornecedores', 'FORNECEDORAPROVADOAUDITORIA'))) {
            // NOTA é número em alguns bancos e texto em outros: o texto vai com collation explícita, senão a view
            // falha fora da conexão do portal (ex.: mysqldump na collation padrão do MySQL 8 — "Illegal mix of collations").
            $notaTxt = "(CAST(TRIM(ft.`NOTA`) AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci)";
            $notaNum = $tem('fo_fornecedoresxtipofornecedores', 'NOTA')
                ? "(CASE WHEN $notaTxt REGEXP '^[0-9]+([.,][0-9]+)?$' AND CAST(REPLACE($notaTxt, ',', '.') AS DECIMAL(10,2)) > 0
                         THEN CAST(REPLACE($notaTxt, ',', '.') AS DECIMAL(10,2)) END)" : 'NULL';
            $aprov = $tem('fo_fornecedoresxtipofornecedores', 'FORNECEDORAPROVADOAUDITORIA') ? "UPPER(TRIM(COALESCE(ft.`FORNECEDORAPROVADOAUDITORIA`, '')))" : "''";
            $validade = $tem('fo_fornecedoresxtipofornecedores', 'DATAVALIDADE') ? "(CASE WHEN YEAR(ft.`DATAVALIDADE`) > 0 THEN DATE(ft.`DATAVALIDADE`) END)" : 'NULL';
            $base = "(CASE WHEN $notaNum IS NOT NULL THEN LEAST(100, IF($notaNum <= 10, $notaNum * 10, $notaNum))
                          WHEN $aprov LIKE 'S%' THEN 100 WHEN $aprov LIKE 'N%' THEN 0 END)";
            $aud = "SELECT a.`codfornecedor` AS CODFORNECEDOR, AVG(a.s) AS score, COUNT(a.s) AS qtd
                      FROM (SELECT ft.`codfornecedor`, IF($validade IS NOT NULL AND $validade < CURDATE(), $base * 0.5, $base) AS s
                              FROM `fo_fornecedoresxtipofornecedores` ft) a GROUP BY a.`codfornecedor`";
        } else { $aud = $vazio('0 AS CODFORNECEDOR, NULL AS score, 0 AS qtd'); $avisos[] = 'sem auditoria de homologação: componente fora da nota'; }

        $x = "SELECT f.`codfornecedor` AS CODFORNECEDOR, $unid AS CODUNIDADE,
               COALESCE(d.ok, 0) AS DOC_OK, COALESCE(d.total, 0) AS DOC_TOTAL,
               COALESCE(fn.ok, 0) AS FUNC_OK, COALESCE(fn.total, 0) AS FUNC_TOTAL, COALESCE(fn.funcionarios, 0) AS FUNCIONARIOS,
               COALESCE(n.abertas, 0) AS NC_ABERTAS, COALESCE(n.atrasadas, 0) AS NC_ATRASADAS, COALESCE(n.fechadas, 0) AS NC_FECHADAS,
               COALESCE(a.qtd, 0) AS AUDITORIAS,
               ROUND(IF(COALESCE(d.total, 0) > 0, 100 * d.ok / d.total, NULL), 1) AS NOTA_DOC_FORNECEDOR,
               ROUND(IF(COALESCE(fn.total, 0) > 0, 100 * fn.ok / fn.total, NULL), 1) AS NOTA_DOC_FUNCIONARIOS,
               ROUND(GREATEST(0, 100 - c.`nc_penalidade_aberta` * COALESCE(n.abertas, 0) - c.`nc_penalidade_atrasada` * COALESCE(n.atrasadas, 0)
                                    - c.`nc_penalidade_fechada` * COALESCE(n.fechadas, 0)), 1) AS NOTA_NC,
               ROUND(a.score, 1) AS NOTA_AUDITORIA
          FROM `fo_fornecedores` f
          JOIN `fo_ranking_config` c ON c.`id` = 1
          LEFT JOIN ($doc) d ON d.`CODFORNECEDOR` = f.`codfornecedor`
          LEFT JOIN ($func) fn ON fn.`CODFORNECEDOR` = f.`codfornecedor`
          LEFT JOIN ($nc) n ON n.`CODFORNECEDOR` = f.`codfornecedor`
          LEFT JOIN ($aud) a ON a.`CODFORNECEDOR` = f.`codfornecedor`
         WHERE $ativo";
        // Só com não conformidades (sem documentos exigidos, funcionários nem auditoria) não há base para nota: fica "Sem dados"
        $y = "SELECT x.*, IF(x.NOTA_DOC_FORNECEDOR IS NULL AND x.NOTA_DOC_FUNCIONARIOS IS NULL AND x.NOTA_AUDITORIA IS NULL, NULL, ROUND((COALESCE(x.NOTA_DOC_FORNECEDOR, 0) * c.`peso_doc_fornecedor` + COALESCE(x.NOTA_DOC_FUNCIONARIOS, 0) * c.`peso_doc_funcionarios`
                               + x.NOTA_NC * c.`peso_nc` + COALESCE(x.NOTA_AUDITORIA, 0) * c.`peso_auditoria`)
                              / NULLIF(c.`peso_doc_fornecedor` * (x.NOTA_DOC_FORNECEDOR IS NOT NULL) + c.`peso_doc_funcionarios` * (x.NOTA_DOC_FUNCIONARIOS IS NOT NULL)
                                       + c.`peso_nc` + c.`peso_auditoria` * (x.NOTA_AUDITORIA IS NOT NULL), 0), 1)) AS NOTA
                FROM ($x) x JOIN `fo_ranking_config` c ON c.`id` = 1";
        $views = [];
        $views['vw_fornecedor_ranking_calculo'] = "CREATE OR REPLACE VIEW `vw_fornecedor_ranking_calculo` AS
SELECT y.*, CASE WHEN y.NOTA IS NULL THEN NULL WHEN y.NOTA >= c.`faixa_a` THEN 'A' WHEN y.NOTA >= c.`faixa_b` THEN 'B' WHEN y.NOTA >= c.`faixa_c` THEN 'C' ELSE 'D' END AS FAIXA
  FROM ($y) y JOIN `fo_ranking_config` c ON c.`id` = 1";

        // ---------------------------------------------------------- views de leitura das telas
        $nome = static fn(string $a): string => "COALESCE(NULLIF(TRIM($a.`nome`), ''), $a.`userid`, '')";
        $fantasia = $tem('fo_fornecedores', 'nomefantasia') ? 'f.`nomefantasia`' : "''";
        $cnpj = $tem('fo_fornecedores', 'cnpj_cpf') ? 'f.`cnpj_cpf`' : "''";
        $joinUnid = $tem('unidades', 'CODUNIDADE', 'DSCUNIDADE') && $unid !== 'NULL' ? 'LEFT JOIN `unidades` un ON un.`CODUNIDADE` = f.`codunidade`' : '';
        $unidNome = $joinUnid ? "COALESCE(un.`DSCUNIDADE`, '')" : "''";
        $views['vw_fornecedor_ranking'] = "CREATE OR REPLACE VIEW `vw_fornecedor_ranking` AS
SELECT f.`codfornecedor` AS CODFORNECEDOR, COALESCE(f.`razaosocial`, '') AS RAZAOSOCIAL, COALESCE($fantasia, '') AS NOMEFANTASIA, COALESCE($cnpj, '') AS CNPJ,
       $unid AS CODUNIDADE, $unidNome AS UNIDADE,
       r.`nota` AS NOTA, r.`faixa` AS FAIXA, r.`nota_doc_fornecedor` AS NOTA_DOC_FORNECEDOR, r.`nota_doc_funcionarios` AS NOTA_DOC_FUNCIONARIOS,
       r.`nota_nc` AS NOTA_NC, r.`nota_auditoria` AS NOTA_AUDITORIA, COALESCE(r.`doc_ok`, 0) AS DOC_OK, COALESCE(r.`doc_total`, 0) AS DOC_TOTAL,
       COALESCE(r.`func_ok`, 0) AS FUNC_OK, COALESCE(r.`func_total`, 0) AS FUNC_TOTAL, COALESCE(r.`funcionarios`, 0) AS FUNCIONARIOS,
       COALESCE(r.`nc_abertas`, 0) AS NC_ABERTAS, COALESCE(r.`nc_atrasadas`, 0) AS NC_ATRASADAS, COALESCE(r.`nc_fechadas`, 0) AS NC_FECHADAS,
       COALESCE(r.`auditorias`, 0) AS AUDITORIAS, r.`calculado_em` AS CALCULADO_EM,
       (SELECT h.`nota` FROM `fo_ranking_historico` h WHERE h.`codfornecedor` = f.`codfornecedor` AND h.`data` < CURDATE() ORDER BY h.`data` DESC LIMIT 1) AS NOTA_ANTERIOR,
       IF(b.`codfornecedor` IS NULL, 'Não', 'Sim') AS BLOQUEADO, b.`motivo` AS MOTIVO_BLOQUEIO, b.`bloqueado_em` AS BLOQUEADO_EM, " . $nome('ub') . " AS BLOQUEADO_POR
  FROM `fo_fornecedores` f
  LEFT JOIN `fo_ranking` r ON r.`codfornecedor` = f.`codfornecedor`
  $joinUnid
  LEFT JOIN `fo_fornecedor_bloqueios` b ON b.`codfornecedor` = f.`codfornecedor`
  LEFT JOIN `usuarios` ub ON ub.`codusuario` = b.`codusuario`
 WHERE $ativo";
        // Demais perfis (fora ADMINISTRADOR / ADM DA EMPRESA / GESTOR): só as unidades do usuário, mesma regra da Portaria
        if ($tem('vw_portaria_usuario_unidades', 'CODUSUARIO', 'CODUNIDADE'))
            $views['vw_fornecedor_ranking_acesso'] = "CREATE OR REPLACE VIEW `vw_fornecedor_ranking_acesso` AS
SELECT r.*, uu.`CODUSUARIO` AS CODUSUARIO_ACESSO FROM `vw_fornecedor_ranking` r JOIN `vw_portaria_usuario_unidades` uu ON uu.`CODUNIDADE` = r.`CODUNIDADE`";
        $views['vw_fornecedor_ranking_config'] = "CREATE OR REPLACE VIEW `vw_fornecedor_ranking_config` AS
SELECT c.`peso_doc_fornecedor` AS PESO_DOC_FORNECEDOR, c.`peso_doc_funcionarios` AS PESO_DOC_FUNCIONARIOS, c.`peso_nc` AS PESO_NC, c.`peso_auditoria` AS PESO_AUDITORIA,
       c.`faixa_a` AS FAIXA_A, c.`faixa_b` AS FAIXA_B, c.`faixa_c` AS FAIXA_C,
       c.`nc_penalidade_aberta` AS NC_PENALIDADE_ABERTA, c.`nc_penalidade_atrasada` AS NC_PENALIDADE_ATRASADA, c.`nc_penalidade_fechada` AS NC_PENALIDADE_FECHADA,
       c.`nc_janela_meses` AS NC_JANELA_MESES, c.`recalcular` AS RECALCULAR, c.`ultimo_calculo` AS ULTIMO_CALCULO, c.`alterado_em` AS ALTERADO_EM, " . $nome('us') . " AS ALTERADO_POR
  FROM `fo_ranking_config` c LEFT JOIN `usuarios` us ON us.`codusuario` = c.`alterado_por` WHERE c.`id` = 1";
        $views['vw_fornecedor_ranking_config_historico'] = "CREATE OR REPLACE VIEW `vw_fornecedor_ranking_config_historico` AS
SELECT h.`codhistorico` AS CODHISTORICO, h.`antes` AS ANTES, h.`depois` AS DEPOIS, h.`em` AS EM, " . $nome('us') . " AS USUARIO
  FROM `fo_ranking_config_historico` h LEFT JOIN `usuarios` us ON us.`codusuario` = h.`codusuario`";
        $views['vw_fornecedor_bloqueios_historico'] = "CREATE OR REPLACE VIEW `vw_fornecedor_bloqueios_historico` AS
SELECT h.`codhistorico` AS CODHISTORICO, h.`codfornecedor` AS CODFORNECEDOR, COALESCE(f.`razaosocial`, CONCAT('#', h.`codfornecedor`)) AS RAZAOSOCIAL,
       h.`acao` AS ACAO, COALESCE(h.`motivo`, '') AS MOTIVO, h.`em` AS EM, " . $nome('us') . " AS USUARIO
  FROM `fo_fornecedor_bloqueios_historico` h
  LEFT JOIN `fo_fornecedores` f ON f.`codfornecedor` = h.`codfornecedor`
  LEFT JOIN `usuarios` us ON us.`codusuario` = h.`codusuario`";
        $views['vw_fornecedor_ranking_historico'] = "CREATE OR REPLACE VIEW `vw_fornecedor_ranking_historico` AS
SELECT h.`codfornecedor` AS CODFORNECEDOR, h.`data` AS DATA, h.`nota` AS NOTA, h.`faixa` AS FAIXA FROM `fo_ranking_historico` h";
        // Detalhe: treinamentos (a view da Portaria não traz o fornecedor) e não conformidades do fornecedor
        if ($tem('vw_portaria_documentacao_treinamento_grid', 'CODFUNCIONARIO') && $tem('fo_funcionarios', 'CODFUNCIONARIO', 'CODFORNECEDOR'))
            $views['vw_fornecedor_ranking_treinamentos'] = "CREATE OR REPLACE VIEW `vw_fornecedor_ranking_treinamentos` AS
SELECT fu.`CODFORNECEDOR` AS CODFORNECEDOR, t.`CODIGO`, t.`CODFUNCIONARIO`, t.`FUNCIONARIO`, t.`DSCTIPOTREINAMENTO`, t.`DATADEVALIDADETREINAMENTO`,
       t.`SITUACAO_COR`, t.`SITUACAO_DESCRICAO`
  FROM `vw_portaria_documentacao_treinamento_grid` t JOIN `fo_funcionarios` fu ON fu.`CODFUNCIONARIO` = t.`CODFUNCIONARIO`";
        if ($ncOk) {
            $desc = "''";
            foreach (['DESCRICAO', 'DSCOCORRENCIA', 'DESCRICAO_OCORRENCIA', 'DESCRICAO_DA_OCORRENCIA', 'OCORRENCIA', 'DSCNAOCONFORMIDADE', 'NAOCONFORMIDADE', 'RELATO'] as $cd)
                if ($tem('nc_indicacao_ocorrencia', $cd)) { $desc = "COALESCE(o.`$cd`, '')"; break; }
            $col = static fn(string $c, string $padrao = 'NULL'): string => $tem('nc_indicacao_ocorrencia', $c) ? "o.`$c`" : $padrao;
            $views['vw_fornecedor_ranking_ncs'] = "CREATE OR REPLACE VIEW `vw_fornecedor_ranking_ncs` AS
SELECT o.`CODOCORRENCIA`, o.`CODFORNECEDOR`, " . $col('NRANO', "''") . " AS NRANO, o.`DATA_OCORRENCIA`, " . $col('ETAPA', "''") . " AS ETAPA, " . $col('SITUACAO', "''") . " AS SITUACAO,
       " . $col('DATA_DE_CONCLUSAO_PREVISTA') . " AS DATA_DE_CONCLUSAO_PREVISTA, $desc AS DESCRICAO,
       IF($aberta, 'Sim', 'Não') AS ABERTA, IF($atrasada, 'Sim', 'Não') AS ATRASADA
  FROM `nc_indicacao_ocorrencia` o WHERE o.`CODTIPONAOCONFORMIDADE` = 1 AND o.`CODFORNECEDOR` > 0";
        }

        if (!$simular) {
            foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
                $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
                if ($cmd !== '') $pdo->exec($cmd);
            }
            foreach ($views as $ddl) $pdo->exec($ddl);
            if ($criadas = MigracaoCrud::garantir($pdo)) $info['procedures_crud_criadas'] = $criadas;
            foreach (array_keys($views) as $v) { $st = $pdo->prepare('CALL sp_crud_select(:t, NULL, NULL, 1, 0)'); $st->execute(['t' => $v]); $st->fetchAll(); $st->closeCursor(); }

            // Cadastro de fornecedores (vw_homologacao_fornecedores_grid): + faixa, nota e bloqueio do ranking. A view atual vira
            // <view>_base (mesmo esquema da migração 288); se outra migração recriar o grid, basta rodar esta de novo.
            $v = 'vw_homologacao_fornecedores_grid'; $base = $v . '_base';
            $defs = [];
            foreach ($pdo->query("SELECT LOWER(TABLE_NAME) n, VIEW_DEFINITION d FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('$v', '$base')")->fetchAll(PDO::FETCH_ASSOC) as $r) $defs[$r['n']] = (string)$r['d'];
            if (isset($defs[$v])) {
                if (!(str_contains($defs[$v], "`$base`") && isset($defs[$base]))) {
                    if (isset($defs[$base])) $pdo->exec("DROP VIEW `$base`");
                    $pdo->exec("RENAME TABLE `$v` TO `$base`");
                }
                $colsBase = array_flip(MigracaoCrud::colunas($pdo, $base));
                if (!isset($colsBase['codfornecedor'])) throw new RuntimeException("$v sem a coluna CODFORNECEDOR");
                $extra = [];
                foreach (['RANKING_FAIXA' => 'rk.`FAIXA`', 'RANKING_NOTA' => 'rk.`NOTA`', 'BLOQUEADO_RANKING' => "COALESCE(rk.`BLOQUEADO`, 'Não')", 'MOTIVO_BLOQUEIO' => 'rk.`MOTIVO_BLOQUEIO`'] as $c => $expr)
                    if (!isset($colsBase[strtolower($c)])) $extra[] = "$expr AS `$c`";
                $pdo->exec("CREATE OR REPLACE VIEW `$v` AS SELECT b.*" . ($extra ? ', ' . implode(', ', $extra) : '') . "
  FROM `$base` b LEFT JOIN `vw_fornecedor_ranking` rk ON rk.`CODFORNECEDOR` = b.`CODFORNECEDOR`");
                $st = $pdo->prepare('CALL sp_crud_select(:t, NULL, NULL, 1, 0)'); $st->execute(['t' => $v]); $st->fetchAll(); $st->closeCursor();
                $info['cadastro_fornecedores'] = 'colunas do ranking no grid de fornecedores';
            }

            // Menus e permissões
            $gravarMenu($pdo, $menuPesos); $gravarMenu($pdo, $menuRanking);
            $flags = ['pode_visualizar', 'pode_incluir', 'pode_editar', 'pode_excluir', 'pode_exportar_excel'];
            $codFornecedores = (int)(MigracaoCrud::primeiro($pdo, 'menus', ['codigo' => M289_MENU_FORNECEDORES])['codmenu'] ?? 0);
            $liberados = [];
            foreach (['permissoes', 'perfil_menus'] as $t) {
                $tc = MigracaoCrud::colunas($pdo, $t);
                if (!$tc) continue;
                $fl = array_values(array_intersect($flags, $tc));
                foreach (MigracaoCrud::selecionar($pdo, 'perfis') as $pf) {
                    if (!in_array($perfilNorm((string)($pf['codigo'] ?? '')), M289_PERFIS, true)) continue;
                    foreach ([$menuPesos['codmenu'], $menuRanking['codmenu']] as $cm) MigracaoCrud::salvar($pdo, $t, ['codperfil' => (int)$pf['codperfil'], 'codmenu' => $cm], array_fill_keys($fl, 1));
                    if ($t === 'permissoes') $liberados[] = $pf['codigo'];
                }
                // Ranking (só ver) para quem já vê o cadastro de fornecedores
                if ($codFornecedores && in_array('pode_visualizar', $tc, true)) {
                    foreach (MigracaoCrud::selecionar($pdo, $t, ['codmenu' => $codFornecedores]) as $p)
                        if ((int)($p['pode_visualizar'] ?? 0) === 1) MigracaoCrud::salvar($pdo, $t, ['codperfil' => (int)$p['codperfil'], 'codmenu' => $menuRanking['codmenu']], ['pode_visualizar' => 1]);
                }
            }
            $info['perfis_com_acesso_total'] = array_values(array_unique($liberados));

            // Primeiro cálculo
            $st = $pdo->prepare('CALL sp_fornecedor_ranking_atualizar(1)'); $st->execute(); $r = $st->fetch(PDO::FETCH_ASSOC) ?: []; $st->closeCursor();
            $info['calculo'] = ($r['status'] ?? '?') . ' — ' . (int)($r['fornecedores'] ?? 0) . ' fornecedores';
            $info['faixas'] = $pdo->query("SELECT COALESCE(`faixa`, '-') f, COUNT(*) n FROM `fo_ranking` GROUP BY `faixa` ORDER BY `faixa`")->fetchAll(PDO::FETCH_KEY_PAIR);
            $info['nota_media'] = (float)$pdo->query('SELECT ROUND(AVG(`nota`), 1) FROM `fo_ranking`')->fetchColumn();
        } else $info['views'] = count($views) . ' a gravar';
        if ($avisos) $info['avisos'] = $avisos;
    } catch (Throwable $e) { $relatorio['ok'] = false; $info['erro'] = $e->getMessage(); }
    $relatorio['bancos'][$banco] = $info;
}
echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
