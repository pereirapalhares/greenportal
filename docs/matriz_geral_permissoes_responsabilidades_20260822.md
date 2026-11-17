# Matriz geral de permissões e responsabilidades — atualização

**Data:** 22/08/2026  
**Tenant validado:** `gmodelo_dbp` — Empresa Modelo  
**Documento-base:** `matriz_geral_permissoes_responsabilidades_20260821.md`

## 1. Homologações concluídas

| Roteiro | Módulo/escopo | Resultado |
|---|---|---|
| PER-001 a PER-005 | Licenças e Requisitos Legais | Aprovados |
| PER-006 | Medição e Monitoramento | Aprovado no reteste |
| PER-007 | Gestão de Fornecedores | Aprovado |
| PER-008 | Gestão de Funcionários | Aprovado no reteste final |
| PER-009 | Responsabilidades por Treinamento | Aprovado |
| Reteste do filtro Nome | Gestão de Funcionários | Aprovado; 17 verificações |

## 2. Matriz funcional consolidada

| Visualizar | Incluir | Editar | Excluir | Comportamento |
|---:|---:|---:|---:|---|
| 0 | 0 | 0 | 0 | acesso bloqueado |
| 1 | 0 | 0 | 0 | consulta somente leitura |
| 1 | 1 | 0 | 0 | inclusão; registros existentes bloqueados |
| 1 | 0 | 1 | 0 | edição; inclusão bloqueada |
| 1 | 1 | 1 | 0 | inclusão e edição |
| 1 | 0 | 0 | 1 | abertura da manutenção e exclusão lógica |
| 1 | 1 | 0 | 1 | inclusão e exclusão lógica |
| 1 | 0 | 1 | 1 | edição e exclusão lógica |
| 1 | 1 | 1 | 1 | manutenção completa |

As permissões são aplicadas no cliente apenas para orientar a interface e são
validadas novamente no servidor. Exclusões preservam o histórico com `ATIVO=0`.

## 3. Estado dos módulos

| Módulo | Entidade/origem | Interface | Estado |
|---|---|---|---|
| `LICENCAS` | `li_licencas` e grupos legados | consulta e manutenção | homologado |
| `REQUISITOS_LEGAIS` | `legislacaoxsituacao.CODSITUACAO` | consulta e manutenção | homologado |
| `MEDICAO_MONITORAMENTO` | `mm_monitoramento.CODMONITORAMENTO` | consulta e manutenção | homologado PER-006 |
| `HOMOLOGACAO` — fornecedores | fornecedor do tenant | consulta e manutenção | homologado PER-007 |
| `HOMOLOGACAO` — funcionários | `fo_funcionarios` | consulta e manutenção | homologado PER-008 |
| `TREINAMENTO` | `fo_funcionarioxtiposxtreinamentos.codigo` | consulta e manutenção | homologado PER-009 |
| `INFRAESTRUTURA` | `infraestrutura.CODINFRAESTRUTURA`; sem responsável legado | consulta e manutenção contextual implementadas | PER-010 pendente de homologação |
| `CONTROLE` | origem ainda não aprovada | não iniciar | pendente |

## 4. Correção adicional homologada

O filtro de coluna `NOME` da Gestão de Funcionários foi corrigido em
`assets/js/grid-pagination.js`. O componente agora rejeita arrays como objeto
de filtros de contexto, evitando que `data-context-filters="[]"` descarte os
filtros textuais durante a serialização.

O reteste automatizado confirmou:

- correspondência parcial por nome;
- ausência de resultados sem correspondência;
- limpeza e restauração da grade;
- combinação com busca geral;
- cabeçalho visível e botão contextual desabilitado sem seleção.

## 5. Próximo passo

A frente de Homologação de Fornecedores está encerrada funcionalmente. O próximo
módulo só deve ser iniciado após a definição da origem legada, da entidade,
chave contextual e da transação. Não criar interfaces ou migrações para
`INFRAESTRUTURA` ou `CONTROLE` por inferência.
