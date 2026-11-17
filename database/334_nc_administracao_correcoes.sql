-- Migração 334 — Não Conformidades › Administração, fase 2 e 3 (22/10/2026, decisões do usuário):
--   Correção de ocorrência (substitui as 12 telas de correção do legado), Transferência de responsabilidades e Painel de saneamento.
-- Regras:
--   - até a virada, correção/transferência/atribuição valem só para as NCs do greenportal (código a partir de 50001, fn_nc_faixa);
--     as do sistema anterior aparecem só para consulta ("corrija no sistema anterior");
--   - motivo obrigatório; cada alteração vai para o histórico da NC (nc_workflow_historico, aba Histórico) com "antes → depois" e
--     para nc_admin_historico; quem passa a ser gestor/responsável é avisado em Home › Alertas;
--   - etapa não é texto livre: reabrir (volta à etapa anterior à finalização), devolver uma etapa, encerrar administrativamente;
--   - NCs antigas não são alteradas em lote (o painel não encerra NCs; só atribui gestor e lembra responsáveis, nas do greenportal).
-- Aplicada por scripts/aplicar-migracao-333-nc-administracao.php (junto com a 333).

DROP FUNCTION IF EXISTS `fn_nc_nome_usuario`
-- @@
CREATE FUNCTION `fn_nc_nome_usuario`(p INT) RETURNS VARCHAR(250) READS SQL DATA
RETURN IF(COALESCE(p, 0) = 0, '(ninguém)', COALESCE((SELECT CONVERT(COALESCE(NULLIF(TRIM(nome), ''), CONCAT('Usuário ', codusuario)) USING utf8mb4) FROM usuarios WHERE codusuario = p), CONCAT(p, ' (não cadastrado)')))
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_admin_validar`
-- @@
-- NC que a administração pode alterar: existe, é do greenportal e tem motivo
CREATE PROCEDURE `sp_nc_admin_validar`(IN p_codigo INT, IN p_motivo TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Não conformidade não encontrada.'; END IF;
  IF p_codigo < fn_nc_faixa('ocorrencia') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NC do sistema anterior: até a virada, corrija no sistema anterior (as alterações chegam pelo sincronismo).';
  END IF;
  IF CHAR_LENGTH(TRIM(COALESCE(p_motivo, ''))) < 5 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o motivo (obrigatório).'; END IF;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_admin_corrigir`
-- @@
-- Correção de ocorrência: p_dados = só os campos alterados ({"CODUSUARIO_GESTOR": "12", "IDENTIFICACAO": "...", ...});
-- p_acoes = [{"codplanoacao": N, "codusuario": M}] (responsáveis das ações). Campos do tipo (fornecedor, quantidades, cliente)
-- só quando o tipo da NC tem o campo (vw_nc_tipo_campos).
CREATE PROCEDURE `sp_nc_admin_corrigir`(IN p_codigo INT, IN p_dados JSON, IN p_acoes JSON, IN p_motivo TEXT, IN p_usuario INT)
BEGIN
  DECLARE v_tipo INT; DECLARE v_etapa VARCHAR(500);
  DECLARE v_emissor, v_gestor, v_resp, v_unidade, v_forn INT;
  DECLARE v_lista VARCHAR(100); DECLARE v_data DATETIME;
  DECLARE v_ident VARCHAR(500); DECLARE v_desc, v_prov, v_abr MEDIUMTEXT; DECLARE v_cliente VARCHAR(500); DECLARE v_qe, v_qd VARCHAR(100);
  DECLARE v_i, v_j, v_k, v_n INT; DECLARE v_t MEDIUMTEXT; DECLARE v_dt DATETIME; DECLARE v_msg VARCHAR(255); DECLARE v_resumo TEXT;
  DECLARE v_d JSON DEFAULT COALESCE(p_dados, JSON_OBJECT());
  DECLARE v_a JSON DEFAULT COALESCE(p_acoes, JSON_ARRAY());
  DECLARE v_fim INT DEFAULT 0; DECLARE v_dest INT; DECLARE v_tit VARCHAR(180); DECLARE v_aba VARCHAR(20);
  DECLARE c_avisos CURSOR FOR SELECT avisar, MIN(titulo), MIN(aba) FROM tmp_nc_corr WHERE avisar > 0 GROUP BY avisar;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fim = 1;
  CALL sp_nc_admin_validar(p_codigo, p_motivo);
  IF JSON_TYPE(v_d) <> 'OBJECT' OR JSON_TYPE(v_a) <> 'ARRAY' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dados inválidos.'; END IF;
  SELECT CODTIPONAOCONFORMIDADE, ETAPA, CODUSUARIO_EMISSOR, CODUSUARIO_GESTOR, CODUSUARIO_RESPONSAVEL, codunidade, CODFORNECEDOR, listadedistribuicao, DATA_OCORRENCIA,
         IDENTIFICACAO, DESCRICAO, PROVIDENCIA, ANALISE_ABRANGENCIA, DSCCLIENTE, QTDENVOLVIDA, QTDDEVOLVIDA
    INTO v_tipo, v_etapa, v_emissor, v_gestor, v_resp, v_unidade, v_forn, v_lista, v_data, v_ident, v_desc, v_prov, v_abr, v_cliente, v_qe, v_qd
    FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo FOR UPDATE;
  DROP TEMPORARY TABLE IF EXISTS tmp_nc_corr;
  CREATE TEMPORARY TABLE tmp_nc_corr (ordem INT AUTO_INCREMENT PRIMARY KEY, campo VARCHAR(80), antes TEXT, depois TEXT, avisar INT NULL, titulo VARCHAR(180) NULL, aba VARCHAR(20) NULL);

  -- Emitente, gestor, responsável (usuário ativo; responsável pode ficar vazio)
  IF fn_nc_tem(v_d, 'CODUSUARIO_EMISSOR') THEN
    SET v_i = CAST(fn_nc_txt(v_d, 'CODUSUARIO_EMISSOR') AS SIGNED);
    IF NOT (v_i <=> v_emissor) THEN
      IF v_i IS NULL OR NOT EXISTS (SELECT 1 FROM usuarios WHERE codusuario = v_i AND ativo = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Emitente: escolha um usuário ativo.'; END IF;
      UPDATE nc_indicacao_ocorrencia SET CODUSUARIO_EMISSOR = v_i WHERE CODOCORRENCIA = p_codigo;
      INSERT INTO tmp_nc_corr (campo, antes, depois, avisar, titulo, aba) VALUES ('Emitente', fn_nc_nome_usuario(v_emissor), fn_nc_nome_usuario(v_i), v_i, 'Você passou a ser o emitente desta não conformidade (correção da administração)', 'ocorrencia');
    END IF;
  END IF;
  IF fn_nc_tem(v_d, 'CODUSUARIO_GESTOR') THEN
    SET v_i = CAST(fn_nc_txt(v_d, 'CODUSUARIO_GESTOR') AS SIGNED);
    IF NOT (v_i <=> v_gestor) THEN
      IF v_i IS NULL OR NOT EXISTS (SELECT 1 FROM usuarios WHERE codusuario = v_i AND ativo = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gestor: escolha um usuário ativo.'; END IF;
      UPDATE nc_indicacao_ocorrencia SET CODUSUARIO_GESTOR = v_i WHERE CODOCORRENCIA = p_codigo;
      INSERT INTO tmp_nc_corr (campo, antes, depois, avisar, titulo, aba) VALUES ('Gestor', fn_nc_nome_usuario(v_gestor), fn_nc_nome_usuario(v_i), v_i, 'Você passou a ser o gestor desta não conformidade (correção da administração)', 'gestor');
    END IF;
  END IF;
  IF fn_nc_tem(v_d, 'CODUSUARIO_RESPONSAVEL') THEN
    SET v_i = CAST(fn_nc_txt(v_d, 'CODUSUARIO_RESPONSAVEL') AS SIGNED);
    IF NOT (v_i <=> v_resp) THEN
      IF v_i IS NOT NULL AND NOT EXISTS (SELECT 1 FROM usuarios WHERE codusuario = v_i AND ativo = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Responsável: escolha um usuário ativo.'; END IF;
      UPDATE nc_indicacao_ocorrencia SET CODUSUARIO_RESPONSAVEL = v_i WHERE CODOCORRENCIA = p_codigo;
      INSERT INTO tmp_nc_corr (campo, antes, depois, avisar, titulo, aba) VALUES ('Responsável', fn_nc_nome_usuario(v_resp), fn_nc_nome_usuario(v_i), v_i, 'Você passou a ser o responsável desta não conformidade (correção da administração)', 'analise');
    END IF;
  END IF;
  -- Data da ocorrência
  IF fn_nc_tem(v_d, 'DATA_OCORRENCIA') THEN
    SET v_t = fn_nc_txt(v_d, 'DATA_OCORRENCIA');
    SET v_dt = IF(v_t REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}', CAST(LEFT(v_t, 10) AS DATE), NULL);
    IF v_dt IS NULL OR v_dt > CURDATE() THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Data da ocorrência inválida (não pode ser futura).'; END IF;
    IF NOT (DATE(v_dt) <=> DATE(v_data)) THEN
      UPDATE nc_indicacao_ocorrencia SET DATA_OCORRENCIA = v_dt WHERE CODOCORRENCIA = p_codigo;
      INSERT INTO tmp_nc_corr (campo, antes, depois) VALUES ('Data da ocorrência', COALESCE(DATE_FORMAT(v_data, '%d/%m/%Y'), ''), DATE_FORMAT(v_dt, '%d/%m/%Y'));
    END IF;
  END IF;
  -- Unidade
  IF fn_nc_tem(v_d, 'codunidade') THEN
    SET v_i = CAST(fn_nc_txt(v_d, 'codunidade') AS SIGNED);
    IF NOT (v_i <=> v_unidade) THEN
      IF v_i IS NULL OR NOT EXISTS (SELECT 1 FROM unidades WHERE CODUNIDADE = v_i) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unidade inexistente.'; END IF;
      UPDATE nc_indicacao_ocorrencia SET codunidade = v_i WHERE CODOCORRENCIA = p_codigo;
      INSERT INTO tmp_nc_corr (campo, antes, depois) VALUES ('Unidade', COALESCE((SELECT CONVERT(DSCUNIDADE USING utf8mb4) FROM unidades WHERE CODUNIDADE = v_unidade), ''), (SELECT CONVERT(DSCUNIDADE USING utf8mb4) FROM unidades WHERE CODUNIDADE = v_i));
    END IF;
  END IF;
  -- Lista de distribuição (vazio = sem lista)
  IF fn_nc_tem(v_d, 'listadedistribuicao') THEN
    SET v_i = CAST(fn_nc_txt(v_d, 'listadedistribuicao') AS SIGNED);
    IF NOT (CONVERT(CAST(v_i AS CHAR) USING utf8mb4) COLLATE utf8mb4_bin <=> CONVERT(NULLIF(TRIM(COALESCE(v_lista, '')), '') USING utf8mb4) COLLATE utf8mb4_bin) THEN
      IF v_i IS NOT NULL AND NOT EXISTS (SELECT 1 FROM nc_listadistribuicao WHERE CODLISTA = v_i) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lista de distribuição inexistente.'; END IF;
      UPDATE nc_indicacao_ocorrencia SET listadedistribuicao = CAST(v_i AS CHAR) WHERE CODOCORRENCIA = p_codigo;
      INSERT INTO tmp_nc_corr (campo, antes, depois) VALUES ('Lista de distribuição',
        COALESCE((SELECT CONVERT(DSCLISTA USING utf8mb4) FROM nc_listadistribuicao WHERE v_lista REGEXP '^[0-9]+$' AND CODLISTA = CAST(v_lista AS SIGNED)), COALESCE(v_lista, '')),
        COALESCE((SELECT CONVERT(DSCLISTA USING utf8mb4) FROM nc_listadistribuicao WHERE CODLISTA = v_i), '(sem lista)'));
    END IF;
  END IF;
  -- Textos
  IF fn_nc_tem(v_d, 'IDENTIFICACAO') THEN
    SET v_t = fn_nc_txt(v_d, 'IDENTIFICACAO');
    IF v_t IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O sumário não pode ficar vazio.'; END IF;
    IF NOT (CONVERT(v_t USING utf8mb4) COLLATE utf8mb4_bin <=> CONVERT(v_ident USING utf8mb4) COLLATE utf8mb4_bin) THEN
      UPDATE nc_indicacao_ocorrencia SET IDENTIFICACAO = LEFT(v_t, 500) WHERE CODOCORRENCIA = p_codigo;
      INSERT INTO tmp_nc_corr (campo, antes, depois) VALUES ('Sumário', LEFT(COALESCE(v_ident, ''), 300), LEFT(v_t, 300));
    END IF;
  END IF;
  IF fn_nc_tem(v_d, 'DESCRICAO') THEN
    SET v_t = fn_nc_txt(v_d, 'DESCRICAO');
    IF NOT (CONVERT(v_t USING utf8mb4) COLLATE utf8mb4_bin <=> CONVERT(v_desc USING utf8mb4) COLLATE utf8mb4_bin) THEN
      UPDATE nc_indicacao_ocorrencia SET DESCRICAO = v_t WHERE CODOCORRENCIA = p_codigo;
      INSERT INTO tmp_nc_corr (campo, antes, depois) VALUES ('Descrição', LEFT(COALESCE(v_desc, ''), 300), LEFT(COALESCE(v_t, ''), 300));
    END IF;
  END IF;
  IF fn_nc_tem(v_d, 'PROVIDENCIA') THEN
    SET v_t = fn_nc_txt(v_d, 'PROVIDENCIA');
    IF NOT (CONVERT(v_t USING utf8mb4) COLLATE utf8mb4_bin <=> CONVERT(v_prov USING utf8mb4) COLLATE utf8mb4_bin) THEN
      UPDATE nc_indicacao_ocorrencia SET PROVIDENCIA = v_t WHERE CODOCORRENCIA = p_codigo;
      INSERT INTO tmp_nc_corr (campo, antes, depois) VALUES ('Ação imediata', LEFT(COALESCE(v_prov, ''), 300), LEFT(COALESCE(v_t, ''), 300));
    END IF;
  END IF;
  IF fn_nc_tem(v_d, 'ANALISE_ABRANGENCIA') THEN
    SET v_t = fn_nc_txt(v_d, 'ANALISE_ABRANGENCIA');
    IF NOT (CONVERT(v_t USING utf8mb4) COLLATE utf8mb4_bin <=> CONVERT(v_abr USING utf8mb4) COLLATE utf8mb4_bin) THEN
      UPDATE nc_indicacao_ocorrencia SET ANALISE_ABRANGENCIA = v_t WHERE CODOCORRENCIA = p_codigo;
      INSERT INTO tmp_nc_corr (campo, antes, depois) VALUES ('Análise de abrangência', LEFT(COALESCE(v_abr, ''), 300), LEFT(COALESCE(v_t, ''), 300));
    END IF;
  END IF;
  -- Campos do tipo da NC
  IF fn_nc_tem(v_d, 'CODFORNECEDOR') THEN
    IF NOT EXISTS (SELECT 1 FROM vw_nc_tipo_campos WHERE CODTIPONAOCONFORMIDADE = v_tipo AND CAMPO = 'CODFORNECEDOR') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este tipo de NC não tem fornecedor.'; END IF;
    SET v_i = CAST(fn_nc_txt(v_d, 'CODFORNECEDOR') AS SIGNED);
    IF NOT (v_i <=> v_forn) THEN
      IF v_i IS NULL OR NOT EXISTS (SELECT 1 FROM fo_fornecedores WHERE CODFORNECEDOR = v_i) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fornecedor inexistente.'; END IF;
      UPDATE nc_indicacao_ocorrencia SET CODFORNECEDOR = v_i WHERE CODOCORRENCIA = p_codigo;
      INSERT INTO tmp_nc_corr (campo, antes, depois) VALUES ('Fornecedor', COALESCE((SELECT CONVERT(RAZAOSOCIAL USING utf8mb4) FROM fo_fornecedores WHERE CODFORNECEDOR = v_forn), ''), (SELECT CONVERT(RAZAOSOCIAL USING utf8mb4) FROM fo_fornecedores WHERE CODFORNECEDOR = v_i));
    END IF;
  END IF;
  IF fn_nc_tem(v_d, 'QTDENVOLVIDA') THEN
    IF NOT EXISTS (SELECT 1 FROM vw_nc_tipo_campos WHERE CODTIPONAOCONFORMIDADE = v_tipo AND CAMPO = 'QTDENVOLVIDA') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este tipo de NC não tem quantidade envolvida.'; END IF;
    SET v_t = fn_nc_txt(v_d, 'QTDENVOLVIDA');
    IF NOT (CONVERT(v_t USING utf8mb4) COLLATE utf8mb4_bin <=> CONVERT(v_qe USING utf8mb4) COLLATE utf8mb4_bin) THEN
      UPDATE nc_indicacao_ocorrencia SET QTDENVOLVIDA = LEFT(v_t, 100) WHERE CODOCORRENCIA = p_codigo;
      INSERT INTO tmp_nc_corr (campo, antes, depois) VALUES ('Quantidade envolvida', COALESCE(v_qe, ''), COALESCE(v_t, ''));
    END IF;
  END IF;
  IF fn_nc_tem(v_d, 'QTDDEVOLVIDA') THEN
    IF NOT EXISTS (SELECT 1 FROM vw_nc_tipo_campos WHERE CODTIPONAOCONFORMIDADE = v_tipo AND CAMPO = 'QTDDEVOLVIDA') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este tipo de NC não tem quantidade devolvida.'; END IF;
    SET v_t = fn_nc_txt(v_d, 'QTDDEVOLVIDA');
    IF NOT (CONVERT(v_t USING utf8mb4) COLLATE utf8mb4_bin <=> CONVERT(v_qd USING utf8mb4) COLLATE utf8mb4_bin) THEN
      UPDATE nc_indicacao_ocorrencia SET QTDDEVOLVIDA = LEFT(v_t, 100) WHERE CODOCORRENCIA = p_codigo;
      INSERT INTO tmp_nc_corr (campo, antes, depois) VALUES ('Quantidade devolvida', COALESCE(v_qd, ''), COALESCE(v_t, ''));
    END IF;
  END IF;
  IF fn_nc_tem(v_d, 'DSCCLIENTE') THEN
    IF NOT EXISTS (SELECT 1 FROM vw_nc_tipo_campos WHERE CODTIPONAOCONFORMIDADE = v_tipo AND CAMPO = 'DSCCLIENTE') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este tipo de NC não tem cliente.'; END IF;
    SET v_t = fn_nc_txt(v_d, 'DSCCLIENTE');
    IF v_t IS NULL OR NOT EXISTS (SELECT 1 FROM vw_nc_opcoes WHERE LISTA = 'CLIENTE' AND CODIGO = CONVERT(v_t USING utf8mb4) COLLATE utf8mb4_0900_ai_ci) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cliente não cadastrado (Administração › Clientes).'; END IF;
    IF NOT (CONVERT(v_t USING utf8mb4) COLLATE utf8mb4_bin <=> CONVERT(TRIM(v_cliente) USING utf8mb4) COLLATE utf8mb4_bin) THEN
      UPDATE nc_indicacao_ocorrencia SET DSCCLIENTE = v_t WHERE CODOCORRENCIA = p_codigo;
      INSERT INTO tmp_nc_corr (campo, antes, depois) VALUES ('Cliente', COALESCE(v_cliente, ''), v_t);
    END IF;
  END IF;
  -- Responsáveis das ações
  SET v_j = 0; SET v_n = JSON_LENGTH(v_a);
  WHILE v_j < v_n DO
    SET v_k = CAST(JSON_UNQUOTE(JSON_EXTRACT(v_a, CONCAT('$[', v_j, '].codplanoacao'))) AS SIGNED);
    SET v_i = CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(v_a, CONCAT('$[', v_j, '].codusuario'))), 'null') AS SIGNED);
    IF v_k IS NULL OR COALESCE(fn_nc_plano_ocorrencia(v_k), 0) <> p_codigo THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ação não pertence a esta não conformidade.'; END IF;
    IF NOT ((SELECT CODUSUARIO FROM nc_planodeacao WHERE CODPLANOACAO = v_k) <=> v_i) THEN
      IF v_i IS NULL OR NOT EXISTS (SELECT 1 FROM usuarios WHERE codusuario = v_i AND ativo = 1) THEN
        SET v_msg = CONCAT('Responsável da ação ', v_k, ': escolha um usuário ativo.'); SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
      END IF;
      INSERT INTO tmp_nc_corr (campo, antes, depois, avisar, titulo, aba)
      SELECT CONCAT('Responsável da ação ', v_k), fn_nc_nome_usuario(CODUSUARIO), fn_nc_nome_usuario(v_i), v_i, 'Favor avaliar a ação sob sua responsabilidade de uma não conformidade', 'analise'
        FROM nc_planodeacao WHERE CODPLANOACAO = v_k;
      UPDATE nc_planodeacao SET CODUSUARIO = v_i WHERE CODPLANOACAO = v_k;
    END IF;
    SET v_j = v_j + 1;
  END WHILE;

  IF NOT EXISTS (SELECT 1 FROM tmp_nc_corr) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nenhuma alteração para gravar.'; END IF;
  SELECT GROUP_CONCAT(CONCAT(campo, ': "', antes, '" → "', depois, '"') ORDER BY ordem SEPARATOR '; ') INTO v_resumo FROM tmp_nc_corr;
  CALL sp_nc_historico_registrar(p_codigo, 'CORRECAO_ADMIN', v_etapa, v_etapa, p_usuario, CONCAT('Correção pela administração — ', v_resumo, '. Motivo: ', TRIM(p_motivo)));
  CALL sp_nc_admin_historico('CORRECAO', 'NC', p_codigo, NULL,
       (SELECT JSON_ARRAYAGG(JSON_OBJECT('campo', campo, 'antes', antes, 'depois', depois)) FROM tmp_nc_corr), p_motivo, p_usuario);
  SET v_fim = 0;
  OPEN c_avisos;
  avisos: LOOP
    FETCH c_avisos INTO v_dest, v_tit, v_aba;
    IF v_fim = 1 THEN LEAVE avisos; END IF;
    CALL sp_nc_alertar_usuario(p_codigo, v_dest, v_tit, v_aba, p_usuario);
  END LOOP;
  CLOSE c_avisos;
  SELECT (SELECT COUNT(*) FROM tmp_nc_corr) AS ALTERACOES, v_resumo AS RESUMO;
  DROP TEMPORARY TABLE IF EXISTS tmp_nc_corr;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_admin_etapa_destino`
-- @@
-- Para onde a ação de etapa levaria a NC (a tela mostra antes de confirmar). p_acao: REABRIR | DEVOLVER | ENCERRAR.
CREATE PROCEDURE `sp_nc_admin_etapa_destino`(IN p_codigo INT, IN p_acao VARCHAR(20))
BEGIN
  DECLARE v_etapa VARCHAR(500); DECLARE v_destino VARCHAR(500) DEFAULT NULL; DECLARE v_motivo VARCHAR(255) DEFAULT '';
  SELECT fn_nc_etapa(ETAPA) INTO v_etapa FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo;
  IF p_acao = 'REABRIR' THEN
    IF v_etapa <> 'Finalizado' THEN SET v_motivo = 'A NC não está finalizada.';
    ELSE
      SELECT fn_nc_etapa(ETAPA_ANTERIOR) INTO v_destino FROM nc_workflow_historico
       WHERE CODOCORRENCIA = p_codigo AND CONVERT(fn_nc_etapa(ETAPA_NOVA) USING utf8mb4) COLLATE utf8mb4_bin = 'Finalizado' AND COALESCE(ETAPA_ANTERIOR, '') NOT IN ('', 'Finalizado')
       ORDER BY CODHISTORICO DESC LIMIT 1;
      IF v_destino IS NULL THEN SET v_motivo = 'Sem histórico da finalização: não há etapa para onde reabrir.'; END IF;
    END IF;
  ELSEIF p_acao = 'DEVOLVER' THEN
    IF v_etapa IN ('Finalizado', 'Em criação') THEN SET v_motivo = IF(v_etapa = 'Finalizado', 'NC finalizada: use Reabrir.', 'A NC ainda está em criação.');
    ELSE
      SELECT fn_nc_etapa(ETAPA_ANTERIOR) INTO v_destino FROM nc_workflow_historico
       WHERE CODOCORRENCIA = p_codigo AND CONVERT(fn_nc_etapa(ETAPA_NOVA) USING utf8mb4) COLLATE utf8mb4_bin = v_etapa AND COALESCE(ETAPA_ANTERIOR, '') <> '' AND CONVERT(fn_nc_etapa(ETAPA_ANTERIOR) USING utf8mb4) COLLATE utf8mb4_bin <> v_etapa
       ORDER BY CODHISTORICO DESC LIMIT 1;
      IF v_destino IS NULL THEN SET v_motivo = 'Sem histórico de como a NC chegou a esta etapa.'; END IF;
    END IF;
  ELSEIF p_acao = 'ENCERRAR' THEN
    IF v_etapa = 'Finalizado' THEN SET v_motivo = 'A NC já está finalizada.'; ELSE SET v_destino = 'Finalizado'; END IF;
  ELSE SET v_motivo = 'Ação inválida.';
  END IF;
  SELECT v_etapa AS ETAPA_ATUAL, v_destino AS ETAPA_DESTINO, v_motivo AS IMPEDIMENTO;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_admin_etapa`
-- @@
-- Etapa controlada pela administração (com motivo): reabrir a NC finalizada, devolver uma etapa ou encerrar administrativamente
CREATE PROCEDURE `sp_nc_admin_etapa`(IN p_codigo INT, IN p_acao VARCHAR(20), IN p_motivo TEXT, IN p_usuario INT)
BEGIN
  DECLARE v_etapa, v_gravada, v_destino VARCHAR(500); DECLARE v_impedimento VARCHAR(255);
  DECLARE v_emissor, v_gestor, v_resp INT; DECLARE v_rotulo, v_titulo VARCHAR(180);
  CALL sp_nc_admin_validar(p_codigo, p_motivo);
  SELECT ETAPA, CODUSUARIO_EMISSOR, CODUSUARIO_GESTOR, CODUSUARIO_RESPONSAVEL INTO v_gravada, v_emissor, v_gestor, v_resp FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo FOR UPDATE;
  SET v_etapa = fn_nc_etapa(v_gravada);
  -- mesmo cálculo de sp_nc_admin_etapa_destino (sem devolver resultado)
  IF p_acao = 'REABRIR' THEN
    IF v_etapa <> 'Finalizado' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A NC não está finalizada.'; END IF;
    SELECT fn_nc_etapa(ETAPA_ANTERIOR) INTO v_destino FROM nc_workflow_historico
     WHERE CODOCORRENCIA = p_codigo AND CONVERT(fn_nc_etapa(ETAPA_NOVA) USING utf8mb4) COLLATE utf8mb4_bin = 'Finalizado' AND COALESCE(ETAPA_ANTERIOR, '') NOT IN ('', 'Finalizado') ORDER BY CODHISTORICO DESC LIMIT 1;
    IF v_destino IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sem histórico da finalização: não há etapa para onde reabrir.'; END IF;
    UPDATE nc_indicacao_ocorrencia SET ETAPA = v_destino, SITUACAO = 'Em andamento', data_de_finalizacao = NULL WHERE CODOCORRENCIA = p_codigo;
    SET v_rotulo = 'Reaberta pela administração'; SET v_titulo = CONCAT('Não conformidade reaberta pela administração (etapa ', v_destino, ')');
  ELSEIF p_acao = 'DEVOLVER' THEN
    IF v_etapa IN ('Finalizado', 'Em criação') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Só NCs em andamento podem ser devolvidas (finalizada: use Reabrir).'; END IF;
    SELECT fn_nc_etapa(ETAPA_ANTERIOR) INTO v_destino FROM nc_workflow_historico
     WHERE CODOCORRENCIA = p_codigo AND CONVERT(fn_nc_etapa(ETAPA_NOVA) USING utf8mb4) COLLATE utf8mb4_bin = v_etapa AND COALESCE(ETAPA_ANTERIOR, '') <> '' AND CONVERT(fn_nc_etapa(ETAPA_ANTERIOR) USING utf8mb4) COLLATE utf8mb4_bin <> v_etapa ORDER BY CODHISTORICO DESC LIMIT 1;
    IF v_destino IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sem histórico de como a NC chegou a esta etapa: não há para onde devolver.'; END IF;
    UPDATE nc_indicacao_ocorrencia SET ETAPA = v_destino, SITUACAO = 'Em andamento' WHERE CODOCORRENCIA = p_codigo;
    SET v_rotulo = 'Devolvida pela administração'; SET v_titulo = CONCAT('Não conformidade devolvida pela administração para a etapa ', v_destino);
  ELSEIF p_acao = 'ENCERRAR' THEN
    IF v_etapa = 'Finalizado' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A NC já está finalizada.'; END IF;
    SET v_destino = 'Finalizado';
    UPDATE nc_indicacao_ocorrencia SET ETAPA = 'Finalizado', SITUACAO = 'ENCERRADO ADMINISTRATIVAMENTE', data_de_finalizacao = NOW() WHERE CODOCORRENCIA = p_codigo;
    SET v_rotulo = 'Encerrada administrativamente'; SET v_titulo = 'Não conformidade encerrada administrativamente';
  ELSE
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ação de etapa inválida.';
  END IF;
  CALL sp_nc_historico_registrar(p_codigo, CONCAT('ADMIN_', p_acao), v_gravada, v_destino, p_usuario, CONCAT(v_rotulo, '. Motivo: ', TRIM(p_motivo)));
  CALL sp_nc_admin_historico(CONCAT('ETAPA_', p_acao), 'NC', p_codigo, JSON_OBJECT('etapa', v_etapa), JSON_OBJECT('etapa', v_destino), p_motivo, p_usuario);
  CALL sp_nc_alertar_usuario(p_codigo, v_emissor, v_titulo, 'ocorrencia', p_usuario);
  IF COALESCE(v_gestor, 0) <> COALESCE(v_emissor, 0) THEN CALL sp_nc_alertar_usuario(p_codigo, v_gestor, v_titulo, 'ocorrencia', p_usuario); END IF;
  IF COALESCE(v_resp, 0) NOT IN (COALESCE(v_emissor, 0), COALESCE(v_gestor, 0)) THEN CALL sp_nc_alertar_usuario(p_codigo, v_resp, v_titulo, 'ocorrencia', p_usuario); END IF;
  SELECT v_etapa AS ETAPA_ANTERIOR, v_destino AS ETAPA_NOVA;
END
-- @@
-- Responsabilidades de um usuário nas NCs em aberto e nas listas (Transferência de responsabilidades). EDITAVEL = 1 só no que é do
-- greenportal (NC a partir de 50001, lista a partir de 50001); o resto aparece para consulta.
CREATE OR REPLACE VIEW `vw_nc_admin_responsabilidades` AS
SELECT CONVERT('GESTOR' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS TIPO, o.CODOCORRENCIA AS CODIGO, o.CODOCORRENCIA, o.CODUSUARIO_GESTOR AS CODUSUARIO,
       CONVERT(COALESCE(NULLIF(o.NRANO, ''), o.CODOCORRENCIA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS NUMERO,
       CONVERT(COALESCE(o.IDENTIFICACAO, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS DESCRICAO,
       CONVERT(fn_nc_etapa(o.ETAPA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS ETAPA, CAST(o.CODOCORRENCIA >= fn_nc_faixa('ocorrencia') AS SIGNED) AS EDITAVEL
  FROM nc_indicacao_ocorrencia o WHERE o.CODUSUARIO_GESTOR > 0 AND COALESCE(o.ETAPA, '') <> 'Finalizado'
UNION ALL
SELECT CONVERT('RESPONSAVEL' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, o.CODOCORRENCIA, o.CODOCORRENCIA, o.CODUSUARIO_RESPONSAVEL,
       CONVERT(COALESCE(NULLIF(o.NRANO, ''), o.CODOCORRENCIA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(COALESCE(o.IDENTIFICACAO, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
       CONVERT(fn_nc_etapa(o.ETAPA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(o.CODOCORRENCIA >= fn_nc_faixa('ocorrencia') AS SIGNED)
  FROM nc_indicacao_ocorrencia o WHERE o.CODUSUARIO_RESPONSAVEL > 0 AND COALESCE(o.ETAPA, '') <> 'Finalizado'
UNION ALL
SELECT CONVERT('EMITENTE' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, o.CODOCORRENCIA, o.CODOCORRENCIA, o.CODUSUARIO_EMISSOR,
       CONVERT(COALESCE(NULLIF(o.NRANO, ''), o.CODOCORRENCIA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(COALESCE(o.IDENTIFICACAO, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
       CONVERT(fn_nc_etapa(o.ETAPA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(o.CODOCORRENCIA >= fn_nc_faixa('ocorrencia') AS SIGNED)
  FROM nc_indicacao_ocorrencia o WHERE o.CODUSUARIO_EMISSOR > 0 AND COALESCE(o.ETAPA, '') <> 'Finalizado'
UNION ALL
SELECT CONVERT('ACAO' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, p.CODPLANOACAO, o.CODOCORRENCIA, p.CODUSUARIO,
       CONVERT(COALESCE(NULLIF(o.NRANO, ''), o.CODOCORRENCIA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(LEFT(COALESCE(p.ACAO, ''), 300) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
       CONVERT(fn_nc_etapa(o.ETAPA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(o.CODOCORRENCIA >= fn_nc_faixa('ocorrencia') AS SIGNED)
  FROM nc_planodeacao p LEFT JOIN nc_causas c ON c.CODCAUSA = p.CODCAUSA
  JOIN nc_indicacao_ocorrencia o ON o.CODOCORRENCIA = COALESCE(p.CODOCORRENCIA, c.CODOCORRENCIA)
 WHERE p.CODUSUARIO > 0 AND COALESCE(p.STATUS, 0) < 100 AND COALESCE(o.ETAPA, '') <> 'Finalizado'
UNION ALL
SELECT CONVERT('LISTA' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, x.CODLISTAXUSUARIOS, NULL, x.CODUSUARIO,
       CONVERT(CAST(l.CODLISTA AS CHAR) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(TRIM(l.DSCLISTA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
       CONVERT('' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(l.CODLISTA >= 50001 AS SIGNED)
  FROM nc_listadistribuicaoxusuarios x JOIN nc_listadistribuicao l ON l.CODLISTA = x.CODLISTA
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_admin_transferir`
-- @@
-- Transferência de responsabilidades (desligamento/troca de função): p_itens = [{"tipo": "GESTOR|RESPONSAVEL|EMITENTE|ACAO|LISTA", "codigo": N}]
-- (códigos de vw_nc_admin_responsabilidades). Só o que é do greenportal e ainda é do usuário de origem; o resto é contado como ignorado.
CREATE PROCEDURE `sp_nc_admin_transferir`(IN p_origem INT, IN p_destino INT, IN p_itens JSON, IN p_motivo TEXT, IN p_usuario INT)
BEGIN
  DECLARE v_j, v_n, v_cod, v_nc, v_feitos, v_ignorados, v_rc INT DEFAULT 0;
  DECLARE v_tipo VARCHAR(20); DECLARE v_rotulo VARCHAR(80); DECLARE v_etapa VARCHAR(500);
  DECLARE v_fim INT DEFAULT 0;
  DECLARE v_de, v_para VARCHAR(250);
  DECLARE c_ncs CURSOR FOR SELECT CODOCORRENCIA FROM tmp_nc_transf;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fim = 1;
  IF CHAR_LENGTH(TRIM(COALESCE(p_motivo, ''))) < 5 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o motivo (obrigatório).'; END IF;
  IF COALESCE(p_origem, 0) = 0 OR p_origem = p_destino THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha usuários de origem e de destino diferentes.'; END IF;
  IF NOT EXISTS (SELECT 1 FROM usuarios WHERE codusuario = p_destino AND ativo = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O usuário de destino precisa estar ativo.'; END IF;
  IF p_itens IS NULL OR JSON_TYPE(p_itens) <> 'ARRAY' OR JSON_LENGTH(p_itens) = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha o que transferir.'; END IF;
  SET v_de = fn_nc_nome_usuario(p_origem); SET v_para = fn_nc_nome_usuario(p_destino);
  DROP TEMPORARY TABLE IF EXISTS tmp_nc_transf;
  CREATE TEMPORARY TABLE tmp_nc_transf (CODOCORRENCIA INT PRIMARY KEY);
  SET v_n = JSON_LENGTH(p_itens);
  WHILE v_j < v_n DO
    SET v_tipo = UPPER(JSON_UNQUOTE(JSON_EXTRACT(p_itens, CONCAT('$[', v_j, '].tipo'))));
    SET v_cod = CAST(JSON_UNQUOTE(JSON_EXTRACT(p_itens, CONCAT('$[', v_j, '].codigo'))) AS SIGNED);
    SET v_nc = NULL; SET v_rotulo = NULL; SET v_rc = 0;
    IF v_tipo IN ('GESTOR', 'RESPONSAVEL', 'EMITENTE') THEN
      SET v_nc = v_cod;
      IF v_tipo = 'GESTOR' THEN
        UPDATE nc_indicacao_ocorrencia SET CODUSUARIO_GESTOR = p_destino WHERE CODOCORRENCIA = v_cod AND CODOCORRENCIA >= fn_nc_faixa('ocorrencia') AND CODUSUARIO_GESTOR = p_origem AND COALESCE(ETAPA, '') <> 'Finalizado';
        SET v_rc = ROW_COUNT(); SET v_rotulo = 'Gestor';
      ELSEIF v_tipo = 'RESPONSAVEL' THEN
        UPDATE nc_indicacao_ocorrencia SET CODUSUARIO_RESPONSAVEL = p_destino WHERE CODOCORRENCIA = v_cod AND CODOCORRENCIA >= fn_nc_faixa('ocorrencia') AND CODUSUARIO_RESPONSAVEL = p_origem AND COALESCE(ETAPA, '') <> 'Finalizado';
        SET v_rc = ROW_COUNT(); SET v_rotulo = 'Responsável';
      ELSE
        UPDATE nc_indicacao_ocorrencia SET CODUSUARIO_EMISSOR = p_destino WHERE CODOCORRENCIA = v_cod AND CODOCORRENCIA >= fn_nc_faixa('ocorrencia') AND CODUSUARIO_EMISSOR = p_origem AND COALESCE(ETAPA, '') <> 'Finalizado';
        SET v_rc = ROW_COUNT(); SET v_rotulo = 'Emitente';
      END IF;
    ELSEIF v_tipo = 'ACAO' THEN
      SET v_nc = fn_nc_plano_ocorrencia(v_cod);
      UPDATE nc_planodeacao p JOIN nc_indicacao_ocorrencia o ON o.CODOCORRENCIA = v_nc
         SET p.CODUSUARIO = p_destino
       WHERE p.CODPLANOACAO = v_cod AND p.CODUSUARIO = p_origem AND COALESCE(p.STATUS, 0) < 100 AND o.CODOCORRENCIA >= fn_nc_faixa('ocorrencia') AND COALESCE(o.ETAPA, '') <> 'Finalizado';
      SET v_rc = ROW_COUNT();
      SET v_rotulo = CONCAT('Responsável da ação ', v_cod);
    ELSEIF v_tipo = 'LISTA' THEN
      -- já na lista: só sai a origem; senão a linha passa para o destino
      IF EXISTS (SELECT 1 FROM nc_listadistribuicaoxusuarios x JOIN nc_listadistribuicaoxusuarios y ON y.CODLISTA = x.CODLISTA AND y.CODUSUARIO = p_destino WHERE x.CODLISTAXUSUARIOS = v_cod) THEN
        DELETE FROM nc_listadistribuicaoxusuarios WHERE CODLISTAXUSUARIOS = v_cod AND CODUSUARIO = p_origem AND CODLISTA >= 50001;
        SET v_rc = ROW_COUNT();
      ELSE
        UPDATE nc_listadistribuicaoxusuarios SET CODUSUARIO = p_destino WHERE CODLISTAXUSUARIOS = v_cod AND CODUSUARIO = p_origem AND CODLISTA >= 50001;
        SET v_rc = ROW_COUNT();
      END IF;
    END IF;
    IF v_rc > 0 THEN
      SET v_feitos = v_feitos + 1;
      IF v_nc IS NOT NULL THEN
        SELECT ETAPA INTO v_etapa FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = v_nc;
        CALL sp_nc_historico_registrar(v_nc, 'TRANSFERENCIA_ADMIN', v_etapa, v_etapa, p_usuario, CONCAT('Transferência pela administração — ', v_rotulo, ': "', v_de, '" → "', v_para, '". Motivo: ', TRIM(p_motivo)));
        INSERT IGNORE INTO tmp_nc_transf VALUES (v_nc);
      END IF;
    ELSE
      SET v_ignorados = v_ignorados + 1;
    END IF;
    SET v_j = v_j + 1;
  END WHILE;
  SET v_fim = 0;
  OPEN c_ncs;
  ncs: LOOP
    FETCH c_ncs INTO v_nc;
    IF v_fim = 1 THEN LEAVE ncs; END IF;
    CALL sp_nc_alertar_usuario(v_nc, p_destino, 'Você recebeu responsabilidades nesta não conformidade (transferência da administração)', 'ocorrencia', p_usuario);
  END LOOP;
  CLOSE c_ncs;
  IF v_feitos > 0 THEN
    CALL sp_nc_admin_historico('TRANSFERENCIA', 'USUARIO', p_origem, JSON_OBJECT('usuario', v_de), JSON_OBJECT('destino', p_destino, 'nome_destino', v_para, 'itens', JSON_EXTRACT(p_itens, '$'), 'transferidos', v_feitos, 'ignorados', v_ignorados), p_motivo, p_usuario);
  END IF;
  DROP TEMPORARY TABLE IF EXISTS tmp_nc_transf;
  SELECT v_feitos AS TRANSFERIDOS, v_ignorados AS IGNORADOS;
END
-- @@
-- Painel de saneamento: NCs em aberto com os sinais de que precisam de ação da administração
CREATE OR REPLACE VIEW `vw_nc_saneamento` AS
SELECT o.CODOCORRENCIA,
       CONVERT(COALESCE(NULLIF(o.NRANO, ''), o.CODOCORRENCIA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS NUMERO,
       CONVERT(COALESCE(t.DSCTIPONAOCONFORMIDADE, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS TIPO,
       CONVERT(fn_nc_etapa(o.ETAPA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS ETAPA,
       CONVERT(LEFT(COALESCE(o.IDENTIFICACAO, ''), 300) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS SUMARIO,
       CONVERT(COALESCE(un.DSCUNIDADE, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS UNIDADE,
       o.CODUSUARIO_GESTOR, CONVERT(COALESCE(ug.nome, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS GESTOR, CAST(COALESCE(ug.ativo, 0) AS SIGNED) AS GESTOR_ATIVO,
       o.CODUSUARIO_RESPONSAVEL, CONVERT(COALESCE(ur.nome, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS RESPONSAVEL, CAST(COALESCE(ur.ativo, 0) AS SIGNED) AS RESPONSAVEL_ATIVO,
       CAST(COALESCE(o.CODUSUARIO_GESTOR, 0) = 0 AS SIGNED) AS SEM_GESTOR,
       CAST((COALESCE(o.CODUSUARIO_GESTOR, 0) > 0 AND COALESCE(ug.ativo, 0) = 0) OR (COALESCE(o.CODUSUARIO_RESPONSAVEL, 0) > 0 AND COALESCE(ur.ativo, 0) = 0) AS SIGNED) AS PESSOA_INATIVA,
       COALESCE(o.data_de_criacao, o.DATA_OCORRENCIA) AS CRIADA_EM,
       GREATEST(COALESCE(h.ULTIMA, '1900-01-01'), COALESCE(o.data_de_criacao, o.DATA_OCORRENCIA, '1900-01-01')) AS ULTIMA_MOVIMENTACAO,
       DATEDIFF(CURDATE(), COALESCE(o.data_de_criacao, o.DATA_OCORRENCIA)) AS IDADE_DIAS,
       DATEDIFF(CURDATE(), GREATEST(COALESCE(h.ULTIMA, '1900-01-01'), COALESCE(o.data_de_criacao, o.DATA_OCORRENCIA, '1900-01-01'))) AS DIAS_SEM_MOVIMENTO,
       CAST(COALESCE(a.VENCIDAS, 0) AS SIGNED) AS ACOES_VENCIDAS,
       CONVERT(IF(o.CODOCORRENCIA >= fn_nc_faixa('ocorrencia'), 'GREENPORTAL', 'LEGADO') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS ORIGEM
  FROM nc_indicacao_ocorrencia o
  LEFT JOIN nc_tipo_de_naoconformidade t ON t.CODTIPONAOCONFORMIDADE = o.CODTIPONAOCONFORMIDADE
  LEFT JOIN unidades un ON un.CODUNIDADE = o.codunidade
  LEFT JOIN usuarios ug ON ug.codusuario = o.CODUSUARIO_GESTOR
  LEFT JOIN usuarios ur ON ur.codusuario = o.CODUSUARIO_RESPONSAVEL
  LEFT JOIN (SELECT CODOCORRENCIA, MAX(CRIADO_EM) AS ULTIMA FROM nc_workflow_historico GROUP BY CODOCORRENCIA) h ON h.CODOCORRENCIA = o.CODOCORRENCIA
  LEFT JOIN (SELECT COALESCE(p.CODOCORRENCIA, c.CODOCORRENCIA) AS CODOCORRENCIA, COUNT(*) AS VENCIDAS FROM nc_planodeacao p LEFT JOIN nc_causas c ON c.CODCAUSA = p.CODCAUSA
              WHERE COALESCE(p.STATUS, 0) < 100 AND p.DATAFINAL IS NOT NULL AND p.DATAFINAL > '1900-01-01' AND p.DATAFINAL < CURDATE() GROUP BY COALESCE(p.CODOCORRENCIA, c.CODOCORRENCIA)) a ON a.CODOCORRENCIA = o.CODOCORRENCIA
 WHERE COALESCE(o.ETAPA, '') <> 'Finalizado'
-- @@
CREATE OR REPLACE VIEW `vw_nc_acoes_vencidas` AS
SELECT p.CODPLANOACAO, o.CODOCORRENCIA,
       CONVERT(COALESCE(NULLIF(o.NRANO, ''), o.CODOCORRENCIA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS NUMERO,
       CONVERT(LEFT(COALESCE(p.ACAO, ''), 300) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS ACAO,
       p.CODUSUARIO, CONVERT(COALESCE(u.nome, CONCAT(p.CODUSUARIO, ' (não cadastrado)')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS RESPONSAVEL, CAST(COALESCE(u.ativo, 0) AS SIGNED) AS RESPONSAVEL_ATIVO,
       p.DATAFINAL, DATEDIFF(CURDATE(), p.DATAFINAL) AS DIAS_ATRASO, CAST(COALESCE(p.STATUS, 0) AS SIGNED) AS STATUS,
       CONVERT(fn_nc_etapa(o.ETAPA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS ETAPA,
       CONVERT(IF(o.CODOCORRENCIA >= fn_nc_faixa('ocorrencia'), 'GREENPORTAL', 'LEGADO') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS ORIGEM
  FROM nc_planodeacao p LEFT JOIN nc_causas c ON c.CODCAUSA = p.CODCAUSA
  JOIN nc_indicacao_ocorrencia o ON o.CODOCORRENCIA = COALESCE(p.CODOCORRENCIA, c.CODOCORRENCIA)
  LEFT JOIN usuarios u ON u.codusuario = p.CODUSUARIO
 WHERE COALESCE(p.STATUS, 0) < 100 AND p.DATAFINAL IS NOT NULL AND p.DATAFINAL > '1900-01-01' AND p.DATAFINAL < CURDATE() AND COALESCE(o.ETAPA, '') <> 'Finalizado'
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_admin_atribuir_gestor`
-- @@
-- Painel de saneamento: atribui o gestor às NCs escolhidas (só as do greenportal em aberto; as outras são ignoradas)
CREATE PROCEDURE `sp_nc_admin_atribuir_gestor`(IN p_itens JSON, IN p_gestor INT, IN p_motivo TEXT, IN p_usuario INT)
BEGIN
  DECLARE v_j, v_n, v_nc, v_antes, v_feitos, v_ignorados INT DEFAULT 0; DECLARE v_etapa VARCHAR(500);
  IF CHAR_LENGTH(TRIM(COALESCE(p_motivo, ''))) < 5 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o motivo (obrigatório).'; END IF;
  IF NOT EXISTS (SELECT 1 FROM usuarios WHERE codusuario = p_gestor AND ativo = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha um gestor ativo.'; END IF;
  IF p_itens IS NULL OR JSON_TYPE(p_itens) <> 'ARRAY' OR JSON_LENGTH(p_itens) = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha as não conformidades.'; END IF;
  SET v_n = JSON_LENGTH(p_itens);
  WHILE v_j < v_n DO
    SET v_nc = CAST(JSON_UNQUOTE(JSON_EXTRACT(p_itens, CONCAT('$[', v_j, ']'))) AS SIGNED);
    SET v_antes = NULL; SET v_etapa = NULL;
    SELECT CODUSUARIO_GESTOR, ETAPA INTO v_antes, v_etapa FROM nc_indicacao_ocorrencia
     WHERE CODOCORRENCIA = v_nc AND CODOCORRENCIA >= fn_nc_faixa('ocorrencia') AND COALESCE(ETAPA, '') <> 'Finalizado';
    IF v_etapa IS NOT NULL AND NOT (v_antes <=> p_gestor) THEN
      UPDATE nc_indicacao_ocorrencia SET CODUSUARIO_GESTOR = p_gestor WHERE CODOCORRENCIA = v_nc;
      CALL sp_nc_historico_registrar(v_nc, 'GESTOR_ATRIBUIDO_ADMIN', v_etapa, v_etapa, p_usuario, CONCAT('Gestor atribuído pela administração: "', fn_nc_nome_usuario(v_antes), '" → "', fn_nc_nome_usuario(p_gestor), '". Motivo: ', TRIM(p_motivo)));
      CALL sp_nc_alertar_usuario(v_nc, p_gestor, 'Você passou a ser o gestor desta não conformidade (administração)', 'gestor', p_usuario);
      SET v_feitos = v_feitos + 1;
    ELSE SET v_ignorados = v_ignorados + 1;
    END IF;
    SET v_j = v_j + 1;
  END WHILE;
  IF v_feitos > 0 THEN CALL sp_nc_admin_historico('GESTOR_ATRIBUIDO', 'SANEAMENTO', p_gestor, NULL, JSON_OBJECT('ncs', JSON_EXTRACT(p_itens, '$'), 'atribuidos', v_feitos), p_motivo, p_usuario); END IF;
  SELECT v_feitos AS ATRIBUIDOS, v_ignorados AS IGNORADOS;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_admin_lembrar`
-- @@
-- Painel de saneamento: lembrete (Home › Alertas) ao responsável de cada ação vencida escolhida (só NCs do greenportal)
CREATE PROCEDURE `sp_nc_admin_lembrar`(IN p_itens JSON, IN p_usuario INT)
BEGIN
  DECLARE v_j, v_n, v_cod, v_nc, v_resp, v_feitos, v_ignorados INT DEFAULT 0; DECLARE v_prazo DATE;
  IF p_itens IS NULL OR JSON_TYPE(p_itens) <> 'ARRAY' OR JSON_LENGTH(p_itens) = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha as ações.'; END IF;
  SET v_n = JSON_LENGTH(p_itens);
  WHILE v_j < v_n DO
    SET v_cod = CAST(JSON_UNQUOTE(JSON_EXTRACT(p_itens, CONCAT('$[', v_j, ']'))) AS SIGNED);
    SET v_nc = NULL; SET v_resp = NULL; SET v_prazo = NULL;
    SELECT CODOCORRENCIA, CODUSUARIO, DATAFINAL INTO v_nc, v_resp, v_prazo FROM vw_nc_acoes_vencidas WHERE CODPLANOACAO = v_cod AND ORIGEM = 'GREENPORTAL' AND RESPONSAVEL_ATIVO = 1;
    IF v_nc IS NOT NULL THEN
      CALL sp_nc_alertar_usuario(v_nc, v_resp, CONCAT('Lembrete: a ação ', v_cod, ' sob sua responsabilidade venceu em ', DATE_FORMAT(v_prazo, '%d/%m/%Y')), 'analise', p_usuario);
      SET v_feitos = v_feitos + 1;
    ELSE SET v_ignorados = v_ignorados + 1;
    END IF;
    SET v_j = v_j + 1;
  END WHILE;
  IF v_feitos > 0 THEN CALL sp_nc_admin_historico('LEMBRETE', 'SANEAMENTO', 'ACOES', NULL, JSON_OBJECT('acoes', JSON_EXTRACT(p_itens, '$'), 'lembrados', v_feitos), NULL, p_usuario); END IF;
  SELECT v_feitos AS LEMBRADOS, v_ignorados AS IGNORADOS;
END
