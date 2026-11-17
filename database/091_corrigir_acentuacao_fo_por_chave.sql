-- Correção definitiva de acentuação usando green_modelo como origem.
USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

UPDATE gmodelo_dbp.fo_contatos t JOIN green_modelo.fo_contatos s ON s.codcontato=t.codcontato
   SET t.nome_contato=CONVERT(s.nome_contato USING utf8mb4);
UPDATE gmodelo_dbp.fo_criteriofornecedores t JOIN green_modelo.fo_criteriofornecedores s ON s.codcriteriofornecedor=t.codcriteriofornecedor
   SET t.DSCRITERIO=CONVERT(s.DSCRITERIO USING utf8mb4);
UPDATE gmodelo_dbp.fo_criteriofuncionarios t JOIN green_modelo.fo_criteriofuncionarios s ON s.codcriteriofuncionario=t.codcriteriofuncionario
   SET t.DSCRITERIO=CONVERT(s.DSCRITERIO USING utf8mb4), t.tipo_de_documento=CONVERT(s.tipo_de_documento USING utf8mb4);
UPDATE gmodelo_dbp.fo_fornecedorxcriterios t JOIN green_modelo.fo_fornecedorxcriterios s ON s.codfornecedorxcriterios=t.codfornecedorxcriterios
   SET t.enviadoodocumento=CONVERT(s.enviadoodocumento USING utf8mb4), t.situacao=CONVERT(s.situacao USING utf8mb4),
       t.incluidocontrato=CONVERT(s.incluidocontrato USING utf8mb4), t.observacao=CONVERT(s.observacao USING utf8mb4),
       t.observacao_do_fornecedor=CONVERT(s.observacao_do_fornecedor USING utf8mb4);
UPDATE gmodelo_dbp.fo_fornecedorxcriterios_historico t JOIN green_modelo.fo_fornecedorxcriterios_historico s ON s.CODHISTORICO=t.CODHISTORICO
   SET t.enviadoodocumento=CONVERT(s.enviadoodocumento USING utf8mb4), t.observacao=CONVERT(s.observacao USING utf8mb4),
       t.observacao_do_fornecedor=CONVERT(s.observacao_do_fornecedor USING utf8mb4);
UPDATE gmodelo_dbp.fo_funcionarioxtiposxcriterios t JOIN green_modelo.fo_funcionarioxtiposxcriterios s ON s.codigo=t.codigo
   SET t.atendeaocriterio=CONVERT(s.atendeaocriterio USING utf8mb4), t.observacao=CONVERT(s.observacao USING utf8mb4);
UPDATE gmodelo_dbp.fo_funcionarioxtiposxtreinamentos t JOIN green_modelo.fo_funcionarioxtiposxtreinamentos s ON s.codigo=t.codigo
   SET t.treinamentorealizado=CONVERT(s.treinamentorealizado USING utf8mb4);
UPDATE gmodelo_dbp.fo_tipofornecedoresxcriteriofornecedores t JOIN green_modelo.fo_tipofornecedoresxcriteriofornecedores s ON s.codchave=t.codchave
   SET t.nivel_de_exigencia=CONVERT(s.nivel_de_exigencia USING utf8mb4);
UPDATE gmodelo_dbp.fo_tipotreinamentos t JOIN green_modelo.fo_tipotreinamentos s ON s.codtipotreinamento=t.codtipotreinamento
   SET t.DSCTIPO=CONVERT(s.DSCTIPO USING utf8mb4);

INSERT INTO gmodelo_dbp.registros_migracao
    (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes)
VALUES ('CORRECAO_ACENTUACAO_FO_POR_CHAVE_091','green_modelo','gmodelo_dbp.tabelas fo_','DADOS','1.0.0','CONCLUIDA',18523,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
        JSON_OBJECT('metodo','atualizacao por chave primaria','backup','20260819_fo_acentuacao_pre_091.sql'))
ON DUPLICATE KEY UPDATE status=VALUES(status),concluida_em=VALUES(concluida_em),quantidade_registros=VALUES(quantidade_registros),detalhes=VALUES(detalhes);
