-- Permite que atribuições diretas sejam classificadas por um tipo ou categoria.
-- Necessário para representar os campos de finalidade das licenças.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

ALTER TABLE `responsabilidade_atribuicoes`
    DROP CHECK `CK_RESP_ATRIB_ORIGEM_REF`;

ALTER TABLE `responsabilidade_atribuicoes`
    ADD CONSTRAINT `CK_RESP_ATRIB_ORIGEM_REF` CHECK (
        (`ORIGEM` = 'TIPO' AND `CODTIPORESPONSABILIDADE` IS NOT NULL AND `CODCATEGORIARESPONSABILIDADE` IS NULL)
        OR (`ORIGEM` = 'CATEGORIA' AND `CODTIPORESPONSABILIDADE` IS NULL AND `CODCATEGORIARESPONSABILIDADE` IS NOT NULL)
        OR (`ORIGEM` = 'DIRETA' AND NOT (`CODTIPORESPONSABILIDADE` IS NOT NULL AND `CODCATEGORIARESPONSABILIDADE` IS NOT NULL))
        OR (`ORIGEM` = 'GRUPO_PADRAO' AND `CODTIPORESPONSABILIDADE` IS NULL AND `CODCATEGORIARESPONSABILIDADE` IS NULL)
    );

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('MODELO_RESPONSABILIDADES_CONSTRAINT_100', 'database/093_modelo_responsabilidades_base.sql', 'gmodelo_dbp.responsabilidade_atribuicoes', 'ESTRUTURA', '1.0.1', 'CONCLUIDA', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, JSON_OBJECT('ajuste', 'DIRETA pode possuir tipo ou categoria'))
ON DUPLICATE KEY UPDATE
    `status` = VALUES(`status`), `concluida_em` = VALUES(`concluida_em`), `detalhes` = VALUES(`detalhes`);
