# Exportação Excel pelo Agendador do Windows

As solicitações de exportação são gravadas em `<banco_do_tenant>.exportacoes_grid` com status `SOLICITADA`. O processamento é feito pelo worker PHP `scripts/export-worker.php`, executado pela tarefa `\\GreenSolutions\\GreenPortal-ExportWorker`.

O worker percorre somente tenants permitidos pelo padrão `g[a-z0-9]+_dbp`, usa `WindowsServiceRuntime` para bloquear concorrência e registra a execução em `green_service_runs`. Cada planilha é processada por `classes/ExportacaoGridWorker.php` e continua usando as procedures de CRUD existentes.

## Agendamento

## Regra obrigatoria de execucao

As telas web nao iniciam `ExportacaoGridWorker.php` e nao executam PDF/XLSX no Apache. Elas somente inserem a solicitacao em `exportacoes_grid` com status `SOLICITADA`. O unico executor autorizado e `scripts/export-worker.php`, iniciado pela tarefa `GreenPortal-ExportWorker` do Agendador de Tarefas do Windows.

`classes/ExportacaoGridWorker.php` exige a variavel interna `GREENPORTAL_EXPORT_FROM_SCHEDULER=1`, definida somente pelo script agendado. Assim, uma tentativa de disparo direto pelo Apache e recusada e permanece na fila para o proximo ciclo do Agendador.

## Extracao de documentos

PDF, imagem e Word enviados para leitura sao enfileirados em `green_ocr_jobs`. A API web nao executa `pdftotext`, Tesseract ou LibreOffice. O `GreenPortal-OcrWorker`, executado pelo Agendador do Windows, processa a fila e a tela consulta o resultado pelo `documentos_ocr_status.php`.

O servico HTTP legado `ExportacaoGridService.php` foi removido do fluxo operacional. Os arquivos em `principal/backup` sao apenas copias historicas e nao sao carregados pelo portal.

O cadastro é feito junto com os demais workers:

```powershell
.\scripts\criar-agendamentos-greenportal.ps1
```

O script deve ser executado como Administrador. A tarefa usa PHP CLI oculto, conta `SYSTEM`, intervalo de um minuto e política `IgnoreNew` para não haver execução simultânea.

Os scripts `executar-servico-exportacao.ps1` e `instalar-servico-exportacao.ps1` foram descontinuados. O serviço residente HTTP da porta 8787 não faz mais parte do fluxo do Green Portal.
