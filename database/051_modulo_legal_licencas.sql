-- Módulo Legal / Licenças
-- View consolidada no banco gmodelo_db. Os dados auxiliares de usuários e
-- unidades continuam no banco da empresa Modelo (gmodelo_dbp).

USE `gmodelo_db`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

DROP PROCEDURE IF EXISTS `sp_crud_select`;
DROP PROCEDURE IF EXISTS `sp_crud_count`;
DROP PROCEDURE IF EXISTS `sp_crud_insert`;
DROP PROCEDURE IF EXISTS `sp_crud_update`;
DROP PROCEDURE IF EXISTS `sp_crud_delete`;
DELIMITER $$

CREATE PROCEDURE `sp_crud_select`(IN p_tabela VARCHAR(128), IN p_filtros JSON, IN p_busca VARCHAR(255), IN p_limite INT, IN p_offset INT)
SQL SECURITY INVOKER
BEGIN
  DECLARE v_where LONGTEXT DEFAULT '1=1'; DECLARE v_part LONGTEXT; DECLARE v_sql LONGTEXT;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_tabela AND TABLE_TYPE IN ('BASE TABLE','VIEW')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tabela ou view não autorizada'; END IF;
  IF p_filtros IS NOT NULL AND JSON_LENGTH(p_filtros)>0 THEN
    SELECT GROUP_CONCAT(CONCAT('`',k.campo,'` = ',QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros,CONCAT('$."',k.campo,'"'))))) SEPARATOR ' AND ') INTO v_part
    FROM JSON_TABLE(JSON_KEYS(p_filtros),'$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) k
    WHERE EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=p_tabela AND c.COLUMN_NAME=k.campo);
    IF v_part IS NOT NULL THEN SET v_where=CONCAT(v_where,' AND ',v_part); END IF;
  END IF;
  IF COALESCE(p_busca,'')<>'' THEN
    SELECT GROUP_CONCAT(CONCAT('`',c.COLUMN_NAME,'` LIKE ',QUOTE(CONCAT('%',p_busca,'%'))) SEPARATOR ' OR ') INTO v_part FROM INFORMATION_SCHEMA.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=p_tabela AND c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext');
    IF v_part IS NOT NULL THEN SET v_where=CONCAT(v_where,' AND (',v_part,')'); END IF;
  END IF;
  SET v_sql=CONCAT('SELECT * FROM `',DATABASE(),'`.`',p_tabela,'` WHERE ',v_where,' LIMIT ',LEAST(GREATEST(COALESCE(p_limite,25),1),1000),' OFFSET ',GREATEST(COALESCE(p_offset,0),0));
  SET @sql=v_sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
END$$

CREATE PROCEDURE `sp_crud_count`(IN p_tabela VARCHAR(128), IN p_filtros JSON, IN p_busca VARCHAR(255))
SQL SECURITY INVOKER
BEGIN
  DECLARE v_where LONGTEXT DEFAULT '1=1'; DECLARE v_part LONGTEXT; DECLARE v_sql LONGTEXT;
  IF p_filtros IS NOT NULL AND JSON_LENGTH(p_filtros)>0 THEN
    SELECT GROUP_CONCAT(CONCAT('`',k.campo,'` = ',QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros,CONCAT('$."',k.campo,'"'))))) SEPARATOR ' AND ') INTO v_part FROM JSON_TABLE(JSON_KEYS(p_filtros),'$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) k WHERE EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=p_tabela AND c.COLUMN_NAME=k.campo);
    IF v_part IS NOT NULL THEN SET v_where=CONCAT(v_where,' AND ',v_part); END IF;
  END IF;
  IF COALESCE(p_busca,'')<>'' THEN SELECT GROUP_CONCAT(CONCAT('`',c.COLUMN_NAME,'` LIKE ',QUOTE(CONCAT('%',p_busca,'%'))) SEPARATOR ' OR ') INTO v_part FROM INFORMATION_SCHEMA.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=p_tabela AND c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext'); IF v_part IS NOT NULL THEN SET v_where=CONCAT(v_where,' AND (',v_part,')'); END IF; END IF;
  SET v_sql=CONCAT('SELECT COUNT(*) AS total_registros FROM `',DATABASE(),'`.`',p_tabela,'` WHERE ',v_where); SET @sql=v_sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
END$$

CREATE PROCEDURE `sp_crud_insert`(IN p_tabela VARCHAR(128), IN p_dados JSON)
SQL SECURITY INVOKER
BEGIN
  DECLARE v_cols LONGTEXT; DECLARE v_vals LONGTEXT; DECLARE v_sql LONGTEXT;
  SELECT GROUP_CONCAT(CONCAT('`',k.campo,'`') ORDER BY k.campo SEPARATOR ',') INTO v_cols FROM JSON_TABLE(JSON_KEYS(p_dados),'$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) k;
  SELECT GROUP_CONCAT(IF(JSON_TYPE(JSON_EXTRACT(p_dados,CONCAT('$."',k.campo,'"')))='NULL','NULL',QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_dados,CONCAT('$."',k.campo,'"'))))) ORDER BY k.campo SEPARATOR ',') INTO v_vals FROM JSON_TABLE(JSON_KEYS(p_dados),'$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) k;
  SET v_sql=CONCAT('INSERT INTO `',DATABASE(),'`.`',p_tabela,'` (',v_cols,') VALUES (',v_vals,')'); SET @sql=v_sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s; SELECT ROW_COUNT() registros_afetados,LAST_INSERT_ID() chave_gerada;
END$$

CREATE PROCEDURE `sp_crud_update`(IN p_tabela VARCHAR(128), IN p_chave JSON, IN p_dados JSON)
SQL SECURITY INVOKER
BEGIN
  DECLARE v_set LONGTEXT; DECLARE v_where LONGTEXT; DECLARE v_sql LONGTEXT;
  SELECT GROUP_CONCAT(CONCAT('`',k.campo,'` = ',IF(JSON_TYPE(JSON_EXTRACT(p_dados,CONCAT('$."',k.campo,'"')))='NULL','NULL',QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_dados,CONCAT('$."',k.campo,'"')))))) ORDER BY k.campo SEPARATOR ',') INTO v_set FROM JSON_TABLE(JSON_KEYS(p_dados),'$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) k WHERE NOT EXISTS (SELECT 1 FROM JSON_TABLE(JSON_KEYS(p_chave),'$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) x WHERE x.campo=k.campo);
  SELECT GROUP_CONCAT(CONCAT('`',k.campo,'` = ',QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_chave,CONCAT('$."',k.campo,'"'))))) ORDER BY k.campo SEPARATOR ' AND ') INTO v_where FROM JSON_TABLE(JSON_KEYS(p_chave),'$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) k;
  SET v_sql=CONCAT('UPDATE `',DATABASE(),'`.`',p_tabela,'` SET ',v_set,' WHERE ',v_where); SET @sql=v_sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s; SELECT ROW_COUNT() registros_afetados;
END$$

CREATE PROCEDURE `sp_crud_delete`(IN p_tabela VARCHAR(128), IN p_chave JSON)
SQL SECURITY INVOKER
BEGIN
  DECLARE v_where LONGTEXT; DECLARE v_sql LONGTEXT;
  SELECT GROUP_CONCAT(CONCAT('`',k.campo,'` = ',QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_chave,CONCAT('$."',k.campo,'"'))))) ORDER BY k.campo SEPARATOR ' AND ') INTO v_where FROM JSON_TABLE(JSON_KEYS(p_chave),'$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) k;
  SET v_sql=CONCAT('DELETE FROM `',DATABASE(),'`.`',p_tabela,'` WHERE ',v_where); SET @sql=v_sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s; SELECT ROW_COUNT() registros_afetados;
END$$
DELIMITER ;

CREATE OR REPLACE VIEW `vw_licencas_grid` AS
SELECT l.CODLICENCA,l.CODUNIDADE,u.DSCUNIDADE,l.DSCLICENCA,
 DATE_FORMAT(l.DATA_VALIDADE,'%d/%m/%Y') DATA_VALIDADE,
 DATE_FORMAT(l.DATA_REACAO,'%d/%m/%Y') DATA_REACAO,
 DATE_FORMAT(l.DATA_PROTOCOLO,'%d/%m/%Y') DATA_PROTOCOLO,
 l.CODUSUARIO,COALESCE(us.nome,'') RESPONSAVEL,
 CONCAT_WS(';','licencas',l.CODLICENCA) ARQUIVOS,l.CODLISTA,l.EMPROTOCOLO,
 DATE_FORMAT(l.PROTOCOLADO_EM,'%d/%m/%Y') PROTOCOLADO_EM,
 IF(YEAR(l.PROTOCOLADO_EM)+YEAR(l.DATA_PROTOCOLO)=0,l.PROTOCOLADO_NO_PRAZO,IF(l.PROTOCOLADO_EM<=l.DATA_PROTOCOLO,'S','N')) PROTOCOLADO_NO_PRAZO,
 DATEDIFF(NOW(),l.PROTOCOLADO_EM) QTD_DIAS_EM_PROTOCOLO,l.CODSTATUS,l.OBSERVACAO,
 l.CODCLASSIFICACAO,l.CODFREQUENCIA,DATEDIFF(l.DATA_VALIDADE,NOW()) QTDDIAS,DATEDIFF(l.DATA_REACAO,NOW()) QTDDIAS2
FROM gmodelo_db.li_licencas l
LEFT JOIN gmodelo_dbp.unidades u ON u.CODUNIDADE=l.CODUNIDADE
LEFT JOIN gmodelo_dbp.usuarios us ON us.codusuario=l.CODUSUARIO;

INSERT INTO gmodelo_dbp.menus (codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,ativo)
SELECT m.codmenu,'MODULO_LEGAL','LICENCAS','Licenças','Licenças','Licenses','Licencias','/principal/manutencaolegal_licencas.php',2,2,1
FROM gmodelo_dbp.menus m WHERE m.codigo='MODULO_LEGAL'
AND NOT EXISTS (SELECT 1 FROM gmodelo_dbp.menus x WHERE x.codigo='LICENCAS');

UPDATE gmodelo_dbp.menus
SET codmenu_pai=(SELECT codmenu FROM (SELECT codmenu FROM gmodelo_dbp.menus WHERE codigo='MODULO_LEGAL') x),
    modulo='MODULO_LEGAL', ordem=2, url='/principal/manutencaolegal_licencas.php',
    label=CONVERT(0x4C6963656EC3A76173 USING utf8mb4),
    label_pt=CONVERT(0x4C6963656EC3A76173 USING utf8mb4),
    label_en='Licenses', label_es='Licencias', ativo=1
WHERE codigo='LICENCAS';

INSERT INTO gmodelo_dbp.permissoes (codperfil,codmenu,pode_incluir,pode_visualizar,pode_editar,pode_excluir,pode_exportar_excel,pode_gerar_pdf)
SELECT p.codperfil,m.codmenu,CASE WHEN p.codigo='ADMINISTRADOR' THEN 1 ELSE 0 END,
CASE WHEN p.codigo='ADMINISTRADOR' THEN 1 ELSE 0 END,CASE WHEN p.codigo='ADMINISTRADOR' THEN 1 ELSE 0 END,
CASE WHEN p.codigo='ADMINISTRADOR' THEN 1 ELSE 0 END,CASE WHEN p.codigo='ADMINISTRADOR' THEN 1 ELSE 0 END,0
FROM gmodelo_dbp.perfis p CROSS JOIN gmodelo_dbp.menus m WHERE p.ativo=1 AND m.codigo='LICENCAS'
AND NOT EXISTS (SELECT 1 FROM gmodelo_dbp.permissoes x WHERE x.codperfil=p.codperfil AND x.codmenu=m.codmenu);
