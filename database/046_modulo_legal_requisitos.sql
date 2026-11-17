-- Green Portal - Módulo Legal / Requisitos legais.
-- Migra a situação de atendimento do legado e substitui as funções por views.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

USE `gmodelo_dbp`;

CREATE TABLE IF NOT EXISTS `legislacaoxsituacao` (
    `CODSITUACAO` int NOT NULL,
    `CODLEGISLACAO` int DEFAULT NULL,
    `dscsituacao` varchar(30000) DEFAULT NULL,
    `atendido` varchar(20) DEFAULT NULL,
    `CODUSUARIO` int DEFAULT NULL,
    `OBS` varchar(5000) DEFAULT NULL,
    `codunidade` int DEFAULT NULL,
    `data_de_avaliacao` date DEFAULT NULL,
    `data_de_vencimento_avaliacao` date DEFAULT NULL,
    `CODLEGISLACAOCODUNIDADE` varchar(100) DEFAULT NULL,
    PRIMARY KEY (`CODSITUACAO`),
    KEY `ix_lxsituacao_leg_unidade` (`CODLEGISLACAO`, `codunidade`),
    KEY `ix_lxsituacao_atendido` (`atendido`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELETE FROM `legislacaoxsituacao`;
INSERT INTO `legislacaoxsituacao`
    (`CODSITUACAO`, `CODLEGISLACAO`, `dscsituacao`, `atendido`, `CODUSUARIO`, `OBS`, `codunidade`, `data_de_avaliacao`, `data_de_vencimento_avaliacao`, `CODLEGISLACAOCODUNIDADE`)
SELECT `CODSITUACAO`, `CODLEGISLACAO`, CONVERT(`dscsituacao` USING utf8mb4), CONVERT(`atendido` USING utf8mb4), `CODUSUARIO`, CONVERT(`OBS` USING utf8mb4), `codunidade`, `data_de_avaliacao`, `data_de_vencimento_avaliacao`, CONVERT(`CODLEGISLACAOCODUNIDADE` USING utf8mb4)
FROM `green_modelo`.`legislacaoxsituacao`;

USE `glegislacao_dbp`;

CREATE OR REPLACE VIEW `vw_legislacao_nao_revogada` AS
SELECT `CODLEGISLACAO`, `CODIGO`, `CODAMBITO`, `CODTIPO`, `NR`, `ANO`, `ARTIGOS`, `MEIO_AMBIENTE`, `SEGURANCA`, `QUALIDADE`, `RESPOSABILIDADE`, `PADRAO`, `data_atualizacao`, `situacao_da_lei`, `devido_da_lei`
FROM `legislacao`
WHERE `situacao_da_lei` IS NULL OR `situacao_da_lei` <> 'Lei Revogada';

USE `gmodelo_dbp`;

CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_grid` AS
SELECT
    x.`CODLEGISLACAO`,
    u.`DSCUNIDADE`,
    p.`PODER`,
    CASE
        WHEN COALESCE(s.`q_pa`, 0) > 0 THEN 'Parcialmente Atendido'
        WHEN COALESCE(s.`q_n`, 0) > 0 AND COALESCE(s.`q_s`, 0) > 0 THEN 'Parcialmente Atendido'
        WHEN COALESCE(s.`q_s`, 0) > 0 AND COALESCE(s.`q_i`, 0) > 0 THEN 'Atendimento Indefinido!'
        WHEN COALESCE(s.`q_n`, 0) > 0 AND COALESCE(s.`q_i`, 0) > 0 THEN 'Não'
        WHEN COALESCE(s.`q_s`, 0) > 0 THEN 'Sim'
        WHEN COALESCE(s.`q_n`, 0) > 0 THEN 'Não'
        ELSE 'Atendimento Indefinido!'
    END AS `ATENDIDO`,
    l.`CODIGO`,
    a.`DSCAMBITO`,
    t.`DSCTIPO`,
    l.`NR`,
    l.`ANO`,
    l.`PADRAO`,
    (SELECT GROUP_CONCAT(DISTINCT ass.`DSCASSUNTO` ORDER BY ass.`DSCASSUNTO` SEPARATOR '; ')
       FROM `glegislacao_dbp`.`legislacaoxassunto` la
       INNER JOIN `glegislacao_dbp`.`assuntos` ass ON ass.`CODASSUNTO` = la.`CODASSUNTO`
      WHERE la.`CODLEGISLACAO` = x.`CODLEGISLACAO`) AS `DSCASSUNTO`,
    l.`ARTIGOS`,
    l.`MEIO_AMBIENTE`,
    l.`SEGURANCA`,
    l.`QUALIDADE`,
    l.`RESPOSABILIDADE`,
    x.`CODLEGISLACAXEMPRESA`,
    x.`codunidade` AS `CODUNIDADE`,
    l.`CODTIPO`,
    l.`CODAMBITO`,
    x.`CODEMPRESA`
FROM `legislacaoxempresa` x
INNER JOIN `glegislacao_dbp`.`vw_legislacao_nao_revogada` l ON l.`CODLEGISLACAO` = x.`CODLEGISLACAO`
LEFT JOIN `unidades` u ON u.`CODUNIDADE` = x.`codunidade`
LEFT JOIN `glegislacao_dbp`.`ambitos` a ON a.`CODAMBITO` = l.`CODAMBITO`
LEFT JOIN `glegislacao_dbp`.`tipos` t ON t.`CODTIPO` = l.`CODTIPO`
LEFT JOIN (
    SELECT `CODLEGISLACAO`, `codunidade`, MAX(`poder`) AS `PODER`
    FROM `legislacaoxpoder`
    GROUP BY `CODLEGISLACAO`, `codunidade`
) p ON p.`CODLEGISLACAO` = x.`CODLEGISLACAO` AND p.`codunidade` = x.`codunidade`
LEFT JOIN (
    SELECT `CODLEGISLACAO`, `codunidade`,
           SUM(CASE WHEN `atendido` = 'PA' THEN 1 ELSE 0 END) AS `q_pa`,
           SUM(CASE WHEN `atendido` = 'S' THEN 1 ELSE 0 END) AS `q_s`,
           SUM(CASE WHEN `atendido` = 'N' THEN 1 ELSE 0 END) AS `q_n`,
           SUM(CASE WHEN `atendido` NOT IN ('S', 'N', 'PA') OR `atendido` IS NULL THEN 1 ELSE 0 END) AS `q_i`
    FROM `legislacaoxsituacao`
    GROUP BY `CODLEGISLACAO`, `codunidade`
) s ON s.`CODLEGISLACAO` = x.`CODLEGISLACAO` AND s.`codunidade` = x.`codunidade`;

INSERT INTO `menus` (`codmenu_pai`, `modulo`, `codigo`, `label`, `label_pt`, `label_en`, `label_es`, `url`, `nivel`, `ordem`, `ativo`)
SELECT m.`codmenu`, 'MODULO_LEGAL', 'REQUISITOS_LEGAIS', 'Requisitos legais', 'Requisitos legais', 'Legal Requirements', 'Requisitos legales', '/principal/modulolegal_requisitoslegais.php', 2, 1, 1
FROM `menus` m
WHERE m.`codigo` = 'MODULO_LEGAL'
  AND NOT EXISTS (SELECT 1 FROM `menus` e WHERE e.`codigo` = 'REQUISITOS_LEGAIS');

INSERT INTO `permissoes` (`codperfil`, `codmenu`, `pode_incluir`, `pode_visualizar`, `pode_editar`, `pode_excluir`, `pode_exportar_excel`, `pode_gerar_pdf`)
SELECT p.`codperfil`, m.`codmenu`, 0,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       0, 0,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       0
FROM `perfis` p CROSS JOIN `menus` m
WHERE p.`ativo` = 1 AND m.`codigo` = 'REQUISITOS_LEGAIS'
  AND NOT EXISTS (SELECT 1 FROM `permissoes` e WHERE e.`codperfil` = p.`codperfil` AND e.`codmenu` = m.`codmenu`);
