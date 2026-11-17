-- Homologação de Fornecedores: views de grid, menu e permissões.
USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_homologacao_fornecedores_grid` AS
SELECT f.codfornecedor AS CODFORNECEDOR, f.codunidade AS CODUNIDADE,
       f.cnpj_cpf AS CNPJ_CPF, f.razaosocial AS RAZAOSOCIAL,
       f.nomefantasia AS NOMEFANTASIA,
       COALESCE(GROUP_CONCAT(DISTINCT tf.DSCTIPO ORDER BY tf.DSCTIPO SEPARATOR ', '), '') AS TIPOFORNECEDOR,
       f.ativadofor AS ATIVADOFOR, f.codusuario_responsavel_fornecedor AS CODUSUARIO_RESPONSAVEL_FORNECEDOR,
       f.trabalha_instalacao_fornecedor AS TRABALHA_INSTALACAO_FORNECEDOR,
       f.tem_produtos_quimicos AS TEM_PRODUTOS_QUIMICOS, f.tem_equipamentos AS TEM_EQUIPAMENTOS,
       f.codusuario_responsavel_integracao AS CODUSUARIO_RESPONSAVEL_INTEGRACAO,
       f.nivel_de_servicao AS NIVEL_DE_SERVICO, f.data_da_avaliacao AS DATA_DA_AVALIACAO,
       f.data_da_proxima_avaliacao AS DATA_DA_PROXIMA_AVALIACAO, 'Arquivos' AS ANEXO,
       f.endereco AS ENDERECO, f.cidade AS CIDADE, f.estado AS ESTADO, f.cep AS CEP, f.emails AS EMAILS
  FROM fo_fornecedores f
  LEFT JOIN fo_fornecedoresxtipofornecedores fxt ON fxt.codfornecedor = f.codfornecedor
  LEFT JOIN fo_tipofornecedores tf ON tf.codtipofornecedor = fxt.codtipofornecedor
 GROUP BY f.codfornecedor, f.codunidade, f.cnpj_cpf, f.razaosocial, f.nomefantasia, f.ativadofor,
          f.codusuario_responsavel_fornecedor, f.trabalha_instalacao_fornecedor, f.tem_produtos_quimicos,
          f.tem_equipamentos, f.codusuario_responsavel_integracao, f.nivel_de_servicao,
          f.data_da_avaliacao, f.data_da_proxima_avaliacao, f.endereco, f.cidade, f.estado, f.cep, f.emails;

CREATE OR REPLACE VIEW `vw_homologacao_funcionarios_grid` AS
SELECT fu.CODFUNCIONARIO, u.DSCUNIDADE, fo.RAZAOSOCIAL, fu.NOME, fu.CELULAR, fu.TELEFONE,
       fu.EMAIL, fu.NRIDENTIFICACAO, fu.CODTIPOFUNCIONARIO, tf.DSCTIPO, fu.SETOR,
       'Arquivos' AS ANEXO, fu.DATA_VALIDADE, fu.data_de_inicio AS DATA_DE_INICIO, fu.CODFORNECEDOR
  FROM fo_funcionarios fu
  LEFT JOIN fo_fornecedores fo ON fo.CODFORNECEDOR = fu.CODFORNECEDOR
  LEFT JOIN unidades u ON u.CODUNIDADE = fo.CODUNIDADE
  LEFT JOIN fo_tipofuncionarios tf ON tf.CODTIPOFUNCIONARIO = fu.CODTIPOFUNCIONARIO;

CREATE OR REPLACE VIEW `vw_homologacao_tipos_funcionarios_grid` AS
SELECT codtipofuncionario, DSCTIPO FROM fo_tipofuncionarios WHERE DSCTIPO IS NOT NULL;

INSERT INTO menus (codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,ativo)
SELECT NULL,'HOMOLOGACAO_FORNECEDORES','HOMOLOGACAO_FORNECEDORES',CONVERT(0x486F6D6F6C6F6761C3A7C3A36F20646520466F726E656365646F726573 USING utf8mb4),CONVERT(0x486F6D6F6C6F6761C3A7C3A36F20646520466F726E656365646F726573 USING utf8mb4),'Supplier Homologation',CONVERT(0x486F6D6F6C6F67616369C3B36E2064652050726F766565646F726573 USING utf8mb4),'',1,70,1
 WHERE NOT EXISTS (SELECT 1 FROM menus WHERE codigo='HOMOLOGACAO_FORNECEDORES');
UPDATE menus SET label=CONVERT(0x486F6D6F6C6F6761C3A7C3A36F20646520466F726E656365646F726573 USING utf8mb4), label_pt=CONVERT(0x486F6D6F6C6F6761C3A7C3A36F20646520466F726E656365646F726573 USING utf8mb4), label_es=CONVERT(0x486F6D6F6C6F67616369C3B36E2064652050726F766565646F726573 USING utf8mb4) WHERE codigo='HOMOLOGACAO_FORNECEDORES';

INSERT INTO menus (codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,ativo)
SELECT m.codmenu,'HOMOLOGACAO_FORNECEDORES','HOMOLOGACAO_FORNECEDOR_FORNECEDOR',CONVERT(0x47657374C3A36F20646520466F726E656365646F726573 USING utf8mb4),CONVERT(0x47657374C3A36F20646520466F726E656365646F726573 USING utf8mb4),'Supplier Management',CONVERT(0x4765737469C3B36E2064652050726F766565646F726573 USING utf8mb4),'/principal/homologacaofornecedor_fornecedor.php',2,10,1 FROM menus m WHERE m.codigo='HOMOLOGACAO_FORNECEDORES'
ON DUPLICATE KEY UPDATE codmenu_pai=VALUES(codmenu_pai),url=VALUES(url),ativo=1,label_pt=VALUES(label_pt),label_en=VALUES(label_en),label_es=VALUES(label_es);
INSERT INTO menus (codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,ativo)
SELECT m.codmenu,'HOMOLOGACAO_FORNECEDORES','HOMOLOGACAO_FORNECEDOR_FUNCIONARIO',CONVERT(0x47657374C3A36F2064652046756E63696F6EC3A172696F73 USING utf8mb4),CONVERT(0x47657374C3A36F2064652046756E63696F6EC3A172696F73 USING utf8mb4),'Employee Management',CONVERT(0x4765737469C3B36E20646520456D706C6561646F73 USING utf8mb4),'/principal/homologacaofornecedor_funcionario.php',2,20,1 FROM menus m WHERE m.codigo='HOMOLOGACAO_FORNECEDORES'
ON DUPLICATE KEY UPDATE codmenu_pai=VALUES(codmenu_pai),url=VALUES(url),ativo=1,label_pt=VALUES(label_pt),label_en=VALUES(label_en),label_es=VALUES(label_es);

INSERT INTO permissoes (codperfil,codmenu,pode_incluir,pode_visualizar,pode_editar,pode_excluir,pode_exportar_excel,pode_gerar_pdf)
SELECT p.codperfil,m.codmenu,IF(p.codigo='ADMINISTRADOR',1,0),1,IF(p.codigo='ADMINISTRADOR',1,0),IF(p.codigo='ADMINISTRADOR',1,0),IF(p.codigo='ADMINISTRADOR',1,0),0 FROM perfis p JOIN menus m ON m.codigo IN ('HOMOLOGACAO_FORNECEDORES','HOMOLOGACAO_FORNECEDOR_FORNECEDOR','HOMOLOGACAO_FORNECEDOR_FUNCIONARIO')
ON DUPLICATE KEY UPDATE pode_visualizar=VALUES(pode_visualizar),pode_incluir=VALUES(pode_incluir),pode_editar=VALUES(pode_editar),pode_excluir=VALUES(pode_excluir),pode_exportar_excel=VALUES(pode_exportar_excel);

INSERT INTO registros_migracao (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes)
VALUES ('HOMOLOGACAO_FORNECEDORES_087','fo_fornecedor.php/fo_funcionarios.php','gmodelo_dbp.views/menus/permissoes','ESTRUTURA','1.0.0','CONCLUIDA',2,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,JSON_OBJECT('transacoes',JSON_ARRAY('homologacaofornecedor_fornecedor','homologacaofornecedor_funcionario')))
ON DUPLICATE KEY UPDATE status=VALUES(status),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
