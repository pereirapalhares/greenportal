-- Tela, endpoint e trilha de aprovação da Nova Ocorrência da Empresa.
USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE TABLE IF NOT EXISTS `nc_workflow_historico` (
  `CODHISTORICO` bigint unsigned NOT NULL AUTO_INCREMENT,
  `CODOCORRENCIA` int NOT NULL,
  `ACAO` varchar(50) NOT NULL,
  `ETAPA_ANTERIOR` varchar(500) DEFAULT NULL,
  `ETAPA_NOVA` varchar(500) DEFAULT NULL,
  `CODUSUARIO` bigint unsigned NOT NULL,
  `MOTIVO` text,
  `CRIADO_EM` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODHISTORICO`), KEY `ix_nc_workflow_ocorrencia` (`CODOCORRENCIA`), KEY `ix_nc_workflow_usuario` (`CODUSUARIO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
INSERT INTO `menus` (`codmenu_pai`,`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
SELECT m.codmenu,'NAO_CONFORMIDADES','NAO_CONFORMIDADES_NOVA_OCORRENCIA_EMPRESA',
       'Nova ocorrência da empresa','Nova ocorrência da empresa','New company occurrence','Nueva ocurrencia de la empresa',
       '/principal/naoconformidades_novaocorrenciaempresa.php?v=20260821-2',2,20,1 FROM menus m WHERE m.codigo='NAO_CONFORMIDADES'
ON DUPLICATE KEY UPDATE codmenu_pai=VALUES(codmenu_pai),url=VALUES(url),label=VALUES(label),label_pt=VALUES(label_pt),label_en=VALUES(label_en),label_es=VALUES(label_es),ativo=1;
INSERT INTO permissoes (codperfil,codmenu,pode_incluir,pode_visualizar,pode_editar,pode_excluir,pode_exportar_excel,pode_gerar_pdf)
SELECT p.codperfil,m.codmenu,1,1,1,0,0,0 FROM perfis p CROSS JOIN menus m WHERE m.codigo='NAO_CONFORMIDADES_NOVA_OCORRENCIA_EMPRESA'
ON DUPLICATE KEY UPDATE pode_incluir=VALUES(pode_incluir),pode_visualizar=VALUES(pode_visualizar),pode_editar=VALUES(pode_editar);
