<?php
declare(strict_types=1);

/*
 * Migração 306 — Requisitos legais de uma licença na mesma tela do Módulo Legal › Requisitos legais.
 * Manutenção legal › Licenças › "Requisitos e documentos" (principal/licenca_requisitos_documentos.php) passa a abrir a
 * tela de requisitos legais (classes/ModuloLegalRequisitosGrid.php) filtrada pela licença escolhida.
 * Em cada banco de empresa com o grid de requisitos e a tabela legislacaoxlicencas cria:
 *   vw_modulolegal_requisitos_licenca_grid = linhas do grid de requisitos (vw_modulolegal_requisitos_situacao_grid, ou
 *   vw_modulolegal_requisitos_filtro_grid sem a migração 284) + CODLICENCA, pelos vínculos requisito × unidade da licença.
 * Só cria view (não mexe em dados). Pode ser executada mais de uma vez; se a view do grid mudar, rodar de novo.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-306-requisitos-por-licenca.php [--simular] [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$simular = in_array('--simular', $argv ?? [], true);
$soBanco = null;
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--banco=')) $soBanco = array_filter(array_map('trim', explode(',', substr($a, 8))));
$env = GreenPortalDatabase::env();
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$linhas = static function (string $sql, array $p = []) use ($is): array { $s = $is->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$rel = ['ok' => true, 'migracao' => '306', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];

$bancos = array_column($linhas("SELECT DISTINCT v.TABLE_SCHEMA b FROM information_schema.VIEWS v JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = v.TABLE_SCHEMA AND t.TABLE_NAME = 'legislacaoxlicencas'
    WHERE v.TABLE_NAME = 'vw_modulolegal_requisitos_filtro_grid' AND v.TABLE_SCHEMA LIKE 'g%\\_dbp' ORDER BY 1"), 'b');
if ($soBanco) $bancos = array_values(array_intersect($bancos, $soBanco));
foreach ($bancos as $banco) {
    try {
        $temSituacao = (bool)$linhas("SELECT 1 FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'vw_modulolegal_requisitos_situacao_grid'", [$banco]);
        $base = $temSituacao ? 'vw_modulolegal_requisitos_situacao_grid' : 'vw_modulolegal_requisitos_filtro_grid';
        $colsBase = array_map('strtolower', array_column($linhas('SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$banco, $base]), 'c'));
        $colsVinc = array_map('strtolower', array_column($linhas("SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'legislacaoxlicencas'", [$banco]), 'c'));
        foreach (['codlegislacao', 'codunidade'] as $c) if (!in_array($c, $colsBase, true)) throw new RuntimeException("$base sem a coluna $c");
        foreach (['codlicenca', 'codlegislacao', 'codunidade'] as $c) if (!in_array($c, $colsVinc, true)) throw new RuntimeException("legislacaoxlicencas sem a coluna $c");
        if (in_array('codlicenca', $colsBase, true)) throw new RuntimeException("$base já tem CODLICENCA");
        $sql = "CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_licenca_grid` AS
 SELECT g.*, r.`CODLICENCA` AS `CODLICENCA`
   FROM `$base` g
   JOIN `legislacaoxlicencas` r ON r.`CODLEGISLACAO` = g.`CODLEGISLACAO` AND r.`codunidade` = g.`CODUNIDADE`";
        if ($simular) { $rel['bancos'][$banco] = "será criada sobre $base"; continue; }
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec($sql);
        $n = (int)$pdo->query('SELECT COUNT(*) FROM `vw_modulolegal_requisitos_licenca_grid`')->fetchColumn();
        $rel['bancos'][$banco] = "criada sobre $base ($n vínculo(s) requisito × licença)";
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = 'ERRO: ' . $e->getMessage(); }
}
if (!$bancos) $rel['bancos'] = 'nenhum banco com vw_modulolegal_requisitos_filtro_grid e legislacaoxlicencas';
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
