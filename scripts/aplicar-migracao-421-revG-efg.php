<?php
declare(strict_types=1);

/*
 * Migração 421 — Revisão G, blocos E, F e G (26/11/2026).
 *
 * Em cada empresa (g*_dbp), tabelas e views (gravação pelas telas com sp_crud_*):
 *   E  88  ctrl_documento_versao, ctrl_documento_obsoleto ........ nova versão do Controle: obsoletos, avisos e treinamento
 *   E   9  usuario_validade + vw_usuarios_validade ............... confirmação periódica de que o usuário continua válido
 *   E  16  portal_config ........................................ retenção e envio dos logs por e-mail (e outras opções)
 *   F      ml_aplicab_ambito, ml_aplicab_resposta, ml_aplicab_lei  questionário de aplicabilidade legal por unidade
 *          vw_aplicab_palavras, vw_aplicab_lei_palavra ........... palavras-chave × leis (glegislacao_dbp) para o questionário
 *   G   6  grid_filtro_favorito ................................. filtros favoritos dos grids (estrela), compartilháveis
 *   G  10  api_token ............................................ tokens da API de leitura
 *   G  42  rcl_leitura .......................................... leitura da RCL (revisões da legislação) por usuário
 *   G  63  lic_campo, lic_campo_valor ........................... campos personalizados das licenças
 *   G 149/150 hom_ativo_tipo, hom_ativo_tipo_doc, hom_ativo, hom_ativo_doc  veículos e equipamentos dos fornecedores
 * Universal: menus novos (ao lado das telas parecidas) e, em cada empresa, as permissões copiadas da tela irmã.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-421-revG-efg.php [--simular] [--banco=gcrown_dbp]
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
$leg = (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp');
$uni = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$rel = ['ok' => true, 'migracao' => '421', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'menus' => [], 'bancos' => []];

// codigo => [pt, en, es, url, ícone, url da tela irmã (posição e permissões)]
$menus = [
    'QUESTIONARIO_APLICABILIDADE_LEGAL' => ['Questionário de aplicabilidade legal', 'Legal applicability questionnaire', 'Cuestionario de aplicabilidad legal', '/principal/modulolegal_questionario_aplicabilidade.php', 'bi-ui-checks', 'modulolegal_requisitoslegais.php'],
    'RCL_LEITURA' => ['RCL — revisões e leitura', 'RCL — revisions and reading', 'RCL — revisiones y lectura', '/principal/modulolegal_rcl_leitura.php', 'bi-journal-check', 'modulolegal_requisitoslegais.php'],
    'VALIDADE_USUARIOS' => ['Validade dos usuários', 'User validity', 'Validez de los usuarios', '/principal/seguranca_validade_usuarios.php', 'bi-person-check', 'seguranca_usuarios.php'],
    'API_TOKENS' => ['Tokens da API', 'API tokens', 'Tokens de la API', '/principal/seguranca_api_tokens.php', 'bi-key', 'seguranca_usuarios.php'],
    'LOGS_SISTEMA' => ['Logs do sistema', 'System logs', 'Registros del sistema', '/principal/administracao_logs.php', 'bi-journal-text', 'administracao_emailsenviados.php'],
    'LICENCAS_CAMPOS' => ['Campos personalizados (Licenças)', 'Custom fields (Licenses)', 'Campos personalizados (Licencias)', '/principal/licencas_campos.php', 'bi-input-cursor-text', 'manutencaolegal_licencas.php'],
    'HOM_TIPOS_ATIVOS' => ['Tipos de veículo e equipamento', 'Vehicle and equipment types', 'Tipos de vehículo y equipo', '/principal/homologacaofornecedores_tipos_ativos.php', 'bi-truck', 'homologacaofornecedores_criteriosfornecedores.php'],
];

$ddl = static fn(string $leg, string $uni): array => [
    // ---- E 88
    "CREATE TABLE IF NOT EXISTS `ctrl_documento_versao` (`CODVERSAO` INT NOT NULL AUTO_INCREMENT, `CODDOCUMENTO` INT NOT NULL, `VERSAO` VARCHAR(30) NULL, `VERSAO_ANTERIOR` VARCHAR(30) NULL,
      `ARQUIVO` VARCHAR(255) NULL, `CODUSUARIO` INT NULL, `AVISADOS` INT NOT NULL DEFAULT 0, `NECESSITA_TREINAMENTO` CHAR(1) NOT NULL DEFAULT 'N', `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`CODVERSAO`), KEY `ix_cdv_doc` (`CODDOCUMENTO`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE OR REPLACE VIEW `vw_ctrl_documento_versao` AS SELECT v.*, COALESCE(NULLIF(TRIM(u.`nome`),''), u.`userid`) AS `USUARIO` FROM `ctrl_documento_versao` v LEFT JOIN `usuarios` u ON u.`codusuario` = v.`CODUSUARIO`",
    "CREATE TABLE IF NOT EXISTS `ctrl_documento_obsoleto` (`CODOBSOLETO` INT NOT NULL AUTO_INCREMENT, `CODDOCUMENTO` INT NOT NULL, `ARQUIVO` VARCHAR(255) NOT NULL, `VERSAO` VARCHAR(30) NULL,
      `CODUSUARIO` INT NULL, `OBSOLETO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`CODOBSOLETO`), UNIQUE KEY `ux_cdo` (`CODDOCUMENTO`, `ARQUIVO`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE OR REPLACE VIEW `vw_ctrl_documento_obsoleto` AS SELECT * FROM `ctrl_documento_obsoleto`",
    // ---- E 9
    "CREATE TABLE IF NOT EXISTS `usuario_validade` (`CODVALIDADE` INT NOT NULL AUTO_INCREMENT, `CODUSUARIO` INT NOT NULL, `SITUACAO` VARCHAR(20) NOT NULL DEFAULT 'CONFIRMADO', `OBS` VARCHAR(500) NULL,
      `CODUSUARIO_GESTOR` INT NULL, `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`CODVALIDADE`), KEY `ix_uv_usuario` (`CODUSUARIO`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE OR REPLACE VIEW `vw_usuarios_validade` AS
     SELECT u.`codusuario` AS `CODUSUARIO`, COALESCE(NULLIF(TRIM(u.`nome`),''), u.`userid`) AS `NOME`, u.`userid` AS `USERID`, u.`email` AS `EMAIL`, u.`codperfil` AS `CODPERFIL`, p.`nome` AS `PERFIL`,
            u.`ativo` AS `ATIVO`, u.`ultimo_acesso` AS `ULTIMO_ACESSO`, u.`criado_em` AS `CRIADO_EM`, u.`codusuario_superior` AS `CODUSUARIO_SUPERIOR`, u.`codfornecedor` AS `CODFORNECEDOR`, u.`tipo_auth` AS `TIPO_AUTH`,
            DATEDIFF(CURDATE(), DATE(u.`ultimo_acesso`)) AS `DIAS_SEM_ACESSO`,
            (SELECT MAX(v.`CRIADO_EM`) FROM `usuario_validade` v WHERE v.`CODUSUARIO` = u.`codusuario` AND v.`SITUACAO` = 'CONFIRMADO') AS `CONFIRMADO_EM`,
            (SELECT v.`CODUSUARIO_GESTOR` FROM `usuario_validade` v WHERE v.`CODUSUARIO` = u.`codusuario` AND v.`SITUACAO` = 'CONFIRMADO' ORDER BY v.`CRIADO_EM` DESC, v.`CODVALIDADE` DESC LIMIT 1) AS `CONFIRMADO_POR`
       FROM `usuarios` u LEFT JOIN `perfis` p ON p.`codperfil` = u.`codperfil`",
    // ---- E 16 (e configurações gerais)
    "CREATE TABLE IF NOT EXISTS `portal_config` (`CHAVE` VARCHAR(80) NOT NULL, `VALOR` TEXT NULL, `ALTERADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, `CODUSUARIO` INT NULL,
      PRIMARY KEY (`CHAVE`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE OR REPLACE VIEW `vw_portal_config` AS SELECT * FROM `portal_config`",
    // ---- F
    "CREATE TABLE IF NOT EXISTS `ml_aplicab_ambito` (`CODAPLICABAMBITO` INT NOT NULL AUTO_INCREMENT, `CODUNIDADE` INT NOT NULL, `CODAMBITO` INT NOT NULL, `CODUSUARIO` INT NULL, `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`CODAPLICABAMBITO`), UNIQUE KEY `ux_aa` (`CODUNIDADE`, `CODAMBITO`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE OR REPLACE VIEW `vw_ml_aplicab_ambito` AS SELECT a.*, b.`DSCAMBITO` FROM `ml_aplicab_ambito` a LEFT JOIN `$leg`.`ambitos` b ON b.`CODAMBITO` = a.`CODAMBITO`",
    "CREATE TABLE IF NOT EXISTS `ml_aplicab_resposta` (`CODAPLICABRESPOSTA` INT NOT NULL AUTO_INCREMENT, `CODUNIDADE` INT NOT NULL, `CODPALAVRACHAVE` INT NOT NULL, `RESPOSTA` CHAR(1) NOT NULL,
      `ORIGEM` VARCHAR(10) NOT NULL DEFAULT 'USUARIO', `CODUSUARIO` INT NULL, `RESPONDIDO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`CODAPLICABRESPOSTA`), UNIQUE KEY `ux_ar` (`CODUNIDADE`, `CODPALAVRACHAVE`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE OR REPLACE VIEW `vw_ml_aplicab_resposta` AS SELECT * FROM `ml_aplicab_resposta`",
    "CREATE TABLE IF NOT EXISTS `ml_aplicab_lei` (`CODAPLICABLEI` INT NOT NULL AUTO_INCREMENT, `CODUNIDADE` INT NOT NULL, `CODLEGISLACAO` INT NOT NULL, `PALAVRAS` VARCHAR(1000) NULL,
      `DECISAO` VARCHAR(10) NOT NULL DEFAULT 'INCLUIDA', `CODUSUARIO` INT NULL, `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`CODAPLICABLEI`), UNIQUE KEY `ux_al` (`CODUNIDADE`, `CODLEGISLACAO`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE OR REPLACE VIEW `vw_ml_aplicab_lei` AS SELECT * FROM `ml_aplicab_lei`",
    "CREATE OR REPLACE VIEW `vw_aplicab_palavras` AS
     SELECT p.`codpalavrachave` AS `CODPALAVRACHAVE`, p.`dscpalavrachave` AS `PALAVRA`, t.`TIPOS`, COALESCE(n.`QTD_LEIS`, 0) AS `QTD_LEIS`
       FROM `$uni`.`palavras_chaves` p
       LEFT JOIN (SELECT x.`CODPALAVRACHAVE`, GROUP_CONCAT(DISTINCT tp.`dsctipopalavrachave` ORDER BY tp.`dsctipopalavrachave` SEPARATOR ', ') AS `TIPOS`
                    FROM `$leg`.`tiposxpalavras_chaves` x JOIN `$leg`.`tipos_palavras_chaves` tp ON tp.`codtipopalavrachave` = x.`CODTIPOPALAVRACHAVE` GROUP BY x.`CODPALAVRACHAVE`) t ON t.`CODPALAVRACHAVE` = p.`codpalavrachave`
       LEFT JOIN (SELECT lp.`codpalavrachave`, COUNT(DISTINCT lp.`codlegislacao`) AS `QTD_LEIS` FROM `$leg`.`legislacaoxpalavraschave` lp GROUP BY lp.`codpalavrachave`) n ON n.`codpalavrachave` = p.`codpalavrachave`",
    "CREATE OR REPLACE VIEW `vw_aplicab_lei_palavra` AS
     SELECT lp.`codpalavrachave` AS `CODPALAVRACHAVE`, l.`CODLEGISLACAO`, l.`CODIGO`, l.`CODAMBITO`, a.`DSCAMBITO`, LEFT(l.`PADRAO`, 400) AS `PADRAO`, l.`situacao_da_lei` AS `SITUACAO_LEI`
       FROM `$leg`.`legislacaoxpalavraschave` lp JOIN `$leg`.`legislacao` l ON l.`CODLEGISLACAO` = lp.`codlegislacao` LEFT JOIN `$leg`.`ambitos` a ON a.`CODAMBITO` = l.`CODAMBITO`",
    "CREATE OR REPLACE VIEW `vw_aplicab_ambitos` AS SELECT `CODAMBITO`, `DSCAMBITO` FROM `$leg`.`ambitos`",
    // ---- G 6
    "CREATE TABLE IF NOT EXISTS `grid_filtro_favorito` (`CODFILTRO` INT NOT NULL AUTO_INCREMENT, `TELA` VARCHAR(255) NOT NULL, `NOME` VARCHAR(100) NOT NULL, `FILTROS` TEXT NOT NULL,
      `CODUSUARIO` INT NOT NULL, `COMPARTILHADO` CHAR(1) NOT NULL DEFAULT 'N', `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`CODFILTRO`), KEY `ix_gff_tela` (`TELA`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE OR REPLACE VIEW `vw_grid_filtro_favorito` AS SELECT f.*, COALESCE(NULLIF(TRIM(u.`nome`),''), u.`userid`) AS `USUARIO` FROM `grid_filtro_favorito` f LEFT JOIN `usuarios` u ON u.`codusuario` = f.`CODUSUARIO`",
    // ---- G 10
    "CREATE TABLE IF NOT EXISTS `api_token` (`CODTOKEN` INT NOT NULL AUTO_INCREMENT, `NOME` VARCHAR(100) NOT NULL, `PREFIXO` VARCHAR(20) NOT NULL, `HASH` CHAR(64) NOT NULL, `ESCOPOS` VARCHAR(200) NOT NULL,
      `VALIDO_ATE` DATE NOT NULL, `CODUSUARIO` INT NULL, `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `REVOGADO_EM` DATETIME NULL, `ULTIMO_USO_EM` DATETIME NULL, `USOS` INT NOT NULL DEFAULT 0,
      PRIMARY KEY (`CODTOKEN`), UNIQUE KEY `ux_api_hash` (`HASH`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE OR REPLACE VIEW `vw_api_token` AS SELECT t.*, COALESCE(NULLIF(TRIM(u.`nome`),''), u.`userid`) AS `USUARIO` FROM `api_token` t LEFT JOIN `usuarios` u ON u.`codusuario` = t.`CODUSUARIO`",
    // ---- G 42
    "CREATE TABLE IF NOT EXISTS `rcl_leitura` (`CODLEITURA` INT NOT NULL AUTO_INCREMENT, `CODREVISAO` INT NOT NULL, `CODUSUARIO` INT NOT NULL, `LIDA_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`CODLEITURA`), UNIQUE KEY `ux_rcl_leitura` (`CODREVISAO`, `CODUSUARIO`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE OR REPLACE VIEW `vw_rcl_leitura` AS SELECT r.*, COALESCE(NULLIF(TRIM(u.`nome`),''), u.`userid`) AS `USUARIO` FROM `rcl_leitura` r LEFT JOIN `usuarios` u ON u.`codusuario` = r.`CODUSUARIO`",
    // ---- G 63
    "CREATE TABLE IF NOT EXISTS `lic_campo` (`CODCAMPO` INT NOT NULL AUTO_INCREMENT, `NOME` VARCHAR(100) NOT NULL, `TIPO` VARCHAR(10) NOT NULL DEFAULT 'TEXTO', `OPCOES` VARCHAR(1000) NULL,
      `OBRIGATORIO` CHAR(1) NOT NULL DEFAULT 'N', `ORDEM` INT NOT NULL DEFAULT 0, `ATIVO` CHAR(1) NOT NULL DEFAULT 'S', `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`CODCAMPO`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS `lic_campo_valor` (`CODVALOR` INT NOT NULL AUTO_INCREMENT, `CODLICENCA` INT NOT NULL, `CODCAMPO` INT NOT NULL, `VALOR` VARCHAR(2000) NULL, `CODUSUARIO` INT NULL,
      `ALTERADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (`CODVALOR`), UNIQUE KEY `ux_lcv` (`CODLICENCA`, `CODCAMPO`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE OR REPLACE VIEW `vw_lic_campo` AS SELECT c.*, (SELECT COUNT(*) FROM `lic_campo_valor` v WHERE v.`CODCAMPO` = c.`CODCAMPO` AND COALESCE(v.`VALOR`,'') <> '') AS `QTD_USO` FROM `lic_campo` c",
    "CREATE OR REPLACE VIEW `vw_lic_campo_valor` AS SELECT v.*, c.`NOME`, c.`TIPO`, c.`ORDEM` FROM `lic_campo_valor` v JOIN `lic_campo` c ON c.`CODCAMPO` = v.`CODCAMPO`",
    // ---- G 149/150
    "CREATE TABLE IF NOT EXISTS `hom_ativo_tipo` (`CODTIPOATIVO` INT NOT NULL AUTO_INCREMENT, `CATEGORIA` VARCHAR(12) NOT NULL, `NOME` VARCHAR(120) NOT NULL, `ATIVO` CHAR(1) NOT NULL DEFAULT 'S',
      `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`CODTIPOATIVO`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS `hom_ativo_tipo_doc` (`CODTIPODOC` INT NOT NULL AUTO_INCREMENT, `CODTIPOATIVO` INT NOT NULL, `NOME` VARCHAR(200) NOT NULL, `OBRIGATORIO` CHAR(1) NOT NULL DEFAULT 'S',
      `VALIDADE_MESES` INT NULL, `ATIVO` CHAR(1) NOT NULL DEFAULT 'S', PRIMARY KEY (`CODTIPODOC`), KEY `ix_htd_tipo` (`CODTIPOATIVO`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS `hom_ativo` (`CODATIVO` INT NOT NULL AUTO_INCREMENT, `CODFORNECEDOR` INT NOT NULL, `CODTIPOATIVO` INT NOT NULL, `IDENTIFICACAO` VARCHAR(60) NOT NULL, `DESCRICAO` VARCHAR(300) NULL,
      `ATIVO` CHAR(1) NOT NULL DEFAULT 'S', `CODUSUARIO` INT NULL, `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`CODATIVO`), KEY `ix_ha_forn` (`CODFORNECEDOR`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS `hom_ativo_doc` (`CODATIVODOC` INT NOT NULL AUTO_INCREMENT, `CODATIVO` INT NOT NULL, `CODTIPODOC` INT NOT NULL, `DATA_VALIDADE` DATE NULL, `SITUACAO` VARCHAR(30) NOT NULL DEFAULT 'Pendente',
      `OBS` VARCHAR(500) NULL, `CODUSUARIO` INT NULL, `ALTERADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`CODATIVODOC`), UNIQUE KEY `ux_had` (`CODATIVO`, `CODTIPODOC`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE OR REPLACE VIEW `vw_hom_ativo_tipo` AS SELECT t.*, (SELECT COUNT(*) FROM `hom_ativo_tipo_doc` d WHERE d.`CODTIPOATIVO` = t.`CODTIPOATIVO` AND d.`ATIVO` = 'S') AS `QTD_DOCS`,
       (SELECT COUNT(*) FROM `hom_ativo` a WHERE a.`CODTIPOATIVO` = t.`CODTIPOATIVO`) AS `QTD_USO` FROM `hom_ativo_tipo` t",
    "CREATE OR REPLACE VIEW `vw_hom_ativo_tipo_doc` AS SELECT * FROM `hom_ativo_tipo_doc`",
    "CREATE OR REPLACE VIEW `vw_hom_ativo` AS SELECT a.*, t.`CATEGORIA`, t.`NOME` AS `TIPO` FROM `hom_ativo` a JOIN `hom_ativo_tipo` t ON t.`CODTIPOATIVO` = a.`CODTIPOATIVO`",
    "CREATE OR REPLACE VIEW `vw_hom_ativo_doc` AS
     SELECT d.`CODATIVODOC`, d.`CODATIVO`, d.`CODTIPODOC`, d.`DATA_VALIDADE`, d.`SITUACAO`, d.`OBS`, d.`ALTERADO_EM`, a.`CODFORNECEDOR`, td.`NOME` AS `DOCUMENTO`, td.`OBRIGATORIO`, td.`VALIDADE_MESES`,
            CASE WHEN d.`SITUACAO` = 'Aprovado' AND d.`DATA_VALIDADE` IS NOT NULL AND d.`DATA_VALIDADE` < CURDATE() THEN 'Vencido' ELSE d.`SITUACAO` END AS `SITUACAO_ATUAL`
       FROM `hom_ativo_doc` d JOIN `hom_ativo` a ON a.`CODATIVO` = d.`CODATIVO` JOIN `hom_ativo_tipo_doc` td ON td.`CODTIPODOC` = d.`CODTIPODOC`",
];

// ---------------------------------------------------------------- empresas: tabelas e views
$cat = new MenuCatalogoService($env);
foreach ($cat->empresas() as $banco => $pdo) {
    if ($soBanco !== null && $banco !== $soBanco) continue;
    $info = [];
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $faltam = array_values(array_filter(['documentos', 'usuarios', 'perfis', 'li_licencas', 'fo_fornecedores'], static fn($t) => !MigracaoCrud::existe($pdo, $t)));
        if ($faltam) $info['aviso'] = 'sem ' . implode(', ', $faltam) . ' (objetos que dependem deles serão pulados)';
        $feitos = 0; $pulados = [];
        $colsUsuarios = MigracaoCrud::existe($pdo, 'usuarios') ? array_map('strtolower', MigracaoCrud::colunas($pdo, 'usuarios')) : [];
        foreach ($ddl($leg, $uni) as $i => $sql) {
            // vw_usuarios_validade: coluna de usuarios que a empresa não tem (ex.: gmodelo2 sem codfornecedor) entra como NULL
            if (str_contains($sql, '`vw_usuarios_validade`') && $colsUsuarios) {
                $sql = preg_replace_callback('/u\.`([a-z_]+)`/', static fn($m) => in_array($m[1], $colsUsuarios, true) ? $m[0] : 'NULL', $sql);
            }
            if ($simular) { $feitos++; continue; }
            try { $pdo->exec($sql); $feitos++; }
            catch (Throwable $e) { $nome = preg_match('/`([a-z_]+)`/', $sql, $m) ? $m[1] : ('#' . $i); $pulados[$nome] = mb_substr($e->getMessage(), 0, 160); }
        }
        $info['objetos'] = $feitos . ($simular ? ' a criar/atualizar' : ' criados/atualizados');
        if ($pulados) { $info['pulados'] = $pulados; if (!$faltam) $rel['ok'] = false; }
        if (!$simular) MigracaoCrud::garantir($pdo);
        // E 16: views dos logs com a data e o nome do usuário (as colunas de logs_auditoria variam entre empresas)
        foreach ([['logs_auditoria', 'vw_logs_acoes'], ['historico_alteracoes', 'vw_logs_alteracoes']] as [$tab, $view]) {
            if (!MigracaoCrud::existe($pdo, $tab)) { $info['logs'][$view] = 'sem ' . $tab; continue; }
            $cols = array_map('strtolower', MigracaoCrud::colunas($pdo, $tab));
            $colData = current(array_filter(['data_hora', 'criado_em', 'data_evento', 'data', 'created_at', 'dt_evento', 'datahora'], static fn($c) => in_array($c, $cols, true))) ?: null;
            $colUsu = current(array_filter(['codusuario', 'usuario', 'cod_usuario', 'user_id'], static fn($c) => in_array($c, $cols, true))) ?: null;
            if (!$colData) { $info['logs'][$view] = 'sem coluna de data em ' . $tab . ' (' . implode(',', $cols) . ')'; continue; }
            $sql = "CREATE OR REPLACE VIEW `$view` AS SELECT t.*, t.`$colData` AS `DATA_EVENTO`, " . ($colUsu ? "COALESCE(NULLIF(TRIM(u.`nome`),''), u.`userid`)" : 'NULL') . " AS `NOME_USUARIO` FROM `$tab` t"
                . ($colUsu ? " LEFT JOIN `usuarios` u ON u.`codusuario` = t.`$colUsu`" : '');
            if (!$simular) $pdo->exec($sql);
            $info['logs'][$view] = ($simular ? 'a criar' : 'ok') . " (data: $colData" . ($colUsu ? ", usuário: $colUsu" : '') . ')';
        }
        // E 9: usuarios.ultimo_acesso só é gravado no login desde 19/11/2026; preenche o vazio (ou mais antigo) com a última
        // ação do usuário nos logs, para a Validade dos usuários e o Painel de bordo não mostrarem "Nunca entrou" para quem usa
        if (MigracaoCrud::existe($pdo, 'logs_auditoria') && in_array('ultimo_acesso', array_map('strtolower', MigracaoCrud::colunas($pdo, 'usuarios')), true)
            && in_array('codusuario', $colsLog = array_map('strtolower', MigracaoCrud::colunas($pdo, 'logs_auditoria')), true) && in_array('criado_em', $colsLog, true)) {
            $s = $pdo->query("SELECT l.`codusuario` AS U, MAX(l.`criado_em`) AS M, MAX(u.`ultimo_acesso`) AS A FROM `logs_auditoria` l JOIN `usuarios` u ON u.`codusuario` = l.`codusuario`
                               GROUP BY l.`codusuario` HAVING A IS NULL OR A < M");
            $lista = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
            if (!$simular) foreach ($lista as $r) {
                $c = $pdo->prepare('CALL sp_crud_update(?, ?, ?)');
                $c->execute(['usuarios', json_encode(['codusuario' => (int)$r['U']]), json_encode(['ultimo_acesso' => $r['M']])]);
                $c->closeCursor();
            }
            $info['ultimo_acesso_pelos_logs'] = count($lista) . ($simular ? ' a preencher' : ' preenchido(s)');
        }
        if (!$simular && MigracaoCrud::existe($pdo, 'legislacaoxrevisoes')) $info['colunas_legislacaoxrevisoes'] = implode(',', MigracaoCrud::colunas($pdo, 'legislacaoxrevisoes'));
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}

// ---------------------------------------------------------------- universal: menus (ao lado da tela irmã)
try {
    $u = $cat->universal();
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $porUrl = static function (PDO $p, string $arquivo): ?array {
        foreach (MigracaoCrud::selecionar($p, 'menus') as $m) { $m = array_change_key_case($m); if (str_ends_with(strtolower((string)($m['url'] ?? '')), '/principal/' . strtolower($arquivo)) || strtolower(basename((string)($m['url'] ?? ''))) === strtolower($arquivo)) return $m; }
        return null;
    };
    foreach ($menus as $codigo => [$pt, $en, $es, $url, $icone, $irmaoUrl]) {
        $existe = MigracaoCrud::primeiro($u, 'menus', ['codigo' => $codigo]);
        if ($existe) { if (!$simular) $cat->replicar($codigo, null, false); $rel['menus'][$codigo] = 'já existia (codmenu ' . $existe['codmenu'] . ')'; continue; }
        $irmao = $porUrl($u, $irmaoUrl);
        if (!$irmao) { $rel['menus'][$codigo] = 'NÃO criado: tela irmã ' . $irmaoUrl . ' não está no menu universal'; continue; }
        if ($simular) { $rel['menus'][$codigo] = 'será criado ao lado de ' . ($irmao['codigo'] ?? $irmaoUrl) . ': ' . $pt; continue; }
        $g = $cat->gravar(['codmenu_pai' => (int)$irmao['codmenu_pai'], 'modulo' => $irmao['modulo'] ?? null, 'codigo' => $codigo, 'label' => $pt, 'label_pt' => $pt, 'label_en' => $en, 'label_es' => $es,
            'url' => $url, 'nivel' => (int)($irmao['nivel'] ?? 2), 'ordem' => (int)($irmao['ordem'] ?? 0) + 1, 'icone' => $icone, 'ativo' => 1]);
        $erros = array_filter($g['empresas'], static fn($r) => str_starts_with((string)$r, 'ERRO'));
        $rel['menus'][$codigo] = 'criado (codmenu ' . $g['codmenu'] . ', ao lado de ' . ($irmao['codigo'] ?? '') . ', ' . count($g['empresas']) . ' empresa(s)' . ($erros ? ', erro em: ' . implode(', ', array_keys($erros)) : '') . ')';
        if ($erros) $rel['ok'] = false;
    }
    // permissões: as mesmas da tela irmã em cada perfil
    foreach ($cat->empresas() as $banco => $pdo) {
        if ($soBanco !== null && $banco !== $soBanco) continue;
        foreach ($menus as $codigo => $m) {
            $irmao = $porUrl($pdo, $m[5]); $novo = MigracaoCrud::primeiro($pdo, 'menus', ['codigo' => $codigo]);
            if (!$irmao || !$novo) { $rel['bancos'][$banco]['permissoes'][$codigo] = $simular ? 'menu será replicado; permissões = as de ' . $m[5] : 'menu ou tela irmã ausente'; continue; }
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

$rel['depois'] = 'os menus aparecem no próximo login (ou ao abrir outra tela, quando a sessão é atualizada)';
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
