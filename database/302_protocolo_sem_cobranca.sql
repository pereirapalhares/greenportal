-- Migração 302 — Homologação de Fornecedores: documentos com "Em protocolo" = Sim e sem data de cobrança (carga antiga).
-- Decisão do usuário (17/10/2026): passam para "Aguardando vencimento do protocolo", com cobrança em 30 dias a partir da conversão;
-- a partir daí o serviço do protocolo (migração 300) trata normalmente. NA e "Não enviar ao fornecedor" não mudam.
-- Aplicado por scripts/aplicar-migracao-302-protocolo-sem-cobranca.php. Comandos separados por "-- @@".

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_protocolo_sem_cobranca_converter`

-- @@
-- p_aplicar = 0 só lista. Grava histórico (p_usuario; 0 = serviço/migração) e o log das regras (codregra PROTOCOLO_SEM_COBRANCA).
CREATE PROCEDURE `sp_fo_protocolo_sem_cobranca_converter`(IN p_aplicar TINYINT, IN p_usuario BIGINT)
SQL SECURITY INVOKER
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; DROP TEMPORARY TABLE IF EXISTS tmp_fo_psc; RESIGNAL; END;
    DROP TEMPORARY TABLE IF EXISTS tmp_fo_psc;
    CREATE TEMPORARY TABLE tmp_fo_psc (codigo BIGINT PRIMARY KEY, codfornecedor BIGINT, situacao_antes VARCHAR(100));
    INSERT INTO tmp_fo_psc (codigo, codfornecedor, situacao_antes)
    SELECT x.codfornecedorxcriterios, x.codfornecedor, COALESCE(x.situacao, '')
      FROM `fo_fornecedorxcriterios` x
     WHERE UPPER(COALESCE(x.emprotocolo, '')) = 'S'
       AND (x.datacobranca_emprotocolo IS NULL OR x.datacobranca_emprotocolo < '1900-01-01')
       AND COALESCE(TRIM(x.situacao), '') NOT IN ('NA', 'Não enviar ao fornecedor', 'Aguardando vencimento do protocolo');
    IF COALESCE(p_aplicar, 0) = 1 THEN
        START TRANSACTION;
        UPDATE `fo_fornecedorxcriterios` x JOIN tmp_fo_psc t ON t.codigo = x.codfornecedorxcriterios
           SET x.situacao = 'Aguardando vencimento do protocolo', x.datacobranca_emprotocolo = DATE_ADD(CURDATE(), INTERVAL 30 DAY);
        INSERT INTO `fo_fornecedorxcriterios_historico` (CODUSUARIOALTERACAO, DATAALTERACAO, codfornecedorxcriterios, codfornecedor, codtipo, codcriterio,
            enviadoodocumento, datadevalidade, datadereacao, situacao, incluidocontrato, codusuario, codusuario_fornecedor, observacao,
            observacao_do_fornecedor, emprotocolo, datacobranca_emprotocolo)
        SELECT COALESCE(p_usuario, 0), NOW(), x.codfornecedorxcriterios, x.codfornecedor, x.codtipo, x.codcriterio, x.enviadoodocumento, x.datadevalidade,
               x.datadereacao, x.situacao, x.incluidocontrato, x.codusuario, x.codusuario_fornecedor, x.observacao, x.observacao_do_fornecedor,
               x.emprotocolo, x.datacobranca_emprotocolo
          FROM `fo_fornecedorxcriterios` x JOIN tmp_fo_psc t ON t.codigo = x.codfornecedorxcriterios;
        INSERT INTO `fo_regras_documentos_log` (escopo, codigo, codfornecedor, codregra, situacao_antes, situacao_depois, campo, campo_antes, campo_depois, arquivos, codusuario)
        SELECT 'FORNECEDOR', codigo, codfornecedor, 'PROTOCOLO_SEM_COBRANCA', situacao_antes, 'Aguardando vencimento do protocolo',
               'datacobranca_emprotocolo', NULL, DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 30 DAY), '%Y-%m-%d'), NULL, COALESCE(p_usuario, 0)
          FROM tmp_fo_psc;
        COMMIT;
    END IF;
    SELECT situacao_antes AS SITUACAO_ANTES, COUNT(*) AS QTD FROM tmp_fo_psc GROUP BY situacao_antes ORDER BY QTD DESC;
    DROP TEMPORARY TABLE IF EXISTS tmp_fo_psc;
END
