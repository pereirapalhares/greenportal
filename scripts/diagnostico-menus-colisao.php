<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) — menus de cada cliente comparados com o catálogo universal (guniversal_dbp.menus).
 * O menu lateral usa o catálogo universal e casa as permissões da sessão pelo codmenu; por isso o mesmo codmenu
 * precisa ter o mesmo código nos dois lugares. Lista:
 *  - COLISAO: mesmo codmenu, código diferente (a permissão de um vale para o outro no menu lateral!);
 *  - CODMENU_DIFERENTE: mesmo código, codmenu diferente;
 *  - SO_NO_CLIENTE / SO_NO_UNIVERSAL.
 * Para cada caso mostra quantas permissões (pode_visualizar) o codmenu do cliente tem.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-menus-colisao.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$u = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$uni = []; $uniCod = [];
foreach ($u->query('SELECT codmenu, UPPER(codigo) codigo, COALESCE(label_pt, label) rotulo, url, ativo FROM menus')->fetchAll(PDO::FETCH_ASSOC) as $m) { $uni[(int)$m['codmenu']] = $m; $uniCod[$m['codigo']] = $m; }

$saida = ['diagnostico' => 'menus cliente x universal', 'bancos' => []];
foreach (['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'] as $b) {
    try { $p = GreenPortalDatabase::connect($env, $b, 'GPT'); } catch (Throwable) { continue; }
    $perm = [];
    foreach ($p->query('SELECT codmenu, COUNT(*) n FROM permissoes WHERE pode_visualizar = 1 GROUP BY codmenu')->fetchAll(PDO::FETCH_ASSOC) as $r) $perm[(int)$r['codmenu']] = (int)$r['n'];
    $r = ['COLISAO' => [], 'CODMENU_DIFERENTE' => [], 'SO_NO_CLIENTE' => [], 'SO_NO_UNIVERSAL' => []];
    $cli = [];
    foreach ($p->query('SELECT codmenu, UPPER(codigo) codigo, COALESCE(label_pt, label) rotulo, url, ativo FROM menus')->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $cm = (int)$m['codmenu']; $cli[$m['codigo']] = $m;
        $txt = "codmenu $cm: cliente {$m['codigo']} \"{$m['rotulo']}\" (perfis " . ($perm[$cm] ?? 0) . ')';
        if (isset($uni[$cm]) && $uni[$cm]['codigo'] !== $m['codigo']) $r['COLISAO'][] = $txt . " × universal {$uni[$cm]['codigo']} \"{$uni[$cm]['rotulo']}\"";
        elseif (!isset($uni[$cm]) && isset($uniCod[$m['codigo']])) $r['CODMENU_DIFERENTE'][] = $txt . " × universal codmenu {$uniCod[$m['codigo']]['codmenu']}";
        elseif (!isset($uniCod[$m['codigo']])) $r['SO_NO_CLIENTE'][] = $txt . ' ' . ($m['url'] ?? '');
    }
    foreach ($uniCod as $cod => $m) if (!isset($cli[$cod]) && (int)$m['ativo']) $r['SO_NO_UNIVERSAL'][] = "codmenu {$m['codmenu']}: $cod \"{$m['rotulo']}\"";
    $saida['bancos'][$b] = $r;
}
$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents(dirname(__DIR__) . '/storage/logs/diagnostico_menus_colisao.json', $json);
echo $json . PHP_EOL;
