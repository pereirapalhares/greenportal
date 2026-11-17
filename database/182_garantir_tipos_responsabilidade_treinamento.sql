-- Garante a configuracao minima do CRUD de responsabilidades de treinamentos.
-- Idempotente para os tenants de homologacao e producao controlada.

INSERT INTO gatlas_dbp.responsabilidade_modulos
    (CODIGO, NOME, DESCRICAO, ATIVO, ORDEM)
VALUES
    ('TREINAMENTO', 'Treinamento', 'Responsabilidades de treinamentos.', 1, 40)
ON DUPLICATE KEY UPDATE
    NOME = VALUES(NOME),
    DESCRICAO = VALUES(DESCRICAO),
    ATIVO = 1;

INSERT INTO gatlas_dbp.responsabilidade_tipos
    (CODMODULO, CODIGO, NOME, DESCRICAO, ATIVO, ORDEM)
SELECT CODMODULO, 'TREINAMENTO', 'Treinamento',
       'Responsabilidade relacionada a treinamento.', 1, 10
  FROM gatlas_dbp.responsabilidade_modulos
 WHERE CODIGO = 'TREINAMENTO'
ON DUPLICATE KEY UPDATE
    NOME = VALUES(NOME),
    DESCRICAO = VALUES(DESCRICAO),
    ATIVO = 1;

INSERT INTO gmodelo2_dbp.responsabilidade_modulos
    (CODIGO, NOME, DESCRICAO, ATIVO, ORDEM)
VALUES
    ('TREINAMENTO', 'Treinamento', 'Responsabilidades de treinamentos.', 1, 40)
ON DUPLICATE KEY UPDATE
    NOME = VALUES(NOME),
    DESCRICAO = VALUES(DESCRICAO),
    ATIVO = 1;

INSERT INTO gmodelo2_dbp.responsabilidade_tipos
    (CODMODULO, CODIGO, NOME, DESCRICAO, ATIVO, ORDEM)
SELECT CODMODULO, 'TREINAMENTO', 'Treinamento',
       'Responsabilidade relacionada a treinamento.', 1, 10
  FROM gmodelo2_dbp.responsabilidade_modulos
 WHERE CODIGO = 'TREINAMENTO'
ON DUPLICATE KEY UPDATE
    NOME = VALUES(NOME),
    DESCRICAO = VALUES(DESCRICAO),
    ATIVO = 1;
