<?php
declare(strict_types=1);

/*
 * Lista de distribuição de NC (popup aberto por Não Conformidades › Administração › Listas de distribuição; migração 333).
 * Mostra nome, categorias, uso nas NCs, as pessoas (usuários do portal e participantes externos, com a situação de cada uma) e
 * o histórico das alterações. Nas listas do greenportal (código a partir de 50001): alterar nome e categorias, incluir e retirar
 * pessoas, excluir (se nenhuma NC usa). Nas do sistema anterior: só consulta e "Copiar como nova lista" (até a virada o legado
 * prevalece e o sincronismo desfaria a alteração).
 * Leitura por views (vw_nc_listas, vw_nc_lista_membros, vw_nc_categorias, vw_nc_lista_categorias, vw_nc_pessoas, vw_nc_admin_historico);
 * gravação por api/nc_administracao.php (procedures sp_nc_lista_*).
 */

require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeAdministracao.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/components/pagina_padrao.php';

$pdo = TransactionSecurity::connect(NaoConformidadeAdministracao::MENU_LISTAS);
$perm = PermissaoTransacao::obter(NaoConformidadeAdministracao::MENU_LISTAS);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$A = NaoConformidadeAdministracao::class;
$codigo = max(0, (int)($_GET['codigo'] ?? 0));
$erro = '';
$lista = null; $membros = []; $categorias = []; $marcadas = []; $pessoas = []; $historico = [];
try {
    $categorias = $A::ler($pdo, 'vw_nc_categorias');
    usort($categorias, static fn($a, $b) => strcasecmp((string)$a['DSCCATEGORIA'], (string)$b['DSCCATEGORIA']));
    if ($codigo > 0) {
        $lista = $A::ler($pdo, 'vw_nc_listas', ['CODLISTA' => $A::exato($codigo)])[0] ?? null;
        if (!$lista) { http_response_code(404); $erro = 'Lista de distribuição ' . $codigo . ' não encontrada.'; }
        else {
            $membros = $A::ler($pdo, 'vw_nc_lista_membros', ['CODLISTA' => $A::exato($codigo)]);
            usort($membros, static fn($a, $b) => strcasecmp((string)$a['NOME'], (string)$b['NOME']));
            $marcadas = array_map('intval', array_column($A::ler($pdo, 'vw_nc_lista_categorias', ['CODLISTA' => $A::exato($codigo)]), 'CODCATEGORIA'));
            $historico = $A::ler($pdo, 'vw_nc_admin_historico', ['OBJETO' => $A::exato('LISTA'), 'CODIGO' => $A::exato($codigo)], 2000);
            usort($historico, static fn($a, $b) => (int)$b['CODHISTORICO'] <=> (int)$a['CODHISTORICO']);
        }
    }
} catch (Throwable $ex) {
    $erro = 'Listas não disponíveis neste banco: aplique a migração 333.';
    error_log('[nc lista] ' . $ex->getMessage());
}
$legado = $codigo > 0 && $codigo < $A::FAIXA_LISTA;
$editavel = !$legado && $erro === '' && ($codigo > 0 ? !empty($perm['pode_editar']) : !empty($perm['pode_incluir']));
if ($editavel && $codigo > 0) {
    // pessoas que podem ser incluídas (ativas e ainda fora da lista)
    $naLista = array_flip(array_map('intval', array_column($membros, 'CODUSUARIO')));
    foreach ($A::ler($pdo, 'vw_nc_pessoas', ['ATIVO' => $A::exato(1)]) as $p) {
        if (isset($naLista[(int)$p['CODUSUARIO']])) continue;
        $pessoas[] = ['c' => (int)$p['CODUSUARIO'], 'n' => (string)$p['NOME'], 'e' => (string)$p['EMAIL'], 't' => (string)$p['TIPO']];
    }
    usort($pessoas, static fn($a, $b) => strcasecmp($a['n'], $b['n']));
}
$tipo = static fn(string $t): string => ['USUARIO' => 'Usuário do portal', 'PARTICIPANTE' => 'Participante externo'][$t] ?? 'Não cadastrado';
$acaoTexto = ['LISTA_INCLUIDA' => 'Lista criada', 'LISTA_ALTERADA' => 'Nome/categorias alterados', 'LISTA_MEMBROS' => 'Pessoas incluídas/retiradas', 'LISTA_COPIADA' => 'Criada como cópia', 'LISTA_EXCLUIDA' => 'Lista excluída'];
$nomesPessoa = [];
foreach ($membros as $m) $nomesPessoa[(int)$m['CODUSUARIO']] = (string)$m['NOME'];
$detalhe = static function (array $h) use ($nomesPessoa): string {
    $d = json_decode((string)($h['DEPOIS'] ?? ''), true) ?: [];
    $a = json_decode((string)($h['ANTES'] ?? ''), true) ?: [];
    $nome = static fn($c) => $nomesPessoa[(int)$c] ?? ('código ' . (int)$c);
    foreach (['incluidos', 'retirados'] as $k) if (isset($d[$k]) && is_string($d[$k])) $d[$k] = json_decode($d[$k], true) ?: [];
    if ($h['ACAO'] === 'LISTA_MEMBROS') {
        $p = [];
        if (!empty($d['incluidos'])) $p[] = 'incluídos: ' . implode(', ', array_map($nome, (array)$d['incluidos']));
        if (!empty($d['retirados'])) $p[] = 'retirados: ' . implode(', ', array_map(static fn($c) => 'código ' . (int)$c, (array)$d['retirados']));
        return implode('; ', $p);
    }
    if ($h['ACAO'] === 'LISTA_ALTERADA' && ($a['nome'] ?? null) !== ($d['nome'] ?? null)) return 'nome: "' . ($a['nome'] ?? '') . '" → "' . ($d['nome'] ?? '') . '"';
    if ($h['ACAO'] === 'LISTA_COPIADA') return 'cópia da lista ' . (int)($d['copiada_de'] ?? 0);
    return '';
};
$titulo = $codigo > 0 ? 'Lista de distribuição ' . $codigo : 'Nova lista de distribuição';
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($titulo) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php require_once dirname(__DIR__) . '/components/grid_local.php'; greenPortalGridLocalHead(); /* c537: grids no padrão do portal */ ?>
<style>
.nc-lista .card{margin-bottom:12px}.nc-lista .card-header{font-weight:600;background:#f6faf8}
.nc-lista table{font-size:11px}.nc-lista td,.nc-lista th{vertical-align:middle}
.nc-lista .nc-cats{display:flex;flex-wrap:wrap;gap:6px 18px}
.nc-lista .nc-busca-resultados{max-height:220px;overflow:auto;border:1px solid #dce9e2;border-radius:8px}
.nc-lista .nc-busca-resultados button{display:block;width:100%;text-align:left;border:0;border-bottom:1px solid #eef4f1;background:#fff;padding:5px 8px;font-size:11px}
.nc-lista .nc-busca-resultados button:hover,.nc-lista .nc-busca-resultados button.ativo{background:#eef8f3}
</style>
</head>
<body class="nc-lista" data-csrf="<?= $e($A::csrf()) ?>" data-api="api/nc_administracao.php" data-codigo="<?= $codigo ?>">
<main class="container-fluid py-3">
<div data-nc-aviso hidden></div>
<?php if ($erro !== ''): ?>
  <div class="alert alert-danger"><?= $e($erro) ?></div>
<?php else: ?>
  <div class="d-flex align-items-center gap-2 mb-2">
    <h2 class="h5 mb-0"><i class="bi bi-people me-1"></i><?= $e($titulo) ?></h2>
    <?php if ($codigo > 0): ?><span class="badge <?= $legado ? 'text-bg-secondary' : 'text-bg-success' ?>"><?= $legado ? 'Sistema anterior' : 'Greenportal' ?></span><?php endif; ?>
  </div>
  <?php if ($legado): ?>
    <div class="alert alert-info py-2">Lista do sistema anterior: até a virada só consulta aqui (alterações são feitas lá e chegam pelo sincronismo). Para uma lista própria do greenportal, use <b>Copiar como nova lista</b>.</div>
  <?php endif; ?>

  <section class="card">
    <div class="card-header">Dados da lista</div>
    <div class="card-body">
      <div class="row g-2 align-items-end">
        <div class="col-md-6"><label class="form-label mb-1" for="ncNome">Nome</label>
          <input class="form-control form-control-sm" id="ncNome" maxlength="500" value="<?= $e($lista['DSCLISTA'] ?? '') ?>" <?= $editavel ? '' : 'disabled' ?>></div>
        <div class="col-md-6"><span class="form-label d-block mb-1">Categorias</span>
          <div class="nc-cats"><?php foreach ($categorias as $c): $cc = (int)$c['CODCATEGORIA']; ?>
            <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" name="ncCat" value="<?= $cc ?>" <?= in_array($cc, $marcadas, true) ? 'checked' : '' ?> <?= $editavel ? '' : 'disabled' ?>><?= $e($c['DSCCATEGORIA']) ?></label>
          <?php endforeach; ?></div></div>
      </div>
      <div class="d-flex flex-wrap gap-2 mt-3">
        <?php if ($editavel): ?><button type="button" class="btn btn-sm btn-success" data-acao="salvar"><i class="bi bi-check2 me-1"></i>Salvar</button><?php endif; ?>
        <?php if ($codigo > 0 && !empty($perm['pode_incluir'])): ?><button type="button" class="btn btn-sm btn-outline-primary" data-acao="copiar"><i class="bi bi-copy me-1"></i>Copiar como nova lista</button><?php endif; ?>
        <?php if ($codigo > 0 && !$legado && !empty($perm['pode_excluir'])): ?>
          <button type="button" class="btn btn-sm btn-outline-danger" data-acao="excluir" <?= (int)$lista['QTD_NCS'] > 0 ? 'disabled title="Lista usada em NCs: não pode ser excluída"' : '' ?>><i class="bi bi-trash me-1"></i>Excluir</button>
        <?php endif; ?>
      </div>
      <div class="row g-2 mt-2" data-copiar hidden>
        <div class="col-md-6"><label class="form-label mb-1" for="ncNomeCopia">Nome da nova lista</label>
          <input class="form-control form-control-sm" id="ncNomeCopia" maxlength="500" value="<?= $e(($lista['DSCLISTA'] ?? '') . ' (greenportal)') ?>"></div>
        <div class="col-md-6 d-flex align-items-end gap-2"><button type="button" class="btn btn-sm btn-primary" data-acao="copiar-confirmar">Criar cópia</button>
          <span class="text-muted small">Leva as categorias e as pessoas cadastradas.</span></div>
      </div>
    </div>
  </section>

  <?php if ($codigo > 0): ?>
  <section class="card">
    <div class="card-header">Uso</div>
    <div class="card-body py-2">Usada em <b><?= (int)$lista['QTD_NCS'] ?></b> não conformidade(s), <?= (int)$lista['QTD_ABERTAS'] ?> em aberto<?= ($u = $A::data($lista['ULTIMO_USO'] ?? '')) !== '' ? '; último uso em ' . $e($u) : '' ?>.
      Quem está na lista recebe o aviso de criação da NC: usuários do portal em Home › Alertas; participantes externos por e-mail.</div>
  </section>

  <section class="card">
    <div class="card-header d-flex align-items-center">Pessoas (<?= count($membros) ?>)
      <?php $inat = count(array_filter($membros, static fn($m) => (int)$m['ATIVO'] === 0)); if ($inat): ?><span class="badge text-bg-warning ms-2"><?= $inat ?> não recebem avisos (desativadas ou não cadastradas)</span><?php endif; ?></div>
    <div class="card-body">
      <?php if ($editavel): ?>
      <div class="row g-2 mb-2">
        <div class="col-md-6"><label class="form-label mb-1" for="ncBusca">Incluir pessoa</label>
          <input class="form-control form-control-sm" id="ncBusca" autocomplete="off" placeholder="Digite nome ou e-mail (usuários do portal e participantes externos ativos)">
          <div class="nc-busca-resultados mt-1" data-resultados hidden></div></div>
      </div>
      <?php endif; ?>
      <?php
      // c537: pessoas no grid padrão do portal; "Retirar da lista" na barra, para a linha selecionada
      $linhas = [];
      foreach ($membros as $m) {
          $ativo = (int)$m['ATIVO'] === 1; $sit = $ativo ? 'Ativo' : ((string)$m['TIPO'] === 'NAO_CADASTRADO' ? 'Não cadastrado' : 'Desativado');
          $linhas[] = ['cells' => [
              'NOME' => ['text' => (string)$m['NOME'], 'title' => (string)$m['NOME'], 'html' => $e($m['NOME']) . ($editavel ? greenPortalGridLocalLinha('<button type="button" data-linha-acao="retirar" data-retirar="' . (int)$m['CODUSUARIO'] . '"></button>') : '')],
              'EMAIL' => ['text' => (string)$m['EMAIL'], 'title' => (string)$m['EMAIL']],
              'TIPO' => ['text' => $tipo((string)$m['TIPO'])],
              'SITUACAO' => ['text' => $sit, 'html' => $ativo ? 'Ativo' : '<span class="badge text-bg-warning">' . $e($sit) . '</span>'],
          ]];
      }
      greenPortalGridLocal([
          'transaction' => 'naoconformidades_listadistribuicao_pessoas', 'fragment_class' => 'nc-grid-pessoas', 'id' => 'ncPessoasGrid', 'page_size' => 15,
          'columns' => ['NOME' => ['label' => 'Nome'], 'EMAIL' => ['label' => 'E-mail'], 'TIPO' => ['label' => 'Tipo', 'filter' => 'select', 'width' => '170px'], 'SITUACAO' => ['label' => 'Situação', 'filter' => 'select', 'width' => '140px']],
          'cell_boxes' => true, 'rows' => $linhas, 'default_sort' => ['NOME', 'asc'], 'empty' => 'Nenhuma pessoa nesta lista.',
          'buttons' => ['reload' => false, 'new' => $editavel, 'new_attribute' => 'data-focar-busca', 'delete' => $editavel, 'delete_attribute' => 'data-grid-acao-linha="retirar"'],
      ]);
      ?>
    </div>
  </section>

  <?php if ($historico): ?>
  <section class="card">
    <div class="card-header">Histórico de alterações no greenportal</div>
    <div class="card-body"><?php
      $linhas = [];
      foreach ($historico as $h) $linhas[] = ['cells' => ['CRIADO_EM' => ['text' => $A::data($h['CRIADO_EM'], true), 'sort' => $A::ordenacao($h['CRIADO_EM'])], 'USUARIO' => ['text' => (string)$h['NOME_USUARIO']],
          'ACAO' => ['text' => $acaoTexto[$h['ACAO']] ?? (string)$h['ACAO']], 'DETALHE' => ['text' => $detalhe($h), 'title' => $detalhe($h)]]];
      greenPortalGridLocal([
          'transaction' => 'naoconformidades_listadistribuicao_historico', 'fragment_class' => 'nc-grid-historico', 'id' => 'ncListaHistGrid', 'page_size' => 10,
          'columns' => ['CRIADO_EM' => ['label' => 'Data', 'filter' => 'none', 'width' => '130px'], 'USUARIO' => ['label' => 'Usuário', 'filter' => 'select', 'width' => '200px'], 'ACAO' => ['label' => 'Alteração', 'filter' => 'select', 'width' => '220px'], 'DETALHE' => ['label' => 'Detalhe']],
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
  var pessoas = <?= json_encode($pessoas, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var tipos = { USUARIO: 'Usuário do portal', PARTICIPANTE: 'Participante externo' };
  function ocupado(b, sim) { if (b) b.disabled = !!sim; }
  function falhou(err) { GPNcAdmin.aviso(err.message || String(err), 'danger'); }
  function categorias() { return Array.prototype.map.call(document.querySelectorAll('input[name="ncCat"]:checked'), function (c) { return Number(c.value); }); }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-acao]');
    if (b) {
      var acao = b.getAttribute('data-acao');
      if (acao === 'salvar') {
        ocupado(b, true);
        GPNcAdmin.gravar('lista_salvar', { codigo: cod, nome: document.getElementById('ncNome').value, categorias: categorias() })
          .then(function (r) { window.location.href = 'naoconformidades_listadistribuicao.php?codigo=' + encodeURIComponent(r.CODLISTA || cod) + '&gravado=1'; })
          .catch(function (e) { ocupado(b, false); falhou(e); });
      } else if (acao === 'copiar') {
        var area = document.querySelector('[data-copiar]'); area.hidden = !area.hidden; if (!area.hidden) document.getElementById('ncNomeCopia').focus();
      } else if (acao === 'copiar-confirmar') {
        ocupado(b, true);
        GPNcAdmin.gravar('lista_copiar', { origem: cod, nome: document.getElementById('ncNomeCopia').value })
          .then(function (r) { window.location.href = 'naoconformidades_listadistribuicao.php?codigo=' + encodeURIComponent(r.CODLISTA) + '&gravado=1'; })
          .catch(function (e) { ocupado(b, false); falhou(e); });
      } else if (acao === 'excluir') {
        GPNcAdmin.confirmar('Excluir lista', 'Excluir esta lista de distribuição? As pessoas e categorias dela também saem (fica registrado no histórico).', function () {
          ocupado(b, true);
          GPNcAdmin.gravar('lista_excluir', { codigo: cod }).then(function () { GPNcAdmin.fechar(); }).catch(function (e) { ocupado(b, false); falhou(e); });
        });
      }
      return;
    }
    var r = ev.target.closest('[data-retirar]');
    if (r) {
      ocupado(r, true);
      GPNcAdmin.gravar('lista_membros', { codigo: cod, retirar: [Number(r.getAttribute('data-retirar'))] })
        .then(function () { window.location.reload(); }).catch(function (e) { ocupado(r, false); falhou(e); });
      return;
    }
    var inc = ev.target.closest('[data-incluir]');
    if (inc) {
      ocupado(inc, true);
      GPNcAdmin.gravar('lista_membros', { codigo: cod, incluir: [Number(inc.getAttribute('data-incluir'))] })
        .then(function () { window.location.reload(); }).catch(function (e) { ocupado(inc, false); falhou(e); });
    }
  });
  var busca = document.getElementById('ncBusca'), res = document.querySelector('[data-resultados]');
  document.addEventListener('click', function (ev) { if (ev.target.closest('[data-focar-busca]') && busca) { busca.focus(); busca.scrollIntoView({ block: 'center' }); } });
  function normal(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
  if (busca && res) busca.addEventListener('input', function () {
    var q = normal(busca.value.trim());
    res.innerHTML = '';
    if (q.length < 2) { res.hidden = true; return; }
    var achados = pessoas.filter(function (p) { return normal(p.n).indexOf(q) >= 0 || normal(p.e).indexOf(q) >= 0; }).slice(0, 30);
    achados.forEach(function (p) {
      var bt = document.createElement('button'); bt.type = 'button'; bt.setAttribute('data-incluir', p.c);
      bt.textContent = p.n + (p.e ? ' — ' + p.e : '') + ' (' + (tipos[p.t] || p.t) + ')';
      res.appendChild(bt);
    });
    if (!achados.length) { var s = document.createElement('div'); s.className = 'text-muted small p-2'; s.textContent = 'Ninguém encontrado (só pessoas ativas que ainda não estão na lista).'; res.appendChild(s); }
    res.hidden = false;
  });
  if (/[?&]gravado=1/.test(window.location.search)) GPNcAdmin.aviso('Gravado.', 'success');
}());
</script>
</body></html>
