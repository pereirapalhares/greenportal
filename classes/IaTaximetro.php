<?php
declare(strict_types=1);
require_once __DIR__ . '/AdministracaoTextos.php';

require_once __DIR__ . '/GreenPortalDatabase.php';

/**
 * Taxímetro de IA (migração 340, 23/10/2026): registra cada chamada à OpenAI no banco da empresa (ia_consumo) com os tokens que a
 * OpenAI devolve (usage), o preço do modelo e a cotação PTAX do dia (tabelas ia_precos e ia_cambio do universal) e controla o limite
 * mensal de cada empresa.
 *
 * Como usar:
 *  - quem chama a IA informa antes o contexto: IaTaximetro::contexto($pdo, ['tipo' => ..., 'registro' => ..., 'codusuario' => ...]);
 *    as classes InteligenciaArtificial* chamam IaTaximetro::registrar() logo depois da resposta da OpenAI (sucesso ou erro);
 *  - novos pedidos de IA passam por IaTaximetro::exigirDisponivel($pdo): com o consumo do mês acima do limite + folga, o pedido é
 *    recusado (IaLimiteExcedido). Os pedidos que já estavam na fila são processados normalmente.
 * Erro no taxímetro nunca interrompe a análise de IA (só vai para o log do PHP).
 */
final class IaTaximetro
{
    public const MENU = 'ADMINISTRACAO_CONSUMO_IA';
    /** Usada só quando o universal ainda não tem nenhuma cotação gravada (o custo fica marcado com a data da cotação vazia). */
    public const COTACAO_RESERVA = 5.50;
    /** Estimativa dos pedidos anteriores ao taxímetro: caracteres por token (texto em português). */
    public const CARACTERES_POR_TOKEN = 4;

    /** Tipo do pedido => [módulo, descrição]. */
    public const TIPOS = [
        'NC_ANALISE_CAUSA' => ['Não Conformidades', 'Análise da causa'],
        'NC_EFICACIA' => ['Não Conformidades', 'Avaliação da eficácia'],
        'PLANODEACAO' => ['Plano de ação', 'Evidência do plano de ação'],
        'FUNCIONARIOS' => ['Homologação de Fornecedores', 'Documento de funcionário'],
        'FUNCIONARIOCRITERIOS' => ['Homologação de Fornecedores', 'Critério de funcionário'],
        'FORNECEDORCRITERIOS' => ['Homologação de Fornecedores', 'Critério de fornecedor'],
        'LICENCAS' => ['Licenças', 'Documento de licença'],
        'LICENCA_DOCUMENTO' => ['Licenças', 'Extração de licença'],
        'CONTROLES' => ['Módulo Legal', 'Controle de documentos'],
        'INFRAESTRUTURA' => ['Módulo Legal', 'Infraestrutura'],
        'TREINAMENTOS' => ['Módulo Legal', 'Treinamentos'],
        'ASSISTENTE' => ['Assistente da aplicação', 'Pergunta ao assistente'],
        'SOLICITACAO_MELHORIA' => ['Solicitações de melhoria', 'Redação da solicitação'],
        'ML_RISCO' => ['Módulo Legal', 'Sugestão de risco (severidade × probabilidade)'],
        // Migração 346: vetores da busca semântica (índice e pesquisas) e reavaliação de lei alterada
        'BUSCA_SEMANTICA' => ['Pesquisa de documentos', 'Busca pelo sentido (índice e pesquisas)'],
        'LEI_REAVALIACAO' => ['Módulo Legal', 'Reavaliação por mudança de lei'],
        // Migração 347: sugestão dos itens do modelo de documento a partir do arquivo de exemplo
        'DOC_MODELO_SUGESTAO' => ['Homologação de Fornecedores', 'Sugestão de modelo de documento'],
        'DOC_ADULTERACAO' => ['Homologação de Fornecedores', 'Análise de adulteração de documento'],
        // Migração 382: links onde acompanhar alterações da lei (Manutenção Legal › Legislação › URL de pesquisa)
        'LEG_URL_PESQUISA' => ['Módulo Legal', 'Sugestão de URL de pesquisa da legislação'],
        'LEG_PALAVRAS_CHAVE' => ['Módulo Legal', 'Sugestão de palavras-chave da legislação'],
    ];

    public const SITUACOES = [
        'SEM_LIMITE' => ['Sem limite', 'secondary'], 'NORMAL' => ['Dentro do limite', 'success'], 'AVISO' => ['Perto do limite', 'warning'],
        'EXCEDIDO' => ['Limite atingido (usando a folga)', 'danger'], 'BLOQUEADO' => ['Bloqueado', 'dark'],
    ];

    private static ?array $ctx = null;
    private static ?PDO $universal = null;
    private static ?array $precos = null;
    private static ?array $cambio = null;
    private static ?array $env = null;

    // ------------------------------------------------------------------ contexto e registro

    /**
     * Contexto da próxima chamada: tipo, entidade, registro, codjob, codusuario, origem (FILA | TELA), modulo (opcional).
     * Vale até IaTaximetro::limpar() (ou o próximo contexto).
     */
    public static function contexto(PDO $pdo, array $c): void
    {
        self::$ctx = ['pdo' => $pdo] + $c;
    }

    public static function limpar(): void
    {
        self::$ctx = null;
    }

    public static function modulo(string $tipo): string
    {
        return self::TIPOS[strtoupper(trim($tipo))][0] ?? 'Outros';
    }

    public static function descricaoTipo(string $tipo): string
    {
        return adm_t(self::TIPOS[strtoupper(trim($tipo))][1] ?? $tipo);
    }

    /**
     * Grava a chamada (as classes InteligenciaArtificial* chamam logo depois da resposta). $resposta = JSON decodificado da OpenAI
     * (com "usage" e "model"); null quando a comunicação falhou.
     */
    public static function registrar(?array $resposta, string $modelo, int $duracaoMs, bool $sucesso, ?string $erro = null): void
    {
        try {
            $c = self::$ctx ?? self::contextoDaSessao();
            if (!$c) { error_log('[IaTaximetro] chamada de IA sem contexto (não registrada): ' . $modelo); return; }
            $u = is_array($resposta['usage'] ?? null) ? $resposta['usage'] : [];
            $entrada = (int)($u['prompt_tokens'] ?? $u['input_tokens'] ?? 0);
            $saida = (int)($u['completion_tokens'] ?? $u['output_tokens'] ?? 0);
            $cache = (int)($u['prompt_tokens_details']['cached_tokens'] ?? $u['input_tokens_details']['cached_tokens'] ?? 0);
            $modeloReal = trim((string)($resposta['model'] ?? '')) ?: $modelo;
            if ($entrada === 0 && $saida === 0 && $sucesso) return; // resposta sem consumo informado
            $custo = self::custo($modeloReal, $entrada, $cache, $saida, date('Y-m-d'));
            $tipo = strtoupper((string)($c['tipo'] ?? 'OUTROS'));
            self::gravar($c['pdo'], [
                'criado' => null, 'empresa' => (int)($c['codempresa'] ?? ($_SESSION['tenant']['codempresa'] ?? 0)), 'usuario' => (int)($c['codusuario'] ?? 0),
                'modulo' => (string)($c['modulo'] ?? self::modulo($tipo)), 'tipo' => $tipo, 'entidade' => $c['entidade'] ?? null, 'registro' => isset($c['registro']) ? (string)$c['registro'] : null,
                'codjob' => (int)($c['codjob'] ?? 0), 'origem' => (string)($c['origem'] ?? 'TELA'), 'modelo' => $modeloReal, 'preco_modelo' => $custo['preco_modelo'],
                'entrada' => $entrada, 'cache' => $cache, 'saida' => $saida, 'usd' => $custo['usd'], 'cotacao' => $custo['cotacao'], 'cotacao_data' => $custo['cotacao_data'],
                'estimado' => 0, 'sucesso' => $sucesso ? 1 : 0, 'ms' => $duracaoMs, 'erro' => $erro !== null ? mb_substr($erro, 0, 500) : null, 'referencia' => null,
            ]);
        } catch (Throwable $e) {
            error_log('[IaTaximetro] não registrou a chamada: ' . $e->getMessage());
        }
    }

    /** Grava uma linha (sp_ia_consumo_registrar); devolve 1 se gravou (0 = referência já registrada). */
    public static function gravar(PDO $pdo, array $l): int
    {
        $s = $pdo->prepare('CALL sp_ia_consumo_registrar(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $s->execute([$l['criado'], $l['empresa'] ?: null, $l['usuario'] ?: null, mb_substr((string)$l['modulo'], 0, 60), mb_substr((string)$l['tipo'], 0, 50),
            $l['entidade'] !== null ? mb_substr((string)$l['entidade'], 0, 100) : null, $l['registro'] !== null ? mb_substr((string)$l['registro'], 0, 100) : null,
            $l['codjob'] ?: null, $l['origem'], mb_substr((string)$l['modelo'], 0, 100), $l['preco_modelo'], (int)$l['entrada'], (int)$l['cache'], (int)$l['saida'],
            round((float)$l['usd'], 6), round((float)$l['cotacao'], 4), $l['cotacao_data'], (int)$l['estimado'], (int)$l['sucesso'], $l['ms'], $l['erro'], $l['referencia']]);
        $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        while ($s->nextRowset()) {}
        $s->closeCursor();
        return (int)($r['GRAVADOS'] ?? 0) > 0 ? 1 : 0;
    }

    /** Chamada feita numa tela sem contexto informado: usa o banco e o usuário da sessão. */
    private static function contextoDaSessao(): ?array
    {
        if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) return null;
        require_once __DIR__ . '/TenantContext.php';
        return ['pdo' => TenantContext::connect(GreenPortalDatabase::env(), $_SESSION), 'tipo' => 'OUTROS', 'codusuario' => (int)($_SESSION['usuario']['codusuario'] ?? 0),
            'registro' => (string)($_SERVER['SCRIPT_NAME'] ?? ''), 'origem' => 'TELA'];
    }

    // ------------------------------------------------------------------ preço e cotação

    /** Custo de uma chamada: ['usd', 'cotacao', 'cotacao_data', 'preco_modelo']. Tokens em cache pagam o preço de cache. */
    public static function custo(string $modelo, int $entrada, int $cache, int $saida, ?string $data = null): array
    {
        $data ??= date('Y-m-d');
        $p = self::preco($modelo, $data);
        $cache = min(max(0, $cache), max(0, $entrada));
        $usd = 0.0;
        if ($p) $usd = (($entrada - $cache) * (float)$p['USD_ENTRADA'] + $cache * (float)$p['USD_ENTRADA_CACHE'] + $saida * (float)$p['USD_SAIDA']) / 1000000;
        $c = self::cotacao($data);
        return ['usd' => $usd, 'cotacao' => $c['valor'], 'cotacao_data' => $c['data'], 'preco_modelo' => $p['MODELO'] ?? null];
    }

    /**
     * Preço do modelo na data: o nome igual ou o nome seguido de "-" (versão datada, ex.: gpt-4o-mini-2024-07-18); vale o nome mais
     * comprido e, nele, a vigência mais recente até a data. Sem preço próprio, o preço padrão (*).
     */
    public static function preco(string $modelo, string $data): ?array
    {
        $modelo = strtolower(trim($modelo));
        $melhor = null;
        $padrao = null;
        foreach (self::precos() as $p) {
            if ((int)$p['ATIVO'] !== 1 || (string)$p['VIGENTE_DESDE'] > $data) continue;
            $m = (string)$p['MODELO'];
            if ($m === '*') { if (!$padrao || $p['VIGENTE_DESDE'] > $padrao['VIGENTE_DESDE']) $padrao = $p; continue; }
            if ($modelo !== $m && !str_starts_with($modelo, $m . '-')) continue;
            if (!$melhor || strlen($m) > strlen($melhor['MODELO']) || (strlen($m) === strlen($melhor['MODELO']) && $p['VIGENTE_DESDE'] > $melhor['VIGENTE_DESDE'])) $melhor = $p;
        }
        return $melhor ?? $padrao;
    }

    /** Cotação (PTAX venda) do dia ou do último dia anterior com cotação: ['valor', 'data', 'fonte']. */
    public static function cotacao(string $data): array
    {
        $achada = null;
        foreach (self::cambio() as $c) {
            if ((string)$c['DATA'] <= $data) { $achada = $c; break; } // lista em ordem decrescente
        }
        $achada ??= self::cambio() ? self::cambio()[count(self::cambio()) - 1] : null;
        if (!$achada) return ['valor' => self::COTACAO_RESERVA, 'data' => null, 'fonte' => 'RESERVA'];
        return ['valor' => (float)$achada['COTACAO_VENDA'], 'data' => (string)$achada['DATA'], 'fonte' => (string)$achada['FONTE']];
    }

    public static function precos(): array
    {
        if (self::$precos === null) {
            try { self::$precos = self::universal()->query('SELECT * FROM vw_ia_precos ORDER BY MODELO, VIGENTE_DESDE DESC')->fetchAll(PDO::FETCH_ASSOC); }
            catch (Throwable $e) { error_log('[IaTaximetro] preços: ' . $e->getMessage()); self::$precos = []; }
        }
        return self::$precos;
    }

    public static function cambio(): array
    {
        if (self::$cambio === null) {
            try { self::$cambio = self::universal()->query('SELECT DATA, COTACAO_VENDA, COTACAO_COMPRA, FONTE FROM vw_ia_cambio ORDER BY DATA DESC')->fetchAll(PDO::FETCH_ASSOC); }
            catch (Throwable $e) { error_log('[IaTaximetro] câmbio: ' . $e->getMessage()); self::$cambio = []; }
        }
        return self::$cambio;
    }

    public static function recarregar(): void
    {
        self::$precos = null;
        self::$cambio = null;
    }

    public static function universal(): PDO
    {
        if (self::$universal) return self::$universal;
        $env = self::env();
        $u = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
        $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $u->exec('SET NAMES utf8mb4');
        return self::$universal = $u;
    }

    private static function env(): array
    {
        return self::$env ??= GreenPortalDatabase::env();
    }

    /** Modelo configurado para as análises (mesma regra das classes InteligenciaArtificial*), usado na estimativa dos pedidos antigos. */
    public static function modeloPadrao(): string
    {
        $env = self::env();
        $model = (string)($env['GPT_AI_MODEL'] ?? '');
        if ($model !== '') return $model;
        $arquivo = (string)($env['GPT_AI_CREDENTIALS_FILE'] ?? dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'greengde' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php');
        if (is_file($arquivo) && preg_match('/define\s*\(\s*[\'"]OPENAI_MODEL[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/i', (string)file_get_contents($arquivo), $m)) return trim($m[1]);
        return 'gpt-4o-mini';
    }

    // ------------------------------------------------------------------ limite

    /** Situação do mês (vw_ia_limite_situacao); null antes da migração 340. */
    public static function situacao(PDO $pdo): ?array
    {
        try {
            $r = $pdo->query('SELECT * FROM vw_ia_limite_situacao')->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Recusa um novo pedido de IA quando o consumo do mês passou do limite + folga. */
    public static function exigirDisponivel(PDO $pdo): void
    {
        $s = self::situacao($pdo);
        if ($s && (string)$s['SITUACAO'] === 'BLOQUEADO') throw new IaLimiteExcedido(self::mensagemBloqueio($s));
    }

    public static function mensagemBloqueio(array $s): string
    {
        return adm_t('Limite mensal de IA da empresa atingido (') . self::moeda((float)$s['CONSUMO_BRL']) . adm_t(' usados de ') . self::moeda((float)$s['LIMITE_BRL'])
            . adm_t(', com a folga de ') . self::numero((float)$s['FOLGA_PCT'], 0) . '%). Os novos pedidos de IA estão bloqueados até o administrador aumentar o limite; '
            . adm_t('as análises já pedidas continuam. Procure o administrador do sistema.');
    }

    public static function moeda(?float $v, int $casas = 2): string
    {
        return $v === null ? '—' : 'R$ ' . number_format($v, $casas, ',', '.');
    }

    public static function numero(?float $v, int $casas = 0): string
    {
        return $v === null ? '—' : number_format($v, $casas, ',', '.');
    }

    // ------------------------------------------------------------------ PTAX (Banco Central)

    /**
     * Cotações PTAX do período (API pública do Banco Central, Olinda): ['Y-m-d' => ['venda' => x, 'compra' => y]].
     * @throws RuntimeException sem acesso à internet ou resposta inválida
     */
    public static function ptaxBuscar(string $de, string $ate): array
    {
        $f = static fn(string $d): string => (new DateTimeImmutable($d))->format('m-d-Y');
        $url = 'https://olinda.bcb.gov.br/olinda/servico/PTAX/versao/v1/odata/CotacaoDolarPeriodo(dataInicial=@dataInicial,dataFinalCotacao=@dataFinalCotacao)'
            . '?@dataInicial=%27' . $f($de) . '%27&@dataFinalCotacao=%27' . $f($ate) . '%27&$top=10000&$format=json&$select=cotacaoCompra,cotacaoVenda,dataHoraCotacao';
        $ch = curl_init($url);
        $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Accept: application/json']];
        $ca = self::cacert();
        if ($ca !== null) { $op[CURLOPT_SSL_VERIFYPEER] = true; $op[CURLOPT_SSL_VERIFYHOST] = 2; $op[CURLOPT_CAINFO] = $ca; }
        curl_setopt_array($ch, $op);
        $raw = curl_exec($ch); $erro = curl_error($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($raw === false || $erro !== '') throw new RuntimeException('PTAX: sem acesso ao Banco Central (' . ($erro ?: 'resposta vazia') . ').');
        if ($status < 200 || $status >= 300) throw new RuntimeException('PTAX: o Banco Central respondeu HTTP ' . $status . '.');
        $j = json_decode((string)$raw, true);
        if (!is_array($j) || !isset($j['value']) || !is_array($j['value'])) throw new RuntimeException('PTAX: resposta inválida do Banco Central.');
        $out = [];
        foreach ($j['value'] as $v) {
            $d = substr((string)($v['dataHoraCotacao'] ?? ''), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !is_numeric($v['cotacaoVenda'] ?? null)) continue;
            $out[$d] = ['venda' => (float)$v['cotacaoVenda'], 'compra' => is_numeric($v['cotacaoCompra'] ?? null) ? (float)$v['cotacaoCompra'] : null]; // a última do dia vale
        }
        ksort($out);
        return $out;
    }

    /**
     * Grava no universal as cotações que faltam (desde o dia seguinte à última gravada, ou $desde, até hoje). As digitadas à mão
     * (fonte MANUAL) não são trocadas. Devolve ['gravadas' => n, 'ultima' => 'Y-m-d' | null].
     */
    public static function ptaxAtualizar(?string $desde = null, string $usuario = 'SERVICO'): array
    {
        $u = self::universal();
        $ultima = (string)($u->query('SELECT MAX(DATA) FROM vw_ia_cambio')->fetchColumn() ?: '');
        $hoje = date('Y-m-d');
        $de = $desde ?? ($ultima !== '' ? (new DateTimeImmutable($ultima))->modify('+1 day')->format('Y-m-d') : (new DateTimeImmutable($hoje))->modify('-15 days')->format('Y-m-d'));
        if ($de > $hoje) return ['gravadas' => 0, 'ultima' => $ultima ?: null];
        $n = 0;
        $s = $u->prepare('CALL sp_ia_cambio_gravar(?, ?, ?, ?, ?)');
        foreach (self::ptaxBuscar($de, $hoje) as $d => $c) {
            $s->execute([$d, round($c['venda'], 4), $c['compra'] !== null ? round($c['compra'], 4) : null, 'PTAX', mb_substr($usuario, 0, 150)]);
            while ($s->nextRowset()) {}
            $s->closeCursor();
            $n++;
        }
        self::$cambio = null;
        return ['gravadas' => $n, 'ultima' => (string)($u->query('SELECT MAX(DATA) FROM vw_ia_cambio')->fetchColumn() ?: '') ?: null];
    }

    private static function cacert(): ?string
    {
        foreach (['E:\\wamp\\apps\\phpmyadmin5.2.3\\vendor\\composer\\ca-bundle\\res\\cacert.pem', 'C:\\wamp\\bin\\php\\cacert.pem', 'C:\\wamp64\\bin\\php\\cacert.pem', 'C:\\php\\cacert.pem',
            php_ini_loaded_file() ? dirname((string)php_ini_loaded_file()) . DIRECTORY_SEPARATOR . 'cacert.pem' : ''] as $c) {
            if ($c !== '' && is_file($c)) return $c;
        }
        return null;
    }
}

/** Pedido de IA recusado: limite mensal + folga da empresa esgotados. A mensagem pode ir direto para o usuário. */
final class IaLimiteExcedido extends RuntimeException {}
