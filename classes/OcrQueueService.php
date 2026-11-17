<?php
declare(strict_types=1);

final class OcrQueueService
{
    public function __construct(private readonly PDO $pdo, private readonly array $env, private readonly string $processor = 'GreenPortalOcrWorker') {}

    public function enqueue(array $job): array
    {
        $key = trim((string)($job['chave_idempotencia'] ?? ''));
        if ($key === '') throw new InvalidArgumentException('A chave de idempotência do OCR é obrigatória.');
        // Fila de OCR por procedure (idempotente pela chave): devolve o job
        $st = $this->pdo->prepare('CALL sp_ocr_job_enfileirar(:chave,:tipo,:entidade,:registro,:arquivo,:caminho,:tamanho,:usuario)');
        $st->execute([
            'chave' => substr($key, 0, 191),
            'tipo' => substr(strtoupper(trim((string)($job['tipo'] ?? 'DOCUMENTO'))), 0, 50),
            'entidade' => substr(trim((string)($job['entidade'] ?? '')), 0, 100),
            'registro' => substr(trim((string)($job['registro_id'] ?? '')), 0, 100),
            'arquivo' => substr(basename((string)($job['arquivo'] ?? '')), 0, 255),
            'caminho' => substr((string)($job['caminho_arquivo'] ?? ''), 0, 1000),
            'tamanho' => isset($job['tamanho_bytes']) ? (int)$job['tamanho_bytes'] : null,
            'usuario' => !empty($job['codusuario']) ? (int)$job['codusuario'] : null,
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $st->closeCursor();
        if (!$row) throw new RuntimeException('Não foi possível localizar o job de OCR.');
        return $row;
    }

    public function claim(): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare('CALL sp_ocr_job_reservar(:processador)');
            $st->execute(['processador' => $this->processor]);
            $job = $st->fetch(PDO::FETCH_ASSOC);
            $st->closeCursor();
            $this->pdo->commit();
            return $job ?: null;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function processPending(int $limit = 5): int
    {
        $processed = 0;
        while ($processed < max(1, $limit) && ($job = $this->claim()) !== null) {
            try { $result = $this->callProvider($job); $this->complete((int)$job['codjob'], $result); }
            catch (Throwable $e) { $this->fail((int)$job['codjob'], $e->getMessage()); }
            $processed++;
        }
        return $processed;
    }

    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM vw_green_ocr_jobs WHERE codjob=:id');
        $st->execute(['id' => $id]); $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function callProvider(array $job): array
    {
        $path = (string)$job['caminho_arquivo'];
        if (!is_file($path)) throw new RuntimeException('Arquivo não localizado para a leitura de texto (OCR).');
        // 23/10/2026 (regra do usuário: todo serviço de backend roda como PHP no Agendador do Windows, sem portas): a leitura é
        // sempre local, executada por este worker CLI (GreenPortal-OcrWorker): PDF por pdftotext e, sem texto, pdftoppm + tesseract;
        // imagens por tesseract; Word por ZipArchive/LibreOffice (LicenseDocumentExtractionService, ferramentas da pasta tools).
        // O serviço OCR HTTP (OCR_SERVICE_URL / OCR_SERVICE_TOKEN, porta 8091) não é mais usado. A API web só enfileira.
        require_once __DIR__ . '/LicenseDocumentExtractionService.php';
        $root = (string)($this->env['GGED_STORAGE_LOCAL_ROOT'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage');
        $result = (new LicenseDocumentExtractionService($root))->extract($path);
        $texto = trim((string)($result['texto'] ?? ''));
        if ($texto === '') throw new RuntimeException('A leitura não encontrou texto no documento.');
        return ['texto' => $texto, 'paginas' => (int)($result['paginas'] ?? 0), 'confianca' => (float)($result['confianca'] ?? 0)];
    }

    private function complete(int $id, array $result): void
    {
        $st = $this->pdo->prepare('CALL sp_ocr_job_concluir(:id,:texto,:paginas,:confianca)');
        $st->execute(['id'=>$id,'texto'=>$result['texto'],'paginas'=>$result['paginas'],'confianca'=>$result['confianca']]);
        $st->closeCursor();
    }

    private function fail(int $id, string $error): void
    {
        $st = $this->pdo->prepare('CALL sp_ocr_job_falhar(:id,:erro)');
        $st->execute(['id'=>$id,'erro'=>substr($error,0,2000)]);
        $st->closeCursor();
    }
}
