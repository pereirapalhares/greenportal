<?php
declare(strict_types=1);

/*
 * Não Conformidades › Administração › Correção de ocorrência (migração 334, 22/10/2026 — substitui as 12 telas de correção do legado).
 * Busca a NC pelo número, mostra os dados atuais e corrige num só lugar: emitente, gestor, responsável, responsáveis das ações,
 * data, unidade, lista, sumário, descrição, ação imediata, abrangência e, conforme o tipo, fornecedor, quantidades e cliente.
 * Etapa só por ações controladas: reabrir, devolver uma etapa, encerrar administrativamente. Motivo obrigatório; o "antes → depois"
 * fica no histórico da NC e quem passa a ser gestor/responsável é avisado em Home › Alertas.
 * Até a virada só as NCs do greenportal (a partir de 50001) são alteradas; as do sistema anterior aparecem só para consulta.
 * Leitura por views (vw_nc_ocorrencia, vw_nc_usuarios, vw_nc_opcoes, vw_nc_tipo_campos, vw_nc_planodeacao, vw_nc_historico) e
 * sp_nc_admin_etapa_destino; gravação por api/nc_administracao.php (sp_nc_admin_corrigir, sp_nc_admin_etapa).
 */

require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeAdministracao.php';
require_once dirname(__DIR__) . '/components/pagina_padrao.php';

$pdo = TransactionSecurity::connect(NaoConformidadeAdministracao::MENU_CORRECAO);
$perm = PermissaoTransacao::obter(NaoConformidadeAdministracao::MENU_CORRECAO);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$A = NaoConformidadeAdministracao::class;
$busca = trim((string)($_GET['nc'] ?? ''));
$codigo = max(0, (int)($_GET['codigo'] ?? 0));
$erro = ''; $nc = null; $achadas = [];
try {
    if ($codigo === 0 && $busca !== '') {
        $achadas = str_contains($busca, '/') ? $A::ler($pdo, 'vw_nc_ocorrencia', ['NRANO' => $A::exato($busca)], 50)
            : (ctype_digit($busca) ? $A::ler($pdo, 'vw_nc_ocorrencia', ['CODOCORRENCIA' => $A::exato($busca)], 5) : []);
        if (!$achadas && ctype_digit($busca)) $achadas = $A::ler($pdo, 'vw_nc_ocorrencia', ['NRANO' => $busca . '/'], 50);
        if (count($achadas) === 1) $codigo = (int)$achadas[0]['CODOCORRENCIA'];
        elseif (!$achadas) $erro = 'Nenhuma não conformidade com o número "' . $busca . '".';
    }
    if ($codigo > 0) {
        $nc = $A::ler($pdo, 'vw_nc_ocorrencia', ['CODOCORRENCIA' => $A::exato($codigo)], 5)[0] ?? null;
        if (!$nc) $erro = 'Não conformidade ' . $codigo . ' não encontrada.';
    }
} catch (Throwable $ex) {
    $erro = 'Não foi possível ler a não conformidade: aplique as migrações 321 e 333.';
    error_log('[nc correcao] ' . $ex->getMessage());
}
$legado = $nc && $codigo < 50001;
$editavel = $nc && !$legado && !empty($perm['pode_editar']);
$usuarios = []; $opcoes = []; $campos = []; $acoes = []; $destinos = []; $historico = [];
if ($nc) {
    foreach ($A::ler($pdo, 'vw_nc_usuarios') as $u) $usuarios[(int)$u['CODUSUARIO']] = ['nome' => (string)$u['NOME'], 'ativo' => (int)$u['ATIVO'] === 1];
    uasort($usuarios, static fn($a, $b) => strcasecmp($a['nome'], $b['nome']));
    foreach (['UNIDADE', 'LISTA', 'FORNECEDOR', 'CLIENTE'] as $l) {
        $opcoes[$l] = [];
        foreach ($A::ler($pdo, 'vw_nc_opcoes', ['LISTA' => $A::exato($l)]) as $o) $opcoes[$l][(string)$o['CODIGO']] = (string)$o['DESCRICAO'];
        asort($opcoes[$l], SORT_NATURAL | SORT_FLAG_CASE);
    }
    $campos = array_flip(array_map('strtoupper', array_column($A::ler($pdo, 'vw_nc_tipo_campos', ['CODTIPONAOCONFORMIDADE' => $A::exato((int)$nc['CODTIPONAOCONFORMIDADE'])]), 'CAMPO')));
    $acoes = $A::ler($pdo, 'vw_nc_planodeacao', ['CODOCORRENCIA' => $A::exato($codigo)], 2000);
    usort($acoes, static fn($a, $b) => (int)$a['CODPLANOACAO'] <=> (int)$b['CODPLANOACAO']);
    if ($editavel) foreach (['REABRIR', 'DEVOLVER', 'ENCERRAR'] as $acao) $destinos[$acao] = $A::proc($pdo, 'sp_nc_admin_etapa_destino', [$codigo, $acao])[0] ?? [];
    foreach ($A::ler($pdo, 'vw_nc_historico', ['CODOCORRENCIA' => $A::exato($codigo)], 5000) as $h) if (isset($A::ACOES_HISTORICO[$h['ACAO']])) $historico[] = $h;
    usort($historico, static fn($a, $b) => (int)$b['CODHISTORICO'] <=> (int)$a['CODHISTORICO']);
}
$selUsuario = static function (string $id, mixed $atual, bool $vazio, bool $habilitado) use ($usuarios, $e): string {
    $atual = (int)$atual;
    $h = '<select class="form-select form-select-sm" id="' . $e($id) . '" data-campo="' . $e($id) . '" data-inicial="' . ($atual ?: '') . '"' . ($habilitado ? '' : ' disabled') . '>';
    if ($vazio || !$atual) $h .= '<option value="">' . ($vazio ? '(nenhum)' : '(escolha)') . '</option>';
    if ($atual && (!isset($usuarios[$atual]) || !$usuarios[$atual]['ativo'])) $h .= '<option value="' . $atual . '" selected>' . $e(($usuarios[$atual]['nome'] ?? $atual . ' (não cadastrado)') . ' — desativado') . '</option>';
    foreach ($usuarios as $c => $u) if ($u['ativo']) $h .= '<option value="' . $c . '"' . ($c === $atual ? ' selected' : '') . '>' . $e($u['nome']) . '</option>';
    return $h . '</select>';
};
$selOpcao = static function (string $id, string $lista, mixed $atual, bool $vazio, bool $habilitado) use ($opcoes, $e): string {
    $atual = trim((string)$atual);
    $h = '<select class="form-select form-select-sm" id="' . $e($id) . '" data-campo="' . $e($id) . '" data-inicial="' . $e($atual) . '"' . ($habilitado ? '' : ' disabled') . '>';
    if ($vazio || $atual === '') $h .= '<option value="">' . ($vazio ? '(nenhum)' : '(escolha)') . '</option>';
    if ($atual !== '' && !isset($opcoes[$lista][$atual])) $h .= '<option value="' . $e($atual) . '" selected>' . $e($atual . ' (não cadastrado)') . '</option>';
    foreach ($opcoes[$lista] ?? [] as $c => $d) $h .= '<option value="' . $e($c) . '"' . ((string)$c === $atual ? ' selected' : '') . '>' . $e($d) . '</option>';
    return $h . '</select>';
};
$texto = static fn(string $id, mixed $v, bool $hab, int $linhas = 0, int $max = 0): string => $linhas
    ? '<textarea class="form-control form-control-sm" id="' . $e($id) . '" data-campo="' . $e($id) . '" rows="' . $linhas . '"' . ($hab ? '' : ' disabled') . '>' . $e($v) . '</textarea>'
    : '<input class="form-control form-control-sm" id="' . $e($id) . '" data-campo="' . $e($id) . '" value="' . $e($v) . '"' . ($max ? ' maxlength="' . $max . '"' : '') . ($hab ? '' : ' disabled') . '>';
$rotuloEtapa = ['REABRIR' => ['Reabrir', 'bi-arrow-counterclockwise', 'Volta para a etapa anterior à finalização.'], 'DEVOLVER' => ['Devolver uma etapa', 'bi-arrow-return-left', 'Volta para a etapa de onde a NC veio.'], 'ENCERRAR' => ['Encerrar administrativamente', 'bi-x-octagon', 'Finaliza com a situação "ENCERRADO ADMINISTRATIVAMENTE".']];
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Correção de ocorrência</title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php require_once dirname(__DIR__) . '/components/grid_local.php'; greenPortalGridLocalHead(); /* c537: grids no padrão do portal */ ?>
<style>.nc-corr .card{margin-bottom:12px}.nc-corr .card-header{font-weight:600;background:#f6faf8}.nc-corr table{font-size:11px}.nc-corr .form-label{margin-bottom:2px;font-weight:600}
.nc-corr [data-campo].alterado{border-color:#e0a800;background:#fffaf0}</style>
</head>
<body class="nc-corr" data-csrf="<?= $e($A::csrf()) ?>" data-api="api/nc_administracao.php" data-codigo="<?= $codigo ?>">
<main class="container-fluid py-3">
  <h2 class="h5 mb-1"><i class="bi bi-pencil-square me-1"></i>Correção de ocorrência</h2>
  <p class="text-muted small mb-2">Corrija dados de uma não conformidade com motivo; tudo fica no histórico da NC. Até a virada, só as NCs criadas no greenportal são alteradas aqui.</p>
  <form class="d-flex gap-2 mb-3 align-items-center" method="get" style="max-width:520px">
    <input class="form-control form-control-sm" name="nc" value="<?= $e($busca !== '' ? $busca : ($nc['NRANO'] ?? '')) ?>" placeholder="Número da NC (ex.: 50012/2026 ou 50012)" aria-label="Número da NC" autofocus>
    <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-search me-1"></i>Buscar</button>
  </form>
  <div data-nc-aviso hidden></div>
  <?php if ($erro !== ''): ?><div class="alert alert-warning py-2"><?= $e($erro) ?></div><?php endif; ?>
  <?php if (count($achadas) > 1): ?>
    <div class="card"><div class="card-header">Mais de uma NC com este número — escolha</div><div class="card-body"><?php
      // c537: grid padrão; Abrir na barra (linha selecionada) ou duplo clique
      $linhas = [];
      foreach ($achadas as $a) $linhas[] = ['cells' => [
          'NRANO' => ['text' => (string)$a['NRANO'], 'html' => $e($a['NRANO']) . greenPortalGridLocalLinha('<a href="?codigo=' . (int)$a['CODOCORRENCIA'] . '" data-linha-acao="abrir"></a>')],
          'TIPO' => ['text' => (string)($a['DSCTIPONAOCONFORMIDADE'] ?? '')], 'SUMARIO' => ['text' => (string)($a['IDENTIFICACAO'] ?? ''), 'title' => (string)($a['IDENTIFICACAO'] ?? '')], 'ETAPA' => ['text' => (string)($a['ETAPA'] ?? '')]]];
      greenPortalGridLocal([
          'transaction' => 'naoconformidades_correcao_busca', 'fragment_class' => 'nc-grid-busca', 'id' => 'ncBuscaGrid', 'page_size' => 10,
          'columns' => ['NRANO' => ['label' => 'NC', 'width' => '130px'], 'TIPO' => ['label' => 'Tipo', 'filter' => 'select', 'width' => '180px'], 'SUMARIO' => ['label' => 'Sumário'], 'ETAPA' => ['label' => 'Etapa', 'filter' => 'select', 'width' => '200px']],
          'cell_boxes' => true, 'rows' => $linhas, 'buttons' => ['reload' => false], 'double_click_action' => 'abrir',
          'extras' => '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-grid-acao-linha="abrir" disabled title="Abrir a NC selecionada" aria-label="Abrir a NC selecionada"><i class="bi bi-box-arrow-up-right"></i></button>',
      ]);
    ?></div></div>
  <?php endif; ?>

  <?php if ($nc): $tem = static fn(string $c): bool => isset($campos[$c]); $dataOc = substr((string)($nc['DATA_OCORRENCIA'] ?? ''), 0, 10); if ($dataOc === '0000-00-00') $dataOc = ''; ?>
  <section class="card">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
      NC <?= $e($nc['NRANO'] ?: $codigo) ?> — <?= $e($nc['DSCTIPONAOCONFORMIDADE'] ?? '') ?>
      <span class="badge text-bg-light">Etapa: <?= $e($nc['ETAPA'] ?? '') ?></span>
      <span class="badge <?= $legado ? 'text-bg-secondary' : 'text-bg-success' ?>"><?= $legado ? 'Sistema anterior' : 'Greenportal' ?></span>
      <button type="button" class="btn btn-sm btn-outline-primary ms-auto" data-nc-abrir="naoconformidades_ocorrencia.php?codigo=<?= $codigo ?>" data-nc-titulo="NC <?= $e($nc['NRANO'] ?: $codigo) ?>"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir a NC</button>
    </div>
    <div class="card-body">
      <?php if ($legado): ?><div class="alert alert-info py-2">NC do sistema anterior: até a virada a correção é feita lá (as alterações chegam pelo sincronismo). Aqui só consulta.</div>
      <?php elseif (empty($perm['pode_editar'])): ?><div class="alert alert-info py-2">Seu perfil só consulta esta tela.</div><?php endif; ?>
      <div class="row g-2">
        <div class="col-md-4"><label class="form-label" for="CODUSUARIO_EMISSOR">Emitente</label><?= $selUsuario('CODUSUARIO_EMISSOR', $nc['CODUSUARIO_EMISSOR'], false, (bool)$editavel) ?></div>
        <div class="col-md-4"><label class="form-label" for="CODUSUARIO_GESTOR">Gestor</label><?= $selUsuario('CODUSUARIO_GESTOR', $nc['CODUSUARIO_GESTOR'], false, (bool)$editavel) ?></div>
        <div class="col-md-4"><label class="form-label" for="CODUSUARIO_RESPONSAVEL">Responsável</label><?= $selUsuario('CODUSUARIO_RESPONSAVEL', $nc['CODUSUARIO_RESPONSAVEL'], true, (bool)$editavel) ?></div>
        <div class="col-md-3"><label class="form-label" for="DATA_OCORRENCIA">Data da ocorrência</label>
          <input class="form-control form-control-sm" type="date" id="DATA_OCORRENCIA" data-campo="DATA_OCORRENCIA" max="<?= date('Y-m-d') ?>" value="<?= $e($dataOc) ?>" data-inicial="<?= $e($dataOc) ?>"<?= $editavel ? '' : ' disabled' ?>></div>
        <div class="col-md-4"><label class="form-label" for="codunidade">Unidade</label><?= $selOpcao('codunidade', 'UNIDADE', $nc['codunidade'], false, (bool)$editavel) ?></div>
        <div class="col-md-5"><label class="form-label" for="listadedistribuicao">Lista de distribuição</label><?= $selOpcao('listadedistribuicao', 'LISTA', $nc['listadedistribuicao'], true, (bool)$editavel) ?></div>
        <div class="col-12"><label class="form-label" for="IDENTIFICACAO">Sumário</label><?= $texto('IDENTIFICACAO', $nc['IDENTIFICACAO'] ?? '', (bool)$editavel, 0, 500) ?></div>
        <div class="col-12"><label class="form-label" for="DESCRICAO">Descrição</label><?= $texto('DESCRICAO', $nc['DESCRICAO'] ?? '', (bool)$editavel, 3) ?></div>
        <div class="col-md-6"><label class="form-label" for="PROVIDENCIA">Ação imediata</label><?= $texto('PROVIDENCIA', $nc['PROVIDENCIA'] ?? '', (bool)$editavel, 2) ?></div>
        <div class="col-md-6"><label class="form-label" for="ANALISE_ABRANGENCIA">Análise de abrangência</label><?= $texto('ANALISE_ABRANGENCIA', $nc['ANALISE_ABRANGENCIA'] ?? '', (bool)$editavel, 2) ?></div>
        <?php if ($tem('CODFORNECEDOR')): ?><div class="col-md-6"><label class="form-label" for="CODFORNECEDOR">Fornecedor</label><?= $selOpcao('CODFORNECEDOR', 'FORNECEDOR', $nc['CODFORNECEDOR'], false, (bool)$editavel) ?></div><?php endif; ?>
        <?php if ($tem('QTDENVOLVIDA')): ?><div class="col-md-3"><label class="form-label" for="QTDENVOLVIDA">Quantidade envolvida</label><?= $texto('QTDENVOLVIDA', $nc['QTDENVOLVIDA'] ?? '', (bool)$editavel, 0, 100) ?></div><?php endif; ?>
        <?php if ($tem('QTDDEVOLVIDA')): ?><div class="col-md-3"><label class="form-label" for="QTDDEVOLVIDA">Quantidade devolvida</label><?= $texto('QTDDEVOLVIDA', $nc['QTDDEVOLVIDA'] ?? '', (bool)$editavel, 0, 100) ?></div><?php endif; ?>
        <?php if ($tem('DSCCLIENTE')): ?><div class="col-md-6"><label class="form-label" for="DSCCLIENTE">Cliente</label><?= $selOpcao('DSCCLIENTE', 'CLIENTE', trim((string)($nc['DSCCLIENTE'] ?? '')), false, (bool)$editavel) ?></div><?php endif; ?>
      </div>
      <?php if ($acoes): ?>
        <h3 class="h6 mt-3">Responsáveis das ações</h3>
        <?php
        // c537: grid padrão; o responsável de cada ação é escolhido na própria linha (como os campos do grid padrão)
        $linhas = [];
        foreach ($acoes as $a) $linhas[] = ['cells' => [
            'CODPLANOACAO' => ['text' => (string)(int)$a['CODPLANOACAO']],
            'ACAO' => ['text' => mb_substr((string)$a['ACAO'], 0, 300), 'title' => (string)$a['ACAO']],
            'DATAFINAL' => ['text' => $A::data($a['DATAFINAL'] ?? ''), 'sort' => $A::ordenacao($a['DATAFINAL'] ?? '')],
            'STATUS' => ['text' => (int)$a['STATUS'] . '%'],
            'RESPONSAVEL' => ['text' => (string)($a['RESPONSAVEL'] ?? ''), 'html' => '<div data-acao-plano="' . (int)$a['CODPLANOACAO'] . '">' . $selUsuario('acao_' . (int)$a['CODPLANOACAO'], $a['CODUSUARIO'], false, (bool)$editavel) . '</div>'],
        ]];
        greenPortalGridLocal([
            'transaction' => 'naoconformidades_correcao_acoes', 'fragment_class' => 'nc-grid-acoes', 'id' => 'ncCorrAcoesGrid', 'page_size' => 10,
            'columns' => ['CODPLANOACAO' => ['label' => 'Ação nº', 'width' => '90px'], 'ACAO' => ['label' => 'Descrição'], 'DATAFINAL' => ['label' => 'Prazo', 'filter' => 'none', 'width' => '110px'],
                'STATUS' => ['label' => 'Status', 'filter' => 'select', 'width' => '90px'], 'RESPONSAVEL' => ['label' => 'Responsável', 'width' => '280px']],
            'rows' => $linhas, 'buttons' => ['reload' => false], 'empty' => 'Sem ações.',
        ]);
        ?>
      <?php endif; ?>
      <?php if ($editavel): ?>
        <label class="form-label mt-3" for="ncMotivo">Motivo (obrigatório)</label>
        <textarea class="form-control form-control-sm" id="ncMotivo" rows="2" maxlength="2000" placeholder="Por que a correção está sendo feita (vai para o histórico da NC)"></textarea>
        <button type="button" class="btn btn-sm btn-success mt-2" data-acao="corrigir"><i class="bi bi-check2 me-1"></i>Salvar correções</button>
        <span class="text-muted small ms-2">Só os campos alterados (destacados) são gravados.</span>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($editavel): ?>
  <section class="card">
    <div class="card-header">Etapa</div>
    <div class="card-body">
      <p class="small text-muted mb-2">Etapa atual: <b><?= $e($nc['ETAPA'] ?? '') ?></b>. A etapa só muda pelas ações abaixo, com o motivo informado acima; emitente, gestor e responsável são avisados.</p>
      <div class="d-flex flex-wrap gap-2">
      <?php foreach ($destinos as $acao => $d): $imp = (string)($d['IMPEDIMENTO'] ?? ''); [$rot, $ico, $dica] = $rotuloEtapa[$acao]; ?>
        <button type="button" class="btn btn-sm <?= $acao === 'ENCERRAR' ? 'btn-outline-danger' : 'btn-outline-primary' ?>" data-etapa="<?= $acao ?>" data-destino="<?= $e($d['ETAPA_DESTINO'] ?? '') ?>"
          <?= $imp !== '' ? 'disabled title="' . $e($imp) . '"' : 'title="' . $e($dica . ' Destino: ' . ($d['ETAPA_DESTINO'] ?? '')) . '"' ?>><i class="bi <?= $ico ?> me-1"></i><?= $e($rot) ?><?= $imp === '' ? ' → ' . $e($d['ETAPA_DESTINO'] ?? '') : '' ?></button>
      <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($historico): ?>
  <section class="card">
    <div class="card-header">Correções feitas pela administração</div>
    <div class="card-body"><?php
      $linhas = [];
      foreach ($historico as $h) $linhas[] = ['cells' => ['CRIADO_EM' => ['text' => $A::data($h['CRIADO_EM'], true), 'sort' => $A::ordenacao($h['CRIADO_EM'])], 'USUARIO' => ['text' => (string)($h['USUARIO'] ?? '')],
          'ACAO' => ['text' => $A::ACOES_HISTORICO[$h['ACAO']]], 'MOTIVO' => ['text' => (string)($h['MOTIVO'] ?? ''), 'title' => (string)($h['MOTIVO'] ?? '')]]];
      greenPortalGridLocal([
          'transaction' => 'naoconformidades_correcao_historico', 'fragment_class' => 'nc-grid-historico', 'id' => 'ncCorrHistGrid', 'page_size' => 10,
          'columns' => ['CRIADO_EM' => ['label' => 'Data', 'filter' => 'none', 'width' => '130px'], 'USUARIO' => ['label' => 'Usuário', 'filter' => 'select', 'width' => '180px'], 'ACAO' => ['label' => 'Ação', 'filter' => 'select', 'width' => '230px'], 'MOTIVO' => ['label' => 'Detalhe']],
          'cell_boxes' => true, 'rows' => $linhas, 'default_sort' => ['CRIADO_EM', 'desc'], 'buttons' => ['reload' => false], 'empty' => 'Sem correções.',
      ]);
    ?></div>
  </section>
  <?php endif; ?>
  <?php endif; ?>
</main>
<?php greenPortalGridLocalScripts(); ?>
<script src="../assets/js/nc-administracao.js?v=1"></script>
<script>
(function () {
  var cod = Number(document.body.getAttribute('data-codigo')) || 0;
  function valor(el) { return el.value == null ? '' : String(el.value); }
  function inicial(el) { return el.hasAttribute('data-inicial') ? el.getAttribute('data-inicial') : el.defaultValue; }
  function alterados() {
    var dados = {}, acoes = [];
    document.querySelectorAll('[data-campo]').forEach(function (el) {
      var ini = el.tagName === 'TEXTAREA' ? el.defaultValue : inicial(el);
      if (valor(el) === String(ini == null ? '' : ini)) return;
      var td = el.closest('[data-acao-plano]');
      if (td) acoes.push({ codplanoacao: Number(td.getAttribute('data-acao-plano')), codusuario: Number(el.value) || null });
      else dados[el.getAttribute('data-campo')] = valor(el);
    });
    return { dados: dados, acoes: acoes };
  }
  document.addEventListener('input', function (ev) { var el = ev.target.closest('[data-campo]'); if (el) { var ini = el.tagName === 'TEXTAREA' ? el.defaultValue : inicial(el); el.classList.toggle('alterado', valor(el) !== String(ini == null ? '' : ini)); } });
  document.addEventListener('change', function (ev) { var el = ev.target.closest('select[data-campo]'); if (el) el.classList.toggle('alterado', valor(el) !== String(inicial(el) || '')); });
  function motivo() { var m = document.getElementById('ncMotivo'); return m ? m.value.trim() : ''; }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-acao="corrigir"]');
    if (b) {
      var a = alterados();
      if (!Object.keys(a.dados).length && !a.acoes.length) { GPNcAdmin.aviso('Nenhum campo foi alterado.', 'warning'); return; }
      if (motivo().length < 5) { GPNcAdmin.aviso('Informe o motivo da correção.', 'warning'); document.getElementById('ncMotivo').focus(); return; }
      b.disabled = true;
      GPNcAdmin.gravar('nc_corrigir', { codigo: cod, dados: a.dados, acoes: a.acoes, motivo: motivo() })
        .then(function (r) { window.location.href = '?codigo=' + cod + '&gravado=' + encodeURIComponent(r.ALTERACOES || 1); })
        .catch(function (e) { b.disabled = false; GPNcAdmin.aviso(e.message, 'danger'); });
      return;
    }
    var et = ev.target.closest('[data-etapa]');
    if (et && !et.disabled) {
      if (motivo().length < 5) { GPNcAdmin.aviso('Informe o motivo (campo acima) antes de mudar a etapa.', 'warning'); document.getElementById('ncMotivo').focus(); return; }
      var acao = et.getAttribute('data-etapa'), destino = et.getAttribute('data-destino');
      GPNcAdmin.confirmar('Mudar etapa', et.textContent.trim() + ': a NC vai para "' + destino + '". Confirma?', function () {
        et.disabled = true;
        GPNcAdmin.gravar('nc_etapa', { codigo: cod, etapa: acao, motivo: motivo() })
          .then(function () { window.location.href = '?codigo=' + cod + '&etapa=1'; })
          .catch(function (e) { et.disabled = false; GPNcAdmin.aviso(e.message, 'danger'); });
      });
    }
  });
  var q = new URLSearchParams(window.location.search);
  if (q.get('gravado')) GPNcAdmin.aviso(q.get('gravado') + ' alteração(ões) gravada(s) e registrada(s) no histórico da NC.', 'success');
  if (q.get('etapa')) GPNcAdmin.aviso('Etapa alterada e registrada no histórico da NC.', 'success');
}());
</script>
</body></html>
