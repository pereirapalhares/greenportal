<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';
require_once __DIR__ . '/IaTaximetro.php';

/*
 * Busca semântica (migração 346): vetores (embeddings da OpenAI) dos itens da Pesquisa de documentos e a pesquisa pelo sentido.
 *
 * Modelo e dimensões no .env: GPT_AI_EMBEDDING_MODEL (padrão text-embedding-3-small) e GPT_AI_EMBEDDING_DIMENSOES (padrão 256);
 * chave e endereço da IA: os mesmos das análises (GPT_AI_API_KEY, GPT_AI_API_URL). Semelhança mínima: BUSCA_SEMANTICA_MINIMO
 * (padrão 0,35).
 * Indexação (serviço da Pesquisa de documentos, scripts/busca-worker.php): itens novos ou com o texto alterado
 * (sp_busca_semantica_pendentes; registros sempre, arquivos só com texto lido pelo OCR), em lotes, gravados em 8 bits com a
 * norma (sp_busca_semantica_gravar). Cada chamada passa pelo taxímetro de IA (tipo BUSCA_SEMANTICA) e respeita o limite mensal.
 * Pesquisa: o texto digitado vira um vetor (guardado na sessão por 30 min) e é comparado (cosseno) com os vetores da empresa
 * (sp_busca_semantica_vetores, em páginas). A permissão de ver cada item é aplicada depois pela pesquisa normal
 * (sp_busca_pesquisar_negocios com o filtro "semantico").
 */
final class BuscaSemantica
{
    private const LOTE = 64;
    private const CARACTERES = 6000;

    private array $env;
    private string $modelo;
    private int $dimensoes;

    public function __construct(private readonly PDO $pdo, ?array $env = null)
    {
        $this->env = $env ?? GreenPortalDatabase::env();
        $this->modelo = trim((string)($this->env['GPT_AI_EMBEDDING_MODEL'] ?? '')) ?: 'text-embedding-3-small';
        $this->dimensoes = max(64, min(1536, (int)($this->env['GPT_AI_EMBEDDING_DIMENSOES'] ?? 256) ?: 256));
    }

    public function modelo(): string { return $this->modelo; }
    public function dimensoes(): int { return $this->dimensoes; }
    public function minimo(): float { $m = (float)str_replace(',', '.', (string)($this->env['BUSCA_SEMANTICA_MINIMO'] ?? '0.35')); return $m > 0 && $m < 1 ? $m : 0.35; }

    public static function disponivel(PDO $pdo): bool
    {
        try { $s = $pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME IN ('sp_busca_semantica_pendentes', 'sp_busca_semantica_vetores')"); $n = (int)$s->fetchColumn(); $s->closeCursor(); return $n === 2; }
        catch (Throwable) { return false; }
    }

    /** Chave da IA configurada? (sem chave a busca semântica fica desligada, sem erro) */
    public function configurada(): bool { return $this->credenciais()[0] !== ''; }

    // ------------------------------------------------------------------ vetores
    /**
     * Vetores dos textos (um por texto, normalizados pela OpenAI). Passa pelo taxímetro com o contexto informado antes.
     * @return array<int, float[]>
     */
    public function vetores(array $textos): array
    {
        [$key, $url] = $this->credenciais();
        if ($key === '') throw new RuntimeException('Serviço de IA não configurado.');
        $saida = [];
        foreach (array_chunk(array_values($textos), self::LOTE) as $lote) {
            $lote = array_map(static fn($t) => mb_substr(trim((string)$t), 0, self::CARACTERES) ?: '-', $lote);
            $payload = ['model' => $this->modelo, 'input' => $lote];
            if (str_starts_with($this->modelo, 'text-embedding-3')) $payload['dimensions'] = $this->dimensoes;
            $ch = curl_init($url . '/embeddings');
            $op = [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)];
            if (($ca = self::cacert()) !== null) { $op[CURLOPT_SSL_VERIFYPEER] = true; $op[CURLOPT_SSL_VERIFYHOST] = 2; $op[CURLOPT_CAINFO] = $ca; }
            curl_setopt_array($ch, $op);
            $t0 = microtime(true); $raw = curl_exec($ch); $erro = curl_error($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
            $ms = (int)round((microtime(true) - $t0) * 1000);
            if ($raw === false || $erro !== '') { IaTaximetro::registrar(null, $this->modelo, $ms, false, 'Falha na comunicação: ' . $erro); throw new RuntimeException('Falha na comunicação com a IA: ' . ($erro ?: 'resposta vazia')); }
            $r = json_decode((string)$raw, true);
            if ($status < 200 || $status >= 300 || !is_array($r['data'] ?? null)) {
                $m = 'O serviço de IA retornou HTTP ' . $status . (!empty($r['error']['message']) ? ': ' . mb_substr((string)$r['error']['message'], 0, 240) : '.');
                IaTaximetro::registrar(is_array($r) ? $r : null, $this->modelo, $ms, false, $m);
                throw new RuntimeException($m);
            }
            IaTaximetro::registrar($r, $this->modelo, $ms, true);
            $dados = $r['data'];
            usort($dados, static fn($a, $b) => (int)($a['index'] ?? 0) <=> (int)($b['index'] ?? 0));
            foreach ($dados as $d) $saida[] = self::normalizar(array_map('floatval', (array)($d['embedding'] ?? [])), $this->dimensoes);
        }
        return $saida;
    }

    /** Corta nas dimensões configuradas (modelo sem "dimensions") e normaliza. */
    private static function normalizar(array $v, int $d): array
    {
        if (count($v) > $d) $v = array_slice($v, 0, $d);
        $n = sqrt(array_sum(array_map(static fn($x) => $x * $x, $v))) ?: 1.0;
        return array_map(static fn($x) => $x / $n, $v);
    }

    /** Vetor em 8 bits com sinal e a norma desse vetor. @return array{0: string, 1: float} */
    public static function quantizar(array $v): array
    {
        $m = max(array_map('abs', $v)) ?: 1.0;
        $q = array_map(static fn($x) => (int)max(-127, min(127, round($x / $m * 127))), $v);
        return [pack('c*', ...$q), sqrt(array_sum(array_map(static fn($x) => $x * $x, $q))) ?: 1.0];
    }

    // ------------------------------------------------------------------ indexação
    /** Serviço: vetoriza até $limite itens novos ou alterados e remove os que saíram. @return array{vetorizados:int, removidos:int, aviso?:string} */
    public function indexar(int $limite = 400): array
    {
        $r = ['vetorizados' => 0, 'removidos' => 0];
        $rem = $this->call('CALL sp_busca_semantica_limpar()');
        $r['removidos'] = (int)($rem[0]['REMOVIDOS'] ?? 0);
        if (!$this->configurada()) return $r + ['aviso' => 'sem chave da IA no .env: busca semântica desligada'];
        $s = IaTaximetro::situacao($this->pdo);
        if ($s && (string)$s['SITUACAO'] === 'BLOQUEADO') return $r + ['aviso' => 'limite mensal de IA atingido: vetorização suspensa até o próximo mês ou o aumento do limite'];
        $pend = $this->call('CALL sp_busca_semantica_pendentes(?)', [max(1, $limite)]);
        if (!$pend) return $r;
        IaTaximetro::contexto($this->pdo, ['tipo' => 'BUSCA_SEMANTICA', 'entidade' => 'busca_indice', 'origem' => 'FILA', 'modulo' => 'Pesquisa de documentos']);
        try {
            foreach (array_chunk($pend, self::LOTE) as $lote) {
                $vets = $this->vetores(array_map(static fn($p) => (string)$p['texto'], $lote));
                foreach ($lote as $i => $p) {
                    if (!isset($vets[$i])) continue;
                    [$bin, $norma] = self::quantizar($vets[$i]);
                    $this->call('CALL sp_busca_semantica_gravar(?, ?, ?, ?, ?, ?)', [(int)$p['codbusca'], (string)$p['hash'], $this->modelo, $this->dimensoes, bin2hex($bin), $norma]);
                    $r['vetorizados']++;
                }
            }
        } finally { IaTaximetro::limpar(); }
        return $r;
    }

    // ------------------------------------------------------------------ pesquisa
    /** Vetor do texto pesquisado (sessão: 30 min, até 30 textos). */
    public function vetorConsulta(string $q, int $usuario): ?array
    {
        $q = trim(mb_strtolower($q));
        if (mb_strlen($q) < 3 || !$this->configurada()) return null;
        $k = md5($this->modelo . '|' . $this->dimensoes . '|' . $q);
        $cache = (array)($_SESSION['busca_semantica_cache'] ?? []);
        if (isset($cache[$k]) && (int)$cache[$k]['t'] > time() - 1800) return $cache[$k]['v'];
        $s = IaTaximetro::situacao($this->pdo);
        if ($s && (string)$s['SITUACAO'] === 'BLOQUEADO') return null;
        IaTaximetro::contexto($this->pdo, ['tipo' => 'BUSCA_SEMANTICA', 'entidade' => 'busca_consulta', 'origem' => 'TELA', 'codusuario' => $usuario, 'modulo' => 'Pesquisa de documentos']);
        try { $v = $this->vetores([$q])[0] ?? null; } finally { IaTaximetro::limpar(); }
        if ($v === null) return null;
        $cache[$k] = ['t' => time(), 'v' => $v];
        if (count($cache) > 30) $cache = array_slice($cache, -30, null, true);
        $_SESSION['busca_semantica_cache'] = $cache;
        return $v;
    }

    /**
     * Itens mais parecidos com o vetor: [['id' => codbusca, 's' => semelhança 0..1], ...], do maior para o menor.
     * $tipos restringe os tipos do índice; $excluir = codbusca que não entram.
     */
    public function parecidos(array $vetor, int $limite = 60, ?float $minimo = null, array $tipos = [], array $excluir = []): array
    {
        $minimo ??= $this->minimo();
        $d = count($vetor);
        $q = array_combine(range(1, $d), array_values($vetor));
        $fora = array_flip(array_map('intval', $excluir));
        $res = [];
        $apos = 0;
        do {
            $linhas = $this->call('CALL sp_busca_semantica_vetores(?, ?, ?, ?, ?)', [$this->modelo, $this->dimensoes, json_encode(array_values($tipos)), $apos, 5000]);
            foreach ($linhas as $l) {
                $apos = (int)$l['codbusca'];
                if (isset($fora[$apos])) continue;
                $a = unpack('c*', (string)$l['vetor']);
                if (!$a || count($a) !== $d) continue;
                $dot = 0.0;
                for ($k = 1; $k <= $d; $k++) $dot += $q[$k] * $a[$k];
                $s = $dot / ((float)$l['norma'] ?: 1.0);
                if ($s >= $minimo) $res[$apos] = $s;
            }
        } while (count($linhas) === 5000);
        arsort($res);
        $saida = [];
        foreach (array_slice($res, 0, $limite, true) as $id => $s) $saida[] = ['id' => (int)$id, 's' => round($s, 4)];
        return $saida;
    }

    // ------------------------------------------------------------------ auxiliares
    private function credenciais(): array
    {
        $key = (string)($this->env['GPT_AI_API_KEY'] ?? '');
        $url = rtrim((string)($this->env['GPT_AI_API_URL'] ?? 'https://api.openai.com/v1'), '/');
        $arq = (string)($this->env['GPT_AI_CREDENTIALS_FILE'] ?? dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'greengde' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php');
        if ($key === '' && is_file($arq) && preg_match('/\$apiKey\s*=\s*[\'"]([^\'"]+)[\'"]/i', (string)file_get_contents($arq), $m)) $key = trim($m[1]);
        return [$key, $url];
    }

    private static function cacert(): ?string
    {
        foreach (['E:\\wamp\\apps\\phpmyadmin5.2.3\\vendor\\composer\\ca-bundle\\res\\cacert.pem', 'C:\\wamp\\bin\\php\\cacert.pem', 'C:\\wamp64\\bin\\php\\cacert.pem', 'C:\\php\\cacert.pem',
            php_ini_loaded_file() ? dirname((string)php_ini_loaded_file()) . DIRECTORY_SEPARATOR . 'cacert.pem' : ''] as $c) if ($c !== '' && is_file($c)) return $c;
        return null;
    }

    private function call(string $sql, array $p = []): array
    {
        $s = $this->pdo->prepare($sql);
        foreach (array_values($p) as $i => $v) $s->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : (is_null($v) ? PDO::PARAM_NULL : PDO::PARAM_STR));
        $s->execute();
        $r = [];
        do { try { $x = $s->fetchAll(PDO::FETCH_ASSOC); if ($x) $r = $x; } catch (Throwable) {} } while ($s->nextRowset());
        $s->closeCursor();
        return $r;
    }
}
