<?php
declare(strict_types=1);
// Migrações 386/387/391 — Legislação › CheckList (?tipo=checklist) e Palavras-chave (?tipo=palavras), em LISTA DUPLA como
// Legislações filhas (391): à esquerda o catálogo único, à direita os itens da lei; › vincula, ‹ tira o vínculo (o item continua
// no catálogo). Catálogos: perguntas no glegislacao_dbp; palavras_chaves no guniversal_dbp (o mesmo das licenças).
// Editar o texto muda o catálogo e vale para todas as leis que usam o item.
require_once dirname(__DIR__) . '/classes/LegislacaoPopup.php';
require_once dirname(__DIR__) . '/components/lista_dupla.php';

[$id, $perm, $lei] = LegislacaoAssociacoes::iniciarPopup();
$L = LegislacaoAssociacoes::class;
$tipo = (($_GET['tipo'] ?? $_POST['tipo'] ?? '') === 'palavras') ? 'palavras' : 'checklist';
$P = $tipo === 'palavras';
$idioma = strtolower((string)($_SESSION['idioma'] ?? 'pt'));
$X = static fn(string $k, array $v = []): string => LegislacaoTextos::t($k, $v);
$K = static fn(string $k, array $v = []): string => LegislacaoTextos::t(($P ? 'pal.' : 'chk.') . $k, $v); // texto do tipo (pal./chk.)
$fn = $P
    ? ['daLei' => [$L, 'palavrasDaLei'], 'pagina' => [$L, 'paginaPalavras'], 'vincular' => [$L, 'vincularPalavra'], 'desvincular' => [$L, 'desvincularPalavra'], 'nova' => [$L, 'novaPalavra'], 'editar' => [$L, 'editarPalavra'], 'info' => [$L, 'infoPalavra']]
    : ['daLei' => [$L, 'perguntasDaLei'], 'pagina' => [$L, 'paginaPerguntas'], 'vincular' => [$L, 'vincularPergunta'], 'desvincular' => [$L, 'desvincularPergunta'], 'nova' => [$L, 'novaPergunta'], 'editar' => [$L, 'editarPergunta'], 'info' => [$L, 'infoPergunta']];

if (($_POST['ajax'] ?? '') === '1') {
    $acao = (string)($_POST['action'] ?? '');
    $cod = (int)($_POST['cod'] ?? 0);
    $texto = (string)($_POST['texto'] ?? '');
    try {
        if ($acao === 'ld_listar') {
            $lado = (string)($_POST['lado'] ?? '');
            $busca = trim((string)($_POST['busca'] ?? ''));
            $pagina = max(1, (int)($_POST['pagina'] ?? 1));
            $daLei = [];
            foreach (($fn['daLei'])($id) as $i) $daLei[$i['cod']] = $i;
            $filtros = (array)json_decode((string)($_POST['filtros'] ?? '{}'), true);
            if ($lado === 'esquerda' && $P && ($filtros['mostrar'] ?? 'sugeridas') !== 'todas') {
                // Sugeridas (migração 391): palavras de leis do mesmo tipo e âmbito, do mesmo assunto e da família
                [$rows, $total] = $L::sugestoesPalavras($id, $busca, $pagina);
                $itens = array_map(static fn(array $p): array => ['id' => $p['cod'], 'cells' => ['TEXTO' => $p['texto'], 'CODIGO' => (string)$p['cod']], 'detalhe' => $p['motivo']], $rows);
                $L::json(['ok' => true, 'itens' => $itens, 'total' => $total, 'por_pagina' => 25]);
            }
            if ($lado === 'esquerda') {
                [$rows, $total] = ($fn['pagina'])($busca, $pagina);
                $itens = array_map(static fn(array $p): array => ['id' => $p['cod'], 'cells' => ['TEXTO' => $p['texto'], 'CODIGO' => (string)$p['cod']],
                    'bloqueado' => isset($daLei[$p['cod']]) ? $X('ld.bloq_ja_vinculada') : ''], $rows);
                $L::json(['ok' => true, 'itens' => $itens, 'total' => $total, 'por_pagina' => 25]);
            }
            $chave = $L::chave($busca); $itens = [];
            foreach ($daLei as $c => $i) {
                if ($chave !== '' && !str_contains($L::chave($i['texto'] . ' ' . $c), $chave)) continue;
                $itens[] = ['id' => $c, 'cells' => ['TEXTO' => $i['texto'], 'CODIGO' => (string)$c], 'detalhe' => $K('usada_em', ['n' => $i['usos']])];
            }
            usort($itens, static fn($a, $b) => strnatcasecmp($a['cells']['TEXTO'], $b['cells']['TEXTO']));
            $L::json(['ok' => true, 'itens' => array_slice($itens, ($pagina - 1) * 25, 25), 'total' => count($itens), 'por_pagina' => 25]);
        }
        switch ($acao) {
            case 'ld_mover':
                $ids = array_values(array_unique(array_filter(array_map('intval', (array)json_decode((string)($_POST['ids'] ?? '[]'), true)))));
                if (!$ids || count($ids) > 200) $L::json(['ok' => false, 'message' => $X($P ? 'ld.marque_palavras' : 'ld.marque_perguntas')], 422);
                $para = (string)($_POST['para'] ?? ''); $ok = 0; $erros = [];
                foreach ($ids as $c) {
                    try { $para === 'direita' ? ($fn['vincular'])($id, $c) : ($fn['desvincular'])($id, $c); $ok++; }
                    catch (InvalidArgumentException | RuntimeException $e) { $erros[$e->getMessage()] = ($erros[$e->getMessage()] ?? 0) + 1; }
                }
                $msg = $para === 'direita' ? $K('msg_vinculadas', ['n' => $ok]) : $X('cat.msg_desvinculados', ['n' => $ok]);
                foreach ($erros as $m => $n) $msg .= ' ' . $X('ld.erros', ['n' => $n, 'motivo' => $m]);
                $L::json(['ok' => true, 'message' => $msg, 'resumo' => $L::resumo($id)]);
            case 'info': $L::json(['ok' => true] + ($fn['info'])($cod));
            case 'leis_checklist': // migração 392: de qual lei copiar
                if ($P) $L::json(['ok' => false, 'message' => $X('erro.acao_desconhecida')], 400);
                $L::json(['ok' => true, 'leis' => $L::leisComChecklist($id, $texto)]);
            case 'copiar':
                if ($P) $L::json(['ok' => false, 'message' => $X('erro.acao_desconhecida')], 400);
                @set_time_limit(300);
                [$n, $ja] = $L::copiarChecklist($id, (int)($_POST['origem'] ?? 0));
                $msg = $ja ? $X('chk.msg_copiadas_ja', ['n' => $n, 'ja' => $ja]) : $X('chk.msg_copiadas', ['n' => $n]);
                break;
            case 'nova':
                if ($P) {
                    $r = $L::novaPalavraDetalhe($id, $texto);
                    $msg = $r['criada'] ? $X('pal.msg_nova') : $X($r['vinculada'] ? 'pal.msg_existia_vinculada' : 'pal.msg_existia_ja', ['cod' => $r['cod']]);
                } else { ($fn['nova'])($id, $texto); $msg = $X('chk.msg_nova'); }
                break;
            case 'editar':
                $usos = (int)(($fn['info'])($cod)['usos'] ?? 0);
                ($fn['editar'])($cod, $texto);
                $msg = $K('msg_editado', ['n' => $usos]);
                break;
            case 'sugerir_ia':
                if (!$P) $L::json(['ok' => false, 'message' => $X('erro.acao_desconhecida')], 400);
                @set_time_limit(180);
                $L::json(['ok' => true, 'sugestoes' => $L::sugerirPalavrasIa($id, $idioma)]);
            case 'incluir_sugestoes':
                if (!$P) $L::json(['ok' => false, 'message' => $X('erro.acao_desconhecida')], 400);
                $n = 0; $novas = 0; $ja = 0;
                foreach (array_slice((array)json_decode((string)($_POST['textos'] ?? '[]'), true), 0, 20) as $t) if (is_string($t) && trim($t) !== '') {
                    $r = $L::novaPalavraDetalhe($id, $t);
                    if ($r['vinculada']) $n++; else $ja++;
                    if ($r['criada']) $novas++;
                }
                $msg = rtrim($X('pal.msg_ia_incluidas', ['n' => $n]), '.') . ($novas ? ', ' . $X('pal.msg_ia_novas', ['n' => $novas]) : '') . ($ja ? '; ' . $X('pal.msg_ia_ja', ['n' => $ja]) : '') . '.';
                break;
            default: $L::json(['ok' => false, 'message' => $X('erro.acao_desconhecida')], 400);
        }
        $L::json(['ok' => true, 'message' => $msg]);
    } catch (InvalidArgumentException | RuntimeException $e) {
        $L::json(['ok' => false, 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        error_log('[legislacao_catalogo] ' . $e->getMessage());
        $L::json(['ok' => false, 'message' => $X('erro.nao_concluido')], 500);
    }
}

$extras = '';
if ($perm['editar']) {
    $extras = LegislacaoPopup::botao('bi-plus-lg', $K('btn_nova'), 'data-lp-nova-abrir', false, 'outline-success')
        . LegislacaoPopup::botao('bi-pencil', $K('btn_editar'), 'data-lp-editar-abrir', true);
    if (!$P) $extras .= LegislacaoPopup::botao('bi-copy', $X('chk.btn_copiar'), 'data-lp-copiar-abrir');
    if ($P && $L::iaDisponivel()) $extras .= LegislacaoPopup::botao('bi-stars', $X('pal.btn_ia'), 'data-lp-ia');
}
$explica = $K('explica') . ($perm['editar'] ? '' : ' <i class="bi bi-lock"></i> ' . $L::h($X('texto.somente_consulta')));

// textos longos (perguntas) quebram linha dentro da coluna, sem empurrar o Código para fora
LegislacaoPopup::inicio($K('titulo'), $lei, $explica, '.lista-dupla td[data-column="TEXTO"]{white-space:normal!important;overflow-wrap:anywhere}.lista-dupla td[data-column="CODIGO"]{white-space:nowrap}');
greenPortalListaDuplaHead();
greenPortalListaDupla([
    'id' => $P ? 'ldPalavras' : 'ldChecklist', 'transaction' => 'manutencaolegal_legislacao', 'editavel' => $perm['editar'], 'csrf' => $L::csrf(),
    'colunas' => ['TEXTO' => ['label' => $K('col'), 'width' => $P ? '75%' : '82%'], 'CODIGO' => ['label' => $X('ld.col_codigo')]],
    'titulo_esquerda' => $K('titulo_esquerda'), 'titulo_direita' => $K('titulo_direita'), 'extras' => $extras,
    'filtros_esquerda' => $P ? '<select class="form-select form-select-sm" data-ld-filtro="mostrar" aria-label="' . $L::h($X('ld.mostrar')) . '" title="' . $L::h($X('pal.dica_mostrar')) . '"><option value="sugeridas" selected>' . $L::h($X('ld.sugeridas')) . '</option><option value="todas">' . $L::h($X('ld.todas')) . '</option></select>' : '',
    'vazio_esquerda' => $X('cat.vazio_esquerda') . ($P ? ' ' . $X('cat.vazio_sugeridas') : '') . ($perm['editar'] ? ' ' . $X('cat.vazio_criar') : ''), 'vazio_direita' => $K('vazio_direita'),
]);
?>
<div class="modal fade lp-modal" id="lpNova" tabindex="-1"><div class="modal-dialog"><form class="modal-content" data-lp-nova autocomplete="off">
  <div class="modal-header py-2"><h5 class="modal-title fs-6"><?= $L::h($K('form_nova')) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $L::h($X('comum.fechar')) ?>"></button></div>
  <div class="modal-body"><?= $P ? '<input class="form-control form-control-sm mb-2" name="texto" maxlength="200" required placeholder="' . $L::h($K('form_placeholder')) . '">' : '<textarea class="form-control form-control-sm mb-2" name="texto" rows="3" maxlength="1000" required placeholder="' . $L::h($K('form_placeholder')) . '"></textarea>' ?>
    <div class="alert alert-danger py-1 px-2 mb-2 small d-none" data-lp-modal-erro></div>
    <div class="text-muted small"><?= $L::h($K('form_dica')) ?></div></div>
  <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> <?= $L::h($X('cat.form_incluir')) ?></button><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= $L::h($X('comum.cancelar')) ?></button></div>
</form></div></div>
<div class="modal fade lp-modal" id="lpEditar" tabindex="-1"><div class="modal-dialog"><form class="modal-content" data-lp-editar autocomplete="off">
  <div class="modal-header py-2"><h5 class="modal-title fs-6"><?= $L::h($K('form_editar')) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $L::h($X('comum.fechar')) ?>"></button></div>
  <div class="modal-body"><input type="hidden" name="cod">
    <textarea class="form-control form-control-sm mb-2" name="texto" rows="<?= $P ? 2 : 4 ?>" maxlength="<?= $P ? 200 : 1000 ?>" required></textarea>
    <div class="alert alert-danger py-1 px-2 mb-2 small d-none" data-lp-modal-erro></div>
    <div class="alert alert-warning py-1 px-2 mb-0 small" data-lp-usos></div></div>
  <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> <?= $L::h($X('comum.salvar')) ?></button><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= $L::h($X('comum.cancelar')) ?></button></div>
</form></div></div>
<?php if (!$P): ?>
<div class="modal fade lp-modal" id="lpCopiar" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header py-2"><h5 class="modal-title fs-6"><i class="bi bi-copy"></i> <?= $L::h($X('chk.copiar_titulo')) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $L::h($X('comum.fechar')) ?>"></button></div>
  <div class="modal-body">
    <input type="search" class="form-control form-control-sm mb-2" data-lp-copiar-busca placeholder="<?= $L::h($X('chk.copiar_filtro')) ?>" autocomplete="off">
    <div class="text-muted small mb-1"><?= $L::h($X('chk.copiar_dica')) ?></div>
    <div class="lp-lista" data-lp-copiar-lista style="max-height:50vh;overflow:auto"><div class="lp-vazio"><?= $L::h(IdiomaService::t('comum.relacionamentos.carregando', ['pt' => 'Carregando...', 'en' => 'Loading...', 'es' => 'Cargando...'])) ?></div></div>
  </div>
  <div class="modal-footer py-2"><button type="button" class="btn btn-sm btn-success" data-lp-copiar-ok disabled><i class="bi bi-check-lg"></i> <?= $L::h($X('chk.copiar_btn')) ?></button><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= $L::h($X('comum.cancelar')) ?></button></div>
</div></div></div>
<?php endif; ?>
<?php if ($P): ?>
<div class="modal fade lp-modal" id="lpIa" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header py-2"><h5 class="modal-title fs-6"><i class="bi bi-stars"></i> <?= $L::h($X('pal.ia_titulo')) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $L::h($X('comum.fechar')) ?>"></button></div>
  <div class="modal-body"><div class="text-muted small mb-1"><?= $L::h($X('pal.ia_dica')) ?></div><div class="lp-lista" data-lp-sugestoes></div></div>
  <div class="modal-footer py-2"><button type="button" class="btn btn-sm btn-success" data-lp-sug-ok><i class="bi bi-check-lg"></i> <?= $L::h($X('pal.ia_btn')) ?></button><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= $L::h($X('comum.cancelar')) ?></button></div>
</div></div></div>
<?php endif;
LegislacaoPopup::fim($id, $tipo, 'window.LP_PALAVRAS = ' . ($P ? 'true' : 'false') . ';' . <<<'JS'
(function () {
    var X = function (k, p, v) { var t = LP.T('manutencaolegal_legislacao.js.' + k, p); if (v) Object.keys(v).forEach(function (c) { t = t.split('{' + c + '}').join(v[c]); }); return t; };
    var $ = window.jQuery, mNova = bootstrap.Modal.getOrCreateInstance(document.getElementById('lpNova')), mEditar = bootstrap.Modal.getOrCreateInstance(document.getElementById('lpEditar'));
    var elIa = document.getElementById('lpIa'), mIa = elIa ? bootstrap.Modal.getOrCreateInstance(elIa) : null;
    // Editar: só com exatamente um item marcado (em qualquer lado)
    function marcadas() { return $('.lista-dupla tbody tr[data-id]').filter(function () { return $(this).find('[data-ld-marca]').is(':checked'); }).map(function () { return this.getAttribute('data-id'); }).get(); }
    function estadoEditar() { $('[data-lp-editar-abrir]').prop('disabled', marcadas().length !== 1); }
    $(document).on('change click', '.lista-dupla table', function () { setTimeout(estadoEditar, 0); });
    $(document).on('lista-dupla-movida', function () { setTimeout(estadoEditar, 300); });
    // erro de gravação aparece dentro da janela aberta (e não atrás dela)
    function erroModal($f) { return function (r) { LP.aviso(''); $f.find('[data-lp-modal-erro]').removeClass('d-none').text((r && (r.message || (r.responseJSON && r.responseJSON.message))) || X('nao_concluido', 'Não foi possível concluir.')); }; }
    $('.lp-modal').on('show.bs.modal', function () { $(this).find('[data-lp-modal-erro]').addClass('d-none').text(''); });
    $(document).on('click', '[data-lp-nova-abrir]', function () { $('[data-lp-nova]')[0].reset(); mNova.show(); setTimeout(function () { $('[data-lp-nova] [name=texto]').trigger('focus'); }, 300); });
    $('[data-lp-nova]').on('submit', function (e) { e.preventDefault(); var $f = $(this); LP.enviar({ action: 'nova', texto: $f.find('[name=texto]').val() }, $f.find('[type=submit]')).then(LP.gravado, erroModal($f)); });
    $(document).on('click', '[data-lp-editar-abrir]', function () {
        var m = marcadas(); if (m.length !== 1) return;
        LP.enviar({ action: 'info', cod: m[0] }, $(this)).then(function (r) {
            var $f = $('[data-lp-editar]'), n = Number(r.usos || 0);
            $f.find('[name=cod]').val(r.cod); $f.find('[name=texto]').val(r.texto);
            $('[data-lp-usos]').html('<i class="bi bi-exclamation-triangle"></i> ' + LP.esc(window.LP_PALAVRAS ? X('pal_usos', 'A mudança vale para {n} lei(s) e para as licenças que usam esta palavra.', { n: n }) : X('chk_usos', 'A mudança vale para {n} lei(s).', { n: n })));
            mEditar.show();
        }, function () {});
    });
    $('[data-lp-editar]').on('submit', function (e) { e.preventDefault(); var $f = $(this); LP.enviar({ action: 'editar', cod: $f.find('[name=cod]').val(), texto: $f.find('[name=texto]').val() }, $f.find('[type=submit]')).then(LP.gravado, erroModal($f)); });
    $(document).on('click', '[data-lp-ia]', function () {
        if (!mIa) return;
        LP.aviso(X('ia_consultando', 'Consultando a IA...'), 'info');
        LP.enviar({ action: 'sugerir_ia' }, $(this)).then(function (r) {
            var s = r.sugestoes || [];
            if (!s.length) return LP.aviso(X('ia_nada', 'A IA não trouxe palavras novas.'), 'info');
            LP.aviso('');
            $('[data-lp-sugestoes]').html(s.map(function (x) { return '<label class="lp-item" style="cursor:pointer"><input type="checkbox" class="form-check-input" value="' + LP.esc(x.texto) + '"' + (x.nova ? '' : ' checked') + '><span class="lp-txt">' + LP.esc(x.texto) + '</span>' + (x.nova ? '<span class="badge text-bg-warning">' + LP.esc(X('ia_nova', 'nova no catálogo')) + '</span>' : '<span class="badge text-bg-success">' + LP.esc(X('ia_existe', 'já no catálogo')) + '</span>') + '</label>'; }).join(''));
            mIa.show();
        }, function () {});
    });
    // Copiar o checklist de outra lei (migração 392)
    var elCop = document.getElementById('lpCopiar'), mCop = elCop ? bootstrap.Modal.getOrCreateInstance(elCop) : null, leisCop = [], tCop = null;
    // filtro por palavras, cada uma no começo de uma palavra do nome (ex.: "nr 10" acha "Norma Regulamentadora (NR) nº 10")
    function semAcento(t) { return String(t || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[º°ª]/g, ' ').replace(/[^a-z0-9]+/g, ' ').trim(); }
    function listarCop() {
        var termos = semAcento($('[data-lp-copiar-busca]').val()).split(' ').filter(Boolean);
        var l = leisCop.filter(function (x) { var w = ' ' + semAcento(x.rotulo + ' ' + x.codigo + ' ' + x.situacao) + ' '; return termos.every(function (t) { return w.indexOf(' ' + t) >= 0 || w.indexOf(' ' + t.replace(/^0+(?=\d)/, '')) >= 0 || w.indexOf(' 0' + t) >= 0; }); });
        $('[data-lp-copiar-lista]').html(l.length ? l.map(function (x) { return '<label class="lp-item" style="cursor:pointer"><input type="radio" class="form-check-input" name="lpOrigem" value="' + x.id + '" data-qtd="' + x.qtd + '"><span class="lp-txt">' + LP.esc(x.rotulo) + ' <span class="text-muted small">' + LP.esc(x.codigo) + (x.situacao ? ' · ' + LP.esc(x.situacao) : '') + '</span></span><span class="badge text-bg-secondary">' + LP.esc(X('copiar_qtd', '{n} pergunta(s)', { n: x.qtd })) + '</span></label>'; }).join('') : '<div class="lp-vazio">' + LP.esc(X('copiar_vazio', 'Nenhuma lei com checklist nesse filtro.')) + '</div>');
        $('[data-lp-copiar-ok]').prop('disabled', true);
    }
    $(document).on('click', '[data-lp-copiar-abrir]', function () {
        if (!mCop) return;
        $('[data-lp-copiar-busca]').val(''); $('[data-lp-copiar-lista]').html('<div class="lp-vazio">' + LP.esc(X('carregando', 'Carregando...')) + '</div>'); $('[data-lp-copiar-ok]').prop('disabled', true); mCop.show();
        LP.enviar({ action: 'leis_checklist', texto: '' }).then(function (r) { leisCop = r.leis || []; listarCop(); }, function () { mCop.hide(); });
    });
    $(document).on('input', '[data-lp-copiar-busca]', function () { clearTimeout(tCop); tCop = setTimeout(listarCop, 200); });
    $(document).on('change', '[name=lpOrigem]', function () { $('[data-lp-copiar-ok]').prop('disabled', false); });
    $(document).on('click', '[data-lp-copiar-ok]', function () {
        var $r = $('[name=lpOrigem]:checked'); if (!$r.length) return;
        var $b = $(this), rot = $r.closest('label').find('.lp-txt').text(), qtd = $r.attr('data-qtd');
        LP.confirmar(X('copiar_titulo', 'Copiar checklist'), X('copiar_confirma', 'Copiar para esta lei as perguntas de {lei} ({n} no total)? As que esta lei já tem são ignoradas.', { lei: rot, n: qtd }), function () {
            LP.enviar({ action: 'copiar', origem: $r.val() }, $b).then(LP.gravado, function () {});
        });
    });
    $(document).on('click', '[data-lp-sug-ok]', function () {
        var textos = $('[data-lp-sugestoes] input:checked').map(function () { return this.value; }).get();
        if (!textos.length) return;
        LP.enviar({ action: 'incluir_sugestoes', textos: JSON.stringify(textos) }, $(this)).then(LP.gravado, function () {});
    });
}());
JS, '<script src="../assets/js/lista-dupla.js?v=5"></script>');
