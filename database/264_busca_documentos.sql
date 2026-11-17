-- Migração 264 — Pesquisa de Documentos (busca universal), Fase 1: cadastros e nomes de arquivos.
-- Aplicado por scripts/aplicar-migracao-264-busca-documentos.php em gatlas_dbp e gmodelo_dbp.
-- Pré-requisitos: views dos grids (licenças, controles, requisitos, treinamentos, infraestrutura) e migrações 260, 262 e 263.
--
-- busca_indice: um item por registro (item REGISTRO) e por arquivo do GED do registro (item ARQUIVO), com os dados que a tela
--   de pesquisa mostra, a transação de destino (pagina + filtros) e a regra de acesso (escopo):
--     NENHUM  = quem tem Visualizar na transação vê;
--     USUARIO = sem "Visualizar total", só os itens em que o usuário é o responsável (codusuario) — Licenças;
--     UNIDADE = só as unidades do usuário (vw_portaria_usuario_unidades), ADMINISTRADOR vê tudo — Portaria.
-- vw_busca_fonte_registros: o que deve estar no índice (um SELECT por módulo, a partir das views dos grids).
-- Serviço de indexação (scripts/busca-worker.php, a cada 10 minutos):
--   sp_busca_indexar_registros           grava só o que mudou (hash), remove o que sumiu, atualiza os arquivos do registro;
--   sp_busca_sincronizar_arquivos(tipo)  recebe a lista de arquivos das pastas do GED e sincroniza os itens ARQUIVO.
-- Pesquisa: sp_busca_pesquisar (lista de itens) e sp_busca_pesquisar_negocios (resultados agrupados pelo negócio: o cadastro
--   — licença, controle, requisito, treinamento, infraestrutura — ou, na Portaria, o fornecedor / funcionário; com os
--   arquivos e os vínculos Requisito legal ↔ evidências), já com as permissões.
-- Grupo (negócio) de cada item: grupo_tipo + grupo_chave (+ grupo_nome, grupo_detalhe).
-- Vínculos entre negócios (qualquer módulo): catálogo busca_relacao (uma linha por relação: lados A e B, rótulos de cada lado,
--   ícones, ordem e a view de origem vw_busca_rel_* com chave_a / chave_b = grupo_chave dos dois lados). O serviço grava os pares
--   em busca_vinculo (sp_busca_indexar_vinculos). Relações iniciais (views montadas pelo script com as tabelas que existem):
--   Requisito legal ↔ Licença / Controle de documentos / Treinamento / Infraestrutura. Módulo novo: criar a view vw_busca_rel_<nome>
--   e incluir a linha em busca_relacao — a pesquisa mostra a seção nos dois lados, sem mudar tela nem procedure.
-- Dicionário da pesquisa (264_busca_dicionario.sql: busca_dicionario aqui e em {{UNIVERSAL}}): vw_busca_dicionario junta os dois;
--   sp_busca_termos expande cada termo pesquisado com o significado e os sinônimos aprovados (ASO → "Atestado de Saúde
--   Ocupacional"), sigla vale só como palavra inteira, e os itens do contexto do termo sobem na ordem. O serviço sugere termos
--   novos a partir dos cadastros ("SIGLA - descrição" e "descrição (SIGLA)") — sp_busca_dicionario_sugerir.
-- Requisitos legais (ModuloLegalRequisitosGrid) recebem filtros com valor simples; as outras telas, {"value","exact"}.
-- Parâmetros e variáveis de texto com collation explícita (o banco do cliente pode ter outra collation padrão).
-- Comandos separados por "-- @@".

-- @@
CREATE TABLE IF NOT EXISTS `busca_indice` (
    `codbusca` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tipo` VARCHAR(40) NOT NULL COMMENT 'licencas, controles, requisitos, treinamentos, infraestrutura, fornecedorcriterios, funcionariocriterios, funcionariotreinamentos',
    `item` VARCHAR(10) NOT NULL COMMENT 'REGISTRO ou ARQUIVO',
    `chave` VARCHAR(100) NOT NULL COMMENT 'Chave do registro na tela de destino',
    `arquivo` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Nome do arquivo (vazio no item REGISTRO)',
    `nome` VARCHAR(500) NOT NULL DEFAULT '',
    `titulo` VARCHAR(500) NULL,
    `descricao` VARCHAR(1000) NULL,
    `texto` MEDIUMTEXT NULL,
    `situacao` VARCHAR(100) NULL,
    `situacao_cor` VARCHAR(30) NULL,
    `validade` DATE NULL,
    `codunidade` INT NULL,
    `unidade` VARCHAR(500) NULL,
    `codusuario` INT NULL,
    `formato` VARCHAR(20) NOT NULL DEFAULT 'registro',
    `tamanho` BIGINT NULL,
    `modificado_em` DATETIME NULL,
    `transacao` VARCHAR(80) NOT NULL,
    `pagina` VARCHAR(200) NOT NULL,
    `filtros` JSON NULL,
    `ged_tipo` VARCHAR(40) NULL,
    `ged_valor` BIGINT NULL,
    `pasta` BIGINT NULL COMMENT 'Pasta do GED do registro (licenças: CODLICENCA_BASE)',
    `escopo` VARCHAR(10) NOT NULL DEFAULT 'NENHUM',
    `hash` CHAR(40) NULL COMMENT 'Impressão dos dados do registro',
    `hash_registro` CHAR(40) NULL COMMENT 'Item ARQUIVO: hash do registro copiado',
    `grupo_tipo` VARCHAR(40) NULL COMMENT 'Negócio do item: o próprio cadastro ou, na Portaria, fornecedor / funcionario',
    `grupo_chave` VARCHAR(100) NULL,
    `grupo_nome` VARCHAR(500) NULL,
    `grupo_detalhe` VARCHAR(500) NULL COMMENT 'Funcionário: fornecedor',
    `ref_legislacao` INT NULL COMMENT 'Requisitos: CODLEGISLACAO (vínculos)',
    `ref_unidade` INT NULL COMMENT 'Requisitos: CODUNIDADE (vínculos)',
    `responsavel` VARCHAR(500) NULL COMMENT 'Responsável(is) pelo item, como aparece na tela de origem',
    `norm` MEDIUMBLOB NULL COMMENT 'nome + título + descrição + texto em minúsculas, sem acento, pontuação = espaço (conferência rápida dos termos)',
    `indexado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`codbusca`),
    UNIQUE KEY `uk_busca_item` (`tipo`, `item`, `chave`, `arquivo`),
    KEY `idx_busca_tipo_pasta` (`tipo`, `item`, `pasta`),
    KEY `idx_busca_transacao` (`transacao`),
    KEY `idx_busca_grupo` (`grupo_tipo`, `grupo_chave`),
    KEY `idx_busca_ref` (`tipo`, `item`, `ref_legislacao`),
    FULLTEXT KEY `ft_busca` (`nome`, `titulo`, `descricao`, `texto`) WITH PARSER ngram
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci

-- @@
DROP PROCEDURE IF EXISTS `sp_busca_migrar_colunas`

-- @@
-- Índice criado antes dos grupos/vínculos: acrescenta as colunas e os índices que faltam (pode rodar mais de uma vez).
CREATE PROCEDURE `sp_busca_migrar_colunas`()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'busca_indice' AND COLUMN_NAME = 'grupo_tipo') THEN
        ALTER TABLE `busca_indice`
            ADD COLUMN `grupo_tipo` VARCHAR(40) NULL AFTER `hash_registro`, ADD COLUMN `grupo_chave` VARCHAR(100) NULL AFTER `grupo_tipo`,
            ADD COLUMN `grupo_nome` VARCHAR(500) NULL AFTER `grupo_chave`, ADD COLUMN `grupo_detalhe` VARCHAR(500) NULL AFTER `grupo_nome`,
            ADD COLUMN `ref_legislacao` INT NULL AFTER `grupo_detalhe`, ADD COLUMN `ref_unidade` INT NULL AFTER `ref_legislacao`,
            ADD KEY `idx_busca_grupo` (`grupo_tipo`, `grupo_chave`), ADD KEY `idx_busca_ref` (`tipo`, `item`, `ref_legislacao`);
        -- força regravar tudo na próxima indexação (o hash passa a incluir o grupo)
        UPDATE `busca_indice` SET `hash` = NULL, `hash_registro` = NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'busca_indice' AND COLUMN_NAME = 'norm') THEN
        ALTER TABLE `busca_indice` ADD COLUMN `norm` MEDIUMBLOB NULL AFTER `texto`;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'busca_indice' AND COLUMN_NAME = 'responsavel') THEN
        ALTER TABLE `busca_indice` ADD COLUMN `responsavel` VARCHAR(500) NULL AFTER `ref_unidade`;
        UPDATE `busca_indice` SET `hash` = NULL, `hash_registro` = NULL;
    END IF;
    -- busca_vinculo da versão sem catálogo (sem a coluna relacao): é só cache, recriada e regravada pelo serviço
    IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'busca_vinculo')
       AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'busca_vinculo' AND COLUMN_NAME = 'relacao') THEN
        DROP TABLE `busca_vinculo`;
    END IF;
END

-- @@
CALL `sp_busca_migrar_colunas`()

-- @@
DROP PROCEDURE IF EXISTS `sp_busca_migrar_colunas`

-- @@
DROP VIEW IF EXISTS `vw_busca_fonte_vinculos`

-- @@
-- Catálogo das relações entre negócios. rotulo_a_* = título da seção no negócio do lado A (lista os do lado B); rotulo_b_* = no lado B.
-- fonte = view vw_busca_rel_* com as colunas chave_a e chave_b (grupo_chave dos dois lados). ativo = 0 desliga a relação.
CREATE TABLE IF NOT EXISTS `busca_relacao` (
    `codigo` VARCHAR(40) NOT NULL,
    `tipo_a` VARCHAR(40) NOT NULL COMMENT 'grupo_tipo do lado A (licencas, requisitos, fornecedor, funcionario, ...)',
    `tipo_b` VARCHAR(40) NOT NULL,
    `rotulo_a_pt` VARCHAR(150) NOT NULL, `rotulo_a_en` VARCHAR(150) NULL, `rotulo_a_es` VARCHAR(150) NULL,
    `rotulo_b_pt` VARCHAR(150) NOT NULL, `rotulo_b_en` VARCHAR(150) NULL, `rotulo_b_es` VARCHAR(150) NULL,
    `icone_a` VARCHAR(60) NULL COMMENT 'ícone (bootstrap-icons) da seção no lado A', `icone_b` VARCHAR(60) NULL,
    `fonte` VARCHAR(64) NOT NULL COMMENT 'view vw_busca_rel_* (chave_a, chave_b)',
    `ordem` INT NOT NULL DEFAULT 100,
    `ativo` TINYINT NOT NULL DEFAULT 1,
    PRIMARY KEY (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci

-- @@
-- Pares de negócios vinculados (gravado pelo serviço a partir do catálogo).
CREATE TABLE IF NOT EXISTS `busca_vinculo` (
    `relacao` VARCHAR(40) NOT NULL,
    `tipo_a` VARCHAR(40) NOT NULL,
    `chave_a` VARCHAR(100) NOT NULL,
    `tipo_b` VARCHAR(40) NOT NULL,
    `chave_b` VARCHAR(100) NOT NULL,
    PRIMARY KEY (`relacao`, `chave_a`, `chave_b`),
    KEY `idx_busca_vinculo_a` (`tipo_a`, `chave_a`),
    KEY `idx_busca_vinculo_b` (`tipo_b`, `chave_b`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci

-- @@
-- Fontes: um SELECT por módulo. Toda coluna de texto das views passa para utf8mb4_0900_ai_ci antes de ser comparada ou
-- concatenada (as views dos clientes têm collations diferentes, e o servidor usa outra collation na conexão).
CREATE OR REPLACE VIEW `vw_busca_fonte_registros` AS
SELECT 'licencas' AS tipo, CAST(g.CODLICENCA AS CHAR) AS chave,
       CONVERT(CONVERT(g.DSCLICENCA USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4) AS titulo,
       CONVERT(CONVERT(g.DSCTIPODOCUMENTO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4) AS descricao,
       CONVERT(CONCAT_WS(' ', CONVERT(g.RESPONSAVEL USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.OBSERVACAO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci) USING utf8mb4) AS texto,
       CONVERT(CASE WHEN CONVERT(g.DATA_VALIDADE USING utf8mb4) COLLATE utf8mb4_0900_ai_ci IS NULL OR CONVERT(g.DATA_VALIDADE USING utf8mb4) COLLATE utf8mb4_0900_ai_ci NOT REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}' THEN _utf8mb4'Sem validade' COLLATE utf8mb4_0900_ai_ci
            WHEN LEFT(CONVERT(g.DATA_VALIDADE USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, 10) < CURDATE() THEN 'Vencida'
            WHEN LEFT(CONVERT(g.DATA_VALIDADE USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, 10) <= CURDATE() + INTERVAL 30 DAY THEN 'Vence em até 30 dias'
            ELSE 'Em dia' END USING utf8mb4) AS situacao,
       CASE WHEN CONVERT(g.DATA_VALIDADE USING utf8mb4) COLLATE utf8mb4_0900_ai_ci IS NULL OR CONVERT(g.DATA_VALIDADE USING utf8mb4) COLLATE utf8mb4_0900_ai_ci NOT REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}' THEN 'sem-situacao'
            WHEN LEFT(CONVERT(g.DATA_VALIDADE USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, 10) < CURDATE() THEN 'vencido'
            WHEN LEFT(CONVERT(g.DATA_VALIDADE USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, 10) <= CURDATE() + INTERVAL 30 DAY THEN 'a-vencer'
            ELSE 'aprovado' END AS situacao_cor,
       CASE WHEN CONVERT(g.DATA_VALIDADE USING utf8mb4) COLLATE utf8mb4_0900_ai_ci REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}' AND LEFT(CONVERT(g.DATA_VALIDADE USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, 10) > '1900-01-01' THEN CAST(LEFT(CONVERT(g.DATA_VALIDADE USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, 10) AS DATE) END AS validade,
       g.CODUNIDADE AS codunidade, CONVERT(CONVERT(g.DSCUNIDADE USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4) AS unidade, g.CODUSUARIO AS codusuario,
       'LICENCAS' AS transacao, '/principal/manutencaolegal_licencas.php' AS pagina,
       JSON_OBJECT('CODLICENCA', JSON_OBJECT('value', CAST(g.CODLICENCA AS CHAR), 'exact', TRUE)) AS filtros,
       'licencas' AS ged_tipo, g.CODLICENCA AS ged_valor,
       COALESCE(NULLIF(l.CODLICENCA_BASE, 0), g.CODLICENCA) AS pasta, 'USUARIO' AS escopo,
       CAST(NULL AS CHAR(40) CHARSET utf8mb4) COLLATE utf8mb4_0900_ai_ci AS grupo_tipo, CAST(NULL AS CHAR(100) CHARSET utf8mb4) COLLATE utf8mb4_0900_ai_ci AS grupo_chave,
       CAST(NULL AS CHAR(500) CHARSET utf8mb4) COLLATE utf8mb4_0900_ai_ci AS grupo_nome, CAST(NULL AS CHAR(500) CHARSET utf8mb4) COLLATE utf8mb4_0900_ai_ci AS grupo_detalhe,
       CAST(NULL AS SIGNED) AS ref_legislacao, CAST(NULL AS SIGNED) AS ref_unidade,
       CONVERT(CONVERT(LEFT(g.RESPONSAVEL, 500) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4) AS responsavel
  FROM `vw_licencas_grid` g
  LEFT JOIN `li_licencas` l ON l.CODLICENCA = g.CODLICENCA
UNION ALL
SELECT 'controles', CAST(g.CODDOCUMENTO AS CHAR),
       CONVERT(CONVERT(g.TITULO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4), CONVERT(CONVERT(g.CODIGO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4),
       CONVERT(CONCAT_WS(' ', CONVERT(g.PALAVRAS_CHAVES USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.RESPONSAVEIS USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.ATIVIDADES USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.ASPECTOS USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.IMPACTOS USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.PERIGOS USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.RISCOS USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.ASPECTOS_SOCIAIS USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.IMPACTOS_SOCIAIS USING utf8mb4) COLLATE utf8mb4_0900_ai_ci) USING utf8mb4),
       NULL, NULL, NULL, NULL, CONVERT(LEFT(CONVERT(g.UNIDADES USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, 500) USING utf8mb4), NULL,
       'CONTROLE_DOCUMENTOS', '/principal/modulolegal_controle.php',
       JSON_OBJECT('CODDOCUMENTO', JSON_OBJECT('value', CAST(g.CODDOCUMENTO AS CHAR), 'exact', TRUE)),
       'controles', g.CODDOCUMENTO, g.CODDOCUMENTO, 'NENHUM', NULL, NULL, NULL, NULL, NULL, NULL, CONVERT(CONVERT(LEFT(g.RESPONSAVEIS, 500) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4)
  FROM `vw_controle_documentos_grid` g
UNION ALL
SELECT 'requisitos', CAST(g.CODLEGISLACAXEMPRESA AS CHAR),
       CONVERT(CONCAT_WS(' ', CONVERT(g.DSCTIPO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONCAT_WS('/', NULLIF(CONVERT(g.NR USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, ''), NULLIF(CONVERT(g.ANO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, ''))) USING utf8mb4),
       CONVERT(LEFT(CONVERT(g.DSCASSUNTO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, 1000) USING utf8mb4),
       CONVERT(CONCAT_WS(' ', CONVERT(g.CODIGO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.DSCAMBITO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.ARTIGOS USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.PODER USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, LEFT(CONVERT(g.PADRAO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, 3000)) USING utf8mb4),
       CONVERT(NULLIF(CONVERT(g.ATENDIDO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, '') USING utf8mb4),
       CASE WHEN CONVERT(g.ATENDIDO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci = 'Sim' THEN 'aprovado' WHEN CONVERT(g.ATENDIDO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE 'N%o' THEN 'reprovado'
            WHEN CONVERT(g.ATENDIDO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE 'Parcial%' THEN 'a-vencer' ELSE 'sem-situacao' END,
       NULL, g.CODUNIDADE, CONVERT(COALESCE(CONVERT(g.UNIDADE USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.DSCUNIDADE USING utf8mb4) COLLATE utf8mb4_0900_ai_ci) USING utf8mb4), NULL,
       'REQUISITOS_LEGAIS', '/principal/modulolegal_requisitoslegais.php',
       JSON_OBJECT('CODLEGISLACAO', CAST(g.CODLEGISLACAO AS CHAR), 'CODUNIDADE', CAST(g.CODUNIDADE AS CHAR),
                   'LEI_REVOGADA', COALESCE(NULLIF(CONVERT(g.LEI_REVOGADA USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, ''), 'NAO')),
       NULL, NULL, NULL, 'NENHUM', NULL, NULL, NULL, NULL, g.CODLEGISLACAO, g.CODUNIDADE, NULL
  FROM `vw_modulolegal_requisitos_grid` g
UNION ALL
SELECT 'treinamentos', CAST(g.CODTREINAMENTO AS CHAR),
       CONVERT(CONVERT(g.DSCTREINAMENTO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4), NULL, CONVERT(CONVERT(g.PALAVRAS_CHAVE USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4),
       NULL, NULL, NULL, NULL, NULL, NULL,
       'MODULOLEGAL_TREINAMENTOS', '/principal/modulolegal_treinamentos.php',
       JSON_OBJECT('CODTREINAMENTO', JSON_OBJECT('value', CAST(g.CODTREINAMENTO AS CHAR), 'exact', TRUE)),
       'treinamentos', g.CODTREINAMENTO, g.CODTREINAMENTO, 'NENHUM', NULL, NULL, NULL, NULL, NULL, NULL, NULL
  FROM `vw_modulolegal_treinamentos_grid` g
UNION ALL
SELECT 'infraestrutura', CAST(g.CODINFRAESTRUTURA AS CHAR),
       CONVERT(CONVERT(g.DSCINFRAESTRUTURA USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4), NULL, CONVERT(CONCAT_WS(' ', CONVERT(g.ATRIBUTOS USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.PALAVRAS_CHAVE USING utf8mb4) COLLATE utf8mb4_0900_ai_ci) USING utf8mb4),
       NULL, NULL, NULL, NULL, NULL, NULL,
       'MODULOLEGAL_INFRAESTRUTURA', '/principal/modulolegal_InfraEstrutura.php',
       JSON_OBJECT('CODINFRAESTRUTURA', JSON_OBJECT('value', CAST(g.CODINFRAESTRUTURA AS CHAR), 'exact', TRUE)),
       'infraestrutura', g.CODINFRAESTRUTURA, g.CODINFRAESTRUTURA, 'NENHUM', NULL, NULL, NULL, NULL, NULL, NULL, NULL
  FROM `vw_modulolegal_infraestrutura_grid` g
UNION ALL
SELECT 'fornecedorcriterios', CAST(g.CODFORNECEDORXCRITERIOS AS CHAR),
       CONVERT(LEFT(CONVERT(g.DSCRITERIO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, 500) USING utf8mb4), CONVERT(CONVERT(g.RAZAOSOCIAL USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4),
       CONVERT(CONCAT_WS(' ', CONVERT(g.RESPONSAVEL USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.OBSERVACAO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.OBSERVACAO_DO_FORNECEDOR USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.SITUACAO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci) USING utf8mb4),
       CONVERT(CONVERT(g.SITUACAO_DESCRICAO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4), CONVERT(g.SITUACAO_COR USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, g.DATADEVALIDADE,
       g.CODUNIDADE, CONVERT(CONVERT(g.UNIDADEFORNECEDOR USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4), NULL,
       'PORTARIA_DOCUMENTACAO_FORNECEDOR', '/principal/portaria_documentacaofornecedor.php',
       JSON_OBJECT('CODFORNECEDORXCRITERIOS', JSON_OBJECT('value', CAST(g.CODFORNECEDORXCRITERIOS AS CHAR), 'exact', TRUE)),
       'fornecedorcriterios', g.CODFORNECEDORXCRITERIOS, g.CODFORNECEDORXCRITERIOS, 'UNIDADE',
       'fornecedor', CAST(g.CODFORNECEDOR AS CHAR), CONVERT(CONVERT(g.RAZAOSOCIAL USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4), NULL, NULL, NULL,
       CONVERT(CONVERT(LEFT(g.RESPONSAVEL, 500) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4)
  FROM `vw_portaria_documentacao_fornecedor_grid` g
UNION ALL
SELECT 'funcionariocriterios', CAST(CONVERT(g.CODIGO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS CHAR),
       CONVERT(CONVERT(g.DSCRITERIO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4), CONVERT(CONCAT_WS(' — ', CONVERT(g.FUNCIONARIO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.RAZAOSOCIAL USING utf8mb4) COLLATE utf8mb4_0900_ai_ci) USING utf8mb4),
       CONVERT(CONCAT_WS(' ', CONVERT(g.NRIDENTIFICACAO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.DSCTIPO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.NOME USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.OBSERVACAO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.SITUACAO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.ATENDEAOCRITERIO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci) USING utf8mb4),
       CONVERT(CONVERT(g.SITUACAO_DESCRICAO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4), CONVERT(g.SITUACAO_COR USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, g.DATADEVALIDADE,
       g.CODUNIDADE, CONVERT(CONVERT(g.DSCUNIDADE USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4), NULL,
       'PORTARIA_DOCUMENTACAO_FUNCIONARIO', '/principal/portaria_documentacaofuncionario.php',
       JSON_OBJECT('CODIGO', JSON_OBJECT('value', CAST(CONVERT(g.CODIGO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS CHAR), 'exact', TRUE)),
       'funcionariocriterios', CONVERT(g.CODIGO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.CODIGO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, 'UNIDADE',
       'funcionario', CAST(g.CODFUNCIONARIO AS CHAR), CONVERT(CONVERT(g.FUNCIONARIO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4),
       CONVERT(CONVERT(g.RAZAOSOCIAL USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4), NULL, NULL, CONVERT(CONVERT(LEFT(g.NOME, 500) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4)
  FROM `vw_portaria_documentacao_funcionario_grid` g
UNION ALL
SELECT 'funcionariotreinamentos', CAST(CONVERT(g.CODIGO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS CHAR),
       CONVERT(COALESCE(CONVERT(g.DSCTIPOTREINAMENTO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONCAT('Treinamento ', g.CODTIPOTREINAMENTO)) USING utf8mb4),
       CONVERT(CONVERT(g.FUNCIONARIO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4),
       CONVERT(CONCAT_WS(' ', CONVERT(g.DSCTIPO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.RESPONSAVELTREINAMENTO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.RESPONSAVEL USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.TREINAMENTOREALIZADO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci) USING utf8mb4),
       CONVERT(CONVERT(g.SITUACAO_DESCRICAO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4), CONVERT(g.SITUACAO_COR USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, g.DATADEVALIDADETREINAMENTO,
       g.CODUNIDADE, NULL, NULL,
       'PORTARIA_DOCUMENTACAO_FUNCIONARIO', '/principal/portaria_documentacaofuncionario_treinamentos.php',
       JSON_OBJECT('CODFUNCIONARIO', CAST(g.CODFUNCIONARIO AS CHAR), 'CODIGO', JSON_OBJECT('value', CAST(CONVERT(g.CODIGO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS CHAR), 'exact', TRUE)),
       'funcionariotreinamentos', CONVERT(g.CODIGO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(g.CODIGO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, 'UNIDADE',
       'funcionario', CAST(g.CODFUNCIONARIO AS CHAR), CONVERT(CONVERT(g.FUNCIONARIO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4), NULL, NULL, NULL,
       CONVERT(CONVERT(LEFT(g.RESPONSAVEL, 500) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci USING utf8mb4)
  FROM `vw_portaria_documentacao_treinamento_grid` g

-- @@
-- Responsáveis vindos das telas de Responsabilidades (atribuições ATIVAS), para os tipos cuja view do grid não traz o responsável:
-- requisitos (por CODLEGISLACAO + CODUNIDADE), treinamentos e infraestrutura (pelo código). Montada pelo script com as views
-- vw_responsabilidade_* que existem no banco (marcador {{RESPONSAVEIS}}). Colunas: tipo, chave, ref_legislacao, ref_unidade, responsavel.
CREATE OR REPLACE VIEW `vw_busca_fonte_responsaveis` AS
{{RESPONSAVEIS}}

-- @@
DROP PROCEDURE IF EXISTS `sp_busca_indexar_vinculos`

-- @@
-- Lê as views das relações ativas do catálogo (só as que existem) e sincroniza busca_vinculo. p_total = vínculos gravados.
CREATE PROCEDURE `sp_busca_indexar_vinculos`(OUT p_total INT)
BEGIN
    DECLARE v_fim TINYINT DEFAULT 0;
    DECLARE v_rel VARCHAR(40) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
    DECLARE v_fonte VARCHAR(64) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
    DECLARE c_rel CURSOR FOR SELECT r.codigo, r.fonte FROM `busca_relacao` r
        WHERE r.ativo = 1 AND r.fonte REGEXP '^vw_busca_rel_[a-z0-9_]+$'
          AND EXISTS (SELECT 1 FROM information_schema.VIEWS v WHERE v.TABLE_SCHEMA = DATABASE() AND v.TABLE_NAME = r.fonte);
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fim = 1;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_vn;
    CREATE TEMPORARY TABLE tmp_busca_vn (relacao VARCHAR(40) NOT NULL, chave_a VARCHAR(100) NOT NULL, chave_b VARCHAR(100) NOT NULL,
        PRIMARY KEY (relacao, chave_a, chave_b)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    OPEN c_rel;
    lendo: LOOP
        FETCH c_rel INTO v_rel, v_fonte;
        IF v_fim = 1 THEN LEAVE lendo; END IF;
        SET @busca_sql = CONCAT('INSERT IGNORE INTO tmp_busca_vn (relacao, chave_a, chave_b) SELECT ', QUOTE(v_rel),
            ', LEFT(CONVERT(CAST(f.chave_a AS CHAR) USING utf8mb4), 100), LEFT(CONVERT(CAST(f.chave_b AS CHAR) USING utf8mb4), 100) FROM `', v_fonte,
            '` f WHERE f.chave_a IS NOT NULL AND f.chave_b IS NOT NULL');
        PREPARE s_rel FROM @busca_sql;
        EXECUTE s_rel;
        DEALLOCATE PREPARE s_rel;
    END LOOP;
    CLOSE c_rel;
    DELETE b FROM `busca_vinculo` b
      LEFT JOIN tmp_busca_vn n ON n.relacao = b.relacao AND n.chave_a = b.chave_a AND n.chave_b = b.chave_b
     WHERE n.relacao IS NULL;
    INSERT IGNORE INTO `busca_vinculo` (relacao, tipo_a, chave_a, tipo_b, chave_b)
    SELECT n.relacao, r.tipo_a, n.chave_a, r.tipo_b, n.chave_b FROM tmp_busca_vn n JOIN `busca_relacao` r ON r.codigo = n.relacao;
    -- relação com os lados trocados no catálogo: acompanha
    UPDATE `busca_vinculo` b JOIN `busca_relacao` r ON r.codigo = b.relacao SET b.tipo_a = r.tipo_a, b.tipo_b = r.tipo_b
     WHERE b.tipo_a <> r.tipo_a OR b.tipo_b <> r.tipo_b;
    SELECT COUNT(*) INTO p_total FROM `busca_vinculo`;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_vn;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_busca_indexar_registros`

-- @@
-- Grava no índice os registros novos ou alterados (hash), remove os que sumiram (com os arquivos) e atualiza nos
-- itens ARQUIVO os dados copiados do registro e regrava os vínculos entre negócios (sp_busca_indexar_vinculos).
-- Devolve: incluidos_ou_alterados, removidos, arquivos_atualizados, total, vinculos.
CREATE PROCEDURE `sp_busca_indexar_registros`()
BEGIN
    DECLARE v_gravados INT DEFAULT 0;
    DECLARE v_removidos INT DEFAULT 0;
    DECLARE v_arquivos INT DEFAULT 0;
    DECLARE v_total INT DEFAULT 0;
    DECLARE v_vinculos INT DEFAULT 0;
    DECLARE v_sugestoes INT DEFAULT 0;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_fonte;
    CREATE TEMPORARY TABLE tmp_busca_fonte (
        tipo VARCHAR(40) NOT NULL, chave VARCHAR(100) NOT NULL,
        titulo VARCHAR(500) NULL, descricao VARCHAR(1000) NULL, texto MEDIUMTEXT NULL,
        situacao VARCHAR(100) NULL, situacao_cor VARCHAR(30) NULL, validade DATE NULL,
        codunidade INT NULL, unidade VARCHAR(500) NULL, codusuario INT NULL,
        transacao VARCHAR(80) NOT NULL, pagina VARCHAR(200) NOT NULL, filtros JSON NULL,
        ged_tipo VARCHAR(40) NULL, ged_valor BIGINT NULL, pasta BIGINT NULL, escopo VARCHAR(10) NOT NULL,
        grupo_tipo VARCHAR(40) NOT NULL, grupo_chave VARCHAR(100) NOT NULL, grupo_nome VARCHAR(500) NULL, grupo_detalhe VARCHAR(500) NULL,
        ref_legislacao INT NULL, ref_unidade INT NULL, responsavel VARCHAR(500) NULL,
        hash CHAR(40) NOT NULL DEFAULT '',
        PRIMARY KEY (tipo, chave)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    INSERT IGNORE INTO tmp_busca_fonte
    SELECT f.tipo, f.chave, LEFT(f.titulo, 500), LEFT(f.descricao, 1000), f.texto, LEFT(f.situacao, 100), f.situacao_cor, f.validade,
           f.codunidade, LEFT(f.unidade, 500), f.codusuario, f.transacao, f.pagina, f.filtros, f.ged_tipo, f.ged_valor, f.pasta, f.escopo,
           COALESCE(f.grupo_tipo, f.tipo), COALESCE(NULLIF(f.grupo_chave, ''), f.chave), LEFT(COALESCE(NULLIF(TRIM(f.grupo_nome), ''), f.titulo), 500), LEFT(f.grupo_detalhe, 500),
           f.ref_legislacao, f.ref_unidade, LEFT(NULLIF(TRIM(f.responsavel), ''), 500), ''
      FROM `vw_busca_fonte_registros` f
     WHERE f.chave IS NOT NULL;
    -- Responsáveis das telas de Responsabilidades (requisitos, treinamentos, infraestrutura)
    UPDATE tmp_busca_fonte f JOIN `vw_busca_fonte_responsaveis` r ON r.tipo = f.tipo AND r.chave = f.chave
       SET f.responsavel = LEFT(r.responsavel, 500) WHERE f.responsavel IS NULL AND r.chave IS NOT NULL;
    UPDATE tmp_busca_fonte f JOIN `vw_busca_fonte_responsaveis` r ON r.tipo = f.tipo AND r.ref_legislacao = f.ref_legislacao AND r.ref_unidade = f.ref_unidade
       SET f.responsavel = LEFT(r.responsavel, 500) WHERE f.responsavel IS NULL AND r.ref_legislacao IS NOT NULL;
    UPDATE tmp_busca_fonte f SET f.hash = SHA1(CONCAT_WS('|', f.titulo, f.descricao, f.texto, f.situacao, f.situacao_cor, f.validade, f.codunidade,
           f.unidade, f.codusuario, f.transacao, f.pagina, CAST(f.filtros AS CHAR), f.ged_tipo, f.ged_valor, f.pasta, f.escopo,
           f.grupo_tipo, f.grupo_chave, f.grupo_nome, f.grupo_detalhe, f.ref_legislacao, f.ref_unidade, f.responsavel));
    SELECT COUNT(*) INTO v_total FROM tmp_busca_fonte;

    INSERT INTO `busca_indice` (tipo, item, chave, arquivo, nome, titulo, descricao, texto, situacao, situacao_cor, validade, codunidade, unidade,
                                codusuario, formato, transacao, pagina, filtros, ged_tipo, ged_valor, pasta, escopo,
                                grupo_tipo, grupo_chave, grupo_nome, grupo_detalhe, ref_legislacao, ref_unidade, responsavel, hash)
    SELECT f.tipo, 'REGISTRO', f.chave, '', COALESCE(f.titulo, CONCAT('#', f.chave)), f.titulo, f.descricao, f.texto, f.situacao, f.situacao_cor,
           f.validade, f.codunidade, f.unidade, f.codusuario, 'registro', f.transacao, f.pagina, f.filtros, f.ged_tipo, f.ged_valor, f.pasta,
           f.escopo, f.grupo_tipo, f.grupo_chave, f.grupo_nome, f.grupo_detalhe, f.ref_legislacao, f.ref_unidade, f.responsavel, f.hash
      FROM tmp_busca_fonte f
      LEFT JOIN `busca_indice` i ON i.tipo = f.tipo AND i.item = 'REGISTRO' AND i.chave = f.chave AND i.arquivo = ''
     WHERE i.codbusca IS NULL OR NOT (i.hash <=> f.hash)
    ON DUPLICATE KEY UPDATE nome = VALUES(nome), titulo = VALUES(titulo), descricao = VALUES(descricao), texto = VALUES(texto),
        situacao = VALUES(situacao), situacao_cor = VALUES(situacao_cor), validade = VALUES(validade), codunidade = VALUES(codunidade),
        unidade = VALUES(unidade), codusuario = VALUES(codusuario), transacao = VALUES(transacao), pagina = VALUES(pagina),
        filtros = VALUES(filtros), ged_tipo = VALUES(ged_tipo), ged_valor = VALUES(ged_valor), pasta = VALUES(pasta),
        escopo = VALUES(escopo), grupo_tipo = VALUES(grupo_tipo), grupo_chave = VALUES(grupo_chave), grupo_nome = VALUES(grupo_nome),
        grupo_detalhe = VALUES(grupo_detalhe), ref_legislacao = VALUES(ref_legislacao), ref_unidade = VALUES(ref_unidade),
        responsavel = VALUES(responsavel), hash = VALUES(hash), norm = NULL;
    SET v_gravados = ROW_COUNT();

    DELETE i FROM `busca_indice` i
      LEFT JOIN tmp_busca_fonte f ON f.tipo = i.tipo AND f.chave = i.chave
     WHERE f.chave IS NULL;
    SET v_removidos = ROW_COUNT();

    UPDATE `busca_indice` a
      JOIN `busca_indice` r ON r.tipo = a.tipo AND r.item = 'REGISTRO' AND r.chave = a.chave AND r.arquivo = ''
       SET a.titulo = r.titulo, a.descricao = r.descricao, a.texto = r.texto, a.situacao = r.situacao, a.situacao_cor = r.situacao_cor,
           a.validade = r.validade, a.codunidade = r.codunidade, a.unidade = r.unidade, a.codusuario = r.codusuario,
           a.transacao = r.transacao, a.pagina = r.pagina, a.filtros = r.filtros, a.ged_tipo = r.ged_tipo, a.ged_valor = r.ged_valor,
           a.pasta = r.pasta, a.escopo = r.escopo, a.grupo_tipo = r.grupo_tipo, a.grupo_chave = r.grupo_chave, a.grupo_nome = r.grupo_nome,
           a.grupo_detalhe = r.grupo_detalhe, a.responsavel = r.responsavel, a.hash_registro = r.hash, a.norm = NULL
     WHERE a.item = 'ARQUIVO' AND NOT (a.hash_registro <=> r.hash);
    SET v_arquivos = ROW_COUNT();
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_fonte;

    -- Vínculos entre negócios (catálogo busca_relacao)
    -- Texto normalizado dos itens novos ou alterados (conferência rápida dos termos na pesquisa)
    UPDATE `busca_indice` SET norm = CAST(CONCAT(' ', TRIM(REGEXP_REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(CONCAT_WS(' ', nome, titulo, descricao, texto)),'á','a'),'à','a'),'â','a'),'ã','a'),'ä','a'),'é','e'),'ê','e'),'è','e'),'ë','e'),'í','i'),'ì','i'),'î','i'),'ï','i'),'ó','o'),'ò','o'),'ô','o'),'õ','o'),'ö','o'),'ú','u'),'ù','u'),'û','u'),'ü','u'),'ç','c'),'ñ','n'), '[^a-z0-9]+', ' ')), ' ') AS BINARY) WHERE norm IS NULL;
    CALL `sp_busca_indexar_vinculos`(v_vinculos);
    -- Sugestões para o dicionário a partir dos cadastros
    CALL `sp_busca_dicionario_sugerir`(v_sugestoes);

    SELECT v_gravados AS incluidos_ou_alterados, v_removidos AS removidos, v_arquivos AS arquivos_atualizados, v_total AS total, v_vinculos AS vinculos, v_sugestoes AS sugestoes_dicionario;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_busca_sincronizar_arquivos`

-- @@
-- p_arquivos: [{"p": pasta, "a": "nome do arquivo", "t": tamanho, "m": "AAAA-MM-DD HH:MM:SS"}, ...] = todos os arquivos das
-- pastas do GED do tipo. Cada arquivo vai para o registro da pasta (licenças com versões: a versão mais recente).
-- Devolve: incluidos_ou_alterados, removidos, sem_registro (arquivos de pastas sem registro na tela).
CREATE PROCEDURE `sp_busca_sincronizar_arquivos`(IN p_tipo VARCHAR(40) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci, IN p_arquivos JSON)
BEGIN
    DECLARE v_gravados INT DEFAULT 0;
    DECLARE v_removidos INT DEFAULT 0;
    DECLARE v_sem_registro INT DEFAULT 0;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_arq;
    CREATE TEMPORARY TABLE tmp_busca_arq (
        pasta BIGINT NOT NULL, arquivo VARCHAR(255) NOT NULL, tamanho BIGINT NULL, modificado DATETIME NULL,
        PRIMARY KEY (pasta, arquivo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    IF p_arquivos IS NOT NULL AND JSON_LENGTH(p_arquivos) > 0 THEN
        INSERT IGNORE INTO tmp_busca_arq (pasta, arquivo, tamanho, modificado)
        SELECT j.p, LEFT(j.a, 255), j.t, j.m
          FROM JSON_TABLE(p_arquivos, '$[*]' COLUMNS (p BIGINT PATH '$.p', a VARCHAR(500) PATH '$.a', t BIGINT PATH '$.t', m DATETIME PATH '$.m')) j
         WHERE j.p IS NOT NULL AND j.a IS NOT NULL AND j.a <> '';
    END IF;

    DROP TEMPORARY TABLE IF EXISTS tmp_busca_pasta;
    -- Registro dono de cada pasta: o de maior código (licenças com versões: a versão mais recente)
    CREATE TEMPORARY TABLE tmp_busca_pasta (pasta BIGINT NOT NULL PRIMARY KEY, chave VARCHAR(100) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    INSERT INTO tmp_busca_pasta (pasta, chave)
    SELECT r.pasta, CAST(MAX(CAST(r.chave AS UNSIGNED)) AS CHAR) FROM `busca_indice` r
     WHERE r.tipo = p_tipo AND r.item = 'REGISTRO' AND r.pasta IS NOT NULL
     GROUP BY r.pasta;

    SELECT COUNT(*) INTO v_sem_registro FROM tmp_busca_arq a LEFT JOIN tmp_busca_pasta m ON m.pasta = a.pasta WHERE m.pasta IS NULL;

    INSERT INTO `busca_indice` (tipo, item, chave, arquivo, nome, titulo, descricao, texto, situacao, situacao_cor, validade, codunidade, unidade,
                                codusuario, formato, tamanho, modificado_em, transacao, pagina, filtros, ged_tipo, ged_valor, pasta, escopo,
                                grupo_tipo, grupo_chave, grupo_nome, grupo_detalhe, responsavel, hash_registro)
    SELECT r.tipo, 'ARQUIVO', r.chave, a.arquivo, a.arquivo, r.titulo, r.descricao, r.texto, r.situacao, r.situacao_cor, r.validade, r.codunidade,
           r.unidade, r.codusuario,
           LEFT(LOWER(IF(LOCATE('.', a.arquivo) > 0, SUBSTRING_INDEX(a.arquivo, '.', -1), '')), 20), a.tamanho, a.modificado,
           r.transacao, r.pagina, r.filtros, r.ged_tipo, r.ged_valor, r.pasta, r.escopo,
           r.grupo_tipo, r.grupo_chave, r.grupo_nome, r.grupo_detalhe, r.responsavel, r.hash
      FROM tmp_busca_arq a
      JOIN tmp_busca_pasta m ON m.pasta = a.pasta
      JOIN `busca_indice` r ON r.tipo = p_tipo AND r.item = 'REGISTRO' AND r.chave = m.chave AND r.arquivo = ''
      LEFT JOIN `busca_indice` x ON x.tipo = r.tipo AND x.item = 'ARQUIVO' AND x.chave = r.chave AND x.arquivo = a.arquivo
     WHERE x.codbusca IS NULL OR NOT (x.tamanho <=> a.tamanho) OR NOT (x.modificado_em <=> a.modificado) OR NOT (x.hash_registro <=> r.hash)
    ON DUPLICATE KEY UPDATE nome = VALUES(nome), titulo = VALUES(titulo), descricao = VALUES(descricao), texto = VALUES(texto),
        situacao = VALUES(situacao), situacao_cor = VALUES(situacao_cor), validade = VALUES(validade), codunidade = VALUES(codunidade),
        unidade = VALUES(unidade), codusuario = VALUES(codusuario), formato = VALUES(formato), tamanho = VALUES(tamanho),
        modificado_em = VALUES(modificado_em), transacao = VALUES(transacao), pagina = VALUES(pagina), filtros = VALUES(filtros),
        ged_tipo = VALUES(ged_tipo), ged_valor = VALUES(ged_valor), pasta = VALUES(pasta), escopo = VALUES(escopo),
        grupo_tipo = VALUES(grupo_tipo), grupo_chave = VALUES(grupo_chave), grupo_nome = VALUES(grupo_nome), grupo_detalhe = VALUES(grupo_detalhe),
        responsavel = VALUES(responsavel), hash_registro = VALUES(hash_registro), norm = NULL;
    SET v_gravados = ROW_COUNT();
    UPDATE `busca_indice` SET norm = CAST(CONCAT(' ', TRIM(REGEXP_REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(CONCAT_WS(' ', nome, titulo, descricao, texto)),'á','a'),'à','a'),'â','a'),'ã','a'),'ä','a'),'é','e'),'ê','e'),'è','e'),'ë','e'),'í','i'),'ì','i'),'î','i'),'ï','i'),'ó','o'),'ò','o'),'ô','o'),'õ','o'),'ö','o'),'ú','u'),'ù','u'),'û','u'),'ü','u'),'ç','c'),'ñ','n'), '[^a-z0-9]+', ' ')), ' ') AS BINARY) WHERE tipo = p_tipo AND norm IS NULL;


    -- Arquivos que não estão mais na pasta (ou cujo registro da pasta mudou)
    DELETE x FROM `busca_indice` x
      LEFT JOIN tmp_busca_pasta m ON m.pasta = x.pasta
      LEFT JOIN tmp_busca_arq a ON a.pasta = x.pasta AND a.arquivo = x.arquivo
     WHERE x.tipo = p_tipo AND x.item = 'ARQUIVO' AND (a.arquivo IS NULL OR m.chave IS NULL OR m.chave <> x.chave);
    SET v_removidos = ROW_COUNT();

    DROP TEMPORARY TABLE IF EXISTS tmp_busca_arq;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_pasta;
    SELECT v_gravados AS incluidos_ou_alterados, v_removidos AS removidos, v_sem_registro AS sem_registro;
END

-- @@
-- Dicionário: base comum (universal) + termos da empresa
CREATE OR REPLACE VIEW `vw_busca_dicionario` AS
SELECT 'UNIVERSAL' AS base, d.coddicionario, d.termo, d.significado, d.sinonimos, d.sigla, d.contexto, d.status, d.origem, d.ocorrencias,
       d.exemplo, d.atualizado_em
  FROM `{{UNIVERSAL}}`.`busca_dicionario` d
UNION ALL
SELECT 'EMPRESA', d.coddicionario, d.termo, d.significado, d.sinonimos, d.sigla, d.contexto, d.status, d.origem, d.ocorrencias,
       d.exemplo, d.atualizado_em
  FROM `busca_dicionario` d

-- @@
DROP PROCEDURE IF EXISTS `sp_busca_dicionario_listar`

-- @@
-- Tela Dicionário da pesquisa. p_status / p_base vazios = todos; p_texto procura em termo, significado e sinônimos.
CREATE PROCEDURE `sp_busca_dicionario_listar`(IN p_status VARCHAR(12) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
    IN p_base VARCHAR(10) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci, IN p_texto VARCHAR(200) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci)
BEGIN
    SELECT d.* FROM `vw_busca_dicionario` d
     WHERE (COALESCE(p_status, '') = '' OR d.status = p_status)
       AND (COALESCE(p_base, '') = '' OR d.base = p_base)
       AND (COALESCE(p_texto, '') = '' OR CONCAT_WS(' ', d.termo, d.significado, d.sinonimos) LIKE CONCAT('%', p_texto, '%'))
     ORDER BY FIELD(d.status, 'SUGESTAO', 'APROVADO', 'REJEITADO'), d.termo, d.significado, d.base;
    SELECT d.status, COUNT(*) AS qtd FROM `vw_busca_dicionario` d GROUP BY d.status;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_busca_dicionario_sugerir`

-- @@
-- Serviço de indexação: procura nos cadastros indexados os pares "SIGLA - descrição" / "SIGLA: descrição" e "descrição (SIGLA)"
-- e grava como SUGESTAO (origem DADOS) no dicionário da empresa os termos que ainda não estão em nenhuma das duas bases (qualquer
-- situação). A sigla tem 2 a 10 letras maiúsculas/números e começa com a mesma letra da descrição. Nos termos já sugeridos,
-- atualiza ocorrências e contexto. p_novos = termos sugeridos agora.
CREATE PROCEDURE `sp_busca_dicionario_sugerir`(OUT p_novos INT)
BEGIN
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_sug;
    CREATE TEMPORARY TABLE tmp_busca_sug (termo VARCHAR(100) NOT NULL, significado VARCHAR(300) NOT NULL, tipo VARCHAR(40) NOT NULL, exemplo VARCHAR(500) NULL)
        ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    -- "ASO - ATESTADO DE SAUDE OCUPACIONAL"
    INSERT INTO tmp_busca_sug (termo, significado, tipo, exemplo)
    SELECT x.termo, x.significado, x.tipo, x.exemplo FROM (
        SELECT REGEXP_SUBSTR(t.txt, '^[A-Z][A-Z0-9]{1,9}', 1, 1, 'c') AS termo,
               TRIM(REGEXP_REPLACE(t.txt, '^[A-Z][A-Z0-9]{1,9}\\s*[-–:]\\s*', '', 1, 1, 'c')) AS significado, t.tipo, LEFT(t.txt, 500) AS exemplo
          FROM (SELECT DISTINCT i.tipo, TRIM(v.txt) AS txt FROM `busca_indice` i
                  JOIN JSON_TABLE(JSON_ARRAY(COALESCE(i.nome, ''), COALESCE(i.titulo, ''), COALESCE(i.descricao, '')), '$[*]' COLUMNS (txt VARCHAR(1000) PATH '$')) v
                 WHERE i.item = 'REGISTRO') t
         WHERE REGEXP_LIKE(t.txt, '^[A-Z][A-Z0-9]{1,9}\\s*[-–:]\\s*[[:alpha:]].{3,}', 'c')
    ) x WHERE CHAR_LENGTH(x.significado) BETWEEN 4 AND 300;
    -- "Programa de Gerenciamento de Riscos (PGR)"
    INSERT INTO tmp_busca_sug (termo, significado, tipo, exemplo)
    SELECT x.termo, x.significado, x.tipo, x.exemplo FROM (
        SELECT REGEXP_REPLACE(REGEXP_SUBSTR(t.txt, '\\(([A-Z][A-Z0-9]{1,9})\\)', 1, 1, 'c'), '[()]', '') AS termo,
               TRIM(REGEXP_SUBSTR(t.txt, '[[:alpha:]][^()]{3,}(?=\\s*\\([A-Z][A-Z0-9]{1,9}\\))', 1, 1, 'c')) AS significado, t.tipo, LEFT(t.txt, 500) AS exemplo
          FROM (SELECT DISTINCT i.tipo, TRIM(v.txt) AS txt FROM `busca_indice` i
                  JOIN JSON_TABLE(JSON_ARRAY(COALESCE(i.nome, ''), COALESCE(i.titulo, ''), COALESCE(i.descricao, '')), '$[*]' COLUMNS (txt VARCHAR(1000) PATH '$')) v
                 WHERE i.item = 'REGISTRO') t
         WHERE REGEXP_LIKE(t.txt, '[[:alpha:]][^()]{3,}\\s*\\([A-Z][A-Z0-9]{1,9}\\)', 'c')
    ) x WHERE x.significado IS NOT NULL AND CHAR_LENGTH(x.significado) BETWEEN 4 AND 300;
    -- A sigla começa com a mesma letra da descrição (evita "NR 10 - ..." e textos soltos)
    DELETE FROM tmp_busca_sug WHERE termo IS NULL OR significado IS NULL OR LEFT(termo, 1) <> LEFT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(significado),'á','a'),'à','a'),'â','a'),'ã','a'),'ä','a'),'é','e'),'ê','e'),'è','e'),'í','i'),'ó','o'),'ô','o'),'õ','o'),'ú','u'),'ü','u'),'ç','c'), 1)
        OR significado REGEXP '^[0-9]';

    DROP TEMPORARY TABLE IF EXISTS tmp_busca_sug2;
    CREATE TEMPORARY TABLE tmp_busca_sug2 (termo VARCHAR(100) NOT NULL, significado VARCHAR(300) NOT NULL, contexto VARCHAR(300) NULL, ocorrencias INT NOT NULL,
        exemplo VARCHAR(500) NULL, PRIMARY KEY (termo, significado)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    INSERT IGNORE INTO tmp_busca_sug2 (termo, significado, contexto, ocorrencias, exemplo)
    SELECT s.termo, MIN(s.significado), LEFT(GROUP_CONCAT(DISTINCT s.tipo ORDER BY s.tipo), 300), COUNT(*), MIN(s.exemplo)
      FROM tmp_busca_sug s GROUP BY s.termo, s.significado;

    -- Já sugeridos (empresa, DADOS, ainda SUGESTAO): só ocorrências e contexto
    UPDATE `busca_dicionario` d JOIN tmp_busca_sug2 s ON s.termo = d.termo AND s.significado = d.significado
       SET d.ocorrencias = s.ocorrencias, d.contexto = COALESCE(d.contexto, s.contexto)
     WHERE d.origem = 'DADOS' AND d.status = 'SUGESTAO';
    -- Novos: termo que não existe em nenhuma base (qualquer significado e situação)
    INSERT IGNORE INTO `busca_dicionario` (termo, significado, sigla, contexto, status, origem, ocorrencias, exemplo)
    SELECT s.termo, s.significado, 1, s.contexto, 'SUGESTAO', 'DADOS', s.ocorrencias, s.exemplo
      FROM tmp_busca_sug2 s
     WHERE NOT EXISTS (SELECT 1 FROM `vw_busca_dicionario` d WHERE d.termo = s.termo);
    SET p_novos = ROW_COUNT();
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_sug;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_sug2;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_busca_termos`

-- @@
-- Termos da pesquisa. Cada "frase" do modo booleano montado pela API é um grupo; o item tem de atender a todos os grupos.
-- Alternativas de cada grupo (tmp_busca_alt): a própria frase (e sem acento) e, se p_literal = 0, o termo, o significado e os
-- sinônimos APROVADOS do dicionário em que a frase é o termo, o significado ou um sinônimo (a pesquisa inteira também é
-- comparada com o dicionário: "atestado de saude ocupacional" vira um grupo só). Sigla = palavra inteira (regex); as outras
-- alternativas = trecho (LIKE, sem diferenciar maiúscula e acento). O FULLTEXT ngram só pré-seleciona (ignora espaços).
-- Saída: p_qtd = grupos; p_match = expressão para MATCH (+("alt1" "alt2") por grupo); p_contexto = tipos do contexto dos termos
-- do dicionário usados; p_rx = regex das alternativas (para saber se aparecem no nome/título); tmp_busca_dic_usado = termos usados.
CREATE PROCEDURE `sp_busca_termos`(IN p_termo VARCHAR(1000) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci, IN p_literal TINYINT, OUT p_qtd INT,
    OUT p_match VARCHAR(4000) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci, OUT p_contexto VARCHAR(1000) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
    OUT p_rx VARCHAR(4000) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci)
BEGIN
    DECLARE v_i INT DEFAULT 1;
    DECLARE v_t VARCHAR(1000) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
    DECLARE v_todas VARCHAR(1000) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT '';
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_frase;
    CREATE TEMPORARY TABLE tmp_busca_frase (grupo INT NOT NULL PRIMARY KEY, frase VARCHAR(1000) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    lendo: LOOP
        SET v_t = REGEXP_SUBSTR(COALESCE(p_termo, ''), '"[^"]+"', 1, v_i);
        IF v_t IS NULL OR v_i > 20 THEN LEAVE lendo; END IF;
        SET v_t = TRIM(SUBSTRING(v_t, 2, CHAR_LENGTH(v_t) - 2));
        IF v_t <> '' THEN INSERT INTO tmp_busca_frase VALUES (v_i, v_t); SET v_todas = TRIM(CONCAT(v_todas, ' ', v_t)); END IF;
        SET v_i = v_i + 1;
    END LOOP;
    IF v_i = 1 AND TRIM(COALESCE(p_termo, '')) <> '' THEN
        SET v_t = TRIM(REGEXP_REPLACE(p_termo, '[+<>()~*@"-]', ' '));
        IF v_t <> '' THEN INSERT INTO tmp_busca_frase VALUES (1, v_t); SET v_todas = v_t; END IF;
    END IF;

    -- Dicionário aprovado, uma linha por forma (termo, significado, cada sinônimo)
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_dic;
    CREATE TEMPORARY TABLE tmp_busca_dic (entrada VARCHAR(20) NOT NULL, termo VARCHAR(100) NOT NULL, significado VARCHAR(300) NOT NULL, sigla TINYINT NOT NULL,
        contexto VARCHAR(300) NULL, forma VARCHAR(300) NOT NULL, forma_sigla TINYINT NOT NULL, KEY (forma)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    IF COALESCE(p_literal, 0) = 0 AND (SELECT COUNT(*) FROM tmp_busca_frase) > 0 THEN
        INSERT INTO tmp_busca_dic
        SELECT CONCAT(d.base, ':', d.coddicionario), d.termo, d.significado, d.sigla, d.contexto, d.termo, d.sigla FROM `vw_busca_dicionario` d WHERE d.status = 'APROVADO'
        UNION ALL
        SELECT CONCAT(d.base, ':', d.coddicionario), d.termo, d.significado, d.sigla, d.contexto, d.significado, 0 FROM `vw_busca_dicionario` d WHERE d.status = 'APROVADO'
        UNION ALL
        SELECT CONCAT(d.base, ':', d.coddicionario), d.termo, d.significado, d.sigla, d.contexto, CONVERT(TRIM(j.s) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, 0
          FROM `vw_busca_dicionario` d
          JOIN JSON_TABLE(CONCAT('["', REPLACE(REPLACE(REPLACE(COALESCE(d.sinonimos, ''), '\\', '\\\\'), '"', '\\"'), ';', '","'), '"]'), '$[*]' COLUMNS (s VARCHAR(300) PATH '$')) j
         WHERE d.status = 'APROVADO' AND TRIM(COALESCE(j.s, '')) <> '';
        -- A pesquisa inteira é um termo do dicionário ("atestado de saude ocupacional"): um grupo só
        IF (SELECT COUNT(*) FROM tmp_busca_frase) > 1 AND EXISTS (SELECT 1 FROM tmp_busca_dic WHERE forma = v_todas) THEN
            DELETE FROM tmp_busca_frase;
            INSERT INTO tmp_busca_frase VALUES (1, v_todas);
        END IF;
    END IF;

    DROP TEMPORARY TABLE IF EXISTS tmp_busca_dic_usado;
    CREATE TEMPORARY TABLE tmp_busca_dic_usado (grupo INT NOT NULL, entrada VARCHAR(20) NOT NULL, frase VARCHAR(1000) NOT NULL, termo VARCHAR(100) NOT NULL,
        significado VARCHAR(300) NOT NULL, contexto VARCHAR(300) NULL, PRIMARY KEY (grupo, entrada)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    INSERT IGNORE INTO tmp_busca_dic_usado
    SELECT f.grupo, d.entrada, f.frase, d.termo, d.significado, d.contexto FROM tmp_busca_frase f JOIN tmp_busca_dic d ON d.forma = f.frase;

    DROP TEMPORARY TABLE IF EXISTS tmp_busca_alt;
    -- sem chave única: na collation da pesquisa "fábrica" = "fabrica", mas o FULLTEXT precisa das duas formas
    CREATE TEMPORARY TABLE tmp_busca_alt (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, grupo INT NOT NULL, alt VARCHAR(700) NOT NULL, sigla TINYINT NOT NULL,
        likev VARCHAR(1100) NOT NULL, rx VARCHAR(1200) NOT NULL, chk VARBINARY(1200) NOT NULL DEFAULT '',
        KEY (grupo)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    -- a própria frase (sigla do dicionário = palavra inteira) e sem acento
    INSERT INTO tmp_busca_alt (grupo, alt, sigla, likev, rx)
    SELECT f.grupo, LEFT(f.frase, 700), IF(EXISTS (SELECT 1 FROM tmp_busca_dic d WHERE d.forma = f.frase AND d.forma_sigla = 1) AND f.frase REGEXP '^[[:alnum:]]+$', 1, 0), '', '' FROM tmp_busca_frase f;
    INSERT INTO tmp_busca_alt (grupo, alt, sigla, likev, rx)
    SELECT f.grupo, REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(f.frase),'á','a'),'à','a'),'â','a'),'ã','a'),'ä','a'),'é','e'),'ê','e'),'è','e'),'í','i'),'ó','o'),'ô','o'),'õ','o'),'ú','u'),'ü','u'),'ç','c'), 0, '', '' FROM tmp_busca_frase f
     WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(f.frase),'á','a'),'à','a'),'â','a'),'ã','a'),'ä','a'),'é','e'),'ê','e'),'è','e'),'í','i'),'ó','o'),'ô','o'),'õ','o'),'ú','u'),'ü','u'),'ç','c') <> LOWER(f.frase) COLLATE utf8mb4_bin;
    -- formas do dicionário
    INSERT INTO tmp_busca_alt (grupo, alt, sigla, likev, rx)
    SELECT u.grupo, d.forma, IF(d.forma_sigla = 1 AND d.forma REGEXP '^[[:alnum:]]+$', 1, 0), '', '' FROM tmp_busca_dic_usado u JOIN tmp_busca_dic d ON d.entrada = u.entrada;
    INSERT INTO tmp_busca_alt (grupo, alt, sigla, likev, rx)
    SELECT u.grupo, REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(d.forma),'á','a'),'à','a'),'â','a'),'ã','a'),'ä','a'),'é','e'),'ê','e'),'è','e'),'í','i'),'ó','o'),'ô','o'),'õ','o'),'ú','u'),'ü','u'),'ç','c'), 0, '', '' FROM tmp_busca_dic_usado u JOIN tmp_busca_dic d ON d.entrada = u.entrada
     WHERE d.forma_sigla = 0 AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(d.forma),'á','a'),'à','a'),'â','a'),'ã','a'),'ä','a'),'é','e'),'ê','e'),'è','e'),'í','i'),'ó','o'),'ô','o'),'õ','o'),'ú','u'),'ü','u'),'ç','c') <> LOWER(d.forma) COLLATE utf8mb4_bin;
    UPDATE tmp_busca_alt SET
        likev = REPLACE(REPLACE(REPLACE(alt, '\\', '\\\\'), '%', '\\%'), '_', '\\_'),
        rx = CONCAT('(^|[^[:alnum:]])', REGEXP_REPLACE(alt, '([.*+?^$|(){}\\[\\]\\\\])', '\\\\$1'), '([^[:alnum:]]|$)'),
        -- forma normalizada como busca_indice.norm; sigla com espaços em volta = palavra inteira
        chk = IF(sigla = 1, CAST(CONCAT(' ', TRIM(REGEXP_REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(alt),'á','a'),'à','a'),'â','a'),'ã','a'),'ä','a'),'é','e'),'ê','e'),'è','e'),'ë','e'),'í','i'),'ì','i'),'î','i'),'ï','i'),'ó','o'),'ò','o'),'ô','o'),'õ','o'),'ö','o'),'ú','u'),'ù','u'),'û','u'),'ü','u'),'ç','c'),'ñ','n'), '[^a-z0-9]+', ' ')), ' ') AS BINARY), CAST(TRIM(REGEXP_REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(alt),'á','a'),'à','a'),'â','a'),'ã','a'),'ä','a'),'é','e'),'ê','e'),'è','e'),'ë','e'),'í','i'),'ì','i'),'î','i'),'ï','i'),'ó','o'),'ò','o'),'ô','o'),'õ','o'),'ö','o'),'ú','u'),'ù','u'),'û','u'),'ü','u'),'ç','c'),'ñ','n'), '[^a-z0-9]+', ' ')) AS BINARY));
    DELETE FROM tmp_busca_alt WHERE TRIM(chk) = _binary'';

    -- grupos (tabela à parte: o MySQL não abre a mesma tabela temporária duas vezes na mesma consulta)
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_gt;
    CREATE TEMPORARY TABLE tmp_busca_gt (grupo INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
    INSERT IGNORE INTO tmp_busca_gt SELECT DISTINCT grupo FROM tmp_busca_alt;
    SELECT COUNT(*) INTO p_qtd FROM tmp_busca_gt;
    SELECT GROUP_CONCAT(x.g ORDER BY x.grupo SEPARATOR ' ') INTO p_match FROM (
        SELECT a.grupo, CONCAT('+(', GROUP_CONCAT(CONCAT('"', REPLACE(a.alt, '"', ' '), '"') SEPARATOR ' '), ')') AS g FROM tmp_busca_alt a GROUP BY a.grupo) x;
    SELECT GROUP_CONCAT(DISTINCT u.contexto SEPARATOR ',') INTO p_contexto FROM tmp_busca_dic_usado u WHERE COALESCE(u.contexto, '') <> '';
    SELECT GROUP_CONCAT(a.rx SEPARATOR '|') INTO p_rx FROM tmp_busca_alt a;
    SET p_match = COALESCE(p_match, ''); SET p_contexto = REPLACE(COALESCE(p_contexto, ''), ' ', ''); SET p_rx = COALESCE(p_rx, '');
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_frase;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_dic;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_busca_pesquisar`

-- @@
-- Pesquisa com as permissões do usuário.
--   p_termo        texto para MATCH ... AGAINST em modo booleano (montado pela API; vazio = sem termo)
--   p_filtros      {"tipos":[...], "formatos":[...], "situacoes":[...], "unidades":[...], "item":"REGISTRO|ARQUIVO", "validade_ate":"AAAA-MM-DD"}
--   p_permissoes   [{"m":"LICENCAS","t":1}, ...] = transações com Visualizar (t = Visualizar total)
--   p_admin        1 = ADMINISTRADOR (vê tudo)
-- Resultados: 1) total  2) página  3) tipos  4) formatos  5) situações  6) unidades (contagens do conjunto filtrado)
CREATE PROCEDURE `sp_busca_pesquisar`(IN p_termo VARCHAR(1000) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci, IN p_filtros JSON, IN p_permissoes JSON, IN p_admin TINYINT,
    IN p_usuario INT, IN p_ordem VARCHAR(20) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci, IN p_limite INT, IN p_offset INT)
BEGIN
    DECLARE v_tipos JSON DEFAULT JSON_EXTRACT(COALESCE(p_filtros, JSON_OBJECT()), '$.tipos');
    DECLARE v_formatos JSON DEFAULT JSON_EXTRACT(COALESCE(p_filtros, JSON_OBJECT()), '$.formatos');
    DECLARE v_situacoes JSON DEFAULT JSON_EXTRACT(COALESCE(p_filtros, JSON_OBJECT()), '$.situacoes');
    DECLARE v_unidades JSON DEFAULT JSON_EXTRACT(COALESCE(p_filtros, JSON_OBJECT()), '$.unidades');
    DECLARE v_item VARCHAR(10) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT JSON_UNQUOTE(JSON_EXTRACT(COALESCE(p_filtros, JSON_OBJECT()), '$.item'));
    DECLARE v_validade_ate DATE DEFAULT NULL;
    DECLARE v_termo VARCHAR(1000) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT TRIM(COALESCE(p_termo, ''));
    DECLARE v_qtd_termos INT DEFAULT 0;
    DECLARE v_match VARCHAR(4000) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT '';
    DECLARE v_contexto VARCHAR(1000) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT '';
    DECLARE v_rx VARCHAR(4000) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT '';
    DECLARE v_primeiro VARCHAR(700) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL;
    DECLARE v_primeiro_sigla TINYINT DEFAULT 0;
    DECLARE v_primeiro_rx VARCHAR(1200) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL;
    DECLARE v_literal TINYINT DEFAULT IF(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(p_filtros, JSON_OBJECT()), '$.literal')) IN ('1', 'true'), 1, 0);
    -- Ordenação: relevancia | <coluna> | <coluna>_asc | <coluna>_desc  (coluna: nome, titulo, tipo, situacao, unidade, validade, modificado)
    DECLARE v_col VARCHAR(20) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT SUBSTRING_INDEX(COALESCE(p_ordem, 'relevancia'), '_', 1);
    DECLARE v_desc TINYINT DEFAULT IF(COALESCE(p_ordem, '') LIKE '%\_desc' OR p_ordem = 'modificado', 1, 0);
    SET v_validade_ate = STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(p_filtros, JSON_OBJECT()), '$.validade_ate')), '%Y-%m-%d');
    IF v_item NOT IN ('REGISTRO', 'ARQUIVO') THEN SET v_item = NULL; END IF;
    IF v_col NOT IN ('nome', 'titulo', 'tipo', 'situacao', 'unidade', 'responsavel', 'validade', 'modificado') THEN SET v_col = NULL; END IF;
    SET p_limite = LEAST(GREATEST(COALESCE(p_limite, 25), 1), 5000);   -- até 5000: exportação para o Excel
    SET p_offset = GREATEST(COALESCE(p_offset, 0), 0);

    CALL `sp_busca_termos`(v_termo, v_literal, v_qtd_termos, v_match, v_contexto, v_rx);
    SELECT a.alt, a.sigla, a.rx INTO v_primeiro, v_primeiro_sigla, v_primeiro_rx FROM tmp_busca_alt a ORDER BY a.grupo, a.id LIMIT 1;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_perm;
    CREATE TEMPORARY TABLE tmp_busca_perm (transacao VARCHAR(80) NOT NULL PRIMARY KEY, total TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    IF p_permissoes IS NOT NULL AND JSON_LENGTH(p_permissoes) > 0 THEN
        INSERT INTO tmp_busca_perm (transacao, total)
        SELECT UPPER(j.m), MAX(COALESCE(j.t, 0)) FROM JSON_TABLE(p_permissoes, '$[*]' COLUMNS (m VARCHAR(80) PATH '$.m', t TINYINT PATH '$.t')) j
         WHERE j.m IS NOT NULL GROUP BY UPPER(j.m);
    END IF;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_unid;
    CREATE TEMPORARY TABLE tmp_busca_unid (codunidade INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
    INSERT IGNORE INTO tmp_busca_unid SELECT CODUNIDADE FROM `vw_portaria_usuario_unidades` WHERE CODUSUARIO = p_usuario AND CODUNIDADE IS NOT NULL;

    DROP TEMPORARY TABLE IF EXISTS tmp_busca_res;
    CREATE TEMPORARY TABLE tmp_busca_res (codbusca BIGINT UNSIGNED NOT NULL PRIMARY KEY, relevancia DOUBLE NOT NULL DEFAULT 0,
        ordem VARCHAR(300) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL) ENGINE=InnoDB;
    INSERT INTO tmp_busca_res (codbusca, relevancia)
    SELECT i.codbusca, IF(v_match = '', 0, MATCH(i.nome, i.titulo, i.descricao, i.texto) AGAINST (v_match IN BOOLEAN MODE)
           -- contexto do termo do dicionário (ASO → documentos de funcionário) e termo visível no nome / título sobem na ordem
           + IF(v_contexto <> '' AND FIND_IN_SET(i.tipo, v_contexto) > 0, 1000, 0)
           + IF(v_rx <> '' AND REGEXP_LIKE(CONCAT_WS(' ', i.nome, i.titulo, i.descricao), v_rx, 'i'), 100, 0))
      FROM `busca_indice` i
      LEFT JOIN tmp_busca_perm pm ON pm.transacao = i.transacao
      LEFT JOIN tmp_busca_unid un ON un.codunidade = i.codunidade
     WHERE (v_match = '' OR MATCH(i.nome, i.titulo, i.descricao, i.texto) AGAINST (v_match IN BOOLEAN MODE))
       -- cada grupo de termos tem de aparecer de fato (o ngram também acha pedaços separados por espaço): alguma alternativa
       -- do grupo como trecho, ou como palavra inteira se for sigla
       AND (v_qtd_termos = 0 OR NOT EXISTS (SELECT 1 FROM tmp_busca_gt gg WHERE NOT EXISTS (
              SELECT 1 FROM tmp_busca_alt ta WHERE ta.grupo = gg.grupo AND IF(i.norm IS NOT NULL, LOCATE(ta.chk, i.norm) > 0,
                  IF(ta.sigla = 1, REGEXP_LIKE(CONCAT_WS(' ', i.nome, i.titulo, i.descricao, i.texto), ta.rx, 'i'),
                     CONCAT_WS(' ', i.nome, i.titulo, i.descricao, i.texto) LIKE CONCAT('%', ta.likev, '%'))))))
       AND (p_admin = 1 OR (pm.transacao IS NOT NULL AND (
              i.escopo = 'NENHUM'
              OR (i.escopo = 'USUARIO' AND (pm.total = 1 OR i.codusuario = p_usuario))
              OR (i.escopo = 'UNIDADE' AND un.codunidade IS NOT NULL))))
       AND (v_tipos IS NULL OR JSON_LENGTH(v_tipos) = 0 OR JSON_CONTAINS(v_tipos, JSON_QUOTE(i.tipo)))
       AND (v_formatos IS NULL OR JSON_LENGTH(v_formatos) = 0 OR JSON_CONTAINS(v_formatos, JSON_QUOTE(i.formato)))
       AND (v_situacoes IS NULL OR JSON_LENGTH(v_situacoes) = 0 OR JSON_CONTAINS(v_situacoes, JSON_QUOTE(COALESCE(i.situacao, ''))))
       AND (v_unidades IS NULL OR JSON_LENGTH(v_unidades) = 0 OR JSON_CONTAINS(v_unidades, JSON_QUOTE(COALESCE(i.unidade, ''))))
       AND (v_item IS NULL OR i.item = v_item)
       AND (v_validade_ate IS NULL OR (i.validade IS NOT NULL AND i.validade <= v_validade_ate));

    -- Chave de ordenação da coluna escolhida no grid (texto; datas como AAAA-MM-DD; tipo pelo nome exibido)
    IF v_col IS NOT NULL THEN
        UPDATE tmp_busca_res r JOIN `busca_indice` i ON i.codbusca = r.codbusca
           SET r.ordem = LEFT(NULLIF(TRIM(CASE v_col
                WHEN 'nome' THEN i.nome
                WHEN 'titulo' THEN IF(i.item = 'ARQUIVO', i.titulo, i.descricao)
                WHEN 'tipo' THEN CONCAT(CASE i.tipo WHEN 'licencas' THEN 'Licença' WHEN 'controles' THEN 'Controle de documento' WHEN 'requisitos' THEN 'Requisito legal'
                    WHEN 'treinamentos' THEN 'Treinamento' WHEN 'infraestrutura' THEN 'Infraestrutura' WHEN 'fornecedorcriterios' THEN 'Documento de fornecedor'
                    WHEN 'funcionariocriterios' THEN 'Documento de funcionário' WHEN 'funcionariotreinamentos' THEN 'Treinamento de funcionário' ELSE i.tipo END, ' ', COALESCE(i.formato, ''))
                WHEN 'situacao' THEN i.situacao
                WHEN 'unidade' THEN i.unidade
                WHEN 'responsavel' THEN i.responsavel
                WHEN 'validade' THEN DATE_FORMAT(i.validade, '%Y-%m-%d')
                WHEN 'modificado' THEN DATE_FORMAT(i.modificado_em, '%Y-%m-%d %H:%i:%s')
              END), ''), 300);
    END IF;

    SELECT COUNT(*) AS total FROM tmp_busca_res;
    SELECT i.codbusca, i.tipo, i.item, i.chave, i.arquivo, i.nome, i.titulo, i.descricao, i.situacao, i.situacao_cor, i.validade, i.unidade,
           i.formato, i.tamanho, i.modificado_em, i.transacao, i.pagina, CAST(i.filtros AS CHAR) AS filtros, i.ged_tipo, i.ged_valor, i.indexado_em,
           r.relevancia, i.responsavel, i.grupo_tipo, i.grupo_chave, i.grupo_nome, i.grupo_detalhe,
           CASE WHEN COALESCE(v_primeiro, '') = '' OR COALESCE(i.texto, '') = ''
                    OR IF(v_primeiro_sigla = 1, REGEXP_LIKE(CONCAT_WS(' ', i.nome, i.titulo, i.descricao), v_primeiro_rx, 'i'),
                          CONCAT_WS(' ', i.nome, i.titulo, i.descricao) LIKE CONCAT('%', v_primeiro, '%'))
                    OR IF(v_primeiro_sigla = 1, REGEXP_INSTR(i.texto, v_primeiro_rx, 1, 1, 0, 'i'), LOCATE(v_primeiro, i.texto)) = 0 THEN NULL
               ELSE CONCAT(IF(IF(v_primeiro_sigla = 1, REGEXP_INSTR(i.texto, v_primeiro_rx, 1, 1, 0, 'i'), LOCATE(v_primeiro, i.texto)) > 70, '…', ''),
                    SUBSTRING(i.texto, GREATEST(1, IF(v_primeiro_sigla = 1, REGEXP_INSTR(i.texto, v_primeiro_rx, 1, 1, 0, 'i'), LOCATE(v_primeiro, i.texto)) - 70), CHAR_LENGTH(v_primeiro) + 140),
                    IF(IF(v_primeiro_sigla = 1, REGEXP_INSTR(i.texto, v_primeiro_rx, 1, 1, 0, 'i'), LOCATE(v_primeiro, i.texto)) + CHAR_LENGTH(v_primeiro) + 70 < CHAR_LENGTH(i.texto), '…', '')) END AS trecho
      FROM tmp_busca_res r JOIN `busca_indice` i ON i.codbusca = r.codbusca
     ORDER BY (v_col IS NOT NULL AND r.ordem IS NULL),          -- vazios sempre no fim
              CASE WHEN v_desc = 0 THEN r.ordem END ASC,
              CASE WHEN v_desc = 1 THEN r.ordem END DESC,
              r.relevancia DESC, i.titulo, i.item DESC, i.nome, i.codbusca
     LIMIT p_limite OFFSET p_offset;
    SELECT i.tipo AS valor, COUNT(*) AS qtd FROM tmp_busca_res r JOIN `busca_indice` i ON i.codbusca = r.codbusca GROUP BY i.tipo ORDER BY qtd DESC;
    SELECT i.formato AS valor, COUNT(*) AS qtd FROM tmp_busca_res r JOIN `busca_indice` i ON i.codbusca = r.codbusca GROUP BY i.formato ORDER BY qtd DESC LIMIT 15;
    SELECT COALESCE(i.situacao, '') AS valor, MAX(i.situacao_cor) AS cor, COUNT(*) AS qtd FROM tmp_busca_res r JOIN `busca_indice` i ON i.codbusca = r.codbusca GROUP BY COALESCE(i.situacao, '') ORDER BY qtd DESC LIMIT 20;
    SELECT COALESCE(i.unidade, '') AS valor, COUNT(*) AS qtd FROM tmp_busca_res r JOIN `busca_indice` i ON i.codbusca = r.codbusca GROUP BY COALESCE(i.unidade, '') ORDER BY qtd DESC LIMIT 20;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_res;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_perm;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_unid;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_alt;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_gt;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_dic_usado;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_busca_pesquisar_negocios`

-- @@
-- Pesquisa agrupada pelo negócio (mesmos parâmetros e permissões de sp_busca_pesquisar; p_limite/p_offset contam grupos).
-- Grupo = grupo_tipo + grupo_chave: o cadastro (licença, controle, requisito, treinamento, infraestrutura) com os seus
-- arquivos, ou o fornecedor / funcionário da Portaria com os seus critérios, treinamentos e arquivos.
-- Resultados:
--   1) total de grupos
--   2) grupos da página: dados do negócio (o registro do cadastro, quando houver), relevância, acertos e contagens
--      (registros, arquivos, pendentes = reprovado/vencido, a_vencer) do que o usuário pode ver no grupo
--   3) itens dos grupos da página (até 40 por grupo; os que bateram com a pesquisa primeiro; registro antes dos arquivos)
--   4) vínculos dos grupos da página por relação do catálogo busca_relacao, nos dois sentidos (até 30 por relação), com o
--      rótulo e o ícone da seção; só os negócios vizinhos que o usuário pode ver
--   p_filtros.grupo {"t","c"}: só aquele negócio ("Ver na pesquisa" de um vínculo)
--   5..8) contagens por tipo, formato, situação e unidade dos itens encontrados (para o Refinar por)
--   9) termos do dicionário usados (p_filtros.literal = 1: sem dicionário, só o texto digitado)
--   p_filtros.semantico [{"id","s"}] (migração 346): só os itens escolhidos pelo sentido, com a semelhança como relevância
CREATE PROCEDURE `sp_busca_pesquisar_negocios`(IN p_termo VARCHAR(1000) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci, IN p_filtros JSON, IN p_permissoes JSON,
    IN p_admin TINYINT, IN p_usuario INT, IN p_ordem VARCHAR(20) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci, IN p_limite INT, IN p_offset INT)
BEGIN
    DECLARE v_tipos JSON DEFAULT JSON_EXTRACT(COALESCE(p_filtros, JSON_OBJECT()), '$.tipos');
    DECLARE v_formatos JSON DEFAULT JSON_EXTRACT(COALESCE(p_filtros, JSON_OBJECT()), '$.formatos');
    DECLARE v_situacoes JSON DEFAULT JSON_EXTRACT(COALESCE(p_filtros, JSON_OBJECT()), '$.situacoes');
    DECLARE v_unidades JSON DEFAULT JSON_EXTRACT(COALESCE(p_filtros, JSON_OBJECT()), '$.unidades');
    DECLARE v_item VARCHAR(10) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT JSON_UNQUOTE(JSON_EXTRACT(COALESCE(p_filtros, JSON_OBJECT()), '$.item'));
    DECLARE v_validade_ate DATE DEFAULT NULL;
    DECLARE v_termo VARCHAR(1000) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT TRIM(COALESCE(p_termo, ''));
    DECLARE v_qtd_termos INT DEFAULT 0;
    DECLARE v_match VARCHAR(4000) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT '';
    DECLARE v_contexto VARCHAR(1000) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT '';
    DECLARE v_rx VARCHAR(4000) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT '';
    DECLARE v_primeiro VARCHAR(700) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL;
    DECLARE v_primeiro_sigla TINYINT DEFAULT 0;
    DECLARE v_primeiro_rx VARCHAR(1200) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL;
    DECLARE v_literal TINYINT DEFAULT IF(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(p_filtros, JSON_OBJECT()), '$.literal')) IN ('1', 'true'), 1, 0);
    DECLARE v_col VARCHAR(20) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT SUBSTRING_INDEX(COALESCE(p_ordem, 'relevancia'), '_', 1);
    DECLARE v_desc TINYINT DEFAULT IF(COALESCE(p_ordem, '') LIKE '%\_desc' OR p_ordem = 'modificado', 1, 0);
    -- "Ver na pesquisa" de um vínculo: só o negócio escolhido ({"grupo": {"t": grupo_tipo, "c": grupo_chave}})
    DECLARE v_grupo_tipo VARCHAR(40) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT JSON_UNQUOTE(JSON_EXTRACT(COALESCE(p_filtros, JSON_OBJECT()), '$.grupo.t'));
    DECLARE v_grupo_chave VARCHAR(100) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT JSON_UNQUOTE(JSON_EXTRACT(COALESCE(p_filtros, JSON_OBJECT()), '$.grupo.c'));
    -- Migração 346 (busca semântica): {"semantico": [{"id": codbusca, "s": semelhança}, ...]} — só esses itens, com a semelhança
    -- como relevância (o texto vem vazio: a escolha foi feita pelo sentido, no PHP)
    DECLARE v_sem JSON DEFAULT JSON_EXTRACT(COALESCE(p_filtros, JSON_OBJECT()), '$.semantico');
    SET v_validade_ate = STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(p_filtros, JSON_OBJECT()), '$.validade_ate')), '%Y-%m-%d');
    IF v_item NOT IN ('REGISTRO', 'ARQUIVO') THEN SET v_item = NULL; END IF;
    IF v_col NOT IN ('nome', 'titulo', 'tipo', 'situacao', 'unidade', 'responsavel', 'validade', 'modificado') THEN SET v_col = NULL; END IF;
    IF v_grupo_tipo = '' OR v_grupo_chave = '' OR v_grupo_chave IS NULL THEN SET v_grupo_tipo = NULL; END IF;
    SET p_limite = LEAST(GREATEST(COALESCE(p_limite, 25), 1), 100);
    SET p_offset = GREATEST(COALESCE(p_offset, 0), 0);

    CALL `sp_busca_termos`(v_termo, v_literal, v_qtd_termos, v_match, v_contexto, v_rx);
    -- Trecho: primeira forma pesquisada (para mostrar onde ela aparece quando não está no nome / título / descrição)
    SELECT a.alt, a.sigla, a.rx INTO v_primeiro, v_primeiro_sigla, v_primeiro_rx FROM tmp_busca_alt a ORDER BY a.grupo, a.id LIMIT 1;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_perm;
    CREATE TEMPORARY TABLE tmp_busca_perm (transacao VARCHAR(80) NOT NULL PRIMARY KEY, total TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    IF p_permissoes IS NOT NULL AND JSON_LENGTH(p_permissoes) > 0 THEN
        INSERT INTO tmp_busca_perm (transacao, total)
        SELECT UPPER(j.m), MAX(COALESCE(j.t, 0)) FROM JSON_TABLE(p_permissoes, '$[*]' COLUMNS (m VARCHAR(80) PATH '$.m', t TINYINT PATH '$.t')) j
         WHERE j.m IS NOT NULL GROUP BY UPPER(j.m);
    END IF;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_unid;
    CREATE TEMPORARY TABLE tmp_busca_unid (codunidade INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
    INSERT IGNORE INTO tmp_busca_unid SELECT CODUNIDADE FROM `vw_portaria_usuario_unidades` WHERE CODUSUARIO = p_usuario AND CODUNIDADE IS NOT NULL;

    IF v_sem IS NOT NULL AND JSON_TYPE(v_sem) <> 'ARRAY' THEN SET v_sem = NULL; END IF;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_sem;
    CREATE TEMPORARY TABLE tmp_busca_sem (codbusca BIGINT UNSIGNED NOT NULL PRIMARY KEY, score DOUBLE NOT NULL DEFAULT 0) ENGINE=InnoDB;
    IF v_sem IS NOT NULL THEN
        INSERT IGNORE INTO tmp_busca_sem (codbusca, score)
        SELECT j.id, COALESCE(j.s, 0) FROM JSON_TABLE(v_sem, '$[*]' COLUMNS (id BIGINT UNSIGNED PATH '$.id', s DOUBLE PATH '$.s')) j WHERE j.id IS NOT NULL;
    END IF;

    -- 1. Itens encontrados (texto + filtros + permissões)
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_res;
    CREATE TEMPORARY TABLE tmp_busca_res (codbusca BIGINT UNSIGNED NOT NULL PRIMARY KEY, relevancia DOUBLE NOT NULL DEFAULT 0,
        grupo_tipo VARCHAR(40) NOT NULL, grupo_chave VARCHAR(100) NOT NULL, KEY (grupo_tipo, grupo_chave)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    INSERT INTO tmp_busca_res (codbusca, relevancia, grupo_tipo, grupo_chave)
    SELECT i.codbusca, IF(v_sem IS NOT NULL, COALESCE(sem.score, 0) * 100, IF(v_match = '', 0, MATCH(i.nome, i.titulo, i.descricao, i.texto) AGAINST (v_match IN BOOLEAN MODE)
           -- contexto do termo do dicionário (ASO → documentos de funcionário) e termo visível no nome / título sobem na ordem
           + IF(v_contexto <> '' AND FIND_IN_SET(i.tipo, v_contexto) > 0, 1000, 0)
           + IF(v_rx <> '' AND REGEXP_LIKE(CONCAT_WS(' ', i.nome, i.titulo, i.descricao), v_rx, 'i'), 100, 0))),
           COALESCE(i.grupo_tipo, i.tipo), COALESCE(i.grupo_chave, i.chave)
      FROM `busca_indice` i
      LEFT JOIN tmp_busca_sem sem ON sem.codbusca = i.codbusca
      LEFT JOIN tmp_busca_perm pm ON pm.transacao = i.transacao
      LEFT JOIN tmp_busca_unid un ON un.codunidade = i.codunidade
     WHERE (v_sem IS NULL OR sem.codbusca IS NOT NULL)
       AND (v_match = '' OR MATCH(i.nome, i.titulo, i.descricao, i.texto) AGAINST (v_match IN BOOLEAN MODE))
       -- cada grupo de termos tem de aparecer de fato (o ngram também acha pedaços separados por espaço): alguma alternativa
       -- do grupo como trecho, ou como palavra inteira se for sigla
       AND (v_qtd_termos = 0 OR NOT EXISTS (SELECT 1 FROM tmp_busca_gt gg WHERE NOT EXISTS (
              SELECT 1 FROM tmp_busca_alt ta WHERE ta.grupo = gg.grupo AND IF(i.norm IS NOT NULL, LOCATE(ta.chk, i.norm) > 0,
                  IF(ta.sigla = 1, REGEXP_LIKE(CONCAT_WS(' ', i.nome, i.titulo, i.descricao, i.texto), ta.rx, 'i'),
                     CONCAT_WS(' ', i.nome, i.titulo, i.descricao, i.texto) LIKE CONCAT('%', ta.likev, '%'))))))
       AND (p_admin = 1 OR (pm.transacao IS NOT NULL AND (
              i.escopo = 'NENHUM'
              OR (i.escopo = 'USUARIO' AND (pm.total = 1 OR i.codusuario = p_usuario))
              OR (i.escopo = 'UNIDADE' AND un.codunidade IS NOT NULL))))
       AND (v_tipos IS NULL OR JSON_LENGTH(v_tipos) = 0 OR JSON_CONTAINS(v_tipos, JSON_QUOTE(i.tipo)))
       AND (v_formatos IS NULL OR JSON_LENGTH(v_formatos) = 0 OR JSON_CONTAINS(v_formatos, JSON_QUOTE(i.formato)))
       AND (v_situacoes IS NULL OR JSON_LENGTH(v_situacoes) = 0 OR JSON_CONTAINS(v_situacoes, JSON_QUOTE(COALESCE(i.situacao, ''))))
       AND (v_unidades IS NULL OR JSON_LENGTH(v_unidades) = 0 OR JSON_CONTAINS(v_unidades, JSON_QUOTE(COALESCE(i.unidade, ''))))
       AND (v_item IS NULL OR i.item = v_item)
       AND (v_validade_ate IS NULL OR (i.validade IS NOT NULL AND i.validade <= v_validade_ate))
       AND (v_grupo_tipo IS NULL OR (COALESCE(i.grupo_tipo, i.tipo) = v_grupo_tipo AND COALESCE(i.grupo_chave, i.chave) = v_grupo_chave));

    -- 2. Grupos (negócios) dos itens encontrados
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_grp;
    CREATE TEMPORARY TABLE tmp_busca_grp (grupo_tipo VARCHAR(40) NOT NULL, grupo_chave VARCHAR(100) NOT NULL, relevancia DOUBLE NOT NULL DEFAULT 0,
        acertos INT NOT NULL DEFAULT 0, cabecalho BIGINT UNSIGNED NULL, nome VARCHAR(500) NULL, detalhe VARCHAR(500) NULL,
        situacao VARCHAR(100) NULL, cor VARCHAR(30) NULL, pior INT NOT NULL DEFAULT 0, unidade VARCHAR(500) NULL, responsavel VARCHAR(500) NULL, validade DATE NULL,
        modificado DATETIME NULL, ordem VARCHAR(300) NULL, PRIMARY KEY (grupo_tipo, grupo_chave)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    INSERT INTO tmp_busca_grp (grupo_tipo, grupo_chave, relevancia, acertos, nome, detalhe, pior, unidade, responsavel, validade, modificado)
    SELECT r.grupo_tipo, r.grupo_chave, MAX(r.relevancia), COUNT(*), MAX(i.grupo_nome), MAX(i.grupo_detalhe),
           MAX(CASE i.situacao_cor WHEN 'reprovado' THEN 5 WHEN 'vencido' THEN 4 WHEN 'a-vencer' THEN 3 WHEN 'sem-situacao' THEN 2 WHEN 'aprovado' THEN 1 ELSE 0 END),
           MIN(i.unidade), LEFT(GROUP_CONCAT(DISTINCT i.responsavel ORDER BY i.responsavel SEPARATOR '; '), 500), MIN(i.validade), MAX(i.modificado_em)
      FROM tmp_busca_res r JOIN `busca_indice` i ON i.codbusca = r.codbusca
     GROUP BY r.grupo_tipo, r.grupo_chave;
    -- Negócio que é um cadastro: dados do próprio registro
    UPDATE tmp_busca_grp g JOIN `busca_indice` h ON h.tipo = g.grupo_tipo AND h.item = 'REGISTRO' AND h.chave = g.grupo_chave AND h.arquivo = ''
       SET g.cabecalho = h.codbusca, g.nome = h.nome, g.situacao = h.situacao, g.cor = h.situacao_cor, g.unidade = h.unidade, g.validade = h.validade,
           g.responsavel = h.responsavel;
    -- Fornecedor / funcionário: a pior situação dos itens encontrados
    UPDATE tmp_busca_grp SET cor = ELT(pior, 'aprovado', 'sem-situacao', 'a-vencer', 'vencido', 'reprovado') WHERE cabecalho IS NULL AND pior > 0;

    IF v_col IS NOT NULL THEN
        UPDATE tmp_busca_grp g SET g.ordem = LEFT(NULLIF(TRIM(CASE v_col
            WHEN 'nome' THEN g.nome
            WHEN 'titulo' THEN g.detalhe
            WHEN 'tipo' THEN CASE g.grupo_tipo WHEN 'licencas' THEN 'Licença' WHEN 'controles' THEN 'Controle de documento' WHEN 'requisitos' THEN 'Requisito legal'
                WHEN 'treinamentos' THEN 'Treinamento' WHEN 'infraestrutura' THEN 'Infraestrutura' WHEN 'fornecedor' THEN 'Fornecedor'
                WHEN 'funcionario' THEN 'Funcionário' ELSE g.grupo_tipo END
            WHEN 'situacao' THEN COALESCE(g.situacao, ELT(g.pior, 'Aprovado', 'Sem situação', 'Vence em até 30 dias', 'Vencido', 'Reprovado'))
            WHEN 'unidade' THEN g.unidade
            WHEN 'responsavel' THEN g.responsavel
            WHEN 'validade' THEN DATE_FORMAT(g.validade, '%Y-%m-%d')
            WHEN 'modificado' THEN DATE_FORMAT(g.modificado, '%Y-%m-%d %H:%i:%s')
          END), ''), 300);
    END IF;

    -- 3. Página de grupos
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_pag;
    CREATE TEMPORARY TABLE tmp_busca_pag (grupo_tipo VARCHAR(40) NOT NULL, grupo_chave VARCHAR(100) NOT NULL, posicao INT NOT NULL,
        PRIMARY KEY (grupo_tipo, grupo_chave)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    INSERT INTO tmp_busca_pag (grupo_tipo, grupo_chave, posicao)
    SELECT x.grupo_tipo, x.grupo_chave, x.posicao FROM (
        SELECT g.grupo_tipo, g.grupo_chave, ROW_NUMBER() OVER (ORDER BY (v_col IS NOT NULL AND g.ordem IS NULL),
               CASE WHEN v_desc = 0 THEN g.ordem END ASC, CASE WHEN v_desc = 1 THEN g.ordem END DESC,
               g.relevancia DESC, g.acertos DESC, g.nome, g.grupo_tipo, g.grupo_chave) AS posicao
          FROM tmp_busca_grp g) x
     WHERE x.posicao > p_offset AND x.posicao <= p_offset + p_limite;

    -- Contagens do que o usuário pode ver em cada grupo da página
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_cnt;
    CREATE TEMPORARY TABLE tmp_busca_cnt (grupo_tipo VARCHAR(40) NOT NULL, grupo_chave VARCHAR(100) NOT NULL, registros INT NOT NULL, arquivos INT NOT NULL,
        pendentes INT NOT NULL, a_vencer INT NOT NULL, PRIMARY KEY (grupo_tipo, grupo_chave)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    INSERT INTO tmp_busca_cnt
    SELECT p.grupo_tipo, p.grupo_chave, SUM(i.item = 'REGISTRO'), SUM(i.item = 'ARQUIVO'),
           SUM(i.item = 'REGISTRO' AND COALESCE(i.situacao_cor, '') IN ('reprovado', 'vencido')), SUM(i.item = 'REGISTRO' AND COALESCE(i.situacao_cor, '') = 'a-vencer')
      FROM tmp_busca_pag p
      JOIN `busca_indice` i ON i.grupo_tipo = p.grupo_tipo AND i.grupo_chave = p.grupo_chave
      LEFT JOIN tmp_busca_perm pm ON pm.transacao = i.transacao
      LEFT JOIN tmp_busca_unid un ON un.codunidade = i.codunidade
     WHERE p_admin = 1 OR (pm.transacao IS NOT NULL AND (i.escopo = 'NENHUM' OR (i.escopo = 'USUARIO' AND (pm.total = 1 OR i.codusuario = p_usuario))
           OR (i.escopo = 'UNIDADE' AND un.codunidade IS NOT NULL)))
     GROUP BY p.grupo_tipo, p.grupo_chave;

    SELECT COUNT(*) AS total FROM tmp_busca_grp;
    SELECT g.grupo_tipo, g.grupo_chave, g.nome, g.detalhe, g.situacao, g.cor, g.unidade, g.responsavel, g.validade, g.modificado, g.relevancia, g.acertos,
           CASE WHEN COALESCE(v_primeiro, '') = '' OR COALESCE(h.texto, '') = ''
                    OR IF(v_primeiro_sigla = 1, REGEXP_LIKE(CONCAT_WS(' ', h.nome, h.titulo, h.descricao), v_primeiro_rx, 'i'),
                          CONCAT_WS(' ', h.nome, h.titulo, h.descricao) LIKE CONCAT('%', v_primeiro, '%'))
                    OR IF(v_primeiro_sigla = 1, REGEXP_INSTR(h.texto, v_primeiro_rx, 1, 1, 0, 'i'), LOCATE(v_primeiro, h.texto)) = 0 THEN NULL
               ELSE CONCAT(IF(IF(v_primeiro_sigla = 1, REGEXP_INSTR(h.texto, v_primeiro_rx, 1, 1, 0, 'i'), LOCATE(v_primeiro, h.texto)) > 70, '…', ''),
                    SUBSTRING(h.texto, GREATEST(1, IF(v_primeiro_sigla = 1, REGEXP_INSTR(h.texto, v_primeiro_rx, 1, 1, 0, 'i'), LOCATE(v_primeiro, h.texto)) - 70), CHAR_LENGTH(v_primeiro) + 140),
                    IF(IF(v_primeiro_sigla = 1, REGEXP_INSTR(h.texto, v_primeiro_rx, 1, 1, 0, 'i'), LOCATE(v_primeiro, h.texto)) + CHAR_LENGTH(v_primeiro) + 70 < CHAR_LENGTH(h.texto), '…', '')) END AS trecho,
           COALESCE(c.registros, 0) AS registros, COALESCE(c.arquivos, 0) AS arquivos, COALESCE(c.pendentes, 0) AS pendentes, COALESCE(c.a_vencer, 0) AS a_vencer,
           h.codbusca AS cab_codbusca, h.tipo AS cab_tipo, h.chave AS cab_chave, h.titulo AS cab_titulo, h.descricao AS cab_descricao,
           h.transacao AS cab_transacao, h.pagina AS cab_pagina, CAST(h.filtros AS CHAR) AS cab_filtros, h.ged_tipo AS cab_ged_tipo, h.ged_valor AS cab_ged_valor
      FROM tmp_busca_pag p
      JOIN tmp_busca_grp g ON g.grupo_tipo = p.grupo_tipo AND g.grupo_chave = p.grupo_chave
      LEFT JOIN tmp_busca_cnt c ON c.grupo_tipo = p.grupo_tipo AND c.grupo_chave = p.grupo_chave
      LEFT JOIN `busca_indice` h ON h.codbusca = g.cabecalho
     ORDER BY p.posicao;

    -- Itens dos grupos da página (cada registro seguido dos seus arquivos; os registros que bateram com a pesquisa primeiro)
    -- trecho só para os itens mostrados (até 40 por grupo)
    -- (a ordenação por grupo usa só as chaves; os demais campos vêm depois, só dos itens mostrados)
    SELECT z.grupo_tipo, z.grupo_chave, t.codbusca, t.tipo, t.item, t.chave, t.arquivo, t.nome, t.titulo, t.descricao, t.situacao, t.situacao_cor,
           t.validade, t.unidade, t.formato, t.tamanho, t.modificado_em, t.transacao, t.pagina, CAST(t.filtros AS CHAR) AS filtros, t.ged_tipo,
           t.ged_valor, t.indexado_em, z.encontrado, t.responsavel, z.reg_encontrado, z.reg_relevancia, z.n,
           IF(z.encontrado = 0, NULL, CASE WHEN COALESCE(v_primeiro, '') = '' OR COALESCE(t.texto, '') = ''
                    OR IF(v_primeiro_sigla = 1, REGEXP_LIKE(CONCAT_WS(' ', t.nome, t.titulo, t.descricao), v_primeiro_rx, 'i'),
                          CONCAT_WS(' ', t.nome, t.titulo, t.descricao) LIKE CONCAT('%', v_primeiro, '%'))
                    OR IF(v_primeiro_sigla = 1, REGEXP_INSTR(t.texto, v_primeiro_rx, 1, 1, 0, 'i'), LOCATE(v_primeiro, t.texto)) = 0 THEN NULL
               ELSE CONCAT(IF(IF(v_primeiro_sigla = 1, REGEXP_INSTR(t.texto, v_primeiro_rx, 1, 1, 0, 'i'), LOCATE(v_primeiro, t.texto)) > 70, '…', ''),
                    SUBSTRING(t.texto, GREATEST(1, IF(v_primeiro_sigla = 1, REGEXP_INSTR(t.texto, v_primeiro_rx, 1, 1, 0, 'i'), LOCATE(v_primeiro, t.texto)) - 70), CHAR_LENGTH(v_primeiro) + 140),
                    IF(IF(v_primeiro_sigla = 1, REGEXP_INSTR(t.texto, v_primeiro_rx, 1, 1, 0, 'i'), LOCATE(v_primeiro, t.texto)) + CHAR_LENGTH(v_primeiro) + 70 < CHAR_LENGTH(t.texto), '…', '')) END) AS trecho FROM (
        SELECT y.*, ROW_NUMBER() OVER (PARTITION BY y.grupo_tipo, y.grupo_chave
                   ORDER BY y.reg_encontrado DESC, y.reg_relevancia DESC, y.tipo, y.chave, y.item DESC, y.encontrado DESC, y.modificado_em DESC, y.nome) AS n
          FROM (
            SELECT p.grupo_tipo, p.grupo_chave, i.codbusca, i.tipo, i.item, i.chave, i.nome, i.modificado_em, (r.codbusca IS NOT NULL) AS encontrado,
                   MAX(r.codbusca IS NOT NULL) OVER (PARTITION BY p.grupo_tipo, p.grupo_chave, i.tipo, i.chave) AS reg_encontrado,
                   MAX(COALESCE(r.relevancia, 0)) OVER (PARTITION BY p.grupo_tipo, p.grupo_chave, i.tipo, i.chave) AS reg_relevancia
              FROM tmp_busca_pag p
              JOIN `busca_indice` i ON i.grupo_tipo = p.grupo_tipo AND i.grupo_chave = p.grupo_chave
              LEFT JOIN tmp_busca_res r ON r.codbusca = i.codbusca
              LEFT JOIN tmp_busca_perm pm ON pm.transacao = i.transacao
              LEFT JOIN tmp_busca_unid un ON un.codunidade = i.codunidade
             WHERE NOT (i.item = 'REGISTRO' AND i.tipo = p.grupo_tipo AND i.chave = p.grupo_chave)
               AND (p_admin = 1 OR (pm.transacao IS NOT NULL AND (i.escopo = 'NENHUM' OR (i.escopo = 'USUARIO' AND (pm.total = 1 OR i.codusuario = p_usuario))
                    OR (i.escopo = 'UNIDADE' AND un.codunidade IS NOT NULL))))
          ) y
    ) z JOIN `busca_indice` t ON t.codbusca = z.codbusca WHERE z.n <= 40 ORDER BY z.grupo_tipo, z.grupo_chave, z.n;

    -- Vínculos dos grupos da página, por relação do catálogo e nos dois sentidos (lado A lista os do lado B e vice-versa).
    -- O negócio vizinho aparece se o usuário pode ver algum item dele; dados do vizinho = o registro do cadastro (quando há).
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_nb;
    CREATE TEMPORARY TABLE tmp_busca_nb (grupo_tipo VARCHAR(40) NOT NULL, grupo_chave VARCHAR(100) NOT NULL, relacao VARCHAR(40) NOT NULL, lado CHAR(1) NOT NULL,
        nb_tipo VARCHAR(40) NOT NULL, nb_chave VARCHAR(100) NOT NULL, PRIMARY KEY (grupo_tipo, grupo_chave, relacao, lado, nb_tipo, nb_chave))
        ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    INSERT IGNORE INTO tmp_busca_nb
    SELECT p.grupo_tipo, p.grupo_chave, v.relacao, 'A', v.tipo_b, v.chave_b
      FROM tmp_busca_pag p JOIN `busca_vinculo` v ON v.tipo_a = p.grupo_tipo AND v.chave_a = p.grupo_chave
      JOIN `busca_relacao` r ON r.codigo = v.relacao AND r.ativo = 1;
    INSERT IGNORE INTO tmp_busca_nb
    SELECT p.grupo_tipo, p.grupo_chave, v.relacao, 'B', v.tipo_a, v.chave_a
      FROM tmp_busca_pag p JOIN `busca_vinculo` v ON v.tipo_b = p.grupo_tipo AND v.chave_b = p.grupo_chave
      JOIN `busca_relacao` r ON r.codigo = v.relacao AND r.ativo = 1;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_nbv;
    CREATE TEMPORARY TABLE tmp_busca_nbv (grupo_tipo VARCHAR(40) NOT NULL, grupo_chave VARCHAR(100) NOT NULL, relacao VARCHAR(40) NOT NULL, lado CHAR(1) NOT NULL,
        nb_tipo VARCHAR(40) NOT NULL, nb_chave VARCHAR(100) NOT NULL, cab BIGINT UNSIGNED NULL, nome VARCHAR(500) NULL, detalhe VARCHAR(500) NULL,
        pior INT NOT NULL DEFAULT 0, PRIMARY KEY (grupo_tipo, grupo_chave, relacao, lado, nb_tipo, nb_chave))
        ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    INSERT INTO tmp_busca_nbv
    SELECT nb.grupo_tipo, nb.grupo_chave, nb.relacao, nb.lado, nb.nb_tipo, nb.nb_chave,
           MIN(CASE WHEN i.item = 'REGISTRO' AND i.tipo = nb.nb_tipo AND i.chave = nb.nb_chave THEN i.codbusca END),
           MAX(i.grupo_nome), MAX(i.grupo_detalhe),
           MAX(CASE WHEN i.item <> 'REGISTRO' THEN 0 WHEN i.situacao_cor = 'reprovado' THEN 5 WHEN i.situacao_cor = 'vencido' THEN 4 WHEN i.situacao_cor = 'a-vencer' THEN 3
                    WHEN i.situacao_cor = 'sem-situacao' THEN 2 WHEN i.situacao_cor = 'aprovado' THEN 1 ELSE 0 END)
      FROM tmp_busca_nb nb
      JOIN `busca_indice` i ON i.grupo_tipo = nb.nb_tipo AND i.grupo_chave = nb.nb_chave
      LEFT JOIN tmp_busca_perm pm ON pm.transacao = i.transacao
      LEFT JOIN tmp_busca_unid un ON un.codunidade = i.codunidade
     WHERE p_admin = 1 OR (pm.transacao IS NOT NULL AND (i.escopo = 'NENHUM' OR (i.escopo = 'USUARIO' AND (pm.total = 1 OR i.codusuario = p_usuario))
           OR (i.escopo = 'UNIDADE' AND un.codunidade IS NOT NULL)))
     GROUP BY nb.grupo_tipo, nb.grupo_chave, nb.relacao, nb.lado, nb.nb_tipo, nb.nb_chave;
    SELECT x.* FROM (
        SELECT n.grupo_tipo, n.grupo_chave, n.relacao, n.lado, rl.ordem AS relacao_ordem,
               IF(n.lado = 'A', rl.rotulo_a_pt, rl.rotulo_b_pt) AS rotulo_pt, IF(n.lado = 'A', rl.rotulo_a_en, rl.rotulo_b_en) AS rotulo_en,
               IF(n.lado = 'A', rl.rotulo_a_es, rl.rotulo_b_es) AS rotulo_es, IF(n.lado = 'A', rl.icone_a, rl.icone_b) AS icone,
               n.nb_tipo, n.nb_chave, n.nome AS nb_nome, n.detalhe AS nb_detalhe, ELT(NULLIF(n.pior, 0), 'aprovado', 'sem-situacao', 'a-vencer', 'vencido', 'reprovado') AS nb_cor,
               h.codbusca, h.tipo, h.chave, h.nome, h.titulo, h.descricao, h.situacao, h.situacao_cor, h.validade, h.unidade, h.responsavel,
               h.transacao, h.pagina, CAST(h.filtros AS CHAR) AS filtros, h.ged_tipo, h.ged_valor,
               COUNT(*) OVER (PARTITION BY n.grupo_tipo, n.grupo_chave, n.relacao, n.lado) AS total_grupo,
               ROW_NUMBER() OVER (PARTITION BY n.grupo_tipo, n.grupo_chave, n.relacao, n.lado
                   ORDER BY CASE COALESCE(h.situacao_cor, ELT(NULLIF(n.pior, 0), 'aprovado', 'sem-situacao', 'a-vencer', 'vencido', 'reprovado'))
                            WHEN 'reprovado' THEN 1 WHEN 'vencido' THEN 2 WHEN 'a-vencer' THEN 3 ELSE 4 END, COALESCE(h.nome, n.nome), h.unidade) AS posicao
          FROM tmp_busca_nbv n
          JOIN `busca_relacao` rl ON rl.codigo = n.relacao
          LEFT JOIN `busca_indice` h ON h.codbusca = n.cab
    ) x WHERE x.posicao <= 30 ORDER BY x.grupo_tipo, x.grupo_chave, x.relacao_ordem, x.relacao, x.lado, x.posicao;

    SELECT i.tipo AS valor, COUNT(*) AS qtd FROM tmp_busca_res r JOIN `busca_indice` i ON i.codbusca = r.codbusca GROUP BY i.tipo ORDER BY qtd DESC;
    SELECT i.formato AS valor, COUNT(*) AS qtd FROM tmp_busca_res r JOIN `busca_indice` i ON i.codbusca = r.codbusca GROUP BY i.formato ORDER BY qtd DESC LIMIT 15;
    SELECT COALESCE(i.situacao, '') AS valor, MAX(i.situacao_cor) AS cor, COUNT(*) AS qtd FROM tmp_busca_res r JOIN `busca_indice` i ON i.codbusca = r.codbusca GROUP BY COALESCE(i.situacao, '') ORDER BY qtd DESC LIMIT 20;
    SELECT COALESCE(i.unidade, '') AS valor, COUNT(*) AS qtd FROM tmp_busca_res r JOIN `busca_indice` i ON i.codbusca = r.codbusca GROUP BY COALESCE(i.unidade, '') ORDER BY qtd DESC LIMIT 20;
    -- 9) termos do dicionário usados na pesquisa (aviso "Resultados para ASO (Atestado de Saúde Ocupacional)")
    SELECT u.grupo, u.frase, u.termo, u.significado FROM tmp_busca_dic_usado u ORDER BY u.grupo, u.termo;
    -- 10) formas a destacar na tela (sigla = só palavra inteira)
    SELECT a.alt, MAX(a.sigla) AS sigla FROM tmp_busca_alt a GROUP BY a.alt ORDER BY CHAR_LENGTH(a.alt) DESC;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_nb;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_nbv;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_cnt;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_pag;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_grp;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_res;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_sem;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_perm;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_unid;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_alt;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_gt;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_dic_usado;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_busca_vinculos_texto`

-- @@
-- Exportação da pesquisa: vínculos de uma lista de negócios (p_grupos = [{"t": grupo_tipo, "c": grupo_chave}, ...]) por relação
-- do catálogo, nos dois sentidos, com os nomes dos negócios vizinhos que o usuário pode ver (mesmas regras da pesquisa).
-- Resultado: grupo_tipo, grupo_chave, relacao, lado, ordem, rotulo_pt/en/es, total, nomes ("A; B; C").
CREATE PROCEDURE `sp_busca_vinculos_texto`(IN p_grupos JSON, IN p_permissoes JSON, IN p_admin TINYINT, IN p_usuario INT)
BEGIN
    SET SESSION group_concat_max_len = 65535;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_perm;
    CREATE TEMPORARY TABLE tmp_busca_perm (transacao VARCHAR(80) NOT NULL PRIMARY KEY, total TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    IF p_permissoes IS NOT NULL AND JSON_LENGTH(p_permissoes) > 0 THEN
        INSERT INTO tmp_busca_perm (transacao, total)
        SELECT UPPER(j.m), MAX(COALESCE(j.t, 0)) FROM JSON_TABLE(p_permissoes, '$[*]' COLUMNS (m VARCHAR(80) PATH '$.m', t TINYINT PATH '$.t')) j
         WHERE j.m IS NOT NULL GROUP BY UPPER(j.m);
    END IF;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_unid;
    CREATE TEMPORARY TABLE tmp_busca_unid (codunidade INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
    INSERT IGNORE INTO tmp_busca_unid SELECT CODUNIDADE FROM `vw_portaria_usuario_unidades` WHERE CODUSUARIO = p_usuario AND CODUNIDADE IS NOT NULL;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_pag;
    CREATE TEMPORARY TABLE tmp_busca_pag (grupo_tipo VARCHAR(40) NOT NULL, grupo_chave VARCHAR(100) NOT NULL, PRIMARY KEY (grupo_tipo, grupo_chave))
        ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    INSERT IGNORE INTO tmp_busca_pag (grupo_tipo, grupo_chave)
    SELECT CONVERT(j.t USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(j.c USING utf8mb4) COLLATE utf8mb4_0900_ai_ci
      FROM JSON_TABLE(COALESCE(p_grupos, JSON_ARRAY()), '$[*]' COLUMNS (t VARCHAR(40) PATH '$.t', c VARCHAR(100) PATH '$.c')) j
     WHERE j.t IS NOT NULL AND j.c IS NOT NULL;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_nb;
    CREATE TEMPORARY TABLE tmp_busca_nb (grupo_tipo VARCHAR(40) NOT NULL, grupo_chave VARCHAR(100) NOT NULL, relacao VARCHAR(40) NOT NULL, lado CHAR(1) NOT NULL,
        nb_tipo VARCHAR(40) NOT NULL, nb_chave VARCHAR(100) NOT NULL, PRIMARY KEY (grupo_tipo, grupo_chave, relacao, lado, nb_tipo, nb_chave))
        ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    INSERT IGNORE INTO tmp_busca_nb
    SELECT p.grupo_tipo, p.grupo_chave, v.relacao, 'A', v.tipo_b, v.chave_b
      FROM tmp_busca_pag p JOIN `busca_vinculo` v ON v.tipo_a = p.grupo_tipo AND v.chave_a = p.grupo_chave
      JOIN `busca_relacao` r ON r.codigo = v.relacao AND r.ativo = 1;
    INSERT IGNORE INTO tmp_busca_nb
    SELECT p.grupo_tipo, p.grupo_chave, v.relacao, 'B', v.tipo_a, v.chave_a
      FROM tmp_busca_pag p JOIN `busca_vinculo` v ON v.tipo_b = p.grupo_tipo AND v.chave_b = p.grupo_chave
      JOIN `busca_relacao` r ON r.codigo = v.relacao AND r.ativo = 1;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_nbv;
    CREATE TEMPORARY TABLE tmp_busca_nbv (grupo_tipo VARCHAR(40) NOT NULL, grupo_chave VARCHAR(100) NOT NULL, relacao VARCHAR(40) NOT NULL, lado CHAR(1) NOT NULL,
        nb_tipo VARCHAR(40) NOT NULL, nb_chave VARCHAR(100) NOT NULL, nome VARCHAR(500) NULL, PRIMARY KEY (grupo_tipo, grupo_chave, relacao, lado, nb_tipo, nb_chave))
        ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    INSERT INTO tmp_busca_nbv
    SELECT nb.grupo_tipo, nb.grupo_chave, nb.relacao, nb.lado, nb.nb_tipo, nb.nb_chave,
           COALESCE(MAX(CASE WHEN i.item = 'REGISTRO' AND i.tipo = nb.nb_tipo AND i.chave = nb.nb_chave THEN
                    CONCAT(i.nome, IF(COALESCE(i.unidade, '') <> '', CONCAT(' (', i.unidade, ')'), '')) END), MAX(i.grupo_nome))
      FROM tmp_busca_nb nb
      JOIN `busca_indice` i ON i.grupo_tipo = nb.nb_tipo AND i.grupo_chave = nb.nb_chave
      LEFT JOIN tmp_busca_perm pm ON pm.transacao = i.transacao
      LEFT JOIN tmp_busca_unid un ON un.codunidade = i.codunidade
     WHERE p_admin = 1 OR (pm.transacao IS NOT NULL AND (i.escopo = 'NENHUM' OR (i.escopo = 'USUARIO' AND (pm.total = 1 OR i.codusuario = p_usuario))
           OR (i.escopo = 'UNIDADE' AND un.codunidade IS NOT NULL)))
     GROUP BY nb.grupo_tipo, nb.grupo_chave, nb.relacao, nb.lado, nb.nb_tipo, nb.nb_chave;
    SELECT n.grupo_tipo, n.grupo_chave, n.relacao, n.lado, rl.ordem,
           IF(n.lado = 'A', rl.rotulo_a_pt, rl.rotulo_b_pt) AS rotulo_pt, IF(n.lado = 'A', rl.rotulo_a_en, rl.rotulo_b_en) AS rotulo_en,
           IF(n.lado = 'A', rl.rotulo_a_es, rl.rotulo_b_es) AS rotulo_es,
           COUNT(*) AS total, GROUP_CONCAT(n.nome ORDER BY n.nome SEPARATOR '; ') AS nomes
      FROM tmp_busca_nbv n JOIN `busca_relacao` rl ON rl.codigo = n.relacao
     GROUP BY n.grupo_tipo, n.grupo_chave, n.relacao, n.lado, rl.ordem, rotulo_pt, rotulo_en, rotulo_es
     ORDER BY n.grupo_tipo, n.grupo_chave, rl.ordem, n.relacao, n.lado;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_nbv;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_nb;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_pag;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_perm;
    DROP TEMPORARY TABLE IF EXISTS tmp_busca_unid;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_busca_resumo`

-- @@
-- Situação do índice (tela de pesquisa e testes): itens por tipo, arquivos, última alteração e última execução do serviço.
CREATE PROCEDURE `sp_busca_resumo`()
SELECT tipo, SUM(item = 'REGISTRO') AS registros, SUM(item = 'ARQUIVO') AS arquivos, MAX(indexado_em) AS ultima_alteracao,
       (SELECT MAX(r.fim_em) FROM `green_service_runs` r WHERE r.servico = 'busca' AND r.status = 'CONCLUIDA') AS ultima_execucao
  FROM `busca_indice` GROUP BY tipo ORDER BY tipo
