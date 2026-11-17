<?php
declare(strict_types=1);

/*
 * Migração 383 — Manutenção Legal › Legislação: filtros Empresa e Unidade (17/11/2026).
 * Problema: sp_legislacao_select / sp_legislacao_count (glegislacao_dbp, compartilhado) liam o vínculo lei x empresa/unidade
 * fixo de gmodelo_dbp.legislacaoxempresa (na Crown o filtro Empresa trazia 0 leis em vez de 6.131) e conferiam empresa e
 * unidade em linhas diferentes. Agora a tela e a exportação filtram no banco da empresa logada (empresa e unidade na mesma
 * linha) e chamam sp_legislacao_select_ids / sp_legislacao_count_ids com a lista de leis.
 * As procedures antigas continuam no banco (sem uso) para não quebrar nada que ainda as chame.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-383-legislacao-filtro-empresa.php [--simular]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rel = ['ok' => true, 'migracao' => '383', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR'];
$def = static fn(string $p): string => (string)$pdo->query("SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = " . $pdo->quote($p))->fetchColumn();
$antiga = $def('sp_legislacao_select');
$rel['procedure_antiga'] = ['le_gmodelo_dbp' => str_contains($antiga, 'gmodelo_dbp'), 'filtros_iguais_aos_da_037' => str_contains($antiga, "'$.ASSUNTOS'") && str_contains($antiga, 'legislacaoxalteracao')];
if (!$rel['procedure_antiga']['filtros_iguais_aos_da_037']) $rel['aviso'] = 'a procedure atual não é a da migração 037: confira se algum filtro novo precisa ir para as *_ids';
if ($simular) {
    foreach (['sp_legislacao_select_ids', 'sp_legislacao_count_ids'] as $p) $rel['procedures'][$p] = $def($p) !== '' ? 'existe (será recriada)' : 'será criada';
} else {
    $n = 0;
    foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents(dirname(__DIR__) . '/database/383_legislacao_filtro_empresa.sql')) as $bloco) {
        $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
        if ($cmd === '') continue;
        $pdo->exec($cmd); $n++;
    }
    $rel['blocos'] = $n;
    foreach (['sp_legislacao_select_ids', 'sp_legislacao_count_ids'] as $p) { $ok = $def($p) !== ''; $rel['procedures'][$p] = $ok ? 'gravada' : 'FALTA'; if (!$ok) $rel['ok'] = false; }
    $s = $pdo->prepare('CALL sp_legislacao_count_ids(?, NULL, NULL, NULL, ?)'); $s->execute(['[1,2,3]', '{}']);
    $rel['teste_3_leis'] = (int)($s->fetch(PDO::FETCH_ASSOC)['total_registros'] ?? -1); $s->closeCursor();
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
