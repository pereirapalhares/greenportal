<?php
declare(strict_types=1);
// Migrações 386/387 — Legislação › Alterações na legislação (popup do grid de Manutenção Legal › Legislação), no grid padrão.
// Incluir / editar / excluir alterações (legislacaoxalteracao) e, opcionalmente, atualizar a situação, o "devido" e a data
// de atualização da própria lei — o que faz a lei entrar no RCL do mês e no filtro Mês/Ano.
require_once dirname(__DIR__) . '/classes/LegislacaoPopup.php';

[$id, $perm, $lei] = LegislacaoAssociacoes::iniciarPopup();
$L = LegislacaoAssociacoes::class;
$idioma = strtolower((string)($_SESSION['idioma'] ?? 'pt'));
$X = static fn(string $k, array $v = []): string => LegislacaoTextos::t($k, $v);

if (($_POST['ajax'] ?? '') === '1') {
    $acao = (string)($_POST['action'] ?? '');
    try {
        if ($acao === 'salvar') {
            $leiDados = ($_POST['atualizar_lei'] ?? '') === '1' ? ['situacao' => (string)($_POST['situacao'] ?? ''), 'devido' => (string)($_POST['devido'] ?? ''), 'data' => (string)($_POST['data_lei'] ?? '')] : null;
            $cod = (int)($_POST['cod'] ?? 0);
            $L::salvarAlteracao($id, $cod, (string)($_POST['data'] ?? ''), (string)($_POST['tipo'] ?? ''), (string)($_POST['descricao'] ?? ''), $leiDados);
            $msg = $X($cod ? 'alt.msg_gravada' : 'alt.msg_incluida') . ($leiDados ? ' ' . $X('alt.msg_lei_atualizada') : '');
        } elseif ($acao === 'excluir') {
            $L::excluirAlteracao($id, (int)($_POST['cod'] ?? 0)); $msg = $X('alt.msg_excluida');
        } else $L::json(['ok' => false, 'message' => $X('erro.acao_desconhecida')], 400);
        $L::json(['ok' => true, 'message' => $msg, 'resumo' => ['type' => 'legislacao-alteracoes-saved', 'id' => $id] + $L::situacaoLei($id)]);
    } catch (InvalidArgumentException | RuntimeException $e) {
        $L::json(['ok' => false, 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        error_log('[legislacao_alteracoes] ' . $e->getMessage());
        $L::json(['ok' => false, 'message' => $X('erro.nao_concluido')], 500);
    }
}

$br = static fn(string $d): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? date($idioma === 'en' ? 'm/d/Y' : 'd/m/Y', strtotime($d)) : '';
$situacao = $L::situacaoLei($id);
$linhas = [];
foreach ($L::alteracoes($id) as $a) {
    $data = substr((string)($a['data_da_alteracao'] ?? ''), 0, 10);
    $acoes = $perm['editar'] ? '<button type="button" data-linha-acao="editar" data-cod="' . (int)$a['codalteracao'] . '" data-data="' . $L::h($data) . '" data-tipo="' . $L::h($a['tipo'] ?? '') . '" data-descricao="' . $L::h($a['descricao'] ?? '') . '"></button>'
        . '<button type="button" data-linha-acao="excluir" data-cod="' . (int)$a['codalteracao'] . '"></button>' : '';
    $linhas[] = ['cells' => [
        'DATA' => ['text' => $br($data), 'html' => $L::h($br($data)) . greenPortalGridLocalLinha($acoes), 'sort' => $data],
        'TIPO' => ['text' => (string)($a['tipo'] ?? '')], // grid_local espera ['text' => ...] (texto solto saía vazio)
        'DESCRICAO' => ['text' => (string)($a['descricao'] ?? ''), 'html' => '<div class="lp-pre">' . $L::h($a['descricao'] ?? '') . '</div>'],
    ]];
}
$tipos = $L::tiposAlteracao();
$explica = $L::h($X('alt.explica_situacao')) . ' <b>' . $L::h($situacao['situacao'] !== '' ? LegislacaoTextos::valor($situacao['situacao']) : '—') . '</b>' . ($situacao['devido'] !== '' ? ' · ' . $L::h(LegislacaoTextos::valor($situacao['devido'])) : '')
    . ' · ' . $L::h($X('alt.explica_data')) . ' <b>' . $L::h($br($situacao['data']) ?: '—') . '</b>. ' . $L::h($X('alt.explica_rcl')) . ($perm['editar'] ? '' : ' <i class="bi bi-lock"></i> ' . $L::h($X('texto.somente_consulta')));

LegislacaoPopup::inicio($X('alt.titulo'), $lei, $explica);
greenPortalGridLocal([
    'transaction' => 'manutencaolegal_legislacao', 'id' => 'lpAlteracoesGrid', 'fragment_class' => 'lp-grid',
    'columns' => [
        'DATA' => ['label' => $X('alt.col_data'), 'filter' => 'text', 'width' => '110px'],
        'TIPO' => ['label' => $X('alt.col_tipo'), 'filter' => 'select', 'width' => '220px'],
        'DESCRICAO' => ['label' => $X('urls.col_descricao'), 'filter' => 'text'],
    ],
    'rows' => $linhas, 'empty' => $X('alt.vazio') . ($perm['editar'] ? ' ' . $X('alt.vazio_dica') : ''),
    'default_sort' => ['DATA', 'desc'],
    'buttons' => ['reload' => true, 'columns' => true, 'new' => $perm['editar'], 'new_attribute' => 'data-lp-novo', 'delete' => $perm['editar'], 'delete_attribute' => 'data-grid-acao-linha="excluir"'],
    'extras' => $perm['editar'] ? LegislacaoPopup::botao('bi-pencil', $X('alt.btn_editar'), 'data-grid-acao-linha="editar"', true) : '',
    'double_click_action' => $perm['editar'] ? 'editar' : '',
]);
?>
<div class="modal fade lp-modal" id="lpForm" tabindex="-1"><div class="modal-dialog modal-lg"><form class="modal-content" data-lp-form autocomplete="off">
  <div class="modal-header py-2"><h5 class="modal-title fs-6" data-lp-form-titulo><?= $L::h($X('alt.form_nova')) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $L::h($X('comum.fechar')) ?>"></button></div>
  <div class="modal-body"><input type="hidden" name="cod" value="0">
    <div class="row g-2">
      <div class="col-md-3"><label><?= $L::h($X('campo.data_alteracao')) ?></label><input type="date" class="form-control form-control-sm" name="data" required></div>
      <div class="col-md-9"><label><?= $L::h($X('alt.form_tipo')) ?></label><select class="form-select form-select-sm" name="tipo" required><option value=""><?= $L::h($X('alt.form_selecione')) ?></option><?php foreach ($tipos as $t): ?><option><?= $L::h($t) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><label><?= $L::h($X('urls.col_descricao')) ?></label><textarea class="form-control form-control-sm" name="descricao" rows="4" maxlength="5000" required></textarea></div>
      <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="lpAtualizarLei" name="atualizar_lei" value="1"><label class="form-check-label" for="lpAtualizarLei" style="font-size:12px;color:inherit"><?= $L::h($X('alt.form_atualizar_lei')) ?></label></div></div>
      <div class="col-md-4" data-lp-lei><label><?= $L::h($X('alt.form_situacao')) ?></label><select class="form-select form-select-sm" name="situacao"><?php foreach ($L::SITUACOES as $s): ?><option value="<?= $L::h($s) ?>"><?= $L::h(LegislacaoTextos::valor($s)) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-5" data-lp-lei><label><?= $L::h($X('alt.form_devido')) ?></label><select class="form-select form-select-sm" name="devido"><?php foreach ($L::DEVIDOS as $d): ?><option value="<?= $L::h($d) ?>"><?= $L::h(LegislacaoTextos::valor($d)) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3" data-lp-lei><label><?= $L::h($X('alt.form_data_atualizacao')) ?></label><input type="date" class="form-control form-control-sm" name="data_lei"></div>
      <div class="col-12 text-muted small" data-lp-lei><?= $L::h($X('alt.form_rcl')) ?></div>
    </div>
  </div>
  <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> <?= $L::h($X('comum.salvar')) ?></button><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= $L::h($X('comum.cancelar')) ?></button></div>
</form></div></div>
<?php
LegislacaoPopup::fim($id, 'alteracoes', 'window.LP_LEI = ' . json_encode($situacao, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';' . <<<'JS'
(function () {
    var X = function (k, p) { return LP.T('manutencaolegal_legislacao.js.' + k, p); };
    var $ = window.jQuery, modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('lpForm')), $f = $('[data-lp-form]'), tocada = false, dataTocada = false;
    function sugerir() { if (!tocada) $f.find('[name=situacao]').val(/revog|retir|cancel/i.test(String($f.find('[name=tipo]').val() || '')) ? 'Lei Revogada' : 'Lei Alterada'); }
    function toggle() { var on = $f.find('[name=atualizar_lei]').is(':checked'); $f.find('[data-lp-lei]').toggle(on); }
    function abrir(b) {
        tocada = false; dataTocada = false; $f[0].reset();
        var lei = window.LP_LEI || {};
        $f.find('[name=cod]').val(b ? b.getAttribute('data-cod') : 0);
        $f.find('[name=data]').val(b ? b.getAttribute('data-data') : new Date().toISOString().substring(0, 10));
        var tipo = b ? b.getAttribute('data-tipo') : '';
        if (tipo && !$f.find('[name=tipo] option').filter(function () { return this.value === tipo; }).length) $f.find('[name=tipo]').append($('<option>').text(tipo));
        $f.find('[name=tipo]').val(tipo || '');
        $f.find('[name=descricao]').val(b ? b.getAttribute('data-descricao') : '');
        $f.find('[name=atualizar_lei]').prop('checked', !b);   // nova: marcada; editar: desmarcada
        $f.find('[name=devido]').val(b ? (lei.devido || 'Devido à Atualização') : 'Devido à Atualização');
        if (b) { $f.find('[name=situacao]').val(lei.situacao || 'Lei Alterada'); tocada = true; } else sugerir();
        $f.find('[name=data_lei]').val($f.find('[name=data]').val());
        $('[data-lp-form-titulo]').text(b ? X('alt_editar', 'Editar alteração') : X('alt_nova', 'Nova alteração'));
        toggle(); modal.show();
    }
    $(document).on('click', '[data-lp-novo]', function () { abrir(null); });
    $f.on('change', '[name=tipo]', sugerir);
    $f.on('change', '[name=situacao]', function () { tocada = true; });
    $f.on('change', '[name=atualizar_lei]', toggle);
    $f.on('change', '[name=data]', function () { if (!dataTocada) $f.find('[name=data_lei]').val($(this).val()); });
    $f.on('change', '[name=data_lei]', function () { dataTocada = true; });
    $f.on('submit', function (e) {
        e.preventDefault();
        var d = { action: 'salvar' }; $f.serializeArray().forEach(function (x) { d[x.name] = x.value; });
        d.atualizar_lei = $f.find('[name=atualizar_lei]').is(':checked') ? '1' : '0';
        LP.enviar(d, $f.find('[type=submit]')).then(LP.gravado, function () {});
    });
    $(document).on('click', '[data-linha-acao]', function () {
        var a = this.getAttribute('data-linha-acao'), cod = this.getAttribute('data-cod');
        if (a === 'editar') return abrir(this);
        if (a === 'excluir') LP.confirmar(X('alt_excluir_titulo', 'Excluir alteração'), X('alt_excluir_texto', 'Excluir esta alteração da lei? A situação e a data da lei não mudam.'), function () { LP.enviar({ action: 'excluir', cod: cod }).then(LP.gravado, function () {}); });
    });
}());
JS);
