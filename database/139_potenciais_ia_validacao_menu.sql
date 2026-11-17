USE `gmodelo_dbp`;

DROP PROCEDURE IF EXISTS sp_ia_potencial_solicitar;
DELIMITER $$
CREATE PROCEDURE sp_ia_potencial_solicitar(
    IN p_codusuario BIGINT,
    IN p_idioma CHAR(2),
    IN p_contexto_json JSON
)
SQL SECURITY DEFINER
BEGIN
    DECLARE v_existente BIGINT DEFAULT NULL;
    SELECT codsolicitacao INTO v_existente
      FROM ml_potenciais_solicitacoes
     WHERE codusuario = p_codusuario
       AND status IN ('PENDENTE','PROCESSANDO')
       AND JSON_UNQUOTE(JSON_EXTRACT(contexto_json, '$.CODLEGISLACAO')) = JSON_UNQUOTE(JSON_EXTRACT(p_contexto_json, '$.CODLEGISLACAO'))
     ORDER BY codsolicitacao DESC LIMIT 1;
    IF v_existente IS NULL THEN
        INSERT INTO ml_potenciais_solicitacoes (codusuario, idioma, contexto_json)
        VALUES (p_codusuario, IF(p_idioma IN ('pt','en','es'), p_idioma, 'pt'), p_contexto_json);
        SET v_existente = LAST_INSERT_ID();
    END IF;
    SELECT v_existente AS codsolicitacao;
END$$

DROP PROCEDURE IF EXISTS sp_ia_potencial_validar;
CREATE PROCEDURE sp_ia_potencial_validar(
    IN p_codpotencial BIGINT,
    IN p_codusuario BIGINT,
    IN p_status VARCHAR(20)
)
SQL SECURITY DEFINER
BEGIN
    IF p_status NOT IN ('APROVADO','REJEITADO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Status de validacao nao permitido.';
    END IF;
    UPDATE ml_potenciais
       SET status = p_status, validado_em = NOW(), validado_por = p_codusuario
     WHERE codpotencial = p_codpotencial AND status = 'SUGERIDO';
    IF ROW_COUNT() = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O potencial nao esta disponivel para validacao.';
    END IF;
    SELECT codpotencial, status, validado_em, validado_por
      FROM ml_potenciais WHERE codpotencial = p_codpotencial;
END$$
DELIMITER ;

INSERT INTO menus (codmenu_pai, modulo, codigo, label, label_pt, label_en, label_es, url, nivel, ordem, ativo)
SELECT m.codmenu, 'MODULO_LEGAL', 'MODULOLEGAL_POTENCIAIS', 'Potenciais IA', 'Potenciais IA', 'AI potentials', 'Potenciales IA', '/principal/modulolegal_potenciais.php', 2, 39, 1
  FROM menus m
 WHERE m.codigo = 'MODULO_LEGAL'
   AND NOT EXISTS (SELECT 1 FROM menus x WHERE x.codigo = 'MODULOLEGAL_POTENCIAIS');

INSERT INTO permissoes (codperfil, codmenu, pode_incluir, pode_visualizar, pode_editar, pode_excluir, pode_exportar_excel, pode_gerar_pdf)
SELECT p.codperfil, m.codmenu,
       IF(p.codigo IN ('ADMINISTRADOR','ADM_DA_EMPRESA'),1,0),
       IF(p.codigo IN ('ADMINISTRADOR','ADM_DA_EMPRESA','GESTOR'),1,0),
       IF(p.codigo IN ('ADMINISTRADOR','ADM_DA_EMPRESA'),1,0),
       0, 0, 0
  FROM perfis p CROSS JOIN menus m
 WHERE m.codigo = 'MODULOLEGAL_POTENCIAIS'
   AND NOT EXISTS (SELECT 1 FROM permissoes x WHERE x.codperfil = p.codperfil AND x.codmenu = m.codmenu);
