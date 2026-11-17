# Plano final de go-live — 23/09/2026

## Verificações executadas

- Backup original confirmado: `runtime/backups/gmodelo_dbp_pre_clone_20260922.sql`.
- Backup do clone confirmado: `runtime/backups/gmodelo2_dbp_clone_20260922.sql`.
- Hashes SHA-256 registrados no log da execução.
- Retenção repetida em `dry-run` nos dois bancos: 12 exportações elegíveis, 0 alertas elegíveis e 0 exclusões em cada banco.
- Isolamento Modelo/Modelo2 e matriz de permissões já aprovados tecnicamente.

## Responsáveis finais propostos

| Entrega | Responsável |
|---|---|
| Política e agendamento da retenção | Infraestrutura/DBA |
| Matriz de permissões | Segurança e responsável do projeto |
| Relatório de homologação | Responsável do projeto |
| Backup e reversão | Infraestrutura/DBA |
| Checklist e evidências | QA |
| Janela e execução de publicação | Responsável de release |
| Monitoramento pós-publicação | Infraestrutura/QA |

## Datas propostas

- 24/09/2026: aprovação da retenção, matriz e plano de reversão.
- 25/09/2026: aprovação do relatório e checklist de go-live.
- 28/09/2026: janela proposta de publicação, condicionada à autorização explícita.
- 28–29/09/2026: monitoramento pós-publicação e relatório de incidentes.

As datas são propostas operacionais, não estão confirmadas até manifestação do responsável do projeto.

## Checklist revalidado

- [x] Backup lógico disponível.
- [x] Plano de reversão definido: restaurar `gmodelo_dbp` a partir do backup e manter `Modelo2` inativa.
- [x] Isolamento entre tenants validado tecnicamente.
- [x] Matriz de permissões validada tecnicamente.
- [x] Retenção validada em `dry-run` nos dois bancos.
- [ ] Política e agendamento aprovados formalmente.
- [ ] Relatório aprovado formalmente.
- [ ] Datas e responsáveis confirmados.
- [ ] Janela oficial autorizada.
- [ ] Go-live executado.
- [ ] Monitoramento pós-publicação concluído.

## Decisão

**Publicação ainda não autorizada.** O ambiente está tecnicamente preparado sob premissas, mas os itens de governança permanecem pendentes. Nenhuma publicação foi executada.
