-- Green Portal - Correção da view do popup de alterações dos requisitos legais.
-- A rota do popup usa glegislacao_dbp como banco de consulta. A view anterior
-- havia sido criada somente em gmodelo_dbp, causando erro ao abrir o popup.

USE `glegislacao_dbp`;

CREATE OR REPLACE
  ALGORITHM=UNDEFINED
  SQL SECURITY INVOKER
VIEW `vw_modulolegal_requisitos_alteracoes_grid` AS
SELECT
    a.`codalteracao` AS `codalteracao`,
    a.`codlegislacao` AS `CODLEGISLACAO`,
    a.`tipo` AS `tipo`,
    a.`descricao` AS `descricao`,
    a.`data_da_alteracao` AS `data_da_alteracao`,
    t.`DSCTIPODEALTERACAO` AS `DSCTIPODEALTERACAO`
FROM `legislacaoxalteracao` AS a
LEFT JOIN `tipo_de_alteracao` AS t
       ON t.`DSCTIPODEALTERACAO` = a.`tipo`;

-- A view expõe CODLEGISLACAO para que sp_crud_select aplique o filtro
-- recebido pelo popup. A tela não consulta uma lista geral sem contexto.
