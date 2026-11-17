-- Permissões das transações Treinamentos e InfraEstrutura.
-- ADM_DA_EMPRESA: CRUD sem exportação.
-- GESTOR: somente visualização.

USE `gmodelo_dbp`;

UPDATE `permissoes` p
JOIN `perfis` f ON f.`codperfil` = p.`codperfil`
JOIN `menus` m ON m.`codmenu` = p.`codmenu`
SET p.`pode_incluir` = CASE WHEN f.`codigo` IN ('ADMINISTRADOR','ADM_DA_EMPRESA') THEN 1 ELSE 0 END,
    p.`pode_visualizar` = CASE WHEN f.`codigo` IN ('ADMINISTRADOR','ADM_DA_EMPRESA','GESTOR') THEN 1 ELSE 0 END,
    p.`pode_editar` = CASE WHEN f.`codigo` IN ('ADMINISTRADOR','ADM_DA_EMPRESA') THEN 1 ELSE 0 END,
    p.`pode_excluir` = CASE WHEN f.`codigo` IN ('ADMINISTRADOR','ADM_DA_EMPRESA') THEN 1 ELSE 0 END,
    p.`pode_exportar_excel` = CASE WHEN f.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
    p.`pode_gerar_pdf` = 0
WHERE m.`codigo` IN ('MODULOLEGAL_TREINAMENTOS','MODULOLEGAL_INFRAESTRUTURA');

INSERT IGNORE INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
    ('PERMISSOES_TREINAMENTOS_INFRAESTRUTURA_133',
     'gmodelo_dbp.permissoes; gmodelo_dbp.perfis; gmodelo_dbp.menus',
     'MODULOLEGAL_TREINAMENTOS; MODULOLEGAL_INFRAESTRUTURA',
     'SEGURANCA','1.0','CONCLUIDA',6,NOW(),NOW(),
     JSON_OBJECT(
         'ADM_DA_EMPRESA', JSON_OBJECT('pode_incluir',1,'pode_visualizar',1,'pode_editar',1,'pode_excluir',1),
         'GESTOR', JSON_OBJECT('pode_incluir',0,'pode_visualizar',1,'pode_editar',0,'pode_excluir',0),
         'ADMINISTRADOR', JSON_OBJECT('pode_incluir',1,'pode_visualizar',1,'pode_editar',1,'pode_excluir',1)
     ));
