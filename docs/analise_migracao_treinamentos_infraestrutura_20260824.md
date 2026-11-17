# Análise do legado — Treinamentos e InfraEstrutura

As diretrizes funcionais e técnicas consolidadas estão em `docs/modulo_legal_treinamentos_infraestrutura_diretrizes.md`.

## Transações encontradas

| Transação | Código legado | Tabela principal | Chave | Registros no legado |
|---|---|---|---|---:|
| Treinamentos | `mlTreinamentos` / `codtransacao=150` | Legado: `ml_treinamenos`; destino: `ml_treinamentos` | `CODTREINAMENTO` | 2 |
| InfraEstrutura | `mlInfraEstruturas` / `codtransacao=152` | `ml_infraestrutura` | `CODINFRAESTRUTURA` | 3 |

As telas planejadas são `principal/modulolegal_treinamentos.php` e `principal/modulolegal_InfraEstrutura.php`, como transações filhas de `MODULO_LEGAL`.

## Configuração do grid no `hpr_configuracoes`

### Treinamentos

- `LAYOUT=2E`, filtro ativo e carregamento automático desabilitado;
- grid principal: `DSCTREINAMENTO` com label “Descrição”;
- coluna `ARQUIVO` com label “Arquivos”, tipo link;
- consulta legada: `CODTREINAMENTO`, `DSCTREINAMENTO` e referência física `ML_TREINAMENOS;<chave>`;
- botões legados: `IAEXP` — incluir, alterar, excluir e exportar;
- aba configurada: “Palavras Chave”, usando a relação `mlTreinamenosxpalavraschave`.

### InfraEstrutura

- `LAYOUT=2E`, filtro ativo e carregamento automático desabilitado;
- grid principal: `DSCINFRAESTRUTURA` com label “Descrição”;
- coluna `ARQUIVO` com label “Arquivos”, tipo link;
- consulta legada: `CODINFRAESTRUTURA`, `DSCINFRAESTRUTURA` e referência física `ML_INFRAESTRUTURA;<chave>`;
- botões legados: `IAEXP`;
- abas configuradas:
  - “Atributos”, relação `ml_infraestruturaxatributos`;
  - “Palavras chave”, relação `ml_infraestruturaxpalavraschave`.

## Tabelas relacionadas encontradas

| Tabela legada | Finalidade | Registros |
|---|---|---:|
| `ml_treinamenos` → `ml_treinamentos` | Cadastro de treinamentos | 2 |
| `ml_treinamenosxpalavraschave` → `ml_treinamentosxpalavraschave` | Palavras-chave dos treinamentos | 5 |
| `ml_infraestrutura` | Cadastro de infraestruturas | 3 |
| `ml_infraestruturaxatributos` | Atributos livres da infraestrutura | 5 |
| `ml_infraestruturaxpalavraschave` | Palavras-chave da infraestrutura | 2 |

Os relacionamentos com palavras-chave usam `codpalavrachave`, cujo catálogo permanece compartilhado no banco universal (`guniversal_dbp.palavras_chaves`), conforme o padrão já adotado no Controle.

Também foram encontradas as transações legadas auxiliares:

- `mlTreinamentoXPalavrasChave` (`codtransacao=151`);
- `mlInfraEstruturaXPalavrasChave` (`codtransacao=153`);
- `mlInfraestruturaXAtributos` (`codtransacao=154`);
- transações de filtro contextual com requisitos: `mlTreinamentosXRequisitos` (`163`) e `mlInfraEstruturaXRequisitos` (`164`).

## Situação no `gmodelo_dbp`

As cinco tabelas legadas acima não foram encontradas no `gmodelo_dbp`. Portanto, antes dos CRUDs será necessária uma migração estrutural e de dados, com nomenclatura compatível e views de grid. A migração deve preservar as chaves e os relacionamentos existentes.

## Relacionamentos implementados

Foram criados os grids de relacionamento em popup, com barra de ações acima do grid, inclusão por combobox para palavras-chave, inclusão por campos de descrição/valor para atributos, exclusão da linha selecionada e confirmação por popup antes da exclusão local. A confirmação final usa exclusivamente procedures.

- API: `principal/api/modulo_legal_relacionamentos.php`;
- JavaScript: `assets/js/modulo-legal-relacionamentos.js`;
- views de catálogo: `vw_modulolegal_rel_palavras_chaves` e `vw_modulolegal_infraestrutura_atributos`;
- migração: `database/130_treinamentos_infraestrutura_relacionamentos.sql`.

## Próxima implementação

1. Usar `gestao_arquivos.php` para os arquivos físicos, respectivamente em `treinamentos/<CODTREINAMENTO>` e `infraestrutura/<CODINFRAESTRUTURA>`.
2. Implementar os testes visuais e não visuais dos dois CRUDs e dos relacionamentos.

As associações contextuais com requisitos (`163` e `164`) devem ser tratadas em uma etapa posterior, após confirmar a regra de negócio no novo módulo.
