# Matriz geral de permissões e responsabilidades

**Data:** 21/08/2026  
**Tenant validado:** `gmodelo_dbp` — Empresa Modelo  
**Escopo:** modelo de responsabilidades da Fase 1

## 1. Estado da homologação

| Item | Escopo | Resultado |
|---|---|---|
| PER-001 | consulta de responsabilidades | aprovado |
| PER-002 | bloqueio de consulta sem Visualizar | aprovado |
| PER-003 | inclusão/edição conforme permissões | aprovado |
| PER-004 | exclusão lógica com Excluir sem Editar/Incluir | aprovado sem ressalvas |
| PER-005 | manutenção completa autorizada | aprovado funcionalmente |

Os relatórios de referência estão em `tests/` e as homologações visuais foram
executadas nos módulos Licenças e Requisitos Legais.

## 2. Matriz funcional de permissões

| Visualizar | Incluir | Editar | Excluir | Comportamento esperado |
|---:|---:|---:|---:|---|
| 0 | 0 | 0 | 0 | transação e responsabilidades bloqueadas |
| 1 | 0 | 0 | 0 | consulta somente; manutenção bloqueada |
| 1 | 1 | 0 | 0 | consulta e inclusão; campos existentes bloqueados |
| 1 | 0 | 1 | 0 | consulta e edição; Adicionar oculto |
| 1 | 1 | 1 | 0 | consulta, inclusão e edição |
| 1 | 0 | 0 | 1 | consulta, abertura da manutenção e exclusão lógica |
| 1 | 1 | 0 | 1 | inclusão e exclusão lógica; edição bloqueada |
| 1 | 0 | 1 | 1 | edição e exclusão lógica; inclusão bloqueada |
| 1 | 1 | 1 | 1 | manutenção completa |

Regras comuns:

- `Visualizar` é obrigatório para consultar responsabilidades;
- `Incluir` controla o botão Adicionar e novas linhas;
- `Editar` controla os campos das atribuições existentes;
- `Excluir` controla a lixeira e a exclusão lógica;
- o servidor deve validar todas as operações, independentemente do bloqueio
  visual no navegador;
- exclusão lógica deve preservar o registro como histórico;
- mensagens devem representar o resultado real da operação e não expor SQL.

## 3. Perfis utilizados na homologação

| Perfil/usuário | Uso | Estado final |
|---|---|---|
| `ADMINISTRADOR` / `admin` | preparação, configuração e limpeza | permanente |
| `GESTOR` / `ueditor` | execução dos testes PER-001 a PER-005 | permissões restauradas ao baseline |

Durante o PER-005, o GESTOR recebeu temporariamente todas as permissões CRUD
nas duas transações. Após a execução, voltou ao baseline:

- `LICENCAS` (74): Incluir=0, Visualizar=0, Editar=0, Excluir=0;
- `REQUISITOS_LEGAIS` (306): Incluir=0, Visualizar=0, Editar=0, Excluir=0.

Exportar Excel e Gerar PDF foram preservados conforme o estado anterior.

## 4. Matriz de módulos do modelo

| Módulo | Tipos cadastrados | Vínculos legados aprovados | Interface de responsabilidade | Status |
|---|---:|---|---|---|
| `REQUISITOS_LEGAIS` | sim | `legislacaoxsituacao.CODUSUARIO` | consulta e manutenção | homologado PER-001 a PER-005 |
| `LICENCAS` | sim | campos `codusuario` e `codgrupo*` de `li_licencas` | consulta e manutenção | homologado PER-001 a PER-005 |
| `MEDICAO_MONITORAMENTO` | sim | `mm_monitoramento.codusuario` | ainda não criada | próximo candidato |
| `HOMOLOGACAO` | sim | fornecedor e documentação | Gestão de Fornecedores implementada e homologada PER-007; Gestão de Funcionários em validação PER-008 | implementação inicial em `gmodelo_dbp` |
| `TREINAMENTO` | sim | `fo_funcionarioxtiposxtreinamentos.codusuario` | regra funcional definida; 2 vínculos válidos e 953 órfãos identificados | implementação PER-009 |
| `INFRAESTRUTURA` | sim | origem legada ainda não definida | ainda não criada | requer decisão funcional |
| `CONTROLE` | sim | tabelas candidatas sem registros | ainda não criada | requer decisão funcional |

## 5. Próximo módulo em validação

O próximo candidato é `MEDICAO_MONITORAMENTO`, porque possui:

- tipo `MONITORAMENTO` cadastrado;
- origem legada definida em `mm_monitoramento.codusuario`;
- identificador de registro definido em `CODMONITORAMENTO`;
- dados legados identificados no inventário;
- destino funcional definido como usuário.

Essa validação começa pela prontidão estrutural e pelo contrato da interface.
Não deve haver migração adicional nem alteração de dados até a aprovação do
roteiro visual e da tela de manutenção.

## 6. Pendências de governança

- decidir o módulo funcional de `planodeacao.codusuario`;
- confirmar a separação entre fornecedor e documentação em Homologação;
- revisar a semântica dos vínculos de Treinamento;
- definir as origens de Infraestrutura e Controle;
- manter a replicação para novos tenants somente após a aprovação no
  `gmodelo_dbp`.

## 7. Próximo teste em andamento

O PER-008 valida a consulta e a manutenção de responsabilidades diretas em
`fo_funcionarios`. O campo `fo_funcionarioxtiposxtreinamentos.codusuario`
permanece no módulo `TREINAMENTO` e não deve ser apresentado como
responsabilidade do funcionário até revisão semântica.
