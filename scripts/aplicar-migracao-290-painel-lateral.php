<?php
declare(strict_types=1);

/*
 * Migração 290 — barra lateral de pendências (principal/main_pendencias.php) e as telas Próximos prazos e Aprovações.
 * Substitui as consultas diretas em tabelas por duas views, com as mesmas regras do Dashboard:
 *
 *   vw_painel_prazos      o que vence (até 30 dias) ou já venceu (até 1 ano) sob a responsabilidade do usuário:
 *                         REQUISITO (situação de atendimento a revalidar), LICENCA (vw_dash_licencas: vencida, vence em 30 dias,
 *                         entrega periódica atrasada ou em 30 dias, prazo de reação), MONITORAMENTO (data de reação),
 *                         PLANO_NC (plano de ação de não conformidade), TAREFA (green_tasks), RESPONSABILIDADE (atribuição que
 *                         termina) e AVALIACAO_FORNECEDOR (próxima avaliação do fornecedor).
 *                         Responsável = o usuário do registro e, nas licenças e monitoramentos, também quem responde pelo item
 *                         em vw_responsabilidade_efetiva (grupo, vários responsáveis, substituição e delegação).
 *                         Colunas: CODUSUARIO, TIPO, CODIGO, CHAVE2, TITULO, DETALHE, PRAZO, SITUACAO (ATRASADO / HOJE / PROXIMO)
 *   vw_painel_aprovacoes  o que espera decisão: IA (sugestões da análise por IA ainda não avaliadas, de quem pediu),
 *                         NC_GESTOR (ocorrência "Com o Gestor"), PERFIL_ATRIBUICOES (envio aguardando o gestor),
 *                         SUBSTITUICAO / DELEGACAO (pedidos pendentes: CODUSUARIO = 0, vale para quem pode editar a transação),
 *                         FORNECEDOR_DOC (documentos "Pendente com a empresa", para o responsável pelo fornecedor) e
 *                         WORKFLOW (fluxos abertos sob responsabilidade do usuário).
 *                         Colunas: CODUSUARIO, TIPO, CODIGO, CHAVE2, TITULO, DETALHE, DESDE, TRANSACAO, ACAO
 *   vw_painel_calendario  calendário de pendências (principal/painel_calendario.php): as mesmas partes de vw_painel_prazos,
 *                         numa janela de 3 anos para trás e 3 para a frente, com todas as datas de vencimento das licenças
 *                         (não só as próximas de 30 dias), o prazo de reação, e os eventos da agenda (AGENDA, agenda_eventos).
 *                         Colunas: as de vw_painel_prazos + ANO (filtro do calendário por ano)
 *
 * Cada parte só entra quando as tabelas/views de que depende existem (o relatório lista o que ficou de fora).
 * Rodar depois das migrações 285 e 288. Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-290-painel-lateral.php [--simular] [--banco=gatlas_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$bancoLegislacao = 'glegislacao_dbp';
$relatorio = ['ok' => true, 'migracao' => '290', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes', 'usuarios') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && preg_match('/^g[a-z0-9_]+_dbp$/', (string)$b) && ($soBanco === null || $b === $soBanco)));
$colsLeg = [];
foreach ($geral->query("SELECT LOWER(TABLE_NAME) t, LOWER(COLUMN_NAME) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = " . $geral->quote($bancoLegislacao))->fetchAll(PDO::FETCH_ASSOC) as $r) $colsLeg[$r['t']][$r['c']] = true;

foreach ($bancos as $banco) {
    $info = ['prazos' => [], 'aprovacoes' => [], 'fora' => []];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $cols = [];
        $s = $pdo->query('SELECT LOWER(TABLE_NAME) t, LOWER(COLUMN_NAME) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()');
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $cols[$r['t']][$r['c']] = true;
        $s->closeCursor();
        $tem = static function (string $t, string ...$c) use ($cols): bool {
            if (!isset($cols[strtolower($t)])) return false;
            foreach ($c as $x) if (!isset($cols[strtolower($t)][strtolower($x)])) return false;
            return true;
        };
        $temLeg = static fn(string ...$c): bool => isset($colsLeg['legislacao']) && !array_diff(array_map('strtolower', $c), array_keys($colsLeg['legislacao']));
        // Entidades de responsabilidade (vw_responsabilidade_efetiva.ENTIDADE) que correspondem a licenças e monitoramentos
        $entidades = [];
        if ($tem('vw_responsabilidade_efetiva', 'ENTIDADE', 'REGISTRO_ID', 'CODUSUARIO_DESTINO')) {
            foreach ($pdo->query('SELECT DISTINCT `ENTIDADE` FROM `vw_responsabilidade_efetiva`')->fetchAll(PDO::FETCH_COLUMN) as $en) $entidades[] = (string)$en;
        }
        $info['entidades_responsabilidade'] = $entidades;
        [$prazos, $aprovacoes, $fora] = M290Views::montar($tem, $temLeg, $bancoLegislacao, $entidades);
        $info['fora'] = $fora;
        $info['prazos'] = array_keys($prazos); $info['aprovacoes'] = array_keys($aprovacoes);
        $ddl = [
            'vw_painel_prazos' => $prazos ? "CREATE OR REPLACE VIEW `vw_painel_prazos` AS\n" . implode("\nUNION\n", $prazos) : M290Views::vazia('PRAZO'),
            'vw_painel_aprovacoes' => $aprovacoes ? "CREATE OR REPLACE VIEW `vw_painel_aprovacoes` AS\n" . implode("\nUNION ALL\n", $aprovacoes) : M290Views::vazia('DESDE'),
        ];
        [$calendario, , $foraCal] = M290Views::montar($tem, $temLeg, $bancoLegislacao, $entidades, true);
        $info['calendario'] = array_keys($calendario);
        foreach ($foraCal as $k => $v) if (!isset($info['fora'][$k])) $info['fora'][$k] = $v;
        $ddl['vw_painel_calendario'] = $calendario ? "CREATE OR REPLACE VIEW `vw_painel_calendario` AS\n" . implode("\nUNION\n", $calendario) : M290Views::vazia('ANO');
        if (!$simular) {
            if ($criadas = MigracaoCrud::garantir($pdo)) $info['procedures_crud_criadas'] = $criadas;
            foreach ($ddl as $nome => $sql) {
                $pdo->exec($sql);
                $st = $pdo->prepare('CALL sp_crud_select(:t, NULL, NULL, 1, 0)'); $st->execute(['t' => $nome]); $st->fetchAll(); $st->closeCursor();
            }
            $info['linhas'] = [
                'prazos' => (int)$pdo->query('SELECT COUNT(*) FROM `vw_painel_prazos`')->fetchColumn(),
                'prazos_por_tipo' => $pdo->query('SELECT `TIPO`, COUNT(*) FROM `vw_painel_prazos` GROUP BY `TIPO`')->fetchAll(PDO::FETCH_KEY_PAIR),
                'aprovacoes_por_tipo' => $pdo->query('SELECT `TIPO`, COUNT(*) FROM `vw_painel_aprovacoes` GROUP BY `TIPO`')->fetchAll(PDO::FETCH_KEY_PAIR),
                'calendario_por_tipo' => $pdo->query('SELECT `TIPO`, COUNT(*) FROM `vw_painel_calendario` GROUP BY `TIPO`')->fetchAll(PDO::FETCH_KEY_PAIR),
            ];
        }
        if (!$info['fora']) unset($info['fora']);
    } catch (Throwable $e) { $relatorio['ok'] = false; $info['erro'] = $e->getMessage(); }
    $relatorio['bancos'][$banco] = $info;
}
echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);

final class M290Views
{
    /** Texto com a mesma collation em todas as partes do UNION */
    private static function t(string $e): string { return "COALESCE(CONVERT($e USING utf8mb4) COLLATE utf8mb4_unicode_ci, _utf8mb4'' COLLATE utf8mb4_unicode_ci)"; }
    private static function d(string $e): string { return "(CASE WHEN YEAR($e) > 0 THEN DATE($e) END)"; }
    private static function situacao(string $prazo): string { return "(CASE WHEN $prazo < CURDATE() THEN 'ATRASADO' WHEN $prazo = CURDATE() THEN 'HOJE' ELSE 'PROXIMO' END)"; }
    /** true ao montar vw_painel_calendario: janela de 3 anos para cada lado e a coluna ANO */
    private static bool $cal = false;
    private static function janela(string $prazo): string
    {
        return self::$cal ? "$prazo IS NOT NULL AND $prazo BETWEEN DATE_SUB(CURDATE(), INTERVAL 3 YEAR) AND DATE_ADD(CURDATE(), INTERVAL 3 YEAR)"
            : "$prazo IS NOT NULL AND $prazo BETWEEN DATE_SUB(CURDATE(), INTERVAL 365 DAY) AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
    }

    public static function vazia(string $data): string
    {
        $nome = ['PRAZO' => 'vw_painel_prazos', 'DESDE' => 'vw_painel_aprovacoes', 'ANO' => 'vw_painel_calendario'][$data];
        $extra = $data === 'DESDE' ? ", CAST(NULL AS DATETIME) AS `DESDE`, '' AS `TRANSACAO`, '' AS `ACAO`"
            : ", CAST(NULL AS DATE) AS `PRAZO`, '' AS `SITUACAO`" . ($data === 'ANO' ? ", '' AS `ANO`" : '');
        return "CREATE OR REPLACE VIEW `$nome` AS SELECT 0 AS `CODUSUARIO`, '' AS `TIPO`, '' AS `CODIGO`, '' AS `CHAVE2`, '' AS `TITULO`, '' AS `DETALHE`$extra FROM DUAL WHERE 1 = 0";
    }

    private static function prazo(string $usuario, string $tipo, string $codigo, string $chave2, string $titulo, string $detalhe, string $prazo, string $from): string
    {
        return "SELECT $usuario AS `CODUSUARIO`, '$tipo' AS `TIPO`, " . self::t("CAST($codigo AS CHAR)") . " AS `CODIGO`, " . self::t("CAST($chave2 AS CHAR)") . " AS `CHAVE2`,
       " . self::t($titulo) . " AS `TITULO`, " . self::t($detalhe) . " AS `DETALHE`, $prazo AS `PRAZO`, " . self::situacao($prazo) . " AS `SITUACAO`"
            . (self::$cal ? ", " . self::t("CAST(YEAR($prazo) AS CHAR)") . " AS `ANO`" : '') . "
  FROM $from";
    }

    private static function aprov(string $usuario, string $tipo, string $codigo, string $chave2, string $titulo, string $detalhe, string $desde, string $transacao, string $acao, string $from): string
    {
        return "SELECT $usuario AS `CODUSUARIO`, '$tipo' AS `TIPO`, " . self::t("CAST($codigo AS CHAR)") . " AS `CODIGO`, " . self::t("CAST($chave2 AS CHAR)") . " AS `CHAVE2`,
       " . self::t($titulo) . " AS `TITULO`, " . self::t($detalhe) . " AS `DETALHE`, CAST($desde AS DATETIME) AS `DESDE`, '$transacao' AS `TRANSACAO`, '$acao' AS `ACAO`
  FROM $from";
    }

    /** @return array{0: array<string,string>, 1: array<string,string>, 2: array<string,string>} */
    public static function montar(callable $tem, callable $temLeg, string $bancoLeg, array $entidades, bool $calendario = false): array
    {
        self::$cal = $calendario;
        try { return self::partes($tem, $temLeg, $bancoLeg, $entidades); } finally { self::$cal = false; }
    }

    private static function partes(callable $tem, callable $temLeg, string $bancoLeg, array $entidades): array
    {
        $p = []; $a = []; $fora = [];
        $resp = $tem('vw_responsabilidade_efetiva', 'ENTIDADE', 'REGISTRO_ID', 'CODUSUARIO_DESTINO');
        $ent = static function (string $regex) use ($entidades): array { return array_values(array_filter($entidades, static fn($e) => preg_match($regex, (string)$e) === 1)); };
        $inEnt = static fn(array $l): string => "'" . implode("', '", array_map(static fn($x) => str_replace("'", "''", (string)$x), $l)) . "'";

        // ---------------------------------------------------------------- PRAZOS
        if ($tem('legislacaoxsituacao', 'CODUSUARIO', 'data_de_vencimento_avaliacao', 'atendido', 'CODLEGISLACAO', 'codunidade')) {
            $P = self::d('s.`data_de_vencimento_avaliacao`');
            $joinLeg = $temLeg('CODLEGISLACAO', 'CODIGO') ? "\n  LEFT JOIN `$bancoLeg`.`legislacao` lg ON lg.`CODLEGISLACAO` = s.`CODLEGISLACAO`" : '';
            $nr = $temLeg('NR', 'ANO') ? ", NULLIF(CONCAT_WS('/', NULLIF(lg.`NR`, ''), NULLIF(lg.`ANO`, '')), '')" : '';
            $titulo = $joinLeg ? "CONCAT_WS(' ', NULLIF(lg.`CODIGO`, '')$nr)" : "CONCAT('Requisito ', s.`CODLEGISLACAO`)";
            $joinUn = $tem('unidades', 'CODUNIDADE', 'DSCUNIDADE') ? "\n  LEFT JOIN `unidades` un ON un.`CODUNIDADE` = s.`codunidade`" : '';
            $p['REQUISITO'] = self::prazo('s.`CODUSUARIO`', 'REQUISITO', 's.`CODLEGISLACAO`', 's.`codunidade`', "COALESCE(NULLIF($titulo, ''), CONCAT('Requisito ', s.`CODLEGISLACAO`))",
                $joinUn ? "CONCAT('Revalidar a situação de atendimento · ', COALESCE(CONVERT(un.`DSCUNIDADE` USING utf8mb4), ''))" : "'Revalidar a situação de atendimento'", $P,
                "`legislacaoxsituacao` s$joinLeg$joinUn\n WHERE s.`CODUSUARIO` > 0 AND COALESCE(UPPER(TRIM(s.`atendido`)), '') <> 'S' AND " . self::janela($P));
        } else $fora['REQUISITO'] = 'sem legislacaoxsituacao';

        if ($tem('vw_dash_licencas', 'CODLICENCA', 'CODUSUARIO', 'FAIXA', 'DATA_VALIDADE', 'DATA_REACAO', 'LICENCA')) {
            $atencao = "l.`FAIXA` IN ('VENCIDA', 'PROT_SEM_PRAZO', 'VENCE_30', 'ENTREGA_ATRASADA', 'ENTREGA_30')";
            $reacao = "(l.`DATA_REACAO` IS NOT NULL AND l.`DATA_REACAO` BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND l.`FAIXA` NOT IN ('EM_PROTOCOLO', 'ENTREGA_REALIZADA'))";
            $prazoLic = "(CASE WHEN $atencao THEN l.`DATA_VALIDADE` ELSE l.`DATA_REACAO` END)";
            $detalhe = "CONCAT(CASE l.`FAIXA` WHEN 'VENCIDA' THEN 'Vencida' WHEN 'PROT_SEM_PRAZO' THEN 'Em protocolo: falta informar as datas do protocolo' WHEN 'VENCE_30' THEN 'Vence em até 30 dias' WHEN 'ENTREGA_ATRASADA' THEN 'Entrega atrasada'
                WHEN 'ENTREGA_30' THEN 'Entrega em até 30 dias' ELSE 'Prazo de reação' END, IF(l.`UNIDADE` <> '', CONCAT(' · ', CONVERT(l.`UNIDADE` USING utf8mb4)), ''))";
            $onde = "($atencao OR $reacao) AND " . self::janela($prazoLic);
            if (self::$cal) {
                // Calendário: todo vencimento (validade ou entrega periódica) e, à parte, o prazo de reação
                $fechada = "l.`FAIXA` NOT IN ('EM_PROTOCOLO', 'ENTREGA_REALIZADA', 'SEM_VALIDADE', 'ENTREGA_SEM_PRAZO')";
                $unid = "IF(l.`UNIDADE` <> '', CONCAT(' · ', CONVERT(l.`UNIDADE` USING utf8mb4)), '')";
                $detV = "CONCAT(CASE WHEN l.`FAIXA` = 'VENCIDA' THEN 'Licença vencida' WHEN l.`FAIXA` = 'PROT_SEM_PRAZO' THEN 'Em protocolo (prazo não informado)' WHEN l.`FAIXA` = 'ENTREGA_ATRASADA' THEN 'Entrega atrasada'
                WHEN l.`FAIXA` LIKE 'ENTREGA%' THEN 'Entrega periódica' ELSE 'Vencimento da licença' END, $unid)";
                $V = 'l.`DATA_VALIDADE`'; $R = 'l.`DATA_REACAO`';
                $partes = ['LICENCA' => [$V, $detV, "$fechada AND " . self::janela($V)],
                           'LICENCA_REACAO' => [$R, "CONCAT('Prazo de reação', $unid)", "l.`FAIXA` NOT IN ('EM_PROTOCOLO', 'ENTREGA_REALIZADA') AND " . self::janela($R)]];
                $el = $resp ? $ent('/licen/i') : [];
                foreach ($partes as $k => [$data, $det, $cond]) {
                    $p[$k] = self::prazo('l.`CODUSUARIO`', 'LICENCA', 'l.`CODLICENCA`', "''", 'l.`LICENCA`', $det, $data, "`vw_dash_licencas` l\n WHERE l.`CODUSUARIO` > 0 AND $cond");
                    if ($el) $p[$k . '_RESP'] = self::prazo('r.`CODUSUARIO_DESTINO`', 'LICENCA', 'l.`CODLICENCA`', "''", 'l.`LICENCA`', $det, $data,
                        "`vw_dash_licencas` l\n  JOIN `vw_responsabilidade_efetiva` r ON r.`ENTIDADE` IN (" . $inEnt($el) . ") AND CAST(r.`REGISTRO_ID` AS CHAR) = CAST(l.`CODLICENCA` AS CHAR)\n WHERE $cond");
                }
            } else {
            $p['LICENCA'] = self::prazo('l.`CODUSUARIO`', 'LICENCA', 'l.`CODLICENCA`', "''", 'l.`LICENCA`', $detalhe, $prazoLic, "`vw_dash_licencas` l\n WHERE l.`CODUSUARIO` > 0 AND $onde");
            $el = $resp ? $ent('/licen/i') : [];
            if ($el) $p['LICENCA_RESP'] = self::prazo('r.`CODUSUARIO_DESTINO`', 'LICENCA', 'l.`CODLICENCA`', "''", 'l.`LICENCA`', $detalhe, $prazoLic,
                "`vw_dash_licencas` l\n  JOIN `vw_responsabilidade_efetiva` r ON r.`ENTIDADE` IN (" . $inEnt($el) . ") AND CAST(r.`REGISTRO_ID` AS CHAR) = CAST(l.`CODLICENCA` AS CHAR)\n WHERE $onde");
            }
        } else $fora['LICENCA'] = 'sem vw_dash_licencas (migrações 285 e 288)';

        if ($tem('vw_minhas_monitoramentos_grid', 'CODUSUARIO', 'CODMONITORAMENTO', 'DATA_REACAO')) {
            $P = self::d('m.`DATA_REACAO`');
            $tit = $tem('vw_minhas_monitoramentos_grid', 'PARAMETROS') ? "COALESCE(NULLIF(TRIM(m.`PARAMETROS`), ''), CONCAT('Monitoramento ', m.`CODMONITORAMENTO`))" : "CONCAT('Monitoramento ', m.`CODMONITORAMENTO`)";
            $p['MONITORAMENTO'] = self::prazo('m.`CODUSUARIO`', 'MONITORAMENTO', 'm.`CODMONITORAMENTO`', "''", $tit, "'Data de reação da medição'", $P,
                "`vw_minhas_monitoramentos_grid` m\n WHERE m.`CODUSUARIO` > 0 AND " . self::janela($P));
        } else $fora['MONITORAMENTO'] = 'sem vw_minhas_monitoramentos_grid';

        if ($tem('nc_planodeacao', 'CODUSUARIO', 'DATAFINAL', 'STATUS', 'CODOCORRENCIA')) {
            $P = self::d('pa.`DATAFINAL`');
            $tit = $tem('nc_planodeacao', 'ACAO') ? "COALESCE(NULLIF(LEFT(TRIM(CONVERT(pa.`ACAO` USING utf8mb4)), 200), ''), 'Plano de ação')" : "'Plano de ação'";
            $p['PLANO_NC'] = self::prazo('pa.`CODUSUARIO`', 'PLANO_NC', 'pa.`CODOCORRENCIA`', ($tem('nc_planodeacao', 'CODPLANOACAO') ? 'pa.`CODPLANOACAO`' : "''"), $tit,
                "CONCAT('Plano de ação da não conformidade ', pa.`CODOCORRENCIA`)", $P,
                "`nc_planodeacao` pa\n WHERE pa.`CODUSUARIO` > 0 AND COALESCE(pa.`STATUS`, 0) NOT IN (0, 2) AND " . self::janela($P));
        } else $fora['PLANO_NC'] = 'sem nc_planodeacao';

        if ($tem('green_tasks', 'codtarefa', 'codusuario_destino', 'data_limite', 'status', 'titulo')) {
            $P = self::d('gt.`data_limite`');
            $ia = $tem('green_tasks', 'origem_entidade', 'origem_id') && $tem('ml_potenciais', 'codpotencial', 'codsolicitacao')
                ? "(CASE WHEN gt.`origem_entidade` = 'ml_potenciais' THEN (SELECT mp.`codsolicitacao` FROM `ml_potenciais` mp WHERE mp.`codpotencial` = gt.`origem_id` LIMIT 1) END)" : "''";
            $p['TAREFA'] = self::prazo('gt.`codusuario_destino`', 'TAREFA', 'gt.`codtarefa`', "COALESCE($ia, '')", 'gt.`titulo`', "'Tarefa'", $P,
                "`green_tasks` gt\n WHERE gt.`codusuario_destino` > 0 AND gt.`status` IN ('PENDENTE', 'PROCESSANDO') AND " . self::janela($P));
        } else $fora['TAREFA'] = 'sem green_tasks';

        if ($resp && $tem('vw_responsabilidade_efetiva', 'VALIDO_ATE', 'CODORIGEM', 'MODULO_NOME')) {
            $P = self::d('re.`VALIDO_ATE`');
            $p['RESPONSABILIDADE'] = self::prazo('re.`CODUSUARIO_DESTINO`', 'RESPONSABILIDADE', 're.`CODORIGEM`', 're.`TIPO_ORIGEM`', "CONCAT('Responsabilidade ', COALESCE(CONVERT(re.`MODULO_NOME` USING utf8mb4), ''))",
                "'Atribuição de responsabilidade termina'", $P, "`vw_responsabilidade_efetiva` re\n WHERE " . (self::$cal ? self::janela($P) : "$P BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"));
        } else $fora['RESPONSABILIDADE'] = 'sem vw_responsabilidade_efetiva';

        if ($tem('fo_fornecedores', 'CODFORNECEDOR', 'CODUSUARIO_RESPONSAVEL_FORNECEDOR', 'DATA_DA_PROXIMA_AVALIACAO', 'RAZAOSOCIAL')) {
            $P = self::d('f.`DATA_DA_PROXIMA_AVALIACAO`');
            $ativo = $tem('fo_fornecedores', 'ATIVADOFOR') ? " AND UPPER(" . self::t('f.`ATIVADOFOR`') . ") NOT IN ('NAO', 'NÃO', 'N', '0')" : '';
            $p['AVALIACAO_FORNECEDOR'] = self::prazo('f.`CODUSUARIO_RESPONSAVEL_FORNECEDOR`', 'AVALIACAO_FORNECEDOR', 'f.`CODFORNECEDOR`', "''", 'f.`RAZAOSOCIAL`', "'Próxima avaliação do fornecedor'", $P,
                "`fo_fornecedores` f\n WHERE f.`CODUSUARIO_RESPONSAVEL_FORNECEDOR` > 0$ativo AND " . self::janela($P));
        } else $fora['AVALIACAO_FORNECEDOR'] = 'sem fo_fornecedores.DATA_DA_PROXIMA_AVALIACAO';

        if (self::$cal) {
            if ($tem('agenda_eventos', 'codeventoagenda', 'codusuario_responsavel', 'titulo', 'inicio', 'status')) {
                $P = self::d('ag.`inicio`');
                $det = $tem('agenda_eventos', 'descricao') ? "COALESCE(NULLIF(LEFT(TRIM(CONVERT(ag.`descricao` USING utf8mb4)), 200), ''), 'Evento da agenda')" : "'Evento da agenda'";
                $orig = $tem('agenda_eventos', 'origem_entidade', 'origem_id');
                $chave = $tem('agenda_eventos', 'chave_idempotencia');
                // CHAVE2 = origem; CODIGO = registro a abrir. Evento de medição (EV_EVENTO:<monitoramento>:<evento>) abre os
                // eventos do monitoramento; potencial da IA abre a análise; o resto, o registro de origem ou a Agenda
                $codigo = $orig ? "COALESCE(NULLIF(ag.`origem_id`, ''), ag.`codeventoagenda`)" : 'ag.`codeventoagenda`';
                if ($chave) $codigo = "(CASE WHEN ag.`chave_idempotencia` LIKE 'EV\\_EVENTO:%' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(ag.`chave_idempotencia`, ':', 2), ':', -1) ELSE $codigo END)";
                if ($orig && $tem('ml_potenciais', 'codpotencial', 'codsolicitacao'))
                    $codigo = "(CASE WHEN ag.`origem_entidade` = 'ml_potenciais' THEN (SELECT mp.`codsolicitacao` FROM `ml_potenciais` mp WHERE mp.`codpotencial` = ag.`origem_id` LIMIT 1) ELSE $codigo END)";
                $origem = $orig ? "COALESCE(ag.`origem_entidade`, '')" : "''";
                if ($chave) $origem = "(CASE WHEN ag.`chave_idempotencia` LIKE 'EV\\_EVENTO:%' THEN 'ev_eventos' ELSE $origem END)";
                $p['AGENDA'] = self::prazo('ag.`codusuario_responsavel`', 'AGENDA', $codigo, $origem, 'ag.`titulo`', $det, $P,
                    "`agenda_eventos` ag\n WHERE ag.`codusuario_responsavel` > 0 AND " . self::t('ag.`status`') . " = 'ATIVO' AND " . self::janela($P));
            } else $fora['AGENDA'] = 'sem agenda_eventos';
            return [$p, [], $fora];
        }

        // ---------------------------------------------------------------- APROVAÇÕES
        if ($tem('ml_potenciais', 'codsolicitacao', 'status') && $tem('ml_potenciais_solicitacoes', 'codsolicitacao', 'codusuario', 'status')) {
            $quando = $tem('ml_potenciais_solicitacoes', 'concluida_em') ? 'MAX(s.`concluida_em`)' : 'NULL';
            $a['IA'] = self::aprov('s.`codusuario`', 'IA', 's.`codsolicitacao`', "''", "CONCAT(COUNT(*), IF(COUNT(*) = 1, ' sugestão da IA para avaliar', ' sugestões da IA para avaliar'))",
                "CONCAT('Análise por IA nº ', s.`codsolicitacao`)", $quando, 'MODULOLEGAL_POTENCIAIS', 'pode_editar',
                "`ml_potenciais` pt\n  JOIN `ml_potenciais_solicitacoes` s ON s.`codsolicitacao` = pt.`codsolicitacao`\n WHERE " . self::t('pt.`status`') . " = 'SUGERIDO' AND s.`codusuario` > 0\n GROUP BY s.`codsolicitacao`, s.`codusuario`");
        } else $fora['IA'] = 'sem ml_potenciais';

        if ($tem('nc_indicacao_ocorrencia', 'CODOCORRENCIA', 'ETAPA', 'CODUSUARIO_GESTOR')) {
            $c = static fn(string $col, string $padrao = "''") => $tem('nc_indicacao_ocorrencia', $col) ? "CONVERT(o.`$col` USING utf8mb4)" : $padrao;
            $a['NC_GESTOR'] = self::aprov('o.`CODUSUARIO_GESTOR`', 'NC_GESTOR', 'o.`CODOCORRENCIA`', $c('CODTIPONAOCONFORMIDADE', '0'),
                "CONCAT_WS(' · ', NULLIF(" . $c('NRANO') . ", ''), COALESCE(NULLIF(LEFT(" . $c('IDENTIFICACAO') . ", 160), ''), CONCAT('Ocorrência ', o.`CODOCORRENCIA`)))",
                "'Não conformidade aguardando a deliberação do gestor'", $c('DATA_ABERTURA', 'NULL'), '', '',
                "`nc_indicacao_ocorrencia` o\n WHERE o.`CODUSUARIO_GESTOR` > 0 AND " . self::t('o.`ETAPA`') . " = 'Com o Gestor'");
        } else $fora['NC_GESTOR'] = 'sem nc_indicacao_ocorrencia';

        if ($tem('vw_perfil_atribuicoes_situacao', 'codgestor', 'ultimo_status', 'ultimo_codperfilatrib')) {
            $nome = $tem('vw_perfil_atribuicoes_situacao', 'usuario_nome') ? "COALESCE(NULLIF(TRIM(ps.`usuario_nome`), ''), ps.`usuario_login`)" : "CONCAT('Usuário ', ps.`codusuario`)";
            $a['PERFIL_ATRIBUICOES'] = self::aprov('ps.`codgestor`', 'PERFIL_ATRIBUICOES', 'ps.`ultimo_codperfilatrib`', 'ps.`codusuario`', "CONCAT('Perfil de atribuições de ', $nome)",
                "'Envio aguardando a sua aprovação'", ($tem('vw_perfil_atribuicoes_situacao', 'ultimo_enviado_em') ? 'ps.`ultimo_enviado_em`' : 'NULL'), '', '',
                "`vw_perfil_atribuicoes_situacao` ps\n WHERE ps.`codgestor` > 0 AND " . self::t('ps.`ultimo_status`') . " = 'PENDENTE_APROVACAO'");
        } else $fora['PERFIL_ATRIBUICOES'] = 'sem vw_perfil_atribuicoes_situacao (migração 268)';

        foreach (['SUBSTITUICAO' => ['responsabilidade_substituicoes', 'CODSUBSTITUICAO', 'Substituição de responsabilidade aguardando aprovação'],
                  'DELEGACAO' => ['responsabilidade_delegacoes', 'CODDELEGACAO', 'Delegação de responsabilidade aguardando aprovação']] as $tipo => [$tab, $pk, $txt]) {
            if (!$tem($tab, $pk, 'STATUS')) { $fora[$tipo] = "sem $tab"; continue; }
            $motivo = $tem($tab, 'MOTIVO') ? "COALESCE(NULLIF(LEFT(TRIM(CONVERT(x.`MOTIVO` USING utf8mb4)), 160), ''), '$txt')" : "'$txt'";
            $periodo = $tem($tab, 'VALIDO_DE', 'VALIDO_ATE') ? "CONCAT('Período ', DATE_FORMAT(x.`VALIDO_DE`, '%d/%m/%Y'), ' a ', COALESCE(DATE_FORMAT(x.`VALIDO_ATE`, '%d/%m/%Y'), '—'))" : "''";
            $a[$tipo] = self::aprov('0', $tipo, "x.`$pk`", "''", $motivo, $periodo, ($tem($tab, 'CRIADO_EM') ? 'x.`CRIADO_EM`' : 'NULL'), 'RESPONSABILIDADE_SUBSTITUICAO', 'pode_editar',
                "`$tab` x\n WHERE " . self::t('x.`STATUS`') . " = 'PENDENTE'");
        }

        $docs = $tem('fo_fornecedorxcriterios', 'CODFORNECEDOR', 'SITUACAO') ? 'fo_fornecedorxcriterios' : null;
        if ($docs && $tem('fo_fornecedores', 'CODFORNECEDOR', 'CODUSUARIO_RESPONSAVEL_FORNECEDOR', 'RAZAOSOCIAL')) {
            $a['FORNECEDOR_DOC'] = self::aprov('f.`CODUSUARIO_RESPONSAVEL_FORNECEDOR`', 'FORNECEDOR_DOC', 'f.`CODFORNECEDOR`', "''", 'f.`RAZAOSOCIAL`',
                "CONCAT(COUNT(*), IF(COUNT(*) = 1, ' documento pendente com a empresa', ' documentos pendentes com a empresa'))", 'NULL', '', '',
                "`fo_fornecedorxcriterios` dc\n  JOIN `fo_fornecedores` f ON f.`CODFORNECEDOR` = dc.`CODFORNECEDOR`\n WHERE f.`CODUSUARIO_RESPONSAVEL_FORNECEDOR` > 0 AND " . self::t('dc.`SITUACAO`') . " = 'Pendente com a empresa'\n GROUP BY f.`CODFORNECEDOR`, f.`CODUSUARIO_RESPONSAVEL_FORNECEDOR`, f.`RAZAOSOCIAL`");
        } else $fora['FORNECEDOR_DOC'] = 'sem fo_fornecedorxcriterios / fo_fornecedores';

        if ($tem('workflow_instancias', 'codinstancia', 'codusuario_responsavel', 'status', 'etapa_atual', 'codworkflow') && $tem('workflow_definicoes', 'codworkflow', 'codigo')) {
            $nomeWf = $tem('workflow_definicoes', 'nome') ? "COALESCE(NULLIF(CONVERT(wd.`nome` USING utf8mb4), ''), CONVERT(wd.`codigo` USING utf8mb4))" : 'wd.`codigo`';
            $a['WORKFLOW'] = self::aprov('wi.`codusuario_responsavel`', 'WORKFLOW', 'wi.`codinstancia`', ($tem('workflow_instancias', 'origem_entidade') ? 'wi.`origem_entidade`' : "''"),
                $nomeWf, "CONCAT('Etapa: ', COALESCE(CONVERT(wi.`etapa_atual` USING utf8mb4), ''))", ($tem('workflow_instancias', 'criado_em') ? 'wi.`criado_em`' : 'NULL'), '', '',
                "`workflow_instancias` wi\n  JOIN `workflow_definicoes` wd ON wd.`codworkflow` = wi.`codworkflow`\n WHERE wi.`codusuario_responsavel` > 0 AND " . self::t('wi.`status`') . " NOT IN ('CONCLUIDA', 'CANCELADA')");
        } else $fora['WORKFLOW'] = 'sem workflow_instancias';

        return [$p, $a, $fora];
    }
}
