<?php
declare(strict_types=1);

/*
 * Não Conformidades › Administração › Transferência de responsabilidades (migração 334, 22/10/2026). Para desligamento ou troca de
 * função: escolhe o usuário de origem, vê tudo o que está com ele (gestor, responsável, emitente de NCs em aberto, ações abertas e
 * listas de distribuição), marca o que vai para o usuário de destino e transfere com motivo. Cada NC alterada recebe o registro no
 * histórico e o destino é avisado em Home › Alertas. Até a virada só o que é do greenportal (NC/lista a partir de 50001) é
 * transferido; o do sistema anterior aparece para consulta ("transferir no sistema anterior").
 * Leitura: vw_nc_usuarios, vw_nc_admin_responsabilidades. Gravação: api/nc_administracao.php (sp_nc_admin_transferir).
 */

require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeAdministracao.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeTextos.php';
require_once dirname(__DIR__) . '/components/pagina_padrao.php';

$pdo = TransactionSecurity::connect(NaoConformidadeAdministracao::MENU_TRANSFERENCIA);
$perm = PermissaoTransacao::obter(NaoConformidadeAdministracao::MENU_TRANSFERENCIA);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$A = NaoConformidadeAdministracao::class;
$origem = max(0, (int)($_GET['origem'] ?? 0));
$usuarios = []; $itens = []; $erro = '';
try {
    foreach ($A::ler($pdo, 'vw_nc_usuarios') as $u) $usuarios[(int)$u['CODUSUARIO']] = ['nome' => (string)$u['NOME'], 'ativo' => (int)$u['ATIVO'] === 1];
    uasort($usuarios, static fn($a, $b) => strcasecmp($a['nome'], $b['nome']));
    if ($origem > 0) {
        $itens = $A::ler($pdo, 'vw_nc_admin_responsabilidades', ['CODUSUARIO' => $A::exato($origem)], 20000);
        $ordem = ['GESTOR' => 1, 'RESPONSAVEL' => 2, 'ACAO' => 3, 'EMITENTE' => 4, 'LISTA' => 5];
        usort($itens, static fn($a, $b) => [$ordem[$a['TIPO']] ?? 9, -(int)$a['EDITAVEL'], (int)$a['CODIGO']] <=> [$ordem[$b['TIPO']] ?? 9, -(int)$b['EDITAVEL'], (int)$b['CODIGO']]);
    }
} catch (Throwable $ex) {
    $erro = ncT('tr.sem_migracao');
    error_log('[nc transferencia] ' . $ex->getMessage());
}
$tipos = ['GESTOR' => ncT('tr.t_gestor'), 'RESPONSAVEL' => ncT('tr.t_resp'), 'ACAO' => ncT('tr.t_acao'), 'EMITENTE' => ncT('tr.t_emitente'), 'LISTA' => ncT('campo.listadedistribuicao')];
$editaveis = count(array_filter($itens, static fn($i) => (int)$i['EDITAVEL'] === 1));
$porTipo = [];
foreach ($itens as $i) { $t = (string)$i['TIPO']; $porTipo[$t] = ($porTipo[$t] ?? 0) + 1; }
$podeGravar = !empty($perm['pode_editar']);
?>
<!doctype html><html lang="<?= $e(IdiomaService::idioma() === 'pt' ? 'pt-BR' : IdiomaService::idioma()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e(ncT('tr.titulo')) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php require_once dirname(__DIR__) . '/components/grid_local.php'; greenPortalGridLocalHead(); /* c537: grid no padrão do portal */ ?>
<style>.nc-transf .card{margin-bottom:12px}.nc-transf .card-header{font-weight:600;background:#f6faf8}.nc-transf table{font-size:11px}</style>
</head>
<body class="nc-transf" data-csrf="<?= $e($A::csrf()) ?>" data-api="api/nc_administracao.php" data-origem="<?= $origem ?>">
<main class="container-fluid py-3">
  <h2 class="h5 mb-1"><i class="bi bi-arrow-left-right me-1"></i><?= $e(ncT('tr.titulo')) ?></h2>
  <p class="text-muted small mb-2"><?= $e(ncT('tr.explica')) ?></p>
  <div data-nc-aviso hidden></div>
  <?php if ($erro !== ''): ?><div class="alert alert-warning py-2"><?= $e($erro) ?></div><?php endif; ?>
  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-md-5"><label class="form-label mb-1 fw-semibold" for="origem"><?= $e(ncT('tr.de')) ?></label>
      <select class="form-select form-select-sm" id="origem" name="origem" onchange="this.form.submit()"><option value=""><?= $e(ncT('comum.escolha')) ?></option>
        <?php foreach ($usuarios as $c => $u): ?><option value="<?= $c ?>"<?= $c === $origem ? ' selected' : '' ?>><?= $e($u['nome'] . ($u['ativo'] ? '' : ' — ' . ncT('comum.desativado_traco'))) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><button class="btn btn-sm btn-success" type="submit"><i class="bi bi-search me-1"></i><?= $e(ncT('tr.ver')) ?></button></div>
  </form>

  <?php if ($origem > 0 && $erro === ''): ?>
  <section class="card">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center"><?= $e(ncT('tr.de_quem', ['nome' => $usuarios[$origem]['nome'] ?? $origem])) ?>
      <?php foreach ($porTipo as $t => $n): ?><span class="badge text-bg-light"><?= $e($tipos[$t] ?? $t) ?>: <?= $n ?></span><?php endforeach; ?></div>
    <div class="card-body">
      <?php if (!$itens): ?><p class="text-muted mb-0"><?= $e(ncT('tr.nada')) ?></p>
      <?php else: ?>
        <?php if ($editaveis < count($itens)): ?><div class="alert alert-info py-2"><?= $e(ncT('tr.legado', ['n' => count($itens) - $editaveis])) ?></div><?php endif; ?>
        <?php
        // c537: grid padrão do portal; coluna de seleção (a caixa do cabeçalho marca/desmarca as linhas da página) e Abrir NC na barra
        $linhas = [];
        foreach ($itens as $i) {
            $ed = (int)$i['EDITAVEL'] === 1;
            $resp = ($tipos[$i['TIPO']] ?? $i['TIPO']) . ($i['TIPO'] === 'ACAO' ? ' ' . (int)$i['CODIGO'] : '');
            $abrir = $i['CODOCORRENCIA'] ? greenPortalGridLocalLinha('<button type="button" data-linha-acao="abrir" data-nc-abrir="naoconformidades_ocorrencia.php?codigo=' . (int)$i['CODOCORRENCIA'] . '" data-nc-titulo="' . $e(ncT('col.nc') . ' ' . $i['NUMERO']) . '"></button>') : '';
            $linhas[] = ['cells' => [
                'TIPO' => ['text' => $resp, 'html' => $e($resp) . $abrir],
                'NUMERO' => ['text' => (string)$i['NUMERO']],
                'DESCRICAO' => ['text' => mb_substr((string)$i['DESCRICAO'], 0, 300), 'title' => (string)$i['DESCRICAO']],
                'ETAPA' => ['text' => NaoConformidadeTextos::valor((string)$i['ETAPA'])],
                'ORIGEM' => ['text' => $ed ? 'Greenportal' : ncT('cor.sistema_anterior')],
            ], 'actions' => $ed ? '<input class="form-check-input" type="checkbox" data-grid-marcar data-item data-tipo="' . $e($i['TIPO']) . '" data-codigo="' . (int)$i['CODIGO'] . '" checked aria-label="' . $e(ncT('tr.transferir_aria')) . '">' : ''];
        }
        greenPortalGridLocal([
            'transaction' => 'naoconformidades_transferencia', 'fragment_class' => 'nc-grid-transferencia', 'id' => 'ncTransfGrid',
            'columns' => ['TIPO' => ['label' => ncT('col.responsabilidade'), 'filter' => 'select', 'width' => '200px'], 'NUMERO' => ['label' => ncT('col.nc_lista'), 'width' => '130px'], 'DESCRICAO' => ['label' => ncT('col.descricao')],
                'ETAPA' => ['label' => ncT('col.etapa'), 'filter' => 'select', 'width' => '190px'], 'ORIGEM' => ['label' => ncT('col.origem'), 'filter' => 'select', 'width' => '140px']],
            'actions_label' => ncT('tr.marcar_pag'), 'select_all' => $editaveis > 0, 'cell_boxes' => true, 'rows' => $linhas, 'buttons' => ['reload' => false, 'columns' => true],
            'double_click_action' => 'abrir',
            'extras' => '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-grid-acao-linha="abrir" disabled title="' . $e(ncT('cor.abrir_sel')) . '" aria-label="' . $e(ncT('cor.abrir_sel')) . '"><i class="bi bi-box-arrow-up-right"></i></button>',
        ]);
        ?>
        <?php if ($podeGravar && $editaveis): ?>
        <div class="row g-2 mt-2">
          <div class="col-md-5"><label class="form-label mb-1 fw-semibold" for="destino"><?= $e(ncT('tr.para')) ?></label>
            <select class="form-select form-select-sm" id="destino"><option value=""><?= $e(ncT('comum.escolha')) ?></option>
              <?php foreach ($usuarios as $c => $u): if (!$u['ativo'] || $c === $origem) continue; ?><option value="<?= $c ?>"><?= $e($u['nome']) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-7"><label class="form-label mb-1 fw-semibold" for="ncMotivo"><?= $e(ncT('cor.motivo')) ?></label>
            <input class="form-control form-control-sm" id="ncMotivo" maxlength="2000" placeholder="<?= $e(ncT('tr.motivo_ph')) ?>"></div>
        </div>
        <button type="button" class="btn btn-sm btn-success mt-2" data-acao="transferir"><i class="bi bi-arrow-left-right me-1"></i><?= $e(ncT('tr.transferir')) ?></button>
        <span class="text-muted small ms-2" data-contagem></span>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>
</main>
<?php greenPortalGridLocalScripts(); ?>
<?= NaoConformidadeTextos::script() ?>
<script src="../assets/js/nc-administracao.js?v=2"></script>
<script>
(function () {
  function N(k, p) { return typeof window.greenT === 'function' ? window.greenT('naoconformidades.js.' + k, p) : p; }
  var origem = Number(document.body.getAttribute('data-origem')) || 0;
  function marcados() { return Array.prototype.filter.call(document.querySelectorAll('[data-item]'), function (c) { return c.checked; }); }
  function contar() { var c = document.querySelector('[data-contagem]'); if (c) c.textContent = N('tr_marcados', '{n} marcado(s)').replace('{n}', marcados().length); }
  document.addEventListener('change', function (ev) {
    contar();
  });
  contar();
  var cab = document.querySelector('[data-grid-marcar-pagina]'); if (cab) cab.checked = true; // os itens já vêm marcados
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-acao="transferir"]'); if (!b) return;
    var itens = marcados().map(function (c) { return { tipo: c.getAttribute('data-tipo'), codigo: Number(c.getAttribute('data-codigo')) }; });
    var destino = Number(document.getElementById('destino').value) || 0, motivo = document.getElementById('ncMotivo').value.trim();
    if (!itens.length) { GPNcAdmin.aviso(N('tr_marque', 'Marque o que transferir.'), 'warning'); return; }
    if (!destino) { GPNcAdmin.aviso(N('tr_escolha', 'Escolha o usuário de destino.'), 'warning'); return; }
    if (motivo.length < 5) { GPNcAdmin.aviso(N('san_informe_motivo', 'Informe o motivo.'), 'warning'); return; }
    var nome = document.getElementById('destino').selectedOptions[0].textContent;
    GPNcAdmin.confirmar(N('tr_titulo', 'Transferir responsabilidades'), N('tr_confirma', 'Transferir {n} item(ns) para {nome}? Cada NC recebe o registro no histórico e {nome} é avisado(a).').replace('{n}', itens.length).split('{nome}').join(nome), function () {
      b.disabled = true;
      GPNcAdmin.gravar('transferir', { origem: origem, destino: destino, itens: itens, motivo: motivo })
        .then(function (r) {
          GPNcAdmin.aviso(N('tr_transferidos', '{n} transferido(s)').replace('{n}', r.TRANSFERIDOS || 0) + (Number(r.IGNORADOS) ? '; ' + N('tr_ignorados', '{n} ignorado(s) (já não estavam com a pessoa ou a NC foi finalizada)').replace('{n}', r.IGNORADOS) : '') + '.', 'success');
          window.setTimeout(function () { window.location.reload(); }, 1800);
        }).catch(function (e) { b.disabled = false; GPNcAdmin.aviso(e.message, 'danger'); });
    });
  });
}());
</script>
</body></html>
