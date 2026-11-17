<?php
declare(strict_types=1);

/*
 * Migração 219 — a view do grid de Gestão de Funcionários passa a ter CODUNIDADE (filtro Unidade / Fornecedor).
 * Altera apenas a view vw_homologacao_funcionarios_grid; nenhum dado é alterado ou apagado.
 * Aplica em todos os bancos de clientes que têm o módulo de Homologação (fo_funcionarios + fo_fornecedores).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-219-funcionarios-grid-codunidade.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$env = GreenPortalDatabase::env();
$sql = file_get_contents(dirname(__DIR__) . '/database/219_funcionarios_grid_codunidade.sql');
if ($sql === false) throw new RuntimeException('Script SQL da migração 219 não encontrado.');

// Descobre os bancos de clientes: novos bancos (*_dbp) com as tabelas do módulo e o controle de migrações.
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES
    WHERE TABLE_NAME IN ('fo_funcionarios', 'fo_fornecedores', 'registros_migracao') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 3 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
// Só os bancos da homologação (classes/HomologacaoBancos.php; --banco= para só alguns)
$homologacao = HomologacaoBancos::lista($argv ?? []);
$bancos = array_values(array_intersect($homologacao, $bancos));

$resultado = [];
foreach (array_diff($homologacao, $bancos) as $semTabelas) $resultado[$semTabelas] = ['aviso' => 'banco sem as tabelas exigidas: não aplicado'];
$ok = true;
foreach ($bancos as $banco) {
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $total = (int)$pdo->query('SELECT COUNT(*) FROM fo_funcionarios')->fetchColumn();
        $pdo->exec($sql);
        $visiveis = (int)$pdo->query('SELECT COUNT(*) FROM vw_homologacao_funcionarios_grid')->fetchColumn();
        $detalhes = ['view' => 'vw_homologacao_funcionarios_grid', 'regra' => 'CODUNIDADE na view; mantém a regra da 218', 'funcionarios_total' => $total, 'visiveis_no_grid' => $visiveis, 'ocultos' => $total - $visiveis];
        $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
            VALUES ('GREEN_FUNCIONARIOS_GRID_CODUNIDADE_219', 'fo_funcionarios;fo_fornecedores', 'vw_homologacao_funcionarios_grid', 'ESTRUTURA', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
            ->execute([$visiveis, json_encode($detalhes, JSON_UNESCAPED_UNICODE)]);
        $resultado[$banco] = $detalhes;
    } catch (Throwable $e) {
        $ok = false;
        $resultado[$banco] = ['erro' => $e->getMessage()];
    }
}

echo json_encode(['ok' => $ok && $bancos !== [], 'migracao' => '219', 'bancos' => $resultado], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
