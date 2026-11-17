-- Remove apenas preferências de colunas que não pertencem mais ao grid de Requisitos Legais.
-- A ordem personalizada das colunas atuais do usuário é preservada.
DELETE FROM `gmodelo_dbp`.`preferencias_grid`
 WHERE `transacao` = 'modulolegal_requisitoslegais'
   AND `coluna` NOT IN (
       'CODLEGISLACAO', 'UNIDADE', 'PADRAO', 'PODER', 'ATENDIDO', 'DSCAMBITO',
       'CODIGO', 'DSCTIPO', 'NR', 'ANO', 'DSCASSUNTO', 'ARTIGOS',
       'MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE',
       'CODLEGISLACAXEMPRESA', 'CODUNIDADE', 'CODTIPO', 'CODAMBITO', 'CODEMPRESA'
   );
