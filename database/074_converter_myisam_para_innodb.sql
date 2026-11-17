-- Green Portal - Conversão das tabelas legadas MyISAM para InnoDB.
-- Executar após backup do gmodelo_dbp.

USE `gmodelo_dbp`;

ALTER TABLE `documentosxunidades` ENGINE=InnoDB;
ALTER TABLE `legislacaoxchecklist` ENGINE=InnoDB;
ALTER TABLE `legislacaoxdocumentos` ENGINE=InnoDB;
ALTER TABLE `planodeacao` ENGINE=InnoDB;
ALTER TABLE `vlaiaxlegislacao` ENGINE=InnoDB;
ALTER TABLE `legislacaoxempresa` ENGINE=InnoDB;
