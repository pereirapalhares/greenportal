# Transação: Tipos de treinamento

Data: 13/10/2026 · Migração 248 (cadastros simples de Homologação de Fornecedores)

## Identificação

| Item | Valor |
|---|---|
| Arquivo | `principal/homologacaofornecedores_tipostreinamentos.php` |
| Código do menu/permissão | `HOMOLOGACAO_TIPOS_TREINAMENTOS` (abaixo de `HOMOLOGACAO_FORNECEDORES`) |
| Rótulos | Tipos de treinamento · Training types · Tipos de capacitación |
| Legado | `hpr_configuracoes`: `configuracoes_transacoes.dsctransacao = 'foTiposTreinamentos'` (codtransacao 145) |
| Tabela | `fo_tipotreinamentos` (chave `CODTIPOTREINAMENTO`, descrição `DSCTIPO`) |
| Ajuda | `guniversal_dbp.ajuda_transacoes`, transação `homologacaofornecedores_tipostreinamentos` |

## Como era no legado (hpr_configuracoes)

- `configuracoes_transacoes` 145: tabela `fo_tipotreinamentos`, chave `CODTIPOTREINAMENTO`, botões `IAEXP`,
  `LAYOUT 1C`, `NAOCARREGARAUTOMATICAMENTE = S`, `COMFILTRO = S`,
  `cquery = SELECT CODTIPOTREINAMENTO, DSCTIPO FROM #DATABASE#.FO_TIPOTREINAMENTOS #WHERE# ORDER BY DSCTIPO`,
  `cqueryexcel` só com `DSCTIPO`.
- `configuracoes_grids` 964: `DSCTIPO`, label "Tipos"/"Types", editável (`ed`), obrigatório, `#text_filter`, `str`.
- Sem listas, filtros por perfil, abas, eventos ou atualizações.

## Tela nova

| Campo | Tela |
|---|---|
| `CODTIPOTREINAMENTO` | oculto; gerado pelo banco a partir de 100000 |
| `DSCTIPO` | "Tipo de treinamento", obrigatório, filtro de texto, ordem alfabética |
| `QTD_EM_USO` | "Em uso", somente leitura |

Diferença combinada: a tela abre já com a lista (o legado abria vazia).

## Banco de dados (somente views e procedures)

Modelo `database/248_homologacao_cadastro_simples.sql`, aplicado por
`scripts/aplicar-migracao-248-cadastros-homologacao.php` com os dados de `database/248_cadastros_homologacao.php`:

| Objeto | Uso |
|---|---|
| `vw_homologacao_tipos_treinamentos_grid` | leitura do grid |
| `sp_crud_insert` / `sp_crud_update` / `sp_crud_delete` | gravação pela tela |
| `sp_fo_tipotreinamentos_validar_exclusao` + `trg_fo_tipotreinamentos_antes_excluir` | tipo em uso não pode ser excluído |
| `sp_fo_tipotreinamentos_comparar(origem)` / `sp_fo_tipotreinamentos_migrar(origem)` | comparação e migração |

Tabelas que usam o tipo (descobertas em cada banco): `fo_tipofuncionariosxtipotreinamentos`,
`fo_funcionarioxtiposxtreinamentos`, `fo_tipofuncxcriteriofuncxtreinamento` (as que existirem).

## Migração origem → destino

`green_modelo → gmodelo_dbp`, `green_atlas → gatlas_dbp` e `green_modelo → gmodelo2_dbp` (se existir): estrutura
comparada, tabela criada se faltar, chave AUTO_INCREMENT a partir de 100000, registros só da origem incluídos,
comparação gravada em `registros_migracao` (`HOMOLOGACAO_TIPOS_TREINAMENTOS_248`) e em
`storage/logs/migracao_248_cadastros_homologacao.json`.

## Sincronismo

`fo_tipotreinamentos` entrou em `SINC_MESTRE_NOVO` (`scripts/sincronizar-fo-legado.php`): mantido no sistema novo.

## Permissões

Menu sem perfil liberado; liberação pela migração 247:
`--transacao=HOMOLOGACAO_TIPOS_TREINAMENTOS --perfis=ADM_DA_EMPRESA,HOMOLOGACAO` (ADMINISTRADOR por regra).

## Testes

`scripts/testar-cadastros-homologacao.php --cadastro=fo_tipotreinamentos`: objetos, menu, ajuda, leitura,
inclusão/alteração/exclusão pelas procedures, bloqueio de exclusão em uso e comparação. Roteiro visual igual ao de
Critérios dos funcionários.

## Reutilização

Novos cadastros simples de Homologação entram como um item em `database/248_cadastros_homologacao.php`
(tabela, chave, descrição, view, menu, rótulos e ajuda) e uma tela no mesmo formato desta.
