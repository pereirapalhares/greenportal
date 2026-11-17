<?php
declare(strict_types=1);

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$env = GreenPortalDatabase::env();
$sql = file_get_contents(__DIR__ . '/../database/216_homologacao_fornecedores_grid_crud.sql');
if ($sql === false) throw new RuntimeException('Migração 216 não localizada.');

$out = [];
foreach (HomologacaoBancos::lista($argv ?? []) as $database) {
    $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
    $pdo->exec('USE ' . $database . '; ' . $sql);
    $viewColumns = $pdo->query('SHOW COLUMNS FROM vw_homologacao_fornecedores_grid')->fetchAll(PDO::FETCH_COLUMN);
    $permissions = (int)$pdo->query("SELECT COUNT(*) FROM permissoes p JOIN menus m ON m.codmenu=p.codmenu WHERE m.codigo='HOMOLOGACAO_FORNECEDOR_FORNECEDOR' AND p.pode_visualizar=1 AND p.pode_incluir=1 AND p.pode_editar=1 AND p.pode_excluir=1")->fetchColumn();
    $details = ['view_columns' => $viewColumns, 'permissions_with_crud' => $permissions, 'idempotente' => true];
    $record = $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes) VALUES ('GREEN_HOMOLOGACAO_FORNECEDORES_GRID_216','fo_fornecedores;usuarios;unidades;permissoes;perfil_menus',:destino,'ESTRUTURA','4.0.1','CONCLUIDA',:quantidade,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,:detalhes) ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),concluida_em=CURRENT_TIMESTAMP,detalhes=VALUES(detalhes)");
    $record->execute(['destino' => $database . '.vw_homologacao_fornecedores_grid', 'quantidade' => $permissions, 'detalhes' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $out[$database] = ['responsavel_fornecedor' => in_array('RESPONSAVEL_FORNECEDOR', $viewColumns, true), 'responsavel_integracao' => in_array('RESPONSAVEL_INTEGRACAO', $viewColumns, true), 'permissoes_crud' => $permissions];
}

echo json_encode(['ok' => true, 'migration' => '216', 'targets' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
