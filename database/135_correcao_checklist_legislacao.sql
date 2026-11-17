-- Correção do filtro de legislações e do checklist contextual.

USE `glegislacao_dbp`;

CREATE OR REPLACE VIEW `vw_legislacao_nao_revogada` AS
SELECT `CODLEGISLACAO`, `CODIGO`, `CODAMBITO`, `CODTIPO`, `NR`, `ANO`, `ARTIGOS`,
       `MEIO_AMBIENTE`, `SEGURANCA`, `QUALIDADE`, `RESPOSABILIDADE`, `PADRAO`,
       `data_atualizacao`, `situacao_da_lei`, `devido_da_lei`
  FROM `legislacao`
 WHERE UPPER(TRIM(COALESCE(`situacao_da_lei`, ''))) NOT IN ('LEI REVOGADA', 'REVOGADA');

USE `gmodelo_dbp`;

USE `glegislacao_dbp`;
CREATE OR REPLACE VIEW `vw_legislacao_grid` AS
SELECT l.`CODLEGISLACAO`, l.`CODIGO`, rel.`CODEMPRESA`, rel.`CODUNIDADE`, alt.`MES_ANO_ATUALIZACAO`,
       l.`CODAMBITO`, l.`CODTIPO`, l.`NR`, l.`ANO`, l.`ARTIGOS`, l.`MEIO_AMBIENTE`, l.`SEGURANCA`,
       l.`QUALIDADE`, l.`RESPOSABILIDADE`, l.`PADRAO`, l.`data_atualizacao`, l.`situacao_da_lei`,
       l.`devido_da_lei`,
       (SELECT GROUP_CONCAT(a.`DSCASSUNTO` ORDER BY a.`DSCASSUNTO` SEPARATOR '; ')
          FROM `legislacaoxassunto` la INNER JOIN `assuntos` a ON a.`CODASSUNTO` = la.`CODASSUNTO`
         WHERE la.`CODLEGISLACAO` = l.`CODLEGISLACAO`) AS `ASSUNTOS`
  FROM `legislacao` l
  LEFT JOIN (SELECT `CODLEGISLACAO`, CONCAT('|', GROUP_CONCAT(DISTINCT `CODEMPRESA` ORDER BY `CODEMPRESA` SEPARATOR '|'), '|') AS `CODEMPRESA`,
                    CONCAT('|', GROUP_CONCAT(DISTINCT `codunidade` ORDER BY `codunidade` SEPARATOR '|'), '|') AS `CODUNIDADE`
               FROM `gmodelo_dbp`.`legislacaoxempresa` GROUP BY `CODLEGISLACAO`) rel ON rel.`CODLEGISLACAO` = l.`CODLEGISLACAO`
  LEFT JOIN (SELECT `codlegislacao`, CONCAT('|', GROUP_CONCAT(DISTINCT DATE_FORMAT(`data_da_alteracao`, '%Y-%m') ORDER BY `data_da_alteracao` SEPARATOR '|'), '|') AS `MES_ANO_ATUALIZACAO`
               FROM `legislacaoxalteracao` WHERE `data_da_alteracao` IS NOT NULL GROUP BY `codlegislacao`) alt ON alt.`codlegislacao` = l.`CODLEGISLACAO`
 WHERE UPPER(TRIM(COALESCE(l.`situacao_da_lei`, ''))) NOT IN ('LEI REVOGADA', 'REVOGADA');

USE `gmodelo_dbp`;

CREATE OR REPLACE VIEW `vw_legislacaoxempresa_matrix_all` AS
SELECT l.`CODLEGISLACAO`, l.`CODIGO`,
       (SELECT MIN(x.`CODEMPRESA`) FROM `legislacaoxempresa` x WHERE x.`CODLEGISLACAO` = l.`CODLEGISLACAO`) AS `CODEMPRESA`,
       DATE_FORMAT(l.`data_atualizacao`, '%Y-%m') AS `MES_ANO_ATUALIZACAO`,
       CASE WHEN EXISTS (SELECT 1 FROM `legislacaoxempresa` x WHERE x.`CODLEGISLACAO` = l.`CODLEGISLACAO`) THEN 'Sim' ELSE 'Não' END AS `CORRELACIONADO`
  FROM `glegislacao_dbp`.`vw_legislacao_nao_revogada` l;

CREATE OR REPLACE VIEW `vw_legislacaoxempresa_matrix` AS
SELECT MIN(x.`CODLEGISLACAXEMPRESA`) AS `CODLEGISLACAXEMPRESA`, x.`CODLEGISLACAO`, x.`CODEMPRESA`, l.`CODIGO`,
       DATE_FORMAT(l.`data_atualizacao`, '%Y-%m') AS `MES_ANO_ATUALIZACAO`, 'Sim' AS `CORRELACIONADO`
  FROM `legislacaoxempresa` x
  INNER JOIN `glegislacao_dbp`.`vw_legislacao_nao_revogada` l ON l.`CODLEGISLACAO` = x.`CODLEGISLACAO`
 GROUP BY x.`CODLEGISLACAO`, x.`CODEMPRESA`, l.`CODIGO`, l.`data_atualizacao`;

CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_checklist_grid` AS
SELECT c.`codlegislacao` AS `CODLEGISLACAO`, l.`CODIGO` AS `CODIGO`, c.`codunidade` AS `CODUNIDADE`,
       COALESCE(e.`nome`, e.`codigo`, '') AS `EMPRESA`, COALESCE(u.`DSCUNIDADE`, '') AS `UNIDADE`,
       c.`codlegislacaoxchecklist`, c.`codpergunta`, COALESCE(p.`DSCPERGUNTA`, '') AS `PERGUNTA`, c.`ATENDE`,
       CASE c.`ATENDE` WHEN 'S' THEN 'Atendido' WHEN 'N' THEN 'Não atendido' WHEN 'PA' THEN 'Parcialmente atendido' ELSE 'Não informado' END AS `ATENDIMENTO`, c.`obs`
  FROM `legislacaoxchecklist` c
  LEFT JOIN `glegislacao_dbp`.`legislacao` l ON l.`CODLEGISLACAO` = c.`codlegislacao`
  LEFT JOIN `glegislacao_dbp`.`perguntas` p ON p.`CODPERGUNTA` = c.`codpergunta`
  LEFT JOIN `guniversal_dbp`.`empresa` e ON e.`codempresa` = (SELECT MIN(x.`CODEMPRESA`) FROM `legislacaoxempresa` x WHERE x.`CODLEGISLACAO` = c.`codlegislacao` AND x.`codunidade` = c.`codunidade`)
  LEFT JOIN `unidades` u ON u.`CODUNIDADE` = c.`codunidade`;
