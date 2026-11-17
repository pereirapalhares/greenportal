<?php
declare(strict_types=1);

/*
 * Minhas atividades (migração 358; GER-5.1 "telas Minhas" e GER-5.2 "Minhas Ações" do 01_Mudancas_Gerais).
 * Uma aba por módulo; cada aba abre a tela do módulo já filtrada para o usuário logado ("meu" = responsável direto ou por
 * grupo/atribuição), com salvar e sem incluir/excluir. A aba só aparece se o perfil pode ver a tela do módulo (a permissão
 * também confere se a empresa contratou o módulo). ?aba=<id> abre direto numa aba.
 * Ao lado de cada título, a quantidade de registros da aba (a mesma contagem da grade, pela ação count_rows da própria tela;
 * em Ações, as não concluídas; em Ocorrências, as não finalizadas); as abas ficam em ordem decrescente dessa quantidade (pedido do usuário de 12/11/2026).
 */
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$sessaoExpirada = static function (): never { http_response_code(401); exit(IdiomaService::t('home.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.'])); };
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $sessaoExpirada();
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/HomologacaoFornecedorAcesso.php';
$pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), (string)$_SESSION['tenant']['banco_dados'], 'GPT');
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $sessaoExpirada();
$idioma = IdiomaService::idioma();
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$t = static fn(string $chave, array $padrao): string => IdiomaService::t('minhas_atividades.' . $chave, $padrao);
$pode = static fn(string $codigo): bool => !empty(PermissaoTransacao::obter($codigo)['pode_visualizar']);
// Views da migração 358 (as abas novas só aparecem depois dela)
$views = [];
try { foreach ($pdo->query("SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('vw_minhas_condicionantes_grid','vw_meus_fornecedores_grid','vw_meus_funcionarios_grid','vw_minhas_acoes','vw_ml_requisito_responsaveis')")->fetchAll(PDO::FETCH_COLUMN) as $v) $views[strtolower((string)$v)] = true; }
catch (Throwable) { $views = []; }
$externo = HomologacaoFornecedorAcesso::externo();

$abas = [
    ['id' => 'requisitos', 'cod' => 'REQUISITOS_LEGAIS', 'view' => 'vw_ml_requisito_responsaveis', 'icon' => 'bi-journal-check', 'src' => 'meus_requisitos.php', 'conta' => ['LEI_REVOGADA' => 'NAO'],
        'label' => $t('aba2.requisitos', ['pt' => 'Requisitos legais', 'en' => 'Legal requirements', 'es' => 'Requisitos legales'])],
    ['id' => 'licencas', 'cod' => 'MINHAS_LICENCAS', 'icon' => 'bi-file-earmark-text', 'src' => 'minhas_licencas.php',
        'label' => $t('aba2.licencas', ['pt' => 'Licenças', 'en' => 'Licenses', 'es' => 'Licencias'])],
    ['id' => 'condicionantes', 'cod' => 'MINHAS_LICENCAS', 'view' => 'vw_minhas_condicionantes_grid', 'icon' => 'bi-list-check', 'src' => 'minhas_condicionantes.php',
        'label' => $t('aba2.condicionantes', ['pt' => 'Condicionantes', 'en' => 'License conditions', 'es' => 'Condicionantes'])],
    ['id' => 'medicoes', 'cod' => 'MM_PLANO_MEDICAO_MONITORAMENTO', 'icon' => 'bi-speedometer', 'src' => 'minhas_medicoes.php',
        'label' => $t('aba2.medicoes', ['pt' => 'Medições e monitoramentos', 'en' => 'Measurements and monitoring', 'es' => 'Mediciones y monitoreos'])],
    ['id' => 'controles', 'cod' => 'MEUS_CONTROLES', 'icon' => 'bi-ui-checks', 'src' => 'meus_controles.php',
        'label' => $t('aba2.controles', ['pt' => 'Controles', 'en' => 'Controls', 'es' => 'Controles'])],
    ['id' => 'treinamentos', 'cod' => 'MEUS_TREINAMENTOS', 'icon' => 'bi-mortarboard', 'src' => 'meus_treinamentos.php',
        'label' => $t('aba2.treinamentos', ['pt' => 'Treinamentos', 'en' => 'Training', 'es' => 'Capacitaciones'])],
    ['id' => 'infraestruturas', 'cod' => 'MINHAS_INFRAESTRUTURAS', 'icon' => 'bi-building-gear', 'src' => 'minhas_infraestruturas.php',
        'label' => $t('aba2.infraestruturas', ['pt' => 'Infraestruturas', 'en' => 'Infrastructure', 'es' => 'Infraestructuras'])],
    ['id' => 'fornecedores', 'cod' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR', 'view' => 'vw_meus_fornecedores_grid', 'interno' => true, 'icon' => 'bi-truck', 'src' => 'meus_fornecedores.php',
        'label' => $t('aba2.fornecedores', ['pt' => 'Fornecedores', 'en' => 'Suppliers', 'es' => 'Proveedores'])],
    ['id' => 'funcionarios', 'cod' => 'HOMOLOGACAO_FORNECEDOR_FUNCIONARIO', 'view' => 'vw_meus_funcionarios_grid', 'interno' => true, 'icon' => 'bi-person-badge', 'src' => 'meus_funcionarios.php',
        'label' => $t('aba2.funcionarios', ['pt' => 'Funcionários de fornecedores', 'en' => 'Supplier employees', 'es' => 'Empleados de proveedores'])],
    ['id' => 'ocorrencias', 'cod' => 'NAO_CONFORMIDADES_MINHAS_OCORRENCIAS', 'icon' => 'bi-exclamation-octagon', 'src' => 'naoconformidades_minhasocorrencias.php', 'conta' => ['CODUSUARIO_ENVOLVIDO' => (int)($_SESSION['usuario']['codusuario'] ?? 0), 'ETAPA' => ['value' => 'Finalizado', 'operator' => 'not', 'exact' => true]],
        'label' => $t('aba2.ocorrencias', ['pt' => 'Ocorrências (NC)', 'en' => 'Occurrences (NC)', 'es' => 'Ocurrencias (NC)'])],
    ['id' => 'acoes', 'cod' => '', 'view' => 'vw_minhas_acoes', 'icon' => 'bi-kanban', 'src' => 'minhas_acoes.php', 'conta_url' => 'minhas_acoes.php?contar=1',
        'label' => $t('aba2.acoes', ['pt' => 'Ações', 'en' => 'Actions', 'es' => 'Acciones'])],
];
$abas = array_values(array_filter($abas, static function (array $a) use ($pode, $views, $externo): bool {
    if (!empty($a['view']) && empty($views[$a['view']])) return false;
    if (!empty($a['interno']) && $externo) return false;
    if ($a['id'] === 'acoes') return $pode('MINHAS_LICENCAS') || $pode('LICENCAS') || ($pode('HOMOLOGACAO_FORNECEDOR_FORNECEDOR') && !$externo) || $pode('NAO_CONFORMIDADES_MINHAS_OCORRENCIAS');
    return $pode($a['cod']);
}));
$inicial = (string)($_GET['aba'] ?? '');
$indice = 0;
foreach ($abas as $i => $a) if ($a['id'] === $inicial) $indice = $i;
?><!doctype html><html lang="<?= $idioma === 'pt' ? 'pt-BR' : $e($idioma) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($t('titulo', ['pt' => 'Minhas atividades', 'en' => 'My work', 'es' => 'Mis actividades'])) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css?v=3" rel="stylesheet"><link href="../assets/css/main.css?v=15" rel="stylesheet">
<style>
body{background:var(--gp-surface)}.ma-shell{height:100vh;display:flex;flex-direction:column;color:var(--gp-text)}
.ma-tabs{display:flex;gap:4px;overflow-x:auto;border-bottom:1px solid var(--gp-border)}
.ma-tab{border:1px solid transparent;border-bottom:0;background:transparent;color:var(--gp-muted);border-radius:8px 8px 0 0;padding:9px 12px;font-size:12px;white-space:nowrap}
.ma-tab:hover{background:var(--gp-primary-soft);color:var(--gp-primary)}.ma-tab.active{background:#fff;border-color:var(--gp-border);color:var(--gp-primary);font-weight:600}.ma-tab i{margin-right:5px}
.ma-panes{flex:1;min-height:0;background:#fff;border:1px solid var(--gp-border);border-top:0;border-radius:0 0 9px 9px;overflow:hidden}
.ma-pane{height:100%;display:none}.ma-pane.active{display:block}.ma-pane iframe{display:block;width:100%;height:100%;min-height:520px;border:0}
.ma-vazio{padding:28px;color:var(--gp-muted)}
.ma-qtd{display:inline-block;min-width:20px;margin-left:6px;padding:0 6px;border-radius:9px;background:#e7ecea;color:var(--gp-muted);font-size:11px;font-weight:600;line-height:17px;text-align:center}
.ma-tab.active .ma-qtd{background:var(--gp-primary,#3ba179);color:#fff}.ma-qtd.zero{opacity:.55}
.ma-carregando{padding:28px;color:var(--gp-muted)}
.ma-ajuda{order:999;margin-left:auto;border:0;background:transparent;color:var(--gp-muted);padding:6px 10px;font-size:15px}.ma-ajuda:hover{color:var(--gp-primary)}
</style><?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?></head>
<body data-transacao="minhas_atividades"><main class="ma-shell">
<?php if (!$abas): ?>
<div class="ma-vazio"><?= $e($t('vazio', ['pt' => 'Seu perfil não tem acesso a nenhuma das telas desta página.', 'en' => 'Your profile cannot access any of the screens on this page.', 'es' => 'Su perfil no tiene acceso a ninguna de las pantallas de esta página.'])) ?></div>
<?php else: ?>
<nav class="ma-tabs" role="tablist"><?php foreach ($abas as $i => $a): ?><button class="ma-tab" type="button" role="tab" aria-selected="false" data-ma-aba="<?= $e($a['id']) ?>" data-ma-ordem="<?= $i ?>" data-conta-url="<?= $e($a['conta_url'] ?? $a['src']) ?>" data-conta-filtros="<?= $e(json_encode((object)($a['conta'] ?? []), JSON_UNESCAPED_UNICODE)) ?>"<?= isset($a['conta_url']) ? ' data-conta-get' : '' ?>><i class="bi <?= $e($a['icon']) ?>"></i><?= $e($a['label']) ?><span class="ma-qtd" aria-hidden="true">…</span></button><?php endforeach; ?><button type="button" class="ma-ajuda" data-open-ajuda title="<?= $e($t('ajuda', ['pt' => 'Ajuda desta tela (F1)', 'en' => 'Help for this screen (F1)', 'es' => 'Ayuda de esta pantalla (F1)'])) ?>" aria-label="<?= $e($t('ajuda', ['pt' => 'Ajuda desta tela (F1)', 'en' => 'Help for this screen (F1)', 'es' => 'Ayuda de esta pantalla (F1)'])) ?>"><i class="bi bi-question-circle"></i></button></nav>
<section class="ma-panes"><div class="ma-carregando" data-ma-carregando><span class="spinner-border spinner-border-sm me-2"></span><?= $e($t('carregando', ['pt' => 'Contando os registros de cada aba…', 'en' => 'Counting the records of each tab…', 'es' => 'Contando los registros de cada pestaña…'])) ?></div><?php foreach ($abas as $i => $a): ?><div class="ma-pane" data-ma-painel="<?= $e($a['id']) ?>"><iframe title="<?= $e($a['label']) ?>" data-src="<?= $e($a['src']) ?>"></iframe></div><?php endforeach; ?></section>
<?php endif; ?>
</main>
<script>
(function () {
  var nav = document.querySelector('.ma-tabs'); if (!nav) return;
  var abas = [].slice.call(nav.querySelectorAll('[data-ma-aba]')), pedida = <?= json_encode((string)($_GET['aba'] ?? '')) ?>, contas = {};
  function ativar(id) {
    abas.forEach(function (b) {
      var esta = b.getAttribute('data-ma-aba') === id, p = document.querySelector('[data-ma-painel="' + b.getAttribute('data-ma-aba') + '"]');
      b.classList.toggle('active', esta); b.setAttribute('aria-selected', esta ? 'true' : 'false');
      if (!p) return;
      p.classList.toggle('active', esta);
      var f = p.querySelector('iframe');
      if (esta && f && !f.getAttribute('src')) f.setAttribute('src', f.getAttribute('data-src'));
    });
  }
  abas.forEach(function (b) { b.addEventListener('click', function () { ativar(b.getAttribute('data-ma-aba')); }); });
  function contar(b) {
    var url = b.getAttribute('data-conta-url'), req;
    if (b.hasAttribute('data-conta-get')) req = fetch(url, { credentials: 'same-origin' });
    else {
      var d = new FormData(); d.append('action', 'count_rows'); d.append('filtros', b.getAttribute('data-conta-filtros') || '{}'); d.append('busca', ''); d.append('ajax', '1');
      req = fetch(url, { method: 'POST', body: d, credentials: 'same-origin' });
    }
    return req.then(function (r) { return r.json(); }).then(function (j) { return j && j.ok ? parseInt(j.total, 10) || 0 : null; }).catch(function () { return null; })
      .then(function (n) {
        contas[b.getAttribute('data-ma-aba')] = n;
        var q = b.querySelector('.ma-qtd'); q.textContent = n === null ? '?' : (n > 9999 ? '9999+' : String(n)); q.classList.toggle('zero', n === 0);
      });
  }
  var feito = false;
  function ordenar() {
    if (feito) return; feito = true;
    abas.sort(function (a, b) {
      var x = contas[a.getAttribute('data-ma-aba')], y = contas[b.getAttribute('data-ma-aba')];
      x = x === null || x === undefined ? -1 : x; y = y === null || y === undefined ? -1 : y;
      return y - x || (parseInt(a.getAttribute('data-ma-ordem'), 10) - parseInt(b.getAttribute('data-ma-ordem'), 10));
    });
    abas.forEach(function (b) { nav.appendChild(b); });
    var c = document.querySelector('[data-ma-carregando]'); if (c) c.remove();
    var alvo = abas.filter(function (b) { return b.getAttribute('data-ma-aba') === pedida; })[0] || abas[0];
    ativar(alvo.getAttribute('data-ma-aba'));
  }
  Promise.all(abas.map(contar)).then(ordenar);
  setTimeout(ordenar, 15000); // se alguma contagem demorar, ordena com as que chegaram
}());
</script></body></html>
