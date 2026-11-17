# Levantamento — SQL direto em tabelas (GreenPortal)

Gerado em 14/10/2026 a partir dos arquivos atuais do servidor (telas, APIs, classes, componentes, login e serviços/workers em execução).
Regra: toda leitura por view ou `sp_crud_select` e toda gravação por procedure. A estrutura das migrações continua por DDL.

**56 arquivos · 285 comandos SQL direto em tabela.**

## Etapas

| Etapa | O que entra | Arquivos | Comandos |
|---|---|---|---|
| 1 ✅ | Base comum: auditoria (`logs_auditoria`) e alertas (`alertas`) — **concluída em 14/10** (migração 281) | 15 | ~25 |
| 2 ✅ | GED, licenças e IA — **concluída em 14/10** (migração 282) | 11 | 59 |
| 3 | Painéis e pendências (dashboards, home, painel, eventos) — só leitura, vira views | 9 | 51 |
| 4 | Agenda, tarefas, workflow, conteúdo e serviços/workers | 12 | 69 |
| 5 | Questionários (`QuestionnaireService`) | 1 | 30 |
| 6 | Não conformidades | 8 | 32 |
| 7 | Segurança, empresas, cadastros e outros | 13 | 43 |

Cada etapa: migração nova com as views/procedures → você roda → publico o código → teste visual.


## 1. Auditoria e alertas

| Arquivo | Comandos | Grava em | Lê de |
|---|---|---|---|
| `classes/AuditService.php` | 1 | logs_auditoria | — |

## 2. Segurança, empresas e cadastros

| Arquivo | Comandos | Grava em | Lê de |
|---|---|---|---|
| `classes/EmpresaMigracaoService.php` | 12 | empresa, empresa_migracao_config, menus, registros_migracao | empresa, empresas, menus |
| `classes/GuiaUsuarioService.php` | 2 | — | guia_usuario_versoes, menus |
| `classes/MenuService.php` | 1 | — | menus |
| `classes/ModuloLegalRequisitosGrid.php` | 1 | — | ml_potenciais_solicitacoes |
| `principal/controle_documentos_vinculos.php` | 5 | content_document_links, content_documents | content_documents |
| `principal/manutencaolegalrequisitoslegais_checklist.php` | 7 | legislacaoxpergunta | legislacaoxpergunta |
| `principal/medicaoemonitoramento_mm_monitoramento.php` | 1 | — | ev_eventos |
| `principal/seguranca_modulos_empresa.php` | 5 | empresa_modulos | empresa, empresa_modulos, modulos_sistema |
| `principal/seguranca_usuarios.php` | 1 | — | usuarios |
| `scripts/sincronizar-permissoes-atlas.php` | 3 | permissoes, registros_migracao | perfil_menus, permissoes |

## 3. GED, licenças e IA

| Arquivo | Comandos | Grava em | Lê de |
|---|---|---|---|
| `classes/GedAnaliseIaRegistro.php` | 11 | alertas, ged_analises_ia | ged_analises_ia, usuarios |
| `classes/GedStorageService.php` | 2 | content_document_links | li_licencas |
| `classes/LicenseVersionService.php` | 13 | content_document_links, legislacaoxlicencas, li_licencas, logs_auditoria | content_document_links, legislacaoxlicencas, li_licencas |
| `classes/OcrQueueService.php` | 7 | green_ocr_jobs | green_ocr_jobs |
| `principal/api/documentos_ocr_status.php` | 1 | — | green_ocr_jobs |
| `principal/api/fornecedorcriterios_ia.php` | 1 | logs_auditoria | — |
| `principal/api/funcionarios_ia.php` | 14 | fo_funcionarios_documentos_ia, fo_funcionarioxtiposxcriterios, fo_funcionarioxtiposxtreinamentos, fo_sinc_protecoes, logs_auditoria | fo_criteriofuncionarios, fo_fornecedores, fo_funcionarios, fo_funcionarios_documentos_ia, fo_funcionarioxtiposxcriterios, fo_funcionarioxtiposxtreinamentos, fo_tipofuncionarios, fo_tipotreinamentos |
| `principal/ia_potenciais_categoria.php` | 2 | — | ml_potenciais, ml_potenciais_solicitacoes |
| `principal/ia_potenciais_resultado.php` | 2 | — | ml_potenciais_solicitacoes, mm_empresa |
| `principal/ia_potenciais_resultado_pdf.php` | 2 | — | ml_potenciais_solicitacoes, mm_empresa |
| `principal/licenca_requisitos_documentos.php` | 3 | — | content_document_links, content_documents, content_versions, glegislacao_dbp.legislacao, legislacaoxlicencas, li_licencas |
| `services/InteligenciaArtificialService.php` | 1 | — | empresa |

## 4. Questionários

| Arquivo | Comandos | Grava em | Lê de |
|---|---|---|---|
| `classes/QuestionnaireService.php` | 30 | amb_questionario, amb_questionario_evidencias, amb_questionario_execucoes, amb_questionario_opcao, amb_questionario_pergunta, amb_questionario_publicacao, amb_questionario_respostas, amb_questionario_resultados, amb_questionario_versao, logs_auditoria | amb_questionario, amb_questionario_evidencias, amb_questionario_execucoes, amb_questionario_pergunta, amb_questionario_respostas, amb_questionario_resultados, amb_questionario_versao, workflow_instancias |

## 5. Painéis, agenda, tarefas e serviços

| Arquivo | Comandos | Grava em | Lê de |
|---|---|---|---|
| `classes/AgendaService.php` | 9 | agenda_eventos, agenda_execucoes, agenda_recorrencias | agenda_eventos, agenda_recorrencias |
| `classes/AiApprovedTaskService.php` | 3 | alertas, logs_auditoria | alertas, green_tasks, ml_potenciais, ml_potenciais_solicitacoes |
| `classes/ContentService.php` | 13 | content_documents, content_versions, logs_auditoria | content_documents, content_versions, workflow_definicoes, workflow_instancias |
| `classes/PendenciasService.php` | 3 | — | agenda_eventos, alertas, green_tasks |
| `classes/ResponsibilityAgendaService.php` | 2 | — | agenda_eventos, usuarios |
| `classes/TaskService.php` | 8 | green_task_history, green_tasks | green_tasks |
| `classes/WorkflowService.php` | 7 | workflow_historico, workflow_instancias | workflow_definicoes, workflow_instancias, workflow_transicoes |
| `principal/dashboard.php` | 11 | — | legislacaoxlicencas, legislacaoxsituacao, li_licencas, ml_potenciais_solicitacoes, unidades |
| `principal/dashboard_admin.php` | 13 | — | alertas, exportacoes_grid, legislacaoxdocumentos, legislacaoxempresa, legislacaoxlicencas, legislacaoxsituacao, li_licencas, ml_potenciais_solicitacoes, perfis, permissoes, unidades, usuarios |
| `principal/eventos.php` | 9 | ev_eventos | ev_eventos, green_tasks, ml_potenciais, mm_monitoramento, usuarios |
| `principal/home_agenda.php` | 1 | — | green_tasks |
| `principal/home_alertas.php` | 1 | — | ged_analises_ia |
| `principal/home_pendencias.php` | 3 | — | fo_criteriofornecedores, fo_fornecedores, fo_fornecedorxcriterios, fo_planodeacao |
| `principal/main_pendencias.php` | 5 | — | legislacaoxsituacao, nc_indicacao_ocorrencia, nc_planodeacao |
| `principal/painel_pendencias.php` | 4 | — | fo_fornecedores, legislacaoxsituacao, li_licencas, nc_indicacao_ocorrencia |
| `principal/responsabilidade_substituicao.php` | 3 | — | grupos, responsabilidade_atribuicoes, responsabilidade_modulos, responsabilidade_substituicoes, usuarios |
| `principal/servicos.php` | 6 | servicos_auditoria, servicos_status | servicos, servicos_exportacoes_cache, servicos_fila_status, servicos_task_status |
| `scripts/manutencao-retencao-exportacoes-alertas.php` | 4 | alertas, exportacoes_grid | alertas, exportacoes_grid |
| `scripts/service-monitor.php` | 10 | servicos_exportacoes_cache, servicos_fila_status, servicos_monitoramento_execucoes, servicos_task_status | empresa, exportacoes_grid, servicos, usuarios |
| `scripts/task-worker.php` | 2 | alertas, green_service_runs | alertas |
| `scripts/windows-service.php` | 3 | alertas | alertas, content_documents, workflow_instancias |

## 6. Não conformidades

| Arquivo | Comandos | Grava em | Lê de |
|---|---|---|---|
| `classes/NaoConformidadeWorkflow.php` | 3 | — | grupos, nc_indicacao_ocorrencia, perfis, usuarios |
| `principal/api/naoconformidades_novaocorrenciaempresa.php` | 10 | nc_indicacao_ocorrencia, nc_workflow_historico | grupos, nc_indicacao_ocorrencia, perfis, usuarios |
| `principal/naoconformidade_ocorrenciapdf.php` | 5 | — | nc_causas, nc_indicacao_ocorrencia, nc_planodeacao, nc_planodeacao_historico, nc_tipos_causas, usuarios, vlistadeocorrenciasnc |
| `principal/naoconformidades_analisedacausaraiz.php` | 7 | nc_causas, nc_indicacao_ocorrencia, nc_planodeacao | nc_causas, nc_planodeacao, usuarios |
| `principal/naoconformidades_aprovacao.php` | 1 | — | nc_indicacao_ocorrencia |
| `principal/naoconformidades_avaliacaodaeficacia.php` | 2 | nc_indicacao_ocorrencia | — |
| `principal/naoconformidades_comogestor.php` | 2 | nc_indicacao_ocorrencia | — |
| `principal/naoconformidades_novaocorrenciaempresa.php` | 2 | — | grupos, nc_indicacao_ocorrencia, perfis, usuarios |

## 7. Outros

| Arquivo | Comandos | Grava em | Lê de |
|---|---|---|---|
| `classes/ResponsibilityService.php` | 3 | logs_auditoria, responsabilidade_substituicoes | responsabilidade_delegacoes |
| `classes/WindowsServiceRuntime.php` | 1 | green_service_runs | — |
| `principal/diagnostico_laia_correlacionamentos_temporario.php` | 1 | — | laia |

## Fora deste levantamento

- `scripts/aplicar-migracao-*` (84): migrações. Já padronizadas as 264, 266, 268 e 280; as demais rodaram uma vez e ficam como histórico, salvo se forem reexecutadas.
- Scripts de diagnóstico, testes, inventário e cargas antigas da Atlas/Goodyear (~118): ferramentas pontuais de manutenção, não são usadas pelas telas.
- `principal/backup/`: cópias antigas, não são carregadas.