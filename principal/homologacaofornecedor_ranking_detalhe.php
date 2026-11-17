<?php
declare(strict_types=1);
/*
 * Detalhe da nota de um fornecedor no Ranking (popup de 90% aberto por homologacaofornecedor_ranking.php).
 * Mostra cada componente (peso, nota, quanto contribui), o bloqueio, a evolução da nota e o que está puxando a nota para
 * baixo: documentos do fornecedor e dos funcionários fora do prazo/pendentes, treinamentos e não conformidades.
 * Só leitura por views (vw_fornecedor_ranking[_acesso], views da Portaria, vw_fornecedor_ranking_treinamentos/_ncs/_historico).
 * Migração 369 (16/11/2026): nota, faixa e bloqueio no topo; abaixo, sub-abas (Composição da nota, Documentos do fornecedor,
 * Funcionários, Não conformidades) com as listas no grid padrão (classes/DashboardGrid.php + grid-local.js).
 */
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/FornecedorRanking.php';
require_once dirname(__DIR__) . '/classes/DashboardGrid.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$pdo = TransactionSecurity::connect(FornecedorRanking::MENU_RANKING);
$T = static fn(string $chave, array $textos): string => IdiomaService::t('ranking_fornecedores.' . $chave, $textos);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$n = [FornecedorRanking::class, 'numero'];
$cod = max(0, (int)($_GET['fornecedor'] ?? 0));
$f = $cod > 0 ? (FornecedorRanking::fornecedores($pdo, ['CODFORNECEDOR' => $cod]) ?? [])[0] ?? null : null;
if (!$f) { http_response_code(404); exit($e($T('nao_encontrado', ['pt' => 'Fornecedor não encontrado ou fora das suas unidades.', 'en' => 'Supplier not found or outside your units.', 'es' => 'Proveedor no encontrado o fuera de sus unidades.']))); }
$cfg = (FornecedorRanking::ler($pdo, 'vw_fornecedor_ranking_config') ?? [])[0] ?? [];
$pendente = static fn(array $l): bool => !in_array((string)($l['SITUACAO_COR'] ?? ''), ['aprovado', 'nao-aplica'], true);
$docs = array_values(array_filter(FornecedorRanking::ler($pdo, 'vw_portaria_documentacao_fornecedor_grid', ['CODFORNECEDOR' => $cod]) ?? [], $pendente));
$func = array_values(array_filter(FornecedorRanking::ler($pdo, 'vw_portaria_documentacao_funcionario_grid', ['CODFORNECEDOR' => $cod]) ?? [], $pendente));
$trein = array_values(array_filter(FornecedorRanking::ler($pdo, 'vw_fornecedor_ranking_treinamentos', ['CODFORNECEDOR' => $cod]) ?? [], $pendente));
$ncs = FornecedorRanking::ler($pdo, 'vw_fornecedor_ranking_ncs', ['CODFORNECEDOR' => $cod]) ?? [];
$hist = FornecedorRanking::ler($pdo, 'vw_fornecedor_ranking_historico', ['CODFORNECEDOR' => $cod]) ?? [];
usort($hist, static fn($a, $b) => strcmp((string)$a['DATA'], (string)$b['DATA']));
$hist = array_slice(array_values(array_filter($hist, static fn($h) => $h['NOTA'] !== null)), -90);
usort($ncs, static fn($a, $b) => [$b['ABERTA'] === 'Sim', (string)$b['DATA_OCORRENCIA']] <=> [$a['ABERTA'] === 'Sim', (string)$a['DATA_OCORRENCIA']]);
$ordemCor = ['vencido' => 0, 'reprovado' => 1, 'pendente-fornecedor' => 2, 'pendente-empresa' => 3, 'sem-situacao' => 4, 'a-vencer' => 5];
foreach ([&$docs, &$func, &$trein] as &$lista) usort($lista, static fn($a, $b) => ($ordemCor[$a['SITUACAO_COR']] ?? 9) <=> ($ordemCor[$b['SITUACAO_COR']] ?? 9));
unset($lista);
$corSit = ['vencido' => 'danger', 'reprovado' => 'danger', 'pendente-fornecedor' => 'warning', 'pendente-empresa' => 'warning', 'sem-situacao' => 'secondary', 'a-vencer' => 'info'];
$dataBr = static fn($v): string => $v && substr((string)$v, 0, 4) > '1900' ? date('d/m/Y', strtotime((string)$v)) : '–';
$faixa = (string)($f['FAIXA'] ?? '');
$cor = FornecedorRanking::CORES[$faixa] ?? '#9aa5a0';
// Componentes: peso efetivo (redistribuído quando o componente não existe para o fornecedor)
$comp = [
    ['doc', $T('c.doc', ['pt' => 'Documentação do fornecedor', 'en' => 'Supplier documents', 'es' => 'Documentación del proveedor']), (float)($cfg['PESO_DOC_FORNECEDOR'] ?? 0), $f['NOTA_DOC_FORNECEDOR'],
        sprintf($T('c.doc_det', ['pt' => '%d de %d critérios exigidos em dia', 'en' => '%d of %d required criteria up to date', 'es' => '%d de %d criterios exigidos al día']), (int)$f['DOC_OK'], (int)$f['DOC_TOTAL'])],
    ['func', $T('c.func', ['pt' => 'Documentação dos funcionários', 'en' => 'Employee documents', 'es' => 'Documentación de los empleados']), (float)($cfg['PESO_DOC_FUNCIONARIOS'] ?? 0), $f['NOTA_DOC_FUNCIONARIOS'],
        sprintf($T('c.func_det', ['pt' => '%d de %d critérios e treinamentos em dia · %d funcionário(s) ativo(s)', 'en' => '%d of %d criteria and trainings up to date · %d active employee(s)', 'es' => '%d de %d criterios y capacitaciones al día · %d empleado(s) activo(s)']), (int)$f['FUNC_OK'], (int)$f['FUNC_TOTAL'], (int)$f['FUNCIONARIOS'])],
    ['nc', $T('c.nc', ['pt' => 'Não conformidades', 'en' => 'Nonconformities', 'es' => 'No conformidades']), (float)($cfg['PESO_NC'] ?? 0), $f['NOTA_NC'],
        sprintf($T('c.nc_det', ['pt' => '%d aberta(s), %d atrasada(s), %d fechada(s) nos últimos %d meses', 'en' => '%d open, %d overdue, %d closed in the last %d months', 'es' => '%d abierta(s), %d atrasada(s), %d cerrada(s) en los últimos %d meses']), (int)$f['NC_ABERTAS'], (int)$f['NC_ATRASADAS'], (int)$f['NC_FECHADAS'], (int)($cfg['NC_JANELA_MESES'] ?? 12))],
    ['aud', $T('c.aud', ['pt' => 'Auditorias', 'en' => 'Audits', 'es' => 'Auditorías']), (float)($cfg['PESO_AUDITORIA'] ?? 0), $f['NOTA_AUDITORIA'],
        (int)$f['AUDITORIAS'] > 0 ? sprintf($T('c.aud_det', ['pt' => '%d auditoria(s) de homologação (vencida vale metade)', 'en' => '%d homologation audit(s) (expired counts half)', 'es' => '%d auditoría(s) de homologación (vencida vale la mitad)']), (int)$f['AUDITORIAS']) : $T('c.aud_sem', ['pt' => 'Sem auditoria registrada', 'en' => 'No audit recorded', 'es' => 'Sin auditoría registrada'])],
];
$somaPesos = 0.0;
foreach ($comp as $c) if ($c[3] !== null) $somaPesos += $c[2];
// Migração 369: listas no grid padrão
$situacao = static fn(array $d): array => ['text' => (string)($d['SITUACAO_DESCRICAO'] ?? $d['SITUACAO_COR']), 'html' => '<span class="badge text-bg-' . ($corSit[$d['SITUACAO_COR']] ?? 'secondary') . '">' . $e($d['SITUACAO_DESCRICAO'] ?? $d['SITUACAO_COR']) . '</span>'];
$abrirForn = ['url' => 'homologacaofornecedor_fornecedor.php?filtros=' . rawurlencode((string)json_encode(['CODFORNECEDOR' => (string)$cod])), 'title' => $T('abrir_fornecedor', ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor']) . ' - ' . $f['RAZAOSOCIAL']];
$abrirFunc = ['url' => 'homologacaofornecedor_funcionario.php?filtros=' . rawurlencode((string)json_encode(['CODFORNECEDOR' => (string)$cod])), 'title' => $T('abrir_funcionarios', ['pt' => 'Funcionários do fornecedor', 'en' => 'Supplier employees', 'es' => 'Empleados del proveedor']) . ' - ' . $f['RAZAOSOCIAL']];
$colSit = ['label' => $T('col.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']), 'filter' => 'select', 'width' => '170px'];
$colVal = ['label' => $T('col.validade', ['pt' => 'Validade', 'en' => 'Expiry', 'es' => 'Validez']), 'width' => '110px'];
$rowsDocs = [];
foreach ($docs as $d) $rowsDocs[] = ['cells' => ['CRITERIO' => ['text' => (string)($d['DSCRITERIO'] ?? ''), 'title' => (string)($d['DSCRITERIO'] ?? '')], 'SITUACAO' => $situacao($d), 'VALIDADE' => DashboardGrid::prazo($d['DATADEVALIDADE'] ?? null)], 'open' => $abrirForn];
$gridDocs = DashboardGrid::grid('rdDocs', ['CRITERIO' => ['label' => $T('col.criterio', ['pt' => 'Critério', 'en' => 'Criterion', 'es' => 'Criterio'])], 'SITUACAO' => $colSit, 'VALIDADE' => $colVal], $rowsDocs,
    ['vazio' => $T('docs_ok', ['pt' => 'Todos os documentos exigidos estão em dia.', 'en' => 'All required documents are up to date.', 'es' => 'Todos los documentos exigidos están al día.']), 'transacao' => 'homologacaofornecedor_ranking']);
$rowsFunc = [];
foreach ($func as $d) $rowsFunc[] = ['cells' => ['FUNCIONARIO' => (string)($d['FUNCIONARIO'] ?? ''), 'TIPO' => $T('tipo_criterio', ['pt' => 'Critério', 'en' => 'Criterion', 'es' => 'Criterio']), 'ITEM' => ['text' => (string)($d['DSCRITERIO'] ?? ''), 'title' => (string)($d['DSCRITERIO'] ?? '')], 'SITUACAO' => $situacao($d), 'VALIDADE' => DashboardGrid::prazo($d['DATADEVALIDADE'] ?? null)], 'open' => $abrirFunc];
foreach ($trein as $d) $rowsFunc[] = ['cells' => ['FUNCIONARIO' => (string)($d['FUNCIONARIO'] ?? ''), 'TIPO' => $T('tipo_treinamento', ['pt' => 'Treinamento', 'en' => 'Training', 'es' => 'Capacitación']), 'ITEM' => ['text' => (string)($d['DSCTIPOTREINAMENTO'] ?? ''), 'title' => (string)($d['DSCTIPOTREINAMENTO'] ?? '')], 'SITUACAO' => $situacao($d), 'VALIDADE' => DashboardGrid::prazo($d['DATADEVALIDADETREINAMENTO'] ?? null)], 'open' => $abrirFunc];
$gridFunc = DashboardGrid::grid('rdFunc', ['FUNCIONARIO' => ['label' => $T('col.funcionario', ['pt' => 'Funcionário', 'en' => 'Employee', 'es' => 'Empleado']), 'filter' => 'select', 'width' => '200px'],
    'TIPO' => ['label' => $T('col.tipo', ['pt' => 'Tipo', 'en' => 'Type', 'es' => 'Tipo']), 'filter' => 'select', 'width' => '110px'], 'ITEM' => ['label' => $T('col.item', ['pt' => 'Item', 'en' => 'Item', 'es' => 'Ítem'])], 'SITUACAO' => $colSit, 'VALIDADE' => $colVal], $rowsFunc,
    ['vazio' => $T('func_ok', ['pt' => 'Nada pendente nos funcionários.', 'en' => 'Nothing pending for employees.', 'es' => 'Nada pendiente en los empleados.']), 'transacao' => 'homologacaofornecedor_ranking']);
$rowsNc = [];
foreach ($ncs as $c) {
    $sit = $c['ATRASADA'] === 'Sim' ? [$T('atrasada', ['pt' => 'Atrasada', 'en' => 'Overdue', 'es' => 'Atrasada']), 'danger'] : ($c['ABERTA'] === 'Sim' ? [$T('aberta', ['pt' => 'Aberta', 'en' => 'Open', 'es' => 'Abierta']), 'warning'] : [$T('fechada', ['pt' => 'Fechada', 'en' => 'Closed', 'es' => 'Cerrada']), 'success']);
    $num = ($c['NRANO'] ?? '') !== '' ? (string)$c['NRANO'] : '#' . $c['CODOCORRENCIA'];
    $rowsNc[] = ['cells' => ['NC' => $num, 'SITUACAO' => ['text' => $sit[0], 'html' => '<span class="badge text-bg-' . $sit[1] . '">' . $e($sit[0]) . '</span>'], 'DATA' => DashboardGrid::prazo($c['DATA_OCORRENCIA'], false),
        'ETAPA' => (string)$c['ETAPA'], 'PREVISTA' => DashboardGrid::prazo($c['DATA_DE_CONCLUSAO_PREVISTA'], $c['ABERTA'] === 'Sim'), 'DESCRICAO' => ['text' => (string)$c['DESCRICAO'], 'title' => (string)$c['DESCRICAO']]],
        'open' => ['url' => 'naoconformidades_ocorrencia.php?codigo=' . (int)$c['CODOCORRENCIA'], 'title' => $T('col.nc', ['pt' => 'NC', 'en' => 'NC', 'es' => 'NC']) . ' ' . $num], 'grupos' => $c['ABERTA'] === 'Sim' ? 'ABERTA' : 'FECHADA'];
}
$gridNc = DashboardGrid::grid('rdNcs', ['NC' => ['label' => $T('col.nc', ['pt' => 'NC', 'en' => 'NC', 'es' => 'NC']), 'width' => '100px'], 'SITUACAO' => ['label' => $T('col.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']), 'filter' => 'select', 'width' => '110px'],
    'DATA' => ['label' => $T('col.data', ['pt' => 'Data', 'en' => 'Date', 'es' => 'Fecha']), 'width' => '100px'], 'ETAPA' => ['label' => $T('col.etapa', ['pt' => 'Etapa', 'en' => 'Stage', 'es' => 'Etapa']), 'filter' => 'select', 'width' => '170px'],
    'PREVISTA' => ['label' => $T('col.prevista', ['pt' => 'Conclusão prevista', 'en' => 'Planned completion', 'es' => 'Conclusión prevista']), 'width' => '120px'], 'DESCRICAO' => ['label' => $T('col.descricao', ['pt' => 'Descrição', 'en' => 'Description', 'es' => 'Descripción'])]], $rowsNc,
    ['vazio' => $T('ncs_vazio', ['pt' => 'Nenhuma não conformidade registrada para este fornecedor.', 'en' => 'No nonconformity recorded for this supplier.', 'es' => 'Ninguna no conformidad registrada para este proveedor.']), 'transacao' => 'homologacaofornecedor_ranking']);
?>
<!doctype html><html lang="<?= IdiomaService::idioma() === 'pt' ? 'pt-BR' : $e(IdiomaService::idioma()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $e($f['RAZAOSOCIAL']) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2"><link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2"><?php DashboardGrid::head(); ?>
<style>
body{background:var(--gp-surface)}.rd{padding:12px 16px;color:var(--gp-text);font-size:12.5px}.rd-topo{display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
.rd-nota{width:84px;height:84px;border-radius:14px;color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;background:var(--cor)}.rd-nota b{font-size:26px;line-height:1}.rd-nota span{font-size:12px;opacity:.9}
.rd h2{font-size:16px;margin:0}.rd h3{font-size:13px;margin:14px 0 6px;color:var(--gp-primary)}.rd .sub{color:var(--gp-muted);font-size:11.5px}
.rd table{width:100%;font-size:12px;background:#fff;border:1px solid var(--gp-border);border-radius:8px}.rd th{color:var(--gp-muted);font-weight:600;background:#fff}.rd td,.rd th{padding:6px 8px;border-bottom:1px solid var(--gp-border);vertical-align:top}
.rd .num{text-align:right;white-space:nowrap}.rd-barra{height:7px;background:#edf1ef;border-radius:4px;min-width:90px}.rd-barra i{display:block;height:7px;border-radius:4px;background:var(--cor)}
.rd-bloq{background:#fdf0ef;border:1px solid #f1c4bf;border-radius:8px;padding:8px 12px;margin-bottom:10px}.rd-grade{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:12px}
.rd-vazio{color:var(--gp-muted);background:#fff;border:1px solid var(--gp-border);border-radius:8px;padding:10px 12px}.rd svg{background:#fff;border:1px solid var(--gp-border);border-radius:8px}
html,body{height:auto}.rd .menu-grid-fragment table{border:0;border-radius:0}.rd .menu-grid-fragment td,.rd .menu-grid-fragment th{vertical-align:middle}
</style></head>
<body><main class="rd" style="--cor:<?= $cor ?>">
<div class="rd-topo">
  <div class="rd-nota"><b><?= $e($faixa !== '' ? $n($f['NOTA']) : '–') ?></b><span><?= $e($faixa !== '' ? $T('faixa', ['pt' => 'Faixa', 'en' => 'Band', 'es' => 'Franja']) . ' ' . $faixa : $T('sem_dados', ['pt' => 'Sem dados', 'en' => 'No data', 'es' => 'Sin datos'])) ?></span></div>
  <div><h2><?= $e($f['RAZAOSOCIAL']) ?></h2><div class="sub"><?= $e(trim(implode(' · ', array_filter([(string)$f['NOMEFANTASIA'], (string)$f['CNPJ'], (string)$f['UNIDADE']])))) ?></div>
  <div class="sub"><?= $e($T('calculado', ['pt' => 'Notas calculadas em', 'en' => 'Scores calculated on', 'es' => 'Notas calculadas el'])) ?> <?= $e($f['CALCULADO_EM'] ? date('d/m/Y H:i', strtotime((string)$f['CALCULADO_EM'])) : '–') ?><?php if ($f['NOTA_ANTERIOR'] !== null && $f['NOTA'] !== null): ?> · <?= $e($T('anterior', ['pt' => 'nota anterior', 'en' => 'previous score', 'es' => 'nota anterior'])) ?> <?= $e($n($f['NOTA_ANTERIOR'])) ?><?php endif; ?></div></div>
</div>
<?php if ($f['BLOQUEADO'] === 'Sim'): ?><div class="rd-bloq"><b class="text-danger"><i class="bi bi-lock-fill me-1"></i><?= $e($T('bloqueado_titulo', ['pt' => 'Fornecedor bloqueado', 'en' => 'Supplier blocked', 'es' => 'Proveedor bloqueado'])) ?></b> <span class="text-muted small"><?= $e($f['BLOQUEADO_POR']) ?> · <?= $e($f['BLOQUEADO_EM'] ? date('d/m/Y H:i', strtotime((string)$f['BLOQUEADO_EM'])) : '') ?></span><div style="white-space:pre-wrap"><?= $e($f['MOTIVO_BLOQUEIO']) ?></div></div><?php endif; ?>

<?php
// Migração 369: composição + evolução numa sub-aba; as listas em grids, cada uma na sua sub-aba
ob_start(); ?>
<table><thead><tr><th><?= $e($T('col.item', ['pt' => 'Item', 'en' => 'Item', 'es' => 'Ítem'])) ?></th><th class="num"><?= $e($T('col.peso', ['pt' => 'Peso', 'en' => 'Weight', 'es' => 'Peso'])) ?></th><th class="num"><?= $e($T('col.peso_efetivo', ['pt' => 'Peso efetivo', 'en' => 'Effective weight', 'es' => 'Peso efectivo'])) ?></th><th class="num"><?= $e($T('col.nota', ['pt' => 'Nota', 'en' => 'Score', 'es' => 'Nota'])) ?></th><th style="width:22%"></th><th class="num"><?= $e($T('col.contribui', ['pt' => 'Contribui', 'en' => 'Contributes', 'es' => 'Aporta'])) ?></th><th><?= $e($T('col.base', ['pt' => 'Base do cálculo', 'en' => 'Calculation basis', 'es' => 'Base del cálculo'])) ?></th></tr></thead><tbody>
<?php foreach ($comp as [$k, $rot, $peso, $nota, $det]): $efetivo = $nota !== null && $somaPesos > 0 ? 100 * $peso / $somaPesos : null; ?>
<tr><td><b><?= $e($rot) ?></b></td><td class="num"><?= $e($n($peso, 0)) ?>%</td><td class="num"><?= $efetivo === null ? '<span class="text-muted">' . $e($T('fora', ['pt' => 'fora da conta', 'en' => 'not counted', 'es' => 'fuera del cálculo'])) . '</span>' : $e($n($efetivo)) . '%' ?></td>
<td class="num"><?= $e($nota === null ? '–' : $n($nota)) ?></td><td><?php if ($nota !== null): ?><div class="rd-barra"><i style="width:<?= max(1, min(100, (float)$nota)) ?>%"></i></div><?php endif; ?></td>
<td class="num"><?= $e($efetivo === null ? '–' : $n((float)$nota * $efetivo / 100)) ?></td><td class="text-muted"><?= $e($det) ?></td></tr>
<?php endforeach; ?></tbody></table>

<?php if (count($hist) > 1):
    $w = 640; $h = 90; $min = min(array_map(static fn($x) => (float)$x['NOTA'], $hist)); $max = max(array_map(static fn($x) => (float)$x['NOTA'], $hist));
    $min = max(0, floor($min - 5)); $max = min(100, ceil($max + 5)); $q = count($hist) - 1; $pts = [];
    foreach ($hist as $i => $x) $pts[] = round(10 + ($w - 20) * $i / $q, 1) . ',' . round($h - 10 - ($h - 20) * (((float)$x['NOTA'] - $min) / max(1, $max - $min)), 1); ?>
<h3><i class="bi bi-graph-up me-1"></i><?= $e($T('evolucao', ['pt' => 'Evolução da nota', 'en' => 'Score history', 'es' => 'Evolución de la nota'])) ?> <span class="sub">(<?= $e($dataBr($hist[0]['DATA'])) ?> – <?= $e($dataBr($hist[$q]['DATA'])) ?>)</span></h3>
<svg viewBox="0 0 <?= $w ?> <?= $h ?>" width="100%" height="<?= $h ?>" preserveAspectRatio="none" role="img" aria-label="<?= $e($T('evolucao', ['pt' => 'Evolução da nota', 'en' => 'Score history', 'es' => 'Evolución de la nota'])) ?>"><polyline fill="none" stroke="<?= $cor ?>" stroke-width="2" points="<?= implode(' ', $pts) ?>"/><text x="6" y="12" font-size="10" fill="#7a8a83"><?= $e($n($max, 0)) ?></text><text x="6" y="<?= $h - 3 ?>" font-size="10" fill="#7a8a83"><?= $e($n($min, 0)) ?></text></svg>
<?php endif; ?>

<?php $quadroComposicao = ob_get_clean(); ?>
<?= DashboardGrid::subabas('ranking-detalhe', [
    ['id' => 'composicao', 'icone' => 'bi-calculator', 'rotulo' => $T('composicao', ['pt' => 'Composição da nota', 'en' => 'Score breakdown', 'es' => 'Composición de la nota']), 'html' => $quadroComposicao],
    ['id' => 'docs', 'icone' => 'bi-file-earmark-x', 'rotulo' => $T('docs_pend_curto', ['pt' => 'Documentos do fornecedor a resolver', 'en' => 'Supplier documents to resolve', 'es' => 'Documentos del proveedor a resolver']), 'contagem' => count($docs), 'html' => $gridDocs],
    ['id' => 'func', 'icone' => 'bi-people', 'rotulo' => $T('func_pend_curto', ['pt' => 'Funcionários a resolver', 'en' => 'Employees to resolve', 'es' => 'Empleados a resolver']), 'contagem' => count($func) + count($trein), 'html' => $gridFunc],
    ['id' => 'ncs', 'icone' => 'bi-exclamation-octagon', 'rotulo' => $T('ncs', ['pt' => 'Não conformidades do fornecedor', 'en' => 'Supplier nonconformities', 'es' => 'No conformidades del proveedor']), 'contagem' => count($ncs), 'html' => $gridNc],
], true) ?>
</main><?php DashboardGrid::scripts(); ?></body></html>
