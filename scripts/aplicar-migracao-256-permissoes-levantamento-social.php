<?php
declare(strict_types=1);

/*
 * Migração 256 — acesso ao Levantamento Social igual ao do Levantamento Ambiental.
 * Para cada perfil, copia as permissões (permissoes e perfil_menus: todas as colunas pode_*) da tela do Levantamento
 * Ambiental para a tela equivalente do Levantamento Social. Quem não tem acesso no Ambiental não ganha no Social;
 * permissões que já existem no Social são igualadas às do Ambiental. ADMINISTRADOR tem acesso por regra.
 *
 * Equivalências (Ambiental → Social):
 *   LEVANTAMENTO_AMBIENTAL → LEVANTAMENTO_SOCIAL      LAIA → LAIS
 *   LAIA_ATIVIDADES → LAIS_ATIVIDADES                 ASPECTOS → LAIS_ASPECTOS_SOCIAIS
 *   IMPACTOS → LAIS_IMPACTOS_SOCIAIS                  LAIA_DESTINOS → LAIS_PARTES_INTERESSADAS (cadastro auxiliar)
 *   LAIA_REVISAO → LAIS_REVISOES                      CORRELACIONAMENTO_AMBIENTAL → CORRELACIONAMENTO_SOCIAL
 *   LAIA_REQUISITOS_LEGAIS → LAIS_REQUISITOS_LEGAIS   LAIA_CONTROLES_DOCUMENTOS → LAIS_CONTROLES_DOCUMENTOS
 *   LAIA_OBJETIVOS_METAS → LAIS_OBJETIVOS_METAS
 * Antes, inclui no menu do cliente as telas do Social (e seus grupos) que existem no catálogo universal e faltam lá.
 * Cópia de segurança: bkp256_permissoes e bkp256_perfil_menus em cada cliente (uma única vez).
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-256-permissoes-levantamento-social.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-256-permissoes-levantamento-social.php
 * Opção: --banco=gatlas_dbp (só um cliente). Os usuários veem a mudança no próximo login.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

const M256_MAPA = [
    'LEVANTAMENTO_AMBIENTAL' => 'LEVANTAMENTO_SOCIAL', 'LAIA' => 'LAIS', 'LAIA_ATIVIDADES' => 'LAIS_ATIVIDADES',
    'ASPECTOS' => 'LAIS_ASPECTOS_SOCIAIS', 'IMPACTOS' => 'LAIS_IMPACTOS_SOCIAIS', 'LAIA_DESTINOS' => 'LAIS_PARTES_INTERESSADAS',
    'LAIA_REVISAO' => 'LAIS_REVISOES', 'CORRELACIONAMENTO_AMBIENTAL' => 'CORRELACIONAMENTO_SOCIAL',
    'LAIA_REQUISITOS_LEGAIS' => 'LAIS_REQUISITOS_LEGAIS', 'LAIA_CONTROLES_DOCUMENTOS' => 'LAIS_CONTROLES_DOCUMENTOS',
    'LAIA_OBJETIVOS_METAS' => 'LAIS_OBJETIVOS_METAS',
];

$simular = in_array('--simular', $argv ?? [], true);
$somente = '';
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--banco=')) $somente = trim(substr($a, 8));

$env = GreenPortalDatabase::env();
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 3 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
if ($somente !== '') $bancos = array_values(array_intersect($bancos, [$somente]));

// Menus do catálogo universal: as telas do Social que faltarem no menu do cliente (usado em Segurança › Permissões
// e nas permissões da sessão) são incluídas antes, com o mesmo codmenu.
$universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$universal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$catalogo = [];
foreach ($universal->query('SELECT * FROM menus')->fetchAll(PDO::FETCH_ASSOC) as $m) $catalogo[strtoupper((string)$m['codigo'])] = $m;
$porCodmenu = [];
foreach ($catalogo as $cod => $m) $porCodmenu[(int)$m['codmenu']] = $cod;
// Destinos e seus pais (até a raiz), na ordem pai → filho
$necessarios = [];
foreach (M256_MAPA as $destino) {
    $cadeia = []; $cod = $destino;
    while ($cod !== null && isset($catalogo[$cod])) { array_unshift($cadeia, $cod); $pai = (int)($catalogo[$cod]['codmenu_pai'] ?? 0); $cod = $pai > 0 ? ($porCodmenu[$pai] ?? null) : null; }
    foreach ($cadeia as $c) $necessarios[$c] = true;
}

$relatorio = ['ok' => true, 'migracao' => '256', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach ($bancos as $banco) {
    $info = ['telas' => []];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $menu = [];
        foreach ($pdo->query('SELECT codmenu, UPPER(codigo) AS codigo FROM menus')->fetchAll(PDO::FETCH_ASSOC) as $m) $menu[$m['codigo']] = (int)$m['codmenu'];
        // Menus que faltam no cliente (copiados do catálogo universal, mesmo codmenu)
        $colsMenu = array_column($pdo->query('SHOW COLUMNS FROM menus')->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $usados = array_flip(array_values($menu));
        foreach (array_keys($necessarios) as $cod) {
            if (isset($menu[$cod])) continue;
            $linha = array_intersect_key($catalogo[$cod], array_flip($colsMenu));
            if (isset($usados[(int)$linha['codmenu']])) { $info['menus_incluidos'][$cod] = 'NAO INCLUIDO: codmenu ' . $linha['codmenu'] . ' já usado por outro menu neste cliente'; continue; }
            if (!$simular) {
                $campos = array_keys($linha);
                $pdo->prepare('INSERT INTO menus (`' . implode('`, `', $campos) . '`) VALUES (' . implode(', ', array_fill(0, count($campos), '?')) . ')')->execute(array_values($linha));
            }
            $menu[$cod] = (int)$linha['codmenu']; $usados[(int)$linha['codmenu']] = true;
            $info['menus_incluidos'][$cod] = 'INCLUIDO (codmenu ' . $linha['codmenu'] . ')';
        }
        $perfis = [];
        foreach ($pdo->query('SELECT codperfil, UPPER(codigo) AS codigo FROM perfis')->fetchAll(PDO::FETCH_ASSOC) as $p) $perfis[(int)$p['codperfil']] = $p['codigo'];
        $tabelas = [];
        foreach (['permissoes', 'perfil_menus'] as $t) {
            $q = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'); $q->execute([$t]);
            $cols = $q->fetchAll(PDO::FETCH_COLUMN);
            if ($cols) $tabelas[$t] = array_values(array_filter($cols, static fn($c) => str_starts_with(strtolower($c), 'pode_')));
        }
        if (!$simular) {
            foreach (array_keys($tabelas) as $t) {
                $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'); $q->execute(["bkp256_$t"]);
                if (!(int)$q->fetchColumn()) $pdo->exec("CREATE TABLE `bkp256_$t` AS SELECT * FROM `$t`");
            }
            $pdo->beginTransaction();
        }
        foreach (M256_MAPA as $origem => $destino) {
            if (!isset($menu[$origem], $menu[$destino])) { $info['telas']["$origem → $destino"] = 'menu inexistente neste cliente'; continue; }
            $resumo = [];
            foreach ($tabelas as $t => $flags) {
                if (!$flags) continue;
                $lista = '`' . implode('`, `', $flags) . '`';
                $q = $pdo->prepare("SELECT codperfil, $lista FROM `$t` WHERE codmenu = ?"); $q->execute([$menu[$origem]]);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $o) {
                    $codperfil = (int)$o['codperfil'];
                    $nome = $perfis[$codperfil] ?? "perfil $codperfil";
                    if ($nome === 'ADMINISTRADOR') continue;
                    $valores = array_map(static fn($c) => (int)($o[$c] ?? 0), $flags);
                    if (!array_sum($valores)) continue; // sem nenhuma ação no Ambiental: nada a copiar
                    $d = $pdo->prepare("SELECT $lista FROM `$t` WHERE codperfil = ? AND codmenu = ?"); $d->execute([$codperfil, $menu[$destino]]);
                    $atual = $d->fetch(PDO::FETCH_ASSOC);
                    if (!$atual) {
                        $resumo[$nome][$t] = 'INCLUIDA';
                        if (!$simular) $pdo->prepare("INSERT INTO `$t` (codperfil, codmenu, $lista) VALUES (?, ?" . str_repeat(', ?', count($flags)) . ')')
                            ->execute([$codperfil, $menu[$destino], ...$valores]);
                    } elseif (array_map(static fn($c) => (int)($atual[$c] ?? 0), $flags) !== $valores) {
                        $resumo[$nome][$t] = 'IGUALADA';
                        if (!$simular) $pdo->prepare("UPDATE `$t` SET " . implode(', ', array_map(static fn($c) => "`$c` = ?", $flags)) . ' WHERE codperfil = ? AND codmenu = ?')
                            ->execute([...$valores, $codperfil, $menu[$destino]]);
                    } else {
                        $resumo[$nome][$t] = 'JA ESTAVA IGUAL';
                    }
                }
            }
            $info['telas']["$origem → $destino"] = $resumo ?: 'nenhum perfil com acesso no Ambiental';
        }
        if (!$simular) $pdo->commit();
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $relatorio['bancos'][$banco] = $info;
}
$relatorio['observacao'] = 'Os usuários desses perfis veem a mudança no próximo login.';
$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents(dirname(__DIR__) . '/storage/logs/migracao_256_permissoes_levantamento_social' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
