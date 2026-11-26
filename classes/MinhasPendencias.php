<?php
declare(strict_types=1);

require_once __DIR__ . '/IdiomaService.php';
require_once __DIR__ . '/GreenPortalDatabase.php';
require_once __DIR__ . '/PermissaoTransacao.php';

/**
 * Pendências do usuário nos pilares (responsável direto ou por grupo), com o motivo de cada uma.
 * Usada pela Home › Minhas pendências (home_pendencias.php) e pela Home › Agenda (eventos.php?modo=agenda).
 * Leitura só por views com sp_crud_select (migrações 210, 296, 310, 311 e 312); o filtro do usuário é exato.
 * Cada item: pilar, titulo, motivo, prazo (?DateTimeImmutable), url (tela de origem, relativa a principal/).
 */
final class MinhasPendencias
{
    /** Views novas: se faltarem, a tela avisa qual migração rodar. */
    public const VIEWS_MIGRACAO = [
        'vw_homologacao_pendencias_fornecedor_responsavel' => '311', 'vw_homologacao_pendencias_planoacao_interno' => '311',
        'vw_homologacao_pendencias_treinamento_interno' => '312', 'vw_homologacao_pendencias_funcionario_externo' => '312',
        'vw_homologacao_responsabilidade_fornecedor' => '312', 'vw_agenda_eventos_usuario' => '312', 'vw_agenda_tarefas_usuario' => '312',
        'vw_minhas_taxas' => '344',
    ];

    /** c572: leitura em lote (Gestão de pendências) — view => [codusuario => linhas]; null = leitura normal, por usuário. */
    private static ?array $lote = null;
    /** c572: tempo (s) e linhas de cada view lida em lote (diagnóstico). */
    public static array $tempos = [];
    /** c573: textos e permissões já resolvidos durante o lote. */
    private static array $memo = [];

    /** c580 (GER-8): sem o redirecionamento das transferências (usado para montar as pendências de quem transferiu). */
    private bool $semTransferencia = false;

    private array $itens = [];
    private array $vistos = [];
    private array $faltando = [];
    private DateTimeImmutable $hoje;

    public function __construct(private PDO $pdo, private int $uid, private bool $fornecedorExterno = false)
    {
        $this->hoje = new DateTimeImmutable('today');
    }

    // ------------------------------------------------------------ auxiliares públicos (datas no padrão do portal)
    public static function data(mixed $valor): ?DateTimeImmutable
    {
        $valor = trim((string)$valor);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $valor, $m) || $valor <= '1900-01-01') return null;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? new DateTimeImmutable($m[1] . '-' . $m[2] . '-' . $m[3]) : null;
    }

    public static function formatar(?DateTimeImmutable $d, bool $comHora = false): string
    {
        if (!$d) return '';
        $f = IdiomaService::idioma() === 'en' ? 'm/d/Y' : 'd/m/Y';
        return $d->format($comHora ? $f . ' H:i' : $f);
    }

    /** vencido | a-vencer (até 30 dias) | no-prazo | '' (sem data) — mesma regra de cores do Painel. */
    public static function sinal(?DateTimeImmutable $d): string
    {
        if (!$d) return '';
        $n = (int)(new DateTimeImmutable('today'))->diff($d->setTime(0, 0))->format('%r%a');
        return $n < 0 ? 'vencido' : ($n <= 30 ? 'a-vencer' : 'no-prazo');
    }

    public function faltando(): array
    {
        return array_values(array_unique(array_filter($this->faltando, static fn(string $v): bool => isset(self::VIEWS_MIGRACAO[$v]))));
    }

    /**
     * c572 (16/11/2026): quantidade de pendências (todas, inclusive as ocultadas na Gestão de pendências) de cada usuário,
     * com a mesma regra de itens(); cada view é lida uma vez só para todos (sp_crud_select sem filtro, em páginas).
     * @return array<int,int> codusuario => quantidade (só quem tem pendência)
     */
    public static function contarPorUsuario(PDO $pdo, array $uids): array
    {
        self::$lote = []; self::$memo = [];
        $out = [];
        try {
            // Só monta para quem aparece em alguma view lida (os demais não têm pendência); a primeira montagem carrega as views
            $pedidos = array_fill_keys(array_map('intval', $uids), true); $feitos = [];
            $montar = static function (int $uid) use ($pdo, &$out, &$feitos): void {
                $feitos[$uid] = true;
                $n = count((new self($pdo, $uid))->itens(true));
                if ($n > 0) $out[$uid] = $n;
            };
            if ($pedidos) $montar((int)array_key_first($pedidos));
            do {
                $antes = count(self::$lote);
                $candidatos = [];
                foreach (self::$lote as $idx) if (is_array($idx)) foreach ($idx as $uid => $_) if (isset($pedidos[$uid]) && !isset($feitos[$uid])) $candidatos[$uid] = true;
                foreach (array_keys($candidatos) as $uid) $montar((int)$uid);
            } while (count(self::$lote) > $antes);
        } finally { self::$lote = null; self::$memo = []; }
        return $out;
    }

    /** Lote: lê a view inteira uma vez e indexa pelo usuário. */
    private function lerLote(string $view): ?array
    {
        if (!array_key_exists($view, self::$lote)) {
            $idx = []; $t0 = microtime(true); $nl = 0;
            try {
                // sp_crud_select devolve no máximo 1000 linhas por chamada: páginas de 1000 até vir menos
                for ($o = 0; ; $o += 1000) {
                    $rows = GreenPortalDatabase::call($this->pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,:o)', ['tabela' => $view, 'filtros' => '{}', 'o' => $o]);
                    foreach ($rows as $r) $idx[(int)($r['CODUSUARIO'] ?? 0)][] = $r;
                    $nl += count($rows);
                    if (count($rows) < 1000) break;
                }
            } catch (Throwable) { $idx = null; }
            self::$lote[$view] = $idx;
            self::$tempos[$view] = [round(microtime(true) - $t0, 2), $nl];
        }
        return self::$lote[$view];
    }

    /** sp_crud_select com o usuário em filtro exato; confere o usuário de novo na linha. */
    public function ler(string $view, int $limite = 1000): array
    {
        if (self::$lote !== null) {
            $idx = $this->lerLote($view);
            if ($idx === null) { $this->faltando[] = $view; return []; }
            return array_slice($idx[$this->uid] ?? [], 0, $limite);
        }
        try {
            $rows = GreenPortalDatabase::call($this->pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,:limite,0)', [
                'tabela' => $view, 'limite' => $limite,
                'filtros' => json_encode(['CODUSUARIO' => ['value' => (string)$this->uid, 'exact' => true]], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable) { $this->faltando[] = $view; return []; }
        return array_values(array_filter($rows, fn(array $r): bool => (int)($r['CODUSUARIO'] ?? 0) === $this->uid));
    }

    // ------------------------------------------------------------ montagem
    private function t(string $chave, array $padrao, array $valores = []): string
    {
        // c573: em lote o texto da chave é o mesmo para todos os usuários: guarda e reaproveita
        if (self::$lote !== null) return strtr(self::$memo['t'][$chave] ??= IdiomaService::t($chave, $padrao), $valores);
        return strtr(IdiomaService::t($chave, $padrao), $valores);
    }

    private function dias(DateTimeImmutable $d): int
    {
        return (int)$this->hoje->diff($d)->format('%r%a');
    }

    /** Motivo pela data: vencido, vence em até 30 dias, no prazo, sem data. */
    private function porPrazo(?DateTimeImmutable $d, string $chave, array $vencido, array $vencendo, array $noPrazo, array $semData): string
    {
        if (!$d) return $this->t('home.motivo.' . $chave . '.sem_data', $semData);
        $n = $this->dias($d);
        $v = ['{data}' => self::formatar($d), '{dias}' => (string)abs($n)];
        if ($n < 0) return $this->t('home.motivo.' . $chave . '.vencido', $vencido, $v);
        if ($n <= 30) return $this->t('home.motivo.' . $chave . '.vencendo', $vencendo, $v);
        return $this->t('home.motivo.' . $chave . '.no_prazo', $noPrazo, $v);
    }

    private function add(string $pilar, string $titulo, string $motivo, ?DateTimeImmutable $prazo, string $url): void
    {
        $chave = $url . '|' . $titulo;
        if (isset($this->vistos[$chave])) return;
        $this->vistos[$chave] = true;
        $this->itens[] = ['pilar' => $pilar, 'titulo' => $titulo, 'motivo' => $motivo, 'prazo' => $prazo, 'url' => $url];
    }

    private static function url(string $pagina, array $filtros, bool $minhas = false): string
    {
        return $pagina . '?' . ($minhas ? 'minhas_pendencias=1&' : '') . 'filtros=' . rawurlencode((string)json_encode($filtros, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function pode(string $transacao): bool
    {
        // c573: em lote a permissão é a de quem consulta (a mesma em todas as montagens): guarda e reaproveita
        if (self::$lote !== null) return self::$memo['pode'][$transacao] ??= !empty(PermissaoTransacao::obter($transacao)['pode_visualizar']);
        return !empty(PermissaoTransacao::obter($transacao)['pode_visualizar']);
    }

    /**
     * Lista ordenada: prazo mais antigo primeiro, sem prazo no fim.
     * Migração 365 (GER-18): sem $todas, tira as pendências ocultadas ou desligadas na Gestão de pendências
     * (classes/PendenciasGestao.php); com $todas = true (a própria Gestão de pendências), devolve todas.
     */
    public function itens(bool $todas = false): array
    {
        $this->itens = []; $this->vistos = [];
        $this->pilares();
        $this->homologacao();
        $this->eficacia();
        usort($this->itens, static fn(array $a, array $b): int => [$a['prazo'] === null, $a['prazo']?->format('Y-m-d') ?? '', $a['pilar'], $a['titulo']] <=> [$b['prazo'] === null, $b['prazo']?->format('Y-m-d') ?? '', $b['pilar'], $b['titulo']]);
        if (!$todas && is_file(__DIR__ . '/PendenciasGestao.php')) {
            require_once __DIR__ . '/PendenciasGestao.php';
            try { $this->itens = PendenciasGestao::filtrar($this->pdo, $this->itens, $this->uid); } catch (Throwable $e) { error_log('MinhasPendencias gestao: ' . $e->getMessage()); }
        }
        if (!$todas && !$this->semTransferencia && self::$lote === null) $this->transferencias();
        return $this->itens;
    }

    /**
     * Migração 380 (GER-8): transferência de responsabilidades por redirecionamento. Tira do usuário o que ele transferiu
     * (pelo módulo da tela de cada pendência) e junta o que foi transferido para ele, com "Responsabilidade de X" no motivo.
     */
    private function transferencias(): void
    {
        if (!is_file(__DIR__ . '/TransferenciaResponsabilidade.php')) return;
        require_once __DIR__ . '/TransferenciaResponsabilidade.php';
        try {
            if (!TransferenciaResponsabilidade::ativas($this->pdo)) return;
            $this->itens = array_values(array_filter($this->itens, fn(array $i): bool =>
                TransferenciaResponsabilidade::destinoDe($this->pdo, $this->uid, TransferenciaResponsabilidade::moduloDaUrl((string)$i['url'])) === null));
            foreach (TransferenciaResponsabilidade::recebidas($this->pdo, $this->uid) as $t) {
                $origem = new self($this->pdo, (int)$t['CODUSUARIO_ORIGEM'], false);
                $origem->semTransferencia = true;
                $mods = TransferenciaResponsabilidade::modulosDe($t); $todosMods = strtoupper((string)$t['MODULOS']) === 'TODOS';
                $prefixo = $this->t('home.motivo.transferida', ['pt' => 'Responsabilidade de {nome} (transferida para você)', 'en' => 'Responsibility of {nome} (transferred to you)', 'es' => 'Responsabilidad de {nome} (transferida a usted)'], ['{nome}' => (string)$t['ORIGEM']]);
                foreach ($origem->itens(false) as $i) {
                    $m = TransferenciaResponsabilidade::moduloDaUrl((string)$i['url']);
                    if (!$todosMods && !in_array($m, $mods, true)) continue;
                    $this->add($i['pilar'], $i['titulo'], $prefixo . ': ' . $i['motivo'], $i['prazo'], $i['url']);
                }
            }
            usort($this->itens, static fn(array $a, array $b): int => [$a['prazo'] === null, $a['prazo']?->format('Y-m-d') ?? '', $a['pilar'], $a['titulo']] <=> [$b['prazo'] === null, $b['prazo']?->format('Y-m-d') ?? '', $b['pilar'], $b['titulo']]);
        } catch (Throwable $e) { error_log('MinhasPendencias transferencias: ' . $e->getMessage()); }
    }

    private function pilares(): void
    {
        // Licenças
        if (self::pode('LICENCAS')) {
            $pilar = $this->t('home.pilar.licencas', ['pt' => 'Licenças', 'en' => 'Licenses', 'es' => 'Licencias']);
            foreach ($this->ler('vw_minhas_licencas_grid', 500) as $r) {
                // Migração 415: arquivada ou sem validade (não vence) não é pendência; recorrente usa o próximo ciclo
                $sit415 = strtoupper(trim((string)($r['SITUACAO_LICENCA'] ?? '')));
                if ($sit415 === 'ARQUIVADA' || $sit415 === 'NAO_VENCE') continue;
                $validade = self::data(($r['PROXIMO_VENCIMENTO'] ?? '') ?: ($r['DATA_VALIDADE'] ?? '')); $reacao = self::data($r['DATA_REACAO'] ?? '');
                if (!empty($r['PROXIMO_VENCIMENTO']) && substr((string)$r['PROXIMO_VENCIMENTO'], 0, 10) !== substr((string)($r['DATA_VALIDADE'] ?? ''), 0, 10)) $reacao = null;
                if (in_array(strtoupper(trim((string)($r['EMPROTOCOLO'] ?? ''))), ['S', 'SIM', '1'], true)) $motivo = $this->t('home.motivo.licenca.protocolo', ['pt' => 'Renovação em protocolo no órgão: acompanhar a emissão', 'en' => 'Renewal filed with the agency: follow up on issuance', 'es' => 'Renovación en protocolo: acompañar la emisión']);
                elseif ($validade && $this->dias($validade) < 0) $motivo = $this->t('home.motivo.licenca.vencida', ['pt' => 'Licença vencida em {data}', 'en' => 'License expired on {data}', 'es' => 'Licencia vencida el {data}'], ['{data}' => self::formatar($validade)]);
                elseif ($reacao && $this->dias($reacao) <= 0) $motivo = $this->t('home.motivo.licenca.reacao', ['pt' => 'Prazo para iniciar a renovação passou em {data}', 'en' => 'Deadline to start the renewal passed on {data}', 'es' => 'El plazo para iniciar la renovación venció el {data}'], ['{data}' => self::formatar($reacao)]);
                else $motivo = $this->porPrazo($validade, 'licenca', ['pt' => 'Licença vencida em {data}', 'en' => 'License expired on {data}', 'es' => 'Licencia vencida el {data}'],
                    ['pt' => 'Licença vence em {dias} dia(s): providenciar a renovação', 'en' => 'License expires in {dias} day(s): arrange the renewal', 'es' => 'La licencia vence en {dias} día(s): gestionar la renovación'],
                    ['pt' => 'Responsável pela licença (válida até {data})', 'en' => 'Responsible for the license (valid until {data})', 'es' => 'Responsable de la licencia (válida hasta {data})'],
                    ['pt' => 'Licença sem data de validade: informar a validade', 'en' => 'License without expiry date: enter the expiry date', 'es' => 'Licencia sin fecha de vencimiento: informar el vencimiento']);
                $this->add($pilar, (string)($r['DSCLICENCA'] ?? $pilar), $motivo, $validade, self::url('manutencaolegal_licencas.php', ['CODLICENCA' => (string)($r['CODLICENCA'] ?? '')], true));
            }
        }
        // Migração 344: taxas das licenças não pagas, vencidas ou vencendo em até 30 dias (responsáveis da licença)
        if (self::pode('LICENCAS_TAXAS') || self::pode('LICENCAS')) {
            $pilar = $this->t('home.pilar.licencas', ['pt' => 'Licenças', 'en' => 'Licenses', 'es' => 'Licencias']);
            foreach ($this->ler('vw_minhas_taxas', 500) as $r) {
                $venc = self::data($r['VENCIMENTO'] ?? '');
                $valor = trim((string)($r['VALOR'] ?? '')) !== '' ? 'R$ ' . $r['VALOR'] : $this->t('home.motivo.taxa.sem_valor', ['pt' => 'valor não informado', 'en' => 'amount not informed', 'es' => 'valor no informado']);
                $v = ['{valor}' => $valor];
                if ($venc && $this->dias($venc) === 0) $motivo = $this->t('home.motivo.taxa.hoje', ['pt' => 'Taxa ({valor}) vence hoje: pagar e registrar o pagamento', 'en' => 'Fee ({valor}) due today: pay it and record the payment', 'es' => 'La tasa ({valor}) vence hoy: pagar y registrar el pago']);
                else $motivo = $this->porPrazo($venc, 'taxa', ['pt' => 'Taxa ({valor}) vencida em {data} sem pagamento: pagar e registrar o pagamento', 'en' => 'Fee ({valor}) overdue since {data}: pay it and record the payment', 'es' => 'Tasa ({valor}) vencida el {data} sin pago: pagar y registrar el pago'],
                    ['pt' => 'Taxa ({valor}) vence em {dias} dia(s), em {data}: pagar e registrar o pagamento', 'en' => 'Fee ({valor}) due in {dias} day(s), on {data}: pay it and record the payment', 'es' => 'La tasa ({valor}) vence en {dias} día(s), el {data}: pagar y registrar el pago'],
                    ['pt' => 'Taxa ({valor}) com vencimento em {data}', 'en' => 'Fee ({valor}) due on {data}', 'es' => 'Tasa ({valor}) con vencimiento el {data}'],
                    ['pt' => 'Taxa sem vencimento', 'en' => 'Fee without due date', 'es' => 'Tasa sin vencimiento']);
                $titulo = $this->t('home.titulo.taxa', ['pt' => 'Taxa', 'en' => 'Fee', 'es' => 'Tasa']) . ' ' . trim((string)($r['TIPO_DESC'] ?? '')) . ' - ' . trim((string)($r['LICENCA'] ?? ''));
                $this->add($pilar, $titulo, strtr($motivo, $v), $venc, self::url('manutencaolegal_licencas_taxas.php', ['CODTAXA' => ['value' => (string)($r['CODTAXA'] ?? ''), 'exact' => true]]));
            }
        }
        // Medição e monitoramento
        if (self::pode('MM_PLANO_MEDICAO_MONITORAMENTO')) {
            $pilar = $this->t('home.pilar.medicao', ['pt' => 'Medição e Monitoramento', 'en' => 'Measurement and Monitoring', 'es' => 'Medición y Monitoreo']);
            foreach ($this->ler('vw_minhas_monitoramentos_grid', 500) as $r) {
                $reacao = self::data($r['DATA_REACAO'] ?? '');
                $motivo = $this->porPrazo($reacao, 'monitoramento', ['pt' => 'Monitoramento atrasado desde {data}', 'en' => 'Monitoring overdue since {data}', 'es' => 'Monitoreo atrasado desde {data}'],
                    ['pt' => 'Monitoramento previsto para {data}', 'en' => 'Monitoring due on {data}', 'es' => 'Monitoreo previsto para {data}'],
                    ['pt' => 'Responsável pelo monitoramento (próximo em {data})', 'en' => 'Responsible for the monitoring (next on {data})', 'es' => 'Responsable del monitoreo (próximo el {data})'],
                    ['pt' => 'Monitoramento sem data de reação: definir a data', 'en' => 'Monitoring without a reaction date: set the date', 'es' => 'Monitoreo sin fecha de reacción: definir la fecha']);
                $this->add($pilar, (string)($r['PARAMETROS'] ?? $pilar), $motivo, $reacao, self::url('medicaoemonitoramento_mm_monitoramento.php', ['CODMONITORAMENTO' => (string)($r['CODMONITORAMENTO'] ?? '')], true));
            }
        }
        // Migração 416 (Revisão G item 25): leis não analisadas (requisito sem avaliação manual) de que o usuário é responsável,
        // uma pendência por unidade, que abre os Requisitos legais filtrados em "Não avaliado" na unidade
        if (self::pode('REQUISITOS_LEGAIS')) {
            $porUnidade = [];
            foreach ($this->ler('vw_minhas_leis_nao_avaliadas', 1000) as $r) {
                $u = (string)($r['CODUNIDADE'] ?? '');
                $porUnidade[$u]['n'] = ($porUnidade[$u]['n'] ?? 0) + 1;
                $porUnidade[$u]['nome'] = trim((string)($r['UNIDADE'] ?? '')) ?: ('#' . $u);
            }
            if ($porUnidade) {
                $pilar = $this->t('home.pilar.legal', ['pt' => 'Requisitos legais', 'en' => 'Legal requirements', 'es' => 'Requisitos legales']);
                foreach ($porUnidade as $u => $x) {
                    $titulo = $this->t('home.titulo.leis_nao_analisadas', ['pt' => 'Leis não analisadas', 'en' => 'Laws not analyzed', 'es' => 'Leyes no analizadas']) . ' - ' . $x['nome'];
                    $motivo = str_replace('{n}', (string)$x['n'], $this->t('home.motivo.leis_nao_analisadas', ['pt' => '{n} lei(s) sem avaliação: avaliar o atendimento (há Avaliação em lote nos Requisitos legais)', 'en' => '{n} law(s) without assessment: assess the compliance (batch assessment available in Legal requirements)', 'es' => '{n} ley(es) sin evaluación: evaluar la atención (hay evaluación en lote en Requisitos legales)']));
                    $this->add($pilar, $titulo, $motivo, null, self::url('modulolegal_requisitoslegais.php', ['CODUNIDADE' => (string)$u, 'ATENDIDO' => 'Não avaliado']));
                }
            }
        }
        // Treinamentos, infraestrutura e controles (sem data: a pendência é a responsabilidade)
        foreach ([
            ['MODULOLEGAL_TREINAMENTOS', 'vw_minhas_treinamentos_grid', 'modulolegal_treinamentos.php', 'CODTREINAMENTO', 'DSCTREINAMENTO', 'treinamentos', ['pt' => 'Treinamentos', 'en' => 'Training', 'es' => 'Capacitaciones'], ['pt' => 'Responsável pelo treinamento: manter a evidência e os participantes em dia', 'en' => 'Responsible for the training: keep evidence and attendees up to date', 'es' => 'Responsable de la capacitación: mantener evidencias y participantes al día']],
            ['MODULOLEGAL_INFRAESTRUTURA', 'vw_minhas_infraestrutura_grid', 'modulolegal_InfraEstrutura.php', 'CODINFRAESTRUTURA', 'DSCINFRAESTRUTURA', 'infraestrutura', ['pt' => 'Infraestrutura', 'en' => 'Infrastructure', 'es' => 'Infraestructura'], ['pt' => 'Responsável pela infraestrutura: manter os arquivos e atributos em dia', 'en' => 'Responsible for the infrastructure: keep files and attributes up to date', 'es' => 'Responsable de la infraestructura: mantener archivos y atributos al día']],
            ['CONTROLE_DOCUMENTOS', 'vw_meus_controles_grid', 'modulolegal_controle.php', 'CODDOCUMENTO', 'TITULO', 'controles', ['pt' => 'Controles', 'en' => 'Controls', 'es' => 'Controles'], ['pt' => 'Responsável pelo controle: manter o documento atualizado', 'en' => 'Responsible for the control: keep the document up to date', 'es' => 'Responsable del control: mantener el documento actualizado']],
        ] as [$perm, $view, $pagina, $chave, $campoTitulo, $pk, $pilarTxt, $motivoTxt]) {
            if (!self::pode($perm)) continue;
            $pilar = $this->t('home.pilar.' . $pk, $pilarTxt);
            $motivo = $this->t('home.motivo.' . $pk . '.responsavel', $motivoTxt);
            foreach ($this->ler($view, 500) as $r) $this->add($pilar, (string)($r[$campoTitulo] ?? $pilar), $motivo, null, self::url($pagina, [$chave => (string)($r[$chave] ?? '')], true));
        }
    }

    /** Migração 362 (GER-5): ações concluídas em que o usuário é o avaliador da eficácia. */
    private function eficacia(): void
    {
        if (!is_file(__DIR__ . '/EficaciaPlanos.php')) return;
        require_once __DIR__ . '/EficaciaPlanos.php';
        try { $linhas = self::$lote !== null ? $this->ler('vw_eficacia_pendentes') : EficaciaPlanos::pendentes($this->pdo, $this->uid); } catch (Throwable) { return; }
        $motivo = $this->t('home.motivo.eficacia.avaliar', ['pt' => 'Ação concluída: avaliar a eficácia (Eficaz ou Não eficaz)', 'en' => 'Action done: review its effectiveness (effective or not)', 'es' => 'Acción concluida: evaluar la eficacia (eficaz o no eficaz)']);
        foreach ($linhas as $r) {
            $mod = (string)($r['MODULO'] ?? '');
            if ($mod === 'CONDICIONANTE') { if (!self::pode('LICENCAS')) continue; $pilar = $this->t('home.pilar.licencas', ['pt' => 'Licenças', 'en' => 'Licenses', 'es' => 'Licencias']); }
            elseif ($mod === 'FORNECEDOR') { if ($this->fornecedorExterno || !self::pode('HOMOLOGACAO_FORNECEDOR_FORNECEDOR')) continue; $pilar = $this->t('home.pilar.homologacao', ['pt' => 'Homologação de Fornecedores', 'en' => 'Supplier Homologation', 'es' => 'Homologación de Proveedores']); }
            else continue;
            $acao = mb_strimwidth(trim((string)($r['ACAO'] ?? '')), 0, 120, '…');
            $this->add($pilar, trim((string)($r['ORIGEM'] ?? '')) . ' - ' . ($acao !== '' ? $acao : '#' . (int)$r['CODPLANOACAO']), $motivo,
                self::data($r['DATAFINAL'] ?? ''), EficaciaPlanos::url($mod, (int)($r['CODORIGEM'] ?? 0)));
        }
    }

    private function homologacao(): void
    {
        $pilar = $this->t('home.pilar.homologacao', ['pt' => 'Homologação de Fornecedores', 'en' => 'Supplier Homologation', 'es' => 'Homologación de Proveedores']);
        $urlForn = static fn(int $id): string => self::url('homologacaofornecedor_fornecedor.php', ['CODFORNECEDOR' => $id]);
        $urlFunc = static fn(int $id): string => self::url('homologacaofornecedor_funcionario.php', ['CODFUNCIONARIO' => $id]);
        $podeFuncionario = self::pode('HOMOLOGACAO_FORNECEDOR_FUNCIONARIO');
        $motivoFuncionario = function (array $r): array {
            $atende = trim((string)($r['ATENDE'] ?? '')); $validade = self::data($r['DATADEVALIDADE'] ?? '');
            if ($atende === 'Não') return [$this->t('home.motivo.doc_funcionario.nao_atende', ['pt' => 'Funcionário não atende ao critério', 'en' => 'Employee does not meet the criterion', 'es' => 'El empleado no cumple el criterio']), $validade];
            if ($atende === '') return [$this->t('home.motivo.doc_funcionario.sem_avaliacao', ['pt' => 'Critério do funcionário ainda não avaliado', 'en' => 'Employee criterion not yet reviewed', 'es' => 'Criterio del empleado aún no evaluado']), $validade];
            return [$this->porPrazo($validade, 'doc_funcionario', ['pt' => 'Documento do funcionário vencido em {data}', 'en' => 'Employee document expired on {data}', 'es' => 'Documento del empleado vencido el {data}'],
                ['pt' => 'Documento do funcionário vence em {data}', 'en' => 'Employee document expires on {data}', 'es' => 'El documento del empleado vence el {data}'],
                ['pt' => 'Documento do funcionário vence em {data}', 'en' => 'Employee document expires on {data}', 'es' => 'El documento del empleado vence el {data}'],
                ['pt' => 'Documento do funcionário sem validade', 'en' => 'Employee document without expiry', 'es' => 'Documento del empleado sin vencimiento']), $validade];
        };
        // Migração 296: o fornecedor externo não recebe as pendências de responsável interno.
        if (!$this->fornecedorExterno) {
            // 311: fornecedores ativos em que o usuário é o responsável pelo fornecedor ou pela integração.
            foreach ($this->ler('vw_homologacao_pendencias_fornecedor_responsavel') as $r) {
                $id = (int)($r['CODFORNECEDOR'] ?? 0); if ($id <= 0) continue;
                $avaliacao = self::data($r['DATA_PROXIMA_AVALIACAO'] ?? '');
                $papel = ($r['PAPEL'] ?? '') === 'INTEGRACAO'
                    ? $this->t('home.motivo.fornecedor.papel_integracao', ['pt' => 'Responsável pela integração', 'en' => 'Responsible for onboarding', 'es' => 'Responsable de la integración'])
                    : $this->t('home.motivo.fornecedor.papel_fornecedor', ['pt' => 'Responsável pelo fornecedor', 'en' => 'Responsible for the supplier', 'es' => 'Responsable del proveedor']);
                $this->add($pilar, (string)$r['FORNECEDOR'], $papel . ': ' . $this->porPrazo($avaliacao, 'fornecedor.avaliacao', ['pt' => 'avaliação vencida em {data}', 'en' => 'evaluation overdue since {data}', 'es' => 'evaluación vencida el {data}'],
                    ['pt' => 'avaliação prevista para {data}', 'en' => 'evaluation due on {data}', 'es' => 'evaluación prevista para {data}'],
                    ['pt' => 'próxima avaliação em {data}', 'en' => 'next evaluation on {data}', 'es' => 'próxima evaluación el {data}'],
                    ['pt' => 'sem data da próxima avaliação', 'en' => 'no next evaluation date', 'es' => 'sin fecha de la próxima evaluación']), $avaliacao, $urlForn($id));
            }
            // 312: responsabilidades da camada nova (atribuições do módulo Homologação) em fornecedores ativos.
            foreach ($this->ler('vw_homologacao_responsabilidade_fornecedor') as $r) {
                $id = (int)($r['CODFORNECEDOR'] ?? 0); if ($id <= 0) continue;
                $ate = self::data($r['VALIDO_ATE'] ?? '');
                $this->add($pilar, (string)$r['FORNECEDOR'], $this->t('home.motivo.fornecedor.atribuida', ['pt' => 'Responsabilidade atribuída: {modulo}', 'en' => 'Assigned responsibility: {modulo}', 'es' => 'Responsabilidad asignada: {modulo}'], ['{modulo}' => (string)($r['MODULO_NOME'] ?? 'Homologação')])
                    . ($ate ? ' (' . $this->t('home.motivo.fornecedor.ate', ['pt' => 'até {data}', 'en' => 'until {data}', 'es' => 'hasta {data}'], ['{data}' => self::formatar($ate)]) . ')' : ''), $ate, $urlForn($id));
            }
            // 310: documentos do fornecedor que pedem ação da empresa.
            foreach ($this->ler('vw_homologacao_pendencias_documento_interno') as $r) {
                $id = (int)($r['CODFORNECEDOR'] ?? 0); if ($id <= 0) continue;
                $situacao = trim((string)($r['SITUACAO'] ?? '')); $validade = self::data($r['DATADEVALIDADE'] ?? '');
                if ($situacao === 'Pendente com a empresa') $motivo = $this->t('home.motivo.doc_fornecedor.analisar', ['pt' => 'Documento enviado aguardando análise da empresa', 'en' => 'Document sent, awaiting company review', 'es' => 'Documento enviado, a la espera del análisis de la empresa']);
                elseif ($situacao === '') $motivo = $this->t('home.motivo.doc_fornecedor.sem_situacao', ['pt' => 'Documento ainda não avaliado (sem situação)', 'en' => 'Document not yet reviewed (no status)', 'es' => 'Documento aún no evaluado (sin situación)']);
                else $motivo = $this->porPrazo($validade, 'doc_fornecedor', ['pt' => 'Documento aprovado vencido em {data}: cobrar a renovação', 'en' => 'Approved document expired on {data}: request the renewal', 'es' => 'Documento aprobado vencido el {data}: solicitar la renovación'],
                    ['pt' => 'Documento aprovado vence em {data}: cobrar a renovação', 'en' => 'Approved document expires on {data}: request the renewal', 'es' => 'El documento aprobado vence el {data}: solicitar la renovación'],
                    ['pt' => 'Documento aprovado dentro dos dias de reação (vence em {data})', 'en' => 'Approved document within the reaction days (expires on {data})', 'es' => 'Documento aprobado dentro de los días de reacción (vence el {data})'],
                    ['pt' => 'Documento aprovado sem validade', 'en' => 'Approved document without expiry', 'es' => 'Documento aprobado sin vencimiento']);
                $this->add($pilar, $r['FORNECEDOR'] . ' - ' . $r['DSCRITERIO'], $motivo, $validade, $urlForn($id));
            }
            if ($podeFuncionario) {
                // 310 (c452): documentos de funcionário que pedem ação.
                foreach ($this->ler('vw_homologacao_pendencias_funcionario_interno') as $r) {
                    $id = (int)($r['CODFUNCIONARIO'] ?? 0); if ($id <= 0) continue;
                    [$motivo, $validade] = $motivoFuncionario($r);
                    $this->add($pilar, $r['FORNECEDOR'] . ' - ' . $r['FUNCIONARIO'] . ' - ' . $r['DSCRITERIO'], $motivo, $validade, $urlFunc($id));
                }
                // 312: treinamentos de funcionário vencidos ou vencendo em 30 dias.
                foreach ($this->ler('vw_homologacao_pendencias_treinamento_interno') as $r) {
                    $id = (int)($r['CODFUNCIONARIO'] ?? 0); if ($id <= 0) continue;
                    $validade = self::data($r['DATADEVALIDADE'] ?? '');
                    $this->add($pilar, $r['FORNECEDOR'] . ' - ' . $r['FUNCIONARIO'] . ' - ' . $r['TREINAMENTO'], $this->porPrazo($validade, 'treinamento_funcionario', ['pt' => 'Treinamento do funcionário vencido em {data}', 'en' => 'Employee training expired on {data}', 'es' => 'Capacitación del empleado vencida el {data}'],
                        ['pt' => 'Treinamento do funcionário vence em {data}', 'en' => 'Employee training expires on {data}', 'es' => 'La capacitación del empleado vence el {data}'],
                        ['pt' => 'Treinamento do funcionário vence em {data}', 'en' => 'Employee training expires on {data}', 'es' => 'La capacitación del empleado vence el {data}'],
                        ['pt' => 'Treinamento do funcionário sem validade', 'en' => 'Employee training without expiry', 'es' => 'Capacitación del empleado sin vencimiento']), $validade, $urlFunc($id));
                }
            }
            // 311: ações do plano de ação do fornecedor.
            foreach ($this->ler('vw_homologacao_pendencias_planoacao_interno') as $r) {
                $id = (int)($r['CODFORNECEDOR'] ?? 0); if ($id <= 0) continue;
                $prazo = self::data($r['DATAFINAL'] ?? '');
                $acao = trim((string)($r['ACAO'] ?? '')) ?: $this->t('home.motivo.plano.titulo', ['pt' => 'Plano de ação', 'en' => 'Action plan', 'es' => 'Plan de acción']);
                $this->add($pilar, $r['FORNECEDOR'] . ' - ' . $acao, $this->porPrazo($prazo, 'plano', ['pt' => 'Ação do plano de ação atrasada desde {data}', 'en' => 'Action plan item overdue since {data}', 'es' => 'Acción del plan atrasada desde {data}'],
                    ['pt' => 'Ação do plano de ação com prazo em {data}', 'en' => 'Action plan item due on {data}', 'es' => 'Acción del plan con plazo el {data}'],
                    ['pt' => 'Ação do plano de ação com prazo em {data}', 'en' => 'Action plan item due on {data}', 'es' => 'Acción del plan con plazo el {data}'],
                    ['pt' => 'Ação do plano de ação sem prazo', 'en' => 'Action plan item without a deadline', 'es' => 'Acción del plan sin plazo']), $prazo, $urlForn($id));
            }
        }
        // 296: documentos em que o usuário é o responsável do fornecedor e que pedem ação dele.
        foreach ($this->ler('vw_homologacao_pendencias_fornecedor_externo') as $r) {
            $id = (int)($r['CODFORNECEDOR'] ?? 0); if ($id <= 0) continue;
            $situacao = trim((string)($r['SITUACAO'] ?? '')); $validade = self::data($r['DATADEVALIDADE'] ?? ''); $cor = strtoupper(trim((string)($r['COR'] ?? '')));
            if ($validade && $this->dias($validade) < 0) $motivo = $this->t('home.motivo.externo.vencido', ['pt' => 'Documento vencido em {data}: enviar o documento atualizado', 'en' => 'Document expired on {data}: send the updated document', 'es' => 'Documento vencido el {data}: enviar el documento actualizado'], ['{data}' => self::formatar($validade)]);
            elseif ($cor === 'AMARELO' && $validade) $motivo = $this->t('home.motivo.externo.vencendo', ['pt' => 'Documento vence em {data}: enviar a renovação', 'en' => 'Document expires on {data}: send the renewal', 'es' => 'El documento vence el {data}: enviar la renovación'], ['{data}' => self::formatar($validade)]);
            elseif ($situacao === '') $motivo = $this->t('home.motivo.externo.enviar', ['pt' => 'Documento ainda não enviado: enviar o documento', 'en' => 'Document not sent yet: send the document', 'es' => 'Documento aún no enviado: enviar el documento']);
            else $motivo = $this->t('home.motivo.externo.situacao', ['pt' => 'Situação "{situacao}": corrigir e reenviar o documento', 'en' => 'Status "{situacao}": fix and resend the document', 'es' => 'Situación "{situacao}": corregir y reenviar el documento'], ['{situacao}' => $situacao]);
            $this->add($pilar, $r['FORNECEDOR'] . ' - ' . $r['DSCRITERIO'], $motivo, $validade, $urlForn($id));
        }
        // 312: documentos de funcionário em que o usuário é o responsável do fornecedor (antes só na agenda, sem filtro).
        if ($podeFuncionario) {
            foreach ($this->ler('vw_homologacao_pendencias_funcionario_externo') as $r) {
                $id = (int)($r['CODFUNCIONARIO'] ?? 0); if ($id <= 0) continue;
                [$motivo, $validade] = $motivoFuncionario($r);
                $this->add($pilar, $r['FORNECEDOR'] . ' - ' . $r['FUNCIONARIO'] . ' - ' . $r['DSCRITERIO'], $motivo, $validade, $urlFunc($id));
            }
        }
    }
}
