-- Green Portal - correcao de texto gravado com perda de acentuacao.
USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

UPDATE `setup_empresa`
   SET `valor` = 'Sistemas de Gestão em conformidade com as normas ISO 14001, OHSAS 18001 e ISO 16001.'
 WHERE `chave` = 'login_marketing' AND `idioma` = 'pt';
