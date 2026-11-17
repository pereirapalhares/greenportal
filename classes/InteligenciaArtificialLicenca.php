<?php
declare(strict_types=1);

require_once __DIR__ . '/IaTaximetro.php';

final class InteligenciaArtificialLicenca
{
    public static function analisar(string $texto, string $idioma, PDO $pdo): array
    {
        $env = self::env();
        $url = rtrim((string)($env['GPT_AI_API_URL'] ?? ''), '/');
        $key = (string)($env['GPT_AI_API_KEY'] ?? '');
        $model = (string)($env['GPT_AI_MODEL'] ?? 'gpt-4o-mini');
        $credentialsFile = (string)($env['GPT_AI_CREDENTIALS_FILE'] ?? dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'greengde' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php');
        if (($key === '' || $model === 'gpt-4o-mini') && is_file($credentialsFile)) {
            $source = (string)file_get_contents($credentialsFile);
            if ($key === '' && preg_match('/\$apiKey\s*=\s*[\'\"]([^\'\"]+)[\'\"]/i', $source, $match)) $key = trim($match[1]);
            if ($key === '' && preg_match('/define\s*\(\s*[\'\"]OPENAI_API_KEY[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/i', $source, $match)) $key = trim($match[1]);
            if ($model === 'gpt-4o-mini' && preg_match('/define\s*\(\s*[\'\"]OPENAI_MODEL[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/i', $source, $match)) $model = trim($match[1]);
        }
        if ($url === '') $url = 'https://api.openai.com/v1';
        if ($key === '') throw new RuntimeException('Serviço de IA não configurado.');
        IaTaximetro::exigirDisponivel($pdo); // migração 340: limite mensal de IA da empresa
        $promptRows = GreenPortalDatabase::call($pdo, 'CALL sp_ia_prompt_obter(:codigo,:idioma)', ['codigo' => 'LI_LICENCA_DOCUMENTO', 'idioma' => $idioma]);
        $prompt = trim((string)($promptRows[0]['prompt'] ?? ''));
        if ($prompt === '') throw new RuntimeException('Prompt de extração de licença não configurado.');
        $texto = trim($texto);
        if ($texto === '') throw new RuntimeException('Não foi possível extrair texto do documento.');
        $texto = function_exists('mb_substr') ? mb_substr($texto, 0, 120000, 'UTF-8') : substr($texto, 0, 120000);
        $language = ['pt' => 'português', 'en' => 'inglês', 'es' => 'espanhol'][$idioma] ?? 'português';
        $payload = ['model' => $model, 'temperature' => 0.1, 'max_tokens' => 1200, 'response_format' => ['type' => 'json_object'], 'messages' => [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => "Idioma de resposta: {$language}. Analise o texto extraído do documento abaixo e retorne somente o JSON solicitado pelo prompt.\n\n" . $texto]
        ]];
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedPayload === false) throw new RuntimeException('Não foi possível codificar o texto extraído para envio à IA: ' . json_last_error_msg());
        $ch = curl_init($url . '/chat/completions');
        $curlOptions = [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'], CURLOPT_POSTFIELDS => $encodedPayload];
        $cacert = self::cacert();
        if ($cacert !== null) { $curlOptions[CURLOPT_SSL_VERIFYPEER] = true; $curlOptions[CURLOPT_SSL_VERIFYHOST] = 2; $curlOptions[CURLOPT_CAINFO] = $cacert; }
        curl_setopt_array($ch, $curlOptions);
        $inicio = microtime(true);
        $rawResponse = curl_exec($ch); $error = curl_error($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        // Migração 340: taxímetro de IA (sem contexto informado, vale o banco e o usuário da sessão)
        $ms = (int)round((microtime(true) - $inicio) * 1000);
        IaTaximetro::contexto($pdo, ['tipo' => 'LICENCA_DOCUMENTO', 'entidade' => 'li_licencas', 'codusuario' => (int)($_SESSION['usuario']['codusuario'] ?? 0), 'origem' => PHP_SAPI === 'cli' ? 'FILA' : 'TELA']);
        if ($rawResponse === false || $error !== '') { IaTaximetro::registrar(null, $model, $ms, false, 'Falha na comunicação: ' . $error); IaTaximetro::limpar(); error_log('IA licença falhou transporte: ' . $error); throw new RuntimeException('Falha na comunicação com a IA: ' . $error); }
        $response = json_decode((string)$rawResponse, true);
        if ($status < 200 || $status >= 300) {
            $providerMessage = trim((string)($response['error']['message'] ?? ''));
            if ($providerMessage !== '') $providerMessage = function_exists('mb_substr') ? mb_substr($providerMessage, 0, 240, 'UTF-8') : substr($providerMessage, 0, 240);
            error_log('IA licença retornou HTTP ' . $status . ($providerMessage !== '' ? ': ' . $providerMessage : ''));
            IaTaximetro::registrar(is_array($response) ? $response : null, $model, $ms, false, 'HTTP ' . $status); IaTaximetro::limpar();
            throw new RuntimeException('O serviço de IA retornou HTTP ' . $status . ($providerMessage !== '' ? ': ' . $providerMessage : '.'));
        }
        $content = (string)($response['choices'][0]['message']['content'] ?? '');
        $suggestion = json_decode($content, true);
        IaTaximetro::registrar(is_array($response) ? $response : null, $model, $ms, is_array($suggestion), is_array($suggestion) ? null : 'JSON inválido.');
        IaTaximetro::limpar();
        if (!is_array($suggestion)) throw new RuntimeException('A IA retornou um JSON inválido.');
        return ['sugestao' => self::normalizar($suggestion), 'texto_bytes' => strlen($texto)];
    }

    private static function normalizar(array $data): array
    {
        $alerts = $data['alertas'] ?? [];
        if (!is_array($alerts)) $alerts = [$alerts];
        return ['descricao' => self::value($data, 'descricao'), 'data_validade' => self::date($data['data_validade'] ?? null), 'atividade' => self::value($data, 'atividade'), 'orgao_emissor' => self::value($data, 'orgao_emissor'), 'numero_licenca' => self::value($data, 'numero_licenca'), 'observacao' => self::value($data, 'observacao'), 'confianca' => self::percentual($data['confianca'] ?? 0), 'alertas' => array_values(array_filter(array_map('strval', $alerts)))];
    }
    private static function percentual(mixed $value): float
    {
        $confidence = (float)$value;
        if ($confidence > 0 && $confidence <= 1) $confidence *= 100;
        return round(max(0, min(100, $confidence)), 2);
    }
    private static function value(array $data, string $key): ?string { $value = trim((string)($data[$key] ?? '')); return $value === '' ? null : $value; }
    private static function date(mixed $value): ?string { $value = trim((string)$value); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null; }
    private static function env(): array { $result = []; foreach (file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) { $line = trim($line); if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue; [$key, $value] = explode('=', $line, 2); $result[trim($key)] = trim(trim($value), "\"'"); } return $result; }
    private static function cacert(): ?string { foreach (['E:\\wamp\\apps\\phpmyadmin5.2.3\\vendor\\composer\\ca-bundle\\res\\cacert.pem', 'C:\\wamp\\bin\\php\\cacert.pem', 'C:\\wamp64\\bin\\php\\cacert.pem', 'C:\\php\\cacert.pem', php_ini_loaded_file() ? dirname((string)php_ini_loaded_file()) . DIRECTORY_SEPARATOR . 'cacert.pem' : ''] as $candidate) if ($candidate !== '' && is_file($candidate)) return $candidate; return null; }
}
