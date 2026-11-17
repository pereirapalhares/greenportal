-- Green Portal - Módulo Legal / Minhas Licenças
USE gmodelo_dbp;

INSERT INTO menus (codmenu_pai, modulo, codigo, label, label_pt, label_en, label_es, url, nivel, ordem, ativo)
SELECT p.codmenu, 'MODULO_LEGAL', 'MINHAS_LICENCAS', 'Minhas Licenças', 'Minhas Licenças', 'My Licenses', 'Mis Licencias', '/principal/minhas_licencas.php', 2, 3, 1
  FROM menus p
 WHERE p.codigo = 'MODULO_LEGAL'
   AND NOT EXISTS (SELECT 1 FROM menus m WHERE m.codigo = 'MINHAS_LICENCAS');

UPDATE menus
   SET codmenu_pai = (SELECT codmenu FROM (SELECT codmenu FROM menus WHERE codigo = 'MODULO_LEGAL') x),
       modulo = 'MODULO_LEGAL', ordem = 3, url = '/principal/minhas_licencas.php',
       label_pt = 'Minhas Licenças', label_en = 'My Licenses', label_es = 'Mis Licencias', ativo = 1
 WHERE codigo = 'MINHAS_LICENCAS';

INSERT INTO permissoes (codperfil, codmenu, pode_incluir, pode_visualizar, pode_visualizar_total, pode_editar, pode_excluir, pode_exportar_excel, pode_gerar_pdf)
SELECT p.codperfil, m.codmenu,
       COALESCE(orig.pode_incluir, 0), COALESCE(orig.pode_visualizar, 0), 0,
       COALESCE(orig.pode_editar, 0), COALESCE(orig.pode_excluir, 0),
       COALESCE(orig.pode_exportar_excel, 0), COALESCE(orig.pode_gerar_pdf, 0)
  FROM perfis p CROSS JOIN menus m
  LEFT JOIN permissoes orig ON orig.codperfil = p.codperfil
                            AND orig.codmenu = (SELECT codmenu FROM menus WHERE codigo = 'LICENCAS')
 WHERE m.codigo = 'MINHAS_LICENCAS'
   AND NOT EXISTS (SELECT 1 FROM permissoes x WHERE x.codperfil = p.codperfil AND x.codmenu = m.codmenu);
