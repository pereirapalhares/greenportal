-- Green Portal - Ajuda multilíngue por transação.

CREATE DATABASE IF NOT EXISTS `guniversal_dbp` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE TABLE IF NOT EXISTS `guniversal_dbp`.`ajuda_transacoes` (
    `codajudatransacao` int NOT NULL AUTO_INCREMENT,
    `transacao` varchar(150) NOT NULL,
    `titulo_pt` varchar(255) NOT NULL DEFAULT 'Ajuda',
    `titulo_en` varchar(255) NOT NULL DEFAULT 'Help',
    `titulo_es` varchar(255) NOT NULL DEFAULT 'Ayuda',
    `texto_pt` mediumtext NOT NULL,
    `texto_en` mediumtext NOT NULL,
    `texto_es` mediumtext NOT NULL,
    `ativo` tinyint(1) NOT NULL DEFAULT 1,
    `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`codajudatransacao`),
    UNIQUE KEY `uk_ajuda_transacoes_transacao` (`transacao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `guniversal_dbp`.`ajuda_transacoes` (`transacao`,`titulo_pt`,`titulo_en`,`titulo_es`,`texto_pt`,`texto_en`,`texto_es`)
VALUES
('manutencaolegal_legislacao','Ajuda — Legislação','Help — Legislation','Ayuda — Legislación','<p>Cadastre e mantenha as legislações do sistema. Use a busca e os filtros das colunas para localizar uma lei.</p><p>O ícone ao lado do código abre o texto da lei.</p>','<p>Maintain the system legislation records. Use search and column filters to find a law.</p><p>The icon beside the code opens the law text.</p>','<p>Mantenga las legislaciones del sistema. Use la búsqueda y los filtros para localizar una ley.</p><p>El icono junto al código abre el texto de la ley.</p>'),
('manutencaolegal_legislacaoxempresa','Ajuda — Legislação por unidade','Help — Legislation by unit','Ayuda — Legislación por unidad','<p>Relacione legislações às empresas e unidades. Os campos técnicos identificam as chaves da relação.</p>','<p>Relate legislation to companies and units. Technical fields identify the relationship keys.</p>','<p>Relacione legislaciones con empresas y unidades. Los campos técnicos identifican las claves.</p>'),
('manutencaolegal_legislacaoxpoder','Ajuda — Nível de controle','Help — Control level','Ayuda — Nivel de control','<p>Consulte e altere o nível de controle da legislação por unidade.</p>','<p>View and change the legislation control level by unit.</p>','<p>Consulte y cambie el nivel de control de la legislación por unidad.</p>'),
('modulolegal_requisitoslegais','Ajuda — Requisitos legais','Help — Legal requirements','Ayuda — Requisitos legales','<p>Consulte os requisitos legais vinculados às unidades.</p><ul><li>Selecione uma linha para habilitar os botões contextuais.</li><li>Use os filtros de responsável e lei revogada.</li><li>O nível de controle pode ser confirmado conforme sua permissão.</li></ul>','<p>View legal requirements linked to units.</p><ul><li>Select a row to enable contextual buttons.</li><li>Use responsible and repealed-law filters.</li><li>The control level can be confirmed according to your permission.</li></ul>','<p>Consulte los requisitos legales vinculados a las unidades.</p><ul><li>Seleccione una fila para habilitar los botones contextuales.</li><li>Use los filtros de responsable y ley derogada.</li><li>El nivel de control puede confirmarse según su permiso.</li></ul>'),
('modulolegal_laia','Ajuda — LAIA','Help — LAIA','Ayuda — LAIA','<p>Consulte e mantenha a avaliação de aspectos e impactos ambientais. Selecione uma linha para abrir os relacionamentos específicos.</p>','<p>View and maintain the environmental aspects and impacts assessment. Select a row to open specific relationships.</p>','<p>Consulte y mantenga la evaluación de aspectos e impactos ambientales. Seleccione una fila para abrir las relaciones.</p>'),
('modulolegal_lpro','Ajuda — LPRO','Help — LPRO','Ayuda — LPRO','<p>Consulte e mantenha o levantamento de perigos e riscos ocupacionais. Os botões contextuais usam a linha selecionada.</p>','<p>View and maintain occupational hazards and risks. Contextual buttons use the selected row.</p>','<p>Consulte y mantenga los peligros y riesgos ocupacionales. Los botones usan la fila seleccionada.</p>'),
('modulolegal_lais','Ajuda — LAIS','Help — LAIS','Ayuda — LAIS','<p>Consulte e mantenha a avaliação de aspectos e impactos sociais. Selecione uma linha para abrir os relacionamentos.</p>','<p>View and maintain social aspects and impacts. Select a row to open relationships.</p>','<p>Consulte y mantenga los aspectos e impactos sociales. Seleccione una fila para abrir las relaciones.</p>'),
('modulolegal_situacaocumprimento','Ajuda — Situação quanto ao cumprimento','Help — Compliance status','Ayuda — Situación de cumplimiento','<p>Consulte a situação de atendimento das legislações por unidade e responsável.</p>','<p>View legislation compliance status by unit and responsible user.</p>','<p>Consulte el estado de cumplimiento por unidad y responsable.</p>'),
('modulolegal_correlacionamnetoambiental','Ajuda — Correlacionamento Ambiental','Help — Environmental correlation','Ayuda — Correlacionamiento ambiental','<p>Consulte as legislações ambientais relacionadas às unidades e aos níveis de controle.</p>','<p>View environmental legislation related to units and control levels.</p>','<p>Consulte las legislaciones ambientales relacionadas con unidades y niveles de control.</p>'),
('manutencaolegalrequisitoslegais_situacao','Ajuda — Situação da legislação','Help — Legislation status','Ayuda — Situación de la legislación','<p>Consulte e mantenha as situações da legislação selecionada para a unidade.</p>','<p>View and maintain the selected legislation status for the unit.</p>','<p>Consulte y mantenga la situación de la legislación seleccionada para la unidad.</p>'),
('manutencaolegalrequisitoslegais_licencas','Ajuda — Documentos licenciatórios','Help — Licensing documents','Ayuda — Documentos de licencias','<p>Consulte as licenças relacionadas à legislação e unidade selecionadas.</p>','<p>View licenses related to the selected legislation and unit.</p>','<p>Consulte las licencias relacionadas con la legislación y unidad seleccionadas.</p>'),
('manutencaolegalrequisitoslegais_controles','Ajuda — Controles','Help — Controls','Ayuda — Controles','<p>Consulte os documentos e controles relacionados à legislação e unidade selecionadas.</p>','<p>View documents and controls related to the selected legislation and unit.</p>','<p>Consulte los documentos y controles relacionados con la legislación y unidad seleccionadas.</p>'),
('manutencaolegalrequisitoslegais_medicaomonitoramento','Ajuda — Medição e Monitoramento','Help — Measurement and monitoring','Ayuda — Medición y monitoreo','<p>Consulte as medições e monitoramentos relacionados à legislação e unidade selecionadas.</p>','<p>View measurements and monitoring related to the selected legislation and unit.</p>','<p>Consulte las mediciones y monitoreos relacionados con la legislación y unidad seleccionadas.</p>'),
('manutencaolegalrequisitoslegais_checklist','Ajuda — Checklist','Help — Checklist','Ayuda — Checklist','<p>Consulte os itens de checklist relacionados à legislação e unidade selecionadas.</p>','<p>View checklist items related to the selected legislation and unit.</p>','<p>Consulte los elementos del checklist relacionados con la legislación y unidad seleccionadas.</p>'),
('manutencaolegalrequisitoslegais_alteracoes','Ajuda — Alterações na legislação','Help — Legislation changes','Ayuda — Cambios en la legislación','<p>Consulte o histórico de alterações da legislação selecionada.</p>','<p>View the change history of the selected legislation.</p>','<p>Consulte el historial de cambios de la legislación seleccionada.</p>')
ON DUPLICATE KEY UPDATE titulo_pt=VALUES(titulo_pt),titulo_en=VALUES(titulo_en),titulo_es=VALUES(titulo_es),texto_pt=VALUES(texto_pt),texto_en=VALUES(texto_en),texto_es=VALUES(texto_es),ativo=1;

USE `gmodelo_dbp`;
INSERT INTO `menus` (`codmenu_pai`,`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
SELECT p.`codmenu`,'SETUP','CONFIG_AJUDA_TRANSACOES','Ajuda das transações','Ajuda das transações','Transaction help','Ayuda de las transacciones','/principal/configuracoes_ajuda.php',2,4,1
FROM `menus` p WHERE p.`codigo`='SETUP' AND NOT EXISTS (SELECT 1 FROM `menus` m WHERE m.`codigo`='CONFIG_AJUDA_TRANSACOES');
INSERT INTO `permissoes` (`codperfil`,`codmenu`,`pode_incluir`,`pode_visualizar`,`pode_editar`,`pode_excluir`,`pode_exportar_excel`,`pode_gerar_pdf`)
SELECT p.`codperfil`,m.`codmenu`,0,IF(p.`codigo`='ADMINISTRADOR',1,0),0,IF(p.`codigo`='ADMINISTRADOR',1,0),0,0 FROM `perfis` p CROSS JOIN `menus` m
WHERE p.`ativo`=1 AND m.`codigo`='CONFIG_AJUDA_TRANSACOES' AND NOT EXISTS (SELECT 1 FROM `permissoes` x WHERE x.`codperfil`=p.`codperfil` AND x.`codmenu`=m.`codmenu`);
UPDATE `permissoes` p INNER JOIN `menus` m ON m.`codmenu`=p.`codmenu` INNER JOIN `perfis` f ON f.`codperfil`=p.`codperfil` SET p.`pode_editar`=IF(f.`codigo`='ADMINISTRADOR',1,p.`pode_editar`) WHERE m.`codigo`='CONFIG_AJUDA_TRANSACOES';
INSERT INTO `registros_migracao` (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('AJUDA_TRANSACOES_066','green_portal.transacoes','guniversal_dbp.ajuda_transacoes','ESTRUTURA','1.0.0','CONCLUIDA',(SELECT COUNT(*) FROM `guniversal_dbp`.`ajuda_transacoes`),CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,JSON_OBJECT('multidioma',TRUE,'html_basico',TRUE,'links_externos',TRUE,'menu','CONFIG_AJUDA_TRANSACOES'))
ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
