-- GreenSolutions 4.0.1 — Guia do usuário editável: autorização por tenant.
-- O catálogo do menu fica em guniversal_dbp; aqui só é concedido o acesso ao perfil ADMINISTRADOR.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

INSERT INTO permissoes (codperfil, codmenu, pode_incluir, pode_visualizar, pode_visualizar_total, pode_editar, pode_excluir, pode_exportar_excel, pode_gerar_pdf)
SELECT p.codperfil, m.codmenu, 1, 1, 1, 1, 1, 0, 0
  FROM perfis p
  JOIN (SELECT codmenu FROM guniversal_dbp.menus WHERE codigo = 'ADMINISTRACAO_GUIA_USUARIO' LIMIT 1) m ON 1 = 1
 WHERE p.codigo = 'ADMINISTRADOR'
ON DUPLICATE KEY UPDATE pode_incluir = VALUES(pode_incluir), pode_visualizar = VALUES(pode_visualizar),
    pode_visualizar_total = VALUES(pode_visualizar_total), pode_editar = VALUES(pode_editar), pode_excluir = VALUES(pode_excluir);

INSERT INTO perfil_menus (codperfil, codmenu, pode_incluir, pode_visualizar, pode_editar, pode_excluir, pode_exportar_excel, pode_gerar_pdf)
SELECT p.codperfil, m.codmenu, 1, 1, 1, 1, 0, 0
  FROM perfis p
  JOIN (SELECT codmenu FROM guniversal_dbp.menus WHERE codigo = 'ADMINISTRACAO_GUIA_USUARIO' LIMIT 1) m ON 1 = 1
 WHERE p.codigo = 'ADMINISTRADOR'
ON DUPLICATE KEY UPDATE pode_incluir = VALUES(pode_incluir), pode_visualizar = VALUES(pode_visualizar),
    pode_editar = VALUES(pode_editar), pode_excluir = VALUES(pode_excluir);

INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
VALUES ('GREEN_GUIA_USUARIO_199', 'guniversal_dbp.menus', 'permissoes.ADMINISTRACAO_GUIA_USUARIO', 'CONFIGURACAO', '4.0.1', 'CONCLUIDA', 1,
        CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, JSON_OBJECT('transacao', 'administracao_guia_usuario.php', 'perfil', 'ADMINISTRADOR'))
ON DUPLICATE KEY UPDATE status = VALUES(status), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes);
