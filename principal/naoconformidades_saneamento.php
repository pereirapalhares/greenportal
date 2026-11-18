<?php
declare(strict_types=1);

/*
 * Não Conformidades › Administração › Painel de saneamento (migração 334, 22/10/2026). Filas prontas para a administração agir:
 * NCs em aberto sem gestor, com gestor/responsável desativado, paradas (dias sem movimento), por idade (NCs antigas) e com ações
 * vencidas; ações vencidas com o responsável; listas sem uso ou com pessoas que não recebem avisos.
 * Ações (só nas NCs do greenportal, com motivo quando altera): atribuir gestor às NCs marcadas e lembrar (Home › Alertas) os
 * responsáveis das ações vencidas marcadas. Decisão do usuário: NCs antigas ficam como estão — o painel não encerra NCs.
 * Leitura: vw_nc_saneamento, vw_nc_acoes_vencidas, vw_nc_listas, vw_nc_usuarios. Gravação: api/nc_administracao.php
 * (sp_nc_admin_atribuir_gestor, sp_nc_admin_lembrar).
 */

require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeAdministracao.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeTextos.php';
require_once dirname(__DIR__) . '/components/grid_local.php';

$pdo = TransactionSecurity::connect(NaoConformidadeAdministracao::MENU_SANEAMENTO);
$perm = PermissaoTransacao::obter(NaoConformidadeAdministracao::MENU_SANEAMENTO);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$A = NaoConformidadeAdministracao::class;
$pode = !empty($perm['pode_editar']);
$erro = '';
$ncs = []; $acoes = []; $listas = []; $usuarios = [];
try {
    $ncs = $A::ler($pdo, 'vw_nc_saneamento', [], 50000);
    $acoes = $A::ler($pdo, 'vw_nc_acoes_vencidas', [], 50000);
    $listas = $A::ler($pdo, 'vw_nc_listas');
    foreach ($A::ler($pdo, 'vw_nc_usuarios', ['ATIVO' => $A::exato(1)]) as $u) $usuarios[(int)$u['CODUSUARIO']] = (string)$u['NOME'];
    asort($usuarios, SORT_NATURAL | SORT_FLAG_CASE);
} catch (Throwable $ex) {
    $erro = ncT('san.sem_migracao');
    error_log('[nc saneamento] ' . $ex->getMessage());
}
$faixaIdade = static fn(int $d): string => $d <= 90 ? ncT('san.f_90d') : ($d <= 365 ? ncT('san.f_90d_1a') : ($d <= 1095 ? ncT('san.f_1a_3a') : ncT('san.f_3a')));
$faixaParada = static fn(int $d): string => $d <= 30 ? ncT('san.f_30d') : ($d <= 90 ? ncT('san.f_30_90') : ($d <= 365 ? ncT('san.f_90_1a') : ncT('san.f_1a')));
$SIM = ncT('comum.sim'); $NAO = ncT('comum.nao'); $ANTERIOR = ncT('cor.sistema_anterior'); $DES = ncT('comum.desativado');
$cont = ['sem_gestor' => 0, 'inativa' => 0, 'parada90' => 0, 'antigas' => 0, 'com_vencidas' => 0];
$rowsNc = [];
foreach ($ncs as $n) {
    $cod = (int)$n['CODOCORRENCIA']; $gp = $n['ORIGEM'] === 'GREENPORTAL';
    $idade = (int)$n['IDADE_DIAS']; $parada = (int)$n['DIAS_SEM_MOVIMENTO']; $semG = (int)$n['SEM_GESTOR'] === 1; $inat = (int)$n['PESSOA_INATIVA'] === 1; $venc = (int)$n['ACOES_VENCIDAS'];
    $cont['sem_gestor'] += $semG; $cont['inativa'] += $inat; $cont['parada90'] += $parada > 90; $cont['antigas'] += $idade > 365; $cont['com_vencidas'] += $venc > 0;
    $pessoa = trim(($n['GESTOR'] !== '' ? 'G: ' . $n['GESTOR'] . ((int)$n['GESTOR_ATIVO'] ? '' : ' ' . $DES) : 'G: —') . ' · ' . ($n['RESPONSAVEL'] !== '' ? 'R: ' . $n['RESPONSAVEL'] . ((int)$n['RESPONSAVEL_ATIVO'] ? '' : ' ' . $DES) : 'R: —'));
    $rowsNc[] = ['cells' => [
        'NUMERO' => ['text' => (string)$n['NUMERO'], 'sort' => str_pad((string)$cod, 9, '0', STR_PAD_LEFT)],
        'TIPO' => ['text' => (string)$n['TIPO']],
        'ETAPA' => ['text' => NaoConformidadeTextos::valor((string)$n['ETAPA'])],
        'SUMARIO' => ['text' => (string)$n['SUMARIO'], 'title' => (string)$n['SUMARIO']],
        'PESSOAS' => ['text' => $pessoa, 'title' => $pessoa],
        'SEM_GESTOR' => ['text' => $semG ? $SIM : $NAO, 'html' => $semG ? '<span class="badge text-bg-danger">' . $e($SIM) . '</span>' : $e($NAO)],
        'INATIVA' => ['text' => $inat ? $SIM : $NAO, 'html' => $inat ? '<span class="badge text-bg-warning">' . $e($SIM) . '</span>' : $e($NAO)],
        'PARADA' => ['text' => $faixaParada($parada), 'title' => ncT('san.dias_sem_mov', ['n' => $parada]), 'sort' => str_pad((string)$parada, 6, '0', STR_PAD_LEFT)],
        'IDADE' => ['text' => $faixaIdade($idade), 'title' => ncT('san.dias', ['n' => $idade]), 'sort' => str_pad((string)$idade, 6, '0', STR_PAD_LEFT)],
        'VENCIDAS' => ['text' => $venc > 0 ? $SIM . ' (' . $venc . ')' : $NAO, 'sort' => str_pad((string)$venc, 5, '0', STR_PAD_LEFT)],
        'ORIGEM' => ['text' => $gp ? 'Greenportal' : $ANTERIOR],
    ], 'actions' => $gp && $pode ? '<input class="form-check-input" type="checkbox" data-grid-marcar data-sel-nc="' . $cod . '" aria-label="' . $e(ncT('san.marcar_nc', ['n' => $n['NUMERO']])) . '">' : ''];
    // c537: Abrir (na Correção de ocorrência) na barra, para a linha selecionada; duplo clique também abre
    $rowsNc[count($rowsNc) - 1]['cells']['NUMERO']['html'] = $e($n['NUMERO']) . greenPortalGridLocalLinha('<button type="button" data-linha-acao="abrir" data-nc-abrir="naoconformidades_correcao.php?codigo=' . $cod . '" data-nc-titulo="' . $e(ncT('san.correcao_nc', ['n' => $n['NUMERO']])) . '"></button>');
}
$rowsAc = [];
foreach ($acoes as $a) {
    $gp = $a['ORIGEM'] === 'GREENPORTAL'; $ativo = (int)$a['RESPONSAVEL_ATIVO'] === 1; $dias = (int)$a['DIAS_ATRASO'];
    $rowsAc[] = ['cells' => [
        'NUMERO' => ['text' => (string)$a['NUMERO'], 'html' => $e($a['NUMERO']) . greenPortalGridLocalLinha('<button type="button" data-linha-acao="abrir" data-nc-abrir="naoconformidades_correcao.php?codigo=' . (int)$a['CODOCORRENCIA'] . '" data-nc-titulo="' . $e(ncT('san.correcao_nc', ['n' => $a['NUMERO']])) . '"></button>')],
        'CODPLANOACAO' => ['text' => (string)(int)$a['CODPLANOACAO']],
        'ACAO' => ['text' => (string)$a['ACAO'], 'title' => (string)$a['ACAO']],
        'RESPONSAVEL' => ['text' => (string)$a['RESPONSAVEL'] . ($ativo ? '' : ' ' . $DES)],
        'DATAFINAL' => ['text' => $A::data($a['DATAFINAL']), 'sort' => $A::ordenacao($a['DATAFINAL'])],
        'ATRASO' => ['text' => $faixaParada($dias), 'title' => ncT('san.dias', ['n' => $dias]), 'sort' => str_pad((string)$dias, 6, '0', STR_PAD_LEFT)],
        'STATUS' => ['text' => (int)$a['STATUS'] . '%'],
        'ORIGEM' => ['text' => $gp ? 'Greenportal' : $ANTERIOR],
    ], 'actions' => $gp && $ativo && $pode ? '<input class="form-check-input" type="checkbox" data-grid-marcar data-sel-acao="' . (int)$a['CODPLANOACAO'] . '" aria-label="' . $e(ncT('san.marcar_acao', ['n' => (int)$a['CODPLANOACAO']])) . '">' : ''];
}
$semUso = count(array_filter($listas, static fn($l) => (int)$l['QTD_NCS'] === 0));
$comInativos = count(array_filter($listas, static fn($l) => (int)$l['QTD_INATIVOS'] > 0));
$cartao = static fn(string $rotulo, int $n, string $cor, string $filtro = '', string $valor = '', string $aba = 'ncs'): string =>
    '<div class="col-6 col-md-4 col-xl-2"><button type="button" class="card w-100 text-start nc-cartao border-' . $cor . '"' . ($filtro !== '' ? ' data-filtro="' . $e($filtro) . '" data-valor="' . $e($valor) . '"' : '') . ' data-aba="' . $aba . '"><div class="card-body py-2"><div class="fs-4 fw-semibold text-' . $cor . '">' . number_format($n, 0, IdiomaService::idioma() === 'en' ? '.' : ',', IdiomaService::idioma() === 'en' ? ',' : '.') . '</div><div class="small text-muted">' . $e($rotulo) . '</div></div></button></div>';
?>
<!doctype html><html lang="<?= $e(IdiomaService::idioma() === 'pt' ? 'pt-BR' : IdiomaService::idioma()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e(ncT('san.titulo')) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php greenPortalGridLocalHead(); ?>
<style>.nc-san{padding:12px}.nc-san .nc-cartao{cursor:pointer;border-width:0 0 0 4px!important}.nc-san .nc-cartao:hover{background:#f6faf8}
.nc-san-ncs th[data-column="NUMERO"]{width:110px}.nc-san-ncs th[data-column="SUMARIO"]{width:22%}.nc-san-ncs th[data-column="SEM_GESTOR"],.nc-san-ncs th[data-column="INATIVA"]{width:90px}
.nc-san-ncs th[data-column="ORIGEM"],.nc-san-acoes th[data-column="ORIGEM"]{width:120px}.nc-san-acoes th[data-column="ACAO"]{width:30%}.nc-san .grid-row-actions-heading{width:80px}</style>
</head>
<body class="nc-san" data-transacao="naoconformidades_saneamento" data-csrf="<?= $e($A::csrf()) ?>" data-api="api/nc_administracao.php">
  <h2 class="h5 mb-1"><i class="bi bi-clipboard2-pulse me-1"></i><?= $e(ncT('san.titulo')) ?></h2>
  <p class="text-muted small mb-2"><?= $e(ncT('san.explica')) ?></p>
  <div data-nc-aviso hidden></div>
  <?php if ($erro !== ''): ?><div class="alert alert-warning py-2"><?= $e($erro) ?></div><?php else: ?>
  <div class="row g-2 mb-2">
    <?= $cartao(ncT('san.c_abertas'), count($ncs), 'secondary', '', '', 'ncs') ?>
    <?= $cartao(ncT('san.c_sem_gestor'), $cont['sem_gestor'], 'danger', 'SEM_GESTOR', $SIM) ?>
    <?= $cartao(ncT('san.c_inativa'), $cont['inativa'], 'warning', 'INATIVA', $SIM) ?>
    <?= $cartao(ncT('san.c_paradas'), count(array_filter($ncs, static fn($n) => (int)$n['DIAS_SEM_MOVIMENTO'] > 365)), 'warning', 'PARADA', ncT('san.f_1a')) ?>
    <?= $cartao(ncT('san.c_antigas'), count(array_filter($ncs, static fn($n) => (int)$n['IDADE_DIAS'] > 1095)), 'secondary', 'IDADE', ncT('san.f_3a')) ?>
    <?= $cartao(ncT('san.c_vencidas'), count($acoes), 'danger', '', '', 'acoes') ?>
  </div>
  <ul class="nav nav-tabs mb-2" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#abaNcs" type="button" role="tab"><?= $e(ncT('san.c_abertas')) ?> (<?= count($ncs) ?>)</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaAcoes" type="button" role="tab"><?= $e(ncT('san.c_vencidas')) ?> (<?= count($acoes) ?>)</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaListas" type="button" role="tab"><?= $e(ncT('san.listas')) ?></button></li>
  </ul>
  <div class="tab-content">
    <div class="tab-pane fade show active" id="abaNcs" role="tabpanel">
      <?php if ($pode): ?>
      <div class="card mb-2" data-painel-atribuir hidden><div class="card-body py-2"><div class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label mb-1 small fw-semibold" for="ncGestor"><?= $e(ncT('san.gestor_marcadas')) ?></label>
          <select class="form-select form-select-sm" id="ncGestor"><option value=""><?= $e(ncT('san.escolha_gestor')) ?></option><?php foreach ($usuarios as $c => $n): ?><option value="<?= $c ?>"><?= $e($n) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-5"><label class="form-label mb-1 small fw-semibold" for="ncMotivo"><?= $e(ncT('cor.motivo')) ?></label><input class="form-control form-control-sm" id="ncMotivo" maxlength="2000"></div>
        <div class="col-md-3 d-flex gap-2"><button type="button" class="btn btn-sm btn-success" data-acao="atribuir"><i class="bi bi-person-check me-1"></i><?= $e(ncT('san.atribuir')) ?></button>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-fechar-painel><?= $e(ncT('comum.cancelar')) ?></button></div>
      </div></div></div>
      <?php endif; ?>
      <?php greenPortalGridLocal([
          'transaction' => 'naoconformidades_saneamento', 'fragment_class' => 'nc-san-ncs', 'id' => 'ncSanNcs',
          'columns' => [
              'NUMERO' => ['label' => ncT('col.nc')], 'TIPO' => ['label' => ncT('col.tipo'), 'filter' => 'select'], 'ETAPA' => ['label' => ncT('col.etapa'), 'filter' => 'select'],
              'SUMARIO' => ['label' => ncT('col.sumario')], 'PESSOAS' => ['label' => ncT('col.gestor_resp')],
              'SEM_GESTOR' => ['label' => ncT('san.c_sem_gestor'), 'filter' => 'select'], 'INATIVA' => ['label' => ncT('col.pessoa_desativada'), 'filter' => 'select'],
              'PARADA' => ['label' => ncT('col.sem_mov'), 'filter' => 'select'], 'IDADE' => ['label' => ncT('col.aberta_ha'), 'filter' => 'select'],
              'VENCIDAS' => ['label' => ncT('san.c_vencidas')], 'ORIGEM' => ['label' => ncT('col.origem'), 'filter' => 'select'],
          ],
          'actions_label' => $pode ? ncT('san.marcar_ncs_pag') : '', 'select_all' => $pode, 'cell_boxes' => true, 'buttons' => ['columns' => true],
          'double_click_action' => 'abrir',
          'extras' => '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-grid-acao-linha="abrir" disabled title="' . $e(ncT('san.abrir_correcao')) . '" aria-label="' . $e(ncT('cor.abrir_sel')) . '"><i class="bi bi-box-arrow-up-right"></i></button>'
              . ($pode ? '<button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-abrir-painel title="' . $e(ncT('san.atribuir_dica')) . '" aria-label="' . $e(ncT('san.atribuir_aria')) . '"><i class="bi bi-person-check"></i></button>' : ''),
          'rows' => $rowsNc, 'default_sort' => ['IDADE', 'desc'], 'empty' => ncT('san.nenhuma_aberta'),
      ]); ?>
    </div>
    <div class="tab-pane fade" id="abaAcoes" role="tabpanel">

      <?php greenPortalGridLocal([
          'transaction' => 'naoconformidades_saneamento', 'fragment_class' => 'nc-san-acoes', 'id' => 'ncSanAcoes',
          'columns' => [
              'NUMERO' => ['label' => ncT('col.nc')], 'CODPLANOACAO' => ['label' => ncT('col.acao_n')], 'ACAO' => ['label' => ncT('col.acao')], 'RESPONSAVEL' => ['label' => ncT('col.responsavel')],
              'DATAFINAL' => ['label' => ncT('col.prazo'), 'filter' => 'none'], 'ATRASO' => ['label' => ncT('col.atraso'), 'filter' => 'select'], 'STATUS' => ['label' => ncT('col.status'), 'filter' => 'none'],
              'ORIGEM' => ['label' => ncT('col.origem'), 'filter' => 'select'],
          ],
          'actions_label' => $pode ? ncT('san.marcar_acoes_pag') : '', 'select_all' => $pode, 'cell_boxes' => true, 'buttons' => ['columns' => true],
          'double_click_action' => 'abrir',
          'extras' => '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-grid-acao-linha="abrir" disabled title="' . $e(ncT('san.abrir_nc_acao')) . '" aria-label="' . $e(ncT('san.abrir_nc_acao_aria')) . '"><i class="bi bi-box-arrow-up-right"></i></button>'
              . ($pode ? '<button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-acao="lembrar" title="' . $e(ncT('san.lembrar_dica')) . '" aria-label="' . $e(ncT('san.lembrar_aria')) . '"><i class="bi bi-bell"></i></button>' : ''),
          'rows' => $rowsAc, 'default_sort' => ['DATAFINAL', 'asc'], 'empty' => ncT('san.nenhuma_vencida'),
      ]); ?>
    </div>
    <div class="tab-pane fade" id="abaListas" role="tabpanel">
      <div class="card"><div class="card-body">
        <p class="mb-1"><?= ncT('san.listas_resumo', ['sem' => $semUso, 'inat' => $comInativos]) ?></p>
        <p class="text-muted small mb-2"><?= $e(ncT('san.listas_dica')) ?></p>
        <button type="button" class="btn btn-sm btn-outline-primary" data-nc-abrir="naoconformidades_listasdistribuicao.php" data-nc-titulo="<?= $e(ncT('san.listas')) ?>"><i class="bi bi-people me-1"></i><?= $e(ncT('san.abrir_listas')) ?></button>
      </div></div>
    </div>
  </div>
  <?php endif; ?>
<?php greenPortalGridLocalScripts(); ?>
<?= NaoConformidadeTextos::script() ?>
<script src="../assets/js/nc-administracao.js?v=2"></script>
<script>
(function () {
  function N(k, p) { return typeof window.greenT === 'function' ? window.greenT('naoconformidades.js.' + k, p) : p; }
  function filtrar(coluna, valor) {
    var sec = document.querySelector('.nc-san-ncs');
    if (!sec) return;
    sec.querySelectorAll('[data-column-filter]').forEach(function (f) { if (f.tagName === 'SELECT') f.value = ''; });
    if (coluna) { var f = sec.querySelector('[data-column-filter="' + coluna + '"]'); if (f) f.value = valor; }
    var alvo = sec.querySelector('[data-column-filter]');
    if (alvo) alvo.dispatchEvent(new Event('change', { bubbles: true }));
  }
  document.addEventListener('click', function (ev) {
    var c = ev.target.closest('.nc-cartao');
    if (c) {
      var aba = c.getAttribute('data-aba') === 'acoes' ? '#abaAcoes' : '#abaNcs';
      var bt = document.querySelector('[data-bs-target="' + aba + '"]'); if (bt && window.bootstrap) window.bootstrap.Tab.getOrCreateInstance(bt).show();
      if (aba === '#abaNcs') filtrar(c.getAttribute('data-filtro'), c.getAttribute('data-valor'));
      return;
    }
    var pa = ev.target.closest('[data-abrir-painel]'), painel = document.querySelector('[data-painel-atribuir]');
    if (pa && painel) { painel.hidden = !painel.hidden; if (!painel.hidden) document.getElementById('ncGestor').focus(); return; }
    if (ev.target.closest('[data-fechar-painel]') && painel) { painel.hidden = true; return; }
    var b = ev.target.closest('[data-acao]');
    if (!b) return;
    if (b.getAttribute('data-acao') === 'atribuir') {
      var ncs = Array.prototype.filter.call(document.querySelectorAll('[data-sel-nc]'), function (x) { return x.checked; }).map(function (x) { return Number(x.getAttribute('data-sel-nc')); });
      var gestor = Number(document.getElementById('ncGestor').value) || 0, motivo = document.getElementById('ncMotivo').value.trim();
      if (!ncs.length) { GPNcAdmin.aviso(N('san_marque_ncs', 'Marque as NCs (só as do greenportal podem ser marcadas).'), 'warning'); return; }
      if (!gestor) { GPNcAdmin.aviso(N('san_escolha_gestor', 'Escolha o gestor.'), 'warning'); return; }
      if (motivo.length < 5) { GPNcAdmin.aviso(N('san_informe_motivo', 'Informe o motivo.'), 'warning'); return; }
      GPNcAdmin.confirmar(N('san_atribuir', 'Atribuir gestor'), N('san_confirma', 'Atribuir {gestor} como gestor de {n} NC(s)?').replace('{gestor}', document.getElementById('ncGestor').selectedOptions[0].textContent).replace('{n}', ncs.length), function () {
        b.disabled = true;
        GPNcAdmin.gravar('atribuir_gestor', { itens: ncs, gestor: gestor, motivo: motivo }).then(function (r) {
          GPNcAdmin.aviso(N('san_atribuidos', '{n} NC(s) com gestor atribuído').replace('{n}', r.ATRIBUIDOS || 0) + (Number(r.IGNORADOS) ? '; ' + N('san_ignoradas', '{n} ignorada(s)').replace('{n}', r.IGNORADOS) : '') + '.', 'success');
          window.setTimeout(function () { window.location.reload(); }, 1800);
        }).catch(function (e) { b.disabled = false; GPNcAdmin.aviso(e.message, 'danger'); });
      });
    } else if (b.getAttribute('data-acao') === 'lembrar') {
      var acs = Array.prototype.filter.call(document.querySelectorAll('[data-sel-acao]'), function (x) { return x.checked; }).map(function (x) { return Number(x.getAttribute('data-sel-acao')); });
      if (!acs.length) { GPNcAdmin.aviso(N('san_marque_acoes', 'Marque as ações (só do greenportal e com responsável ativo).'), 'warning'); return; }
      b.disabled = true;
      GPNcAdmin.gravar('lembrar', { itens: acs }).then(function (r) {
        GPNcAdmin.aviso(N('san_lembrados', '{n} lembrete(s) enviado(s) em Home › Alertas.').replace('{n}', r.LEMBRADOS || 0), 'success'); b.disabled = false;
        document.querySelectorAll('[data-sel-acao]').forEach(function (x) { x.checked = false; });
      }).catch(function (e) { b.disabled = false; GPNcAdmin.aviso(e.message, 'danger'); });
    }
  });
}());
</script>
</body></html>
