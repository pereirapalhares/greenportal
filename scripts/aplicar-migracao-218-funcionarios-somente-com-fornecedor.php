<?php
declare(strict_types=1);

/*
 * Migração 218 — o grid de Gestão de Funcionários mostra só funcionários associados a um fornecedor cadastrado.
 * Altera apenas a view vw_homologacao_funcionarios_grid; nenhum dado é alterado ou apagado.
 * Aplica em todos os bancos de clientes que têm o módulo de Homologação (fo_funcionarios + fo_fornecedores).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-218-funcionarios-somente-com-fornecedor.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$env = GreenPortalDatabase::env();
$sql = file_get_contents(dirname(__DIR__) . '/database/218_funcionarios_somente_com_fornecedor.sql');
if ($sql === false) throw new RuntimeException('Script SQL da migração 218 não encontrado.');

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
        $detalhes = ['view' => 'vw_homologacao_funcionarios_grid', 'regra' => 'INNER JOIN fo_fornecedores, sem provisórios da 217', 'funcionarios_total' => $total, 'visiveis_no_grid' => $visiveis, 'ocultos' => $total - $visiveis];
        $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
            VALUES ('GREEN_FUNCIONARIOS_COM_FORNECEDOR_218', 'fo_funcionarios;fo_fornecedores', 'vw_homologacao_funcionarios_grid', 'ESTRUTURA', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
            ->execute([$visiveis, json_encode($detalhes, JSON_UNESCAPED_UNICODE)]);
        $resultado[$banco] = $detalhes;
    } catch (Throwable $e) {
        $ok = false;
        $resultado[$banco] = ['erro' => $e->getMessage()];
    }
}

echo json_encode(['ok' => $ok && $bancos !== [], 'migracao' => '218', 'bancos' => $resultado], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
