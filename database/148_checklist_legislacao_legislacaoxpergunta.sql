USE `glegislacao_dbp`;

CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_checklist_grid` AS
SELECT x.`CODLEGISLACAO`, l.`CODIGO`, x.`CODPERGUNTA`,
       COALESCE(p.`DSCPERGUNTA`, '') AS `PERGUNTA`
FROM `legislacaoxpergunta` x
LEFT JOIN `legislacao` l ON l.`CODLEGISLACAO` = x.`CODLEGISLACAO`
LEFT JOIN `perguntas` p ON p.`CODPERGUNTA` = x.`CODPERGUNTA`;

SET @checklist_unique_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'legislacaoxpergunta'
       AND INDEX_NAME = 'uk_legislacaoxpergunta_legislacao_pergunta') = 0,
    'ALTER TABLE `legislacaoxpergunta` ADD UNIQUE KEY `uk_legislacaoxpergunta_legislacao_pergunta` (`CODLEGISLACAO`, `CODPERGUNTA`)',
    'SELECT 1'
);
PREPARE checklist_unique_stmt FROM @checklist_unique_ddl;
EXECUTE checklist_unique_stmt;
DEALLOCATE PREPARE checklist_unique_stmt;
