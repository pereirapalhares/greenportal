<?php
declare(strict_types=1);

/*
 * Minhas atividades › Minhas ações (migração 358, GER-5.2 do 01_Mudancas_Gerais; decisão do usuário de 12/11/2026: tela única
 * com filtro). Ações dos planos de ação em que o usuário logado é o responsável: Condicionantes de licenças (li_planodeacao),
 * Fornecedores (fo_planodeacao) e Não Conformidades (nc_planodeacao), pela vw_minhas_acoes. Só aparecem os módulos que o perfil
 * pode ver. Situação pelo % (100 = concluída) e pelo prazo. Clicar na ação abre o plano de ação no módulo, onde ela é atualizada.
 */
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$sessao = static function (): never { http_response_code(401); exit(IdiomaService::t('home.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.'])); };
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $sessao();
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/HomologacaoFornecedorAcesso.php';
$pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), (string)$_SESSION['tenant']['banco_dados'], 'GPT');
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $sessao();
$uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$idioma = IdiomaService::idioma();
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$t = static fn(string $chave, array $padrao): string => IdiomaService::t('minhas_acoes.' . $chave, $padrao);
$pode = static fn(string $c): bool => !empty(PermissaoTransacao::obter($c)['pode_visualizar']);
$externo = HomologacaoFornecedorAcesso::externo();
// módulo => [rótulo, pode ver na lista, pode abrir o plano]
$modulos = [
    'CONDICIONANTE' => [$t('modulo.condicionante', ['pt' => 'Condicionantes de licenças', 'en' => 'License conditions', 'es' => 'Condicionantes de licencias']), $pode('LICENCAS') || $pode('MINHAS_LICENCAS'), $pode('LICENCAS')],
    'FORNECEDOR' => [$t('modulo.fornecedor', ['pt' => 'Fornecedores', 'en' => 'Suppliers', 'es' => 'Proveedores']), !$externo && $pode('HOMOLOGACAO_FORNECEDOR_FORNECEDOR'), !$externo && $pode('HOMOLOGACAO_FORNECEDOR_FORNECEDOR')],
    'NC' => [$t('modulo.nc', ['pt' => 'Não Conformidades', 'en' => 'Nonconformities', 'es' => 'No Conformidades']), $pode('NAO_CONFORMIDADES_MINHAS_OCORRENCIAS'), $pode('NAO_CONFORMIDADES_MINHAS_OCORRENCIAS')],
];
$linhas = []; $erro = '';
try {
    $filtro = json_encode(['CODUSUARIO' => ['value' => (string)$uid, 'exact' => true]]);
    for ($off = 0; $off < 5000; $off += 1000) {
        $s = $pdo->prepare('CALL sp_crud_select(?, ?, NULL, 1000, ?)');
        $s->execute(['vw_minhas_acoes', $filtro, $off]);
        $lote = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
        foreach ($lote as $r) if ((int)$r['CODUSUARIO'] === $uid && !empty($modulos[(string)$r['MODULO']][1])) $linhas[] = $r;
        if (count($lote) < 1000) break;
    }
} catch (Throwable $ex) { error_log('minhas_acoes: ' . $ex->getMessage()); $erro = $t('erro', ['pt' => 'Não foi possível carregar as ações. Avise o administrador (migração 358).', 'en' => 'Could not load the actions. Tell the administrator (migration 358).', 'es' => 'No fue posible cargar las acciones. Avise al administrador (migración 358).']); }

$hoje = new DateTimeImmutable('today');
$dt = static function (?string $v): ?DateTimeImmutable { $v = trim((string)$v); if ($v === '' || $v < '1900-01-02') return null; try { return new DateTimeImmutable(substr($v, 0, 10)); } catch (Throwable) { return null; } };
$itens = [];
foreach ($linhas as $r) {
    $fim = $dt($r['DATAFINAL'] ?? null); $ini = $dt($r['DATAINICIAL'] ?? null); $pct = max(0, min(100, (int)$r['STATUS']));
    $dias = $fim ? (int)$hoje->diff($fim)->format('%r%a') : null;
    $sit = $pct >= 100 ? 'concluida' : ($fim === null ? 'sem-prazo' : ($dias < 0 ? 'atrasada' : ($dias <= 7 ? 'vence' : 'no-prazo')));
    $mod = (string)$r['MODULO']; $orig = (int)$r['CODORIGEM'];
    $url = '';
    if (!empty($modulos[$mod][2]) && $orig > 0) $url = match ($mod) {
        'CONDICIONANTE' => 'manutencaolegal_licencas_condicionantesplanodeacao.php?filtros=' . rawurlencode(json_encode(['CODCONDICIONANTE' => (string)$orig])),
        'FORNECEDOR' => 'homologacaofornecedor_fornecedorxplanodeacao.php?filtros=' . rawurlencode(json_encode(['CODFORNECEDOR' => (string)$orig])),
        'NC' => 'naoconformidades_ocorrencia.php?codigo=' . $orig,
        default => '',
    };
    $itens[] = ['mod' => $mod, 'origem' => (string)$r['ORIGEM'], 'acao' => (string)$r['ACAO'], 'ini' => $ini, 'fim' => $fim, 'pct' => $pct, 'sit' => $sit, 'dias' => $dias, 'url' => $url, 'cod' => (int)$r['CODPLANOACAO']];
}
// Migração 362 (GER-5): ações concluídas em que o usuário é o avaliador da eficácia
if ($erro === '' && is_file(dirname(__DIR__) . '/classes/EficaciaPlanos.php')) {
    require_once dirname(__DIR__) . '/classes/EficaciaPlanos.php';
    try {
        foreach (EficaciaPlanos::pendentes($pdo, $uid) as $r) {
            $mod = (string)$r['MODULO'];
            if (empty($modulos[$mod][1])) continue;
            $itens[] = ['mod' => $mod, 'origem' => (string)$r['ORIGEM'], 'acao' => (string)$r['ACAO'], 'ini' => null, 'fim' => $dt($r['DATAFINAL'] ?? null), 'pct' => 100,
                'sit' => 'avaliar', 'dias' => null, 'url' => !empty($modulos[$mod][2]) ? EficaciaPlanos::url($mod, (int)$r['CODORIGEM']) : '', 'cod' => (int)$r['CODPLANOACAO']];
        }
    } catch (Throwable $ex) { error_log('minhas_acoes eficacia: ' . $ex->getMessage()); }
}
// ?contar=1: quantidade de ações não concluídas (número da aba em Minhas atividades)
if (!empty($_GET['contar'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $erro === '', 'total' => count(array_filter($itens, static fn($i) => $i['sit'] !== 'concluida'))]);
    exit;
}
$ordemSit = ['avaliar' => -1, 'atrasada' => 0, 'vence' => 1, 'no-prazo' => 2, 'sem-prazo' => 3, 'concluida' => 4];
usort($itens, static fn($a, $b) => [$ordemSit[$a['sit']], $a['fim']?->format('Ymd') ?? '99999999'] <=> [$ordemSit[$b['sit']], $b['fim']?->format('Ymd') ?? '99999999']);
$rotSit = [
    'avaliar' => $t('sit.avaliar', ['pt' => 'Avaliar eficácia', 'en' => 'Review effectiveness', 'es' => 'Evaluar eficacia']),
    'atrasada' => $t('sit.atrasada', ['pt' => 'Atrasada', 'en' => 'Overdue', 'es' => 'Atrasada']),
    'vence' => $t('sit.vence', ['pt' => 'Vence em até 7 dias', 'en' => 'Due within 7 days', 'es' => 'Vence en hasta 7 días']),
    'no-prazo' => $t('sit.no_prazo', ['pt' => 'No prazo', 'en' => 'On time', 'es' => 'En plazo']),
    'sem-prazo' => $t('sit.sem_prazo', ['pt' => 'Sem prazo', 'en' => 'No deadline', 'es' => 'Sin plazo']),
    'concluida' => $t('sit.concluida', ['pt' => 'Concluída', 'en' => 'Done', 'es' => 'Concluida']),
];
$cont = array_fill_keys(array_keys($rotSit), 0); foreach ($itens as $i) $cont[$i['sit']]++;
$fmt = static fn(?DateTimeImmutable $d): string => $d ? $d->format('d/m/Y') : '';
$theme = in_array((string)($_SESSION['layout_tema'] ?? ''), ['verde', 'azul', 'cinza'], true) ? (string)$_SESSION['layout_tema'] : 'verde';
session_write_close();
?><!doctype html><html lang="<?= $idioma === 'pt' ? 'pt-BR' : $e($idioma) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($t('titulo', ['pt' => 'Minhas ações', 'en' => 'My actions', 'es' => 'Mis acciones'])) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css?v=3" rel="stylesheet"><link href="../assets/css/main.css?v=15" rel="stylesheet">
<style>
body{background:#fff;color:var(--gp-text);font-size:13px}.mac{padding:14px 16px}
.mac-cards{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.mac-card{border:1px solid var(--gp-border);border-radius:8px;padding:8px 12px;min-width:118px;background:#fff;cursor:pointer;text-align:left}
.mac-card b{display:block;font-size:20px;line-height:1.1}.mac-card small{color:var(--gp-muted)}.mac-card.ativo{outline:2px solid var(--gp-primary)}
.mac-card[data-sit=atrasada] b{color:#c0392b}.mac-card[data-sit=vence] b{color:#c47f00}.mac-card[data-sit=concluida] b{color:#2e7d4f}
.mac-filtros{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px}.mac-filtros .form-select,.mac-filtros .form-control{width:auto;min-width:180px}
.mac-tab{width:100%;border-collapse:collapse}.mac-tab th{position:sticky;top:0;background:var(--gp-surface,#f6f8f7);font-weight:600;font-size:12px;border-bottom:1px solid var(--gp-border);padding:7px 8px;text-align:left}
.mac-tab td{border-bottom:1px solid var(--gp-border);padding:7px 8px;vertical-align:top}.mac-tab tr[data-url]:hover td{background:var(--gp-primary-soft);cursor:pointer}
.mac-acao{max-width:520px;white-space:pre-line}.mac-orig{max-width:320px;color:var(--gp-muted)}
.mac-sit{display:inline-block;border-radius:10px;padding:1px 8px;font-size:11px;white-space:nowrap;background:#eef2f0}
.mac-sit.avaliar{background:#e3ecfb;color:#1f4f9a}.mac-card[data-sit=avaliar] b{color:#1f4f9a}.mac-sit.atrasada{background:#fde4e1;color:#a5281b}.mac-sit.vence{background:#fff1d1;color:#8a5a00}.mac-sit.concluida{background:#dff2e7;color:#226b40}
.mac-pct{display:inline-block;width:70px;height:6px;border-radius:3px;background:#e5ebe8;vertical-align:middle;margin-right:5px;overflow:hidden}.mac-pct i{display:block;height:100%;background:var(--gp-primary,#3ba179)}
.mac-vazio{color:var(--gp-muted);padding:20px 4px}
</style></head>
<body class="theme-<?= $e($theme) ?>" data-transacao="minhas_acoes"><main class="mac">
<?php if ($erro): ?><div class="alert alert-warning"><?= $e($erro) ?></div><?php endif; ?>
<div class="mac-cards">
  <?php foreach ($rotSit as $k => $r): ?><button type="button" class="mac-card" data-sit="<?= $e($k) ?>"><b><?= (int)$cont[$k] ?></b><small><?= $e($r) ?></small></button><?php endforeach; ?>
</div>
<div class="mac-filtros">
  <select class="form-select form-select-sm" id="macModulo" aria-label="<?= $e($t('filtro.modulo', ['pt' => 'Módulo', 'en' => 'Module', 'es' => 'Módulo'])) ?>">
    <option value=""><?= $e($t('filtro.todos_modulos', ['pt' => 'Todos os módulos', 'en' => 'All modules', 'es' => 'Todos los módulos'])) ?></option>
    <?php foreach ($modulos as $k => $m) if ($m[1]): ?><option value="<?= $e($k) ?>"><?= $e($m[0]) ?></option><?php endif; ?>
  </select>
  <select class="form-select form-select-sm" id="macSituacao" aria-label="<?= $e($t('filtro.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación'])) ?>">
    <option value="abertas" selected><?= $e($t('filtro.abertas', ['pt' => 'Em aberto (não concluídas)', 'en' => 'Open (not done)', 'es' => 'Abiertas (no concluidas)'])) ?></option>
    <option value=""><?= $e($t('filtro.todas', ['pt' => 'Todas', 'en' => 'All', 'es' => 'Todas'])) ?></option>
    <?php foreach ($rotSit as $k => $r): ?><option value="<?= $e($k) ?>"><?= $e($r) ?></option><?php endforeach; ?>
  </select>
  <input type="search" class="form-control form-control-sm" id="macBusca" placeholder="<?= $e($t('filtro.busca', ['pt' => 'Buscar na ação ou na origem', 'en' => 'Search action or source', 'es' => 'Buscar en la acción o el origen'])) ?>">
  <small class="text-muted" id="macTotal"></small>
  <button type="button" class="btn btn-sm btn-link ms-auto p-0" data-open-ajuda title="<?= $e($t('ajuda', ['pt' => 'Ajuda desta tela (F1)', 'en' => 'Help for this screen (F1)', 'es' => 'Ayuda de esta pantalla (F1)'])) ?>"><i class="bi bi-question-circle me-1"></i><?= $e($t('ajuda_curta', ['pt' => 'Ajuda', 'en' => 'Help', 'es' => 'Ayuda'])) ?></button>
</div>
<table class="mac-tab">
  <thead><tr>
    <th><?= $e($t('col.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación'])) ?></th>
    <th><?= $e($t('col.modulo', ['pt' => 'Módulo', 'en' => 'Module', 'es' => 'Módulo'])) ?></th>
    <th><?= $e($t('col.acao', ['pt' => 'Ação', 'en' => 'Action', 'es' => 'Acción'])) ?></th>
    <th><?= $e($t('col.origem', ['pt' => 'Origem', 'en' => 'Source', 'es' => 'Origen'])) ?></th>
    <th><?= $e($t('col.inicio', ['pt' => 'Início', 'en' => 'Start', 'es' => 'Inicio'])) ?></th>
    <th><?= $e($t('col.prazo', ['pt' => 'Prazo', 'en' => 'Deadline', 'es' => 'Plazo'])) ?></th>
    <th><?= $e($t('col.realizado', ['pt' => 'Realizado', 'en' => 'Progress', 'es' => 'Realizado'])) ?></th>
  </tr></thead>
  <tbody>
  <?php foreach ($itens as $i): ?>
    <tr data-mod="<?= $e($i['mod']) ?>" data-sit="<?= $e($i['sit']) ?>"<?= $i['url'] ? ' data-url="' . $e($i['url']) . '" title="' . $e($t('abrir', ['pt' => 'Abrir o plano de ação no módulo', 'en' => 'Open the action plan in the module', 'es' => 'Abrir el plan de acción en el módulo'])) . '"' : '' ?>>
      <td><span class="mac-sit <?= $e($i['sit']) ?>"><?= $e($rotSit[$i['sit']]) ?></span></td>
      <td><?= $e($modulos[$i['mod']][0] ?? $i['mod']) ?></td>
      <td class="mac-acao"><?= $e(mb_strimwidth(trim($i['acao']), 0, 600, '…')) ?></td>
      <td class="mac-orig"><?= $e($i['origem']) ?></td>
      <td><?= $e($fmt($i['ini'])) ?></td>
      <td><?= $e($fmt($i['fim'])) ?></td>
      <td><span class="mac-pct"><i style="width:<?= (int)$i['pct'] ?>%"></i></span><?= (int)$i['pct'] ?>%</td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<div class="mac-vazio" id="macVazio" hidden><?= $e($t('vazio', ['pt' => 'Nenhuma ação com estes filtros.', 'en' => 'No actions with these filters.', 'es' => 'Ninguna acción con estos filtros.'])) ?></div>
</main>
<script>
(function () {
  var mod = document.getElementById('macModulo'), sit = document.getElementById('macSituacao'), busca = document.getElementById('macBusca'),
      linhas = [].slice.call(document.querySelectorAll('.mac-tab tbody tr')), total = document.getElementById('macTotal'), vazio = document.getElementById('macVazio'),
      rotTotal = <?= json_encode($t('total', ['pt' => '{n} ação(ões)', 'en' => '{n} action(s)', 'es' => '{n} acción(es)']), JSON_UNESCAPED_UNICODE) ?>;
  function norm(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
  function aplicar() {
    var m = mod.value, s = sit.value, q = norm(busca.value), n = 0;
    linhas.forEach(function (tr) {
      var ok = (!m || tr.getAttribute('data-mod') === m)
        && (!s || (s === 'abertas' ? tr.getAttribute('data-sit') !== 'concluida' : tr.getAttribute('data-sit') === s))
        && (!q || norm(tr.textContent).indexOf(q) >= 0);
      tr.hidden = !ok; if (ok) n++;
    });
    [].forEach.call(document.querySelectorAll('.mac-card'), function (c) { c.classList.toggle('ativo', c.getAttribute('data-sit') === s); });
    total.textContent = rotTotal.replace('{n}', n); vazio.hidden = n > 0;
  }
  [mod, sit].forEach(function (el) { el.addEventListener('change', aplicar); });
  busca.addEventListener('input', aplicar);
  [].forEach.call(document.querySelectorAll('.mac-card'), function (c) {
    c.addEventListener('click', function () { sit.value = sit.value === c.getAttribute('data-sit') ? 'abertas' : c.getAttribute('data-sit'); aplicar(); });
  });
  document.querySelector('.mac-tab tbody').addEventListener('click', function (ev) {
    var tr = ev.target.closest('tr[data-url]'); if (!tr) return;
    var url = tr.getAttribute('data-url'), titulo = tr.children[1].textContent + ' — ' + tr.children[2].textContent.slice(0, 40), host = window.top || window;
    try {
      if (typeof host.greenPortalOpenTab === 'function') { host.greenPortalOpenTab('acao-' + tr.getAttribute('data-mod') + '-' + url.replace(/[^a-z0-9]+/gi, '-').slice(-40), '../principal/' + url, titulo); return; }
    } catch (e) {}
    window.open(url, '_blank');
  });
  aplicar();
}());
</script></body></html>
