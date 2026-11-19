<?php
declare(strict_types=1);

/*
 * Migração 400 — Sincronismo do UNIVERSAL (decisão do usuário de 19/11/2026): green_universal → guniversal_dbp, só o
 * catálogo de palavras-chave (palavras_chaves), por operações como a legislação central (393): só o que mudou no legado
 * desde a última execução; o que foi feito no greenportal fica. Entra em toda execução (todas as empresas, uma empresa e
 * o agendamento diário), menos na opção "Legislação"; a opção "Universal (guniversal_dbp)" roda só ele.
 * A 1ª execução aplicada só cria a linha de base (guniversal_dbp.universal_sinc_base); exclusões vão com cópia para
 * guniversal_dbp.universal_sinc_exclusoes. Tipos de palavras-chave, Tipos × Palavras-chave, Help Desk e pesquisas: fora.
 * Regrava sp_sinc_execucao_solicitar para aceitar 'legislacao' (399) e 'universal'.
 * Uso: php scripts\aplicar-migracao-400-sincronismo-universal.php [--aplicar]   (pode rodar mais de uma vez)
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$aplicar = in_array('--aplicar', $argv ?? [], true);
$rel = ['ok' => true, 'migracao' => '400', 'modo' => $aplicar ? 'APLICAR' : 'SIMULACAO', 'universal' => []];
$sp = [];
$sp['sp_sinc_execucao_solicitar'] = "CREATE PROCEDURE sp_sinc_execucao_solicitar(IN p_modo VARCHAR(10), IN p_empresa VARCHAR(60), IN p_usuario BIGINT, IN p_usuario_nome VARCHAR(200), IN p_simulacao BIGINT) BEGIN
 DECLARE v_empresa VARCHAR(60) DEFAULT NULLIF(LOWER(TRIM(p_empresa)), '');
 DECLARE v_sim BIGINT DEFAULT NULL;
 IF p_modo NOT IN ('SIMULAR', 'VALIDAR', 'APLICAR') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Modo inválido'; END IF;
 IF p_modo = 'APLICAR' THEN
   IF NOT EXISTS (SELECT 1 FROM vw_sincronismo_execucoes WHERE codexecucao = p_simulacao AND pode_aplicar = 1) THEN
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Para aplicar, selecione uma simulação concluída nas últimas 24 horas e ainda não aplicada';
   END IF;
   SELECT empresa INTO v_empresa FROM sincronismo_execucoes WHERE codexecucao = p_simulacao;
   SET v_sim = p_simulacao;
 ELSEIF v_empresa IS NOT NULL AND v_empresa NOT IN ('legislacao', 'universal') AND NOT EXISTS (SELECT 1 FROM vw_sincronismo_empresas WHERE codigo = v_empresa) THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Empresa fora do sincronismo (sem migração concluída)';
 END IF;
 IF EXISTS (SELECT 1 FROM sincronismo_execucoes WHERE status = 'NA_FILA' AND modo = p_modo AND empresa <=> v_empresa) THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Já existe um pedido igual na fila';
 END IF;
 INSERT INTO sincronismo_execucoes (origem, modo, empresa, codusuario, usuario_nome, codsimulacao) VALUES ('MANUAL', p_modo, v_empresa, p_usuario, p_usuario_nome, v_sim);
 SELECT LAST_INSERT_ID() AS codexecucao; END";

try {
    $env = GreenPortalDatabase::env();
    $u = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
    $u->exec('SET NAMES utf8mb4');
    foreach ($sp as $nome => $sql) {
        if (!$aplicar) { $rel['universal'][$nome] = 'será regravada (aceita as opções legislacao e universal)'; continue; }
        $u->exec("DROP PROCEDURE IF EXISTS `$nome`"); $u->exec($sql); $rel['universal'][$nome] = 'ok';
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['erro'] = $e->getMessage(); }
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
