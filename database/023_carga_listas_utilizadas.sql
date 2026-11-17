USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

INSERT INTO `formulario_lista`
(`codigo`,`label_pt`,`label_en`,`label_es`,`descricao`,`origem`,`fonte_codigo`,`campo_valor`,`campo_label`,`filtro_json`,`ativo`) VALUES
('PERFIS_ATIVOS','Perfis ativos','Active profiles','Perfiles activos','Perfis utilizados nos grids de usuários, permissões e formulários.','BANCO','vw_perfis_grid','codperfil','codigo',JSON_OBJECT('ativo',1),1),
('GRUPOS_ATIVOS','Grupos ativos','Active groups','Grupos activos','Grupos utilizados no grid de usuários.','BANCO','vw_grupos_grid','codgrupo','nome',JSON_OBJECT('ativo',1),1),
('MENUS_ATIVOS','Menus ativos','Active menus','Menús activos','Transações utilizadas nos grids de permissões e formulários.','BANCO','vw_menus_grid','codmenu','codigo',JSON_OBJECT('ativo',1),1),
('MENUS_PAI','Menus pai','Parent menus','Menús padre','Menus utilizados para definir a hierarquia da transação.','BANCO','vw_menus_grid','codmenu','codigo',JSON_OBJECT('ativo',1),1),
('ORIGEM_LISTA','Origem da lista','List source','Origen de la lista','Define se os itens são fixos ou vêm de uma fonte cadastrada.','FIXA',NULL,NULL,NULL,NULL,1),
('TIPO_CAMPO_FORMULARIO','Tipo do campo','Field type','Tipo de campo','Tipos de campos disponíveis para formulários e grids.','FIXA',NULL,NULL,NULL,NULL,1),
('NIVEL_MENU','Nível do menu','Menu level','Nivel del menú','Níveis permitidos para os itens de menu.','FIXA',NULL,NULL,NULL,NULL,1),
('SITUACAO_ATIVO','Situação','Status','Estado','Situação utilizada nos grids administrativos.','FIXA',NULL,NULL,NULL,NULL,1)
ON DUPLICATE KEY UPDATE
    `label_pt`=VALUES(`label_pt`), `label_en`=VALUES(`label_en`), `label_es`=VALUES(`label_es`),
    `descricao`=VALUES(`descricao`), `origem`=VALUES(`origem`), `fonte_codigo`=VALUES(`fonte_codigo`),
    `campo_valor`=VALUES(`campo_valor`), `campo_label`=VALUES(`campo_label`),
    `filtro_json`=VALUES(`filtro_json`), `ativo`=1;

INSERT INTO `formulario_lista_itens` (`codformulariolista`,`valor`,`label_pt`,`label_en`,`label_es`,`ordem`,`ativo`)
SELECT l.`codformulariolista`, x.`valor`, x.`label_pt`, x.`label_en`, x.`label_es`, x.`ordem`, 1
FROM `formulario_lista` l
JOIN (
    SELECT 'ORIGEM_LISTA' AS `codigo_lista`, 'FIXA' AS `valor`, 'Fixa' AS `label_pt`, 'Fixed' AS `label_en`, 'Fija' AS `label_es`, 1 AS `ordem`
    UNION ALL SELECT 'ORIGEM_LISTA', 'BANCO', 'Banco de dados', 'Database', 'Base de datos', 2
    UNION ALL SELECT 'TIPO_CAMPO_FORMULARIO', 'text', 'Texto', 'Text', 'Texto', 1
    UNION ALL SELECT 'TIPO_CAMPO_FORMULARIO', 'number', 'Número', 'Number', 'Número', 2
    UNION ALL SELECT 'TIPO_CAMPO_FORMULARIO', 'checkbox', 'Caixa de seleção', 'Checkbox', 'Casilla de selección', 3
    UNION ALL SELECT 'TIPO_CAMPO_FORMULARIO', 'select', 'Lista de seleção', 'Selection list', 'Lista de selección', 4
    UNION ALL SELECT 'TIPO_CAMPO_FORMULARIO', 'date', 'Data', 'Date', 'Fecha', 5
    UNION ALL SELECT 'TIPO_CAMPO_FORMULARIO', 'email', 'E-mail', 'Email', 'Correo electrónico', 6
    UNION ALL SELECT 'NIVEL_MENU', '1', 'Nível 1', 'Level 1', 'Nivel 1', 1
    UNION ALL SELECT 'NIVEL_MENU', '2', 'Nível 2', 'Level 2', 'Nivel 2', 2
    UNION ALL SELECT 'NIVEL_MENU', '3', 'Nível 3', 'Level 3', 'Nivel 3', 3
    UNION ALL SELECT 'NIVEL_MENU', '4', 'Nível 4', 'Level 4', 'Nivel 4', 4
    UNION ALL SELECT 'SITUACAO_ATIVO', '1', 'Ativo', 'Active', 'Activo', 1
    UNION ALL SELECT 'SITUACAO_ATIVO', '0', 'Inativo', 'Inactive', 'Inactivo', 2
) x ON x.`codigo_lista`=l.`codigo`
ON DUPLICATE KEY UPDATE
    `label_pt`=VALUES(`label_pt`), `label_en`=VALUES(`label_en`), `label_es`=VALUES(`label_es`),
    `ordem`=VALUES(`ordem`), `ativo`=1;
