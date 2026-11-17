-- Permissões específicas para gestão de arquivos físicos do GED.
-- Upload usa pode_incluir; visualização usa pode_visualizar; exclusão usa pode_excluir.

USE `gmodelo_dbp`;

INSERT INTO `menus`
    (`codmenu_pai`,`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
SELECT
    m.`codmenu`, 'MODULO_LEGAL', 'GESTAO_ARQUIVOS',
    'Gestão de arquivos', 'Gestão de arquivos', 'File management', 'Gestión de archivos',
    '/principal/gestao_arquivos.php', 3, 1, 0
FROM `menus` m
WHERE m.`codigo` = 'CONTROLE_DOCUMENTOS'
  AND NOT EXISTS (SELECT 1 FROM `menus` x WHERE x.`codigo` = 'GESTAO_ARQUIVOS');

SET @codmenu_ged = (SELECT `codmenu` FROM `menus` WHERE `codigo` = 'GESTAO_ARQUIVOS' LIMIT 1);

INSERT INTO `permissoes`
    (`codperfil`,`codmenu`,`pode_incluir`,`pode_visualizar`,`pode_editar`,`pode_excluir`,`pode_exportar_excel`,`pode_gerar_pdf`)
SELECT p.`codperfil`, @codmenu_ged, 0, 0, 0, 0, 0, 0
FROM `perfis` p
WHERE @codmenu_ged IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `permissoes` z
      WHERE z.`codperfil` = p.`codperfil` AND z.`codmenu` = @codmenu_ged
  );

UPDATE `permissoes` p
JOIN `perfis` f ON f.`codperfil` = p.`codperfil`
SET p.`pode_incluir` = CASE WHEN f.`codigo` IN ('ADMINISTRADOR','ADM_DA_EMPRESA','GESTOR') THEN 1 ELSE 0 END,
    p.`pode_visualizar` = CASE WHEN f.`codigo` IN ('ADMINISTRADOR','ADM_DA_EMPRESA','GESTOR') THEN 1 ELSE 0 END,
    p.`pode_editar` = 0,
    p.`pode_excluir` = CASE WHEN f.`codigo` IN ('ADMINISTRADOR','ADM_DA_EMPRESA') THEN 1 ELSE 0 END,
    p.`pode_exportar_excel` = 0,
    p.`pode_gerar_pdf` = 0
WHERE p.`codmenu` = @codmenu_ged;

INSERT IGNORE INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
    ('GESTAO_ARQUIVOS_PERMISSOES_128',
     'gmodelo_dbp.menus; gmodelo_dbp.perfis; gmodelo_dbp.permissoes',
     'GESTAO_ARQUIVOS',
     'SEGURANCA','1.0','CONCLUIDA',3,NOW(),NOW(),
     JSON_OBJECT(
         'ADMINISTRADOR', JSON_OBJECT('pode_incluir',1,'pode_visualizar',1,'pode_editar',0,'pode_excluir',1),
         'ADM_DA_EMPRESA', JSON_OBJECT('pode_incluir',1,'pode_visualizar',1,'pode_editar',0,'pode_excluir',1),
         'GESTOR', JSON_OBJECT('pode_incluir',1,'pode_visualizar',1,'pode_editar',0,'pode_excluir',0)
     ));
