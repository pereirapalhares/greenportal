<?php
declare(strict_types=1);

require_once __DIR__ . '/RequisitoRisco.php';
require_once __DIR__ . '/IaTaximetro.php';
require_once __DIR__ . '/DocumentAiQueueService.php';

/**
 * Requisitos legais, etapa 2 (migração 342): a IA sugere a Severidade e a Probabilidade dos itens de gestão.
 *   - pedido de um item (botão "Sugerir risco com IA" no cadastro do pilar) ou em lote (itens com "risco não avaliado",
 *     botão no dashboard e na tela de sugestões); o lote só roda quando alguém pede;
 *   - os itens vão para a fila de IA (tipo ML_RISCO, até MAX_ITENS_PEDIDO por pedido) com o prompt ML_RISCO (Setup › Prompts de IA)
 *     e o contexto de cada item: dados do cadastro, requisitos legais ligados (norma, assunto, artigos, texto), unidade, situação
 *     e os critérios de severidade do pilar e de probabilidade;
 *   - o serviço "ia" do Agendador chama concluirPedido() quando o pedido termina; o alerta sai quando o lote termina;
 *   - nada é gravado no item sem a decisão de quem pode alterar o cadastro dele (podeDecidir): aceitar, ajustar ou recusar.
 * Tudo por procedures e views (sp_ml_risco_*, vw_ml_risco_*, sp_crud_select); cada chamada passa pelo taxímetro de IA.
 */
final class RequisitoRiscoIa
{
    public const TIPO = 'ML_RISCO';
    public const PROMPT = 'ML_RISCO';
    public const MENU = 'MODULOLEGAL_RISCO_SUGESTOES';
    public const TELA = '/principal/modulolegal_risco_sugestoes.php';
    public const MAX_ITENS_PEDIDO = 8;
    public const MAX_LOTE = 300;
    /** Menu principal e menu "Minhas…" de cada pilar (permissão de edição = pode decidir a sugestão). */
    public const MENUS = [
        'LICENCAS' => ['LICENCAS', 'MINHAS_LICENCAS'],
        'MEDICAO' => ['MM_PLANO_MEDICAO_MONITORAMENTO', null],
        'TREINAMENTOS' => ['MODULOLEGAL_TREINAMENTOS', 'MEUS_TREINAMENTOS'],
        'INFRAESTRUTURA' => ['MODULOLEGAL_INFRAESTRUTURA', 'MINHAS_INFRAESTRUTURAS'],
        'CONTROLES' => ['CONTROLE_DOCUMENTOS', 'MEUS_CONTROLES'],
        'HOMOLOGACAO' => ['HOMOLOGACAO_FORNECEDOR_FORNECEDOR', null],
    ];
    /** Colunas do cadastro que não vão para a IA (dados pessoais, arquivos, controle). */
    private const COLUNA_FORA = '/(SENHA|PASSWORD|EMAIL|FONE|TELEFONE|CELULAR|CPF|CNPJ|RG$|TOKEN|ARQUIVO|CAMINHO|ANEXO|HASH|IP$|USUARIO|CRIADO|ALTERADO|ATUALIZADO|STATUS_VERSAO|VERSAO)/i';

    public static function disponivel(PDO $pdo): bool
    {
        static $cache = [];
        $k = spl_object_id($pdo);
        if (!isset($cache[$k])) {
            try { $pdo->query('SELECT 1 FROM vw_ml_risco_sugestoes LIMIT 1')->fetchAll(); $cache[$k] = RequisitoRisco::disponivel($pdo); }
            catch (Throwable) { $cache[$k] = false; }
        }
        return $cache[$k];
    }

    // ------------------------------------------------------------------ permissão

    /** Pode aceitar/ajustar/recusar: quem pode alterar o cadastro do item (edição na transação do pilar). */
    public static function podeDecidir(PDO $pdo, string $area, int $item, int $uid): bool
    {
        require_once __DIR__ . '/PermissaoTransacao.php';
        [$principal, $minhas] = self::MENUS[$area] ?? [null, null];
        if ($principal === null) return false;
        $p = PermissaoTransacao::obter($principal);
        if (!empty($p['pode_editar']) && !empty($p['pode_visualizar_total'])) return true;
        $m = $minhas !== null ? PermissaoTransacao::obter($minhas) : [];
        if (empty($p['pode_editar']) && empty($m['pode_editar'])) return false;
        return self::responsavel($pdo, $area, $item, $uid);
    }

    /** O usuário é responsável pelo item (responsabilidade do registro ou de todos os registros da tabela). */
    public static function responsavel(PDO $pdo, string $area, int $item, int $uid): bool
    {
        if ($uid <= 0) return false;
        try {
            foreach (self::ler($pdo, 'vw_ml_risco_responsaveis', ['AREA' => self::exato($area), 'CODUSUARIO' => self::exato($uid)], 1000) as $r)
                if (in_array((int)$r['ITEM_ID'], [0, $item], true)) return true;
        } catch (Throwable) {}
        return false;
    }

    // ------------------------------------------------------------------ pedido

    /** Pedido de um item (cadastro do pilar). $cadastro = linha do grid (dados que a tela mostra). @return ['lote', 'sugestao'] */
    public static function solicitarItem(PDO $pdo, int $uid, string $idioma, string $area, int $item, array $cadastro = []): array
    {
        $area = strtoupper($area);
        if (!isset(RequisitoRisco::AREAS[$area]) || $item <= 0) throw new RuntimeException('Item inválido.');
        $prompt = self::prompt($pdo, $idioma);
        IaTaximetro::exigirDisponivel($pdo);
        $lote = (int)(self::chamar($pdo, 'CALL sp_ml_risco_lote_criar(?, ?, ?)', [$uid, 'ITEM', $area])[0]['CODLOTE'] ?? 0);
        $nome = self::nomeItem($pdo, $area, $item, $cadastro);
        $s = self::chamar($pdo, 'CALL sp_ml_risco_sugestao_criar(?, ?, ?, ?, ?)', [$lote, $area, $item, $nome, 'A1'])[0] ?? [];
        $itens = [['ref' => 'A1', 'codsugestao' => (int)$s['CODSUGESTAO'], 'area' => $area, 'chave' => (int)$s['ITEM_ID'], 'id' => $item, 'nome' => $nome, 'cadastro' => $cadastro]];
        self::enfileirar($pdo, $uid, $idioma, $lote, $itens, $prompt);
        return ['lote' => $lote, 'sugestao' => (int)$s['CODSUGESTAO']];
    }

    /**
     * Lote: itens aplicáveis ainda sem risco (vw_ml_risco_nao_avaliados), sem sugestão aguardando, a revisar ou recusada, até $limite.
     * @return ['lote', 'itens', 'pedidos', 'restantes']
     */
    public static function solicitarLote(PDO $pdo, int $uid, string $idioma, string $area = '', int $limite = self::MAX_LOTE): array
    {
        $area = strtoupper(trim($area));
        if ($area !== '' && !isset(RequisitoRisco::AREAS[$area])) throw new RuntimeException('Área inválida.');
        $limite = max(1, min(self::MAX_LOTE, $limite));
        $abertas = [];
        // Fora do lote: os que já têm sugestão aguardando ou a revisar, e os que tiveram a sugestão recusada (o pedido de um item
        // pelo cadastro continua possível)
        foreach (['AGUARDANDO', 'PENDENTE', 'RECUSADA'] as $st) foreach (self::ler($pdo, 'vw_ml_risco_sugestoes', ['STATUS' => self::exato($st)], 20000) as $s) $abertas[$s['AREA'] . ':' . $s['ITEM_ID']] = true;
        $candidatos = [];
        foreach (self::ler($pdo, 'vw_ml_risco_nao_avaliados', $area !== '' ? ['AREA' => self::exato($area)] : [], 20000) as $i) {
            if (isset($abertas[$i['AREA'] . ':' . $i['ITEM_ID']])) continue;
            $candidatos[] = $i;
        }
        if (!$candidatos) return ['lote' => 0, 'itens' => 0, 'pedidos' => 0, 'restantes' => 0];
        usort($candidatos, static fn($a, $b) => [(int)$b['REQUISITOS'], $a['AREA'], $a['ITEM']] <=> [(int)$a['REQUISITOS'], $b['AREA'], $b['ITEM']]);
        $restantes = max(0, count($candidatos) - $limite);
        $candidatos = array_slice($candidatos, 0, $limite);
        $prompt = self::prompt($pdo, $idioma);
        IaTaximetro::exigirDisponivel($pdo);
        $lote = (int)(self::chamar($pdo, 'CALL sp_ml_risco_lote_criar(?, ?, ?)', [$uid, 'LOTE', $area])[0]['CODLOTE'] ?? 0);
        $itens = [];
        foreach ($candidatos as $n => $c) {
            $ref = 'A' . (($n % self::MAX_ITENS_PEDIDO) + 1);
            $s = self::chamar($pdo, 'CALL sp_ml_risco_sugestao_criar(?, ?, ?, ?, ?)', [$lote, (string)$c['AREA'], (int)$c['ITEM_ID'], (string)$c['ITEM'], $ref])[0] ?? [];
            $itens[] = ['ref' => $ref, 'codsugestao' => (int)$s['CODSUGESTAO'], 'area' => (string)$c['AREA'], 'chave' => (int)$s['ITEM_ID'], 'id' => (int)$c['ITEM_ID'], 'nome' => (string)$c['ITEM'], 'cadastro' => []];
        }
        $pedidos = 0;
        foreach (array_chunk($itens, self::MAX_ITENS_PEDIDO) as $grupo) { self::enfileirar($pdo, $uid, $idioma, $lote, $grupo, $prompt); $pedidos++; }
        return ['lote' => $lote, 'itens' => count($itens), 'pedidos' => $pedidos, 'restantes' => $restantes];
    }

    private static function enfileirar(PDO $pdo, int $uid, string $idioma, int $lote, array $itens, string $prompt): void
    {
        $texto = self::contexto($pdo, $itens, $idioma);
        // O limite da empresa já foi conferido no começo do pedido: um lote não fica pela metade por causa do limite
        $job = (new DocumentAiQueueService($pdo))->enqueue(['chave_idempotencia' => 'IA:' . self::TIPO . ':' . $lote . ':' . $itens[0]['codsugestao'] . ':' . bin2hex(random_bytes(4)),
            'tipo' => self::TIPO, 'entidade' => 'ml_risco_sugestoes', 'registro_id' => (string)$lote, 'arquivo' => 'ml_risco', 'codanalise_ged' => 0,
            'codusuario' => $uid, 'idioma' => in_array($idioma, ['pt', 'en', 'es'], true) ? $idioma : 'pt', 'prompt' => $prompt, 'texto' => $texto, 'ignorar_limite' => true]);
        foreach ($itens as $i) self::chamar($pdo, 'CALL sp_ml_risco_sugestao_job(?, ?)', [$i['codsugestao'], (int)$job['codjob']]);
    }

    /** Prompt ML_RISCO (Setup › Prompts de IA) no idioma do usuário. */
    public static function prompt(PDO $pdo, string $idioma): string
    {
        $p = '';
        try {
            $s = $pdo->prepare('CALL sp_ia_prompt_obter(?, ?)'); $s->execute([self::PROMPT, $idioma]);
            $p = trim((string)(($s->fetch(PDO::FETCH_ASSOC) ?: [])['prompt'] ?? '')); while ($s->nextRowset()) {} $s->closeCursor();
        } catch (Throwable) {}
        if ($p === '') throw new RuntimeException('O prompt ' . self::PROMPT . ' não está cadastrado ou está inativo (Setup › Prompts de IA; migração 342).');
        return $p;
    }

    // ------------------------------------------------------------------ contexto do pedido

    /** Texto do pedido: critérios do pilar e, para cada item (ref), cadastro, situação, unidade e requisitos legais ligados. */
    public static function contexto(PDO $pdo, array $itens, string $idioma = 'pt'): string
    {
        static $cache = [];
        $ck = spl_object_id($pdo);
        if (!isset($cache[$ck])) {
            $cache[$ck] = [];
            try { foreach (self::ler($pdo, 'vw_situacao_legal_itens', [], 50000) as $r) $cache[$ck][$r['AREA'] . ':' . $r['CHAVE']][] = $r; } catch (Throwable) {}
        }
        $evid = $cache[$ck];
        $areas = array_values(array_unique(array_column($itens, 'area')));
        $t = "PEDIDO: avaliar o risco de " . count($itens) . " item(ns) de gestão de requisitos legais. Para cada item informe a Severidade (1 a 4, pelos critérios da área do item) e a Probabilidade (1 a 4), com a justificativa.\n";
        $t .= "Pontuação = Severidade × Probabilidade: 1–3 Baixo, 4–6 Moderado, 8–9 Alto, 12–16 ou Severidade 4 = Crítico.\n\n";
        foreach ($areas as $a) {
            $t .= "CRITÉRIOS DE SEVERIDADE — " . RequisitoRisco::AREAS[$a][0] . " (área $a):\n";
            foreach (RequisitoRisco::CRITERIOS[$a] ?? [] as $n => $c) $t .= "  $n " . RequisitoRisco::ROTULOS_SEVERIDADE[$n] . ": $c\n";
        }
        $t .= "CRITÉRIOS DE PROBABILIDADE (todas as áreas):\n";
        foreach (RequisitoRisco::CRITERIOS['PROBABILIDADE'] as $n => $c) $t .= "  $n " . RequisitoRisco::ROTULOS_PROBABILIDADE[$n] . ": $c\n";
        $reqCache = [];
        foreach ($itens as $i) {
            $a = $i['area'];
            $t .= "\n=== ITEM ref=" . $i['ref'] . " | área " . $a . " (" . RequisitoRisco::AREAS[$a][0] . ") ===\n";
            $t .= "Item: " . self::corta((string)$i['nome'], 300) . "\n";
            $dados = $i['cadastro'] ?: self::cadastro($pdo, $a, (int)$i['id'] ?: (int)$i['chave']);
            $linhas = [];
            foreach ($dados as $k => $v) {
                if (!is_scalar($v) || preg_match(self::COLUNA_FORA, (string)$k) || (preg_match('/^COD/i', (string)$k) && is_numeric($v))) continue;
                $v = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$v)) ?? '');
                if ($v === '' || $v === '0' || mb_strlen($v) < 2 || str_starts_with($v, '0000-00-00')) continue;
                $linhas[] = $k . ': ' . self::corta($v, 400);
                if (count($linhas) >= 18) break;
            }
            if ($linhas) $t .= "Cadastro: " . implode(' | ', $linhas) . "\n";
            $ev = $evid[$a . ':' . $i['chave']] ?? [];
            if ($ev) {
                $t .= "Situação atual no sistema: " . self::corta((string)$ev[0]['STATUS'] . ' — ' . (string)$ev[0]['MOTIVO'], 300) . "\n";
                $t .= "Requisitos legais ligados (" . count(array_unique(array_map(static fn($e) => $e['CODLEGISLACAO'] . ':' . $e['CODUNIDADE'], $ev))) . "):\n";
                $vistos = 0;
                foreach ($ev as $e) {
                    $k = $e['CODLEGISLACAO'] . ':' . $e['CODUNIDADE'];
                    if (isset($reqCache[$k]) && $reqCache[$k] === false) continue;
                    if (!isset($reqCache[$k])) {
                        $r = self::ler($pdo, 'vw_modulolegal_requisitos_situacao_grid', ['CODLEGISLACAO' => self::exato((int)$e['CODLEGISLACAO']), 'CODUNIDADE' => self::exato((int)$e['CODUNIDADE'])], 1)[0] ?? null;
                        $reqCache[$k] = $r ?: false;
                        if (!$r) continue;
                    }
                    $r = $reqCache[$k];
                    $norma = trim(trim((string)($r['DSCTIPO'] ?? '')) . ' ' . trim((string)($r['NR'] ?? '')) . (trim((string)($r['ANO'] ?? '')) !== '' ? '/' . trim((string)$r['ANO']) : ''));
                    $t .= "  - " . trim((string)($r['CODIGO'] ?? '') . ($norma !== '' ? ' (' . $norma . ')' : '')) . ' | unidade: ' . trim((string)($r['UNIDADE'] ?? ''))
                        . (trim((string)($r['DSCAMBITO'] ?? '')) !== '' ? ' | âmbito: ' . trim((string)$r['DSCAMBITO']) : '')
                        . (trim((string)($r['DSCASSUNTO'] ?? '')) !== '' ? ' | assunto: ' . self::corta((string)$r['DSCASSUNTO'], 200) : '')
                        . (trim((string)($r['ARTIGOS'] ?? '')) !== '' ? ' | artigos: ' . self::corta((string)$r['ARTIGOS'], 200) : '')
                        . (trim(strip_tags((string)($r['PADRAO'] ?? ''))) !== '' ? ' | texto: ' . self::corta(strip_tags((string)$r['PADRAO']), 700) : '') . "\n";
                    if (++$vistos >= 4) break;
                }
            } else {
                $t .= "Requisitos legais ligados: nenhum (o item ainda não foi ligado a um requisito).\n";
            }
        }
        $t .= "\nResponda só o JSON pedido, com um objeto por ref (" . implode(', ', array_column($itens, 'ref')) . ").";
        return $t;
    }

    /** Dados do cadastro do item (tabela do pilar, por procedure). */
    private static function cadastro(PDO $pdo, string $area, int $id): array
    {
        [, , $tabela, $chave] = RequisitoRisco::AREAS[$area];
        try { return self::ler($pdo, $tabela, [$chave => self::exato($id)], 1)[0] ?? []; } catch (Throwable) { return []; }
    }

    private static function nomeItem(PDO $pdo, string $area, int $item, array $cadastro): string
    {
        foreach (['DSCLICENCA', 'DSCMONITORAMENTO', 'DSCTREINAMENTO', 'dsctreinamento', 'DSCINFRAESTRUTURA', 'dscinfraestrutura', 'TITULO', 'NOMEFANTASIA', 'RAZAOSOCIAL', 'DESCRICAO'] as $c)
            if (trim((string)($cadastro[$c] ?? '')) !== '') return self::corta((string)$cadastro[$c], 300);
        $d = self::cadastro($pdo, $area, $item);
        foreach (['DSCLICENCA', 'DSCMONITORAMENTO', 'dsctreinamento', 'DSCTREINAMENTO', 'dscinfraestrutura', 'DSCINFRAESTRUTURA', 'TITULO', 'NOMEFANTASIA', 'RAZAOSOCIAL', 'DESCRICAO'] as $c)
            if (trim((string)($d[$c] ?? '')) !== '') return self::corta((string)$d[$c], 300);
        return RequisitoRisco::AREAS[$area][1] . ' #' . $item;
    }

    // ------------------------------------------------------------------ resposta da IA (serviço "ia")

    /** Pedido terminado (pronto ou erro): grava as sugestões dos itens e confere se o lote acabou (alerta). Falha aqui não para a fila. */
    public static function concluirPedido(PDO $pdo, int $codjob): void
    {
        $job = (new DocumentAiQueueService($pdo))->find($codjob);
        if (!$job) return;
        $sugs = self::ler($pdo, 'vw_ml_risco_sugestoes', ['CODJOB' => self::exato($codjob)], 100);
        if (!$sugs) return;
        $ok = (string)$job['status'] === 'CONCLUIDA';
        $resp = [];
        if ($ok) {
            $r = json_decode((string)($job['resultado_json'] ?? ''), true);
            $lista = $r['sugestao']['itens'] ?? $r['sugestao']['items'] ?? (isset($r['sugestao']['ref']) ? [$r['sugestao']] : []);
            foreach (is_array($lista) ? $lista : [] as $x) if (is_array($x) && isset($x['ref'])) $resp[strtoupper(trim((string)$x['ref']))] = $x;
        }
        $lotes = [];
        foreach ($sugs as $s) {
            if ((string)$s['STATUS'] !== 'AGUARDANDO') continue;
            $lotes[(int)$s['CODLOTE']] = true;
            if (!$ok) { self::chamar($pdo, 'CALL sp_ml_risco_sugestao_resultado(?, ?, NULL, NULL, NULL, NULL, ?)', [(int)$s['CODSUGESTAO'], 'ERRO', mb_substr((string)($job['erro'] ?? 'Falha na IA.'), 0, 500)]); continue; }
            $x = $resp[strtoupper((string)$s['REF_PEDIDO'])] ?? null;
            if ($x === null && count($sugs) === 1 && count($resp) === 1) $x = reset($resp);
            if ($x === null) { self::chamar($pdo, 'CALL sp_ml_risco_sugestao_resultado(?, ?, NULL, NULL, NULL, NULL, ?)', [(int)$s['CODSUGESTAO'], 'SEM_RESPOSTA', 'A IA não respondeu sobre este item.']); continue; }
            $sev = self::nivel($x['severidade'] ?? $x['severity'] ?? null);
            $prob = self::nivel($x['probabilidade'] ?? $x['probability'] ?? null);
            $just = trim((string)($x['justificativa'] ?? $x['justification'] ?? ''));
            $conf = isset($x['confianca']) || isset($x['confidence']) ? max(0, min(100, (int)round((float)($x['confianca'] ?? $x['confidence'])))) : null;
            self::chamar($pdo, 'CALL sp_ml_risco_sugestao_resultado(?, ?, ?, ?, ?, ?, ?)', [(int)$s['CODSUGESTAO'], $sev && $prob ? 'PENDENTE' : 'SEM_RESPOSTA', $sev, $prob,
                mb_substr($just, 0, 2000), $conf, $sev && $prob ? null : 'A IA não informou severidade e probabilidade válidas (1 a 4).']);
        }
        foreach (array_keys($lotes) as $l) self::chamar($pdo, 'CALL sp_ml_risco_lote_verificar(?, ?)', [$l, self::TELA . '?lote=' . $l]);
    }

    private static function nivel(mixed $v): ?int
    {
        if (is_string($v) && preg_match('/[1-4]/', $v, $m)) $v = $m[0];
        $n = is_numeric($v) ? (int)round((float)$v) : 0;
        return $n >= 1 && $n <= 4 ? $n : null;
    }

    // ------------------------------------------------------------------ decisão

    /** ACEITAR | AJUSTAR | RECUSAR, com a permissão do cadastro do item. */
    public static function decidir(PDO $pdo, int $uid, int $codsugestao, string $decisao, ?int $sev, ?int $prob, string $motivo = ''): array
    {
        $s = self::ler($pdo, 'vw_ml_risco_sugestoes', ['CODSUGESTAO' => self::exato($codsugestao)], 1)[0] ?? null;
        if (!$s) throw new RuntimeException('Sugestão não encontrada.');
        if (!self::podeDecidir($pdo, (string)$s['AREA'], (int)$s['ITEM_ID'], $uid))
            throw new RuntimeException('Seu perfil não pode alterar o cadastro deste item (' . RequisitoRisco::AREAS[(string)$s['AREA']][0] . '): peça a quem pode editar.');
        return self::chamar($pdo, 'CALL sp_ml_risco_sugestao_decidir(?, ?, ?, ?, ?, ?)', [$codsugestao, strtoupper($decisao), $sev, $prob, mb_substr(trim($motivo), 0, 1000), $uid])[0] ?? [];
    }

    /** Origem do risco gravado (MANUAL, IA, IA_AJUSTADA) por chave do item. */
    public static function origens(PDO $pdo, string $area, array $chaves): array
    {
        $chaves = array_values(array_unique(array_filter(array_map('intval', $chaves))));
        if (!$chaves || !self::disponivel($pdo)) return [];
        $s = $pdo->prepare('SELECT ITEM_ID, ORIGEM FROM vw_ml_risco_itens_origem WHERE AREA = ? AND ITEM_ID IN (' . implode(',', array_fill(0, count($chaves), '?')) . ')');
        $s->execute([$area, ...$chaves]);
        $o = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $o[(int)$r['ITEM_ID']] = (string)$r['ORIGEM'];
        $s->closeCursor();
        return $o;
    }

    // ------------------------------------------------------------------ auxiliares

    /** Token das telas (sugestões e botão do cadastro) para api/risco_sugestoes.php. */
    public static function csrf(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION['ml_risco_csrf'])) $_SESSION['ml_risco_csrf'] = bin2hex(random_bytes(16));
        return (string)$_SESSION['ml_risco_csrf'];
    }

    public static function csrfValido(?string $t): bool
    {
        return is_string($t) && $t !== '' && !empty($_SESSION['ml_risco_csrf']) && hash_equals((string)$_SESSION['ml_risco_csrf'], $t);
    }

    public static function exato(mixed $v): array { return ['value' => (string)$v, 'exact' => true]; }

    public static function ler(PDO $pdo, string $view, array $filtros = [], int $limite = 1000): array
    {
        $linhas = [];
        for ($off = 0; count($linhas) < $limite; $off += 1000) {
            $s = $pdo->prepare('CALL sp_crud_select(?, ?, NULL, ?, ?)');
            $s->execute([$view, $filtros ? json_encode($filtros, JSON_UNESCAPED_UNICODE) : null, min(1000, $limite - count($linhas)), $off]);
            $lote = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) {} $s->closeCursor();
            array_push($linhas, ...$lote);
            if (count($lote) < 1000) break;
        }
        return $linhas;
    }

    public static function chamar(PDO $pdo, string $sql, array $p): array
    {
        $s = $pdo->prepare($sql); $s->execute($p);
        $r = [];
        do { try { $x = $s->fetchAll(PDO::FETCH_ASSOC); if ($x) $r = $x; } catch (Throwable) {} } while ($s->nextRowset()); // o último resultado (as procedures que chamam outras devolvem vários)
        $s->closeCursor();
        return $r;
    }

    private static function corta(string $v, int $n): string { $v = trim($v); return mb_strlen($v) > $n ? mb_substr($v, 0, $n - 1) . '…' : $v; }
}
