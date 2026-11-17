<?php
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'gatlas_dbp', 'GPT');
$mode = $argv[1] ?? 'status';
$where = "codperfil=2 AND codmenu=19";
$cols = ['pode_incluir','pode_visualizar','pode_editar','pode_excluir','pode_exportar_excel','pode_gerar_pdf','pode_importar_arquivos','pode_visualizar_arquivos'];
$before = $pdo->query("SELECT " . implode(',', $cols) . " FROM permissoes WHERE {$where}")->fetch(PDO::FETCH_ASSOC);
if (!$before) { fwrite(STDERR, "Permissao nao localizada\n"); exit(1); }
if ($mode === 'activate') {
    $pdo->exec("UPDATE permissoes SET pode_incluir=1,pode_visualizar=1,pode_editar=1,pode_excluir=0,pode_exportar_excel=1,pode_gerar_pdf=0,pode_importar_arquivos=0,pode_visualizar_arquivos=0 WHERE {$where}");
} elseif ($mode === 'restore') {
    $pdo->exec("UPDATE permissoes SET pode_incluir=0,pode_visualizar=0,pode_editar=0,pode_excluir=0,pode_exportar_excel=0,pode_gerar_pdf=0,pode_importar_arquivos=0,pode_visualizar_arquivos=0 WHERE {$where}");
}
$after=$pdo->query("SELECT ".implode(',', $cols)." FROM permissoes WHERE {$where}")->fetch(PDO::FETCH_ASSOC);
echo json_encode(['before'=>$before,'after'=>$after],JSON_UNESCAPED_UNICODE).PHP_EOL;
