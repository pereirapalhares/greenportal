<?php
declare(strict_types=1);
// Migrações 382/387 — Legislação › URL de pesquisa (popup do grid de Manutenção Legal › Legislação), no grid padrão do portal.
require_once dirname(__DIR__) . '/classes/LegislacaoPopup.php';

[$id, $perm, $lei] = LegislacaoAssociacoes::iniciarPopup();
$L = LegislacaoAssociacoes::class;
$idioma = strtolower((string)($_SESSION['idioma'] ?? 'pt'));
$X = static fn(string $k, array $v = []): string => LegislacaoTextos::t($k, $v);

if (($_POST['ajax'] ?? '') === '1') {
    $acao = (string)($_POST['action'] ?? '');
    $cod = (int)($_POST['cod'] ?? 0);
    try {
        switch ($acao) {
            case 'incluir': $L::incluirUrl($id, (string)($_POST['url'] ?? ''), (string)($_POST['descricao'] ?? '')); $msg = $X('urls.msg_incluido'); break;
            case 'alterar': $L::alterarUrl($id, $cod, (string)($_POST['url'] ?? ''), (string)($_POST['descricao'] ?? '')); $msg = $X('urls.msg_alterado'); break;
            case 'confirmar': $L::confirmarUrl($id, $cod); $msg = $X('urls.msg_confirmada'); break;
            case 'excluir': $L::excluirUrl($id, $cod); $msg = $X('urls.msg_excluido'); break;
            case 'verificar':
                $r = $L::verificarUrls($id, $cod > 0 ? $cod : null);
                $ok = count(array_filter($r, static fn($s) => $s >= 200 && $s < 400));
                $msg = $r ? $X('urls.msg_verificados', ['ok' => $ok, 'n' => count($r)]) : $X('urls.msg_nada_verificar'); break;
            case 'sugerir_ia':
                @set_time_limit(180);
                $r = $L::sugerirUrlsIa($id, $idioma);
                $msg = $r['novas'] ? $X('urls.msg_ia_novas', ['n' => $r['novas']]) : $X('urls.msg_ia_nada');
                if ($r['repetidas']) $msg .= ' ' . $X('urls.msg_ia_repetidas', ['n' => $r['repetidas']]);
                if ($r['invalidas']) $msg .= ' ' . $X('urls.msg_ia_invalidas', ['n' => $r['invalidas']]);
                break;
            default: $L::json(['ok' => false, 'message' => $X('erro.acao_desconhecida')], 400);
        }
        $L::json(['ok' => true, 'message' => $msg, 'resumo' => $L::resumo($id)]);
    } catch (InvalidArgumentException | RuntimeException $e) {
        $L::json(['ok' => false, 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        error_log('[legislacao_urls] ' . $e->getMessage());
        $L::json(['ok' => false, 'message' => $X('erro.nao_concluido')], 500);
    }
}

$fmt = static fn($d) => $d ? date($idioma === 'en' ? 'm/d/Y H:i' : 'd/m/Y H:i', strtotime((string)$d)) : '';
$linhas = [];
foreach ($L::urls($id) as $u) {
    $cod = (int)$u['CODLEGISLACAOURL'];
    $sug = (string)$u['SITUACAO'] === 'SUGERIDA';
    $st = $u['ULTIMO_STATUS_HTTP'] === null ? null : (int)$u['ULTIMO_STATUS_HTTP'];
    [$stTxt, $stCor] = $L::statusTexto($st);
    $acoes = '<button type="button" data-linha-acao="abrir" data-url="' . $L::h($u['URL']) . '"></button>';
    if ($perm['editar']) $acoes .= '<button type="button" data-linha-acao="editar" data-cod="' . $cod . '" data-url="' . $L::h($u['URL']) . '" data-descricao="' . $L::h($u['DESCRICAO'] ?? '') . '"></button>'
        . '<button type="button" data-linha-acao="excluir" data-cod="' . $cod . '"></button><button type="button" data-linha-acao="verificar" data-cod="' . $cod . '"></button>'
        . ($sug ? '<button type="button" data-linha-acao="confirmar" data-cod="' . $cod . '"></button>' : '');
    $situacao = $sug ? $X('urls.sugerida') : $X('urls.confirmada');
    $linhas[] = ['cells' => [
        'SITUACAO' => ['text' => $situacao, 'html' => '<span class="badge text-bg-' . ($sug ? 'warning' : 'success') . '">' . $L::h($situacao) . '</span>' . ((string)$u['ORIGEM'] === 'IA' && !$sug ? ' <i class="bi bi-stars text-muted" title="' . $L::h($X('urls.sugerida_confirmada', ['usuario' => $u['USUARIO_CONFIRMACAO'] ?? ''])) . '"></i>' : '') . greenPortalGridLocalLinha($acoes)],
        'DESCRICAO' => ['text' => (string)($u['DESCRICAO'] ?? ''), 'html' => $L::h($u['DESCRICAO'] ?? '') . ((string)($u['JUSTIFICATIVA_IA'] ?? '') !== '' ? '<div class="text-muted small"><i class="bi bi-stars"></i> ' . $L::h($u['JUSTIFICATIVA_IA']) . '</div>' : '')],
        'URL' => ['text' => (string)$u['URL'], 'html' => '<a class="lp-link" href="' . $L::h($u['URL']) . '" target="_blank" rel="noopener noreferrer">' . $L::h($u['URL']) . '</a>'],
        'VERIFICACAO' => ['text' => $stTxt, 'html' => '<span class="badge text-bg-' . $stCor . '">' . $L::h($stTxt) . '</span>' . ($u['ULTIMA_VERIFICACAO'] ? '<div class="text-muted small">' . $L::h($fmt($u['ULTIMA_VERIFICACAO'])) . '</div>' : ''), 'sort' => (string)($u['ULTIMA_VERIFICACAO'] ?? '')],
        'INCLUIDO' => ['text' => trim((string)($u['USUARIO_INCLUSAO'] ?? '') . ' · ' . (string)($u['EMPRESA_INCLUSAO'] ?? '') . ' · ' . $fmt($u['DATA_INCLUSAO'] ?? null), ' ·'), 'sort' => (string)($u['DATA_INCLUSAO'] ?? '')],
    ]];
}
$extras = LegislacaoPopup::botao('bi-box-arrow-up-right', $X('urls.btn_abrir'), 'data-grid-acao-linha="abrir"', true, 'outline-secondary');
if ($perm['editar']) {
    $extras .= LegislacaoPopup::botao('bi-pencil', $X('urls.btn_editar'), 'data-grid-acao-linha="editar"', true)
        . LegislacaoPopup::botao('bi-check2-circle', $X('urls.btn_confirmar'), 'data-grid-acao-linha="confirmar"', true, 'outline-success')
        . LegislacaoPopup::botao('bi-arrow-repeat', $X('urls.btn_verificar'), 'data-grid-acao-linha="verificar"', true)
        . LegislacaoPopup::botao('bi-check2-all', $X('urls.btn_verificar_todos'), 'data-lp-verificar-todos')
        . ($L::iaDisponivel() ? LegislacaoPopup::botao('bi-stars', $X('urls.btn_ia'), 'data-lp-ia') : '');
}

LegislacaoPopup::inicio($X('urls.titulo'), $lei, $L::h($X('urls.explica')) . ($perm['editar'] ? '' : ' <i class="bi bi-lock"></i> ' . $L::h($X('texto.somente_consulta'))));
greenPortalGridLocal([
    'transaction' => 'manutencaolegal_legislacao', 'id' => 'lpUrlsGrid', 'fragment_class' => 'lp-grid',
    'columns' => [
        'SITUACAO' => ['label' => $X('urls.col_situacao'), 'filter' => 'select', 'width' => '150px'],
        'DESCRICAO' => ['label' => $X('urls.col_descricao'), 'filter' => 'text', 'width' => '26%'],
        'URL' => ['label' => $X('urls.col_link'), 'filter' => 'text'],
        'VERIFICACAO' => ['label' => $X('urls.col_verificacao'), 'filter' => 'select', 'width' => '150px'],
        'INCLUIDO' => ['label' => $X('urls.col_incluido'), 'filter' => 'text', 'width' => '200px'],
    ],
    'rows' => $linhas, 'empty' => $X('urls.vazio') . ($perm['editar'] ? ' ' . $X($L::iaDisponivel() ? 'urls.vazio_dica_ia' : 'urls.vazio_dica') : ''),
    'default_sort' => ['SITUACAO', 'desc'],
    'buttons' => ['reload' => true, 'columns' => true, 'new' => $perm['editar'], 'new_attribute' => 'data-lp-novo', 'delete' => $perm['editar'], 'delete_attribute' => 'data-grid-acao-linha="excluir"'],
    'extras' => $extras, 'double_click_action' => $perm['editar'] ? 'editar' : 'abrir',
]);
?>
<div class="modal fade lp-modal" id="lpForm" tabindex="-1"><div class="modal-dialog"><form class="modal-content" data-lp-form autocomplete="off">
  <div class="modal-header py-2"><h5 class="modal-title fs-6" data-lp-form-titulo><?= $L::h($X('urls.form_novo')) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $L::h($X('comum.fechar')) ?>"></button></div>
  <div class="modal-body"><input type="hidden" name="cod" value="0">
    <label><?= $L::h($X('urls.form_endereco')) ?></label><input type="text" class="form-control form-control-sm mb-2" name="url" maxlength="1000" required>
    <label><?= $L::h($X('urls.col_descricao')) ?></label><input type="text" class="form-control form-control-sm" name="descricao" maxlength="300" placeholder="<?= $L::h($X('urls.form_descricao_ex')) ?>"></div>
  <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> <?= $L::h($X('comum.salvar')) ?></button><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= $L::h($X('comum.cancelar')) ?></button></div>
</form></div></div>
<?php
LegislacaoPopup::fim($id, 'urls', <<<'JS'
(function () {
    var X = function (k, p) { return LP.T('manutencaolegal_legislacao.js.' + k, p); };
    var $ = window.jQuery, modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('lpForm')), $f = $('[data-lp-form]');
    function abrirForm(b) {
        $f[0].reset(); $f.find('[name=cod]').val(b ? b.getAttribute('data-cod') : 0);
        $f.find('[name=url]').val(b ? b.getAttribute('data-url') : ''); $f.find('[name=descricao]').val(b ? b.getAttribute('data-descricao') : '');
        $('[data-lp-form-titulo]').text(b ? X('urls_editar', 'Editar link') : X('urls_novo', 'Novo link')); modal.show(); setTimeout(function () { $f.find('[name=url]').trigger('focus'); }, 300);
    }
    $(document).on('click', '[data-lp-novo]', function () { abrirForm(null); });
    $f.on('submit', function (e) {
        e.preventDefault(); var cod = Number($f.find('[name=cod]').val() || 0);
        LP.enviar({ action: cod ? 'alterar' : 'incluir', cod: cod, url: $f.find('[name=url]').val(), descricao: $f.find('[name=descricao]').val() }, $f.find('[type=submit]')).then(LP.gravado, function () {});
    });
    $(document).on('click', '[data-linha-acao]', function (e) {
        var b = this, a = b.getAttribute('data-linha-acao'), cod = b.getAttribute('data-cod');
        if (a === 'abrir') { window.open(b.getAttribute('data-url'), '_blank', 'noopener'); return; }
        if (a === 'editar') return abrirForm(b);
        if (a === 'excluir') return LP.confirmar(X('urls_excluir_titulo', 'Excluir link'), X('urls_excluir_texto', 'Excluir este link da lei? A lista vale para todas as empresas.'), function () { LP.enviar({ action: 'excluir', cod: cod }).then(LP.gravado, function () {}); });
        if (a === 'confirmar') { LP.enviar({ action: 'confirmar', cod: cod }).then(LP.gravado, function () {}); return; }
        if (a === 'verificar') { LP.aviso(X('verificando', 'Verificando...'), 'info'); LP.enviar({ action: 'verificar', cod: cod }).then(LP.gravado, function () {}); }
    });
    $(document).on('click', '[data-lp-verificar-todos]', function () { LP.aviso(X('verificando_links', 'Verificando os links...'), 'info'); LP.enviar({ action: 'verificar' }, $(this)).then(LP.gravado, function () {}); });
    $(document).on('click', '[data-lp-ia]', function () { LP.aviso(X('urls_ia_aguarde', 'Consultando a IA e testando os links sugeridos (pode levar até 1 minuto)...'), 'info'); LP.enviar({ action: 'sugerir_ia' }, $(this)).then(LP.gravado, function () {}); });
}());
JS);
