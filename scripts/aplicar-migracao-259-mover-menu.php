<?php
declare(strict_types=1);

/*
 * Migração 259 — move um menu (com todos os submenus) para baixo de outro, no catálogo universal
 * (guniversal_dbp.menus, menu lateral) e nos menus de cada cliente (Segurança › Permissões).
 * Permissões não mudam. O menu entra por último entre os itens do novo pai (ordem = maior + 10), salvo --ordem.
 * Cópia: bkp259_menus em cada banco (uma única vez).
 *
 * Uso (padrão: Portaria para baixo de Homologação de Fornecedores):
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-259-mover-menu.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-259-mover-menu.php
 * Opções: --menu=PORTARIA --para=HOMOLOGACAO_FORNECEDORES --ordem=NN
 * Os usuários veem o menu novo no próximo login.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$opcao = static function (string $nome, string $padrao) use ($argv): string {
    foreach ($argv ?? [] as $a) if (str_starts_with($a, "--$nome=")) return trim(substr($a, strlen($nome) + 3));
    return $padrao;
};
$simular = in_array('--simular', $argv ?? [], true);
$menu = strtoupper($opcao('menu', 'PORTARIA'));
$para = strtoupper($opcao('para', 'HOMOLOGACAO_FORNECEDORES'));
$ordemFixa = $opcao('ordem', '');

$env = GreenPortalDatabase::env();
$bancos = ['universal' => (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'gatlas_dbp' => 'gatlas_dbp', 'gmodelo_dbp' => 'gmodelo_dbp', 'gmodelo2_dbp' => 'gmodelo2_dbp'];
$relatorio = ['ok' => true, 'migracao' => '259', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'menu' => $menu, 'para' => $para, 'bancos' => []];

foreach ($bancos as $rotulo => $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, $rotulo === 'universal' ? 'GUNIVERSAL' : 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $busca = $pdo->prepare('SELECT codmenu, codmenu_pai, ordem, COALESCE(label_pt, label) AS rotulo FROM menus WHERE UPPER(codigo) = ?');
        $busca->execute([$menu]); $m = $busca->fetch(PDO::FETCH_ASSOC);
        $busca->execute([$para]); $p = $busca->fetch(PDO::FETCH_ASSOC);
        if (!$m || !$p) { $info['aviso'] = (!$m ? "$menu" : "$para") . ' não existe neste banco'; $relatorio['bancos'][$rotulo] = $info; continue; }
        // Não pode ir para baixo de um descendente dele mesmo
        $cursor = (int)$p['codmenu'];
        $paiDe = $pdo->prepare('SELECT codmenu_pai FROM menus WHERE codmenu = ?');
        for ($i = 0; $i < 20 && $cursor > 0; $i++) {
            if ($cursor === (int)$m['codmenu']) throw new RuntimeException("$para está dentro de $menu");
            $paiDe->execute([$cursor]); $cursor = (int)$paiDe->fetchColumn();
        }
        $ordem = $ordemFixa !== '' ? (int)$ordemFixa : (int)$pdo->query('SELECT COALESCE(MAX(ordem), 0) + 10 FROM menus WHERE codmenu_pai = ' . (int)$p['codmenu'] . ' AND codmenu <> ' . (int)$m['codmenu'])->fetchColumn();
        $filhos = $pdo->query('SELECT COALESCE(label_pt, label) FROM menus WHERE codmenu_pai = ' . (int)$m['codmenu'] . ' ORDER BY ordem')->fetchAll(PDO::FETCH_COLUMN);
        $info = ['antes' => 'pai ' . ((int)$m['codmenu_pai'] ?: 'raiz') . ', ordem ' . $m['ordem'], 'depois' => "pai {$p['codmenu']} ({$p['rotulo']}), ordem $ordem", 'submenus_que_vao_junto' => $filhos];
        if (!$simular) {
            if (!(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bkp259_menus'")->fetchColumn()) {
                $pdo->exec('CREATE TABLE bkp259_menus AS SELECT * FROM menus');
            }
            $cols = array_column($pdo->query('SHOW COLUMNS FROM menus')->fetchAll(PDO::FETCH_ASSOC), 'Field');
            $set = 'codmenu_pai = ?, ordem = ?' . (in_array('nivel', $cols, true) ? ', nivel = (SELECT n FROM (SELECT COALESCE(nivel, 1) + 1 AS n FROM menus WHERE codmenu = ' . (int)$p['codmenu'] . ') x)' : '');
            $pdo->prepare("UPDATE menus SET $set WHERE codmenu = ?")->execute([(int)$p['codmenu'], $ordem, (int)$m['codmenu']]);
            $info['situacao'] = 'movido';
        }
    } catch (Throwable $e) {
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $relatorio['bancos'][$rotulo] = $info;
}
$relatorio['observacao'] = 'Os usuários veem o menu novo no próximo login.';
echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
