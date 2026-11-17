# Validação final da transação Requisitos Legais — 24/09/2026

**Rota:** `principal/modulolegal_requisitoslegais.php`  
**Empresa testada:** Modelo (`gmodelo_dbp`)  
**Tenant Modelo2:** não alterado; permanece INATIVA.

## Resultado da rodada

| Item | Resultado | Evidência |
|---|---|---|
| CRUD contextual de Licenças | Aprovado | `tests/resultado-crud-requisitos-legais-3-popups-20260924.json` |
| CRUD contextual de Controles | Aprovado | mesma evidência |
| CRUD contextual de Medição/Monitoramento | Aprovado | mesma evidência |
| Checklist por checkbox | Aprovado: marcar associou, salvar/reabrir confirmou; desmarcar removeu, salvar/reabrir confirmou | `tests/resultado-checklist-checkbox-requisitos-legais-20260924.json` |
| Edição e restauração de `PODER` | Aprovado; valor original `Controle` restaurado | `tests/resultado-poder-requisitos-legais-20260924.json` |
| Solicitação de Potenciais IA no portal | Aprovado: HTTP 200, alerta, link e tela de acompanhamento | `tests/evidencias-fluxo-ia-requisitos-20260924/resultado.json` |
| Processamento externo da IA | Aprovado: health ativo, solicitação processada até `CONCLUIDA`, tela de resultados e alerta final validados | `tests/evidencias-servico-ia-end-to-end-20260924/resultado.json` |
| Exportação XLSX | Aprovado manualmente após execução do worker: job 65, XLSX válido, 634.292 bytes, 6 entradas ZIP | job temporário removido após a validação |
| PDF | Parcial: endpoint HTTP 200, 572.087 bytes e assinatura `%PDF-`; modal visual não foi criado no clique automatizado | `tests/resultado-pdf-requisitos-legais-20260924.json` |
| Permissões dos sete usuários | Aprovado: admin, ueditor e gestor HTTP 200; urevisor, uaprovador, responsavel e visitante HTTP 403 | `tests/resultado-permissoes-requisitos-legais-7-usuarios-20260924.json` |

## Integridade e limpeza

- Inclusões, alterações, duplicações e exclusões dos três popups alteráveis foram persistidas e depois limpas.
- O valor de `PODER` foi restaurado para `Controle`.
- A solicitação IA temporária nº 28 e seus alertas foram removidos.
- O job de exportação temporário nº 65, seus alertas e o arquivo XLSX foram removidos.
- Não foram alterados registros permanentes de negócio fora dos testes controlados.

## Pendências antes do encerramento técnico

1. Corrigir ou validar manualmente a abertura visual do modal PDF e o conteúdo efetivamente renderizado/download.
2. Registrar aceite funcional do responsável pelo Módulo Legal.
3. Manter as aprovações formais de permissões, relatório, infraestrutura/retenção e janela de publicação como gates de go-live.

Esta documentação não representa aprovação formal nem autorização de publicação.
