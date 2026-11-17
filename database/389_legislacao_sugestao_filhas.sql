-- Migração 389 — Legislação › Legislações filhas: lista "Sugeridas" (possíveis filhas da lei escolhida) (17/11/2026).
-- Critérios medidos nos vínculos existentes (scripts/teste-sugestao-filhas-389b.php): mesmo âmbito E (tipo relacionado OU
-- assunto em comum). Os tipos relacionados e seus pontos vêm da tela (LegislacaoAssociacoes::tiposRelacionados) em p_tipos
-- JSON [{"cod":1,"pts":40},...]. Sem a própria lei e sem as filhas atuais; da mais provável para a menos provável;
-- TOTAL_REGISTROS em cada linha. v2: parte só das candidatas (tipos relacionados no mesmo âmbito + leis do mesmo
-- assunto), em vez de varrer todas as leis do âmbito (v1 levava 8 s numa lei federal). Blocos separados por "-- @@". Banco: glegislacao_dbp.
-- @@
DROP PROCEDURE IF EXISTS `sp_legislacao_sugestao_filhas`
-- @@
CREATE PROCEDURE `sp_legislacao_sugestao_filhas`(
    IN p_pai INT,
    IN p_tipos JSON,
    IN p_codtipo INT,
    IN p_busca VARCHAR(255),
    IN p_limite INT,
    IN p_offset INT
)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_amb INT DEFAULT NULL;
    DECLARE v_ano INT DEFAULT 0;
    DECLARE v_limite INT;
    DECLARE v_offset INT;
    DECLARE v_tipos JSON;
    SET v_limite = LEAST(GREATEST(COALESCE(p_limite, 25), 1), 1000);
    SET v_offset = GREATEST(COALESCE(p_offset, 0), 0);
    SET v_tipos = COALESCE(p_tipos, JSON_ARRAY());
    SELECT l.`CODAMBITO`, IF(CAST(l.`ANO` AS CHAR) REGEXP '^[0-9]{4}$', CAST(l.`ANO` AS UNSIGNED), 0)
      INTO v_amb, v_ano
      FROM `legislacao` l WHERE l.`CODLEGISLACAO` = p_pai LIMIT 1;
    SELECT y.*, COUNT(*) OVER () AS `TOTAL_REGISTROS`
      FROM (
        SELECT l.`CODLEGISLACAO`, l.`CODIGO`, l.`CODTIPO`, t.`DSCTIPO`, l.`NR`, l.`ANO`, l.`situacao_da_lei`,
               COALESCE(tp.`pts`, 0) AS `PONTOS_TIPO`,
               IF(sa.`CODLEGISLACAO` IS NULL, 0, 1) AS `MESMO_ASSUNTO`,
               c.`ANO_NUM`,
               COALESCE(tp.`pts`, 0) + IF(sa.`CODLEGISLACAO` IS NULL, 0, 15)
               + IF(v_ano = 0 OR c.`ANO_NUM` = 0 OR c.`ANO_NUM` >= v_ano, 5, 0)
               + IF(v_ano > 0 AND c.`ANO_NUM` > 0, GREATEST(0, 5 - FLOOR(ABS(CAST(c.`ANO_NUM` AS SIGNED) - CAST(v_ano AS SIGNED)) / 3)), 0) AS `PONTOS`
          FROM (
                -- candidatas 1: leis do mesmo âmbito com tipo relacionado
                SELECT l1.`CODLEGISLACAO`, IF(CAST(l1.`ANO` AS CHAR) REGEXP '^[0-9]{4}$', CAST(l1.`ANO` AS UNSIGNED), 0) AS `ANO_NUM`
                  FROM JSON_TABLE(v_tipos, '$[*]' COLUMNS (`cod` INT PATH '$.cod')) j
                  JOIN `legislacao` l1 ON l1.`CODTIPO` = j.`cod` AND l1.`CODAMBITO` <=> v_amb
                UNION
                -- candidatas 2: leis do mesmo âmbito com assunto em comum
                SELECT l2.`CODLEGISLACAO`, IF(CAST(l2.`ANO` AS CHAR) REGEXP '^[0-9]{4}$', CAST(l2.`ANO` AS UNSIGNED), 0)
                  FROM `legislacaoxassunto` a1
                  JOIN `legislacaoxassunto` a2 ON a2.`CODASSUNTO` = a1.`CODASSUNTO`
                  JOIN `legislacao` l2 ON l2.`CODLEGISLACAO` = a2.`CODLEGISLACAO` AND l2.`CODAMBITO` <=> v_amb
                 WHERE a1.`CODLEGISLACAO` = p_pai
               ) c
          JOIN `legislacao` l ON l.`CODLEGISLACAO` = c.`CODLEGISLACAO`
          LEFT JOIN `tipos` t ON t.`CODTIPO` = l.`CODTIPO`
          LEFT JOIN (SELECT jt.`cod`, MAX(jt.`pts`) AS `pts`
                       FROM JSON_TABLE(v_tipos, '$[*]' COLUMNS (`cod` INT PATH '$.cod', `pts` INT PATH '$.pts')) jt
                      GROUP BY jt.`cod`) tp ON tp.`cod` = l.`CODTIPO`
          LEFT JOIN (SELECT DISTINCT a2.`CODLEGISLACAO`
                       FROM `legislacaoxassunto` a1
                       JOIN `legislacaoxassunto` a2 ON a2.`CODASSUNTO` = a1.`CODASSUNTO`
                      WHERE a1.`CODLEGISLACAO` = p_pai) sa ON sa.`CODLEGISLACAO` = l.`CODLEGISLACAO`
         WHERE l.`CODLEGISLACAO` <> p_pai
           AND NOT EXISTS (SELECT 1 FROM `legislacaopaixlegislacaofilha` r WHERE r.`CODLEGISLACAO_PAI` = p_pai AND r.`CODLEGISLACAO_FILHA` = l.`CODLEGISLACAO`)
           AND (p_codtipo IS NULL OR p_codtipo = 0 OR l.`CODTIPO` = p_codtipo)
           AND (p_busca IS NULL OR p_busca = '' OR CONCAT_WS(' ', l.`CODIGO`, t.`DSCTIPO`, l.`NR`, l.`ANO`, l.`situacao_da_lei`, LEFT(l.`PADRAO`, 300)) LIKE CONCAT('%', p_busca, '%'))
      ) y
     ORDER BY y.`PONTOS` DESC, y.`ANO_NUM` DESC, y.`CODLEGISLACAO`
     LIMIT v_limite OFFSET v_offset;
END
