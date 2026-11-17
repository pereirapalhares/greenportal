<?php
declare(strict_types=1);

/**
 * Compatibilidade para chamadas legadas. Nenhuma tela web inicia processos.
 * A fila exportacoes_grid e processada exclusivamente por scripts/export-worker.php,
 * executado pela tarefa GreenPortal-ExportWorker no Agendador do Windows.
 */
final class ExportWorkerLauncher
{
    public static function launch(int $jobId, string $database): bool
    {
        return $jobId > 0 && preg_match('/^[A-Za-z0-9_]+$/', $database) === 1;
    }
}
