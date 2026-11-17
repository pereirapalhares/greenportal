<?php
declare(strict_types=1);
// Migrações 386/387 — Legislação › Alterações na legislação (popup do grid de Manutenção Legal › Legislação), no grid padrão.
// Incluir / editar / excluir alterações (legislacaoxalteracao) e, opcionalmente, atualizar a situação, o "devido" e a data
// de atualização da própria lei — o que faz a lei entrar no RCL do mês e no filtro Mês/Ano.
require_once dirname(__DIR__) . '/classes/LegislacaoPopup.php';

[$id, $perm, $lei] = LegislacaoAssociacoes::iniciarPopup();
$L = LegislacaoAssociacoes::class;
$idioma = strtolower((string)($_SESSION['idioma'] ?? 'pt'));

if (($_POST['ajax'] ?? '') === '1') {
    $acao = (string)($_POST['action'] ?? '');
    try {
        if ($acao === 'salvar') {
            $leiDados = ($_POST['atualizar_lei'] ?? '') === '1' ? ['situacao' => (string)($_POST['situacao'] ?? ''), 'devido' => (string)($_POST['devido'] ?? ''), 'data' => (string)($_POST['data_lei'] ?? '')] : null;
            $cod = (int)($_POST['cod'] ?? 0);
            $L::salvarAlteracao($id, $cod, (string)($_POST['data'] ?? ''), (string)($_POST['tipo'] ?? ''), (string)($_POST['descricao'] ?? ''), $leiDados);
            $msg = ($cod ? 'Alteração gravada.' : 'Alteração incluída.') . ($leiDados ? ' Situação e data da lei atualizadas.' : '');
        } elseif ($acao === 'excluir') {
            $L::excluirAlteracao($id, (int)($_POST['cod'] ?? 0)); $msg = 'Alteração excluída.';
        } else $L::json(['ok' => false, 'message' => 'Ação desconhecida.'], 400);
        $L::json(['ok' => true, 'message' => $msg, 'resumo' => ['type' => 'legislacao-alteracoes-saved', 'id' => $id] + $L::situacaoLei($id)]);
    } catch (InvalidArgumentException | RuntimeException $e) {
        $L::json(['ok' => false, 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        error_log('[legislacao_alteracoes] ' . $e->getMessage());
        $L::json(['ok' => false, 'message' => 'Não foi possível concluir a operação.'], 500);
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
        'TIPO' => (string)($a['tipo'] ?? ''),
        'DESCRICAO' => ['text' => (string)($a['descricao'] ?? ''), 'html' => '<div class="lp-pre">' . $L::h($a['descricao'] ?? '') . '</div>'],
    ]];
}
$tipos = $L::tiposAlteracao();
$explica = 'Situação atual da lei: <b>' . $L::h($situacao['situacao'] ?: '—') . '</b>' . ($situacao['devido'] !== '' ? ' · ' . $L::h($situacao['devido']) : '')
    . ' · Data de atualização: <b>' . $L::h($br($situacao['data']) ?: '—') . '</b>. A descrição sai no RCL em "Alterações" ou "Revogado por".' . ($perm['editar'] ? '' : ' <i class="bi bi-lock"></i> Somente consulta.');

LegislacaoPopup::inicio('Alterações na legislação', $lei, $explica);
greenPortalGridLocal([
    'transaction' => 'manutencaolegal_legislacao', 'id' => 'lpAlteracoesGrid', 'fragment_class' => 'lp-grid',
    'columns' => [
        'DATA' => ['label' => 'Data', 'filter' => 'text', 'width' => '110px'],
        'TIPO' => ['label' => 'Tipo', 'filter' => 'select', 'width' => '220px'],
        'DESCRICAO' => ['label' => 'Descrição', 'filter' => 'text'],
    ],
    'rows' => $linhas, 'empty' => 'Nenhuma alteração registrada.' . ($perm['editar'] ? ' Use Novo para incluir.' : ''),
    'default_sort' => ['DATA', 'desc'],
    'buttons' => ['reload' => true, 'columns' => true, 'new' => $perm['editar'], 'new_attribute' => 'data-lp-novo', 'delete' => $perm['editar'], 'delete_attribute' => 'data-grid-acao-linha="excluir"'],
    'extras' => $perm['editar'] ? LegislacaoPopup::botao('bi-pencil', 'Editar a alteração', 'data-grid-acao-linha="editar"', true) : '',
    'double_click_action' => $perm['editar'] ? 'editar' : '',
]);
?>
<div class="modal fade lp-modal" id="lpForm" tabindex="-1"><div class="modal-dialog modal-lg"><form class="modal-content" data-lp-form autocomplete="off">
  <div class="modal-header py-2"><h5 class="modal-title fs-6" data-lp-form-titulo>Nova alteração</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
  <div class="modal-body"><input type="hidden" name="cod" value="0">
    <div class="row g-2">
      <div class="col-md-3"><label>Data da alteração</label><input type="date" class="form-control form-control-sm" name="data" required></div>
      <div class="col-md-9"><label>Tipo de alteração</label><select class="form-select form-select-sm" name="tipo" required><option value="">Selecione</option><?php foreach ($tipos as $t): ?><option><?= $L::h($t) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><label>Descrição</label><textarea class="form-control form-control-sm" name="descricao" rows="4" maxlength="5000" required></textarea></div>
      <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="lpAtualizarLei" name="atualizar_lei" value="1"><label class="form-check-label" for="lpAtualizarLei" style="font-size:12px;color:inherit">Atualizar também a situação e a data da lei</label></div></div>
      <div class="col-md-4" data-lp-lei><label>Situação da lei</label><select class="form-select form-select-sm" name="situacao"><?php foreach ($L::SITUACOES as $s): ?><option><?= $L::h($s) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-5" data-lp-lei><label>Devido</label><select class="form-select form-select-sm" name="devido"><?php foreach ($L::DEVIDOS as $d): ?><option><?= $L::h($d) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3" data-lp-lei><label>Data de atualização</label><input type="date" class="form-control form-control-sm" name="data_lei"></div>
      <div class="col-12 text-muted small" data-lp-lei>A lei entra no RCL do mês da data de atualização.</div>
    </div>
  </div>
  <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> Salvar</button><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button></div>
</form></div></div>
<?php
LegislacaoPopup::fim($id, 'alteracoes', 'window.LP_LEI = ' . json_encode($situacao, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';' . <<<'JS'
(function () {
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
        $('[data-lp-form-titulo]').text(b ? 'Editar alteração' : 'Nova alteração');
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
        if (a === 'excluir') LP.confirmar('Excluir alteração', 'Excluir esta alteração da lei? A situação e a data da lei não mudam.', function () { LP.enviar({ action: 'excluir', cod: cod }).then(LP.gravado, function () {}); });
    });
}());
JS);
