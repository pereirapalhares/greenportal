USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TEMPORARY TABLE `_carga_formularios_campos` (
    `menu_codigo` VARCHAR(100) NOT NULL,
    `codigo` VARCHAR(100) NOT NULL,
    `label_pt` VARCHAR(150) NOT NULL,
    `label_en` VARCHAR(150) NOT NULL,
    `label_es` VARCHAR(150) NOT NULL,
    `tipo` VARCHAR(30) NOT NULL,
    `ordem` INT NOT NULL,
    `visivel` TINYINT(1) NOT NULL DEFAULT 1,
    `obrigatorio` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`menu_codigo`, `codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `_carga_formularios_campos`
(`menu_codigo`,`codigo`,`label_pt`,`label_en`,`label_es`,`tipo`,`ordem`,`visivel`,`obrigatorio`) VALUES
('SETUP_MENU','codmenu','Código','Code','Código','number',1,0,0),
('SETUP_MENU','codmenu_pai','Menu pai','Parent menu','Menú padre','number',2,1,0),
('SETUP_MENU','modulo','Módulo','Module','Módulo','text',3,1,1),
('SETUP_MENU','codigo','Código','Code','Código','text',4,1,1),
('SETUP_MENU','label_pt','Label PT','PT label','Etiqueta PT','text',5,1,1),
('SETUP_MENU','label_en','Label EN','EN label','Etiqueta EN','text',6,1,1),
('SETUP_MENU','label_es','Label ES','ES label','Etiqueta ES','text',7,1,1),
('SETUP_MENU','url','URL','URL','URL','text',8,1,0),
('SETUP_MENU','nivel','Nível','Level','Nivel','number',9,1,1),
('SETUP_MENU','ordem','Ordem','Order','Orden','number',10,1,1),
('SETUP_MENU','ativo','Ativo','Active','Activo','checkbox',11,1,0),
('SEGURANCA_GRUPOS','codgrupo','Código','Code','Código','number',1,0,0),
('SEGURANCA_GRUPOS','nome','Nome','Name','Nombre','text',2,1,1),
('SEGURANCA_GRUPOS','ativo','Ativo','Active','Activo','checkbox',3,1,0),
('SEGURANCA_USUARIOS','codusuario','Código','Code','Código','number',1,0,0),
('SEGURANCA_USUARIOS','userid','Usuário','User','Usuario','text',2,1,1),
('SEGURANCA_USUARIOS','nome','Nome','Name','Nombre','text',3,1,1),
('SEGURANCA_USUARIOS','email','E-mail','Email','Correo electrónico','text',4,1,0),
('SEGURANCA_USUARIOS','codperfil','Perfil','Profile','Perfil','select',5,1,1),
('SEGURANCA_USUARIOS','codgrupo','Grupo','Group','Grupo','select',6,1,0),
('SEGURANCA_USUARIOS','ativo','Ativo','Active','Activo','checkbox',7,1,0),
('SEGURANCA_PERMISSOES','codpermissao','Código','Code','Código','number',1,0,0),
('SEGURANCA_PERMISSOES','codperfil','Perfil','Profile','Perfil','select',2,1,1),
('SEGURANCA_PERMISSOES','perfil_codigo','Código do perfil','Profile code','Código del perfil','text',3,1,0),
('SEGURANCA_PERMISSOES','codmenu','Menu','Menu','Menú','select',4,1,1),
('SEGURANCA_PERMISSOES','menu_label','Transação','Transaction','Transacción','text',5,1,0),
('SEGURANCA_PERMISSOES','pode_incluir','Incluir','Create','Incluir','checkbox',6,1,0),
('SEGURANCA_PERMISSOES','pode_visualizar','Visualizar','View','Visualizar','checkbox',7,1,0),
('SEGURANCA_PERMISSOES','pode_editar','Editar','Edit','Editar','checkbox',8,1,0),
('SEGURANCA_PERMISSOES','pode_excluir','Excluir','Delete','Eliminar','checkbox',9,1,0),
('SEGURANCA_PERMISSOES','pode_exportar_excel','Exportar Excel','Export Excel','Exportar Excel','checkbox',10,1,0),
('SEGURANCA_PERMISSOES','pode_gerar_pdf','Gerar PDF','Generate PDF','Generar PDF','checkbox',11,1,0),
('SEGURANCA_PERFIL','codperfil','Código','Code','Código','number',1,0,0),
('SEGURANCA_PERFIL','codigo','Código do perfil','Profile code','Código del perfil','text',2,1,1),
('SEGURANCA_PERFIL','nome','Nome','Name','Nombre','text',3,1,1),
('SEGURANCA_PERFIL','acesso_total','Acesso total','Full access','Acceso total','checkbox',4,1,0),
('SEGURANCA_PERFIL','ativo','Ativo','Active','Activo','checkbox',5,1,0),
('SEGURANCA_FORMULARIO','codpermissao_campo','Código da permissão','Permission code','Código del permiso','number',1,0,0),
('SEGURANCA_FORMULARIO','codcampoformulario','Código do campo','Field code','Código del campo','number',2,0,0),
('SEGURANCA_FORMULARIO','codmenu','Menu','Menu','Menú','select',3,1,1),
('SEGURANCA_FORMULARIO','campo_codigo','Código do campo','Field code','Código del campo','text',4,1,1),
('SEGURANCA_FORMULARIO','label_pt','Label PT','PT label','Etiqueta PT','text',5,1,1),
('SEGURANCA_FORMULARIO','label_en','Label EN','EN label','Etiqueta EN','text',6,1,1),
('SEGURANCA_FORMULARIO','label_es','Label ES','ES label','Etiqueta ES','text',7,1,1),
('SEGURANCA_FORMULARIO','tipo','Tipo','Type','Tipo','select',8,1,1),
('SEGURANCA_FORMULARIO','ordem','Ordem','Order','Orden','number',9,1,1),
('SEGURANCA_FORMULARIO','visivel','Visível','Visible','Visible','checkbox',10,1,0),
('SEGURANCA_FORMULARIO','obrigatorio','Obrigatório','Required','Obligatorio','checkbox',11,1,0),
('SEGURANCA_FORMULARIO','codperfil','Perfil','Profile','Perfil','select',12,1,1),
('SEGURANCA_FORMULARIO','pode_visualizar','Visualizar','View','Visualizar','checkbox',13,1,0),
('SEGURANCA_FORMULARIO','pode_editar','Editar','Edit','Editar','checkbox',14,1,0),
('SEGURANCA_FORMULARIO','pode_incluir','Incluir','Create','Incluir','checkbox',15,1,0);

INSERT INTO `formularios_campos`
(`codmenu`,`codigo`,`label_pt`,`label_en`,`label_es`,`tipo`,`ordem`,`visivel`,`obrigatorio`)
SELECT m.`codmenu`, s.`codigo`, s.`label_pt`, s.`label_en`, s.`label_es`, s.`tipo`, s.`ordem`, s.`visivel`, s.`obrigatorio`
FROM `_carga_formularios_campos` s
JOIN `menus` m ON m.`codigo` = s.`menu_codigo`
ON DUPLICATE KEY UPDATE
    `label_pt`=VALUES(`label_pt`), `label_en`=VALUES(`label_en`), `label_es`=VALUES(`label_es`),
    `tipo`=VALUES(`tipo`), `ordem`=VALUES(`ordem`), `visivel`=VALUES(`visivel`), `obrigatorio`=VALUES(`obrigatorio`);

INSERT INTO `permissoes_campos`
(`codcampoformulario`,`codperfil`,`pode_visualizar`,`pode_editar`,`pode_incluir`)
SELECT c.`codcampoformulario`, p.`codperfil`,
       CASE WHEN p.`codigo`='ADMINISTRADOR' THEN 1 ELSE 1 END,
       CASE WHEN p.`codigo`='ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo`='ADMINISTRADOR' THEN 1 ELSE 0 END
FROM `formularios_campos` c
JOIN `_carga_formularios_campos` s ON s.`codigo`=c.`codigo`
JOIN `menus` m ON m.`codmenu`=c.`codmenu` AND m.`codigo`=s.`menu_codigo`
CROSS JOIN `perfis` p
WHERE p.`ativo`=1
ON DUPLICATE KEY UPDATE
    `pode_visualizar`=VALUES(`pode_visualizar`),
    `pode_editar`=VALUES(`pode_editar`),
    `pode_incluir`=VALUES(`pode_incluir`);

DROP TEMPORARY TABLE `_carga_formularios_campos`;
