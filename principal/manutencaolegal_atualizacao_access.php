<?php
declare(strict_types=1);

/*
 * Manutenção Legal › Atualização do Access (migração 404, 24/11/2026). Substitui as telas mlExcel/mlAccess e o
 * clsLegislacao.exe do legado. Só o ADMINISTRADOR.
 *  1. Dependências: as três importações do mês (Legislação x Empresas, x Controle, Alterações) precisam estar aplicadas;
 *  2. Arquivos: leis_modelo.mdb e o .mdb de cada unidade (nome = Nome MDB de Administração › Unidades); cada envio sobrepõe
 *     o anterior (o antigo vai para storage/access/historico); baixar um arquivo ou todos da empresa (.zip);
 *  3. Processar: fonte (portal ou leis_modelo.mdb), empresas, Simular (só conta) ou Atualizar (grava) — o serviço
 *     ACCESS_ATUALIZACAO executa em segundo plano e manda o alerta;
 *  4. Histórico: pedidos com o resultado por unidade e o log.
 * 24/11/2026 (pedido do usuário): no padrão do projeto — dependências no topo e sub-abas Processamentos | Arquivos das unidades |
 * leis_modelo.mdb, cada lista no grid padrão (DashboardGrid / grid local: barra de ícones, Ajuda, busca, filtro e ordenação
 * por coluna, 25 por página); ações da linha pela barra (data-grid-acao-linha); Abrir / duplo clique abre o processamento em
 * popup (resultado por unidade e log em sub-abas); confirmações em modal, sem diálogo nativo.
 */

require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/AccessAtualizacao.php';
require_once dirname(__DIR__) . '/classes/DashboardGrid.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

TransactionSecurity::connect('ATUALIZACAO_ACCESS');
if (strtoupper(trim((string)($_SESSION['usuario']['codigo_perfil'] ?? ''))) !== 'ADMINISTRADOR') { http_response_code(403); exit('Somente o ADMINISTRADOR usa a Atualização do Access.'); }

$idioma = IdiomaService::idioma();
$L = static fn(string $pt, string $en, string $es): string => ['pt' => $pt, 'en' => $en, 'es' => $es][$idioma] ?? $pt;
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
if (empty($_SESSION['access_csrf'])) $_SESSION['access_csrf'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['access_csrf'];
$usuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$nomeUsuario = trim((string)($_SESSION['usuario']['nome'] ?? $_SESSION['usuario']['login'] ?? '')) ?: ('usuário ' . $usuario);
$bancoLogado = (string)($_SESSION['tenant']['banco_dados'] ?? '');
$empresaLogada = (int)($_SESSION['tenant']['codempresa'] ?? 0);
$migradas = LegislacaoEmpresas::migradas();
$dt = static fn(?string $v, bool $hora = true): string => ($v && ($t = strtotime($v))) ? date(($idioma === 'en' ? 'm/d/Y' : 'd/m/Y') . ($hora ? ' H:i' : ''), $t) : '—';
$tam = static fn(?int $b): string => $b === null ? '—' : ($b >= 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : number_format($b / 1024, 0, ',', '.') . ' KB');

// ------------------------------------------------------------------ downloads
if (isset($_GET['baixar']) || isset($_GET['zip'])) {
    session_write_close();
    $arqs = AccessAtualizacao::arquivos();
    if (isset($_GET['baixar'])) {
        $cod = (int)$_GET['baixar'];
        $alvo = null;
        if ($arqs['MODELO'] && (int)$arqs['MODELO']['codarquivo'] === $cod) $alvo = $arqs['MODELO'];
        foreach ($arqs['UNIDADES'] as $uns) foreach ($uns as $a) if ((int)$a['codarquivo'] === $cod) $alvo = $a;
        $caminho = $alvo ? AccessAtualizacao::caminho($alvo) : '';
        if (!$alvo || !is_file($caminho)) { http_response_code(404); exit('Arquivo não encontrado.'); }
        header('Content-Type: application/x-msaccess');
        header('Content-Length: ' . filesize($caminho));
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode((string)$alvo['nome_arquivo']));
        readfile($caminho);
        exit;
    }
    $codemp = (int)$_GET['zip'];
    if (!isset($migradas[$codemp]) || empty($arqs['UNIDADES'][$codemp]) || !class_exists('ZipArchive')) { http_response_code(404); exit('Nada para baixar.'); }
    $zip = AccessAtualizacao::pasta('tmp') . DIRECTORY_SEPARATOR . 'zip_' . bin2hex(random_bytes(6)) . '.zip';
    $z = new ZipArchive();
    $z->open($zip, ZipArchive::CREATE);
    foreach ($arqs['UNIDADES'][$codemp] as $a) { $c = AccessAtualizacao::caminho($a); if (is_file($c)) $z->addFile($c, (string)$a['nome_arquivo']); }
    $z->close();
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($zip));
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode('access_' . preg_replace('/\W+/u', '_', $migradas[$codemp]['nome']) . '_' . date('Ymd') . '.zip'));
    readfile($zip);
    @unlink($zip);
    exit;
}

// ------------------------------------------------------------------ ações (JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $resp = static function (bool $ok, string $msg, array $extra = []): never { echo json_encode(['ok' => $ok, 'message' => $msg] + $extra, JSON_UNESCAPED_UNICODE); exit; };
    // Envio maior que o post_max_size: o PHP descarta tudo (sem csrf) — avisar o motivo certo
    if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) $resp(false, $L('O envio passou do limite do servidor (', 'The upload exceeded the server limit (', 'El envío superó el límite del servidor (') . ini_get('post_max_size') . ').');
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $resp(false, $L('A página ficou desatualizada. Recarregue e tente de novo.', 'This page is out of date. Reload and try again.', 'La página quedó desactualizada. Recargue e intente de nuevo.'));
    $acao = (string)($_POST['acao'] ?? '');
    try {
        if ($acao === 'enviar') {
            $f = $_FILES['arquivo'] ?? null;
            if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
                $erro = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
                $resp(false, in_array($erro, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? $L('Arquivo maior que o limite do servidor (', 'File larger than the server limit (', 'Archivo mayor que el límite del servidor (') . ini_get('upload_max_filesize') . ').'
                    : $L('Escolha o arquivo .mdb.', 'Choose the .mdb file.', 'Elija el archivo .mdb.'));
            }
            @set_time_limit(300);
            $tipo = ($_POST['tipo'] ?? '') === 'MODELO' ? 'MODELO' : 'UNIDADE';
            $r = AccessAtualizacao::receber((string)$f['tmp_name'], (string)$f['name'], $tipo, (int)($_POST['codempresa'] ?? 0), (int)($_POST['codunidade'] ?? 0), $usuario, $nomeUsuario);
            $resp(true, sprintf($L('%s recebido (%d leis). O arquivo anterior foi guardado no histórico.', '%s received (%d laws). The previous file was kept in the history.', '%s recibido (%d leyes). El archivo anterior quedó en el historial.'), $r['nome'], $r['leis']));
        }
        // 24/11/2026: envio em partes (os .mdb passam de 20 MB, o limite do servidor): o navegador manda pedaços de 4 MB, o
        // servidor junta em storage/access/tmp e, na última parte, confere e grava como no envio normal.
        if ($acao === 'enviar_parte') {
            $up = (string)($_POST['upload_id'] ?? '');
            $parte = (int)($_POST['parte'] ?? -1); $total = (int)($_POST['total'] ?? 0); $offset = (int)($_POST['offset'] ?? -1);
            $f = $_FILES['pedaco'] ?? null;
            if (!preg_match('/^[a-f0-9]{16,40}$/', $up) || $parte < 0 || $total < 1 || $parte >= $total || $offset < 0 || !$f || (int)$f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) $resp(false, $L('Envio inválido. Tente de novo.', 'Invalid upload. Try again.', 'Envío inválido. Intente de nuevo.'));
            $acum = AccessAtualizacao::pasta('tmp') . DIRECTORY_SEPARATOR . 'up_' . $up . '.part';
            if ($parte === 0) @unlink($acum);
            clearstatcache(true, $acum);
            $atual = is_file($acum) ? (int)filesize($acum) : 0;
            if ($atual !== $offset) { @unlink($acum); $resp(false, $L('O envio se perdeu no meio. Tente de novo.', 'The upload was interrupted. Try again.', 'El envío se interrumpió. Intente de nuevo.')); }
            $in = fopen((string)$f['tmp_name'], 'rb'); $out = fopen($acum, 'ab');
            stream_copy_to_stream($in, $out); fclose($in); fclose($out);
            if ($parte < $total - 1) $resp(true, '', ['parte' => $parte]);
            @set_time_limit(300);
            try {
                $tipo = ($_POST['tipo'] ?? '') === 'MODELO' ? 'MODELO' : 'UNIDADE';
                $r = AccessAtualizacao::receber($acum, (string)($_POST['nome'] ?? ''), $tipo, (int)($_POST['codempresa'] ?? 0), (int)($_POST['codunidade'] ?? 0), $usuario, $nomeUsuario);
            } finally { @unlink($acum); }
            $resp(true, sprintf($L('%s recebido (%d leis). O arquivo anterior foi guardado no histórico.', '%s received (%d laws). The previous file was kept in the history.', '%s recibido (%d leyes). El archivo anterior quedó en el historial.'), $r['nome'], $r['leis']), ['fim' => true]);
        }
        if ($acao === 'processar') {
            $id = AccessAtualizacao::solicitar((string)($_POST['modo'] ?? 'SIMULAR'), (string)($_POST['fonte'] ?? ''), (array)($_POST['empresas'] ?? []), $usuario, $nomeUsuario, $bancoLogado);
            $resp(true, $L('Pedido registrado. O serviço processa em até 1 minuto e o resultado chega nos alertas.', 'Request saved. The service runs within 1 minute and the result arrives in the alerts.', 'Pedido registrado. El servicio procesa en hasta 1 minuto y el resultado llega en las alertas.'), ['codprocessamento' => $id]);
        }
        $resp(false, 'Ação inválida.');
    } catch (Throwable $err) {
        $resp(false, $err->getMessage());
    }
}

// ------------------------------------------------------------------ dados
$dep = AccessAtualizacao::dependencias();
$arqs = AccessAtualizacao::arquivos();
$procId = (int)($_GET['codprocessamento'] ?? 0);
$proc = $procId > 0 ? AccessAtualizacao::processamento($procId) : null;
$corSt = ['SOLICITADO' => 'info', 'PROCESSANDO' => 'info', 'CONCLUIDO' => 'success', 'CONCLUIDO_COM_ERROS' => 'warning', 'ERRO' => 'danger',
    'APLICADA' => 'success', 'APLICADA_COM_ERROS' => 'warning', 'CONFERIDA' => 'warning', 'AUTORIZADA' => 'info', 'APLICANDO' => 'info'];
$txtSt = ['SOLICITADO' => $L('Na fila', 'Queued', 'En cola'), 'PROCESSANDO' => $L('Processando', 'Processing', 'Procesando'), 'CONCLUIDO' => $L('Concluído', 'Done', 'Concluido'),
    'CONCLUIDO_COM_ERROS' => $L('Concluído com erros', 'Done with errors', 'Concluido con errores'), 'ERRO' => $L('Erro', 'Error', 'Error'),
    'APLICADA' => $L('Aplicada', 'Applied', 'Aplicada'), 'APLICADA_COM_ERROS' => $L('Aplicada com erros', 'Applied with errors', 'Aplicada con errores'),
    'CONFERIDA' => $L('Conferida, falta autorizar', 'Checked, not authorized yet', 'Revisada, falta autorizar'), 'AUTORIZADA' => $L('Autorizada, na fila', 'Authorized, queued', 'Autorizada, en cola'),
    'APLICANDO' => $L('Aplicando', 'Applying', 'Aplicando')];
$badge = static fn(?string $s): string => $s === null ? '<span class="badge text-bg-secondary">' . htmlspecialchars($L('Não enviada', 'Not sent', 'No enviada'), ENT_QUOTES, 'UTF-8') . '</span>'
    : '<span class="badge text-bg-' . ($corSt[$s] ?? 'secondary') . '">' . htmlspecialchars($txtSt[$s] ?? $s, ENT_QUOTES, 'UTF-8') . '</span>';
$stCell = static fn(?string $s): array => ['text' => $txtSt[$s] ?? (string)$s, 'html' => $badge($s)];
$dtCell = static fn(?string $v): array => ['text' => $dt($v), 'sort' => (string)$v];
$modoTxt = static fn(string $m): string => $m === 'APLICAR' ? $L('Atualização', 'Update', 'Actualización') : $L('Simulação', 'Simulation', 'Simulación');
$fonteTxt = static fn(string $f): string => $f === 'MODELO' ? 'leis_modelo.mdb' : $L('Portal', 'Portal', 'Portal');
$tituloTela = $L('Atualização do Access', 'Access update', 'Actualización de Access');
$jsonJs = static fn(mixed $v): string => (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

if ($proc) {
    // ---------------------------------------------------------------- detalhe de um processamento (popup)
    $res = json_decode((string)($proc['resumo'] ?? ''), true) ?: [];
    $linhas = [];
    foreach ((array)($res['unidades'] ?? []) as $r) $linhas[] = ['cells' => [
        'EMPRESA' => (string)$r['empresa'], 'UNIDADE' => (string)$r['unidade'], 'ARQUIVO' => (string)$r['arquivo'],
        'ANTES' => DashboardGrid::numero((int)($r['leis_antes'] ?? 0)), 'DEPOIS' => DashboardGrid::numero((int)($r['leis_depois'] ?? 0)),
        'INCLUIDAS' => DashboardGrid::numero((int)$r['incluidas']), 'CONTROLES' => DashboardGrid::numero((int)$r['controles']),
        'REVOGADAS' => DashboardGrid::numero((int)$r['revogadas']), 'ALTERADAS' => DashboardGrid::numero((int)$r['alteradas']),
        'SEMFONTE' => DashboardGrid::numero((int)$r['sem_fonte'], 'status-warning') + (!empty($r['sem_fonte_exemplos']) ? ['title' => implode(', ', $r['sem_fonte_exemplos'])] : []),
        'ERRO' => ['text' => (string)($r['erro'] ?? ''), 'html' => !empty($r['erro']) ? '<span class="text-danger">' . $e($r['erro']) . '</span>' : '', 'title' => (string)($r['erro'] ?? '')],
    ]];
    $gridRes = DashboardGrid::grid('accessResultado', [
        'EMPRESA' => ['label' => $L('Empresa', 'Company', 'Empresa'), 'filter' => 'select', 'width' => '150px'],
        'UNIDADE' => ['label' => $L('Unidade', 'Unit', 'Unidad'), 'width' => '180px'],
        'ARQUIVO' => ['label' => 'Nome MDB'],
        'ANTES' => ['label' => $L('Leis antes', 'Laws before', 'Leyes antes'), 'filter' => 'none', 'width' => '90px'],
        'DEPOIS' => ['label' => $L('Leis depois', 'Laws after', 'Leyes después'), 'filter' => 'none', 'width' => '90px'],
        'INCLUIDAS' => ['label' => $L('Incluídas', 'Added', 'Incluidas'), 'filter' => 'none', 'width' => '85px'],
        'CONTROLES' => ['label' => $L('Controles', 'Controls', 'Controles'), 'filter' => 'none', 'width' => '85px'],
        'REVOGADAS' => ['label' => $L('Revogadas', 'Revoked', 'Revocadas'), 'filter' => 'none', 'width' => '85px'],
        'ALTERADAS' => ['label' => $L('Alteradas', 'Changed', 'Cambiadas'), 'filter' => 'none', 'width' => '85px'],
        'SEMFONTE' => ['label' => $L('Sem dados na fonte', 'No data in source', 'Sin datos en la fuente'), 'filter' => 'none', 'width' => '110px'],
        'ERRO' => ['label' => $L('Erro', 'Error', 'Error')],
    ], $linhas, ['transacao' => 'manutencaolegal_atualizacao_access', 'ordem' => ['EMPRESA', 'asc'],
        'vazio' => in_array($proc['status'], ['SOLICITADO', 'PROCESSANDO'], true) ? $L('Processando… a página se atualiza sozinha.', 'Processing… the page refreshes by itself.', 'Procesando… la página se actualiza sola.') : $L('Nenhuma unidade processada.', 'No unit processed.', 'Ninguna unidad procesada.')]);
    $t = (array)($res['totais'] ?? []);
    $cartao = static fn(string $rot, mixed $n, string $cls = ''): string => '<div class="aa-cartao ' . $cls . '"><b>' . htmlspecialchars((string)$n, ENT_QUOTES, 'UTF-8') . '</b>' . htmlspecialchars($rot, ENT_QUOTES, 'UTF-8') . '</div>';
    ?><!DOCTYPE html>
<html lang="<?= $e(['en' => 'en', 'es' => 'es'][$idioma] ?? 'pt-BR') ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $e($tituloTela) ?> nº <?= (int)$proc['codprocessamento'] ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2"><link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2"><?php DashboardGrid::head(); ?>
<style>html,body{height:auto}.aa{padding:10px 14px}.aa-cab{margin-bottom:6px}.aa-cartoes{display:flex;flex-wrap:wrap;gap:8px;margin:6px 0}.aa-cartao{border:1px solid #d9e6df;border-radius:8px;padding:6px 12px;min-width:110px;background:#fff;font-size:12px;color:#4f675d}.aa-cartao b{display:block;font-size:18px;color:#1f4d3a}.aa-cartao.perigo b{color:#b91c1c}.aa-cartao.alerta b{color:#b45309}pre.aa-log{white-space:pre-wrap;font-size:12px;background:#f8faf9;padding:8px;border-radius:6px;margin:0;max-height:none}</style></head>
<body><main class="aa">
<div class="aa-cab"><b><?= $e($modoTxt((string)$proc['modo'])) ?> nº <?= (int)$proc['codprocessamento'] ?></b> · <?= $e($L('fonte', 'source', 'fuente')) ?> <?= $e($fonteTxt((string)$proc['fonte'])) ?> · <?= $badge($proc['status']) ?> · <?= $e($dt($proc['solicitado_em'])) ?> <?= $e($L('por', 'by', 'por')) ?> <?= $e($proc['solicitado_por_nome']) ?><?= $proc['fim'] ? ' · ' . $e($L('fim', 'end', 'fin')) . ' ' . $e($dt($proc['fim'])) : '' ?>
<?php if ($proc['modo'] !== 'APLICAR'): ?><div class="small text-muted"><?= $e($L('Simulação: nada foi gravado nos arquivos; os números mostram o que a atualização faria.', 'Simulation: nothing was written to the files; the numbers show what the update would do.', 'Simulación: nada se grabó en los archivos; los números muestran lo que haría la actualización.')) ?></div><?php endif; ?></div>
<div class="aa-cartoes"><?= $cartao($L('arquivos', 'files', 'archivos'), $t['arquivos'] ?? 0) . $cartao($L('leis incluídas', 'laws added', 'leyes incluidas'), $t['incluidas'] ?? 0) . $cartao($L('controles', 'controls', 'controles'), $t['controles'] ?? 0) . $cartao($L('revogadas', 'revoked', 'revocadas'), $t['revogadas'] ?? 0) . $cartao($L('alteradas', 'changed', 'cambiadas'), $t['alteradas'] ?? 0) . $cartao($L('sem dados na fonte', 'no data in source', 'sin datos en la fuente'), $t['sem_fonte'] ?? 0, 'alerta') . $cartao($L('erros', 'errors', 'errores'), $t['erros'] ?? 0, 'perigo') ?></div>
<?= DashboardGrid::subabas('accessDetalhe', [
    ['id' => 'resultado', 'icone' => 'bi-table', 'rotulo' => $L('Resultado por unidade', 'Result by unit', 'Resultado por unidad'), 'html' => $gridRes, 'contagem' => count($linhas)],
    ['id' => 'log', 'icone' => 'bi-journal-text', 'rotulo' => 'Log', 'html' => DashboardGrid::quadro('Log', 'bi-journal-text', '<pre class="aa-log">' . $e($proc['log'] ?? '') . '</pre>')],
]) ?>
</main>
<?php DashboardGrid::scripts(); ?>
<?php if (in_array($proc['status'], ['SOLICITADO', 'PROCESSANDO'], true)): ?><script>setTimeout(function () { window.location.reload(); }, 15000);</script><?php endif; ?>
</body></html>
<?php
    exit;
}

// -------------------------------------------------------------------- tela principal
$lista = AccessAtualizacao::processamentos(200);
$emAndamento = (bool)array_filter($lista, static fn($p) => in_array($p['status'], ['SOLICITADO', 'PROCESSANDO'], true));
$base = basename(__FILE__);
// Processamentos
$rowsProc = [];
// 24/11/2026 (pedido do usuário): coluna Empresa / unidade — as unidades do processamento. Concluído: as do resultado;
// na fila ou processando: as unidades com .mdb das empresas pedidas (a que está sendo atualizada agora vem marcada com ▶).
$unidadesProc = static function (array $p) use ($arqs, $migradas): array {
    $res = json_decode((string)($p['resumo'] ?? ''), true);
    $feitas = [];
    foreach ((array)($res['unidades'] ?? []) as $u) $feitas[] = ['emp' => (string)$u['empresa'], 'un' => (string)$u['unidade'], 'erro' => !empty($u['erro'])];
    $andamento = in_array($p['status'], ['SOLICITADO', 'PROCESSANDO'], true);
    if (!$andamento) return ['lista' => $feitas, 'atual' => null];
    $previstas = [];
    foreach ((array)(json_decode((string)($p['empresas'] ?? ''), true) ?: []) as $codemp) {
        foreach (($arqs['UNIDADES'][(int)$codemp] ?? []) as $a) $previstas[] = ['emp' => (string)($migradas[(int)$codemp]['nome'] ?? $codemp), 'un' => (string)$a['unidade_nome'], 'erro' => false];
    }
    $atual = $p['status'] === 'PROCESSANDO' && isset($previstas[count($feitas)]) ? count($feitas) : null;
    return ['lista' => $previstas ?: $feitas, 'atual' => $atual];
};
foreach ($lista as $p) {
    $id = (int)$p['codprocessamento'];
    $up = $unidadesProc($p);
    $partes = []; $htmlUn = [];
    foreach ($up['lista'] as $i => $u) {
        $txt = $u['emp'] === $u['un'] ? $u['un'] : $u['emp'] . ' › ' . $u['un'];
        $partes[] = $txt;
        $htmlUn[] = ($up['atual'] === $i ? '<strong class="text-success">▶ ' . $e($txt) . '</strong>' : ($u['erro'] ? '<span class="text-danger">' . $e($txt) . '</span>' : $e($txt)));
    }
    $textoUn = implode('; ', $partes);
    $rowsProc[] = ['cells' => [
        'COD' => ['text' => (string)$id, 'sort' => str_pad((string)$id, 8, '0', STR_PAD_LEFT)],
        'UNIDADES' => ['text' => $textoUn, 'html' => implode('; ', $htmlUn), 'title' => $textoUn],
        'TIPO' => $modoTxt((string)$p['modo']), 'FONTE' => $fonteTxt((string)$p['fonte']), 'STATUS' => $stCell($p['status']),
        'PEDIDO' => $dtCell($p['solicitado_em']), 'POR' => (string)$p['solicitado_por_nome'], 'FIM' => $dtCell($p['fim']),
        'ARQUIVOS' => DashboardGrid::numero((int)($p['total_arquivos'] ?? 0)), 'ERROS' => DashboardGrid::numero((int)($p['total_erros'] ?? 0), 'status-danger'),
    ], 'open' => ['url' => $base . '?codprocessamento=' . $id, 'title' => $modoTxt((string)$p['modo']) . ' nº ' . $id]];
}
$bloq = !$dep['ok'] || empty($arqs['UNIDADES']) || $emAndamento;
$motivoBloq = !$dep['ok'] ? $L('Bloqueado: aplique antes as importações do mês (veja o aviso no topo).', 'Blocked: apply the monthly imports first (see the notice at the top).', 'Bloqueado: aplique antes las importaciones del mes (vea el aviso arriba).')
    : (empty($arqs['UNIDADES']) ? $L('Bloqueado: envie os .mdb das unidades (aba Arquivos das unidades).', 'Blocked: send the unit .mdb files (Unit files tab).', 'Bloqueado: envíe los .mdb de las unidades (pestaña Archivos de las unidades).')
    : ($emAndamento ? $L('Há um processamento em andamento; aguarde terminar.', 'A processing is running; wait for it to finish.', 'Hay un procesamiento en curso; espere a que termine.') : ''));
$tSim = $L('Simular (não grava)', 'Simulate (no changes)', 'Simular (no graba)');
$tAtu = $L('Atualizar os Access', 'Update the Access files', 'Actualizar los Access');
$extrasProc = '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-processar="SIMULAR"' . ($bloq ? ' disabled' : '') . ' title="' . $e($bloq ? $motivoBloq : $tSim) . '" aria-label="' . $e($tSim) . '"><i class="bi bi-search"></i></button>'
    . '<button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-processar="APLICAR"' . ($bloq ? ' disabled' : '') . ' title="' . $e($bloq ? $motivoBloq : $tAtu) . '" aria-label="' . $e($tAtu) . '"><i class="bi bi-database-up"></i></button>';
$gridProc = DashboardGrid::grid('accessProcessamentos', [
    'COD' => ['label' => 'Nº', 'width' => '70px'],
    'UNIDADES' => ['label' => $L('Empresa / unidade', 'Company / unit', 'Empresa / unidad'), 'width' => '260px'],
    'TIPO' => ['label' => $L('Tipo', 'Type', 'Tipo'), 'filter' => 'select', 'width' => '110px'],
    'FONTE' => ['label' => $L('Fonte', 'Source', 'Fuente'), 'filter' => 'select', 'width' => '120px'],
    'STATUS' => ['label' => 'Status', 'filter' => 'select', 'width' => '150px'],
    'PEDIDO' => ['label' => $L('Pedido em', 'Requested on', 'Pedido el'), 'filter' => 'none', 'width' => '125px'],
    'POR' => ['label' => $L('Por', 'By', 'Por'), 'filter' => 'select'],
    'FIM' => ['label' => $L('Fim', 'End', 'Fin'), 'filter' => 'none', 'width' => '125px'],
    'ARQUIVOS' => ['label' => $L('Arquivos', 'Files', 'Archivos'), 'filter' => 'none', 'width' => '80px'],
    'ERROS' => ['label' => $L('Erros', 'Errors', 'Errores'), 'filter' => 'none', 'width' => '70px'],
], $rowsProc, ['transacao' => 'manutencaolegal_atualizacao_access', 'ordem' => ['COD', 'desc'], 'extras' => $extrasProc,
    'vazio' => $L('Nenhum processamento ainda. Use Simular ou Atualizar na barra.', 'No processing yet. Use Simulate or Update on the toolbar.', 'Ningún procesamiento todavía. Use Simular o Actualizar en la barra.')]);

// Arquivos das unidades (todas as empresas migradas)
$rowsArq = []; $errosUn = []; $nEnviados = 0;
foreach ($migradas as $codemp => $emp) {
    try { $uns = AccessAtualizacao::unidades($codemp); } catch (Throwable $x) { $errosUn[] = $emp['nome'] . ': ' . $x->getMessage(); continue; }
    foreach ($uns as $codun => $un) {
        $a = $arqs['UNIDADES'][$codemp][$codun] ?? null;
        if ($a) $nEnviados++;
        $acoes = greenPortalGridLocalLinha(
            ($un['mdb'] !== '' ? '<button type="button" data-linha-acao="enviar" data-tipo="UNIDADE" data-codempresa="' . (int)$codemp . '" data-codunidade="' . (int)$codun . '" data-mdb="' . $e($un['mdb']) . '" data-rotulo="' . $e($emp['nome'] . ' › ' . $un['nome']) . '"></button>' : '')
            . ($a ? '<button type="button" data-linha-acao="baixar" data-href="' . $e($base . '?baixar=' . (int)$a['codarquivo']) . '"></button>' : '')
            . (!empty($arqs['UNIDADES'][$codemp]) ? '<button type="button" data-linha-acao="zip" data-href="' . $e($base . '?zip=' . (int)$codemp) . '"></button>' : ''));
        $rowsArq[] = ['cells' => [
            'EMPRESA' => ['text' => $emp['nome'], 'html' => $e($emp['nome']) . $acoes],
            'UNIDADE' => ['text' => $un['nome'], 'html' => $e($un['nome']) . ($un['manutencao'] ? '' : ' <span class="badge text-bg-light" title="' . $e($L('Não usada em Manutenção Legal', 'Not used in Legal Maintenance', 'No usada en Mantenimiento Legal')) . '">ML: ' . $e($L('não', 'no', 'no')) . '</span>')],
            'MDB' => ['text' => $un['mdb'] ?: '—', 'title' => $un['mdb']],
            'SITUACAO' => $a ? ['text' => $L('Enviado', 'Sent', 'Enviado'), 'html' => '<span class="badge text-bg-success">' . $e($L('Enviado', 'Sent', 'Enviado')) . '</span>']
                : ['text' => $L('Não enviado', 'Not sent', 'No enviado'), 'html' => '<span class="badge text-bg-secondary">' . $e($L('Não enviado', 'Not sent', 'No enviado')) . '</span>'],
            'LEIS' => $a ? DashboardGrid::numero((int)$a['leis']) : ['text' => '', 'sort' => '-1'],
            'TAMANHO' => $a ? ['text' => $tam((int)$a['bytes']), 'sort' => str_pad((string)(int)$a['bytes'], 12, '0', STR_PAD_LEFT)] : ['text' => '', 'sort' => ''],
            'ENVIADO' => $a ? $dtCell($a['enviado_em']) : ['text' => '', 'sort' => ''],
            'POR' => $a ? (string)$a['enviado_por_nome'] : '',
            'ATUALIZADO' => $a && $a['atualizado_em'] ? ['text' => $dt($a['atualizado_em']) . ' (nº ' . (int)$a['atualizado_processamento'] . ')', 'sort' => (string)$a['atualizado_em']] : ['text' => '', 'sort' => ''],
        ], 'attrs' => ''];
    }
}
$tEnv = $L('Enviar o .mdb da unidade selecionada', 'Send the .mdb of the selected unit', 'Enviar el .mdb de la unidad seleccionada');
$tBai = $L('Baixar o .mdb da unidade selecionada', 'Download the .mdb of the selected unit', 'Descargar el .mdb de la unidad seleccionada');
$tZip = $L('Baixar todos os .mdb da empresa da linha selecionada (.zip)', 'Download all .mdb files of the selected row company (.zip)', 'Descargar todos los .mdb de la empresa de la fila seleccionada (.zip)');
$extrasArq = '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-grid-acao-linha="enviar" disabled title="' . $e($tEnv) . '" aria-label="' . $e($tEnv) . '"><i class="bi bi-upload"></i></button>'
    . '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-grid-acao-linha="baixar" disabled title="' . $e($tBai) . '" aria-label="' . $e($tBai) . '"><i class="bi bi-download"></i></button>'
    . '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-grid-acao-linha="zip" disabled title="' . $e($tZip) . '" aria-label="' . $e($tZip) . '"><i class="bi bi-file-zip"></i></button>';
$avisoArq = $L('O nome do arquivo precisa ser igual ao Nome MDB da unidade (Administração › Unidades). O envio sobrepõe o arquivo anterior, que fica guardado no histórico.', 'The file name must match the unit MDB name (Administration › Units). Sending replaces the previous file, which is kept in the history.', 'El nombre del archivo debe ser igual al Nombre MDB de la unidad (Administración › Unidades). El envío reemplaza el archivo anterior, que queda en el historial.')
    . ($errosUn ? ' ' . $L('Empresas com erro: ', 'Companies with errors: ', 'Empresas con error: ') . implode('; ', $errosUn) : '');
$gridArq = DashboardGrid::grid('accessArquivos', [
    'EMPRESA' => ['label' => $L('Empresa', 'Company', 'Empresa'), 'filter' => 'select', 'width' => '150px'],
    'UNIDADE' => ['label' => $L('Unidade', 'Unit', 'Unidad'), 'width' => '190px'],
    'MDB' => ['label' => 'Nome MDB'],
    'SITUACAO' => ['label' => $L('Arquivo', 'File', 'Archivo'), 'filter' => 'select', 'width' => '110px'],
    'LEIS' => ['label' => $L('Leis', 'Laws', 'Leyes'), 'filter' => 'none', 'width' => '70px'],
    'TAMANHO' => ['label' => $L('Tamanho', 'Size', 'Tamaño'), 'filter' => 'none', 'width' => '85px'],
    'ENVIADO' => ['label' => $L('Enviado em', 'Sent on', 'Enviado el'), 'filter' => 'none', 'width' => '125px'],
    'POR' => ['label' => $L('Por', 'By', 'Por'), 'filter' => 'select', 'width' => '130px'],
    'ATUALIZADO' => ['label' => $L('Última atualização', 'Last update', 'Última actualización'), 'filter' => 'none', 'width' => '150px'],
], $rowsArq, ['transacao' => 'manutencaolegal_atualizacao_access', 'ordem' => ['EMPRESA', 'asc'], 'extras' => $extrasArq, 'acao_duplo' => 'enviar', 'aviso' => $avisoArq,
    'vazio' => $L('Nenhuma unidade.', 'No units.', 'Ninguna unidad.')]);

// leis_modelo.mdb
$m = $arqs['MODELO'];
$htmlModelo = DashboardGrid::quadro('leis_modelo.mdb', 'bi-file-earmark-text',
    '<p class="mb-2">' . ($m ? $e(sprintf($L('%d leis, %s, enviado em %s por %s.', '%d laws, %s, sent on %s by %s.', '%d leyes, %s, enviado el %s por %s.'), (int)$m['leis'], $tam((int)$m['bytes']), $dt($m['enviado_em']), $m['enviado_por_nome'])) : '<span class="text-muted">' . $e($L('Ainda não enviado.', 'Not sent yet.', 'Aún no enviado.')) . '</span>') . '</p>'
    . '<p class="small text-muted mb-2">' . $e($L('Usado como fonte "leis_modelo.mdb" e, na fonte "Portal", para o texto do requisito. O envio sobrepõe o arquivo anterior (que fica no histórico).', 'Used as the "leis_modelo.mdb" source and, with the "Portal" source, for the requirement text. Sending replaces the previous file (kept in the history).', 'Usado como fuente "leis_modelo.mdb" y, con la fuente "Portal", para el texto del requisito. El envío reemplaza el archivo anterior (queda en el historial).')) . '</p>'
    . '<button type="button" class="btn btn-sm btn-outline-primary" data-enviar-modelo data-tipo="MODELO" data-mdb="leis_modelo.mdb" data-rotulo="leis_modelo.mdb"><i class="bi bi-upload me-1"></i>' . $e($L('Enviar', 'Send', 'Enviar')) . '</button>'
    . ($m ? ' <a class="btn btn-sm btn-outline-secondary" href="' . $e($base . '?baixar=' . (int)$m['codarquivo']) . '"><i class="bi bi-download me-1"></i>' . $e($L('Baixar', 'Download', 'Descargar')) . '</a>' : ''));
$empComArquivos = array_filter($migradas, static fn($emp, $cod) => !empty($arqs['UNIDADES'][$cod]), ARRAY_FILTER_USE_BOTH);
?><!DOCTYPE html>
<html lang="<?= $e(['en' => 'en', 'es' => 'es'][$idioma] ?? 'pt-BR') ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $e($tituloTela) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2"><link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2"><?php DashboardGrid::head(); ?>
<style>html,body{height:auto}.aa{padding:10px 14px}.aa-dep{display:flex;flex-wrap:wrap;gap:6px 16px;align-items:center;border:1px solid #d9e6df;border-radius:8px;padding:8px 12px;background:#fff;font-size:12px}.aa-dep.bloq{border-color:#f0c36d;background:#fffbeb}.aa-dep .aa-dep-tit{font-weight:600;color:#1f4d3a}.aa-msg .alert{margin:0 0 8px;padding:6px 10px;font-size:12px;white-space:pre-line}</style>
</head>
<body>
<main class="aa" data-csrf="<?= $e($csrf) ?>">
<div class="aa-msg" data-msg></div>
<div class="aa-dep<?= $dep['ok'] ? '' : ' bloq' ?>">
  <span class="aa-dep-tit"><i class="bi bi-diagram-3 me-1"></i><?= $e($L('Depende das importações do mês:', 'Depends on the monthly imports:', 'Depende de las importaciones del mes:')) ?></span>
  <?php foreach ($dep['itens'] as $it): ?><span title="<?= $e($it['pedido'] ? $L('Pedido nº ', 'Request no. ', 'Pedido nº ') . $it['pedido'] . ' (' . $it['arquivo'] . '), ' . $L('enviada em ', 'sent on ', 'enviada el ') . $dt($it['enviado_em']) . ($it['ok'] ? ', ' . $L('aplicada em ', 'applied on ', 'aplicada el ') . $dt($it['aplicado_em']) : '') : '') ?>"><i class="bi <?= $it['ok'] ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> me-1"></i><?= $e($it['nome']) ?> <?= $badge($it['status']) ?><?= $it['pedido'] ? ' <span class="text-muted">nº ' . (int)$it['pedido'] . '</span>' : '' ?></span><?php endforeach; ?>
  <?php if (!$dep['ok']): ?><span class="text-danger w-100"><?= $e($L('Atualização bloqueada: aplique as importações marcadas em vermelho (Manutenção Legal › Legislação, Legislação x Empresas e Legislação x Controle, botão Importar). A atualização do Access usa o que essas importações gravaram no portal.', 'Update blocked: apply the imports marked in red (Legal Maintenance › Legislation, Legislation x Companies and Legislation x Control, Import button). The Access update uses what those imports saved in the portal.', 'Actualización bloqueada: aplique las importaciones marcadas en rojo (Mantenimiento Legal › Legislación, Legislación x Empresas y Legislación x Control, botón Importar). La actualización de Access usa lo que esas importaciones grabaron en el portal.')) ?></span><?php endif; ?>
</div>
<?= DashboardGrid::subabas('accessAbas', [
    ['id' => 'processamentos', 'icone' => 'bi-play-circle', 'rotulo' => $L('Processamentos', 'Processings', 'Procesamientos'), 'html' => $gridProc, 'contagem' => count($rowsProc)],
    ['id' => 'arquivos', 'icone' => 'bi-database', 'rotulo' => $L('Arquivos das unidades', 'Unit files', 'Archivos de las unidades'), 'html' => $gridArq, 'contagem' => $nEnviados],
    ['id' => 'modelo', 'icone' => 'bi-file-earmark-text', 'rotulo' => 'leis_modelo.mdb', 'html' => $htmlModelo],
], true) ?>
</main>

<div class="modal fade" id="aaModalEnviar" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="bi bi-upload me-1"></i><?= $e($L('Enviar arquivo Access', 'Send Access file', 'Enviar archivo Access')) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form data-enviar-form><div class="modal-body">
    <p class="mb-1"><b data-enviar-rotulo></b></p>
    <p class="small text-muted mb-2"><?= $e($L('Nome esperado:', 'Expected name:', 'Nombre esperado:')) ?> <code data-enviar-mdb></code></p>
    <input type="hidden" name="tipo"><input type="hidden" name="codempresa"><input type="hidden" name="codunidade">
    <input class="form-control form-control-sm" type="file" name="arquivo" accept=".mdb">
    <div class="small text-muted mt-2"><?= $e($L('O arquivo atual será substituído e guardado no histórico.', 'The current file will be replaced and kept in the history.', 'El archivo actual será reemplazado y guardado en el historial.')) ?></div>
    <div class="small mt-2" data-enviar-msg></div>
  </div><div class="modal-footer"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= $e($L('Cancelar', 'Cancel', 'Cancelar')) ?></button><button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-upload me-1"></i><?= $e($L('Enviar', 'Send', 'Enviar')) ?></button></div></form>
</div></div></div>

<div class="modal fade" id="aaModalProcessar" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" data-processar-titulo></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form data-processar-form><div class="modal-body">
    <div class="mb-3"><b><?= $e($L('Fonte dos dados das leis incluídas e alteradas', 'Source of the data for added and changed laws', 'Fuente de los datos de las leyes incluidas y cambiadas')) ?></b>
      <div class="form-check"><input class="form-check-input" type="radio" name="fonte" value="PORTAL" id="fPortal" checked><label class="form-check-label" for="fPortal"><?= $e($L('Portal — cadastro de Legislação; o texto do requisito vem do leis_modelo.mdb (quando a lei está nele); área responsável pelas marcações; na lei alterada, só os campos do portal mudam.', 'Portal — legislation register; the requirement text comes from leis_modelo.mdb (when the law is in it); responsible area from the flags; on changed laws only the portal fields change.', 'Portal — registro de Legislación; el texto del requisito viene del leis_modelo.mdb (cuando la ley está en él); área responsable por las marcas; en la ley cambiada, solo cambian los campos del portal.')) ?></label></div>
      <div class="form-check"><input class="form-check-input" type="radio" name="fonte" value="MODELO" id="fModelo"<?= $m ? '' : ' disabled' ?>><label class="form-check-label" for="fModelo"><?= $e($L('leis_modelo.mdb (como o .NET) — lei que não está no modelo fica de fora e aparece como "sem dados na fonte".', 'leis_modelo.mdb (like the .NET program) — laws not in the model are left out and shown as "no data in source".', 'leis_modelo.mdb (como el .NET) — ley que no está en el modelo queda fuera y aparece como "sin datos en la fuente".')) ?></label></div>
    </div>
    <div><b><?= $e($L('Empresas', 'Companies', 'Empresas')) ?></b> <span class="small text-muted">(<?= $e($L('só as que têm .mdb enviados; número de arquivos entre parênteses', 'only those with .mdb files sent; number of files in parentheses', 'solo las que tienen .mdb enviados; número de archivos entre paréntesis')) ?>)</span><br>
      <?php foreach ($empComArquivos as $cod => $emp): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="empresas[]" value="<?= (int)$cod ?>" id="emp<?= (int)$cod ?>" checked><label class="form-check-label" for="emp<?= (int)$cod ?>"><?= $e($emp['nome']) ?> (<?= count($arqs['UNIDADES'][$cod]) ?>)</label></div><?php endforeach; ?>
    </div>
    <div class="alert alert-warning py-2 mt-3 mb-0 small" data-processar-aviso hidden><?= $e($L('Os .mdb das empresas marcadas serão gravados. O arquivo anterior de cada unidade fica guardado no histórico. Recomendado simular antes.', 'The .mdb files of the selected companies will be written. Each previous file is kept in the history. Simulating first is recommended.', 'Los .mdb de las empresas marcadas serán grabados. El archivo anterior de cada unidad queda en el historial. Se recomienda simular antes.')) ?></div>
  </div><div class="modal-footer"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= $e($L('Cancelar', 'Cancel', 'Cancelar')) ?></button><button type="submit" class="btn btn-sm btn-success" data-processar-ok></button></div></form>
</div></div></div>

<?php DashboardGrid::scripts(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var raiz = document.querySelector('.aa'), csrf = raiz.getAttribute('data-csrf'), caixa = document.querySelector('[data-msg]');
  var T = <?= $jsonJs(['erro' => $L('Resposta inesperada do servidor.', 'Unexpected server response.', 'Respuesta inesperada del servidor.'), 'falha' => $L('Falha de comunicação.', 'Communication failure.', 'Falla de comunicación.'),
      'escolha' => $L('Escolha o arquivo .mdb.', 'Choose the .mdb file.', 'Elija el archivo .mdb.'), 'enviando' => $L('Enviando e conferindo o arquivo…', 'Sending and checking the file…', 'Enviando y revisando el archivo…'),
      'nomeDif' => $L('O nome do arquivo é diferente do Nome MDB esperado.', 'The file name differs from the expected MDB name.', 'El nombre del archivo es diferente del Nombre MDB esperado.'),
      'sim' => $tSim, 'atu' => $tAtu, 'okSim' => $L('Simular', 'Simulate', 'Simular'), 'okAtu' => $L('Sim, atualizar', 'Yes, update', 'Sí, actualizar'), 'semEmp' => $L('Marque ao menos uma empresa.', 'Select at least one company.', 'Marque al menos una empresa.')]) ?>;
  function msg(t, ok) { caixa.innerHTML = ''; if (!t) return; var d = document.createElement('div'); d.className = 'alert alert-' + (ok ? 'success' : 'danger'); d.textContent = t; caixa.appendChild(d); }
  function post(fd) {
    fd.append('csrf', csrf);
    return fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, message: T.erro }; }); })
      .catch(function () { return { ok: false, message: T.falha }; });
  }
  function recarregar(aba) { var u = new URL(window.location.href); u.searchParams.delete('codprocessamento'); window.location.href = u.toString(); }
  // enviar arquivo (linha selecionada do grid de arquivos ou o leis_modelo.mdb)
  var mEnv = new bootstrap.Modal(document.getElementById('aaModalEnviar')), fEnv = document.querySelector('[data-enviar-form]'), envMsg = fEnv.querySelector('[data-enviar-msg]');
  function abrirEnvio(b) {
    fEnv.reset(); envMsg.textContent = ''; envMsg.className = 'small mt-2';
    fEnv.tipo.value = b.getAttribute('data-tipo'); fEnv.codempresa.value = b.getAttribute('data-codempresa') || 0; fEnv.codunidade.value = b.getAttribute('data-codunidade') || 0;
    fEnv.querySelector('[data-enviar-rotulo]').textContent = b.getAttribute('data-rotulo'); fEnv.querySelector('[data-enviar-mdb]').textContent = b.getAttribute('data-mdb');
    fEnv.setAttribute('data-esperado', b.getAttribute('data-mdb') || ''); mEnv.show();
  }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-linha-acao="enviar"], [data-enviar-modelo]');
    if (b) { abrirEnvio(b); return; }
    var d = ev.target.closest('[data-linha-acao="baixar"], [data-linha-acao="zip"]');
    if (d) { window.location.href = d.getAttribute('data-href'); }
  });
  fEnv.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var arq = fEnv.arquivo.files[0];
    if (!arq) { envMsg.className = 'small mt-2 text-danger'; envMsg.textContent = T.escolha; return; }
    var esperado = fEnv.getAttribute('data-esperado');
    if (fEnv.tipo.value === 'UNIDADE' && esperado && arq.name.toLowerCase() !== esperado.toLowerCase()) { envMsg.className = 'small mt-2 text-danger'; envMsg.textContent = T.nomeDif; return; }
    // envio em partes de 4 MB (os .mdb passam do limite de upload do servidor)
    var TAM = 4 * 1024 * 1024, total = Math.max(1, Math.ceil(arq.size / TAM)), id = Array.from(crypto.getRandomValues(new Uint8Array(12)), function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    var bt = fEnv.querySelector('[type=submit]'); bt.disabled = true; envMsg.className = 'small mt-2 text-muted';
    function parte(i) {
      envMsg.textContent = T.enviando + ' ' + Math.round(i * 100 / total) + '%';
      var fd = new FormData();
      ['tipo', 'codempresa', 'codunidade'].forEach(function (k) { fd.append(k, fEnv[k].value); });
      fd.append('acao', 'enviar_parte'); fd.append('upload_id', id); fd.append('parte', i); fd.append('total', total); fd.append('offset', i * TAM); fd.append('nome', arq.name);
      fd.append('pedaco', arq.slice(i * TAM, Math.min(arq.size, (i + 1) * TAM)), 'parte.bin');
      post(fd).then(function (j) {
        if (!j.ok) { bt.disabled = false; envMsg.className = 'small mt-2 text-danger'; envMsg.textContent = j.message || T.erro; return; }
        if (i + 1 < total) { parte(i + 1); return; }
        bt.disabled = false; mEnv.hide(); msg(j.message, true); setTimeout(recarregar, 1200);
      });
    }
    parte(0);
  });
  // processar (barra do grid de processamentos)
  var mProc = new bootstrap.Modal(document.getElementById('aaModalProcessar')), fProc = document.querySelector('[data-processar-form]'), modo = 'SIMULAR';
  document.querySelectorAll('[data-processar]').forEach(function (b) {
    b.addEventListener('click', function () {
      modo = b.getAttribute('data-processar');
      document.querySelector('#aaModalProcessar [data-processar-titulo]').textContent = modo === 'APLICAR' ? T.atu : T.sim;
      fProc.querySelector('[data-processar-ok]').textContent = modo === 'APLICAR' ? T.okAtu : T.okSim;
      fProc.querySelector('[data-processar-aviso]').hidden = modo !== 'APLICAR';
      mProc.show();
    });
  });
  fProc.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (!fProc.querySelector('input[name="empresas[]"]:checked')) { msg(T.semEmp, false); return; }
    var fd = new FormData(fProc); fd.append('acao', 'processar'); fd.append('modo', modo);
    var bt = fProc.querySelector('[data-processar-ok]'); bt.disabled = true;
    post(fd).then(function (j) {
      bt.disabled = false; mProc.hide(); msg(j.message, !!j.ok);
      if (j.ok) setTimeout(recarregar, 1500);
    });
  });
});
</script>
<?php if ($emAndamento): ?><script>/* 24/11/2026: com processamento na fila ou em andamento, o grid acompanha sozinho (a cada 15 s), sem atrapalhar quem está usando a tela */
setInterval(function () { if (document.hidden || document.querySelector('.modal.show')) return; var p = document.querySelector('[data-subaba-painel]:not([hidden]) #accessProcessamentos'); if (p || !document.querySelector('[data-subaba-painel]')) window.location.reload(); }, 15000);</script><?php endif; ?>
</body>
</html>
