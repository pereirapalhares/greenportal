<?php
declare(strict_types=1);

/*
 * Migração 366 — Não Conformidades e Fornecedores nos dashboards pessoal e da empresa (pedido do usuário de 16/11/2026).
 * Cada empresa: database/366_dashboards_nc_fornecedores.sql — só views. Blocos [NC] só onde existem nc_indicacao_ocorrencia,
 * nc_workflow_historico e fn_nc_etapa (321); blocos [FO] só onde existem vw_homologacao_painel_itens (298) e nc_indicacao_ocorrencia.
 * Universal: trecho <!-- ml366 --> da Ajuda de dashboard e dashboard_admin.
 * Telas (já na pasta): principal/dashboard.php, principal/dashboard_admin.php, classes/DashboardNcFornecedores.php.
 * Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-366-dashboards-nc-fornecedores.php [--simular] [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$rel = ['ok' => true, 'migracao' => '366', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal' => [], 'bancos' => []];

$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'usuarios') AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 2 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && $b !== 'glegislacao_dbp' && ($soBanco === null || $b === $soBanco)));
if (!$bancos) $rel['bancos'] = ['aviso' => 'nenhuma empresa encontrada' . ($soBanco ? " ($soBanco)" : '')];
$sql = (string)file_get_contents(dirname(__DIR__) . '/database/366_dashboards_nc_fornecedores.sql');
$blocos = [];
foreach (preg_split('/^-- @@/m', $sql) as $i => $b) {
    if ($i === 0) continue; // cabeçalho
    $marca = preg_match('/^\s*\[(NC|FO)\]/', $b, $m) ? $m[1] : '';
    $corpo = trim((string)preg_replace('/^--.*$/m', '', (string)preg_replace('/^\s*\[(NC|FO)\][^\n]*/', '', $b)));
    if ($corpo !== '') $blocos[] = [$marca, $corpo, preg_match('/VIEW `([a-z0-9_]+)`/', $corpo, $v) ? $v[1] : '?'];
}

foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $existe = static function (string $tipo, string $nome) use ($pdo): bool {
            $sqlE = match ($tipo) {
                'tabela' => 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                'funcao' => "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = ? AND ROUTINE_TYPE = 'FUNCTION'",
            };
            $s = $pdo->prepare($sqlE); $s->execute([$nome]); $n = (int)$s->fetchColumn(); $s->closeCursor(); return $n > 0;
        };
        $temNc = $existe('tabela', 'nc_indicacao_ocorrencia') && $existe('tabela', 'nc_workflow_historico') && $existe('funcao', 'fn_nc_etapa');
        $temFo = $existe('tabela', 'vw_homologacao_painel_itens') && $existe('tabela', 'fo_fornecedores') && $existe('tabela', 'nc_indicacao_ocorrencia');
        // Os textos fixos das views (ex.: 'DOCUMENTO', 'Finalizado') seguem a collation da conexão: usa a do banco, a mesma
        // das views antigas (vw_homologacao_painel_itens e outras), para não dar "Illegal mix of collations".
        $colBanco = (string)$pdo->query('SELECT @@collation_database')->fetchColumn();
        if (preg_match('/^utf8mb4_[a-z0-9_]+$/', $colBanco)) $pdo->exec("SET NAMES utf8mb4 COLLATE $colBanco");
        $info['collation'] = $colBanco;
        $feitos = []; $pulados = []; $falhas = [];
        foreach ($blocos as [$marca, $corpo, $view]) {
            if (($marca === 'NC' && !$temNc) || ($marca === 'FO' && !$temFo)) { $pulados[] = $view; continue; }
            try {
                if (!$simular) $pdo->exec($corpo);
                $feitos[] = $view;
            } catch (Throwable $e) { $falhas[$view] = $e->getMessage(); }
        }
        if ($falhas) { $rel['ok'] = false; $info['erros'] = $falhas; }
        $info['acao'] = ($simular ? 'seriam criadas: ' : 'views: ') . ($feitos ? implode(', ', $feitos) : 'nenhuma');
        if ($pulados) $info['pulados'] = implode(', ', $pulados) . ' (faltam ' . (!$temNc ? 'as tabelas/função de NC (321)' : '') . (!$temNc && !$temFo ? ' e ' : '') . (!$temFo ? 'o Painel de Homologação (298)' : '') . ')';
        if (!$simular && in_array('vw_dash_nc_resumo', $feitos, true)) {
            $r = $pdo->query('SELECT ABERTAS, GESTOR, ANALISE, EFICACIA, MAIS_1_ANO FROM vw_dash_nc_resumo')->fetch(PDO::FETCH_ASSOC);
            $info['nc'] = (int)$r['ABERTAS'] . ' abertas (gestor ' . (int)$r['GESTOR'] . ', análise/plano ' . (int)$r['ANALISE'] . ', eficácia ' . (int)$r['EFICACIA'] . ', mais de 1 ano ' . (int)$r['MAIS_1_ANO'] . ')';
        }
        if (!$simular && in_array('vw_dash_fornecedor_resumo', $feitos, true)) {
            $r = $pdo->query('SELECT ATIVOS, SEM_RESPONSAVEL, AVALIACAO_VENCIDA, SEM_DATA_AVALIACAO FROM vw_dash_fornecedor_resumo')->fetch(PDO::FETCH_ASSOC);
            $info['fornecedores'] = (int)$r['ATIVOS'] . ' ativos (sem responsável ' . (int)$r['SEM_RESPONSAVEL'] . ', avaliação vencida ' . (int)$r['AVALIACAO_VENCIDA'] . ', sem data ' . (int)$r['SEM_DATA_AVALIACAO'] . ')';
        }
    } catch (Throwable $e) {
        $rel['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $rel['bancos'][$banco] = $info;
}

// ---------------------------------------------------------------- universal: Ajuda
try {
    $u = GreenPortalDatabase::connect($env, $bancoUniversal, 'GUNIVERSAL');
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (!MigracaoCrud::existe($u, 'ajuda_transacoes')) throw new RuntimeException('sem a tabela ajuda_transacoes no universal');
    foreach (require dirname(__DIR__) . '/database/366_ajuda_dashboards.php' as $transacao => $d) {
        $atual = null;
        foreach (MigracaoCrud::selecionar($u, 'ajuda_transacoes', ['transacao' => $transacao]) as $r) if (strcasecmp((string)($r['transacao'] ?? ''), $transacao) === 0) { $atual = $r; break; }
        $dados = [];
        foreach (['pt', 'en', 'es'] as $lg) {
            $velho = trim((string)preg_replace('/\s*<!-- ml366 -->.*?<!-- \/ml366 -->/s', '', (string)($atual['texto_' . $lg] ?? '')));
            $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . "<!-- ml366 -->\n" . $d['texto'][$lg] . "\n<!-- /ml366 -->";
            if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
        }
        if ($atual !== null) {
            $igual = true;
            foreach ($dados as $k => $v) if ((string)($atual[$k] ?? '') !== $v) $igual = false;
            if ($igual) { $rel['universal']['ajuda'][$transacao] = 'igual'; continue; }
        }
        if ($simular) { $rel['universal']['ajuda'][$transacao] = $atual === null ? 'será incluída' : 'será atualizada'; continue; }
        if ($atual === null) MigracaoCrud::inserir($u, 'ajuda_transacoes', ['transacao' => $transacao] + $dados + ['ativo' => 1]);
        else {
            $chave = ['transacao' => (string)$atual['transacao']];
            foreach (['id', 'codajuda', 'codigo', 'codajudatransacao'] as $pk) if (isset($atual[$pk]) && $atual[$pk] !== '') { $chave = [$pk => $atual[$pk]]; break; }
            MigracaoCrud::atualizar($u, 'ajuda_transacoes', $chave, $dados);
        }
        $rel['universal']['ajuda'][$transacao] = $atual === null ? 'incluída' : 'atualizada';
    }
} catch (Throwable $e) {
    $rel['ok'] = false;
    $rel['universal']['erro'] = $e->getMessage();
}

$logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (is_dir($logDir)) @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'migracao_366_dashboards' . ($simular ? '_simulacao' : '') . '.json', json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
