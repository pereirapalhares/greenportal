<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/AdministracaoTextos.php';
/*
 * Relatório de uma execução do sincronismo (Administração › Migração › Sincronismo; migração 305).
 * Abre em popup a partir da tela. Lê o registro pela procedure sp_sinc_execucao_obter e o arquivo do relatório gravado
 * pelo serviço em storage\logs\sincronismo\execucao_<n>.json.
 * Migração 370: sub-abas (Resumo por empresa | Detalhe por empresa | Validação | Relatório completo) com as listas no grid
 * padrão (DashboardGrid / grid local), no lugar das tabelas e blocos empilhados.
 */
session_start();
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/AssistenteAplicacaoService.php';
require_once dirname(__DIR__) . '/components/pagina_padrao.php';
require_once dirname(__DIR__) . '/classes/DashboardGrid.php';

if (empty($_SESSION['autenticado'])) { http_response_code(401); exit(adm_t('Sessao expirada.')); }
if (strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) !== 'ADMINISTRADOR') { http_response_code(403); exit(adm_t('Acesso restrito ao perfil ADMINISTRADOR.')); }

$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$id = max(0, (int)($_GET['id'] ?? 0));
$x = AssistenteAplicacaoService::chamarProcedure($pdo, 'sp_sinc_execucao_obter', [$id])[0] ?? null;
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$num = static fn($n): string => number_format((int)$n, 0, ',', '.');
$dataBr = static fn($d): string => $d ? date('d/m/Y H:i:s', strtotime((string)$d)) : '';

$j = null; $bruto = ''; $aviso = '';
if ($x && $x['relatorio']) {
    $base = realpath(dirname(__DIR__) . '/storage/logs/sincronismo');
    $arq = realpath((string)$x['relatorio']);
    if ($base && $arq && str_starts_with($arq, $base . DIRECTORY_SEPARATOR) && is_file($arq)) {
        $bruto = (string)file_get_contents($arq);
        $p = strpos($bruto, '{');
        $j = $p === false ? null : json_decode(substr($bruto, $p), true);
        if (!is_array($j)) $aviso = adm_t('O arquivo do relatório não está em JSON (execução interrompida?).');
    } else $aviso = adm_t('Arquivo do relatório não encontrado (os relatórios ficam 90 dias).');
}
$rotModo = ['SIMULAR' => adm_t('Simulação (nada foi gravado)'), 'APLICAR' => adm_t('Aplicação (gravado no greenportal)'), 'VALIDAR' => adm_t('Validação (somente leitura)')];

$txt = static function ($v): string {
    if (!is_array($v)) return (string)($v ?? '');
    $p = [];
    foreach ($v as $k => $x) $p[] = (is_int($k) ? '' : $k . ': ') . (is_array($x) ? json_encode($x, JSON_UNESCAPED_UNICODE) : (string)$x);
    return implode('; ', $p);
};
$n = static fn($v): array => ['text' => $v === null || $v === '' ? '' : number_format((int)$v, 0, ',', '.'), 'sort' => (string)(int)($v ?? 0)];
$badge = static fn(string $st): array => ['text' => $st, 'html' => $st === '' ? '' : '<span class="badge text-bg-' . (str_starts_with($st, 'OK') ? 'success' : 'warning') . '">' . htmlspecialchars($st, ENT_QUOTES, 'UTF-8') . '</span>'];
/** Linhas de divergência da validação (grid "Validação"). */
$divergencias = static function (string $banco, string $grupo, array $lista) use ($txt, $n): array {
    $rows = [];
    foreach ($lista as $t => $d) {
        $d = (array)$d;
        $obs = implode(' · ', array_filter([$txt($d['situacao'] ?? ''), $txt($d['obs'] ?? ''), $txt($d['motivo'] ?? ''), isset($d['erro']) ? 'erro: ' . $txt($d['erro']) : '',
            isset($d['incluidos']) ? 'mapeada: +' . (int)$d['incluidos'] . ' ~' . (int)($d['alterados'] ?? 0) . ' −' . (int)($d['excluidos'] ?? 0) : '']));
        $rows[] = ['cells' => ['BANCO' => $banco, 'GRUPO' => $grupo, 'TABELA' => (string)$t, 'LEGADO' => $n($d['linhas_legado'] ?? null), 'GREEN' => $n($d['linhas_greenportal'] ?? null),
            'FALTAM' => $n($d['faltam_no_greenportal'] ?? null), 'SOBRAM' => $n($d['sobram_no_greenportal'] ?? null), 'OBS' => ['text' => $obs, 'title' => $obs]]];
    }
    return $rows;
};
/** Linhas das tabelas que tiveram mudança (grid "Detalhe por empresa"). */
$mudancas = static function (string $banco, string $grupo, array $tabelas, array $ignorar) use ($n): array {
    $rows = [];
    foreach ($tabelas as $t => $d) {
        $d = (array)$d;
        $incl = (int)($d['incluidos'] ?? 0) + (int)($d['afetados'] ?? 0); $alt = (int)($d['alterados'] ?? 0); $exc = (int)($d['excluidos'] ?? 0);
        $ign = 0; foreach ($ignorar as $c) $ign += (int)($d[$c] ?? 0);
        if ($incl + $alt + $exc + $ign === 0 && !isset($d['erro'])) continue;
        $det = isset($d['erro']) ? 'erro: ' . (is_array($d['erro']) ? json_encode($d['erro'], JSON_UNESCAPED_UNICODE) : $d['erro'])
            : (isset($d['colunas_alteradas']) ? 'colunas: ' . implode(', ', array_map(static fn($k, $v) => "$k ($v)", array_keys((array)$d['colunas_alteradas']), (array)$d['colunas_alteradas']))
            : (isset($d['origem']) && is_numeric($d['origem']) ? adm_t('linhas no legado: ') . number_format((int)$d['origem'], 0, ',', '.') : (string)($d['origem'] ?? '')));
        $rows[] = ['cells' => ['BANCO' => $banco, 'GRUPO' => $grupo, 'TABELA' => (string)$t, 'INCL' => $n($incl), 'ALT' => $n($alt), 'EXCL' => $n($exc), 'IGN' => $n($ign),
            'DETALHE' => ['text' => $det, 'title' => $det, 'html' => isset($d['erro']) ? '<span class="text-danger">' . htmlspecialchars($det, ENT_QUOTES, 'UTF-8') . '</span>' : htmlspecialchars($det, ENT_QUOTES, 'UTF-8')]],
            'grupos' => isset($d['erro']) ? 'ERRO' : ''];
    }
    return $rows;
};
$colDiv = ['BANCO' => ['label' => adm_t('Banco'), 'filter' => 'select', 'width' => '140px'], 'GRUPO' => ['label' => adm_t('Grupo'), 'filter' => 'select', 'width' => '170px'], 'TABELA' => ['label' => adm_t('Tabela'), 'width' => '200px'],
    'LEGADO' => ['label' => adm_t('Legado'), 'filter' => 'none', 'width' => '85px'], 'GREEN' => ['label' => adm_t('Greenportal'), 'filter' => 'none', 'width' => '90px'],
    'FALTAM' => ['label' => adm_t('Faltam no greenportal'), 'filter' => 'none', 'width' => '100px'], 'SOBRAM' => ['label' => adm_t('Sobram no greenportal'), 'filter' => 'none', 'width' => '100px'], 'OBS' => ['label' => adm_t('Observação')]];
$op = ['transacao' => 'administracao_sincronismo'];
$abas = []; $extrasValid = '';
if (is_array($j) && $x['modo'] === 'VALIDAR') {
    $rowsSt = []; $rowsDiv = [];
    foreach ((array)($j['bancos'] ?? []) as $b => $v) {
        $v = (array)$v; $st = (string)($v['status'] ?? ($v['erro'] ?? ''));
        $semCorr = !empty($v['tabelas_do_legado_sem_correspondente']) ? implode(', ', array_map(static fn($k, $val) => is_int($k) ? (string)$val : "$k ($val)", array_keys((array)$v['tabelas_do_legado_sem_correspondente']), (array)$v['tabelas_do_legado_sem_correspondente'])) : '';
        $rowsSt[] = ['cells' => ['BANCO' => (string)$b, 'STATUS' => $badge($st), 'FO' => $n($v['fo_nc']['tabelas_verificadas'] ?? 0), 'FO_DIV' => $n(count((array)($v['fo_nc']['divergentes'] ?? []))),
            'LEGAL' => $n($v['legal']['tabelas_verificadas'] ?? 0), 'LEGAL_DIV' => $n(count((array)($v['legal']['divergentes'] ?? []))), 'SEMCORR' => ['text' => $semCorr, 'title' => $semCorr]],
            'grupos' => str_starts_with($st, 'OK') ? 'OK' : 'DIVERGE'];
        array_push($rowsDiv, ...$divergencias((string)$b, adm_t('Homologação e NC'), (array)($v['fo_nc']['divergentes'] ?? [])), ...$divergencias((string)$b, adm_t('Módulo Legal'), (array)($v['legal']['divergentes'] ?? [])));
    }
    if (isset($j['legislacao_central'])) array_push($rowsDiv, ...$divergencias('glegislacao_dbp', adm_t('Legislação central'), (array)($j['legislacao_central']['divergentes'] ?? [])));
    $abas[] = ['id' => 'bancos', 'icone' => 'bi-database-check', 'rotulo' => adm_t('Validação por empresa'), 'contagem' => count($rowsSt), 'html' => DashboardGrid::grid('srBancos', [
        'BANCO' => ['label' => adm_t('Banco'), 'width' => '160px'], 'STATUS' => ['label' => adm_t('Situação'), 'filter' => 'select', 'width' => '160px'],
        'FO' => ['label' => adm_t('Homologação e NC: tabelas'), 'filter' => 'none', 'width' => '110px'], 'FO_DIV' => ['label' => adm_t('Divergentes'), 'filter' => 'none', 'width' => '90px'],
        'LEGAL' => ['label' => adm_t('Módulo Legal: tabelas'), 'filter' => 'none', 'width' => '110px'], 'LEGAL_DIV' => ['label' => adm_t('Divergentes'), 'filter' => 'none', 'width' => '90px'],
        'SEMCORR' => ['label' => adm_t('Tabelas do legado sem correspondente')],
    ], $rowsSt, $op)];
    $abas[] = ['id' => 'divergencias', 'icone' => 'bi-exclamation-triangle', 'rotulo' => adm_t('Divergências'), 'contagem' => count($rowsDiv), 'html' => DashboardGrid::grid('srDivergencias', $colDiv, $rowsDiv, $op + ['vazio' => adm_t('Sem divergências.')])];
} elseif (is_array($j)) {
    $rowsRes = [];
    foreach ((array)($j['bancos'] ?? []) as $b => $r) {
        $r = (array)$r; $t = (array)($r['totais'] ?? []); $m = (array)($j['mapeadas'][$b]['totais'] ?? []); $l = (array)($j['legal_empresas'][$b]['totais'] ?? []); $v = (array)($j['validacao'][$b] ?? []);
        $st = (string)($v['status'] ?? ($v['erro'] ?? ''));
        $erro = (string)($r['erro'] ?? ($j['legal_empresas'][$b]['erro'] ?? ($j['mapeadas'][$b]['erro'] ?? '')));
        $rowsRes[] = ['cells' => ['BANCO' => (string)$b, 'INCL' => $n(($t['incluidos'] ?? 0) + ($m['incluidos'] ?? 0)), 'ALT' => $n(($t['alterados'] ?? 0) + ($m['alterados'] ?? 0)), 'EXCL' => $n(($t['excluidos'] ?? 0) + ($m['excluidos'] ?? 0)),
            'IGN' => $n(($t['ignorados_dependencia'] ?? 0) + ($t['ignorados_duplicidade'] ?? 0)), 'ML' => $n($l['afetados'] ?? 0), 'ML_EXC' => $n($l['excluidos'] ?? 0),
            'VALID' => $st !== '' ? $badge($st) : ['text' => $x['modo'] === 'SIMULAR' ? adm_t('só na aplicação') : '', 'html' => $x['modo'] === 'SIMULAR' ? ('<span class="text-muted">' . adm_t('só na aplicação') . '</span>') : ''],
            'ERRO' => ['text' => $erro, 'title' => $erro, 'html' => '<span class="text-danger">' . $e($erro) . '</span>']], 'grupos' => $erro !== '' ? 'ERRO' : ''];
    }
    $c = (array)($j['legislacao_central'] ?? []);
    if ($c) $rowsRes[] = ['cells' => ['BANCO' => adm_t('Legislação central'), 'INCL' => $n($c['totais']['afetados'] ?? 0), 'ALT' => $n(null), 'EXCL' => $n($c['totais']['excluidos'] ?? 0), 'IGN' => $n(null), 'ML' => $n(null), 'ML_EXC' => $n(null),
        'VALID' => '', 'ERRO' => ['text' => (string)($c['erro'] ?? ($c['motivo'] ?? '')), 'html' => '<span class="text-danger">' . $e($c['erro'] ?? ($c['motivo'] ?? '')) . '</span>']]];
    $fora = !empty($j['fora_da_execucao']) ? ('<div class="alert alert-warning py-1 small mb-2">' . adm_t('Fora desta execução (erro na pré-validação):') . ' ') . $e(json_encode($j['fora_da_execucao'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</div>' : '';
    $nc = ['filter' => 'none', 'width' => '95px'];
    $abas[] = ['id' => 'resumo', 'icone' => 'bi-list-check', 'rotulo' => adm_t('Resumo por empresa'), 'contagem' => count((array)($j['bancos'] ?? [])), 'html' => $fora . DashboardGrid::grid('srResumo', [
        'BANCO' => ['label' => adm_t('Banco'), 'width' => '160px'], 'INCL' => ['label' => adm_t('Incluídos')] + $nc, 'ALT' => ['label' => adm_t('Alterados')] + $nc, 'EXCL' => ['label' => adm_t('Excluídos')] + $nc,
        'IGN' => ['label' => adm_t('Ignorados (dependência/duplicidade)')] + $nc, 'ML' => ['label' => adm_t('Módulo Legal (incl./alt.)')] + $nc, 'ML_EXC' => ['label' => adm_t('Módulo Legal (excl.)')] + $nc,
        'VALID' => ['label' => adm_t('Validação'), 'filter' => 'select', 'width' => '150px'], 'ERRO' => ['label' => adm_t('Erro')],
    ], $rowsRes, $op)];
    $rowsDet = []; $rowsDiv = []; $restantes = '';
    foreach ((array)($j['bancos'] ?? []) as $b => $r) {
        $r = (array)$r;
        array_push($rowsDet, ...$mudancas((string)$b, adm_t('Homologação e NC'), (array)($r['tabelas'] ?? []), ['ignorados_dependencia', 'ignorados_duplicidade']));
        array_push($rowsDet, ...$mudancas((string)$b, adm_t('Tabelas mapeadas'), (array)($j['mapeadas'][$b]['tabelas'] ?? []), []));
        array_push($rowsDet, ...$mudancas((string)$b, adm_t('Módulo Legal'), (array)($j['legal_empresas'][$b]['tabelas'] ?? []), ['protegidos']));
        if (isset($j['validacao'][$b])) {
            $v = (array)$j['validacao'][$b];
            array_push($rowsDiv, ...$divergencias((string)$b, adm_t('Homologação e NC'), (array)($v['fo_nc']['divergentes'] ?? [])), ...$divergencias((string)$b, adm_t('Módulo Legal'), (array)($v['legal']['divergentes'] ?? [])));
            if (!empty($v['diferencas_restantes'])) $restantes .= '<div class="small text-muted mt-2">' . $e($b) . (adm_t(': diferenças que um novo sincronismo ainda faria') . '</div><div class="sr-json">') . $e(json_encode($v['diferencas_restantes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</div>';
        }
    }
    array_push($rowsDet, ...$mudancas('glegislacao_dbp', adm_t('Legislação central'), (array)($j['legislacao_central']['tabelas'] ?? []), ['protegidos']));
    $abas[] = ['id' => 'detalhe', 'icone' => 'bi-table', 'rotulo' => adm_t('Detalhe por empresa'), 'contagem' => count($rowsDet), 'html' => DashboardGrid::grid('srDetalhe', [
        'BANCO' => ['label' => adm_t('Banco'), 'filter' => 'select', 'width' => '140px'], 'GRUPO' => ['label' => adm_t('Grupo'), 'filter' => 'select', 'width' => '150px'], 'TABELA' => ['label' => adm_t('Tabela'), 'width' => '200px'],
        'INCL' => ['label' => adm_t('Incluídos')] + $nc, 'ALT' => ['label' => adm_t('Alterados')] + $nc, 'EXCL' => ['label' => adm_t('Excluídos')] + $nc, 'IGN' => ['label' => adm_t('Ignorados / protegidos')] + $nc, 'DETALHE' => ['label' => adm_t('Detalhe')],
    ], $rowsDet, $op + ['vazio' => adm_t('Nenhuma mudança.')])];
    if (isset($j['validacao']) || $rowsDiv) $abas[] = ['id' => 'validacao', 'icone' => 'bi-database-check', 'rotulo' => adm_t('Validação depois de gravar'), 'contagem' => count($rowsDiv),
        'html' => DashboardGrid::grid('srValidacao', $colDiv, $rowsDiv, $op + ['vazio' => adm_t('Sem divergências.')]) . $restantes];
}
if ($bruto !== '') $abas[] = ['id' => 'json', 'icone' => 'bi-filetype-json', 'rotulo' => adm_t('Relatório completo (JSON)'),
    'html' => '<div class="sr-json">' . $e(strlen($bruto) > 2000000 ? substr($bruto, 0, 2000000) . "\n… (cortado; arquivo completo em " . $x['relatorio'] . ')' : $bruto) . '</div>'];
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= adm_h('Relatório do sincronismo #') ?><?= $e($id) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php DashboardGrid::head(); ?>
<style>
html,body{height:auto}body{padding:14px 16px}h2{font-size:16px}
.sr-json{font-family:ui-monospace,Consolas,monospace;font-size:11px;white-space:pre;max-height:calc(100vh - 220px);overflow:auto;background:#f7faf8;border-radius:6px;padding:8px}
</style>
</head>
<body>
<?php if (!$x): ?><div class="alert alert-warning"><?= adm_h('Execução #') ?><?= $e($id) ?> <?= adm_h('não encontrada.') ?></div></body></html><?php exit; endif; ?>
<h2><i class="bi bi-journal-text me-1"></i><?= adm_h('Sincronismo #') ?><?= $e($id) ?> — <?= $e($rotModo[$x['modo']] ?? $x['modo']) ?></h2>
<p class="text-muted mb-2"><?= $x['origem'] === 'AGENDADO' ? 'Agendado' : 'Manual' ?> · <?= $e($x['empresa'] ? ($x['empresa_nome'] ?: $x['empresa']) : adm_t('Todas as empresas')) ?> · <?= $e($x['usuario_nome'] ?? '') ?>
 <?= adm_h('· solicitado') ?> <?= $e($dataBr($x['solicitado_em'])) ?><?= $x['iniciado_em'] ? adm_t(' · início ') . $e($dataBr($x['iniciado_em'])) : '' ?><?= $x['finalizado_em'] ? ' · fim ' . $e($dataBr($x['finalizado_em'])) : '' ?>
 · <strong><?= $e($x['status']) ?></strong><?= $x['codsimulacao'] ? adm_t(' · da simulação #') . $e($x['codsimulacao']) : '' ?></p>
<?php if ($x['erro']): ?><div class="alert alert-<?= $x['status'] === 'ERRO' ? 'danger' : 'warning' ?> py-2 small"><?= $e($x['erro']) ?></div><?php endif; ?>
<?php if ($aviso): ?><div class="alert alert-warning py-2 small"><?= $e($aviso) ?></div><?php endif; ?>
<?= DashboardGrid::subabas('sincronismo-relatorio', $abas, true) ?>
<?php DashboardGrid::scripts(); ?>
</body></html>
