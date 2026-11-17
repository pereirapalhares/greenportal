<?php
declare(strict_types=1);

/*
 * Migração 311 — Home › Minhas pendências no padrão de grid (18/10/2026).
 * principal/home_pendencias.php ainda lia fo_fornecedores e fo_planodeacao com SQL direto; passa a ler duas views
 * (regra do projeto: acesso ao banco só por procedures e views):
 *  - vw_homologacao_pendencias_fornecedor_responsavel: fornecedores ativos em que a pessoa é o responsável pelo fornecedor
 *    ou pela integração (PAPEL = FORNECEDOR | INTEGRACAO), com a data da próxima avaliação;
 *  - vw_homologacao_pendencias_planoacao_interno: ações do plano de ação do fornecedor em que a pessoa é a responsável.
 * Só views (não mexe em dados). Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-311-pendencias-home-views.php [--simular] [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$simular = in_array('--simular', $argv ?? [], true);
$soBanco = null;
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--banco=')) $soBanco = array_filter(array_map('trim', explode(',', substr($a, 8))));
$env = GreenPortalDatabase::env();
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$precisa = ['fo_fornecedores', 'fo_planodeacao'];
$lista = "'" . implode("','", $precisa) . "'";
$bancos = $is->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ($lista) AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = " . count($precisa) . " ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
if ($soBanco) $bancos = array_values(array_intersect($bancos, $soBanco));

$nome = "COALESCE(NULLIF(TRIM(f.`nomefantasia`), ''), NULLIF(TRIM(f.`razaosocial`), ''), CONCAT('Fornecedor ', f.`codfornecedor`))";
$viewFornecedor = "CREATE OR REPLACE VIEW `vw_homologacao_pendencias_fornecedor_responsavel` AS
SELECT f.`codusuario_responsavel_fornecedor` AS `CODUSUARIO`, f.`codfornecedor` AS `CODFORNECEDOR`, $nome AS `FORNECEDOR`,
       'FORNECEDOR' AS `PAPEL`, CASE WHEN f.`data_da_proxima_avaliacao` > '1900-01-01' THEN f.`data_da_proxima_avaliacao` END AS `DATA_PROXIMA_AVALIACAO`
  FROM `fo_fornecedores` f
 WHERE COALESCE(f.`codusuario_responsavel_fornecedor`, 0) > 0 AND f.`ATIVADOFOR` = 'Sim'
UNION ALL
SELECT f.`codusuario_responsavel_integracao`, f.`codfornecedor`, $nome,
       'INTEGRACAO', CASE WHEN f.`data_da_proxima_avaliacao` > '1900-01-01' THEN f.`data_da_proxima_avaliacao` END
  FROM `fo_fornecedores` f
 WHERE COALESCE(f.`codusuario_responsavel_integracao`, 0) > 0 AND f.`ATIVADOFOR` = 'Sim'
   AND f.`codusuario_responsavel_integracao` <> COALESCE(f.`codusuario_responsavel_fornecedor`, 0)";
$viewPlano = "CREATE OR REPLACE VIEW `vw_homologacao_pendencias_planoacao_interno` AS
SELECT p.`codusuario` AS `CODUSUARIO`, p.`codplanoacao` AS `CODPLANOACAO`, p.`codfornecedor` AS `CODFORNECEDOR`, $nome AS `FORNECEDOR`,
       NULLIF(TRIM(p.`acao`), '') AS `ACAO`, CASE WHEN p.`datafinal` > '1900-01-01' THEN p.`datafinal` END AS `DATAFINAL`
  FROM `fo_planodeacao` p
  JOIN `fo_fornecedores` f ON f.`codfornecedor` = p.`codfornecedor`
 WHERE COALESCE(p.`codusuario`, 0) > 0";

$rel = ['ok' => true, 'migracao' => '311', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach ($bancos as $banco) {
    if ($simular) { $rel['bancos'][$banco] = 'views serão criadas'; continue; }
    try {
        $pdo = GreenPortalDatabase::connect($env, (string)$banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec($viewFornecedor);
        $pdo->exec($viewPlano);
        $f = (int)$pdo->query('SELECT COUNT(*) FROM vw_homologacao_pendencias_fornecedor_responsavel')->fetchColumn();
        $fu = (int)$pdo->query('SELECT COUNT(DISTINCT CODUSUARIO) FROM vw_homologacao_pendencias_fornecedor_responsavel')->fetchColumn();
        $p = (int)$pdo->query('SELECT COUNT(*) FROM vw_homologacao_pendencias_planoacao_interno')->fetchColumn();
        $pu = (int)$pdo->query('SELECT COUNT(DISTINCT CODUSUARIO) FROM vw_homologacao_pendencias_planoacao_interno')->fetchColumn();
        $rel['bancos'][$banco] = "fornecedores sob responsabilidade: $f de $fu responsável(is); ações de plano: $p de $pu responsável(is)";
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = 'ERRO: ' . $e->getMessage(); }
}
if (!$bancos) $rel['bancos'] = 'nenhum banco com as tabelas da homologação';
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
