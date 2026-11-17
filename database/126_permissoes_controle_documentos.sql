-- Permissoes do CRUD independente de Controle.
-- ADM_DA_EMPRESA: consulta e alteracoes CRUD.
-- GESTOR: somente consulta.

USE `gmodelo_dbp`;

UPDATE `permissoes` p
JOIN `menus` m ON m.`codmenu` = p.`codmenu`
JOIN `perfis` f ON f.`codperfil` = p.`codperfil`
SET p.`pode_incluir` = CASE WHEN f.`codigo` = 'ADM_DA_EMPRESA' THEN 1 ELSE p.`pode_incluir` END,
    p.`pode_visualizar` = CASE WHEN f.`codigo` IN ('ADM_DA_EMPRESA', 'GESTOR') THEN 1 ELSE p.`pode_visualizar` END,
    p.`pode_editar` = CASE WHEN f.`codigo` = 'ADM_DA_EMPRESA' THEN 1 ELSE p.`pode_editar` END,
    p.`pode_excluir` = CASE WHEN f.`codigo` = 'ADM_DA_EMPRESA' THEN 1 ELSE p.`pode_excluir` END,
    p.`pode_exportar_excel` = 0,
    p.`pode_gerar_pdf` = 0
WHERE m.`codigo` = 'CONTROLE_DOCUMENTOS'
  AND f.`codigo` IN ('ADM_DA_EMPRESA', 'GESTOR');

INSERT IGNORE INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
    ('CONTROLE_DOCUMENTOS_PERMISSOES_126',
     'gmodelo_dbp.permissoes; gmodelo_dbp.menus; gmodelo_dbp.perfis',
     'CONTROLE_DOCUMENTOS',
     'CORRECAO','1.0','CONCLUIDA',2,NOW(),NOW(),
     JSON_OBJECT(
         'ADM_DA_EMPRESA', JSON_OBJECT('pode_incluir',1,'pode_visualizar',1,'pode_editar',1,'pode_excluir',1,'pode_exportar_excel',0,'pode_gerar_pdf',0),
         'GESTOR', JSON_OBJECT('pode_incluir',0,'pode_visualizar',1,'pode_editar',0,'pode_excluir',0,'pode_exportar_excel',0,'pode_gerar_pdf',0)
     ));
