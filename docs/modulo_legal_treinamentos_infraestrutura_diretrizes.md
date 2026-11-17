# Diretrizes — Treinamentos e InfraEstrutura

## Transações

As duas transações pertencem ao módulo `MODULO_LEGAL`:

| Transação | Código de menu | Página | Legado |
|---|---|---|---|
| Treinamentos | `MODULOLEGAL_TREINAMENTOS` | `principal/modulolegal_treinamentos.php` | `mlTreinamentos` |
| InfraEstrutura | `MODULOLEGAL_INFRAESTRUTURA` | `principal/modulolegal_InfraEstrutura.php` | `mlInfraEstruturas` |

As configurações legadas foram analisadas em `hpr_configuracoes.configuracoes_transacoes`, `configuracoes_grids` e `configuracoes_tabulacoes`.

## Estrutura de dados

As tabelas foram migradas para `gmodelo_dbp` pela migração `129_treinamentos_infraestrutura.sql`:

- `ml_treinamentos`;
- `ml_treinamentosxpalavraschave`;
- `ml_infraestrutura`;
- `ml_infraestruturaxatributos`;
- `ml_infraestruturaxpalavraschave`.

Views principais:

- `vw_modulolegal_treinamentos_grid`;
- `vw_modulolegal_infraestrutura_grid`.

Os catálogos dos relacionamentos são fornecidos pelas views:

- `vw_modulolegal_rel_palavras_chaves`;
- `vw_modulolegal_infraestrutura_atributos`.

Palavras-chave continuam usando o catálogo compartilhado `guniversal_dbp.palavras_chaves`.

## Relacionamentos

Os relacionamentos são abertos por botões na barra acima do grid principal:

| Tela | Relacionamento | Chave pai | Operação |
|---|---|---|---|
| Treinamentos | Palavras-chave | `codtreinamento` | Incluir/excluir |
| InfraEstrutura | Palavras-chave | `codinfraestrutura` | Incluir/excluir |
| InfraEstrutura | Atributos | `codinfraestrutura` | Incluir/alterar/excluir |

Cada relacionamento abre uma modal com um grid. Nenhum botão de ação é colocado dentro das linhas.

### Palavras-chave

As associações existentes são exibidas em linhas somente leitura. O botão `Incluir` cria uma linha com combobox. O combobox não permite selecionar a mesma palavra-chave duas vezes.

### Atributos

Cada linha possui `Descrição` e `Valor`. Linhas existentes são apresentadas como somente leitura; novas linhas permitem preenchimento. A exclusão ocorre pela linha selecionada na barra superior.

## Confirmação e backend

O JavaScript está em `assets/js/modulo-legal-relacionamentos.js` e a API única em `principal/api/modulo_legal_relacionamentos.php`.

O fluxo usa:

- `sp_crud_select` para registro pai, catálogo e relacionamentos atuais;
- `sp_crud_insert` para novas associações;
- `sp_crud_update` para alterações de atributos;
- `sp_crud_delete` para exclusões.

O PHP não executa `INSERT`, `UPDATE`, `DELETE` ou `SELECT` diretamente. A API mantém uma lista branca de relacionamentos e valida a permissão no servidor.

A exclusão local exige confirmação por popup de pergunta. O botão `Confirmar` grava as alterações no banco. O resumo no grid principal é atualizado após a confirmação.

## Permissões

As permissões foram definidas pela migração `133_permissoes_treinamentos_infraestrutura.sql`:

| Perfil | Visualizar | Incluir | Editar | Excluir | Exportar |
|---|---:|---:|---:|---:|---:|
| `ADMINISTRADOR` | Sim | Sim | Sim | Sim | Sim |
| `ADM_DA_EMPRESA` | Sim | Sim | Sim | Sim | Não |
| `GESTOR` | Sim | Não | Não | Não | Não |
| Demais perfis | Não | Não | Não | Não | Não |

## Migrações

- `129_treinamentos_infraestrutura.sql`: tabelas, dados, views principais, menus e permissões iniciais;
- `130_treinamentos_infraestrutura_relacionamentos.sql`: views de catálogo e registro dos relacionamentos.

## Arquivos físicos

As colunas `ARQUIVO` foram habilitadas nos dois grids e reutilizam a tela central `principal/gestao_arquivos.php`, com os tipos:

- `treinamentos`, chave `CODTREINAMENTO`;
- `infraestrutura`, chave `CODINFRAESTRUTURA`.

Os diretórios seguem o padrão definido em `docs/ged_arquivos_diretrizes.md`.

A contagem é obtida por `principal/api/ged_contagens.php`, que recebe o tipo e as chaves da página. O JavaScript `assets/js/modulo-legal-arquivos.js` transforma a coluna em ícone com quantidade e abre o popup em iframe.
