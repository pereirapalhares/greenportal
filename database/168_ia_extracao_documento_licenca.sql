-- Prompt e armazenamento da extração de documentos de licenças.
-- A IA gera uma sugestão; a gravação dos campos operacionais continua sujeita
-- à confirmação do usuário na transação de licenças.

USE `gmodelo_dbp`;
SET NAMES utf8mb4;

ALTER TABLE `li_licencas`
  ADD COLUMN `DADOS_DOCUMENTO_IA` JSON NULL
  COMMENT 'Última extração estruturada do documento da licença, aguardando ou após revisão do usuário';

INSERT INTO `ia_prompts`
  (`codigo`,`nome_pt`,`nome_en`,`nome_es`,`prompt_pt`,`prompt_en`,`prompt_es`)
SELECT
  'LI_LICENCA_DOCUMENTO',
  'Preenchimento de licença por documento',
  'License completion from document',
  'Completar licencia desde documento',
  'Leia o documento da licença e extraia somente informações claramente identificadas. Retorne exclusivamente JSON válido neste formato: {"descricao":null,"data_validade":null,"atividade":null,"orgao_emissor":null,"numero_licenca":null,"observacao":null,"confianca":0,"alertas":[]}. Não invente informações. Use null quando o dado não estiver claramente disponível. Retorne datas no formato YYYY-MM-DD. Diferencie data de emissão, data de validade e data de protocolo. Não defina unidade, empresa, usuário, status, permissões ou classificação. Informe alertas quando houver dúvida ou conflito no documento. Retorne somente o JSON, sem Markdown ou explicações.',
  'Read the license document and extract only clearly identified information. Return exclusively valid JSON in this format: {"descricao":null,"data_validade":null,"atividade":null,"orgao_emissor":null,"numero_licenca":null,"observacao":null,"confianca":0,"alertas":[]}. Do not invent information. Use null when the data is not clearly available. Return dates in YYYY-MM-DD format. Distinguish issue date, validity date and protocol date. Do not define unit, company, user, status, permissions or classification. Report alerts when there is doubt or conflict in the document. Return only JSON, without Markdown or explanations.',
  'Lea el documento de la licencia y extraiga únicamente información claramente identificada. Devuelva exclusivamente JSON válido con este formato: {"descricao":null,"data_validade":null,"atividade":null,"orgao_emissor":null,"numero_licenca":null,"observacao":null,"confianca":0,"alertas":[]}. No invente información. Use null cuando el dato no esté claramente disponible. Devuelva las fechas en formato YYYY-MM-DD. Diferencie fecha de emisión, fecha de validez y fecha de protocolo. No defina unidad, empresa, usuario, estado, permisos ni clasificación. Informe alertas cuando exista duda o conflicto en el documento. Devuelva solamente JSON, sin Markdown ni explicaciones.'
WHERE NOT EXISTS (SELECT 1 FROM `ia_prompts` WHERE `codigo`='LI_LICENCA_DOCUMENTO');

INSERT INTO `registros_migracao`
  (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,
   `quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
  ('IA_EXTRACAO_DOCUMENTO_LICENCA_168',
   'gmodelo_dbp.li_licencas e gmodelo_dbp.ia_prompts',
   'gmodelo_dbp.li_licencas.DADOS_DOCUMENTO_IA e prompt LI_LICENCA_DOCUMENTO',
   'ESTRUTURA','1.0.0','CONCLUIDA',1,NOW(),NOW(),
   JSON_OBJECT('fluxo','extração assistida com confirmação do usuário',
               'campo_json','DADOS_DOCUMENTO_IA',
               'prompt','LI_LICENCA_DOCUMENTO'))
ON DUPLICATE KEY UPDATE
  `status`=VALUES(`status`),
  `quantidade_registros`=VALUES(`quantidade_registros`),
  `concluida_em`=VALUES(`concluida_em`),
  `detalhes`=VALUES(`detalhes`);
