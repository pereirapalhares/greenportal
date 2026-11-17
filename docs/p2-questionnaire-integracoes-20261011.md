# P2 — Integração de questionários por contexto

## Entrega

O motor universal de questionários passou a aceitar os contextos `LEGAL`, `SGA`, `QUALITY` e `WORKFLOW`.

- O catálogo continua em `guniversal_dbp` e é filtrado pelo campo `modulo`.
- Execuções, respostas, resultados e evidências continuam no banco do tenant.
- A execução usa chave de idempotência e não duplica o questionário quando o mesmo contexto é reaberto.
- Evidências são associadas ao questionário, pergunta e registro de origem; a inclusão é idempotente.
- Ao concluir um questionário contextual, o Workflow avança para `REVISAO`.
- A aprovação humana pode levar o Workflow a `FINALIZADO`; a rejeição leva a `CANCELADO`.
- Fornecedores permanecem compatíveis com o fluxo `SUPPLIER_QUESTIONNAIRE` já existente.

## Pontos de entrada

Foram adicionados lançadores contextuais aos grids de:

- Requisitos legais e seus relacionamentos;
- Licenças, medições e monitoramentos, treinamentos e infraestrutura;
- Controles e documentos;
- Ocorrências de Qualidade.

A execução usa `principal/questionario_execucao.php` e a API `principal/api/questionario_execucao.php`. O contexto nunca escolhe o banco: o tenant continua sendo resolvido exclusivamente pela sessão autenticada.

## Workflows

A migração `196_questionnaire_context_workflows.sql` cria, de forma idempotente, os fluxos por tenant:

- `LEGAL_QUESTIONNAIRE`;
- `SGA_QUESTIONNAIRE`;
- `QUALITY_QUESTIONNAIRE`;
- `WORKFLOW_QUESTIONNAIRE`.

Cada fluxo possui as etapas `INICIO`, `REVISAO`, `FINALIZADO` e `CANCELADO`, com as ações `ENVIAR`, `APROVAR` e `REJEITAR`.

## Homologação

Executados com sucesso:

```powershell
E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-196-questionnaire-context.php
E:\wamp\bin\php\php8.4.15\php.exe scripts\testar-questionnaire-contextos-p2.php
```

O teste validou os quatro contextos em `gatlas_dbp`, `gmodelo_dbp` e `gmodelo2_dbp`, incluindo catálogo filtrado, execução, resposta, evidência, idempotência, revisão e aprovação. Os dados temporários foram removidos ao final; não restaram execuções ou workflows de teste.
