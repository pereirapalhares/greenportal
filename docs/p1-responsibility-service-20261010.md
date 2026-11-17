# P1 — ResponsibilityService integrado ao TaskService

Versão: GreenSolutions 4.0.1

## Implementado

- `classes/ResponsibilityService.php` como serviço transversal por tenant.
- Consolidação de responsabilidades efetivas por usuário usando
  `vw_responsabilidade_efetiva`.
- Consulta de atribuições diretas e por grupo.
- Consulta de delegações ativas com vigência.
- Detecção de mais de uma atribuição principal no mesmo objeto.
- Tabela `responsabilidade_substituicoes` para solicitações formais.
- Aprovação e revogação de delegações e substituições.
- Auditoria das aprovações, revogações e solicitações.
- Geração idempotente de tarefas para responsabilidades que vencem em até
  30 dias.
- Processamento dessas tarefas pelo `scripts/task-worker.php`, usando alertas.

## Migração

`database/184_responsabilidade_substituicoes.sql` foi aplicado em:

- `gatlas_dbp`
- `gmodelo_dbp`
- `gmodelo2_dbp`

O registro `GREEN_RESPONSIBILITY_184` foi gravado em
`registros_migracao` nos três tenants.

## Validação

O serviço foi validado nos três bancos para:

- consulta de responsabilidades por usuário;
- consulta de responsabilidades por grupo;
- delegações ativas;
- conflitos de atribuições principais;
- execução do worker sem erros;
- reexecução idempotente sem duplicação de tarefas.

O Atlas atualmente não possui responsabilidades ativas para o usuário de teste;
por isso não foram criadas tarefas automáticas nesse tenant. Isso não é erro de
processamento; indica ausência de atribuições com vencimento no horizonte de 30
dias.

## Transação de substituição

A transação `principal/responsabilidade_substituicao.php` foi criada com:

- consulta das solicitações;
- solicitação para usuário ou grupo;
- vigência e motivo obrigatório;
- aprovação e revogação autorizadas;
- menu e permissão inicial para `ADMINISTRADOR`;
- auditoria das ações.

A migração `GREEN_RESPONSIBILITY_185` foi aplicada nos três tenants. O teste
controlado no Modelo confirmou solicitação, aprovação, efetivação, revogação e
limpeza do registro temporário, mantendo a auditoria.

O próximo bloco do P1 é o `AgendaService` com centralização de notificações,
pendências e tarefas recorrentes.
