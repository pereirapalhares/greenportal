UPDATE `gmodelo_dbp`.`menus`
SET `label`=CONVERT(0x416a75646120646173207472616e7361c3a7c3b56573 USING utf8mb4),
    `label_pt`=CONVERT(0x416a75646120646173207472616e7361c3a7c3b56573 USING utf8mb4),
    `label_en`=CONVERT(0x5472616e73616374696f6e2068656c70 USING utf8mb4),
    `label_es`=CONVERT(0x4179756461206465206c6173207472616e73616363696f6e6573 USING utf8mb4)
WHERE `codigo`='CONFIG_AJUDA_TRANSACOES';

INSERT INTO `gmodelo_dbp`.`registros_migracao`
(`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('AJUDA_MENU_UTF8_069','gmodelo_dbp.menus','gmodelo_dbp.menus','CORRECAO','1.0.0','CONCLUIDA',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,JSON_OBJECT('menu','CONFIG_AJUDA_TRANSACOES','utf8',TRUE))
ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
