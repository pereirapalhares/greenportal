-- Migração 220 — Gestão de Funcionários: corrige a busca geral do grid ("Buscar no grid").
-- A coluna ANEXO era o texto fixo 'Arquivos'. Um texto fixo na view fica com a collation da conexão
-- que criou a view (utf8mb4_general_ci); a busca do grid usa a da sessão (utf8mb4_0900_ai_ci).
-- Como os dois lados são "texto fixo", o MySQL não decide qual usar e a busca falha com
-- "Illegal mix of collations ... for operation 'like'". ANEXO passa a ser NULL (a tela já mostra
-- o botão de anexos no lugar do texto) e a busca volta a funcionar.
-- Mantém as regras das migrações 218 e 219. Aplicada em todos os bancos de clientes.

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
