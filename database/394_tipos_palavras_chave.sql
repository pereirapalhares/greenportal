-- Migração 394 — Manutenção Legal › Tipos de palavras-chave e Tipos × Palavras-chave (glegislacao_dbp, compartilhado).
-- As tabelas vieram do legado na migração 031 (green_universal.tipos_palavras_chaves e tiposxpalavras_chaves) e não entram
-- no sincronismo: o que for gravado aqui fica.
-- Blocos separados por "-- @@" (scripts/aplicar-migracao-394-tipos-palavras-chave.php executa um a um). Pode rodar de novo.
-- As chaves passam a AUTO_INCREMENT e o índice único (tipo, palavra) é criado pelo script (só se não houver repetidos).
-- @@
CREATE OR REPLACE VIEW `vw_tipos_palavras_chaves_grid` AS
SELECT t.`codtipopalavrachave`, t.`dsctipopalavrachave`,
       (SELECT COUNT(*) FROM `tiposxpalavras_chaves` x WHERE x.`CODTIPOPALAVRACHAVE` = t.`codtipopalavrachave`) AS `QTD_PALAVRAS`
FROM `tipos_palavras_chaves` t
-- @@
DROP TRIGGER IF EXISTS `trg_tipos_palavras_chaves_bi`
-- @@
CREATE TRIGGER `trg_tipos_palavras_chaves_bi` BEFORE INSERT ON `tipos_palavras_chaves` FOR EACH ROW
BEGIN
    DECLARE v_cod INT DEFAULT NULL;
    DECLARE v_msg VARCHAR(255);
    SET NEW.`dsctipopalavrachave` = TRIM(REGEXP_REPLACE(COALESCE(NEW.`dsctipopalavrachave`, ''), '[[:space:]]+', ' '));
    IF NEW.`dsctipopalavrachave` = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o nome do tipo de palavra-chave.';
    END IF;
    SELECT `codtipopalavrachave` INTO v_cod FROM `tipos_palavras_chaves` WHERE `dsctipopalavrachave` = NEW.`dsctipopalavrachave` LIMIT 1;
    IF v_cod IS NOT NULL THEN
        SET v_msg = CONCAT('Já existe o tipo "', NEW.`dsctipopalavrachave`, '" (código ', v_cod, ').');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
END
-- @@
DROP TRIGGER IF EXISTS `trg_tipos_palavras_chaves_bu`
-- @@
CREATE TRIGGER `trg_tipos_palavras_chaves_bu` BEFORE UPDATE ON `tipos_palavras_chaves` FOR EACH ROW
BEGIN
    DECLARE v_cod INT DEFAULT NULL;
    DECLARE v_msg VARCHAR(255);
    SET NEW.`dsctipopalavrachave` = TRIM(REGEXP_REPLACE(COALESCE(NEW.`dsctipopalavrachave`, ''), '[[:space:]]+', ' '));
    IF NEW.`dsctipopalavrachave` = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o nome do tipo de palavra-chave.';
    END IF;
    SELECT `codtipopalavrachave` INTO v_cod FROM `tipos_palavras_chaves`
     WHERE `dsctipopalavrachave` = NEW.`dsctipopalavrachave` AND `codtipopalavrachave` <> OLD.`codtipopalavrachave` LIMIT 1;
    IF v_cod IS NOT NULL THEN
        SET v_msg = CONCAT('Já existe o tipo "', NEW.`dsctipopalavrachave`, '" (código ', v_cod, ').');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
END
-- @@
DROP TRIGGER IF EXISTS `trg_tipos_palavras_chaves_bd`
-- @@
CREATE TRIGGER `trg_tipos_palavras_chaves_bd` BEFORE DELETE ON `tipos_palavras_chaves` FOR EACH ROW
BEGIN
    DECLARE v_qtd INT DEFAULT 0;
    DECLARE v_msg VARCHAR(255);
    SELECT COUNT(*) INTO v_qtd FROM `tiposxpalavras_chaves` WHERE `CODTIPOPALAVRACHAVE` = OLD.`codtipopalavrachave`;
    IF v_qtd > 0 THEN
        SET v_msg = CONCAT('O tipo "', OLD.`dsctipopalavrachave`, '" tem ', v_qtd, ' palavra(s)-chave. Retire-as em Tipos × Palavras-chave antes de excluir.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
END
