/*
  Primeira versao do fluxo de potenciais gerados por IA.
  Aplicar em cada banco de empresa gmodelo_dbp apos a estrutura base.
*/

CREATE TABLE IF NOT EXISTS ml_potenciais_solicitacoes (
    codsolicitacao BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codusuario BIGINT UNSIGNED NULL,
    idioma CHAR(2) NOT NULL DEFAULT 'pt',
    contexto_json JSON NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'PENDENTE',
    solicitada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    iniciada_em DATETIME NULL,
    concluida_em DATETIME NULL,
    processador VARCHAR(150) NULL,
    erro TEXT NULL,
    PRIMARY KEY (codsolicitacao),
    KEY ix_ml_potenciais_solicitacoes_status (status, solicitada_em),
    CONSTRAINT ck_ml_potenciais_solicitacoes_idioma CHECK (idioma IN ('pt','en','es')),
    CONSTRAINT ck_ml_potenciais_solicitacoes_status CHECK (status IN ('PENDENTE','PROCESSANDO','CONCLUIDA','ERRO','CANCELADA'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ml_potenciais (
    codpotencial BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codsolicitacao BIGINT UNSIGNED NOT NULL,
    codrequisitolegal BIGINT UNSIGNED NULL,
    tipo_potencial VARCHAR(30) NOT NULL,
    titulo TEXT NOT NULL,
    descricao TEXT NULL,
    justificativa_ia TEXT NULL,
    confianca_ia DECIMAL(5,2) NULL,
    idioma_conteudo CHAR(2) NOT NULL DEFAULT 'pt',
    status VARCHAR(20) NOT NULL DEFAULT 'SUGERIDO',
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    validado_em DATETIME NULL,
    validado_por BIGINT UNSIGNED NULL,
    codregistro_destino BIGINT UNSIGNED NULL,
    PRIMARY KEY (codpotencial),
    KEY ix_ml_potenciais_solicitacao (codsolicitacao),
    KEY ix_ml_potenciais_status (status),
    CONSTRAINT fk_ml_potenciais_solicitacao FOREIGN KEY (codsolicitacao) REFERENCES ml_potenciais_solicitacoes (codsolicitacao),
    CONSTRAINT ck_ml_potenciais_tipo CHECK (tipo_potencial IN ('LICENCA','MONITORAMENTO','TREINAMENTO','INFRAESTRUTURA','CONTROLE','INFLUENCIA')),
    CONSTRAINT ck_ml_potenciais_status CHECK (status IN ('SUGERIDO','APROVADO','REJEITADO','CONVERTIDO')),
    CONSTRAINT ck_ml_potenciais_idioma CHECK (idioma_conteudo IN ('pt','en','es'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP VIEW IF EXISTS vw_ml_potenciais_solicitacoes_grid;
CREATE VIEW vw_ml_potenciais_solicitacoes_grid AS
SELECT codsolicitacao, codusuario, idioma, status, solicitada_em, iniciada_em,
       concluida_em, processador, erro
  FROM ml_potenciais_solicitacoes;

DROP VIEW IF EXISTS vw_ml_potenciais_grid;
CREATE VIEW vw_ml_potenciais_grid AS
SELECT p.codpotencial, p.codsolicitacao, p.codrequisitolegal,
       p.tipo_potencial, p.titulo, p.descricao, p.justificativa_ia,
       p.confianca_ia, p.idioma_conteudo, p.status, p.criado_em,
       p.validado_em, p.validado_por, p.codregistro_destino
  FROM ml_potenciais p;

DROP PROCEDURE IF EXISTS sp_ia_potencial_solicitar;
DELIMITER $$
CREATE PROCEDURE sp_ia_potencial_solicitar(
    IN p_codusuario BIGINT,
    IN p_idioma CHAR(2),
    IN p_contexto_json JSON
)
SQL SECURITY DEFINER
BEGIN
    INSERT INTO ml_potenciais_solicitacoes (codusuario, idioma, contexto_json)
    VALUES (p_codusuario, IF(p_idioma IN ('pt','en','es'), p_idioma, 'pt'), p_contexto_json);
    SELECT LAST_INSERT_ID() AS codsolicitacao;
END$$

DROP PROCEDURE IF EXISTS sp_ia_potencial_proxima_solicitacao;
CREATE PROCEDURE sp_ia_potencial_proxima_solicitacao()
SQL SECURITY DEFINER
BEGIN
    DECLARE v_id BIGINT DEFAULT NULL;
    START TRANSACTION;
    SELECT codsolicitacao INTO v_id
      FROM ml_potenciais_solicitacoes
     WHERE status = 'PENDENTE'
     ORDER BY solicitada_em, codsolicitacao
     LIMIT 1 FOR UPDATE;
    IF v_id IS NOT NULL THEN
        UPDATE ml_potenciais_solicitacoes
           SET status = 'PROCESSANDO', iniciada_em = NOW(),
               processador = COALESCE(@ia_processador, 'GreenPortalIA')
         WHERE codsolicitacao = v_id;
    END IF;
    COMMIT;
    SELECT codsolicitacao, codusuario, idioma, contexto_json, status,
           solicitada_em, iniciada_em, processador
      FROM ml_potenciais_solicitacoes
     WHERE codsolicitacao = v_id;
END$$

DROP PROCEDURE IF EXISTS sp_ia_potencial_concluir;
CREATE PROCEDURE sp_ia_potencial_concluir(
    IN p_codsolicitacao BIGINT,
    IN p_status VARCHAR(20),
    IN p_potenciais_json JSON,
    IN p_erro TEXT
)
SQL SECURITY DEFINER
BEGIN
    IF p_status = 'CONCLUIDA' THEN
        INSERT INTO ml_potenciais (
            codsolicitacao, codrequisitolegal, tipo_potencial, titulo,
            descricao, justificativa_ia, confianca_ia, idioma_conteudo
        )
        SELECT p_codsolicitacao, x.codrequisitolegal, x.tipo_potencial,
               x.titulo, x.descricao, x.justificativa_ia, x.confianca_ia,
               IF(x.idioma_conteudo IN ('pt','en','es'), x.idioma_conteudo, 'pt')
          FROM JSON_TABLE(p_potenciais_json, '$.potenciais[*]'
            COLUMNS (
              codrequisitolegal BIGINT PATH '$.codrequisitolegal' NULL ON ERROR,
              tipo_potencial VARCHAR(30) PATH '$.tipo_potencial',
              titulo TEXT PATH '$.titulo',
              descricao TEXT PATH '$.descricao' NULL ON ERROR,
              justificativa_ia TEXT PATH '$.justificativa_ia' NULL ON ERROR,
              confianca_ia DECIMAL(5,2) PATH '$.confianca_ia' NULL ON ERROR,
              idioma_conteudo CHAR(2) PATH '$.idioma_conteudo' NULL ON ERROR
            )
          ) AS x;
    END IF;
    UPDATE ml_potenciais_solicitacoes
       SET status = p_status, concluida_em = NOW(), erro = NULLIF(p_erro, '')
     WHERE codsolicitacao = p_codsolicitacao;
    SELECT codsolicitacao, status, concluida_em, erro
      FROM ml_potenciais_solicitacoes
     WHERE codsolicitacao = p_codsolicitacao;
END$$
DELIMITER ;
