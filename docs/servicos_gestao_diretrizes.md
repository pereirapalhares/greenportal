# Gestão de serviços

> **Atualização de 11/11/2026 (migração 353, RN-PLT-142 e 143):** os serviços rodam pelo orquestrador (3 trilhas e o vigia, tarefas `GreenPortal-Orquestrador-*`). As ~20 tarefas soltas descritas abaixo estão desativadas. A tela `principal/servicos.php` mostra as trilhas, o vigia, os problemas atuais, o cadastro de cada serviço (`orq_servicos`) e o histórico (`orq_execucoes`). O ADMINISTRADOR ajusta o cadastro e tira suspensões; a tela não envia mais comandos ao Agendador. O texto abaixo é o histórico da versão anterior.

## Escopo

A transação `/principal/servicos.php`, acessível em **Administração > Serviços**, centraliza a consulta do estado das tarefas PHP do Green Portal e das filas de processamento.

O catálogo, o status e a auditoria ficam no banco central `guniversal_dbp`. As filas permanecem no banco de cada empresa, mantendo o isolamento multitenant.

Todos os executores usam tarefas com prefixo `GreenPortal-` na pasta `\\GreenSolutions\\` do Agendador de Tarefas do Windows. O catálogo inclui tarefas de agenda, responsabilidades, workflow, conteúdo/GED, IA, OCR, notificações, tarefas gerais e exportação Excel.

As tarefas de Excel e Inteligência Artificial substituem os antigos processos HTTP residentes das portas 8787 e 8788. A aplicação web não inicia, encerra ou consulta essas portas.

## Perfis

- `ADMINISTRADOR`: visualiza todas as empresas, atualiza o monitoramento e pode enviar comandos às tarefas previamente cadastradas;
- `ADM_DA_EMPRESA`: visualiza somente as filas e o estado dos serviços, sem controlar tarefas globais.

As ações de controle usam somente nomes de tarefas fixos do catálogo. A tela não aceita comandos livres do sistema.

## Persistência

- `guniversal_dbp.servicos`: catálogo central das tarefas;
- `guniversal_dbp.servicos_status`: último estado conhecido, `LastTaskResult` e verificação;
- `guniversal_dbp.servicos_auditoria`: usuário, ação, resultado e mensagem;
- `<banco_da_empresa>.green_service_runs`: execução, quantidade, status e detalhes de cada worker;
- `<banco_da_empresa>.exportacoes_grid`: fila de exportações;
- `<banco_da_empresa>.ml_potenciais_solicitacoes`: fila de IA.

A implantação estrutural inicial está registrada em `database/153_servicos_gestao.sql`. O catálogo de tarefas do Windows está em `database/197_windows_task_scheduler_catalog.sql` e pode ser aplicado por `scripts/aplicar-migracao-197-windows-task-scheduler.php`.
