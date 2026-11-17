-- Corrige o literal Não que tambem existe em parte dos dados da origem.
USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

UPDATE fo_fornecedorxcriterios
   SET observacao_do_fornecedor = REPLACE(
       observacao_do_fornecedor,
       CONVERT(0x4EC383C2A36F USING utf8mb4),
       CONVERT(0x4EC3A36F USING utf8mb4))
 WHERE HEX(observacao_do_fornecedor) LIKE '%4EC383C2A36F%';

INSERT INTO registros_migracao
    (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes)
VALUES ('CORRECAO_ACENTUACAO_RESIDUAL_FO_092','dados de origem com Não','gmodelo_dbp.fo_fornecedorxcriterios','DADOS','1.0.0','CONCLUIDA',1780,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
        JSON_OBJECT('backup','20260819_fo_fornecedorxcriterios_pre_092.sql','substituicao','Não'))
ON DUPLICATE KEY UPDATE status=VALUES(status),concluida_em=VALUES(concluida_em),quantidade_registros=VALUES(quantidade_registros),detalhes=VALUES(detalhes);
