<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) — o que o rascunho do Guia do usuário tem de diferente da versão publicada.
 * Para decidir se publica (--publicar-pendente na migração 309) ou descarta o rascunho.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-guia-rascunho.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/GuiaUsuarioService.php';

$env = GreenPortalDatabase::env();
$u = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$s = new GuiaUsuarioService($u);
$r = $s->rascunho(); $p = $s->publicado();
$rel = ['rascunho' => $r ? ['revisao' => $r['revisao'], 'alterado_por' => $r['alterado_por'], 'alterado_em' => $r['alterado_em'], 'pendente' => $r['pendente']] : null,
    'publicado' => $p ? $p['versao'] : null, 'diferencas' => []];
if ($r && $p) {
    $a = $s->normalizar($p['conteudo']); $b = $s->normalizar($r['conteudo']);
    $pt = static fn($v) => is_array($v) ? (string)($v['pt'] ?? '') : (string)$v;
    $porId = static function (array $doc): array { $o = []; foreach ($doc['modulos'] as $m) $o[$m['id']] = $m; return $o; };
    $ma = $porId($a); $mb = $porId($b);
    foreach (array_unique(array_merge(array_keys($ma), array_keys($mb))) as $id) {
        if (!isset($ma[$id])) { $rel['diferencas'][$id] = 'aba nova no rascunho: ' . $pt($mb[$id]['nome']); continue; }
        if (!isset($mb[$id])) { $rel['diferencas'][$id] = 'aba excluída no rascunho'; continue; }
        $d = [];
        foreach (['nome', 'resumo', 'ativo', 'perfis'] as $c) if (json_encode($ma[$id][$c]) !== json_encode($mb[$id][$c])) $d[] = "$c alterado";
        foreach (['fases' => 'nome', 'tarefas' => 'titulo', 'menu' => 'caminho', 'termos' => 'termo', 'guias' => 'titulo', 'telas' => 'chaves'] as $c => $rot) {
            $ta = array_map(static fn($x) => is_array($x[$rot]) && isset($x[$rot]['pt']) ? $x[$rot]['pt'] : json_encode($x[$rot], JSON_UNESCAPED_UNICODE), $ma[$id][$c] ?? []);
            $tb = array_map(static fn($x) => is_array($x[$rot]) && isset($x[$rot]['pt']) ? $x[$rot]['pt'] : json_encode($x[$rot], JSON_UNESCAPED_UNICODE), $mb[$id][$c] ?? []);
            $novos = array_values(array_diff($tb, $ta)); $saiu = array_values(array_diff($ta, $tb));
            $mudou = json_encode($ma[$id][$c] ?? []) !== json_encode($mb[$id][$c] ?? []);
            if ($novos) $d[] = "$c incluídos: " . implode(' | ', array_slice($novos, 0, 8));
            if ($saiu) $d[] = "$c retirados: " . implode(' | ', array_slice($saiu, 0, 8));
            if ($mudou && !$novos && !$saiu) $d[] = "$c com textos alterados (" . count($mb[$id][$c] ?? []) . ')';
        }
        if ($d) $rel['diferencas'][$id] = $d;
    }
    if (json_encode($a['perfis']) !== json_encode($b['perfis'])) $rel['diferencas']['perfis'] = 'perfis alterados';
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
