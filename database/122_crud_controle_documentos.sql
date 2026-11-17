-- CRUD independente de Controle baseado na transacao legada mlDocumentos.
-- Fonte: hpr_configuracoes (configuracoes_transacoes.codtransacao = 124).

USE `gmodelo_dbp`;

CREATE OR REPLACE VIEW `vw_controle_documentos_grid` AS
SELECT
    d.`CODDOCUMENTO`,
    d.`CODIGO`,
    d.`TITULO`,
    d.`ARQUIVO`,
    (SELECT GROUP_CONCAT(DISTINCT u.`DSCUNIDADE` ORDER BY u.`DSCUNIDADE` SEPARATOR '; ')
       FROM `documentosxunidades` dxu JOIN `unidades` u ON u.`CODUNIDADE` = dxu.`CODUNIDADE`
      WHERE dxu.`CODDOCUMENTO` = d.`CODDOCUMENTO`) AS `UNIDADES`,
    (SELECT GROUP_CONCAT(DISTINCT a.`DSCATIVIDADE` ORDER BY a.`DSCATIVIDADE` SEPARATOR '; ')
       FROM `documentosxatividades` dxa JOIN `atividades` a ON a.`CODATIVIDADE` = dxa.`CODATIVIDADE`
      WHERE dxa.`CODDOCUMENTO` = d.`CODDOCUMENTO`) AS `ATIVIDADES`,
    (SELECT GROUP_CONCAT(DISTINCT a.`DSCASPECTO` ORDER BY a.`DSCASPECTO` SEPARATOR '; ')
       FROM `documentosxaspectos` dxa JOIN `aspectos` a ON a.`CODASPECTO` = dxa.`CODASPECTO`
      WHERE dxa.`CODDOCUMENTO` = d.`CODDOCUMENTO`) AS `ASPECTOS`,
    (SELECT GROUP_CONCAT(DISTINCT i.`DSCIMPACTO` ORDER BY i.`DSCIMPACTO` SEPARATOR '; ')
       FROM `documentosximpactos` dxi JOIN `impactos` i ON i.`CODIMPACTO` = dxi.`CODIMPACTO`
      WHERE dxi.`CODDOCUMENTO` = d.`CODDOCUMENTO`) AS `IMPACTOS`,
    (SELECT GROUP_CONCAT(DISTINCT p.`DSCPERIGO` ORDER BY p.`DSCPERIGO` SEPARATOR '; ')
       FROM `documentosxperigos` dxp JOIN `perigos` p ON p.`CODPERIGO` = dxp.`CODPERIGO`
      WHERE dxp.`CODDOCUMENTO` = d.`CODDOCUMENTO`) AS `PERIGOS`,
    (SELECT GROUP_CONCAT(DISTINCT r.`DSCRISCO` ORDER BY r.`DSCRISCO` SEPARATOR '; ')
       FROM `documentosxriscos` dxr JOIN `riscos` r ON r.`CODRISCO` = dxr.`CODRISCO`
      WHERE dxr.`CODDOCUMENTO` = d.`CODDOCUMENTO`) AS `RISCOS`,
    (SELECT GROUP_CONCAT(DISTINCT pk.`dscpalavrachave` ORDER BY pk.`dscpalavrachave` SEPARATOR '; ')
       FROM `documentosxpalavraschave` dxpk JOIN `guniversal_dbp`.`palavras_chaves` pk ON pk.`codpalavrachave` = dxpk.`codpalavrachave`
      WHERE dxpk.`coddocumento` = d.`CODDOCUMENTO`) AS `PALAVRAS_CHAVES`
FROM `documentos` d;

INSERT INTO `menus`
    (`codmenu_pai`,`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
SELECT
    m.`codmenu`, 'MODULO_LEGAL', 'CONTROLE_DOCUMENTOS',
    'Controle', 'Controle', 'Control', 'Control',
    '/principal/modulolegal_controle.php', 2, 36, 1
FROM `menus` m
WHERE m.`codigo` = 'MODULO_LEGAL'
  AND NOT EXISTS (SELECT 1 FROM `menus` x WHERE x.`codigo` = 'CONTROLE_DOCUMENTOS');

SET @codmenu_controle = (SELECT `codmenu` FROM `menus` WHERE `codigo` = 'CONTROLE_DOCUMENTOS' LIMIT 1);

INSERT INTO `permissoes`
    (`codperfil`,`codmenu`,`pode_incluir`,`pode_visualizar`,`pode_editar`,`pode_excluir`,`pode_exportar_excel`,`pode_gerar_pdf`)
SELECT p.`codperfil`, @codmenu_controle,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       0
FROM `perfis` p
WHERE @codmenu_controle IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `permissoes` z
      WHERE z.`codperfil` = p.`codperfil` AND z.`codmenu` = @codmenu_controle
  );

SET @qtd_documentos_controle = (SELECT COUNT(*) FROM `documentos`);

INSERT IGNORE INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
    ('CONTROLE_DOCUMENTOS_CRUD_122',
     'hpr_configuracoes.configuracoes_transacoes (dsctransacao = mlDocumentos; codtransacao = 124)',
     'gmodelo_dbp.vw_controle_documentos_grid; menus; permissoes',
     'ESTRUTURA','1.0','CONCLUIDA',
     @qtd_documentos_controle, NOW(), NOW(),
     JSON_OBJECT(
         'transacao_legada', 'mlDocumentos',
         'codtransacao_legada', 124,
         'tabela_legada', 'documentos',
         'chave', 'CODDOCUMENTO',
         'campos_grid', JSON_ARRAY('CODIGO','TITULO','ARQUIVO'),
         'relacionamentos_preparados', JSON_ARRAY(
             'mlDocumentosXUnidades','mlDocumentosXAtividades','mlDocumentosXPalavrasChave',
             'mlDocumentosXAspectos','mlDocumentosXImpactos','mlDocumentosXPerigos','mlDocumentosXRiscos',
             'mlDocumentosXAspectoSocial','mlDocumentosXImpactoSocial'
         )
     )
);
