<?php
declare(strict_types=1);

/*
 * Migração 351 — Backup automático e retenção (decisão do usuário de 25/10/2026).
 *
 * Universal:
 *   - backup_config (horário, quantos manter, o que entra) e backup_execucoes (histórico), procedures sp_backup_* e a view
 *     vw_backup_execucoes;
 *   - menu Administração › Backup (ADMINISTRACAO_BACKUP, só ADMINISTRADOR), replicado nas empresas, e a Ajuda da tela;
 *   - serviço BACKUP_WORKER na tela Administração › Serviços.
 * Quem faz o backup é scripts/backup-worker.php no Agendador do Windows (scripts/criar-agendamento-backup.ps1): todo dia no
 * horário da configuração (padrão 02:00) — bancos *_dbp (mysqldump consistente, compactado), aplicação (zip, com o .env),
 * espelho do GED (só o que é novo ou mudou; nada é apagado do espelho), manifesto SHA-256, rotação (14 diários + o último de
 * cada uma das 8 semanas) e, em seguida, a retenção de exportações (30 dias) e alertas lidos (90 dias) em todas as empresas.
 * Destino: BACKUP_DIR no .env (outro disco ou compartilhamento de rede). Sem ele, backups\automatico na aplicação, e a tela avisa.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-351-backup.php [--simular]
 * Pode ser executada mais de uma vez (a configuração gravada não é sobrescrita).
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';

const M351_MENU = 'ADMINISTRACAO_BACKUP';
const M351_URL = '/principal/administracao_backup.php';

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$rel = ['ok' => true, 'migracao' => '351', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'destino' => [], 'universal' => []];

// ---------------------------------------------------------------- destino (só informa)
$app = dirname(__DIR__);
$dir = trim((string)($env['BACKUP_DIR'] ?? ''));
$rel['destino']['pasta'] = $dir !== '' ? $dir : $app . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'automatico';
$rel['destino']['situacao'] = $dir === '' ? 'BACKUP_DIR não configurado no .env: os backups ficam no mesmo disco da aplicação (sem proteção contra perda do disco)'
    : (is_dir($dir) ? (is_writable($dir) ? 'ok' : 'a pasta existe, mas não aceita gravação por este usuário (o serviço roda como SYSTEM)') : 'a pasta não existe: o serviço tenta criar');
$dump = (string)($env['MYSQLDUMP_PATH'] ?? 'E:\\wamp\\bin\\mysql\\mysql8.4.7\\bin\\mysqldump.exe');
$rel['destino']['mysqldump'] = is_file($dump) ? $dump : "NÃO encontrado em $dump: configure MYSQLDUMP_PATH no .env";
$rel['destino']['ged'] = ['portal' => (string)($env['GGED_STORAGE_LOCAL_ROOT'] ?? $app . DIRECTORY_SEPARATOR . 'storage'), 'legado' => (string)($env['GGED_LEGACY_ROOT'] ?? 'E:\\projetoGreen\\GED')];
$rel['destino']['zip'] = class_exists('ZipArchive') ? 'ok' : 'NÃO: habilite a extensão zip no php.ini (a aplicação fica fora do backup)';
$rel['destino']['zlib'] = function_exists('gzopen') ? 'ok' : 'NÃO: habilite a extensão zlib no php.ini';

try {
    $cat = new MenuCatalogoService($env);
    $u = $cat->universal();
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $u->exec('SET NAMES utf8mb4');
    $um = static function (string $q, array $p = []) use ($u): mixed { $s = $u->prepare($q); $s->execute($p); $v = $s->fetchColumn(); $s->closeCursor(); return $v; };

    $ddl = [];
    $ddl['backup_config'] = "CREATE TABLE IF NOT EXISTS backup_config (
 codconfig TINYINT UNSIGNED NOT NULL DEFAULT 1, ativo TINYINT(1) NOT NULL DEFAULT 1, horario TIME NOT NULL DEFAULT '02:00:00',
 manter_diarios INT NOT NULL DEFAULT 14, manter_semanais INT NOT NULL DEFAULT 8,
 incluir_aplicacao TINYINT(1) NOT NULL DEFAULT 1, incluir_ged TINYINT(1) NOT NULL DEFAULT 1, retencao TINYINT(1) NOT NULL DEFAULT 1,
 ultima_agendada DATE NULL, worker_ultimo_ciclo_em DATETIME NULL, atualizado_por BIGINT UNSIGNED NULL,
 atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY(codconfig)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";
    $ddl['backup_execucoes'] = "CREATE TABLE IF NOT EXISTS backup_execucoes (
 codbackup BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, origem VARCHAR(10) NOT NULL DEFAULT 'MANUAL', codusuario BIGINT UNSIGNED NULL,
 usuario_nome VARCHAR(200) NULL, status VARCHAR(25) NOT NULL DEFAULT 'NA_FILA', solicitado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 iniciado_em DATETIME NULL, finalizado_em DATETIME NULL, pasta VARCHAR(500) NULL, destino_protegido TINYINT(1) NULL,
 tamanho_bytes BIGINT UNSIGNED NULL, resumo JSON NULL, erro TEXT NULL, removido_em DATETIME NULL,
 PRIMARY KEY(codbackup), KEY ix_backup_status(status, solicitado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";
    $ddl['config_inicial'] = "INSERT IGNORE INTO backup_config (codconfig, ativo, horario, ultima_agendada) VALUES (1, 1, '02:00:00', IF(CURTIME() >= '02:00:00', CURDATE(), NULL))";
    $ddl['vw_backup_execucoes'] = "CREATE OR REPLACE VIEW vw_backup_execucoes AS
 SELECT b.codbackup, b.origem, b.codusuario, b.usuario_nome, b.status, b.solicitado_em, b.iniciado_em, b.finalizado_em, b.pasta,
        b.destino_protegido, b.tamanho_bytes, b.resumo, b.erro, b.removido_em,
        IF(b.iniciado_em IS NULL, NULL, TIMESTAMPDIFF(SECOND, b.iniciado_em, COALESCE(b.finalizado_em, NOW()))) AS duracao_segundos
   FROM backup_execucoes b";

    $sp = [];
    $sp['sp_backup_config_obter'] = "CREATE PROCEDURE sp_backup_config_obter() BEGIN
 SELECT c.ativo, TIME_FORMAT(c.horario, '%H:%i') AS horario, c.manter_diarios, c.manter_semanais, c.incluir_aplicacao, c.incluir_ged, c.retencao,
        c.ultima_agendada, c.worker_ultimo_ciclo_em, c.atualizado_em, TIMESTAMPDIFF(SECOND, c.worker_ultimo_ciclo_em, NOW()) AS worker_segundos,
        CASE WHEN c.ativo = 0 THEN NULL
             WHEN COALESCE(c.ultima_agendada, '1000-01-01') < CURDATE() AND CURTIME() < c.horario THEN TIMESTAMP(CURDATE(), c.horario)
             WHEN COALESCE(c.ultima_agendada, '1000-01-01') < CURDATE() THEN NOW()
             ELSE TIMESTAMP(CURDATE() + INTERVAL 1 DAY, c.horario) END AS proxima_execucao,
        (SELECT MAX(b.finalizado_em) FROM backup_execucoes b WHERE b.status IN ('CONCLUIDO', 'CONCLUIDO_COM_AVISO')) AS ultimo_ok_em,
        TIMESTAMPDIFF(HOUR, (SELECT MAX(b.finalizado_em) FROM backup_execucoes b WHERE b.status IN ('CONCLUIDO', 'CONCLUIDO_COM_AVISO')), NOW()) AS horas_desde_ultimo_ok
   FROM backup_config c WHERE c.codconfig = 1; END";
    $sp['sp_backup_config_salvar'] = "CREATE PROCEDURE sp_backup_config_salvar(IN p_ativo TINYINT, IN p_horario VARCHAR(5), IN p_diarios INT, IN p_semanais INT,
 IN p_aplicacao TINYINT, IN p_ged TINYINT, IN p_retencao TINYINT, IN p_usuario BIGINT) BEGIN
 DECLARE v_h TIME;
 IF p_horario IS NULL OR p_horario NOT REGEXP '^([01][0-9]|2[0-3]):[0-5][0-9]$' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Horário inválido (use HH:MM)'; END IF;
 IF p_diarios IS NULL OR p_diarios < 1 OR p_diarios > 90 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Backups diários: de 1 a 90'; END IF;
 IF p_semanais IS NULL OR p_semanais < 0 OR p_semanais > 52 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Backups semanais: de 0 a 52'; END IF;
 SET v_h = CAST(CONCAT(p_horario, ':00') AS TIME);
 INSERT IGNORE INTO backup_config (codconfig) VALUES (1);
 UPDATE backup_config SET ativo = IF(p_ativo = 1, 1, 0), horario = v_h, manter_diarios = p_diarios, manter_semanais = p_semanais,
        incluir_aplicacao = IF(p_aplicacao = 1, 1, 0), incluir_ged = IF(p_ged = 1, 1, 0), retencao = IF(p_retencao = 1, 1, 0), atualizado_por = p_usuario,
        ultima_agendada = IF(v_h > CURTIME(), CURDATE() - INTERVAL 1 DAY, CURDATE()) WHERE codconfig = 1;
 CALL sp_backup_config_obter(); END";
    $sp['sp_backup_execucoes_listar'] = "CREATE PROCEDURE sp_backup_execucoes_listar(IN p_limite INT) BEGIN SELECT * FROM vw_backup_execucoes ORDER BY codbackup DESC LIMIT p_limite; END";
    $sp['sp_backup_solicitar'] = "CREATE PROCEDURE sp_backup_solicitar(IN p_usuario BIGINT, IN p_usuario_nome VARCHAR(200)) BEGIN
 IF EXISTS (SELECT 1 FROM backup_execucoes WHERE status IN ('NA_FILA', 'EM_EXECUCAO', 'CANCELANDO')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Já existe um backup na fila ou em execução'; END IF;
 INSERT INTO backup_execucoes (origem, codusuario, usuario_nome) VALUES ('MANUAL', p_usuario, p_usuario_nome);
 SELECT LAST_INSERT_ID() AS codbackup; END";
    // Na fila: cancela na hora. Em execução: pede o cancelamento (CANCELANDO); o serviço confere a cada 30 segundos, para,
    // apaga a pasta do dia e grava CANCELADO.
    $sp['sp_backup_cancelar'] = "CREATE PROCEDURE sp_backup_cancelar(IN p_id BIGINT, IN p_usuario BIGINT) BEGIN
 UPDATE backup_execucoes SET status = 'CANCELADO', finalizado_em = NOW(), erro = 'Cancelado pelo usuário antes de começar' WHERE codbackup = p_id AND status = 'NA_FILA';
 IF ROW_COUNT() = 0 THEN
   UPDATE backup_execucoes SET status = 'CANCELANDO' WHERE codbackup = p_id AND status = 'EM_EXECUCAO';
   IF ROW_COUNT() = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Só é possível cancelar backup na fila ou em execução'; END IF;
 END IF;
 SELECT p_id AS codbackup, status FROM backup_execucoes WHERE codbackup = p_id; END";
    $sp['sp_backup_worker_situacao'] = "CREATE PROCEDURE sp_backup_worker_situacao(IN p_id BIGINT) BEGIN
 SELECT status FROM backup_execucoes WHERE codbackup = p_id; END";
    $sp['sp_backup_worker_agendar'] = "CREATE PROCEDURE sp_backup_worker_agendar() BEGIN
 INSERT IGNORE INTO backup_config (codconfig) VALUES (1);
 UPDATE backup_config SET worker_ultimo_ciclo_em = NOW(), atualizado_em = atualizado_em WHERE codconfig = 1;
 IF EXISTS (SELECT 1 FROM backup_config WHERE codconfig = 1 AND ativo = 1 AND CURTIME() >= horario AND COALESCE(ultima_agendada, '1000-01-01') < CURDATE()) THEN
   UPDATE backup_config SET ultima_agendada = CURDATE(), atualizado_em = atualizado_em WHERE codconfig = 1;
   IF NOT EXISTS (SELECT 1 FROM backup_execucoes WHERE status IN ('NA_FILA', 'EM_EXECUCAO', 'CANCELANDO')) THEN
     INSERT INTO backup_execucoes (origem, usuario_nome) VALUES ('AGENDADO', 'Backup diário');
   END IF;
 END IF; END";
    // Só é chamada com o bloqueio do serviço (GET_LOCK): o que ficou "em execução" é de um serviço que parou.
    $sp['sp_backup_worker_reivindicar'] = "CREATE PROCEDURE sp_backup_worker_reivindicar() BEGIN
 DECLARE v_id BIGINT DEFAULT 0;
 UPDATE backup_execucoes SET status = 'ERRO', finalizado_em = NOW(), erro = 'Interrompido: o serviço parou durante o backup' WHERE status = 'EM_EXECUCAO';
 UPDATE backup_execucoes SET status = 'CANCELADO', finalizado_em = NOW(), erro = 'Cancelado pelo usuário durante o backup (o serviço parou antes de concluir o cancelamento)' WHERE status = 'CANCELANDO';
 SELECT COALESCE(MIN(codbackup), 0) INTO v_id FROM backup_execucoes WHERE status = 'NA_FILA';
 IF v_id > 0 THEN
   UPDATE backup_execucoes SET status = 'EM_EXECUCAO', iniciado_em = NOW() WHERE codbackup = v_id;
   SELECT b.*, c.incluir_aplicacao, c.incluir_ged, c.retencao, c.manter_diarios, c.manter_semanais FROM backup_execucoes b JOIN backup_config c ON c.codconfig = 1 WHERE b.codbackup = v_id;
 END IF; END";
    $sp['sp_backup_worker_progresso'] = "CREATE PROCEDURE sp_backup_worker_progresso(IN p_id BIGINT, IN p_pasta VARCHAR(500), IN p_resumo JSON) BEGIN
 UPDATE backup_execucoes SET pasta = COALESCE(p_pasta, pasta), resumo = COALESCE(p_resumo, resumo) WHERE codbackup = p_id AND status IN ('EM_EXECUCAO', 'CANCELANDO'); END";
    $sp['sp_backup_worker_finalizar'] = "CREATE PROCEDURE sp_backup_worker_finalizar(IN p_id BIGINT, IN p_status VARCHAR(25), IN p_pasta VARCHAR(500), IN p_protegido TINYINT,
 IN p_tamanho BIGINT, IN p_resumo JSON, IN p_erro TEXT) BEGIN
 UPDATE backup_execucoes SET status = p_status, finalizado_em = NOW(), pasta = p_pasta, destino_protegido = p_protegido, tamanho_bytes = p_tamanho,
        resumo = p_resumo, erro = p_erro WHERE codbackup = p_id; END";
    // Rotação: backups concluídos ainda guardados (o serviço escolhe quais manter e marca os apagados)
    $sp['sp_backup_guardados'] = "CREATE PROCEDURE sp_backup_guardados() BEGIN
 SELECT codbackup, pasta, finalizado_em, tamanho_bytes FROM backup_execucoes
  WHERE status IN ('CONCLUIDO', 'CONCLUIDO_COM_AVISO') AND removido_em IS NULL AND pasta IS NOT NULL ORDER BY finalizado_em DESC; END";
    $sp['sp_backup_marcar_removido'] = "CREATE PROCEDURE sp_backup_marcar_removido(IN p_id BIGINT) BEGIN
 UPDATE backup_execucoes SET removido_em = NOW() WHERE codbackup = p_id AND removido_em IS NULL; END";
    // Empresas cuja retenção roda depois do backup (banco existente, ativo)
    $sp['sp_backup_bancos_retencao'] = "CREATE PROCEDURE sp_backup_bancos_retencao() BEGIN
 SELECT DISTINCT e.banco_dados FROM empresa e
  WHERE e.status = 'ATIVA' AND e.banco_dados IS NOT NULL AND EXISTS (SELECT 1 FROM information_schema.SCHEMATA s WHERE s.SCHEMA_NAME = e.banco_dados)
  ORDER BY e.banco_dados; END";

    foreach ($ddl as $nome => $sql) {
        if ($simular) { $rel['universal'][$nome] = 'será gravado'; continue; }
        $u->exec($sql); $rel['universal'][$nome] = 'ok';
    }
    foreach ($sp as $nome => $sql) {
        if ($simular) { $rel['universal'][$nome] = 'será gravada'; continue; }
        $u->exec("DROP PROCEDURE IF EXISTS `$nome`"); $u->exec($sql); $rel['universal'][$nome] = 'ok';
    }

    // Serviço na tela Administração › Serviços
    if ((int)$um("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'servicos'") > 0) {
        $cols = array_flip(MigracaoCrud::colunas($u, 'servicos'));
        $dados = array_intersect_key(['nome_pt' => 'Backup e retenção', 'nome_en' => 'Backup and retention', 'nome_es' => 'Copia de seguridad y retención',
            'descricao_pt' => 'Faz o backup diário (bancos, aplicação e GED) no horário de Administração › Backup, apaga os backups antigos e roda a retenção de exportações e alertas.',
            'descricao_en' => 'Runs the daily backup (databases, application and GED) at the time set in Administration › Backup, removes old backups and runs the exports/alerts retention.',
            'descricao_es' => 'Hace la copia diaria (bases, aplicación y GED) en el horario configurado, borra las copias antiguas y ejecuta la retención de exportaciones y alertas.',
            'host' => 'TASK_SCHEDULER', 'script' => 'scripts/backup-worker.php', 'ativo' => 1], $cols);
        $existe = (int)$um("SELECT COUNT(*) FROM servicos WHERE codigo = 'BACKUP_WORKER'") > 0;
        if ($simular) $rel['universal']['servico'] = $existe ? 'será atualizado' : 'será incluído';
        elseif ($existe) { MigracaoCrud::atualizar($u, 'servicos', ['codigo' => 'BACKUP_WORKER'], $dados); $rel['universal']['servico'] = 'atualizado'; }
        else { MigracaoCrud::inserir($u, 'servicos', ['codigo' => 'BACKUP_WORKER'] + $dados + array_intersect_key(['porta' => 0, 'ordem' => 15], $cols)); $rel['universal']['servico'] = 'incluído'; }
    }

    // Menu Administração › Backup, logo depois de Migração
    $existe = MigracaoCrud::primeiro($u, 'menus', ['codigo' => M351_MENU]);
    $ref = MigracaoCrud::primeiro($u, 'menus', ['codigo' => 'ADMINISTRACAO_MIGRACAO']);
    if ($existe) { if (!$simular) $cat->replicar(M351_MENU, null, false); $rel['universal']['menu'] = 'já existia (codmenu ' . $existe['codmenu'] . ')'; }
    elseif (!$ref) { $rel['ok'] = false; $rel['universal']['menu'] = 'ERRO: menu ADMINISTRACAO_MIGRACAO não encontrado (aplique a 305)'; }
    elseif ($simular) $rel['universal']['menu'] = 'será criado: Administração › Backup';
    else {
        $ref = array_change_key_case($ref);
        $g = $cat->gravar(['codmenu_pai' => $ref['codmenu_pai'] !== null ? (int)$ref['codmenu_pai'] : null, 'modulo' => $ref['modulo'] ?? 'ADMINISTRACAO', 'codigo' => M351_MENU,
            'label' => 'Backup', 'label_pt' => 'Backup', 'label_en' => 'Backup', 'label_es' => 'Copia de seguridad', 'url' => M351_URL,
            'nivel' => (int)($ref['nivel'] ?? 1), 'ordem' => (int)($ref['ordem'] ?? 0) + 1, 'icone' => 'bi-hdd-stack', 'ativo' => 1]);
        $rel['universal']['menu'] = 'criado (codmenu ' . $g['codmenu'] . ')';
    }

    // Ajuda
    if (MigracaoCrud::existe($u, 'ajuda_transacoes')) {
        $ajuda = require dirname(__DIR__) . '/database/351_ajuda_backup.php';
        $atual = MigracaoCrud::primeiro($u, 'ajuda_transacoes', ['transacao' => 'administracao_backup']);
        if ($atual) $rel['universal']['ajuda'] = 'já existia (mantida: pode ter sido editada na tela de ajuda)';
        elseif ($simular) $rel['universal']['ajuda'] = 'será incluída';
        else {
            $d = ['titulo_pt' => $ajuda['titulo']['pt'], 'titulo_en' => $ajuda['titulo']['en'], 'titulo_es' => $ajuda['titulo']['es'],
                'texto_pt' => $ajuda['texto']['pt'], 'texto_en' => $ajuda['texto']['en'], 'texto_es' => $ajuda['texto']['es'], 'ativo' => 1];
            MigracaoCrud::inserir($u, 'ajuda_transacoes', ['transacao' => 'administracao_backup'] + $d);
            $rel['universal']['ajuda'] = 'incluída';
        }
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['erro'] = $e->getMessage(); }

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$logs = $app . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logs)) @mkdir($logs, 0775, true);
@file_put_contents($logs . DIRECTORY_SEPARATOR . 'migracao_351_backup' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
if (!$simular && $rel['ok']) echo PHP_EOL . 'Depois instale o serviço (PowerShell como Administrador): scripts\criar-agendamento-backup.ps1' . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
