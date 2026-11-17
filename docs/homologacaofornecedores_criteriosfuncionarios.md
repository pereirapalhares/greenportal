# Transação: Critérios dos funcionários

Data: 13/10/2026 · Migração 245 · Módulo Homologação de Fornecedores

## Identificação

| Item | Valor |
|---|---|
| Arquivo | `principal/homologacaofornecedores_criteriosfuncionarios.php` |
| Código do menu/permissão | `HOMOLOGACAO_CRITERIOS_FUNCIONARIOS` (abaixo de `HOMOLOGACAO_FORNECEDORES`) |
| Rótulos | Critérios dos funcionários · Employee criteria · Criterios de los empleados |
| Legado | `E:\wamp\www\configuracoes\fo_criteriofuncionarios.php` |
| Tabela | `fo_criteriofuncionarios` (chave `codcriteriofuncionario`) |
| Ajuda | `guniversal_dbp.ajuda_transacoes`, transação `homologacaofornecedores_criteriosfuncionarios` |

## Campos

| Campo | Legado | Tela nova |
|---|---|---|
| `CODCRITERIOFUNCIONARIO` | código, oculto | oculto; gerado pelo banco (AUTO_INCREMENT) a partir de 100000 |
| `DSCRITERIO` | "Critérios dos Funcionários", texto | "Critério", obrigatório |
| `TIPO_DE_DOCUMENTO` | lista: Competência, Regularização de Ordem Legal | mesma lista, vinda de view |
| `QTD_EM_USO` | — | "Em uso", somente leitura: quantos vínculos usam o critério |

## Banco de dados (somente views e procedures)

Criados em cada banco de cliente por `scripts/aplicar-migracao-245-criterios-funcionarios.php`
a partir de `database/245_homologacao_criterios_funcionarios.sql`:

| Objeto | Uso |
|---|---|
| `vw_homologacao_criterios_funcionarios_grid` | leitura do grid (`sp_crud_select` / `sp_crud_count`) |
| `vw_homologacao_criterios_funcionarios_tipos_documento` | lista do campo Tipo de documento |
| `sp_crud_insert` / `sp_crud_update` / `sp_crud_delete` | gravação pela tela (CRUD controlado pela transação) |
| `sp_fo_criteriofuncionario_validar_exclusao` | regra: critério em uso não pode ser excluído (SIGNAL 45000 → mensagem na tela) |
| `trg_fo_criteriofuncionarios_antes_excluir` | chama a regra acima antes de qualquer exclusão |
| `sp_fo_criteriofuncionarios_migrar(origem)` | inclui no destino os critérios que só existem na origem |
| `sp_fo_criteriofuncionarios_comparar(origem)` | lista diferenças origem x destino |

As tabelas que usam o critério são descobertas no próprio banco (coluna `codcriteriofuncionario`
ou chave estrangeira para `fo_criteriofuncionarios`): `fo_tipofuncionariosxcriteriofuncionarios`,
`fo_funcionarioxtiposxcriterios`, `fo_tipofuncxcriteriofuncxtreinamento`, `fo_funcionariosxcriterios`
(as que existirem em cada banco).

## Migração origem → destino

| Origem (legado) | Destino |
|---|---|
| `green_modelo.fo_criteriofuncionarios` | `gmodelo_dbp.fo_criteriofuncionarios` |
| `green_atlas.fo_criteriofuncionarios` | `gatlas_dbp.fo_criteriofuncionarios` |
| `green_modelo.fo_criteriofuncionarios` | `gmodelo2_dbp.fo_criteriofuncionarios` (se o banco existir) |

A tabela já existia no destino (migração 082 e sincronismo diário de FO). A migração 245:

1. compara a estrutura (colunas, chave, auto incremento) e cria a tabela no destino se faltar;
2. torna a chave AUTO_INCREMENT, com novos códigos a partir de **100000**, para que um critério criado
   no sistema novo nunca use um código que o legado venha a criar;
3. inclui no destino os critérios que só existem na origem (não altera nem exclui nada do destino);
4. compara origem x destino e grava o resultado em `registros_migracao`
   (`HOMOLOGACAO_CRITERIOS_FUNCIONARIOS_245`) e em `storage/logs/migracao_245_criterios_funcionarios.json`.

Diferenças do tipo `DIFERENTE` podem ser a correção de acentuação já feita no sistema novo
(migração 088) e não são sobrescritas.

## Sincronismo com o legado

A partir da migração 245 os critérios dos funcionários são mantidos **no sistema novo**.
`scripts/sincronizar-fo-legado.php` passou a ignorar `fo_criteriofuncionarios` (constante
`SINC_MESTRE_NOVO`); sem isso o sincronismo diário (05:00) desfaria as inclusões, alterações e exclusões
feitas na tela. Consequências até a virada definitiva:

- critério criado no legado não chega ao sistema novo (cadastrar também na tela nova);
- vínculos do legado que apontam para um critério inexistente no novo são contados como
  "ignorados por dependência" no relatório do sincronismo (quando há chave estrangeira).

## Permissões

O menu é cadastrado no catálogo universal e no menu de cada cliente (para aparecer em
Segurança › Permissões), **sem liberar nenhum perfil**. O que cada perfil pode fazer (visualizar,
incluir, editar, excluir, exportar) é definido em Segurança › Permissões. ADMINISTRADOR tem acesso por
regra do sistema.

## Isolamento e auditoria

A tela valida sessão, tenant e a permissão da própria transação (`ManutencaoLegalCadastroGrid`), só aceita
os campos declarados e registra salvamentos e exclusões (inclusive falhas) em `logs_auditoria`.

## Testes

`scripts/testar-homologacaofornecedores-criteriosfuncionarios.php` confere, em cada banco: objetos,
menu, ajuda, leitura pelo grid e pela lista, inclusão/alteração/exclusão pelas procedures `sp_crud_*`,
bloqueio da exclusão de critério em uso e comparação origem x destino. O critério de teste é removido.

Roteiro visual: abrir Homologação de Fornecedores › Critérios dos funcionários; incluir um critério;
alterar o tipo; excluir; tentar excluir um critério com "Em uso" > 0 (deve aparecer a mensagem);
filtrar; exportar; conferir o "?"; repetir com um perfil sem Incluir/Excluir para ver os botões bloqueados.

## Execução

```
E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-245-criterios-funcionarios.php --simular
E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-245-criterios-funcionarios.php
E:\wamp\bin\php\php8.4.15\php.exe scripts\testar-homologacaofornecedores-criteriosfuncionarios.php
```

Reversão: remover o menu `HOMOLOGACAO_CRITERIOS_FUNCIONARIOS`, o gatilho, as views e as procedures
listadas acima e retirar `fo_criteriofuncionarios` de `SINC_MESTRE_NOVO`. Os dados da tabela não são
apagados pela migração.
