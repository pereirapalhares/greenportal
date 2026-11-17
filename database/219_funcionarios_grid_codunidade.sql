-- Migração 219 — Gestão de Funcionários (Homologação de Fornecedores)
-- Acrescenta CODUNIDADE (unidade do fornecedor) à view do grid, para o filtro Unidade / Fornecedor da tela.
-- Mantém a regra da migração 218: só funcionários de fornecedores cadastrados, sem os provisórios da 217.
-- Aplicada em todos os bancos de clientes. Pode ser executada mais de uma vez.

CREATE OR REPLACE VIEW `vw_homologacao_funcionarios_grid` AS
SELECT fu.CODFUNCIONARIO, u.DSCUNIDADE, fo.RAZAOSOCIAL, fu.NOME, fu.CELULAR, fu.TELEFONE,
       fu.EMAIL, fu.NRIDENTIFICACAO, fu.CODTIPOFUNCIONARIO, tf.DSCTIPO, fu.SETOR,
       CAST(NULL AS CHAR(1)) AS ANEXO, fu.DATA_VALIDADE, fu.data_de_inicio AS DATA_DE_INICIO, fu.CODFORNECEDOR,
       fo.CODUNIDADE AS CODUNIDADE
  FROM fo_funcionarios fu
  INNER JOIN fo_fornecedores fo ON fo.CODFORNECEDOR = fu.CODFORNECEDOR
  LEFT JOIN unidades u ON u.CODUNIDADE = fo.CODUNIDADE
  LEFT JOIN fo_tipofuncionarios tf ON tf.CODTIPOFUNCIONARIO = fu.CODTIPOFUNCIONARIO
 WHERE COALESCE(fo.RAZAOSOCIAL, '') NOT LIKE '%- migra%o 217';
