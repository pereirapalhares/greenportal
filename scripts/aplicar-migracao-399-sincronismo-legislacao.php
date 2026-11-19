<?php
declare(strict_types=1);

/*
 * Migração 399 — Sincronismo: opção "Legislação (glegislacao_dbp)" no combo Empresa de "Rodar agora" (pedido do usuário
 * de 19/11/2026). Roda só a legislação central (green_legislacao → glegislacao_dbp, por operações, migração 393), sem
 * nenhuma empresa: sincronizar-fo-legado.php --empresa=legislacao.
 * Regrava sp_sinc_execucao_solicitar (305) para aceitar 'legislacao' além das empresas do sincronismo.
 * Uso: php scripts\aplicar-migracao-399-sincronismo-legislacao.php [--aplicar]   (pode rodar mais de uma vez)
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$aplicar = in_array('--aplicar', $argv ?? [], true);
$rel = ['ok' => true, 'migracao' => '399', 'modo' => $aplicar ? 'APLICAR' : 'SIMULACAO', 'universal' => []];
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
 ELSEIF v_empresa IS NOT NULL AND v_empresa <> 'legislacao' AND NOT EXISTS (SELECT 1 FROM vw_sincronismo_empresas WHERE codigo = v_empresa) THEN
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
        if (!$aplicar) { $rel['universal'][$nome] = 'será regravada (aceita a opção legislacao)'; continue; }
        $u->exec("DROP PROCEDURE IF EXISTS `$nome`"); $u->exec($sql); $rel['universal'][$nome] = 'ok';
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['erro'] = $e->getMessage(); }
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
