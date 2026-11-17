-- Green Portal - LAIA abaixo de Modulo Legal.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
START TRANSACTION;

UPDATE `gmodelo_dbp`.`menus` child
JOIN `gmodelo_dbp`.`menus` parent
  ON parent.`codigo` = 'MODULO_LEGAL'
SET child.`codmenu_pai` = parent.`codmenu`,
    child.`nivel` = 2,
    child.`ordem` = CASE child.`codigo`
        WHEN 'LAIA' THEN 20
        WHEN 'LAIA_ATIVIDADES' THEN 21
        WHEN 'LAIA_DESTINOS' THEN 22
        WHEN 'LAIA_REVISAO' THEN 23
        ELSE child.`ordem`
    END
WHERE child.`codigo` IN ('LAIA', 'LAIA_ATIVIDADES', 'LAIA_DESTINOS', 'LAIA_REVISAO');

DELETE p
  FROM `gmodelo_dbp`.`permissoes` p
  JOIN `gmodelo_dbp`.`menus` m ON m.`codmenu` = p.`codmenu`
 WHERE m.`codigo` = 'MODULO_LAIA';

DELETE FROM `gmodelo_dbp`.`menus`
 WHERE `codigo` = 'MODULO_LAIA';

INSERT INTO `gmodelo_dbp`.`registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`,
     `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('MENU_LAIA_MODULO_LEGAL_056',
     'gmodelo_dbp.menus.MODULO_LAIA',
     'gmodelo_dbp.menus.MODULO_LEGAL',
     'ESTRUTURA', '1.0.0', 'CONCLUIDA', 4,
     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
     JSON_OBJECT('itens', JSON_ARRAY('LAIA', 'LAIA_ATIVIDADES', 'LAIA_DESTINOS', 'LAIA_REVISAO'),
                 'menu_pai_anterior', 'MODULO_LAIA',
                 'menu_pai_novo', 'MODULO_LEGAL'))
ON DUPLICATE KEY UPDATE
    `status` = VALUES(`status`),
    `quantidade_registros` = VALUES(`quantidade_registros`),
    `concluida_em` = VALUES(`concluida_em`),
    `detalhes` = VALUES(`detalhes`);

COMMIT;
