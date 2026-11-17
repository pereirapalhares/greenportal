-- Migração 314 — Homologação de Fornecedores: Relatórios de Homologação (relatórios gerenciais, estatísticas e relatório
-- unificado de funcionários numa tela só: principal/homologacaofornecedores_relatorios.php, menu HOMOLOGACAO_RELATORIOS_GERENCIAIS).
-- Aplicado por scripts/aplicar-migracao-314-relatorios-homologacao.php em cada banco de cliente. Comandos separados por "-- @@".
-- Depende da migração 298 (vw_homologacao_painel_itens = regra única de cores; vw_homologacao_fornecedores_do_usuario).
-- Pode ser reaplicado.
--
--  sp_fo_relatorio_itens       itens de UMA categoria do Painel (mesmos filtros, cores e escopo do fornecedor externo)
--                              + as colunas do relatório do legado (tipo, nível, protocolo, nota, setor, CAS, recursos...).
--  sp_fo_relatorio_contagens   itens por categoria e cor (números do catálogo de relatórios).
--  sp_fo_relatorio_unificado   relatório unificado de funcionários: funcionários, itens exigidos pelo tipo e situação de cada item.
--  fo_relatorios_visoes        "Meus relatórios" (visões salvas por usuário) com envio semanal por alerta
--                              (scripts/homologacao-relatorios-worker.php, Agendador de Tarefas do Windows).

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_relatorio_itens`

-- @@
-- p_categoria: DOCUMENTO | AUDITORIA | NIVEL | INTEGRACAO | DOCFUNC | TREINAMENTO | EQUIPAMENTO | QUIMICO | PLANO.
-- Filtros iguais aos do Painel (sp_fo_painel_itens): externo, unidade, tipo de fornecedor, busca (fornecedor, fantasia, CNPJ).
-- p_externo: 0 = vê tudo, 1 = fornecedor externo (296), 2 = interno restrito por unidade (migração 316, tem de estar aplicada).
CREATE PROCEDURE `sp_fo_relatorio_itens`(IN p_usuario BIGINT, IN p_externo TINYINT, IN p_unidade BIGINT, IN p_tipo BIGINT,
                                         IN p_busca VARCHAR(200), IN p_categoria VARCHAR(20))
SQL SECURITY INVOKER
BEGIN
    DECLARE v_busca VARCHAR(200) DEFAULT TRIM(COALESCE(p_busca, ''));
    DECLARE v_digitos VARCHAR(200) DEFAULT REGEXP_REPLACE(TRIM(COALESCE(p_busca, '')), '[^0-9]', '');
    DROP TEMPORARY TABLE IF EXISTS tmp_fo_relatorio;
    CREATE TEMPORARY TABLE tmp_fo_relatorio
    SELECT i.CATEGORIA, i.CODREGISTRO, i.CODFORNECEDOR, i.CODFUNCIONARIO, i.ITEM, i.SITUACAO, i.VALIDADE, i.QTDDIAS, i.REACAO, i.COR,
           i.CODUSUARIO_EMPRESA, i.CODUSUARIO_FORNECEDOR, i.CODUNIDADE, i.DSCUNIDADE, i.RAZAOSOCIAL, i.NOMEFANTASIA, i.CNPJ_CPF,
           i.RESPONSAVEL_EMPRESA, i.RESPONSAVEL_FORNECEDOR
      FROM `vw_homologacao_painel_itens` i
     WHERE i.CATEGORIA = p_categoria
       AND (COALESCE(p_externo, 0) = 0
            OR (p_externo = 1 AND EXISTS (SELECT 1 FROM `vw_homologacao_fornecedores_do_usuario` a WHERE a.CODUSUARIO = p_usuario AND a.CODFORNECEDOR = i.CODFORNECEDOR))
            OR (p_externo = 2 AND EXISTS (SELECT 1 FROM `vw_homologacao_fornecedores_acesso_interno` a WHERE a.CODUSUARIO = p_usuario AND a.CODFORNECEDOR = i.CODFORNECEDOR)))
       AND (p_unidade IS NULL OR i.CODUNIDADE = p_unidade)
       AND (p_tipo IS NULL OR EXISTS (SELECT 1 FROM `fo_fornecedoresxtipofornecedores` ft WHERE ft.codfornecedor = i.CODFORNECEDOR AND ft.codtipofornecedor = p_tipo))
       AND (v_busca = '' OR i.RAZAOSOCIAL LIKE CONCAT('%', v_busca, '%') OR i.NOMEFANTASIA LIKE CONCAT('%', v_busca, '%')
            OR (CHAR_LENGTH(v_digitos) >= 3 AND REGEXP_REPLACE(i.CNPJ_CPF, '[^0-9]', '') LIKE CONCAT('%', v_digitos, '%')));

    IF p_categoria = 'DOCUMENTO' THEN
        SELECT r.*, t.DSCTIPO AS TIPO, c.DSCRITERIO AS DOCUMENTO,
               (SELECT MAX(tc.nivel_de_exigencia) FROM `fo_tipofornecedoresxcriteriofornecedores` tc WHERE tc.codtipofornecedor = x.codtipo AND tc.codcriteriofornecedor = x.codcriterio) AS NIVEL,
               x.enviadoodocumento AS ENVIADO, x.emprotocolo AS PROTOCOLO, x.datacobranca_emprotocolo AS COBRANCA_PROTOCOLO, x.incluidocontrato AS CONTRATO,
               x.observacao AS OBSERVACAO, x.observacao_do_fornecedor AS OBSERVACAO_FORNECEDOR,
               -- Migração 318: responsável pelo fornecedor (cadastro), como o Excel "Fornecedores + Critérios" do legado.
               (SELECT ur.nome FROM `fo_fornecedores` ff JOIN `usuarios` ur ON ur.codusuario = ff.CODUSUARIO_RESPONSAVEL_FORNECEDOR WHERE ff.codfornecedor = r.CODFORNECEDOR LIMIT 1) AS RESPONSAVEL_CADASTRO
          FROM tmp_fo_relatorio r
          LEFT JOIN `fo_fornecedorxcriterios` x ON x.codfornecedorxcriterios = r.CODREGISTRO
          LEFT JOIN `fo_tipofornecedores` t ON t.codtipofornecedor = x.codtipo
          LEFT JOIN `fo_criteriofornecedores` c ON c.codcriteriofornecedor = x.codcriterio;
    ELSEIF p_categoria = 'AUDITORIA' THEN
        SELECT r.*, t.DSCTIPO AS TIPO, x.nota AS NOTA, x.fornecedoraprovadoauditoria AS APROVADO
          FROM tmp_fo_relatorio r
          LEFT JOIN `fo_fornecedoresxtipofornecedores` x ON x.codfornecedorxtipofornecedores = r.CODREGISTRO
          LEFT JOIN `fo_tipofornecedores` t ON t.codtipofornecedor = x.codtipofornecedor;
    ELSEIF p_categoria = 'NIVEL' THEN
        SELECT r.*, f.nivel_de_servicao AS NIVEL_SERVICO,
               IF(f.data_da_avaliacao IS NULL OR f.data_da_avaliacao < '1900-01-01', NULL, f.data_da_avaliacao) AS ULTIMA_AVALIACAO
          FROM tmp_fo_relatorio r
          LEFT JOIN `fo_fornecedores` f ON f.codfornecedor = r.CODREGISTRO;
    ELSEIF p_categoria = 'INTEGRACAO' THEN
        SELECT r.*, fu.NOME AS FUNCIONARIO, fu.NRIDENTIFICACAO AS NRREGISTRO, fu.setor AS SETOR, tf.DSCTIPO AS TIPO_FUNCIONARIO,
               IF(fu.data_de_inicio IS NULL OR fu.data_de_inicio < '1900-01-01', NULL, fu.data_de_inicio) AS DATA_INICIO, fu.INTEGRACAO AS INTEGRACAO
          FROM tmp_fo_relatorio r
          LEFT JOIN `fo_funcionarios` fu ON fu.CODFUNCIONARIO = r.CODREGISTRO
          LEFT JOIN `fo_tipofuncionarios` tf ON tf.codtipofuncionario = fu.CODTIPOFUNCIONARIO;
    ELSEIF p_categoria = 'DOCFUNC' THEN
        SELECT r.*, fu.NOME AS FUNCIONARIO, fu.NRIDENTIFICACAO AS NRREGISTRO, fu.setor AS SETOR, tf.DSCTIPO AS TIPO_FUNCIONARIO,
               cf.DSCRITERIO AS DOCUMENTO, cf.tipo_de_documento AS TIPO_DOCUMENTO, x.atendeaocriterio AS ATENDE, x.observacao AS OBSERVACAO
          FROM tmp_fo_relatorio r
          LEFT JOIN `fo_funcionarioxtiposxcriterios` x ON x.codigo = r.CODREGISTRO
          LEFT JOIN `fo_funcionarios` fu ON fu.CODFUNCIONARIO = x.codfuncionario
          LEFT JOIN `fo_tipofuncionarios` tf ON tf.codtipofuncionario = COALESCE(NULLIF(x.codtipofuncionario, 0), fu.CODTIPOFUNCIONARIO)
          LEFT JOIN `fo_criteriofuncionarios` cf ON cf.codcriteriofuncionario = x.codcriteriofuncionario;
    ELSEIF p_categoria = 'TREINAMENTO' THEN
        SELECT r.*, fu.NOME AS FUNCIONARIO, fu.NRIDENTIFICACAO AS NRREGISTRO, fu.setor AS SETOR, tf.DSCTIPO AS TIPO_FUNCIONARIO,
               tt.DSCTIPO AS DOCUMENTO, x.treinamentorealizado AS ATENDE
          FROM tmp_fo_relatorio r
          LEFT JOIN `fo_funcionarioxtiposxtreinamentos` x ON x.codigo = r.CODREGISTRO
          LEFT JOIN `fo_funcionarios` fu ON fu.CODFUNCIONARIO = x.codfuncionario
          LEFT JOIN `fo_tipofuncionarios` tf ON tf.codtipofuncionario = COALESCE(NULLIF(x.codtipofuncionario, 0), fu.CODTIPOFUNCIONARIO)
          LEFT JOIN `fo_tipotreinamentos` tt ON tt.codtipotreinamento = x.codtipotreinamento;
    ELSEIF p_categoria = 'EQUIPAMENTO' THEN
        SELECT r.*, q.DSCEQUIPAMENTO AS DOCUMENTO, q.NRIDENTIFICACAO AS IDENTIFICACAO, q.APROVADO AS APROVADO
          FROM tmp_fo_relatorio r
          LEFT JOIN `fo_equipamentos` q ON q.codequipamento = r.CODREGISTRO;
    ELSEIF p_categoria = 'QUIMICO' THEN
        SELECT r.*, q.DSCPRODUTO AS DOCUMENTO, q.NCAS AS CAS, q.PRODUTO_CONTROLADO AS CONTROLADO, q.CARACTERISTICA AS CARACTERISTICA, q.APROVADO AS APROVADO
          FROM tmp_fo_relatorio r
          LEFT JOIN `fo_produtoquimico` q ON q.codproduto = r.CODREGISTRO;
    ELSEIF p_categoria = 'PLANO' THEN
        SELECT r.*, q.acao AS DOCUMENTO, q.recursos AS RECURSOS, COALESCE(q.status, 0) AS ANDAMENTO,
               IF(q.datainicial IS NULL OR q.datainicial < '1900-01-01', NULL, q.datainicial) AS INICIO
          FROM tmp_fo_relatorio r
          LEFT JOIN `fo_planodeacao` q ON q.codplanoacao = r.CODREGISTRO;
    ELSE
        SELECT r.* FROM tmp_fo_relatorio r;
    END IF;
    DROP TEMPORARY TABLE IF EXISTS tmp_fo_relatorio;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_relatorio_contagens`

-- @@
-- Itens por categoria e cor, com os filtros do Painel (números do catálogo da tela Relatórios de Homologação).
CREATE PROCEDURE `sp_fo_relatorio_contagens`(IN p_usuario BIGINT, IN p_externo TINYINT, IN p_unidade BIGINT, IN p_tipo BIGINT, IN p_busca VARCHAR(200))
SQL SECURITY INVOKER
BEGIN
    DECLARE v_busca VARCHAR(200) DEFAULT TRIM(COALESCE(p_busca, ''));
    DECLARE v_digitos VARCHAR(200) DEFAULT REGEXP_REPLACE(TRIM(COALESCE(p_busca, '')), '[^0-9]', '');
    SELECT i.CATEGORIA, i.COR, COUNT(*) AS N
      FROM `vw_homologacao_painel_itens` i
     WHERE (COALESCE(p_externo, 0) = 0
            OR (p_externo = 1 AND EXISTS (SELECT 1 FROM `vw_homologacao_fornecedores_do_usuario` a WHERE a.CODUSUARIO = p_usuario AND a.CODFORNECEDOR = i.CODFORNECEDOR))
            OR (p_externo = 2 AND EXISTS (SELECT 1 FROM `vw_homologacao_fornecedores_acesso_interno` a WHERE a.CODUSUARIO = p_usuario AND a.CODFORNECEDOR = i.CODFORNECEDOR)))
       AND (p_unidade IS NULL OR i.CODUNIDADE = p_unidade)
       AND (p_tipo IS NULL OR EXISTS (SELECT 1 FROM `fo_fornecedoresxtipofornecedores` ft WHERE ft.codfornecedor = i.CODFORNECEDOR AND ft.codtipofornecedor = p_tipo))
       AND (v_busca = '' OR i.RAZAOSOCIAL LIKE CONCAT('%', v_busca, '%') OR i.NOMEFANTASIA LIKE CONCAT('%', v_busca, '%')
            OR (CHAR_LENGTH(v_digitos) >= 3 AND REGEXP_REPLACE(i.CNPJ_CPF, '[^0-9]', '') LIKE CONCAT('%', v_digitos, '%')))
     GROUP BY i.CATEGORIA, i.COR;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_relatorio_unificado`

-- @@
-- Relatório unificado de funcionários (ativos, de fornecedores ativos), 4 resultados:
--  1. funcionários do filtro (nº do registro, setor, data de início, tipo de funcionário = "função" do legado);
--  2. documentos exigidos por tipo de funcionário (cadastro Tipos de funcionários);
--  3. treinamentos exigidos por tipo de funcionário;
--  4. situação de cada item (integração, documentos e treinamentos) pela regra única de cores (vw_homologacao_painel_itens).
-- p_busca: fornecedor, fantasia, CNPJ, nome do funcionário ou nº do registro. p_tipofuncionario: um tipo ou NULL.
CREATE PROCEDURE `sp_fo_relatorio_unificado`(IN p_usuario BIGINT, IN p_externo TINYINT, IN p_unidade BIGINT, IN p_tipo BIGINT,
                                             IN p_busca VARCHAR(200), IN p_tipofuncionario BIGINT)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_busca VARCHAR(200) DEFAULT TRIM(COALESCE(p_busca, ''));
    DECLARE v_digitos VARCHAR(200) DEFAULT REGEXP_REPLACE(TRIM(COALESCE(p_busca, '')), '[^0-9]', '');
    DROP TEMPORARY TABLE IF EXISTS tmp_fo_unificado;
    CREATE TEMPORARY TABLE tmp_fo_unificado (PRIMARY KEY (CODFUNCIONARIO))
    SELECT fu.CODFUNCIONARIO,
           CONVERT(COALESCE(fu.NOME, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS NOME,
           CONVERT(COALESCE(fu.NRIDENTIFICACAO, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS NRREGISTRO,
           CONVERT(COALESCE(fu.setor, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS SETOR,
           IF(fu.data_de_inicio IS NULL OR fu.data_de_inicio < '1900-01-01', NULL, fu.data_de_inicio) AS DATA_INICIO,
           COALESCE(fu.CODTIPOFUNCIONARIO, 0) AS CODTIPOFUNCIONARIO,
           CONVERT(COALESCE(tf.DSCTIPO, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS TIPO_FUNCIONARIO,
           f.codfornecedor AS CODFORNECEDOR,
           CONVERT(COALESCE(NULLIF(TRIM(f.razaosocial), ''), CONCAT('Fornecedor ', f.codfornecedor)) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS RAZAOSOCIAL,
           CONVERT(COALESCE(f.cnpj_cpf, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS CNPJ_CPF,
           f.codunidade AS CODUNIDADE,
           CONVERT(COALESCE(un.DSCUNIDADE, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS DSCUNIDADE
      FROM `fo_funcionarios` fu
      JOIN `fo_fornecedores` f ON f.codfornecedor = fu.CODFORNECEDOR
      LEFT JOIN `fo_tipofuncionarios` tf ON tf.codtipofuncionario = fu.CODTIPOFUNCIONARIO
      LEFT JOIN `unidades` un ON un.codunidade = f.codunidade
     WHERE UPPER(TRIM(COALESCE(fu.ativo, 'S'))) NOT IN ('N', 'NAO', 'NÃO', '0')
       AND LOWER(TRIM(COALESCE(f.ativadofor, ''))) IN ('sim', 's', '1')
       AND (COALESCE(p_externo, 0) = 0
            OR (p_externo = 1 AND EXISTS (SELECT 1 FROM `vw_homologacao_fornecedores_do_usuario` a WHERE a.CODUSUARIO = p_usuario AND a.CODFORNECEDOR = f.codfornecedor))
            OR (p_externo = 2 AND EXISTS (SELECT 1 FROM `vw_homologacao_fornecedores_acesso_interno` a WHERE a.CODUSUARIO = p_usuario AND a.CODFORNECEDOR = f.codfornecedor)))
       AND (p_unidade IS NULL OR f.codunidade = p_unidade)
       AND (p_tipo IS NULL OR EXISTS (SELECT 1 FROM `fo_fornecedoresxtipofornecedores` ft WHERE ft.codfornecedor = f.codfornecedor AND ft.codtipofornecedor = p_tipo))
       AND (p_tipofuncionario IS NULL OR fu.CODTIPOFUNCIONARIO = p_tipofuncionario)
       AND (v_busca = '' OR f.razaosocial LIKE CONCAT('%', v_busca, '%') OR f.nomefantasia LIKE CONCAT('%', v_busca, '%')
            OR fu.NOME LIKE CONCAT('%', v_busca, '%') OR fu.NRIDENTIFICACAO LIKE CONCAT('%', v_busca, '%')
            OR (CHAR_LENGTH(v_digitos) >= 3 AND REGEXP_REPLACE(COALESCE(f.cnpj_cpf, ''), '[^0-9]', '') LIKE CONCAT('%', v_digitos, '%')));

    SELECT * FROM tmp_fo_unificado ORDER BY RAZAOSOCIAL, NOME;

    SELECT tc.codtipofuncionario AS CODTIPOFUNCIONARIO, tc.codcriteriofuncionario AS CODITEM,
           CONVERT(COALESCE(cf.DSCRITERIO, CONCAT('Documento ', tc.codcriteriofuncionario)) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS DSCITEM
      FROM `fo_tipofuncionariosxcriteriofuncionarios` tc
      JOIN `fo_criteriofuncionarios` cf ON cf.codcriteriofuncionario = tc.codcriteriofuncionario
     WHERE tc.codtipofuncionario IN (SELECT DISTINCT CODTIPOFUNCIONARIO FROM tmp_fo_unificado);

    SELECT tt.codtipofuncionario AS CODTIPOFUNCIONARIO, tt.codtipotreinamento AS CODITEM,
           CONVERT(COALESCE(t.DSCTIPO, CONCAT('Treinamento ', tt.codtipotreinamento)) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS DSCITEM
      FROM `fo_tipofuncionariosxtipotreinamentos` tt
      JOIN `fo_tipotreinamentos` t ON t.codtipotreinamento = tt.codtipotreinamento
     WHERE tt.codtipofuncionario IN (SELECT DISTINCT CODTIPOFUNCIONARIO FROM tmp_fo_unificado);

    SELECT i.CODFUNCIONARIO, i.CATEGORIA, i.CODREGISTRO,
           CASE i.CATEGORIA WHEN 'DOCFUNC' THEN xc.codcriteriofuncionario WHEN 'TREINAMENTO' THEN xt.codtipotreinamento ELSE 0 END AS CODITEM,
           CONVERT(CASE i.CATEGORIA WHEN 'DOCFUNC' THEN COALESCE(cf.DSCRITERIO, '') WHEN 'TREINAMENTO' THEN COALESCE(tt.DSCTIPO, '') ELSE '' END USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS DSCITEM,
           i.SITUACAO, i.VALIDADE, i.QTDDIAS, i.COR
      FROM `vw_homologacao_painel_itens` i
      LEFT JOIN `fo_funcionarioxtiposxcriterios` xc ON i.CATEGORIA = 'DOCFUNC' AND xc.codigo = i.CODREGISTRO
      LEFT JOIN `fo_criteriofuncionarios` cf ON cf.codcriteriofuncionario = xc.codcriteriofuncionario
      LEFT JOIN `fo_funcionarioxtiposxtreinamentos` xt ON i.CATEGORIA = 'TREINAMENTO' AND xt.codigo = i.CODREGISTRO
      LEFT JOIN `fo_tipotreinamentos` tt ON tt.codtipotreinamento = xt.codtipotreinamento
     WHERE i.CATEGORIA IN ('INTEGRACAO', 'DOCFUNC', 'TREINAMENTO')
       AND i.CODFUNCIONARIO IN (SELECT CODFUNCIONARIO FROM tmp_fo_unificado);

    DROP TEMPORARY TABLE IF EXISTS tmp_fo_unificado;
END

-- @@
-- "Meus relatórios": visões salvas da tela Relatórios de Homologação (relatório, filtros, filtro rápido, colunas, agrupamento,
-- ordenação). envio_semanal = 1: o serviço semanal manda um alerta com o resumo da visão em Home › Alertas.
-- externo = escopo do dono quando salvou (fornecedor externo vê só os fornecedores ligados a ele).
CREATE TABLE IF NOT EXISTS `fo_relatorios_visoes` (
    `codvisao` BIGINT NOT NULL AUTO_INCREMENT,
    `codusuario` BIGINT NOT NULL,
    `nome` VARCHAR(120) NOT NULL,
    `relatorio` VARCHAR(30) NOT NULL,
    `parametros` JSON NULL,
    `externo` TINYINT(1) NOT NULL DEFAULT 0,
    `envio_semanal` TINYINT(1) NOT NULL DEFAULT 0,
    `ultimo_envio` CHAR(7) NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`codvisao`),
    KEY `ix_fo_relatorios_visoes_usuario` (`codusuario`),
    KEY `ix_fo_relatorios_visoes_envio` (`envio_semanal`, `ultimo_envio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci

-- @@
CREATE OR REPLACE VIEW `vw_fo_relatorios_visoes` AS
SELECT v.codvisao AS CODVISAO, v.codusuario AS CODUSUARIO, v.nome AS NOME, v.relatorio AS RELATORIO, v.parametros AS PARAMETROS,
       v.externo AS EXTERNO, v.envio_semanal AS ENVIO_SEMANAL, v.ultimo_envio AS ULTIMO_ENVIO, v.criado_em AS CRIADO_EM, v.atualizado_em AS ATUALIZADO_EM
  FROM `fo_relatorios_visoes` v
 ORDER BY v.codusuario, v.nome

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_relatorio_visao_salvar`

-- @@
-- Inclui (p_codvisao NULL/0) ou altera uma visão do próprio usuário. Devolve a visão gravada.
CREATE PROCEDURE `sp_fo_relatorio_visao_salvar`(IN p_usuario BIGINT, IN p_codvisao BIGINT, IN p_nome VARCHAR(120), IN p_relatorio VARCHAR(30),
                                                IN p_parametros JSON, IN p_externo TINYINT, IN p_envio TINYINT)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_cod BIGINT DEFAULT NULL;
    IF COALESCE(p_usuario, 0) < 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Usuário inválido.'; END IF;
    IF TRIM(COALESCE(p_nome, '')) = '' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o nome do relatório.'; END IF;
    IF COALESCE(p_relatorio, '') NOT REGEXP '^[a-z0-9_]{2,30}$' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Relatório inválido.'; END IF;
    IF COALESCE(p_codvisao, 0) > 0 THEN
        SELECT codvisao INTO v_cod FROM `fo_relatorios_visoes` WHERE codvisao = p_codvisao AND codusuario = p_usuario;
        IF v_cod IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Relatório salvo não encontrado.'; END IF;
        UPDATE `fo_relatorios_visoes` SET nome = TRIM(p_nome), relatorio = p_relatorio, parametros = p_parametros, externo = IF(p_externo = 1, 1, 0),
               envio_semanal = IF(p_envio = 1, 1, 0) WHERE codvisao = v_cod;
    ELSE
        IF (SELECT COUNT(*) FROM `fo_relatorios_visoes` WHERE codusuario = p_usuario) >= 50 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Limite de 50 relatórios salvos por usuário.';
        END IF;
        INSERT INTO `fo_relatorios_visoes` (codusuario, nome, relatorio, parametros, externo, envio_semanal)
             VALUES (p_usuario, TRIM(p_nome), p_relatorio, p_parametros, IF(p_externo = 1, 1, 0), IF(p_envio = 1, 1, 0));
        SET v_cod = LAST_INSERT_ID();
    END IF;
    SELECT codvisao AS CODVISAO, nome AS NOME, relatorio AS RELATORIO, envio_semanal AS ENVIO_SEMANAL FROM `fo_relatorios_visoes` WHERE codvisao = v_cod;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_relatorio_visao_excluir`

-- @@
CREATE PROCEDURE `sp_fo_relatorio_visao_excluir`(IN p_usuario BIGINT, IN p_codvisao BIGINT)
SQL SECURITY INVOKER
BEGIN
    DELETE FROM `fo_relatorios_visoes` WHERE codvisao = p_codvisao AND codusuario = p_usuario;
    SELECT ROW_COUNT() AS EXCLUIDOS;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_relatorio_visao_envio`

-- @@
-- Liga/desliga o envio semanal de uma visão do próprio usuário.
CREATE PROCEDURE `sp_fo_relatorio_visao_envio`(IN p_usuario BIGINT, IN p_codvisao BIGINT, IN p_envio TINYINT)
SQL SECURITY INVOKER
BEGIN
    UPDATE `fo_relatorios_visoes` SET envio_semanal = IF(p_envio = 1, 1, 0) WHERE codvisao = p_codvisao AND codusuario = p_usuario;
    SELECT codvisao AS CODVISAO, envio_semanal AS ENVIO_SEMANAL FROM `fo_relatorios_visoes` WHERE codvisao = p_codvisao AND codusuario = p_usuario;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_relatorio_visoes_semana`

-- @@
-- Serviço semanal (scripts/homologacao-relatorios-worker.php): visões com envio ligado ainda não enviadas na semana ISO,
-- de usuários ativos. p_codvisao > 0 marca essa visão como enviada na semana (não repete).
CREATE PROCEDURE `sp_fo_relatorio_visoes_semana`(IN p_semana CHAR(7), IN p_codvisao BIGINT)
SQL SECURITY INVOKER
BEGIN
    IF COALESCE(p_codvisao, 0) > 0 THEN
        UPDATE `fo_relatorios_visoes` SET ultimo_envio = p_semana WHERE codvisao = p_codvisao;
        SELECT ROW_COUNT() AS MARCADAS;
    ELSE
        SELECT v.codvisao AS CODVISAO, v.codusuario AS CODUSUARIO, v.nome AS NOME, v.relatorio AS RELATORIO, v.parametros AS PARAMETROS, v.externo AS EXTERNO
          FROM `fo_relatorios_visoes` v
          JOIN `usuarios` u ON u.codusuario = v.codusuario AND u.ativo = 1
         WHERE v.envio_semanal = 1 AND CAST(COALESCE(v.ultimo_envio, '') AS BINARY) <> CAST(COALESCE(p_semana, '') AS BINARY)
         ORDER BY v.codusuario, v.codvisao;
    END IF;
END
