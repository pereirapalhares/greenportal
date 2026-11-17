# Correção da busca genérica dos grids

## Problema

Na empresa Atlas, a busca por `SODEXO` em `homologacaofornecedor_fornecedor.php` não retornava o fornecedor. A consulta revelava o erro MySQL 1267, causado pela comparação de colunas com `utf8mb4_general_ci` contra texto em `utf8mb4_0900_ai_ci`.

## Correção

A migração 233 atualizou os procedimentos genéricos `sp_crud_select` e `sp_crud_count` nos bancos `gatlas_dbp`, `gmodelo_dbp` e `gmodelo2_dbp`.

Nas buscas textuais, as colunas agora são convertidas para `utf8mb4` e comparadas com `utf8mb4_0900_ai_ci` antes do `LIKE`. A correção é centralizada e cobre os grids que usam esses procedimentos, não apenas fornecedores.

## Teste operacional

- Fornecedor localizado: `SODEXO DO BRASIL COMERCIAL S.A.`
- Nome fantasia: `SODEXO`
- Código: `484`
- `sp_crud_select`: 1 resultado.
- `sp_crud_count`: total 1.
- Aplicação nos três bancos concluída com sucesso.

Os procedimentos contextuais permanecem inalterados porque a tela de fornecedores usa o fluxo genérico; os grids contextuais que dependem de `sp_crud_context_*` não apresentaram este erro nesta ocorrência.
