<?php
declare(strict_types=1);

/*
 * Diagnóstico (só leitura) da avaliação da eficácia com IA (migrações 337/338): para os últimos pedidos de uma empresa (ou de uma NC),
 * mostra os arquivos de evidência encontrados nas pastas do GED, as leituras (OCR) feitas, o trecho "CONTEÚDO DAS EVIDÊNCIAS" que
 * foi para a IA e o que a IA respondeu sobre cada ação. Não grava nada.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-nc-ia-eficacia.php --banco=gcrown_dbp [--codigo=19547] [--ultimos=3]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/GedStorageService.php';

$banco = 'gcrown_dbp'; $codigo = 0; $ultimos = 3;
foreach ($argv ?? [] as $a) {
    if (str_starts_with($a, '--banco=')) $banco = substr($a, 8);
    if (str_starts_with($a, '--codigo=')) $codigo = (int)substr($a, 9);
    if (str_starts_with($a, '--ultimos=')) $ultimos = max(1, min(20, (int)substr($a, 10)));
}
if (!preg_match('/^g[a-z0-9_]+_dbp$/', $banco)) { fwrite(STDERR, "banco inválido\n"); exit(1); }
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('SET NAMES utf8mb4');
$linhas = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$corta = static fn($v, int $n = 300): string => mb_strimwidth(trim(preg_replace('/\s+/u', ' ', (string)$v) ?? ''), 0, $n, '…', 'UTF-8');

$sessao = ['tenant' => ['banco_dados' => $banco]];
$ged = new GedStorageService($pdo, $env, $sessao);
$rel = ['banco' => $banco, 'pasta_ged_legado' => $ged->legacyRoot() . DIRECTORY_SEPARATOR . $ged->legacyDatabase(),
    'fila_ocr' => $linhas("SELECT status, COUNT(*) AS qtd FROM vw_green_ocr_jobs WHERE tipo = 'NC_EVIDENCIA' GROUP BY status"), 'pedidos' => []];

$sql = "SELECT * FROM vw_nc_ia_analises WHERE TIPO = 'EFICACIA'" . ($codigo ? ' AND CODOCORRENCIA = ?' : '') . ' ORDER BY CODANALISE DESC LIMIT ' . $ultimos;
foreach ($linhas($sql, $codigo ? [$codigo] : []) as $a) {
    $nc = (int)$a['CODOCORRENCIA'];
    $p = ['codanalise' => (int)$a['CODANALISE'], 'nc' => $nc, 'situacao' => $a['STATUS'], 'pedido_em' => $a['CRIADO_EM'], 'usuario' => $a['USUARIO'], 'job_ia' => $a['CODJOB'] ? ((int)$a['CODJOB'] . ' ' . $a['JOB_STATUS']) : '(ainda não foi para a IA)'];
    // Arquivos nas pastas do GED (o que a tela de arquivos mostra)
    $pastas = ['evidencias_da_analise' => $ged->folders('nc_analise_ocorrencia', $nc), 'anexos_da_ocorrencia' => $ged->folders('nc_indicacao_ocorrencia', $nc)];
    $acoes = $linhas('SELECT CODPLANOACAO, ACAO, STATUS FROM vw_nc_planodeacao WHERE CODOCORRENCIA = ? ORDER BY CODPLANOACAO', [$nc]);
    foreach ($acoes as $i => $ac) $pastas['acao_' . ($i + 1) . ' (' . $corta($ac['ACAO'], 60) . ')'] = $ged->folders('nc_planodeacao', (int)$ac['CODPLANOACAO']);
    foreach ($pastas as $rot => $dirs) {
        $achados = [];
        foreach ($dirs as $d) {
            if (!is_dir($d)) { $achados[] = $d . ' (pasta não existe)'; continue; }
            $n = 0;
            foreach (new DirectoryIterator($d) as $f) if (!$f->isDot() && $f->isFile()) { $achados[] = $d . DIRECTORY_SEPARATOR . $f->getFilename() . ' (' . round($f->getSize() / 1024) . ' KB)'; $n++; }
            if (!$n) $achados[] = $d . ' (vazia)';
        }
        $p['arquivos'][$rot] = $achados;
    }
    // Leituras (OCR) do pedido
    foreach (json_decode((string)$a['EVIDENCIAS'], true) ?: [] as $l) {
        $j = $linhas('SELECT status, tentativas, erro, CHAR_LENGTH(texto) AS caracteres, LEFT(texto, 200) AS inicio FROM vw_green_ocr_jobs WHERE codjob = ?', [(int)$l['codjob']])[0] ?? null;
        $p['leituras'][] = ['origem' => $l['origem'], 'arquivo' => $l['arquivo'], 'ocr_job' => (int)$l['codjob']] + ($j ? ['situacao' => $j['status'], 'tentativas' => (int)$j['tentativas'], 'erro' => $j['erro'], 'caracteres_lidos' => (int)$j['caracteres'], 'inicio_do_texto' => $corta($j['inicio'], 200)] : ['situacao' => 'job de OCR não encontrado']);
    }
    if (empty($p['leituras'])) $p['leituras'] = 'nenhum arquivo foi para a leitura neste pedido (sem arquivo legível nas pastas acima no momento do pedido, ou pedido feito antes da migração 338)';
    // O que foi para a IA e o que ela respondeu
    if ($a['CODJOB']) {
        $job = $linhas('SELECT texto, resultado_json, erro FROM vw_green_ia_document_jobs WHERE codjob = ?', [(int)$a['CODJOB']])[0] ?? [];
        $texto = (string)($job['texto'] ?? '');
        $pos = mb_strpos($texto, 'CONTEÚDO DAS EVIDÊNCIAS');
        $p['enviado_a_ia'] = ['caracteres' => mb_strlen($texto), 'conteudo_das_evidencias' => $pos === false ? '(o pedido não tinha a seção: nenhuma leitura)' : $corta(mb_substr($texto, $pos), 1500)];
        $r = json_decode((string)($job['resultado_json'] ?? ''), true);
        $s = $r['sugestao'] ?? null;
        if (is_array($s)) $p['resposta_da_ia'] = ['parecer' => $s['parecer'] ?? null, 'acoes' => array_map(static fn($x) => is_array($x) ? (($x['numero'] ?? '?') . ' ' . ($x['avaliacao'] ?? '') . ': ' . $corta($x['comentario'] ?? '', 200)) : '', (array)($s['acoes'] ?? [])), 'falta_comprovar' => $s['falta_comprovar'] ?? []];
        elseif (!empty($job['erro'])) $p['resposta_da_ia'] = 'erro: ' . $job['erro'];
    }
    $rel['pedidos'][] = $p;
}
if (!$rel['pedidos']) $rel['pedidos'] = 'nenhum pedido de avaliação da eficácia com IA' . ($codigo ? ' para a NC ' . $codigo : '');
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
