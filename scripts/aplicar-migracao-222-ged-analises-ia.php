<?php
declare(strict_types=1);

/*
 * Migração 222 — histórico das análises de documentos por IA (aba "Análises de IA" e alertas da Gestão de Arquivos).
 * Cria ged_analises_ia e traz para ela as análises de funcionários já feitas (fo_funcionarios_documentos_ia).
 * Aplica em todos os bancos de clientes. Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-222-ged-analises-ia.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$sql = file_get_contents(dirname(__DIR__) . '/database/222_ged_analises_ia.sql');
if ($sql === false) throw new RuntimeException('Script SQL da migração 222 não encontrado.');

$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES
    WHERE TABLE_NAME IN ('alertas', 'registros_migracao') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 2 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);

$resultado = [];
$ok = true;
foreach ($bancos as $banco) {
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->exec($sql);
        $trazidas = 0;
        if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fo_funcionarios_documentos_ia'")->fetchColumn() > 0) {
            $trazidas = $pdo->exec("INSERT INTO ged_analises_ia (tipo, chave, codigo_registro, arquivo, status, resumo, dados_ia, confianca, fonte_texto, referencia, codusuario, codusuario_confirmacao, solicitado_em, concluido_em, confirmado_em)
                SELECT 'funcionarios', 'CODFUNCIONARIO', f.codfuncionario, f.arquivo, IF(f.status = 'PENDENTE', 'CONCLUIDA', f.status),
                       CONCAT_WS(' · ', f.tipo_documento, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(f.dados_ia, '$.aso_resultado')), 'null'), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(f.dados_ia, '$.aso_data_exame')), 'null'), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(f.dados_ia, '$.treinamento_nome')), 'null')),
                       f.dados_ia, f.confianca, f.fonte_texto, f.codanalise, f.codusuario_analise, f.codusuario_confirmacao, f.analisado_em, f.analisado_em, f.confirmado_em
                  FROM fo_funcionarios_documentos_ia f
                 WHERE NOT EXISTS (SELECT 1 FROM ged_analises_ia g WHERE g.tipo = 'funcionarios' AND g.referencia = f.codanalise)");
        }
        // Resumos trazidos na primeira execução com " · null" (campo vazio no JSON): limpa.
        $pdo->exec("UPDATE ged_analises_ia SET resumo = TRIM(TRAILING ' · ' FROM REPLACE(REPLACE(resumo, ' · null · ', ' · '), ' · null', '')) WHERE resumo LIKE '%null%'");
        $detalhes = ['tabela' => 'ged_analises_ia', 'analises_funcionarios_trazidas' => $trazidas, 'alertas' => 'início e resultado de cada análise'];
        $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
            VALUES ('GREEN_GED_ANALISES_IA_222', 'gestao_arquivos.php', 'ged_analises_ia;alertas', 'ESTRUTURA', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
            ->execute([$trazidas, json_encode($detalhes, JSON_UNESCAPED_UNICODE)]);
        $resultado[$banco] = $detalhes;
    } catch (Throwable $e) {
        $ok = false;
        $resultado[$banco] = ['erro' => $e->getMessage()];
    }
}

echo json_encode(['ok' => $ok && $bancos !== [], 'migracao' => '222', 'bancos' => $resultado], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
