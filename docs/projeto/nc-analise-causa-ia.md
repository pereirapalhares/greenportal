# Não Conformidades — IA na análise da causa e na avaliação da eficácia (c538–c543, migrações 336, 337 e 338, 23/10/2026)

Pedidos do usuário:
- "inicie pela análise da causa com IA, lembrando que temos um cadastro de prompts e este tambem deve ser um prompt";
- depois, "A) Avaliação da eficácia com IA";
- depois, "B" (a IA da eficácia ler o conteúdo das evidências).

## Padrão comum aos dois (decisões do usuário 23/10)
- Cada um tem o **seu prompt** em ia_prompts (pt/en/es), editável em Setup › Prompts de IA:
  - **NC_ANALISE_CAUSA** (migração 336);
  - **NC_EFICACIA** (migração 337, regras das evidências na 338).
- O pedido roda em **segundo plano**:
  - vai para a fila green_ia_document_jobs (migração 284), processada pelo serviço "ia" do Agendador;
  - o popup do portal confirma o pedido; não entra alerta no momento do pedido.
- **Bloqueio:** enquanto houver pedido do mesmo tipo na fila (ou lendo evidências), ninguém pede outro para aquela NC. O bloqueio cai depois de 2 h (NaoConformidadeAnaliseIa::HORAS_BLOQUEIO).
- **Resposta nos Alertas:** ao terminar um job NC_ANALISE_CAUSA ou NC_EFICACIA, DocumentAiQueueService chama sp_nc_ia_analise_concluida.
  - A procedure cria o alerta (pronta ou erro).
  - O link abre a NC como aba do portal, na aba certa, com a revisão aberta (`&ia=CODANALISE`).
- **Revisão em abas.** A IA só sugere; nada é gravado sem a revisão.
- **Histórico da NC:** IA_ANALISE_APLICADA / IA_ANALISE_DESCARTADA e IA_EFICACIA_APLICADA / IA_EFICACIA_DESCARTADA.
- Só entram NCs que o usuário pode ver (podeVer).

## Análise da causa (aba "Análise da causa e plano de ação", tipos com causa)
- **Botão:** "Sugerir com IA", para o responsável ou ADMINISTRADOR / ADM_DA_EMPRESA.
- **Contexto:**
  - a ocorrência;
  - a análise, as causas e as ações já cadastradas;
  - os tipos de causa;
  - até 5 NCs semelhantes já tratadas (sp_nc_ia_semelhantes).
- **Abas da revisão:** Resumo, Causas, Ações, Texto da análise e NCs semelhantes.
- **Aplicar:** grava numa transação, pelas procedures da tela.

## Avaliação da eficácia (aba "Verificação da eficácia", c542 e c543)
- **Botão:** "Avaliar com IA", para o gestor ou o admin, na etapa Avaliação da Eficácia.
- **Contexto:**
  - a ocorrência, a análise e as causas;
  - as ações, com status, prazos, reprogramação, observação e os arquivos de evidência;
  - a verificação já escrita;
  - as **NCs parecidas abertas depois desta** (sp_nc_ia_recorrencias).
- **Conteúdo das evidências (c543, migração 338):**
  - Ao pedir, os arquivos de evidência (evidências da análise e arquivos de cada ação; PDF, imagem, Word; até 20 arquivos de 25 MB) vão para a **fila de OCR** (green_ocr_jobs, serviço "ocr" do Agendador).
  - A chave é `GED_EXTRACAO:<tipo>:<id>:<arquivo>:<sha256>`: arquivo já lido é reaproveitado.
  - O pedido fica **EXTRAINDO**, com contexto, prompt, idioma e a lista de leituras guardados em nc_ia_analises.
  - O serviço "ia" (NaoConformidadeEficaciaIa::prepararPendentes, chamado em scripts/windows-service.php antes da fila):
    - quando todas as leituras terminam, ou depois de 2 h, junta "CONTEÚDO DAS EVIDÊNCIAS" ao contexto (até 3.500 caracteres por arquivo e 40.000 no total; arquivo não lido aparece com o motivo);
    - em seguida, põe o pedido na fila de IA (sp_nc_ia_analise_iniciar).
  - Sem arquivos legíveis, o pedido vai direto para a fila de IA.
- **Abas da revisão:** Parecer (Eficaz, Não eficaz ou Inconclusivo, com critérios), Ações e evidências, Recorrências, Falta comprovar e Texto da verificação.
- **"Levar para a verificação":**
  - grava só o texto da verificação;
  - deixa o Resultado e o motivo da devolução preenchidos na tela (`&ia_ef=`);
  - **o gestor aplica o resultado**.

## Layout (c540, c541)
- A revisão da IA é dividida em abas.
- A aba "Análise da causa e plano de ação" tem sub-abas Análise | Causas (n) | Plano de ação (n): ⚠ quando há causa sem ação, volta à sub-aba depois de gravar (`&sub=`).

## Banco
- **Tabela nc_ia_analises** (só do greenportal):
  - colunas TIPO (CAUSA/EFICACIA), CONTEXTO, PROMPT, IDIOMA e EVIDENCIAS (338);
  - situações: EXTRAINDO → SOLICITADA → SUGERIDA → APLICADA ou DESCARTADA; também ERRO e SUBSTITUIDA.
- **database/336_nc_analise_ia.sql** (16 blocos): a tabela, as colunas, a view e as procedures comuns.
- **database/337_nc_eficacia_ia.sql:** sp_nc_ia_recorrencias.
- **database/338_nc_eficacia_evidencias.sql:** sp_nc_ia_analise_extrair e sp_nc_ia_analise_iniciar.
- **Runners 336, 337 e 338:** cada runner grava também os SQL anteriores.
  - O 338 atualiza o NC_EFICACIA só se ele nunca foi editado (versão 1, sem alterado_por).
  - Se foi editado, mantém o prompt e avisa qual regra acrescentar.
- InteligenciaArtificialDocumento: max_tokens 1400 → 2500.

## Arquivos
- classes: NaoConformidadeAnaliseIa.php (comum + causa), NaoConformidadeEficaciaIa.php (eficácia + OCR das evidências), DocumentAiQueueService.php e NaoConformidadeRelatorio.php;
- assets/js: nc-analise-ia.js e nc-eficacia-ia.js;
- principal/naoconformidades_ocorrencia.php;
- scripts/windows-service.php (serviço "ia" chama prepararPendentes).

## Situação
- 336 e 337 aplicadas nos 6 bancos e testadas pelo usuário na Crown.
- 338 (c543): rodar o runner.
- Os serviços "ocr" e "ia" do Agendador precisam estar rodando.
- Pendente: testar a leitura das evidências na Crown.
