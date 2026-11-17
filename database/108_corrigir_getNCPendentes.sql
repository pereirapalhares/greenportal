-- Correcao da procedure de pendencias do modulo de nao conformidades.
-- Escopo: tenant Modelo / gmodelo_dbp.

USE `gmodelo_dbp`;
SET NAMES utf8mb4;

DELIMITER $$

DROP PROCEDURE IF EXISTS `getNCPendentes`$$
CREATE PROCEDURE `getNCPendentes`(IN in_codusurio INT)
READS SQL DATA
BEGIN
    SELECT DISTINCT
           o.codocorrencia,
           o.nrano,
           u.dscunidade,
           o.identificacao,
           o.etapa,
           DATE_FORMAT(o.data_de_conclusao_prevista, '%d/%m/%Y') AS data_de_conclusao_prevista,
           DATE_FORMAT(o.prazo_analize, '%d/%m/%Y') AS prazo_analize,
           t.dsctiponaoconformidade,
           o.codusuario_gestor,
           o.codusuario_prazo_analise,
           o.codusuario_responsavel,
           o.codusuario_emissor,
           o.recusa_do_gestor,
           DATE_FORMAT(o.data_de_envia_analise_eficacia, '%d/%m/%Y') AS data_de_envia_analise_eficacia,
           IF(getQtdDias_nc_planodeacao(o.codocorrencia, in_codusurio) = 0, 999,
              getQtdDias_nc_planodeacao(o.codocorrencia, in_codusurio)) AS qtdlinhas
      FROM `gmodelo_dbp`.`nc_indicacao_ocorrencia` AS o
      INNER JOIN `gmodelo_dbp`.`nc_tipo_de_naoconformidade` AS t
              ON t.CODTIPONAOCONFORMIDADE = o.CODTIPONAOCONFORMIDADE
      INNER JOIN `gmodelo_dbp`.`unidades` AS u
              ON u.codunidade = o.codunidade
     WHERE o.etapa <> 'Finalizado'
       AND (
            (o.codusuario_emissor = in_codusurio
             AND o.etapa = 'Recusado pelo Gestor')
            OR
            (o.codusuario_responsavel = in_codusurio
             AND o.etapa IN ('Em Analise da Causa Raiz',
                             'Recusado pela Avaliacao da Eficacia'))
            OR
            (o.codusuario_responsavel = in_codusurio
             AND o.etapa = CONVERT(0x506C616E6F2064652041C3A7C3A36F USING utf8mb4)
             AND EXISTS (
                 SELECT 1
                   FROM `gmodelo_dbp`.`nc_causas` AS c
                  WHERE c.codocorrencia = o.codocorrencia
                    AND NOT EXISTS (
                        SELECT 1
                          FROM `gmodelo_dbp`.`nc_planodeacao` AS p
                         WHERE p.codcausa = c.codcausa
                    )
             ))
            OR
            (o.codusuario_gestor = in_codusurio
             AND o.etapa IN (CONVERT(0x4176616C6961C3A7C3A36F206461204566696361636961 USING utf8mb4), 'Com o Gestor'))
            OR
            (o.etapa = CONVERT(0x506C616E6F2064652041C3A7C3A36F USING utf8mb4)
             AND EXISTS (
                 SELECT 1
                   FROM `gmodelo_dbp`.`nc_causas` AS c
                   INNER JOIN `gmodelo_dbp`.`nc_planodeacao` AS p
                           ON p.codcausa = c.codcausa
                  WHERE c.codocorrencia = o.codocorrencia
                    AND p.CODUSUARIO = in_codusurio
                    AND p.status < 100
             ))
            OR
            (o.etapa = CONVERT(0x506C616E6F2064652041C3A7C3A36F USING utf8mb4)
             AND EXISTS (
                 SELECT 1
                   FROM `gmodelo_dbp`.`nc_planodeacao` AS p
                  WHERE p.codocorrencia = o.codocorrencia
                    AND p.CODUSUARIO = in_codusurio
                    AND p.codusuario IS NOT NULL
                    AND p.status < 100
             ))
       );
END$$

DELIMITER ;

INSERT INTO `gmodelo_dbp`.`registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`,
     `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('MODULO_NC_108',
     'green_modelo.getNCPendentes',
     'gmodelo_dbp.getNCPendentes',
     'ESTRUTURA', '1.0.0', 'CONCLUIDA', 0,
     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
     JSON_OBJECT('utf8_corrigido', TRUE,
                 'join_explicito', TRUE,
                 'filtro_usuario_sem_ramo_amplo', TRUE,
                 'ramos_pendencias', 6))
ON DUPLICATE KEY UPDATE
    `status` = VALUES(`status`),
    `concluida_em` = VALUES(`concluida_em`),
    `detalhes` = VALUES(`detalhes`);
