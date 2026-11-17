<?php
declare(strict_types=1);

/*
 * Diagnóstico das NCs de Cliente (CODTIPONAOCONFORMIDADE = 2) — etapa 3c da reconstrução (21/10/2026). SOMENTE LEITURA.
 * O legado (nc_cliente/nc_s.php) usa o mesmo fluxo da Empresa (Em criação → Com o Gestor → Em Analise da Causa Raiz /
 * Plano de Ação → Avaliação da Eficacia → Finalizado) com os campos próprios do tipo: cliente (nc_clientes), unidade do
 * cliente (nc_unidadesdocliente), produto (nc_produtos), código do produto, tipo/nº de documento (nc_tipodedocumento),
 * tipo de tampa (nc_tipo_de_tampa), tipo de reclamação (nc_tipo_de_reclamacao), tamanho da lata (nc_tamanho_da_lata,
 * comentado no legado), afeta segurança de alimentos (SIM/NÃO), procedência (Procedente/Improcedente), quantidades.
 * Este diagnóstico confere nos dados como o tipo é usado de fato.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-nc-cliente.php [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$banco = 'gcrown_dbp';
foreach ($argv ?? [] as $a) if (preg_match('/^--banco=(g[a-z0-9_]+_dbp)$/', $a, $m)) $banco = $m[1];
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
$q = static function (string $sql, ?PDO $c = null) use ($pdo): array { try { return ($c ?? $pdo)->query($sql)->fetchAll(); } catch (Throwable $e) { return ['erro' => mb_substr($e->getMessage(), 0, 200)]; } };
$v = static function (string $sql) use ($q) { $r = $q($sql); return isset($r['erro']) ? $r['erro'] : ($r ? reset($r[0]) : null); };
$W = 'o.CODTIPONAOCONFORMIDADE = 2';
$AB = " AND COALESCE(o.ETAPA,'') <> 'Finalizado'";
$REC = " AND o.data_de_criacao >= '2024-01-01'";

$r = ['banco' => $banco, 'gerado_em' => date('c')];
$r['tipo'] = $q('SELECT * FROM nc_tipo_de_naoconformidade WHERE CODTIPONAOCONFORMIDADE = 2');
$r['volume'] = [
    'total' => (int)$v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W"),
    'abertas' => (int)$v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W$AB"),
    'por_ano' => $q("SELECT YEAR(o.data_de_criacao) AS ano, COUNT(*) AS n FROM nc_indicacao_ocorrencia o WHERE $W GROUP BY 1 ORDER BY 1 DESC LIMIT 6"),
    'ultima_criada' => $v("SELECT MAX(o.data_de_criacao) FROM nc_indicacao_ocorrencia o WHERE $W"),
    'etapas_abertas' => $q("SELECT o.ETAPA, COUNT(*) AS n, MAX(o.data_de_criacao) AS mais_recente FROM nc_indicacao_ocorrencia o WHERE $W$AB GROUP BY 1 ORDER BY n DESC"),
    'etapas_criadas_2025_2026' => $q("SELECT o.ETAPA, COUNT(*) AS n FROM nc_indicacao_ocorrencia o WHERE $W AND o.data_de_criacao >= '2025-01-01' GROUP BY 1 ORDER BY n DESC"),
    'finalizadas_por_situacao' => $q("SELECT o.SITUACAO, COUNT(*) AS n FROM nc_indicacao_ocorrencia o WHERE $W AND o.ETAPA = 'Finalizado' GROUP BY 1 ORDER BY n DESC LIMIT 10"),
];
$r['campos_configurados_tipo_2'] = $q("SELECT CAMPO FROM vw_nc_tipo_campos WHERE CODTIPONAOCONFORMIDADE = 2");
$r['campos_configurados_legado'] = $q("SELECT m.DSCCAMPO, m.LABEL FROM nc_tipo_de_naoconformidadexcampos t JOIN green_universal.md_tabelasxcampos m ON m.codcampo = t.codcampo WHERE t.CODTIPONAOCONFORMIDADE = 2 ORDER BY m.LABEL");

// Preenchimento dos campos (desde 2024)
$campos = [
    'CODUNIDADE' => 'o.CODUNIDADE > 0', 'CODAREA' => 'o.CODAREA > 0', 'CODSETOR' => 'o.CODSETOR > 0', 'CODTIPOOCORRENCIA' => 'o.CODTIPOOCORRENCIA > 0',
    'CODTIPOACAO' => 'o.CODTIPOACAO > 0', 'CODORIGEMACAO' => 'o.CODORIGEMACAO > 0', 'CODSIGLAGESTAO' => 'o.CODSIGLAGESTAO > 0',
    'LISTADEDISTRIBUICAO' => "o.LISTADEDISTRIBUICAO REGEXP '^[1-9]'", 'PARADANALINHA' => "COALESCE(o.PARADANALINHA,'') <> ''",
    'DSCCLIENTE' => "TRIM(COALESCE(o.DSCCLIENTE,'')) <> ''", 'codunidade_cliente' => 'o.codunidade_cliente > 0', 'PRODUTO' => "TRIM(COALESCE(o.PRODUTO,'')) <> ''",
    'CODIGODOPRODUTO' => "TRIM(COALESCE(o.CODIGODOPRODUTO,'')) <> ''", 'codtipodedocumento' => 'o.codtipodedocumento > 0', 'nrndocumento' => "TRIM(COALESCE(o.nrndocumento,'')) <> ''",
    'codtamanhodalata' => 'o.codtamanhodalata > 0', 'codtipodetampa' => 'o.codtipodetampa > 0', 'codtipodereclamacao' => 'o.codtipodereclamacao > 0',
    'afetasegurancaalimentos' => "COALESCE(o.afetasegurancaalimentos,'') <> ''", 'procedencia_cliente' => "COALESCE(o.procedencia_cliente,'') <> ''",
    'QTDENVOLVIDA' => "TRIM(COALESCE(o.QTDENVOLVIDA,'')) NOT IN ('', '0')", 'QTDDEVOLVIDA' => "TRIM(COALESCE(o.QTDDEVOLVIDA,'')) NOT IN ('', '0')",
    'TAMANHOLOTEENVOLVIDO' => "TRIM(COALESCE(o.TAMANHOLOTEENVOLVIDO,'')) <> ''", 'CODFORNECEDOR' => 'o.CODFORNECEDOR > 0',
    'HOUVE_RETENCAO_PRODUTO' => "COALESCE(o.houve_retencao_produto,'') <> ''", 'HAVERA_DEVOLUCAO_MATERIAL' => "COALESCE(o.havera_devolucao_material,'') <> ''",
    'PROVIDENCIA' => "TRIM(COALESCE(o.PROVIDENCIA,'')) <> ''", 'ANALISE_DA_CAUSA' => "TRIM(COALESCE(o.ANALISE_DA_CAUSA,'')) <> ''",
    'ANALISE_DA_PROVIDENCIA' => "TRIM(COALESCE(o.ANALISE_DA_PROVIDENCIA,'')) <> ''", 'ANALISE_ABRANGENCIA' => "TRIM(COALESCE(o.ANALISE_ABRANGENCIA,'')) <> ''",
    'improcedente_SIM' => "o.improcedente = 'SIM'", 'data_de_conhecimento_fornecedor' => 'o.data_de_conhecimento_fornecedor IS NOT NULL',
];
$r['preenchimento_desde_2024'] = ['base' => (int)$v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W$REC")];
foreach ($campos as $c => $cond) $r['preenchimento_desde_2024'][$c] = $v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W$REC AND $cond");

// Valores
$r['valores'] = [
    'afetasegurancaalimentos' => $q("SELECT o.afetasegurancaalimentos AS valor, COUNT(*) n FROM nc_indicacao_ocorrencia o WHERE $W GROUP BY 1 ORDER BY n DESC"),
    'procedencia_cliente' => $q("SELECT o.procedencia_cliente AS valor, COUNT(*) n FROM nc_indicacao_ocorrencia o WHERE $W GROUP BY 1 ORDER BY n DESC"),
    'improcedente' => $q("SELECT o.improcedente AS valor, COUNT(*) n FROM nc_indicacao_ocorrencia o WHERE $W GROUP BY 1 ORDER BY n DESC"),
    'PARADANALINHA' => $q("SELECT o.PARADANALINHA AS valor, COUNT(*) n FROM nc_indicacao_ocorrencia o WHERE $W GROUP BY 1 ORDER BY n DESC"),
    'classificacao_CODTIPOOCORRENCIA' => $q("SELECT o.CODTIPOOCORRENCIA, t.DSCTIPOOCORRENCIA, COUNT(*) n FROM nc_indicacao_ocorrencia o LEFT JOIN nc_tipoocorrencia t ON t.CODTIPOOCORRENCIA = o.CODTIPOOCORRENCIA WHERE $W$REC GROUP BY 1, 2 ORDER BY n DESC LIMIT 15"),
    'tipo_de_reclamacao' => $q("SELECT o.codtipodereclamacao, t.dsctipodereclamacao, COUNT(*) n FROM nc_indicacao_ocorrencia o LEFT JOIN nc_tipo_de_reclamacao t ON t.codtipodereclamacao = o.codtipodereclamacao WHERE $W$REC GROUP BY 1, 2 ORDER BY n DESC LIMIT 15"),
    'tipo_de_tampa' => $q("SELECT o.codtipodetampa, t.dsctipodetampa, COUNT(*) n FROM nc_indicacao_ocorrencia o LEFT JOIN nc_tipo_de_tampa t ON t.codtipodetampa = o.codtipodetampa WHERE $W$REC GROUP BY 1, 2 ORDER BY n DESC LIMIT 15"),
    'tipo_de_documento' => $q("SELECT o.codtipodedocumento, t.dsctipodedocumento, COUNT(*) n FROM nc_indicacao_ocorrencia o LEFT JOIN nc_tipodedocumento t ON t.codtipodedocumento = o.codtipodedocumento WHERE $W$REC GROUP BY 1, 2 ORDER BY n DESC LIMIT 15"),
    'unidade_do_cliente' => $q("SELECT o.codunidade_cliente, t.dscunidade, COUNT(*) n FROM nc_indicacao_ocorrencia o LEFT JOIN nc_unidadesdocliente t ON t.codunidade = o.codunidade_cliente WHERE $W$REC GROUP BY 1, 2 ORDER BY n DESC LIMIT 15"),
    'clientes_mais_usados' => $q("SELECT o.DSCCLIENTE, COUNT(*) n, (SELECT COUNT(*) FROM nc_clientes c WHERE c.dsccliente = o.DSCCLIENTE) AS no_cadastro FROM nc_indicacao_ocorrencia o WHERE $W$REC GROUP BY 1 ORDER BY n DESC LIMIT 15"),
    'clientes_fora_do_cadastro_desde_2024' => $v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W$REC AND TRIM(COALESCE(o.DSCCLIENTE,'')) <> '' AND NOT EXISTS (SELECT 1 FROM nc_clientes c WHERE c.dsccliente = o.DSCCLIENTE)"),
    'produtos_mais_usados' => $q("SELECT o.PRODUTO, COUNT(*) n, (SELECT COUNT(*) FROM nc_produtos p WHERE p.dscproduto = o.PRODUTO) AS no_cadastro FROM nc_indicacao_ocorrencia o WHERE $W$REC GROUP BY 1 ORDER BY n DESC LIMIT 15"),
    'produtos_fora_do_cadastro_desde_2024' => $v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W$REC AND TRIM(COALESCE(o.PRODUTO,'')) <> '' AND NOT EXISTS (SELECT 1 FROM nc_produtos p WHERE p.dscproduto = o.PRODUTO)"),
    'amostra_qtd' => $q("SELECT o.QTDENVOLVIDA, o.QTDDEVOLVIDA, o.TAMANHOLOTEENVOLVIDO, o.CODIGODOPRODUTO, o.nrndocumento FROM nc_indicacao_ocorrencia o WHERE $W ORDER BY o.CODOCORRENCIA DESC LIMIT 8"),
];
// Cadastros auxiliares
$r['cadastros'] = [];
foreach (['nc_clientes', 'nc_unidadesdocliente', 'nc_produtos', 'nc_tipodedocumento', 'nc_tipo_de_tampa', 'nc_tipo_de_reclamacao', 'nc_tamanho_da_lata'] as $t) {
    $cols = $q("SELECT COLUMN_NAME, DATA_TYPE, COLUMN_KEY, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' ORDER BY ORDINAL_POSITION");
    $r['cadastros'][$t] = $cols ? ['linhas' => $v("SELECT COUNT(*) FROM `$t`"), 'colunas' => array_map(static fn($c) => $c['COLUMN_NAME'] . ' ' . $c['DATA_TYPE'] . ($c['COLUMN_KEY'] ? ' ' . $c['COLUMN_KEY'] : '') . ($c['EXTRA'] ? ' ' . $c['EXTRA'] : ''), $cols), 'amostra' => $q("SELECT * FROM `$t` LIMIT 5")] : 'tabela não existe';
}
$r['cadastros_no_greenportal'] = $q("SELECT url, codigo FROM menus WHERE url LIKE '%cliente%' OR url LIKE '%tampa%' OR url LIKE '%reclama%' OR url LIKE '%produto%' OR codigo LIKE '%CLIENTE%'");

// Papéis
$r['papeis'] = [
    'com_gestor' => (int)$v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W AND o.CODUSUARIO_GESTOR > 0"),
    'com_responsavel' => (int)$v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W AND o.CODUSUARIO_RESPONSAVEL > 0"),
    'perfis_emitente_desde_2024' => $q("SELECT COALESCE(p.codigo,'(sem perfil)') AS perfil, COUNT(*) AS n FROM nc_indicacao_ocorrencia o LEFT JOIN usuarios u ON u.codusuario = o.CODUSUARIO_EMISSOR LEFT JOIN perfis p ON p.codperfil = u.codperfil WHERE $W$REC GROUP BY 1 ORDER BY n DESC"),
    'perfis_gestor_desde_2024' => $q("SELECT COALESCE(p.codigo,'(sem perfil)') AS perfil, COUNT(*) AS n FROM nc_indicacao_ocorrencia o LEFT JOIN usuarios u ON u.codusuario = o.CODUSUARIO_GESTOR LEFT JOIN perfis p ON p.codperfil = u.codperfil WHERE $W$REC GROUP BY 1 ORDER BY n DESC"),
    'perfis_responsavel_desde_2024' => $q("SELECT COALESCE(p.codigo,'(sem perfil)') AS perfil, COUNT(*) AS n FROM nc_indicacao_ocorrencia o LEFT JOIN usuarios u ON u.codusuario = o.CODUSUARIO_RESPONSAVEL LEFT JOIN perfis p ON p.codperfil = u.codperfil WHERE $W$REC AND o.CODUSUARIO_RESPONSAVEL > 0 GROUP BY 1 ORDER BY n DESC"),
    'responsavel_e_usuario_de_fornecedor_desde_2024' => $v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W$REC AND o.CODUSUARIO_RESPONSAVEL IN (SELECT codusuario FROM fo_fornecedorxusuario)"),
    'emitentes_distintos_desde_2024' => $v("SELECT COUNT(DISTINCT o.CODUSUARIO_EMISSOR) FROM nc_indicacao_ocorrencia o WHERE $W$REC"),
    'gestores_distintos_desde_2024' => $v("SELECT COUNT(DISTINCT o.CODUSUARIO_GESTOR) FROM nc_indicacao_ocorrencia o WHERE $W$REC"),
    'perfis_com_nome_de_cliente' => $q("SELECT codigo, (SELECT COUNT(*) FROM usuarios u WHERE u.codperfil = p.codperfil) usuarios FROM perfis p WHERE codigo LIKE '%CLIENT%'"),
];
// Causas, ações e eficácia
$r['analise'] = [
    'ncs_com_causa' => (int)$v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W AND EXISTS (SELECT 1 FROM nc_causas c WHERE c.CODOCORRENCIA = o.CODOCORRENCIA)"),
    'ncs_com_acao' => (int)$v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W AND EXISTS (SELECT 1 FROM nc_planodeacao p WHERE p.CODOCORRENCIA = o.CODOCORRENCIA)"),
    'acoes_sem_causa' => (int)$v("SELECT COUNT(*) FROM nc_planodeacao p JOIN nc_indicacao_ocorrencia o ON o.CODOCORRENCIA = p.CODOCORRENCIA WHERE $W AND COALESCE(p.CODCAUSA,0) = 0"),
    'abertas_por_etapa_com_causa_e_acao' => $q("SELECT o.ETAPA, COUNT(*) n, SUM(EXISTS (SELECT 1 FROM nc_causas c WHERE c.CODOCORRENCIA = o.CODOCORRENCIA)) com_causa, SUM(EXISTS (SELECT 1 FROM nc_planodeacao p WHERE p.CODOCORRENCIA = o.CODOCORRENCIA)) com_acao FROM nc_indicacao_ocorrencia o WHERE $W$AB GROUP BY 1 ORDER BY n DESC"),
    'pass_pela_nanalise_eficacia' => $q("SELECT o.pass_pela_nanalise_eficacia AS valor, COUNT(*) AS n FROM nc_indicacao_ocorrencia o WHERE $W GROUP BY 1"),
    'improcedentes_finalizadas_pelo_gestor' => $v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W AND o.SITUACAO LIKE '%improcedente%'"),
    'procedencia_x_improcedente' => $q("SELECT o.procedencia_cliente, o.improcedente, o.ETAPA = 'Finalizado' AS finalizada, COUNT(*) n FROM nc_indicacao_ocorrencia o WHERE $W$REC GROUP BY 1, 2, 3 ORDER BY n DESC LIMIT 12"),
];
// Ações ligadas pela causa (o legado confere o plano por CODCAUSA, não por CODOCORRENCIA)
$r['acoes_pela_causa'] = [
    'acoes' => $v("SELECT COUNT(*) FROM nc_planodeacao p JOIN nc_causas c ON c.CODCAUSA = p.CODCAUSA JOIN nc_indicacao_ocorrencia o ON o.CODOCORRENCIA = c.CODOCORRENCIA WHERE $W"),
    'acoes_com_codocorrencia_diferente' => $v("SELECT COUNT(*) FROM nc_planodeacao p JOIN nc_causas c ON c.CODCAUSA = p.CODCAUSA JOIN nc_indicacao_ocorrencia o ON o.CODOCORRENCIA = c.CODOCORRENCIA WHERE $W AND COALESCE(p.CODOCORRENCIA, 0) <> o.CODOCORRENCIA"),
    'valores_codocorrencia_nessas_acoes' => $q("SELECT CASE WHEN p.CODOCORRENCIA IS NULL THEN 'NULL' WHEN p.CODOCORRENCIA = 0 THEN '0' WHEN p.CODOCORRENCIA = o.CODOCORRENCIA THEN 'igual' ELSE 'outro' END AS codocorrencia, COUNT(*) n FROM nc_planodeacao p JOIN nc_causas c ON c.CODCAUSA = p.CODCAUSA JOIN nc_indicacao_ocorrencia o ON o.CODOCORRENCIA = c.CODOCORRENCIA WHERE $W GROUP BY 1"),
    'mesmo_caso_nos_outros_tipos' => $q("SELECT o.CODTIPONAOCONFORMIDADE, COUNT(*) acoes, SUM(COALESCE(p.CODOCORRENCIA, 0) <> o.CODOCORRENCIA) sem_codocorrencia FROM nc_planodeacao p JOIN nc_causas c ON c.CODCAUSA = p.CODCAUSA JOIN nc_indicacao_ocorrencia o ON o.CODOCORRENCIA = c.CODOCORRENCIA GROUP BY 1"),
    'abertas_por_etapa_com_acao_pela_causa' => $q("SELECT o.ETAPA, COUNT(DISTINCT o.CODOCORRENCIA) ncs, COUNT(p.CODPLANOACAO) acoes FROM nc_indicacao_ocorrencia o JOIN nc_causas c ON c.CODOCORRENCIA = o.CODOCORRENCIA LEFT JOIN nc_planodeacao p ON p.CODCAUSA = c.CODCAUSA WHERE $W$AB GROUP BY 1"),
    'vw_nc_plano_definicao' => (string)($q("SHOW CREATE VIEW vw_nc_plano")[0]['Create View'] ?? 'não existe'),
    'getStatusPlanoAcaoNC' => (string)($q("SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'getStatusPlanoAcaoNC'")[0]['ROUTINE_DEFINITION'] ?? ''),
];
$r['tabelas_reclamacao'] = $q("SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE (TABLE_NAME LIKE '%reclama%' OR TABLE_NAME LIKE '%tampa%' OR TABLE_NAME LIKE '%lata%') AND TABLE_SCHEMA IN (DATABASE(), 'green_crown', 'green_universal', 'guniversal_dbp')");
$r['views_do_greenportal_nc'] = $q("SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'vw\\_nc\\_%'");
// Outros bancos
$r['outros_bancos'] = [];
foreach (['gmodelo_dbp', 'gmodelo2_dbp', 'gatlas_dbp', 'gcrown_dbp', 'ggdamericana_dbp', 'gganchieta_dbp'] as $b) {
    try { $p = GreenPortalDatabase::connect($env, $b, 'GPT'); $r['outros_bancos'][$b] = $q("SELECT COUNT(*) total, SUM(COALESCE(ETAPA,'') <> 'Finalizado') abertas, MAX(data_de_criacao) ultima FROM nc_indicacao_ocorrencia WHERE CODTIPONAOCONFORMIDADE = 2", $p)[0] ?? null; }
    catch (Throwable $e) { $r['outros_bancos'][$b] = 'sem acesso'; }
}
$r['amostra_recentes'] = $q("SELECT o.CODOCORRENCIA, o.ETAPA, o.data_de_criacao, o.DSCCLIENTE, o.procedencia_cliente, o.afetasegurancaalimentos,
    (SELECT COUNT(*) FROM nc_causas c WHERE c.CODOCORRENCIA = o.CODOCORRENCIA) AS causas, (SELECT COUNT(*) FROM nc_planodeacao p WHERE p.CODOCORRENCIA = o.CODOCORRENCIA) AS acoes
    FROM nc_indicacao_ocorrencia o WHERE $W ORDER BY o.CODOCORRENCIA DESC LIMIT 10");

$json = json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'diagnostico_nc_cliente.json', $json);
echo $json . PHP_EOL;
