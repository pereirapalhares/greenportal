<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';
require_once __DIR__ . '/IdiomaService.php';

/**
 * Migração 380 — Transferência de responsabilidades (GER-8; decisão do usuário de 16/11/2026: REDIRECIONAR).
 * Não altera os registros (até a virada o sincronismo com o legado desfaria): enquanto a transferência vale, o que é da
 * origem nos módulos escolhidos aparece e é cobrado do destino — Minhas pendências, sino, Agenda e pendências universais
 * (classes/MinhasPendencias.php e classes/PendenciasService.php) e os alertas/e-mails (gatilho trg_alertas_bi_transferencia).
 * A origem deixa de receber. Temporária (até uma data) ou definitiva (até ser encerrada). Motivo obrigatório, log e avisos.
 * Leitura só por views (sp_crud_select / sp_crud_count); gravação por sp_crud_insert / sp_crud_update.
 */
final class TransferenciaResponsabilidade
{
    /** Módulos que podem ser transferidos (código => rótulo). */
    public const MODULOS = [
        'LEGAL' => 'Requisitos legais', 'LICENCAS' => 'Licenças, condicionantes e taxas', 'MEDICAO' => 'Medição e monitoramento',
        'CONTROLES' => 'Controles', 'TREINAMENTOS' => 'Treinamentos', 'INFRAESTRUTURA' => 'Infraestrutura',
        'FORNECEDORES' => 'Homologação de fornecedores', 'NC' => 'Não conformidades', 'AGENDA' => 'Agenda e tarefas',
    ];

    private static array $cache = [];

    /** Módulo de uma tela/link (a mesma regra do gatilho dos alertas). '' = sem módulo. */
    public static function moduloDaUrl(string $url): string
    {
        $u = strtolower($url);
        foreach ([
            'NC' => '/naoconformidade/', 'FORNECEDORES' => '/homologacao|fornecedor|portaria|integracao/', 'LICENCAS' => '/licenca|condicionante|taxa/',
            'MEDICAO' => '/medicao|monitoramento/', 'TREINAMENTOS' => '/treinamento/', 'INFRAESTRUTURA' => '/infraestrutura/', 'CONTROLES' => '/controle/',
            'LEGAL' => '/requisito|modulolegal|situacao|legislacao/', 'AGENDA' => '/eventos|agenda|tarefa/',
        ] as $m => $re) if (preg_match($re, $u)) return $m;
        return '';
    }

    public static function disponivel(PDO $pdo): bool
    {
        $k = 'disp:' . spl_object_id($pdo);
        if (!array_key_exists($k, self::$cache)) {
            try { $pdo->query('SELECT 1 FROM `vw_transferencias_responsabilidade_ativas` LIMIT 1')->fetchAll(); self::$cache[$k] = true; }
            catch (Throwable) { self::$cache[$k] = false; }
        }
        return self::$cache[$k];
    }

    /** Transferências em vigor hoje (cache por requisição). */
    public static function ativas(PDO $pdo): array
    {
        $k = 'ativas:' . spl_object_id($pdo);
        if (!array_key_exists($k, self::$cache)) self::$cache[$k] = self::disponivel($pdo) ? self::ler($pdo, 'vw_transferencias_responsabilidade_ativas', []) : [];
        return self::$cache[$k];
    }

    public static function limparCache(): void { self::$cache = []; }

    private static function cobre(array $t, string $modulo): bool
    {
        $mods = strtoupper(trim((string)$t['MODULOS']));
        return $mods === 'TODOS' || ($modulo !== '' && in_array($modulo, explode(',', $mods), true));
    }

    /** Para quem foi transferido o que é de $uid no módulo (null = continua com ele). '' = só transferência de TODOS. */
    public static function destinoDe(PDO $pdo, int $uid, string $modulo): ?array
    {
        foreach (self::ativas($pdo) as $t) if ((int)$t['CODUSUARIO_ORIGEM'] === $uid && self::cobre($t, $modulo)) return $t;
        return null;
    }

    /** Transferências em vigor PARA $uid (de quem ele está respondendo). */
    public static function recebidas(PDO $pdo, int $uid): array
    {
        return array_values(array_filter(self::ativas($pdo), static fn($t) => (int)$t['CODUSUARIO_DESTINO'] === $uid));
    }

    public static function modulosDe(array $t): array
    {
        $m = strtoupper(trim((string)$t['MODULOS']));
        return $m === 'TODOS' ? array_keys(self::MODULOS) : array_values(array_intersect(explode(',', $m), array_keys(self::MODULOS)));
    }

    public static function rotuloModulos(string $mods): string
    {
        $m = strtoupper(trim($mods));
        if ($m === 'TODOS') return 'Todos os módulos';
        return implode(', ', array_map(static fn($c) => self::MODULOS[$c] ?? $c, array_filter(explode(',', $m))));
    }

    // ------------------------------------------------------------------ prévia (o que a pessoa tem em cada módulo)
    /**
     * Quantidades da origem por módulo, pelas mesmas views de Minhas atividades / Minhas pendências.
     * @return array<string, array{n:int, detalhe:string}>
     */
    public static function previa(PDO $pdo, int $uid): array
    {
        $eu = ['value' => (string)$uid, 'exact' => true];
        $c = static fn(string $v, array $f): int => (int)(self::contar($pdo, $v, $f) ?? 0);
        $out = [];
        $out['LEGAL'] = ['n' => $c('vw_modulolegal_requisitos_situacao_grid', ['LEI_REVOGADA' => 'NAO', 'RESPONSAVEL_IDS' => ',' . $uid . ',']), 'detalhe' => 'requisitos legais pelos quais responde'];
        $lic = $c('vw_minhas_licencas_grid', ['CODUSUARIO' => $eu]); $tx = $c('vw_minhas_taxas', ['CODUSUARIO' => $eu]);
        $out['LICENCAS'] = ['n' => $lic + $tx, 'detalhe' => $lic . ' licença(s), ' . $tx . ' taxa(s)'];
        $out['MEDICAO'] = ['n' => $c('vw_minhas_monitoramentos_grid', ['CODUSUARIO' => $eu]), 'detalhe' => 'monitoramentos'];
        $out['CONTROLES'] = ['n' => $c('vw_meus_controles_grid', ['CODUSUARIO' => $eu]), 'detalhe' => 'controles'];
        $out['TREINAMENTOS'] = ['n' => $c('vw_minhas_treinamentos_grid', ['CODUSUARIO' => $eu]), 'detalhe' => 'treinamentos'];
        $out['INFRAESTRUTURA'] = ['n' => $c('vw_minhas_infraestrutura_grid', ['CODUSUARIO' => $eu]), 'detalhe' => 'infraestruturas'];
        $out['FORNECEDORES'] = ['n' => $c('vw_meus_fornecedores_grid', ['CODUSUARIO_ACESSO' => $eu]), 'detalhe' => 'fornecedores pelos quais responde'];
        $out['NC'] = ['n' => $c('vminhasocorrenciasnc', ['CODUSUARIO_ENVOLVIDO' => $eu, 'ETAPA' => ['value' => 'Finalizado', 'operator' => 'not', 'exact' => true]]), 'detalhe' => 'não conformidades em andamento em que está envolvido'];
        $ev = $c('vw_agenda_eventos_usuario', ['CODUSUARIO' => $eu]); $ta = $c('vw_agenda_tarefas_usuario', ['CODUSUARIO' => $eu]);
        $out['AGENDA'] = ['n' => $ev + $ta, 'detalhe' => $ev . ' evento(s), ' . $ta . ' tarefa(s)'];
        return $out;
    }

    // ------------------------------------------------------------------ ações
    /** Cria a transferência; devolve o código. $modulos: ['TODOS'] ou lista de códigos de MODULOS. */
    public static function transferir(PDO $pdo, int $autor, int $origem, int $destino, array $modulos, string $tipo, string $inicio, ?string $fim, string $motivo, array $previa): int
    {
        $motivo = self::motivo($motivo);
        if ($origem <= 0 || $destino <= 0) throw new RuntimeException('Escolha quem passa as responsabilidades e quem assume.');
        if ($origem === $destino) throw new RuntimeException('Quem assume precisa ser outra pessoa.');
        $tipo = $tipo === 'DEFINITIVA' ? 'DEFINITIVA' : 'TEMPORARIA';
        $mods = in_array('TODOS', $modulos, true) ? 'TODOS' : implode(',', array_values(array_intersect(array_keys(self::MODULOS), $modulos)));
        if ($mods === '') throw new RuntimeException('Escolha ao menos um módulo.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio)) throw new RuntimeException('Informe a data de início.');
        if ($tipo === 'TEMPORARIA') {
            if (!$fim || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim)) throw new RuntimeException('A transferência temporária precisa da data de volta (último dia).');
            if ($fim < $inicio) throw new RuntimeException('A data de volta não pode ser antes do início.');
        } else $fim = null;
        if (self::ler($pdo, 'vw_transferencias_responsabilidade_ativas', ['CODUSUARIO_ORIGEM' => ['value' => (string)$destino, 'exact' => true]])) {
            foreach (self::ler($pdo, 'vw_transferencias_responsabilidade_ativas', ['CODUSUARIO_ORIGEM' => ['value' => (string)$destino, 'exact' => true]]) as $t)
                if (array_intersect(self::modulosDe($t), $mods === 'TODOS' ? array_keys(self::MODULOS) : explode(',', $mods)))
                    throw new RuntimeException('Quem vai assumir está com responsabilidades transferidas para ' . $t['DESTINO'] . ' nesses módulos. Escolha outra pessoa ou encerre aquela transferência antes.');
        }
        foreach (self::ler($pdo, 'vw_transferencias_responsabilidade_grid', ['CODUSUARIO_ORIGEM' => ['value' => (string)$origem, 'exact' => true], 'SITUACAO' => ['value' => 'ATIVA', 'exact' => true]]) as $t) {
            if ($t['ESTADO'] === 'VENCIDA') continue;
            $sobrepoe = $t['INICIO'] <= ($fim ?? '9999-12-31') && ($t['FIM'] === null || $t['FIM'] >= $inicio);
            if ($sobrepoe && array_intersect(self::modulosDe($t), $mods === 'TODOS' ? array_keys(self::MODULOS) : explode(',', $mods)))
                throw new RuntimeException('Já existe transferência de ' . $t['ORIGEM'] . ' para ' . $t['DESTINO'] . ' nesses módulos e nesse período (nº ' . $t['CODTRANSFERENCIA'] . '). Encerre-a antes.');
        }
        $cod = self::inserir($pdo, 'transferencias_responsabilidade', [
            'CODUSUARIO_ORIGEM' => $origem, 'CODUSUARIO_DESTINO' => $destino, 'MODULOS' => $mods, 'TIPO' => $tipo, 'INICIO' => $inicio, 'FIM' => $fim,
            'MOTIVO' => $motivo, 'SITUACAO' => 'ATIVA', 'CODUSUARIO_AUTOR' => $autor, 'CRIADO_EM' => self::agora($pdo),
            'PREVIA' => json_encode($previa, JSON_UNESCAPED_UNICODE),
        ]);
        if (!$cod) throw new RuntimeException('Não foi possível gravar a transferência.');
        $nO = self::nome($pdo, $origem); $nD = self::nome($pdo, $destino);
        $periodo = $tipo === 'TEMPORARIA' ? 'de ' . self::br($inicio) . ' até ' . self::br((string)$fim) : 'a partir de ' . self::br($inicio) . ' (definitiva)';
        $resumo = self::rotuloModulos($mods) . ', ' . $periodo;
        self::log($pdo, 'TRANSFERIR', $cod, $origem, $destino, $autor, $motivo, $resumo);
        $link = '../principal/home.php';
        self::avisar($pdo, $destino, 'Responsabilidades de ' . $nO . ' transferidas para você',
            'Você passa a responder pelo que é de ' . $nO . ' (' . $resumo . '). As pendências e os alertas dessa pessoa nesses módulos vão aparecer para você. Motivo: ' . $motivo, $link);
        if ($origem !== $autor) self::avisar($pdo, $origem, 'Suas responsabilidades foram transferidas para ' . $nD,
            $nD . ' passa a responder pelo que é seu (' . $resumo . '). Nesse período as pendências e os alertas desses módulos vão para essa pessoa. Motivo: ' . $motivo, $link);
        $gestor = self::superior($pdo, $origem);
        if ($gestor > 0 && !in_array($gestor, [$autor, $destino, $origem], true)) self::avisar($pdo, $gestor, 'Transferência de responsabilidades: ' . $nO . ' → ' . $nD,
            'As responsabilidades de ' . $nO . ' passaram para ' . $nD . ' (' . $resumo . '). Motivo: ' . $motivo, $link);
        self::limparCache();
        return $cod;
    }

    /** Encerra (as responsabilidades voltam para a origem na hora). */
    public static function encerrar(PDO $pdo, int $autor, int $cod, string $motivo): array
    {
        $motivo = self::motivo($motivo);
        $t = self::ler($pdo, 'vw_transferencias_responsabilidade_grid', ['CODTRANSFERENCIA' => ['value' => (string)$cod, 'exact' => true]], 1)[0] ?? null;
        if (!$t || $t['SITUACAO'] !== 'ATIVA') throw new RuntimeException('Esta transferência não está ativa.');
        self::executar($pdo, 'CALL sp_crud_update(:t, :k, :d)', ['t' => 'transferencias_responsabilidade', 'k' => json_encode(['CODTRANSFERENCIA' => $cod]),
            'd' => json_encode(['SITUACAO' => 'ENCERRADA', 'CODUSUARIO_ENCERROU' => $autor, 'ENCERRADO_EM' => self::agora($pdo), 'MOTIVO_ENCERRAMENTO' => $motivo], JSON_UNESCAPED_UNICODE)]);
        self::log($pdo, 'ENCERRAR', $cod, (int)$t['CODUSUARIO_ORIGEM'], (int)$t['CODUSUARIO_DESTINO'], $autor, $motivo, self::rotuloModulos((string)$t['MODULOS']));
        $link = '../principal/home.php';
        foreach ([(int)$t['CODUSUARIO_ORIGEM'] => 'Suas responsabilidades voltaram para você', (int)$t['CODUSUARIO_DESTINO'] => 'Transferência encerrada: as responsabilidades de ' . $t['ORIGEM'] . ' voltaram para essa pessoa'] as $u => $tit)
            if ($u !== $autor) self::avisar($pdo, $u, $tit, 'Transferência nº ' . $cod . ' (' . $t['ORIGEM'] . ' → ' . $t['DESTINO'] . ', ' . self::rotuloModulos((string)$t['MODULOS']) . ') encerrada. Motivo: ' . $motivo, $link);
        self::limparCache();
        return $t;
    }

    // ------------------------------------------------------------------ auxiliares
    public static function ler(PDO $pdo, string $view, array $filtros, int $paginas = 10): array
    {
        $out = [];
        for ($p = 0; $p < $paginas; $p++) {
            $s = $pdo->prepare('CALL sp_crud_select(:t, :f, NULL, 1000, :o)');
            $s->execute(['t' => $view, 'f' => json_encode((object)$filtros, JSON_UNESCAPED_UNICODE), 'o' => $p * 1000]);
            $l = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
            array_push($out, ...$l);
            if (count($l) < 1000) break;
        }
        return $out;
    }

    private static function contar(PDO $pdo, string $view, array $filtros): ?int
    {
        try {
            $s = $pdo->prepare('CALL sp_crud_count(:t, :f, :b)');
            $s->execute(['t' => $view, 'f' => json_encode((object)$filtros, JSON_UNESCAPED_UNICODE), 'b' => '']);
            $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
            return isset($r[0]['total_registros']) ? (int)$r[0]['total_registros'] : null;
        } catch (Throwable) { return null; }
    }

    private static function executar(PDO $pdo, string $sql, array $p): array
    {
        $s = $pdo->prepare($sql); $s->execute($p);
        $r = []; try { $r = $s->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable) {}
        $s->closeCursor();
        return $r;
    }

    private static function inserir(PDO $pdo, string $tabela, array $dados): ?int
    {
        $r = self::executar($pdo, 'CALL sp_crud_insert(:t, :d)', ['t' => $tabela, 'd' => json_encode($dados, JSON_UNESCAPED_UNICODE)]);
        $id = (int)($r[0]['chave_gerada'] ?? (array_values($r[0] ?? [])[0] ?? 0));
        return $id > 0 ? $id : null;
    }

    private static function log(PDO $pdo, string $acao, ?int $cod, int $origem, int $destino, int $autor, string $motivo, string $detalhe): void
    {
        self::inserir($pdo, 'transferencias_responsabilidade_log', ['ACAO' => $acao, 'CODTRANSFERENCIA' => $cod, 'CODUSUARIO_ORIGEM' => $origem,
            'CODUSUARIO_DESTINO' => $destino, 'CODUSUARIO_AUTOR' => $autor, 'MOTIVO' => $motivo, 'DETALHE' => mb_substr($detalhe, 0, 4000), 'CRIADO_EM' => self::agora($pdo)]);
    }

    /** Alerta direto para a pessoa (sem o redirecionamento do gatilho: o aviso da transferência é para ela mesma). */
    private static function avisar(PDO $pdo, int $usuario, string $titulo, string $mensagem, string $link): void
    {
        try {
            $pdo->exec('SET @gp_sem_transferencia = 1');
            self::inserir($pdo, 'alertas', ['codusuario' => $usuario, 'titulo' => mb_substr($titulo, 0, 180), 'mensagem' => mb_substr($mensagem, 0, 2000), 'link' => $link, 'lido' => 0]);
        } catch (Throwable $e) { error_log('TransferenciaResponsabilidade::avisar: ' . $e->getMessage()); }
        finally { try { $pdo->exec('SET @gp_sem_transferencia = 0'); } catch (Throwable) {} }
    }

    public static function nome(PDO $pdo, int $id): string
    {
        $k = 'nome:' . $id;
        if (!isset(self::$cache[$k])) {
            $u = self::ler($pdo, 'vw_usuarios_grid', ['codusuario' => ['value' => (string)$id, 'exact' => true]], 1)[0] ?? null;
            self::$cache[$k] = $u ? (trim((string)($u['nome'] ?? '')) ?: (string)($u['userid'] ?? '#' . $id)) : '#' . $id;
        }
        return self::$cache[$k];
    }

    private static function superior(PDO $pdo, int $id): int
    {
        $u = self::ler($pdo, 'vw_usuarios_grid', ['codusuario' => ['value' => (string)$id, 'exact' => true]], 1)[0] ?? null;
        return (int)($u['codusuario_superior'] ?? 0);
    }

    private static function motivo(string $m): string
    {
        $m = trim(preg_replace('/\s+/', ' ', $m) ?? '');
        if (mb_strlen($m) < 5) throw new RuntimeException('Escreva o motivo (pelo menos 5 letras).');
        return mb_substr($m, 0, 1000);
    }

    /** Relógio do banco (o PHP do servidor está em UTC). */
    private static function agora(PDO $pdo): string { return (string)$pdo->query('SELECT NOW()')->fetchColumn(); }

    public static function br(?string $d): string { return $d ? date('d/m/Y', strtotime($d)) : ''; }
}
