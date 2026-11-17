# Regras de negócio — Módulo Legal

Atualizado em 24/10/2026. Documento de referência: o que o sistema faz hoje e as decisões do usuário. Legenda: [FEITO] em produção, [PENDENTE] decidido mas ainda não aplicado/configurado, [A CONFIRMAR] dúvida em aberto.

Escopo: base de legislação, requisitos legais, situação, % de atendimento e risco, potenciais da IA, reavaliação por mudança de lei, licenças (versões, protocolo, taxas, condicionantes), controles, treinamentos, infraestrutura, medição e monitoramento, LAIA/LPRO/LAIS e correlacionamentos, homologação ("influência"), Relatório de Atendimento Legal e extração de dados pela IA.

Princípio geral (decisão do usuário, documento do Ricardo): o Módulo Legal é o pivô da gestão legal e se liga a Licenças, Medição e monitoramento, Treinamento, Infraestrutura, Controle e Homologação. Cada um desses cadastros continua com "vida própria" (acesso independente pelo menu).

---

## 1. Legislação (base central)

**RN-LEG-01** — A legislação fica numa base central única, `glegislacao_dbp`, compartilhada por todas as empresas. Tipos, âmbitos, assuntos, alterações, perguntas e categorias também ficam nela. [FEITO]
Onde: Manutenção Legal › Legislação (`manutencaolegal_legislacao.php`, `LegislacaoGridService`); origem: migrações 028, 031, 044; `migracao_legislacao_diretrizes.txt`.

**RN-LEG-02** — Aspectos, impactos, perigos e riscos ficam no banco de cada empresa (são cadastros replicáveis a partir da Modelo). [FEITO]
Onde: Manutenção Legal › Aspectos/Impactos/Perigos/Riscos (`ManutencaoLegalCadastroGrid`); origem: migração 043; `manutencao_legal_cadastros_diretrizes.txt`.

**RN-LEG-03** — Todos os perfis visualizam a Legislação; só o ADMINISTRADOR inclui, altera e exclui. Gravar e excluir pedem confirmação. [FEITO]
Onde: transação `LEGISLACAO`; origem: migração 030; `manutencao_legal_legislacao_diretrizes.txt`.

**RN-LEG-04** — O texto PADRAO é guardado integralmente (LONGTEXT, com quebras de linha). É editado só pela janela do lápis e só muda na célula depois de "Confirmar". [FEITO]
Onde: grid de Legislação; origem: migrações 028 e procedures universais (limite de 16 MB no SQL dinâmico); `migracao_legislacao_diretrizes.txt`.

**RN-LEG-05** — Texto legado que já contém "?" literal é tratado como conteúdo a revisar. O sistema nunca troca esse caractere sozinho. [FEITO]
Onde: migração da legislação; origem: `manutencao_legal_legislacao_diretrizes.txt`.

**RN-LEG-06** — Listas fixas: "Situação da lei" = Lei Alterada, Lei Inclusa ou Lei Revogada. "Devido da lei" = 7 valores fixos (Alterada/Alterado pela Atualização ou pelo Ajuste, Devido ao Ajuste, Devido à Atualização, Retirado pela Atualização). Meio ambiente, Segurança, Qualidade e Responsabilidade (social) = Sim/Não. [FEITO]
Onde: grid de Legislação; origem: `manutencao_legal_legislacao_diretrizes.txt`.

**RN-LEG-07** — Os assuntos da lei são uma relação (`legislacaoxassunto`). Confirmar a janela de assuntos sincroniza os vínculos sem alterar a lei. O filtro de assuntos acha a lei por qualquer parte do nome do assunto. [FEITO]
Onde: grid de Legislação; origem: migração 037.

**RN-LEG-08** — Filtros contextuais da Legislação: Empresa (só empresas ATIVA), Unidade (habilitada só depois da empresa e só com unidades ligadas à empresa), Mês/Ano de atualização (data da alteração em `legislacaoxalteracao`) e Padrão (texto). Todos os filtros rodam no banco; a contagem do rodapé usa os mesmos critérios. [FEITO]
Onde: `sp_legislacao_select` / `sp_legislacao_count`; origem: migrações 033, 037.

**RN-LEG-09** — O PDF da lei é aberto em janela dentro da aba (PDF.js) a partir de `GED\legislacao\<CODIGO>.pdf`. Se o código termina em letra (ex.: LB0010A), a letra final é ignorada (busca LB0010.pdf). O código só aceita letras, números, hífen e sublinhado. Sem arquivo: mensagem "documento não encontrado". [FEITO]
Onde: `legislacao_pdf.php`, ícone "Texto da Lei" em todo grid que tem CODIGO + CODLEGISLACAO; origem: `legislacao_pdf_diretrizes.txt`, `icone_legislacao_diretrizes.txt`.

**RN-LEG-10** — Legislação × Empresa é uma matriz lei × unidade. A célula aceita só MATRIZ ou uma letra de A a Z (outro valor é recusado no servidor). A empresa gravada é sempre a da sessão. Limpar a célula remove a relação. [FEITO]
Onde: Manutenção Legal › X Empresas (`manutencaolegal_legislacaoxempresa.php`, `legislacaoxempresa`); origem: migração 038; `manutencao_legal_x_empresas_diretrizes.txt`.

**RN-LEG-11** — Escolher uma letra (A–Z) numa célula vazia cria uma lei derivada: código da lei principal + letra (ex.: LB0001 → LB0001A), copiando dados e relacionamentos da principal, para aquela unidade. [FEITO]
Onde: X Empresas; origem: migração 148 (ajuda da matriz).

**RN-LEG-12** — Legislação × Poder é uma matriz lei × unidade com Controle, Conhecimento ou Influência. Trocar o valor remove a relação anterior e cria a nova; limpar remove. [FEITO]
Onde: Manutenção Legal › X Poder (`legislacaoxpoder`); origem: migração 040; `manutencao_legal_x_poder_diretrizes.txt`.

**RN-LEG-13** — Filtro "Correlacionados?" das matrizes: Sim = só leis com relação; Não = todas as leis (células vazias onde não há relação). Paginação de 25 leis. [FEITO]
Onde: X Empresas e X Poder; origem: `manutencao_legal_matrizes_diretrizes.txt`.

## 2. Requisitos legais

**RN-LEG-14** — Um requisito legal é o par lei × unidade da empresa (vindo de `legislacaoxempresa`). O grid é de consulta: não há Novo, Duplicar nem Excluir. [FEITO]
Onde: Módulo Legal › Requisitos legais (`modulolegal_requisitoslegais.php`, `ModuloLegalRequisitosGrid`, `vw_modulolegal_requisitos_grid`); origem: migração 046; `modulo_legal_requisitos_legais_diretrizes.txt`.

**RN-LEG-15** — O único campo editável no grid é o Nível de controle (PODER: Conhecimento, Influência ou Controle). O botão "Confirmar alteração" só habilita depois de uma mudança e grava em `legislacaoxpoder` por lei e unidade. [FEITO]
Onde: grid de Requisitos legais; origem: `modulo_legal_requisitos_legais_diretrizes.txt`.

**RN-LEG-16** — Filtro "Lei revogada" (Sim/Não), com Não por padrão. Vale para a consulta, a contagem, a paginação e a exportação. [FEITO]
Onde: grid de Requisitos legais; origem: `modulo_legal_requisitos_legais_diretrizes.txt`.

**RN-LEG-17** — Filtro "Responsável": mostra só os requisitos em que o usuário escolhido tem situação em `legislacaoxsituacao`. O filtro roda no banco; vários responsáveis não duplicam a linha. [FEITO]
Onde: grid de Requisitos legais (`RESPONSAVEL_IDS`); origem: migrações 047, 048.

**RN-LEG-18** — Filtro "Requisitos sem evidência" (Todos/Sim/Não): sem evidência = existe vínculo de licença, controle, treinamento, infraestrutura ou medição para a lei e unidade, mas nenhum documento ativo no GED para essa entidade. A tela antiga foi desativada. [FEITO]
Onde: `vw_modulolegal_requisitos_filtro_grid` (campo interno `SEM_EVIDENCIA`); origem: migração 232; `filtro-requisitos-sem-evidencia-20261013.md`.

**RN-LEG-19** — A barra do grid tem três grupos: Operações; Correlacionamentos (Documentos Licenciatórios, Medição e Monitoramento, Treinamentos, Infraestrutura, Controles, Homologação de Fornecedores); Avaliação (Situação, CheckList, Alterações, Questionário Legal, Análise de IA, Relatório). Botões de contexto só habilitam com uma linha selecionada. [FEITO]
Onde: `ModuloLegalRequisitosGrid`; origem: `requisitos_legais_barra_botoes_20261011.md`.

**RN-LEG-20** — Todo popup de correlacionamento recebe CODLEGISLACAO e CODUNIDADE da linha e mostra só os registros dessa lei e unidade. [FEITO]
Onde: `manutencaolegalrequisitoslegais_*.php`; origem: migração 065; `modulo_legal_requisitos_popups_diretrizes.txt`.

**RN-LEG-21** — Os popups seguem a permissão da transação `REQUISITOS_LEGAIS`: abrir exige visualizar; Novo exige incluir; Salvar exige editar; Excluir exige excluir. Sem nenhuma dessas, tudo fica somente leitura. O servidor recusa a ação sem permissão. [FEITO]
Onde: popups de Requisitos legais; origem: `modulo_legal_requisitos_popups_diretrizes.txt`.

**RN-LEG-22** — Vínculos com Treinamentos e Infraestrutura não aceitam duplicidade (chave única por lei, unidade e item). [FEITO]
Onde: `legislacaoxtreinamentos`, `legislacaoxinfraestruturas`; origem: migração 148; `requisitos_legais_treinamentos_infraestrutura_diretrizes.md`.

**RN-LEG-23** — O popup Alterações mostra só as alterações da lei selecionada (base central), nunca a lista geral. [FEITO]
Onde: `manutencaolegalrequisitoslegais_alteracoes.php`; origem: migração 118.

**RN-LEG-24** — O botão "Requisitos legais" dentro do LAIA é uma transação própria (`modulolegal_laiarequisitoslegais`), separada da transação principal. [FEITO]
Onde: `modulolegal_laiarequisitoslegais.php`; origem: `modulo_legal_correcao_20260819.txt`.

**RN-LEG-25** — Questionário Legal: as 9.797 perguntas legadas foram saneadas para o questionário universal `LEGAL_REQUIREMENTS` (9.621 migradas, 13 duplicadas, 2 curtas, 161 sem vínculo). O questionário está em RASCUNHO e não deve ser publicado antes da revisão dos itens marcados e da definição dos tipos de resposta. [PENDENTE]
Onde: botão Questionário Legal; origem: migração 203; `p2-questionnaire-legal-saneamento-20261011.md`.

### Requisitos inativados

**RN-LEG-26** — O requisito pode ser retirado da base ativa, por unidade, com efeito imediato. Motivo obrigatório (10 caracteres ou mais). Podem inativar: ADMINISTRADOR, ADM DA EMPRESA ou perfil com permissão de excluir. [FEITO]
Onde: botão "Inativar requisito nesta unidade" (`sp_requisito_inativar`); origem: migração 286.

**RN-LEG-27** — Inativar move a linha de `legislacaoxempresa` para `legislacaoxempresa_inativos` (com quem, quando e por quê). O requisito some do grid, dos dashboards, do cálculo da situação e do relatório. Avaliações, evidências, responsáveis e vínculos ficam guardados. [FEITO]
Onde: `vw_requisitos_inativados_grid`; origem: migração 286.

**RN-LEG-28** — Reativar devolve a linha original. Se a lei já voltou para a unidade por outro caminho (matriz), só encerra a inativação. Uma nova inativação da mesma lei e unidade substitui a anterior. Tudo vai para `requisitos_inativacao_historico`. [FEITO]
Onde: tela Requisitos inativados (`sp_requisito_reativar`); origem: migração 286.

## 3. Situação de cumprimento (avaliação manual e histórico)

**RN-LEG-29** — A avaliação manual fica em `legislacaoxsituacao`: Situação, Atendido? (S = Sim, N = Não, AI = Atendimento indefinido, PA = Parcialmente atendido), Responsável, Data de avaliação, Data de vencimento e Observação. A lista de responsáveis é da empresa atual, em ordem alfabética, sem o usuário admin. [FEITO]
Onde: popup Situação (`modulolegal_situacao.php` / `manutencaolegalrequisitoslegais_situacao.php`); origem: migrações 046, 049, 062.

**RN-LEG-30** — Antes de alterar (com mudança real) ou excluir uma situação, o sistema grava o histórico: descrição anterior, usuário, data e o registro anterior completo em JSON. Inclusão não gera histórico. [FEITO]
Onde: `situacaoxhistorico`; origem: migrações 136, 140; `historico_situacao_requisitos_legais_20260825.md`.

**RN-LEG-31** — O histórico é só consulta (sem Novo, Salvar, Duplicar ou Excluir) e continua visível mesmo depois da exclusão da situação. [FEITO]
Onde: `modulolegal_requisitoslegais_situacaohistorico.php`; origem: `historico_situacao_requisitos_legais_20260825.md`.

**RN-LEG-32** — Responsabilidades do requisito são por situação (CODSITUACAO): usuário ou grupo, finalidade, prioridade e vigência. Exclusão é lógica; registros históricos não são editados. [FEITO]
Onde: botões Responsabilidades / Manter responsabilidades no grid de Situações; origem: migração 103; `interface_consulta_responsabilidades_requisitos_20260820.md`.

## 4. Situação calculada, % de atendimento e risco

**RN-LEG-33** — O sistema calcula sozinho a situação de cada requisito (lei × unidade) a partir dos itens de gestão vinculados (licenças, medição e monitoramento, treinamentos, infraestrutura, controles e fornecedores). A avaliação manual (Atendido?) continua existindo ao lado. [FEITO]
Onde: `vw_situacao_legal_grid`, `sp_situacao_legal_calcular_alvo`; origem: migração 284 (substituída pela 341).

**RN-LEG-34** — Situação de cada item: [FEITO]
- Licença: vencida sem protocolo = Vencido (código CRITICO); vencida e protocolada fora do prazo = Falha; vencida e protocolada no prazo, sem data de validade, com data de reação ou prazo de protocolo vencido, sem responsável, ou com taxa vencida e não paga = Atenção; válida = Ok. Com versões, vale a versão ATIVA.
- Entrega periódica (RAPP, inventário, mapas...): prazo passado sem entrega = Falha (nunca Vencido); entregue = Ok/Atenção.
- Medição: mês previsto (anterior ao atual) sem medição = Falha; sem medição e sem cronograma = Falha; mês atual ainda não medido, data de reação passada ou sem responsável = Atenção.
- Treinamento e infraestrutura: sem responsável = Falha.
- Controle: sem arquivo ou sem responsável = Atenção.
- Fornecedor: não encontrado ou desativado = Falha.
Onde: views `vw_sl_ev_*` montadas pelo script; origem: `scripts/aplicar-migracao-284-situacao-legal.php`, migrações 341 e 344.

**RN-LEG-35** — Situação do requisito (sem os itens "não se aplica"): nenhum item aplicável = AI (Sem evidências vinculadas); algum item Vencido = N; nenhuma Falha = S; nenhum item Ok/Atenção = N; nos demais casos = PA. [FEITO]
Onde: `sp_situacao_legal_calcular_alvo`; origem: migrações 284 e 341.

**RN-LEG-36** — % de atendimento = itens aplicáveis atendidos (Ok ou Atenção) ÷ itens aplicáveis do requisito na unidade. Exemplo: 3 licenças e 2 controles, com 4 Ok = 80%. [FEITO]
Decisão do usuário (23/10/2026): o nível de atendimento passa a ter duas dimensões automáticas, % de atendimento e risco, e as situações Sim/Não/Parcialmente atendido continuam (muitos clientes já as preencheram).
Onde: grid (coluna % atendimento), Situação, painéis, relatório; origem: migração 341; "Detalhamento Cálculo do Nível do Atendimento do Requisito Legal.doc".

**RN-LEG-37** — Risco de cada item = Severidade (1–4, critério próprio de cada pilar) × Probabilidade (1–4, critério comum). Pontuação 1–3 = Baixo; 4–6 = Moderado; 8–9 = Alto; 12–16 ou Severidade 4 (qualquer probabilidade) = Crítico. Tratamentos: manter rotina; planejar melhorias; priorizar ações corretivas; medidas imediatas. [FEITO]
Onde: `RequisitoRisco`, `ml_risco_itens` (tabela só do GreenPortal, o sincronismo do legado não altera); origem: migração 341; documento .doc.

**RN-LEG-38** — Severidade e Probabilidade são obrigatórias ao incluir um item e ao alterar um item que ainda não tem risco. Itens antigos ficam "Risco não avaliado" e nenhum valor é suposto. Cada alteração de risco fica no histórico com usuário e data. [FEITO]
Onde: cadastros dos pilares (Licenças, Plano de medição e monitoramento, Treinamentos, Infraestrutura, Controle de documentos, Fornecedores e telas "Minhas…"); origem: migração 341.

**RN-LEG-39** — O risco, o "não se aplica" e as taxas da licença ficam na licença original e valem para todas as versões. [FEITO]
Onde: `vw_ml_licenca_chave`; origem: migrações 341, 344.

**RN-LEG-40** — Risco do requisito = pior risco entre os itens aplicáveis da unidade (Crítico > Alto > Moderado > Baixo). Sem nenhum item avaliado = "Não avaliado". % e risco não se misturam: um requisito pode estar 100% atendido com risco Crítico. [FEITO]
Decisão do usuário (24/10/2026): "Manter: todos os itens" (e não só os não atendidos).
Onde: `sp_situacao_legal_calcular_alvo`; origem: migração 341.

**RN-LEG-41** — Pendência crítica = item aplicável não atendido (Falha ou Vencido) com risco Crítico. Esses requisitos ficam em destaque e os painéis listam o item e seus responsáveis. [FEITO]
Onde: `vw_dash_legal_pendencias_criticas`; origem: migração 341.

**RN-LEG-42** — "Não se aplica" é marcado por vínculo (requisito × unidade × item), com justificativa de 10 caracteres ou mais. O item sai do %, do risco e das pendências, mas continua listado (riscado, com quem marcou e quando). Pode voltar a se aplicar. Tudo fica no histórico. [FEITO]
Onde: popup Situação › Itens de gestão considerados (`ml_requisito_item_na`); origem: migração 341.

**RN-LEG-43** — Recálculo: ao salvar um item, risco ou "não se aplica", gatilhos põem os requisitos na fila; o serviço processa a fila a cada 5 minutos; a tela Situação recalcula na hora; uma vez por dia roda o cálculo completo (vencimentos). [FEITO]
Onde: `situacao_legal_fila`, `sp_situacao_legal_manutencao`; origem: migrações 284, 341.

**RN-LEG-44** — Quando a avaliação manual diverge da calculada, o responsável decide: ACEITAR (vale a calculada), MANTER (vale a manual, justificativa obrigatória) ou LIMPAR. A decisão perde efeito sozinha se a calculada ou a manual mudarem. [FEITO]
Onde: `sp_situacao_legal_decidir`; origem: migração 284.

**RN-LEG-45** — Rastreabilidade: a coluna "Motivo da situação calculada" resume a conta (ex.: "Atendimento 80% (4 de 5 itens aplicáveis). Risco: Crítico; 1 pendência crítica.") e a Situação mostra item a item pilar, situação, motivo, severidade × probabilidade, risco e "não se aplica". [FEITO]
Onde: grid e popup Situação; origem: migração 341.

**RN-LEG-46** — Os itens já cadastrados ainda estão sem severidade e probabilidade (na aplicação da 341, todos os itens aplicáveis ficaram "Não avaliado"). Os responsáveis precisam informar o risco nos cadastros ou usar a sugestão da IA. [PENDENTE]
Onde: dashboard (pendência "itens com risco não avaliado"); origem: saída da aplicação da migração 341.

### Sugestão de risco pela IA

**RN-LEG-47** — A IA só sugere Severidade e Probabilidade, com justificativa e confiança (0–100). Nada é gravado sem decisão: Aceitar (grava a sugestão), Ajustar (grava os valores da pessoa, origem "IA ajustada") ou Recusar (não grava; motivo opcional). [FEITO]
Decisão do usuário (23/10/2026): a IA só sugere; quem grava é o responsável; o lote só roda quando alguém pede; cada chamada passa pelo taxímetro.
Onde: Módulo Legal › Sugestões de risco (IA) (`modulolegal_risco_sugestoes.php`, `RequisitoRiscoIa`); origem: migração 342.

**RN-LEG-48** — Pode decidir quem tem permissão de editar a transação do pilar do item. Nas telas "Minhas…", só nos itens de que a pessoa é responsável. [FEITO]
Onde: `RequisitoRiscoIa::podeDecidir`; origem: migração 342.

**RN-LEG-49** — Pedido de um item pelo botão "Sugerir risco com IA" do cadastro, ou em lote (itens ligados a requisitos e sem risco, sem sugestão aguardando, a revisar ou recusada), até 300 itens por pedido, os que ligam mais requisitos primeiro. Os itens vão em grupos de até 8. Uma nova sugestão substitui a ainda não decidida do mesmo item. [FEITO]
Onde: `ml_risco_lotes`, `ml_risco_sugestoes`, fila `green_ia_document_jobs` (tipo ML_RISCO); origem: migração 342.

**RN-LEG-50** — A IA recebe os critérios do pilar, os dados do cadastro (sem dados pessoais, arquivos ou campos de controle), a situação, a unidade e os requisitos ligados. Com o limite mensal de IA bloqueado, o pedido é recusado. Ao terminar o lote, sai o alerta "Sugestões de risco prontas". [FEITO]
Onde: prompt `ML_RISCO` (Setup › Prompts de IA); origem: migração 342.

**RN-LEG-51** — O histórico guarda a origem do risco (MANUAL, IA, IA_AJUSTADA), a justificativa, quem decidiu e quando. Se alguém alterar o risco no cadastro depois, ele volta a ser manual. [FEITO]
Onde: `ml_risco_itens_historico`; origem: migração 342.

## 5. Potenciais e tarefas identificados pela IA

**RN-LEG-52** — A partir de um requisito selecionado, a IA gera potenciais em cinco categorias: Licenças, Medição e monitoramento, Treinamentos, Infraestrutura e Documentos/controles (CONTROLE = tabela `documentos`). Cada pedido roda os cinco prompts. A solicitação é confirmada por popup. [FEITO]
Decisão do usuário (doc. Ricardo): os potenciais devem ser validados antes de virar item de gestão.
Onde: botão "Análise de IA" em Requisitos legais; prompts `ML_REQUISITO_*`; origem: migrações 138, 140, 175; `ia_potenciais_categorias_diretrizes.md`.

**RN-LEG-53** — O processamento é em segundo plano, por empresa ATIVA (empresas INATIVAS não são processadas). Falha de comunicação não cria registro parcial; sem chave configurada a solicitação termina em ERRO. Alertas no início e no fim, com link para o resultado. [FEITO]
Onde: `services/InteligenciaArtificialService.php`, `sp_ia_potencial_solicitar_com_alerta`, `sp_ia_potencial_concluir`; origem: `servico_ia_potenciais_diretrizes.md`.

**RN-LEG-54** — Idioma da solicitação: pt, en ou es (inválido = pt). O conteúdo gerado não é traduzido quando o usuário troca o idioma. [FEITO]
Onde: `ml_potenciais.idioma_conteudo`; origem: `servico_ia_potenciais_diretrizes.md`.

**RN-LEG-55** — Aprovar ou rejeitar: ADMINISTRADOR e ADM_DA_EMPRESA; GESTOR só visualiza. Só um potencial em SUGERIDO pode ser validado; o servidor confere de novo sessão, permissão, usuário, empresa, solicitação e categoria. [FEITO]
Onde: `ia_potenciais_resultado.php` e abas `ia_potenciais_*.php`, `sp_ia_potencial_validar`; origem: migrações 139, 179.

**RN-LEG-56** — Potencial APROVADO vira item de gestão (monitoramento, treinamento e infraestrutura sempre como item novo; licença e documento de controle conforme RN-LEG-106) e já fica vinculado ao requisito e à unidade de origem (licença + `legislacaoxlicencas`, monitoramento, treinamento, infraestrutura ou documento com código `IA-<n>`). Sem lei ou unidade identificadas, a aprovação é recusada. [FEITO]
Onde: `sp_ia_potencial_validar`, `sp_ia_potencial_retroconverter_destinos`; origem: migração 179.

**RN-LEG-57** — Cada potencial aprovado gera uma tarefa "Tratar potencial IA" (uma só por potencial). Prazo = data limite do contexto ou 7 dias. Prioridade 8 se a confiança for 80 ou mais, senão 5. Gera alerta e auditoria. [FEITO]
Onde: `AiApprovedTaskService`, `green_tasks` (tipo IA_APROVACAO); origem: migração 192.

**RN-LEG-106** — Na aprovação de um potencial de **licença** ou de **documento de controle**, o usuário escolhe o destino (sugestão do Ricardo, doc. `Ricardo_sugestao1.txt`): licença — vincular o requisito a **uma ou várias licenças já cadastradas** (versão ativa; lista da unidade do requisito ou de todas, filtro por tipo documental, já pré-escolhido pelo título) ou criar uma nova já com o tipo documental; controle — vincular a **um documento já cadastrado** ou criar um novo (`IA-<n>`), podendo aprovar junto outros potenciais sugeridos da mesma solicitação apontando para o mesmo documento (um procedimento atende vários controles, sem duplicar arquivo). Vínculo requisito × registro que já existia não é duplicado. Cada destino fica em `ml_potenciais_destinos` (novo/existente, quem, quando); no controle, o vínculo também vai para `content_document_links` com origem IA_CONFIRMADA. Tudo na mesma transação (falhou um registro, nada é gravado) e auditado. [FEITO]
Decisão do usuário (11/11/2026): implementar a Fase 4 da análise da sugestão do Ricardo.
Onde: abas Licenças e Documentos do Resultado da análise de IA (`ia_potenciais_categoria.php`, `ia_potenciais_destino*.inc.php`), `sp_ia_potencial_aprovar_destino`, `vw_ia_destino_licencas`, `vw_ia_destino_documentos`, `vw_ml_potenciais_destinos`; origem: migração 355; teste: `scripts/teste-355-potenciais.php`.

**RN-LEG-58** — Responsável da tarefa e de itens novos sem responsável vem do perfil de atribuições: quem executa o pilar na unidade; com mais de um, quem tem menos tarefas abertas. Sem ninguém no perfil, a tarefa fica com quem pediu a análise e o ADM DA EMPRESA recebe alerta. Item novo sem responsável espera 1 hora antes da atribuição automática. Quem acompanha é avisado na criação; quem aprova, na conclusão. [FEITO]
Decisão do usuário (23/10/2026): regras acima.
Onde: `ResponsavelAutomatico`; origem: migração 345.

## 6. Reavaliação quando uma lei muda

**RN-LEG-59** — Cada alteração ou revogação nova registrada na base de legislação gera uma reavaliação na empresa: lista os requisitos da própria lei (por unidade) e até 8 itens parecidos pelo sentido (semelhança ≥ 45%) entre requisitos de outras leis, licenças, controles, treinamentos e infraestrutura. [FEITO]
Decisão do usuário (23/10/2026): reavaliar agora, com itens parecidos e tarefa "revisar" para os responsáveis.
Onde: `LeiReavaliacao` (rodado pelo serviço da Pesquisa de documentos), tela Módulo Legal › Reavaliação por mudança de lei; origem: migração 346.

**RN-LEG-60** — Lei que é requisito: cada responsável pela situação do requisito recebe a tarefa "Revisar" (prazo 15 dias, prioridade 7); sem responsável, vai ao ADM DA EMPRESA. Responsáveis dos itens parecidos recebem só alerta. [FEITO]
Onde: `green_tasks` (tipo LEI_REAVALIACAO); origem: migração 346.

**RN-LEG-61** — Lei fora dos requisitos: se algum item tem semelhança ≥ 55%, a tarefa (prioridade 4) vai ao ADM DA EMPRESA para avaliar se ela passa a ser requisito; senão fica registrada como "Sem impacto". [FEITO]
Onde: `LeiReavaliacao`; origem: migração 346.

**RN-LEG-62** — Só alterações posteriores à ativação geram reavaliação (marca inicial gravada na migração: alterações a partir do nº 30547). Cada alteração é processada numa transação: se falhar, é tentada de novo na próxima execução. [FEITO]
Onde: `lei_reav_controle`; origem: saída da aplicação da migração 346.

**RN-LEG-63** — Sem chave de IA ou com o limite mensal atingido, a reavaliação lista só os requisitos da própria lei (sem a comparação pelo sentido). O uso entra no taxímetro (tipo LEI_REAVALIACAO). [FEITO]
Onde: `LeiReavaliacao`, `IaTaximetro`; origem: migração 346.

## 7. Licenças

**RN-LEG-64** — Licenças ficam no banco da empresa (`li_licencas`, chave CODLICENCA automática). A unidade é mostrada pela descrição; o responsável é escolhido entre os usuários ativos da empresa, sem o admin. Dias calculados e campos de descrição são somente leitura. [FEITO]
Onde: Licenças (`manutencaolegal_licencas.php`, `vw_licencas_grid`); origem: migrações 050, 051, 070, 142.

**RN-LEG-65** — Visualizar Licenças mostra só as licenças do usuário (CODUSUARIO). "Visualizar Total" mostra todas. ADM_DA_EMPRESA tem as duas. O escopo é aplicado no servidor no grid, na contagem e na exportação, e filtros do navegador não o removem. [FEITO]
Onde: `permissoes.pode_visualizar_total`; origem: migração 148; `permissao_escopo_visualizacao_licencas.md`.

**RN-LEG-66** — Minhas Licenças é sempre restrita ao usuário logado (mesmo com Visualizar Total) e não permite incluir, duplicar ou excluir; edita conforme a permissão. [FEITO]
Onde: `minhas_licencas.php`; origem: migração 149; `validacao_laia_lpro_lais_controles_direcionados_20260923.md`.

**RN-LEG-67** — Situação da licença: [FEITO]
- Válida: vencimento hoje ou depois.
- Válida (em protocolo): vencida, mas com renovação protocolada no prazo; vale até a resposta do órgão e mostra número e data do protocolo.
- Em protocolo (prazo não informado): ver RN-LEG-105.
- Vencida: vencida sem protocolo ou protocolada fora do prazo.
- Sem data de vencimento.
- Entrega periódica: RAPP, inventário, relatório/declaração anual, mapas, DMR (não é licença; tem prazo de entrega).
"No prazo" = Protocolado em ≤ Data do protocolo; sem uma das datas, vale o campo "Protocolado no prazo?".
Onde: `LicencaSituacao`, `vw_licencas_situacao`, colunas `SITUACAO_LICENCA` e `NUMERO_PROTOCOLO`; origem: migrações 285, 288.

**RN-LEG-68** — A natureza "entrega periódica" vem do tipo documental da licença; sem tipo, do nome. [FEITO]
Onde: Manutenção legal › Tipos documentais de licenças (`li_tipos_documentos.NATUREZA`); origem: migrações 226, 284.

**RN-LEG-69** — Versões: um novo arquivo cria uma nova versão da mesma licença (número x.y, em PROCESSANDO), ligada à licença original. Ao confirmar os dados extraídos, a versão vira ATIVA, as anteriores ficam substituídas e os vínculos passam para a nova. O histórico não é apagado; tudo é auditado. [FEITO]
Onde: `LicenseVersionService`, `sp_licenca_ativar_versao`; origem: migração 208.

**RN-LEG-70** — Arquivos da licença ficam no GED da empresa, em `licencas/<código da licença original>/`. [FEITO]
Onde: `GedStorageService`; origem: migração 208.

**RN-LEG-71** — Responsáveis da licença: atribuições de usuário ou grupo (o grupo TODOS = todos os usuários ativos), com finalidade, prioridade, principal e vigência (ATIVA, PROGRAMADA, EXPIRADA, HISTÓRICA). Só tipos ativos do módulo LICENCAS. Exclusão lógica. [FEITO]
Onde: botões Responsabilidades/Manter (`licenca_responsabilidades*.php`); origem: migração 102; `interface_consulta_responsabilidades_licencas_20260820.md`.

**RN-LEG-72** — Condicionantes: cada licença tem condicionantes (descrição, frequência, atendido, responsável, observação), com contagem de planos de ação. Cada condicionante tem planos de ação (ação, responsável, datas inicial e final, recursos, status). [FEITO]
Onde: botões Condicionantes e Plano de ação da licença (`vw_licencas_condicionantes_grid`, `vw_licencas_condicionantes_planodeacao_grid`); origem: migrações 130, 133; `fechamento_crud_condicionantes_20260922.md`.

**RN-LEG-73** — Lista de distribuição: a tabela de origem (`nc_listadistribuicao`) não existe no banco novo; o campo fica editável com valores da própria view. [A CONFIRMAR]
Onde: grid de Licenças; origem: `manutencao_legal_licencas_liLicencasCrown_diretrizes.txt`.

### Taxas das licenças

**RN-LEG-74** — Uma licença pode ter várias taxas: tipo (Emissão, Renovação, Análise, Fiscalização anual, Publicação, Outra), descrição, número da guia, valor (formato 1.234,56), vencimento (obrigatório), frequência (Única, Mensal, Trimestral, Semestral, Anual, a cada 2/3/4/5 anos), data e valor do pagamento, comprovante. Tipo ou frequência fora da lista viram Outra/Única. Valor pago vazio assume o valor da taxa. [FEITO]
Decisão do usuário (23/10/2026): várias taxas por licença, com esses campos.
Onde: Taxas das licenças (`manutencaolegal_licencas_taxas.php`, `li_taxas`, `LicencaTaxa`) e botão Taxas do cadastro; origem: migração 344.

**RN-LEG-75** — Situação da taxa: Paga (tem data de pagamento); Sem vencimento; Vencida (não paga, vencimento passado); Vence em até 30 dias; A pagar. [FEITO]
Onde: `vw_li_taxas_grid`; origem: migração 344.

**RN-LEG-76** — Ao registrar o pagamento de uma taxa recorrente, o sistema cria a próxima: vencimento + frequência (sem vencimento, a partir do pagamento), mesmo tipo, descrição e valor (a conferir), origem Automática. Cada taxa gera a próxima uma única vez: se a próxima for excluída, não volta. Desfazer o pagamento apaga o valor pago. [FEITO]
Decisão do usuário (23/10/2026): recorrência automática.
Onde: procedures de `li_taxas`; origem: migração 344.

**RN-LEG-77** — Os responsáveis (ativos) da licença recebem alerta 30 dias antes e no dia do vencimento (e por e-mail, conforme as preferências). Taxas vencidas ou que vencem em 30 dias aparecem em Minhas pendências e na Agenda. [FEITO]
Decisão do usuário (23/10/2026): agenda, pendências e alerta 30 dias antes e no dia. Se o serviço ficou parado, o alerta do dia ainda sai até 3 dias depois do vencimento.
Onde: `vw_li_taxas_avisos`, `sp_li_taxas_avisar`, `vw_minhas_taxas`; origem: migração 344.

**RN-LEG-78** — Taxa vencida e não paga deixa a licença em Atenção na situação legal calculada; a licença continua válida. A taxa fica na licença original e continua após a renovação. [FEITO]
Decisão do usuário (23/10/2026).
Onde: situação legal calculada; origem: migrações 284, 344.

**RN-LEG-79** — Quem vê e mantém taxas segue as permissões de Licenças / Minhas licenças. [FEITO]
Onde: menu `LICENCAS_TAXAS`; origem: saída da aplicação da migração 344.

### Extração de dados de licença pela IA

**RN-LEG-80** — Em Arquivos da licença, "Analisar com IA" sugere descrição, validade, atividade, órgão emissor, número, observações, confiança e alertas. Só grava depois de "Confirmar e gravar"; o JSON confirmado fica em `DADOS_DOCUMENTO_IA`. [FEITO]
Onde: `gestao_arquivos.php`, `api/licencas_ia.php`, `InteligenciaArtificialLicenca`; origem: migração 173; `ia_extracao_licencas_implementacao_20261003.md`.

**RN-LEG-81** — Para confirmar é preciso visualizar e editar Licenças; a licença tem de ser do tenant e o arquivo tem de estar na pasta GED da licença. A IA nunca define unidade, empresa, usuário, status ou permissões. [FEITO]
Onde: `api/licencas_ia.php`; origem: `ia_extracao_licencas_implementacao_20261003.md`.

**RN-LEG-82** — Nessa tela, o navegador lê o texto do PDF e envia só o texto: PDF digitalizado (sem texto) não é lido. Na criação de nova versão, a extração local aceita PDF, imagem e Word. [A CONFIRMAR]
Onde: `gestao_arquivos.php`, `LicenseDocumentExtractionService`; origem: `ia_extracao_licencas_implementacao_20261003.md`.

## 8. Controles de documentos

**RN-LEG-83** — Controle é o cadastro de documentos (`documentos`): Código e Título obrigatórios, Arquivos. Associações com unidades, atividades, palavras-chave, aspectos, impactos, perigos, riscos, aspectos sociais e impactos sociais, editadas em janela própria, sem duplicidade. [FEITO]
Onde: Módulo Legal › Controle (`modulolegal_controle.php`); origem: migrações 121–125; `controle_documentos_*.md`.

**RN-LEG-84** — Permissões do Controle: ADMINISTRADOR tudo; ADM_DA_EMPRESA visualiza, inclui, edita e exclui (sem exportar); GESTOR só consulta; demais perfis sem acesso. [FEITO]
Onde: transação `CONTROLE_DOCUMENTOS`; origem: migração 126.

**RN-LEG-85** — Responsáveis do Controle: só usuários e grupos ativos; exclusão histórica (ATIVO = 0); ADM_DA_EMPRESA inclui e exclui; GESTOR consulta. Meus Controles mostra só os documentos atribuídos ao usuário (direto ou por grupo ativo) e não inclui nem exclui. [FEITO]
Onde: `responsabilidade_atribuicoes`, `meus_controles.php`; origem: migrações 127, 155.

## 9. Treinamentos e infraestrutura

**RN-LEG-86** — Treinamentos e Infraestrutura são cadastros independentes do Módulo Legal, com palavras-chave (sem repetir) e, na infraestrutura, atributos Descrição/Valor. [FEITO]
Onde: `modulolegal_treinamentos.php`, `modulolegal_InfraEstrutura.php`; origem: migrações 129, 130; `modulo_legal_treinamentos_infraestrutura_diretrizes.md`.

**RN-LEG-87** — Permissões: ADMINISTRADOR tudo; ADM_DA_EMPRESA visualiza, inclui, edita e exclui (sem exportar); GESTOR só visualiza; demais sem acesso. [FEITO]
Onde: transações `MODULOLEGAL_TREINAMENTOS` e `MODULOLEGAL_INFRAESTRUTURA`; origem: migração 133.

**RN-LEG-88** — Meus Treinamentos e Minhas Infraestruturas mostram só os itens com atribuição ativa ao usuário (direta ou por grupo). Incluir e excluir ficam só no cadastro geral, para não criar item sem responsável. [FEITO]
Onde: `meus_treinamentos.php`, `minhas_infraestruturas.php`; origem: migrações 151, 152, 153, 154.

**RN-LEG-89** — Extração assistida de PDF também para Controles (Código, Título), Infraestrutura (descrição) e Treinamentos (descrição), sempre com revisão e confirmação do usuário. [FEITO]
Onde: Gestão de arquivos; prompts `CONTROLE_DOCUMENTO`, `INFRAESTRUTURA_DOCUMENTO`, `TREINAMENTO_DOCUMENTO`; origem: migração 174.

## 10. Medição e monitoramento

**RN-LEG-90** — Medição e monitoramento é um pilar ligado ao requisito (`mm_monitoramentoxlegislacao`), com plano, equipamentos, medições, calibração e cronograma. Entra no cálculo pela regra do cronograma (RN-LEG-34). [FEITO]
Onde: menu Medição e monitoramento (`MM_PLANO_MEDICAO_MONITORAMENTO`), botão no grid de Requisitos legais; origem: migrações 156–163, 180, 181, 284.

## 11. LAIA, LPRO, LAIS e correlacionamentos

**RN-LEG-91** — LAIA, LPRO e LAIS preservam a ordem, os rótulos e os valores do legado. Valores fixos: Situação Normal/Anormal/Acidental; Tempo Atual/Passado/Futuro; Influência Direto/Indireto; Classificação Adverso/Benéfico; Escala, Severidade e Duração 1 a 3; Filtros C e D vazio ou X. [FEITO]
Onde: `modulolegal_laia.php`, `modulolegal_lpro.php`, `modulolegal_lais.php`; origem: migrações 055–061, 080, 081; `modulo_legal_lpro_lais_legado_diretrizes.md`.

**RN-LEG-92** — Resultado e Filtro B (LAIA/LAIS) e Total e Tolerância (LPRO) são calculados pela view e não são editáveis. [FEITO]
Onde: `vw_laia_*`, `vw_lpro_grid`, `vw_lais_grid`; origem: `modulo_legal_laia_legado_diretrizes.txt`, `modulo_legal_lpro_lais_legado_diretrizes.md`.

**RN-LEG-93** — Os popups recebem a chave contextual do legado: LAIA = aspecto, impacto, atividade e unidade; LPRO = perigo, risco, atividade e unidade (perigo/risco em OR); LAIS = aspecto social e impacto social (em OR) mais unidade obrigatória. [FEITO]
Onde: popups de LAIA (4), LPRO (6) e LAIS (3); origem: migrações 080, 081; `modulo_legal_lpro_correlacionamentos_diretrizes.md`, `modulo_legal_lais_popups_diretrizes.md`.

**RN-LEG-94** — Correlacionamentos Ambiental (aspectos, atividades, impactos, licenças, documentos), Ocupacional (perigos, atividades, riscos, licenças, documentos) e Social (aspectos sociais, impactos sociais, documentos): um item de menu cada; os relacionamentos abrem por botões, filtrados por lei E unidade ao mesmo tempo. O Ambiental só mostra leis com Meio ambiente = Sim. O Social não filtra por Responsabilidade (igual ao legado). [FEITO]
Onde: `modulolegal_correlacionamento*.php`; origem: migrações 064, 076, 077, 078.

**RN-LEG-95** — Nos popups de correlacionamento, a nova linha abre um combo da entidade (sem repetir); a chave da relação é entidade + lei + unidade, ocultas e protegidas. CRUD liberado para o ADMINISTRADOR nos três menus. [FEITO]
Onde: `ManutencaoLegalCadastroGrid`; origem: migração 079; `modulo_legal_correlacionamentos_crud_contextual_diretrizes.txt`.

## 12. Requisito × homologação ("influência")

**RN-LEG-96** — O botão "Homologação de Fornecedores" só habilita com uma linha selecionada e Nível de controle = Influência. Para Conhecimento, Controle ou vazio, fica desabilitado. [FEITO]
Decisão do usuário (doc. Ricardo): ligar o Módulo Legal à homologação pelas leis de "influência" (ações que os fornecedores devem tomar).
Onde: grid de Requisitos legais; origem: migração 141; `relacionamento_requisito_homologacao_influencia_diretrizes.md`.

**RN-LEG-97** — A tela mostra só a Razão Social. Incluir oferece só fornecedores ainda não ligados ao requisito e unidade; linhas salvas não mudam de fornecedor, só podem ser excluídas. Sem duplicar, exportar ou editar o fornecedor. [FEITO]
Onde: `manutencaolegalrequisitoslegais_homologacao.php`, `legislacaoxfornecedores`; origem: migração 141.

**RN-LEG-98** — Fornecedores ligados entram na situação calculada como itens do pilar Homologação (desativado ou inexistente = Falha), com critérios de severidade próprios. [FEITO]
Onde: `vw_sl_ev_homologacao`, `RequisitoRisco::AREAS['HOMOLOGACAO']`; origem: migrações 284, 341.

## 13. Relatório de Atendimento Legal

**RN-LEG-99** — O relatório sai em PDF e/ou Excel, com os filtros e a busca do grid de Requisitos legais. Exige permissão de gerar PDF ou exportar Excel (gera os formatos permitidos) e a situação calculada instalada. É gerado em segundo plano e chega nos alertas. [FEITO]
Onde: botão Relatório em Requisitos legais, `RelatorioAtendimentoLegal`, fila de exportações (transação `modulolegal_relatorio_atendimento`); origem: migrações 284, 341.

**RN-LEG-100** — Conteúdo: resumo por situação, unidade e área legal; licenças válidas × em protocolo × vencidas; % de atendimento, requisitos por risco, matriz risco × % e pendências críticas com responsáveis; não atendidos; divergências manual × calculada; requisitos com evidências (itens com status e motivo); lista dos requisitos sem evidências. Status de licença vencida aparece como "Vencido". [FEITO]
Onde: `RelatorioAtendimentoLegal`; origem: migrações 284, 285, 288, 341.

## 14. Dashboard do Módulo Legal

**RN-LEG-101** — O dashboard mostra oito indicadores (requisitos, licenças, licenças em atenção, situações pendentes, controles, treinamentos, infraestrutura, IA pendente), atenção imediata, pendências por unidade, % de cobertura legal e análises de IA abertas pelo usuário. Os números consideram só registros em que o usuário está envolvido. Sem permissão, o bloco fica vazio sem travar o painel. [FEITO]
Onde: `principal/dashboard.php`; origem: `dashboard_modulo_legal_20261004.md`.

**RN-LEG-102** — Aba Atendimento legal: % de atendimento, risco por unidade, matriz risco × % (em vermelho: risco Crítico sem atendimento total), pendências críticas com os responsáveis e itens com risco não avaliado; botão de sugestão de risco em lote. [FEITO]
Onde: `vw_dash_legal_risco_unidade`, `vw_dash_legal_risco_matriz`; origem: migrações 341, 342.

## 15. Regras transversais do módulo

**RN-LEG-103** — Toda tela do Módulo Legal usa só bancos `_dbp`; o PHP não executa SQL de negócio direto (views e procedures universais); chaves técnicas ficam ocultas e não editáveis. [FEITO]
Onde: todas as transações; origem: `modulo_legal_requisitos_legais_diretrizes.txt`, `diretrizes-projeto.txt` (5.1).

**RN-LEG-104** — Toda chamada de IA do módulo (risco, reavaliação, busca pelo sentido) passa pelo taxímetro e respeita o limite mensal da empresa. [FEITO]
Onde: `IaTaximetro`; origem: migrações 340, 342, 346.

**RN-LEG-105** — Licença vencida com "Em protocolo?" = Sim, mas sem as datas para saber se foi no prazo, fica como **"Em protocolo (prazo não informado)"** (código `PROT_SEM_PRAZO`). [FEITO em 27/10/2026: 288, 285, 290 e 284 aplicadas; ficaram nessa situação 15 licenças no Atlas, 3 na Luxottica, 2 na GD Americana e 1 na Crown]
- **Quando se aplica:** falta "Data do protocolo" ou "Protocolado em", e "Protocolado no prazo?" está vazio. Com "Protocolado no prazo?" = Não, ou com as datas mostrando atraso, continua Vencida.
- **Como aparece:**
  - não conta como vencida nem como válida;
  - fica em amarelo (Atenção) no grid de licenças e na situação legal calculada, que conta Atenção como atendido;
  - entra nos alertas de "precisa de ação" dos dashboards e do painel lateral;
  - o motivo diz que faltam as datas do protocolo;
  - o card só aparece quando há licença nessa situação.
- **Situação no Atlas (27/10/2026):**
  - 79 licenças vencidas: 64 sem "Em protocolo" e 15 marcadas em protocolo;
  - nenhuma licença do banco tem Data do protocolo, Protocolado em, "Protocolado no prazo?" ou número do protocolo preenchidos;
  - nenhuma das vencidas tem versão mais nova válida.
- **Para as 15 marcadas em protocolo virarem "Válida (em protocolo)":** informar as datas no legado, que é onde o cadastro prevalece até a virada; o sincronismo traz.
Onde: `LicencaSituacao` (`vw_licencas_situacao`, migração 288), `vw_dash_licencas` (faixa `PROT_SEM_PRAZO`, migração 285), painel lateral (290), `areaLicencas` da situação legal (284), dashboards, Relatório de Atendimento Legal, `licencas-vencimento.js`; origem: decisão do usuário (27/10/2026), diagnóstico `scripts/diagnostico-licencas-protocolo.php`.

---

## Pontos a confirmar

1. ~~Risco do requisito: todos os itens ou só os não atendidos?~~ Decidido em 24/10/2026: manter o pior risco entre todos os itens aplicáveis, atendidos ou não (RN-LEG-40). A tabela de severidade dos fornecedores ainda deve ser validada com o Ricardo.
2. **Risco obrigatório no cadastro.** O documento pede risco compulsório. O sistema exige só em item novo ou item sem risco; os itens antigos ficam "Não avaliado" (RN-LEG-38/46). Hoje todos os itens estão sem risco: falta definir quem preenche e até quando.
3. **Critérios de severidade de fornecedores** (pilar Homologação): não estão no documento do Ricardo; o código os marca como "a validar".
4. **Migrações 285, 286 e 288** (situação da licença em protocolo, requisitos inativados): estão no código, mas a saída da aplicação por banco não foi vista nesta revisão.
5. **Filtro "Correlacionados?" da matriz X Poder:** um documento diz que usa `legislacaoxempresa`, outro diz que usa a própria `legislacaoxpoder`.
6. **Lista de distribuição das licenças:** migrar `nc_listadistribuicao` ou manter o campo provisório (RN-LEG-73).
7. **PDF digitalizado na extração por "Analisar com IA"** (RN-LEG-82): confirmar se essa tela já usa o OCR da nova versão.
8. **Visualizar Total** só vale para Licenças; falta definir o campo de escopo para Treinamentos, Infraestrutura e os demais módulos.
9. **Responsável × Categoria** (a IA atribui categorias aos requisitos) e a **separação das tabelas de gestão entre Controle e Influência** foram pedidas no documento do Ricardo; não encontrei a implementação completa.
10. **Questionário Legal:** quem revisa os itens marcados (161 sem vínculo, 13 duplicadas, 2 curtas) e quando publicar (RN-LEG-25).
11. **Aprovações formais de go-live** (matriz de permissões, relatório de homologação, retenção): registradas como pendentes em 24/09 e 30/09/2026; confirmar se já foram dadas.
12. **Matriz de acesso LAIA/LPRO/LAIS e licenças direcionadas:** em 23/09/2026 só o ADMINISTRADOR acessava LAIA, LPRO, LAIS e Minhas Licenças. Confirmar se é isso que o negócio quer.
- Atlas, licenças em protocolo: conferido em 27/10/2026 — faltam as datas do protocolo em todo o banco; tratado pela RN-LEG-105.

## Fontes

- Anotações do projeto: % de atendimento e risco (23/10/2026), taxas das licenças (23/10/2026), busca semântica e reavaliação por lei (23/10/2026), responsáveis automáticos (23/10/2026) e próximos passos da apresentação Atlas (24/10/2026); conferidas na revisão de 24/10/2026. Também os cabeçalhos das migrações 341–346, as ajudas e as classes.
- Documento do usuário: `docs/Detalhamento Cálculo do Nível do Atendimento do Requisito Legal.doc` (texto extraído); `docs/ricardo_requisitos.txt` e `docs/portal_legislacao_requisitos.md`.
- Docs (E:\wamp\apps\greenportal\docs): `modulo_legal_*` (13 arquivos), `manutencao_legal_*` (7), `plano_adaptacao_portal_legislacao_20260819.md`, `analise_conformidade_portal_legislacao_requisitos_20260819.md`, `historico_consolidado_modulo_legal_20260924.md`, `historico_situacao_requisitos_legais_20260825.md`, `relacionamento_requisito_homologacao_influencia_diretrizes.md`, `ia_potenciais_categorias_diretrizes.md`, `servico_ia_potenciais_diretrizes.md`, `requisitos_legais_barra_botoes_20261011.md`, `requisitos_legais_treinamentos_infraestrutura_diretrizes.md`, `permissao_escopo_visualizacao_licencas.md`, `ia_extracao_licencas_implementacao_20261003.md`, `ia_extracao_documentos_controles_infraestrutura_treinamentos_20261003.md`, `filtro-requisitos-sem-evidencia-20261013.md`, `legislacao_pdf_diretrizes.txt`, `migracao_legislacao_diretrizes.txt`, `migracao_licencas_modelo_diretrizes.txt`, `controle_documentos_*.md` (5), `dashboard_modulo_legal_20261004.md`, `minhas_licencas_implementacao.md`, `meus_treinamentos_implementacao.md`, `minhas_infraestruturas_implementacao.md`, `ordem_colunas_requisitos_legais.txt`, `icone_legislacao_diretrizes.txt`, `fechamento_crud_condicionantes_20260922.md`, `resultado_crud_condicionantes_20260922.md`, `p2-questionnaire-legal-saneamento-20261011.md`, `correcao_popup_alteracoes_requisitos_legais_20260822.md`, `interface_consulta_responsabilidades_licencas_20260820.md`, `interface_consulta_responsabilidades_requisitos_20260820.md`, `responsabilidades_meus_controles.md`, `responsabilidades_treinamentos_ml.md`, `responsabilidades_infraestrutura_ml.md`, `validacao_laia_lpro_lais_controles_direcionados_20260923.md`, `fechamento_prioridades_requisitos_legais_20260924.md`, `revalidacao_pendencias_requisitos_legais_20260930.md`, `pendencias_modulo_legal_analise_20260920.txt`, `diretrizes-projeto.txt` (seções de Legislação, matrizes e camada de dados).
- Migrações (database): 028, 030, 031, 033, 037, 038, 040, 043, 044, 046–049, 050, 051, 062, 065, 070, 076–081, 102, 103, 118, 121–127, 129, 130, 133, 136, 138–141, 148, 149, 151–155, 173–175, 179, 192, 203, 208, 226, 232, 284, 286, 341, 342, 344, 345, 346 e o script da 288; saídas da aplicação das migrações 341, 342, 344, 345 e 346 (sete bancos).
- Classes: `LicencaSituacao`, `LicencaTaxa`, `RequisitoRisco`, `RequisitoRiscoIa`, `LeiReavaliacao`, `RelatorioAtendimentoLegal`, `LicenseVersionService`, `LicenseDocumentExtractionService`, `AiApprovedTaskService`, `ModuloLegalRequisitosGrid`; scripts `aplicar-migracao-284-situacao-legal.php`, `aplicar-migracao-286-requisitos-inativados.php`, `aplicar-migracao-288-licencas-protocolo.php`; ajudas 341, 342, 344, 346.
