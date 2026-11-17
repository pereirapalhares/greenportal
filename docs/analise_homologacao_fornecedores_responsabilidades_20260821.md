# Análise inicial — Homologação de Fornecedores e responsabilidades

**Data:** 21/08/2026  
**Legado:** `green_modelo`  
**Novo tenant:** `gmodelo_dbp`  
**Menu:** Homologação de Fornecedores

## 1. Cenário confirmado

O módulo já está iniciado e possui a estrutura funcional abaixo:

- **Gestão de Fornecedores** — `homologacaofornecedor_fornecedor.php`;
- **Gestão de Funcionários** — `homologacaofornecedor_funcionario.php`;
- relacionamentos contextuais de contatos, certificados, auditoria, critérios,
  funcionários, produtos químicos, equipamentos e plano de ação;
- tabelas `fo_` migradas para `gmodelo_dbp`;
- views de grid, menus e permissões já criados pelos scripts 087, 089 e 090.

## 2. Comparação legado x novo

| Tabela | `green_modelo` | `gmodelo_dbp` | Resultado |
|---|---:|---:|---|
| `fo_fornecedores` | 514 | 514 | compatível |
| `fo_fornecedorxusuario` | 1.123 | 1.123 | compatível |
| `fo_fornecedorxcriterios` | 8.396 | 8.396 | compatível |
| `fo_funcionarios` | 1.108 | 1.103 | diferença de 5 registros |

A diferença em `fo_funcionarios` não deve ser ignorada. Antes da homologação
de Gestão de Funcionários, deve-se identificar os cinco registros ausentes e
confirmar se foram excluídos por órfãos, filtro de migração ou outro critério.

## 3. Menus e transações confirmados

| Código | URL | Estado |
|---|---|---|
| `HOMOLOGACAO_FORNECEDORES` | menu pai | ativo |
| `HOMOLOGACAO_FORNECEDOR_FORNECEDOR` | `homologacaofornecedor_fornecedor.php` | ativo |
| `HOMOLOGACAO_FORNECEDOR_FUNCIONARIO` | `homologacaofornecedor_funcionario.php` | ativo |

O primeiro fluxo de responsabilidades será iniciado em **Gestão de
Fornecedores**, por ser a entidade principal do módulo. Gestão de Funcionários
será validada em seguida, após a conferência da diferença de cinco registros.

## 4. Pendências de integridade identificadas

As pendências abaixo já estão documentadas e devem permanecer separadas da
implementação de responsabilidades:

- `fo_contatos.codfornecedor`: 3 órfãos;
- `fo_fornecedoresxtipofornecedores.codfornecedor`: 160 órfãos;
- `fo_fornecedorxcriterios.codfornecedor`: 1.352 órfãos;
- `fo_fornecedorxusuario.CODFORNECEDOR`: 62 órfãos;
- `fo_funcionarios.CODFORNECEDOR`: 25 órfãos no banco novo;
- `fo_planodeacao.codfornecedor`: 1 órfão.

Esses registros não devem ser apagados automaticamente. A implementação das
responsabilidades deve trabalhar somente com fornecedores válidos e manter os
órfãos em uma situação identificável para saneamento posterior.

## 5. Responsabilidades do módulo

Tipos já cadastrados no modelo:

- `HOMOLOGACAO_FORNECEDOR` — responsável pelo fornecedor;
- `HOMOLOGACAO_DOCUMENTACAO` — responsável pela documentação do fornecedor.

O vínculo de fornecedor deve ser consultado a partir do fornecedor selecionado
em Gestão de Fornecedores. O vínculo de documentação deverá respeitar o
registro contextual de critérios/documentos, sem misturar responsáveis de
fornecedores diferentes.

As origens legadas já migradas para `responsabilidade_atribuicoes` são:

- `fo_fornecedorxusuario` para responsabilidade pelo fornecedor;
- `fo_fornecedorxcriterios` para responsabilidade pela documentação.

Não será alterado nem removido o campo legado de responsável existente em
`fo_fornecedores`; a nova estrutura continuará sendo uma representação
complementar e auditável.

## 6. Próximas ações técnicas

1. Identificar os cinco funcionários presentes em `green_modelo` e ausentes em
   `gmodelo_dbp`.
2. Criar a view de responsabilidades por fornecedor, com histórico e situação.
3. Criar a consulta contextual a partir da linha selecionada em Gestão de
   Fornecedores.
4. Criar a manutenção de responsabilidades do fornecedor com inclusão,
   edição, exclusão lógica e permissões.
5. Criar a consulta/manutenção contextual da documentação, mantendo o
   fornecedor e o critério no contexto.
6. Criar o roteiro PER-007 para Gestão de Fornecedores.
7. Homologar primeiro o fornecedor; depois validar Gestão de Funcionários.

## 7. Regra de segurança da implementação

Nenhum órfão será apagado, corrigido ou reassociado automaticamente nesta
etapa. Nenhuma atribuição legada será removida. Toda exclusão feita pela nova
interface será lógica, preservando histórico e identificando a entidade e o
registro de origem.

## 8. Implementação inicial — Gestão de Fornecedores

Foi implementada a primeira versão da consulta e manutenção de
responsabilidades no fornecedor:

- view `vw_responsabilidade_fornecedores`, criada pelo script
  `database/112_responsabilidades_fornecedores.sql`;
- consulta contextual em `principal/fornecedor_responsabilidades.php`;
- manutenção em `principal/fornecedor_responsabilidades_edicao.php`;
- botão **Responsabilidades** integrado à linha selecionada da Gestão de
  Fornecedores;
- consulta dos vínculos legados de `fo_fornecedorxusuario`;
- manutenção de novos vínculos diretos em `fo_fornecedores`;
- exclusão lógica e preservação de histórico;
- permissões vinculadas à transação
  `HOMOLOGACAO_FORNECEDOR_FORNECEDOR`;
- órfãos mantidos fora do escopo, conforme decisão funcional.

O script SQL 112 foi aplicado no `gmodelo_dbp` e a view foi confirmada. Os
endpoints PHP e a tela principal passaram na verificação de sintaxe.

O roteiro **PER-007** foi criado em
`tests/roteiro_homologacao_PER007_responsabilidades_fornecedor_20260821.txt`.
O módulo está pronto para homologação visual da consulta e manutenção no
fornecedor antes de avançar para Gestão de Funcionários ou responsabilidades
de documentação.
