-- Green Portal - Situação quanto ao cumprimento conforme mlLegislacaoXSituacao.
USE `gmodelo_dbp`;

DROP FUNCTION IF EXISTS `GETATENDIDO`;
DELIMITER $$
CREATE FUNCTION `GETATENDIDO`(in_codlegislacao INT, in_codunidade INT)
RETURNS VARCHAR(100) CHARSET utf8mb4
READS SQL DATA
BEGIN
    DECLARE v_pa INT DEFAULT 0;
    DECLARE v_ai INT DEFAULT 0;
    DECLARE v_s INT DEFAULT 0;
    DECLARE v_n INT DEFAULT 0;

    SELECT COALESCE(SUM(CASE WHEN atendido='PA' THEN 1 ELSE 0 END),0),
           COALESCE(SUM(CASE WHEN atendido='AI' OR atendido NOT IN ('N','S','PA') THEN 1 ELSE 0 END),0),
           COALESCE(SUM(CASE WHEN atendido='S' THEN 1 ELSE 0 END),0),
           COALESCE(SUM(CASE WHEN atendido='N' THEN 1 ELSE 0 END),0)
      INTO v_pa,v_ai,v_s,v_n
      FROM `legislacaoxsituacao`
     WHERE `CODLEGISLACAO`=in_codlegislacao AND `codunidade`=in_codunidade;

    IF v_pa>0 THEN RETURN 'Parcialmente Atendido'; END IF;
    IF v_n>0 AND v_s>0 THEN RETURN 'Parcialmente Atendido'; END IF;
    IF v_s>0 AND v_ai>0 THEN RETURN 'Atendimento Indefinido!'; END IF;
    IF v_n>0 AND v_ai>0 THEN RETURN 'Não'; END IF;
    IF v_s>0 AND v_ai>0 AND v_n>0 THEN RETURN 'Atendimento Indefinido!'; END IF;
    IF v_s>0 THEN RETURN 'Sim'; END IF;
    IF v_n>0 THEN RETURN 'Não'; END IF;
    IF v_ai>0 THEN RETURN 'Atendimento Indefinido!'; END IF;
    RETURN 'Atendimento Indefinido!';
END$$
DELIMITER ;

CREATE OR REPLACE VIEW `vw_modulolegal_situacao_cumprimento_grid` AS
SELECT s.`CODSITUACAO`, s.`CODLEGISLACAO`, s.`codunidade`,
       a.`DSCASSUNTO`, u.`DSCUNIDADE`, p.`poder` AS `NIVEL_DE_CONTROLE`,
       COALESCE(us.`nome`,'') AS `NOME`, l.`CODIGO` AS `TEXTODALEI`,
       s.`dscsituacao` AS `DSCSITUACAO`, GETATENDIDO(s.`CODLEGISLACAO`,s.`codunidade`) AS `ATENDIDO`,
       s.`OBS`, s.`data_de_avaliacao` AS `DATA_DE_AVALIACAO`,
       s.`data_de_vencimento_avaliacao` AS `DATA_DE_VENCIMENTO_AVALIACAO`,
       s.`CODUSUARIO`
  FROM `legislacaoxsituacao` s
  INNER JOIN `glegislacao_dbp`.`legislacao` l ON l.`CODLEGISLACAO`=s.`CODLEGISLACAO`
  INNER JOIN `unidades` u ON u.`CODUNIDADE`=s.`codunidade`
  INNER JOIN `glegislacao_dbp`.`legislacaoxassunto` la ON la.`CODLEGISLACAO`=l.`CODLEGISLACAO`
  INNER JOIN `glegislacao_dbp`.`assuntos` a ON a.`CODASSUNTO`=la.`CODASSUNTO`
  INNER JOIN `legislacaoxpoder` p ON p.`CODLEGISLACAO`=s.`CODLEGISLACAO` AND p.`codunidade`=s.`codunidade`
  LEFT JOIN `usuarios` us ON us.`codusuario`=s.`CODUSUARIO`;

INSERT INTO `menus`
 (`codmenu_pai`,`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
SELECT p.`codmenu`,'MODULO_LEGAL','SITUACAO_CUMPRIMENTO','Situação quanto ao cumprimento','Situação quanto ao cumprimento','Compliance status','Situación de cumplimiento','/principal/modulolegal_situacaocumprimento.php',2,32,1
  FROM `menus` p
 WHERE p.`codigo`='MODULO_LEGAL'
   AND NOT EXISTS (SELECT 1 FROM `menus` m WHERE m.`codigo`='SITUACAO_CUMPRIMENTO');

INSERT INTO `permissoes`
 (`codperfil`,`codmenu`,`pode_incluir`,`pode_visualizar`,`pode_editar`,`pode_excluir`,`pode_exportar_excel`,`pode_gerar_pdf`)
SELECT p.`codperfil`,m.`codmenu`,0,IF(p.`codigo`='ADMINISTRADOR',1,0),0,0,IF(p.`codigo`='ADMINISTRADOR',1,0),0
  FROM `perfis` p CROSS JOIN `menus` m
 WHERE p.`ativo`=1 AND m.`codigo`='SITUACAO_CUMPRIMENTO'
   AND NOT EXISTS (SELECT 1 FROM `permissoes` x WHERE x.`codperfil`=p.`codperfil` AND x.`codmenu`=m.`codmenu`);

INSERT INTO `registros_migracao`
 (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('MODULO_LEGAL_SITUACAO_CUMPRIMENTO_062','green_modelo.GETATENDIDO; green_modelo.legislacaoxsituacao','gmodelo_dbp.GETATENDIDO; gmodelo_dbp.vw_modulolegal_situacao_cumprimento_grid','ESTRUTURA','1.0.0','CONCLUIDA',(SELECT COUNT(*) FROM `legislacaoxsituacao`),CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,JSON_OBJECT('transacao','mlLegislacaoXSituacao','funcao_recriada','GETATENDIDO','seguranca_legada_desconsiderada',TRUE))
ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
