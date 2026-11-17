# Inventário legado — Infraestrutura e responsabilidades

**Data:** 23/08/2026  
**Tenant analisado:** `gmodelo_dbp` / legado `green_modelo`  
**Escopo:** identificar relações de `CODINFRAESTRUTURA` antes de implementar o modelo de responsabilidades.

## 1. Tabela principal

`infraestrutura` é a tabela principal identificada no legado.

| Campo | Papel |
|---|---|
| `CODINFRAESTRUTURA` | chave primária técnica |
| `DSCINFRAESTRUTURA` | descrição da infraestrutura |

Foram encontrados 4 registros: códigos 1, 5, 6 e 8.

## 2. Relações confirmadas

| Tabela | Campos | Registros | Interpretação |
|---|---|---:|---|
| `infraestruturaxunidades` | `CODINFRAESTRUTURA`, `CODUNIDADE` | 27 | infraestrutura vinculada a unidades |
| `atividadesxinfraestrutura` | `CODINFRAESTRUTURA`, `CODATIVIDADE` | 16 | infraestrutura vinculada a atividades |
| `atividades` | `CODATIVIDADE` | — | cadastro referenciado pela relação de atividades |
| `unidades` | `CODUNIDADE` | — | cadastro referenciado pela relação de unidades |

Os 27 vínculos de unidades e os 16 vínculos de atividades apontam para códigos
existentes em `infraestrutura`; não foram encontrados órfãos nessas relações.

## 3. Estruturas paralelas

Também existem:

- `ml_infraestrutura`;
- `ml_infraestruturaxatributos`;
- `ml_infraestruturaxpalavraschave`.

Essa estrutura não deve ser misturada automaticamente com `infraestrutura`: ela
possui 3 registros próprios e somente 1 código coincide com a tabela principal.
As tabelas auxiliares possuem 5 registros de atributos e 2 de palavras-chave.

## 4. Responsável legado

Nenhuma das tabelas que possuem `CODINFRAESTRUTURA` apresentou campo direto
`CODUSUARIO` ou `CODGRUPO`:

- `infraestrutura` não possui responsável;
- `infraestruturaxunidades` não possui responsável;
- `atividadesxinfraestrutura` não possui responsável;
- as tabelas `ml_*` também não apresentam responsável direto.

Também não foram encontradas chaves estrangeiras formais declaradas no banco.
As relações são lógicas pelos códigos.

## 5. Interface existente

Já existe a tela contextual `principal/modulolegalpro_infraestrutura.php`, usada
no relacionamento LPRO. Ela consulta `infraestrutura` por atividade e unidade,
mas não é ainda um módulo independente de gestão nem possui consulta/manutenção
de responsabilidades.

## 6. Conclusão e decisão pendente

O registro candidato para uma futura responsabilidade é:

- entidade: `infraestrutura`;
- chave contextual: `CODINFRAESTRUTURA`;
- filtros/relacionamentos auxiliares: unidade e atividade;
- origem de responsável legado: **não identificada**.

Portanto, não deve ser feita migração automática de responsáveis. É necessário
decidir funcionalmente se a responsabilidade será criada apenas para novos
vínculos no modelo `responsabilidade_atribuicoes` ou se existe outra origem
legada fora das tabelas relacionadas que ainda deve ser analisada.

Nenhum dado foi alterado durante este inventário.
