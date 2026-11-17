<?php
declare(strict_types=1);

require_once __DIR__ . '/IaTaximetro.php';

final class InteligenciaArtificialDocumento
{
    public static function analisar(string $texto, string $prompt, string $idioma): array
    {
        $env = self::env(); $key = (string)($env['GPT_AI_API_KEY'] ?? ''); $url = rtrim((string)($env['GPT_AI_API_URL'] ?? 'https://api.openai.com/v1'), '/'); $model = (string)($env['GPT_AI_MODEL'] ?? 'gpt-4o-mini');
        $credentials = (string)($env['GPT_AI_CREDENTIALS_FILE'] ?? dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'greengde' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php');
        if (is_file($credentials)) { $source = (string)file_get_contents($credentials); if ($key === '' && preg_match('/\$apiKey\s*=\s*[\'\"]([^\'\"]+)[\'\"]/i', $source, $m)) $key = trim($m[1]); if ($model === 'gpt-4o-mini' && preg_match('/define\s*\(\s*[\'\"]OPENAI_MODEL[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/i', $source, $m)) $model = trim($m[1]); }
        if ($key === '') throw new RuntimeException('Serviço de IA não configurado.');
        $texto = trim($texto); if ($texto === '') throw new RuntimeException('Não foi possível extrair texto do documento.');
        $texto = function_exists('mb_substr') ? mb_substr($texto, 0, 120000, 'UTF-8') : substr($texto, 0, 120000);
        $language = ['pt' => 'português', 'en' => 'inglês', 'es' => 'espanhol'][$idioma] ?? 'português';
        $payload = ['model'=>$model,'temperature'=>0.1,'max_tokens'=>2500,'response_format'=>['type'=>'json_object'],'messages'=>[['role'=>'system','content'=>$prompt],['role'=>'user','content'=>"Idioma de resposta: {$language}. Analise o texto do documento e retorne somente o JSON solicitado pelo prompt.\n\n".$texto]]];
        $ch = curl_init($url . '/chat/completions'); $options = [CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>120,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]; $cacert=self::cacert(); if($cacert!==null){$options[CURLOPT_SSL_VERIFYPEER]=true;$options[CURLOPT_SSL_VERIFYHOST]=2;$options[CURLOPT_CAINFO]=$cacert;} curl_setopt_array($ch,$options); $inicio=microtime(true); $raw=curl_exec($ch); $error=curl_error($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
        $ms=(int)round((microtime(true)-$inicio)*1000);
        // Migração 340: taxímetro de IA (tokens, custo e limite da empresa); o contexto é informado por quem chama (fila de IA)
        if ($raw === false || $error !== '') { IaTaximetro::registrar(null,$model,$ms,false,'Falha na comunicação: '.$error); throw new RuntimeException('Falha na comunicação com a IA: '.($error ?: 'resposta vazia')); } $response=json_decode((string)$raw,true); if ($status<200||$status>=300) { $msg='O serviço de IA retornou HTTP '.$status.(!empty($response['error']['message'])?': '.substr((string)$response['error']['message'],0,240):'.'); IaTaximetro::registrar(is_array($response)?$response:null,$model,$ms,false,$msg); throw new RuntimeException($msg); } $content=(string)($response['choices'][0]['message']['content']??''); $data=json_decode($content,true); IaTaximetro::registrar(is_array($response)?$response:null,$model,$ms,is_array($data),is_array($data)?null:'A IA retornou um JSON inválido.'); if (!is_array($data)) throw new RuntimeException('A IA retornou um JSON inválido.'); return ['sugestao'=>$data,'texto_bytes'=>strlen($texto),'modelo'=>(string)($response['model']??$model),'tokens_entrada'=>(int)($response['usage']['prompt_tokens']??0),'tokens_saida'=>(int)($response['usage']['completion_tokens']??0)];
    }
    private static function env(): array { $r=[]; foreach (file(dirname(__DIR__).DIRECTORY_SEPARATOR.'.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line) { $line=trim($line); if($line===''||$line[0]==='#'||!str_contains($line,'='))continue; [$k,$v]=explode('=',$line,2);$r[trim($k)]=trim(trim($v),'"\''); } return $r; }
    private static function cacert(): ?string { foreach (['E:\\wamp\\apps\\phpmyadmin5.2.3\\vendor\\composer\\ca-bundle\\res\\cacert.pem','C:\\wamp\\bin\\php\\cacert.pem','C:\\wamp64\\bin\\php\\cacert.pem','C:\\php\\cacert.pem',php_ini_loaded_file()?dirname((string)php_ini_loaded_file()).DIRECTORY_SEPARATOR.'cacert.pem':''] as $candidate) if($candidate!==''&&is_file($candidate)) return $candidate; return null; }
}
