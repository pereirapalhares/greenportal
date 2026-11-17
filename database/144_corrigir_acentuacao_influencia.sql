-- Normaliza registros eventualmente gravados com dupla codificação.
USE `gmodelo_dbp`;

UPDATE `legislacaoxpoder`
   SET `poder` = CONVERT(0x496E666C75C3AA6E636961 USING utf8mb4)
 WHERE HEX(`poder`) = '496E666C75C383C2AA6E636961';

INSERT IGNORE INTO `registros_migracao`
 (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('CORRECAO_ACENTUACAO_INFLUENCIA_144',
        'gmodelo_dbp.legislacaoxpoder.poder',
        'gmodelo_dbp.legislacaoxpoder.poder',
        'CORRECAO','1.0.0','CONCLUIDA',ROW_COUNT(),NOW(),NOW(),
        JSON_OBJECT('valor_correto','Influência','codificacao','UTF-8'));
