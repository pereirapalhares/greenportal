# P1 — AgendaService, notificações e recorrências

Versão: GreenSolutions 4.0.1

## Implementado

- `classes/AgendaService.php` como serviço transversal por tenant.
- Agenda independente das estruturas específicas de `ev_eventos`.
- Eventos com criador, responsável, período, descrição, local e origem.
- Chave de idempotência para evitar eventos duplicados.
- Recorrências diárias, semanais e mensais.
- Materialização de ocorrências pelo `scripts/task-worker.php`.
- Notificação automática ao responsável por meio do `TaskService`.
- Execução idempotente registrada em `agenda_execucoes`.
- `ev_eventos` legado preservado para compatibilidade com monitoramentos.

## Migração

`database/186_agenda_service.sql` foi aplicado em `gatlas_dbp`, `gmodelo_dbp`
e `gmodelo2_dbp`. Foram criadas as tabelas:

- `agenda_eventos`;
- `agenda_recorrencias`;
- `agenda_execucoes`.

O registro `GREEN_AGENDA_186` foi gravado em `registros_migracao` nos três
tenants.

## Worker

O worker executa, por tenant:

1. materialização de recorrências vencidas ou programadas para o próximo dia;
2. geração de tarefas de notificação;
3. geração de tarefas de responsabilidades próximas do vencimento;
4. processamento das notificações pendentes.

## Validação

Uma recorrência diária temporária foi criada no Atlas, materializada pelo
worker, transformada em evento e notificação, e executada uma segunda vez sem
duplicação. Todos os registros temporários foram removidos.

O próximo incremento é integrar as telas de agenda existentes à nova fonte
`agenda_eventos` e criar a consulta universal de pendências do usuário.

## Integração concluída

O incremento foi aplicado:

- `eventos.php` sincroniza eventos de monitoramento com `agenda_eventos` por
  chave de origem, mantendo `ev_eventos` para compatibilidade;
- alterações e exclusões atualizam ou cancelam a cópia universal;
- `PendenciasService` consolida tarefas, eventos da agenda, responsabilidades
  próximas do vencimento e alertas não lidos;
- `painel_pendencias_universal.php` exibe a consulta consolidada;
- o menu `PAINEL_PENDENCIAS_UNIVERSAL` foi cadastrado com permissão inicial
  para `ADMINISTRADOR`.

A migração `GREEN_PENDENCIAS_187` foi aplicada nos três tenants e a consulta
foi validada em Atlas, Modelo e Modelo2 sem erro de isolamento.
