<?php
declare(strict_types=1);
/*
 * Homologação › Ranking de fornecedores (menu HOMOLOGACAO_FORNECEDOR_RANKING, migração 289).
 * Fornecedores ativos em ordem de nota (0–100), faixas A/B/C/D, tendência (nota do dia anterior), componentes da nota e o
 * bloqueio. Clique na linha: detalhe da nota em popup de 90% (homologacaofornecedor_ranking_detalhe.php).
 * ADMINISTRADOR, ADM DA EMPRESA e GESTOR veem todas as unidades; os demais, só as unidades a que têm acesso (como na Portaria).
 * Só leitura (views); a nota é calculada pelo serviço agendado (scripts/fornecedor-ranking-worker.php).
 * Migração 369: lista no grid padrão (DashboardGrid / grid local): barra de ícones, busca, filtro e ordenação por coluna,
 * paginação; os cartões das faixas filtram o grid (data-grid-filtro); Abrir / duplo clique abrem o detalhe da nota.
 */
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/FornecedorRanking.php';
require_once dirname(__DIR__) . '/classes/DashboardGrid.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$pdo = TransactionSecurity::connect(FornecedorRanking::MENU_RANKING);
$podePesos = !empty(PermissaoTransacao::obter(FornecedorRanking::MENU_PESOS)['pode_visualizar']);
$T = static fn(string $chave, array $textos): string => IdiomaService::t('ranking_fornecedores.' . $chave, $textos);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$n = [FornecedorRanking::class, 'numero'];

$cfg = (FornecedorRanking::ler($pdo, 'vw_fornecedor_ranking_config') ?? [])[0] ?? null;
$linhas = FornecedorRanking::fornecedores($pdo);
if ($linhas !== null) usort($linhas, static fn($a, $b) => [$a['NOTA'] === null, -(float)$a['NOTA'], $a['RAZAOSOCIAL']] <=> [$b['NOTA'] === null, -(float)$b['NOTA'], $b['RAZAOSOCIAL']]);
$cont = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, '' => 0]; $bloq = 0; $unidades = [];
foreach ($linhas ?? [] as $l) { $cont[(string)($l['FAIXA'] ?? '')]++; if ($l['BLOQUEADO'] === 'Sim') $bloq++; if ($l['UNIDADE'] !== '') $unidades[$l['UNIDADE']] = true; }
ksort($unidades, SORT_NATURAL | SORT_FLAG_CASE);
$faixaRot = ['A' => $T('faixa.a', ['pt' => 'Faixa A', 'en' => 'Band A', 'es' => 'Franja A']), 'B' => $T('faixa.b', ['pt' => 'Faixa B', 'en' => 'Band B', 'es' => 'Franja B']),
    'C' => $T('faixa.c', ['pt' => 'Faixa C', 'en' => 'Band C', 'es' => 'Franja C']), 'D' => $T('faixa.d', ['pt' => 'Faixa D', 'en' => 'Band D', 'es' => 'Franja D'])];
$semDados = $T('sem_dados', ['pt' => 'Sem dados', 'en' => 'No data', 'es' => 'Sin datos']);
$pct = static fn($v): string => $v === null ? '–' : FornecedorRanking::numero($v, 0) . '%';

// Grid padrão (migração 369)
$rows = [];
foreach ($linhas ?? [] as $i => $l) {
    $f = (string)($l['FAIXA'] ?? ''); $cor = FornecedorRanking::CORES[$f] ?? '#9aa5a0'; $bl = $l['BLOQUEADO'] === 'Sim';
    $dif = $l['NOTA'] !== null && $l['NOTA_ANTERIOR'] !== null ? round((float)$l['NOTA'] - (float)$l['NOTA_ANTERIOR'], 1) : null;
    $nome = (string)$l['RAZAOSOCIAL']; $fant = trim((string)$l['NOMEFANTASIA']);
    $tend = $dif === null || abs($dif) < 0.05 ? '<span class="text-muted">–</span>'
        : ($dif > 0 ? '<span class="rk-sobe"><i class="bi bi-arrow-up-short"></i>' . $e($n($dif)) . '</span>' : '<span class="rk-desce"><i class="bi bi-arrow-down-short"></i>' . $e($n(abs($dif))) . '</span>');
    $nc = (int)$l['NC_ABERTAS']; $ncAtr = (int)$l['NC_ATRASADAS'];
    $rows[] = [
        'cells' => [
            'POS' => ['text' => $l['NOTA'] !== null ? (string)($i + 1) : '', 'sort' => (string)$i],
            'FORNECEDOR' => ['text' => $nome . ($fant !== '' && $fant !== $nome ? ' ' . $fant : ''), 'html' => '<b>' . $e($nome) . '</b>' . ($fant !== '' && $fant !== $nome ? ' <span class="text-muted small">' . $e($fant) . '</span>' : ''), 'title' => $nome],
            'UNIDADE' => (string)$l['UNIDADE'],
            'FAIXA' => ['text' => $f !== '' ? $f : $semDados, 'html' => $f !== '' ? '<span class="rk-faixa" style="background:' . $cor . '">' . $e($f) . '</span>' : '<span class="text-muted">' . $e($semDados) . '</span>', 'sort' => $f !== '' ? $f : 'Z'],
            'NOTA' => ['text' => $l['NOTA'] !== null ? $n($l['NOTA']) : '', 'sort' => (string)($l['NOTA'] ?? -1),
                'html' => $l['NOTA'] !== null ? '<div class="rk-barra" style="--cor:' . $cor . '"><b style="width:34px;text-align:right">' . $e($n($l['NOTA'])) . '</b><span class="fundo"><i style="width:' . max(2, min(100, (float)$l['NOTA'])) . '%"></i></span></div>' : '<span class="text-muted">–</span>'],
            'TEND' => ['text' => $dif === null ? '' : $n($dif), 'html' => $tend, 'sort' => (string)($dif ?? 0)],
            'DOC' => ['text' => $pct($l['NOTA_DOC_FORNECEDOR']), 'sort' => (string)($l['NOTA_DOC_FORNECEDOR'] ?? -1), 'title' => (int)$l['DOC_OK'] . ' / ' . (int)$l['DOC_TOTAL']],
            'FUNC' => ['text' => $pct($l['NOTA_DOC_FUNCIONARIOS']), 'sort' => (string)($l['NOTA_DOC_FUNCIONARIOS'] ?? -1), 'title' => (int)$l['FUNC_OK'] . ' / ' . (int)$l['FUNC_TOTAL'] . ' · ' . (int)$l['FUNCIONARIOS']],
            'NC' => ['text' => (string)$nc, 'sort' => (string)$nc, 'html' => $nc . ($ncAtr > 0 ? ' <span class="badge text-bg-danger" title="' . $e($T('atrasadas', ['pt' => 'atrasadas', 'en' => 'overdue', 'es' => 'atrasadas'])) . '">' . $ncAtr . '</span>' : '')],
            'AUD' => ['text' => $l['NOTA_AUDITORIA'] === null ? '–' : $n($l['NOTA_AUDITORIA'], 0), 'sort' => (string)($l['NOTA_AUDITORIA'] ?? -1)],
            'BLOQUEIO' => $bl ? ['text' => $T('bloqueado', ['pt' => 'Bloqueado', 'en' => 'Blocked', 'es' => 'Bloqueado']), 'title' => (string)$l['MOTIVO_BLOQUEIO'],
                'html' => '<span class="badge text-bg-danger"><i class="bi bi-lock-fill"></i> ' . $e($T('bloqueado', ['pt' => 'Bloqueado', 'en' => 'Blocked', 'es' => 'Bloqueado'])) . '</span> <span class="small text-muted">' . $e(mb_strimwidth((string)$l['MOTIVO_BLOQUEIO'], 0, 80, '…')) . '</span>'] : '',
        ],
        'open' => ['url' => 'homologacaofornecedor_ranking_detalhe.php?fornecedor=' . (int)$l['CODFORNECEDOR'], 'title' => $T('detalhe', ['pt' => 'Nota do fornecedor', 'en' => 'Supplier score', 'es' => 'Nota del proveedor']) . ' - ' . $nome],
        'grupos' => trim(($f !== '' ? 'FAIXA_' . $f : 'SEM_DADOS') . ($bl ? ' BLOQ' : '')),
        'attrs' => $bl ? 'class="rk-bloq"' : '',
    ];
}
$num = ['width' => '90px', 'filter' => 'none'];
$gridRanking = DashboardGrid::grid('rkGrid', [
    'POS' => ['label' => '#', 'filter' => 'none', 'width' => '50px'],
    'FORNECEDOR' => ['label' => $T('col.fornecedor', ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor'])],
    'UNIDADE' => ['label' => $T('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), 'filter' => 'select', 'width' => '150px'],
    'FAIXA' => ['label' => $T('col.faixa', ['pt' => 'Faixa', 'en' => 'Band', 'es' => 'Franja']), 'filter' => 'select', 'width' => '80px'],
    'NOTA' => ['label' => $T('col.nota', ['pt' => 'Nota', 'en' => 'Score', 'es' => 'Nota']), 'filter' => 'none', 'width' => '150px'],
    'TEND' => ['label' => $T('col.tendencia', ['pt' => 'Tendência', 'en' => 'Trend', 'es' => 'Tendencia'])] + $num,
    'DOC' => ['label' => $T('col.doc', ['pt' => 'Doc. fornecedor', 'en' => 'Supplier docs', 'es' => 'Doc. proveedor'])] + $num,
    'FUNC' => ['label' => $T('col.func', ['pt' => 'Doc. funcionários', 'en' => 'Employee docs', 'es' => 'Doc. empleados'])] + $num,
    'NC' => ['label' => $T('col.nc', ['pt' => 'NCs abertas', 'en' => 'Open NCs', 'es' => 'NC abiertas'])] + $num,
    'AUD' => ['label' => $T('col.aud', ['pt' => 'Auditoria', 'en' => 'Audit', 'es' => 'Auditoría'])] + $num,
    'BLOQUEIO' => ['label' => $T('col.bloqueio', ['pt' => 'Bloqueio', 'en' => 'Block', 'es' => 'Bloqueo']), 'filter' => 'select', 'width' => '200px'],
], $rows, ['transacao' => 'homologacaofornecedor_ranking', 'ordem' => ['POS', 'asc'],
    'vazio' => $T('nenhum', ['pt' => 'Nenhum fornecedor ativo nas suas unidades.', 'en' => 'No active supplier in your units.', 'es' => 'Ningún proveedor activo en sus unidades.'])]);
?>
<!doctype html><html lang="<?= IdiomaService::idioma() === 'pt' ? 'pt-BR' : $e(IdiomaService::idioma()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $e($T('titulo', ['pt' => 'Ranking de fornecedores', 'en' => 'Supplier ranking', 'es' => 'Ranking de proveedores'])) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2"><link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2"><link rel="stylesheet" href="../assets/css/app.css?v=3"><link rel="stylesheet" href="../assets/css/main.css?v=15"><?php DashboardGrid::head(); ?>
<style>
body{background:var(--gp-surface)}.rk{padding:12px 16px;color:var(--gp-text);font-size:12.5px}.rk h2{font-size:16px;margin:0 0 2px}.rk .sub{font-size:11.5px;color:var(--gp-muted);margin-bottom:10px}
.rk-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;margin-bottom:10px}.rk-card{background:#fff;border:1px solid var(--gp-border);border-radius:10px;padding:9px 12px;cursor:pointer;border-left:5px solid var(--cor,#999)}
.rk-card.ativo{outline:2px solid var(--cor,#999)}.rk-card b{font-size:20px;display:block;line-height:1.2}.rk-card span{font-size:11px;color:var(--gp-muted)}
.rk-faixa{display:inline-block;width:22px;height:22px;border-radius:6px;color:#fff;font-weight:700;text-align:center;line-height:22px}
.rk-barra{display:flex;align-items:center;gap:6px;min-width:120px}.rk-barra i{display:block;height:7px;border-radius:4px;background:var(--cor)}.rk-barra .fundo{flex:1;background:#edf1ef;border-radius:4px}
.rk-sobe{color:#1f8a5b}.rk-desce{color:#c0392b}.rk tr.rk-bloq td{background:#fdf0ef}
.rk-rodape{font-size:11px;color:var(--gp-muted);margin-top:8px}
</style><?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?></head>
<body><main class="rk">
<h2><i class="bi bi-trophy me-1"></i><?= $e($T('titulo', ['pt' => 'Ranking de fornecedores', 'en' => 'Supplier ranking', 'es' => 'Ranking de proveedores'])) ?></h2>
<div class="sub"><?= $e($T('sub', ['pt' => 'Nota de 0 a 100 calculada com a documentação do fornecedor e dos funcionários, as não conformidades e as auditorias. Clique no fornecedor para ver de onde vem a nota.', 'en' => 'Score from 0 to 100 based on supplier and employee documents, nonconformities and audits. Click a supplier to see where the score comes from.', 'es' => 'Nota de 0 a 100 calculada con la documentación del proveedor y de los empleados, las no conformidades y las auditorías. Haga clic en el proveedor para ver de dónde viene la nota.'])) ?></div>
<?php if ($linhas === null || $cfg === null): ?><div class="alert alert-warning small"><?= $e($T('sem_migracao', ['pt' => 'Ranking de fornecedores ainda não instalado nesta empresa (rode a migração 289).', 'en' => 'Supplier ranking not installed for this company yet (run migration 289).', 'es' => 'Ranking de proveedores aún no instalado en esta empresa (ejecute la migración 289).'])) ?></div></main></body></html><?php exit; endif; ?>
<div class="rk-cards">
<?php foreach (['A', 'B', 'C', 'D'] as $f): ?><div class="rk-card" style="--cor:<?= FornecedorRanking::CORES[$f] ?>" data-rk-card="FAIXA_<?= $f ?>" data-grid-filtro="rkGrid" data-grid-valor="FAIXA_<?= $f ?>" data-grid-rotulo="<?= $e($faixaRot[$f]) ?>"><b><?= $cont[$f] ?></b><span><?= $e($faixaRot[$f]) ?> · <?= $f === 'D' ? $e('< ' . $n($cfg['FAIXA_C'], 0)) : $e('≥ ' . $n($cfg['FAIXA_' . $f], 0)) ?></span></div><?php endforeach; ?>
<div class="rk-card" style="--cor:#9aa5a0" data-rk-card="SEM_DADOS" data-grid-filtro="rkGrid" data-grid-valor="SEM_DADOS" data-grid-rotulo="<?= $e($semDados) ?>"><b><?= $cont[''] ?></b><span><?= $e($semDados) ?></span></div>
<div class="rk-card" style="--cor:#7b241c" data-rk-card="BLOQ" data-grid-filtro="rkGrid" data-grid-valor="BLOQ" data-grid-rotulo="<?= $e($T('bloqueados', ['pt' => 'Bloqueados', 'en' => 'Blocked', 'es' => 'Bloqueados'])) ?>"><b><?= $bloq ?></b><span><?= $e($T('bloqueados', ['pt' => 'Bloqueados', 'en' => 'Blocked', 'es' => 'Bloqueados'])) ?></span></div>
</div>
<?= $gridRanking ?>
<div class="rk-rodape"><?= $e($T('calculado', ['pt' => 'Notas calculadas em', 'en' => 'Scores calculated on', 'es' => 'Notas calculadas el'])) ?> <?= $e($cfg['ULTIMO_CALCULO'] ? date('d/m/Y H:i', strtotime((string)$cfg['ULTIMO_CALCULO'])) : '–') ?>
 · <?= $e($T('pesos', ['pt' => 'pesos', 'en' => 'weights', 'es' => 'pesos'])) ?>: <?= $e($T('p_doc', ['pt' => 'doc. fornecedor', 'en' => 'supplier docs', 'es' => 'doc. proveedor'])) ?> <?= $e($n($cfg['PESO_DOC_FORNECEDOR'], 0)) ?>%, <?= $e($T('p_func', ['pt' => 'doc. funcionários', 'en' => 'employee docs', 'es' => 'doc. empleados'])) ?> <?= $e($n($cfg['PESO_DOC_FUNCIONARIOS'], 0)) ?>%, <?= $e($T('p_nc', ['pt' => 'NCs', 'en' => 'NCs', 'es' => 'NC'])) ?> <?= $e($n($cfg['PESO_NC'], 0)) ?>%, <?= $e($T('p_aud', ['pt' => 'auditorias', 'en' => 'audits', 'es' => 'auditorías'])) ?> <?= $e($n($cfg['PESO_AUDITORIA'], 0)) ?>%
<?php if ($podePesos): ?> · <a href="#" data-rk-pesos><?= $e($T('alterar_pesos', ['pt' => 'Alterar pesos e bloqueios', 'en' => 'Change weights and blocks', 'es' => 'Cambiar pesos y bloqueos'])) ?></a><?php endif; ?></div>
</main>
<?php DashboardGrid::scripts(); ?>
<script>
(function () {
    'use strict';
    var T = <?= json_encode(['pesos' => $T('titulo_pesos', ['pt' => 'Pesos do ranking de fornecedores', 'en' => 'Supplier ranking weights', 'es' => 'Pesos del ranking de proveedores'])], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    function host() { var w = window; for (var i = 0; i < 6 && w; i++) { try { if (w.GreenPortalPopups && w.jQuery) return w; } catch (e) { break; } if (w === w.parent) break; w = w.parent; } return null; }
    // Popup de 90% da área desta tela (regra dos popups do portal)
    function abrir(titulo, url) {
        var h = host(), abs = new URL(url, window.location.href).href;
        if (h && h.GreenPortalPopups.transacao90) {
            var area = window.frameElement ? window.frameElement.getBoundingClientRect() : null;
            h.GreenPortalPopups.transacao90({ titulo: titulo, url: abs, area: area ? { width: area.width, height: area.height } : null });
        } else window.location.href = abs;
    }
    // Cartão ativo acompanha o filtro do grid (o filtro em si é do assets/js/subabas.js, data-grid-filtro)
    document.addEventListener('click', function (ev) {
        var c = ev.target.closest('[data-rk-card]'); if (!c) return;
        var ativo = !c.classList.contains('ativo');
        document.querySelectorAll('[data-rk-card]').forEach(function (x) { x.classList.toggle('ativo', ativo && x === c); });
    }, true);
    document.addEventListener('click', function (ev) {
        if (ev.target.closest('.grid-local-grupo-aviso')) document.querySelectorAll('[data-rk-card]').forEach(function (x) { x.classList.remove('ativo'); });
    });
    var pesos = document.querySelector('[data-rk-pesos]');
    if (pesos) pesos.addEventListener('click', function (ev) { ev.preventDefault(); abrir(T.pesos, 'administracao_pesos.php'); });
}());
</script>
</body></html>
