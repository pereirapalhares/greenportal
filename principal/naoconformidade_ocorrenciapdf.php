<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
// c491: conexão e permissão pelo NaoConformidadeWorkflow (antes lia o .env por conta própria); quem não vê a lista geral
// inteira só gera o PDF das ocorrências de que participa. Leituras por views (vw_nc_*, migração 321).
require_once dirname(__DIR__) . '/classes/NaoConformidadeWorkflow.php';
[$pdo, $ncUsuario, $ncPermissoes] = NaoConformidadeWorkflow::iniciar();

$codigo = filter_input(INPUT_GET, 'codigo', FILTER_VALIDATE_INT);
if (!$codigo || $codigo < 1) {
    http_response_code(400);
    exit('Codigo da ocorrencia invalido.');
}
if (!NaoConformidadeWorkflow::podeVer($pdo, $ncUsuario, (int)$codigo, $ncPermissoes)) {
    http_response_code(403);
    exit('Seu perfil nao tem permissao para visualizar esta ocorrencia.');
}

// c526: relatório completo, com as mesmas informações das abas da tela (NaoConformidadeRelatorio) e o mesmo layout para todos os tipos
require_once dirname(__DIR__) . '/classes/NaoConformidadeRelatorio.php';
require_once dirname(__DIR__) . '/classes/AuditService.php';
require_once __DIR__ . '/naoconformidade_relatoriopdftemplate.php';
$relatorio = NaoConformidadeRelatorio::montar($pdo, (int)$codigo, GreenPortalDatabase::env(), $_SESSION);
if (!$relatorio) {
    http_response_code(404);
    exit('Ocorrencia nao encontrada.');
}
$templateHtml = renderNaoConformidadeRelatorioPdf($relatorio);
try { AuditService::record($pdo, 'naoconformidades_ocorrencia', 'PDF', 'SUCESSO', 'nc_indicacao_ocorrencia', (string)(int)$codigo); } catch (Throwable) {}

if (($_GET['formato'] ?? '') === 'html') {
    header('Content-Type: text/html; charset=UTF-8');
    echo $templateHtml;
    echo '<script>window.addEventListener("load", function () { setTimeout(function () { window.print(); }, 350); });</script>';
    exit;
}

$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
    $pdfOptions = new Dompdf\Options();
    $pdfOptions->set('isRemoteEnabled', false);
    $pdfOptions->set('defaultFont', 'Arial');
    $dompdf = new Dompdf\Dompdf($pdfOptions);
    $dompdf->loadHtml($templateHtml, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $pdf = $dompdf->output();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="nao_conformidade_' . (int)$codigo . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

// Sem o Dompdf: o mesmo relatório em HTML, já abrindo a impressão (salvar como PDF)
header('Content-Type: text/html; charset=UTF-8');
echo $templateHtml;
echo '<script>window.addEventListener("load", function () { setTimeout(function () { window.print(); }, 350); });</script>';
