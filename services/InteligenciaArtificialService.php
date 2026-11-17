<?php
declare(strict_types=1);

// Compatibilidade de arquivo: o serviço HTTP foi desativado.
// A IA é processada exclusivamente por scripts/ia-worker.php no Agendador.
if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "Servico HTTP legado desativado; use scripts/ia-worker.php.\n");
    exit(2);
}
http_response_code(410);
echo 'Serviço HTTP legado desativado.';
