# Diretriz oficial — serviços PHP no Agendador do Windows

Todos os serviços internos do GreenSolutions devem possuir executor PHP CLI e funcionar no Windows Task Scheduler. A camada web apenas registra solicitações; processamento recorrente, filas, notificações, materialização de agenda, responsabilidades, workflow, conteúdo/GED, IA e OCR devem ser executados por PHP.

## Executores

- `scripts/task-worker.php`: tarefas e notificações; já é o worker principal e possui o VBS oculto `scripts/task-worker-hidden.vbs`.
- `scripts/agenda-worker.php`: materializa ocorrências recorrentes.
- `scripts/responsibility-worker.php`: gera tarefas de responsabilidades próximas do vencimento.
- `scripts/workflow-worker.php`: monitora instâncias ativas.
- `scripts/content-worker.php`: monitora conteúdos em revisão.
- `scripts/ged-worker.php`: verifica o storage GED configurado.
- `scripts/ia-worker.php`: monitora solicitações de análise de IA pendentes/processando.
- `scripts/ocr-worker.php`: verifica a configuração do integrador OCR PHP.
- `scripts/notification-worker.php`: processa tarefas de notificação.
- `scripts/export-worker.php`: processa a fila de exportações Excel por tenant.

Os executores, exceto `task-worker.php`, utilizam `scripts/windows-service.php`, com bloqueio por serviço, isolamento por tenant, `--database=gatlas_dbp` opcional, `--dry-run` e registro em `green_service_runs`. A migração 190 cria a tabela de acompanhamento nos três tenants.

Excel e Inteligência Artificial também são tarefas do Agendador do Windows. Os antigos processos residentes HTTP das portas 8787 e 8788 não são mais iniciados nem controlados pela aplicação web. A tela `principal/servicos.php` consulta a pasta `\\GreenSolutions\\` e, quando autorizada, envia comandos `Start-ScheduledTask`/`Stop-ScheduledTask` para tarefas previamente cadastradas.

## Agendamento recomendado

Executar com `E:\wamp\bin\php\php8.4.15\php.exe`, usuário com permissão de leitura do projeto e escrita em `storage`. Usar a ação “Iniciar um programa”, sem console visível por meio de um `.vbs` oculto equivalente ao worker existente.

Para criar automaticamente a estrutura, executar como Administrador:

```powershell
.\scripts\criar-agendamentos-greenportal.ps1
```

O script cria a pasta `\GreenSolutions\` e as tarefas `GreenPortal-*`. Para restringir a homologação a um tenant, usar `-Database gatlas_dbp`; para iniciar imediatamente após o cadastro, usar `-ExecutarAgora`.

Após a primeira execução, validar o Agendador e o banco com:

```powershell
.\scripts\homologar-agendamentos-greenportal.ps1
```

O teste de concorrência PHP está em `scripts/testar-bloqueio-worker.php` e a validação dos três tenants em `scripts/validar-servicos-windows.php`.

- Tarefas/notificações: a cada 1 minuto;
- Agenda e responsabilidades: a cada 5 minutos;
- IA e OCR: a cada 1 minuto quando houver fila;
- Workflow, conteúdo e GED: a cada 10 minutos;
- Exportação Excel: a cada 1 minuto, processando somente filas pendentes;
- Execução por todos os tenants: sem `--database`;
- Homologação isolada: informar `--database=gatlas_dbp`.

Todos os workers devem permanecer idempotentes, registrar erro e não aceitar nome de banco livre. O banco somente pode ser um tenant validado pelo padrão `g[a-z0-9]+_dbp`.
