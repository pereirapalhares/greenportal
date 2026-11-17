<?php
declare(strict_types=1);

/*
 * Teste para achar onde os serviços do Agendador travam quando rodam sem console (Agendador / Start-Job).
 * Faz o mesmo que uma execução do serviço de agenda numa empresa. Grava cada passo, com o horário, em storage\logs\teste_travamento_<pid>.log — o último passo gravado mostra onde parou.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\teste-travamento-servico.php [--database=gatlas_dbp]
 */

$log = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'teste_travamento_' . getmypid() . '.log';
$t0 = microtime(true);
$passo = static function (string $msg) use ($log, $t0): void {
    @file_put_contents($log, sprintf("%s +%6.2fs %s\n", date('H:i:s'), microtime(true) - $t0, $msg), FILE_APPEND);
};
$passo('início; PHP ' . PHP_VERSION . '; usuário ' . (getenv('USERNAME') ?: '?') . '; sapi ' . PHP_SAPI . '; stdin ' . (defined('STDIN') ? 'sim' : 'não'));
$banco = 'gatlas_dbp';
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--database=')) $banco = substr($a, 11);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$passo('classe GreenPortalDatabase carregada');
$env = GreenPortalDatabase::env();
$passo('.env lido (' . count($env) . ' chaves)');
try {
    $passo('conectando ao universal');
    $u = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
    $passo('universal conectado; SELECT 1 = ' . $u->query('SELECT 1')->fetchColumn());
    $passo("conectando a $banco");
    $p = GreenPortalDatabase::connect($env, $banco, 'GPT');
    $passo("$banco conectado; agora no MySQL = " . $p->query('SELECT NOW()')->fetchColumn());
    $passo('carregando as classes do windows-service');
    foreach (['WindowsServiceRuntime', 'TaskService', 'AgendaService', 'ResponsibilityService', 'ResponsibilityAgendaService', 'OcrQueueService', 'DocumentAiQueueService',
              'InteligenciaArtificialDocumento', 'AiApprovedTaskService', 'EmailFilaService', 'AlertaEmailService', 'NaoConformidadeEficaciaIa', 'IaTaximetro'] as $c) {
        require_once dirname(__DIR__) . '/classes/' . $c . '.php';
        $passo("  classe $c ok");
    }
    $passo('lista de bancos do serviço');
    $lista = (new WindowsServiceRuntime($env, 'agenda', true))->databases(null);
    $passo('bancos: ' . implode(',', $lista));
    $passo('agenda (só materializa ocorrências vencidas)');
    $n = (new AgendaService($p, new TaskService($p)))->materializeDueOccurrences(1);
    $passo("agenda ok ($n)");
} catch (Throwable $e) { $passo('ERRO: ' . $e->getMessage()); }
$passo('fim');
echo "ok: $log\n";
