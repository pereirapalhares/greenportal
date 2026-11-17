-- GreenSolutions 4.0.1 — menus de Portaria e Administração para o acesso personalizado da Atlas.
-- Aplicar em guniversal_dbp e gatlas_dbp pelo script da migração 215.

INSERT INTO `menus`
    (`codmenu`, `codmenu_pai`, `modulo`, `codigo`, `label`, `label_pt`, `label_en`, `label_es`, `url`, `nivel`, `ordem`, `ativo`)
VALUES
    (115, 44, 'ADMINISTRACAO', 'ADMINISTRACAO_HELPDESK', 'Help Desk', 'Help Desk', 'Help Desk', 'Mesa de ayuda', '/principal/administracao_helpdesk.php', 2, 8, 1),
    (119, NULL, 'PORTARIA', 'PORTARIA', 'Portaria', 'Portaria', 'Gatehouse', 'Portería', NULL, 1, 60, 1),
    (120, 119, 'PORTARIA', 'PORTARIA_DOCUMENTACAO_FORNECEDOR', 'Documentação de Fornecedor', 'Documentação de Fornecedor', 'Supplier Documentation', 'Documentación de Proveedor', '/principal/portaria_documentacaofornecedor.php', 2, 10, 1),
    (121, 119, 'PORTARIA', 'PORTARIA_DOCUMENTACAO_FUNCIONARIO', 'Documentação de Funcionário', 'Documentação de Funcionário', 'Employee Documentation', 'Documentación de Empleado', '/principal/portaria_documentacaofuncionario.php', 2, 20, 1),
    (122, 44, 'ADMINISTRACAO', 'ADMINISTRACAO_EMAILS_ENVIADOS', 'Emails', 'Emails', 'Sent Emails', 'Correos enviados', '/principal/administracao_emailsenviados.php', 2, 7, 1)
ON DUPLICATE KEY UPDATE
    `codmenu_pai` = VALUES(`codmenu_pai`),
    `modulo` = VALUES(`modulo`),
    `label` = VALUES(`label`),
    `label_pt` = VALUES(`label_pt`),
    `label_en` = VALUES(`label_en`),
    `label_es` = VALUES(`label_es`),
    `url` = VALUES(`url`),
    `nivel` = VALUES(`nivel`),
    `ordem` = VALUES(`ordem`),
    `ativo` = VALUES(`ativo`);
