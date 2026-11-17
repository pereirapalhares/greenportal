<?php
declare(strict_types=1);

require_once __DIR__ . '/IaTaximetro.php';

/**
 * Chamada de conversa à IA (OpenAI, configurada no .env como as demais análises do portal) para o
 * assistente da aplicação. Recebe as mensagens já montadas (sistema, histórico e pergunta) e devolve o
 * JSON da resposta com o modelo usado e o consumo de tokens.
 */
final class InteligenciaArtificialAssistente
{
    /** @param list<array{role:string,content:string}> $mensagens */
    public static function conversar(array $mensagens, int $maxTokens = 900): array
    {
        $env = self::env();
        $key = (string)($env['GPT_AI_API_KEY'] ?? '');
        $url = rtrim((string)($env['GPT_AI_API_URL'] ?? 'https://api.openai.com/v1'), '/');
        $model = (string)($env['GPT_AI_ASSISTANT_MODEL'] ?? $env['GPT_AI_MODEL'] ?? 'gpt-4o-mini');
        $credentials = (string)($env['GPT_AI_CREDENTIALS_FILE'] ?? dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'greengde' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php');
        if (is_file($credentials)) {
            $source = (string)file_get_contents($credentials);
            if ($key === '' && preg_match('/\$apiKey\s*=\s*[\'"]([^\'"]+)[\'"]/i', $source, $m)) $key = trim($m[1]);
            if ($model === 'gpt-4o-mini' && empty($env['GPT_AI_ASSISTANT_MODEL']) && preg_match('/define\s*\(\s*[\'"]OPENAI_MODEL[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/i', $source, $m)) $model = trim($m[1]);
        }
        if ($key === '') throw new RuntimeException('Serviço de IA não configurado.');

        $payload = ['model' => $model, 'temperature' => 0.2, 'max_tokens' => $maxTokens, 'response_format' => ['type' => 'json_object'], 'messages' => $mensagens];
        $ch = curl_init($url . '/chat/completions');
        $options = [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ];
        $cacert = self::cacert();
        if ($cacert !== null) { $options[CURLOPT_SSL_VERIFYPEER] = true; $options[CURLOPT_SSL_VERIFYHOST] = 2; $options[CURLOPT_CAINFO] = $cacert; }
        curl_setopt_array($ch, $options);
        $inicio = microtime(true);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        // Migração 340: taxímetro de IA (o contexto — assistente ou solicitação de melhoria — é informado por quem chama)
        $ms = (int)round((microtime(true) - $inicio) * 1000);
        if ($raw === false || $error !== '') { IaTaximetro::registrar(null, $model, $ms, false, 'Falha na comunicação: ' . $error); throw new RuntimeException('Falha na comunicação com a IA: ' . ($error ?: 'resposta vazia')); }
        $response = json_decode((string)$raw, true);
        if ($status < 200 || $status >= 300) {
            $msg = 'O serviço de IA retornou HTTP ' . $status . (!empty($response['error']['message']) ? ': ' . substr((string)$response['error']['message'], 0, 240) : '.');
            IaTaximetro::registrar(is_array($response) ? $response : null, $model, $ms, false, $msg);
            throw new RuntimeException($msg);
        }
        $content = (string)($response['choices'][0]['message']['content'] ?? '');
        $data = json_decode($content, true);
        IaTaximetro::registrar(is_array($response) ? $response : null, $model, $ms, is_array($data), is_array($data) ? null : 'Resposta em formato inválido.');
        if (!is_array($data)) throw new RuntimeException('A IA retornou uma resposta em formato inválido.');
        return [
            'dados' => $data,
            'modelo' => (string)($response['model'] ?? $model),
            'tokens_entrada' => isset($response['usage']['prompt_tokens']) ? (int)$response['usage']['prompt_tokens'] : null,
            'tokens_saida' => isset($response['usage']['completion_tokens']) ? (int)$response['usage']['completion_tokens'] : null,
            'duracao_ms' => (int)round((microtime(true) - $inicio) * 1000),
        ];
    }

    private static function env(): array
    {
        $r = [];
        foreach (file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $r[trim($k)] = trim(trim($v), '"\'');
        }
        return $r;
    }

    private static function cacert(): ?string
    {
        foreach (['E:\\wamp\\apps\\phpmyadmin5.2.3\\vendor\\composer\\ca-bundle\\res\\cacert.pem', 'C:\\wamp\\bin\\php\\cacert.pem', 'C:\\wamp64\\bin\\php\\cacert.pem', 'C:\\php\\cacert.pem', php_ini_loaded_file() ? dirname((string)php_ini_loaded_file()) . DIRECTORY_SEPARATOR . 'cacert.pem' : ''] as $candidate) {
            if ($candidate !== '' && is_file($candidate)) return $candidate;
        }
        return null;
    }
}
