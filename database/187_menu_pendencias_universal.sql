-- Menu da consulta transversal de pendências.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
INSERT INTO `menus` (`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
VALUES ('SEGURANCA','PAINEL_PENDENCIAS_UNIVERSAL','Pendências universais','Pendências universais','Universal pending items','Pendientes universales','/principal/painel_pendencias_universal.php',2,91,1)
ON DUPLICATE KEY UPDATE `url`=VALUES(`url`),`label`=VALUES(`label`),`label_pt`=VALUES(`label_pt`),`label_en`=VALUES(`label_en`),`label_es`=VALUES(`label_es`),`ativo`=1;
UPDATE `menus` child JOIN `menus` parent ON parent.`codigo`='SEGURANCA' SET child.`codmenu_pai`=parent.`codmenu`,child.`nivel`=2 WHERE child.`codigo`='PAINEL_PENDENCIAS_UNIVERSAL';
INSERT INTO `permissoes` (`codperfil`,`codmenu`,`pode_incluir`,`pode_visualizar`,`pode_editar`,`pode_excluir`,`pode_exportar_excel`,`pode_gerar_pdf`)
SELECT p.`codperfil`,m.`codmenu`,0,1,0,0,0,0 FROM `perfis` p CROSS JOIN `menus` m WHERE p.`codigo`='ADMINISTRADOR' AND m.`codigo`='PAINEL_PENDENCIAS_UNIVERSAL'
ON DUPLICATE KEY UPDATE `pode_visualizar`=VALUES(`pode_visualizar`);
INSERT INTO `registros_migracao` (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('GREEN_PENDENCIAS_187','PendenciasService','PAINEL_PENDENCIAS_UNIVERSAL','CONFIGURACAO','4.0.1','CONCLUIDA',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,JSON_OBJECT('fontes',JSON_ARRAY('green_tasks','agenda_eventos','vw_responsabilidade_efetiva','alertas')))
ON DUPLICATE KEY UPDATE `status`=VALUES(`status`),`concluida_em`=VALUES(`concluida_em`),`detalhes`=VALUES(`detalhes`);
