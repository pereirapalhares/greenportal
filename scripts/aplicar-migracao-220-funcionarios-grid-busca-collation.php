<?php
declare(strict_types=1);

/*
 * Migração 220 — corrige a busca geral do grid de Gestão de Funcionários (collation da coluna ANEXO).
 * Altera apenas a view vw_homologacao_funcionarios_grid; nenhum dado é alterado ou apagado.
 * Aplica em todos os bancos de clientes que têm o módulo de Homologação (fo_funcionarios + fo_fornecedores).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-220-funcionarios-grid-busca-collation.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$env = GreenPortalDatabase::env();
$sql = file_get_contents(dirname(__DIR__) . '/database/220_funcionarios_grid_busca_collation.sql');
if ($sql === false) throw new RuntimeException('Script SQL da migração 220 não encontrado.');

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
        // Teste da busca geral nas duas collations usadas pelo portal.
        $buscaOk = [];
        foreach (['utf8mb4_0900_ai_ci', 'utf8mb4_general_ci'] as $collation) {
            $pdo->exec("SET NAMES utf8mb4 COLLATE $collation");
            $pdo->query("SELECT COUNT(*) FROM vw_homologacao_funcionarios_grid WHERE NOME LIKE '%a%' OR ANEXO LIKE '%a%' OR DSCUNIDADE LIKE '%a%' OR RAZAOSOCIAL LIKE '%a%' OR DSCTIPO LIKE '%a%' OR SETOR LIKE '%a%'")->fetchColumn();
            $buscaOk[] = $collation;
        }
        $detalhes = ['view' => 'vw_homologacao_funcionarios_grid', 'regra' => 'ANEXO sem texto fixo; busca testada', 'funcionarios_total' => $total, 'visiveis_no_grid' => $visiveis, 'ocultos' => $total - $visiveis, 'busca_testada' => $buscaOk];
        $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
            VALUES ('GREEN_FUNCIONARIOS_GRID_BUSCA_220', 'fo_funcionarios;fo_fornecedores', 'vw_homologacao_funcionarios_grid', 'ESTRUTURA', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
            ->execute([$visiveis, json_encode($detalhes, JSON_UNESCAPED_UNICODE)]);
        $resultado[$banco] = $detalhes;
    } catch (Throwable $e) {
        $ok = false;
        $resultado[$banco] = ['erro' => $e->getMessage()];
    }
}

echo json_encode(['ok' => $ok && $bancos !== [], 'migracao' => '220', 'bancos' => $resultado], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
