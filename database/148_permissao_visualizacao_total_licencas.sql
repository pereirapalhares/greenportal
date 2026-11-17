-- Escopo de visualização por transação.
USE gmodelo_dbp;

DELIMITER $$
DROP PROCEDURE IF EXISTS sp_migration_148_visualizacao_total $$
CREATE PROCEDURE sp_migration_148_visualizacao_total()
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exists
      FROM information_schema.columns
     WHERE table_schema = 'gmodelo_dbp'
       AND table_name = 'permissoes'
       AND column_name = 'pode_visualizar_total';
    IF v_exists = 0 THEN
        ALTER TABLE gmodelo_dbp.permissoes ADD COLUMN pode_visualizar_total TINYINT(1) NOT NULL DEFAULT 0 AFTER pode_visualizar;
    END IF;

    CREATE OR REPLACE VIEW gmodelo_dbp.vw_permissoes_grid AS
    SELECT p.codpermissao,p.codperfil,pf.codigo AS perfil_codigo,p.codmenu,m.codigo AS menu_codigo,m.label_pt AS menu_label,
           p.pode_incluir,p.pode_visualizar,p.pode_visualizar_total,p.pode_editar,p.pode_excluir,p.pode_exportar_excel,p.pode_gerar_pdf
      FROM gmodelo_dbp.permissoes p
      JOIN gmodelo_dbp.perfis pf ON pf.codperfil=p.codperfil
      JOIN gmodelo_dbp.menus m ON m.codmenu=p.codmenu;

    UPDATE gmodelo_dbp.permissoes p
      JOIN gmodelo_dbp.perfis f ON f.codperfil=p.codperfil
      JOIN gmodelo_dbp.menus m ON m.codmenu=p.codmenu
       SET p.pode_visualizar=1,p.pode_visualizar_total=1
     WHERE m.codigo='LICENCAS' AND f.codigo IN ('ADMINISTRADOR','ADM_DA_EMPRESA');

    INSERT INTO gmodelo_dbp.registros_migracao
        (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes)
    VALUES ('148_permissao_visualizacao_total_licencas','greenportal','gmodelo_dbp.permissoes','ESTRUTURA','1','APLICADO',0,NOW(),NOW(),JSON_OBJECT('escopo','LICENCAS'));
END $$
DELIMITER ;
CALL sp_migration_148_visualizacao_total();
DROP PROCEDURE IF EXISTS sp_migration_148_visualizacao_total;
