<?php
declare(strict_types=1);

/*
 * Migração 423 — Módulo de Auditoria (Revisão G, itens 166 a 177; 27/11/2026).
 *
 * Em cada empresa (g*_dbp):
 *   Tabelas do legado (Green 3), com a mesma estrutura, para o sincronismo diário legado -> greenportal (prefixo auditoria_):
 *     auditoria_planejamento, auditoria_planejamentoxavaliacao, auditoria_planejamentoxexecucao, auditoria_planodeacao,
 *     auditoria_workflow. Chave primária com AUTO_INCREMENT a partir de 50001 (faixa do greenportal: o sincronismo não apaga nem
 *     altera o que for criado aqui — SincronismoLegado::FAIXAS_NOVO).
 *   Tabelas novas do greenportal (prefixo aud_, fora do sincronismo):
 *     aud_info ........ unidade, tipo (interna, externa, fornecedor, cliente), norma/referência, escopo, fornecedor, origem da RNC
 *     aud_checklist ... perguntas da auditoria (IA a partir da norma ou manuais), resposta, evidência
 *     aud_constatacao . constatações (NC maior, NC menor, observação, oportunidade de melhoria) e a RNC aberta para cada NC
 *   Views: vw_aud_auditorias_grid, vw_aud_avaliacao, vw_aud_execucao, vw_aud_workflow, vw_aud_checklist, vw_aud_constatacao,
 *          vw_aud_rnc (RNCs com CODAUDITORIA), vw_aud_info.
 * Universal (guniversal_dbp):
 *   módulo comercial AUDITORIA (modulos_sistema) ligado nas empresas que têm Não Conformidades ligado;
 *   menu AUDITORIA (grupo, ao lado de Não Conformidades) com HOME_AUDITORIA, AUDITORIA_GESTAO e MINHAS_AUDITORIAS;
 *   permissões copiadas da Home de NC, da Lista geral de ocorrências e de Minhas ocorrências.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-423-auditoria.php [--simular] [--banco=gcrown_dbp]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$rel = ['ok' => true, 'migracao' => '423', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal' => [], 'menus' => [], 'bancos' => []];
$CS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

// tabela do legado => [chave, CREATE TABLE]
$legado = [
    'auditoria_planejamento' => ['CODAUDITORIA', "CREATE TABLE IF NOT EXISTS `auditoria_planejamento` (`CODAUDITORIA` INT NOT NULL AUTO_INCREMENT, `DSCAUDITORIA` VARCHAR(500) NULL,
        `OBJETIVO` VARCHAR(5000) NULL, `datadeinicio` DATE NULL, `datadefim` DATE NULL, `localizacao` VARCHAR(500) NULL, `datadecriacao` DATETIME NULL,
        `codusuario_avaliador` INT NULL, `codgrupo_avaliadores` INT NULL, `codusuario_auditor` INT NULL, `codgrupo_auditores` INT NULL, `codusuario_criador` INT NULL,
        `status` VARCHAR(100) NULL, PRIMARY KEY (`CODAUDITORIA`), KEY `ix_audp_status` (`status`)) AUTO_INCREMENT=50001 $CS"],
    'auditoria_planejamentoxavaliacao' => ['CODAVALIACAO', "CREATE TABLE IF NOT EXISTS `auditoria_planejamentoxavaliacao` (`CODAVALIACAO` INT NOT NULL AUTO_INCREMENT, `CODAUDITORIA` INT NULL,
        `DSCAVALIACAO` VARCHAR(500) NULL, `DATAAVALIACAO` DATE NULL, `codusuario_avaliador` INT NULL, PRIMARY KEY (`CODAVALIACAO`), KEY `ix_audav_aud` (`CODAUDITORIA`)) AUTO_INCREMENT=50001 $CS"],
    'auditoria_planejamentoxexecucao' => ['CODEXECUCAO', "CREATE TABLE IF NOT EXISTS `auditoria_planejamentoxexecucao` (`CODEXECUCAO` INT NOT NULL AUTO_INCREMENT, `CODAUDITORIA` INT NULL,
        `OBSERVACAO` VARCHAR(5000) NULL, `DATAEXECUCAO` DATE NULL, `DATAENCERRAMENTO` DATE NULL, `codusuario_auditor` INT NULL, PRIMARY KEY (`CODEXECUCAO`), KEY `ix_audex_aud` (`CODAUDITORIA`)) AUTO_INCREMENT=50001 $CS"],
    'auditoria_planodeacao' => ['CODPLANOACAO', "CREATE TABLE IF NOT EXISTS `auditoria_planodeacao` (`CODPLANOACAO` INT NOT NULL AUTO_INCREMENT, `CODAUDITORIA` INT NULL, `ACAO` VARCHAR(5000) NULL,
        `PRIORIDADE` VARCHAR(500) NULL, `CODUSUARIO` INT NULL, `DATAINICIAL` DATE NULL, `DATAFINAL` DATE NULL, `RECURSOS` VARCHAR(5000) NULL, `STATUS` INT NULL, `obs` VARCHAR(5000) NULL,
        PRIMARY KEY (`CODPLANOACAO`), KEY `ix_audpa_aud` (`CODAUDITORIA`)) AUTO_INCREMENT=50001 $CS"],
    'auditoria_workflow' => ['CODWORKFLOW', "CREATE TABLE IF NOT EXISTS `auditoria_workflow` (`CODWORKFLOW` INT NOT NULL AUTO_INCREMENT, `CODAUDITORIA` INT NULL, `ETAPA` VARCHAR(100) NULL,
        `DSCOBSERVACAO` VARCHAR(500) NULL, `DATAOCORRENCIA` DATETIME NULL, `CODUSUARIO` INT NULL, PRIMARY KEY (`CODWORKFLOW`), KEY `ix_audwf_aud` (`CODAUDITORIA`)) AUTO_INCREMENT=50001 $CS"],
];

$novas = [
    "CREATE TABLE IF NOT EXISTS `aud_info` (`CODAUDITORIA` INT NOT NULL, `CODUNIDADE` INT NULL, `TIPO` VARCHAR(12) NOT NULL DEFAULT 'INTERNA', `NORMA` VARCHAR(300) NULL,
      `ESCOPO` VARCHAR(5000) NULL, `CODFORNECEDOR` INT NULL, `CODORIGEMACAO` INT NULL, `CODUSUARIO` INT NULL, `ALTERADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`CODAUDITORIA`), KEY `ix_audinfo_unidade` (`CODUNIDADE`)) $CS",
    "CREATE TABLE IF NOT EXISTS `aud_checklist` (`CODCHECKLIST` INT NOT NULL AUTO_INCREMENT, `CODAUDITORIA` INT NOT NULL, `ORDEM` INT NOT NULL DEFAULT 0, `REQUISITO` VARCHAR(200) NULL,
      `PERGUNTA` VARCHAR(1000) NOT NULL, `ORIENTACAO` VARCHAR(2000) NULL, `RESPOSTA` VARCHAR(12) NULL, `EVIDENCIA` TEXT NULL, `ORIGEM` VARCHAR(10) NOT NULL DEFAULT 'MANUAL',
      `CODUSUARIO` INT NULL, `RESPONDIDO_EM` DATETIME NULL, `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`CODCHECKLIST`), KEY `ix_audck_aud` (`CODAUDITORIA`, `ORDEM`)) $CS",
    "CREATE TABLE IF NOT EXISTS `aud_constatacao` (`CODCONSTATACAO` INT NOT NULL AUTO_INCREMENT, `CODAUDITORIA` INT NOT NULL, `CODCHECKLIST` INT NULL, `CLASSIFICACAO` VARCHAR(12) NOT NULL,
      `REQUISITO` VARCHAR(200) NULL, `DESCRICAO` TEXT NOT NULL, `EVIDENCIA` TEXT NULL, `CODOCORRENCIA` INT NULL, `CODUSUARIO` INT NULL, `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`CODCONSTATACAO`), KEY `ix_audcs_aud` (`CODAUDITORIA`), KEY `ix_audcs_oc` (`CODOCORRENCIA`)) $CS",
];

$nome = static fn(string $a): string => "COALESCE(NULLIF(TRIM($a.`nome`),''), $a.`userid`)";
$views = static fn(bool $ncAud): array => [
    "CREATE OR REPLACE VIEW `vw_aud_info` AS SELECT * FROM `aud_info`",
    "CREATE OR REPLACE VIEW `vw_aud_avaliacao` AS SELECT a.`CODAVALIACAO`, a.`CODAUDITORIA`, a.`DSCAVALIACAO`, a.`DATAAVALIACAO`, a.`codusuario_avaliador` AS `CODUSUARIO_AVALIADOR`, " . $nome('u') . " AS `AVALIADOR`
       FROM `auditoria_planejamentoxavaliacao` a LEFT JOIN `usuarios` u ON u.`codusuario` = a.`codusuario_avaliador`",
    "CREATE OR REPLACE VIEW `vw_aud_execucao` AS SELECT x.`CODEXECUCAO`, x.`CODAUDITORIA`, x.`OBSERVACAO`, x.`DATAEXECUCAO`, x.`DATAENCERRAMENTO`, x.`codusuario_auditor` AS `CODUSUARIO_AUDITOR`, " . $nome('u') . " AS `AUDITOR`
       FROM `auditoria_planejamentoxexecucao` x LEFT JOIN `usuarios` u ON u.`codusuario` = x.`codusuario_auditor`",
    "CREATE OR REPLACE VIEW `vw_aud_workflow` AS SELECT w.`CODWORKFLOW`, w.`CODAUDITORIA`, w.`ETAPA`, w.`DSCOBSERVACAO`, w.`DATAOCORRENCIA`, w.`CODUSUARIO`, " . $nome('u') . " AS `USUARIO`
       FROM `auditoria_workflow` w LEFT JOIN `usuarios` u ON u.`codusuario` = w.`CODUSUARIO`",
    "CREATE OR REPLACE VIEW `vw_aud_checklist` AS SELECT c.*, " . $nome('u') . " AS `USUARIO` FROM `aud_checklist` c LEFT JOIN `usuarios` u ON u.`codusuario` = c.`CODUSUARIO`",
    $ncAud
      ? "CREATE OR REPLACE VIEW `vw_aud_rnc` AS SELECT o.`CODOCORRENCIA`, o.`codauditoria` AS `CODAUDITORIA`, o.`NRANO`, o.`IDENTIFICACAO`, o.`ETAPA`, o.`CODTIPONAOCONFORMIDADE`, o.`CODUNIDADE`,
           o.`CODUSUARIO_GESTOR`, o.`CODUSUARIO_EMISSOR`, o.`data_de_criacao` AS `DATA_CRIACAO`, o.`data_de_finalizacao` AS `DATA_FINALIZACAO`
           FROM `nc_indicacao_ocorrencia` o WHERE o.`codauditoria` IS NOT NULL AND o.`codauditoria` > 0"
      : "CREATE OR REPLACE VIEW `vw_aud_rnc` AS SELECT CAST(NULL AS SIGNED) AS `CODOCORRENCIA`, CAST(NULL AS SIGNED) AS `CODAUDITORIA`, CAST(NULL AS CHAR) AS `NRANO`, CAST(NULL AS CHAR) AS `IDENTIFICACAO`,
           CAST(NULL AS CHAR) AS `ETAPA`, CAST(NULL AS SIGNED) AS `CODTIPONAOCONFORMIDADE`, CAST(NULL AS SIGNED) AS `CODUNIDADE`, CAST(NULL AS SIGNED) AS `CODUSUARIO_GESTOR`,
           CAST(NULL AS SIGNED) AS `CODUSUARIO_EMISSOR`, CAST(NULL AS DATETIME) AS `DATA_CRIACAO`, CAST(NULL AS DATETIME) AS `DATA_FINALIZACAO` FROM DUAL WHERE 1 = 0",
    "CREATE OR REPLACE VIEW `vw_aud_constatacao` AS SELECT s.*, r.`NRANO` AS `RNC_NUMERO`, r.`ETAPA` AS `RNC_ETAPA`, " . $nome('u') . " AS `USUARIO`
       FROM `aud_constatacao` s LEFT JOIN `vw_aud_rnc` r ON r.`CODOCORRENCIA` = s.`CODOCORRENCIA` LEFT JOIN `usuarios` u ON u.`codusuario` = s.`CODUSUARIO`",
    "CREATE OR REPLACE VIEW `vw_aud_auditorias_grid` AS
     SELECT p.`CODAUDITORIA`, p.`DSCAUDITORIA`, p.`OBJETIVO`, p.`datadeinicio` AS `DATADEINICIO`, p.`datadefim` AS `DATADEFIM`, p.`localizacao` AS `LOCALIZACAO`, p.`datadecriacao` AS `DATADECRIACAO`,
            p.`codusuario_avaliador` AS `CODUSUARIO_AVALIADOR`, p.`codgrupo_avaliadores` AS `CODGRUPO_AVALIADORES`, p.`codusuario_auditor` AS `CODUSUARIO_AUDITOR`,
            p.`codgrupo_auditores` AS `CODGRUPO_AUDITORES`, p.`codusuario_criador` AS `CODUSUARIO_CRIADOR`, COALESCE(NULLIF(TRIM(p.`status`), ''), 'P') AS `STATUS`,
            CASE COALESCE(NULLIF(TRIM(p.`status`), ''), 'P') WHEN 'P' THEN 'Em Planejamento' WHEN 'A' THEN 'Em Avaliação' WHEN 'R' THEN 'Recusada' WHEN 'X' THEN 'Em Execução'
                 WHEN 'E' THEN 'Encerrada' WHEN 'C' THEN 'Cancelada' ELSE p.`status` END AS `SITUACAO`,
            i.`CODUNIDADE`, COALESCE(i.`TIPO`, 'INTERNA') AS `TIPO`, i.`NORMA`, i.`ESCOPO`, i.`CODFORNECEDOR`, i.`CODORIGEMACAO`,
            " . $nome('ua') . " AS `AVALIADOR`, " . $nome('uu') . " AS `AUDITOR`, " . $nome('uc') . " AS `CRIADOR`,
            (SELECT MAX(x.`DATAEXECUCAO`) FROM `auditoria_planejamentoxexecucao` x WHERE x.`CODAUDITORIA` = p.`CODAUDITORIA`) AS `DATAEXECUCAO`,
            (SELECT MAX(x.`DATAENCERRAMENTO`) FROM `auditoria_planejamentoxexecucao` x WHERE x.`CODAUDITORIA` = p.`CODAUDITORIA`) AS `DATAENCERRAMENTO`,
            (SELECT COUNT(*) FROM `aud_checklist` c WHERE c.`CODAUDITORIA` = p.`CODAUDITORIA`) AS `QTD_PERGUNTAS`,
            (SELECT COUNT(*) FROM `aud_checklist` c WHERE c.`CODAUDITORIA` = p.`CODAUDITORIA` AND COALESCE(c.`RESPOSTA`, '') <> '') AS `QTD_RESPONDIDAS`,
            (SELECT COUNT(*) FROM `aud_constatacao` s WHERE s.`CODAUDITORIA` = p.`CODAUDITORIA`) AS `QTD_CONSTATACOES`,
            (SELECT COUNT(*) FROM `aud_constatacao` s WHERE s.`CODAUDITORIA` = p.`CODAUDITORIA` AND s.`CLASSIFICACAO` IN ('NC_MAIOR', 'NC_MENOR')) AS `QTD_NC`,
            (SELECT COUNT(*) FROM `vw_aud_rnc` r WHERE r.`CODAUDITORIA` = p.`CODAUDITORIA`) AS `QTD_RNC`,
            (SELECT COUNT(*) FROM `vw_aud_rnc` r WHERE r.`CODAUDITORIA` = p.`CODAUDITORIA` AND COALESCE(r.`ETAPA`, '') <> 'Finalizado') AS `QTD_RNC_ABERTAS`,
            (SELECT MAX(w.`DATAOCORRENCIA`) FROM `auditoria_workflow` w WHERE w.`CODAUDITORIA` = p.`CODAUDITORIA`) AS `ULTIMO_MOVIMENTO`
       FROM `auditoria_planejamento` p
       LEFT JOIN `aud_info` i ON i.`CODAUDITORIA` = p.`CODAUDITORIA`
       LEFT JOIN `usuarios` ua ON ua.`codusuario` = p.`codusuario_avaliador`
       LEFT JOIN `usuarios` uu ON uu.`codusuario` = p.`codusuario_auditor`
       LEFT JOIN `usuarios` uc ON uc.`codusuario` = p.`codusuario_criador`",
];

// codigo => [pt, en, es, url, ícone, ordem, menu de onde copiar as permissões]
$menus = [
    'HOME_AUDITORIA' => ['Home de Auditoria', 'Audit home', 'Inicio de Auditoría', '/principal/home_auditoria.php', 'bi-house-door', 10, 'HOME_NAO_CONFORMIDADES'],
    'AUDITORIA_GESTAO' => ['Gestão de Auditorias', 'Audit management', 'Gestión de Auditorías', '/principal/auditoria_gestao.php', 'bi-clipboard2-check', 20, 'NAO_CONFORMIDADES_LISTA_OCORRENCIAS'],
    'MINHAS_AUDITORIAS' => ['Minhas Auditorias', 'My audits', 'Mis Auditorías', '/principal/minhas_auditorias.php', 'bi-person-check', 30, 'NAO_CONFORMIDADES_MINHAS_OCORRENCIAS'],
];

$cat = new MenuCatalogoService($env);
foreach ($cat->empresas() as $banco => $pdo) {
    if ($soBanco !== null && $banco !== $soBanco) continue;
    $info = [];
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ($legado as $t => [$pk, $sql]) {
            if (!MigracaoCrud::existe($pdo, $t)) { if (!$simular) $pdo->exec($sql); $info['legado'][$t] = $simular ? 'a criar' : 'criada'; continue; }
            // já existe (veio da migração do legado, ex.: Atlas): garante chave primária com AUTO_INCREMENT e o início da faixa do greenportal
            $temPk = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' AND INDEX_NAME = 'PRIMARY'")->fetchColumn();
            $ai = (string)$pdo->query("SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' AND COLUMN_NAME = '$pk'")->fetchColumn();
            $acoes = [];
            if (!$simular) {
                try {
                    if (!$temPk) { $pdo->exec("ALTER TABLE `$t` MODIFY `$pk` INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (`$pk`)"); $acoes[] = 'chave primária'; }
                    elseif (stripos($ai, 'auto_increment') === false) { $pdo->exec("ALTER TABLE `$t` MODIFY `$pk` INT NOT NULL AUTO_INCREMENT"); $acoes[] = 'auto_increment'; }
                    $max = (int)$pdo->query("SELECT COALESCE(MAX(`$pk`), 0) FROM `$t`")->fetchColumn();
                    if ($max < 50001) { $pdo->exec("ALTER TABLE `$t` AUTO_INCREMENT = 50001"); $acoes[] = 'faixa 50001'; }
                } catch (Throwable $e) { $info['avisos'][$t] = mb_substr($e->getMessage(), 0, 160); }
            }
            $info['legado'][$t] = 'já existia (' . (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() . ' linhas)' . ($acoes ? ': ' . implode(', ', $acoes) : '');
        }
        foreach ($novas as $sql) if (!$simular) $pdo->exec($sql);
        $ncAud = MigracaoCrud::existe($pdo, 'nc_indicacao_ocorrencia') && in_array('codauditoria', array_map('strtolower', MigracaoCrud::colunas($pdo, 'nc_indicacao_ocorrencia')), true);
        if (MigracaoCrud::existe($pdo, 'nc_indicacao_ocorrencia') && !$ncAud) {
            if (!$simular) { $pdo->exec('ALTER TABLE `nc_indicacao_ocorrencia` ADD COLUMN `codauditoria` INT NULL, ADD KEY `ix_nc_codauditoria` (`codauditoria`)'); $ncAud = true; }
            $info['nc_indicacao_ocorrencia'] = 'coluna codauditoria ' . ($simular ? 'a criar' : 'criada');
        } elseif ($ncAud && !$simular) {
            $temIdx = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nc_indicacao_ocorrencia' AND LOWER(COLUMN_NAME) = 'codauditoria' AND SEQ_IN_INDEX = 1")->fetchColumn();
            if (!$temIdx) $pdo->exec('ALTER TABLE `nc_indicacao_ocorrencia` ADD KEY `ix_nc_codauditoria` (`codauditoria`)');
        }
        $feitos = 0;
        foreach ($views($ncAud || $simular) as $sql) { if (!$simular) $pdo->exec($sql); $feitos++; }
        // pendências por pessoa (Minhas pendências / sininho — item 172): quem planeja, quem avalia e quem executa, com os grupos
        $grp = static function (string $papel, string $colGrupo, string $st) use ($pdo): string {
            if (!MigracaoCrud::existe($pdo, 'responsabilidade_grupoxusuario')) return '';
            $cols = array_map('strtolower', MigracaoCrud::colunas($pdo, 'responsabilidade_grupoxusuario'));
            $cond = (in_array('ativo', $cols, true) ? ' AND g.`ATIVO` = 1' : '') . (in_array('valido_ate', $cols, true) ? ' AND (g.`VALIDO_ATE` IS NULL OR g.`VALIDO_ATE` >= CURDATE())' : '');
            return " UNION SELECT a.`CODAUDITORIA`, a.`DSCAUDITORIA`, a.`STATUS`, a.`DATADEINICIO`, a.`DATADEFIM`, a.`QTD_RNC_ABERTAS`, '$papel' AS `PAPEL`, g.`CODUSUARIO`
                       FROM `vw_aud_auditorias_grid` a JOIN `responsabilidade_grupoxusuario` g ON g.`CODGRUPO` = a.`$colGrupo` WHERE a.`STATUS` = '$st'$cond";
        };
        $pend = "CREATE OR REPLACE VIEW `vw_aud_pendencias` AS
            SELECT a.`CODAUDITORIA`, a.`DSCAUDITORIA`, a.`STATUS`, a.`DATADEINICIO`, a.`DATADEFIM`, a.`QTD_RNC_ABERTAS`, 'PLANO' AS `PAPEL`, a.`CODUSUARIO_CRIADOR` AS `CODUSUARIO` FROM `vw_aud_auditorias_grid` a WHERE a.`STATUS` IN ('P', 'R') AND a.`CODUSUARIO_CRIADOR` > 0
            UNION SELECT a.`CODAUDITORIA`, a.`DSCAUDITORIA`, a.`STATUS`, a.`DATADEINICIO`, a.`DATADEFIM`, a.`QTD_RNC_ABERTAS`, 'AVALIAR', a.`CODUSUARIO_AVALIADOR` FROM `vw_aud_auditorias_grid` a WHERE a.`STATUS` = 'A' AND a.`CODUSUARIO_AVALIADOR` > 0
            UNION SELECT a.`CODAUDITORIA`, a.`DSCAUDITORIA`, a.`STATUS`, a.`DATADEINICIO`, a.`DATADEFIM`, a.`QTD_RNC_ABERTAS`, 'EXECUTAR', a.`CODUSUARIO_AUDITOR` FROM `vw_aud_auditorias_grid` a WHERE a.`STATUS` = 'X' AND a.`CODUSUARIO_AUDITOR` > 0"
            . $grp('AVALIAR', 'CODGRUPO_AVALIADORES', 'A') . $grp('EXECUTAR', 'CODGRUPO_AUDITORES', 'X');
        if (!$simular) $pdo->exec($pend);
        $feitos++;
        $info['objetos'] = (count($novas) + $feitos) . ($simular ? ' a criar/atualizar' : ' criados/atualizados');
        if (!$simular) MigracaoCrud::garantir($pdo);
        $info['auditorias'] = $simular && !MigracaoCrud::existe($pdo, 'auditoria_planejamento') ? 0 : (int)$pdo->query('SELECT COUNT(*) FROM `auditoria_planejamento`')->fetchColumn();
        if ($ncAud) $info['rnc_com_auditoria'] = (int)$pdo->query('SELECT COUNT(*) FROM `nc_indicacao_ocorrencia` WHERE `codauditoria` > 0')->fetchColumn();
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}

// ---------------------------------------------------------------- universal: módulo comercial, menus e permissões
try {
    $u = $cat->universal();
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // módulo comercial (Segurança › Módulos da empresa); ligado onde Não Conformidades está ligado
    try { if (MigracaoCrud::existe($u, 'modulos_sistema')) {
        $ordemNc = (int)($u->query("SELECT ordem FROM modulos_sistema WHERE codigo_modulo = 'NAO_CONFORMIDADES'")->fetchColumn() ?: 30);
        if (!MigracaoCrud::primeiro($u, 'modulos_sistema', ['codigo_modulo' => 'AUDITORIA'])) {
            if (!$simular) MigracaoCrud::inserir($u, 'modulos_sistema', ['codigo_modulo' => 'AUDITORIA', 'nome_pt' => 'Auditoria', 'nome_en' => 'Audit', 'nome_es' => 'Auditoría', 'ativo' => 1, 'ordem' => $ordemNc + 1]);
            $rel['universal']['modulo'] = $simular ? 'módulo AUDITORIA a criar' : 'módulo AUDITORIA criado';
        } else $rel['universal']['modulo'] = 'módulo AUDITORIA já existia';
        if (MigracaoCrud::existe($u, 'empresa_modulos')) {
            $n = 0;
            foreach (MigracaoCrud::selecionar($u, 'empresa_modulos', ['codigo_modulo' => 'NAO_CONFORMIDADES']) as $m) {
                $m = array_change_key_case($m);
                if (MigracaoCrud::primeiro($u, 'empresa_modulos', ['codempresa' => (int)$m['codempresa'], 'codigo_modulo' => 'AUDITORIA'])) continue;
                if (!$simular) MigracaoCrud::inserir($u, 'empresa_modulos', ['codempresa' => (int)$m['codempresa'], 'codigo_modulo' => 'AUDITORIA', 'ativo' => (int)$m['ativo'], 'atualizado_por' => 0]);
                $n++;
            }
            $rel['universal']['empresas'] = $n . ' empresa(s) ' . ($simular ? 'a ligar (como Não Conformidades)' : 'com o módulo ligado como Não Conformidades');
        }
    } else $rel['universal']['modulo'] = 'sem modulos_sistema: o módulo não é comercial nesta instalação (menus valem para todas as empresas)';
    } catch (Throwable $e) { $rel['universal']['aviso'] = 'módulo comercial não gravado: ' . mb_substr($e->getMessage(), 0, 200) . ' (ligar em Segurança › Módulos da empresa)'; }

    // grupo AUDITORIA ao lado de Não Conformidades
    $nc = MigracaoCrud::primeiro($u, 'menus', ['codigo' => 'NAO_CONFORMIDADES']);
    $grupo = MigracaoCrud::primeiro($u, 'menus', ['codigo' => 'AUDITORIA']);
    if (!$grupo) {
        if (!$nc) throw new RuntimeException('o universal não tem o grupo NAO_CONFORMIDADES para posicionar a Auditoria');
        $nc = array_change_key_case($nc);
        if ($simular) { $rel['menus']['AUDITORIA'] = 'grupo será criado ao lado de NAO_CONFORMIDADES'; $codGrupo = 0; $nivelGrupo = (int)$nc['nivel']; }
        else {
            $g = $cat->gravar(['codmenu_pai' => !empty($nc['codmenu_pai']) ? (int)$nc['codmenu_pai'] : null, 'modulo' => 'AUDITORIA', 'codigo' => 'AUDITORIA', 'label' => 'Auditoria', 'label_pt' => 'Auditoria',
                'label_en' => 'Audit', 'label_es' => 'Auditoría', 'url' => null, 'nivel' => (int)$nc['nivel'], 'ordem' => (int)$nc['ordem'] + 1, 'icone' => 'bi-clipboard2-check', 'ativo' => 1]);
            $codGrupo = (int)$g['codmenu']; $nivelGrupo = (int)$nc['nivel'];
            $rel['menus']['AUDITORIA'] = 'grupo criado (codmenu ' . $codGrupo . ')';
        }
    } else {
        $grupo = array_change_key_case($grupo); $codGrupo = (int)$grupo['codmenu']; $nivelGrupo = (int)$grupo['nivel'];
        if (!$simular) $cat->replicar('AUDITORIA', null, false);
        $rel['menus']['AUDITORIA'] = 'grupo já existia (codmenu ' . $codGrupo . ')';
    }
    foreach ($menus as $codigo => [$pt, $en, $es, $url, $icone, $ordem]) {
        $existe = MigracaoCrud::primeiro($u, 'menus', ['codigo' => $codigo]);
        if ($existe) { if (!$simular) $cat->replicar($codigo, null, false); $rel['menus'][$codigo] = 'já existia (codmenu ' . $existe['codmenu'] . ')'; continue; }
        if ($simular) { $rel['menus'][$codigo] = 'será criado: ' . $pt; continue; }
        $g = $cat->gravar(['codmenu_pai' => $codGrupo, 'modulo' => 'AUDITORIA', 'codigo' => $codigo, 'label' => $pt, 'label_pt' => $pt, 'label_en' => $en, 'label_es' => $es,
            'url' => $url, 'nivel' => $nivelGrupo + 1, 'ordem' => $ordem, 'icone' => $icone, 'ativo' => 1]);
        $erros = array_filter($g['empresas'], static fn($r) => str_starts_with((string)$r, 'ERRO'));
        $rel['menus'][$codigo] = 'criado (codmenu ' . $g['codmenu'] . ', ' . count($g['empresas']) . ' empresa(s)' . ($erros ? ', erro em: ' . implode(', ', array_keys($erros)) : '') . ')';
        if ($erros) $rel['ok'] = false;
    }
    // permissões: as mesmas dos menus de NC equivalentes, em cada perfil
    foreach ($cat->empresas() as $banco => $pdo) {
        if ($soBanco !== null && $banco !== $soBanco) continue;
        foreach ($menus as $codigo => $m) {
            $irmao = MigracaoCrud::primeiro($pdo, 'menus', ['codigo' => $m[6]]); $novo = MigracaoCrud::primeiro($pdo, 'menus', ['codigo' => $codigo]);
            if (!$irmao || !$novo) { $rel['bancos'][$banco]['permissoes'][$codigo] = $simular ? 'menu será replicado; permissões = as de ' . $m[6] : 'menu ou ' . $m[6] . ' ausente'; continue; }
            $irmao = array_change_key_case($irmao); $novo = array_change_key_case($novo);
            $cont = 0;
            foreach (['permissoes', 'perfil_menus'] as $t) {
                if (!MigracaoCrud::existe($pdo, $t)) continue;
                $cols = MigracaoCrud::colunas($pdo, $t);
                if (!in_array('codmenu', $cols, true) || !in_array('codperfil', $cols, true)) continue;
                foreach (MigracaoCrud::selecionar($pdo, $t, ['codmenu' => (int)$irmao['codmenu']]) as $p) {
                    $p = array_change_key_case($p);
                    $flags = array_filter($p, static fn($k) => str_starts_with((string)$k, 'pode_'), ARRAY_FILTER_USE_KEY);
                    if ($flags && !array_filter($flags)) continue;
                    if (MigracaoCrud::primeiro($pdo, $t, ['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$novo['codmenu']])) continue;
                    if (!$simular) MigracaoCrud::inserir($pdo, $t, ['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$novo['codmenu']] + $flags);
                    $cont++;
                }
            }
            $rel['bancos'][$banco]['permissoes'][$codigo] = $cont . ($simular ? ' a criar' : ' criada(s)');
        }
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['menus']['erro'] = $e->getMessage(); }

// ---------------------------------------------------------------- ajuda das telas (item 175)
try {
    $u = $cat->universal();
    $textos = require dirname(__DIR__) . '/database/423_ajuda_auditoria.php';
    if (!MigracaoCrud::existe($u, 'ajuda_transacoes')) throw new RuntimeException('sem a tabela ajuda_transacoes no universal');
    foreach ($textos as $transacao => $d) {
        $atual = null;
        foreach (MigracaoCrud::selecionar($u, 'ajuda_transacoes', ['transacao' => $transacao]) as $r) if (strcasecmp((string)($r['transacao'] ?? ''), $transacao) === 0) { $atual = $r; break; }
        $dados = [];
        foreach (['pt', 'en', 'es'] as $lg) {
            $velho = trim((string)preg_replace('/\s*<!-- ml423 -->.*?<!-- \/ml423 -->/s', '', (string)($atual['texto_' . $lg] ?? '')));
            $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . "<!-- ml423 -->\n" . $d['texto'][$lg] . "\n<!-- /ml423 -->";
            if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
        }
        if ($simular) { $rel['ajuda'][$transacao] = $atual === null ? 'será incluída' : 'será atualizada'; continue; }
        if ($atual === null) { MigracaoCrud::inserir($u, 'ajuda_transacoes', ['transacao' => $transacao] + $dados + ['ativo' => 1]); $rel['ajuda'][$transacao] = 'incluída'; }
        else {
            $chave = ['transacao' => (string)$atual['transacao']];
            foreach (['id', 'codajuda', 'codigo', 'codajudatransacao'] as $pk) if (isset($atual[$pk]) && $atual[$pk] !== '') { $chave = [$pk => $atual[$pk]]; break; }
            MigracaoCrud::atualizar($u, 'ajuda_transacoes', $chave, $dados + ['ativo' => 1]);
            $rel['ajuda'][$transacao] = 'atualizada';
        }
    }
} catch (Throwable $e) { $rel['ajuda']['aviso'] = $e->getMessage(); }

$rel['depois'] = 'o sincronismo diário passa a trazer as tabelas auditoria_* do legado (SincronismoLegado::PREFIXOS); os menus aparecem no próximo login';
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
