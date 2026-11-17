-- Modulo Legal / Revisao do LPRO, migrado do legado.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`levantamentoocupacionalxrevisoes`
  LIKE `green_modelo`.`levantamentoocupacionalxrevisoes`;
ALTER TABLE `gmodelo_dbp`.`levantamentoocupacionalxrevisoes`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
INSERT IGNORE INTO `gmodelo_dbp`.`levantamentoocupacionalxrevisoes`
  (`CHAVE`,`DSCREVISAO`,`DATA`)
SELECT `CHAVE`,`DSCREVISAO`,`DATA`
  FROM `green_modelo`.`levantamentoocupacionalxrevisoes`;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_lpro_revisao_grid` AS
SELECT `CHAVE`,`DSCREVISAO`,`DATA`
  FROM `gmodelo_dbp`.`levantamentoocupacionalxrevisoes`;

INSERT INTO `gmodelo_dbp`.`menus`
  (`codmenu_pai`,`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
SELECT p.`codmenu`,'MODULO_LEGAL','LPRO_REVISAO','Revisão do LPRO','Revisão do LPRO',
       'LPRO Review','Revisión del LPRO','/principal/manutencaolegal_revisaolpro.php',2,25,1
  FROM `gmodelo_dbp`.`menus` p
 WHERE p.`codigo`='MODULO_LEGAL'
   AND NOT EXISTS (SELECT 1 FROM `gmodelo_dbp`.`menus` m WHERE m.`codigo`='LPRO_REVISAO');

INSERT INTO `gmodelo_dbp`.`permissoes`
  (`codperfil`,`codmenu`,`pode_incluir`,`pode_visualizar`,`pode_editar`,`pode_excluir`,`pode_exportar_excel`,`pode_gerar_pdf`)
SELECT p.`codperfil`,m.`codmenu`,
       IF(p.`codigo`='ADMINISTRADOR',1,0),IF(p.`codigo`='ADMINISTRADOR',1,0),
       IF(p.`codigo`='ADMINISTRADOR',1,0),IF(p.`codigo`='ADMINISTRADOR',1,0),
       IF(p.`codigo`='ADMINISTRADOR',1,0),0
  FROM `gmodelo_dbp`.`perfis` p CROSS JOIN `gmodelo_dbp`.`menus` m
 WHERE p.`ativo`=1 AND m.`codigo`='LPRO_REVISAO'
   AND NOT EXISTS (SELECT 1 FROM `gmodelo_dbp`.`permissoes` x
                    WHERE x.`codperfil`=p.`codperfil` AND x.`codmenu`=m.`codmenu`);

INSERT IGNORE INTO `guniversal_dbp`.`ajuda_transacoes`
 (`transacao`,`titulo_pt`,`titulo_en`,`titulo_es`,`texto_pt`,`texto_en`,`texto_es`,`ativo`)
VALUES ('manutencaolegal_revisaolpro','Revisão do LPRO','LPRO review','Revisión del LPRO',
        'Cadastre e consulte as revisões do LPRO.',
        'Register and consult LPRO reviews.',
        'Registre y consulte las revisiones del LPRO.',1);

INSERT INTO `gmodelo_dbp`.`registros_migracao`
  (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('MODULO_LEGAL_REVISAO_LPRO_154','green_modelo.levantamentoocupacionalxrevisoes',
        'gmodelo_dbp.levantamentoocupacionalxrevisoes; gmodelo_dbp.vw_lpro_revisao_grid',
        'DADOS','1.0.0','CONCLUIDA',
        (SELECT COUNT(*) FROM `gmodelo_dbp`.`levantamentoocupacionalxrevisoes`),
        CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
        JSON_OBJECT('transacao','manutencaolegal_revisaolpro','menu','LPRO_REVISAO',
                    'chave','CHAVE','acentuacao_utf8mb4',TRUE))
ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),
  concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
