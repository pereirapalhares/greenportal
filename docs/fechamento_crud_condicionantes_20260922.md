# Fechamento do CRUD de Condicionantes — 22/09/2026

- O roteiro foi corrigido para selecionar a linha por evento direto, evitando células ocultas.
- ADMINISTRADOR incluiu um registro temporário.
- O grid recarregou após POST HTTP 302.
- O registro temporário foi localizado, selecionado e excluído pela interface dentro do iframe contextual.
- A consulta posterior ao banco retornou zero registros de teste.
- Os bloqueios dos demais perfis permanecem conforme a matriz atual.

Evidências:

- `tests/resultado-crud-condicionantes-cdp-v2-20260922.json`
- `tests/resultado-exclusao-condicionante-celula-visivel-20260922.json`
- `scripts/excluir-condicionante-celula-visivel-cdp.js`

Status do CRUD de Condicionantes: **APROVADO TECNICAMENTE**.

Continuam pendentes apenas os gates externos: aprovação formal da matriz e do relatório, segundo tenant, ambiente semelhante à produção, agendamento operacional da retenção e autorização de go-live.
