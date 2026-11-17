<?php
declare(strict_types=1);

/*
 * Migração 223 — controle de duplicatas no envio de documentos à IA (Gestão de Arquivos).
 * Acrescenta a ged_analises_ia a impressão digital do arquivo (hash_arquivo, SHA-256 do conteúdo) e
 * analise_origem (análise cuja leitura foi reaproveitada), e calcula o hash das análises já feitas
 * a partir dos arquivos do GED. Aplica em todos os bancos de clientes. Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-223-ged-analises-ia-duplicatas.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/GedStorageService.php';

$env = GreenPortalDatabase::env();
$pastas = ['funcionarios' => 'fo_funcionarios', 'controles' => 'documentos', 'documentos' => 'documentos', 'infraestrutura' => 'infraestrutura', 'treinamentos' => 'treinamentos', 'licencas' => 'licencas'];

$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME = 'ged_analises_ia' AND TABLE_SCHEMA LIKE '%\\_dbp' ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);

$resultado = [];
$ok = true;
foreach ($bancos as $banco) {
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $colunas = array_map('strtolower', $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ged_analises_ia'")->fetchAll(PDO::FETCH_COLUMN));
        if (!in_array('hash_arquivo', $colunas, true)) $pdo->exec("ALTER TABLE ged_analises_ia ADD COLUMN hash_arquivo CHAR(64) NULL COMMENT 'SHA-256 do conteúdo do arquivo analisado' AFTER arquivo, ADD KEY idx_ged_analises_ia_hash (hash_arquivo, tipo, status)");
        if (!in_array('analise_origem', $colunas, true)) $pdo->exec("ALTER TABLE ged_analises_ia ADD COLUMN analise_origem BIGINT NULL COMMENT 'Análise cuja leitura da IA foi reaproveitada (mesmo documento)' AFTER referencia");

        // Hash das análises já feitas, lido dos arquivos do GED.
        $storage = new GedStorageService($pdo, $env, ['tenant' => ['banco_dados' => $banco]]);
        $calculados = 0; $naoEncontrados = 0;
        foreach ($pdo->query('SELECT codanalise, tipo, codigo_registro, arquivo FROM ged_analises_ia WHERE hash_arquivo IS NULL')->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $pasta = $pastas[$a['tipo']] ?? null;
            $caminho = $pasta ? $storage->resolve($pasta, (int)$a['codigo_registro'], (string)$a['arquivo']) : null;
            if ($caminho === null && $a['tipo'] === 'licencas') {
                $base = (int)$pdo->query('SELECT COALESCE(CODLICENCA_BASE, 0) FROM li_licencas WHERE CODLICENCA = ' . (int)$a['codigo_registro'])->fetchColumn();
                if ($base > 0) $caminho = $storage->resolve('licencas', $base, (string)$a['arquivo']);
            }
            if ($caminho === null) { $naoEncontrados++; continue; }
            $pdo->prepare('UPDATE ged_analises_ia SET hash_arquivo = ? WHERE codanalise = ?')->execute([hash_file('sha256', $caminho), $a['codanalise']]);
            $calculados++;
        }
        $detalhes = ['colunas' => ['hash_arquivo', 'analise_origem'], 'hashes_calculados' => $calculados, 'arquivos_nao_encontrados' => $naoEncontrados, 'reanalise' => 'somente ADM da empresa, com confirmação'];
        $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
            VALUES ('GREEN_GED_ANALISES_IA_DUPLICATAS_223', 'ged_analises_ia', 'ged_analises_ia.hash_arquivo', 'ESTRUTURA', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
            ->execute([$calculados, json_encode($detalhes, JSON_UNESCAPED_UNICODE)]);
        $resultado[$banco] = $detalhes;
    } catch (Throwable $e) {
        $ok = false;
        $resultado[$banco] = ['erro' => $e->getMessage()];
    }
}

echo json_encode(['ok' => $ok && $bancos !== [], 'migracao' => '223', 'bancos' => $resultado], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
