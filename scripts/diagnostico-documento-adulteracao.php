<?php
declare(strict_types=1);

/*
 * Diagnóstico (só leitura) da análise de adulteração dos documentos da homologação (migração 349).
 * Em cada banco da homologação (ou só no --banco=): ativação, análises por situação e por risco, as análises de risco
 * MÉDIO/ALTO com os indícios, as que deram erro, as que estão paradas esperando leitura (OCR) ou IA há mais de 1 hora,
 * e o andamento das filas de OCR e de IA (tipo DOC_ADULTERACAO). Serve para calibrar falsos positivos com documentos reais.
 * Não grava nada.
 * Com --arquivos: também procura nas pastas do GED (portal e legado) os arquivos enviados depois da ativação, como a varredura
 *   faria (só lê as pastas; pode levar alguns minutos nos bancos grandes), e lista os bancos que o serviço "ia" percorre.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-documento-adulteracao.php [--banco=gmodelo2_dbp] [--ultimos=30] [--arquivos]
 * Resultado também em storage\logs\diagnostico_documento_adulteracao.json
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';
require_once dirname(__DIR__) . '/classes/HomologacaoDocumentoAdulteracao.php';
require_once dirname(__DIR__) . '/classes/GedStorageService.php';
require_once dirname(__DIR__) . '/classes/WindowsServiceRuntime.php';

$ultimos = 30; $arquivos = in_array('--arquivos', $argv ?? [], true);
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--ultimos=')) $ultimos = max(1, min(200, (int)substr($a, 10)));
$env = GreenPortalDatabase::env();
$bancos = HomologacaoBancos::lista($argv ?? []);
$corta = static fn($v, int $n = 200): string => mb_strimwidth(trim(preg_replace('/\s+/u', ' ', (string)$v) ?? ''), 0, $n, '…', 'UTF-8');
$rel = ['gerado_em' => date('d/m/Y H:i'), 'bancos' => []];
if ($arquivos) {
    try { $rel['bancos_do_servico_ia'] = array_values((new WindowsServiceRuntime($env, 'ia', true))->databases(null)); }
    catch (Throwable $e) { $rel['bancos_do_servico_ia'] = 'erro: ' . $e->getMessage(); }
}

foreach ($bancos as $banco) {
    $r = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');
        $q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $x = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $x; };
        $existe = static fn(string $v): bool => (int)$q("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$v])[0]['n'] > 0;
        if (!$existe('vw_doc_adulteracao')) { $rel['bancos'][$banco] = 'migração 349 não aplicada'; continue; }

        $r['controle'] = $q('SELECT CHAVE, VALOR, ALTERADO_EM FROM vw_doc_adult_controle ORDER BY CHAVE');
        $r['por_situacao'] = $q('SELECT STATUS, COUNT(*) AS qtd FROM vw_doc_adulteracao GROUP BY STATUS ORDER BY qtd DESC');
        $r['por_risco'] = $q("SELECT COALESCE(RISCO, '(sem risco)') AS RISCO, ESCOPO, ORIGEM, COUNT(*) AS qtd FROM vw_doc_adulteracao WHERE STATUS = 'CONCLUIDA' GROUP BY 1, 2, 3 ORDER BY 1, 2, 3");
        $r['pendentes_de_ciencia'] = (int)$q('SELECT COUNT(*) AS n FROM vw_doc_adulteracao WHERE PENDENTE_CIENCIA = 1')[0]['n'];

        // Risco MÉDIO/ALTO: os indícios, para conferir falso positivo
        $risco = $q("SELECT CODANALISE, ESCOPO, ORIGEM, RISCO, ARQUIVO, RAZAOSOCIAL, FUNCIONARIO, DSCRITERIO, SITUACAO_DOC, IA_CONSULTADA, RESUMO, INDICIOS,
                            CIENTE_EM, CIENTE_MOTIVO, CRIADO_EM, CONCLUIDO_EM
                       FROM vw_doc_adulteracao WHERE STATUS = 'CONCLUIDA' AND RISCO IN ('MEDIO', 'ALTO') ORDER BY CODANALISE DESC LIMIT $ultimos");
        foreach ($risco as &$x) {
            $ind = json_decode((string)$x['INDICIOS'], true);
            $x['INDICIOS'] = is_array($ind) ? array_map(static fn($i) => is_array($i)
                ? trim(($i['nivel'] ?? $i['gravidade'] ?? '') . ' ' . ($i['codigo'] ?? $i['tipo'] ?? '') . ': ' . mb_strimwidth((string)($i['descricao'] ?? $i['mensagem'] ?? json_encode($i, JSON_UNESCAPED_UNICODE)), 0, 220, '…', 'UTF-8'))
                : (string)$i, $ind) : $corta($x['INDICIOS'], 400);
            foreach (['ARQUIVO', 'RAZAOSOCIAL', 'FUNCIONARIO', 'DSCRITERIO', 'RESUMO', 'CIENTE_MOTIVO'] as $k) $x[$k] = $corta($x[$k], $k === 'RESUMO' ? 500 : 120);
        }
        unset($x);
        $r['risco_medio_alto'] = $risco;
        // Indícios que mais aparecem (todas as concluídas): ajuda a ver regra que dispara demais
        $cont = [];
        foreach ($q("SELECT INDICIOS FROM vw_doc_adulteracao WHERE STATUS = 'CONCLUIDA' AND INDICIOS IS NOT NULL") as $x) {
            foreach ((array)json_decode((string)$x['INDICIOS'], true) as $i) {
                if (!is_array($i)) continue;
                $k = trim(($i['nivel'] ?? $i['gravidade'] ?? '?') . ' ' . ($i['codigo'] ?? $i['tipo'] ?? mb_strimwidth((string)($i['descricao'] ?? ''), 0, 60, '…', 'UTF-8')));
                $cont[$k] = ($cont[$k] ?? 0) + 1;
            }
        }
        arsort($cont);
        $r['indicios_mais_frequentes'] = array_slice($cont, 0, 25, true);

        $r['erros'] = array_map(static fn($x) => ['CODANALISE' => $x['CODANALISE'], 'ARQUIVO' => $x['ARQUIVO'], 'MENSAGEM' => $x['MENSAGEM'], 'CRIADO_EM' => $x['CRIADO_EM']],
            $q("SELECT CODANALISE, ARQUIVO, MENSAGEM, CRIADO_EM FROM vw_doc_adulteracao WHERE STATUS = 'ERRO' ORDER BY CODANALISE DESC LIMIT $ultimos"));
        $r['paradas_mais_de_1h'] = $q("SELECT CODANALISE, STATUS, ARQUIVO, OCR_JOB, IA_JOB, CRIADO_EM FROM vw_doc_adulteracao
                                        WHERE STATUS NOT IN ('CONCLUIDA', 'ERRO') AND CRIADO_EM < NOW() - INTERVAL 1 HOUR ORDER BY CODANALISE LIMIT $ultimos");

        // Filas (o serviço "ocr" e o serviço "ia" do Agendador precisam estar rodando)
        if ($existe('vw_green_ocr_jobs')) $r['fila_ocr_das_analises'] = $q("SELECT j.status, COUNT(*) AS qtd FROM vw_green_ocr_jobs j
            JOIN fo_doc_adulteracao a ON a.OCR_JOB = j.codjob GROUP BY j.status");
        if ($existe('vw_green_ia_document_jobs')) $r['fila_ia_doc_adulteracao'] = $q("SELECT j.status, COUNT(*) AS qtd FROM vw_green_ia_document_jobs j
            JOIN fo_doc_adulteracao a ON a.IA_JOB = j.codjob GROUP BY j.status");
        // Varredura "seca": o que a varredura acharia hoje (arquivos depois da ativação, formato e tamanho aceitos)
        if ($arquivos) {
            $ativado = (int)($q("SELECT VALOR FROM vw_doc_adult_controle WHERE CHAVE = 'ATIVADO_TS'")[0]['VALOR'] ?? 0);
            $legado = HomologacaoBancos::legado($banco);
            $ged = new GedStorageService($pdo, $env, ['tenant' => ['banco_dados' => $banco] + ($legado !== null ? ['banco_legado' => $legado] : [])]);
            foreach (HomologacaoDocumentoAdulteracao::ESCOPOS as $escopo => $cfg) {
                $s = $pdo->prepare('CALL sp_doc_adult_candidatos(?, ?)'); $s->execute([$escopo, 50000]);
                $cands = array_map(static fn($c) => (int)$c['CODIGO'], $s->fetchAll(PDO::FETCH_ASSOC));
                do { try { $s->fetchAll(); } catch (Throwable) {} } while ($s->nextRowset()); $s->closeCursor();
                $v = ['documentos_candidatos' => count($cands), 'pastas_existentes' => 0, 'arquivos' => 0, 'depois_da_ativacao' => 0, 'aceitos' => 0, 'exemplos' => [], 'raizes' => []];
                foreach ($cands as $codigo) {
                    foreach ($ged->folders($cfg['pasta'], $codigo) as $dir) {
                        $raiz = dirname($dir, 2); $v['raizes'][$raiz] = true;
                        if (!is_dir($dir)) continue;
                        $v['pastas_existentes']++;
                        foreach (@scandir($dir) ?: [] as $n) {
                            if ($n === '.' || $n === '..' || !is_file($dir . DIRECTORY_SEPARATOR . $n)) continue;
                            $v['arquivos']++;
                            $mt = (int)@filemtime($dir . DIRECTORY_SEPARATOR . $n);
                            if ($ativado > 0 && $mt < $ativado) continue;
                            $v['depois_da_ativacao']++;
                            $ok = in_array(strtolower(pathinfo($n, PATHINFO_EXTENSION)), HomologacaoDocumentoAdulteracao::EXTENSOES, true)
                                && (int)@filesize($dir . DIRECTORY_SEPARATOR . $n) <= HomologacaoDocumentoAdulteracao::MAX_BYTES;
                            if ($ok) $v['aceitos']++;
                            if (count($v['exemplos']) < 10) $v['exemplos'][] = $dir . DIRECTORY_SEPARATOR . $n . ' (' . date('d/m/Y H:i', $mt) . ($ok ? '' : ', formato ou tamanho fora') . ')';
                        }
                    }
                }
                $v['raizes'] = array_keys($v['raizes']);
                $r['varredura_seca'][$escopo] = $v;
            }
        }
    } catch (Throwable $e) { $r['erro_do_diagnostico'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $r;
}

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$logs = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logs)) @mkdir($logs, 0775, true);
@file_put_contents($logs . DIRECTORY_SEPARATOR . 'diagnostico_documento_adulteracao.json', $json);
echo $json . PHP_EOL;
