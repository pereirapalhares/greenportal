<?php
declare(strict_types=1);

/*
 * Serviço das regras automáticas dos documentos da Homologação de Fornecedores — roda SÓ pelo Agendador de Tarefas do Windows
 * (\GreenSolutions\GreenPortal-DocumentosHomologacao, a cada 60 minutos, como SYSTEM;
 * scripts/criar-agendamento-documentos-homologacao.ps1). Requer a migração 301.
 * Equivale ao serviço do legado E:\projetoGreen\processos\enviadoodocumento\enviadoodocumento.php, adaptado
 * (regras descritas em database/301_regras_documentos_homologacao.sql e na tabela fo_regras_documentos de cada empresa).
 *
 * Em cada empresa e para cada escopo (FORNECEDOR, FUNCIONARIO):
 *  1. CALL sp_fo_regras_documentos_listar(escopo) → códigos a examinar;
 *  2. conta os arquivos de cada código no GED (mesmas pastas do legado: fo_fornecedorxcriterios\<código> e
 *     fo_funcionarioxtiposxcriterios\<código>, via GedStorageService) e a data do arquivo mais novo;
 *  3. CALL sp_fo_regras_documentos_aplicar(escopo, contagens, 1, 0) → aplica, grava histórico e log;
 *  (migração 348) reaplica a validade das integrações aprovadas pelo portal (sp_fo_integracao_reaplicar), caso o sincronismo traga uma data mais antiga;
 *  4. avisa em Home › Alertas o responsável de cada documento que mudou (empresa: foi para "Pendente com a empresa";
 *     fornecedor: foi para "Pendente com o fornecedor"), um alerta por responsável e escopo, só de fornecedores ativos.
 *
 * Uso: php scripts\homologacao-documentos-worker.php [--banco=gmodelo_dbp] [--ged=green_modelo] [--simular] [--sem-alertas]
 *   --simular     calcula e mostra o que mudaria, sem gravar (use antes da primeira execução);
 *   --sem-alertas grava sem criar alertas (útil na primeira execução, que pode mudar muitos documentos de uma vez).
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';
require_once dirname(__DIR__) . '/classes/GedStorageService.php';
require_once dirname(__DIR__) . '/classes/IntegracaoFuncionario.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$semAlertas = in_array('--sem-alertas', $args, true);
$soBanco = null; $gedForcado = null;
foreach ($args as $a) {
    if (preg_match('/^--banco=(g[a-z0-9_]+_dbp)$/', $a, $m)) $soBanco = $m[1];
    if (preg_match('/^--ged=([A-Za-z0-9_]+)$/', $a, $m)) $gedForcado = $m[1];
}
$env = GreenPortalDatabase::env();
$universal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$saida = ['servico' => 'homologacao-documentos', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'alertas' => !$simular && !$semAlertas, 'inicio' => date('c'), 'bancos' => []];

$runtime = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime';
if (!is_dir($runtime)) @mkdir($runtime, 0770, true);
$trava = fopen($runtime . DIRECTORY_SEPARATOR . 'green-homologacao-documentos.lock', 'c');
if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) exit(0);

try {
    $is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
    $s = $is->query("SELECT ROUTINE_SCHEMA FROM information_schema.ROUTINES WHERE ROUTINE_NAME = 'sp_fo_regras_documentos_aplicar' AND ROUTINE_SCHEMA LIKE 'g%\\_dbp' ORDER BY ROUTINE_SCHEMA");
    $bancos = array_values(array_filter($s->fetchAll(PDO::FETCH_COLUMN), static fn($b) => $b !== $universal && preg_match('/^g[a-z0-9_]+_dbp$/', (string)$b) && ($soBanco === null || $b === $soBanco)));
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }

$chamar = static function (PDO $pdo, string $sql, array $p): array {
    $st = $pdo->prepare($sql); $st->execute($p); $r = $st->fetchAll(PDO::FETCH_ASSOC); while ($st->nextRowset()) { } $st->closeCursor(); return $r;
};
// Arquivos de um registro no GED: quantidade (nomes distintos nas pastas do legado e local) e data do mais novo.
$contar = static function (GedStorageService $ged, string $pasta, int $codigo): array {
    $nomes = []; $ultimo = 0;
    foreach ($ged->folders($pasta, $codigo) as $dir) {
        if (!is_dir($dir)) continue;
        try {
            foreach (new DirectoryIterator($dir) as $f) {
                if ($f->isDot() || !$f->isFile()) continue;
                $nomes[$f->getFilename()] = true;
                $ultimo = max($ultimo, (int)$f->getMTime());
            }
        } catch (Throwable) { }
    }
    return ['n' => count($nomes), 'm' => $ultimo > 0 ? date('Y-m-d H:i:s', $ultimo) : ''];
};
$escopos = [
    'FORNECEDOR' => ['pasta' => 'fo_fornecedorxcriterios', 'rotulo' => 'documento(s) de fornecedor', 'consulta' => ['Pendente com a empresa' => 'pendemp', 'Pendente com o fornecedor' => 'pendforn']],
    'FUNCIONARIO' => ['pasta' => 'fo_funcionarioxtiposxcriterios', 'rotulo' => 'documento(s) de funcionário', 'consulta' => ['Pendente com a empresa' => 'docfunc', 'Pendente com o fornecedor' => 'docfunc']],
];

foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        // Pasta do GED: --ged, senão o legado de classes/HomologacaoBancos.php (ex.: ggdamericana_dbp -> green_gd_americana), senão pelo nome do banco
        $gedBanco = $gedForcado ?? HomologacaoBancos::legado($banco);
        $sessao = ['tenant' => ['banco_dados' => $banco] + ($gedBanco !== null ? ['banco_legado' => $gedBanco] : [])];
        $ged = new GedStorageService($pdo, $env, $sessao);
        $info['ged'] = $ged->legacyRoot() . DIRECTORY_SEPARATOR . $ged->legacyDatabase();
        foreach ($escopos as $escopo => $cfg) {
            $t0 = microtime(true);
            $codigos = array_map(static fn($l) => (int)$l['codigo'], $chamar($pdo, 'CALL sp_fo_regras_documentos_listar(?)', [$escopo]));
            $lista = []; $comArquivo = 0;
            foreach ($codigos as $c) { $a = $contar($ged, $cfg['pasta'], $c); if ($a['n'] > 0) $comArquivo++; $lista[] = ['c' => $c] + $a; }
            $mudancas = $lista ? $chamar($pdo, 'CALL sp_fo_regras_documentos_aplicar(?, ?, ?, 0)', [$escopo, json_encode($lista), $simular ? 0 : 1]) : [];
            $porRegra = [];
            foreach ($mudancas as $m) {
                if (!empty($m['REGRA'])) { $k = $m['REGRA'] . ': ' . ($m['SITUACAO_ANTES'] !== '' ? $m['SITUACAO_ANTES'] : '(sem situação)') . ' → ' . ($m['SITUACAO_DEPOIS'] !== '' ? $m['SITUACAO_DEPOIS'] : '(sem pendência)'); $porRegra[$k] = ($porRegra[$k] ?? 0) + 1; }
                if (!empty($m['REGRA_CAMPO']) && $m['REGRA_CAMPO'] !== $m['REGRA']) { $k = $m['REGRA_CAMPO'] . ': ' . ($m['CAMPO_ANTES'] !== '' ? $m['CAMPO_ANTES'] : '(vazio)') . ' → ' . $m['CAMPO_DEPOIS']; $porRegra[$k] = ($porRegra[$k] ?? 0) + 1; }
            }
            ksort($porRegra);
            // Alertas: um por responsável, só documentos de fornecedores ativos que mudaram de situação
            $porUsuario = [];
            foreach ($mudancas as $m) {
                if (empty($m['REGRA']) || (int)$m['ATIVO'] !== 1) continue;
                $destino = (string)$m['SITUACAO_DEPOIS'];
                $u = $destino === 'Pendente com a empresa' ? (int)($m['CODUSUARIO_EMPRESA'] ?? 0) : ($destino === 'Pendente com o fornecedor' ? (int)($m['CODUSUARIO_FORNECEDOR'] ?? 0) : 0);
                if ($u > 0) $porUsuario[$u][$destino][] = trim((string)$m['RAZAOSOCIAL']) . ': ' . trim((string)$m['DOCUMENTO']);
            }
            $alertas = 0;
            if (!$simular && !$semAlertas) {
                foreach ($porUsuario as $u => $destinos) {
                    foreach ($destinos as $destino => $docs) {
                        $n = count($docs);
                        $msg = "$n {$cfg['rotulo']} passaram para \"$destino\": " . implode('; ', array_slice($docs, 0, 5)) . ($n > 5 ? '; ...' : '') . '.';
                        $link = '/principal/homologacaofornecedores_painel.php?responsavel=EU&consulta=' . ($cfg['consulta'][$destino] ?? 'todos');
                        $r = $chamar($pdo, 'CALL sp_alerta_criar(?, ?, ?, ?, ?)', [$u, 'Homologação — documentos ' . ($destino === 'Pendente com a empresa' ? 'para análise' : 'pendentes com o fornecedor'), mb_substr($msg, 0, 1000), $link, 'USUARIO_LINK']);
                        if ((int)($r[0]['codalerta'] ?? 0) > 0) $alertas++;
                    }
                }
            }
            $info[$escopo] = ['examinados' => count($codigos), 'com_arquivo' => $comArquivo, 'mudancas' => count($mudancas), 'por_regra' => $porRegra,
                'responsaveis_avisados' => count($porUsuario), 'alertas_criados' => $alertas, 'tempo_ms' => (int)round((microtime(true) - $t0) * 1000)];
        }
        // Migração 348: a integração aprovada pelo portal prevalece sobre a data mais antiga que o sincronismo trouxer do legado
        if (!$simular) { try { $info['integracoes_reaplicadas'] = IntegracaoFuncionario::reaplicar($pdo); } catch (Throwable $x) { $info['integracoes_reaplicadas'] = 'erro: ' . $x->getMessage(); } }
        $info['status'] = $simular ? 'SIMULADO' : 'CONCLUIDA';
    } catch (Throwable $e) { $info['status'] = 'ERRO'; $info['erro'] = $e->getMessage(); }
    $saida['bancos'][$banco] = $info;
}
$saida['fim'] = date('c');
$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$logs = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logs)) @mkdir($logs, 0775, true);
@file_put_contents($logs . DIRECTORY_SEPARATOR . 'homologacao_documentos_' . date('Y-m-d') . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
flock($trava, LOCK_UN);
fclose($trava);
exit(0);
