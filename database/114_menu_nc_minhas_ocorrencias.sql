USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
INSERT INTO menus (codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,ativo)
SELECT m.codmenu,'NAO_CONFORMIDADES','NAO_CONFORMIDADES_MINHAS_OCORRENCIAS',
       'Minhas ocorrências','Minhas ocorrências','My occurrences','Mis ocurrencias',
       '/principal/naoconformidades_minhasocorrencias.php',2,5,1
  FROM menus m WHERE m.codigo='NAO_CONFORMIDADES'
ON DUPLICATE KEY UPDATE codmenu_pai=VALUES(codmenu_pai),url=VALUES(url),label=VALUES(label),label_pt=VALUES(label_pt),label_en=VALUES(label_en),label_es=VALUES(label_es),ordem=5,ativo=1;
INSERT INTO permissoes (codperfil,codmenu,pode_incluir,pode_visualizar,pode_editar,pode_excluir,pode_exportar_excel,pode_gerar_pdf)
SELECT p.codperfil,m.codmenu,0,1,0,0,1,1 FROM perfis p CROSS JOIN menus m WHERE m.codigo='NAO_CONFORMIDADES_MINHAS_OCORRENCIAS'
ON DUPLICATE KEY UPDATE pode_visualizar=VALUES(pode_visualizar),pode_exportar_excel=VALUES(pode_exportar_excel),pode_gerar_pdf=VALUES(pode_gerar_pdf);
