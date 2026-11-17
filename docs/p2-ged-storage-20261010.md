# P2 — Integração GED e armazenamento

Novos uploads da interface `principal/gestao_arquivos.php` passam pelo `GedStorageService` e usam `GGED_STORAGE_LOCAL_ROOT`, fora da área pública. A estrutura permanece segregada por banco legado, pasta lógica e chave numérica do registro.

O serviço valida tenant, sessão, permissão, chave, unidade, tamanho e caminho real. O nome físico é gerado no servidor. O MIME real, SHA-256, tamanho, nome e `storage_key` são registrados em `content_versions`; o documento Green Content correspondente é criado em `content_documents`. Upload, download e exclusão geram auditoria.

Arquivos já existentes no caminho legado `E:\projetoGreen\GED` continuam disponíveis em fallback somente leitura. Novos arquivos não são gravados no legado. A exclusão remove o arquivo físico, mas preserva os registros de auditoria e versionamento.

Validação realizada: PHP sem erros de sintaxe em `GedStorageService`, endpoint GED, `ContentService` e `WorkflowService`; o teste P2 de criação, duas versões, aprovação, histórico e auditoria permanece aprovado.
