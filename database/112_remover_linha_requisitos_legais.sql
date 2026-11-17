-- Green Portal - Requisitos Legais: remove a coluna legada sem valor.
-- Aplicar no tenant correspondente após backup e validação em homologação.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE `gmodelo_dbp`;

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
    l.`NR`, l.`ANO`, l.`PADRAO`,
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
