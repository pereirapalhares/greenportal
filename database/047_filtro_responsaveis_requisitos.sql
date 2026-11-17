-- Green Portal - filtro de responsaveis em Modulo Legal / Requisitos legais.
-- A tabela pertence ao banco da empresa e conserva as chaves do legado.

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
    PRIMARY KEY (`CODSITUACAO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `legislacaoxsituacao`
    (`CODSITUACAO`, `CODLEGISLACAO`, `dscsituacao`, `atendido`, `CODUSUARIO`, `OBS`, `codunidade`, `data_de_avaliacao`, `data_de_vencimento_avaliacao`, `CODLEGISLACAOCODUNIDADE`)
SELECT `CODSITUACAO`, `CODLEGISLACAO`, CONVERT(`dscsituacao` USING utf8mb4), CONVERT(`atendido` USING utf8mb4), `CODUSUARIO`, CONVERT(`OBS` USING utf8mb4), `codunidade`, `data_de_avaliacao`, `data_de_vencimento_avaliacao`, CONVERT(`CODLEGISLACAOCODUNIDADE` USING utf8mb4)
FROM `green_modelo`.`legislacaoxsituacao`
ON DUPLICATE KEY UPDATE
    `CODLEGISLACAO` = VALUES(`CODLEGISLACAO`),
    `dscsituacao` = VALUES(`dscsituacao`),
    `atendido` = VALUES(`atendido`),
    `CODUSUARIO` = VALUES(`CODUSUARIO`),
    `OBS` = VALUES(`OBS`),
    `codunidade` = VALUES(`codunidade`),
    `data_de_avaliacao` = VALUES(`data_de_avaliacao`),
    `data_de_vencimento_avaliacao` = VALUES(`data_de_vencimento_avaliacao`),
    `CODLEGISLACAOCODUNIDADE` = VALUES(`CODLEGISLACAOCODUNIDADE`);

SET @criar_indice_responsavel = (
    SELECT IF(COUNT(*) = 0,
        'CREATE INDEX `ix_lxsituacao_leg_unidade_usuario` ON `legislacaoxsituacao` (`CODLEGISLACAO`, `codunidade`, `CODUSUARIO`)',
        'SELECT 1')
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'legislacaoxsituacao'
      AND INDEX_NAME = 'ix_lxsituacao_leg_unidade_usuario'
);
PREPARE stmt_indice_responsavel FROM @criar_indice_responsavel;
EXECUTE stmt_indice_responsavel;
DEALLOCATE PREPARE stmt_indice_responsavel;

CREATE OR REPLACE VIEW `vw_modulolegal_responsaveis` AS
SELECT DISTINCT
    u.`codusuario` AS `CODUSUARIO`,
    u.`userid` AS `USERID`,
    u.`nome` AS `NOME`
FROM `legislacaoxsituacao` s
INNER JOIN `usuarios` u ON u.`codusuario` = s.`CODUSUARIO`
WHERE s.`CODUSUARIO` IS NOT NULL
  AND EXISTS (
      SELECT 1
      FROM `legislacaoxsituacao` sx
      WHERE sx.`CODUSUARIO` = u.`codusuario`
  );

CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_grid` AS
SELECT
    x.`CODLEGISLACAO`,
    u.`DSCUNIDADE`,
    COALESCE(u.`DSCUNIDADE`, '') AS `UNIDADE`,
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
    CASE WHEN UPPER(TRIM(COALESCE(l.`situacao_da_lei`, ''))) = 'LEI REVOGADA' THEN 'SIM' ELSE 'NAO' END AS `LEI_REVOGADA`,
    a.`DSCAMBITO`,
    t.`DSCTIPO`,
    l.`NR`,
    l.`ANO`,
    l.`PADRAO`,
    (SELECT GROUP_CONCAT(DISTINCT ass.`DSCASSUNTO` ORDER BY ass.`DSCASSUNTO` SEPARATOR '; ')
       FROM `glegislacao_dbp`.`legislacaoxassunto` la
       INNER JOIN `glegislacao_dbp`.`assuntos` ass ON ass.`CODASSUNTO` = la.`CODASSUNTO`
      WHERE la.`CODLEGISLACAO` = x.`CODLEGISLACAO`) AS `DSCASSUNTO`,
    l.`ARTIGOS`, l.`MEIO_AMBIENTE`, l.`SEGURANCA`, l.`QUALIDADE`, l.`RESPOSABILIDADE`,
    x.`CODLEGISLACAXEMPRESA`, x.`codunidade` AS `CODUNIDADE`, l.`CODTIPO`, l.`CODAMBITO`, x.`CODEMPRESA`,
    CASE WHEN s.`responsavel_ids` IS NULL THEN '' ELSE CONCAT(',', s.`responsavel_ids`, ',') END AS `RESPONSAVEL_IDS`
FROM `legislacaoxempresa` x
INNER JOIN `glegislacao_dbp`.`legislacao` l ON l.`CODLEGISLACAO` = x.`CODLEGISLACAO`
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
           SUM(CASE WHEN `atendido` NOT IN ('S', 'N', 'PA') OR `atendido` IS NULL THEN 1 ELSE 0 END) AS `q_i`,
           GROUP_CONCAT(DISTINCT `CODUSUARIO` ORDER BY `CODUSUARIO` SEPARATOR ',') AS `responsavel_ids`
    FROM `legislacaoxsituacao`
    GROUP BY `CODLEGISLACAO`, `codunidade`
) s ON s.`CODLEGISLACAO` = x.`CODLEGISLACAO` AND s.`codunidade` = x.`codunidade`;
