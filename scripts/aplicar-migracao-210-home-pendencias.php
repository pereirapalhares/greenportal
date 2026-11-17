<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$sql = file_get_contents(dirname(__DIR__) . '/database/210_home_pendencias_views.sql');
if ($sql === false) {
    throw new RuntimeException('Migração 210 não localizada.');
}

$databases = ['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'];
$result = [];
foreach ($databases as $database) {
    $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
    $pdo->exec('USE `' . $database . '`');
    // Os legados misturam collations utf8mb4_general_ci e utf8mb4_0900_ai_ci.
    // Fixar a collation da sessão evita que os CASTs textuais das chaves
    // de responsabilidade falhem durante a criação das views.
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
    // Modelo2 ainda não possuía a view-base desta transação. A estrutura
    // equivalente é criada a partir da própria tabela, sem alterar dados.
    $baseView = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.views WHERE table_schema = DATABASE() AND table_name = 'vw_medicao_monitoramento_monitoramento_grid'")->fetchColumn();
    if ($baseView === 0) {
        $pdo->exec("CREATE OR REPLACE VIEW vw_medicao_monitoramento_monitoramento_grid AS
            SELECT m.CODMONITORAMENTO,m.PARAMETROS,m.DATA_MEDICAO,m.DATA_REACAO,m.CODUSUARIO,
                   m.CODEQUIPAMENTO,m.OBS,m.CODAREA,m.CODASPECTO,m.CODPERIGO,m.codunidade AS CODUNIDADE,
                   m.codmedicao AS CODMEDICAO,CAST('' AS CHAR(1)) AS cronograma
            FROM mm_monitoramento m");
    }
    $pdo->exec($sql);

    $migration = $pdo->prepare(
        "INSERT INTO registros_migracao
            (codigo_migracao, origem, destino, tipo, versao, status,
             quantidade_registros, iniciada_em, concluida_em, detalhes)
         VALUES
            ('GREEN_HOME_PENDENCIAS_210', 'responsabilidade_atribuicoes;legado de usuários e grupos',
             'vw_minhas_licencas_grid;vw_minhas_monitoramentos_grid', 'ESTRUTURA', '4.0.1',
             'CONCLUIDA', :quantidade, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, :detalhes)
         ON DUPLICATE KEY UPDATE
             status = VALUES(status), quantidade_registros = VALUES(quantidade_registros),
             concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)"
    );
    $counts = [];
    foreach (['vw_minhas_licencas_grid', 'vw_minhas_monitoramentos_grid', 'vw_minhas_treinamentos_grid', 'vw_minhas_infraestrutura_grid', 'vw_meus_controles_grid'] as $view) {
        try {
            $counts[$view] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $view . '`')->fetchColumn();
        } catch (Throwable) {
            $counts[$view] = null;
        }
    }
    $migration->execute([
        'quantidade' => array_sum(array_map(static fn($v): int => is_int($v) ? $v : 0, $counts)),
        'detalhes' => json_encode(['views' => $counts, 'idempotente' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $result[$database] = ['views' => $counts, 'migration' => 1];
}

echo json_encode(['ok' => true, 'migration' => '210', 'targets' => $result], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
