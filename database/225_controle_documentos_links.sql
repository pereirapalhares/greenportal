/*
 * Migração 225 — Fase 2: integração dos documentos de Controle aos vínculos
 * documentais reutilizáveis da Fase 1.
 *
 * A estrutura legada continua sendo a fonte funcional do módulo. Esta
 * migração cria/recupera o documento lógico em content_documents e espelha
 * seus vínculos com requisitos legais, aspectos e impactos sociais.
 */

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

INSERT INTO `content_documents`
    (`chave_idempotencia`,`codigo`,`titulo`,`descricao`,`tipo_documento`,
     `codusuario_criador`,`codunidade`,`origem_entidade`,`origem_id`,`status`)
SELECT
    CONCAT('CONTROLE_DOCUMENTO_', d.`CODDOCUMENTO`),
    CONCAT('CONTROLE_DOCUMENTO_', d.`CODDOCUMENTO`),
    COALESCE(NULLIF(d.`TITULO`, ''), CONCAT('Documento de controle ', d.`CODDOCUMENTO`)),
    NULLIF(d.`ARQUIVO`, ''),
    'CONTROLE',
    COALESCE((SELECT MIN(u.`codusuario`) FROM `usuarios` u WHERE u.`ativo`=1), 1),
    COALESCE((SELECT MIN(dx.`CODUNIDADE`) FROM `documentosxunidades` dx WHERE dx.`CODDOCUMENTO`=d.`CODDOCUMENTO`), 0),
    'documentos',
    CAST(d.`CODDOCUMENTO` AS CHAR),
    'RASCUNHO'
FROM `documentos` d
WHERE NOT EXISTS (
    SELECT 1 FROM `content_documents` c
    WHERE c.`chave_idempotencia`=CONCAT('CONTROLE_DOCUMENTO_', d.`CODDOCUMENTO`)
);

INSERT INTO `content_document_links`
    (`coddocumento`,`tipo_entidade`,`entidade_id`,`codrequisitolegal`,`codunidade`,
     `papel`,`origem_vinculo`,`status`,`codusuario`)
SELECT c.`coddocumento`, 'CONTROLE_REQUISITO', d.`CODDOCUMENTO`,
       x.`CODLEGISLACAO`, COALESCE(x.`codunidade`, 0),
       'EVIDENCIA', 'MIGRACAO', 'ATIVO', NULL
FROM `legislacaoxdocumentos` x
JOIN `documentos` d ON d.`CODDOCUMENTO`=x.`CODDOCUMENTO`
JOIN `content_documents` c ON c.`chave_idempotencia`=CONCAT('CONTROLE_DOCUMENTO_', d.`CODDOCUMENTO`)
WHERE NOT EXISTS (
    SELECT 1 FROM `content_document_links` l
    WHERE l.`coddocumento`=c.`coddocumento`
      AND l.`tipo_entidade`='CONTROLE_REQUISITO'
      AND l.`entidade_id`=d.`CODDOCUMENTO`
      AND l.`codrequisitolegal`=x.`CODLEGISLACAO`
      AND l.`codunidade`=COALESCE(x.`codunidade`, 0)
      AND l.`papel`='EVIDENCIA'
);

INSERT INTO `content_document_links`
    (`coddocumento`,`tipo_entidade`,`entidade_id`,`codrequisitolegal`,`codunidade`,
     `papel`,`origem_vinculo`,`status`,`codusuario`)
SELECT c.`coddocumento`, 'CONTROLE_LAIS_ASPECTO', x.`CODASPECTOSOCIAL`, 0,
       COALESCE(x.`codunidade`, 0), 'EVIDENCIA', 'MIGRACAO', 'ATIVO', NULL
FROM `lais_documentosxaspectos` x
JOIN `content_documents` c ON c.`chave_idempotencia`=CONCAT('CONTROLE_DOCUMENTO_', x.`CODDOCUMENTO`)
WHERE x.`CODDOCUMENTO` IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `content_document_links` l
    WHERE l.`coddocumento`=c.`coddocumento`
      AND l.`tipo_entidade`='CONTROLE_LAIS_ASPECTO'
      AND l.`entidade_id`=x.`CODASPECTOSOCIAL`
      AND l.`codunidade`=COALESCE(x.`codunidade`, 0)
      AND l.`papel`='EVIDENCIA'
  );

INSERT INTO `content_document_links`
    (`coddocumento`,`tipo_entidade`,`entidade_id`,`codrequisitolegal`,`codunidade`,
     `papel`,`origem_vinculo`,`status`,`codusuario`)
SELECT c.`coddocumento`, 'CONTROLE_LAIS_IMPACTO', x.`CODIMPACTOSOCIAL`, 0,
       COALESCE(x.`codunidade`, 0), 'EVIDENCIA', 'MIGRACAO', 'ATIVO', NULL
FROM `lais_documentosximpactos` x
JOIN `content_documents` c ON c.`chave_idempotencia`=CONCAT('CONTROLE_DOCUMENTO_', x.`CODDOCUMENTO`)
WHERE x.`CODDOCUMENTO` IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `content_document_links` l
    WHERE l.`coddocumento`=c.`coddocumento`
      AND l.`tipo_entidade`='CONTROLE_LAIS_IMPACTO'
      AND l.`entidade_id`=x.`CODIMPACTOSOCIAL`
      AND l.`codunidade`=COALESCE(x.`codunidade`, 0)
      AND l.`papel`='EVIDENCIA'
  );

CREATE OR REPLACE VIEW `vw_controle_documentos_links` AS
SELECT l.`codvinculodocumento`, l.`coddocumento`, c.`codigo`, c.`titulo`,
       c.`descricao`, c.`tipo_documento`, c.`origem_entidade`, c.`origem_id`,
       l.`tipo_entidade`, l.`entidade_id`, l.`codrequisitolegal`, l.`codunidade`,
       l.`papel`, l.`origem_vinculo`, l.`status`, l.`criado_em`, l.`atualizado_em`
  FROM `content_document_links` l
  JOIN `content_documents` c ON c.`coddocumento`=l.`coddocumento`
 WHERE l.`tipo_entidade` LIKE 'CONTROLE%';

INSERT INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
    ('GREEN_CONTROL_DOCUMENT_LINKS_225',
     'documentos;legislacaoxdocumentos;lais_documentosxaspectos;lais_documentosximpactos',
     'content_documents;content_document_links;vw_controle_documentos_links',
     'DADOS','4.0.1','CONCLUIDA',
     (SELECT COUNT(*) FROM `content_document_links` WHERE `tipo_entidade` LIKE 'CONTROLE%'),
     CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
     JSON_OBJECT(
         'fase',2,
         'transacao_principal','modulolegallais_controlesdocumentos',
         'documentos_reutilizaveis',TRUE,
         'relacionamentos_legados_preservados',TRUE,
         'arquivos_duplicados',FALSE
     ))
ON DUPLICATE KEY UPDATE
    status=VALUES(status),
    quantidade_registros=VALUES(quantidade_registros),
    concluida_em=VALUES(concluida_em),
    detalhes=VALUES(detalhes);
