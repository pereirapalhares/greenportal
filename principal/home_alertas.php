<?php
declare(strict_types=1);

/*
 * Home › Alertas (aba da Home, home.php): alertas do usuário na empresa atual (vw_alertas_grid, classes/AlertasService.php).
 * c454: padrão de grid do portal (components/grid_local.php + assets/js/grid-local.js) — barra de ícones e Ajuda, busca,
 * filtro e ordenação por coluna, paginação na barra, datas no formato do idioma; ações por ícone (status da exportação,
 * resultado da IA, baixar Excel), que marcam o alerta como lido (alertas_lido.php).
 * A situação da análise de IA vem da view vw_ged_analise_ia_ultima (migração 312), não mais de SQL direto.
 * c488: as ações deixaram de ser coluna — são botões da barra (Abrir, Mostrar resultado, Baixar Excel, Ver status), habilitados
 * conforme a linha selecionada; duplo clique executa a ação principal. Células no formato do grid padrão e botão Colunas
 * (mesmo padrão da Agenda e de Minhas pendências).
 */

require_once dirname(__DIR__) . '/classes/IdiomaService.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) { http_response_code(401); exit(IdiomaService::t('home.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.'])); }
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/AlertasService.php';
require_once dirname(__DIR__) . '/components/grid_local.php';
$pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), (string)$_SESSION['tenant']['banco_dados'], 'GPT');
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { http_response_code(401); exit(IdiomaService::t('home.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.'])); }
$idioma = IdiomaService::idioma();
$baseUrl = rtrim(str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/principal/home_alertas.php')))), '/');
$e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$t = static fn(string $chave, array $padrao): string => IdiomaService::t('home.alertas.' . $chave, $padrao);
$quando = static function (mixed $valor): ?DateTimeImmutable { $valor = trim((string)$valor); if ($valor === '') return null; try { return new DateTimeImmutable($valor); } catch (Throwable) { return null; } };
$formatar = static fn(?DateTimeImmutable $d): string => $d ? $d->format($idioma === 'en' ? 'm/d/Y H:i' : 'd/m/Y H:i') : '';

$alerts = [];
try { $alerts = (new AlertasService($pdo, (int)($_SESSION['usuario']['codusuario'] ?? 0)))->listar(500); } catch (Throwable) {}
// Situação da última análise de IA do arquivo do alerta (CONCLUIDA = aguardando a confirmação do usuário).
$semView = false;
$iaPending = static function (array $alert) use ($pdo, &$semView): bool {
    if (!preg_match('/gestao_arquivos\.php/i', (string)($alert['link'] ?? ''))) return false;
    $url = parse_url((string)$alert['link']); parse_str((string)($url['query'] ?? ''), $q);
    if (empty($q['tipo']) || empty($q['valor']) || empty($q['arquivo'])) return false;
    try {
        $rows = GreenPortalDatabase::call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,5,0)', ['tabela' => 'vw_ged_analise_ia_ultima', 'filtros' => json_encode([
            'TIPO' => ['value' => (string)$q['tipo'], 'exact' => true], 'CODIGO_REGISTRO' => ['value' => (string)(int)$q['valor'], 'exact' => true], 'ARQUIVO' => ['value' => (string)$q['arquivo'], 'exact' => true],
        ], JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable) { $semView = true; return false; }
    foreach ($rows as $r) if ((string)$r['TIPO'] === (string)$q['tipo'] && (int)$r['CODIGO_REGISTRO'] === (int)$q['valor'] && (string)$r['ARQUIVO'] === (string)$q['arquivo']) return strtoupper((string)$r['STATUS']) === 'CONCLUIDA';
    return false;
};

$txt = [
    'novo' => $t('situacao.novo', ['pt' => 'Novo', 'en' => 'New', 'es' => 'Nuevo']),
    'lido' => $t('situacao.lido', ['pt' => 'Lido', 'en' => 'Read', 'es' => 'Leído']),
    'confirmar' => $t('situacao.confirmar', ['pt' => 'Aguardando sua confirmação', 'en' => 'Awaiting your confirmation', 'es' => 'Esperando su confirmación']),
    'status' => $t('acao.status', ['pt' => 'Ver status', 'en' => 'View status', 'es' => 'Ver estado']),
    'resultado' => $t('acao.resultado', ['pt' => 'Mostrar resultado', 'en' => 'Show result', 'es' => 'Mostrar resultado']),
    'abrir' => $t('acao.abrir', ['pt' => 'Abrir', 'en' => 'Open', 'es' => 'Abrir']),
    'excel' => $t('acao.excel', ['pt' => 'Baixar Excel', 'en' => 'Download Excel', 'es' => 'Descargar Excel']),
];
$botao = static fn(string $icone, string $titulo, string $atributos, string $classe = 'btn-outline-primary'): string => '<button type="button" class="btn btn-sm ' . $classe . ' grid-crud-button" ' . $atributos . ' title="' . $e($titulo) . '" aria-label="' . $e($titulo) . '"><i class="bi ' . $icone . '"></i></button>';
$rows = [];
foreach ($alerts as $alert) {
    $link = (string)($alert['link'] ?? ''); $linkStatus = (string)($alert['link_status'] ?? '');
    $titulo = (string)($alert['titulo'] ?? $alert['assunto'] ?? $t('alerta', ['pt' => 'Alerta', 'en' => 'Alert', 'es' => 'Alerta']));
    $mensagem = (string)($alert['mensagem'] ?? $alert['descricao'] ?? '');
    $exportacao = (bool)preg_match('/exporta|excel|planilha/i', $titulo . ' ' . $mensagem);
    $ia = (bool)preg_match('/Análise de IA|Analise de IA|AI analysis|Análisis de IA|gestao_arquivos\.php|ia_potenciais_resultado\.php/i', $titulo . ' ' . $link);
    $pendente = $ia && $iaPending($alert);
    $alvo = str_starts_with($link, '/') ? $baseUrl . $link : $link;
    $alvoStatus = str_starts_with($linkStatus, '/') ? $baseUrl . $linkStatus : $linkStatus;
    $lido = (int)($alert['lido'] ?? 0) === 1;
    $situacao = $pendente ? $txt['confirmar'] : ($lido ? $txt['lido'] : $txt['novo']);
    $d = $quando($alert['criado_em'] ?? $alert['data_criacao'] ?? '');
    // Ação de cada alerta, guardada na linha; os botões da barra usam a da linha selecionada.
    $acaoAttrs = '';
    if ($alvoStatus !== '') $acaoAttrs .= ' data-acao-status="' . $e($alvoStatus) . '"';
    if ($alvo !== '' && $ia) $acaoAttrs .= ' data-acao-resultado="' . $e($alvo) . '"';
    elseif ($alvo !== '' && $exportacao) $acaoAttrs .= ' data-acao-excel="' . $e($alvo) . '"';
    $abrirUrl = $alvo !== '' && !$ia && !$exportacao ? $alvo : '';
    $rows[] = ['attrs' => 'data-codalerta="' . (int)($alert['codalerta'] ?? 0) . '" data-alerta-titulo="' . $e($titulo) . '"' . $acaoAttrs, 'open' => ['url' => $abrirUrl, 'title' => $titulo], 'cells' => [
        'DATA' => ['text' => $formatar($d), 'sort' => $d ? $d->format('Y-m-d H:i:s') : ''],
        'ALERTA' => ['text' => $titulo, 'title' => $titulo, 'html' => ($lido ? '' : '<i class="bi bi-circle-fill text-success me-1" style="font-size:7px;vertical-align:middle" aria-hidden="true"></i>') . $e($titulo)],
        'MENSAGEM' => ['text' => $mensagem],
        'SITUACAO' => ['text' => $situacao, 'html' => $pendente ? '<span class="text-warning-emphasis fw-semibold"><i class="bi bi-exclamation-circle-fill me-1"></i>' . $e($situacao) . '</span>' : '<span data-alerta-situacao>' . $e($situacao) . '</span>'],
    ]];
}
?>
<!doctype html><html lang="<?= $idioma === 'pt' ? 'pt-BR' : $e($idioma) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php greenPortalGridLocalHead(); ?>
<style>.home-alertas-grid-fragment th[data-column="DATA"]{width:130px}.home-alertas-grid-fragment th[data-column="ALERTA"]{width:28%}.home-alertas-grid-fragment th[data-column="SITUACAO"]{width:170px}
.alert-status-progress{height:8px;background:var(--gp-primary-soft,#e7f4ee);border-radius:8px;overflow:hidden;margin:10px 0}.alert-status-progress span{display:block;height:100%;background:var(--gp-primary,#27815f)}</style>
</head><body data-transacao="home_alertas">
<?php greenPortalGridLocal([
    'transaction' => 'home_alertas', 'fragment_class' => 'home-alertas-grid-fragment', 'id' => 'homeAlertasGrid',
    'columns' => [
        'DATA' => ['label' => $t('coluna.data', ['pt' => 'Data', 'en' => 'Date', 'es' => 'Fecha'])],
        'ALERTA' => ['label' => $t('coluna.alerta', ['pt' => 'Alerta', 'en' => 'Alert', 'es' => 'Alerta'])],
        'MENSAGEM' => ['label' => $t('coluna.mensagem', ['pt' => 'Mensagem', 'en' => 'Message', 'es' => 'Mensaje'])],
        'SITUACAO' => ['label' => $t('coluna.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']), 'filter' => 'select'],
    ],
    'open_button' => true, 'open_in_tab' => true, 'cell_boxes' => true, 'buttons' => ['columns' => true],
    'extras' => $botao('bi-eye', $txt['resultado'], 'data-alerta-barra="resultado" disabled') . $botao('bi-file-earmark-excel', $txt['excel'], 'data-alerta-barra="excel" disabled', 'btn-outline-success')
        . $botao('bi-hourglass-split', $txt['status'], 'data-alerta-barra="status" disabled', 'btn-outline-secondary'),
    'rows' => $rows, 'default_sort' => ['DATA', 'desc'],
    'empty' => $t('vazio', ['pt' => 'Nenhum alerta pendente.', 'en' => 'No pending alerts.', 'es' => 'No hay alertas pendientes.']),
    'notice' => $semView ? $t('migracao312', ['pt' => 'A situação das análises de IA não pôde ser lida: execute a migração 312.', 'en' => 'The AI analysis status could not be read: run migration 312.', 'es' => 'No se pudo leer la situación de los análisis de IA: ejecute la migración 312.']) : '',
]); ?>
<?php greenPortalGridLocalScripts(); ?>
<script>
(function ($) {
    var language = <?= json_encode($idioma) ?>, lido = <?= json_encode($txt['lido']) ?>, base = <?= json_encode($baseUrl) ?>;
    function L(pt, en, es) { return language === 'en' ? en : (language === 'es' ? es : pt); }
    // Abrir uma ação do alerta marca o alerta como lido (o mesmo do sino do cabeçalho).
    function marcarLido($row) {
        var cod = Number($row.attr('data-codalerta') || 0);
        if (!cod || $row.data('lido')) return;
        $row.data('lido', 1); $row.find('[data-alerta-situacao]').text(lido); $row.find('td[data-column="ALERTA"] .bi-circle-fill').remove();
        $.ajax({ url: base + '/principal/alertas_lido.php', method: 'POST', dataType: 'json', global: false, data: { codalerta: cod } });
    }
    function selecionada() { return $('#homeAlertasGrid tbody tr[data-row].selected').first(); }
    // Botões da barra: habilitados conforme a ação da linha selecionada (c488).
    function atualizarBarra($row) {
        $('[data-alerta-barra]').each(function () { var tem = !!($row && $row.attr('data-acao-' + $(this).attr('data-alerta-barra'))); $(this).prop('disabled', !tem); });
    }
    $(document).on('click', '#homeAlertasGrid tbody tr[data-row]', function () { atualizarBarra($(this)); });
    $(document).on('click', '[data-grid-abrir-selecionado]', function () { marcarLido(selecionada()); });
    $(document).on('click', '[data-alerta-barra]', function (ev) {
        ev.preventDefault();
        var $row = selecionada(), acao = $(this).attr('data-alerta-barra'), url = $row.attr('data-acao-' + acao);
        if (!$row.length || !url) return;
        marcarLido($row);
        if (acao === 'resultado') mostrarResultado(url, $row.attr('data-alerta-titulo') || '');
        else if (acao === 'status') verStatus(url);
        else if (acao === 'excel') { var a = document.createElement('a'); a.href = url; a.setAttribute('download', ''); document.body.appendChild(a); a.click(); a.remove(); }
    });
    // Duplo clique: Abrir (grid-local.js) ou, sem link de abrir, a ação principal do alerta.
    $(document).on('dblclick', '#homeAlertasGrid tbody tr[data-row]', function () {
        var $row = $(this);
        if ($row.attr('data-grid-url')) { marcarLido($row); return; }
        var b = ['resultado', 'excel', 'status'].filter(function (a) { return !!$row.attr('data-acao-' + a); })[0];
        if (b) $('[data-alerta-barra="' + b + '"]').trigger('click');
    });
    function dateText(v) { try { var d = new Date(String(v || '').replace(' ', 'T')); return isNaN(d) ? String(v || '') : d.toLocaleString(language === 'en' ? 'en-US' : language === 'es' ? 'es-ES' : 'pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }); } catch (e) { return String(v || ''); } }
    // Resultado de análise de IA: popup com o documento e o resultado da extração.
    function mostrarResultado(urlAlerta, title) {
        var url = new URL(urlAlerta, window.location.href).toString();
        try {
            var host = window.top || window;
            if (!host.GreenPortalPopups || !host.jQuery) throw new Error('sem popup');
            var docUrl = url, analysisUrl = url;
            if (/gestao_arquivos\.php/i.test(url)) {
                docUrl = /[?&]aba=/i.test(url) ? url.replace(/([?&])aba=[^&]*/i, '$1aba=arquivos') : url + (url.indexOf('?') < 0 ? '?' : '&') + 'aba=arquivos';
                analysisUrl = /[?&]aba=/i.test(url) ? url.replace(/([?&])aba=[^&]*/i, '$1aba=analises') : url + (url.indexOf('?') < 0 ? '?' : '&') + 'aba=analises';
            }
            var box = host.jQuery('<div class="alert-result-tabs" style="height:100%;display:flex;flex-direction:column"><ul class="nav nav-tabs"><li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#alertDocTab" type="button"></button></li><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#alertIaTab" type="button"></button></li></ul><div class="tab-content" style="flex:1 1 auto;min-height:0"><div class="tab-pane fade show active" id="alertDocTab" style="height:100%"><iframe style="width:100%;height:100%;min-height:520px;border:0"></iframe></div><div class="tab-pane fade" id="alertIaTab" style="height:100%"><iframe style="width:100%;height:100%;min-height:520px;border:0"></iframe></div></div></div>');
            box.find('[data-bs-target="#alertDocTab"]').text(L('Documento', 'Document', 'Documento'));
            box.find('[data-bs-target="#alertIaTab"]').text(L('Resultado da extração', 'Extraction result', 'Resultado de extracción'));
            box.find('#alertDocTab iframe').attr({ src: docUrl, title: L('Documento', 'Document', 'Documento') });
            box.find('#alertIaTab iframe').attr({ src: analysisUrl, title: L('Resultado da extração', 'Extraction result', 'Resultado de extracción') });
            host.GreenPortalPopups.transacaoIndependente({ titulo: title, conteudo: box, amplo: true, redimensionavel: true });
        } catch (e) { window.open(url, '_blank'); }
    }
    // Status da exportação: janela com a barra de progresso, atualizada a cada 3 s enquanto processa.
    function verStatus(statusUrl) {
        var modal = document.getElementById('homeAlertStatusModal');
        if (!modal) {
            modal = document.createElement('div'); modal.id = 'homeAlertStatusModal'; modal.className = 'modal fade';
            modal.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="alert-status-progress"><span></span></div><div class="alert-status-details small"></div></div></div></div>';
            document.body.appendChild(modal);
        }
        modal.querySelector('.modal-title').textContent = L('Status da exportação', 'Export status', 'Estado de exportación');
        var instance = bootstrap.Modal.getOrCreateInstance(modal), details = modal.querySelector('.alert-status-details'), bar = modal.querySelector('.alert-status-progress span');
        function refresh() {
            fetch(statusUrl, { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (data) {
                var j = data.job || {}, p = Number(j.percentual || 0);
                bar.style.width = p + '%';
                details.innerHTML = '';
                var linha = function (rotulo, valor) { var div = document.createElement('div'), b = document.createElement('strong'); b.textContent = rotulo + ': '; div.appendChild(b); div.appendChild(document.createTextNode(valor)); details.appendChild(div); };
                linha('Status', j.status || '—'); linha(L('Solicitada', 'Requested', 'Solicitada'), dateText(j.solicitado_em || ''));
                if (j.erro) { var err = document.createElement('div'); err.className = 'text-danger mt-2'; err.textContent = String(j.erro); details.appendChild(err); }
                if (data.active || j.status === 'SOLICITADA' || j.status === 'PROCESSANDO') setTimeout(refresh, 3000);
            }).catch(function () { details.textContent = L('Não foi possível carregar o status.', 'Could not load status.', 'No fue posible cargar el estado.'); });
        }
        instance.show(); refresh();
    }
}(jQuery));
</script>
</body></html>
