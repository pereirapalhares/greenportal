<?php
declare(strict_types=1);

/** Gera candidatos de correção/justificativa dos órfãos legais da Crown; não altera dados. */
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env = GreenPortalDatabase::env();
$target = GreenPortalDatabase::connect($env, 'gcrown_dbp', 'GPT');
$legacy = GreenPortalDatabase::connect($env, 'green_crown', 'LEGADO_MODELO');
$result = ['banco' => 'gcrown_dbp', 'gerado_em' => date(DATE_ATOM), 'condicionantes_sem_licenca' => [], 'responsaveis_inexistentes' => []];
$q = $target->query('SELECT c.* FROM li_condicionantes c LEFT JOIN li_licencas l ON l.CODLICENCA=c.CODLICENCA WHERE l.CODLICENCA IS NULL ORDER BY c.CODCONDICIONANTE');
foreach ($q as $row) {
    $legacyLicense = null;
    if (isset($row['CODLICENCA'])) {
        $s = $legacy->prepare('SELECT * FROM li_licencas WHERE CODLICENCA=? LIMIT 1'); $s->execute([(int)$row['CODLICENCA']]); $legacyLicense = $s->fetch(PDO::FETCH_ASSOC) ?: null; $s->closeCursor();
    }
    $result['condicionantes_sem_licenca'][] = ['codigo' => (int)($row['CODCONDICIONANTE'] ?? 0), 'codlicenca' => (int)($row['CODLICENCA'] ?? 0), 'descricao' => (string)($row['DSCCONDICIONANTE'] ?? ''), 'responsavel' => (int)($row['CODUSUARIO'] ?? 0), 'existe_no_legado' => $legacyLicense !== null, 'licenca_legado' => $legacyLicense ? ['descricao' => (string)($legacyLicense['DSCLICENCA'] ?? ''), 'numero' => (string)($legacyLicense['NUMERO_LICENCA'] ?? '')] : null, 'acao_sugerida' => $legacyLicense ? 'revisar_relacionamento_licenca' : 'justificar_ou_inativar'];
}
$q = $target->query('SELECT c.CODCONDICIONANTE,c.CODUSUARIO,u.nome FROM li_condicionantes c LEFT JOIN usuarios u ON u.codusuario=c.CODUSUARIO WHERE COALESCE(c.CODUSUARIO,0)>0 AND u.codusuario IS NULL GROUP BY c.CODCONDICIONANTE,c.CODUSUARIO,u.nome ORDER BY c.CODCONDICIONANTE');
foreach ($q as $row) $result['responsaveis_inexistentes'][] = ['condicionante' => (int)$row['CODCONDICIONANTE'], 'codusuario' => (int)$row['CODUSUARIO'], 'acao_sugerida' => 'associar_usuario_ativo_ou_remover_responsavel'];
$result['resumo'] = ['condicionantes' => count($result['condicionantes_sem_licenca']), 'responsaveis' => count($result['responsaveis_inexistentes']), 'automaticamente_corrigiveis' => 0, 'dependem_decisao' => count($result['condicionantes_sem_licenca']) + count($result['responsaveis_inexistentes'])];
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
file_put_contents($dir . DIRECTORY_SEPARATOR . 'analise-orfaos-crown.json', $json . PHP_EOL, LOCK_EX);
echo $json . PHP_EOL;
