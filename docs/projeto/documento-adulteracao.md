# Análise de adulteração dos documentos da homologação (c564, migração 349 — 24/10/2026)

Onda 3 da apresentação Atlas ("IA aplicada aos documentos — 3. Análise de adulteração").

## Situação
- **Migração 349 aplicada em 24/10/2026 às 18:44** nos 6 bancos da homologação (gmodelo, gatlas, gmodelo2, gcrown, ggdamericana, gganchieta). Só arquivos enviados a partir daí são analisados sozinhos.
- PHP do servidor tem openssl_cms_verify, exif, gd, gzuncompress e ZipArchive: todas as verificações ativas.
- Regras FORN_ADULTERACAO / FUNC_ADULTERACAO: ligadas só na MODELO2 (as outras regras da 301 estão desligadas nos demais bancos).
- Regras de negócio RN-HOM-109 a 118 marcadas [FEITO] em docs (c565).

## Decisões do usuário (24/10/2026)
- Verificações: metadados do PDF, assinatura digital, imagem e foto, conteúdo pela IA.
- Roda automática quando o arquivo chega (serviço "ia" do Agendador).
- Resultado: aponta o risco (baixo/médio/alto) e exige motivo (ciência) para aprovar documento de risco alto.

## Como funciona
- classes/HomologacaoDocumentoAdulteracao.php
  - processar(): varredura a cada 10 min, só pastas do GED que mudaram; arquivos com mtime anterior à ativação (fo_doc_adulteracao_controle.ATIVADO_TS) só pelo botão "Analisar este arquivo"; mesmo hash no mesmo documento = uma análise.
  - Leitura pela fila de OCR (mesma chave GED_EXTRACAO da Gestão de Arquivos/347) → verificar() → fila de IA tipo DOC_ADULTERACAO (prompt DOC_ADULTERACAO) → aoConcluirJob() (DocumentAiQueueService::notificar).
  - Verificações locais (PHP puro, sem programas externos): /Info e XMP (inclusive em object streams), editores de imagem/PDF, ModDate − CreationDate > 1 dia, atualizações incrementais (ignora híbrido do Office e PDF linearizado), assinaturas /ByteRange com openssl_cms_verify (DETACHED|NOVERIFY|BINARY; cadeia ICP-Brasil não conferida), alteração depois da última assinatura (DSS/carimbo permitidos), EXIF/APP13 Photoshop, ELA em JPEG (histerese, BAIXA), docx core.xml, CNPJ/CPF DV, datas impossíveis, emissão futura, validade < emissão, emissão depois da última gravação do arquivo (ALTA).
  - Risco: ALTA ou 3 MEDIA → ALTO; MEDIA → MEDIO; BAIXA → BAIXO; senão SEM_INDICIOS. INFO não conta.
  - Sem texto/prompt/limite de IA: conclui só com as regras fixas. Funcionário: texto limpo de dados de saúde antes da IA.
  - Alerta (MEDIO/ALTO) ao responsável da empresa pelo documento (sem ele: vw_resp_auto_adm), link gestao_arquivos …&aba=adulteracao.
- Banco (database/349_documento_adulteracao.sql): fo_doc_adulteracao, _log, _controle; vw_doc_adulteracao, vw_doc_adult_pendentes, vw_doc_adult_controle; sp_doc_adult_* (candidatos, hashes, registrar, presenca, arquivo_excluido, aguardando, ocr_texto, ia, por_job, concluir, erro, ciente, listar, controle).
- Gatilhos trg_fo_doc_adult_forn_bu / _func_bu: recusam Aprovado/Aprovado sem validade e Atende = Sim com risco ALTO sem ciência e arquivo presente; @gp_sinc_legado = 1 passa (legado prevalece). MESSAGE_TEXT ≤ 128 caracteres.
- 301 regravada: regras FORN_ADULTERACAO (ordem 15) e FUNC_ADULTERACAO (ordem 105), só agem com vw_doc_adult_pendentes presente; o runner da 349 regrava a 301 e as regras novas seguem o ligado/desligado das outras do mesmo escopo.
- Tela: Gestão de Arquivos, aba "Adulteração" (assets/js/ged-adulteracao.js) + ícone de escudo por arquivo; ações da API gestao_arquivos: adulteracao, adulteracao_analisar, adulteracao_ciente (Editar em PORTARIA_DOCUMENTACAO_* ou HOMOLOGACAO_FORNECEDOR_FORNECEDOR/FUNCIONARIO; motivo ≥ 10). Excluir o arquivo marca ARQUIVO_PRESENTE = 0. Fornecedor externo não vê.
- Ajuda (ml349): portaria_documentacaofornecedor/funcionario, homologacaofornecedor_fornecedor/funcionario. Taxímetro: DOC_ADULTERACAO.

## Testado localmente (MySQL 8 + IA simulada)
PDF limpo, editado (iLovePDF, CNPJ inválido, emissão depois da gravação → ALTO), assinado íntegro, assinado alterado e com bytes depois da assinatura (ALTO), incremental, object streams, linearizado, PowerPoint híbrido (sem falso positivo), JPEG com marca do Photoshop e região colada, docx; gatilhos, sincronismo, ciência, regras 301, exclusão de arquivo, varredura, IA com erro, perfil externo e a aba no navegador.

## Pendente
- Testar com documentos reais (certidões assinadas do gov.br/Serpro, ASO digitalizado) pelo botão "Analisar este arquivo" para calibrar falsos positivos.
- Conferir que o serviço "ia" (GreenPortal-IaWorker) está rodando.
