<?php
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'gatlas_dbp', 'GPT');
$id = 4;
$mode = $argv[1] ?? 'status';
$row = $pdo->query("SELECT codusuario,ativo,ativado FROM usuarios WHERE codusuario={$id}")->fetch(PDO::FETCH_ASSOC);
if (!$row) { fwrite(STDERR, "Usuario nao localizado\n"); exit(1); }
if ($mode === 'activate') {
    $pdo->exec("UPDATE usuarios SET ativo=1, ativado='S' WHERE codusuario={$id}");
} elseif ($mode === 'restore') {
    $pdo->exec("UPDATE usuarios SET ativo=0, ativado='N' WHERE codusuario={$id}");
}
echo json_encode(['before'=>$row,'after'=>$pdo->query("SELECT codusuario,ativo,ativado FROM usuarios WHERE codusuario={$id}")->fetch(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE) . PHP_EOL;
