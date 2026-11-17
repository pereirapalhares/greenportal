-- Views usadas pela Home para apresentar pendências atribuídas diretamente,
-- por grupo ou pelo responsável legado. Executar em cada banco tenant.

CREATE OR REPLACE VIEW `vw_minhas_licencas_grid` AS
SELECT DISTINCT
    g.`CODLICENCA`, g.`CODUNIDADE`, g.`DSCUNIDADE`, g.`DSCLICENCA`,
    g.`DATA_VALIDADE`, g.`DATA_REACAO`, g.`DATA_PROTOCOLO`, g.`CODUSUARIO`,
    g.`RESPONSAVEL`, g.`ARQUIVOS`, g.`CODLISTA`, g.`EMPROTOCOLO`,
    g.`PROTOCOLADO_EM`, g.`PROTOCOLADO_NO_PRAZO`, g.`QTD_DIAS_EM_PROTOCOLO`,
    g.`CODSTATUS`, g.`OBSERVACAO`, g.`CODCLASSIFICACAO`, g.`CODFREQUENCIA`,
    g.`QTDDIAS`, g.`QTDDIAS2`
FROM `vw_licencas_grid` g
INNER JOIN `li_licencas` l ON l.`CODLICENCA` = g.`CODLICENCA`
WHERE g.`CODUSUARIO` IS NOT NULL
  AND COALESCE(l.`STATUS_VERSAO`, 'ATIVA' COLLATE utf8mb4_0900_ai_ci) = 'ATIVA' COLLATE utf8mb4_0900_ai_ci
UNION
SELECT DISTINCT
    g.`CODLICENCA`, g.`CODUNIDADE`, g.`DSCUNIDADE`, g.`DSCLICENCA`,
    g.`DATA_VALIDADE`, g.`DATA_REACAO`, g.`DATA_PROTOCOLO`, a.`CODUSUARIO`,
    g.`RESPONSAVEL`, g.`ARQUIVOS`, g.`CODLISTA`, g.`EMPROTOCOLO`,
    g.`PROTOCOLADO_EM`, g.`PROTOCOLADO_NO_PRAZO`, g.`QTD_DIAS_EM_PROTOCOLO`,
    g.`CODSTATUS`, g.`OBSERVACAO`, g.`CODCLASSIFICACAO`, g.`CODFREQUENCIA`,
    g.`QTDDIAS`, g.`QTDDIAS2`
FROM `vw_licencas_grid` g
INNER JOIN `li_licencas` l ON l.`CODLICENCA` = g.`CODLICENCA`
INNER JOIN `responsabilidade_atribuicoes` a
        ON a.`ENTIDADE` = 'li_licencas' COLLATE utf8mb4_0900_ai_ci
       AND a.`REGISTRO_ID` = CAST(g.`CODLICENCA` AS CHAR) COLLATE utf8mb4_0900_ai_ci
       AND a.`TIPO_DESTINO` = 'USUARIO' COLLATE utf8mb4_0900_ai_ci
       AND a.`CODUSUARIO` IS NOT NULL
       AND a.`ATIVO` = 1
       AND a.`VALIDO_DE` <= CURRENT_DATE
       AND (a.`VALIDO_ATE` IS NULL OR a.`VALIDO_ATE` >= CURRENT_DATE)
INNER JOIN `responsabilidade_modulos` rm
        ON rm.`CODMODULO` = a.`CODMODULO` AND rm.`CODIGO` = 'LICENCAS' COLLATE utf8mb4_0900_ai_ci
 WHERE COALESCE(l.`STATUS_VERSAO`, 'ATIVA' COLLATE utf8mb4_0900_ai_ci) = 'ATIVA' COLLATE utf8mb4_0900_ai_ci
UNION
SELECT DISTINCT
    g.`CODLICENCA`, g.`CODUNIDADE`, g.`DSCUNIDADE`, g.`DSCLICENCA`,
    g.`DATA_VALIDADE`, g.`DATA_REACAO`, g.`DATA_PROTOCOLO`, gu.`CODUSUARIO`,
    g.`RESPONSAVEL`, g.`ARQUIVOS`, g.`CODLISTA`, g.`EMPROTOCOLO`,
    g.`PROTOCOLADO_EM`, g.`PROTOCOLADO_NO_PRAZO`, g.`QTD_DIAS_EM_PROTOCOLO`,
    g.`CODSTATUS`, g.`OBSERVACAO`, g.`CODCLASSIFICACAO`, g.`CODFREQUENCIA`,
    g.`QTDDIAS`, g.`QTDDIAS2`
FROM `vw_licencas_grid` g
INNER JOIN `li_licencas` l ON l.`CODLICENCA` = g.`CODLICENCA`
INNER JOIN `responsabilidade_atribuicoes` a
        ON a.`ENTIDADE` = 'li_licencas' COLLATE utf8mb4_0900_ai_ci
       AND a.`REGISTRO_ID` = CAST(g.`CODLICENCA` AS CHAR) COLLATE utf8mb4_0900_ai_ci
       AND a.`TIPO_DESTINO` = 'GRUPO' COLLATE utf8mb4_0900_ai_ci
       AND a.`CODGRUPO` IS NOT NULL
       AND a.`ATIVO` = 1
       AND a.`VALIDO_DE` <= CURRENT_DATE
       AND (a.`VALIDO_ATE` IS NULL OR a.`VALIDO_ATE` >= CURRENT_DATE)
INNER JOIN `responsabilidade_modulos` rm
        ON rm.`CODMODULO` = a.`CODMODULO` AND rm.`CODIGO` = 'LICENCAS' COLLATE utf8mb4_0900_ai_ci
INNER JOIN `responsabilidade_grupoxusuario` gu
        ON gu.`CODGRUPO` = a.`CODGRUPO` AND gu.`ATIVO` = 1
 WHERE COALESCE(l.`STATUS_VERSAO`, 'ATIVA' COLLATE utf8mb4_0900_ai_ci) = 'ATIVA' COLLATE utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_minhas_monitoramentos_grid` AS
SELECT DISTINCT
    g.`CODMONITORAMENTO`, g.`PARAMETROS`, g.`DATA_MEDICAO`, g.`DATA_REACAO`,
    g.`CODUSUARIO`, g.`CODEQUIPAMENTO`, g.`OBS`, g.`CODAREA`, g.`CODASPECTO`,
    g.`CODPERIGO`, g.`CODUNIDADE`, g.`CODMEDICAO`, g.`cronograma`
FROM `vw_medicao_monitoramento_monitoramento_grid` g
WHERE g.`CODUSUARIO` IS NOT NULL
UNION
SELECT DISTINCT
    g.`CODMONITORAMENTO`, g.`PARAMETROS`, g.`DATA_MEDICAO`, g.`DATA_REACAO`,
    a.`CODUSUARIO`, g.`CODEQUIPAMENTO`, g.`OBS`, g.`CODAREA`, g.`CODASPECTO`,
    g.`CODPERIGO`, g.`CODUNIDADE`, g.`CODMEDICAO`, g.`cronograma`
FROM `vw_medicao_monitoramento_monitoramento_grid` g
INNER JOIN `responsabilidade_atribuicoes` a
        ON a.`ENTIDADE` = 'mm_monitoramento' COLLATE utf8mb4_0900_ai_ci
       AND a.`REGISTRO_ID` = CAST(g.`CODMONITORAMENTO` AS CHAR) COLLATE utf8mb4_0900_ai_ci
       AND a.`TIPO_DESTINO` = 'USUARIO' COLLATE utf8mb4_0900_ai_ci
       AND a.`CODUSUARIO` IS NOT NULL
       AND a.`ATIVO` = 1
       AND a.`VALIDO_DE` <= CURRENT_DATE
       AND (a.`VALIDO_ATE` IS NULL OR a.`VALIDO_ATE` >= CURRENT_DATE)
INNER JOIN `responsabilidade_modulos` rm
        ON rm.`CODMODULO` = a.`CODMODULO` AND rm.`CODIGO` = 'MEDICAO_MONITORAMENTO' COLLATE utf8mb4_0900_ai_ci
UNION
SELECT DISTINCT
    g.`CODMONITORAMENTO`, g.`PARAMETROS`, g.`DATA_MEDICAO`, g.`DATA_REACAO`,
    gu.`CODUSUARIO`, g.`CODEQUIPAMENTO`, g.`OBS`, g.`CODAREA`, g.`CODASPECTO`,
    g.`CODPERIGO`, g.`CODUNIDADE`, g.`CODMEDICAO`, g.`cronograma`
FROM `vw_medicao_monitoramento_monitoramento_grid` g
INNER JOIN `responsabilidade_atribuicoes` a
        ON a.`ENTIDADE` = 'mm_monitoramento' COLLATE utf8mb4_0900_ai_ci
       AND a.`REGISTRO_ID` = CAST(g.`CODMONITORAMENTO` AS CHAR) COLLATE utf8mb4_0900_ai_ci
       AND a.`TIPO_DESTINO` = 'GRUPO' COLLATE utf8mb4_0900_ai_ci
       AND a.`CODGRUPO` IS NOT NULL
       AND a.`ATIVO` = 1
       AND a.`VALIDO_DE` <= CURRENT_DATE
       AND (a.`VALIDO_ATE` IS NULL OR a.`VALIDO_ATE` >= CURRENT_DATE)
INNER JOIN `responsabilidade_modulos` rm
        ON rm.`CODMODULO` = a.`CODMODULO` AND rm.`CODIGO` = 'MEDICAO_MONITORAMENTO' COLLATE utf8mb4_0900_ai_ci
INNER JOIN `responsabilidade_grupoxusuario` gu
        ON gu.`CODGRUPO` = a.`CODGRUPO` AND gu.`ATIVO` = 1;

CREATE OR REPLACE VIEW `vw_minhas_treinamentos_grid` AS
SELECT DISTINCT t.`CODTREINAMENTO`, t.`DSCTREINAMENTO`, g.`ARQUIVO`, g.`PALAVRAS_CHAVE`, a.`CODUSUARIO`
FROM `ml_treinamentos` t
INNER JOIN `vw_modulolegal_treinamentos_grid` g ON g.`CODTREINAMENTO` = t.`CODTREINAMENTO`
INNER JOIN `responsabilidade_atribuicoes` a
        ON a.`ENTIDADE` = 'ml_treinamentos' COLLATE utf8mb4_0900_ai_ci
       AND a.`REGISTRO_ID` = CAST(t.`CODTREINAMENTO` AS CHAR) COLLATE utf8mb4_0900_ai_ci
       AND a.`TIPO_DESTINO` = 'USUARIO' COLLATE utf8mb4_0900_ai_ci
       AND a.`CODUSUARIO` IS NOT NULL AND a.`ATIVO` = 1
       AND a.`VALIDO_DE` <= CURRENT_DATE
       AND (a.`VALIDO_ATE` IS NULL OR a.`VALIDO_ATE` >= CURRENT_DATE)
UNION
SELECT DISTINCT t.`CODTREINAMENTO`, t.`DSCTREINAMENTO`, g.`ARQUIVO`, g.`PALAVRAS_CHAVE`, gu.`CODUSUARIO`
FROM `ml_treinamentos` t
INNER JOIN `vw_modulolegal_treinamentos_grid` g ON g.`CODTREINAMENTO` = t.`CODTREINAMENTO`
INNER JOIN `responsabilidade_atribuicoes` a
        ON a.`ENTIDADE` = 'ml_treinamentos' COLLATE utf8mb4_0900_ai_ci
       AND a.`REGISTRO_ID` = CAST(t.`CODTREINAMENTO` AS CHAR) COLLATE utf8mb4_0900_ai_ci
       AND a.`TIPO_DESTINO` = 'GRUPO' COLLATE utf8mb4_0900_ai_ci
       AND a.`CODGRUPO` IS NOT NULL AND a.`ATIVO` = 1
       AND a.`VALIDO_DE` <= CURRENT_DATE
       AND (a.`VALIDO_ATE` IS NULL OR a.`VALIDO_ATE` >= CURRENT_DATE)
INNER JOIN `responsabilidade_grupoxusuario` gu
        ON gu.`CODGRUPO` = a.`CODGRUPO` AND gu.`ATIVO` = 1;

CREATE OR REPLACE VIEW `vw_minhas_infraestrutura_grid` AS
SELECT DISTINCT i.`CODINFRAESTRUTURA`, i.`DSCINFRAESTRUTURA`, g.`ARQUIVO`, g.`ATRIBUTOS`, g.`PALAVRAS_CHAVE`, a.`CODUSUARIO`
FROM `ml_infraestrutura` i
INNER JOIN `vw_modulolegal_infraestrutura_grid` g ON g.`CODINFRAESTRUTURA` = i.`CODINFRAESTRUTURA`
INNER JOIN `responsabilidade_atribuicoes` a
        ON a.`ENTIDADE` = 'ml_infraestrutura' COLLATE utf8mb4_0900_ai_ci
       AND a.`REGISTRO_ID` = CAST(i.`CODINFRAESTRUTURA` AS CHAR) COLLATE utf8mb4_0900_ai_ci
       AND a.`TIPO_DESTINO` = 'USUARIO' COLLATE utf8mb4_0900_ai_ci
       AND a.`CODUSUARIO` IS NOT NULL AND a.`ATIVO` = 1
       AND a.`VALIDO_DE` <= CURRENT_DATE
       AND (a.`VALIDO_ATE` IS NULL OR a.`VALIDO_ATE` >= CURRENT_DATE)
UNION
SELECT DISTINCT i.`CODINFRAESTRUTURA`, i.`DSCINFRAESTRUTURA`, g.`ARQUIVO`, g.`ATRIBUTOS`, g.`PALAVRAS_CHAVE`, gu.`CODUSUARIO`
FROM `ml_infraestrutura` i
INNER JOIN `vw_modulolegal_infraestrutura_grid` g ON g.`CODINFRAESTRUTURA` = i.`CODINFRAESTRUTURA`
INNER JOIN `responsabilidade_atribuicoes` a
        ON a.`ENTIDADE` = 'ml_infraestrutura' COLLATE utf8mb4_0900_ai_ci
       AND a.`REGISTRO_ID` = CAST(i.`CODINFRAESTRUTURA` AS CHAR) COLLATE utf8mb4_0900_ai_ci
       AND a.`TIPO_DESTINO` = 'GRUPO' COLLATE utf8mb4_0900_ai_ci
       AND a.`CODGRUPO` IS NOT NULL AND a.`ATIVO` = 1
       AND a.`VALIDO_DE` <= CURRENT_DATE
       AND (a.`VALIDO_ATE` IS NULL OR a.`VALIDO_ATE` >= CURRENT_DATE)
INNER JOIN `responsabilidade_grupoxusuario` gu
        ON gu.`CODGRUPO` = a.`CODGRUPO` AND gu.`ATIVO` = 1;

CREATE OR REPLACE VIEW `vw_meus_controles_grid` AS
SELECT DISTINCT d.`CODDOCUMENTO`, d.`CODIGO`, d.`TITULO`, g.`ARQUIVO`, g.`UNIDADES`, g.`ATIVIDADES`, g.`ASPECTOS`, g.`IMPACTOS`, g.`PERIGOS`, g.`RISCOS`, g.`PALAVRAS_CHAVES`, g.`RESPONSAVEIS`, g.`ASPECTOS_SOCIAIS`, g.`IMPACTOS_SOCIAIS`, a.`CODUSUARIO`
FROM `documentos` d
INNER JOIN `vw_controle_documentos_grid` g ON g.`CODDOCUMENTO` = d.`CODDOCUMENTO`
INNER JOIN `responsabilidade_atribuicoes` a
        ON a.`ENTIDADE` = 'documentos' COLLATE utf8mb4_0900_ai_ci
       AND a.`REGISTRO_ID` = CAST(d.`CODDOCUMENTO` AS CHAR) COLLATE utf8mb4_0900_ai_ci
       AND a.`TIPO_DESTINO` = 'USUARIO' COLLATE utf8mb4_0900_ai_ci
       AND a.`CODUSUARIO` IS NOT NULL AND a.`ATIVO` = 1
       AND a.`VALIDO_DE` <= CURRENT_DATE
       AND (a.`VALIDO_ATE` IS NULL OR a.`VALIDO_ATE` >= CURRENT_DATE)
UNION
SELECT DISTINCT d.`CODDOCUMENTO`, d.`CODIGO`, d.`TITULO`, g.`ARQUIVO`, g.`UNIDADES`, g.`ATIVIDADES`, g.`ASPECTOS`, g.`IMPACTOS`, g.`PERIGOS`, g.`RISCOS`, g.`PALAVRAS_CHAVES`, g.`RESPONSAVEIS`, g.`ASPECTOS_SOCIAIS`, g.`IMPACTOS_SOCIAIS`, gu.`CODUSUARIO`
FROM `documentos` d
INNER JOIN `vw_controle_documentos_grid` g ON g.`CODDOCUMENTO` = d.`CODDOCUMENTO`
INNER JOIN `responsabilidade_atribuicoes` a
        ON a.`ENTIDADE` = 'documentos' COLLATE utf8mb4_0900_ai_ci
       AND a.`REGISTRO_ID` = CAST(d.`CODDOCUMENTO` AS CHAR) COLLATE utf8mb4_0900_ai_ci
       AND a.`TIPO_DESTINO` = 'GRUPO' COLLATE utf8mb4_0900_ai_ci
       AND a.`CODGRUPO` IS NOT NULL AND a.`ATIVO` = 1
       AND a.`VALIDO_DE` <= CURRENT_DATE
       AND (a.`VALIDO_ATE` IS NULL OR a.`VALIDO_ATE` >= CURRENT_DATE)
INNER JOIN `responsabilidade_grupoxusuario` gu
        ON gu.`CODGRUPO` = a.`CODGRUPO` AND gu.`ATIVO` = 1;
