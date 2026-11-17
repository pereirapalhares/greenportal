-- Cadastro central dos serviços e auditoria das ações administrativas.
USE `guniversal_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `servicos` (
    `codservico` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo` VARCHAR(80) NOT NULL,
    `nome_pt` VARCHAR(150) NOT NULL,
    `nome_en` VARCHAR(150) NOT NULL,
    `nome_es` VARCHAR(150) NOT NULL,
    `descricao_pt` VARCHAR(500) NULL,
    `descricao_en` VARCHAR(500) NULL,
    `descricao_es` VARCHAR(500) NULL,
    `host` VARCHAR(120) NOT NULL,
    `porta` INT UNSIGNED NOT NULL,
    `script` VARCHAR(255) NOT NULL,
    `ativo` TINYINT(1) NOT NULL DEFAULT 1,
    `ordem` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`codservico`), UNIQUE KEY `uk_servicos_codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `servicos_status` (
    `codservico` BIGINT UNSIGNED NOT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'DESCONHECIDO',
    `pid` BIGINT UNSIGNED NULL,
    `ultima_verificacao` DATETIME NULL,
    `ultima_inicializacao` DATETIME NULL,
    `ultima_parada` DATETIME NULL,
    `ultima_mensagem` VARCHAR(1000) NULL,
    `ultima_acao_por` BIGINT UNSIGNED NULL,
    PRIMARY KEY (`codservico`),
    CONSTRAINT `fk_servicos_status_servico` FOREIGN KEY (`codservico`) REFERENCES `servicos` (`codservico`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `servicos_auditoria` (
    `codauditoria` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codservico` BIGINT UNSIGNED NULL,
    `codusuario` BIGINT UNSIGNED NULL,
    `acao` VARCHAR(40) NOT NULL,
    `resultado` VARCHAR(20) NOT NULL,
    `mensagem` VARCHAR(1000) NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`codauditoria`), KEY `ix_servicos_auditoria_servico` (`codservico`), KEY `ix_servicos_auditoria_data` (`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `servicos` (`codigo`,`nome_pt`,`nome_en`,`nome_es`,`descricao_pt`,`descricao_en`,`descricao_es`,`host`,`porta`,`script`,`ordem`)
VALUES
 ('EXPORTACAO_GRID','Exportação Excel','Excel Export','Exportación Excel','Gera planilhas a partir das filas dos grids.','Generates spreadsheets from grid queues.','Genera hojas de cálculo a partir de las colas de grids.','127.0.0.1',8787,'services/ExportacaoGridService.php',1),
 ('INTELIGENCIA_ARTIFICIAL','Inteligência Artificial','Artificial Intelligence','Inteligencia Artificial','Processa análises e potenciais legais.','Processes legal analyses and potentials.','Procesa análisis y potenciales legales.','127.0.0.1',8788,'services/InteligenciaArtificialService.php',2)
ON DUPLICATE KEY UPDATE `nome_pt`=VALUES(`nome_pt`),`nome_en`=VALUES(`nome_en`),`nome_es`=VALUES(`nome_es`),`host`=VALUES(`host`),`porta`=VALUES(`porta`),`script`=VALUES(`script`),`ativo`=1;
INSERT IGNORE INTO `servicos_status` (`codservico`,`status`) SELECT `codservico`,'DESCONHECIDO' FROM `servicos`;

USE `gmodelo_dbp`;
INSERT INTO `menus` (`codmenu_pai`,`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
SELECT m.`codmenu`,'ADMINISTRACAO','ADMINISTRACAO_SERVICOS','Serviços','Serviços','Services','Servicios','/principal/servicos.php',2,4,1
FROM `menus` m WHERE m.`codigo`='ADMINISTRACAO'
AND NOT EXISTS (SELECT 1 FROM `menus` x WHERE x.`codigo`='ADMINISTRACAO_SERVICOS');
INSERT INTO `permissoes` (`codperfil`,`codmenu`,`pode_incluir`,`pode_visualizar`,`pode_editar`,`pode_excluir`,`pode_exportar_excel`,`pode_gerar_pdf`)
SELECT p.`codperfil`,m.`codmenu`,0,1,IF(p.`codigo`='ADMINISTRADOR',1,0),0,0,0
FROM `perfis` p CROSS JOIN `menus` m
WHERE m.`codigo`='ADMINISTRACAO_SERVICOS' AND p.`codigo` IN ('ADMINISTRADOR','ADM_DA_EMPRESA')
AND NOT EXISTS (SELECT 1 FROM `permissoes` x WHERE x.`codperfil`=p.`codperfil` AND x.`codmenu`=m.`codmenu`);
