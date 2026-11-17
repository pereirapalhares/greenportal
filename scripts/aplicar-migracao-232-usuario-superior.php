<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$targets = ['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'];
$result = [];

foreach ($targets as $database) {
    $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
    $hasColumn = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=" . $pdo->quote($database) . " AND TABLE_NAME='usuarios' AND COLUMN_NAME='codusuario_superior'")->fetchColumn() > 0;
    if (!$hasColumn) {
        $pdo->exec('ALTER TABLE usuarios ADD COLUMN codusuario_superior BIGINT UNSIGNED NULL AFTER codgrupo, ADD KEY ix_usuarios_superior (codusuario_superior)');
    }
    $hasForeignKey = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=" . $pdo->quote($database) . " AND TABLE_NAME='usuarios' AND CONSTRAINT_NAME='fk_usuarios_superior'")->fetchColumn() > 0;
    if (!$hasForeignKey) {
        $pdo->exec('ALTER TABLE usuarios ADD CONSTRAINT fk_usuarios_superior FOREIGN KEY (codusuario_superior) REFERENCES usuarios(codusuario) ON DELETE SET NULL ON UPDATE CASCADE');
    }
    $pdo->exec("CREATE OR REPLACE VIEW vw_usuarios_grid AS
        SELECT u.codusuario, u.userid, u.nome, u.email, u.codperfil, u.codgrupo,
               u.codusuario_superior, u.ativo,
               p.codigo AS perfil_codigo, p.nome AS perfil_nome,
               g.nome AS grupo_nome, us.nome AS usuario_superior_nome,
               u.criado_em, u.ultimo_acesso
          FROM usuarios u
          LEFT JOIN perfis p ON p.codperfil=u.codperfil
          LEFT JOIN grupos g ON g.codgrupo=u.codgrupo
          LEFT JOIN usuarios us ON us.codusuario=u.codusuario_superior");
    $migration = $pdo->prepare("INSERT INTO registros_migracao(codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes)
        VALUES('SEGURANCA_USUARIO_SUPERIOR_232','usuarios.codusuario_superior','vw_usuarios_grid.usuario_superior_nome','ESTRUTURA','1.0.0','CONCLUIDA',?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,?)
        ON DUPLICATE KEY UPDATE status='CONCLUIDA',quantidade_registros=VALUES(quantidade_registros),concluida_em=CURRENT_TIMESTAMP,detalhes=VALUES(detalhes)");
    $migration->execute([(int)$pdo->query('SELECT COUNT(*) FROM usuarios WHERE codusuario_superior IS NOT NULL')->fetchColumn(), json_encode(['idempotente'=>true,'alvo'=>'usuario superior'], JSON_UNESCAPED_UNICODE)]);
    $result[$database] = ['coluna'=>true,'usuarios_com_superior'=>(int)$pdo->query('SELECT COUNT(*) FROM usuarios WHERE codusuario_superior IS NOT NULL')->fetchColumn()];
}

echo json_encode(['ok'=>true,'migration'=>232,'targets'=>$result], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL;
