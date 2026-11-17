USE `gmodelo_dbp`;
SET NAMES utf8mb4;

CREATE OR REPLACE VIEW `vw_minhas_infraestrutura_grid` AS
SELECT DISTINCT i.`CODINFRAESTRUTURA`, i.`DSCINFRAESTRUTURA`, g.`ARQUIVO`, g.`ATRIBUTOS`, g.`PALAVRAS_CHAVE`, a.`CODUSUARIO`
FROM `ml_infraestrutura` i
INNER JOIN `vw_modulolegal_infraestrutura_grid` g ON g.`CODINFRAESTRUTURA` = i.`CODINFRAESTRUTURA`
INNER JOIN `responsabilidade_atribuicoes` a ON a.`ENTIDADE`='ml_infraestrutura'
 AND a.`REGISTRO_ID`=CAST(i.`CODINFRAESTRUTURA` AS CHAR) AND a.`TIPO_DESTINO`='USUARIO'
 AND a.`CODUSUARIO` IS NOT NULL AND a.`ATIVO`=1 AND a.`VALIDO_DE`<=CURRENT_DATE
 AND (a.`VALIDO_ATE` IS NULL OR a.`VALIDO_ATE`>=CURRENT_DATE)
UNION
SELECT DISTINCT i.`CODINFRAESTRUTURA`, i.`DSCINFRAESTRUTURA`, g.`ARQUIVO`, g.`ATRIBUTOS`, g.`PALAVRAS_CHAVE`, gu.`CODUSUARIO`
FROM `ml_infraestrutura` i
INNER JOIN `vw_modulolegal_infraestrutura_grid` g ON g.`CODINFRAESTRUTURA` = i.`CODINFRAESTRUTURA`
INNER JOIN `responsabilidade_atribuicoes` a ON a.`ENTIDADE`='ml_infraestrutura'
 AND a.`REGISTRO_ID`=CAST(i.`CODINFRAESTRUTURA` AS CHAR) AND a.`TIPO_DESTINO`='GRUPO'
 AND a.`CODGRUPO` IS NOT NULL AND a.`ATIVO`=1 AND a.`VALIDO_DE`<=CURRENT_DATE
 AND (a.`VALIDO_ATE` IS NULL OR a.`VALIDO_ATE`>=CURRENT_DATE)
INNER JOIN `responsabilidade_grupoxusuario` gu ON gu.`CODGRUPO`=a.`CODGRUPO` AND gu.`ATIVO`=1;

INSERT INTO `menus` (`codmenu_pai`,`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
SELECT p.`codmenu`,'MODULO_LEGAL','MINHAS_INFRAESTRUTURAS','Minhas Infraestruturas','Minhas Infraestruturas','My Infrastructure','Mis Infraestructuras','/principal/minhas_infraestruturas.php',2,40,1
FROM `menus` p WHERE p.`codigo`='MODULO_LEGAL' AND NOT EXISTS (SELECT 1 FROM `menus` m WHERE m.`codigo`='MINHAS_INFRAESTRUTURAS');
UPDATE `menus` SET `codmenu_pai`=(SELECT codmenu FROM (SELECT codmenu FROM menus WHERE codigo='MODULO_LEGAL') x),`modulo`='MODULO_LEGAL',`ordem`=40,`url`='/principal/minhas_infraestruturas.php',`ativo`=1 WHERE `codigo`='MINHAS_INFRAESTRUTURAS';

INSERT INTO `permissoes` (`codperfil`,`codmenu`,`pode_incluir`,`pode_visualizar`,`pode_visualizar_total`,`pode_editar`,`pode_excluir`,`pode_exportar_excel`,`pode_gerar_pdf`)
SELECT p.`codperfil`,m.`codmenu`,0,COALESCE(orig.`pode_visualizar`,0),0,COALESCE(orig.`pode_editar`,0),0,COALESCE(orig.`pode_exportar_excel`,0),0
FROM `perfis` p CROSS JOIN `menus` m LEFT JOIN `permissoes` orig ON orig.`codperfil`=p.`codperfil` AND orig.`codmenu`=(SELECT codmenu FROM menus WHERE codigo='MODULOLEGAL_INFRAESTRUTURA')
WHERE m.`codigo`='MINHAS_INFRAESTRUTURAS' AND NOT EXISTS (SELECT 1 FROM permissoes x WHERE x.codperfil=p.codperfil AND x.codmenu=m.codmenu);
