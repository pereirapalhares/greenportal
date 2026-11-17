<?php
declare(strict_types=1);

/*
 * Migração 304 — Administração › Empresas e migração volta a abrir.
 * "Menus de migracao" (ADMINISTRACAO_MIGRACAO_MENUS) era filho de "Empresas e migracao" (ADMINISTRACAO_EMPRESAS_MIGRACAO);
 * com um filho, o item pai vira pasta no menu do portal e a tela administracao_migracao_empresas.php não abre.
 * Passa "Menus de migracao" para o mesmo nível, logo abaixo de "Empresas e migracao", em todos os bancos que têm o item
 * (universal e clientes). A tela de empresas continua com o botão que abre os menus de migração.
 * O código (classes/EmpresaMigracaoService.php) passou a gravar o item assim, para a migração 266 não desfazer.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-304-menu-migracao-empresas.php [--simular]
 * Os usuários veem a mudança no próximo login.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$universal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$rel = ['ok' => true, 'migracao' => '304', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
// Só bancos com a tabela menus no formato do portal (codigo, codmenu_pai, nivel, ordem)
$bancos = $is->query("SELECT TABLE_SCHEMA FROM COLUMNS WHERE TABLE_NAME = 'menus' AND TABLE_SCHEMA LIKE 'g%\\_dbp' AND COLUMN_NAME IN ('codigo', 'codmenu_pai', 'nivel', 'ordem') GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT COLUMN_NAME) = 4 ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
foreach ($bancos as $banco) {
    try {
        $pdo = GreenPortalDatabase::connect($env, (string)$banco, $banco === $universal ? 'GUNIVERSAL' : 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $um = static function (string $sql, array $p = []) use ($pdo): ?array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetch(PDO::FETCH_ASSOC) ?: null; $s->closeCursor(); return $r; };
        $pai = $um("SELECT codmenu, codmenu_pai, ordem, nivel FROM menus WHERE codigo = 'ADMINISTRACAO_EMPRESAS_MIGRACAO' LIMIT 1");
        $filho = $um("SELECT codmenu, codmenu_pai, ordem, nivel FROM menus WHERE codigo = 'ADMINISTRACAO_MIGRACAO_MENUS' LIMIT 1");
        if (!$pai || !$filho) continue;
        if ((int)$filho['codmenu_pai'] !== (int)$pai['codmenu']) { $rel['bancos'][$banco] = 'já estava no mesmo nível'; continue; }
        $novo = ['codmenu_pai' => (int)$pai['codmenu_pai'], 'nivel' => (int)$pai['nivel'], 'ordem' => (int)$pai['ordem'] + 1];
        if (!$simular) $pdo->prepare('UPDATE menus SET codmenu_pai = ?, nivel = ?, ordem = ? WHERE codmenu = ?')->execute([$novo['codmenu_pai'], $novo['nivel'], $novo['ordem'], (int)$filho['codmenu']]);
        $rel['bancos'][$banco] = ($simular ? 'será movido' : 'movido') . " (codmenu {$filho['codmenu']}: pai {$filho['codmenu_pai']} → {$novo['codmenu_pai']}, nível {$filho['nivel']} → {$novo['nivel']})";
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = 'ERRO: ' . $e->getMessage(); }
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
