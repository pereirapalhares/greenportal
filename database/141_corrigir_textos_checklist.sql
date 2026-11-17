-- Corrige os textos do atendimento do checklist.
USE gmodelo_dbp;
SET NAMES utf8mb4;

CREATE OR REPLACE VIEW vw_modulolegal_requisitos_checklist_grid AS
SELECT c.codlegislacao AS CODLEGISLACAO, l.CODIGO AS CODIGO, c.codunidade AS CODUNIDADE,
       COALESCE(e.nome, e.codigo, '') AS EMPRESA, COALESCE(u.DSCUNIDADE, '') AS UNIDADE,
       c.codlegislacaoxchecklist, c.codpergunta, COALESCE(p.DSCPERGUNTA, '') AS PERGUNTA, c.ATENDE,
       CASE c.ATENDE WHEN 'S' THEN 'Atendido'
            WHEN 'N' THEN CONVERT(0x4ec3a36f206174656e6469646f USING utf8mb4)
            WHEN 'PA' THEN 'Parcialmente atendido'
            ELSE CONVERT(0x4ec3a36f20696e666f726d61646f USING utf8mb4) END AS ATENDIMENTO,
       c.obs
  FROM legislacaoxchecklist c
  LEFT JOIN glegislacao_dbp.legislacao l ON l.CODLEGISLACAO = c.codlegislacao
  LEFT JOIN glegislacao_dbp.perguntas p ON p.CODPERGUNTA = c.codpergunta
  LEFT JOIN guniversal_dbp.empresa e ON e.codempresa = (
      SELECT MIN(x.CODEMPRESA) FROM legislacaoxempresa x
       WHERE x.CODLEGISLACAO = c.codlegislacao AND x.codunidade = c.codunidade)
  LEFT JOIN unidades u ON u.CODUNIDADE = c.codunidade;
