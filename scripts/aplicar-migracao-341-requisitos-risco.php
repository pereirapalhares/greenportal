<?php
declare(strict_types=1);

/*
 * Migração 341 — Requisitos legais: nível de atendimento (%) e risco (docs/Detalhamento Cálculo do Nível do Atendimento do
 * Requisito Legal.doc; análise no documento do projeto requisitos-atendimento-risco.md).
 *
 * Em cada empresa com a situação legal calculada (migração 284):
 *   1. colunas novas em situacao_legal_calculada (% de atendimento, risco, pendências críticas, itens sem risco, não se aplica);
 *   2. vw_ml_licenca_chave (licença -> licença original, para o risco valer em todas as versões) e vw_ml_risco_responsaveis
 *      (responsáveis de cada item, para destacar quem responde pelas pendências críticas), montadas conforme o banco;
 *   3. database/341_requisitos_atendimento_risco.sql — tabelas do risco por item e do "não se aplica", views, procedures
 *      sp_ml_risco_salvar e sp_ml_requisito_na, e o novo cálculo (sp_situacao_legal_calcular_alvo);
 *   4. critérios de severidade e probabilidade (ml_risco_criterios);
 *   5. recálculo completo.
 * No universal: texto da Ajuda de Requisitos legais e notas nas ajudas das telas dos pilares (database/341_ajuda_requisitos.php).
 *
 * Os itens já cadastrados ficam com "Risco não avaliado" até alguém informar severidade e probabilidade (nada é suposto).
 * Rodar a 284 de novo depois desta reaplica a 341 automaticamente.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-341-requisitos-risco.php [--simular] [--sem-calculo] [--banco=gatlas_dbp]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/Migracao341RequisitosRisco.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$semCalculo = in_array('--sem-calculo', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);

$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$relatorio = ['ok' => true, 'migracao' => '341', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];

$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes', 'usuarios') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && preg_match('/^g[a-z0-9_]+_dbp$/', (string)$b) && ($soBanco === null || $b === $soBanco)));

foreach ($bancos as $banco) {
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (!MigracaoCrud::existe($pdo, 'legislacaoxempresa')) { $relatorio['bancos'][$banco] = ['ignorado' => 'sem módulo legal (legislacaoxempresa)']; continue; }
        $relatorio['bancos'][$banco] = Migracao341RequisitosRisco::aplicar($pdo, $simular, !$semCalculo);
    } catch (Throwable $e) {
        $relatorio['ok'] = false;
        $relatorio['bancos'][$banco] = ['erro' => $e->getMessage()];
    }
}

try {
    $u = GreenPortalDatabase::connect($env, $bancoUniversal, 'GPT');
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $relatorio['universal'] = ['ajuda' => Migracao341RequisitosRisco::gravarAjuda($u, $simular)];
} catch (Throwable $e) {
    $relatorio['ok'] = false;
    $relatorio['universal'] = ['erro' => $e->getMessage()];
}

echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
