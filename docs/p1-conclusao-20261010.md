# Conclusão do P1 — GreenSolutions 4.0.1

O P1 foi concluído tecnicamente nos tenants `gatlas_dbp`, `gmodelo_dbp` e `gmodelo2_dbp`.

## Entregas consolidadas

- `TaskService`: tarefas idempotentes, prioridade, responsável, prazo, tentativas e histórico.
- `ResponsibilityService`: responsabilidades efetivas, vigência, delegação/substituição e geração de tarefas.
- `AgendaService`: agenda, recorrência e materialização de ocorrências.
- `WorkflowService`: instâncias, etapas, transições, tarefas e auditoria.
- `ContentService`/GED: metadados, versões, storage configurável e integração inicial com aprovação.
- Workers PHP no Agendador do Windows para tarefas, agenda, responsabilidades, workflow, conteúdo, GED, IA, OCR e notificações.
- IA legal e OCR com filas assíncronas, recuperação, tentativas, idempotência e isolamento por tenant.
- Potenciais IA aprovados agora geram tarefas `IA_APROVACAO` com responsável, unidade, prioridade, prazo padrão de sete dias, alerta e auditoria.

## Migração 192

A coluna `green_tasks.codunidade` foi criada para preservar o contexto operacional da unidade. O reconciliador `AiApprovedTaskService` utiliza a chave `IA-POTENCIAL:<codpotencial>` e pode ser reexecutado sem duplicar tarefas, alertas ou auditoria.

## Homologação

Executar:

```powershell
E:\wamp\bin\php\php8.4.15\php.exe scripts\homologar-p1-final.php
E:\wamp\bin\php\php8.4.15\php.exe scripts\validar-servicos-windows.php
```

Critérios validados: migração idempotente, zero potencial aprovado sem tarefa, zero chaves duplicadas, última execução de IA concluída e processamento nos três tenants.

O próximo estágio documental é o P2: `QuestionnaireService` universal, inventário/migração dos questionários legados `amb_questionario_*` e integração com fornecedores, Legal, SGA, Qualidade e Workflow.
