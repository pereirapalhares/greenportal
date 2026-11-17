-- As chaves primarias ficam ocultas inicialmente nos grids existentes.
USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

UPDATE `preferencias_grid`
   SET `visivel` = 0
 WHERE (`transacao` = 'setup_menu' AND `coluna` = 'codmenu')
    OR (`transacao` = 'seguranca_grupos' AND `coluna` = 'codgrupo');
