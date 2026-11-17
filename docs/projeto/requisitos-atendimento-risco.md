# Requisitos legais — nível de atendimento (%) e risco (análise de 23/10/2026)

Fonte: `docs/Detalhamento Cálculo do Nível do Atendimento do Requisito Legal.doc` (Ricardo).

## O que o documento pede
- **Manter** o "Atendido?" manual com Sim, Não e Parcialmente Atendido: muitos clientes já têm esse campo preenchido.
- **Novo:** a avaliação da conformidade passa a ter **duas dimensões independentes**, calculadas a partir dos itens dos 5 pilares ligados ao requisito.
  1. **% de atendimento:** itens aplicáveis atendidos ÷ itens aplicáveis. Exemplo: 3 licenças e 2 controles, com 2 licenças e 2 controles OK, dá 80%.
  2. **Risco:** cada item de gestão recebe **Severidade** (1 a 4, critério próprio de cada pilar) e **Probabilidade** (1 a 4, critério comum a todos). A **pontuação** é Severidade × Probabilidade:
     - 1 a 3: **Baixo**, manter os controles;
     - 4 a 6: **Moderado**, planejar melhorias;
     - 8 a 9: **Alto**, priorizar ações corretivas;
     - 12 a 16, **ou Severidade 4**: **Crítico**, medidas imediatas.
- Severidade e probabilidade são **obrigatórias** no cadastro do item; o sistema calcula o risco.
- **Não misturar as dimensões:** um requisito pode estar 100% atendido e ter risco Crítico, ou 20% atendido e ter risco Baixo.
- Destacar os requisitos não atendidos por causa de um item crítico e **pôr os responsáveis em evidência**.
- Mostrar o risco nos dashboards.
- **Rastreabilidade:** dizer por que o sistema chegou ao % e ao risco.

## Situação anterior (migração 284, situação calculada)
- Por requisito e unidade, o sistema já junta os itens ligados: licenças, medição, treinamentos, infraestrutura, controles e fornecedores (homologação).
- Cada item recebe uma situação: OK, ATENCAO, FALHA ou CRITICO (licença vencida sem protocolo).
- O detalhe por item fica em JSON (`situacao_legal_calculada.detalhes`).
- A situação do requisito é S, PA, N ou AI. Ela é comparada com a avaliação manual, e o responsável decide qual vale.
- Não existia risco, severidade, probabilidade nem "não se aplica" por item.

## Decisões do usuário (23/10/2026)
- **a) Duas medidas de risco.** O documento é contraditório: diz "pior risco entre os não atendidos" e também admite "100% atendido com risco Crítico". Ficam:
  - **Risco do requisito:** o pior risco entre **todos** os itens aplicáveis (risco inerente);
  - **Pendência crítica:** itens **não atendidos** com risco Crítico. É o número em vermelho, o que gera o destaque e põe os responsáveis em evidência.
- **b) O que é atendido:** OK e Atenção contam como atendido; Falha e Vencido não. A situação de item "CRITICO" (licença vencida sem protocolo) passa a se chamar **"Vencido"**, para não confundir com o risco Crítico.
- **c) "Não se aplica" por item:** é marcado no vínculo requisito × item, com justificativa. O item marcado sai do % e do risco do requisito.
- **d) Fornecedores (homologação):** recebem uma **tabela própria** de severidade.
- **e) Itens antigos** sem severidade e probabilidade ficam como **"Risco não avaliado"** e aparecem no dashboard como pendência.
- **f) IA sugerindo severidade e probabilidade:** fica para a **segunda etapa**, depois das regras manuais funcionando. A IA só sugere, com justificativa, e o responsável técnico confirma.
- **Ajuda:** tudo deve estar explicado em detalhe na **Ajuda da transação Requisitos legais**.

## Critérios de severidade (do documento)
- **Licenças e autorizações:**
  - 1 Baixa: irregularidade documental de efeito mínimo;
  - 2 Moderada: advertência ou exigência administrativa, sem interrupção;
  - 3 Alta: multa relevante ou restrição pontual;
  - 4 Crítica: interdição total, perda de autorização indispensável ou dano severo à imagem.
- **Medição e monitoramento:**
  - 1: falha pontual sem comprometimento;
  - 2: condições inadequadas, mas controláveis;
  - 3: condições graves e fora de controle;
  - 4: acidentes fatais, dano ambiental irreversível ou paralisação total.
- **Treinamento e capacitação:**
  - 1: atividade sem riscos relevantes;
  - 2: erros simples ou incidentes leves;
  - 3: erros significativos, acidentes com afastamento ou danos moderados;
  - 4: fatalidades, incapacidade permanente ou consequências irreversíveis.
- **Infraestrutura:**
  - 1: sem consequências relevantes;
  - 2: pequenos incidentes, sem interrupção;
  - 3: acidentes de gravidade intermediária ou paralisações localizadas;
  - 4: fatalidades, colapso ou paralisação integral e prolongada.
- **Controles operacionais e administrativos:**
  - 1: falha sem consequências, correção simples;
  - 2: pequenos desvios ou incidentes leves;
  - 3: desvios relevantes ou perdas significativas;
  - 4: acidentes graves ou fatais, desastre ambiental ou continuidade comprometida.
- **Fornecedores (homologação), tabela proposta — a validar com o Ricardo:**
  - 1: falha documental ou cadastral sem impacto, regularizável de forma simples;
  - 2: exigência administrativa, atraso ou pequeno prejuízo, sem interrupção;
  - 3: multa relevante ou corresponsabilidade (ex.: resíduos), acidente com afastamento ou interrupção localizada;
  - 4: acidente grave ou fatal, dano ambiental com corresponsabilidade, interdição ou dano severo à imagem.
- **Probabilidade (todos os pilares):**
  - 1 Improvável: controles adequados;
  - 2 Possível: exposição relevante, histórico de desvios ou limitações nos controles;
  - 3 Provável: falhas recorrentes ou controles insuficientes;
  - 4 Quase certa: exposição contínua, sem controles eficazes, ou o evento já ocorre.

## Implementação (etapa 1) — commit c553, migração 341
**Como aplicar:** `E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-341-requisitos-risco.php --simular`, depois sem `--simular`. Pode rodar de novo. Rodar a 284 de novo depois reaplica a 341 sozinha.

**Banco (database/341_requisitos_atendimento_risco.sql + classes/Migracao341RequisitosRisco.php):**
- `ml_risco_criterios` (critérios do documento; fornecedores: tabela proposta), `ml_risco_itens` (+ `_historico`): severidade, probabilidade, pontos e classe por item. Licença: o risco fica na licença original (vale para todas as versões, `vw_ml_licenca_chave`).
- `ml_requisito_item_na` (+ `_historico`): "não se aplica" por requisito × unidade × item, justificativa ≥ 10 caracteres.
- Procedures `sp_ml_risco_salvar` (grava e põe os requisitos ligados na fila de recálculo) e `sp_ml_requisito_na` (marca/desmarca e recalcula na hora).
- `sp_situacao_legal_calcular_alvo` novo: % de atendimento, risco do requisito (pior entre os aplicáveis; NAO_AVALIADO quando nenhum tem risco), pendências críticas, itens sem risco; o resumo começa com "Atendimento X% (a de b itens aplicáveis…). Risco: …". Status CRITICO aparece como "vencido".
- `situacao_legal_calculada` ganha itens_na, itens_aplicaveis, itens_atendidos, pct_atendimento, risco, risco_pontos, pendencias_criticas, itens_sem_risco.
- Views: vw_situacao_legal_itens / vw_situacao_legal_grid / vw_modulolegal_requisitos_situacao_grid (e a de licença, 306) com as colunas novas; vw_situacao_legal_painel_risco, vw_ml_risco_nao_avaliados, vw_ml_pendencias_criticas (com vw_ml_risco_responsaveis, das responsabilidades efetivas), vw_dash_legal_risco_unidade, vw_dash_legal_risco_matriz, vw_dash_legal_pendencias_criticas.

**Telas:**
- Cadastros dos pilares (opção `risk_area` do ManutencaoLegalCadastroGrid + assets/js/risco-item.js): colunas Severidade, Probabilidade (critérios no título da coluna) e Risco calculado na hora; obrigatórias ao incluir e ao alterar item sem risco; também editáveis nos registros do legado (só existem no greenportal). Páginas: Licenças/Minhas licenças, Plano de medição e monitoramento, Treinamentos/Meus, Infraestrutura/Minhas, Controle/Meus controles, Fornecedores (fornecedor externo não vê).
- Situação do requisito: linha das duas dimensões (% com a conta, risco, pendências críticas, itens sem risco) e a tabela de itens com Sev. × Prob., risco, destaque de pendência crítica e botões Não se aplica / Volta a se aplicar.
- Grid de Requisitos legais: colunas % atendimento, Risco (com cor), Pendências críticas, Itens sem risco avaliado (também no Excel).
- Dashboard da empresa (aba Atendimento legal): cartões, matriz risco × % de atendimento, risco por unidade, pendências críticas com responsáveis em evidência, itens com risco não avaliado. Meu dashboard: meus requisitos por risco, minhas pendências críticas.
- Relatório de Atendimento Legal: seção das duas dimensões com a matriz, seção de pendências críticas, % e risco por requisito e por item; no Excel, colunas novas e planilha "Pendências críticas".
- Ajuda (universal, entre as marcas `<!-- ml341 -->`): texto completo em Requisitos legais e na Situação do requisito; nota curta nos cadastros dos pilares. O "?" mostra tabelas como listas (o editor de ajuda e a API só aceitam p, listas e negrito).

**Testado localmente** (MariaDB, banco de teste): cálculo, NA, versões de licença, fila, obrigatoriedade no grid (servidor e tela), linha nova sem valor pré-selecionado, painel, dashboards, PDF e Excel.

**Pendente / a validar:** tabela de severidade de fornecedores com o Ricardo; etapa 2 (IA sugerindo severidade e probabilidade).

## Etapa 2 — IA sugere Severidade e Probabilidade (commit c554, migração 342)
Decisões do usuário (23/10/2026): aceita a sugestão quem pode alterar o cadastro do item (permissão de edição da transação do pilar), nada além disso; o lote **não** roda sozinho, só quando alguém pede.

**Como aplicar:** `E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-342-risco-sugestao-ia.php --simular`, depois sem `--simular` (depois da 341). Nada é enviado à IA pela migração.

**Banco (database/342_risco_sugestao_ia.sql):** ml_risco_lotes (pedido: um item ou lote) e ml_risco_sugestoes (uma por item: AGUARDANDO → PENDENTE → ACEITA/AJUSTADA/RECUSADA; SUBSTITUIDA, ERRO, SEM_RESPOSTA); ml_risco_itens e o histórico ganham ORIGEM (MANUAL, IA, IA_AJUSTADA) e CODSUGESTAO; gatilho volta a origem para MANUAL quando o risco é alterado no cadastro. Procedures sp_ml_risco_lote_criar, sp_ml_risco_sugestao_criar/_job/_resultado/_decidir, sp_ml_risco_lote_verificar (alerta "Sugestões de risco da IA prontas para revisar"). Views vw_ml_risco_sugestoes, vw_ml_risco_lotes, vw_ml_risco_itens_origem. Prompt ML_RISCO (Setup › Prompts de IA).

**Fluxo:** classes/RequisitoRiscoIa.php monta o pedido (critérios do pilar e de probabilidade, cadastro do item sem dados pessoais, situação, unidade, requisitos ligados com norma/assunto/artigos/texto), até 8 itens por pedido na fila de IA (green_ia_document_jobs, tipo ML_RISCO); o serviço "ia" do Agendador grava as respostas (DocumentAiQueueService::notificar → concluirPedido). Taxímetro: tipo ML_RISCO (Módulo Legal), limite conferido no início do pedido. Lote: itens de vw_ml_risco_nao_avaliados sem sugestão aguardando, a revisar ou recusada, até 300, os que ligam mais requisitos primeiro.

**Telas:** botão "Sugerir risco com IA" (estrelas) na barra dos cadastros dos pilares (linha selecionada); Módulo Legal › Sugestões de risco (IA) (principal/modulolegal_risco_sugestoes.php, menu MODULOLEGAL_RISCO_SUGESTOES com a permissão de Requisitos legais; pedir lote exige "incluir"): abas A revisar (trocar Sev./Prob. na linha = ajustar; aceitar/recusar marcadas), Aguardando a IA, Decididas e sem resposta, Pedidos; ?lote=N do alerta. api/risco_sugestoes.php (sugerir_item, sugerir_lote, decidir). Dashboard (Itens com risco não avaliado) abre a tela. Selo "IA" no risco do cadastro e da Situação do requisito. Ajuda (marca ml342) na tela nova, Requisitos legais, Situação e cadastros.

**Testado localmente:** pedido de item e lote, fila com IA simulada (resposta completa, parcial, HTTP 500), alerta, aceitar/ajustar/recusar, permissão (perfil sem edição e "Minhas…" só com o responsável), origem e gatilho, tela e botão no navegador.
