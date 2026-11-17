-- Migração 380 — Transferência de responsabilidades (GER-8 do 01_Mudancas_Gerais; decisão do usuário de 16/11/2026).
-- Até a virada o legado manda: trocar o responsável nos registros (licenças, NC, fornecedores, situações legais...) seria
-- desfeito pelo sincronismo. Por isso a transferência REDIRECIONA, sem alterar os registros: enquanto vale, o que é da pessoa
-- de origem (nos módulos escolhidos) aparece e é cobrado da pessoa de destino — Minhas pendências, sino, Agenda, painel de
-- pendências universais, alertas e e-mails — e a origem deixa de receber. Temporária (até uma data, ex.: férias) ou
-- definitiva (desligamento, troca de função; até ser encerrada). Motivo obrigatório, log e avisos (classes/TransferenciaResponsabilidade.php).
-- Blocos separados por "-- @@"; aplicados por scripts/aplicar-migracao-380-transferencia-responsabilidades.php.

-- @@
CREATE TABLE IF NOT EXISTS `transferencias_responsabilidade` (
  `CODTRANSFERENCIA` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `CODUSUARIO_ORIGEM` BIGINT NOT NULL,
  `CODUSUARIO_DESTINO` BIGINT NOT NULL,
  `MODULOS` VARCHAR(200) NOT NULL DEFAULT 'TODOS',   -- TODOS ou lista: LEGAL,LICENCAS,MEDICAO,CONTROLES,TREINAMENTOS,INFRAESTRUTURA,FORNECEDORES,NC,AGENDA
  `TIPO` VARCHAR(12) NOT NULL,                        -- TEMPORARIA | DEFINITIVA
  `INICIO` DATE NOT NULL,
  `FIM` DATE NULL,                                    -- TEMPORARIA: último dia; DEFINITIVA: vazio (até ser encerrada)
  `MOTIVO` VARCHAR(1000) NOT NULL,
  `SITUACAO` VARCHAR(12) NOT NULL DEFAULT 'ATIVA',    -- ATIVA | ENCERRADA
  `CODUSUARIO_AUTOR` BIGINT NULL,
  `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `CODUSUARIO_ENCERROU` BIGINT NULL,
  `ENCERRADO_EM` DATETIME NULL,
  `MOTIVO_ENCERRAMENTO` VARCHAR(1000) NULL,
  `PREVIA` TEXT NULL,                                 -- quantidades por módulo no momento da transferência (JSON)
  PRIMARY KEY (`CODTRANSFERENCIA`),
  KEY `ix_transf_origem` (`SITUACAO`, `CODUSUARIO_ORIGEM`),
  KEY `ix_transf_destino` (`SITUACAO`, `CODUSUARIO_DESTINO`),
  CONSTRAINT `ck_transf_tipo` CHECK (`TIPO` IN ('TEMPORARIA', 'DEFINITIVA')),
  CONSTRAINT `ck_transf_situacao` CHECK (`SITUACAO` IN ('ATIVA', 'ENCERRADA'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

-- @@
CREATE TABLE IF NOT EXISTS `transferencias_responsabilidade_log` (
  `CODLOG` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ACAO` VARCHAR(20) NOT NULL,                        -- TRANSFERIR | ENCERRAR | ALTERAR_FIM
  `CODTRANSFERENCIA` BIGINT NULL,
  `CODUSUARIO_ORIGEM` BIGINT NULL,
  `CODUSUARIO_DESTINO` BIGINT NULL,
  `CODUSUARIO_AUTOR` BIGINT NULL,
  `MOTIVO` VARCHAR(1000) NULL,
  `DETALHE` VARCHAR(4000) NULL,
  `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODLOG`),
  KEY `ix_transf_log` (`CRIADO_EM`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

-- @@
-- Em vigor hoje (temporária vence no dia seguinte ao FIM).
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `vw_transferencias_responsabilidade_ativas` AS
SELECT t.`CODTRANSFERENCIA`, t.`CODUSUARIO_ORIGEM`, t.`CODUSUARIO_DESTINO`, t.`MODULOS`, t.`TIPO`, t.`INICIO`, t.`FIM`,
       CONVERT(COALESCE(o.`nome`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `ORIGEM`,
       CONVERT(COALESCE(d.`nome`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `DESTINO`
  FROM `transferencias_responsabilidade` t
  LEFT JOIN `usuarios` o ON o.`codusuario` = t.`CODUSUARIO_ORIGEM`
  LEFT JOIN `usuarios` d ON d.`codusuario` = t.`CODUSUARIO_DESTINO`
 WHERE t.`SITUACAO` = 'ATIVA' AND t.`INICIO` <= CURDATE() AND (t.`FIM` IS NULL OR t.`FIM` >= CURDATE())

-- @@
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `vw_transferencias_responsabilidade_grid` AS
SELECT t.`CODTRANSFERENCIA`, t.`CODUSUARIO_ORIGEM`, CONVERT(COALESCE(o.`nome`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `ORIGEM`,
       t.`CODUSUARIO_DESTINO`, CONVERT(COALESCE(d.`nome`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `DESTINO`,
       t.`MODULOS`, t.`TIPO`, t.`INICIO`, t.`FIM`, t.`MOTIVO`, t.`SITUACAO`,
       CASE WHEN t.`SITUACAO` = 'ENCERRADA' THEN 'ENCERRADA'
            WHEN t.`INICIO` > CURDATE() THEN 'AGENDADA'
            WHEN t.`FIM` IS NOT NULL AND t.`FIM` < CURDATE() THEN 'VENCIDA'
            ELSE 'EM_VIGOR' END AS `ESTADO`,
       t.`CODUSUARIO_AUTOR`, CONVERT(COALESCE(a.`nome`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `AUTOR`, t.`CRIADO_EM`,
       t.`CODUSUARIO_ENCERROU`, CONVERT(COALESCE(e.`nome`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `ENCERROU`,
       t.`ENCERRADO_EM`, t.`MOTIVO_ENCERRAMENTO`, t.`PREVIA`
  FROM `transferencias_responsabilidade` t
  LEFT JOIN `usuarios` o ON o.`codusuario` = t.`CODUSUARIO_ORIGEM`
  LEFT JOIN `usuarios` d ON d.`codusuario` = t.`CODUSUARIO_DESTINO`
  LEFT JOIN `usuarios` a ON a.`codusuario` = t.`CODUSUARIO_AUTOR`
  LEFT JOIN `usuarios` e ON e.`codusuario` = t.`CODUSUARIO_ENCERROU`

-- @@
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `vw_transferencias_responsabilidade_log` AS
SELECT l.`CODLOG`, l.`ACAO`, l.`CODTRANSFERENCIA`,
       l.`CODUSUARIO_ORIGEM`, CONVERT(COALESCE(o.`nome`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `ORIGEM`,
       l.`CODUSUARIO_DESTINO`, CONVERT(COALESCE(d.`nome`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `DESTINO`,
       l.`CODUSUARIO_AUTOR`, CONVERT(COALESCE(a.`nome`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `AUTOR`,
       l.`MOTIVO`, l.`DETALHE`, l.`CRIADO_EM`
  FROM `transferencias_responsabilidade_log` l
  LEFT JOIN `usuarios` o ON o.`codusuario` = l.`CODUSUARIO_ORIGEM`
  LEFT JOIN `usuarios` d ON d.`codusuario` = l.`CODUSUARIO_DESTINO`
  LEFT JOIN `usuarios` a ON a.`codusuario` = l.`CODUSUARIO_AUTOR`

-- @@
DROP TRIGGER IF EXISTS `trg_alertas_bi_transferencia`

-- @@
-- Alertas (e os e-mails, que saem dos alertas) da pessoa de origem vão para a de destino enquanto a transferência vale.
-- O módulo do alerta vem do link (a mesma regra de TransferenciaResponsabilidade::moduloDaUrl); alerta sem módulo só
-- é redirecionado quando a transferência é de TODOS os módulos. Exportações e os avisos da própria transferência
-- (@gp_sem_transferencia = 1) não são redirecionados.
CREATE TRIGGER `trg_alertas_bi_transferencia` BEFORE INSERT ON `alertas` FOR EACH ROW
BEGIN
  DECLARE v_mod VARCHAR(20) DEFAULT '';
  DECLARE v_dest BIGINT DEFAULT NULL;
  DECLARE v_nome VARCHAR(200) DEFAULT NULL;   -- nome de quem transferiu (origem)
  IF COALESCE(@gp_sem_transferencia, 0) = 0 AND NEW.`codusuario` IS NOT NULL
     AND COALESCE(NEW.`link`, '') NOT LIKE '%export_status%'
     AND EXISTS (SELECT 1 FROM `transferencias_responsabilidade` x WHERE x.`SITUACAO` = 'ATIVA' AND x.`CODUSUARIO_ORIGEM` = NEW.`codusuario`) THEN
    SET v_mod = CASE
      WHEN COALESCE(NEW.`link`, '') REGEXP 'naoconformidade' THEN 'NC'
      WHEN COALESCE(NEW.`link`, '') REGEXP 'homologacao|fornecedor|portaria|integracao' THEN 'FORNECEDORES'
      WHEN COALESCE(NEW.`link`, '') REGEXP 'licenca|condicionante|taxa' THEN 'LICENCAS'
      WHEN COALESCE(NEW.`link`, '') REGEXP 'medicao|monitoramento' THEN 'MEDICAO'
      WHEN COALESCE(NEW.`link`, '') REGEXP 'treinamento' THEN 'TREINAMENTOS'
      WHEN COALESCE(NEW.`link`, '') REGEXP 'infraestrutura' THEN 'INFRAESTRUTURA'
      WHEN COALESCE(NEW.`link`, '') REGEXP 'controle' THEN 'CONTROLES'
      WHEN COALESCE(NEW.`link`, '') REGEXP 'requisito|modulolegal|situacao|legislacao' THEN 'LEGAL'
      WHEN COALESCE(NEW.`link`, '') REGEXP 'eventos|agenda|tarefa' THEN 'AGENDA'
      ELSE '' END;
    SELECT t.`CODUSUARIO_DESTINO` INTO v_dest
      FROM `transferencias_responsabilidade` t
     WHERE t.`SITUACAO` = 'ATIVA' AND t.`CODUSUARIO_ORIGEM` = NEW.`codusuario`
       AND t.`INICIO` <= CURDATE() AND (t.`FIM` IS NULL OR t.`FIM` >= CURDATE())
       AND (t.`MODULOS` = 'TODOS' OR (v_mod <> '' AND FIND_IN_SET(v_mod COLLATE utf8mb4_unicode_ci, t.`MODULOS`) > 0))
     ORDER BY t.`CODTRANSFERENCIA` DESC LIMIT 1;
    IF v_dest IS NOT NULL AND v_dest <> NEW.`codusuario` THEN
      SELECT `nome` INTO v_nome FROM `usuarios` WHERE `codusuario` = NEW.`codusuario` LIMIT 1;
      -- COLLATE explícito dos dois lados: variável (colação do banco) × coluna (colação da tabela) podem ser diferentes
      SET NEW.`mensagem` = CONCAT(CONVERT(CONCAT('Responsabilidade de ', COALESCE(v_nome, CONCAT('#', NEW.`codusuario`)), ', transferida para você. ') USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                                  CONVERT(COALESCE(NEW.`mensagem`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci);
      SET NEW.`codusuario` = v_dest;
    END IF;
  END IF;
END
