# Monitoramento dos serviços no Agendador do Windows

## Funcionamento

`GreenPortal-ServiceMonitor` é uma tarefa PHP de execução única, disparada pelo Agendador do Windows a cada cinco minutos. Ela consulta o catálogo completo de tarefas por meio do `schtasks.exe`, filtra os nomes `GreenPortal-*`, coleta o estado de cada tarefa e grava um snapshot no banco central `guniversal_dbp`. O PHP não possui laço permanente; a consulta possui limite de 15 segundos e o processo externo é encerrado se ultrapassar esse prazo.

O limite da tarefa no Agendador é de dois minutos. Se uma coleta excepcionalmente travar, o Windows encerra a instância e permite que o próximo disparo faça uma nova coleta. `MultipleInstances=IgnoreNew` impede sobreposição.

O `GreenPortal-ServiceMonitor` não usa `RestartCount=0`: essa combinação gera XML inválido no Agendador. Para ele, o script omite a configuração de reinício automático.

`NAO_INSTALADO` só é utilizado quando a consulta individual conclui validamente que uma tarefa não existe. Se nenhuma tarefa puder ser consultada, o monitor registra `ERRO_COLETA`, evitando mascarar falta de permissão como ausência de instalação.

Para aplicar a correção em uma instalação existente, executar o script em PowerShell elevado. Ele encerra uma instância antiga do monitor antes de registrar novamente a tarefa:

```powershell
.\scripts\criar-agendamentos-greenportal.ps1
```

A transação `principal/servicos.php` não executa PowerShell nem consulta os bancos dos tenants durante o carregamento. Ela lê somente:

- `servicos_task_status`: estado, `LastTaskResult`, última execução, próxima execução e mensagem;
- `servicos_fila_status`: contadores de filas de Excel e IA por tenant;
- `servicos_exportacoes_cache`: últimas solicitações de planilhas;
- `servicos_monitoramento_execucoes`: histórico das coletas.

Quando a última coleta tiver mais de dez minutos, a tela exibe `Atualização pendente`.

## Instalação

Após aplicar a migração 198, executar como Administrador:

```powershell
.\scripts\criar-agendamentos-greenportal.ps1 -ExecutarAgora
```

O total esperado passa a ser 11 tarefas, incluindo `GreenPortal-ServiceMonitor`. A validação é feita por:

```powershell
.\scripts\homologar-agendamentos-greenportal.ps1
```
