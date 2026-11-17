# P2 — Green Content/GED e Workflow

Implementada a fundação universal por tenant em `content_documents` e `content_versions`, sem substituir nem alterar as tabelas GED legadas (`documentos` e relacionamentos). Cada documento possui chave idempotente, código, origem, unidade, status e versão corrente. Cada versão registra hash SHA-256, metadados e chave de armazenamento.

O `ContentService` integra criação, versionamento, envio para aprovação e aprovação ao `WorkflowService`, usando o fluxo publicado `CONTENT_APPROVAL`. As operações geram auditoria em `logs_auditoria`. A migração 189 é idempotente e foi preparada para Atlas, Modelo e Modelo2.

O teste P2 cria um documento temporário na Atlas, duas versões, inicia e aprova o workflow, validando status, quantidade de versões, histórico e auditoria. A próxima etapa é ligar a interface GED ao serviço e definir o adaptador de armazenamento dos arquivos, mantendo a compatibilidade com o legado.
