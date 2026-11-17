USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

-- Inicializa uma permissao por perfil e transacao sem duplicar registros existentes.
-- O ADMINISTRADOR possui todas as permissoes nas transacoes ativas.
INSERT INTO `permissoes`
    (`codperfil`, `codmenu`, `pode_incluir`, `pode_visualizar`, `pode_editar`,
     `pode_excluir`, `pode_exportar_excel`, `pode_gerar_pdf`)
SELECT p.`codperfil`, m.`codmenu`,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       1,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END
  FROM `perfis` p
 CROSS JOIN `menus` m
 WHERE p.`ativo` = 1
   AND m.`ativo` = 1
   AND (p.`codigo` = 'ADMINISTRADOR' OR m.`codigo` = 'DASHBOARD')
   AND NOT EXISTS (
       SELECT 1 FROM `permissoes` existente
        WHERE existente.`codperfil` = p.`codperfil`
          AND existente.`codmenu` = m.`codmenu`
   );

-- Mantem o acesso do ADMINISTRADOR total mesmo quando a carga for executada novamente.
UPDATE `permissoes` permissao
JOIN `perfis` perfil ON perfil.`codperfil` = permissao.`codperfil`
   SET permissao.`pode_incluir` = 1,
       permissao.`pode_visualizar` = 1,
       permissao.`pode_editar` = 1,
       permissao.`pode_excluir` = 1,
       permissao.`pode_exportar_excel` = 1,
       permissao.`pode_gerar_pdf` = 1
 WHERE perfil.`codigo` = 'ADMINISTRADOR';

-- Todos os demais perfis visualizam o Dashboard, sem permissao de alteracao.
UPDATE `permissoes` permissao
JOIN `perfis` perfil ON perfil.`codperfil` = permissao.`codperfil`
JOIN `menus` menu ON menu.`codmenu` = permissao.`codmenu`
   SET permissao.`pode_incluir` = 0,
       permissao.`pode_visualizar` = 1,
       permissao.`pode_editar` = 0,
       permissao.`pode_excluir` = 0,
       permissao.`pode_exportar_excel` = 0,
       permissao.`pode_gerar_pdf` = 0
 WHERE perfil.`codigo` <> 'ADMINISTRADOR'
   AND menu.`codigo` = 'DASHBOARD';
