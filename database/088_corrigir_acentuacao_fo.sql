-- Recarrega textos das tabelas FO a partir da origem latin1, convertendo para UTF-8.
USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

UPDATE gmodelo_dbp.fo_tipofornecedores t JOIN green_modelo.fo_tipofornecedores s USING (codtipofornecedor)
   SET t.DSCTIPO=CONVERT(s.DSCTIPO USING utf8mb4), t.necessita_auditoria=CONVERT(s.necessita_auditoria USING utf8mb4);
UPDATE gmodelo_dbp.fo_tipofuncionarios t JOIN green_modelo.fo_tipofuncionarios s USING (codtipofuncionario)
   SET t.DSCTIPO=CONVERT(s.DSCTIPO USING utf8mb4);
UPDATE gmodelo_dbp.fo_fornecedores t JOIN green_modelo.fo_fornecedores s USING (codfornecedor)
   SET t.razaosocial=CONVERT(s.razaosocial USING utf8mb4), t.nomefantasia=CONVERT(s.nomefantasia USING utf8mb4),
       t.trabalha_instalacao_fornecedor=CONVERT(s.trabalha_instalacao_fornecedor USING utf8mb4), t.tem_produtos_quimicos=CONVERT(s.tem_produtos_quimicos USING utf8mb4),
       t.tem_equipamentos=CONVERT(s.tem_equipamentos USING utf8mb4), t.nivel_de_servicao=CONVERT(s.nivel_de_servicao USING utf8mb4),
       t.endereco=CONVERT(s.endereco USING utf8mb4), t.cidade=CONVERT(s.cidade USING utf8mb4), t.estado=CONVERT(s.estado USING utf8mb4),
       t.cep=CONVERT(s.cep USING utf8mb4), t.ativadofor=CONVERT(s.ativadofor USING utf8mb4), t.cnpj_cpf=CONVERT(s.cnpj_cpf USING utf8mb4),
       t.emails=CONVERT(s.emails USING utf8mb4), t.codigo_fornecedor_empresa=CONVERT(s.codigo_fornecedor_empresa USING utf8mb4),
       t.pais=CONVERT(s.pais USING utf8mb4), t.origem_informacao=CONVERT(s.origem_informacao USING utf8mb4);
UPDATE gmodelo_dbp.fo_funcionarios t JOIN green_modelo.fo_funcionarios s USING (CODFUNCIONARIO)
   SET t.NOME=CONVERT(s.NOME USING utf8mb4), t.CELULAR=CONVERT(s.CELULAR USING utf8mb4), t.TELEFONE=CONVERT(s.TELEFONE USING utf8mb4),
       t.EMAIL=CONVERT(s.EMAIL USING utf8mb4), t.NRIDENTIFICACAO=CONVERT(s.NRIDENTIFICACAO USING utf8mb4), t.INTEGRACAO=CONVERT(s.INTEGRACAO USING utf8mb4),
       t.status=CONVERT(s.status USING utf8mb4), t.setor=CONVERT(s.setor USING utf8mb4), t.ativo=CONVERT(s.ativo USING utf8mb4);
UPDATE gmodelo_dbp.unidades t JOIN green_modelo.unidades s USING (CODUNIDADE)
   SET t.DSCUNIDADE=CONVERT(s.DSCUNIDADE USING utf8mb4), t.usado_em_manutencao_legal=CONVERT(s.usado_em_manutencao_legal USING utf8mb4),
       t.tipo_de_rcl=CONVERT(s.tipo_de_rcl USING utf8mb4);

INSERT INTO gmodelo_dbp.registros_migracao (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes)
VALUES ('CORRECAO_ACENTUACAO_FO_088','green_modelo','gmodelo_dbp.tabelas fo_ e unidades','DADOS','1.0.1','CONCLUIDA',0,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
        JSON_OBJECT('metodo','conversao explicita latin1 para utf8mb4','backup','20260819_fo_pre_088.sql'))
ON DUPLICATE KEY UPDATE status=VALUES(status),versao=VALUES(versao),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
