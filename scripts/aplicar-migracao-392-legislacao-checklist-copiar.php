<?php
declare(strict_types=1);

/*
 * Migração 392 — CheckList da lei: copiar o checklist de outra lei (17/11/2026). Banco glegislacao_dbp.
 * Cria a view vw_legislacao_checklist_resumo (leis que têm checklist e quantas perguntas).
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-392-legislacao-checklist-copiar.php [--simular]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$simular = in_array('--simular', $argv ?? [], true);
$rel = ['ok' => true, 'migracao' => '392', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR'];
try {
    $env = GreenPortalDatabase::env();
    $leg = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
    $leg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($simular) $rel['view'] = 'será criada/recriada: vw_legislacao_checklist_resumo';
    else {
        foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents(dirname(__DIR__) . '/database/392_legislacao_checklist_copiar.sql')) as $bloco) {
            $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
            if ($cmd !== '') $leg->exec($cmd);
        }
        $s = $leg->prepare('CALL sp_crud_select(?, ?, NULL, 5, 0)'); $s->execute(['vw_legislacao_checklist_resumo', '{}']);
        $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) {} $s->closeCursor();
        $s = $leg->prepare('CALL sp_crud_count(?, ?, NULL)'); $s->execute(['vw_legislacao_checklist_resumo', '{}']);
        $n = $s->fetch(PDO::FETCH_NUM); while ($s->nextRowset()) {} $s->closeCursor();
        $rel['view'] = ['leis_com_checklist' => (int)($n[0] ?? 0), 'exemplo' => array_map(static fn($x) => ($x['DSCTIPO'] ?? '') . ' ' . ($x['NR'] ?? '') . '/' . ($x['ANO'] ?? '') . ' — ' . $x['QTD_PERGUNTAS'] . ' perguntas', $r)];
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['erro'] = $e->getMessage(); }
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
