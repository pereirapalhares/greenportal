<?php
declare(strict_types=1);

/*
 * Migração 389 — Legislações filhas: lista "Sugeridas" (17/11/2026). Banco glegislacao_dbp.
 * Cria sp_legislacao_sugestao_filhas e, se faltarem, os índices que ela usa (legislacao.CODAMBITO+CODTIPO,
 * legislacaoxassunto.CODASSUNTO/CODLEGISLACAO). No fim, mede o tempo em 3 leis pai (pode rodar de novo).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-389-legislacao-sugestao-filhas.php [--simular]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/LegislacaoAssociacoes.php';

$simular = in_array('--simular', $argv ?? [], true);
$rel = ['ok' => true, 'migracao' => '389', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR'];
try {
    $env = GreenPortalDatabase::env();
    $leg = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
    $leg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $indices = [
        ['legislacao', 'ix_leg_ambito_tipo', ['CODAMBITO', 'CODTIPO']],
        ['legislacaoxassunto', 'ix_lxa_assunto_lei', ['CODASSUNTO', 'CODLEGISLACAO']],
        ['legislacaoxassunto', 'ix_lxa_lei', ['CODLEGISLACAO']],
    ];
    foreach ($indices as [$tab, $nome, $cols]) {
        // já existe algum índice começando por essas colunas?
        $q = $leg->prepare("SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? GROUP BY INDEX_NAME");
        $q->execute([$tab]);
        $existe = '';
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if (stripos((string)$r['c'] . ',', implode(',', $cols) . ',') === 0) { $existe = (string)$r['INDEX_NAME']; break; }
        if ($existe !== '') { $rel['indices'][$nome] = "já coberto por $existe"; continue; }
        if ($simular) { $rel['indices'][$nome] = 'será criado'; continue; }
        $leg->exec("ALTER TABLE `$tab` ADD INDEX `$nome` (`" . implode('`, `', $cols) . "`)");
        $rel['indices'][$nome] = 'criado';
    }

    if ($simular) $rel['procedure'] = 'será criada/recriada: sp_legislacao_sugestao_filhas';
    else {
        $n = 0;
        foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents(dirname(__DIR__) . '/database/389_legislacao_sugestao_filhas.sql')) as $bloco) {
            $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
            if ($cmd === '') continue;
            $leg->exec($cmd); $n++;
        }
        $rel['procedure'] = ['blocos' => $n];

        // teste: as 3 leis pai com mais filhas (as filhas atuais ficam de fora); tempo da parte PHP e da procedure
        $t0 = microtime(true); LegislacaoAssociacoes::todosTipos(); $rel['segundos_catalogo_tipos'] = round(microtime(true) - $t0, 2);
        foreach ($leg->query('SELECT CODLEGISLACAO_PAI FROM legislacaopaixlegislacaofilha GROUP BY CODLEGISLACAO_PAI ORDER BY COUNT(*) DESC LIMIT 3')->fetchAll(PDO::FETCH_COLUMN) as $pai) {
            $pai = (int)$pai; $lei = LegislacaoAssociacoes::lei($pai);
            $t0 = microtime(true); $tr = LegislacaoAssociacoes::tiposRelacionados((int)($lei['CODTIPO'] ?? 0)); $tTipos = microtime(true) - $t0;
            $t0 = microtime(true); [$rows, $total] = LegislacaoAssociacoes::sugestoesFilhas($pai, 0, '', 1); $tTudo = microtime(true) - $t0;
            $rel['teste'][] = [
                'lei_pai' => $pai . ' — ' . LegislacaoAssociacoes::rotulo((array)$lei),
                'tipos_relacionados' => count($tr), 'segundos_php' => round($tTipos, 2), 'segundos_total' => round($tTudo, 2),
                'total_sugeridas' => $total,
                'primeiras' => array_map(static fn($r) => LegislacaoAssociacoes::rotulo($r) . ' | ' . $r['MOTIVO'] . ' | ' . $r['PONTOS'] . ' pts', array_slice($rows, 0, 3)),
            ];
        }
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['erro'] = $e->getMessage(); }
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
