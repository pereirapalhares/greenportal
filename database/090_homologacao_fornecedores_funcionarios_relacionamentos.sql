-- Relacionamentos da Gestao de Funcionarios.
USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_homologacao_funcionario_criterios_grid` AS
SELECT r.codigo AS CODIGO, r.codfuncionario AS CODFUNCIONARIO, r.codtipofuncionario AS CODTIPOFUNCIONARIO,
       t.DSCTIPO AS DSCTIPO, c.DSCRITERIO AS DSCRITERIO, r.codusuario AS CODUSUARIO,
       r.atendeaocriterio AS ATENDEAOCRITERIO, r.responsaveldocumentacaofornecedor AS RESPONSAVELDOCUMENTACAOFORNECEDOR,
       r.datadevalidade AS DATADEVALIDADE, r.datadeinicio AS DATADEINICIO, r.observacao AS OBSERVACAO
  FROM fo_funcionarioxtiposxcriterios r
  LEFT JOIN fo_tipofuncionarios t ON t.codtipofuncionario = r.codtipofuncionario
  LEFT JOIN fo_criteriofuncionarios c ON c.codcriteriofuncionario = r.codcriteriofuncionario;

CREATE OR REPLACE VIEW `vw_homologacao_funcionario_treinamentos_grid` AS
SELECT r.codigo AS CODIGO, r.codfuncionario AS CODFUNCIONARIO, r.codtipofuncionario AS CODTIPOFUNCIONARIO,
       t.DSCTIPO AS DSCTIPO, tr.DSCTIPO AS DSCTIPOTREINAMENTO, r.codusuario AS CODUSUARIO,
       r.treinamentorealizado AS TREINAMENTOREALIZADO, r.responsaveltreinamento AS RESPONSAVELTREINAMENTO,
       r.datadevalidadetreinamento AS DATADEVALIDADETREINAMENTO, r.datadereacaotreinamneto AS DATADE_REACAO
  FROM fo_funcionarioxtiposxtreinamentos r
  LEFT JOIN fo_tipofuncionarios t ON t.codtipofuncionario = r.codtipofuncionario
  LEFT JOIN fo_tipotreinamentos tr ON tr.codtipotreinamento = r.codtipotreinamento;

INSERT INTO registros_migracao (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes)
VALUES ('HOMOLOGACAO_FORNECEDORES_FUNCIONARIOS_RELACIONAMENTOS_090','fo_gestao_funcionarios_c.php/configuracoes fo_funcionarioxtipos*','gmodelo_dbp.views e grids CRUD','ESTRUTURA','1.0.0','CONCLUIDA',2,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
        JSON_OBJECT('filtros',JSON_ARRAY('CODFUNCIONARIO','CODTIPOFUNCIONARIO'),'transacoes',JSON_ARRAY('homologacaofornecedor_funcionarioxcriterios','homologacaofornecedor_funcionarioxtreinamentos')))
ON DUPLICATE KEY UPDATE status=VALUES(status),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
