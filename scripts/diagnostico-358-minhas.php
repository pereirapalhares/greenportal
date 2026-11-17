<?php
declare(strict_types=1);

/*
 * Diagnóstico (só leitura) para o item 2 da análise do 01_Mudancas_Gerais: telas "Minhas" e "Minhas Ações".
 * Lê a definição atual das views e as colunas das tabelas usadas pelas novas telas, e a contagem de responsabilidades
 * por entidade. Não altera nada. Grava o resultado em storage/diagnostico/358_minhas_<banco>.txt.
 *
 * Uso: & $php scripts\diagnostico-358-minhas.php [--empresa=CROWN]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$empresa = 'CROWN';
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--empresa=')) $empresa = substr($a, 10);
$env = GreenPortalDatabase::env();
$uni = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$banco = '';
foreach (GreenPortalDatabase::call($uni, 'CALL sp_crud_select(:t,:f,NULL,1000,0)', ['t' => 'vw_empresas_login_migradas', 'f' => '{}']) as $e)
    if (strcasecmp((string)$e['codigo'], $empresa) === 0) $banco = (string)$e['banco_dados'];
if ($banco === '' || !preg_match('/^[A-Za-z0-9_]+$/', $banco)) exit("Empresa $empresa não encontrada.\n");
$pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$out = ["Diagnóstico 358 — banco $banco — " . date('Y-m-d H:i:s'), ''];
$q = static function (string $sql) use ($pdo): array { try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return [['ERRO' => $e->getMessage()]]; } };

$views = ['vw_modulolegal_requisitos_grid', 'vw_modulolegal_requisitos_situacao_grid', 'vw_licencas_condicionantes_grid',
    'vw_licencas_condicionantes_planodeacao_grid', 'vw_responsabilidade_fornecedores', 'vw_responsabilidade_funcionarios',
    'vw_homologacao_responsabilidade_fornecedor', 'vw_homologacao_pendencias_planoacao_interno', 'vw_nc_planodeacao',
    'vw_dashboard_planos_acao', 'vw_homologacao_fornecedores_grid', 'vw_homologacao_funcionarios_grid', 'vw_minhas_monitoramentos_grid'];
foreach ($views as $v) {
    $r = $q("SHOW CREATE VIEW `$v`");
    $out[] = "===== VIEW $v";
    $out[] = $r[0]['Create View'] ?? ($r[0]['ERRO'] ?? 'não existe');
    $out[] = '';
}
foreach (['legislacaoxsituacao', 'li_condicionantes', 'li_planodeacao', 'fo_planodeacao', 'nc_planodeacao', 'fo_fornecedores', 'fo_funcionarios', 'fo_fornecedorxusuario', 'responsabilidade_modulos'] as $t) {
    $out[] = "===== COLUNAS $t";
    foreach ($q("SHOW COLUMNS FROM `$t`") as $c) $out[] = isset($c['ERRO']) ? $c['ERRO'] : $c['Field'] . ' ' . $c['Type'];
    $out[] = '';
}
$out[] = '===== responsabilidade_modulos';
foreach ($q('SELECT CODMODULO, CODIGO, NOME, ATIVO FROM responsabilidade_modulos') as $r) $out[] = implode(' | ', $r);
$out[] = '';
$out[] = '===== atribuições ativas por entidade';
foreach ($q("SELECT ENTIDADE, TIPO_DESTINO, COUNT(*) N FROM responsabilidade_atribuicoes WHERE ATIVO=1 GROUP BY ENTIDADE, TIPO_DESTINO ORDER BY ENTIDADE") as $r) $out[] = implode(' | ', $r);
$out[] = '';
$out[] = '===== planos de ação (total / com responsável)';
foreach (['li_planodeacao', 'fo_planodeacao', 'nc_planodeacao'] as $t)
    foreach ($q("SELECT '$t' T, COUNT(*) N, SUM(CODUSUARIO IS NOT NULL AND CODUSUARIO<>0) COM_RESP FROM `$t`") as $r) $out[] = implode(' | ', $r);
$out[] = '';
$out[] = '===== menus "Minhas/Meus" e pais';
foreach ($q("SELECT m.codmenu, m.codigo, m.label_pt, m.url, p.codigo PAI, m.ordem, m.ativo FROM menus m LEFT JOIN menus p ON p.codmenu=m.codmenu_pai WHERE m.codigo LIKE 'MINH%' OR m.codigo LIKE 'MEU%' OR m.codigo IN ('HOME','MODULO_LEGAL','LICENCAS','MEDICAO_MONITORAMENTO','HOMOLOGACAO_FORNECEDORES','NAO_CONFORMIDADES') ORDER BY m.codigo") as $r) $out[] = implode(' | ', array_map('strval', $r));

$dir = dirname(__DIR__) . '/storage/diagnostico';
if (!is_dir($dir)) mkdir($dir, 0775, true);
$arq = $dir . "/358_minhas_$banco.txt";
file_put_contents($arq, implode("\n", $out) . "\n");
echo "OK: $arq (" . count($out) . " linhas)\n";
