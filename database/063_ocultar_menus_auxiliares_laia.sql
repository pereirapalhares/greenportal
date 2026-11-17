-- Os grids LAIA relacionados são acessados pelos botões contextuais;
-- não devem aparecer como menus independentes na navegação.
UPDATE `gmodelo_dbp`.`menus`
   SET `ativo`=0,
       `label`='Requisitos legais',
       `label_pt`='Requisitos legais',
       `label_en`='Legal requirements',
       `label_es`='Requisitos legales'
 WHERE `codigo`='LAIA_REQUISITOS_LEGAIS';

UPDATE `gmodelo_dbp`.`menus`
   SET `ativo`=0,
       `label`='Controles e documentos',
       `label_pt`='Controles e documentos',
       `label_en`='Controls and documents',
       `label_es`='Controles y documentos'
 WHERE `codigo`='LAIA_CONTROLES_DOCUMENTOS';

UPDATE `gmodelo_dbp`.`menus`
   SET `ativo`=0,
       `label`='Objetivos e metas',
       `label_pt`='Objetivos e metas',
       `label_en`='Objectives and targets',
       `label_es`='Objetivos y metas'
 WHERE `codigo`='LAIA_OBJETIVOS_METAS';

INSERT INTO `gmodelo_dbp`.`registros_migracao`
 (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('MENU_LAIA_AUXILIARES_063','gmodelo_dbp.menus.LAIA_*','gmodelo_dbp.menus.LAIA_*','ESTRUTURA','1.0.0','CONCLUIDA',3,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,JSON_OBJECT('navegacao','oculta','acesso_contextual','preservado','acentuacao','corrigida'))
ON DUPLICATE KEY UPDATE status=VALUES(status),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
