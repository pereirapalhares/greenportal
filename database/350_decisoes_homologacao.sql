-- Migração 350 — Homologação de Fornecedores: decisões do usuário de 24/10/2026 (regras de negócio em aberto).
--   * Bloqueio do ranking (migração 289) passa a valer: fornecedor bloqueado não tem documentos aprovados (fornecedor: Aprovado /
--     Aprovado sem validade; funcionário: Atende = Sim) — gatilhos abaixo; na Portaria aparece como "Fornecedor bloqueado (não
--     liberado)" (views das migrações 260/262 regravadas) e as regras automáticas não aprovam (301 regravada).
--   * Excluir funcionário com documentos ou treinamentos preenchidos é recusado: marque os documentos como Desligado.
--   * Integração: fo_funcionarios.DATA_VALIDADE = data de REALIZAÇÃO; a validade é calculada (298/348 regravadas).
-- O sincronismo do legado (@gp_sinc_legado = 1) não passa por estas regras: até a virada o legado prevalece.
-- Aplicado por scripts/aplicar-migracao-350-decisoes-homologacao.php. Comandos separados por "-- @@".

-- Mesma estrutura da migração 289 (ranking), para os gatilhos e a view funcionarem num banco sem o ranking aplicado.
CREATE TABLE IF NOT EXISTS `fo_fornecedor_bloqueios` (
    `codfornecedor` INT NOT NULL,
    `motivo` VARCHAR(2000) NOT NULL,
    `codusuario` INT NULL,
    `bloqueado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`codfornecedor`)
) ENGINE=InnoDB

-- @@
DROP TRIGGER IF EXISTS `trg_fo_bloqueio_forn_bu`

-- @@
CREATE TRIGGER `trg_fo_bloqueio_forn_bu` BEFORE UPDATE ON `fo_fornecedorxcriterios` FOR EACH ROW
BEGIN
    IF COALESCE(@gp_sinc_legado, 0) <> 1
       AND TRIM(COALESCE(NEW.`situacao`, '')) IN ('Aprovado', 'Aprovado sem validade')
       AND TRIM(COALESCE(OLD.`situacao`, '')) NOT IN ('Aprovado', 'Aprovado sem validade')
       AND EXISTS (SELECT 1 FROM `fo_fornecedor_bloqueios` b WHERE b.`codfornecedor` = NEW.`codfornecedor`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fornecedor bloqueado no ranking: os documentos não podem ser aprovados enquanto o bloqueio durar.';
    END IF;
END

-- @@
DROP TRIGGER IF EXISTS `trg_fo_bloqueio_func_bu`

-- @@
CREATE TRIGGER `trg_fo_bloqueio_func_bu` BEFORE UPDATE ON `fo_funcionarioxtiposxcriterios` FOR EACH ROW
BEGIN
    IF COALESCE(@gp_sinc_legado, 0) <> 1
       AND UPPER(TRIM(COALESCE(NEW.`atendeaocriterio`, ''))) = 'SIM'
       AND UPPER(TRIM(COALESCE(OLD.`atendeaocriterio`, ''))) <> 'SIM'
       AND EXISTS (SELECT 1 FROM `fo_funcionarios` fu JOIN `fo_fornecedor_bloqueios` b ON b.`codfornecedor` = fu.`CODFORNECEDOR`
                    WHERE fu.`CODFUNCIONARIO` = NEW.`codfuncionario`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fornecedor bloqueado no ranking: os documentos do funcionário não podem ser aprovados.';
    END IF;
END

-- @@
DROP TRIGGER IF EXISTS `trg_fo_funcionarios_excluir_bd`

-- @@
-- Funcionário com documento ou treinamento preenchido (atende/realizado, validade) não é excluído: o histórico de homologação fica.
CREATE TRIGGER `trg_fo_funcionarios_excluir_bd` BEFORE DELETE ON `fo_funcionarios` FOR EACH ROW
BEGIN
    IF COALESCE(@gp_sinc_legado, 0) <> 1
       AND (EXISTS (SELECT 1 FROM `fo_funcionarioxtiposxcriterios` x WHERE x.`codfuncionario` = OLD.`CODFUNCIONARIO`
                     AND (TRIM(COALESCE(x.`atendeaocriterio`, '')) <> '' OR x.`datadevalidade` > '1900-01-01'))
            OR EXISTS (SELECT 1 FROM `fo_funcionarioxtiposxtreinamentos` t WHERE t.`codfuncionario` = OLD.`CODFUNCIONARIO`
                     AND (TRIM(COALESCE(t.`treinamentorealizado`, '')) <> '' OR t.`datadevalidadetreinamento` > '1900-01-01'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Funcionário com documentos ou treinamentos preenchidos: em vez de excluir, marque como Desligado.';
    END IF;
END

-- @@
-- Fornecedores bloqueados no ranking, para as telas e relatórios (leitura só por view).
CREATE OR REPLACE VIEW `vw_fo_fornecedores_bloqueados` AS
SELECT b.`codfornecedor` AS CODFORNECEDOR, b.`motivo` AS MOTIVO, b.`bloqueado_em` AS BLOQUEADO_EM, b.`codusuario` AS CODUSUARIO
FROM `fo_fornecedor_bloqueios` b
