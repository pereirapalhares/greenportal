# Integração de funcionários (migração 348, c562)

Este item é da Onda 2 da apresentação Atlas: "Integração de funcionários: avaliação e liberação". As quatro etapas são cadastro, questionário, avaliação e decisão.

## Decisões do usuário (24/10/2026)
- **Quem responde:** o próprio funcionário, por um link só dele, sem senha.
- **Qual questionário:** o do tipo de funcionário; sem um próprio, vale o padrão da empresa.
- **Decisão:** a nota mínima sugere Aprovado ou Reprovado, e uma pessoa confirma.
- **Aprovação:** grava a validade da integração, libera na Portaria e gera o certificado em PDF.

## Como funciona
- **Tela:** Homologação de Fornecedores › Integração de funcionários (principal/homologacaofornecedores_integracao.php).
  - Menu HOMOLOGACAO_INTEGRACAO_FUNCIONARIOS, ao lado da Gestão de Funcionários.
  - As permissões são copiadas da Gestão de Funcionários. Editar dá direito a gerar, cancelar, decidir e configurar.
  - O interno restrito vê só os fornecedores das suas unidades (316). O fornecedor externo não acessa.
- **Lista (grid local):**
  - colunas: fornecedor, funcionário, tipo, unidade, questionário, situação, "Integração válida até" (sinal de prazo) e Portaria (Liberado / Não liberado);
  - barra: Gerar link, Confirmar decisão, Certificado, Cancelar e Configurar (engrenagem).
- **Configuração (?config=1):**
  - para cada tipo de funcionário: questionário, nota mínima, validade em meses e prazo do link em dias;
  - uma linha sem tipo é o padrão da empresa;
  - nota mínima vazia usa a do questionário (percentual_minimo); sem ela, 70%.
- **Link:**
  - endereço: integracao.php?e=<código da empresa>&t=<código do link>;
  - o código tem 24 bytes aleatórios; no banco fica só o SHA-256;
  - vale até o prazo e para uma resposta; gerar outro link cancela o anterior.
  - O endereço vem de GREENPORTAL_URL no .env; sem ele, usa o endereço por onde o usuário entrou.
  - Na tela: Copiar link ou "Responder neste computador" (popup).
- **Página do funcionário (integracao.php, na raiz, sem login):**
  - feita para celular e tablet, em português, inglês e espanhol;
  - perguntas agrupadas por bloco (tema), com aviso das obrigatórias;
  - usa o motor universal de questionários (QuestionnaireService, execução com usuário 0 e origem fo_funcionarios);
  - não mostra a nota.
- **Ao enviar:** sp_fo_integracao_respondida calcula a sugestão pela nota mínima e manda alerta ao CODUSUARIO_RESPONSAVEL_INTEGRACAO do fornecedor (senão a quem gerou o link), com link ?integracao=N.
- **Decisão (?decisao=N):**
  - mostra as respostas por bloco, com os pontos, a nota, a nota mínima e a sugestão;
  - motivo obrigatório para reprovar ou para aprovar abaixo da nota mínima.
- **Aprovada:**
  - VALIDADE_ATE = hoje + meses, gravado em fo_funcionarios.DATA_VALIDADE (a data que os relatórios e a lista da portaria usam);
  - registra a proteção do campo contra o sincronismo;
  - gera o certificado em PDF (dompdf, com o nome da empresa no cabeçalho) no GED, em fo_funcionarios\<código>\certificado_integracao_<data>_<n>.pdf.
- **Sincronismo:**
  - a proteção de campos está desligada (decisão c446), e o legado prevalece até a virada;
  - por isso o serviço de documentos da homologação (homologacao-documentos-worker, de hora em hora) chama sp_fo_integracao_reaplicar;
  - a última integração aprovada vence quando o sincronismo traz de volta uma data mais antiga.
- **Questionário modelo:** INTEGRACAO_FUNCIONARIO_PADRAO, criado em RASCUNHO no universal só se não houver nenhum de integração.
  - Blocos: Meio ambiente, Segurança ocupacional e Regras da unidade; 6 perguntas pontuadas; nota mínima 70%.
  - É preciso revisar e publicar em Questionários de Integração de Funcionários.

## Banco (database/348_integracao_funcionarios.sql, {{UNIVERSAL}})
- Tabelas: fo_integracao_config (único por tipo, NULL = padrão) e fo_integracoes (AGUARDANDO, RESPONDIDA, APROVADA, REPROVADA, CANCELADA).
- Views: vw_fo_integracao_tipos, vw_fo_integracao_questionarios (catálogo universal publicado, EMPLOYEE_INTEGRATION), vw_fo_integracao_config, vw_fo_integracoes, vw_fo_integracao_funcionarios (sobre vw_homologacao_funcionarios_grid).
- Procedures:
  - configuração: sp_fo_integracao_config_salvar, sp_fo_integracao_config_excluir;
  - link e resposta: sp_fo_integracao_criar, sp_fo_integracao_por_token, sp_fo_integracao_execucao, sp_fo_integracao_respondida;
  - decisão e certificado: sp_fo_integracao_decidir, sp_fo_integracao_certificado, sp_fo_integracao_cancelar;
  - sincronismo: sp_fo_integracao_reaplicar.
- Runner: scripts/aplicar-migracao-348-integracao-funcionarios.php [--simular] [--banco=], nos 6 bancos da homologação.

## Arquivos (c562)
- Novos:
  - integracao.php;
  - principal/homologacaofornecedores_integracao.php;
  - classes/IntegracaoFuncionario.php;
  - assets/css/integracao-publica.css, assets/css/integracao-funcionarios.css;
  - assets/js/integracao-publica.js, assets/js/integracao-funcionarios.js;
  - database/348_integracao_funcionarios.sql, database/348_ajuda_integracao_funcionarios.php;
  - scripts/aplicar-migracao-348-integracao-funcionarios.php.
- Alterado: scripts/homologacao-documentos-worker.php (reaplica a validade).

## Testes (MySQL 8 local; dompdf simulado)
- **Fluxo completo:** gerar link → página no celular → enviar sem responder (aviso) → responder com 5 de 6 certas (83,3%, sugestão Aprovado) → alerta ao responsável → reprovar sem motivo (recusado) → aprovar.
- **Depois de aprovar:** DATA_VALIDADE ficou com hoje + 12 meses, a lista mostra Liberado e o certificado foi gravado e aberto.
- **Repetição:** decidir de novo foi recusado; reabrir o link mostra "respostas enviadas"; um link inválido dá aviso.
- **Link novo:** gerar outro link cancelou o anterior.
- **Sincronismo:** reaplicar restaurou a data depois de um sincronismo simulado.
- **Pendente:** ver o PDF real gerado pelo dompdf no servidor.
