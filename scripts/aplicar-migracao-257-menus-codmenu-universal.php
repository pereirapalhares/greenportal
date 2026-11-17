<?php
declare(strict_types=1);

/*
 * Migração 257 — codmenu dos menus de cada cliente igual ao do catálogo universal (guniversal_dbp.menus).
 * O menu lateral vem do catálogo universal e casa as permissões da sessão pelo codmenu; quando um cliente usa o
 * mesmo codmenu para outra tela (ex.: gatlas_dbp 109 = RESPONSABILIDADE_SUBSTITUICAO, universal 109 =
 * LEVANTAMENTO_SOCIAL), a permissão de uma tela vale para a outra no menu.
 *
 * Em cada cliente (gatlas_dbp, gmodelo_dbp, gmodelo2_dbp):
 *  1. COLISÃO (mesmo codmenu, código diferente): o menu do cliente passa para o codmenu que o código tem no
 *     universal; se o código não existir no universal, é incluído lá com um codmenu livre (o mesmo em todos os
 *     clientes) e o cliente passa a usá-lo. Todas as tabelas do cliente com coluna codmenu (permissoes,
 *     perfil_menus, permissoes_campos, ...) e menus.codmenu_pai são ajustadas junto;
 *  2. inclui no cliente os menus ativos do universal que faltam (mesmo codmenu), sem nenhuma permissão.
 * Cópia: bkp257_<tabela> de todas as tabelas alteradas, em cada cliente, uma única vez.
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-257-menus-codmenu-universal.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-257-menus-codmenu-universal.php
 * Depois: rode de novo a migração 256 (acesso ao Levantamento Social). Os usuários veem no próximo login.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

const M257_CLIENTES = ['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'];

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$u = GreenPortalDatabase::connect($env, $bancoUniversal, 'GUNIVERSAL');
$u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$carregar = static function (PDO $pdo): array {
    $porCod = []; $porId = [];
    foreach ($pdo->query('SELECT * FROM menus')->fetchAll(PDO::FETCH_ASSOC) as $m) { $porCod[strtoupper((string)$m['codigo'])] = $m; $porId[(int)$m['codmenu']] = $m; }
    return [$porCod, $porId];
};
[$uniCod, $uniId] = $carregar($u);
$uniCols = array_column($u->query('SHOW COLUMNS FROM menus')->fetchAll(PDO::FETCH_ASSOC), 'Field');

$clientes = [];
foreach (M257_CLIENTES as $b) {
    try { $p = GreenPortalDatabase::connect($env, $b, 'GPT'); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $clientes[$b] = $p; } catch (Throwable) {}
}
// Próximo codmenu livre em todos os bancos
$livre = max(array_keys($uniId) ?: [0]);
foreach ($clientes as $p) $livre = max($livre, (int)$p->query('SELECT COALESCE(MAX(codmenu), 0) FROM menus')->fetchColumn());

$relatorio = ['ok' => true, 'migracao' => '257', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal_incluidos' => [], 'bancos' => []];

// 1a. Códigos em colisão que não existem no universal: incluídos no universal (codmenu novo, único)
$novosUniversal = [];
foreach ($clientes as $b => $p) {
    [$cliCod] = $carregar($p);
    foreach ($cliCod as $cod => $m) {
        $cm = (int)$m['codmenu'];
        if (isset($uniId[$cm]) && strtoupper((string)$uniId[$cm]['codigo']) !== $cod && !isset($uniCod[$cod]) && !isset($novosUniversal[$cod])) {
            $novosUniversal[$cod] = ['codmenu' => ++$livre, 'origem' => $b, 'linha' => $m];
        }
    }
}
foreach ($novosUniversal as $cod => $n) {
    $linha = array_intersect_key($n['linha'], array_flip($uniCols));
    $linha['codmenu'] = $n['codmenu'];
    // Pai: o mesmo código de pai que tem no cliente, se existir no universal
    if (!empty($linha['codmenu_pai'])) {
        [, $cliId] = $carregar($clientes[$n['origem']]);
        $codPai = strtoupper((string)($cliId[(int)$linha['codmenu_pai']]['codigo'] ?? ''));
        $linha['codmenu_pai'] = isset($uniCod[$codPai]) ? (int)$uniCod[$codPai]['codmenu'] : null;
    }
    $relatorio['universal_incluidos'][] = "$cod → codmenu {$n['codmenu']} (copiado de {$n['origem']})";
    if (!$simular) {
        $campos = array_keys($linha);
        $u->prepare('INSERT INTO menus (`' . implode('`, `', $campos) . '`) VALUES (' . implode(', ', array_fill(0, count($campos), '?')) . ')')->execute(array_values($linha));
    }
    $uniCod[$cod] = $linha; $uniId[$n['codmenu']] = $linha;
}

foreach ($clientes as $b => $p) {
    $info = ['renumerados' => [], 'incluidos' => [], 'tabelas_ajustadas' => []];
    try {
        [$cliCod, $cliId] = $carregar($p);
        $cols = array_column($p->query('SHOW COLUMNS FROM menus')->fetchAll(PDO::FETCH_ASSOC), 'Field');
        // Tabelas com coluna codmenu (menos cópias)
        $refs = $p->query("SELECT c.TABLE_NAME FROM information_schema.COLUMNS c JOIN information_schema.TABLES t USING (TABLE_SCHEMA, TABLE_NAME)
            WHERE c.TABLE_SCHEMA = DATABASE() AND LOWER(c.COLUMN_NAME) = 'codmenu' AND t.TABLE_TYPE = 'BASE TABLE' AND c.TABLE_NAME <> 'menus'
              AND c.TABLE_NAME NOT LIKE 'bkp%' AND c.TABLE_NAME NOT LIKE '%backup%'")->fetchAll(PDO::FETCH_COLUMN);

        // Plano de renumeração: codigo do cliente → codmenu do universal
        $plano = [];
        foreach ($cliCod as $cod => $m) {
            $atual = (int)$m['codmenu'];
            $alvo = isset($uniCod[$cod]) ? (int)$uniCod[$cod]['codmenu'] : null;
            if ($alvo !== null && $alvo !== $atual) $plano[$atual] = ['codigo' => $cod, 'para' => $alvo];
        }
                foreach ($plano as $de => $x) $info['renumerados'][] = "{$x['codigo']}: $de → {$x['para']}";
        $faltam = [];
        foreach ($uniCod as $cod => $m) if (!isset($cliCod[$cod]) && (int)($m['ativo'] ?? 1) === 1) $faltam[$cod] = $m;
        foreach ($faltam as $cod => $m) $info['incluidos'][] = "$cod (codmenu {$m['codmenu']})";

        if (!$simular && ($plano || $faltam)) {
            foreach (array_merge(['menus'], $refs) as $t) {
                $q = $p->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'); $q->execute(["bkp257_$t"]);
                if (!(int)$q->fetchColumn()) $p->exec("CREATE TABLE `bkp257_$t` AS SELECT * FROM `$t`");
            }
            $p->exec('SET FOREIGN_KEY_CHECKS = 0');
            $p->beginTransaction();
            try {
                // Passo 1: todos os que mudam vão para um codmenu temporário (900000 + atual), para não colidirem entre si
                foreach ($plano as $de => $x) {
                    $tmp = 900000 + $de;
                    $p->prepare('UPDATE menus SET codmenu = ? WHERE codmenu = ?')->execute([$tmp, $de]);
                    $p->prepare('UPDATE menus SET codmenu_pai = ? WHERE codmenu_pai = ?')->execute([$tmp, $de]);
                    foreach ($refs as $t) $p->prepare("UPDATE `$t` SET codmenu = ? WHERE codmenu = ?")->execute([$tmp, $de]);
                }
                // Passo 2: do temporário para o definitivo
                foreach ($plano as $de => $x) {
                    $tmp = 900000 + $de; $para = $x['para'];
                    $ocupado = (int)$p->query('SELECT COUNT(*) FROM menus WHERE codmenu = ' . (int)$para)->fetchColumn();
                    if ($ocupado) throw new RuntimeException("codmenu $para ocupado em $b por um código que não está no universal");
                    $p->prepare('UPDATE menus SET codmenu = ? WHERE codmenu = ?')->execute([$para, $tmp]);
                    $p->prepare('UPDATE menus SET codmenu_pai = ? WHERE codmenu_pai = ?')->execute([$para, $tmp]);
                    foreach ($refs as $t) {
                        $s = $p->prepare("UPDATE `$t` SET codmenu = ? WHERE codmenu = ?"); $s->execute([$para, $tmp]);
                        if ($s->rowCount()) $info['tabelas_ajustadas'][$t] = ($info['tabelas_ajustadas'][$t] ?? 0) + $s->rowCount();
                    }
                }
                // Menus que faltam no cliente (mesmo codmenu do universal), pai pelo código
                foreach ($faltam as $cod => $m) {
                    $linha = array_intersect_key($m, array_flip($cols));
                    if ((int)$p->query('SELECT COUNT(*) FROM menus WHERE codmenu = ' . (int)$linha['codmenu'])->fetchColumn()) {
                        $info['incluidos'][] = "$cod NAO INCLUIDO: codmenu {$linha['codmenu']} ocupado"; continue;
                    }
                    $p->prepare('INSERT INTO menus (`' . implode('`, `', array_keys($linha)) . '`) VALUES (' . implode(', ', array_fill(0, count($linha), '?')) . ')')->execute(array_values($linha));
                }
                $p->commit();
            } catch (Throwable $e) { if ($p->inTransaction()) $p->rollBack(); throw $e; }
            finally { $p->exec('SET FOREIGN_KEY_CHECKS = 1'); }
            $info['copia'] = 'bkp257_menus e bkp257_<tabelas com codmenu>';
        }
    } catch (Throwable $e) {
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $relatorio['bancos'][$b] = $info;
}
$relatorio['proximo_passo'] = 'Rode de novo: scripts\\aplicar-migracao-256-permissoes-levantamento-social.php';
$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents(dirname(__DIR__) . '/storage/logs/migracao_257_menus_codmenu' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
