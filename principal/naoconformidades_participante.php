<?php
declare(strict_types=1);

/*
 * Participante externo de NC (popup aberto por Não Conformidades › Administração › Participantes externos; migração 333).
 * Novo ou do greenportal: nome, e-mail(s) e Ativo. Do sistema anterior: nome e e-mail só consulta (vêm pelo sincronismo), Ativo
 * pode ser alterado (desativado deixa de receber os e-mails). Mostra as listas em que está e o histórico das alterações.
 * Leitura: vw_nc_participantes, vw_nc_participante_listas, vw_nc_admin_historico. Gravação: api/nc_administracao.php (sp_nc_participante_salvar).
 */

require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeAdministracao.php';
require_once dirname(__DIR__) . '/components/pagina_padrao.php';

$pdo = TransactionSecurity::connect(NaoConformidadeAdministracao::MENU_PARTICIPANTES);
$perm = PermissaoTransacao::obter(NaoConformidadeAdministracao::MENU_PARTICIPANTES);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$A = NaoConformidadeAdministracao::class;
$codigo = max(0, (int)($_GET['codigo'] ?? 0));
$erro = ''; $p = null; $listas = []; $historico = [];
try {
    if ($codigo > 0) {
        $p = $A::ler($pdo, 'vw_nc_participantes', ['CODUSUARIO' => $A::exato($codigo)])[0] ?? null;
        if (!$p) { http_response_code(404); $erro = 'Participante ' . $codigo . ' não encontrado.'; }
        else {
            $listas = $A::ler($pdo, 'vw_nc_participante_listas', ['CODUSUARIO' => $A::exato($codigo)]);
            usort($listas, static fn($a, $b) => strcasecmp((string)$a['DSCLISTA'], (string)$b['DSCLISTA']));
            $historico = $A::ler($pdo, 'vw_nc_admin_historico', ['OBJETO' => $A::exato('PARTICIPANTE'), 'CODIGO' => $A::exato($codigo)], 1000);
            usort($historico, static fn($a, $b) => (int)$b['CODHISTORICO'] <=> (int)$a['CODHISTORICO']);
        }
    }
} catch (Throwable $ex) {
    $erro = 'Participantes não disponíveis neste banco: aplique a migração 333.';
    error_log('[nc participante] ' . $ex->getMessage());
}
$legado = ($p['ORIGEM'] ?? '') === 'LEGADO';
$podeGravar = $erro === '' && ($codigo > 0 ? !empty($perm['pode_editar']) : !empty($perm['pode_incluir']));
$titulo = $codigo > 0 ? 'Participante externo ' . $codigo : 'Novo participante externo';
$descreve = static function (array $h): string {
    $a = json_decode((string)($h['ANTES'] ?? ''), true) ?: []; $d = json_decode((string)($h['DEPOIS'] ?? ''), true) ?: [];
    $p = [];
    foreach (['nome' => 'nome', 'email' => 'e-mail', 'ativo' => 'ativo'] as $k => $r) {
        if (!$a) { if (isset($d[$k])) $p[] = $r . ': ' . ($k === 'ativo' ? ((int)$d[$k] ? 'sim' : 'não') : $d[$k]); continue; }
        if (($a[$k] ?? null) != ($d[$k] ?? null)) $p[] = $r . ': ' . ($k === 'ativo' ? (((int)($a[$k] ?? 0) ? 'sim' : 'não') . ' → ' . ((int)($d[$k] ?? 0) ? 'sim' : 'não')) : '"' . ($a[$k] ?? '') . '" → "' . ($d[$k] ?? '') . '"');
    }
    return implode('; ', $p);
};
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($titulo) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php require_once dirname(__DIR__) . '/components/grid_local.php'; greenPortalGridLocalHead(); /* c537: grids no padrão do portal */ ?>
<style>.nc-part .card{margin-bottom:12px}.nc-part .card-header{font-weight:600;background:#f6faf8}.nc-part table{font-size:11px}</style>
</head>
<body class="nc-part" data-csrf="<?= $e($A::csrf()) ?>" data-api="api/nc_administracao.php" data-codigo="<?= $codigo ?>">
<main class="container-fluid py-3">
<div data-nc-aviso hidden></div>
<?php if ($erro !== ''): ?>
  <div class="alert alert-danger"><?= $e($erro) ?></div>
<?php else: ?>
  <div class="d-flex align-items-center gap-2 mb-2">
    <h2 class="h5 mb-0"><i class="bi bi-person-badge me-1"></i><?= $e($titulo) ?></h2>
    <?php if ($codigo > 0): ?><span class="badge <?= $legado ? 'text-bg-secondary' : 'text-bg-success' ?>"><?= $legado ? 'Sistema anterior' : 'Greenportal' ?></span><?php endif; ?>
  </div>
  <p class="text-muted small mb-2">Participante externo não tem acesso ao portal: recebe por e-mail os avisos das listas de distribuição em que está.</p>
  <?php if ($legado): ?><div class="alert alert-info py-2">Cadastrado no sistema anterior: nome e e-mail são alterados lá até a virada (chegam pelo sincronismo). Aqui dá para desativar ou reativar o envio.</div><?php endif; ?>
  <?php if ((int)($p['TAMBEM_USUARIO'] ?? 0) === 1): ?><div class="alert alert-warning py-2">Este código também é um usuário do portal: nas listas ele aparece como usuário e recebe os avisos em Home › Alertas.</div><?php endif; ?>
  <section class="card">
    <div class="card-header">Dados</div>
    <div class="card-body">
      <div class="row g-2">
        <div class="col-md-5"><label class="form-label mb-1" for="ncNome">Nome</label>
          <input class="form-control form-control-sm" id="ncNome" maxlength="200" value="<?= $e($p['NOME'] ?? '') ?>" <?= $podeGravar && !$legado ? '' : 'disabled' ?>></div>
        <div class="col-md-5"><label class="form-label mb-1" for="ncEmail">E-mail <span class="text-muted">(mais de um: separe com ;)</span></label>
          <input class="form-control form-control-sm" id="ncEmail" maxlength="500" value="<?= $e($p['EMAIL'] ?? '') ?>" <?= $podeGravar && !$legado ? '' : 'disabled' ?>></div>
        <div class="col-md-2"><label class="form-label mb-1" for="ncAtivo">Ativo</label>
          <select class="form-select form-select-sm" id="ncAtivo" <?= $podeGravar ? '' : 'disabled' ?>><option value="1"<?= (int)($p['ATIVO'] ?? 1) === 1 ? ' selected' : '' ?>>Sim</option><option value="0"<?= (int)($p['ATIVO'] ?? 1) === 0 ? ' selected' : '' ?>>Não</option></select></div>
      </div>
      <?php if ($podeGravar): ?><button type="button" class="btn btn-sm btn-success mt-3" data-acao="salvar"><i class="bi bi-check2 me-1"></i>Salvar</button><?php endif; ?>
    </div>
  </section>
  <?php if ($codigo > 0): ?>
  <section class="card">
    <div class="card-header">Listas de distribuição (<?= count($listas) ?>)</div>
    <div class="card-body"><?php
      // c537: grid no padrão do portal
      $linhas = [];
      foreach ($listas as $l) $linhas[] = ['cells' => ['CODLISTA' => ['text' => (string)(int)$l['CODLISTA'], 'sort' => str_pad((string)(int)$l['CODLISTA'], 9, '0', STR_PAD_LEFT)], 'DSCLISTA' => ['text' => (string)$l['DSCLISTA'], 'title' => (string)$l['DSCLISTA']]]];
      greenPortalGridLocal([
          'transaction' => 'naoconformidades_participante_listas', 'fragment_class' => 'nc-grid-listas', 'id' => 'ncPartListasGrid', 'page_size' => 10,
          'columns' => ['CODLISTA' => ['label' => 'Código', 'width' => '100px'], 'DSCLISTA' => ['label' => 'Lista']],
          'cell_boxes' => true, 'rows' => $linhas, 'default_sort' => ['DSCLISTA', 'asc'], 'buttons' => ['reload' => false],
          'empty' => 'Não está em nenhuma lista. Para incluir, abra a lista em Listas de distribuição.',
      ]);
    ?></div>
  </section>
  <?php if ($historico): ?>
  <section class="card">
    <div class="card-header">Histórico de alterações no greenportal</div>
    <div class="card-body"><?php
      $linhas = [];
      foreach ($historico as $h) { $t = ($h['ACAO'] === 'PARTICIPANTE_INCLUIDO' ? 'Incluído — ' : '') . $descreve($h);
          $linhas[] = ['cells' => ['CRIADO_EM' => ['text' => $A::data($h['CRIADO_EM'], true), 'sort' => $A::ordenacao($h['CRIADO_EM'])], 'USUARIO' => ['text' => (string)$h['NOME_USUARIO']], 'DETALHE' => ['text' => $t, 'title' => $t]]]; }
      greenPortalGridLocal([
          'transaction' => 'naoconformidades_participante_historico', 'fragment_class' => 'nc-grid-historico', 'id' => 'ncPartHistGrid', 'page_size' => 10,
          'columns' => ['CRIADO_EM' => ['label' => 'Data', 'filter' => 'none', 'width' => '130px'], 'USUARIO' => ['label' => 'Usuário', 'filter' => 'select', 'width' => '200px'], 'DETALHE' => ['label' => 'Alteração']],
          'cell_boxes' => true, 'rows' => $linhas, 'default_sort' => ['CRIADO_EM', 'desc'], 'buttons' => ['reload' => false], 'empty' => 'Sem alterações.',
      ]);
    ?></div>
  </section>
  <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
</main>
<?php greenPortalGridLocalScripts(); ?>
<script src="../assets/js/nc-administracao.js?v=1"></script>
<script>
(function () {
  var cod = Number(document.body.getAttribute('data-codigo')) || 0;
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-acao="salvar"]'); if (!b) return;
    b.disabled = true;
    GPNcAdmin.gravar('participante_salvar', { codigo: cod, nome: document.getElementById('ncNome').value, email: document.getElementById('ncEmail').value, ativo: document.getElementById('ncAtivo').value === '1' })
      .then(function (r) {
        var url = 'naoconformidades_participante.php?codigo=' + encodeURIComponent(r.CODUSUARIO || cod) + '&gravado=1';
        if (r.AVISO) url += '&aviso=' + encodeURIComponent(r.AVISO);
        window.location.href = url;
      }).catch(function (e) { b.disabled = false; GPNcAdmin.aviso(e.message, 'danger'); });
  });
  var q = new URLSearchParams(window.location.search);
  if (q.get('aviso')) GPNcAdmin.aviso(q.get('aviso'), 'warning'); else if (q.get('gravado')) GPNcAdmin.aviso('Gravado.', 'success');
}());
</script>
</body></html>
