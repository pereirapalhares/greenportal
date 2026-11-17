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
// [título, singular, plural, rótulo da coluna, gênero do artigo]
$nome = $P ? ['Palavras-chave', 'palavra-chave', 'palavras-chave', 'Palavra-chave', 'a'] : ['CheckList', 'pergunta', 'perguntas', 'Pergunta', 'a'];
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
                    'bloqueado' => isset($daLei[$p['cod']]) ? 'Já vinculad' . $nome[4] . ' a esta lei' : ''], $rows);
                $L::json(['ok' => true, 'itens' => $itens, 'total' => $total, 'por_pagina' => 25]);
            }
            $chave = $L::chave($busca); $itens = [];
            foreach ($daLei as $c => $i) {
                if ($chave !== '' && !str_contains($L::chave($i['texto'] . ' ' . $c), $chave)) continue;
                $itens[] = ['id' => $c, 'cells' => ['TEXTO' => $i['texto'], 'CODIGO' => (string)$c], 'detalhe' => 'Usad' . $nome[4] . ' em ' . $i['usos'] . ' lei' . ($i['usos'] === 1 ? '' : 's')];
            }
            usort($itens, static fn($a, $b) => strnatcasecmp($a['cells']['TEXTO'], $b['cells']['TEXTO']));
            $L::json(['ok' => true, 'itens' => array_slice($itens, ($pagina - 1) * 25, 25), 'total' => count($itens), 'por_pagina' => 25]);
        }
        switch ($acao) {
            case 'ld_mover':
                $ids = array_values(array_unique(array_filter(array_map('intval', (array)json_decode((string)($_POST['ids'] ?? '[]'), true)))));
                if (!$ids || count($ids) > 200) $L::json(['ok' => false, 'message' => 'Marque de 1 a 200 ' . $nome[2] . '.'], 422);
                $para = (string)($_POST['para'] ?? ''); $ok = 0; $erros = [];
                foreach ($ids as $c) {
                    try { $para === 'direita' ? ($fn['vincular'])($id, $c) : ($fn['desvincular'])($id, $c); $ok++; }
                    catch (InvalidArgumentException | RuntimeException $e) { $erros[$e->getMessage()] = ($erros[$e->getMessage()] ?? 0) + 1; }
                }
                $msg = $para === 'direita' ? $ok . ' ' . ($ok === 1 ? $nome[1] . ' vinculada' : $nome[2] . ' vinculadas') . '.' : $ok . ' vínculo(s) removido(s) (continuam no catálogo).';
                foreach ($erros as $m => $n) $msg .= ' ' . $n . ' não: ' . $m;
                $L::json(['ok' => true, 'message' => $msg, 'resumo' => $L::resumo($id)]);
            case 'info': $L::json(['ok' => true] + ($fn['info'])($cod));
            case 'leis_checklist': // migração 392: de qual lei copiar
                if ($P) $L::json(['ok' => false, 'message' => 'Ação desconhecida.'], 400);
                $L::json(['ok' => true, 'leis' => $L::leisComChecklist($id, $texto)]);
            case 'copiar':
                if ($P) $L::json(['ok' => false, 'message' => 'Ação desconhecida.'], 400);
                @set_time_limit(300);
                [$n, $ja] = $L::copiarChecklist($id, (int)($_POST['origem'] ?? 0));
                $msg = $n . ' pergunta' . ($n === 1 ? '' : 's') . ' copiada' . ($n === 1 ? '' : 's') . ($ja ? ' (' . $ja . ' já estava' . ($ja === 1 ? '' : 'm') . ' nesta lei).' : '.');
                break;
            case 'nova': ($fn['nova'])($id, $texto); $msg = ucfirst($nome[1]) . ' incluída no catálogo e vinculada.'; break;
            case 'editar':
                $usos = (int)(($fn['info'])($cod)['usos'] ?? 0);
                ($fn['editar'])($cod, $texto);
                $msg = 'Texto alterado no catálogo (vale para ' . $usos . ' lei' . ($usos === 1 ? '' : 's') . ($P ? ' e para as licenças que usam a palavra' : '') . ').';
                break;
            case 'sugerir_ia':
                if (!$P) $L::json(['ok' => false, 'message' => 'Ação desconhecida.'], 400);
                @set_time_limit(180);
                $L::json(['ok' => true, 'sugestoes' => $L::sugerirPalavrasIa($id, $idioma)]);
            case 'incluir_sugestoes':
                if (!$P) $L::json(['ok' => false, 'message' => 'Ação desconhecida.'], 400);
                $n = 0;
                foreach (array_slice((array)json_decode((string)($_POST['textos'] ?? '[]'), true), 0, 20) as $t) if (is_string($t) && trim($t) !== '') { $L::novaPalavra($id, $t); $n++; }
                $msg = $n . ' palavra(s)-chave vinculada(s).';
                break;
            default: $L::json(['ok' => false, 'message' => 'Ação desconhecida.'], 400);
        }
        $L::json(['ok' => true, 'message' => $msg]);
    } catch (InvalidArgumentException | RuntimeException $e) {
        $L::json(['ok' => false, 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        error_log('[legislacao_catalogo] ' . $e->getMessage());
        $L::json(['ok' => false, 'message' => 'Não foi possível concluir a operação.'], 500);
    }
}

$extras = '';
if ($perm['editar']) {
    $extras = LegislacaoPopup::botao('bi-plus-lg', 'Nova ' . $nome[1] . ' (entra no catálogo e é vinculada à lei)', 'data-lp-nova-abrir', false, 'outline-success')
        . LegislacaoPopup::botao('bi-pencil', 'Editar o texto d' . $nome[4] . ' ' . $nome[1] . ' marcad' . $nome[4] . ' (vale para todas as leis que usam)', 'data-lp-editar-abrir', true);
    if (!$P) $extras .= LegislacaoPopup::botao('bi-copy', 'Copiar o checklist de outra lei (só entram as perguntas que esta lei ainda não tem)', 'data-lp-copiar-abrir');
    if ($P && $L::iaDisponivel()) $extras .= LegislacaoPopup::botao('bi-stars', 'Sugerir palavras-chave com IA (só entram as que você marcar)', 'data-lp-ia');
}
$explica = ($P ? 'À esquerda, em <b>Sugeridas</b>, as palavras usadas por leis parecidas (mesmo tipo e âmbito, mesmo assunto, família), da mais provável para a menos — o motivo aparece sob a palavra; em <b>Todas</b>, ' : 'À esquerda, ') . 'o catálogo único de ' . $nome[2] . ($P ? ' (o mesmo das licenças)' : ' reaproveitadas entre as leis') . '; à direita, as d' . $nome[4] . 's desta lei. Marque e use › para vincular ou ‹ para tirar o vínculo — o item continua no catálogo. Editar um texto muda o catálogo e vale para todas as leis que usam o item.'
    . ($perm['editar'] ? '' : ' <i class="bi bi-lock"></i> Somente consulta.');

// textos longos (perguntas) quebram linha dentro da coluna, sem empurrar o Código para fora
LegislacaoPopup::inicio($nome[0], $lei, $explica, '.lista-dupla td[data-column="TEXTO"]{white-space:normal!important;overflow-wrap:anywhere}.lista-dupla td[data-column="CODIGO"]{white-space:nowrap}');
greenPortalListaDuplaHead();
greenPortalListaDupla([
    'id' => $P ? 'ldPalavras' : 'ldChecklist', 'transaction' => 'manutencaolegal_legislacao', 'editavel' => $perm['editar'], 'csrf' => $L::csrf(),
    'colunas' => ['TEXTO' => ['label' => $nome[3], 'width' => $P ? '75%' : '82%'], 'CODIGO' => ['label' => 'Código']],
    'titulo_esquerda' => $P ? 'Palavras para incluir' : 'Catálogo de ' . $nome[2], 'titulo_direita' => ucfirst($nome[2]) . ' desta lei', 'extras' => $extras,
    'filtros_esquerda' => $P ? '<select class="form-select form-select-sm" data-ld-filtro="mostrar" aria-label="Mostrar" title="Sugeridas: palavras usadas por leis parecidas (mesmo tipo e âmbito, mesmo assunto, família). Todas: o catálogo inteiro."><option value="sugeridas" selected>Sugeridas</option><option value="todas">Todas</option></select>' : '',
    'vazio_esquerda' => 'Nada com esse filtro.' . ($P ? ' Em Sugeridas, troque para Todas.' : '') . ($perm['editar'] ? ' Use + para criar.' : ''), 'vazio_direita' => 'Nenhum' . ($nome[4] === 'a' ? 'a' : '') . ' ' . $nome[1] . '. Marque à esquerda e use ›.',
]);
?>
<div class="modal fade lp-modal" id="lpNova" tabindex="-1"><div class="modal-dialog"><form class="modal-content" data-lp-nova autocomplete="off">
  <div class="modal-header py-2"><h5 class="modal-title fs-6">Nova <?= $L::h($nome[1]) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
  <div class="modal-body"><?= $P ? '<input class="form-control form-control-sm mb-2" name="texto" maxlength="200" required placeholder="Digite a palavra-chave">' : '<textarea class="form-control form-control-sm mb-2" name="texto" rows="3" maxlength="1000" required placeholder="Digite a pergunta"></textarea>' ?>
    <div class="text-muted small"><?= $P ? 'Se já existir no catálogo (sem diferença de acento ou maiúsculas), a existente é vinculada — não cria repetida.' : 'Busque antes na lista da esquerda: pergunta igual à de outra lei não é criada de novo (vincule a existente com ›).' ?></div></div>
  <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> Incluir e vincular</button><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button></div>
</form></div></div>
<div class="modal fade lp-modal" id="lpEditar" tabindex="-1"><div class="modal-dialog"><form class="modal-content" data-lp-editar autocomplete="off">
  <div class="modal-header py-2"><h5 class="modal-title fs-6">Editar <?= $L::h($nome[1]) ?> no catálogo</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
  <div class="modal-body"><input type="hidden" name="cod">
    <textarea class="form-control form-control-sm mb-2" name="texto" rows="<?= $P ? 2 : 4 ?>" maxlength="<?= $P ? 200 : 1000 ?>" required></textarea>
    <div class="alert alert-warning py-1 px-2 mb-0 small" data-lp-usos></div></div>
  <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> Salvar</button><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button></div>
</form></div></div>
<?php if (!$P): ?>
<div class="modal fade lp-modal" id="lpCopiar" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header py-2"><h5 class="modal-title fs-6"><i class="bi bi-copy"></i> Copiar o checklist de outra lei</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
  <div class="modal-body">
    <input type="search" class="form-control form-control-sm mb-2" data-lp-copiar-busca placeholder="Filtrar pelo tipo, número, ano ou código da lei" autocomplete="off">
    <div class="text-muted small mb-1">Escolha a lei de origem. Só entram as perguntas que esta lei ainda não tem; nada é apagado.</div>
    <div class="lp-lista" data-lp-copiar-lista style="max-height:50vh;overflow:auto"><div class="lp-vazio">Carregando...</div></div>
  </div>
  <div class="modal-footer py-2"><button type="button" class="btn btn-sm btn-success" data-lp-copiar-ok disabled><i class="bi bi-check-lg"></i> Copiar</button><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button></div>
</div></div></div>
<?php endif; ?>
<?php if ($P): ?>
<div class="modal fade lp-modal" id="lpIa" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header py-2"><h5 class="modal-title fs-6"><i class="bi bi-stars"></i> Palavras-chave sugeridas pela IA</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
  <div class="modal-body"><div class="text-muted small mb-1">Marque as que quer vincular. As "novas" entram no catálogo.</div><div class="lp-lista" data-lp-sugestoes></div></div>
  <div class="modal-footer py-2"><button type="button" class="btn btn-sm btn-success" data-lp-sug-ok><i class="bi bi-check-lg"></i> Vincular marcadas</button><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button></div>
</div></div></div>
<?php endif;
LegislacaoPopup::fim($id, $tipo, 'window.LP_PALAVRAS = ' . ($P ? 'true' : 'false') . ';' . <<<'JS'
(function () {
    var $ = window.jQuery, mNova = bootstrap.Modal.getOrCreateInstance(document.getElementById('lpNova')), mEditar = bootstrap.Modal.getOrCreateInstance(document.getElementById('lpEditar'));
    var elIa = document.getElementById('lpIa'), mIa = elIa ? bootstrap.Modal.getOrCreateInstance(elIa) : null;
    // Editar: só com exatamente um item marcado (em qualquer lado)
    function marcadas() { return $('.lista-dupla tbody tr[data-id]').filter(function () { return $(this).find('[data-ld-marca]').is(':checked'); }).map(function () { return this.getAttribute('data-id'); }).get(); }
    function estadoEditar() { $('[data-lp-editar-abrir]').prop('disabled', marcadas().length !== 1); }
    $(document).on('change click', '.lista-dupla table', function () { setTimeout(estadoEditar, 0); });
    $(document).on('lista-dupla-movida', function () { setTimeout(estadoEditar, 300); });
    $(document).on('click', '[data-lp-nova-abrir]', function () { $('[data-lp-nova]')[0].reset(); mNova.show(); setTimeout(function () { $('[data-lp-nova] [name=texto]').trigger('focus'); }, 300); });
    $('[data-lp-nova]').on('submit', function (e) { e.preventDefault(); var $f = $(this); LP.enviar({ action: 'nova', texto: $f.find('[name=texto]').val() }, $f.find('[type=submit]')).then(LP.gravado, function () {}); });
    $(document).on('click', '[data-lp-editar-abrir]', function () {
        var m = marcadas(); if (m.length !== 1) return;
        LP.enviar({ action: 'info', cod: m[0] }, $(this)).then(function (r) {
            var $f = $('[data-lp-editar]'), n = Number(r.usos || 0);
            $f.find('[name=cod]').val(r.cod); $f.find('[name=texto]').val(r.texto);
            $('[data-lp-usos]').html('<i class="bi bi-exclamation-triangle"></i> A mudança vale para ' + n + ' lei' + (n === 1 ? '' : 's') + (window.LP_PALAVRAS ? ' e para as licenças que usam esta palavra' : '') + '.');
            mEditar.show();
        }, function () {});
    });
    $('[data-lp-editar]').on('submit', function (e) { e.preventDefault(); var $f = $(this); LP.enviar({ action: 'editar', cod: $f.find('[name=cod]').val(), texto: $f.find('[name=texto]').val() }, $f.find('[type=submit]')).then(LP.gravado, function () {}); });
    $(document).on('click', '[data-lp-ia]', function () {
        if (!mIa) return;
        LP.aviso('Consultando a IA...', 'info');
        LP.enviar({ action: 'sugerir_ia' }, $(this)).then(function (r) {
            var s = r.sugestoes || [];
            if (!s.length) return LP.aviso('A IA não trouxe palavras novas.', 'info');
            LP.aviso('');
            $('[data-lp-sugestoes]').html(s.map(function (x) { return '<label class="lp-item" style="cursor:pointer"><input type="checkbox" class="form-check-input" value="' + LP.esc(x.texto) + '"' + (x.nova ? '' : ' checked') + '><span class="lp-txt">' + LP.esc(x.texto) + '</span>' + (x.nova ? '<span class="badge text-bg-warning">nova no catálogo</span>' : '<span class="badge text-bg-success">já no catálogo</span>') + '</label>'; }).join(''));
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
        $('[data-lp-copiar-lista]').html(l.length ? l.map(function (x) { return '<label class="lp-item" style="cursor:pointer"><input type="radio" class="form-check-input" name="lpOrigem" value="' + x.id + '" data-qtd="' + x.qtd + '"><span class="lp-txt">' + LP.esc(x.rotulo) + ' <span class="text-muted small">' + LP.esc(x.codigo) + (x.situacao ? ' · ' + LP.esc(x.situacao) : '') + '</span></span><span class="badge text-bg-secondary">' + x.qtd + ' pergunta' + (x.qtd === 1 ? '' : 's') + '</span></label>'; }).join('') : '<div class="lp-vazio">Nenhuma lei com checklist nesse filtro.</div>');
        $('[data-lp-copiar-ok]').prop('disabled', true);
    }
    $(document).on('click', '[data-lp-copiar-abrir]', function () {
        if (!mCop) return;
        $('[data-lp-copiar-busca]').val(''); $('[data-lp-copiar-lista]').html('<div class="lp-vazio">Carregando...</div>'); $('[data-lp-copiar-ok]').prop('disabled', true); mCop.show();
        LP.enviar({ action: 'leis_checklist', texto: '' }).then(function (r) { leisCop = r.leis || []; listarCop(); }, function () { mCop.hide(); });
    });
    $(document).on('input', '[data-lp-copiar-busca]', function () { clearTimeout(tCop); tCop = setTimeout(listarCop, 200); });
    $(document).on('change', '[name=lpOrigem]', function () { $('[data-lp-copiar-ok]').prop('disabled', false); });
    $(document).on('click', '[data-lp-copiar-ok]', function () {
        var $r = $('[name=lpOrigem]:checked'); if (!$r.length) return;
        var $b = $(this), rot = $r.closest('label').find('.lp-txt').text(), qtd = $r.attr('data-qtd');
        LP.confirmar('Copiar checklist', 'Copiar para esta lei as perguntas de ' + rot + ' (' + qtd + ' no total)? As que esta lei já tem são ignoradas.', function () {
            LP.enviar({ action: 'copiar', origem: $r.val() }, $b).then(LP.gravado, function () {});
        });
    });
    $(document).on('click', '[data-lp-sug-ok]', function () {
        var textos = $('[data-lp-sugestoes] input:checked').map(function () { return this.value; }).get();
        if (!textos.length) return;
        LP.enviar({ action: 'incluir_sugestoes', textos: JSON.stringify(textos) }, $(this)).then(LP.gravado, function () {});
    });
}());
JS, '<script src="../assets/js/lista-dupla.js?v=3"></script>');
