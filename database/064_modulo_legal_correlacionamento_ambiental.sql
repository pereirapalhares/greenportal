-- Green Portal - Módulo Legal / Correlacionamento Ambiental.
-- Equivalente moderno da transação legada legislacaoxambiental.

USE `gmodelo_dbp`;

CREATE OR REPLACE VIEW `vw_modulolegal_correlacionamento_ambiental_grid` AS
SELECT DISTINCT
       le.`CODLEGISLACAO`,
       le.`CODIGO`,
       le.`NR`,
       le.`ANO`,
       le.`ARTIGOS`,
       le.`MEIO_AMBIENTE`,
       le.`SEGURANCA`,
       le.`QUALIDADE`,
       le.`RESPOSABILIDADE`,
       ue.`CODUNIDADE`,
       u.`DSCUNIDADE`,
       lp.`PODER` AS `NIVEL_DE_CONTROLE`,
       tp.`DSCTIPO`,
       am.`DSCAMBITO`,
       GROUP_CONCAT(DISTINCT ass.`DSCASSUNTO` ORDER BY ass.`DSCASSUNTO` SEPARATOR ', ') AS `DSCASSUNTO`,
       (SELECT COUNT(*) FROM `legislacaoximpacto` li
         WHERE li.`CODLEGISLACAO`=le.`CODLEGISLACAO` AND li.`codunidade`=ue.`CODUNIDADE`) AS `QTD_IMPACTOS`,
       (SELECT COUNT(*) FROM `legislacaoxlicencas` ll
         WHERE ll.`CODLEGISLACAO`=le.`CODLEGISLACAO` AND ll.`codunidade`=ue.`CODUNIDADE`) AS `QTD_LICENCAS`,
       (SELECT COUNT(DISTINCT da.`CODDOCUMENTO`)
          FROM `documentosxunidades` du
          INNER JOIN `documentos` da ON da.`CODDOCUMENTO`=du.`CODDOCUMENTO`
         WHERE du.`CODUNIDADE`=ue.`CODUNIDADE`) AS `QTD_DOCUMENTOS`
  FROM `glegislacao_dbp`.`legislacao` le
  INNER JOIN `legislacaoxempresa` ue ON ue.`CODLEGISLACAO`=le.`CODLEGISLACAO`
  LEFT JOIN `unidades` u ON u.`CODUNIDADE`=ue.`CODUNIDADE`
  LEFT JOIN `legislacaoxpoder` lp ON lp.`CODLEGISLACAO`=le.`CODLEGISLACAO` AND lp.`codunidade`=ue.`CODUNIDADE`
  LEFT JOIN `glegislacao_dbp`.`tipos` tp ON tp.`CODTIPO`=le.`CODTIPO`
  LEFT JOIN `glegislacao_dbp`.`ambitos` am ON am.`CODAMBITO`=le.`CODAMBITO`
  LEFT JOIN `glegislacao_dbp`.`legislacaoxassunto` lxa ON lxa.`CODLEGISLACAO`=le.`CODLEGISLACAO`
  LEFT JOIN `glegislacao_dbp`.`assuntos` ass ON ass.`CODASSUNTO`=lxa.`CODASSUNTO`
 WHERE LOWER(TRIM(COALESCE(le.`MEIO_AMBIENTE`,''))) IN ('sim','1','true','s')
 GROUP BY le.`CODLEGISLACAO`,le.`CODIGO`,le.`NR`,le.`ANO`,le.`ARTIGOS`,le.`MEIO_AMBIENTE`,
          le.`SEGURANCA`,le.`QUALIDADE`,le.`RESPOSABILIDADE`,ue.`CODUNIDADE`,u.`DSCUNIDADE`,
          lp.`PODER`,tp.`DSCTIPO`,am.`DSCAMBITO`
 ORDER BY le.`CODIGO`,ue.`CODUNIDADE`;

INSERT INTO `menus`
 (`codmenu_pai`,`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
SELECT p.`codmenu`,'MODULO_LEGAL','CORRELACIONAMENTO_AMBIENTAL','Correlacionamento Ambiental',
       'Correlacionamento Ambiental','Environmental Correlation','Correlacionamiento ambiental',
       '/principal/modulolegal_correlacionamnetoambiental.php',2,33,1
  FROM `menus` p
 WHERE p.`codigo`='MODULO_LEGAL'
   AND NOT EXISTS (SELECT 1 FROM `menus` m WHERE m.`codigo`='CORRELACIONAMENTO_AMBIENTAL');

INSERT INTO `permissoes`
 (`codperfil`,`codmenu`,`pode_incluir`,`pode_visualizar`,`pode_editar`,`pode_excluir`,`pode_exportar_excel`,`pode_gerar_pdf`)
SELECT p.`codperfil`,m.`codmenu`,0,IF(p.`codigo`='ADMINISTRADOR',1,0),0,0,IF(p.`codigo`='ADMINISTRADOR',1,0),0
  FROM `perfis` p CROSS JOIN `menus` m
 WHERE p.`ativo`=1 AND m.`codigo`='CORRELACIONAMENTO_AMBIENTAL'
   AND NOT EXISTS (SELECT 1 FROM `permissoes` x WHERE x.`codperfil`=p.`codperfil` AND x.`codmenu`=m.`codmenu`);

INSERT INTO `registros_migracao`
 (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('MODULO_LEGAL_CORRELACIONAMENTO_AMBIENTAL_064',
        'green_legislacao.legislacao; green_modelo.legislacaoxempresa; green_modelo.legislacaoxpoder; green_modelo.legislacaoximpacto; green_modelo.legislacaoxlicencas; green_modelo.documentos*',
        'gmodelo_dbp.vw_modulolegal_correlacionamento_ambiental_grid; gmodelo_dbp.menus; gmodelo_dbp.permissoes',
        'ESTRUTURA','1.0.0','CONCLUIDA',
        (SELECT COUNT(*) FROM `vw_modulolegal_correlacionamento_ambiental_grid`),CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
        JSON_OBJECT('transacao_legada','legislacaoxambiental','arquivo_legado','E:/wamp/www/cadastros/legislacaoxambiental_c.php','filtro_ambiental','MEIO_AMBIENTE=1','chaves_relacionamento',JSON_ARRAY('CODLEGISLACAO','CODUNIDADE')))
ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
