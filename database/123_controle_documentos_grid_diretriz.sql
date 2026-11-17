-- Adequa o grid de Controle ao padrao de relacionamentos 1:N:
-- resumo nas colunas e edicao pela barra de acoes/modal.
USE `gmodelo_dbp`;

CREATE OR REPLACE VIEW `vw_controle_documentos_grid` AS
SELECT
    d.`CODDOCUMENTO`, d.`CODIGO`, d.`TITULO`, d.`ARQUIVO`,
    (SELECT GROUP_CONCAT(DISTINCT u.`DSCUNIDADE` ORDER BY u.`DSCUNIDADE` SEPARATOR '; ') FROM `documentosxunidades` dxu JOIN `unidades` u ON u.`CODUNIDADE` = dxu.`CODUNIDADE` WHERE dxu.`CODDOCUMENTO` = d.`CODDOCUMENTO`) AS `UNIDADES`,
    (SELECT GROUP_CONCAT(DISTINCT a.`DSCATIVIDADE` ORDER BY a.`DSCATIVIDADE` SEPARATOR '; ') FROM `documentosxatividades` dxa JOIN `atividades` a ON a.`CODATIVIDADE` = dxa.`CODATIVIDADE` WHERE dxa.`CODDOCUMENTO` = d.`CODDOCUMENTO`) AS `ATIVIDADES`,
    (SELECT GROUP_CONCAT(DISTINCT a.`DSCASPECTO` ORDER BY a.`DSCASPECTO` SEPARATOR '; ') FROM `documentosxaspectos` dxa JOIN `aspectos` a ON a.`CODASPECTO` = dxa.`CODASPECTO` WHERE dxa.`CODDOCUMENTO` = d.`CODDOCUMENTO`) AS `ASPECTOS`,
    (SELECT GROUP_CONCAT(DISTINCT i.`DSCIMPACTO` ORDER BY i.`DSCIMPACTO` SEPARATOR '; ') FROM `documentosximpactos` dxi JOIN `impactos` i ON i.`CODIMPACTO` = dxi.`CODIMPACTO` WHERE dxi.`CODDOCUMENTO` = d.`CODDOCUMENTO`) AS `IMPACTOS`,
    (SELECT GROUP_CONCAT(DISTINCT p.`DSCPERIGO` ORDER BY p.`DSCPERIGO` SEPARATOR '; ') FROM `documentosxperigos` dxp JOIN `perigos` p ON p.`CODPERIGO` = dxp.`CODPERIGO` WHERE dxp.`CODDOCUMENTO` = d.`CODDOCUMENTO`) AS `PERIGOS`,
    (SELECT GROUP_CONCAT(DISTINCT r.`DSCRISCO` ORDER BY r.`DSCRISCO` SEPARATOR '; ') FROM `documentosxriscos` dxr JOIN `riscos` r ON r.`CODRISCO` = dxr.`CODRISCO` WHERE dxr.`CODDOCUMENTO` = d.`CODDOCUMENTO`) AS `RISCOS`,
    (SELECT GROUP_CONCAT(DISTINCT pk.`dscpalavrachave` ORDER BY pk.`dscpalavrachave` SEPARATOR '; ') FROM `documentosxpalavraschave` dxpk JOIN `guniversal_dbp`.`palavras_chaves` pk ON pk.`codpalavrachave` = dxpk.`codpalavrachave` WHERE dxpk.`coddocumento` = d.`CODDOCUMENTO`) AS `PALAVRAS_CHAVES`,
    (SELECT GROUP_CONCAT(DISTINCT a.`DSCASPECTOSOCIAL` ORDER BY a.`DSCASPECTOSOCIAL` SEPARATOR '; ') FROM `lais_documentosxaspectos` dxa JOIN `lais_aspectossociais` a ON a.`CODASPECTOSOCIAL` = dxa.`CODASPECTOSOCIAL` WHERE dxa.`CODDOCUMENTO` = d.`CODDOCUMENTO`) AS `ASPECTOS_SOCIAIS`,
    (SELECT GROUP_CONCAT(DISTINCT i.`DSCIMPACTOSOCIAL` ORDER BY i.`DSCIMPACTOSOCIAL` SEPARATOR '; ') FROM `lais_documentosximpactos` dxi JOIN `lais_impactossociais` i ON i.`CODIMPACTOSOCIAL` = dxi.`CODIMPACTOSOCIAL` WHERE dxi.`CODDOCUMENTO` = d.`CODDOCUMENTO`) AS `IMPACTOS_SOCIAIS`,
    (SELECT GROUP_CONCAT(DISTINCT COALESCE(u.`nome`, g.`nome`) ORDER BY COALESCE(u.`nome`, g.`nome`) SEPARATOR '; ')
       FROM `vw_responsabilidade_controle_documentos` rd
       LEFT JOIN `usuarios` u ON u.`codusuario` = rd.`CODUSUARIO`
       LEFT JOIN `grupos` g ON g.`codgrupo` = rd.`CODGRUPO`
      WHERE rd.`CODDOCUMENTO` = d.`CODDOCUMENTO`) AS `RESPONSAVEIS`
FROM `documentos` d;

INSERT IGNORE INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
SELECT 'CONTROLE_DOCUMENTOS_GRID_DIRETRIZ_123',
       'diretrizes-projeto.txt (relacionamentos 1:N)',
       'gmodelo_dbp.vw_controle_documentos_grid', 'ESTRUTURA','1.0','CONCLUIDA',
       COUNT(*), NOW(), NOW(),
       JSON_OBJECT('resumos', JSON_ARRAY('UNIDADES','ATIVIDADES','ASPECTOS','IMPACTOS','PERIGOS','RISCOS','PALAVRAS_CHAVES'), 'edicao', 'barra de acoes + modal de marcacao')
FROM `documentos`;
