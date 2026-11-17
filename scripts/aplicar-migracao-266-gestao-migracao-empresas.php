<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/EmpresaMigracaoService.php';

$service = new EmpresaMigracaoService(GreenPortalDatabase::env());
try {
    $central = $service->central();
    $service->garantirEstrutura($central);
    $service->garantirMenu($central);
    $result = $service->sincronizar();
    echo json_encode(['ok'=>true,'migracao'=>266] + $result, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
