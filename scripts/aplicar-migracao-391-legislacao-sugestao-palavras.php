<?php
declare(strict_types=1);

/*
 * Migração 391 — Palavras-chave da lei: lista "Sugeridas" (17/11/2026). Banco glegislacao_dbp.
 * Cria sp_legislacao_sugestao_palavras e o índice legislacaoxpalavraschave(codlegislacao) se faltar; testa em 3 leis.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-391-legislacao-sugestao-palavras.php [--simular]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/LegislacaoAssociacoes.php';

$simular = in_array('--simular', $argv ?? [], true);
$rel = ['ok' => true, 'migracao' => '391', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR'];
try {
    $env = GreenPortalDatabase::env();
    $leg = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
    $leg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $q = $leg->prepare("SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legislacaoxpalavraschave' GROUP BY INDEX_NAME");
    $q->execute(); $existe = '';
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if (stripos((string)$r['c'] . ',', 'codlegislacao,') === 0) $existe = (string)$r['INDEX_NAME'];
    if ($existe !== '') $rel['indice'] = "já coberto por $existe";
    elseif ($simular) $rel['indice'] = 'será criado: ix_lxp_lei (codlegislacao)';
    else { $leg->exec('ALTER TABLE `legislacaoxpalavraschave` ADD INDEX `ix_lxp_lei` (`codlegislacao`)'); $rel['indice'] = 'criado: ix_lxp_lei'; }

    if ($simular) $rel['procedure'] = 'será criada/recriada: sp_legislacao_sugestao_palavras';
    else {
        $n = 0;
        foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents(dirname(__DIR__) . '/database/391_legislacao_sugestao_palavras.sql')) as $bloco) {
            $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
            if ($cmd === '') continue;
            $leg->exec($cmd); $n++;
        }
        $rel['procedure'] = ['blocos' => $n];
        // teste: 3 leis com palavras-chave (as primeiras pelo código)
        $leis = $leg->query('SELECT codlegislacao FROM legislacaoxpalavraschave GROUP BY codlegislacao ORDER BY codlegislacao LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($leis as $lei) {
            $t0 = microtime(true);
            [$itens, $total] = LegislacaoAssociacoes::sugestoesPalavras((int)$lei, '', 1);
            $rel['teste'][] = ['lei' => (int)$lei . ' — ' . LegislacaoAssociacoes::rotulo((array)LegislacaoAssociacoes::lei((int)$lei)), 'segundos' => round(microtime(true) - $t0, 2), 'sugeridas' => $total,
                'primeiras' => array_map(static fn($i) => $i['texto'] . ' | ' . $i['motivo'], array_slice($itens, 0, 5))];
        }
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['erro'] = $e->getMessage(); }
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
