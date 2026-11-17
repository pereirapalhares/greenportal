-- Menu e permissão da transação de substituições de responsabilidades.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

INSERT INTO `menus` (`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
VALUES ('SEGURANCA','RESPONSABILIDADE_SUBSTITUICAO','Substituições de responsabilidades','Substituições de responsabilidades','Responsibility substitutions','Sustituciones de responsabilidades','/principal/responsabilidade_substituicao.php',2,90,1)
ON DUPLICATE KEY UPDATE `url`=VALUES(`url`),`label`=VALUES(`label`),`label_pt`=VALUES(`label_pt`),`label_en`=VALUES(`label_en`),`label_es`=VALUES(`label_es`),`ativo`=1;
UPDATE `menus` child JOIN `menus` parent ON parent.`codigo`='SEGURANCA'
   SET child.`codmenu_pai`=parent.`codmenu`,child.`nivel`=2
 WHERE child.`codigo`='RESPONSABILIDADE_SUBSTITUICAO';

INSERT INTO `permissoes` (`codperfil`,`codmenu`,`pode_incluir`,`pode_visualizar`,`pode_editar`,`pode_excluir`,`pode_exportar_excel`,`pode_gerar_pdf`)
SELECT p.`codperfil`,m.`codmenu`,1,1,1,0,0,0
  FROM `perfis` p CROSS JOIN `menus` m
 WHERE p.`codigo`='ADMINISTRADOR' AND m.`codigo`='RESPONSABILIDADE_SUBSTITUICAO'
ON DUPLICATE KEY UPDATE `pode_incluir`=VALUES(`pode_incluir`),`pode_visualizar`=VALUES(`pode_visualizar`),`pode_editar`=VALUES(`pode_editar`);

INSERT INTO `registros_migracao` (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('GREEN_RESPONSIBILITY_185','ResponsibilityService','RESPONSABILIDADE_SUBSTITUICAO','CONFIGURACAO','4.0.1','CONCLUIDA',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,JSON_OBJECT('url','/principal/responsabilidade_substituicao.php','perfil_inicial','ADMINISTRADOR'))
ON DUPLICATE KEY UPDATE `status`=VALUES(`status`),`concluida_em`=VALUES(`concluida_em`),`detalhes`=VALUES(`detalhes`);
