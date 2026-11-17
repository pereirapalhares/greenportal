<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) — árvore de menus de um módulo no catálogo universal (guniversal_dbp.menus),
 * para estudar a organização do menu. Mostra cada item com código, rótulo, URL, ordem, nível e se está ativo,
 * e quantos perfis têm acesso em cada banco de cliente (permissoes.pode_visualizar).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-menus-modulo.php [--modulo=legal]
 *   --modulo: trecho do código ou do rótulo do menu raiz (padrão: "legal" → Módulo Legal e Manutenção Legal).
 * Saída: tela (árvore em texto) + storage/logs/diagnostico_menus_modulo.json
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$filtro = 'legal';
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--modulo=')) $filtro = strtolower(trim(substr($a, 9)));

$env = GreenPortalDatabase::env();
$u = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$cols = array_column($u->query('SHOW COLUMNS FROM menus')->fetchAll(PDO::FETCH_ASSOC), 'Field');
$sel = array_values(array_intersect(['codmenu', 'codmenu_pai', 'modulo', 'codigo', 'label', 'label_pt', 'url', 'nivel', 'ordem', 'icone', 'ativo', 'visivel', 'oculto'], $cols));
$menus = $u->query('SELECT ' . implode(', ', $sel) . ' FROM menus ORDER BY codmenu_pai, ordem, codmenu')->fetchAll(PDO::FETCH_ASSOC);
$filhos = [];
foreach ($menus as $m) $filhos[(int)($m['codmenu_pai'] ?? 0)][] = $m;

// Raízes: itens de nível 1 (sem pai) cujo código/rótulo contém o filtro
$raizes = array_filter($filhos[0] ?? [], static fn($m) => str_contains(strtolower((string)$m['codigo'] . ' ' . ($m['label_pt'] ?? $m['label'] ?? '')), $filtro));

// Acesso por banco
$acesso = [];
foreach (['gatlas_dbp', 'gmodelo_dbp'] as $b) {
    try {
        $p = GreenPortalDatabase::connect($env, $b, 'GPT');
        foreach ($p->query('SELECT m.codigo, COUNT(DISTINCT pm.codperfil) n FROM menus m JOIN permissoes pm ON pm.codmenu = m.codmenu AND pm.pode_visualizar = 1 GROUP BY m.codigo')->fetchAll(PDO::FETCH_ASSOC) as $r) $acesso[$b][strtoupper((string)$r['codigo'])] = (int)$r['n'];
    } catch (Throwable $e) { $acesso[$b] = ['erro' => $e->getMessage()]; }
}

$linhas = []; $arvore = [];
$montar = static function (array $m, int $nivel) use (&$montar, $filhos, $acesso, &$linhas): array {
    $cod = strtoupper((string)$m['codigo']);
    $item = ['codmenu' => (int)$m['codmenu'], 'codigo' => $cod, 'rotulo' => (string)($m['label_pt'] ?? $m['label'] ?? ''), 'url' => (string)($m['url'] ?? ''),
        'ordem' => (int)($m['ordem'] ?? 0), 'ativo' => (int)($m['ativo'] ?? 1), 'icone' => (string)($m['icone'] ?? '')];
    foreach (['visivel', 'oculto'] as $c) if (array_key_exists($c, $m)) $item[$c] = $m[$c];
    $item['perfis_com_acesso'] = ['gatlas_dbp' => $acesso['gatlas_dbp'][$cod] ?? 0, 'gmodelo_dbp' => $acesso['gmodelo_dbp'][$cod] ?? 0];
    $linhas[] = str_repeat('    ', $nivel) . ($item['ativo'] ? '' : '[INATIVO] ') . (isset($item['oculto']) && $item['oculto'] ? '[OCULTO] ' : '')
        . $item['rotulo'] . '  (' . $cod . ($item['url'] !== '' ? ' → ' . basename($item['url']) : '') . '; ordem ' . $item['ordem'] . '; perfis atlas ' . $item['perfis_com_acesso']['gatlas_dbp'] . ')';
    $item['filhos'] = array_map(static fn($f) => $montar($f, $nivel + 1), $filhos[(int)$m['codmenu']] ?? []);
    return $item;
};
foreach ($raizes as $r) $arvore[] = $montar($r, 0);

$saida = ['diagnostico' => 'menus do módulo', 'filtro' => $filtro, 'colunas_menus' => $cols, 'itens' => count($linhas), 'arvore_texto' => $linhas, 'arvore' => $arvore];
@file_put_contents(dirname(__DIR__) . '/storage/logs/diagnostico_menus_modulo.json', json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo implode(PHP_EOL, $linhas) . PHP_EOL . PHP_EOL . 'Itens: ' . count($linhas) . ' — detalhes em storage/logs/diagnostico_menus_modulo.json' . PHP_EOL;
