<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env = GreenPortalDatabase::env();
$banco = 'gcrown_dbp';
foreach ($argv ?? [] as $arg) if (str_starts_with((string)$arg, '--banco=')) $banco = strtolower(trim(substr((string)$arg, 8)));
if (!preg_match('/^g[a-z0-9_]+_dbp$/', $banco)) throw new InvalidArgumentException('Banco destino inválido.');
$pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
$pdo->exec("CREATE TABLE IF NOT EXISTS migracao_orfaos_decisoes (
  coddecisao BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo VARCHAR(80) NOT NULL,
  registro_id BIGINT NOT NULL,
  acao VARCHAR(40) NOT NULL,
  dados JSON NULL,
  justificativa VARCHAR(2000) NOT NULL,
  codusuario BIGINT NOT NULL,
  decidido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_migracao_orfao_decisao (tipo, registro_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec('DROP VIEW IF EXISTS vw_migracao_orfaos_integridade');
$pdo->exec("CREATE VIEW vw_migracao_orfaos_integridade AS
SELECT 'CONDICIONANTE_SEM_LICENCA' AS tipo, c.CODCONDICIONANTE AS registro_id, c.CODLICENCA AS referencia_id,
       c.DSCCONDICIONANTE AS descricao, COALESCE(c.CODUSUARIO,0) AS responsavel_id, d.acao AS decisao,
       d.justificativa, d.decidido_em
FROM li_condicionantes c
LEFT JOIN li_licencas l ON l.CODLICENCA=c.CODLICENCA
LEFT JOIN migracao_orfaos_decisoes d ON d.tipo='CONDICIONANTE_SEM_LICENCA' AND d.registro_id=c.CODCONDICIONANTE
WHERE l.CODLICENCA IS NULL AND d.coddecisao IS NULL
UNION ALL
SELECT 'RESPONSAVEL_INEXISTENTE' AS tipo, c.CODCONDICIONANTE AS registro_id, c.CODUSUARIO AS referencia_id,
       c.DSCCONDICIONANTE AS descricao, c.CODUSUARIO AS responsavel_id, d.acao AS decisao,
       d.justificativa, d.decidido_em
FROM li_condicionantes c
LEFT JOIN usuarios u ON u.codusuario=c.CODUSUARIO
LEFT JOIN migracao_orfaos_decisoes d ON d.tipo='RESPONSAVEL_INEXISTENTE' AND d.registro_id=c.CODCONDICIONANTE
WHERE COALESCE(c.CODUSUARIO,0)>0 AND u.codusuario IS NULL AND d.coddecisao IS NULL");
$pdo->exec('DROP PROCEDURE IF EXISTS sp_migracao_orfao_decidir');
$pdo->exec("CREATE PROCEDURE sp_migracao_orfao_decidir(IN p_tipo VARCHAR(80), IN p_registro BIGINT, IN p_acao VARCHAR(40), IN p_dados JSON, IN p_justificativa VARCHAR(2000), IN p_usuario BIGINT)
BEGIN
  DECLARE v_id BIGINT;
  IF p_justificativa IS NULL OR CHAR_LENGTH(TRIM(p_justificativa))=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Justificativa obrigatória'; END IF;
  IF p_tipo='CONDICIONANTE_SEM_LICENCA' AND p_acao='VINCULAR_LICENCA' THEN
    IF JSON_EXTRACT(p_dados,'$.CODLICENCA') IS NULL OR NOT EXISTS (SELECT 1 FROM li_licencas WHERE CODLICENCA=CAST(JSON_UNQUOTE(JSON_EXTRACT(p_dados,'$.CODLICENCA')) AS UNSIGNED)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Licença válida não informada'; END IF;
    UPDATE li_condicionantes SET CODLICENCA=CAST(JSON_UNQUOTE(JSON_EXTRACT(p_dados,'$.CODLICENCA')) AS UNSIGNED) WHERE CODCONDICIONANTE=p_registro;
  ELSEIF p_tipo='RESPONSAVEL_INEXISTENTE' AND p_acao='ATRIBUIR_RESPONSAVEL' THEN
    IF JSON_EXTRACT(p_dados,'$.CODUSUARIO') IS NULL OR NOT EXISTS (SELECT 1 FROM usuarios WHERE codusuario=CAST(JSON_UNQUOTE(JSON_EXTRACT(p_dados,'$.CODUSUARIO')) AS UNSIGNED) AND ativo=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Usuário ativo não informado'; END IF;
    UPDATE li_condicionantes SET CODUSUARIO=CAST(JSON_UNQUOTE(JSON_EXTRACT(p_dados,'$.CODUSUARIO')) AS UNSIGNED) WHERE CODCONDICIONANTE=p_registro;
  ELSEIF p_tipo='RESPONSAVEL_INEXISTENTE' AND p_acao='REMOVER_RESPONSAVEL' THEN
    UPDATE li_condicionantes SET CODUSUARIO=NULL WHERE CODCONDICIONANTE=p_registro;
  ELSEIF p_acao NOT IN ('JUSTIFICAR','INATIVAR') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ação não permitida';
  END IF;
  INSERT INTO migracao_orfaos_decisoes(tipo,registro_id,acao,dados,justificativa,codusuario) VALUES(p_tipo,p_registro,p_acao,p_dados,p_justificativa,p_usuario)
  ON DUPLICATE KEY UPDATE acao=VALUES(acao),dados=VALUES(dados),justificativa=VALUES(justificativa),codusuario=VALUES(codusuario),decidido_em=CURRENT_TIMESTAMP;
  SELECT * FROM vw_migracao_orfaos_integridade WHERE tipo=p_tipo AND registro_id=p_registro;
END");
echo json_encode(['ok'=>true,'banco'=>$banco,'view'=>'vw_migracao_orfaos_integridade','procedure'=>'sp_migracao_orfao_decidir'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
