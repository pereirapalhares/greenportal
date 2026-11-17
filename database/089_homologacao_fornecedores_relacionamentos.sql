-- Views dos relacionamentos de fornecedores.
USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_homologacao_fornecedor_contatos_grid` AS
SELECT codcontato AS CODCONTATO, codfornecedor AS CODFORNECEDOR, nome_contato AS NOME_CONTATO, telefones AS TELEFONES, celulares AS CELULARES
  FROM fo_contatos;

CREATE OR REPLACE VIEW `vw_homologacao_fornecedor_certificados_grid` AS
SELECT codcertificado AS CODCERTIFICADO, codfornecedor AS CODFORNECEDOR, dsccertificado AS DSCCERTIFICADO, nrdias AS NRDIAS, dtvalidade AS DTVALIDADE
  FROM fo_certificados;

CREATE OR REPLACE VIEW `vw_homologacao_fornecedor_auditoria_grid` AS
SELECT x.codfornecedorxtipofornecedores AS CODFORNECEDORXTIPOFORNECEDORES, x.codfornecedor AS CODFORNECEDOR,
       x.codtipofornecedor AS CODTIPOFORNECEDOR, t.DSCTIPO AS DSCTIPO, x.datavalidade AS DATAVALIDADE,
       x.datareacao AS DATAREACAO, x.fornecedoraprovadoauditoria AS FORNECEDORAPROVADOAUDITORIA, x.nota AS NOTA
  FROM fo_fornecedoresxtipofornecedores x
  LEFT JOIN fo_tipofornecedores t ON t.codtipofornecedor = x.codtipofornecedor;

CREATE OR REPLACE VIEW `vw_homologacao_tipos_fornecedores_grid` AS
SELECT codtipofornecedor, DSCTIPO FROM fo_tipofornecedores WHERE DSCTIPO IS NOT NULL;

CREATE OR REPLACE VIEW `vw_homologacao_fornecedor_criterios_grid` AS
SELECT x.codfornecedorxcriterios AS CODFORNECEDORXCRITERIOS, x.codfornecedor AS CODFORNECEDOR,
       c.DSCRITERIO AS DSCRITERIO, tc.nivel_de_exigencia AS NIVEL_DE_EXIGENCIA,
       x.enviadoodocumento AS ENVIADOODOCUMENTO, x.situacao AS SITUACAO,
       x.datadevalidade AS DATADAVALIDADE, x.observacao AS OBSERVACAO,
       x.observacao_do_fornecedor AS OBSERVACAO_DO_FORNECEDOR
  FROM fo_fornecedorxcriterios x
  LEFT JOIN fo_criteriofornecedores c ON c.codcriteriofornecedor = x.codcriterio
  LEFT JOIN fo_tipofornecedoresxcriteriofornecedores tc ON tc.codtipofornecedor = x.codtipo AND tc.codcriteriofornecedor = x.codcriterio;

CREATE OR REPLACE VIEW `vw_homologacao_fornecedor_funcionarios_grid` AS
SELECT CODFUNCIONARIO, CODFORNECEDOR, NOME, CELULAR, TELEFONE, EMAIL, NRIDENTIFICACAO,
       CODTIPOFUNCIONARIO, SETOR, DATA_VALIDADE, DATA_DE_INICIO
  FROM fo_funcionarios;

CREATE OR REPLACE VIEW `vw_homologacao_fornecedor_produtos_grid` AS
SELECT codproduto AS CODPRODUTO, codfornecedor AS CODFORNECEDOR, DSCPRODUTO, NCAS, DATA_VALIDADE,
       NRDIAS_REACAO, PRODUTO_CONTROLADO, CARACTERISTICA, APROVADO
  FROM fo_produtoquimico;

CREATE OR REPLACE VIEW `vw_homologacao_fornecedor_equipamentos_grid` AS
SELECT codequipamento AS CODEQUIPAMENTO, codfornecedor AS CODFORNECEDOR, DSCEQUIPAMENTO,
       NRIDENTIFICACAO, DATA_VALIDADE, NRDIAS_REACAO, APROVADO
  FROM fo_equipamentos;

CREATE OR REPLACE VIEW `vw_homologacao_fornecedor_planos_grid` AS
SELECT codplanoacao AS CODPLANOACAO, codfornecedor AS CODFORNECEDOR, acao AS ACAO,
       codusuario AS CODUSUARIO, datainicial AS DATAINICIAL, datafinal AS DATAFINAL,
       recursos AS RECURSOS, status AS STATUS
  FROM fo_planodeacao;

INSERT INTO registros_migracao (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes)
VALUES ('HOMOLOGACAO_FORNECEDORES_RELACIONAMENTOS_089','fo_fornecedorxauditoria_c.php/configuracoes fo_','gmodelo_dbp.views e grids CRUD','ESTRUTURA','1.0.0','CONCLUIDA',8,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
        JSON_OBJECT('filtro_contexto','CODFORNECEDOR','transacoes',JSON_ARRAY('contatos','certificados','auditoria','criterios','funcionarios','produtosquimicos','equipamentos','planodeacao')))
ON DUPLICATE KEY UPDATE status=VALUES(status),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
