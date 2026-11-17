-- Corrige a acentuação do nome do prompt de preenchimento de licenças.
USE `gmodelo_dbp`;
SET NAMES utf8mb4;

UPDATE `ia_prompts`
SET `nome_pt`=CONVERT(0x507265656E6368696D656E746F206465206C6963656EC3A76120706F7220646F63756D656E746F USING utf8mb4),
    `alterado_em`=NOW()
WHERE `codigo`='LI_LICENCA_DOCUMENTO';

INSERT INTO `registros_migracao`
  (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
  ('CORRIGIR_NOME_PROMPT_LICENCA_171','gmodelo_dbp.ia_prompts','gmodelo_dbp.ia_prompts.nome_pt','CORRECAO','1.0.0','CONCLUIDA',1,NOW(),NOW(),JSON_OBJECT('prompt','LI_LICENCA_DOCUMENTO','idioma','pt','codificacao','utf8mb4'))
ON DUPLICATE KEY UPDATE `status`=VALUES(`status`),`concluida_em`=VALUES(`concluida_em`),`detalhes`=VALUES(`detalhes`);
