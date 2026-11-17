<?php
declare(strict_types=1);

/*
 * Migração 310 — Home › Minhas pendências da Homologação de Fornecedores (comparação com o legado da Crown, 18/10/2026).
 * Decisões do usuário:
 *  - documentos do fornecedor em que a pessoa é a responsável interna: só os que pedem ação da empresa —
 *    fornecedor ativo, documento visível na aba Documentos (tipo no fornecedor e critério no tipo) e situação
 *    "Pendente com a empresa", sem situação, ou aprovado vencido/vencendo nos dias de reação (30 se não informado);
 *  - documentos de funcionário em que a pessoa é a responsável (aba "Documentação de Funcionários" da Home do legado):
 *    funcionários de fornecedores ativos, sem Desligado, Afastado e NAP, e só os que pedem ação (2ª decisão, 18/10):
 *    Atende = Não ou vazio, ou Sim vencido/vencendo em 30 dias (regra de cores do Painel).
 * Cria em cada banco de empresa com as tabelas fo_*: vw_homologacao_pendencias_documento_interno e
 * vw_homologacao_pendencias_funcionario_interno (lidas por principal/home_pendencias.php via sp_crud_select).
 * Só views (não mexe em dados). Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-310-pendencias-homologacao-home.php [--simular] [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$simular = in_array('--simular', $argv ?? [], true);
$soBanco = null;
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--banco=')) $soBanco = array_filter(array_map('trim', explode(',', substr($a, 8))));
$env = GreenPortalDatabase::env();
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$precisa = ['fo_fornecedorxcriterios', 'fo_fornecedores', 'fo_criteriofornecedores', 'fo_fornecedoresxtipofornecedores', 'fo_tipofornecedoresxcriteriofornecedores', 'fo_funcionarioxtiposxcriterios', 'fo_funcionarios', 'fo_criteriofuncionarios', 'fo_tipofuncionariosxcriteriofuncionarios'];
$lista = "'" . implode("','", $precisa) . "'";
$bancos = $is->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ($lista) AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = " . count($precisa) . " ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
if ($soBanco) $bancos = array_values(array_intersect($bancos, $soBanco));

$viewDocumento = "CREATE OR REPLACE VIEW `vw_homologacao_pendencias_documento_interno` AS
SELECT x.`codusuario` AS `CODUSUARIO`, x.`codfornecedorxcriterios` AS `CODFORNECEDORXCRITERIOS`, x.`codfornecedor` AS `CODFORNECEDOR`,
       COALESCE(NULLIF(TRIM(f.`NOMEFANTASIA`), ''), NULLIF(TRIM(f.`RAZAOSOCIAL`), ''), CONCAT('Fornecedor ', x.`codfornecedor`)) AS `FORNECEDOR`,
       COALESCE(NULLIF(TRIM(c.`DSCRITERIO`), ''), CONCAT('Critério ', x.`codcriterio`)) AS `DSCRITERIO`,
       NULLIF(TRIM(x.`situacao`), '') AS `SITUACAO`,
       CASE WHEN x.`datadevalidade` > '1900-01-01' THEN x.`datadevalidade` END AS `DATADEVALIDADE`
  FROM `fo_fornecedorxcriterios` x
  JOIN `fo_fornecedores` f ON f.`CODFORNECEDOR` = x.`codfornecedor` AND f.`ATIVADOFOR` = 'Sim'
  LEFT JOIN `fo_criteriofornecedores` c ON c.`CODCRITERIOFORNECEDOR` = x.`codcriterio`
 WHERE COALESCE(x.`codusuario`, 0) > 0
   AND EXISTS (SELECT 1 FROM `fo_fornecedoresxtipofornecedores` ft WHERE ft.`codfornecedor` = x.`codfornecedor` AND ft.`codtipofornecedor` = x.`codtipo`)
   AND EXISTS (SELECT 1 FROM `fo_tipofornecedoresxcriteriofornecedores` tc WHERE tc.`codtipofornecedor` = x.`codtipo` AND tc.`codcriteriofornecedor` = x.`codcriterio`)
   AND (x.`situacao` = 'Pendente com a empresa'
        OR COALESCE(TRIM(x.`situacao`), '') = ''
        OR (x.`situacao` = 'Aprovado' AND x.`datadevalidade` > '1900-01-01'
            AND x.`datadevalidade` <= CURDATE() + INTERVAL COALESCE(NULLIF(CAST(x.`datadereacao` AS UNSIGNED), 0), 30) DAY))";
$viewFuncionario = "CREATE OR REPLACE VIEW `vw_homologacao_pendencias_funcionario_interno` AS
SELECT r.`codusuario` AS `CODUSUARIO`, r.`codigo` AS `CODIGO`, u.`CODFUNCIONARIO` AS `CODFUNCIONARIO`, TRIM(u.`NOME`) AS `FUNCIONARIO`,
       COALESCE(NULLIF(TRIM(f.`NOMEFANTASIA`), ''), NULLIF(TRIM(f.`RAZAOSOCIAL`), ''), CONCAT('Fornecedor ', f.`CODFORNECEDOR`)) AS `FORNECEDOR`,
       COALESCE(NULLIF(TRIM(c.`DSCRITERIO`), ''), CONCAT('Critério ', r.`codcriteriofuncionario`)) AS `DSCRITERIO`,
       NULLIF(TRIM(r.`atendeaocriterio`), '') AS `ATENDE`,
       CASE WHEN r.`datadevalidade` > '1900-01-01' THEN r.`datadevalidade` END AS `DATADEVALIDADE`
  FROM `fo_funcionarioxtiposxcriterios` r
  JOIN `fo_funcionarios` u ON u.`CODFUNCIONARIO` = r.`codfuncionario` AND TRIM(COALESCE(u.`NOME`, '')) <> '' AND r.`codtipofuncionario` = u.`CODTIPOFUNCIONARIO`
  JOIN `fo_fornecedores` f ON f.`CODFORNECEDOR` = u.`CODFORNECEDOR` AND f.`ATIVADOFOR` = 'Sim' AND COALESCE(f.`RAZAOSOCIAL`, '') NOT LIKE '%- migra%o 217'
  JOIN `fo_criteriofuncionarios` c ON c.`CODCRITERIOFUNCIONARIO` = r.`codcriteriofuncionario`
 WHERE EXISTS (SELECT 1 FROM `fo_tipofuncionariosxcriteriofuncionarios` tcf WHERE tcf.`codtipofuncionario` = u.`CODTIPOFUNCIONARIO` AND tcf.`codcriteriofuncionario` = r.`codcriteriofuncionario`)
   AND COALESCE(r.`codusuario`, 0) > 0
   AND COALESCE(r.`atendeaocriterio`, '') NOT IN ('Desligado', 'Afastado', 'NAP')
   AND (COALESCE(TRIM(r.`atendeaocriterio`), '') IN ('', 'Não')
        OR (r.`atendeaocriterio` = 'Sim' AND r.`datadevalidade` > '1900-01-01' AND r.`datadevalidade` <= CURDATE() + INTERVAL 30 DAY))";

$rel = ['ok' => true, 'migracao' => '310', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach ($bancos as $banco) {
    if ($simular) { $rel['bancos'][$banco] = 'views serão criadas'; continue; }
    try {
        $pdo = GreenPortalDatabase::connect($env, (string)$banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec($viewDocumento);
        $pdo->exec($viewFuncionario);
        $d = (int)$pdo->query('SELECT COUNT(*) FROM vw_homologacao_pendencias_documento_interno')->fetchColumn();
        $f = (int)$pdo->query('SELECT COUNT(*) FROM vw_homologacao_pendencias_funcionario_interno')->fetchColumn();
        $du = (int)$pdo->query('SELECT COUNT(DISTINCT CODUSUARIO) FROM vw_homologacao_pendencias_documento_interno')->fetchColumn();
        $fu = (int)$pdo->query('SELECT COUNT(DISTINCT CODUSUARIO) FROM vw_homologacao_pendencias_funcionario_interno')->fetchColumn();
        $rel['bancos'][$banco] = "documentos do fornecedor: $d pendência(s) de $du responsável(is); documentos de funcionário: $f de $fu responsável(is)";
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = 'ERRO: ' . $e->getMessage(); }
}
if (!$bancos) $rel['bancos'] = 'nenhum banco com as tabelas da homologação';
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
