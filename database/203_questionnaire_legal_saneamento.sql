/* Green Questionnaire 203 - saneamento rastreavel das perguntas legais legadas. */
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS amb_questionario_pergunta_saneamento (
    codsaneamento BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    banco_origem VARCHAR(128) NOT NULL,
    tabela_origem VARCHAR(128) NOT NULL,
    codigo_origem BIGINT NOT NULL,
    texto_original TEXT NOT NULL,
    texto_normalizado TEXT NOT NULL,
    hash_texto CHAR(64) NOT NULL,
    quantidade_vinculos INT UNSIGNED NOT NULL DEFAULT 0,
    codpergunta_canonica_origem BIGINT NULL,
    classificacao VARCHAR(40) NOT NULL,
    motivo TEXT NULL,
    codpergunta_destino BIGINT UNSIGNED NULL,
    analisado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (codsaneamento),
    UNIQUE KEY ux_amb_pergunta_saneamento_origem (tabela_origem,codigo_origem),
    KEY ix_amb_pergunta_saneamento_hash (hash_texto),
    KEY ix_amb_pergunta_saneamento_classificacao (classificacao),
    KEY ix_amb_pergunta_saneamento_destino (codpergunta_destino)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS amb_questionario_pergunta_legado_vinculo (
    codvinculo BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    banco_origem VARCHAR(128) NOT NULL,
    tabela_origem VARCHAR(128) NOT NULL,
    codigo_pergunta_origem BIGINT NOT NULL,
    codigo_legislacao_origem BIGINT NOT NULL,
    codpergunta_destino BIGINT UNSIGNED NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (codvinculo),
    UNIQUE KEY ux_amb_pergunta_legado_vinculo (tabela_origem,codigo_pergunta_origem,codigo_legislacao_origem),
    KEY ix_amb_pergunta_legado_vinculo_destino (codpergunta_destino),
    KEY ix_amb_pergunta_legado_vinculo_legislacao (codigo_legislacao_origem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
