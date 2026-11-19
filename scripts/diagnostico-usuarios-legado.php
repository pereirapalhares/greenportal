<?php
declare(strict_types=1);
/*
 * Diagnóstico (só leitura, 19/11/2026): usuários do legado (<legado>_seguranca.usuarios) × greenportal (<banco>.usuarios),
 * por empresa migrada. Os usuários foram copiados uma vez na migração geral (migrar-seguranca-generico.php); o sincronismo
 * diário traz só usuários × unidades (316) e participantes de NC (333). Nada é gravado no banco.
 * Saída: tela (resumo) + storage\logs\diagnostico_usuarios_legado.json (exemplos só com código e login; senha só contada).
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-usuarios-legado.php
 */
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
@set_time_limit(0);
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$existe = static fn(string $db, string $t): bool => (bool)$q('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t]);
$cols = static fn(string $db, string $t): array => array_map('strtolower', array_column($q('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t]), 'COLUMN_NAME'));
$pick = static function (array $c, array $nomes): ?string { foreach ($nomes as $n) if (in_array(strtolower($n), $c, true)) return strtolower($n); return null; };
$norm = static fn($v): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$v)), 'UTF-8');
$r = ['gerado_em' => date('Y-m-d H:i:s'), 'empresas' => []];
foreach ($q("SELECT codempresa, codigo, nome, banco_dados FROM empresa WHERE banco_dados IS NOT NULL AND status = 'ATIVA' ORDER BY nome") as $e) {
    $destino = (string)$e['banco_dados'];
    if (!preg_match('/^g[a-z0-9_]+_dbp$/i', $destino) || !$existe($destino, 'usuarios')) continue;
    $req = $q('SELECT bancos_origem FROM migracao_solicitacoes WHERE banco_destino = ? ORDER BY codsolicitacao DESC LIMIT 1', [$destino]);
    $origens = $req ? (json_decode((string)$req[0]['bancos_origem'], true) ?: []) : [];
    // Sem pedido de migração (Atlas, MODELO): o sincronismo usa green_<código>; o banco de segurança é green_<código>_seguranca.
    $origens[] = 'green_' . strtolower(preg_replace('/[^A-Za-z0-9_]+/', '_', (string)$e['codigo']));
    $seg = null;
    foreach ($origens as $o) foreach ([$o . '_seguranca', $o] as $cand) if (is_string($cand) && preg_match('/^[A-Za-z0-9_]+$/', $cand) && $existe($cand, 'usuarios') && $existe($cand, 'grupos')) { $seg = $cand; break 2; }
    $item = ['empresa' => $e['nome'], 'destino' => $destino, 'origem' => $seg];
    if ($seg === null) { $item['situacao'] = 'sem banco de segurança do legado identificado'; $r['empresas'][] = $item; continue; }
    $cl = $cols($seg, 'usuarios');
    $map = ['codusuario' => $pick($cl, ['codusuario', 'id']), 'userid' => $pick($cl, ['userid', 'login', 'usuario']), 'nome' => $pick($cl, ['nome']), 'email' => $pick($cl, ['email', 'emails']),
        'ativo' => $pick($cl, ['ativado', 'ativo']), 'criado_em' => $pick($cl, ['dtinclusao', 'criado_em', 'created_at']), 'senha' => $pick($cl, ['senha'])];
    $leg = [];
    foreach ($q('SELECT * FROM `' . $seg . '`.usuarios') as $u) { $u = array_change_key_case($u, CASE_LOWER); $id = (int)($u[$map['codusuario']] ?? 0); if ($id > 0) $leg[$id] = $u; }
    $por = [];
    foreach ($q('SELECT codusuario, userid, nome, email, ativo, criado_em, senha FROM `' . $destino . '`.usuarios') as $u) $por[(int)$u['codusuario']] = $u;
    $soLeg = array_diff_key($leg, $por); $soPor = array_diff_key($por, $leg);
    $dif = ['userid' => [], 'nome' => [], 'email' => [], 'ativo' => [], 'criado_em' => [], 'senha' => []];
    foreach (array_intersect_key($leg, $por) as $id => $l) {
        $p = $por[$id];
        foreach (['userid', 'nome', 'email'] as $c) if ($map[$c] && $norm($l[$map[$c]] ?? '') !== $norm($p[$c] ?? '')) $dif[$c][] = $id;
        if ($map['ativo'] && ((strtoupper(trim((string)($l[$map['ativo']] ?? 'S'))) === 'N') ? 0 : 1) !== (int)$p['ativo']) $dif['ativo'][] = $id;
        if ($map['criado_em'] && substr((string)($l[$map['criado_em']] ?? ''), 0, 10) !== substr((string)($p['criado_em'] ?? ''), 0, 10)) $dif['criado_em'][] = $id;
        if ($map['senha'] && (string)($l[$map['senha']] ?? '') !== (string)($p['senha'] ?? '')) $dif['senha'][] = $id;
    }
    $ex = static function (array $ids, array $fonte, string $campoLogin): array { $o = []; foreach (array_slice($ids, 0, 8) as $id) $o[] = $id . ' (' . ($fonte[$id][$campoLogin] ?? '') . ')'; return $o; };
    $maxLeg = $leg ? max(array_keys($leg)) : 0;
    $item += ['legado' => count($leg), 'portal' => count($por), 'so_no_legado' => count($soLeg), 'so_no_portal' => count($soPor),
        'exemplos_so_no_legado' => $ex(array_keys($soLeg), $leg, (string)$map['userid']), 'exemplos_so_no_portal' => $ex(array_keys($soPor), $por, 'userid'),
        'mais_recente_legado' => $map['criado_em'] && $leg ? max(array_map(static fn($u) => (string)($u[$map['criado_em']] ?? ''), $leg)) : null,
        'diferencas' => array_map('count', $dif), 'exemplos_diferencas' => array_map(static fn($ids) => array_slice($ids, 0, 8), array_filter($dif, static fn($ids, $c) => $c !== 'senha' && $ids, ARRAY_FILTER_USE_BOTH)),
        'colunas_legado_usadas' => $map];
    $r['empresas'][] = $item;
}
$json = json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
@file_put_contents(dirname(__DIR__) . '/storage/logs/diagnostico_usuarios_legado.json', $json);
foreach ($r['empresas'] as $i) {
    if (!isset($i['legado'])) { echo str_pad($i['empresa'], 22) . ' ' . ($i['situacao'] ?? '') . PHP_EOL; continue; }
    echo str_pad($i['empresa'], 22) . " legado {$i['legado']} | portal {$i['portal']} | só legado {$i['so_no_legado']} | só portal {$i['so_no_portal']} | diferenças " . json_encode($i['diferencas']) . PHP_EOL;
}
echo "Detalhe em storage\\logs\\diagnostico_usuarios_legado.json\n";
