<?php
declare(strict_types=1);

/*
 * Migração 285 — views do dashboard da empresa em abas (principal/dashboard_admin.php), para ADMINISTRADOR e ADM DA EMPRESA.
 * Substituem as consultas diretas que o dashboard fazia e alimentam as abas novas:
 *
 *   vw_dash_visao_geral     1 linha: usuários ativos, unidades, perfis, permissões, requisitos, licenças em atenção, jobs, alertas
 *   vw_dash_integridade     1 linha: requisitos sem unidade, sem tratamento (sem avaliação e sem evidência), licenças vencidas,
 *                           situações (avaliações) não atendidas
 *   vw_dash_ia_status       solicitações de IA (Potenciais) por status
 *   vw_dash_legal_unidade   aba Atendimento legal: por unidade, situação consolidada, sem evidências, divergências e áreas
 *                           (sobre vw_modulolegal_requisitos_situacao_grid da migração 284; leis revogadas fora)
 *   vw_dash_licencas        aba Licenças e entregas: versão ativa de cada licença com a faixa (vencida, em protocolo, vence em
 *                           30/60/90 dias, entrega periódica atrasada...), a natureza do tipo documental (migração 284) e a
 *                           situação Válida / Válida (em protocolo) / Vencida (classes/LicencaSituacao.php, migração 288):
 *                           EM_PROTOCOLO = vencida com a renovação protocolada no prazo; protocolada fora do prazo = VENCIDA;
 *                           PROT_SEM_PRAZO = marcada em protocolo, sem as datas para saber o prazo (decisão de 27/10/2026)
 *   vw_dash_equipe          aba Pendências e equipe: por usuário ativo, perfil de atribuições, licenças e medições sob
 *                           responsabilidade que pedem ação
 *
 * Cada view só é criada quando as tabelas/views de que depende existem (o relatório informa as que ficaram de fora).
 * Rodar depois da migração 284. Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-285-dashboard-abas.php [--simular] [--banco=gatlas_dbp]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/LicencaSituacao.php';

const M285_REGEXP_PERIODICO = 'RAPP|RELAT.RIO ANUAL|INVENT.RIO|DECLARA..O ANUAL|MAPA DE PRODUTOS|MAPA MENSAL|MAPA TRIMESTRAL|(^|[^A-Z])DMR([^A-Z]|$)';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$relatorio = ['ok' => true, 'migracao' => '285', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes', 'usuarios') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && preg_match('/^g[a-z0-9_]+_dbp$/', (string)$b) && ($soBanco === null || $b === $soBanco)));

foreach ($bancos as $banco) {
    $info = ['views' => [], 'nao_criadas' => []];
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
        foreach (M285Views::montar($tem) as $nome => [$ddl, $falta]) {
            if ($falta !== null) { $info['nao_criadas'][$nome] = $falta; continue; }
            if (!$simular) $pdo->exec($ddl);
            $info['views'][] = $nome;
        }
        if (!$simular) {
            if ($criadas = MigracaoCrud::garantir($pdo)) $info['procedures_crud_criadas'] = $criadas;
            // Conferência: cada view criada responde
            foreach ($info['views'] as $v) {
                $st = $pdo->prepare('CALL sp_crud_select(:t, NULL, NULL, 1, 0)');
                $st->execute(['t' => $v]); $st->fetchAll(); $st->closeCursor();
            }
        }
        $info['views'] = count($info['views']) . ' gravadas';
        if (!$info['nao_criadas']) unset($info['nao_criadas']);
    } catch (Throwable $e) { $relatorio['ok'] = false; $info['erro'] = $e->getMessage(); }
    $relatorio['bancos'][$banco] = $info;
}
echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);

final class M285Views
{
    private static function d(string $e): string { return "(CASE WHEN YEAR($e) > 0 THEN DATE($e) END)"; }
    private static function sim(string $e): string { return "(UPPER(TRIM(COALESCE($e, ''))) IN ('S', 'SIM', '1', 'Y', 'YES', 'X'))"; }

    /** [nome => [DDL, motivo de não criar | null]] */
    public static function montar(callable $tem): array
    {
        $v = [];
        // ---------------------------------------------------------------- Licenças e entregas
        if (!$tem('li_licencas', 'CODLICENCA', 'DATA_VALIDADE')) $v['vw_dash_licencas'] = ['', 'sem li_licencas'];
        else {
            $c = static fn(string $col, string $padrao = 'NULL'): string => $tem('li_licencas', $col) ? "l.`$col`" : $padrao;
            $V = self::d('l.`DATA_VALIDADE`'); $R = self::d($c('DATA_REACAO')); $PE = self::d($c('PROTOCOLADO_EM'));
            $emProt = self::sim($c('EMPROTOCOLO'));
            $joinTipo = ''; $natureza = "'LICENCA'"; $tipoNome = "''";
            $porNome = $tem('li_licencas', 'DSCLICENCA')
                ? "(UPPER(CONVERT(COALESCE(l.`DSCLICENCA`, '') USING utf8mb4)) COLLATE utf8mb4_unicode_ci REGEXP '" . M285_REGEXP_PERIODICO . "')" : 'FALSE';
            $periodico = $porNome;
            if ($tem('li_licencas', 'CODTIPODOCUMENTO') && $tem('li_tipos_documentos', 'CODTIPODOCUMENTO', 'DSCTIPODOCUMENTO')) {
                $joinTipo = "\n  LEFT JOIN `li_tipos_documentos` td ON td.`CODTIPODOCUMENTO` = l.`CODTIPODOCUMENTO`";
                $tipoNome = 'COALESCE(td.`DSCTIPODOCUMENTO`, \'\')';
                if ($tem('li_tipos_documentos', 'NATUREZA'))
                    $periodico = "(CASE WHEN td.`CODTIPODOCUMENTO` IS NULL OR UPPER(td.`DSCTIPODOCUMENTO`) LIKE 'N%O CLASSIFICADO%' THEN $porNome ELSE UPPER(td.`NATUREZA`) = 'ENTREGA_PERIODICA' END)";
            }
            $joinUnid = $tem('unidades', 'CODUNIDADE', 'DSCUNIDADE') && $tem('li_licencas', 'codunidade') ? "\n  LEFT JOIN `unidades` u ON u.`CODUNIDADE` = l.`codunidade`" : '';
            $unidNome = $joinUnid ? "COALESCE(u.`DSCUNIDADE`, '')" : "''";
            $joinResp = $tem('li_licencas', 'CODUSUARIO') && $tem('usuarios', 'codusuario', 'nome') ? "\n  LEFT JOIN `usuarios` r ON r.`codusuario` = l.`CODUSUARIO`" : '';
            $respNome = $joinResp ? "COALESCE(NULLIF(TRIM(r.`nome`), ''), r.`userid`, '')" : "''";
            $onde = $tem('li_licencas', 'STATUS_VERSAO') ? "\n WHERE COALESCE(l.`STATUS_VERSAO`, 'ATIVA') = 'ATIVA'" : '';
            $sit = LicencaSituacao::sql($c, $periodico);
            // Migração 415: validade recorrente (próximo ciclo), "Validade inexistente" e licenças arquivadas (fora do painel)
            $V = $sit['validade'];
            $onde = ($onde ? $onde . ' AND ' : "\n WHERE ") . "NOT {$sit['arquivada']}";
            $faixa = "CASE WHEN $periodico THEN
                    CASE WHEN $V IS NULL THEN 'ENTREGA_SEM_PRAZO'
                         WHEN $V < CURDATE() AND $PE IS NULL THEN 'ENTREGA_ATRASADA'
                         WHEN $V < CURDATE() THEN 'ENTREGA_REALIZADA'
                         WHEN $V <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'ENTREGA_30'
                         ELSE 'ENTREGA_EM_DIA' END
                 WHEN {$sit['nao_vence']} THEN 'NAO_VENCE'
                 WHEN $V IS NULL THEN 'SEM_VALIDADE'
                 WHEN $V < CURDATE() AND {$sit['situacao']} = 'PROTOCOLO' THEN 'EM_PROTOCOLO'
                 WHEN $V < CURDATE() AND {$sit['situacao']} = 'PROT_SEM_PRAZO' THEN 'PROT_SEM_PRAZO'
                 WHEN $V < CURDATE() THEN 'VENCIDA'
                 WHEN $V <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'VENCE_30'
                 WHEN $V <= DATE_ADD(CURDATE(), INTERVAL 60 DAY) THEN 'VENCE_60'
                 WHEN $V <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN 'VENCE_90'
                 ELSE 'VIGENTE' END";
            $v['vw_dash_licencas'] = ["CREATE OR REPLACE VIEW `vw_dash_licencas` AS
SELECT l.`CODLICENCA`, " . $c('codunidade', '0') . " AS `CODUNIDADE`, $unidNome AS `UNIDADE`,
       COALESCE(" . $c('DSCLICENCA', "''") . ", '') AS `LICENCA`, $tipoNome AS `TIPO_DOCUMENTAL`,
       IF($periodico, 'ENTREGA_PERIODICA', 'LICENCA') AS `NATUREZA`,
       $V AS `DATA_VALIDADE`, $R AS `DATA_REACAO`, $PE AS `PROTOCOLADO_EM`, IF($emProt, 'S', 'N') AS `EM_PROTOCOLO`,
       " . $c('CODUSUARIO', '0') . " AS `CODUSUARIO`, $respNome AS `RESPONSAVEL`, $faixa AS `FAIXA`, DATEDIFF($V, CURDATE()) AS `DIAS`,
       {$sit['situacao']} AS `SITUACAO_LICENCA`, {$sit['rotulo']} AS `SITUACAO_LICENCA_DESC`, {$sit['motivo']} AS `MOTIVO_SITUACAO`,
       {$sit['numero']} AS `NUMERO_PROTOCOLO`, {$sit['no_prazo']} AS `PROTOCOLO_NO_PRAZO`
  FROM `li_licencas` l$joinTipo$joinUnid$joinResp$onde", null];
        }

        // Os demais painéis contam licenças por esta view, para os números baterem entre as abas
        $licOk = isset($v['vw_dash_licencas']) && $v['vw_dash_licencas'][1] === null;
        $faixas = static fn(string ...$f): string => "(SELECT COUNT(*) FROM `vw_dash_licencas` WHERE `FAIXA` IN ('" . implode("', '", $f) . "'))";

        // ---------------------------------------------------------------- Visão geral
        $req = ['usuarios', 'unidades', 'perfis', 'permissoes', 'legislacaoxempresa', 'li_licencas', 'exportacoes_grid', 'alertas'];
        $falta = array_values(array_filter($req, static fn($t) => !$tem($t)));
        $versao = $tem('li_licencas', 'STATUS_VERSAO') ? " AND COALESCE(`STATUS_VERSAO`, 'ATIVA') = 'ATIVA'" : '';
        $v['vw_dash_visao_geral'] = ["CREATE OR REPLACE VIEW `vw_dash_visao_geral` AS
SELECT (SELECT COUNT(*) FROM `usuarios` WHERE `ativo` = 1) AS `USUARIOS_ATIVOS`,
       (SELECT COUNT(*) FROM `unidades`) AS `UNIDADES`,
       (SELECT COUNT(*) FROM `perfis` WHERE `ativo` = 1) AS `PERFIS_ATIVOS`,
       (SELECT COUNT(*) FROM `permissoes`) AS `PERMISSOES`,
       (SELECT COUNT(DISTINCT `CODLEGISLACAO`) FROM `legislacaoxempresa`) AS `REQUISITOS`,
       " . ($licOk ? $faixas('VENCIDA', 'VENCE_30', 'ENTREGA_ATRASADA', 'ENTREGA_30', 'PROT_SEM_PRAZO') : "(SELECT COUNT(*) FROM `li_licencas` WHERE YEAR(`DATA_VALIDADE`) > 0
            AND `DATA_VALIDADE` <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)$versao)") . " AS `LICENCAS_ATENCAO`,
       (SELECT COUNT(*) FROM `exportacoes_grid` WHERE `status` IN ('SOLICITADA', 'PROCESSANDO')) AS `JOBS_PENDENTES`,
       (SELECT COUNT(*) FROM `alertas` WHERE COALESCE(`lido`, 0) = 0) AS `ALERTAS_NAO_LIDOS`",
            $falta ? 'sem ' . implode(', ', $falta) : null];

        // ---------------------------------------------------------------- Integridade
        $falta = array_values(array_filter(['legislacaoxempresa', 'legislacaoxsituacao', 'li_licencas', 'situacao_legal_calculada'], static fn($t) => !$tem($t)));
        $v['vw_dash_integridade'] = ["CREATE OR REPLACE VIEW `vw_dash_integridade` AS
SELECT (SELECT COUNT(*) FROM `legislacaoxempresa` WHERE `codunidade` IS NULL OR `codunidade` = 0) AS `REQUISITOS_SEM_UNIDADE`,
       (SELECT COUNT(*) FROM `legislacaoxempresa` x
         WHERE NOT EXISTS (SELECT 1 FROM `legislacaoxsituacao` s WHERE s.`CODLEGISLACAO` = x.`CODLEGISLACAO` AND s.`codunidade` = x.`codunidade`)
           AND NOT EXISTS (SELECT 1 FROM `situacao_legal_calculada` c WHERE c.`CODLEGISLACAO` = x.`CODLEGISLACAO` AND c.`CODUNIDADE` = x.`codunidade` AND c.`situacao` <> 'AI')) AS `REQUISITOS_SEM_TRATAMENTO`,
       " . ($licOk ? $faixas('VENCIDA') : "(SELECT COUNT(*) FROM `li_licencas` WHERE YEAR(`DATA_VALIDADE`) > 0 AND `DATA_VALIDADE` < CURDATE()$versao)") . " AS `LICENCAS_VENCIDAS`,
       (SELECT COUNT(*) FROM `legislacaoxsituacao` WHERE COALESCE(UPPER(TRIM(`atendido`)), '') <> 'S') AS `SITUACOES_PENDENTES`",
            $falta ? 'sem ' . implode(', ', $falta) : null];

        // ---------------------------------------------------------------- IA
        $v['vw_dash_ia_status'] = ["CREATE OR REPLACE VIEW `vw_dash_ia_status` AS
SELECT COALESCE(`status`, 'SEM_STATUS') AS `STATUS`, COUNT(*) AS `TOTAL` FROM `ml_potenciais_solicitacoes` GROUP BY 1",
            $tem('ml_potenciais_solicitacoes', 'status') ? null : 'sem ml_potenciais_solicitacoes'];

        // ---------------------------------------------------------------- Atendimento legal por unidade
        $g = 'vw_modulolegal_requisitos_situacao_grid';
        $okLegal = $tem($g, 'CODUNIDADE', 'SITUACAO_CONSOLIDADA', 'SITUACAO_CALCULADA', 'DIVERGENCIA');
        $txt = static fn(string $c): string => "CONVERT(COALESCE(g.`$c`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci";
        $sit = static fn(string $t): string => "SUM(" . $txt('SITUACAO_CONSOLIDADA') . " = '$t')";
        $areaCols = '';
        foreach (['MEIO_AMBIENTE' => 'MA', 'SEGURANCA' => 'SEG', 'QUALIDADE' => 'QUA', 'RESPOSABILIDADE' => 'SOC'] as $col => $p) {
            if (!$tem($g, $col)) { $areaCols .= ", 0 AS `{$p}_TOTAL`, 0 AS `{$p}_S`, 0 AS `{$p}_PA`, 0 AS `{$p}_N`"; continue; }
            $na = "(" . $txt($col) . " = 'Sim')";
            $areaCols .= ", SUM($na) AS `{$p}_TOTAL`";
            foreach (['S' => 'Atendido', 'PA' => 'Parcialmente atendido', 'N' => 'Não atendido'] as $k => $t)
                $areaCols .= ", SUM($na AND " . $txt('SITUACAO_CONSOLIDADA') . " = '$t') AS `{$p}_$k`";
        }
        $unidade = $tem($g, 'UNIDADE') ? 'MAX(g.`UNIDADE`)' : ($tem($g, 'DSCUNIDADE') ? 'MAX(g.`DSCUNIDADE`)' : "CONCAT('Unidade ', g.`CODUNIDADE`)");
        $revogada = $tem($g, 'LEI_REVOGADA') ? "WHERE UPPER(" . $txt('LEI_REVOGADA') . ") NOT IN ('SIM', 'S')" : '';
        $v['vw_dash_legal_unidade'] = ["CREATE OR REPLACE VIEW `vw_dash_legal_unidade` AS
SELECT g.`CODUNIDADE`, $unidade AS `UNIDADE`, COUNT(*) AS `TOTAL`,
       {$sit('Atendido')} AS `S`, {$sit('Parcialmente atendido')} AS `PA`, {$sit('Não atendido')} AS `N`,
       COUNT(*) - {$sit('Atendido')} - {$sit('Parcialmente atendido')} - {$sit('Não atendido')} AS `AI`,
       SUM(" . $txt('SITUACAO_CALCULADA') . " IN ('', 'Sem evidências vinculadas')) AS `SEM_EVIDENCIA`,
       SUM(" . $txt('DIVERGENCIA') . " = 'Sim') AS `DIVERGENTES`$areaCols
  FROM `$g` g
 $revogada
 GROUP BY g.`CODUNIDADE`", $okLegal ? null : 'sem vw_modulolegal_requisitos_situacao_grid (rode antes a migração 284)'];

        // ---------------------------------------------------------------- Pendências e equipe
        if (!$tem('vw_perfil_atribuicoes_situacao', 'codusuario', 'ultimo_status')) $v['vw_dash_equipe'] = ['', 'sem vw_perfil_atribuicoes_situacao (migração 268)'];
        else {
            $joins = ''; $cols = '';
            if ($licOk) {
                $joins .= "\n  LEFT JOIN (SELECT l.`CODUSUARIO`, COUNT(*) AS `LIC`,
                    SUM(l.`FAIXA` IN ('VENCIDA', 'ENTREGA_ATRASADA')) AS `LIC_VENCIDAS`,
                    SUM(l.`FAIXA` IN ('VENCE_30', 'ENTREGA_30')) AS `LIC_30`
               FROM `vw_dash_licencas` l GROUP BY l.`CODUSUARIO`) lic ON lic.`CODUSUARIO` = s.`codusuario`";
                $cols .= ', COALESCE(lic.`LIC`, 0) AS `LICENCAS`, COALESCE(lic.`LIC_VENCIDAS`, 0) AS `LICENCAS_VENCIDAS`, COALESCE(lic.`LIC_30`, 0) AS `LICENCAS_30`';
            } else $cols .= ', 0 AS `LICENCAS`, 0 AS `LICENCAS_VENCIDAS`, 0 AS `LICENCAS_30`';
            if ($tem('vw_minhas_monitoramentos_grid', 'CODUSUARIO', 'CODMONITORAMENTO', 'DATA_REACAO')) {
                $DR = self::d('m.`DATA_REACAO`');
                $joins .= "\n  LEFT JOIN (SELECT m.`CODUSUARIO`, COUNT(DISTINCT m.`CODMONITORAMENTO`) AS `MED`,
                    COUNT(DISTINCT CASE WHEN $DR < CURDATE() THEN m.`CODMONITORAMENTO` END) AS `MED_ATRASADAS`
               FROM `vw_minhas_monitoramentos_grid` m GROUP BY m.`CODUSUARIO`) med ON med.`CODUSUARIO` = s.`codusuario`";
                $cols .= ', COALESCE(med.`MED`, 0) AS `MEDICOES`, COALESCE(med.`MED_ATRASADAS`, 0) AS `MEDICOES_ATRASADAS`';
            } else $cols .= ', 0 AS `MEDICOES`, 0 AS `MEDICOES_ATRASADAS`';
            $aplica = $tem('vw_perfil_atribuicoes_situacao', 'aplicar_questionario') ? 's.`aplicar_questionario`' : '1';
            $perfilCod = $tem('vw_perfil_atribuicoes_situacao', 'perfil_codigo') ? 's.`perfil_codigo`' : "''";
            $v['vw_dash_equipe'] = ["CREATE OR REPLACE VIEW `vw_dash_equipe` AS
SELECT s.`codusuario` AS `CODUSUARIO`, COALESCE(NULLIF(TRIM(s.`usuario_nome`), ''), s.`usuario_login`) AS `USUARIO`,
       COALESCE(s.`gestor_nome`, '') AS `GESTOR`, $perfilCod AS `PERFIL`,
       CASE WHEN $aplica = 0 THEN 'NAO_APLICA'
            WHEN s.`vigente_codperfilatrib` IS NOT NULL AND s.`valido_ate` IS NOT NULL AND s.`valido_ate` < CURDATE() THEN 'VENCIDO'
            WHEN CONVERT(COALESCE(s.`ultimo_status`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci = 'DEVOLVIDO' THEN 'DEVOLVIDO'
            WHEN CONVERT(COALESCE(s.`ultimo_status`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci = 'PENDENTE_APROVACAO' THEN 'PENDENTE_APROVACAO'
            WHEN s.`vigente_codperfilatrib` IS NOT NULL THEN 'APROVADO'
            ELSE 'SEM_ENVIO' END AS `PERFIL_ATRIBUICOES`,
       s.`valido_ate` AS `PERFIL_VALIDO_ATE`, COALESCE(s.`ultimo_codperfilatrib`, s.`vigente_codperfilatrib`) AS `ULTIMO_CODPERFILATRIB`$cols
  FROM `vw_perfil_atribuicoes_situacao` s$joins", null];
        }
        return $v;
    }
}
