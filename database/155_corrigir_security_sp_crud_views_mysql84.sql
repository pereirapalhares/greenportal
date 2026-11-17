-- Corrige o erro MySQL 1615 ao executar SQL dinamico sobre views.
-- O procedimento e invocado pelo usuario da aplicacao, mas suas consultas
-- dinamicas sobre views devem usar o contexto do definidor autorizado.

USE `gmodelo_dbp`;

ALTER PROCEDURE `sp_crud_select` SQL SECURITY DEFINER;
ALTER PROCEDURE `sp_crud_count` SQL SECURITY DEFINER;

