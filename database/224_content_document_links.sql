/*
 * Migração 224 — Fase 1: vínculos documentais reutilizáveis.
 *
 * Um content_document pode atender vários requisitos, controles, licenças
 * ou outros registros. O vínculo é separado do arquivo físico e, portanto,
 * pode ser removido sem apagar o documento ou suas versões.
 * Aplicar em cada banco tenant.
 */

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `content_document_links` (
    `codvinculodocumento` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `coddocumento` BIGINT UNSIGNED NOT NULL,
    `tipo_entidade` VARCHAR(50) NOT NULL COMMENT 'REQUISITO_LEGAL, CONTROLE, LICENCA, TREINAMENTO, INFRAESTRUTURA, MONITORAMENTO ou outro tipo do domínio',
    `entidade_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Código do registro relacionado quando aplicável; 0 quando não houver',
    `codrequisitolegal` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Requisito legal relacionado; 0 quando não houver',
    `codunidade` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unidade atendida pelo vínculo; 0 quando não houver',
    `papel` VARCHAR(40) NOT NULL DEFAULT 'EVIDENCIA' COMMENT 'EVIDENCIA, REFERENCIA, PROCEDIMENTO ou outro papel do documento',
    `origem_vinculo` VARCHAR(30) NOT NULL DEFAULT 'USUARIO' COMMENT 'USUARIO, MIGRACAO ou IA_CONFIRMADA',
    `status` VARCHAR(20) NOT NULL DEFAULT 'ATIVO',
    `observacao` TEXT NULL,
    `codusuario` BIGINT UNSIGNED NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`codvinculodocumento`),
    UNIQUE KEY `uk_content_document_link` (`coddocumento`,`tipo_entidade`,`entidade_id`,`codrequisitolegal`,`codunidade`,`papel`),
    KEY `ix_content_document_links_documento` (`coddocumento`,`status`),
    KEY `ix_content_document_links_entidade` (`tipo_entidade`,`entidade_id`,`status`),
    KEY `ix_content_document_links_requisito` (`codrequisitolegal`,`codunidade`,`status`),
    KEY `ix_content_document_links_unidade` (`codunidade`,`status`),
    KEY `ix_content_document_links_usuario` (`codusuario`),
    CONSTRAINT `fk_content_document_links_documento` FOREIGN KEY (`coddocumento`) REFERENCES `content_documents` (`coddocumento`),
    CONSTRAINT `fk_content_document_links_usuario` FOREIGN KEY (`codusuario`) REFERENCES `usuarios` (`codusuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
    ('GREEN_CONTENT_DOCUMENT_LINKS_224',
     'content_documents;content_versions',
     'content_document_links',
     'ESTRUTURA','4.0.1','CONCLUIDA',
     (SELECT COUNT(*) FROM `content_document_links`),
     CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
     JSON_OBJECT(
         'fase',1,
         'documentos_reutilizaveis',TRUE,
         'relacionamento','muitos-para-muitos',
         'arquivos_nao_duplicados',TRUE,
         'fluxos_de_negocio_alterados',FALSE
     ))
ON DUPLICATE KEY UPDATE
    status=VALUES(status),
    quantidade_registros=VALUES(quantidade_registros),
    concluida_em=VALUES(concluida_em),
    detalhes=VALUES(detalhes);
