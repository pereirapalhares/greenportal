-- Green Portal - Módulo Legal / Meus Treinamentos
USE gmodelo_dbp;
SET NAMES utf8mb4;

CREATE OR REPLACE VIEW vw_minhas_treinamentos_grid AS
SELECT DISTINCT t.CODTREINAMENTO, t.DSCTREINAMENTO, g.ARQUIVO, g.PALAVRAS_CHAVE, a.CODUSUARIO
  FROM ml_treinamentos t
  INNER JOIN vw_modulolegal_treinamentos_grid g ON g.CODTREINAMENTO = t.CODTREINAMENTO
  INNER JOIN responsabilidade_atribuicoes a
          ON a.ENTIDADE = 'ml_treinamentos'
         AND a.REGISTRO_ID = CAST(t.CODTREINAMENTO AS CHAR)
         AND a.TIPO_DESTINO = 'USUARIO'
         AND a.CODUSUARIO IS NOT NULL
         AND a.ATIVO = 1
         AND a.VALIDO_DE <= CURRENT_DATE
         AND (a.VALIDO_ATE IS NULL OR a.VALIDO_ATE >= CURRENT_DATE)
 WHERE t.CODTREINAMENTO IS NOT NULL;

INSERT INTO menus (codmenu_pai, modulo, codigo, label, label_pt, label_en, label_es, url, nivel, ordem, ativo)
SELECT p.codmenu, 'MODULO_LEGAL', 'MEUS_TREINAMENTOS', 'Meus Treinamentos', 'Meus Treinamentos', 'My Training', 'Mis Capacitaciones', '/principal/meus_treinamentos.php', 2, 39, 1
  FROM menus p
 WHERE p.codigo = 'MODULO_LEGAL'
   AND NOT EXISTS (SELECT 1 FROM menus m WHERE m.codigo = 'MEUS_TREINAMENTOS');

UPDATE menus
   SET codmenu_pai = (SELECT codmenu FROM (SELECT codmenu FROM menus WHERE codigo = 'MODULO_LEGAL') x),
       modulo = 'MODULO_LEGAL', ordem = 39, url = '/principal/meus_treinamentos.php', ativo = 1
 WHERE codigo = 'MEUS_TREINAMENTOS';

INSERT INTO permissoes (codperfil, codmenu, pode_incluir, pode_visualizar, pode_visualizar_total, pode_editar, pode_excluir, pode_exportar_excel, pode_gerar_pdf)
SELECT p.codperfil, m.codmenu, 0, COALESCE(orig.pode_visualizar, 0), 0, COALESCE(orig.pode_editar, 0), 0, COALESCE(orig.pode_exportar_excel, 0), 0
  FROM perfis p CROSS JOIN menus m
  LEFT JOIN permissoes orig ON orig.codperfil = p.codperfil
                            AND orig.codmenu = (SELECT codmenu FROM menus WHERE codigo = 'MODULOLEGAL_TREINAMENTOS')
 WHERE m.codigo = 'MEUS_TREINAMENTOS'
   AND NOT EXISTS (SELECT 1 FROM permissoes x WHERE x.codperfil = p.codperfil AND x.codmenu = m.codmenu);
