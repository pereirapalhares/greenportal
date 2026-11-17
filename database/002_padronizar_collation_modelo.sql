-- Padroniza as tabelas existentes da empresa Modelo.
-- Charset: utf8mb4 | Collation: utf8mb4_0900_ai_ci

USE `gmodelo_dbp`;

ALTER DATABASE `gmodelo_dbp`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_0900_ai_ci;

ALTER TABLE `empresa` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `grupos` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `perfis` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `menus` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `usuarios` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `perfil_menus` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `setup_empresa` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `logs_auditoria` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `registros_migracao` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `bugs_testes` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `artefatos_replicacao` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
