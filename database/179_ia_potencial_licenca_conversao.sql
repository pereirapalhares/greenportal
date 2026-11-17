/* Converte automaticamente potenciais aprovados em registros vinculados ao requisito e unidade. */
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS sp_ia_potencial_validar;
DELIMITER $$
CREATE PROCEDURE sp_ia_potencial_validar(
    IN p_codpotencial BIGINT,
    IN p_codusuario BIGINT,
    IN p_status VARCHAR(20)
)
SQL SECURITY DEFINER
BEGIN
    DECLARE v_tipo VARCHAR(30) DEFAULT '';
    DECLARE v_status VARCHAR(20) DEFAULT '';
    DECLARE v_codsolicitacao BIGINT DEFAULT 0;
    DECLARE v_codusuario_origem BIGINT DEFAULT 0;
    DECLARE v_codlegislacao BIGINT DEFAULT 0;
    DECLARE v_codunidade BIGINT DEFAULT 0;
    DECLARE v_titulo TEXT;
    DECLARE v_descricao TEXT;
    DECLARE v_justificativa TEXT;
    DECLARE v_codlicenca BIGINT DEFAULT 0;
    DECLARE v_codmonitoramento BIGINT DEFAULT 0;
    DECLARE v_codtreinamento BIGINT DEFAULT 0;
    DECLARE v_codinfraestrutura BIGINT DEFAULT 0;
    DECLARE v_coddocumento BIGINT DEFAULT 0;
    DECLARE v_codregistro BIGINT DEFAULT NULL;
    DECLARE v_contexto JSON;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_status NOT IN ('APROVADO','REJEITADO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Status de validacao nao permitido.';
    END IF;

    SELECT p.codsolicitacao, p.tipo_potencial, p.status, p.titulo, p.descricao,
           p.justificativa_ia, p.codregistro_destino, s.codusuario, s.contexto_json
      INTO v_codsolicitacao, v_tipo, v_status, v_titulo, v_descricao,
           v_justificativa, v_codregistro, v_codusuario_origem, v_contexto
      FROM ml_potenciais p
      INNER JOIN ml_potenciais_solicitacoes s ON s.codsolicitacao = p.codsolicitacao
     WHERE p.codpotencial = p_codpotencial
     LIMIT 1;

    IF v_status <> 'SUGERIDO' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O potencial nao esta disponivel para validacao.';
    END IF;

    START TRANSACTION;
    UPDATE ml_potenciais
       SET status = p_status, validado_em = NOW(), validado_por = p_codusuario
     WHERE codpotencial = p_codpotencial AND status = 'SUGERIDO';
    IF ROW_COUNT() = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O potencial nao esta disponivel para validacao.';
    END IF;

    IF p_status = 'APROVADO' AND UPPER(v_tipo) = 'LICENCA' AND COALESCE(v_codregistro, 0) = 0 THEN
        SET v_codlegislacao = CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v_contexto, '$.CODLEGISLACAO')), '0') AS UNSIGNED);
        SET v_codunidade = CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v_contexto, '$.CODUNIDADE')), '0') AS UNSIGNED);
        IF v_codlegislacao = 0 OR v_codunidade = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nao foi possivel converter a licenca: requisito legal ou unidade nao identificados.';
        END IF;

        INSERT INTO li_licencas (DSCLICENCA, codunidade, codusuario, observacao)
        VALUES (LEFT(COALESCE(v_titulo, 'Licenca aprovada pela IA'), 5000), v_codunidade,
                NULLIF(v_codusuario_origem, 0),
                LEFT(CONCAT_WS('\n\n', NULLIF(v_descricao, ''), NULLIF(v_justificativa, '')), 500));
        SET v_codlicenca = LAST_INSERT_ID();

        INSERT INTO legislacaoxlicencas (CODLEGISLACAO, CODLICENCA, codunidade)
        VALUES (v_codlegislacao, v_codlicenca, v_codunidade);

        UPDATE ml_potenciais
           SET codregistro_destino = v_codlicenca
         WHERE codpotencial = p_codpotencial;
    ELSEIF p_status = 'APROVADO' AND UPPER(v_tipo) = 'MONITORAMENTO' AND COALESCE(v_codregistro, 0) = 0 THEN
        SET v_codlegislacao = CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v_contexto, '$.CODLEGISLACAO')), '0') AS UNSIGNED);
        SET v_codunidade = CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v_contexto, '$.CODUNIDADE')), '0') AS UNSIGNED);
        IF v_codlegislacao = 0 OR v_codunidade = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nao foi possivel converter o monitoramento: requisito legal ou unidade nao identificados.'; END IF;
        INSERT INTO mm_monitoramento (PARAMETROS, codunidade, CODUSUARIO, OBS) VALUES (LEFT(COALESCE(v_titulo, 'Monitoramento aprovado pela IA'), 500), v_codunidade, NULLIF(v_codusuario_origem, 0), LEFT(CONCAT_WS('\n\n', NULLIF(v_descricao, ''), NULLIF(v_justificativa, '')), 2000));
        SET v_codmonitoramento = LAST_INSERT_ID();
        INSERT INTO mm_monitoramentoxlegislacao (CODMONITORAMENTO, CODLEGISLACAO) VALUES (v_codmonitoramento, v_codlegislacao);
        UPDATE ml_potenciais SET codregistro_destino = v_codmonitoramento WHERE codpotencial = p_codpotencial;
    ELSEIF p_status = 'APROVADO' AND UPPER(v_tipo) = 'TREINAMENTO' AND COALESCE(v_codregistro, 0) = 0 THEN
        SET v_codlegislacao = CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v_contexto, '$.CODLEGISLACAO')), '0') AS UNSIGNED);
        SET v_codunidade = CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v_contexto, '$.CODUNIDADE')), '0') AS UNSIGNED);
        IF v_codlegislacao = 0 OR v_codunidade = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nao foi possivel converter o treinamento: requisito legal ou unidade nao identificados.'; END IF;
        INSERT INTO ml_treinamentos (dsctreinamento) VALUES (LEFT(COALESCE(v_titulo, 'Treinamento aprovado pela IA'), 300));
        SET v_codtreinamento = LAST_INSERT_ID();
        INSERT INTO legislacaoxtreinamentos (CODLEGISLACAO, CODUNIDADE, CODTREINAMENTO) VALUES (v_codlegislacao, v_codunidade, v_codtreinamento);
        UPDATE ml_potenciais SET codregistro_destino = v_codtreinamento WHERE codpotencial = p_codpotencial;
    ELSEIF p_status = 'APROVADO' AND UPPER(v_tipo) = 'INFRAESTRUTURA' AND COALESCE(v_codregistro, 0) = 0 THEN
        SET v_codlegislacao = CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v_contexto, '$.CODLEGISLACAO')), '0') AS UNSIGNED);
        SET v_codunidade = CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v_contexto, '$.CODUNIDADE')), '0') AS UNSIGNED);
        IF v_codlegislacao = 0 OR v_codunidade = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nao foi possivel converter a infraestrutura: requisito legal ou unidade nao identificados.'; END IF;
        INSERT INTO ml_infraestrutura (dscinfraestrutura) VALUES (LEFT(COALESCE(v_titulo, 'Infraestrutura aprovada pela IA'), 300));
        SET v_codinfraestrutura = LAST_INSERT_ID();
        INSERT INTO legislacaoxinfraestruturas (CODLEGISLACAO, CODUNIDADE, CODINFRAESTRUTURA) VALUES (v_codlegislacao, v_codunidade, v_codinfraestrutura);
        UPDATE ml_potenciais SET codregistro_destino = v_codinfraestrutura WHERE codpotencial = p_codpotencial;
    ELSEIF p_status = 'APROVADO' AND UPPER(v_tipo) = 'CONTROLE' AND COALESCE(v_codregistro, 0) = 0 THEN
        SET v_codlegislacao = CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v_contexto, '$.CODLEGISLACAO')), '0') AS UNSIGNED);
        SET v_codunidade = CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v_contexto, '$.CODUNIDADE')), '0') AS UNSIGNED);
        IF v_codlegislacao = 0 OR v_codunidade = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nao foi possivel converter o documento: requisito legal ou unidade nao identificados.'; END IF;
        INSERT INTO documentos (CODIGO, TITULO, ARQUIVO) VALUES (CONCAT('IA-', p_codpotencial), LEFT(COALESCE(v_titulo, 'Documento aprovado pela IA'), 500), '');
        SET v_coddocumento = LAST_INSERT_ID();
        INSERT INTO legislacaoxdocumentos (CODDOCUMENTO, CODLEGISLACAO, CODUNIDADE) VALUES (v_coddocumento, v_codlegislacao, v_codunidade);
        UPDATE ml_potenciais SET codregistro_destino = v_coddocumento WHERE codpotencial = p_codpotencial;
    END IF;

    COMMIT;
    SELECT codpotencial, status, validado_em, validado_por, codregistro_destino
      FROM ml_potenciais WHERE codpotencial = p_codpotencial;
END$$
DELIMITER ;

/* Aplicar este arquivo em cada banco tenant que utiliza a fila de potenciais IA. */

/* Retroconversao segura dos potenciais ja aprovados antes desta migration. */
DROP PROCEDURE IF EXISTS sp_ia_potencial_retroconverter_destinos;
DELIMITER $$
CREATE PROCEDURE sp_ia_potencial_retroconverter_destinos()
SQL SECURITY DEFINER
BEGIN
    DECLARE v_fim INT DEFAULT 0;
    DECLARE v_potencial BIGINT;
    DECLARE v_titulo TEXT;
    DECLARE v_descricao TEXT;
    DECLARE v_justificativa TEXT;
    DECLARE v_usuario BIGINT;
    DECLARE v_contexto JSON;
    DECLARE v_legislacao BIGINT;
    DECLARE v_unidade BIGINT;
    DECLARE v_licenca BIGINT;
    DECLARE v_destino BIGINT;
    DECLARE v_tipo VARCHAR(30);
    DECLARE c CURSOR FOR
        SELECT p.codpotencial,p.titulo,p.descricao,p.justificativa_ia,s.codusuario,s.contexto_json
          FROM ml_potenciais p INNER JOIN ml_potenciais_solicitacoes s ON s.codsolicitacao=p.codsolicitacao
         WHERE p.status='APROVADO' AND UPPER(p.tipo_potencial) IN ('LICENCA','MONITORAMENTO','TREINAMENTO','INFRAESTRUTURA','CONTROLE') AND COALESCE(p.codregistro_destino,0)=0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fim=1;
    OPEN c;
    loop_converter: LOOP
        FETCH c INTO v_potencial,v_titulo,v_descricao,v_justificativa,v_usuario,v_contexto;
        IF v_fim=1 THEN LEAVE loop_converter; END IF;
        SELECT UPPER(tipo_potencial) INTO v_tipo FROM ml_potenciais WHERE codpotencial=v_potencial;
        SET v_legislacao=CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v_contexto,'$.CODLEGISLACAO')),'0') AS UNSIGNED);
        SET v_unidade=CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v_contexto,'$.CODUNIDADE')),'0') AS UNSIGNED);
        IF v_legislacao>0 AND v_unidade>0 THEN
            IF v_tipo='LICENCA' THEN
                INSERT INTO li_licencas(DSCLICENCA,codunidade,codusuario,observacao) VALUES(LEFT(COALESCE(v_titulo,'Licenca aprovada pela IA'),5000),v_unidade,NULLIF(v_usuario,0),LEFT(CONCAT_WS('\n\n',NULLIF(v_descricao,''),NULLIF(v_justificativa,'')),500));
                SET v_destino=LAST_INSERT_ID();
                INSERT INTO legislacaoxlicencas(CODLEGISLACAO,CODLICENCA,codunidade) VALUES(v_legislacao,v_destino,v_unidade);
            ELSEIF v_tipo='MONITORAMENTO' THEN
                INSERT INTO mm_monitoramento(PARAMETROS,codunidade,CODUSUARIO,OBS) VALUES(LEFT(COALESCE(v_titulo,'Monitoramento aprovado pela IA'),500),v_unidade,NULLIF(v_usuario,0),LEFT(CONCAT_WS('\n\n',NULLIF(v_descricao,''),NULLIF(v_justificativa,'')),2000));
                SET v_destino=LAST_INSERT_ID();
                INSERT INTO mm_monitoramentoxlegislacao(CODMONITORAMENTO,CODLEGISLACAO) VALUES(v_destino,v_legislacao);
            ELSEIF v_tipo='TREINAMENTO' THEN
                INSERT INTO ml_treinamentos(dsctreinamento) VALUES(LEFT(COALESCE(v_titulo,'Treinamento aprovado pela IA'),300));
                SET v_destino=LAST_INSERT_ID();
                INSERT INTO legislacaoxtreinamentos(CODLEGISLACAO,CODUNIDADE,CODTREINAMENTO) VALUES(v_legislacao,v_unidade,v_destino);
            ELSEIF v_tipo='INFRAESTRUTURA' THEN
                INSERT INTO ml_infraestrutura(dscinfraestrutura) VALUES(LEFT(COALESCE(v_titulo,'Infraestrutura aprovada pela IA'),300));
                SET v_destino=LAST_INSERT_ID();
                INSERT INTO legislacaoxinfraestruturas(CODLEGISLACAO,CODUNIDADE,CODINFRAESTRUTURA) VALUES(v_legislacao,v_unidade,v_destino);
            ELSEIF v_tipo='CONTROLE' THEN
                INSERT INTO documentos(CODIGO,TITULO,ARQUIVO) VALUES(CONCAT('IA-',v_potencial),LEFT(COALESCE(v_titulo,'Documento aprovado pela IA'),500),'');
                SET v_destino=LAST_INSERT_ID();
                INSERT INTO legislacaoxdocumentos(CODDOCUMENTO,CODLEGISLACAO,CODUNIDADE) VALUES(v_destino,v_legislacao,v_unidade);
            END IF;
            UPDATE ml_potenciais SET codregistro_destino=v_destino WHERE codpotencial=v_potencial AND COALESCE(codregistro_destino,0)=0;
        END IF;
    END LOOP;
    CLOSE c;
END$$
DELIMITER ;
CALL sp_ia_potencial_retroconverter_destinos();
DROP PROCEDURE IF EXISTS sp_ia_potencial_retroconverter_destinos;
