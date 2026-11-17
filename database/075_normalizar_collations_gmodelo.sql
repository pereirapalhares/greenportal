-- Green Portal - Normalização das collations legadas do gmodelo_dbp.
-- Executar após backup e validação das amostras textuais.

USE `gmodelo_dbp`;

ALTER TABLE `documentos`
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `documentosxaspectos`
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `documentosxatividades`
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `documentosximpactos`
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `objetivosmetasxaspectos`
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `documentosxunidades`
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `legislacaoxchecklist`
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `legislacaoxdocumentos`
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `planodeacao`
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
