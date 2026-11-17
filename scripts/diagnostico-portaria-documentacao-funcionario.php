<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) para a tela Portaria › Documentação de Funcionário
 * (legado: transação foFuncionariosXCriterios em hpr_configuracoes).
 *  1. Configuração do legado: linha de configuracoes_transacoes (consulta, botões, eventos) e colunas do grid
 *     (configuracoes_grids), mais as demais tabelas de configuração ligadas pelo codtransacao.
 *  2. Em cada cliente: tabelas citadas na consulta do legado (novo x legado), colunas e valores (com contagem) das
 *     colunas de situação/datas de fo_funcionarioxtiposxcriterios, quantas linhas a consulta do legado traz
 *     (sem a restrição de usuário) e as pastas do GED candidatas.
 * Nada é alterado.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-portaria-documentacao-funcionario.php
 * Saída: tela (resumo) + storage/logs/diagnostico_portaria_documentacao_funcionario.json (completo)
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$transacao = 'foFuncionariosXCriterios';
$env = GreenPortalDatabase::env();
$saida = ['diagnostico' => 'portaria documentação de funcionário', 'transacao' => $transacao, 'data' => date('c')];
$curto = static fn($v, int $n = 4000) => is_string($v) && mb_strlen($v) > $n ? mb_substr($v, 0, $n) . '…' : $v;

// 1. Configuração do legado
$consulta = '';
try {
    $h = null;
    foreach (['LEGADO_SEGURANCA', 'GPT'] as $prefixo) {
        if (empty($env[$prefixo . '_DB_HOST'])) continue;
        try { $h = GreenPortalDatabase::connect($env, 'hpr_configuracoes', $prefixo); break; } catch (Throwable) {}
    }
    if (!$h) throw new RuntimeException('não conectou em hpr_configuracoes');
    $h->exec('SET NAMES utf8mb4');
    $s = $h->prepare('SELECT * FROM configuracoes_transacoes WHERE LOWER(dsctransacao) = LOWER(?)'); $s->execute([$transacao]);
    $tr = $s->fetchAll(PDO::FETCH_ASSOC);
    if (!$tr) throw new RuntimeException("transação $transacao não encontrada");
    $saida['legado']['transacao'] = array_map(static fn($r) => array_map(static fn($v) => $curto($v), array_filter($r, static fn($v) => $v !== null && $v !== '')), $tr);
    $cod = $tr[0]['codtransacao'] ?? null;
    foreach ($tr[0] as $k => $v) if (preg_match('/query/i', (string)$k) && is_string($v) && stripos($v, 'select') !== false && $consulta === '') $consulta = $v;
    // Demais tabelas de configuração com codtransacao
    $tabs = $h->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND LOWER(COLUMN_NAME) = 'codtransacao' AND TABLE_NAME <> 'configuracoes_transacoes'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tabs as $t) {
        $s = $h->prepare("SELECT * FROM `$t` WHERE codtransacao = ? LIMIT 200"); $s->execute([$cod]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) $saida['legado']['configuracao'][$t] = array_map(static fn($r) => array_map(static fn($v) => $curto($v, 600), array_filter($r, static fn($v) => $v !== null && $v !== '')), $rows);
    }
} catch (Throwable $e) { $saida['legado']['erro'] = $e->getMessage(); }

// Tabelas citadas na consulta
preg_match_all('/#DATABASE#\.([A-Za-z0-9_]+)/', $consulta, $m);
$tabelas = array_values(array_unique(array_map('strtolower', $m[1] ?? [])));
$tabelas = array_values(array_unique(array_merge($tabelas, ['fo_funcionarioxtiposxcriterios', 'fo_funcionarios', 'fo_criteriofuncionarios', 'fo_tipofuncionarios', 'fo_fornecedores', 'unidades'])));
$saida['tabelas_da_consulta'] = $tabelas;

// 2. Clientes
foreach (['gatlas_dbp' => 'green_atlas', 'gmodelo_dbp' => 'green_modelo'] as $banco => $legado) {
    $r = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $v = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $v; };
        $existe = static fn(string $b, string $t): bool => (bool)$q('SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$b, $t])[0]['n'];
        foreach ($tabelas as $t) {
            $r['tabelas'][$t] = ['novo' => $existe($banco, $t) ? (int)$q("SELECT COUNT(*) n FROM `$banco`.`$t`")[0]['n'] : 'NÃO EXISTE',
                'legado' => $existe($legado, $t) ? (int)$q("SELECT COUNT(*) n FROM `$legado`.`$t`")[0]['n'] : 'NÃO EXISTE'];
        }
        $cols = array_column($q("SELECT COLUMN_NAME c, COLUMN_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'fo_funcionarioxtiposxcriterios' ORDER BY ORDINAL_POSITION", [$banco]), 't', 'c');
        $r['colunas_fo_funcionarioxtiposxcriterios'] = $cols;
        foreach (array_keys($cols) as $c) {
            if (preg_match('/^(cod|codigo)/i', $c) || preg_match('/obs/i', $c)) continue;
            if (preg_match('/date|time/i', (string)$cols[$c])) {
                $r['datas'][$c] = $q("SELECT SUM(`$c` IS NULL OR `$c` < '1900-01-01') vazias, SUM(`$c` < CURDATE()) vencidas, SUM(`$c` BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY) ate_30_dias, SUM(`$c` > CURDATE() + INTERVAL 30 DAY) depois FROM `$banco`.fo_funcionarioxtiposxcriterios")[0];
            } else {
                $r['valores'][$c] = array_map(static fn($x) => ($x['v'] ?? '(vazio)') . ' = ' . $x['n'], $q("SELECT `$c` v, COUNT(*) n FROM `$banco`.fo_funcionarioxtiposxcriterios GROUP BY `$c` ORDER BY n DESC LIMIT 15"));
            }
        }
        // Consulta do legado no banco novo (sem restrição de usuário)
        if ($consulta !== '') {
            $sql = str_ireplace(['#DATABASE#_seguranca', '#DATABASE#'], [$legado . '_seguranca', '`' . $banco . '`'], $consulta);
            $sql = preg_replace('/#WHERE#/i', '', $sql) ?? $sql;
            $sql = preg_replace('/and\s+[^()]*in\s*\(\s*select[^)]*#USUARIO_LOGADO#[^)]*\)/i', '', $sql) ?? $sql;
            $sql = str_ireplace('#USUARIO_LOGADO#', '0', $sql);
            $sql = preg_replace('/order\s+by[\s\S]*$/i', '', $sql) ?? $sql;
            try { $r['linhas_da_consulta_do_legado'] = (int)$q("SELECT COUNT(*) n FROM ($sql) x")[0]['n']; }
            catch (Throwable $e) { $r['linhas_da_consulta_do_legado'] = 'não executou: ' . mb_substr($e->getMessage(), 0, 300); }
        }
        $r['usuarios_unidades'] = $existe($banco, 'usuariosxunidades') ? 'usuariosxunidades existe' : 'só usuarios.codunidade';
    } catch (Throwable $e) { $r['erro'] = $e->getMessage(); }
    foreach (['fo_funcionarioxtiposxcriterios', 'fo_funcionariosxcriterios', 'fo_funcionarioxcriterios', 'fo_funcionarios'] as $p) {
        $dir = 'E:\\projetoGreen\\GED\\' . $legado . '\\' . $p;
        if (!is_dir($dir)) { $r['ged'][$p] = 'não existe'; continue; }
        $sub = glob($dir . '\\*', GLOB_ONLYDIR) ?: [];
        $arq = 0; foreach (array_slice($sub, 0, 3000) as $s) $arq += count(glob($s . '\\*') ?: []);
        $r['ged'][$p] = count($sub) . ' pastas, ' . $arq . ' itens; exemplo: ' . implode(', ', array_map('basename', array_slice($sub, 0, 5)));
    }
    $saida['bancos'][$banco] = $r;
}
$saida['consulta_legado'] = $consulta;
$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
@file_put_contents(dirname(__DIR__) . '/storage/logs/diagnostico_portaria_documentacao_funcionario.json', $json);
// Na tela, sem a configuração completa (está no arquivo)
$tela = $saida; unset($tela['legado']['configuracao']);
echo json_encode($tela, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
