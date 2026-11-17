<?php
declare(strict_types=1);

/*
 * Corrige acentuação estragada dentro de funções, procedures, views e triggers do banco (ex.: GETATENDIDO devolvendo
 * "N????o" em vez de "Não" — a rotina foi criada no passado por uma conexão sem utf8mb4 e o texto fixo virou "?").
 *
 * Como funciona:
 *  1. Monta um dicionário com as palavras acentuadas do próprio projeto (database, scripts, classes, principal, lang...)
 *     e, para cada uma, as formas estragadas possíveis: cada letra acentuada vira "?", "??" ou "????" (ex.: Não -> N??o,
 *     N????o) ou o texto duplamente codificado (NÃ£o).
 *  2. Em cada banco (todas as empresas g*_dbp e o universal) lê as definições (SHOW CREATE / information_schema),
 *     troca só as palavras que o dicionário reconhece sem ambiguidade e recria o objeto com a conexão em utf8mb4.
 *     Se a recriação falhar, volta a definição original.
 *  3. Palavras estragadas que o dicionário não reconhece aparecem no relatório (nao_resolvidas) para correção manual.
 *
 * O índice da Pesquisa de documentos se atualiza sozinho na próxima execução do serviço (o hash inclui a situação);
 * com --indexar, a indexação roda na hora nos bancos corrigidos.
 *
 * Uso (PowerShell):
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\corrigir-acentuacao-rotinas.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\corrigir-acentuacao-rotinas.php --indexar
 *   Opcional: --banco=gatlas_dbp (pode repetir)
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

@set_time_limit(0);
$simular = in_array('--simular', $argv ?? [], true);
$indexar = in_array('--indexar', $argv ?? [], true);
$somente = [];
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--banco=')) $somente[] = substr($a, 8);

$env = GreenPortalDatabase::env();
$raiz = dirname(__DIR__);
$universal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');

// ---------- 1. dicionário de palavras acentuadas do projeto ----------
$palavras = [];
$pastas = ['database', 'scripts', 'classes', 'principal', 'components', 'lang', 'idiomas', 'assets/js', 'storage/idiomas'];
foreach ($pastas as $p) {
    $dir = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $p);
    if (!is_dir($dir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || !preg_match('/\.(sql|php|js|json|txt|csv|ini)$/i', $f->getFilename()) || $f->getSize() > 8000000) continue;
        if (str_contains($f->getPathname(), DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) continue;
        $txt = (string)@file_get_contents($f->getPathname());
        if (!mb_check_encoding($txt, 'UTF-8')) continue;
        if (preg_match_all('/[\p{L}]*[^\x00-\x7F\P{L}][\p{L}]*/u', $txt, $m)) foreach ($m[0] as $w) $palavras[$w] = ($palavras[$w] ?? 0) + 1;
    }
}
// palavras comuns que podem não estar escritas no código
foreach (['Não', 'não', 'NÃO', 'Sim', 'Atenção', 'Situação', 'Descrição', 'Observação', 'Licença', 'Licenças', 'Responsável', 'Unidade', 'Válida', 'Válido', 'Pendência', 'Pendências',
          'Legislação', 'Ação', 'Ações', 'Revisão', 'Conclusão', 'Avaliação', 'Notificação', 'Município', 'Órgão', 'Área', 'Período', 'Último', 'Água', 'Resíduos', 'Emissão', 'Aplicável', 'Aplicáveis',
          'Parcial', 'Também', 'Usuário', 'Usuários', 'Código', 'Título', 'Número', 'Data de emissão', 'Vigência', 'Exigência', 'Condição', 'Condições', 'Informação', 'Informações'] as $w)
    foreach (preg_split('/\s+/u', $w) as $x) if (preg_match('/[^\x00-\x7F]/u', $x)) $palavras[$x] = ($palavras[$x] ?? 0) + 50;

$ambiguas = [];
$candidatos = [];      // forma estragada => [palavra correta => frequência no projeto]
$adiciona = static function (string $ruim, string $boa) use (&$candidatos, &$palavras): void {
    if ($ruim === $boa) return;
    $candidatos[$ruim][$boa] = $palavras[$boa];
};
foreach (array_keys($palavras) as $w) {
    foreach ([1, 2, 3, 4] as $k) $adiciona(preg_replace('/[^\x00-\x7F]/u', str_repeat('?', $k), $w), $w);
    $adiciona(mb_convert_encoding($w, 'UTF-8', 'Windows-1252'), $w);                       // duplamente codificado (NÃ£o)
    $adiciona(preg_replace('/[^\x00-\x7F]/u', '?', mb_convert_encoding($w, 'UTF-8', 'Windows-1252')), $w);
}

// Mais de uma palavra possível: vale a mais usada no projeto, se for bem mais comum que a segunda (3x); senão fica para correção manual.
$mapa = [];
foreach ($candidatos as $ruim => $lista) {
    arsort($lista); $v = array_values($lista); $k = array_keys($lista);
    $mapa[$ruim] = (count($v) === 1 || $v[0] >= 3 * $v[1]) ? (string)$k[0] : false;
    if ($mapa[$ruim] === false) $ambiguas[$ruim] = implode(' / ', array_slice($k, 0, 4));
}

// ---------- corrige um texto ----------
// palavra com "?" ou com pares duplamente codificados (Ã + caractere de 0x80 a 0xBF, ex.: Ã£ Ã§ Ã­)
$tokenRuim = '/(?:[ÃÂ][\x{80}-\x{BF}]|[\p{L}\?])+/u';
$corrigir = static function (string $sql) use ($mapa, $tokenRuim, $palavras, $ambiguas): array {
    $trocas = []; $pendentes = [];
    $novo = preg_replace_callback($tokenRuim, static function ($m) use ($mapa, $palavras, $ambiguas, &$trocas, &$pendentes) {
        $t = $m[0];
        if (isset($palavras[$t])) return $t;   // palavra correta (ex.: NÃO)
        if (!preg_match('/\p{L}\?|\?\p{L}|[ÃÂ][\x{80}-\x{BF}]/u', $t)) return $t;    // "?" sozinho (parâmetro de SQL dinâmico) ou palavra normal
        if (preg_match('/^php\?/i', $t)) return $t;                                        // endereço (arquivo.php?parametro)
        if (isset($mapa[$t]) && $mapa[$t] !== false) { $trocas[$t . ' -> ' . $mapa[$t]] = ($trocas[$t . ' -> ' . $mapa[$t]] ?? 0) + 1; return $mapa[$t]; }
        $pendentes[$t] = $ambiguas[$t] ?? true;
        return $t;
    }, $sql);
    return [$novo, $trocas, $pendentes];
};

// ---------- 2. bancos ----------
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $is->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'g%\\_dbp' ORDER BY SCHEMA_NAME")->fetchAll(PDO::FETCH_COLUMN);
if ($somente) $bancos = array_values(array_intersect($bancos, $somente));

$rel = ['ok' => true, 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'dicionario' => count($palavras) . ' palavras acentuadas', 'bancos' => []];
$semDefiner = static fn(string $s): string => preg_replace('/\sDEFINER\s*=\s*`[^`]*`@`[^`]*`/i', '', $s, 1);

foreach ($bancos as $db) {
    $r = ['corrigidos' => [], 'nao_resolvidas' => [], 'erros' => []];
    try {
        $pdo = GreenPortalDatabase::connect($env, $db, $db === $universal ? 'GUNIVERSAL' : 'GPT');
        $pdo->exec('SET NAMES utf8mb4');
        $modoInicial = (string)$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $x = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $x; };

        // candidatos: definição com "?" colado em letra ou com pares duplamente codificados (filtro no PHP, sem REGEXP no banco)
        $suspeito = static fn(?string $d): bool => $d !== null && preg_match('/\p{L}\?|\?\p{L}|[ÃÂ][\x{80}-\x{BF}]/u', $d) === 1;
        $objetos = [];
        foreach ($q("SELECT ROUTINE_NAME n, ROUTINE_TYPE t, ROUTINE_DEFINITION d FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?", [$db]) as $x) if ($suspeito($x['d'])) $objetos[] = [$x['t'], $x['n']];
        foreach ($q("SELECT TABLE_NAME n, VIEW_DEFINITION d FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ?", [$db]) as $x) if ($suspeito($x['d'])) $objetos[] = ['VIEW', $x['n']];
        foreach ($q("SELECT TRIGGER_NAME n, ACTION_STATEMENT d FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?", [$db]) as $x) if ($suspeito($x['d'])) $objetos[] = ['TRIGGER', $x['n']];

        foreach ($objetos as [$tipo, $nome]) {
            $show = $q('SHOW CREATE ' . $tipo . ' `' . str_replace('`', '', $nome) . '`')[0] ?? [];
            $campo = ['FUNCTION' => 'Create Function', 'PROCEDURE' => 'Create Procedure', 'VIEW' => 'Create View', 'TRIGGER' => 'SQL Original Statement'][$tipo];
            $original = (string)($show[$campo] ?? '');
            if ($original === '') { $r['erros'][] = "$tipo $nome: sem acesso à definição"; continue; }
            [$novo, $trocas, $pend] = $corrigir($original);
            foreach ($pend as $p => $opcoes) { $r['nao_resolvidas'][$p][] = "$tipo $nome"; if (is_string($opcoes)) $r['ambiguas'][$p] = $opcoes; }
            if (!$trocas) continue;
            $r['corrigidos'][] = ['objeto' => "$tipo $nome", 'trocas' => $trocas];
            if ($simular) continue;

            $modo = (string)($show['sql_mode'] ?? '');
            $pdo->exec("SET SESSION sql_mode = " . $pdo->quote($modo));
            try {
                if ($tipo === 'VIEW') {
                    $pdo->exec(preg_replace('/^CREATE\s/i', 'CREATE OR REPLACE ', $semDefiner($novo), 1));
                } else {
                    $pdo->exec('DROP ' . $tipo . ' IF EXISTS `' . $nome . '`');
                    try { $pdo->exec($semDefiner($novo)); }
                    catch (Throwable $e) { $pdo->exec($semDefiner($original)); throw $e; }   // volta a definição anterior
                }
            } catch (Throwable $e) {
                $r['erros'][] = "$tipo $nome: " . $e->getMessage();
                $rel['ok'] = false;
            }
        }
        $pdo->exec("SET SESSION sql_mode = " . $pdo->quote($modoInicial));
        if (!$simular && $indexar && $r['corrigidos'] && $q("SELECT 1 x FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'busca_indice'", [$db])) {
            require_once $raiz . '/classes/BuscaIndexadorService.php';
            $ix = (new BuscaIndexadorService($pdo, $env, $db))->executar();
            $r['indexacao'] = $ix['registros'] ?? $ix;
        }
    } catch (Throwable $e) {
        $r['erros'][] = $e->getMessage();
        $rel['ok'] = false;
    }
    if (!$r['corrigidos'] && !$r['nao_resolvidas'] && !$r['erros']) $r = 'sem problemas';
    else { $r['nao_resolvidas'] = array_map(static fn($l) => implode(', ', array_slice(array_unique($l), 0, 5)), $r['nao_resolvidas']); }
    $rel['bancos'][$db] = $r;
}

echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
