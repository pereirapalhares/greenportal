<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';
require_once __DIR__ . '/PermissaoTransacao.php';
require_once __DIR__ . '/IaTaximetro.php';
require_once __DIR__ . '/LegislacaoEmpresas.php';

/*
 * Migração 382 — Legislação (Manutenção Legal › Legislação): associações de cada lei.
 *   - URL de pesquisa (legislacaoxurl): onde a lei pode ser consultada para acompanhar alterações. Incluída pelo usuário
 *     (já CONFIRMADA) ou sugerida pela IA (SUGERIDA, só vale depois que alguém confirma). "Verificar" testa se o endereço responde.
 *   - Legislações filhas (legislacaopaixlegislacaofilha): leis existentes vinculadas a uma lei pai; incluir / tirar o vínculo
 *     (a lei filha continua cadastrada). Não aceita a própria lei nem um vínculo que feche um ciclo.
 * O banco glegislacao_dbp é o MESMO para todas as empresas: gravar exige "Editar" em LEGISLACAO; ver exige "Visualizar".
 * Leitura por sp_crud_select (views/tabelas) e gravação por sp_crud_insert/update/delete.
 */
final class LegislacaoAssociacoes
{
    public const PROMPT = 'LEG_URL_PESQUISA';
    public const TIPO_IA = 'LEG_URL_PESQUISA';
    public const MAX_URLS = 30;
    public const MAX_SUGESTOES = 6;

    private static ?PDO $leg = null;

    // ------------------------------------------------------------------ conexões e permissão

    public static function legislacao(): PDO
    {
        if (self::$leg === null) {
            $env = GreenPortalDatabase::env();
            self::$leg = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
            self::$leg->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }
        return self::$leg;
    }

    public static function empresa(): PDO
    {
        $env = GreenPortalDatabase::env();
        $pdo = GreenPortalDatabase::connect($env, (string)($_SESSION['tenant']['banco_dados'] ?? ''), 'GPT');
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    /** ['ver' => bool, 'editar' => bool] na transação LEGISLACAO. */
    public static function permissao(): array
    {
        $p = PermissaoTransacao::obter('LEGISLACAO');
        return ['ver' => !empty($p['pode_visualizar']), 'editar' => !empty($p['pode_editar'])];
    }

    public static function usuarioNome(): string
    {
        $u = (array)($_SESSION['usuario'] ?? []);
        return mb_substr(trim((string)($u['nome'] ?? $u['userid'] ?? ('usuário ' . ($u['codusuario'] ?? '?')))), 0, 150);
    }

    public static function empresaNome(): string
    {
        $t = (array)($_SESSION['tenant'] ?? []);
        return mb_substr(trim((string)($t['empresa_nome'] ?? $t['nome'] ?? $t['banco_dados'] ?? '')), 0, 80);
    }

    // ------------------------------------------------------------------ leitura genérica

    public static function ler(string $tabela, array $filtros = [], string $busca = '', int $limite = 1000, int $offset = 0): array
    {
        $s = self::legislacao()->prepare('CALL sp_crud_select(:t, :f, :b, :l, :o)');
        $s->execute(['t' => $tabela, 'f' => json_encode($filtros ?: new stdClass(), JSON_UNESCAPED_UNICODE), 'b' => $busca === '' ? null : $busca, 'l' => $limite, 'o' => $offset]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        while ($s->nextRowset()) {}
        $s->closeCursor();
        return $rows;
    }

    private static function crud(string $sql, array $p): void
    {
        $s = self::legislacao()->prepare($sql);
        $s->execute($p);
        while ($s->nextRowset()) {}
        $s->closeCursor();
    }

    private static function inserir(string $tabela, array $dados): void
    {
        self::crud('CALL sp_crud_insert(:t, :d)', ['t' => $tabela, 'd' => json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    private static function atualizar(string $tabela, array $chave, array $dados): void
    {
        self::crud('CALL sp_crud_update(:t, :c, :d)', ['t' => $tabela, 'c' => json_encode($chave), 'd' => json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    private static function excluir(string $tabela, array $chave): void
    {
        self::crud('CALL sp_crud_delete(:t, :c)', ['t' => $tabela, 'c' => json_encode($chave)]);
    }

    /** Dados resumidos da lei (vw_legislacao_busca_simples) ou null. */
    public static function lei(int $id): ?array
    {
        if ($id < 1) return null;
        $r = self::ler('vw_legislacao_busca_simples', ['CODLEGISLACAO' => $id], '', 1);
        return $r[0] ?? null;
    }

    public static function rotulo(array $lei, string $prefixo = ''): string
    {
        $tipo = trim((string)($lei[$prefixo . 'DSCTIPO'] ?? $lei[$prefixo . 'TIPO'] ?? ''));
        $nr = trim((string)($lei[$prefixo . 'NR'] ?? ''));
        $ano = trim((string)($lei[$prefixo . 'ANO'] ?? ''));
        $txt = trim($tipo . ($nr !== '' ? ' nº ' . $nr : '') . ($ano !== '' ? '/' . $ano : ''));
        if ($txt !== '') return $txt;
        $cod = trim((string)($lei[$prefixo . 'CODIGO'] ?? ''));
        return $cod !== '' ? $cod : '(legislação não encontrada no cadastro)';
    }

    /** Contagens por lei para o grid: [CODLEGISLACAO => [QTD_URLS, QTD_URLS_SUGERIDAS, QTD_FILHAS, QTD_PAIS]]. */
    public static function contagens(array $ids): array
    {
        $out = [];
        foreach (array_unique(array_filter(array_map('intval', $ids))) as $id) {
            try { $r = self::ler('vw_legislacao_assoc_contagem', ['CODLEGISLACAO' => $id], '', 1); } catch (Throwable) { return []; } // antes da migração 382
            if ($r) $out[$id] = array_map('intval', array_intersect_key($r[0], array_flip(['QTD_URLS', 'QTD_URLS_SUGERIDAS', 'QTD_FILHAS', 'QTD_PAIS'])));
        }
        return $out;
    }

    /** Ao excluir a lei: tira URLs, alterações, checklist, palavras-chave e vínculos pai/filha (nos dois sentidos). */
    public static function limparLei(int $id): void
    {
        if ($id < 1) return;
        try { foreach (self::ler('legislacaoxurl', ['CODLEGISLACAO' => $id]) as $u) self::excluir('legislacaoxurl', ['CODLEGISLACAOURL' => (int)$u['CODLEGISLACAOURL']]); } catch (Throwable) {}
        foreach (self::ler('legislacaoxalteracao', ['codlegislacao' => $id]) as $a) self::excluir('legislacaoxalteracao', ['codalteracao' => (int)$a['codalteracao']]);
        foreach (self::ler('legislacaoxpergunta', ['CODLEGISLACAO' => $id]) as $q) self::excluir('legislacaoxpergunta', ['CODLEGISLACAO' => $id, 'CODPERGUNTA' => (int)$q['CODPERGUNTA']]);
        foreach (self::ler('legislacaoxpalavraschave', ['codlegislacao' => $id]) as $k) self::excluir('legislacaoxpalavraschave', ['codlegislacaoxpalavrachave' => (int)array_change_key_case($k, CASE_LOWER)['codlegislacaoxpalavrachave']]);
        foreach (['CODLEGISLACAO_PAI', 'CODLEGISLACAO_FILHA'] as $c)
            foreach (self::ler('legislacaopaixlegislacaofilha', [$c => $id]) as $r) self::excluir('legislacaopaixlegislacaofilha', ['codlegislacaopai_filha' => (int)$r['codlegislacaopai_filha']]);
    }

    // ------------------------------------------------------------------ URL de pesquisa

    public static function urls(int $id): array
    {
        $rows = self::ler('legislacaoxurl', ['CODLEGISLACAO' => $id], '', self::MAX_URLS * 2);
        usort($rows, static fn($a, $b) => [(string)$a['SITUACAO'] === 'SUGERIDA' ? 0 : 1, (string)$b['DATA_INCLUSAO']] <=> [(string)$b['SITUACAO'] === 'SUGERIDA' ? 0 : 1, (string)$a['DATA_INCLUSAO']]);
        return $rows;
    }

    public static function normalizarUrl(string $url): string
    {
        $url = trim($url);
        if ($url !== '' && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) $url = 'https://' . $url;
        if (strlen($url) > 1000 || !filter_var($url, FILTER_VALIDATE_URL)) throw new InvalidArgumentException('Endereço inválido. Informe um link completo, por exemplo https://www.planalto.gov.br/...');
        $esquema = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($esquema, ['http', 'https'], true)) throw new InvalidArgumentException('Use um endereço http:// ou https://.');
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) throw new InvalidArgumentException('Use o endereço público do site (não um IP ou localhost).');
        return $url;
    }

    private static function chaveUrl(string $url): string
    {
        return rtrim(strtolower((string)preg_replace('#^https?://(www\.)?#i', '', $url)), '/');
    }

    private static function urlExistente(int $id, string $url, int $ignorar = 0): ?array
    {
        $k = self::chaveUrl($url);
        foreach (self::urls($id) as $u) if ((int)$u['CODLEGISLACAOURL'] !== $ignorar && self::chaveUrl((string)$u['URL']) === $k) return $u;
        return null;
    }

    private static function urlDaLei(int $id, int $codurl): array
    {
        foreach (self::ler('legislacaoxurl', ['CODLEGISLACAOURL' => $codurl], '', 1) as $u) if ((int)$u['CODLEGISLACAO'] === $id) return $u;
        throw new RuntimeException('Link não encontrado (pode ter sido excluído por outra pessoa).');
    }

    public static function incluirUrl(int $id, string $url, string $descricao): void
    {
        if (!self::lei($id)) throw new RuntimeException('Legislação não encontrada.');
        $url = self::normalizarUrl($url);
        if ($e = self::urlExistente($id, $url)) throw new RuntimeException('Esse link já está na lista' . ((string)$e['SITUACAO'] === 'SUGERIDA' ? ' como sugestão da IA (confirme-o).' : '.'));
        if (count(self::urls($id)) >= self::MAX_URLS) throw new RuntimeException('Limite de ' . self::MAX_URLS . ' links por legislação.');
        $agora = date('Y-m-d H:i:s');
        self::inserir('legislacaoxurl', ['CODLEGISLACAO' => $id, 'URL' => $url, 'DESCRICAO' => self::corta($descricao, 300), 'ORIGEM' => 'USUARIO', 'SITUACAO' => 'CONFIRMADA',
            'USUARIO_INCLUSAO' => self::usuarioNome(), 'EMPRESA_INCLUSAO' => self::empresaNome(), 'DATA_INCLUSAO' => $agora, 'USUARIO_CONFIRMACAO' => self::usuarioNome(), 'DATA_CONFIRMACAO' => $agora]);
    }

    public static function alterarUrl(int $id, int $codurl, string $url, string $descricao): void
    {
        self::urlDaLei($id, $codurl);
        $url = self::normalizarUrl($url);
        if (self::urlExistente($id, $url, $codurl)) throw new RuntimeException('Esse link já está na lista.');
        self::atualizar('legislacaoxurl', ['CODLEGISLACAOURL' => $codurl], ['URL' => $url, 'DESCRICAO' => self::corta($descricao, 300)]);
    }

    public static function confirmarUrl(int $id, int $codurl): void
    {
        $u = self::urlDaLei($id, $codurl);
        if ((string)$u['SITUACAO'] === 'CONFIRMADA') return;
        self::atualizar('legislacaoxurl', ['CODLEGISLACAOURL' => $codurl], ['SITUACAO' => 'CONFIRMADA', 'USUARIO_CONFIRMACAO' => self::usuarioNome(), 'DATA_CONFIRMACAO' => date('Y-m-d H:i:s')]);
    }

    public static function excluirUrl(int $id, int $codurl): void
    {
        self::urlDaLei($id, $codurl);
        self::excluir('legislacaoxurl', ['CODLEGISLACAOURL' => $codurl]);
    }

    /** Testa se cada link responde (HEAD, e GET quando o site recusa HEAD). Devolve [CODLEGISLACAOURL => status]. */
    public static function verificarUrls(int $id, ?int $soUma = null): array
    {
        $out = [];
        foreach (self::urls($id) as $u) {
            $cod = (int)$u['CODLEGISLACAOURL'];
            if ($soUma !== null && $cod !== $soUma) continue;
            $status = self::status((string)$u['URL']);
            self::atualizar('legislacaoxurl', ['CODLEGISLACAOURL' => $cod], ['ULTIMA_VERIFICACAO' => date('Y-m-d H:i:s'), 'ULTIMO_STATUS_HTTP' => $status]);
            $out[$cod] = $status;
        }
        return $out;
    }

    public static function status(string $url): int
    {
        if (!function_exists('curl_init')) return 0;
        // só endereços públicos: não deixa o servidor ser usado para sondar a rede interna
        $ips = gethostbynamel((string)parse_url($url, PHP_URL_HOST)) ?: [];
        if (!$ips) return 0;
        foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return 0;
        $status = 0;
        foreach ([true, false] as $head) {
            $ch = curl_init($url);
            $o = [CURLOPT_NOBODY => $head, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 8,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'Mozilla/5.0 (GreenPortal verificador de links)', CURLOPT_RANGE => $head ? null : '0-2047'];
            if ($ca = self::cacert()) $o[CURLOPT_CAINFO] = $ca;
            curl_setopt_array($ch, array_filter($o, static fn($v) => $v !== null));
            curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($status >= 200 && $status < 400) break;
        }
        return $status;
    }

    public static function statusTexto(?int $s): array
    {
        if ($s === null) return ['não verificado', 'secondary'];
        if ($s >= 200 && $s < 400) return ['responde', 'success'];
        if ($s === 0) return ['não respondeu', 'danger'];
        if ($s === 401 || $s === 403) return ['bloqueia verificação (' . $s . ')', 'warning'];
        return ['erro ' . $s, 'danger'];
    }

    /** Pede à IA (prompt LEG_URL_PESQUISA, limite de IA da empresa) links para acompanhar a lei. Grava como SUGERIDA. */
    public static function sugerirUrlsIa(int $id, string $idioma = 'pt'): array
    {
        $atuais = array_map(static fn($u) => (string)$u['URL'], self::urls($id));
        $conteudo = self::perguntarIa($id, self::PROMPT, self::TIPO_IA, $idioma, ['links_ja_cadastrados' => $atuais, 'maximo_de_links' => self::MAX_SUGESTOES], 900);
        $lista = $conteudo['urls'] ?? $conteudo['links'] ?? [];
        $res = ['novas' => 0, 'repetidas' => 0, 'invalidas' => 0];
        foreach (array_slice(is_array($lista) ? $lista : [], 0, self::MAX_SUGESTOES) as $item) {
            $link = is_array($item) ? (string)($item['url'] ?? '') : (string)$item;
            try { $link = self::normalizarUrl($link); } catch (Throwable) { $res['invalidas']++; continue; }
            if (self::urlExistente($id, $link)) { $res['repetidas']++; continue; }
            if (count(self::urls($id)) >= self::MAX_URLS) break;
            $status = self::status($link);
            self::inserir('legislacaoxurl', ['CODLEGISLACAO' => $id, 'URL' => $link, 'DESCRICAO' => self::corta((string)($item['descricao'] ?? ''), 300), 'ORIGEM' => 'IA', 'SITUACAO' => 'SUGERIDA',
                'JUSTIFICATIVA_IA' => self::corta((string)($item['justificativa'] ?? ''), 1000), 'USUARIO_INCLUSAO' => self::usuarioNome(), 'EMPRESA_INCLUSAO' => self::empresaNome(),
                'DATA_INCLUSAO' => date('Y-m-d H:i:s'), 'ULTIMA_VERIFICACAO' => date('Y-m-d H:i:s'), 'ULTIMO_STATUS_HTTP' => $status]);
            $res['novas']++;
        }
        return $res;
    }

    /** Dados da lei para os prompts de IA. */
    private static function dadosLeiIa(int $id): array
    {
        $lei = self::lei($id);
        if (!$lei) throw new RuntimeException('Legislação não encontrada.');
        $completa = self::ler('legislacao', ['CODLEGISLACAO' => $id], '', 1)[0] ?? [];
        $ambito = '';
        if (!empty($completa['CODAMBITO'])) $ambito = (string)(self::ler('ambitos', ['CODAMBITO' => (int)$completa['CODAMBITO']], '', 1)[0]['DSCAMBITO'] ?? '');
        return ['tipo' => (string)($lei['DSCTIPO'] ?? ''), 'numero' => (string)($lei['NR'] ?? ''), 'ano' => (string)($lei['ANO'] ?? ''), 'ambito' => $ambito,
            'codigo_interno' => (string)($lei['CODIGO'] ?? ''), 'situacao' => (string)($lei['situacao_da_lei'] ?? ''),
            'texto_inicial' => self::corta(strip_tags(html_entity_decode((string)($completa['PADRAO'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 2500)];
    }

    /** Chamada à IA com o prompt da empresa ($codigoPrompt), limite e taxímetro da empresa. Devolve o JSON da resposta. */
    private static function perguntarIa(int $id, string $codigoPrompt, string $tipoIa, string $idioma, array $extra, int $maxTokens): array
    {
        $dados = self::dadosLeiIa($id) + $extra;
        $pdo = self::empresa();
        IaTaximetro::exigirDisponivel($pdo);
        $prompt = self::prompt($pdo, $idioma, $codigoPrompt);
        [$url, $key, $model] = self::credenciais(GreenPortalDatabase::env());
        $idiomaTxt = ['pt' => 'português', 'en' => 'inglês', 'es' => 'espanhol'][$idioma] ?? 'português';
        $payload = ['model' => $model, 'temperature' => 0.1, 'max_tokens' => $maxTokens, 'response_format' => ['type' => 'json_object'], 'messages' => [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => "Idioma da resposta: {$idiomaTxt}. Dados da legislação (JSON):\n" . json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)],
        ]];
        $ch = curl_init($url . '/chat/completions');
        $o = [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 90, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        if ($ca = self::cacert()) { $o[CURLOPT_SSL_VERIFYPEER] = true; $o[CURLOPT_SSL_VERIFYHOST] = 2; $o[CURLOPT_CAINFO] = $ca; }
        curl_setopt_array($ch, $o);
        $inicio = microtime(true);
        $raw = curl_exec($ch); $erro = curl_error($ch); $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        $ms = (int)round((microtime(true) - $inicio) * 1000);
        IaTaximetro::contexto($pdo, ['tipo' => $tipoIa, 'modulo' => 'Módulo Legal', 'entidade' => 'legislacao', 'registro' => (string)$id, 'codusuario' => (int)($_SESSION['usuario']['codusuario'] ?? 0), 'origem' => 'TELA']);
        $resp = is_string($raw) ? json_decode($raw, true) : null;
        if ($raw === false || $erro !== '' || $http < 200 || $http >= 300) {
            IaTaximetro::registrar(is_array($resp) ? $resp : null, $model, $ms, false, $erro !== '' ? $erro : 'HTTP ' . $http); IaTaximetro::limpar();
            throw new RuntimeException('O serviço de IA não respondeu' . ($http ? ' (HTTP ' . $http . ')' : '') . '. Tente de novo em instantes.');
        }
        $conteudo = json_decode((string)($resp['choices'][0]['message']['content'] ?? ''), true);
        IaTaximetro::registrar($resp, $model, $ms, is_array($conteudo), is_array($conteudo) ? null : 'JSON inválido.'); IaTaximetro::limpar();
        if (!is_array($conteudo)) throw new RuntimeException('A IA devolveu uma resposta inválida. Tente de novo.');
        return $conteudo;
    }

    public static function iaDisponivel(): bool
    {
        try { [, $key] = self::credenciais(GreenPortalDatabase::env()); return $key !== ''; } catch (Throwable) { return false; }
    }

    private static function prompt(PDO $pdo, string $idioma, string $codigo = self::PROMPT): string
    {
        $p = '';
        try {
            $s = $pdo->prepare('CALL sp_ia_prompt_obter(?, ?)'); $s->execute([$codigo, $idioma]);
            $p = trim((string)(($s->fetch(PDO::FETCH_ASSOC) ?: [])['prompt'] ?? '')); while ($s->nextRowset()) {} $s->closeCursor();
        } catch (Throwable) {}
        if ($p === '') throw new RuntimeException('O prompt ' . $codigo . ' não está cadastrado ou está inativo nesta empresa (Setup › Prompts de IA; migração 382).');
        return $p;
    }

    /** Mesmas credenciais das outras chamadas de IA (GPT_AI_* do .env ou o app.php do greengde). */
    private static function credenciais(array $env): array
    {
        $url = rtrim((string)($env['GPT_AI_API_URL'] ?? ''), '/') ?: 'https://api.openai.com/v1';
        $key = (string)($env['GPT_AI_API_KEY'] ?? '');
        $model = (string)($env['GPT_AI_MODEL'] ?? 'gpt-4o-mini');
        $arq = (string)($env['GPT_AI_CREDENTIALS_FILE'] ?? dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'greengde' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php');
        if (($key === '' || $model === 'gpt-4o-mini') && is_file($arq)) {
            $src = (string)file_get_contents($arq);
            if ($key === '' && preg_match('/\$apiKey\s*=\s*[\'\"]([^\'\"]+)[\'\"]/i', $src, $m)) $key = trim($m[1]);
            if ($key === '' && preg_match('/define\s*\(\s*[\'\"]OPENAI_API_KEY[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/i', $src, $m)) $key = trim($m[1]);
            if ($model === 'gpt-4o-mini' && preg_match('/define\s*\(\s*[\'\"]OPENAI_MODEL[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/i', $src, $m)) $model = trim($m[1]);
        }
        if ($key === '') throw new RuntimeException('Serviço de IA não configurado.');
        return [$url, $key, $model];
    }

    private static function cacert(): ?string
    {
        foreach (['E:\\wamp\\apps\\phpmyadmin5.2.3\\vendor\\composer\\ca-bundle\\res\\cacert.pem', 'C:\\wamp\\bin\\php\\cacert.pem', 'C:\\wamp64\\bin\\php\\cacert.pem', 'C:\\php\\cacert.pem',
            php_ini_loaded_file() ? dirname((string)php_ini_loaded_file()) . DIRECTORY_SEPARATOR . 'cacert.pem' : ''] as $c) if ($c !== '' && is_file($c)) return $c;
        return null;
    }

    // ------------------------------------------------------------------ Legislações filhas

    public static function filhas(int $id): array
    {
        $r = self::unicos(self::ler('vw_legislacao_filhas', ['CODLEGISLACAO_PAI' => $id]), 'CODLEGISLACAO_FILHA');
        usort($r, static fn($a, $b) => strnatcasecmp(self::rotulo($a, 'FILHA_'), self::rotulo($b, 'FILHA_')));
        return $r;
    }

    public static function pais(int $id): array
    {
        return self::unicos(self::ler('vw_legislacao_filhas', ['CODLEGISLACAO_FILHA' => $id]), 'CODLEGISLACAO_PAI');
    }

    /** O legado tem vínculos repetidos (mesmo pai e filha mais de uma vez): mostra um só. */
    private static function unicos(array $rows, string $coluna): array
    {
        $out = [];
        foreach ($rows as $r) { $k = (int)$r[$coluna]; if (!isset($out[$k])) $out[$k] = $r; }
        return array_values($out);
    }

    /** Busca leis para incluir como filha (tipo exato, número/ano/texto por trecho). Máx. 50. */
    public static function buscarLeis(int $id, int $tipo, string $nr, string $ano, string $texto): array
    {
        $f = [];
        if ($tipo > 0) $f['CODTIPO'] = $tipo;
        if (trim($nr) !== '') $f['NR'] = trim($nr);
        if (trim($ano) !== '') $f['ANO'] = trim($ano);
        if (!$f && mb_strlen(trim($texto)) < 3) throw new InvalidArgumentException('Informe o tipo, o número, o ano ou pelo menos 3 letras do texto.');
        $ja = [$id => true];
        foreach (self::filhas($id) as $r) $ja[(int)$r['CODLEGISLACAO_FILHA']] = true;
        $out = [];
        foreach (self::ler('vw_legislacao_busca_simples', $f, trim($texto), 60) as $l) {
            $cod = (int)$l['CODLEGISLACAO'];
            $out[] = ['codigo' => $cod, 'rotulo' => self::rotulo($l), 'codigo_interno' => (string)($l['CODIGO'] ?? ''), 'situacao' => (string)($l['situacao_da_lei'] ?? ''),
                'resumo' => self::corta(strip_tags(html_entity_decode((string)($l['RESUMO'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 160), 'vinculada' => isset($ja[$cod]), 'propria' => $cod === $id];
            if (count($out) >= 50) break;
        }
        return $out;
    }

    public static function incluirFilha(int $pai, int $filha): void
    {
        if ($pai === $filha) throw new RuntimeException('Uma legislação não pode ser filha dela mesma.');
        if (!self::lei($pai) || !self::lei($filha)) throw new RuntimeException('Legislação não encontrada.');
        foreach (self::filhas($pai) as $r) if ((int)$r['CODLEGISLACAO_FILHA'] === $filha) return;
        if (self::ascendente($pai, $filha)) throw new RuntimeException('Essa legislação já é pai (direta ou indiretamente) desta: o vínculo fecharia um ciclo.');
        self::inserir('legislacaopaixlegislacaofilha', ['CODLEGISLACAO_PAI' => $pai, 'CODLEGISLACAO_FILHA' => $filha]);
    }

    public static function removerFilha(int $pai, int $vinculo): void
    {
        $todos = self::ler('legislacaopaixlegislacaofilha', ['CODLEGISLACAO_PAI' => $pai]);
        foreach ($todos as $r) if ((int)$r['codlegislacaopai_filha'] === $vinculo) {
            // tira também as repetições do mesmo par pai/filha que vieram do legado
            foreach ($todos as $x) if ((int)$x['CODLEGISLACAO_FILHA'] === (int)$r['CODLEGISLACAO_FILHA']) self::excluir('legislacaopaixlegislacaofilha', ['codlegislacaopai_filha' => (int)$x['codlegislacaopai_filha']]);
            return;
        }
        throw new RuntimeException('Vínculo não encontrado (pode ter sido removido por outra pessoa).');
    }

    /** Migração 388 (lista dupla): tira a lei filha $filha da lei $pai (todos os vínculos repetidos do par). */
    public static function removerFilhaPorLei(int $pai, int $filha): void
    {
        foreach (self::ler('legislacaopaixlegislacaofilha', ['CODLEGISLACAO_PAI' => $pai]) as $r)
            if ((int)$r['CODLEGISLACAO_FILHA'] === $filha) self::excluir('legislacaopaixlegislacaofilha', ['codlegislacaopai_filha' => (int)$r['codlegislacaopai_filha']]);
    }

    /** Página de leis para a lista dupla (vw_legislacao_busca_simples): [itens, total|null]. */
    public static function paginaLeis(int $tipo, string $busca, int $pagina, int $porPagina = 25): array
    {
        $f = $tipo > 0 ? ['CODTIPO' => $tipo] : [];
        $itens = self::ler('vw_legislacao_busca_simples', $f, trim($busca), $porPagina, max(0, ($pagina - 1) * $porPagina));
        $total = null;
        try {
            $s = self::legislacao()->prepare('CALL sp_crud_count(?, ?, ?)');
            $s->execute(['vw_legislacao_busca_simples', json_encode($f ?: new stdClass()), trim($busca) === '' ? null : trim($busca)]);
            $row = $s->fetch(PDO::FETCH_NUM); while ($s->nextRowset()) {} $s->closeCursor();
            if ($row) $total = (int)$row[0];
        } catch (Throwable) {}
        return [$itens, $total];
    }

    // ------------------------------------------------------------------ Filhas sugeridas (migração 389)
    // Critérios medidos nos 435 vínculos existentes (scripts/teste-sugestao-filhas-389b.php): mesmo âmbito em 97,5%;
    // mesmo tipo, órgão, família de leis, espécie ou assunto cobrem 90%. Citação do número e palavras-chave quase não ocorrem.

    private static ?array $tiposCache = null;

    /** Catálogo de tipos inteiro (são mais de 1.000): [CODTIPO => DSCTIPO], em ordem alfabética. */
    public static function todosTipos(): array
    {
        if (self::$tiposCache !== null) return self::$tiposCache;
        $t = [];
        for ($o = 0; ; $o += 1000) {
            $p = self::ler('tipos', [], '', 1000, $o);
            foreach ($p as $r) $t[(int)$r['CODTIPO']] = trim((string)($r['DSCTIPO'] ?? ''));
            if (count($p) < 1000) break;
        }
        uasort($t, 'strnatcasecmp');
        return self::$tiposCache = $t;
    }

    private static function semAcento(string $t): string
    {
        $t = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $t)), 'UTF-8');
        $c = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
        return $c === false ? $t : $c;
    }

    /** Siglas do órgão no nome do tipo ("Portaria de Consolidação MS" → MS; "Resolução Conjunta SMA/SAA" → SMA, SAA). */
    private static function orgaosTipo(string $tipo): array
    {
        $o = [];
        foreach (preg_split('/[\s\/\-–,.()]+/u', $tipo) ?: [] as $x)
            if (mb_strlen($x) >= 2 && $x === mb_strtoupper($x) && preg_match('/\p{L}/u', $x) && !in_array($x, ['NR', 'N', 'NE', 'RE', 'RDC', 'GM', 'PR', 'E', 'DE', 'DA', 'DO'], true)) $o[$x] = true;
        return $o;
    }

    /** Espécie do ato = primeira palavra relevante do tipo (lei, decreto, portaria, resolução...). */
    private static function especieTipo(string $tipo): string
    {
        $t = self::semAcento($tipo);
        foreach (['emenda', 'elom', 'constitui', 'lei', 'medida provis', 'decreto', 'portaria', 'resolu', 'instru', 'delibera', 'norma', 'nota tecnica', 'comunicado', 'ato', 'despacho', 'circular', 'orienta', 'parecer', 'disposi', 'acuerdo', 'reglamento', 'regulamento', 'directiv', 'codigo'] as $e)
            if (str_starts_with($t, $e)) return $e;
        return $t;
    }

    /** Classe na família das leis: constituicao, emenda_const, lei_organica, elom, lei, mp, decreto_lei, decreto ou ''. */
    private static function classeNorma(string $tipo): string
    {
        $t = self::semAcento($tipo);
        return match (true) {
            str_starts_with($t, 'emenda const') => 'emenda_const',
            str_starts_with($t, 'elom'), str_starts_with($t, 'emenda a lom'), str_starts_with($t, 'emenda a lei organica'), str_starts_with($t, 'emenda a loman') => 'elom',
            str_starts_with($t, 'constitui') => 'constituicao',
            str_starts_with($t, 'lei organica') => 'lei_organica',
            str_starts_with($t, 'lei') => 'lei',
            str_starts_with($t, 'medida provis') => 'mp',
            str_starts_with($t, 'decreto-lei'), str_starts_with($t, 'decreto lei') => 'decreto_lei',
            str_starts_with($t, 'decreto') && !str_starts_with($t, 'decreto legislativo') => 'decreto',
            default => '',
        };
    }

    private const FAMILIA = [
        'constituicao' => ['emenda_const'],
        'lei_organica' => ['elom'],
        'lei' => ['lei', 'mp', 'decreto_lei', 'decreto'],
        'mp' => ['lei', 'mp', 'decreto'],
        'decreto_lei' => ['lei', 'mp', 'decreto_lei', 'decreto'],
    ];

    /**
     * Tipos que costumam ser filhos do tipo da lei pai, com pontos e motivo:
     * mesmo tipo 40, família de leis (altera/regulamenta) 30, mesmo órgão 25, mesma espécie 10 (somam).
     * @return array<int, array{pts:int, motivo:string}>
     */
    public static function tiposRelacionados(int $codtipoPai): array
    {
        $tipos = self::todosTipos();
        $nomePai = $tipos[$codtipoPai] ?? '';
        if ($nomePai === '') return [];
        $orgPai = self::orgaosTipo($nomePai); $espPai = self::especieTipo($nomePai); $famPai = self::FAMILIA[self::classeNorma($nomePai)] ?? [];
        $out = [];
        foreach ($tipos as $cod => $nome) {
            $pts = 0; $mot = [];
            if ($cod === $codtipoPai) { $pts += 40; $mot[] = 'mesmo tipo'; }
            if ($famPai && $cod !== $codtipoPai && in_array(self::classeNorma($nome), $famPai, true)) { $pts += 30; $mot[] = 'altera ou regulamenta (família das leis)'; }
            if ($orgPai && ($comum = array_intersect_key($orgPai, self::orgaosTipo($nome)))) { $pts += 25; if ($cod !== $codtipoPai) $mot[] = 'mesmo órgão (' . implode(', ', array_keys($comum)) . ')'; }
            if ($espPai !== '' && $cod !== $codtipoPai && self::especieTipo($nome) === $espPai) { $pts += 10; if (count($mot) === 0) $mot[] = 'mesma espécie'; }
            if ($pts > 0) $out[$cod] = ['pts' => $pts, 'motivo' => implode(', ', $mot)];
        }
        return $out;
    }

    /**
     * Possíveis filhas da lei: mesmo âmbito E (tipo relacionado OU assunto em comum), sem a própria lei e sem as filhas atuais,
     * da mais provável para a menos provável. @return array{0: array, 1: ?int} [linhas com MOTIVO, total]
     */
    public static function sugestoesFilhas(int $pai, int $tipo, string $busca, int $pagina, int $porPagina = 25): array
    {
        $l = self::lei($pai);
        if (!$l) return [[], 0];
        $rel = self::tiposRelacionados((int)($l['CODTIPO'] ?? 0));
        $json = json_encode(array_map(static fn($c, $v) => ['cod' => $c, 'pts' => $v['pts']], array_keys($rel), $rel));
        $s = self::legislacao()->prepare('CALL sp_legislacao_sugestao_filhas(?, ?, ?, ?, ?, ?)');
        $s->execute([$pai, $json ?: '[]', $tipo > 0 ? $tipo : null, trim($busca) === '' ? null : trim($busca), $porPagina, max(0, ($pagina - 1) * $porPagina)]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) {} $s->closeCursor();
        $total = $rows ? (int)$rows[0]['TOTAL_REGISTROS'] : ($pagina === 1 ? 0 : null);
        foreach ($rows as &$r) {
            $m = [];
            if (isset($rel[(int)$r['CODTIPO']]) && $rel[(int)$r['CODTIPO']]['motivo'] !== '') $m[] = $rel[(int)$r['CODTIPO']]['motivo'];
            if ((int)$r['MESMO_ASSUNTO'] === 1) $m[] = 'assunto em comum';
            $r['MOTIVO'] = ucfirst(implode(', ', $m));
        }
        unset($r);
        return [$rows, $total];
    }

    /** true se $candidato já está acima de $lei (pai, avô...). */
    private static function ascendente(int $lei, int $candidato): bool
    {
        $fila = [$lei]; $visto = [];
        while ($fila && count($visto) < 2000) {
            $atual = array_shift($fila);
            if (isset($visto[$atual])) continue;
            $visto[$atual] = true;
            foreach (self::ler('legislacaopaixlegislacaofilha', ['CODLEGISLACAO_FILHA' => $atual]) as $r) {
                $p = (int)$r['CODLEGISLACAO_PAI'];
                if ($p === $candidato) return true;
                $fila[] = $p;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------ Alterações na legislação (migração 386)

    public const SITUACOES = ['Lei Inclusa', 'Lei Alterada', 'Lei Revogada'];
    public const DEVIDOS = ['Devido à Atualização', 'Devido ao Ajuste', 'Alterada pela Atualização', 'Alterada pelo Ajuste', 'Retirado pela Atualização'];

    /** Catálogo tipo_de_alteracao: lista de descrições, em ordem alfabética. */
    public static function tiposAlteracao(): array
    {
        $t = array_values(array_unique(array_filter(array_map(static fn($r) => trim((string)($r['DSCTIPODEALTERACAO'] ?? '')), self::ler('tipo_de_alteracao')))));
        usort($t, 'strnatcasecmp');
        return $t;
    }

    /** Alterações da lei, da mais recente para a mais antiga. */
    public static function alteracoes(int $id): array
    {
        $r = self::ler('legislacaoxalteracao', ['codlegislacao' => $id]);
        usort($r, static fn($a, $b) => [(string)($b['data_da_alteracao'] ?? ''), (int)$b['codalteracao']] <=> [(string)($a['data_da_alteracao'] ?? ''), (int)$a['codalteracao']]);
        return $r;
    }

    /**
     * Inclui ($cod = 0) ou altera uma alteração. Se $lei vier preenchido (situacao, devido, data), atualiza também a própria lei —
     * é o que faz a lei entrar no RCL do mês e no filtro.
     */
    public static function salvarAlteracao(int $id, int $cod, string $data, string $tipo, string $descricao, ?array $lei): void
    {
        if (!self::lei($id)) throw new RuntimeException('Legislação não encontrada.');
        $data = self::dataValida($data, 'Data da alteração');
        $tipo = trim($tipo);
        if ($tipo === '') throw new InvalidArgumentException('Escolha o tipo de alteração.');
        if (!in_array($tipo, self::tiposAlteracao(), true)) throw new InvalidArgumentException('Tipo de alteração fora do cadastro.');
        $descricao = trim($descricao);
        if ($descricao === '') throw new InvalidArgumentException('Descreva a alteração.');
        if (mb_strlen($descricao) > 5000) throw new InvalidArgumentException('A descrição passa de 5.000 caracteres.');
        $dados = ['codlegislacao' => $id, 'tipo' => mb_substr($tipo, 0, 300), 'descricao' => $descricao, 'data_da_alteracao' => $data . ' 00:00:00'];
        if ($cod > 0) {
            $existe = false;
            foreach (self::ler('legislacaoxalteracao', ['codalteracao' => $cod], '', 1) as $r) $existe = (int)$r['codlegislacao'] === $id;
            if (!$existe) throw new RuntimeException('Alteração não encontrada (pode ter sido excluída por outra pessoa).');
            self::atualizar('legislacaoxalteracao', ['codalteracao' => $cod], $dados);
        } else {
            self::inserir('legislacaoxalteracao', $dados);
        }
        if ($lei !== null) {
            $situacao = trim((string)($lei['situacao'] ?? ''));
            $devido = trim((string)($lei['devido'] ?? ''));
            if (!in_array($situacao, self::SITUACOES, true)) throw new InvalidArgumentException('Escolha a situação da lei.');
            if (!in_array($devido, self::DEVIDOS, true)) throw new InvalidArgumentException('Escolha o "devido" da lei.');
            $dataLei = self::dataValida((string)($lei['data'] ?? ''), 'Data de atualização da lei');
            self::atualizar('legislacao', ['CODLEGISLACAO' => $id], ['situacao_da_lei' => $situacao, 'devido_da_lei' => $devido, 'data_atualizacao' => $dataLei . ' 00:00:00']);
        }
    }

    public static function excluirAlteracao(int $id, int $cod): void
    {
        foreach (self::ler('legislacaoxalteracao', ['codalteracao' => $cod], '', 1) as $r)
            if ((int)$r['codlegislacao'] === $id) { self::excluir('legislacaoxalteracao', ['codalteracao' => $cod]); return; }
        throw new RuntimeException('Alteração não encontrada (pode ter sido excluída por outra pessoa).');
    }

    /** Situação, devido e data de atualização atuais da lei (para o popup e para atualizar a linha do grid). */
    public static function situacaoLei(int $id): array
    {
        $l = self::ler('legislacao', ['CODLEGISLACAO' => $id], '', 1)[0] ?? [];
        return ['situacao' => trim((string)($l['situacao_da_lei'] ?? '')), 'devido' => trim((string)($l['devido_da_lei'] ?? '')), 'data' => substr((string)($l['data_atualizacao'] ?? ''), 0, 10)];
    }

    private static function dataValida(string $v, string $rotulo): string
    {
        $v = trim($v);
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v || (int)$d->format('Y') < 1900 || $d > new DateTime('+1 year')) throw new InvalidArgumentException($rotulo . ' inválida.');
        return $v;
    }

    // ------------------------------------------------------------------ CheckList (catálogo único de perguntas) — migração 386

    public static function perguntasDaLei(int $id): array
    {
        $out = [];
        foreach (self::ler('legislacaoxpergunta', ['CODLEGISLACAO' => $id]) as $r) $out[(int)$r['CODPERGUNTA']] = true;
        if (!$out) return [];
        $lista = [];
        foreach (array_keys($out) as $cod) { $p = self::ler('perguntas', ['CODPERGUNTA' => $cod], '', 1)[0] ?? null; if ($p) $lista[] = ['cod' => $cod, 'texto' => (string)$p['DSCPERGUNTA'], 'usos' => self::usosPergunta($cod)]; }
        usort($lista, static fn($a, $b) => strnatcasecmp($a['texto'], $b['texto']));
        return $lista;
    }

    public static function buscarPerguntas(int $id, string $termo): array
    {
        $termo = trim($termo);
        if (mb_strlen($termo) < 3 && !ctype_digit($termo)) throw new InvalidArgumentException('Digite pelo menos 3 letras (ou o código da pergunta).');
        $ja = array_fill_keys(array_column(self::perguntasDaLei($id), 'cod'), true);
        $rows = ctype_digit($termo) ? self::ler('perguntas', ['CODPERGUNTA' => (int)$termo], '', 1) : self::ler('perguntas', [], $termo, 50);
        return array_map(static fn($p) => ['cod' => (int)$p['CODPERGUNTA'], 'texto' => (string)$p['DSCPERGUNTA'], 'vinculada' => isset($ja[(int)$p['CODPERGUNTA']])], $rows);
    }

    public static function vincularPergunta(int $id, int $cod): void
    {
        if (!self::lei($id) || !self::ler('perguntas', ['CODPERGUNTA' => $cod], '', 1)) throw new RuntimeException('Pergunta ou legislação não encontrada.');
        if (self::ler('legislacaoxpergunta', ['CODLEGISLACAO' => $id, 'CODPERGUNTA' => $cod], '', 1)) return;
        self::inserir('legislacaoxpergunta', ['CODLEGISLACAO' => $id, 'CODPERGUNTA' => $cod]);
    }

    public static function desvincularPergunta(int $id, int $cod): void
    {
        self::excluir('legislacaoxpergunta', ['CODLEGISLACAO' => $id, 'CODPERGUNTA' => $cod]);
    }

    /** Cria a pergunta no catálogo único e já vincula à lei. Recusa texto igual a uma pergunta existente (sem acento/maiúsculas). */
    public static function novaPergunta(int $id, string $texto): int
    {
        $texto = self::textoCatalogo($texto, 1000, 'pergunta');
        foreach (self::ler('perguntas', [], mb_substr($texto, 0, 60), 1000) as $p)
            if (self::chave((string)$p['DSCPERGUNTA']) === self::chave($texto)) throw new RuntimeException('Essa pergunta já existe no catálogo (código ' . $p['CODPERGUNTA'] . '): use "Vincular".');
        self::inserir('perguntas', ['DSCPERGUNTA' => $texto]);
        $cod = 0;
        foreach (self::ler('perguntas', [], mb_substr($texto, 0, 60), 1000) as $p) if ((string)$p['DSCPERGUNTA'] === $texto) $cod = max($cod, (int)$p['CODPERGUNTA']);
        if ($cod < 1) throw new RuntimeException('A pergunta foi gravada, mas não foi possível vinculá-la. Busque e vincule.');
        self::vincularPergunta($id, $cod);
        return $cod;
    }

    /** Muda o texto no catálogo: vale para todas as leis que usam a pergunta. */
    public static function editarPergunta(int $cod, string $texto): void
    {
        $texto = self::textoCatalogo($texto, 1000, 'pergunta');
        if (!self::ler('perguntas', ['CODPERGUNTA' => $cod], '', 1)) throw new RuntimeException('Pergunta não encontrada.');
        self::atualizar('perguntas', ['CODPERGUNTA' => $cod], ['DSCPERGUNTA' => $texto]);
    }

    /** Catálogo de perguntas em páginas, para a lista dupla (migração 391): [itens [{cod, texto}], total|null]. Busca por texto ou código. */
    public static function paginaPerguntas(string $busca, int $pagina, int $porPagina = 25): array
    {
        $busca = trim($busca);
        if ($busca !== '' && ctype_digit($busca)) {
            $p = self::ler('perguntas', ['CODPERGUNTA' => (int)$busca], '', 1);
            return [$pagina === 1 ? array_map(static fn($x) => ['cod' => (int)$x['CODPERGUNTA'], 'texto' => (string)$x['DSCPERGUNTA']], $p) : [], count($p)];
        }
        $itens = array_map(static fn($x) => ['cod' => (int)$x['CODPERGUNTA'], 'texto' => (string)($x['DSCPERGUNTA'] ?? '')],
            self::ler('perguntas', [], $busca, $porPagina, max(0, ($pagina - 1) * $porPagina)));
        $total = null;
        try {
            $s = self::legislacao()->prepare('CALL sp_crud_count(?, ?, ?)');
            $s->execute(['perguntas', '{}', $busca === '' ? null : $busca]);
            $row = $s->fetch(PDO::FETCH_NUM); while ($s->nextRowset()) {} $s->closeCursor();
            if ($row) $total = (int)$row[0];
        } catch (Throwable) {}
        return [$itens, $total];
    }

    public static function infoPergunta(int $cod): array
    {
        $p = self::ler('perguntas', ['CODPERGUNTA' => $cod], '', 1)[0] ?? null;
        if (!$p) throw new RuntimeException('Pergunta não encontrada.');
        return ['cod' => $cod, 'texto' => (string)($p['DSCPERGUNTA'] ?? ''), 'usos' => self::usosPergunta($cod)];
    }

    /** Leis que têm checklist (migração 392), para escolher de onde copiar: [{id, rotulo, codigo, qtd}], sem a própria lei. */
    public static function leisComChecklist(int $excluir, string $busca = ''): array
    {
        $chave = self::chave(trim($busca)); $out = [];
        foreach (self::ler('vw_legislacao_checklist_resumo', [], '', 1000) as $r) {
            $id = (int)$r['CODLEGISLACAO'];
            if ($id === $excluir) continue;
            $rot = self::rotulo($r);
            if ($chave !== '' && !str_contains(self::chave($rot . ' ' . ($r['CODIGO'] ?? '')), $chave)) continue;
            $out[] = ['id' => $id, 'rotulo' => $rot, 'codigo' => (string)($r['CODIGO'] ?? ''), 'situacao' => (string)($r['situacao_da_lei'] ?? ''), 'qtd' => (int)$r['QTD_PERGUNTAS']];
        }
        usort($out, static fn($a, $b) => strnatcasecmp($a['rotulo'], $b['rotulo']));
        return $out;
    }

    /** Copia para $destino as perguntas do checklist de $origem que ele ainda não tem. @return array{0:int,1:int} [copiadas, já existiam] */
    public static function copiarChecklist(int $destino, int $origem): array
    {
        if ($destino === $origem) throw new InvalidArgumentException('Escolha outra lei.');
        if (!self::lei($destino) || !self::lei($origem)) throw new RuntimeException('Legislação não encontrada.');
        $ja = [];
        for ($o = 0; ; $o += 1000) { $p = self::ler('legislacaoxpergunta', ['CODLEGISLACAO' => $destino], '', 1000, $o); foreach ($p as $r) $ja[(int)$r['CODPERGUNTA']] = true; if (count($p) < 1000) break; }
        $novas = []; $existiam = 0;
        for ($o = 0; ; $o += 1000) {
            $p = self::ler('legislacaoxpergunta', ['CODLEGISLACAO' => $origem], '', 1000, $o);
            foreach ($p as $r) { $c = (int)$r['CODPERGUNTA']; if (isset($ja[$c])) $existiam++; else $novas[$c] = true; }
            if (count($p) < 1000) break;
        }
        if (!$novas && !$existiam) throw new RuntimeException('A lei escolhida não tem checklist.');
        foreach (array_keys($novas) as $c) self::inserir('legislacaoxpergunta', ['CODLEGISLACAO' => $destino, 'CODPERGUNTA' => $c]);
        return [count($novas), $existiam];
    }

    public static function usosPergunta(int $cod): int
    {
        return count(LegislacaoEmpresas::ler(self::legislacao(), 'legislacaoxpergunta', ['CODPERGUNTA' => $cod]));
    }

    // ------------------------------------------------------------------ Palavras-chave (catálogo único no guniversal) — migração 386

    public const PROMPT_PALAVRAS = 'LEG_PALAVRAS_CHAVE';
    private static ?PDO $uni = null;

    private static function universal(): PDO
    {
        if (self::$uni === null) {
            $env = GreenPortalDatabase::env();
            self::$uni = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
            self::$uni->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$uni->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }
        return self::$uni;
    }

    private static function lerUni(string $tabela, array $filtros = [], string $busca = '', int $limite = 1000, int $offset = 0): array
    {
        $s = self::universal()->prepare('CALL sp_crud_select(:t, :f, :b, :l, :o)');
        $s->execute(['t' => $tabela, 'f' => json_encode($filtros ?: new stdClass(), JSON_UNESCAPED_UNICODE), 'b' => $busca === '' ? null : $busca, 'l' => $limite, 'o' => max(0, $offset)]);
        $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) {} $s->closeCursor();
        return array_map(static fn($x) => array_change_key_case($x, CASE_LOWER), $r);
    }

    private static function crudUni(string $sql, array $p): void
    {
        $s = self::universal()->prepare($sql); $s->execute($p); while ($s->nextRowset()) {} $s->closeCursor();
    }

    private static function palavra(int $cod): ?array
    {
        return self::lerUni('palavras_chaves', ['codpalavrachave' => $cod], '', 1)[0] ?? null;
    }

    /** Catálogo de palavras-chave em páginas, para a lista dupla (migração 391): [itens [{cod, texto}], total|null]. */
    public static function paginaPalavras(string $busca, int $pagina, int $porPagina = 25): array
    {
        $busca = trim($busca);
        $itens = array_map(static fn($p) => ['cod' => (int)$p['codpalavrachave'], 'texto' => (string)($p['dscpalavrachave'] ?? '')],
            self::lerUni('palavras_chaves', [], $busca, $porPagina, max(0, ($pagina - 1) * $porPagina)));
        $total = null;
        try {
            $s = self::universal()->prepare('CALL sp_crud_count(?, ?, ?)');
            $s->execute(['palavras_chaves', '{}', $busca === '' ? null : $busca]);
            $row = $s->fetch(PDO::FETCH_NUM); while ($s->nextRowset()) {} $s->closeCursor();
            if ($row) $total = (int)$row[0];
        } catch (Throwable) {}
        return [$itens, $total];
    }

    /**
     * Palavras sugeridas para a lei (migração 391): as usadas por leis do mesmo tipo e âmbito, do mesmo assunto e da família,
     * sem as já vinculadas, da mais provável para a menos. Pontos: família 50 por lei; assunto 10 por lei (até 20);
     * tipo+âmbito 1 por lei (até 50). @return array{0: array, 1: int} [itens [{cod, texto, motivo, pontos}], total]
     */
    public static function sugestoesPalavras(int $id, string $busca, int $pagina, int $porPagina = 25): array
    {
        $s = self::legislacao()->prepare('CALL sp_legislacao_sugestao_palavras(?)'); $s->execute([$id]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) {} $s->closeCursor();
        $ja = array_fill_keys(array_column(self::palavrasDaLei($id), 'cod'), true);
        $texto = [];
        for ($o = 0; ; $o += 1000) { $p = self::lerUni('palavras_chaves', [], '', 1000, $o); foreach ($p as $x) $texto[(int)$x['codpalavrachave']] = (string)($x['dscpalavrachave'] ?? ''); if (count($p) < 1000) break; }
        $chave = self::chave(trim($busca));
        $out = [];
        foreach ($rows as $r) {
            $cod = (int)$r['CODPALAVRACHAVE'];
            if (isset($ja[$cod]) || !isset($texto[$cod])) continue;
            if ($chave !== '' && !str_contains(self::chave($texto[$cod] . ' ' . $cod), $chave)) continue;
            $f = (int)$r['FAMILIA']; $a = (int)$r['ASSUNTO']; $g = (int)$r['TIPO_AMBITO'];
            $m = [];
            if ($f) $m[] = $f . ' lei' . ($f === 1 ? '' : 's') . ' da família';
            if ($a) $m[] = $a . ' do mesmo assunto';
            if ($g) $m[] = $g . ' do mesmo tipo e âmbito';
            $out[] = ['cod' => $cod, 'texto' => $texto[$cod], 'pontos' => 50 * $f + 10 * min($a, 20) + min($g, 50), 'motivo' => 'Usada em ' . implode(', ', $m)];
        }
        usort($out, static fn($x, $y) => [$y['pontos'], $x['texto']] <=> [$x['pontos'], $y['texto']]);
        return [array_slice($out, ($pagina - 1) * $porPagina, $porPagina), count($out)];
    }

    /** Texto e uso de uma palavra do catálogo (para o Editar da lista dupla). */
    public static function infoPalavra(int $cod): array
    {
        $p = self::palavra($cod);
        if (!$p) throw new RuntimeException('Palavra-chave não encontrada.');
        return ['cod' => $cod, 'texto' => (string)($p['dscpalavrachave'] ?? ''), 'usos' => self::usosPalavra($cod)];
    }

    public static function palavrasDaLei(int $id): array
    {
        $lista = [];
        foreach (self::ler('legislacaoxpalavraschave', ['codlegislacao' => $id]) as $r) {
            $r = array_change_key_case($r, CASE_LOWER);
            $cod = (int)$r['codpalavrachave'];
            if (isset($lista[$cod])) continue;
            $p = self::palavra($cod);
            $lista[$cod] = ['cod' => $cod, 'vinculo' => (int)$r['codlegislacaoxpalavrachave'], 'texto' => (string)($p['dscpalavrachave'] ?? '(palavra não encontrada no catálogo)'), 'usos' => self::usosPalavra($cod)];
        }
        $lista = array_values($lista);
        usort($lista, static fn($a, $b) => strnatcasecmp($a['texto'], $b['texto']));
        return $lista;
    }

    public static function buscarPalavras(int $id, string $termo): array
    {
        $termo = trim($termo);
        if (mb_strlen($termo) < 2) throw new InvalidArgumentException('Digite pelo menos 2 letras.');
        $ja = array_fill_keys(array_column(self::palavrasDaLei($id), 'cod'), true);
        return array_map(static fn($p) => ['cod' => (int)$p['codpalavrachave'], 'texto' => (string)$p['dscpalavrachave'], 'vinculada' => isset($ja[(int)$p['codpalavrachave']])], self::lerUni('palavras_chaves', [], $termo, 50));
    }

    public static function vincularPalavra(int $id, int $cod): void
    {
        if (!self::lei($id) || !self::palavra($cod)) throw new RuntimeException('Palavra-chave ou legislação não encontrada.');
        if (self::ler('legislacaoxpalavraschave', ['codlegislacao' => $id, 'codpalavrachave' => $cod], '', 1)) return;
        self::inserir('legislacaoxpalavraschave', ['codlegislacao' => $id, 'codpalavrachave' => $cod]);
    }

    public static function desvincularPalavra(int $id, int $cod): void
    {
        foreach (self::ler('legislacaoxpalavraschave', ['codlegislacao' => $id, 'codpalavrachave' => $cod]) as $r)
            self::excluir('legislacaoxpalavraschave', ['codlegislacaoxpalavrachave' => (int)array_change_key_case($r, CASE_LOWER)['codlegislacaoxpalavrachave']]);
    }

    /** Código da palavra no catálogo (sem acento/maiúsculas) ou 0. */
    public static function palavraExistente(string $texto): int
    {
        foreach (self::lerUni('palavras_chaves', [], $texto, 1000) as $p) if (self::chave((string)$p['dscpalavrachave']) === self::chave($texto)) return (int)$p['codpalavrachave'];
        return 0;
    }

    /** Cria no catálogo (ou reaproveita a existente igual) e vincula. */
    public static function novaPalavra(int $id, string $texto): int
    {
        $texto = self::textoCatalogo($texto, 200, 'palavra-chave');
        $cod = self::palavraExistente($texto);
        if ($cod < 1) {
            self::crudUni('CALL sp_crud_insert(?, ?)', ['palavras_chaves', json_encode(['dscpalavrachave' => $texto], JSON_UNESCAPED_UNICODE)]);
            $cod = self::palavraExistente($texto);
            if ($cod < 1) throw new RuntimeException('A palavra-chave foi gravada, mas não foi possível vinculá-la. Busque e vincule.');
        }
        self::vincularPalavra($id, $cod);
        return $cod;
    }

    /** Muda o texto no catálogo único: vale para todas as leis (e licenças) que usam a palavra. */
    public static function editarPalavra(int $cod, string $texto): void
    {
        $texto = self::textoCatalogo($texto, 200, 'palavra-chave');
        if (!self::palavra($cod)) throw new RuntimeException('Palavra-chave não encontrada.');
        $outra = self::palavraExistente($texto);
        if ($outra > 0 && $outra !== $cod) throw new RuntimeException('Já existe essa palavra-chave no catálogo (código ' . $outra . ').');
        self::crudUni('CALL sp_crud_update(?, ?, ?)', ['palavras_chaves', json_encode(['codpalavrachave' => $cod]), json_encode(['dscpalavrachave' => $texto], JSON_UNESCAPED_UNICODE)]);
    }

    public static function usosPalavra(int $cod): int
    {
        return count(LegislacaoEmpresas::ler(self::legislacao(), 'legislacaoxpalavraschave', ['codpalavrachave' => $cod]));
    }

    /** IA: até 12 palavras-chave para a lei, marcando as que já existem no catálogo. Nada é gravado aqui. */
    public static function sugerirPalavrasIa(int $id, string $idioma = 'pt'): array
    {
        $atuais = array_column(self::palavrasDaLei($id), 'texto');
        $r = self::perguntarIa($id, self::PROMPT_PALAVRAS, self::PROMPT_PALAVRAS, $idioma, ['palavras_ja_vinculadas' => $atuais, 'maximo_de_palavras' => 12], 600);
        $ja = array_fill_keys(array_map([self::class, 'chave'], $atuais), true);
        $out = [];
        foreach (array_slice((array)($r['palavras'] ?? $r['palavras_chave'] ?? []), 0, 12) as $p) {
            $t = is_array($p) ? (string)($p['palavra'] ?? $p['texto'] ?? '') : (string)$p;
            $t = trim((string)preg_replace('/\s+/u', ' ', $t));
            if ($t === '' || mb_strlen($t) > 200 || isset($ja[self::chave($t)])) continue;
            $ja[self::chave($t)] = true;
            $cod = self::palavraExistente($t);
            $out[] = ['texto' => $t, 'cod' => $cod, 'nova' => $cod < 1];
        }
        return $out;
    }

    private static function textoCatalogo(string $v, int $max, string $nome): string
    {
        $v = trim((string)preg_replace('/\s+/u', ' ', $v));
        if (mb_strlen($v) < 2) throw new InvalidArgumentException('Digite o texto da ' . $nome . '.');
        if (mb_strlen($v) > $max) throw new InvalidArgumentException('O texto da ' . $nome . ' passa de ' . $max . ' caracteres.');
        return $v;
    }

    public static function chave(string $v): string
    {
        if (class_exists('Normalizer')) $v = (string)preg_replace('/\p{Mn}/u', '', (string)Normalizer::normalize($v, Normalizer::FORM_D));
        return mb_strtolower(trim((string)preg_replace('/[\s.;,]+/u', ' ', $v)));
    }

    // ------------------------------------------------------------------ páginas de popup

    /** Abre a sessão, confere login e permissão e devolve [CODLEGISLACAO, permissão, lei]. Responde 401/403/404 e encerra quando não pode. */
    public static function iniciarPopup(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        require_once __DIR__ . '/UsuarioSessao.php';
        $ajax = ($_POST['ajax'] ?? '') === '1';
        $negar = static function (int $http, string $msg) use ($ajax): never {
            if ($ajax) self::json(['ok' => false, 'message' => $msg], $http);
            http_response_code($http); header('Content-Type: text/html; charset=utf-8'); exit(self::h($msg));
        };
        if (empty($_SESSION['autenticado'])) $negar(401, 'Sessão expirada. Entre de novo no portal.');
        if (!UsuarioSessao::atualizar(self::empresa(), $_SESSION)) $negar(401, 'Sessão expirada. Entre de novo no portal.');
        $perm = self::permissao();
        if (!$perm['ver']) $negar(403, 'Seu perfil não tem permissão para ver a Legislação.');
        $id = (int)($_POST['CODLEGISLACAO'] ?? $_GET['CODLEGISLACAO'] ?? 0);
        $lei = self::lei($id);
        if (!$lei) $negar(404, 'Legislação não encontrada.');
        self::csrf();
        if ($ajax && !in_array((string)($_POST['action'] ?? ''), ['estado', 'buscar', 'ld_listar'], true)) {
            if (!$perm['editar']) $negar(403, 'Seu perfil não tem permissão para alterar a Legislação (a lista é a mesma para todas as empresas).');
            if (!self::csrfValido($_POST['csrf'] ?? null)) $negar(400, 'A página expirou. Feche e abra de novo.');
        }
        session_write_close(); // a IA e a verificação de links demoram: não prende a sessão das outras telas
        return [$id, $perm, $lei];
    }

    /** Contagens de uma lei para atualizar o grid sem recarregar. */
    public static function resumo(int $id): array
    {
        return ['type' => 'legislacao-associacoes-saved', 'id' => $id] + (self::contagens([$id])[$id] ?? []);
    }

    // ------------------------------------------------------------------ util

    public static function corta(string $v, int $n): string
    {
        $v = trim((string)preg_replace('/\s+/u', ' ', $v));
        return mb_strlen($v) > $n ? mb_substr($v, 0, $n - 1) . '…' : $v;
    }

    public static function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

    public static function json(array $dados, int $http = 200): never
    {
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($dados, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function csrf(): string
    {
        if (empty($_SESSION['leg382_csrf'])) $_SESSION['leg382_csrf'] = bin2hex(random_bytes(16));
        return (string)$_SESSION['leg382_csrf'];
    }

    public static function csrfValido(?string $t): bool
    {
        return is_string($t) && !empty($_SESSION['leg382_csrf']) && hash_equals((string)$_SESSION['leg382_csrf'], $t);
    }
}
