-- Habilita o CRUD dos grids contextuais dos Correlacionamentos.
USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

UPDATE `permissoes` p
INNER JOIN `menus` m ON m.`codmenu` = p.`codmenu`
INNER JOIN `perfis` f ON f.`codperfil` = p.`codperfil`
SET p.`pode_incluir` = 1,
    p.`pode_editar` = 1,
    p.`pode_excluir` = 1,
    p.`pode_exportar_excel` = 1
WHERE f.`codigo` = 'ADMINISTRADOR'
  AND m.`codigo` IN ('CORRELACIONAMENTO_AMBIENTAL','CORRELACIONAMENTO_OCUPACIONAL','CORRELACIONAMENTO_SOCIAL');

INSERT INTO `registros_migracao`
(`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('MODULO_LEGAL_CORRELACIONAMENTOS_CRUD_079',
        'gmodelo_dbp.permissoes; greenportal.classes.ManutencaoLegalCadastroGrid',
        'gmodelo_dbp.permissoes; grids contextuais dos Correlacionamentos',
        'CORRECAO','1.2.0','CONCLUIDA',ROW_COUNT(),CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
        JSON_OBJECT('crud_contextual',TRUE,'chaves_compostas',JSON_ARRAY('CODLEGISLACAO','CODUNIDADE'),'perfis_habilitados',JSON_ARRAY('ADMINISTRADOR')))
ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
