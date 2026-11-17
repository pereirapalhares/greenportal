# P1/P2 — WorkflowService

Versão: GreenSolutions 4.0.1

Foi criada a fundação universal de workflows, sem substituir os fluxos legados.

## Estrutura

- `workflow_definicoes`: fluxos versionados e publicados;
- `workflow_etapas`: etapas ordenadas;
- `workflow_transicoes`: ações autorizadas entre etapas;
- `workflow_instancias`: execução idempotente por objeto de origem;
- `workflow_historico`: trilha de transições, usuário e motivo.

O `WorkflowService` permite iniciar uma instância idempotente e executar
transições autorizadas, exigindo motivo quando a transição configurar essa
regra. Instâncias concluídas ou canceladas registram data de finalização.

`database/188_workflow_service.sql` foi aplicado em Atlas, Modelo e Modelo2,
com registro `GREEN_WORKFLOW_188` em `registros_migracao`.

O teste controlado confirmou início, reexecução sem duplicação, transição para
`FINALIZADO` e histórico com duas etapas. Os workflows específicos de não
conformidades permanecem em operação até serem migrados individualmente.
