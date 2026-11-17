-- Migração 297 — Homologação de Fornecedores, etapa 4 (continuação): Gestão de Funcionários do fornecedor externo.
-- Aplicado por scripts/aplicar-migracao-297-funcionarios-fornecedor-externo.php em cada banco de cliente.
-- Comandos separados por "-- @@". Pode ser reaplicado. Depende da migração 296 (vw_homologacao_fornecedores_do_usuario).
-- Se a view vw_homologacao_funcionarios_grid for recriada com outras colunas, rode esta migração de novo.

-- @@
-- Funcionários dos fornecedores ativos ligados a cada usuário (Fornecedor x Usuários). A tela filtra sempre
-- CODUSUARIO_ACESSO = usuário logado quando o perfil é FORNECEDOR ou FORNECEDOR_GESTOR.
CREATE OR REPLACE VIEW `vw_homologacao_funcionarios_usuario_grid` AS
SELECT g.*, a.CODUSUARIO AS CODUSUARIO_ACESSO
  FROM `vw_homologacao_funcionarios_grid` g
  JOIN `vw_homologacao_fornecedores_do_usuario` a ON a.CODFORNECEDOR = g.CODFORNECEDOR
  JOIN `fo_fornecedores` f ON f.codfornecedor = g.CODFORNECEDOR
 WHERE LOWER(TRIM(COALESCE(f.ativadofor, ''))) IN ('sim', 's', '1')
