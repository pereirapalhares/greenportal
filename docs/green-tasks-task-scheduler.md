# Green Tasks e Agendador do Windows

Versão: GreenSolutions 4.0.1

O `TaskService` é o contrato de negócio. O `scripts/task-worker.php` é apenas
um consumidor desse contrato e pode ser executado pelo Agendador de Tarefas do
Windows, por API ou futuramente por um serviço contínuo.

## Instalação

1. Aplicar `database/183_green_tasks.sql` nos bancos `gatlas_dbp`,
   `gmodelo_dbp` e `gmodelo2_dbp`.
2. Confirmar que PHP CLI e o driver `pdo_mysql` estão habilitados.
3. Criar uma tarefa do Windows com execução a cada 1 minuto:

```text
Programa: E:\wamp\bin\php\php8.x.x\php.exe
Argumentos: E:\wamp\apps\greenportal\scripts\task-worker.php --limit=20
Iniciar em: E:\wamp\apps\greenportal
```

4. Usar uma conta sem privilégios administrativos desnecessários e marcar
   execução mesmo sem sessão ativa.

## Contrato operacional

- A chave `chave_idempotencia` é obrigatória e única dentro do tenant.
- A tarefa passa por `PENDENTE`, `PROCESSANDO`, `CONCLUIDA` ou `ERRO`.
- O worker possui bloqueio de dez minutos, até três tentativas e reprocessamento
  seguro.
- O tipo `NOTIFICACAO` grava em `alertas` usando o link `TASK:<codtarefa>` como
  chave idempotente.
- O worker nunca recebe banco livremente: aceita somente nomes no padrão
  `g<tenant>_dbp` e, sem argumento, usa a lista configurada/permitida.
- Cada tenant possui suas próprias tabelas; nenhuma tarefa é compartilhada
  entre empresas.

Os próximos handlers devem ser adicionados ao `TaskService`/worker por tipo,
sem colocar regras de negócio em `task-worker.php` e sem executar SQL arbitrário
recebido de fora.
