-- Normaliza referências do grid mantendo os códigos legados para o CRUD.
CREATE OR REPLACE VIEW vw_homologacao_fornecedores_grid AS
SELECT f.codfornecedor AS CODFORNECEDOR,
       f.codunidade AS CODUNIDADE,
       MAX(u.DSCUNIDADE) AS DSCUNIDADE,
       f.cnpj_cpf AS CNPJ_CPF,
       f.razaosocial AS RAZAOSOCIAL,
       f.nomefantasia AS NOMEFANTASIA,
       COALESCE(GROUP_CONCAT(DISTINCT tf.DSCTIPO ORDER BY tf.DSCTIPO SEPARATOR ', '), '') AS TIPOFORNECEDOR,
       CASE WHEN LOWER(TRIM(COALESCE(f.ativadofor, ''))) IN ('n?o', 'nao', 'não', 'n', '0') THEN 'Não'
            WHEN LOWER(TRIM(COALESCE(f.ativadofor, ''))) IN ('sim', 's', '1') THEN 'Sim'
            ELSE f.ativadofor END AS ATIVADOFOR,
       f.codusuario_responsavel_fornecedor AS CODUSUARIO_RESPONSAVEL_FORNECEDOR,
       COALESCE(MAX(NULLIF(TRIM(uf.nome), '')), MAX(NULLIF(TRIM(uf.userid), '')), CASE WHEN COALESCE(f.codusuario_responsavel_fornecedor, 0) = 0 THEN '' ELSE 'Usuário não localizado' END) AS RESPONSAVEL_FORNECEDOR,
       CASE WHEN LOWER(TRIM(COALESCE(f.trabalha_instalacao_fornecedor, ''))) IN ('n?o', 'nao', 'não', 'n', '0') THEN 'Não'
            WHEN LOWER(TRIM(COALESCE(f.trabalha_instalacao_fornecedor, ''))) IN ('sim', 's', '1') THEN 'Sim'
            ELSE f.trabalha_instalacao_fornecedor END AS TRABALHA_INSTALACAO_FORNECEDOR,
       CASE WHEN LOWER(TRIM(COALESCE(f.tem_produtos_quimicos, ''))) IN ('n?o', 'nao', 'não', 'n', '0') THEN 'Não'
            WHEN LOWER(TRIM(COALESCE(f.tem_produtos_quimicos, ''))) IN ('sim', 's', '1') THEN 'Sim'
            ELSE f.tem_produtos_quimicos END AS TEM_PRODUTOS_QUIMICOS,
       CASE WHEN LOWER(TRIM(COALESCE(f.tem_equipamentos, ''))) IN ('n?o', 'nao', 'não', 'n', '0') THEN 'Não'
            WHEN LOWER(TRIM(COALESCE(f.tem_equipamentos, ''))) IN ('sim', 's', '1') THEN 'Sim'
            ELSE f.tem_equipamentos END AS TEM_EQUIPAMENTOS,
       f.codusuario_responsavel_integracao AS CODUSUARIO_RESPONSAVEL_INTEGRACAO,
       COALESCE(MAX(NULLIF(TRIM(ui.nome), '')), MAX(NULLIF(TRIM(ui.userid), '')), CASE WHEN COALESCE(f.codusuario_responsavel_integracao, 0) = 0 THEN '' ELSE 'Usuário não localizado' END) AS RESPONSAVEL_INTEGRACAO,
       CASE WHEN LOWER(TRIM(COALESCE(f.nivel_de_servicao, ''))) IN ('n?o satisfat?rio', 'nao satisfatorio', 'não satisfatório') THEN 'Não Satisfatório'
            WHEN LOWER(TRIM(COALESCE(f.nivel_de_servicao, ''))) IN ('satisfat?rio', 'satisfatorio', 'satisfatório') THEN 'Satisfatório'
            ELSE f.nivel_de_servicao END AS NIVEL_DE_SERVICO,
       f.data_da_avaliacao AS DATA_DA_AVALIACAO,
       f.data_da_proxima_avaliacao AS DATA_DA_PROXIMA_AVALIACAO,
       'Arquivos' AS ANEXO,
       f.endereco AS ENDERECO,
       f.cidade AS CIDADE,
       f.estado AS ESTADO,
       f.cep AS CEP,
       f.emails AS EMAILS
  FROM fo_fornecedores f
  LEFT JOIN unidades u ON u.codunidade = f.codunidade
  LEFT JOIN usuarios uf ON uf.codusuario = f.codusuario_responsavel_fornecedor
  LEFT JOIN usuarios ui ON ui.codusuario = f.codusuario_responsavel_integracao
  LEFT JOIN fo_fornecedoresxtipofornecedores fxt ON fxt.codfornecedor = f.codfornecedor
  LEFT JOIN fo_tipofornecedores tf ON tf.codtipofornecedor = fxt.codtipofornecedor
 GROUP BY f.codfornecedor, f.codunidade, f.cnpj_cpf, f.razaosocial, f.nomefantasia,
          f.ativadofor, f.codusuario_responsavel_fornecedor,
          f.trabalha_instalacao_fornecedor, f.tem_produtos_quimicos,
          f.tem_equipamentos, f.codusuario_responsavel_integracao,
          f.nivel_de_servicao, f.data_da_avaliacao, f.data_da_proxima_avaliacao,
          f.endereco, f.cidade, f.estado, f.cep, f.emails;

-- Quem possui visualização desta transação pode executar o CRUD dela.
UPDATE permissoes p
JOIN menus m ON m.codmenu = p.codmenu
   SET p.pode_incluir = 1, p.pode_editar = 1, p.pode_excluir = 1
 WHERE m.codigo = 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR'
   AND p.pode_visualizar = 1;
UPDATE perfil_menus p
JOIN menus m ON m.codmenu = p.codmenu
   SET p.pode_incluir = 1, p.pode_editar = 1, p.pode_excluir = 1
 WHERE m.codigo = 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR'
   AND p.pode_visualizar = 1;
