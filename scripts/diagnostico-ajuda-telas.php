<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) da ajuda das telas, base do assistente "Como posso ajudar?".
 * Para cada menu ativo com tela: caminho, ajuda cadastrada (ajuda_transacoes) e tamanho em pt/en/es,
 * citações no Guia do usuário publicado e uso nos últimos 90 dias (logs_auditoria de cada cliente).
 * Nada é alterado. O relatório completo fica em storage\logs\diagnostico_ajuda_telas.json.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-ajuda-telas.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$texto = static fn(?string $html): string => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</li>'], ' ', (string)$html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
$arquivo = static fn(string $url): string => strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME));

// Menus e caminhos
$menus = $universal->query('SELECT codmenu, codmenu_pai, codigo, label_pt, url, ativo FROM menus')->fetchAll(PDO::FETCH_ASSOC);
$porId = [];
foreach ($menus as $m) $porId[(int)$m['codmenu']] = $m;
$caminho = static function (array $m) use ($porId): string {
    $partes = [trim((string)$m['label_pt'])];
    $pai = (int)($m['codmenu_pai'] ?? 0);
    for ($i = 0; $pai > 0 && $i < 6; $i++) { $p = $porId[$pai] ?? null; if (!$p) break; array_unshift($partes, trim((string)$p['label_pt'])); $pai = (int)($p['codmenu_pai'] ?? 0); }
    return implode(' › ', $partes);
};

// Ajuda por transação
$ajudas = [];
foreach ($universal->query('SELECT transacao, ativo, titulo_pt, texto_pt, texto_en, texto_es FROM ajuda_transacoes')->fetchAll(PDO::FETCH_ASSOC) as $a) {
    $ajudas[strtolower(trim((string)$a['transacao']))] = $a;
}

// Citações no Guia do usuário publicado
$citacoesGuia = [];
try {
    $guia = $universal->query('SELECT conteudo FROM vw_guia_usuario_publicado LIMIT 1')->fetchColumn();
    $doc = json_decode((string)$guia, true);
    foreach ((array)($doc['modulos'] ?? []) as $modulo) {
        foreach (['tarefas', 'menu'] as $secao) foreach ((array)($modulo[$secao] ?? []) as $item) { $c = strtoupper((string)($item['transacao'] ?? '')); if ($c !== '') $citacoesGuia[$c] = ($citacoesGuia[$c] ?? 0) + 1; }
        foreach ((array)($modulo['fases'] ?? []) as $fase) foreach ((array)($fase['passos'] ?? []) as $passo) { $c = strtoupper((string)($passo['transacao'] ?? '')); if ($c !== '') $citacoesGuia[$c] = ($citacoesGuia[$c] ?? 0) + 1; }
    }
} catch (Throwable) { /* guia não instalado */ }

// Uso nos últimos 90 dias (todas as empresas)
$uso = [];
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME = 'logs_auditoria' AND TABLE_SCHEMA LIKE '%\\_dbp'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($bancos as $banco) {
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $colunaData = (string)$pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'logs_auditoria' AND COLUMN_NAME IN ('criado_em', 'data_hora', 'registrado_em', 'created_at') LIMIT 1")->fetchColumn();
        $filtro = $colunaData !== '' ? "WHERE `$colunaData` >= NOW() - INTERVAL 90 DAY" : '';
        foreach ($pdo->query("SELECT UPPER(transacao) t, COUNT(*) n FROM logs_auditoria $filtro GROUP BY UPPER(transacao)")->fetchAll(PDO::FETCH_KEY_PAIR) as $t => $n) $uso[(string)$t] = ($uso[(string)$t] ?? 0) + (int)$n;
    } catch (Throwable) { /* banco sem auditoria compatível */ }
}

$telas = [];
foreach ($menus as $m) {
    $url = trim((string)($m['url'] ?? ''));
    if ((int)$m['ativo'] !== 1 || $url === '') continue;
    $codigo = strtoupper((string)$m['codigo']);
    $transacao = $arquivo($url);
    $a = $ajudas[$transacao] ?? null;
    $pt = $a ? $texto($a['texto_pt']) : '';
    $telas[] = [
        'codigo' => $codigo, 'caminho' => $caminho($m), 'url' => $url, 'transacao' => $transacao,
        'ajuda' => $a ? ((int)($a['ativo'] ?? 1) === 1 ? 'ATIVA' : 'INATIVA') : 'SEM_AJUDA',
        'titulo' => $a ? (string)$a['titulo_pt'] : '',
        'caracteres_pt' => mb_strlen($pt), 'caracteres_en' => $a ? mb_strlen($texto($a['texto_en'])) : 0, 'caracteres_es' => $a ? mb_strlen($texto($a['texto_es'])) : 0,
        'citacoes_guia' => $citacoesGuia[$codigo] ?? 0,
        'uso_90_dias' => ($uso[$codigo] ?? 0) + ($uso[strtoupper($transacao)] ?? 0),
        'texto_pt' => $pt,
    ];
}
usort($telas, static fn(array $x, array $y): int => [$y['uso_90_dias'], $x['caminho']] <=> [$x['uso_90_dias'], $y['caminho']]);

$classe = static fn(array $t): string => $t['ajuda'] !== 'ATIVA' ? 'SEM_AJUDA' : ($t['caracteres_pt'] < 200 ? 'CURTA' : ($t['caracteres_pt'] < 600 ? 'MEDIA' : 'BOA'));
$resumo = ['telas' => count($telas), 'SEM_AJUDA' => 0, 'CURTA' => 0, 'MEDIA' => 0, 'BOA' => 0, 'sem_ingles_ou_espanhol' => 0, 'sem_citacao_no_guia' => 0];
foreach ($telas as &$t) {
    $t['classificacao'] = $classe($t);
    $resumo[$t['classificacao']]++;
    if ($t['ajuda'] === 'ATIVA' && ($t['caracteres_en'] === 0 || $t['caracteres_es'] === 0)) $resumo['sem_ingles_ou_espanhol']++;
    if ($t['citacoes_guia'] === 0) $resumo['sem_citacao_no_guia']++;
}
unset($t);

$relatorio = ['gerado_em' => date('Y-m-d H:i:s'), 'resumo' => $resumo, 'bancos_uso' => $bancos, 'telas' => $telas];
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) mkdir($dir, 0775, true);
file_put_contents($dir . DIRECTORY_SEPARATOR . 'diagnostico_ajuda_telas.json', json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo json_encode(['ok' => true, 'resumo' => $resumo, 'relatorio' => 'storage\\logs\\diagnostico_ajuda_telas.json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
