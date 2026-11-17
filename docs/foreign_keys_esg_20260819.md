# Foreign keys — ESG

**Data:** 19/08/2026  
**Origem das constraints:** `green_modelo`  
**Destino:** `gmodelo_dbp`  
**Script:** `database/086_restaurar_foreign_keys_esg.sql`

## Contexto

As tabelas ESG migradas com `CREATE TABLE LIKE` preservaram colunas e índices, mas não trouxeram as constraints referenciais do legado.

O catálogo do banco `green_modelo` possui 106 foreign keys ESG. O script restaura essas constraints no `gmodelo_dbp`, substituindo o schema referenciado por `gmodelo_dbp` e preservando as regras `ON DELETE` e `ON UPDATE`.

## Escopo

Foram consideradas somente constraints que:

- pertencem a tabelas `esg_`;
- possuem tabela e coluna referenciadas;
- existem no catálogo de constraints do legado;
- ainda não existem no destino.

## Validação

Após a execução devem ser confirmados:

- 106 foreign keys ESG no destino;
- nenhuma tabela ou coluna referenciada ausente;
- ausência de registros órfãos;
- `CHECK TABLE` das tabelas ESG;
- preservação das contagens de registros.

## Execução realizada

O script foi executado com sucesso:

- foreign keys no legado: 106;
- foreign keys ESG no destino após a execução: 106;
- foreign keys ESG ausentes no destino: 0;
- registro oficial: `gmodelo_dbp.registros_migracao`, código `FOREIGN_KEYS_ESG_086`, ID `44`.
