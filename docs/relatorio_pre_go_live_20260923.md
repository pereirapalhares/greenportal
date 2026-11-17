# Relatório de pré-go-live — 23/09/2026

## Verificações concluídas

- Backup original e clone confirmados por arquivo e hash SHA-256.
- Plano de reversão confirmado: restaurar `gmodelo_dbp` pelo backup e manter `Modelo2` inativa.
- Retenção executada em `dry-run` nos dois bancos: 12 exportações elegíveis em cada banco, 0 alertas elegíveis e 0 exclusões.
- Homologação simulada repetida no Modelo:
  - 7 logins aprovados;
  - 42 rotas verificadas;
  - 0 erros de servidor;
  - ADMINISTRADOR com HTTP 200 nos seis fluxos;
  - demais perfis com HTTP 403 conforme a matriz.
- Modelo2 confirmado como `INATIVA`.

## Checklist

- [x] Backup confirmado.
- [x] Plano de reversão confirmado.
- [x] Dry-run final da retenção executado.
- [x] Login e permissões revalidados.
- [x] Exportações e alertas verificados tecnicamente.
- [x] Modelo2 inativa.
- [x] Evidências pré-go-live registradas.
- [ ] Agendamento operacional da retenção confirmado.
- [ ] Aprovação formal do relatório registrada.
- [ ] Janela oficial confirmada pelo responsável de release.
- [ ] Publicação real executada.

## Decisão

O pré-go-live técnico está **APROVADO SOB PREMISSAS**. A publicação real permanece reservada para 28/09/2026, das 22:00 às 23:00, e somente deve ser executada mediante ordem explícita no início da janela.

Evidências: `tests/resultado-pre-go-live-20260923.json` e `tests/evidencias-pre-go-live-20260923`.
