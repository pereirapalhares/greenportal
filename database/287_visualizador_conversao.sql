-- Migração 287 — Fila de conversão do visualizador de arquivos (banco universal).
-- O visualizador pede a conversão (Office, TIFF, planilhas, DWG/DXF) e o serviço scripts/visualizador-worker.php,
-- agendado no Agendador de Tarefas do Windows a cada minuto, converte e grava o resultado no cache
-- (storage/runtime/visualizador/<chave>.pdf|html). A tela nunca converte: só pede e acompanha.
-- chave = sha1 do arquivo de origem (caminho + data + tamanho + alvo): arquivo alterado gera chave nova e nova conversão.
-- Executado por scripts/aplicar-migracao-287-visualizador.php (blocos separados por "-- @@").

-- @@
CREATE TABLE IF NOT EXISTS `visualizador_conversao` (
    `chave` CHAR(40) NOT NULL,
    `origem` VARCHAR(1000) NOT NULL,
    `extensao` VARCHAR(10) NOT NULL,
    `alvo` VARCHAR(10) NOT NULL DEFAULT 'pdf',
    `status` VARCHAR(12) NOT NULL DEFAULT 'PENDENTE',
    `tentativas` INT NOT NULL DEFAULT 0,
    `erro` VARCHAR(1000) NULL,
    `banco` VARCHAR(64) NULL,
    `codusuario` INT NULL,
    `pedido_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `inicio_em` DATETIME NULL,
    `fim_em` DATETIME NULL,
    `segundos` DECIMAL(8,1) NULL,
    PRIMARY KEY (`chave`),
    KEY `ix_visualizador_status` (`status`, `pedido_em`)
) ENGINE=InnoDB

-- @@
DROP PROCEDURE IF EXISTS `sp_visualizador_pedir`

-- @@
-- Pede a conversão. Pedido novo entra na fila; já convertido (cache apagado) ou com erro há mais de 10 minutos volta para
-- a fila; pendente ou em andamento fica como está (a tela consulta a cada poucos segundos sem perder o lugar na fila).
CREATE PROCEDURE `sp_visualizador_pedir`(IN p_chave CHAR(40), IN p_origem VARCHAR(1000), IN p_extensao VARCHAR(10), IN p_alvo VARCHAR(10), IN p_banco VARCHAR(64), IN p_usuario INT)
BEGIN
    DECLARE v_status VARCHAR(12) DEFAULT NULL;
    DECLARE v_fim DATETIME DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_status = NULL;
    SELECT `status`, `fim_em` INTO v_status, v_fim FROM `visualizador_conversao` WHERE `chave` = p_chave;
    IF v_status IS NULL THEN
        INSERT IGNORE INTO `visualizador_conversao` (`chave`, `origem`, `extensao`, `alvo`, `status`, `banco`, `codusuario`)
        VALUES (p_chave, p_origem, p_extensao, p_alvo, 'PENDENTE', p_banco, p_usuario);
    ELSEIF v_status = 'CONCLUIDO' OR (v_status = 'ERRO' AND (v_fim IS NULL OR v_fim < DATE_SUB(NOW(), INTERVAL 10 MINUTE))) THEN
        UPDATE `visualizador_conversao`
           SET `status` = 'PENDENTE', `tentativas` = 0, `erro` = NULL, `pedido_em` = NOW(), `origem` = p_origem, `banco` = p_banco, `codusuario` = p_usuario
         WHERE `chave` = p_chave;
    END IF;
    SELECT `status`, `erro`, `tentativas`, GREATEST(0, TIMESTAMPDIFF(SECOND, `pedido_em`, NOW())) AS `espera_segundos`,
           (SELECT COUNT(*) FROM `visualizador_conversao` f WHERE f.`status` = 'PENDENTE' AND f.`pedido_em` < c.`pedido_em`) AS `na_frente`
      FROM `visualizador_conversao` c WHERE `chave` = p_chave;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_visualizador_proximo`

-- @@
-- Próximo pedido para o serviço (o mais antigo já liberado). Pedidos presos em PROCESSANDO há mais de 15 minutos
-- (serviço interrompido) voltam para a fila, ou viram ERRO depois da 3ª tentativa.
CREATE PROCEDURE `sp_visualizador_proximo`()
BEGIN
    DECLARE v_chave CHAR(40) DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_chave = NULL;
    UPDATE `visualizador_conversao`
       SET `status` = IF(`tentativas` < 3, 'PENDENTE', 'ERRO'), `erro` = IF(`tentativas` < 3, `erro`, 'Conversão interrompida (tempo esgotado).'), `fim_em` = NOW()
     WHERE `status` = 'PROCESSANDO' AND `inicio_em` < DATE_SUB(NOW(), INTERVAL 15 MINUTE);
    START TRANSACTION;
    SELECT `chave` INTO v_chave FROM `visualizador_conversao`
     WHERE `status` = 'PENDENTE' AND `pedido_em` <= NOW() ORDER BY `pedido_em` LIMIT 1 FOR UPDATE;
    IF v_chave IS NOT NULL THEN
        UPDATE `visualizador_conversao` SET `status` = 'PROCESSANDO', `inicio_em` = NOW(), `tentativas` = `tentativas` + 1 WHERE `chave` = v_chave;
    END IF;
    COMMIT;
    SELECT `chave`, `origem`, `extensao`, `alvo`, `tentativas`, `banco` FROM `visualizador_conversao` WHERE `chave` = v_chave;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_visualizador_concluir`

-- @@
-- Resultado do serviço: CONCLUIDO, ou ERRO (volta para a fila 1 minuto depois, até a 3ª tentativa).
CREATE PROCEDURE `sp_visualizador_concluir`(IN p_chave CHAR(40), IN p_status VARCHAR(12), IN p_erro VARCHAR(1000))
BEGIN
    UPDATE `visualizador_conversao`
       SET `status` = IF(p_status = 'ERRO' AND `tentativas` < 3, 'PENDENTE', p_status),
           `erro` = LEFT(p_erro, 1000),
           `fim_em` = NOW(),
           `segundos` = LEAST(999999, TIMESTAMPDIFF(MICROSECOND, `inicio_em`, NOW()) / 1000000),
           `pedido_em` = IF(p_status = 'ERRO' AND `tentativas` < 3, DATE_ADD(NOW(), INTERVAL 1 MINUTE), `pedido_em`)
     WHERE `chave` = p_chave;
END

-- @@
CREATE OR REPLACE VIEW `vw_visualizador_conversao` AS
SELECT `chave`, `extensao`, `alvo`, `status`, `tentativas`, `erro`, `banco`, `codusuario`, `pedido_em`, `inicio_em`, `fim_em`, `segundos`,
       SUBSTRING_INDEX(REPLACE(`origem`, '\\', '/'), '/', -1) AS `arquivo`
  FROM `visualizador_conversao`
