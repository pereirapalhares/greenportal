# P1 — Filas assíncronas de IA e OCR

Implementação da versão GreenSolutions 4.0.1 para execução exclusivamente por PHP CLI no Agendador do Windows.

## IA de requisitos legais

`ml_potenciais_solicitacoes` continua sendo a fila oficial por tenant. O worker `GreenPortal-IaWorker` chama `services/InteligenciaArtificialService.php --once --database=<tenant>`, recupera solicitações expiradas, reserva uma solicitação por vez, chama o provedor configurado, grava potenciais, status e alertas. O processo não depende de serviço residente na porta 8788.

## OCR de documentos

`green_ocr_jobs` foi criada em `gatlas_dbp`, `gmodelo_dbp` e `gmodelo2_dbp`. A API `documentos_ocr.php` agora somente valida o arquivo e registra o job com chave idempotente; o `GreenPortal-OcrWorker` chama o serviço OCR, grava texto, páginas, confiança, tentativas e erro. A API `documentos_ocr_status.php` permite à interface acompanhar o processamento.

## Segurança e isolamento

- O job é criado no banco do tenant autenticado.
- O status do OCR só pode ser consultado pelo usuário que criou o job.
- O caminho do arquivo é validado dentro da pasta GED do tenant antes da inclusão na fila.
- Os workers aceitam somente bancos com padrão `g<tenant>_dbp`.
- Bloqueios do `WindowsServiceRuntime` impedem execução simultânea do mesmo serviço.
- Chaves idempotentes evitam duplicação em reenvios.

## Homologação executada

- Migração 191 aplicada e reexecutada nos três tenants.
- `ia` executado em modo único nos três tenants.
- `ocr` executado nos três tenants sem pendências.
- Validação dos nove serviços: últimas execuções `CONCLUIDA`, isolamento sem registros inválidos e sem duplicidades.
- Teste transacional da fila OCR: mesma chave retornou o mesmo job e o rollback não deixou registro.

Os três registros históricos de erro anteriores à homologação continuam preservados em `green_service_runs` para auditoria; não há erro na última execução de nenhum serviço.
