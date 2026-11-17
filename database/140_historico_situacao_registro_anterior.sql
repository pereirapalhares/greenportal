-- Auditoria do estado anterior de legislacaoxsituacao.
-- O registro e gravado antes de alteracao ou exclusao da situacao.

USE `gmodelo_dbp`;

SET @coluna_registro_anterior := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'situacaoxhistorico'
       AND column_name = 'registroanterior'
);
SET @sql_registro_anterior := IF(
    @coluna_registro_anterior = 0,
    'ALTER TABLE `situacaoxhistorico` ADD COLUMN `registroanterior` JSON NULL AFTER `codusuario`',
    'SELECT 1'
);
PREPARE stmt_registro_anterior FROM @sql_registro_anterior;
EXECUTE stmt_registro_anterior;
DEALLOCATE PREPARE stmt_registro_anterior;

CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_situacaohistorico_grid` AS
SELECT h.`CODHISTORICO`, h.`CODSITUACAO`, s.`CODLEGISLACAO`, l.`CODIGO`,
       s.`codunidade` AS `CODUNIDADE`, COALESCE(u.`DSCUNIDADE`, '') AS `UNIDADE`,
       h.`DATAHISTORICO`, h.`DESCRICAO`, h.`codusuario` AS `CODUSUARIO`,
       COALESCE(usr.`nome`, usr.`userid`, '') AS `USUARIO`, h.`registroanterior`
  FROM `situacaoxhistorico` h
  LEFT JOIN `legislacaoxsituacao` s ON s.`CODSITUACAO` = h.`CODSITUACAO`
  LEFT JOIN `glegislacao_dbp`.`legislacao` l ON l.`CODLEGISLACAO` = s.`CODLEGISLACAO`
  LEFT JOIN `unidades` u ON u.`CODUNIDADE` = s.`codunidade`
  LEFT JOIN `usuarios` usr ON usr.`codusuario` = h.`codusuario`;

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES ('REQUISITOS_SITUACAO_HISTORICO_140', 'gmodelo_dbp.legislacaoxsituacao', 'gmodelo_dbp.situacaoxhistorico.registroanterior', 'ESTRUTURA', '1.0.0', 'CONCLUIDA',
        (SELECT COUNT(*) FROM `situacaoxhistorico`), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
        JSON_OBJECT('eventos', JSON_ARRAY('alteracao', 'exclusao'), 'usuario', 'sessao.usuario.codusuario', 'conteudo', 'registro anterior completo em JSON'))
ON DUPLICATE KEY UPDATE status=VALUES(status), quantidade_registros=VALUES(quantidade_registros), concluida_em=VALUES(concluida_em), detalhes=VALUES(detalhes);
