# Homologacao P0 - Atlas

Data: 2026-10-10
Ambiente: WAMP/MySQL de homologacao
Tenant: `gatlas_dbp`

## Resultado

O P0 foi homologado tecnicamente para a Atlas.

- CRUD de responsabilidades de treinamentos: aprovado com Firefox.
- CRUD de responsabilidades de infraestrutura: aprovado com Firefox usando registro temporario; o registro e a responsabilidade foram removidos ao final.
- APIs de opcoes, GED, IA, OCR e relacionamentos: respostas HTTP controladas, sem erro 500.
- Acesso direto sem sessao: HTTP 401.
- Isolamento: nenhuma FK externa ou view da Atlas referenciando `gmodelo_dbp` ou `gmodelo2_dbp`.
- Auditoria: login e acessos registrados com tenant, versao 4.0.1 e correlation_id.
- Rollback: validado sem persistencia da alteracao temporaria.
- Migracao idempotente: duas execucoes sem duplicacao.
- Backup: dumps do legado Atlas gerados com SHA-256 validado.
- Restauracao: dumps restaurados em bancos temporarios; 256 objetos de `green_atlas` e 28 de `green_atlas_seguranca` foram restaurados e os bancos temporarios foram removidos.

## Observacoes

O Modelo2 continua `INATIVA` no cadastro central, conforme a politica de
homologacao. Nenhum banco ativo de tenant foi sobrescrito durante o restore.

O inventario de transacoes permanece como backlog de migracao incremental; a
homologacao P0 refere-se a seguranca, isolamento, autorizacao, auditoria,
idempotencia, backup e restore do lote validado.
