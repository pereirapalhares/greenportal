<?php
declare(strict_types=1);

require_once __DIR__ . '/PaginaSimples.php';

use PaginaSimples as P;

/**
 * Módulo de Auditoria (migração 423, Revisão G itens 166 a 177 — 27/11/2026).
 * Fluxo do Green 3 (auditoria_planejamento.status): P em planejamento → A em avaliação → R recusada (volta ao planejamento) ou
 * X em execução → E encerrada | C cancelada. Cada passo grava auditoria_workflow (histórico) como no legado.
 * Novo no greenportal: aud_info (unidade, tipo, norma, escopo), aud_checklist (perguntas, IA a partir da norma), aud_constatacao
 * (constatações; cada NC abre uma RNC com CODAUDITORIA). A auditoria só encerra com a execução registrada, toda NC com RNC e as
 * RNCs finalizadas (follow-up).
 * Leitura por sp_crud_select / views; gravação por sp_crud_insert / sp_crud_update / sp_crud_delete e sp_alerta_criar.
 */
final class Auditoria
{
    public const STATUS = [
        'P' => [['Em Planejamento', 'Planning', 'En Planificación'], 'bi-pencil-square', 'secondary'],
        'A' => [['Em Avaliação', 'Under review', 'En Evaluación'], 'bi-hourglass-split', 'warning'],
        'R' => [['Recusada', 'Rejected', 'Rechazada'], 'bi-arrow-counterclockwise', 'danger'],
        'X' => [['Em Execução', 'In progress', 'En Ejecución'], 'bi-play-circle', 'primary'],
        'E' => [['Encerrada', 'Closed', 'Cerrada'], 'bi-check-circle', 'success'],
        'C' => [['Cancelada', 'Cancelled', 'Cancelada'], 'bi-x-circle', 'dark'],
    ];
    public const TIPOS = [
        'INTERNA' => ['Interna', 'Internal', 'Interna'], 'EXTERNA' => ['Externa (certificação)', 'External (certification)', 'Externa (certificación)'],
        'FORNECEDOR' => ['De fornecedor', 'Supplier', 'De proveedor'], 'CLIENTE' => ['De cliente', 'Customer', 'De cliente'],
    ];
    public const CLASSIFICACAO = [
        'NC_MAIOR' => [['Não conformidade maior', 'Major nonconformity', 'No conformidad mayor'], 'danger'],
        'NC_MENOR' => [['Não conformidade menor', 'Minor nonconformity', 'No conformidad menor'], 'warning'],
        'OBSERVACAO' => [['Observação', 'Observation', 'Observación'], 'info'],
        'MELHORIA' => [['Oportunidade de melhoria', 'Improvement opportunity', 'Oportunidad de mejora'], 'primary'],
    ];
    public const RESPOSTAS = ['CONFORME' => ['Conforme', 'Conforming', 'Conforme'], 'NC_MAIOR' => ['NC maior', 'Major NC', 'NC mayor'], 'NC_MENOR' => ['NC menor', 'Minor NC', 'NC menor'],
        'OBSERVACAO' => ['Observação', 'Observation', 'Observación'], 'MELHORIA' => ['Melhoria', 'Improvement', 'Mejora'], 'NA' => ['Não se aplica', 'Not applicable', 'No aplica']];
    /** Etapas da barra (fluxo do estágio, item 174). */
    public const ETAPAS = [
        ['P', ['Planejamento', 'Planning', 'Planificación']], ['A', ['Aprovação do plano', 'Plan approval', 'Aprobación del plan']],
        ['X', ['Execução', 'Execution', 'Ejecución']], ['N', ['Constatações e RNC', 'Findings and NCRs', 'Hallazgos y RNC']], ['E', ['Encerramento', 'Closure', 'Cierre']],
    ];

    public static function rotulo(array $tri): string { return P::L($tri[0], $tri[1], $tri[2]); }
    public static function situacao(string $st): string { return self::rotulo(self::STATUS[$st][0] ?? [$st, $st, $st]); }

    public static function badge(string $st): string
    {
        [$r, $ic, $cor] = self::STATUS[$st] ?? [[$st, $st, $st], 'bi-question-circle', 'secondary'];
        return '<span class="badge text-bg-' . $cor . ' aud-st"><i class="bi ' . $ic . ' me-1"></i>' . P::e(self::rotulo($r)) . '</span>';
    }

    public static function auditoria(int $cod): ?array
    {
        return $cod > 0 ? (P::sel('vw_aud_auditorias_grid', ['CODAUDITORIA' => $cod], 1)[0] ?? null) : null;
    }

    /** Grupos ativos do usuário (vínculos de grupo + grupo principal do cadastro). */
    public static function grupos(int $uid): array
    {
        static $cache = [];
        if (isset($cache[$uid])) return $cache[$uid];
        $g = [];
        if (P::existe('vw_responsabilidade_grupoxusuario') || P::existe('responsabilidade_grupoxusuario')) {
            try {
                $hoje = date('Y-m-d');
                foreach (P::sel(P::existe('vw_responsabilidade_grupoxusuario') ? 'vw_responsabilidade_grupoxusuario' : 'responsabilidade_grupoxusuario', ['CODUSUARIO' => $uid], 500) as $r) {
                    if (isset($r['ATIVO']) && (int)$r['ATIVO'] !== 1) continue;
                    if (!empty($r['VALIDO_ATE']) && substr((string)$r['VALIDO_ATE'], 0, 10) < $hoje) continue;
                    $g[(int)$r['CODGRUPO']] = true;
                }
            } catch (Throwable) {}
        }
        try { $u = P::sel('usuarios', ['codusuario' => $uid], 1)[0] ?? []; if (!empty($u['CODGRUPO'])) $g[(int)$u['CODGRUPO']] = true; } catch (Throwable) {}
        return $cache[$uid] = array_keys($g);
    }

    /** Pessoas de um papel: o usuário indicado e os membros ativos do grupo. */
    public static function pessoas(?int $usuario, ?int $grupo): array
    {
        $p = [];
        if ($usuario) $p[$usuario] = true;
        if ($grupo) {
            try {
                $hoje = date('Y-m-d');
                foreach (P::sel(P::existe('vw_responsabilidade_grupoxusuario') ? 'vw_responsabilidade_grupoxusuario' : 'responsabilidade_grupoxusuario', ['CODGRUPO' => $grupo], 2000) as $r) {
                    if (isset($r['ATIVO']) && (int)$r['ATIVO'] !== 1) continue;
                    if (!empty($r['VALIDO_ATE']) && substr((string)$r['VALIDO_ATE'], 0, 10) < $hoje) continue;
                    $p[(int)$r['CODUSUARIO']] = true;
                }
                foreach (P::sel('usuarios', ['codgrupo' => $grupo, 'ativo' => 1], 2000) as $u) $p[(int)$u['CODUSUARIO']] = true;
            } catch (Throwable) {}
        }
        unset($p[0]);
        return array_keys($p);
    }

    /** Papéis do usuário na auditoria (o ADMINISTRADOR tem todos). */
    public static function papeis(array $a, int $uid): array
    {
        $adm = P::admin();
        $gr = self::grupos($uid);
        $em = static fn($g) => $g && in_array((int)$g, $gr, true);
        return [
            'criador' => $adm || (int)($a['CODUSUARIO_CRIADOR'] ?? 0) === $uid,
            'avaliador' => $adm || (int)($a['CODUSUARIO_AVALIADOR'] ?? 0) === $uid || $em($a['CODGRUPO_AVALIADORES'] ?? 0),
            'auditor' => $adm || (int)($a['CODUSUARIO_AUDITOR'] ?? 0) === $uid || $em($a['CODGRUPO_AUDITORES'] ?? 0),
        ];
    }

    /** O usuário participa da auditoria (Minhas Auditorias)? */
    public static function participa(array $a, int $uid): bool
    {
        $gr = self::grupos($uid);
        return in_array($uid, [(int)$a['CODUSUARIO_CRIADOR'], (int)$a['CODUSUARIO_AVALIADOR'], (int)$a['CODUSUARIO_AUDITOR']], true)
            || in_array((int)($a['CODGRUPO_AVALIADORES'] ?? 0), $gr, true) || in_array((int)($a['CODGRUPO_AUDITORES'] ?? 0), $gr, true);
    }

    public static function registrar(int $cod, string $etapa, string $obs): void
    {
        P::ins('auditoria_workflow', ['CODAUDITORIA' => $cod, 'ETAPA' => $etapa, 'DSCOBSERVACAO' => mb_substr($obs, 0, 500), 'DATAOCORRENCIA' => date('Y-m-d H:i:s'), 'CODUSUARIO' => P::$uid ?: null]);
    }

    /** Alerta (sininho; o e-mail sai pelo serviço de alertas conforme a política do usuário). */
    public static function avisar(array $usuarios, string $titulo, string $mensagem, int $cod): int
    {
        $n = 0;
        foreach (array_unique(array_filter(array_map('intval', $usuarios))) as $u) {
            if ($u === P::$uid) continue;
            try { $s = P::$pdo->prepare('CALL sp_alerta_criar(?, ?, ?, ?, ?)'); $s->execute([$u, mb_substr($titulo, 0, 200), mb_substr($mensagem, 0, 2000), 'principal/auditoria_gestao.php?id=' . $cod, 'NAO']); $s->closeCursor(); $n++; }
            catch (Throwable) {}
        }
        return $n;
    }

    /** Agenda do auditor e do avaliador (item 173): período da auditoria enquanto está aberta. */
    public static function agenda(array $a): void
    {
        try {
            require_once __DIR__ . '/TaskService.php';
            require_once __DIR__ . '/AgendaService.php';
            $ag = new AgendaService(P::$pdo, new TaskService(P::$pdo));
            $cod = (int)$a['CODAUDITORIA'];
            $aberta = in_array((string)$a['STATUS'], ['P', 'A', 'X', 'R'], true) && !empty($a['DATADEINICIO']);
            foreach (['auditor' => (int)($a['CODUSUARIO_AUDITOR'] ?? 0), 'avaliador' => (int)($a['CODUSUARIO_AVALIADOR'] ?? 0)] as $papel => $u) {
                $chave = 'auditoria:' . $cod . ':' . $papel;
                if (!$aberta || !$u || ($papel === 'avaliador' && (string)$a['STATUS'] !== 'A')) { $ag->cancelSource($chave, true); continue; }
                $ag->syncSource($chave, [
                    'titulo' => ($papel === 'auditor' ? P::L('Auditoria', 'Audit', 'Auditoría') : P::L('Avaliar plano de auditoria', 'Review audit plan', 'Evaluar plan de auditoría')) . ' #' . $cod . ': ' . mb_substr((string)$a['DSCAUDITORIA'], 0, 120),
                    'descricao' => (string)($a['OBJETIVO'] ?? ''), 'localizacao' => (string)($a['LOCALIZACAO'] ?? ''),
                    'inicio' => substr((string)$a['DATADEINICIO'], 0, 10) . ' 08:00:00', 'fim' => substr((string)($a['DATADEFIM'] ?: $a['DATADEINICIO']), 0, 10) . ' 18:00:00',
                    'codusuario_responsavel' => $u, 'codusuario_criador' => P::$uid ?: $u, 'origem_transacao' => 'auditoria_gestao', 'origem_entidade' => 'auditoria_planejamento', 'origem_id' => $cod,
                ]);
            }
        } catch (Throwable) {}
    }

    /** O que impede o encerramento (vazio = pode encerrar). */
    public static function pendenciasEncerramento(int $cod): array
    {
        $f = [];
        $ex = P::sel('vw_aud_execucao', ['CODAUDITORIA' => $cod], 50);
        if (!$ex || !array_filter($ex, static fn($x) => trim((string)$x['OBSERVACAO']) !== '' && !empty($x['DATAEXECUCAO'])))
            $f[] = P::L('registre a execução (observação e data)', 'record the execution (notes and date)', 'registre la ejecución (observación y fecha)');
        $semRnc = array_filter(P::sel('aud_constatacao', ['CODAUDITORIA' => $cod], 500), static fn($c) => in_array((string)$c['CLASSIFICACAO'], ['NC_MAIOR', 'NC_MENOR'], true) && empty($c['CODOCORRENCIA']));
        if ($semRnc) $f[] = P::L(count($semRnc) . ' não conformidade(s) sem RNC', count($semRnc) . ' nonconformit(y/ies) without NCR', count($semRnc) . ' no conformidad(es) sin RNC');
        $abertas = array_filter(P::sel('vw_aud_rnc', ['CODAUDITORIA' => $cod], 500), static fn($r) => (string)$r['ETAPA'] !== 'Finalizado');
        if ($abertas) $f[] = P::L(count($abertas) . ' RNC ainda aberta(s) (follow-up)', count($abertas) . ' NCR(s) still open (follow-up)', count($abertas) . ' RNC aún abierta(s) (seguimiento)');
        return $f;
    }

    /** Chamada à IA (JSON) com o taxímetro registrando o consumo da auditoria. */
    public static function ia(string $texto, string $codigoPrompt, string $promptPadrao, int $cod): array
    {
        require_once __DIR__ . '/InteligenciaArtificialDocumento.php';
        require_once __DIR__ . '/IaTaximetro.php';
        $prompt = '';
        try { $s = P::$pdo->prepare('CALL sp_ia_prompt_obter(?, ?)'); $s->execute([$codigoPrompt, P::$idioma]); $prompt = trim((string)($s->fetch(PDO::FETCH_ASSOC)['prompt'] ?? '')); $s->closeCursor(); } catch (Throwable) {}
        try { IaTaximetro::contexto(P::$pdo, ['tipo' => $codigoPrompt, 'entidade' => 'auditoria_planejamento', 'registro' => $cod, 'origem' => 'TELA', 'modulo' => 'Auditoria', 'codusuario' => P::$uid]); } catch (Throwable) {}
        $r = InteligenciaArtificialDocumento::analisar($texto, $prompt !== '' ? $prompt : $promptPadrao, P::$idioma);
        return is_array($r['sugestao'] ?? null) ? $r['sugestao'] : $r;
    }

    /** Texto de um arquivo da pasta da auditoria no GED (PDF, Word ou imagem). */
    public static function textoArquivo(int $cod, string $arquivo): string
    {
        require_once __DIR__ . '/GedStorageService.php';
        require_once __DIR__ . '/LicenseDocumentExtractionService.php';
        $env = GreenPortalDatabase::env();
        $st = new GedStorageService(P::$pdo, $env, $_SESSION);
        $path = $st->resolve('auditoria', $cod, basename($arquivo));
        if ($path === null) throw new RuntimeException(P::L('Arquivo não encontrado na pasta da auditoria.', 'File not found in the audit folder.', 'Archivo no encontrado en la carpeta de la auditoría.'));
        if (preg_match('/\.(txt|csv|md)$/i', $path)) return (string)file_get_contents($path);
        return (string)((new LicenseDocumentExtractionService((string)($env['GGED_STORAGE_LOCAL_ROOT'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage')))->extract($path)['texto'] ?? '');
    }

    /** Arquivos da pasta da auditoria no GED. */
    public static function arquivos(int $cod): array
    {
        try {
            require_once __DIR__ . '/GedStorageService.php';
            $st = new GedStorageService(P::$pdo, GreenPortalDatabase::env(), $_SESSION);
            return array_column($st->list('auditoria', $cod, ''), 'name');
        } catch (Throwable) { return []; }
    }
}
