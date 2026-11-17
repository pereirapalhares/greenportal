# Próximos passos — Apresentação Atlas Schindler 2026 (Rev 6)

Este documento compara o PDF da apresentação com o que existe no portal (E:\wamp\apps\greenportal), em 16/10/2026. Atualizado em 24/10/2026: alertas por e-mail, taxas, responsáveis automáticos, busca semântica, validação documental pelos modelos, integração de funcionários e análise de adulteração.

Legenda: FEITO · PARCIAL · FALTA · A CONFIRMAR

## Feito em 15/10 e 16/10 (depois do primeiro levantamento)
- Dashboards só com views:
  - Dashboard da empresa em abas (ADM);
  - Dashboard pessoal em abas (todos os usuários).
- Os links dos dashboards abrem a transação em popup de 90%, já filtrada no caso.
- Popups (popups.js v16):
  - sempre 90% da janela que chamou (recursivo);
  - sempre na frente, inclusive as mensagens;
  - nada abre em nova aba.
- Painel do gestor (dashboard_gestor.php): visão da equipe, por pessoa e prazos.
- Requisitos inativados por unidade (migração 286 aplicada).
- Pesquisa de documentos liberada para todas as empresas migradas (migração 264 --todas aplicada; testada como Crown).
- Visualizador universal de arquivos (migração 287 + serviço agendado GreenPortal-VisualizadorWorker). Testado com .doc real da Crown.
- Licenças em protocolo (migração 288 aplicada):
  - situação Válida / Válida (em protocolo) / Vencida no grid, nos dashboards e no relatório;
  - número do protocolo.
- Ranking de fornecedores (migração 289 + serviço GreenPortal-RankingFornecedores): pesos, faixas A–D, bloqueio com motivo.
- Coluna Evidências em Critérios e em Documentos do fornecedor; botão Arquivos na associação nova de licença.
- Acentuação estragada em funções e views corrigida em todos os bancos (scripts/corrigir-acentuacao-rotinas.php).
- Guia do usuário atualizado (rascunho revisão 4, aba nova "Uso do portal"). Falta publicar pela tela.
- Permissão "Potenciais IA" (MODULOLEGAL_POTENCIAIS) liberada para ADM_DA_EMPRESA no Atlas.
- Segurança › Permissões agora mostra as transações "(fora do menu)".

## Feito em 23/10/2026
- Requisitos legais: % de atendimento e risco (migração 341) e sugestão de risco pela IA (migração 342). Ver claude/requisitos-atendimento-risco.md.
- Alertas por e-mail com link "Abrir no portal" (migração 343, c555; Luxottica c556). Ver claude/alertas-email.md.
  - Falta só o SMTP no .env. O usuário configura depois; até lá nada é enviado.
- Gestão de taxas das licenças (migração 344, c557, aplicada nas 7 empresas). Ver claude/licencas-taxas.md.
- Responsáveis automáticos pelo perfil de atribuições (migração 345, c558). Ver claude/responsaveis-automaticos.md.
- Busca semântica na Pesquisa de documentos e reavaliação por mudança de lei (migração 346, c560, aplicada nas 7 empresas). Ver claude/busca-semantica.md.

## Feito em 24/10/2026
- Validação documental pelos modelos cadastrados (migração 347, c561). Ver claude/documento-modelos.md.
  - Modelo por critério de fornecedor e de funcionário, com itens, regras e arquivo de exemplo; a IA sugere os itens.
  - Validação automática ao chegar o arquivo, com parecer item a item na Gestão de Arquivos; a IA só sugere.
  - Falta aplicar a migração.
- Integração de funcionários (migração 348, c562). Ver claude/integracao-funcionarios.md.
  - O funcionário responde por um link próprio, sem senha; a nota mínima sugere o resultado e uma pessoa confirma.
  - Aprovada: grava a validade da integração (libera na portaria) e gera o certificado em PDF.
  - Falta aplicar a migração, publicar o questionário e configurar o GREENPORTAL_URL.
- Regras de negócio de todos os módulos documentadas em docs (c563). Ver claude/regras-negocio.md.
- Análise de adulteração dos documentos (migração 349, c564; Onda 3). Ver claude/documento-adulteracao.md.
  - Arquivo novo analisado sozinho: metadados, assinatura digital, imagem e conteúdo (regras fixas + IA); risco baixo, médio ou alto.
  - Risco alto sem ciência com motivo impede aprovar o documento; as regras automáticas levam para "Pendente com a empresa".
  - Falta aplicar a migração.

## Gestão de tarefas (6 etapas) — "Da tarefa identificada à ação concluída"
| Etapa | Situação | Observação |
|---|---|---|
| 1 Perfil do usuário | FEITO | Migração 268: questionário de atribuições por módulo/pilar (executa/aprova/acompanha), unidades, aprovação do gestor, revisão anual |
| 2 IA identifica tarefas | FEITO | Botão IA em Requisitos legais → potenciais por pilar → aprovar/rejeitar → tarefa com prazo de 7 dias e alerta |
| 3 Responsabilidades automáticas | FEITO (345) | A tarefa da IA e o item novo sem responsável vão para quem executa o pilar na unidade (o menos carregado). Acompanha recebe alerta na criação; aprova recebe alerta na conclusão (Agenda › Concluir). Sem ninguém no perfil, a tarefa fica com quem pediu e o ADM é avisado |
| 4 Agendas | FEITO | Responsabilidades dos 5 pilares e monitoramentos recorrentes na agenda |
| 5 Pendências | FEITO | Painel de pendências (tarefas, agenda, responsabilidades, alertas); tarefas para o que vence em até 30 dias |
| 6 Alertas e-mail/SMS/WhatsApp | PARCIAL | E-mail pronto (migração 343); falta só configurar o SMTP no .env. SMS/WhatsApp na Onda 3 |
| Painel de bordo do gestor | FEITO | |

## Módulo Legal
| Item | Situação |
|---|---|
| Ciclo tarefa → evidência → situação consolidada → relatório | FEITO |
| Integração com Homologação | FEITO |
| Busca semântica | FEITO (migração 346): cadastros + texto OCR, chave "Também pelo sentido"; reavaliação quando a lei muda |
| Requisitos inativados | FEITO |
| Licenças em protocolo (espelho, vencidas x válidas) | FEITO (migração 288) |
| Gestão de taxas (pedido da Atlas) | FEITO (migração 344) |

## Homologação: IA aplicada aos documentos
| Item | Situação |
|---|---|
| 1 Extração de dados | FEITO (migrações 221/261/262: Analisar com IA nos documentos de fornecedor e de funcionário) |
| 2 Validação documental pelos modelos | FEITO (migração 347) |
| 3 Análise de adulteração | FEITO (migração 349, falta aplicar) |

## Onda 1 — situação
1. Painel do gestor — FEITO.
2. Alertas por e-mail com link direto — FEITO (migração 343). O envio começa quando o SMTP for configurado.
3. Gestão de taxas das licenças — FEITO (migração 344).
4. Licenças em protocolo — FEITO.
5. Requisitos inativados — FEITO (testado inativar/reativar EA0047 Crown).

Ponto de atenção de dados: o Atlas tem 13 licenças vencidas com protocolo considerado fora do prazo e nenhuma "em protocolo". Conferir se faltam as datas (Data do protocolo / Protocolado em) ou o campo "Protocolado no prazo?".

## Ondas 2 e 3
- Onda 2 — todos os itens feitos (faltam aplicar 347 e 348):
  - ranking de fornecedores — FEITO;
  - busca semântica — FEITO (346);
  - validação documental pelos modelos — FEITO (347);
  - integração de funcionários (cadastro → questionário → avaliação → decisão) — FEITO (348).
- Onda 3: análise de adulteração — FEITO (349, falta aplicar); faltam SMS/WhatsApp, APIs SAP/Power BI, SCIM, ESG, GEE, GRI/IFRS.
