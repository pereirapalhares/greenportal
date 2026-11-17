<?php
declare(strict_types=1);

/*
 * Migração 390 — Legislação: listas dos filtros das colunas só com os valores das leis filtradas (17/11/2026).
 * glegislacao_dbp: procedure sp_legislacao_opcoes_ids. No fim mede o tempo sem filtro.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-390-legislacao-opcoes-filtros.php [--simular]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$simular = in_array('--simular', $argv ?? [], true);
$rel = ['ok' => true, 'migracao' => '390', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR'];
try {
    $env = GreenPortalDatabase::env();
    $leg = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
    $leg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($simular) $rel['procedure'] = 'será criada/recriada: sp_legislacao_opcoes_ids';
    else {
        $n = 0;
        foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents(dirname(__DIR__) . '/database/390_legislacao_opcoes_filtros.sql')) as $bloco) {
            $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
            if ($cmd === '') continue;
            $leg->exec($cmd); $n++;
        }
        $rel['procedure'] = ['blocos' => $n];
        $t0 = microtime(true);
        $s = $leg->prepare('CALL sp_legislacao_opcoes_ids(NULL, NULL, NULL, NULL, ?)'); $s->execute(['{}']);
        $g = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) {} $s->closeCursor();
        $rel['teste_sem_filtro'] = ['combinacoes' => count($g), 'segundos' => round(microtime(true) - $t0, 2)];
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['erro'] = $e->getMessage(); }
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
