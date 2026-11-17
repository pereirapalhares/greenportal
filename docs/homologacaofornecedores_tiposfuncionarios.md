# Transação: Tipos de Funcionários

Data: 13/10/2026 · Migrações 248 (cadastro) e 249 (vínculos)

## Identificação

| Item | Valor |
|---|---|
| Arquivo | `principal/homologacaofornecedores_tiposfuncionarios.php` |
| Popup de vínculos | `principal/homologacaofornecedores_tiposfuncionarios_vinculos.php?relacao=CRITERIOS\|TREINAMENTOS\|PALAVRASCHAVE` + `assets/js/homologacao-tipos-funcionarios-vinculos.js` |
| Código do menu/permissão | `HOMOLOGACAO_TIPOS_FUNCIONARIOS` (abaixo de `HOMOLOGACAO_FORNECEDORES`) |
| Rótulos | Tipos de Funcionários · Employee types · Tipos de empleados |
| Legado | `hpr_configuracoes`: `foTiposdeFuncionarios` (codtransacao 142, layout 2E) com abas 144, 146 e 143 |
| Tabelas | `fo_tipofuncionarios` (`codtipofuncionario`, `DSCTIPO`) e os vínculos `fo_tipofuncionariosxcriteriofuncionarios`, `fo_tipofuncionariosxtipotreinamentos`, `fo_tipofuncionariosxpalavraschave` |

## Como era no legado (hpr_configuracoes)

- Transação 142: tabela `fo_tipofuncionarios`, chave `CODTIPOFUNCIONARIO`, botões `IAEXP`, `LAYOUT 2E` (grid em cima e abas
  embaixo), `NAOCARREGARAUTOMATICAMENTE = S`; grid 961 `DSCTIPO` "Tipo"/"Type", editável e obrigatório.
- Abas (`configuracoes_tabulacoes` 83–85) abrindo `/cadastros/correlacionamento.php` com `chave_pai=codtipofuncionario`:
  - **Critérios** (144): `fo_criteriofuncionarios`, exibido como `tipo_de_documento/DSCRITERIO` (normalizarfiltro 66);
  - **Treinamentos** (146): `fo_tipotreinamentos`;
  - **Palavras chave** (143): `GREEN_UNIVERSAL.PALAVRAS_CHAVES`.
  Cada aba tinha duas listas (`SQL_DE` = não vinculados, `SQL_PARA` = vinculados).

## Tela nova

| Campo | Tela |
|---|---|
| `CODTIPOFUNCIONARIO` | oculto; novos a partir de 100000 |
| `DSCTIPO` | "Tipo de funcionário", obrigatório, filtro de texto, ordem alfabética |
| `QTD_EM_USO` | "Em uso": registros de funcionários que usam o tipo (somente leitura) |

Botões **Critérios**, **Treinamentos** e **Palavras-chave** (habilitados ao selecionar uma linha) abrem o popup de duas
listas — Disponíveis e Vinculados ao tipo — com filtro em cada lista, setas para vincular/desvincular os marcados ou todos
os exibidos (desvincular todos pede um segundo clique) e duplo clique em um item. A gravação é imediata. Sem permissão de
edição o popup fica só para consulta. Diferença combinada: a tela abre já com a lista.

## Banco de dados (somente views e procedures)

| Objeto | Uso |
|---|---|
| `vw_homologacao_tipos_funcionarios_cadastro_grid` | leitura do grid (248) |
| `sp_crud_insert` / `sp_crud_update` / `sp_crud_delete` | gravação do tipo |
| `sp_fo_tipofuncionarios_validar_exclusao` + `trg_fo_tipofuncionarios_antes_excluir` | tipo usado por funcionários não pode ser excluído; ao excluir, os vínculos do tipo saem junto |
| `sp_fo_tipofuncionarios_comparar` / `_migrar` | comparação e migração com o legado (248) |
| `vw_homologacao_tipofuncionario_opcoes` | opções das três listas (critérios, treinamentos, palavras-chave do banco universal) |
| `vw_homologacao_tipofuncionario_vinculos` / `_vinculos_resumo` | vínculos gravados / quantidade por tipo |
| `sp_fo_tipofuncionario_vinculos_listar(relacao, tipo)` | tipo + opções com indicação de vinculado |
| `sp_fo_tipofuncionario_vinculos_gravar(relacao, tipo, incluir, remover)` | vincula/desvincula (JSON de códigos), em transação |

Uso que bloqueia a exclusão: `fo_funcionarios`, `fo_funcionariosxtipofuncionarios`, `fo_funcionarioxtiposxcriterios`,
`fo_funcionarioxtiposxtreinamentos`. Os vínculos do próprio tipo (opção `filhos` em `database/248_cadastros_homologacao.php`)
não contam como uso.

## Migração 249 (dados)

Em `gmodelo_dbp`, `gatlas_dbp` e `gmodelo2_dbp`:
- cópia `bkp249_<tabela>` das três tabelas de vínculo;
- removidos os vínculos de tipo inexistente (código -1 do legado) e os repetidos; criado índice único (tipo + item);
- vínculos com critério/treinamento/palavra inexistente são só informados (não aparecem nas listas);
- tipos provisórios "Função não identificada (código N) - migração 217" (60 em `gatlas_dbp`): a 249 excluía só os sem uso
  (nenhum); a migração 251 os removeu (abaixo).

## Migração 251 — igual ao legado

`scripts/aplicar-migracao-251-tipos-funcionarios-igual-legado.php`: exclui do novo os tipos que não existem no legado
(código < 100000; os do sistema novo nunca), com cópia `bkp251_*`. Como no legado (sem chave estrangeira), os
funcionários continuam com o código antigo: a chave estrangeira `fo_funcionariosxtipofuncionarios -> fo_tipofuncionarios`
foi removida e a função aparece em branco nesses registros. Os nomes originais desses 60 códigos não existem em nenhum
banco do servidor (`scripts/diagnostico-tipos-provisorios-217*.php`).

Diagnóstico (somente leitura): `scripts/diagnostico-tipos-funcionarios.php`.

## Sincronismo

`fo_tipofuncionarios` e as três tabelas de vínculo entraram em `SINC_MESTRE_NOVO` (`scripts/sincronizar-fo-legado.php`):
mantidas no sistema novo. Tipos criados no legado depois desta migração não vêm mais; cadastre-os aqui.

## Permissões

Menu sem perfil liberado; liberação pela migração 247:
`--transacao=HOMOLOGACAO_TIPOS_FUNCIONARIOS --perfis=ADM_DA_EMPRESA,HOMOLOGACAO` (ADMINISTRADOR por regra).
Visualizar = ver tela e vínculos; editar = alterar o tipo e vincular/desvincular.

## Testes

- `scripts/testar-cadastros-homologacao.php --cadastro=fo_tipofuncionarios`: objetos, menu, ajuda, inclusão/alteração/
  exclusão, bloqueio de exclusão em uso, comparação.
- `scripts/testar-tipos-funcionarios-vinculos.php`: objetos, índices únicos, sem vínculos órfãos, listar/vincular/não
  duplicar/desvincular/recusar item inexistente nas três relações, exclusão do tipo levando os vínculos, sincronismo e menu.
