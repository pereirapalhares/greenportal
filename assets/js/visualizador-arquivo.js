/* Visualizador de arquivos do GED (principal/visualizador_arquivo.php).
   pdf: PDF.js (páginas em canvas) · imagem: <img> · texto: lido aqui (CSV em tabela, JSON/XML formatados) · áudio/vídeo: player
   previa: pede a conversão ao servidor (api/gestao_arquivos.php action=previa_estado) e acompanha a fila a cada 3 s; o
   serviço agendado converte (Word/PowerPoint/TIFF/DWG -> PDF, planilhas -> HTML mostrado aqui em abas).
   Sem a permissão "Baixar/imprimir arquivos": sem botões, Ctrl+S / Ctrl+P bloqueados, sem menu de contexto e a impressão
   sai em branco (CSS). */
(function () {
    'use strict';
    var VA = window.VA || {}, T = VA.textos || {};
    var body = document.body, area = document.querySelector('[data-va-area]');
    var formato = body.getAttribute('data-formato');
    var ext = String(VA.extensao || '').toLowerCase();
    var NIVEIS = [25, 50, 75, 100, 125, 150, 175, 200, 250, 300, 400];
    var zoom = Number(body.getAttribute('data-zoom')) || 100;   // 100 = largura da área
    var pdf = null, renderizando = 0, modo = formato;            // modo: pdf | imagem | texto | planilha
    var LIMITE_TEXTO = 5 * 1024 * 1024, LIMITE_LINHAS = 5000;

    function fmt(txt, vars) { return String(txt || '').replace(/\{(\w+)\}/g, function (m, k) { return vars && vars[k] != null ? vars[k] : m; }); }
    function aviso(texto) {
        var a = document.querySelector('[data-va-aviso]');
        if (!a) return;
        a.textContent = texto; a.hidden = false;
        clearTimeout(aviso.t); aviso.t = setTimeout(function () { a.hidden = true; }, 4000);
    }
    function faixa(texto) {
        var f = document.querySelector('[data-va-faixa]');
        if (!f) return;
        f.textContent = texto; f.hidden = !texto;
    }
    function mensagem(html, erro) {
        var m = document.querySelector('[data-va-mensagem]');
        if (!m) { m = document.createElement('div'); m.className = 'va-mensagem'; m.setAttribute('data-va-mensagem', ''); area.appendChild(m); }
        m.hidden = false;
        m.classList.toggle('va-mensagem-erro', !!erro);
        m.innerHTML = html;
        return m;
    }
    function esc(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }
    function tamanho(b) { return b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.round(b / 1024) + ' KB'; }

    // Proteção (sem permissão)
    if (!VA.baixar) {
        document.addEventListener('contextmenu', function (e) { e.preventDefault(); });
        document.addEventListener('dragstart', function (e) { e.preventDefault(); });
        document.addEventListener('copy', function (e) { e.preventDefault(); });
        document.addEventListener('keydown', function (e) {
            var k = String(e.key || '').toLowerCase();
            if ((e.ctrlKey || e.metaKey) && (k === 's' || k === 'p' || k === 'u')) { e.preventDefault(); e.stopPropagation(); aviso(T.bloqueado); }
        }, true);
        window.addEventListener('beforeprint', function () { aviso(T.bloqueado); });
    } else {
        var imprimir = document.querySelector('[data-va-imprimir]');
        if (imprimir) imprimir.addEventListener('click', function () { window.print(); });
    }

    function mostrarZoom() { var z = document.querySelector('[data-va-zoom-valor]'); if (z) z.textContent = zoom + '%'; }
    // 100% = largura da área, limitada a uma folha (900 px) para o documento não ficar enorme em telas largas
    function larguraUtil() { return Math.max(200, Math.min(900, area.clientWidth - 24 - 16)); }

    // ------------------------------------------------------------------ imagem
    function zoomImagem() {
        var img = document.querySelector('[data-va-imagem]');
        if (!img || !img.naturalWidth) return;
        var base = Math.min(larguraUtil(), img.naturalWidth);
        img.style.width = Math.round(base * zoom / 100) + 'px';
        mostrarZoom();
    }

    // ------------------------------------------------------------------ PDF
    function carregarPdfJs() {
        if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
        return new Promise(function (ok, erro) {
            var s = document.createElement('script');
            s.src = '../assets/vendor/pdfjs/pdf.min.js?v=2';
            s.onload = function () {
                if (!window.pdfjsLib) return erro(new Error('pdf.js'));
                window.pdfjsLib.GlobalWorkerOptions.workerSrc = '../assets/vendor/pdfjs/pdf.worker.min.js?v=2';
                ok(window.pdfjsLib);
            };
            s.onerror = erro;
            document.head.appendChild(s);
        });
    }
    function desenharPdf() {
        if (!pdf) return;
        var vez = ++renderizando, caixa = document.querySelector('[data-va-pdf]'), largura = larguraUtil();
        var dpr = Math.min(2, window.devicePixelRatio || 1);
        caixa.innerHTML = '';
        mostrarZoom();
        var pagina = function (n) {
            if (vez !== renderizando || n > pdf.numPages) return Promise.resolve();
            return pdf.getPage(n).then(function (p) {
                var v1 = p.getViewport({ scale: 1 }), escala = (largura / v1.width) * zoom / 100;
                var v = p.getViewport({ scale: escala * dpr });
                var c = document.createElement('canvas');
                c.width = Math.floor(v.width); c.height = Math.floor(v.height);
                c.style.width = Math.floor(v.width / dpr) + 'px'; c.style.height = Math.floor(v.height / dpr) + 'px';
                c.setAttribute('aria-label', (T.pagina || 'Página') + ' ' + n);
                caixa.appendChild(c);
                return p.render({ canvasContext: c.getContext('2d'), viewport: v }).promise;
            }).then(function () { return pagina(n + 1); });
        };
        return pagina(1);
    }
    function abrirPdf(url) {
        modo = 'pdf';
        mensagem(esc(T.carregando));
        return carregarPdfJs()
            .then(function (lib) { return lib.getDocument({ url: url, withCredentials: true }).promise; })
            .then(function (d) {
                pdf = d;
                var pg = document.querySelector('[data-va-paginas]');
                if (pg) { pg.textContent = pdf.numPages + ' ' + (T.pagina || 'Página').toLowerCase() + (pdf.numPages > 1 ? 's' : ''); pg.hidden = false; }
                document.querySelector('[data-va-mensagem]').hidden = true;
                return desenharPdf();
            })
            .catch(function () { mensagem(esc(T.erro), true); });
    }

    // ------------------------------------------------------------------ texto
    function decodificar(buf) {
        var bytes = new Uint8Array(buf), txt;
        if (bytes.length >= 2 && bytes[0] === 0xFF && bytes[1] === 0xFE) return new TextDecoder('utf-16le').decode(bytes.subarray(2));
        if (bytes.length >= 2 && bytes[0] === 0xFE && bytes[1] === 0xFF) return new TextDecoder('utf-16be').decode(bytes.subarray(2));
        try { txt = new TextDecoder('utf-8', { fatal: true }).decode(bytes); }
        catch (e) {
            // corte no meio de um caractere (arquivo grande) não é motivo para trocar a codificação
            try { txt = new TextDecoder('utf-8', { fatal: true }).decode(bytes.subarray(0, Math.max(0, bytes.length - 4))); }
            catch (e2) { txt = new TextDecoder('windows-1252').decode(bytes); }
        }
        return txt.charAt(0) === '﻿' ? txt.slice(1) : txt;
    }
    function lerCsv(txt) {
        var primeira = txt.slice(0, txt.indexOf('\n') > 0 ? txt.indexOf('\n') : 2000);
        var sep = ext === 'tsv' ? '\t' : [';', ',', '\t', '|'].sort(function (a, b) { return primeira.split(b).length - primeira.split(a).length; })[0];
        var linhas = [], linha = [], campo = '', aspas = false, i, c;
        for (i = 0; i < txt.length; i++) {
            c = txt.charAt(i);
            if (aspas) {
                if (c === '"') { if (txt.charAt(i + 1) === '"') { campo += '"'; i++; } else aspas = false; }
                else campo += c;
            } else if (c === '"' && campo === '') aspas = true;
            else if (c === sep) { linha.push(campo); campo = ''; }
            else if (c === '\n' || c === '\r') {
                if (c === '\r' && txt.charAt(i + 1) === '\n') i++;
                linha.push(campo); campo = ''; linhas.push(linha); linha = [];
                if (linhas.length > LIMITE_LINHAS) break;
            } else campo += c;
        }
        if (campo !== '' || linha.length) { linha.push(campo); linhas.push(linha); }
        return linhas;
    }
    function tabelaCsv(linhas) {
        var t = document.createElement('table'), cab = document.createElement('thead'), corpo = document.createElement('tbody');
        t.className = 'va-tabela';
        var colunas = 0; linhas.forEach(function (l) { colunas = Math.max(colunas, l.length); });
        linhas.slice(0, LIMITE_LINHAS + 1).forEach(function (l, n) {
            var tr = document.createElement('tr'), num = document.createElement(n === 0 ? 'th' : 'td');
            num.className = 'va-num'; num.textContent = n === 0 ? '' : String(n); tr.appendChild(num);
            for (var i = 0; i < colunas; i++) { var cel = document.createElement(n === 0 ? 'th' : 'td'); cel.textContent = l[i] == null ? '' : l[i]; tr.appendChild(cel); }
            (n === 0 ? cab : corpo).appendChild(tr);
        });
        t.appendChild(cab); t.appendChild(corpo);
        return t;
    }
    function xmlFormatado(txt) {
        if (/\n\s*</.test(txt.slice(0, 4000))) return txt;      // já tem quebras de linha
        var nivel = 0, saida = [];
        txt.replace(/>\s*</g, '>\n<').split('\n').forEach(function (l) {
            if (/^<\/[^>]+>/.test(l)) nivel = Math.max(0, nivel - 1);
            saida.push(new Array(nivel + 1).join('  ') + l);
            if (/^<[^!?\/][^>]*[^\/]>$/.test(l) && !/<\/[^>]+>$/.test(l)) nivel++;
        });
        return saida.join('\n');
    }
    function zoomTexto() {
        var c = document.querySelector('[data-va-texto]') || document.querySelector('[data-va-planilha]');
        if (c) c.style.fontSize = (13 * zoom / 100).toFixed(1) + 'px';
        mostrarZoom();
    }
    function abrirTexto() {
        modo = 'texto';
        var caixa = document.querySelector('[data-va-texto]');
        fetch(VA.url, { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.arrayBuffer(); })
            .then(function (buf) {
                var cortado = buf.byteLength > LIMITE_TEXTO;
                var txt = decodificar(cortado ? buf.slice(0, LIMITE_TEXTO) : buf);
                document.querySelector('[data-va-mensagem]').hidden = true;
                if (cortado) faixa(fmt(T.texto_grande, { tamanho: tamanho(LIMITE_TEXTO) }) + ' (' + tamanho(buf.byteLength) + ')');
                if (ext === 'csv' || ext === 'tsv') {
                    var linhas = lerCsv(txt);
                    caixa.appendChild(tabelaCsv(linhas));
                    caixa.classList.add('va-texto-tabela');
                    if (linhas.length > LIMITE_LINHAS) faixa(fmt(T.linhas_limitadas, { n: LIMITE_LINHAS.toLocaleString('pt-BR') }));
                } else {
                    if (ext === 'json' || ext === 'geojson') { try { txt = JSON.stringify(JSON.parse(txt), null, 2); } catch (e) { /* mostra como veio */ } }
                    else if (['xml', 'kml', 'gpx', 'html', 'htm'].indexOf(ext) >= 0) txt = xmlFormatado(txt);
                    var pre = document.createElement('pre');
                    pre.className = 'va-pre';
                    pre.textContent = txt;
                    caixa.appendChild(pre);
                    var rotulo = document.querySelector('[data-va-quebra-rotulo]'), quebra = document.querySelector('[data-va-quebra]');
                    if (rotulo && quebra) {
                        rotulo.hidden = false;
                        quebra.addEventListener('change', function () { pre.classList.toggle('va-pre-sem-quebra', !quebra.checked); });
                    }
                }
                zoomTexto();
            })
            .catch(function () { mensagem(esc(T.erro), true); });
    }

    // ------------------------------------------------------------------ planilha (HTML do LibreOffice -> abas)
    var PERMITIDOS = { TABLE: 1, COLGROUP: 1, COL: 1, THEAD: 1, TBODY: 1, TFOOT: 1, TR: 1, TD: 1, TH: 1, FONT: 1, B: 1, I: 1, U: 1, S: 1, STRIKE: 1, BR: 1, SPAN: 1, P: 1, EM: 1, STRONG: 1, SUB: 1, SUP: 1, A: 1, DIV: 1 };
    var ATRIBUTOS = { colspan: 1, rowspan: 1, align: 1, valign: 1, bgcolor: 1, color: 1, width: 1, height: 1, span: 1, face: 1 };
    function limpar(no) {
        Array.prototype.slice.call(no.children).forEach(function (el) {
            if (!PERMITIDOS[el.tagName]) { el.replaceWith(document.createTextNode(el.textContent || '')); return; }
            Array.prototype.slice.call(el.attributes).forEach(function (a) { if (!ATRIBUTOS[a.name.toLowerCase()]) el.removeAttribute(a.name); });
            limpar(el);
        });
        return no;
    }
    function abrirPlanilha(url) {
        modo = 'planilha';
        var caixa = document.querySelector('[data-va-planilha]');
        mensagem(esc(T.carregando));
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.arrayBuffer(); })
            .then(function (buf) {
                var doc = new DOMParser().parseFromString(decodificar(buf), 'text/html');   // documento inerte: nada é executado
                var tabelas = Array.prototype.slice.call(doc.querySelectorAll('table'));
                var titulos = Array.prototype.slice.call(doc.querySelectorAll('h1 em'));
                document.querySelector('[data-va-mensagem]').hidden = true;
                if (!tabelas.length) { mensagem(esc(T.sem_tabelas)); return; }
                var abas = document.createElement('div'), conteudo = document.createElement('div');
                abas.className = 'va-abas'; abas.setAttribute('role', 'tablist');
                conteudo.className = 'va-planilha-conteudo';
                tabelas.forEach(function (tab, i) {
                    var b = document.createElement('button'), painel = document.createElement('div');
                    b.type = 'button'; b.className = 'va-aba'; b.setAttribute('role', 'tab');
                    b.textContent = (titulos[i] && titulos[i].textContent.trim()) || ((T.aba || 'Planilha') + ' ' + (i + 1));
                    painel.className = 'va-aba-painel'; painel.hidden = i > 0;
                    var t = limpar(document.importNode(tab, true));
                    t.removeAttribute('border'); t.className = 'va-tabela va-tabela-planilha';
                    painel.appendChild(t);
                    b.addEventListener('click', function () {
                        abas.querySelectorAll('.va-aba').forEach(function (x) { x.classList.remove('ativa'); x.setAttribute('aria-selected', 'false'); });
                        conteudo.querySelectorAll('.va-aba-painel').forEach(function (x) { x.hidden = true; });
                        b.classList.add('ativa'); b.setAttribute('aria-selected', 'true'); painel.hidden = false;
                    });
                    if (i === 0) { b.classList.add('ativa'); b.setAttribute('aria-selected', 'true'); }
                    abas.appendChild(b); conteudo.appendChild(painel);
                });
                if (tabelas.length > 1) caixa.appendChild(abas);
                caixa.appendChild(conteudo);
                caixa.hidden = false;
                zoomTexto();
            })
            .catch(function () { mensagem(esc(T.erro), true); });
    }

    // ------------------------------------------------------------------ prévia (conversão no servidor)
    function urlComArquivo(base, arquivo, acao) {
        var i = base.indexOf('?'), p = new URLSearchParams(base.slice(i + 1));
        p.set('arquivo', arquivo); p.set('action', acao);
        return base.slice(0, i + 1) + p.toString();
    }
    var inicioPrevia = Date.now();
    function acompanharPrevia() {
        fetch(VA.estado, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, message: T.erro }; }); })
            .then(function (j) {
                if (!j || !j.ok) { mensagem('<i class="bi bi-exclamation-triangle" aria-hidden="true"></i><br>' + esc((j && j.message) || T.erro), true); return; }
                if (j.pronto) {
                    if (j.original && j.arquivo_pdf) { faixa(fmt(T.pdf_original, { arquivo: j.arquivo_pdf })); return abrirPdf(urlComArquivo(VA.url, j.arquivo_pdf, 'visualizar')); }
                    faixa(VA.cad ? T.previa_cad : T.previa_office);
                    return j.alvo === 'html' ? abrirPlanilha(VA.previa) : abrirPdf(VA.previa);
                }
                if (j.status === 'ERRO') {
                    mensagem('<i class="bi bi-exclamation-triangle" aria-hidden="true"></i><br>' + esc(T.erro_conversao)
                        + (j.erro ? '<br><small class="va-detalhe">' + esc(j.erro) + '</small>' : '')
                        + '<br>' + esc(VA.baixar ? T.sem_formato_baixar : T.sem_formato_permissao), true);
                    return;
                }
                var html = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> ' + esc(T.preparando)
                    + '<br><small class="va-detalhe">' + esc(T.preparando_detalhe) + '</small>';
                if (j.status === 'PROCESSANDO') html += '<br><small class="va-detalhe">' + esc(T.convertendo) + '</small>';
                else if (j.na_frente > 0) html += '<br><small class="va-detalhe">' + esc(fmt(T.fila, { n: j.na_frente })) + '</small>';
                if (j.status === 'PENDENTE' && j.espera > 150) html += '<div class="va-alerta">' + esc(T.parado) + '</div>';
                mensagem(html);
                if (Date.now() - inicioPrevia < 20 * 60 * 1000) setTimeout(acompanharPrevia, 3000);
            })
            .catch(function () { if (Date.now() - inicioPrevia < 20 * 60 * 1000) setTimeout(acompanharPrevia, 6000); });
    }

    // ------------------------------------------------------------------ zoom
    function aplicarZoom() {
        if (modo === 'pdf') desenharPdf();
        else if (modo === 'imagem') zoomImagem();
        else zoomTexto();
    }
    document.querySelectorAll('[data-va-zoom]').forEach(function (b) {
        b.addEventListener('click', function () {
            var d = b.getAttribute('data-va-zoom');
            if (d === '0') zoom = 100;
            else {
                var i = NIVEIS.indexOf(zoom); if (i < 0) i = NIVEIS.indexOf(100);
                zoom = NIVEIS[Math.max(0, Math.min(NIVEIS.length - 1, i + (d === '+' ? 1 : -1)))];
            }
            aplicarZoom();
        });
    });
    area.addEventListener('wheel', function (e) {
        if (!e.ctrlKey) return;
        e.preventDefault();
        var b = document.querySelector('[data-va-zoom="' + (e.deltaY < 0 ? '+' : '-') + '"]'); if (b) b.click();
    }, { passive: false });
    var tempo = null;
    window.addEventListener('resize', function () { clearTimeout(tempo); tempo = setTimeout(function () { if (modo === 'pdf') desenharPdf(); else if (modo === 'imagem') zoomImagem(); }, 200); });

    // ------------------------------------------------------------------ início
    if (formato === 'imagem') {
        var img = document.querySelector('[data-va-imagem]');
        if (img.complete && img.naturalWidth) zoomImagem(); else img.addEventListener('load', zoomImagem);
        img.addEventListener('error', function () { area.innerHTML = '<div class="va-mensagem"></div>'; area.firstChild.textContent = T.erro; });
    } else if (formato === 'pdf') {
        abrirPdf(VA.url);
    } else if (formato === 'texto') {
        abrirTexto();
    } else if (formato === 'previa') {
        acompanharPrevia();
    } else if (formato === 'audio' || formato === 'video') {
        var midia = document.querySelector('audio, video');
        if (midia) midia.addEventListener('error', function () { mensagem(esc(T.erro) + '<br>' + esc(VA.baixar ? T.sem_formato_baixar : T.sem_formato_permissao), true); });
    }
}());
