# Correções de processamento assíncrono

As extrações de PDF, imagens e documentos convertíveis são enfileiradas em `green_ocr_jobs` e executadas pelo `GreenPortal-OcrWorker`.

As telas de licenças, funcionários, critérios de funcionários e critérios de fornecedores aguardam o job do OCR antes de enviar o texto para análise. As APIs de funcionários e fornecedores não executam a engine de extração quando chamadas diretamente.

A exportação permanece em `exportacoes_grid` e é processada exclusivamente pelo `GreenPortal-ExportWorker`. As referências antigas em `principal/backup` são históricas e não fazem parte do fluxo carregado pelo portal.

A análise documental dos controles, infraestrutura e treinamentos utiliza `green_ia_document_jobs`. O Apache coloca o texto extraído na fila e o `GreenPortal-IaWorker` chama a IA, grava o resultado e atualiza o registro GED. A fila é idempotente e usa procedures/views do banco.

Os fluxos especializados de licenças, funcionários, critérios de funcionários e critérios de fornecedores também usam a mesma fila para a chamada de IA; o pós-processamento específico continua nas APIs de cada domínio.

O script `scripts/validar-agendamentos-greenportal.ps1` valida, em PowerShell elevado, as tarefas `GreenPortal-ExportWorker`, `GreenPortal-OcrWorker` e `GreenPortal-IaWorker`, além de detectar nomes legados.
