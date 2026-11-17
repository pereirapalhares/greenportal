# Fechamento dos gates de go-live — 22/09/2026

## Gates técnicos executados

- Isolamento Modelo/Modelo2 aprovado; `Modelo2` retornou para `INATIVA`.
- Matriz de permissões repetida no banco principal e comportamento por perfil confirmado.
- Retenção executada em `dry-run` nos dois bancos:
  - `gmodelo_dbp`: 12 exportações elegíveis, 0 alertas elegíveis, 0 exclusões.
  - `gmodelo2_dbp`: 12 exportações elegíveis, 0 alertas elegíveis, 0 exclusões.
- Exportações e alertas históricos foram preservados; nenhum item pendente, processando ou não lido foi removido.
- Relatório atualizado com [isolamento Modelo/Modelo2](validacao_isolamento_modelo_modelo2_20260922.md).

## Responsáveis e prazos propostos

| Entrega | Responsável | Prazo proposto | Critério |
|---|---|---|---|
| Aprovar política/agendamento de retenção | Infraestrutura/DBA | D+1 | `dry-run` aprovado e janela agendada |
| Aprovar matriz de permissões | Segurança/Responsável do projeto | D+1 | Matriz assinada ou registrada |
| Aprovar relatório final | Responsável do projeto | D+2 | Aprovação formal registrada |
| Revalidar checklist | QA/Comitê de publicação | D+2 | Todos os gates marcados |
| Publicação | Responsável de release | D+3 | Janela aprovada e autorização explícita |

Os prazos são proposta operacional e precisam ser confirmados pelo responsável do projeto.

## Janela de publicação proposta

Janela sugerida: D+3, fora do horário de pico, com backup confirmado, plano de reversão, monitoramento de login, permissões, exportações e alertas.

## Decisão

**Status técnico: APTO SOB PREMISSAS.**

A aprovação formal do relatório, o agendamento da retenção e a autorização explícita da janela ainda são necessários. O go-live não deve ser executado automaticamente por este agente.
