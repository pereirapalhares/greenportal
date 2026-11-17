/* P2 Green Questionnaire — catálogo, versionamento e publicação universais. */
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS amb_questionario (
    codquestionario BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(100) NOT NULL,
    nome_pt VARCHAR(180) NOT NULL,
    nome_en VARCHAR(180) NULL,
    nome_es VARCHAR(180) NULL,
    descricao_pt TEXT NULL,
    descricao_en TEXT NULL,
    descricao_es TEXT NULL,
    modulo VARCHAR(80) NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_por BIGINT UNSIGNED NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (codquestionario),
    UNIQUE KEY ux_amb_questionario_codigo (codigo),
    KEY ix_amb_questionario_modulo (modulo,ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS amb_questionario_versao (
    codversao BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codquestionario BIGINT UNSIGNED NOT NULL,
    versao INT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'RASCUNHO',
    instrucoes_pt TEXT NULL,
    instrucoes_en TEXT NULL,
    instrucoes_es TEXT NULL,
    percentual_minimo DECIMAL(6,3) NULL,
    criado_por BIGINT UNSIGNED NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    publicado_em DATETIME NULL,
    publicado_por BIGINT UNSIGNED NULL,
    PRIMARY KEY (codversao),
    UNIQUE KEY ux_amb_questionario_versao (codquestionario,versao),
    KEY ix_amb_questionario_versao_status (codquestionario,status),
    CONSTRAINT fk_amb_questionario_versao_questionario FOREIGN KEY (codquestionario) REFERENCES amb_questionario(codquestionario),
    CONSTRAINT ck_amb_questionario_versao_status CHECK (status IN ('RASCUNHO','PUBLICADA','ARQUIVADA'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS amb_questionario_bloco (
    codbloco BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codversao BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(100) NOT NULL,
    titulo_pt VARCHAR(180) NOT NULL,
    titulo_en VARCHAR(180) NULL,
    titulo_es VARCHAR(180) NULL,
    ordem INT NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (codbloco),
    UNIQUE KEY ux_amb_questionario_bloco (codversao,codigo),
    CONSTRAINT fk_amb_questionario_bloco_versao FOREIGN KEY (codversao) REFERENCES amb_questionario_versao(codversao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS amb_questionario_pergunta (
    codpergunta BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codversao BIGINT UNSIGNED NOT NULL,
    codbloco BIGINT UNSIGNED NULL,
    codigo VARCHAR(100) NOT NULL,
    texto_pt TEXT NOT NULL,
    texto_en TEXT NULL,
    texto_es TEXT NULL,
    tipo VARCHAR(30) NOT NULL DEFAULT 'TEXTO',
    obrigatoria TINYINT(1) NOT NULL DEFAULT 0,
    ordem INT NOT NULL DEFAULT 0,
    peso DECIMAL(10,3) NOT NULL DEFAULT 1,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (codpergunta),
    UNIQUE KEY ux_amb_questionario_pergunta (codversao,codigo),
    KEY ix_amb_questionario_pergunta_bloco (codbloco,ordem),
    CONSTRAINT fk_amb_questionario_pergunta_versao FOREIGN KEY (codversao) REFERENCES amb_questionario_versao(codversao),
    CONSTRAINT fk_amb_questionario_pergunta_bloco FOREIGN KEY (codbloco) REFERENCES amb_questionario_bloco(codbloco),
    CONSTRAINT ck_amb_questionario_pergunta_tipo CHECK (tipo IN ('TEXTO','NUMERO','DATA','SIM_NAO','UNICA_ESCOLHA','MULTIPLA_ESCOLHA','ANEXO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS amb_questionario_opcao (
    codopcao BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codpergunta BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(100) NOT NULL,
    rotulo_pt VARCHAR(180) NOT NULL,
    rotulo_en VARCHAR(180) NULL,
    rotulo_es VARCHAR(180) NULL,
    valor VARCHAR(180) NULL,
    pontuacao DECIMAL(10,3) NULL,
    ordem INT NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (codopcao),
    UNIQUE KEY ux_amb_questionario_opcao (codpergunta,codigo),
    CONSTRAINT fk_amb_questionario_opcao_pergunta FOREIGN KEY (codpergunta) REFERENCES amb_questionario_pergunta(codpergunta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS amb_questionario_regra (
    codregra BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codversao BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(100) NOT NULL,
    tipo VARCHAR(30) NOT NULL DEFAULT 'VISIBILIDADE',
    expressao_json JSON NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (codregra),
    UNIQUE KEY ux_amb_questionario_regra (codversao,codigo),
    CONSTRAINT fk_amb_questionario_regra_versao FOREIGN KEY (codversao) REFERENCES amb_questionario_versao(codversao),
    CONSTRAINT ck_amb_questionario_regra_tipo CHECK (tipo IN ('VISIBILIDADE','OBRIGATORIEDADE','VALIDACAO','PONTUACAO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS amb_questionario_publicacao (
    codpublicacao BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codversao BIGINT UNSIGNED NOT NULL,
    codigo_publicacao VARCHAR(150) NOT NULL,
    publicado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    publicado_por BIGINT UNSIGNED NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (codpublicacao),
    UNIQUE KEY ux_amb_questionario_publicacao_versao (codversao),
    UNIQUE KEY ux_amb_questionario_publicacao_codigo (codigo_publicacao),
    CONSTRAINT fk_amb_questionario_publicacao_versao FOREIGN KEY (codversao) REFERENCES amb_questionario_versao(codversao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW vw_amb_questionario_catalogo AS
SELECT q.codquestionario,q.codigo,q.nome_pt,q.nome_en,q.nome_es,q.modulo,q.ativo,
       v.codversao,v.versao,v.status,v.percentual_minimo,p.codpublicacao,p.codigo_publicacao,p.publicado_em
  FROM amb_questionario q
  LEFT JOIN amb_questionario_versao v ON v.codquestionario=q.codquestionario AND v.status='PUBLICADA'
  LEFT JOIN amb_questionario_publicacao p ON p.codversao=v.codversao AND p.ativo=1;

CREATE OR REPLACE VIEW vw_amb_questionario_perguntas AS
SELECT p.codpergunta,p.codversao,p.codbloco,p.codigo,p.texto_pt,p.texto_en,p.texto_es,
       p.tipo,p.obrigatoria,p.ordem,p.peso,p.ativo,o.codopcao,o.codigo AS codigo_opcao,
       o.rotulo_pt,o.rotulo_en,o.rotulo_es,o.valor,o.pontuacao,o.ordem AS ordem_opcao
  FROM amb_questionario_pergunta p
  LEFT JOIN amb_questionario_opcao o ON o.codpergunta=p.codpergunta AND o.ativo=1
 WHERE p.ativo=1;

CREATE TABLE IF NOT EXISTS green_universal_migrations (
    codigo_migracao VARCHAR(120) NOT NULL,
    versao VARCHAR(30) NOT NULL,
    status VARCHAR(20) NOT NULL,
    detalhes JSON NULL,
    executado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (codigo_migracao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO green_universal_migrations(codigo_migracao,versao,status,detalhes)
VALUES('GREEN_QUESTIONNAIRE_UNIVERSAL_193','4.0.1','CONCLUIDA',JSON_OBJECT('publicacoes_imutaveis',TRUE,'catalogo','guniversal_dbp'))
ON DUPLICATE KEY UPDATE status=VALUES(status),detalhes=VALUES(detalhes),executado_em=CURRENT_TIMESTAMP;
