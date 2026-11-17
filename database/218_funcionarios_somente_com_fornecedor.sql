-- Migração 218 — Gestão de Funcionários (Homologação de Fornecedores)
-- O grid passa a listar somente funcionários associados a um fornecedor cadastrado.
-- Funcionários sem fornecedor ou ligados a um código de fornecedor inexistente
-- continuam na tabela fo_funcionarios (nada é apagado); apenas deixam de aparecer na tela.
-- Também ficam ocultos os funcionários dos fornecedores provisórios criados pela migração 217
-- ("Fornecedor não identificado (código X) - migração 217"), que não são fornecedores reais.
-- Aplicada em todos os bancos de clientes. Pode ser executada mais de uma vez.

CREATE OR REPLACE VIEW `vw_homologacao_funcionarios_grid` AS
SELECT fu.CODFUNCIONARIO, u.DSCUNIDADE, fo.RAZAOSOCIAL, fu.NOME, fu.CELULAR, fu.TELEFONE,
       fu.EMAIL, fu.NRIDENTIFICACAO, fu.CODTIPOFUNCIONARIO, tf.DSCTIPO, fu.SETOR,
       CAST(NULL AS CHAR(1)) AS ANEXO, fu.DATA_VALIDADE, fu.data_de_inicio AS DATA_DE_INICIO, fu.CODFORNECEDOR,
       fo.CODUNIDADE AS CODUNIDADE -- incluída pela migração 219 (filtro Unidade / Fornecedor)
  FROM fo_funcionarios fu
  INNER JOIN fo_fornecedores fo ON fo.CODFORNECEDOR = fu.CODFORNECEDOR
  LEFT JOIN unidades u ON u.CODUNIDADE = fo.CODUNIDADE
  LEFT JOIN fo_tipofuncionarios tf ON tf.CODTIPOFUNCIONARIO = fu.CODTIPOFUNCIONARIO
 WHERE COALESCE(fo.RAZAOSOCIAL, '') NOT LIKE '%- migra%o 217';
