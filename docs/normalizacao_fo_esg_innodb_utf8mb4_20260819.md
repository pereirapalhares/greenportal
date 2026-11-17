# Normalização `fo_` e `esg_` — InnoDB e utf8mb4

**Data:** 19/08/2026  
**Banco:** `gmodelo_dbp`  
**Script:** `database/084_normalizar_fo_esg_innodb_utf8mb4.sql`

## Escopo

Converter as 114 tabelas migradas dos módulos Homologação de Fornecedores (`fo_`) e ESG (`esg_`) para:

- `ENGINE=InnoDB`;
- `CHARACTER SET=utf8mb4`;
- `COLLATE=utf8mb4_0900_ai_ci`.

## Backup

Backup criado antes da execução:

`database/backups/20260819_fo_esg_pre_normalizacao_084.sql`

## Justificativa

InnoDB é necessário para transações, locks por linha, recuperação após falhas e futura criação de foreign keys. A collation `utf8mb4_0900_ai_ci` padroniza o armazenamento e a comparação de textos acentuados.

## Validações obrigatórias após a execução

- verificar engine e collation das 114 tabelas;
- executar `CHECK TABLE`;
- comparar contagens de registros;
- validar textos acentuados;
- executar `ANALYZE TABLE`;
- auditar registros órfãos antes de criar foreign keys.

Foreign keys não fazem parte deste script. Elas serão criadas somente depois da identificação dos relacionamentos e da confirmação de que não existem órfãos.

## Execução realizada

O script foi executado com sucesso.

- tabelas normalizadas: 114;
- engine final: `InnoDB`;
- collation final: `utf8mb4_0900_ai_ci`;
- tabelas divergentes após a validação: 0;
- backup utilizado: `database/backups/20260819_fo_esg_pre_normalizacao_084.sql`;
- registro oficial: `gmodelo_dbp.registros_migracao`, código `NORMALIZACAO_FO_ESG_084`, ID `42`.
