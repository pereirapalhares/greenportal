USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

-- Completa a matriz de seguranca sem alterar permissoes ja configuradas.
INSERT INTO `permissoes`
    (`codperfil`, `codmenu`, `pode_incluir`, `pode_visualizar`, `pode_editar`,
     `pode_excluir`, `pode_exportar_excel`, `pode_gerar_pdf`)
SELECT p.`codperfil`, m.`codmenu`,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' OR m.`codigo` = 'DASHBOARD' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END
  FROM `perfis` p
 CROSS JOIN `menus` m
 WHERE p.`ativo` = 1
   AND m.`ativo` = 1
   AND NOT EXISTS (
       SELECT 1 FROM `permissoes` existente
        WHERE existente.`codperfil` = p.`codperfil`
          AND existente.`codmenu` = m.`codmenu`
   );
