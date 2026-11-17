<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';

/**
 * Migração 365 — Gestão de pendências (GER-18.1 deletar, 18.2 não gerar, 18.3 limpar; decisões do usuário de 16/11/2026).
 *
 * As pendências são calculadas na hora (MinhasPendencias, views da barra lateral). Aqui ficam as regras que as escondem:
 *  - OCULTAR (18.1; em lote = LIMPAR, 18.3): uma pendência de uma pessoa some enquanto o prazo dela for o mesmo
 *    ("volta se a situação mudar": se a data mudar, a pendência nova aparece);
 *  - NAO_GERAR_REGISTRO (18.2): o registro (tela + código) não gera pendência para ninguém, até reativar;
 *  - NAO_GERAR_USUARIO (18.2): nada gera pendência para a pessoa, até a data informada (vazia = até reativar).
 * O registro de origem não muda (a licença continua vencida no módulo). Motivo obrigatório, log em pendencias_gestao_log
 * e alerta ao responsável quando quem fez é outra pessoa. Quem pode: ADMINISTRADOR e ADM DA EMPRESA (todos) e o gestor
 * (a equipe dele: usuarios.codusuario_superior, em todos os níveis — a mesma de Home › Minha equipe).
 * Leitura por sp_crud_select; gravação por sp_crud_insert / sp_crud_update.
 */
final class PendenciasGestao
{
    /** Tipo da barra lateral (vw_painel_prazos / vw_painel_calendario) => tela do registro (REG_TIPO). */
    public const TIPOS_BARRA = [
        'LICENCA' => 'manutencaolegal_licencas', 'MONITORAMENTO' => 'medicaoemonitoramento_mm_monitoramento',
        'TREINAMENTO' => 'modulolegal_treinamentos', 'INFRAESTRUTURA' => 'modulolegal_infraestrutura',
        'CONTROLE' => 'modulolegal_controle', 'AVALIACAO_FORNECEDOR' => 'homologacaofornecedor_fornecedor',
    ];

    private static array $cache = [];

    public static function disponivel(PDO $pdo): bool
    {
        $banco = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!array_key_exists($banco, self::$cache)) {
            $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vw_pendencias_gestao_ativas'");
            $s->execute(); $ok = (int)$s->fetchColumn() > 0; $s->closeCursor();
            self::$cache[$banco] = $ok ? null : false;
        }
        return self::$cache[$banco] !== false;
    }

    // ------------------------------------------------------------------ identificação da pendência
    /** Tela e código do registro, tirados do link da pendência (página + primeiro filtro). */
    public static function registroDeUrl(string $url): array
    {
        $partes = parse_url($url);
        $tipo = strtolower((string)preg_replace('/\.php$/i', '', basename((string)($partes['path'] ?? ''))));
        parse_str((string)($partes['query'] ?? ''), $q);
        $codigo = '';
        $f = json_decode((string)($q['filtros'] ?? ''), true);
        if (is_array($f) && $f) { $v = reset($f); $codigo = is_array($v) ? (string)($v['value'] ?? '') : (string)$v; }
        elseif (isset($q['codigo'])) $codigo = (string)$q['codigo'];
        return [$tipo, mb_substr(trim($codigo), 0, 64)];
    }

    public static function chave(array $item): string
    {
        return sha1((string)($item['url'] ?? '') . '|' . (string)($item['titulo'] ?? ''));
    }

    public static function assinatura(mixed $prazo): string
    {
        if ($prazo instanceof DateTimeInterface) return $prazo->format('Y-m-d');
        $p = substr(trim((string)$prazo), 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $p) && $p > '1900-01-01' ? $p : '';
    }

    // ------------------------------------------------------------------ regras em vigor
    /** Índices das regras em vigor (uma leitura por requisição e banco). */
    public static function regras(PDO $pdo): array
    {
        $banco = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!self::disponivel($pdo)) return ['ocultar' => [], 'registro' => [], 'usuario' => []];
        if (is_array(self::$cache[$banco] ?? null)) return self::$cache[$banco];
        $idx = ['ocultar' => [], 'registro' => [], 'usuario' => []];
        foreach (self::ler($pdo, 'vw_pendencias_gestao_ativas', []) as $r) {
            $dono = (int)($r['CODUSUARIO_DONO'] ?? 0);
            if ($r['TIPO'] === 'OCULTAR') $idx['ocultar'][$dono][(string)$r['CHAVE']][(string)$r['ASSINATURA']] = $r;
            elseif ($r['TIPO'] === 'NAO_GERAR_REGISTRO') $idx['registro'][strtolower((string)$r['REG_TIPO']) . '|' . (string)$r['REG_CODIGO']] = $r;
            elseif ($r['TIPO'] === 'NAO_GERAR_USUARIO') $idx['usuario'][$dono] = $r;
        }
        // ocultar também por registro + prazo (para a barra lateral, que não tem o título da pendência)
        $idx['ocultar_reg'] = [];
        foreach ($idx['ocultar'] as $dono => $porChave) foreach ($porChave as $porAss) foreach ($porAss as $r)
            if ((string)$r['REG_TIPO'] !== '') $idx['ocultar_reg'][$dono][strtolower((string)$r['REG_TIPO']) . '|' . (string)$r['REG_CODIGO'] . '|' . (string)$r['ASSINATURA']] = $r;
        return self::$cache[$banco] = $idx;
    }

    public static function limparCache(): void { self::$cache = []; }

    /** Regra que esconde a pendência (null = visível). $item: pilar, titulo, prazo, url. */
    public static function regraDo(PDO $pdo, array $item, int $dono): ?array
    {
        $idx = self::regras($pdo);
        if (isset($idx['usuario'][$dono])) return $idx['usuario'][$dono];
        [$tipo, $cod] = self::registroDeUrl((string)($item['url'] ?? ''));
        if ($tipo !== '' && $cod !== '' && isset($idx['registro'][$tipo . '|' . $cod])) return $idx['registro'][$tipo . '|' . $cod];
        return $idx['ocultar'][$dono][self::chave($item)][self::assinatura($item['prazo'] ?? null)] ?? null;
    }

    /** Tira as pendências escondidas (Minhas pendências, sino, Agenda). */
    public static function filtrar(PDO $pdo, array $itens, int $dono): array
    {
        if (!self::disponivel($pdo)) return $itens;
        return array_values(array_filter($itens, static fn(array $i): bool => self::regraDo($pdo, $i, $dono) === null));
    }

    /** Barra lateral (Próximos prazos e Calendário): linhas com TIPO, CODIGO, CODUSUARIO e PRAZO. */
    public static function filtrarBarra(PDO $pdo, array $linhas, int $dono = 0): array
    {
        if (!self::disponivel($pdo)) return $linhas;
        $idx = self::regras($pdo);
        return array_values(array_filter($linhas, static function (array $r) use ($idx, $dono): bool {
            $d = $dono ?: (int)($r['CODUSUARIO'] ?? 0);
            if (isset($idx['usuario'][$d])) return false;
            $tipo = self::TIPOS_BARRA[strtoupper((string)($r['TIPO'] ?? ''))] ?? '';
            if ($tipo === '') return true;
            $cod = (string)($r['CODIGO'] ?? '');
            if (isset($idx['registro'][$tipo . '|' . $cod])) return false;
            return !isset($idx['ocultar_reg'][$d][$tipo . '|' . $cod . '|' . self::assinatura($r['PRAZO'] ?? null)]);
        }));
    }

    // ------------------------------------------------------------------ quem pode
    public static function perfilAdm(): bool
    {
        $p = strtoupper(trim((string)($_SESSION['usuario']['codigo_perfil'] ?? '')));
        $p = trim((string)preg_replace('/[\s_-]+/', ' ', $p));
        return in_array($p, ['ADMINISTRADOR', 'ADM DA EMPRESA', 'ADM EMPRESA'], true);
    }

    /** Pessoas que o usuário pode gerir (codusuario => nome): ADM = todos os ativos; gestor = ele e a equipe (todos os níveis). */
    public static function escopo(PDO $pdo, int $autor): array
    {
        $usuarios = [];
        foreach (self::ler($pdo, 'vw_usuarios_grid', [], 20) as $u) {
            if (isset($u['ativo']) && (string)$u['ativo'] !== '' && (int)$u['ativo'] !== 1) continue;
            $usuarios[(int)$u['codusuario']] = ['nome' => trim((string)($u['nome'] ?? '')) ?: (string)($u['userid'] ?? ''), 'superior' => (int)($u['codusuario_superior'] ?? 0)];
        }
        $out = [];
        if (self::perfilAdm()) {
            foreach ($usuarios as $id => $u) $out[$id] = $u['nome'];
        } else {
            if (isset($usuarios[$autor])) $out[$autor] = $usuarios[$autor]['nome'];
            $fila = [$autor];
            while ($fila) {
                $chefe = array_shift($fila);
                foreach ($usuarios as $id => $u) if ($u['superior'] === $chefe && !isset($out[$id])) { $out[$id] = $u['nome']; $fila[] = $id; }
            }
        }
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    // ------------------------------------------------------------------ ações
    /**
     * Oculta pendências de uma pessoa (1 = OCULTAR; várias = LIMPAR em lote). $itens: pilar, titulo, prazo, url.
     * Devolve a quantidade ocultada.
     */
    public static function ocultar(PDO $pdo, int $autor, int $dono, array $itens, string $motivo): int
    {
        $motivo = self::motivo($motivo);
        $lote = count($itens) > 1 ? substr(sha1(uniqid('', true)), 0, 16) : null;
        $n = 0; $titulos = []; $ultimo = null;
        foreach ($itens as $it) {
            if (self::regraDo($pdo, $it, $dono) !== null) continue;   // já escondida
            [$tipo, $cod] = self::registroDeUrl((string)$it['url']);
            $ultimo = self::inserir($pdo, 'pendencias_gestao', ['TIPO' => 'OCULTAR', 'CHAVE' => self::chave($it), 'REG_TIPO' => $tipo, 'REG_CODIGO' => $cod,
                'CODUSUARIO_DONO' => $dono, 'ASSINATURA' => self::assinatura($it['prazo'] ?? null), 'PILAR' => mb_substr((string)$it['pilar'], 0, 80),
                'TITULO' => mb_substr((string)$it['titulo'], 0, 300), 'MOTIVO' => $motivo, 'LOTE' => $lote, 'ATIVO' => 1, 'CODUSUARIO_AUTOR' => $autor]);
            $titulos[] = $it['pilar'] . ' — ' . $it['titulo'];
            $n++;
        }
        if ($n === 0) return 0;
        self::limparCache();
        self::log($pdo, $lote ? 'LIMPAR' : 'OCULTAR', $lote ? null : $ultimo, $lote, $n, $dono, $autor, $motivo, implode("\n", $titulos));
        if ($dono !== $autor) self::avisar($pdo, $dono, $autor,
            $n === 1 ? 'Pendência ocultada: ' . mb_substr($titulos[0], 0, 120) : $n . ' pendências suas foram ocultadas',
            ($n === 1 ? 'Ocultada' : 'Ocultadas') . ' por ' . self::nome($pdo, $autor) . '. Motivo: ' . $motivo . "\n" . mb_substr(implode("\n", array_map(static fn($t) => '• ' . $t, $titulos)), 0, 1500)
            . "\nVoltam a aparecer se o prazo mudar.");
        return $n;
    }

    /** O registro da pendência deixa de gerar pendências para todos. */
    public static function naoGerarRegistro(PDO $pdo, int $autor, int $dono, array $item, string $motivo): bool
    {
        $motivo = self::motivo($motivo);
        [$tipo, $cod] = self::registroDeUrl((string)$item['url']);
        if ($tipo === '' || $cod === '') throw new RuntimeException('Esta pendência não tem um registro identificável; use Ocultar.');
        if (isset(self::regras($pdo)['registro'][$tipo . '|' . $cod])) return false;
        $id = self::inserir($pdo, 'pendencias_gestao', ['TIPO' => 'NAO_GERAR_REGISTRO', 'REG_TIPO' => $tipo, 'REG_CODIGO' => $cod, 'CODUSUARIO_DONO' => null,
            'PILAR' => mb_substr((string)$item['pilar'], 0, 80), 'TITULO' => mb_substr((string)$item['titulo'], 0, 300), 'MOTIVO' => $motivo, 'ATIVO' => 1, 'CODUSUARIO_AUTOR' => $autor]);
        self::limparCache();
        self::log($pdo, 'NAO_GERAR_REGISTRO', $id, null, 1, $dono, $autor, $motivo, $item['pilar'] . ' — ' . $item['titulo'] . " ($tipo $cod)");
        if ($dono !== $autor) self::avisar($pdo, $dono, $autor, 'Registro sem pendências: ' . mb_substr((string)$item['titulo'], 0, 120),
            self::nome($pdo, $autor) . ' desligou as pendências deste registro (' . $item['pilar'] . '). Motivo: ' . $motivo);
        return true;
    }

    /** Nada gera pendência para a pessoa até $ate (null = até reativar). */
    public static function naoGerarUsuario(PDO $pdo, int $autor, int $dono, ?string $ate, string $motivo): bool
    {
        $motivo = self::motivo($motivo);
        $ate = $ate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate) ? $ate : null;
        if ($ate !== null && $ate < substr(self::agora($pdo), 0, 10)) throw new RuntimeException('A data de volta já passou.');
        if (isset(self::regras($pdo)['usuario'][$dono])) return false;
        $id = self::inserir($pdo, 'pendencias_gestao', ['TIPO' => 'NAO_GERAR_USUARIO', 'CODUSUARIO_DONO' => $dono, 'ATE' => $ate, 'TITULO' => self::nome($pdo, $dono),
            'MOTIVO' => $motivo, 'ATIVO' => 1, 'CODUSUARIO_AUTOR' => $autor]);
        self::limparCache();
        self::log($pdo, 'NAO_GERAR_USUARIO', $id, null, 1, $dono, $autor, $motivo, 'Até ' . ($ate ? date('d/m/Y', strtotime($ate)) : 'reativar'));
        if ($dono !== $autor) self::avisar($pdo, $dono, $autor, 'Suas pendências foram desligadas' . ($ate ? ' até ' . date('d/m/Y', strtotime($ate)) : ''),
            self::nome($pdo, $autor) . ' desligou as suas pendências' . ($ate ? ' até ' . date('d/m/Y', strtotime($ate)) : '') . '. Motivo: ' . $motivo);
        return true;
    }

    /** Desfaz uma regra (volta a gerar / mostrar). */
    public static function reativar(PDO $pdo, int $autor, int $codgestao, string $motivo): array
    {
        $motivo = self::motivo($motivo);
        $r = self::ler($pdo, 'vw_pendencias_gestao_grid', ['CODGESTAO' => ['value' => (string)$codgestao, 'exact' => true]], 1)[0] ?? null;
        if (!$r || (int)$r['CODGESTAO'] !== $codgestao) throw new RuntimeException('Regra não encontrada.');
        if ((int)$r['ATIVO'] !== 1) throw new RuntimeException('Esta regra já foi reativada.');
        GreenPortalDatabase::call($pdo, 'CALL sp_crud_update(:t,:c,:d)', ['t' => 'pendencias_gestao', 'c' => json_encode(['CODGESTAO' => $codgestao]),
            'd' => json_encode(['ATIVO' => 0, 'CODUSUARIO_REATIVOU' => $autor, 'REATIVADO_EM' => self::agora($pdo)])]);
        self::limparCache();
        $dono = (int)($r['CODUSUARIO_DONO'] ?? 0);
        self::log($pdo, 'REATIVAR', $codgestao, null, 1, $dono ?: null, $autor, $motivo, $r['TIPO'] . ': ' . ($r['PILAR'] ? $r['PILAR'] . ' — ' : '') . $r['TITULO']);
        if ($dono > 0 && $dono !== $autor) self::avisar($pdo, $dono, $autor, $r['TIPO'] === 'NAO_GERAR_USUARIO' ? 'Suas pendências voltaram a ser geradas' : 'Pendência reativada: ' . mb_substr((string)$r['TITULO'], 0, 120),
            self::nome($pdo, $autor) . ' reativou ' . ($r['TIPO'] === 'NAO_GERAR_USUARIO' ? 'as suas pendências' : 'esta pendência') . '. Motivo: ' . $motivo);
        return $r;
    }

    // ------------------------------------------------------------------ apoio
    /** Data e hora do banco (o PHP do servidor está em UTC; CRIADO_EM usa o relógio do MySQL). */
    private static function agora(PDO $pdo): string
    {
        $v = (string)$pdo->query('SELECT NOW()')->fetchColumn();
        return $v !== '' ? $v : date('Y-m-d H:i:s');
    }

    private static function motivo(string $m): string
    {
        $m = trim((string)preg_replace('/\s+/u', ' ', $m));
        if (mb_strlen($m) < 5) throw new RuntimeException('Informe o motivo (pelo menos 5 letras).');
        return mb_substr($m, 0, 1000);
    }

    public static function ler(PDO $pdo, string $view, array $filtros, int $paginas = 10): array
    {
        $out = [];
        for ($p = 0; $p < $paginas; $p++) {
            $lote = GreenPortalDatabase::call($pdo, 'CALL sp_crud_select(:t,:f,NULL,1000,:o)', ['t' => $view, 'f' => json_encode($filtros ?: new stdClass()), 'o' => $p * 1000]);
            array_push($out, ...$lote);
            if (count($lote) < 1000) break;
        }
        return $out;
    }

    private static function inserir(PDO $pdo, string $tabela, array $dados): ?int
    {
        $r = GreenPortalDatabase::call($pdo, 'CALL sp_crud_insert(:t,:d)', ['t' => $tabela, 'd' => json_encode($dados, JSON_UNESCAPED_UNICODE)]);
        $id = (int)($r[0]['chave_gerada'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private static function log(PDO $pdo, string $acao, ?int $cod, ?string $lote, int $qtd, ?int $dono, int $autor, string $motivo, string $detalhe): void
    {
        self::inserir($pdo, 'pendencias_gestao_log', ['ACAO' => $acao, 'CODGESTAO' => $cod, 'LOTE' => $lote, 'QTD' => $qtd, 'CODUSUARIO_DONO' => $dono,
            'CODUSUARIO_AUTOR' => $autor, 'MOTIVO' => $motivo, 'DETALHE' => mb_substr($detalhe, 0, 4000)]);
    }

    private static function avisar(PDO $pdo, int $dono, int $autor, string $titulo, string $mensagem): void
    {
        if ($dono <= 0) return;
        try {
            self::inserir($pdo, 'alertas', ['codusuario' => $dono, 'titulo' => mb_substr($titulo, 0, 180), 'mensagem' => mb_substr($mensagem, 0, 2000),
                'link' => '../principal/home.php', 'lido' => 0]);
        } catch (Throwable $e) { error_log('PendenciasGestao aviso: ' . $e->getMessage()); }
    }

    private static array $nomes = [];
    public static function nome(PDO $pdo, int $id): string
    {
        if (!isset(self::$nomes[$id])) {
            $u = self::ler($pdo, 'vw_usuarios_grid', ['codusuario' => ['value' => (string)$id, 'exact' => true]], 1);
            $u = array_values(array_filter($u, static fn($x) => (int)$x['codusuario'] === $id))[0] ?? null;
            self::$nomes[$id] = $u ? (trim((string)($u['nome'] ?? '')) ?: (string)($u['userid'] ?? '#' . $id)) : '#' . $id;
        }
        return self::$nomes[$id];
    }
}
