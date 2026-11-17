<?php
declare(strict_types=1);

$status = (int)($_GET['status'] ?? $_SERVER['REDIRECT_STATUS'] ?? 500);
$allowed = [400, 403, 404, 500, 502, 503];
if (!in_array($status, $allowed, true)) $status = 500;
http_response_code($status);
if (function_exists('header_remove')) header_remove('X-Powered-By');
$messages = [
    400 => ['pt' => 'A solicitação não pôde ser processada.', 'en' => 'The request could not be processed.', 'es' => 'La solicitud no pudo ser procesada.'],
    403 => ['pt' => 'Você não tem permissão para acessar este recurso.', 'en' => 'You do not have permission to access this resource.', 'es' => 'No tiene permiso para acceder a este recurso.'],
    404 => ['pt' => 'O recurso solicitado não foi encontrado.', 'en' => 'The requested resource was not found.', 'es' => 'No se encontró el recurso solicitado.'],
    500 => ['pt' => 'Ocorreu um erro interno ao processar a solicitação.', 'en' => 'An internal error occurred while processing the request.', 'es' => 'Ocurrió un error interno al procesar la solicitud.'],
    502 => ['pt' => 'O serviço intermediário não respondeu corretamente.', 'en' => 'The intermediate service did not respond correctly.', 'es' => 'El servicio intermedio no respondió correctamente.'],
    503 => ['pt' => 'O serviço está temporariamente indisponível.', 'en' => 'The service is temporarily unavailable.', 'es' => 'El servicio no está disponible temporalmente.'],
];
$titulo = ['pt' => 'Erro', 'en' => 'Error', 'es' => 'Error'];
$voltar = ['pt' => 'Voltar ao Green Portal', 'en' => 'Back to Green Portal', 'es' => 'Volver a Green Portal'];
$idioma = 'pt';
// Textos de Administração › Textos e idiomas, no idioma do usuário; se algo falhar aqui, fica o português.
try {
    require_once __DIR__ . '/classes/IdiomaService.php';
    $idioma = IdiomaService::idioma();
    $mensagem = IdiomaService::t('comum.http.' . $status, $messages[$status]);
    $titulo = IdiomaService::t('comum.http.titulo', $titulo);
    $voltar = IdiomaService::t('comum.http.voltar', $voltar);
} catch (Throwable) {
    $mensagem = $messages[$status]['pt'];
    $titulo = $titulo['pt'];
    $voltar = $voltar['pt'];
}
$e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="<?= $e($idioma === 'pt' ? 'pt-BR' : $idioma) ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Green Portal - <?= $e($titulo) ?> <?= $status ?></title>
<style>body{margin:0;background:var(--gp-primary-soft);color:var(--gp-text);font:13px 'DM Sans',system-ui,-apple-system,'Segoe UI',sans-serif;display:grid;place-items:center;min-height:100vh}.card{max-width:520px;margin:24px;padding:32px;border:1px solid var(--gp-border);border-radius:12px;background:#fff;box-shadow:0 6px 24px #183d2c18;text-align:center}h1{margin:0 0 12px;color:var(--gp-primary-dark);font-size:28px}p{line-height:1.5}a{display:inline-block;margin-top:12px;padding:10px 16px;border-radius:6px;background:var(--gp-primary);color:#fff;text-decoration:none}</style><?php require_once __DIR__ . '/components/pagina_padrao.php'; greenPortalEstiloPagina('.'); ?></head>
<body><main class="card"><h1><?= $status ?></h1><p><?= $e($mensagem) ?></p><a href="/greenportal/"><?= $e($voltar) ?></a></main></body>
</html>
