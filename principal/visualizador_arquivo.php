<?php
declare(strict_types=1);

/*
 * Visualizador de arquivos do GED (Pesquisa de documentos, janela de Arquivos e links diretos).
 * Tenta mostrar qualquer arquivo, sem a barra do navegador (sem Salvar e sem Imprimir):
 *   PDF (PDF.js, páginas em canvas), imagens, texto (TXT, CSV em tabela, JSON, XML...), áudio e vídeo direto no navegador;
 *   Word, PowerPoint, RTF, ODF, Visio, TIFF... e desenhos DWG/DXF em PDF, e planilhas em tabela navegável (uma aba por
 *   planilha), convertidos pelo serviço agendado scripts/visualizador-worker.php (Agendador do Windows; a tela só pede e
 *   acompanha: api/gestao_arquivos.php action=previa_estado / previa, classes/VisualizadorPrevia.php).
 * Com a permissão "Baixar/imprimir arquivos" (pode_baixar_arquivos, migração 266) aparecem os botões Baixar e Imprimir.
 * Parâmetro u = endereço do arquivo em api/gestao_arquivos.php (tipo, valor, arquivo...); o conteúdo vem de action=visualizar,
 * que confere de novo as permissões e a unidade do registro. zoom = % (100 = largura da tela), usado pela janela de Arquivos.
 */

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/GedPermissaoTipo.php';
require_once dirname(__DIR__) . '/classes/VisualizadorPrevia.php';
$t = static fn(string $k, array $p): string => IdiomaService::t('visualizador.' . $k, $p);
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit($h($t('sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.']))); }

// Endereço do arquivo: só api/gestao_arquivos.php, remontado aqui (action=visualizar / download)
$u = (string)($_GET['u'] ?? '');
$partes = parse_url($u);
parse_str((string)($partes['query'] ?? ''), $q);
if (basename((string)($partes['path'] ?? '')) !== 'gestao_arquivos.php' || empty($q['tipo']) || empty($q['valor']) || (string)($q['arquivo'] ?? '') === '') {
    http_response_code(400); exit($h($t('invalido', ['pt' => 'Arquivo inválido.', 'en' => 'Invalid file.', 'es' => 'Archivo inválido.'])));
}
unset($q['action']);
$tipo = strtolower(trim((string)$q['tipo']));
$arquivo = basename((string)$q['arquivo']);
$permissao = GedPermissaoTipo::obter($tipo);
if (empty($permissao['pode_visualizar'])) { http_response_code(403); exit($h($t('sem_permissao', ['pt' => 'Sem permissão para visualizar arquivos.', 'en' => 'Not allowed to view files.', 'es' => 'Sin permiso para ver archivos.']))); }
$podeBaixar = !empty($permissao['pode_baixar_arquivos']);
// Dono do arquivo (quem enviou): pode baixar e imprimir o que enviou, mesmo sem a permissão no perfil (a API confere de novo).
if (!$podeBaixar && !empty($_SESSION['tenant']['banco_dados'])) {
    try {
        require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
        require_once dirname(__DIR__) . '/classes/TenantContext.php';
        $tenantDono = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
        $podeBaixar = isset(GedPermissaoTipo::arquivosDoUsuario($tenantDono, $tipo, (int)$q['valor'])[$arquivo]);
    } catch (Throwable) {
        $podeBaixar = false;
    }
}
$urlVer = 'api/gestao_arquivos.php?' . http_build_query($q + ['action' => 'visualizar']);
$urlBaixar = $podeBaixar ? 'api/gestao_arquivos.php?' . http_build_query($q + ['action' => 'download']) : '';
$ext = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));
$formato = VisualizadorPrevia::formato($ext);
$alvo = VisualizadorPrevia::alvo($ext);
$urlEstado = $formato === 'previa' ? 'api/gestao_arquivos.php?' . http_build_query($q + ['action' => 'previa_estado']) : '';
$urlPrevia = $formato === 'previa' ? 'api/gestao_arquivos.php?' . http_build_query($q + ['action' => 'previa']) : '';
$cad = in_array($ext, VisualizadorPrevia::CAD, true);
$icone = match ($formato) {
    'pdf' => 'bi-file-earmark-pdf', 'imagem' => 'bi-file-earmark-image', 'texto' => in_array($ext, ['csv', 'tsv'], true) ? 'bi-filetype-csv' : 'bi-file-earmark-text',
    'audio' => 'bi-file-earmark-music', 'video' => 'bi-file-earmark-play',
    'previa' => $cad ? 'bi-rulers' : ($alvo === 'html' ? 'bi-file-earmark-spreadsheet' : (in_array($ext, ['ppt', 'pptx', 'pptm', 'pps', 'ppsx', 'odp', 'key'], true) ? 'bi-file-earmark-slides' : 'bi-file-earmark-richtext')),
    default => 'bi-file-earmark',
};
$temZoom = in_array($formato, ['pdf', 'imagem', 'texto', 'previa'], true);
$zoom = max(0, min(400, (int)($_GET['zoom'] ?? 0)));
$T = [
    'carregando' => $t('carregando', ['pt' => 'Carregando documento...', 'en' => 'Loading document...', 'es' => 'Cargando documento...']),
    'erro' => $t('erro', ['pt' => 'Não foi possível exibir o arquivo.', 'en' => 'Could not display the file.', 'es' => 'No fue posible mostrar el archivo.']),
    'sem_formato' => $t('sem_formato', ['pt' => 'Este formato não tem visualização no sistema.', 'en' => 'This format has no in-app preview.', 'es' => 'Este formato no tiene vista previa en el sistema.']),
    'sem_formato_baixar' => $t('sem_formato_baixar', ['pt' => 'Use o botão Baixar para abrir o arquivo no seu computador.', 'en' => 'Use the Download button to open the file on your computer.', 'es' => 'Use el botón Descargar para abrir el archivo en su computadora.']),
    'sem_formato_permissao' => $t('sem_formato_permissao', ['pt' => 'O seu perfil não tem permissão para baixar arquivos. Peça ao administrador do sistema, se precisar.', 'en' => 'Your profile is not allowed to download files. Ask the system administrator if you need it.', 'es' => 'Su perfil no tiene permiso para descargar archivos. Pida al administrador del sistema si lo necesita.']),
    'bloqueado' => $t('bloqueado', ['pt' => 'O seu perfil não tem permissão para salvar ou imprimir arquivos.', 'en' => 'Your profile is not allowed to save or print files.', 'es' => 'Su perfil no tiene permiso para guardar o imprimir archivos.']),
    'baixar' => $t('baixar', ['pt' => 'Baixar', 'en' => 'Download', 'es' => 'Descargar']),
    'imprimir' => $t('imprimir', ['pt' => 'Imprimir', 'en' => 'Print', 'es' => 'Imprimir']),
    'menos' => $t('menos', ['pt' => 'Diminuir', 'en' => 'Zoom out', 'es' => 'Reducir']),
    'mais' => $t('mais', ['pt' => 'Aumentar', 'en' => 'Zoom in', 'es' => 'Aumentar']),
    'ajustar' => $t('ajustar', ['pt' => 'Ajustar à largura', 'en' => 'Fit width', 'es' => 'Ajustar al ancho']),
    'pagina' => $t('pagina', ['pt' => 'Página', 'en' => 'Page', 'es' => 'Página']),
    'preparando' => $t('preparando', ['pt' => 'Preparando a visualização do documento...', 'en' => 'Preparing the document preview...', 'es' => 'Preparando la vista previa del documento...']),
    'preparando_detalhe' => $t('preparando_detalhe', ['pt' => 'O servidor está convertendo o arquivo. Na primeira vez pode levar até um minuto; depois abre na hora.', 'en' => 'The server is converting the file. The first time it may take up to a minute; after that it opens instantly.', 'es' => 'El servidor está convirtiendo el archivo. La primera vez puede tardar hasta un minuto; después se abre al instante.']),
    'fila' => $t('fila', ['pt' => 'Arquivos na frente na fila de conversão: {n}', 'en' => 'Files ahead in the conversion queue: {n}', 'es' => 'Archivos por delante en la cola de conversión: {n}']),
    'convertendo' => $t('convertendo', ['pt' => 'Convertendo agora...', 'en' => 'Converting now...', 'es' => 'Convirtiendo ahora...']),
    'parado' => $t('parado', ['pt' => 'A conversão está demorando mais que o normal. O serviço de conversão (Agendador de Tarefas do Windows) pode estar parado: avise o administrador do sistema.', 'en' => 'The conversion is taking longer than usual. The conversion service (Windows Task Scheduler) may be stopped: tell the system administrator.', 'es' => 'La conversión está tardando más de lo normal. El servicio de conversión (Programador de tareas de Windows) puede estar detenido: avise al administrador del sistema.']),
    'erro_conversao' => $t('erro_conversao', ['pt' => 'Não foi possível converter o arquivo para visualização.', 'en' => 'The file could not be converted for preview.', 'es' => 'No fue posible convertir el archivo para la vista previa.']),
    'previa_cad' => $t('previa_cad', ['pt' => 'Prévia gerada pelo sistema a partir do desenho. Para medidas, camadas e detalhes, use o arquivo original no programa de CAD.', 'en' => 'Preview generated by the system from the drawing. For measurements, layers and details, use the original file in your CAD software.', 'es' => 'Vista previa generada por el sistema a partir del dibujo. Para medidas, capas y detalles, use el archivo original en el programa CAD.']),
    'pdf_original' => $t('pdf_original', ['pt' => 'Mostrando o PDF do mesmo desenho que está no GED: {arquivo}', 'en' => 'Showing the PDF of the same drawing stored in the document manager: {arquivo}', 'es' => 'Mostrando el PDF del mismo dibujo guardado en el gestor documental: {arquivo}']),
    'previa_office' => $t('previa_office', ['pt' => 'Prévia convertida pelo sistema; a formatação pode ter pequenas diferenças do original.', 'en' => 'Preview converted by the system; formatting may differ slightly from the original.', 'es' => 'Vista previa convertida por el sistema; el formato puede tener pequeñas diferencias con el original.']),
    'texto_grande' => $t('texto_grande', ['pt' => 'Arquivo grande: mostrando só o começo ({tamanho}).', 'en' => 'Large file: showing only the beginning ({tamanho}).', 'es' => 'Archivo grande: mostrando solo el comienzo ({tamanho}).']),
    'linhas_limitadas' => $t('linhas_limitadas', ['pt' => 'Mostrando as primeiras {n} linhas.', 'en' => 'Showing the first {n} rows.', 'es' => 'Mostrando las primeras {n} filas.']),
    'sem_tabelas' => $t('sem_tabelas', ['pt' => 'A planilha não tem dados para mostrar.', 'en' => 'The spreadsheet has no data to show.', 'es' => 'La hoja de cálculo no tiene datos para mostrar.']),
    'aba' => $t('aba', ['pt' => 'Planilha', 'en' => 'Sheet', 'es' => 'Hoja']),
    'quebra' => $t('quebra', ['pt' => 'Quebrar linhas', 'en' => 'Wrap lines', 'es' => 'Ajustar líneas']),
];
?><!doctype html>
<html lang="<?= IdiomaService::idioma() === 'pt' ? 'pt-BR' : $h(IdiomaService::idioma()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($arquivo) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2">
<link rel="stylesheet" href="../assets/css/visualizador-arquivo.css?v=2">
</head>
<body class="va<?= $podeBaixar ? '' : ' va-protegido' ?>" data-formato="<?= $h($formato) ?>" data-zoom="<?= $zoom ?>">
<header class="va-barra">
    <span class="va-nome" title="<?= $h($arquivo) ?>"><i class="bi <?= $h($icone) ?>" aria-hidden="true"></i> <?= $h($arquivo) ?></span>
    <span class="va-paginas" data-va-paginas hidden></span>
    <?php if ($formato === 'texto'): ?>
    <label class="va-quebra" data-va-quebra-rotulo hidden><input type="checkbox" data-va-quebra checked> <?= $h($T['quebra']) ?></label>
    <?php endif; ?>
    <?php if ($temZoom): ?>
    <span class="va-zoom">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-va-zoom="-" title="<?= $h($T['menos']) ?>" aria-label="<?= $h($T['menos']) ?>"><i class="bi bi-zoom-out"></i></button>
        <span class="va-zoom-valor" data-va-zoom-valor>100%</span>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-va-zoom="+" title="<?= $h($T['mais']) ?>" aria-label="<?= $h($T['mais']) ?>"><i class="bi bi-zoom-in"></i></button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-va-zoom="0" title="<?= $h($T['ajustar']) ?>" aria-label="<?= $h($T['ajustar']) ?>"><i class="bi bi-arrows-angle-expand"></i></button>
    </span>
    <?php endif; ?>
    <?php if ($podeBaixar): ?>
    <span class="va-acoes">
        <a class="btn btn-sm btn-outline-primary" href="<?= $h($urlBaixar) ?>" download="<?= $h($arquivo) ?>"><i class="bi bi-download" aria-hidden="true"></i> <?= $h($T['baixar']) ?></a>
        <?php if ($temZoom): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-va-imprimir><i class="bi bi-printer" aria-hidden="true"></i> <?= $h($T['imprimir']) ?></button><?php endif; ?>
    </span>
    <?php endif; ?>
</header>
<div class="va-aviso" data-va-aviso role="status" hidden></div>
<div class="va-faixa" data-va-faixa hidden></div>
<main class="va-area" data-va-area>
    <?php if ($formato === 'imagem'): ?>
    <img class="va-imagem" data-va-imagem src="<?= $h($urlVer) ?>" alt="<?= $h($arquivo) ?>" draggable="false">
    <?php elseif ($formato === 'pdf' || $formato === 'previa'): ?>
    <div class="va-pdf" data-va-pdf></div>
    <div class="va-planilha" data-va-planilha hidden></div>
    <div class="va-mensagem" data-va-mensagem><?php if ($formato === 'previa'): ?><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> <?= $h($T['preparando']) ?><?php else: ?><?= $h($T['carregando']) ?><?php endif; ?></div>
    <?php elseif ($formato === 'texto'): ?>
    <div class="va-texto" data-va-texto></div>
    <div class="va-mensagem" data-va-mensagem><?= $h($T['carregando']) ?></div>
    <?php elseif ($formato === 'audio'): ?>
    <div class="va-midia"><i class="bi bi-music-note-beamed" aria-hidden="true"></i><br><audio controls preload="metadata" controlsList="nodownload" src="<?= $h($urlVer) ?>"></audio></div>
    <?php elseif ($formato === 'video'): ?>
    <div class="va-midia"><video controls preload="metadata" controlsList="nodownload noremoteplayback" disablePictureInPicture playsinline src="<?= $h($urlVer) ?>"></video></div>
    <?php else: ?>
    <div class="va-mensagem"><i class="bi bi-file-earmark" aria-hidden="true"></i><br><?= $h($T['sem_formato']) ?><br><?= $h($podeBaixar ? $T['sem_formato_baixar'] : $T['sem_formato_permissao']) ?></div>
    <?php endif; ?>
</main>
<div class="va-impressao-bloqueada"><?= $h($T['bloqueado']) ?></div>
<script>window.VA = <?= json_encode(['url' => $urlVer, 'estado' => $urlEstado, 'previa' => $urlPrevia, 'extensao' => $ext, 'alvo' => $alvo, 'cad' => $cad, 'baixar' => $podeBaixar, 'textos' => $T], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="../assets/js/visualizador-arquivo.js?v=3"></script>
</body>
</html>
