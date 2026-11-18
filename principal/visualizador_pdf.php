<?php
declare(strict_types=1);
/*
 * Visualizador de PDF do portal (18/11/2026, pedido do usuário): abre dentro do popup padrão (GreenPortalPopups) com
 * paginação, busca de palavra (contagem, anterior/próxima ocorrência, destaque na página), zoom e Baixar.
 * Parâmetro src = endereço do PDF; só aceita os geradores conhecidos abaixo (eles conferem sessão e permissão).
 * Ex.: visualizador_pdf.php?src=visualizadopdf.php%3Fcodigo%3DSSO2070%26transacao%3DLEGISLACAO&nome=SSO2070
 */
require_once dirname(__DIR__) . '/classes/AdministracaoTextos.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit($e(adm_t('Sessão expirada.'))); }

const GERADORES_PDF = ['visualizadopdf.php' => ['codigo', 'transacao'], 'legislacao_pdf.php' => ['codigo'], 'naoconformidade_ocorrenciapdf.php' => ['codigo']];
$partes = parse_url((string)($_GET['src'] ?? ''));
$arquivo = basename((string)($partes['path'] ?? ''));
parse_str((string)($partes['query'] ?? ''), $q);
if (!isset(GERADORES_PDF[$arquivo])) { http_response_code(400); exit($e(adm_t('Documento inválido.'))); }
$q = array_intersect_key($q, array_flip(GERADORES_PDF[$arquivo]));
foreach ($q as $k => $v) if (!is_string($v) || !preg_match('/^[A-Za-z0-9_-]{1,60}$/', $v)) { http_response_code(400); exit($e(adm_t('Documento inválido.'))); }
$src = $arquivo . ($q ? '?' . http_build_query($q) : '');
$nome = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string)($_GET['nome'] ?? ($q['codigo'] ?? 'documento'))) ?: 'documento';
$idioma = IdiomaService::idioma();
$T = [
    'buscar' => adm_t('Buscar no PDF'), 'encontradas' => adm_t('{n} encontradas'), 'encontrada' => adm_t('1 encontrada'), 'nenhuma' => adm_t('Nenhuma ocorrência'),
    'pagina' => adm_t('Página'), 'de' => adm_t('de'), 'carregando' => adm_t('Carregando documento...'), 'erro' => adm_t('Não foi possível exibir o PDF. Use o botão Baixar.'),
    'ocorrencia' => adm_t('Ocorrência {i} de {n}'),
];
?><!DOCTYPE html>
<html lang="<?= $e(['en' => 'en', 'es' => 'es'][$idioma] ?? 'pt-BR') ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($nome) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css" rel="stylesheet"><link href="../assets/css/main.css?v=18" rel="stylesheet"><link href="../assets/css/design-system.css?v=3" rel="stylesheet">
<style>
html,body{height:100%;margin:0}
body{display:flex;flex-direction:column;background:#eef2f0;font-size:12px}
.vp-barra{display:flex;align-items:center;gap:6px;flex-wrap:wrap;padding:6px 10px;background:#fff;border-bottom:1px solid var(--gp-border,#d9e4df);flex:0 0 auto}
.vp-grupo{display:flex;align-items:center;gap:4px}
.vp-sep{width:1px;height:22px;background:var(--gp-border,#d9e4df);margin:0 4px}
.vp-btn{display:inline-flex;align-items:center;justify-content:center;height:28px;min-width:28px;padding:0 8px;border:1px solid var(--gp-border,#cfdcd6);border-radius:6px;background:#fff;color:#33463e;font-size:12px;text-decoration:none;cursor:pointer}
.vp-btn:hover:not(:disabled){border-color:var(--gp-primary,#2f7d5b);color:var(--gp-primary,#2f7d5b)}
.vp-btn:disabled{opacity:.45;cursor:default}
.vp-btn.vp-baixar{border-color:var(--gp-primary,#2f7d5b);color:var(--gp-primary,#2f7d5b)}
.vp-busca{position:relative}
.vp-busca i{position:absolute;left:8px;top:50%;transform:translateY(-50%);color:#8aa197;font-size:12px}
.vp-busca input{height:28px;width:220px;padding:0 8px 0 26px;border:1px solid var(--gp-border,#cfdcd6);border-radius:6px;font-size:12px}
.vp-busca input:focus{outline:none;border-color:var(--gp-primary,#2f7d5b)}
.vp-info{color:#6b8279;white-space:nowrap}
.vp-pagina input{width:46px;height:28px;text-align:center;border:1px solid var(--gp-border,#cfdcd6);border-radius:6px;font-size:12px}
.vp-area{flex:1 1 auto;overflow:auto;padding:14px;text-align:center}
.vp-folha{position:relative;display:inline-block;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.18);margin:0 auto}
.vp-folha canvas{display:block}
.vp-marcas{position:absolute;inset:0;pointer-events:none}
.vp-marcas span{position:absolute;background:rgba(255,213,0,.45);border-radius:2px}
.vp-marcas span.atual{background:rgba(255,140,0,.55);outline:2px solid rgba(230,110,0,.8)}
.vp-msg{padding:40px;color:#6b8279}
</style>
</head>
<body>
<div class="vp-barra" role="toolbar" aria-label="<?= adm_a('Visualizador de PDF') ?>">
  <div class="vp-grupo vp-busca"><i class="bi bi-search"></i><input type="search" data-vp-busca placeholder="<?= $e($T['buscar']) ?>" aria-label="<?= $e($T['buscar']) ?>" autocomplete="off"></div>
  <div class="vp-grupo">
    <button type="button" class="vp-btn" data-vp-ant-ocorr title="<?= adm_a('Ocorrência anterior') ?>" aria-label="<?= adm_a('Ocorrência anterior') ?>" disabled><i class="bi bi-chevron-up"></i></button>
    <button type="button" class="vp-btn" data-vp-prox-ocorr title="<?= adm_a('Próxima ocorrência') ?>" aria-label="<?= adm_a('Próxima ocorrência') ?>" disabled><i class="bi bi-chevron-down"></i></button>
    <span class="vp-info" data-vp-contagem></span>
  </div>
  <span class="vp-sep"></span>
  <div class="vp-grupo vp-pagina">
    <button type="button" class="vp-btn" data-vp-primeira title="<?= adm_a('Primeira página') ?>" aria-label="<?= adm_a('Primeira página') ?>"><i class="bi bi-chevron-double-left"></i></button>
    <button type="button" class="vp-btn" data-vp-anterior title="<?= adm_a('Página anterior') ?>" aria-label="<?= adm_a('Página anterior') ?>"><i class="bi bi-chevron-left"></i></button>
    <span class="vp-info"><?= $e($T['pagina']) ?></span><input type="number" min="1" value="1" data-vp-pagina aria-label="<?= $e($T['pagina']) ?>"><span class="vp-info"><?= $e($T['de']) ?> <span data-vp-total>–</span></span>
    <button type="button" class="vp-btn" data-vp-proxima title="<?= adm_a('Próxima página') ?>" aria-label="<?= adm_a('Próxima página') ?>"><i class="bi bi-chevron-right"></i></button>
    <button type="button" class="vp-btn" data-vp-ultima title="<?= adm_a('Última página') ?>" aria-label="<?= adm_a('Última página') ?>"><i class="bi bi-chevron-double-right"></i></button>
  </div>
  <span class="vp-sep"></span>
  <div class="vp-grupo">
    <button type="button" class="vp-btn" data-vp-menos title="<?= adm_a('Diminuir zoom') ?>" aria-label="<?= adm_a('Diminuir zoom') ?>"><i class="bi bi-zoom-out"></i></button>
    <span class="vp-info" data-vp-zoom>100%</span>
    <button type="button" class="vp-btn" data-vp-mais title="<?= adm_a('Aumentar zoom') ?>" aria-label="<?= adm_a('Aumentar zoom') ?>"><i class="bi bi-zoom-in"></i></button>
    <button type="button" class="vp-btn" data-vp-largura title="<?= adm_a('Ajustar à largura') ?>" aria-label="<?= adm_a('Ajustar à largura') ?>"><i class="bi bi-arrows-expand-vertical"></i></button>
  </div>
  <span class="vp-sep"></span>
  <a class="vp-btn vp-baixar" href="<?= $e($src) ?>" download="<?= $e($nome) ?>.pdf" title="<?= adm_a('Baixar o arquivo') ?>"><i class="bi bi-download me-1"></i><?= adm_h('Baixar') ?></a>
</div>
<div class="vp-area" data-vp-area><div class="vp-msg" data-vp-msg><?= $e($T['carregando']) ?></div><div class="vp-folha" data-vp-folha hidden><canvas data-vp-canvas></canvas><div class="vp-marcas" data-vp-marcas></div></div></div>
<script>
(function () {
    'use strict';
    var T = <?= json_encode($T, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>, SRC = <?= json_encode($src, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var $ = function (s) { return document.querySelector(s); };
    var area = $('[data-vp-area]'), folha = $('[data-vp-folha]'), canvas = $('[data-vp-canvas]'), marcas = $('[data-vp-marcas]'), msg = $('[data-vp-msg]');
    var st = { pdf: null, pagina: 1, zoom: 0, larguraAuto: true, textos: [], itens: {}, termo: '', ocorr: [], atual: -1, vez: 0 };
    function fmt(s, v) { return String(s).replace(/\{(\w+)\}/g, function (_, k) { return v[k] !== undefined ? v[k] : ''; }); }
    function norm(s) { return String(s || '').toLocaleLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
    function carregarPdfJs() {
        if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
        return new Promise(function (ok, erro) {
            var s = document.createElement('script'); s.src = '../assets/vendor/pdfjs/pdf.min.js?v=2';
            s.onload = function () { if (!window.pdfjsLib) return erro(new Error('pdf.js')); window.pdfjsLib.GlobalWorkerOptions.workerSrc = '../assets/vendor/pdfjs/pdf.worker.min.js?v=2'; ok(window.pdfjsLib); };
            s.onerror = erro; document.head.appendChild(s);
        });
    }
    function escalaPara(pagina) {
        var base = pagina.getViewport({ scale: 1 });
        if (st.larguraAuto) st.zoom = Math.max(0.3, Math.min(4, (area.clientWidth - 40) / base.width));
        return st.zoom;
    }
    function botoes() {
        var n = st.pdf ? st.pdf.numPages : 0;
        $('[data-vp-pagina]').value = st.pagina; $('[data-vp-pagina]').max = n; $('[data-vp-total]').textContent = n || '–';
        $('[data-vp-anterior]').disabled = $('[data-vp-primeira]').disabled = st.pagina <= 1;
        $('[data-vp-proxima]').disabled = $('[data-vp-ultima]').disabled = st.pagina >= n;
        $('[data-vp-zoom]').textContent = Math.round(st.zoom * 100) + '%';
        $('[data-vp-ant-ocorr]').disabled = $('[data-vp-prox-ocorr]').disabled = st.ocorr.length === 0;
        var c = $('[data-vp-contagem]');
        if (!st.termo) c.textContent = '';
        else if (!st.ocorr.length) c.textContent = T.nenhuma;
        else c.textContent = (st.atual >= 0 ? fmt(T.ocorrencia, { i: st.atual + 1, n: st.ocorr.length }) + ' · ' : '') + (st.ocorr.length === 1 ? T.encontrada : fmt(T.encontradas, { n: st.ocorr.length }));
    }
    function desenhar() {
        if (!st.pdf) return;
        var vez = ++st.vez;
        st.pdf.getPage(st.pagina).then(function (p) {
            var vp = p.getViewport({ scale: escalaPara(p) }), ctx = canvas.getContext('2d'), dpr = window.devicePixelRatio || 1;
            canvas.width = Math.floor(vp.width * dpr); canvas.height = Math.floor(vp.height * dpr);
            canvas.style.width = Math.floor(vp.width) + 'px'; canvas.style.height = Math.floor(vp.height) + 'px';
            return p.render({ canvasContext: ctx, viewport: vp, transform: dpr !== 1 ? [dpr, 0, 0, dpr, 0, 0] : null }).promise.then(function () {
                if (vez !== st.vez) return;
                folha.hidden = false; msg.hidden = true; marcar(p, vp); botoes();
            });
        }).catch(function () { msg.textContent = T.erro; msg.hidden = false; });
    }
    // Destaques da busca na página atual: cada trecho de texto do PDF que contém o termo
    function marcar(p, vp) {
        marcas.innerHTML = '';
        if (!st.termo) return;
        var itens = st.itens[st.pagina] || [], termo = st.termo, contador = 0;
        var atual = st.atual >= 0 ? st.ocorr[st.atual] : null, primeira = null;
        itens.forEach(function (it, idx) {
            var texto = norm(it.str), pos = 0, w = it.width * vp.scale;
            while ((pos = texto.indexOf(termo, pos)) !== -1) {
                var tx = window.pdfjsLib.Util.transform(vp.transform, it.transform), h = Math.hypot(tx[2], tx[3]) || 10;
                var len = Math.max(1, texto.length), x = tx[4] + w * (pos / len), largura = Math.max(4, w * (termo.length / len));
                var s = document.createElement('span');
                s.style.left = x + 'px'; s.style.top = (tx[5] - h) + 'px'; s.style.width = largura + 'px'; s.style.height = (h * 1.15) + 'px';
                if (atual && atual.pagina === st.pagina && atual.item === idx && atual.pos === pos) { s.className = 'atual'; primeira = s; }
                marcas.appendChild(s); contador++; pos += termo.length;
            }
        });
        if (primeira) primeira.scrollIntoView({ block: 'center', inline: 'nearest' });
    }
    function irPara(n) { if (!st.pdf) return; n = Math.max(1, Math.min(st.pdf.numPages, n | 0)); if (n !== st.pagina) { st.pagina = n; desenhar(); } else botoes(); }
    function buscar() {
        st.termo = norm($('[data-vp-busca]').value.trim()); st.ocorr = []; st.atual = -1;
        if (st.termo) Object.keys(st.itens).map(Number).sort(function (a, b) { return a - b; }).forEach(function (pg) {
            st.itens[pg].forEach(function (it, idx) { var t = norm(it.str), pos = 0; while ((pos = t.indexOf(st.termo, pos)) !== -1) { st.ocorr.push({ pagina: pg, item: idx, pos: pos }); pos += st.termo.length; } });
        });
        if (st.ocorr.length) { var i = st.ocorr.findIndex(function (o) { return o.pagina >= st.pagina; }); irOcorr(i < 0 ? 0 : i); } else { desenhar(); botoes(); }
    }
    function irOcorr(i) {
        if (!st.ocorr.length) return;
        st.atual = (i + st.ocorr.length) % st.ocorr.length;
        var o = st.ocorr[st.atual];
        if (o.pagina !== st.pagina) { st.pagina = o.pagina; }
        desenhar();
    }
    var tempo = 0;
    $('[data-vp-busca]').addEventListener('input', function () { clearTimeout(tempo); tempo = setTimeout(buscar, 300); });
    $('[data-vp-busca]').addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); clearTimeout(tempo); if (norm(this.value.trim()) !== st.termo) buscar(); else irOcorr(st.atual + (ev.shiftKey ? -1 : 1)); } });
    $('[data-vp-prox-ocorr]').addEventListener('click', function () { irOcorr(st.atual + 1); });
    $('[data-vp-ant-ocorr]').addEventListener('click', function () { irOcorr(st.atual - 1); });
    $('[data-vp-primeira]').addEventListener('click', function () { irPara(1); });
    $('[data-vp-anterior]').addEventListener('click', function () { irPara(st.pagina - 1); });
    $('[data-vp-proxima]').addEventListener('click', function () { irPara(st.pagina + 1); });
    $('[data-vp-ultima]').addEventListener('click', function () { if (st.pdf) irPara(st.pdf.numPages); });
    $('[data-vp-pagina]').addEventListener('change', function () { irPara(parseInt(this.value, 10) || 1); });
    $('[data-vp-mais]').addEventListener('click', function () { st.larguraAuto = false; st.zoom = Math.min(4, st.zoom * 1.2); desenhar(); });
    $('[data-vp-menos]').addEventListener('click', function () { st.larguraAuto = false; st.zoom = Math.max(0.3, st.zoom / 1.2); desenhar(); });
    $('[data-vp-largura]').addEventListener('click', function () { st.larguraAuto = true; desenhar(); });
    document.addEventListener('keydown', function (ev) {
        if (ev.target && /^(INPUT|TEXTAREA)$/.test(ev.target.tagName)) return;
        if (ev.key === 'PageDown' || ev.key === 'ArrowRight') irPara(st.pagina + 1);
        if (ev.key === 'PageUp' || ev.key === 'ArrowLeft') irPara(st.pagina - 1);
        if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'f') { ev.preventDefault(); $('[data-vp-busca]').focus(); }
    });
    var redim = 0; window.addEventListener('resize', function () { clearTimeout(redim); redim = setTimeout(function () { if (st.larguraAuto) desenhar(); }, 200); });
    carregarPdfJs().then(function (pdfjs) { return pdfjs.getDocument(SRC).promise; }).then(function (pdf) {
        st.pdf = pdf; desenhar();
        // textos de todas as páginas para a busca (em segundo plano)
        var n = pdf.numPages, feitos = 0;
        for (var i = 1; i <= n; i++) (function (pg) {
            pdf.getPage(pg).then(function (p) { return p.getTextContent(); }).then(function (c) { st.itens[pg] = c.items.filter(function (it) { return it.str; }); }).catch(function () { st.itens[pg] = []; })
                .then(function () { if (++feitos === n && st.termo) buscar(); });
        }(i));
    }).catch(function () { msg.textContent = T.erro; });
}());
</script>
</body>
</html>
