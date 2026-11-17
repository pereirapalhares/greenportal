# Validação dos documentos pelos modelos (migração 347, c561)

Este item é da Onda 2 da apresentação Atlas: "Validação documental — conferência conforme modelos e templates previamente cadastrados".

## Decisões do usuário (24/10/2026)
- **Onde:** modelo por critério, tanto de fornecedor quanto de funcionário.
- **O que tem o modelo:**
  - lista de itens, cada um obrigatório ou recomendado;
  - regras fixas;
  - arquivo de exemplo opcional, a partir do qual a IA sugere os itens.
- **Quando:** automática quando o arquivo chega.
- **Decisão:** a IA só sugere. Aprovar, reprovar ou devolver é sempre de uma pessoa.

## Como funciona
- **Tela do modelo:** principal/homologacaofornecedores_modelo_documento.php?escopo=FORNECEDOR|FUNCIONARIO&criterio=N.
  - Abre como popup pelo botão "Modelo do documento" (grupo Associações) em Critérios dos Fornecedores e em Critérios dos funcionários.
  - Usa a permissão do cadastro de critérios: Visualizar para ver; Editar para gravar, enviar o exemplo e pedir a sugestão.
- **Itens:** até 30. Item obrigatório que falta leva à sugestão "Pendente com o fornecedor" e a uma observação de devolução pronta. Item recomendado só aparece no parecer.
- **Regras fixas:**
  - conferir com o cadastro: razão social ≥ 75% e raiz do CNPJ, ou nome ≥ 80% e CPF;
  - assinatura, carimbo ou certificação digital;
  - validade escrita;
  - válido por pelo menos N dias;
  - emitido há no máximo N dias.
- **Arquivo de exemplo:**
  - fica no GED local, pasta fo_documento_modelos/<codmodelo>;
  - vai para a fila de OCR;
  - o botão "Sugerir itens pela IA" usa o prompt DOC_MODELO_SUGESTAO (tipo DOC_MODELO_SUGESTAO no taxímetro);
  - a pessoa escolhe o que acrescentar ou substituir.
- **Validação automática** (serviço "ia" do Agendador, windows-service.php → HomologacaoDocumentoModelo::processar):
  1. **Varredura, a cada 5 minutos:** documentos "Pendente com a empresa" de fornecedores ativos cujo critério tem modelo ativo com validação automática.
     - Entram só os arquivos enviados depois da ativação, salvo com "Validar também os arquivos que já estão nos documentos pendentes".
     - Extensões: pdf, imagens e Word, até 20 MB; até 20 arquivos por empresa a cada varredura.
     - Um hash é validado uma vez só por documento.
     - Arquivo já analisado manualmente fica IGNORADA.
     - O OCR usa a mesma chave GED_EXTRACAO da Gestão de Arquivos.
  2. **Leitura pronta:** o texto vai para a fila de IA com o prompt do documento (FORNECEDOR_DOCUMENTO / FUNCIONARIO_DOCUMENTO), mais as instruções do modelo.
     - Funcionário: o texto passa antes pela retirada de dados de saúde.
     - Com o limite mensal de IA atingido, espera.
  3. **Fim do pedido** (DocumentAiQueueService::notificar → aoConcluirJob):
     - grava a análise PENDENTE, a mesma da análise manual, com o parecer em dados_ia.modelo;
     - conclui o registro em ged_analises_ia, que aparece em Gestão de Arquivos › Análises de IA;
     - manda alerta ao responsável da empresa pelo documento (codusuario), que vira e-mail quando o SMTP for configurado.
- **Botão "Analisar com IA" da Gestão de Arquivos:** com modelo ativo, usa o modelo também. A revisão mostra "Conferência pelo modelo do documento" (itens e regras com Atende / Não atende / Conferir) e a observação de devolução.
- **Código compartilhado:** a conferência da leitura e a gravação saíram das APIs fornecedorcriterios_ia.php e funcionariocriterios_ia.php para classes/HomologacaoDocumentoIa.php, sem mudar a regra. A API e o serviço usam a mesma.
- **Não muda a situação do documento:** quem move para "Pendente com a empresa" continua sendo a regra automática da 301.

## Banco (database/347_documento_modelos.sql)
- Tabelas: fo_documento_modelos (único por ESCOPO+CODCRITERIO; ATIVADO_EM), fo_documento_modelo_validacoes (único por ESCOPO+CODIGO+HASH; status AGUARDANDO_TEXTO → NA_IA → CONCLUIDA/ERRO/IGNORADA), fo_documento_modelo_controle.
- Views: vw_doc_modelos, vw_doc_modelo_texto_exemplo, vw_doc_modelo_validacoes, vw_doc_modelo_controle.
- Procedures:
  - modelo e exemplo: sp_doc_modelo_salvar, sp_doc_modelo_exemplo, sp_doc_modelo_exemplo_texto, sp_doc_modelo_sugestao_job, sp_doc_modelo_do_documento;
  - varredura e leitura: sp_doc_modelo_candidatos, sp_doc_modelo_validacao_registrar, sp_doc_modelo_validacoes_hashes, sp_doc_modelo_validacoes_aguardando, sp_doc_modelo_ocr_texto;
  - andamento da validação: sp_doc_modelo_validacao_ia, sp_doc_modelo_validacao_por_job, sp_doc_modelo_validacao_concluir, sp_doc_modelo_validacao_erro, sp_doc_modelo_controle.
- Runner: scripts/aplicar-migracao-347-documento-modelos.php [--simular] [--banco=].
  - Roda nos bancos da homologação (HomologacaoBancos: os 6, sem a Luxottica).
  - Confere os pré-requisitos (261, 262, 222/282, 284).
  - Cadastra o prompt DOC_MODELO_SUGESTAO e grava a Ajuda (marcador ml347).

## Arquivos (c561)
- Novos:
  - classes/HomologacaoDocumentoModelo.php, classes/HomologacaoDocumentoIa.php;
  - principal/homologacaofornecedores_modelo_documento.php;
  - assets/css/documento-modelo.css, assets/js/documento-modelo.js;
  - database/347_documento_modelos.sql, database/347_ajuda_documento_modelos.php;
  - scripts/aplicar-migracao-347-documento-modelos.php.
- Alterados: principal/api/fornecedorcriterios_ia.php, principal/api/funcionariocriterios_ia.php, principal/gestao_arquivos.php (parecer na revisão), classes/DocumentAiQueueService.php (gancho no fim do pedido), scripts/windows-service.php (serviço "ia"), classes/IaTaximetro.php (tipo DOC_MODELO_SUGESTAO), as duas telas de critérios (botão).

## Testes (MySQL 8 local + IA simulada)
- **Certidão:**
  - saiu "Fora do modelo 6/8": faltava o código de controle, e o endereço (recomendado) não estava no documento;
  - as cinco regras foram conferidas;
  - alerta para o responsável com link para a Gestão de Arquivos.
- **ASO:** a linha de diagnóstico não foi enviada à IA. Saiu "Fora do modelo 2/3" (faltava o CRM do médico).
- **Arquivos que não entram:**
  - arquivo anterior à ativação;
  - arquivo .txt;
  - documento aprovado;
  - fornecedor inativo;
  - critério sem modelo;
  - arquivo já analisado à mão (fica IGNORADA).
- **Repetição:** uma segunda varredura não repete nada.
- **Tela:** gravar, validar os dias, recusar token inválido, enviar o exemplo, ler o texto, sugerir os itens e aplicar a sugestão; o layout foi conferido.
