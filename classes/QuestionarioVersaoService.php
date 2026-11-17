<?php
declare(strict_types=1);

require_once __DIR__ . '/MigracaoCrud.php';
require_once __DIR__ . '/PermissaoTransacao.php';

/*
 * Versões de questionário por empresa (migração 291) — catálogo universal (guniversal_dbp, amb_questionario_*).
 *
 *   codempresa = 0   versão universal: só o ADMINISTRADOR altera; vale para toda empresa que não tem versão própria.
 *   codempresa > 0   versão da empresa: criada pelo ADM DA EMPRESA (ou perfil com Editar em Questionários) a partir da
 *                    versão que a empresa usa; vale só para ela.
 *
 * Versão vigente de uma empresa = a publicada dela, senão a universal publicada.
 * Quando o ADMINISTRADOR publica uma nova versão universal, cada empresa com versão própria recebe um merge de três vias
 * (base = universal de onde a versão da empresa veio, empresa = versão atual dela, universal = nova):
 *   item novo na universal ............................ entra
 *   alterado só na universal .......................... fica o da universal
 *   alterado só na empresa ............................ fica o da empresa
 *   alterado nos dois, diferente ...................... fica o da empresa e vira CONFLITO (o ADM confere)
 *   retirado da universal, sem alteração na empresa ... sai
 *   retirado da universal, alterado na empresa ........ continua (aviso)
 *   criado ou retirado pela empresa ................... continua como a empresa deixou
 * Itens = blocos, perguntas (com as opções) e regras, identificados pelo código.
 * Resultado: nova versão da empresa (origem MERGE). Sem conflito e com a empresa em versão publicada → já publicada;
 * com conflito, ou se a empresa estava com um rascunho aberto → rascunho (o rascunho anterior é arquivado, e o conteúdo dele
 * entra no merge). Versões nunca são apagadas: execuções e perfis respondidos continuam ligados à versão que responderam.
 *
 * Acesso a dados: leitura e gravação por sp_crud_* (MigracaoCrud) e views; metadados por information_schema.
 */
final class QuestionarioVersaoService
{
    /** Códigos de pergunta que alimentam itens estruturados (perfil de atribuições): o código não pode mudar */
    private const CODIGO_ESTRUTURADO = '/(^|\.)PILAR\.|\.TEMAS$|\.CATEGORIAS$|^GERAL\.UNIDADES$/';
    private const STATUS_VIVOS = ['RASCUNHO', 'PUBLICADA'];

    private array $colunas = [];

    public function __construct(private readonly PDO $u) {}

    // ------------------------------------------------------------------ escopo e permissão

    /** Quem está logado: ADMINISTRADOR → universal (0); demais → a empresa da sessão. */
    public static function escopo(array $sessao): array
    {
        $perfil = strtoupper(trim((string)($sessao['usuario']['codigo_perfil'] ?? '')));
        $admin = $perfil === 'ADMINISTRADOR';
        $perm = PermissaoTransacao::obter('QUESTIONARIOS');
        return [
            'admin' => $admin,
            'codempresa' => $admin ? 0 : (int)($sessao['tenant']['codempresa'] ?? 0),
            'empresa' => (string)($sessao['tenant']['nome'] ?? ''),
            'pode_editar' => $admin || !empty($perm['pode_editar']) || !empty($perm['pode_incluir']),
        ];
    }

    public function versao(int $codversao): ?array
    {
        return $codversao > 0 ? MigracaoCrud::primeiro($this->u, 'amb_questionario_versao', ['codversao' => $codversao]) : null;
    }

    /** Lança erro se o escopo não pode alterar a versão (rascunho do próprio escopo). */
    public function exigirEdicao(int $codversao, array $escopo): array
    {
        $v = $this->versao($codversao);
        if (!$v) throw new InvalidArgumentException('Versão não localizada.');
        if (!$escopo['pode_editar']) throw new DomainException('Seu perfil pode consultar os questionários, mas não alterar.');
        if ((int)$v['codempresa'] !== (int)$escopo['codempresa'])
            throw new DomainException((int)$v['codempresa'] === 0 ? 'Esta é a versão universal: só o ADMINISTRADOR altera. Crie a versão da sua empresa.' : 'Esta versão é de outra empresa.');
        if ((string)$v['status'] !== 'RASCUNHO') throw new DomainException('A versão publicada é imutável. Crie uma nova versão para alterar.');
        return $v;
    }

    // ------------------------------------------------------------------ consulta

    /** Versões do questionário (todas as do escopo + universais), mais novas primeiro */
    public function versoes(int $codquestionario, int $codempresa): array
    {
        $r = array_values(array_filter(MigracaoCrud::selecionar($this->u, 'amb_questionario_versao', ['codquestionario' => $codquestionario]),
            static fn($v) => in_array((int)$v['codempresa'], [0, $codempresa], true)));
        usort($r, static fn($a, $b) => [(int)$b['versao'], (int)$b['codversao']] <=> [(int)$a['versao'], (int)$a['codversao']]);
        return $r;
    }

    private function ultima(int $codquestionario, int $codempresa, array $status): ?array
    {
        foreach ($this->versoes($codquestionario, $codempresa) as $v)
            if ((int)$v['codempresa'] === $codempresa && in_array((string)$v['status'], $status, true)) return $v;
        return null;
    }

    /** Versão que a empresa usa hoje: a publicada dela, senão a universal publicada */
    public function vigente(int $codquestionario, int $codempresa): ?array
    {
        return ($codempresa > 0 ? $this->ultima($codquestionario, $codempresa, ['PUBLICADA']) : null) ?? $this->ultima($codquestionario, 0, ['PUBLICADA']);
    }

    /**
     * Linha do questionário para a tela de administração: a versão de trabalho do escopo (rascunho dele, senão a
     * publicada dele, senão a universal publicada / última universal) e as informações de versão.
     */
    public function resumo(array $q, array $escopo): ?array
    {
        $cq = (int)$q['codquestionario']; $emp = (int)$escopo['codempresa'];
        $rasc = $this->ultima($cq, $emp, ['RASCUNHO']);
        $pub = $this->ultima($cq, $emp, ['PUBLICADA']);
        $uni = $this->ultima($cq, 0, ['PUBLICADA']);
        $trab = $rasc ?? $pub ?? $uni ?? ($this->versoes($cq, 0)[0] ?? null);
        if (!$trab) return null;
        $conflitos = $this->conflitos($trab);
        return [
            'codquestionario' => $cq, 'codigo' => $q['codigo'], 'nome_pt' => $q['nome_pt'], 'modulo' => $q['modulo'],
            'codversao' => (int)$trab['codversao'], 'versao' => (int)$trab['versao'], 'status' => (string)$trab['status'],
            'codempresa_versao' => (int)$trab['codempresa'], 'origem' => (string)($trab['origem'] ?? 'UNIVERSAL'),
            'base_universal' => $this->numero((int)($trab['codversao_base_universal'] ?? 0)),
            'em_uso' => $emp > 0 ? ($pub ? 'EMPRESA' : 'UNIVERSAL') : 'UNIVERSAL',
            'versao_em_uso' => (int)(($emp > 0 ? ($pub ?? $uni) : $uni)['versao'] ?? 0),
            'versao_universal' => (int)($uni['versao'] ?? 0),
            'tem_rascunho' => (bool)$rasc,
            'pode_editar' => $escopo['pode_editar'] && (string)$trab['status'] === 'RASCUNHO' && (int)$trab['codempresa'] === $emp,
            'pode_nova_versao' => $escopo['pode_editar'] && !$rasc,
            'conflitos' => array_values(array_filter($conflitos, static fn($c) => empty($c['resolvido']))),
            'avisos' => $this->avisos($trab),
            'observacao' => (string)($trab['observacao'] ?? ''),
        ];
    }

    private function numero(int $codversao): ?int
    {
        $v = $codversao > 0 ? $this->versao($codversao) : null;
        return $v ? (int)$v['versao'] : null;
    }

    public function conflitos(array $v): array
    {
        $d = json_decode((string)($v['conflitos_json'] ?? ''), true);
        return is_array($d['conflitos'] ?? null) ? $d['conflitos'] : [];
    }

    private function avisos(array $v): array
    {
        $d = json_decode((string)($v['conflitos_json'] ?? ''), true);
        return is_array($d['avisos'] ?? null) ? $d['avisos'] : [];
    }

    /** Códigos de pergunta que já existem em outra versão do questionário (não podem ser renomeados) */
    public function codigosFixos(int $codversao): array
    {
        $v = $this->versao($codversao);
        if (!$v) return [];
        $outras = array_map(static fn($x) => (int)$x['codversao'], array_filter(MigracaoCrud::selecionar($this->u, 'amb_questionario_versao', ['codquestionario' => (int)$v['codquestionario']]),
            static fn($x) => (int)$x['codversao'] !== $codversao));
        $fixos = [];
        foreach ($outras as $cv) foreach (MigracaoCrud::selecionar($this->u, 'amb_questionario_pergunta', ['codversao' => $cv]) as $p) $fixos[strtoupper((string)$p['codigo'])] = true;
        return $fixos;
    }

    /** Pode trocar o código da pergunta de $antigo para $novo nesta versão? */
    public function validarTrocaCodigo(int $codversao, string $antigo, string $novo): void
    {
        $antigo = strtoupper($antigo); $novo = strtoupper($novo);
        if ($antigo === $novo) return;
        if (preg_match(self::CODIGO_ESTRUTURADO, $antigo))
            throw new DomainException("O código $antigo alimenta a indicação de responsáveis e não pode ser alterado (o texto pode).");
        if (isset($this->codigosFixos($codversao)[$antigo]))
            throw new DomainException("O código $antigo já existe em outra versão do questionário e não pode ser alterado: é ele que liga as respostas entre as versões.");
    }

    // ------------------------------------------------------------------ nova versão (cópia)

    /** Cria o rascunho do escopo a partir da versão em uso (ou devolve o rascunho que já existe). */
    public function novaVersao(int $codquestionario, array $escopo, int $usuario): array
    {
        if (!$escopo['pode_editar']) throw new DomainException('Seu perfil pode consultar os questionários, mas não alterar.');
        $emp = (int)$escopo['codempresa'];
        if (!$escopo['admin'] && $emp < 1) throw new DomainException('Empresa da sessão não identificada.');
        if ($r = $this->ultima($codquestionario, $emp, ['RASCUNHO'])) return $r;
        $base = $emp > 0 ? ($this->ultima($codquestionario, $emp, ['PUBLICADA']) ?? $this->ultima($codquestionario, 0, ['PUBLICADA'])) : ($this->versoes($codquestionario, 0)[0] ?? null);
        if (!$base) throw new DomainException('O questionário não tem versão para copiar.');
        $conteudo = $this->carregar((int)$base['codversao']);
        $cab = $this->cabecalho($base) + [
            'codquestionario' => $codquestionario, 'codempresa' => $emp, 'status' => 'RASCUNHO', 'criado_por' => $usuario,
            'origem' => $emp > 0 ? 'EMPRESA' : 'UNIVERSAL',
            'codversao_base_universal' => (int)$base['codempresa'] === 0 ? (int)$base['codversao'] : ((int)($base['codversao_base_universal'] ?? 0) ?: null),
            'codversao_base_empresa' => (int)$base['codempresa'] > 0 ? (int)$base['codversao'] : null,
            'observacao' => $emp > 0 ? sprintf('Criada a partir da versão %d (%s).', (int)$base['versao'], (int)$base['codempresa'] > 0 ? 'da empresa' : 'universal') : sprintf('Criada a partir da versão %d.', (int)$base['versao']),
        ];
        $this->u->beginTransaction();
        try {
            $cab['versao'] = $this->proximoNumero($codquestionario, $emp);
            $nova = $this->gravar($cab, $conteudo);
            $this->u->commit();
        } catch (Throwable $e) { if ($this->u->inTransaction()) $this->u->rollBack(); throw $e; }
        return $this->versao($nova) ?? [];
    }

    /** Arquiva o rascunho do escopo (as versões nunca são apagadas) */
    public function descartar(int $codversao, array $escopo): void
    {
        $this->exigirEdicao($codversao, $escopo);
        MigracaoCrud::atualizar($this->u, 'amb_questionario_versao', ['codversao' => $codversao], ['status' => 'ARQUIVADA', 'observacao' => 'Rascunho descartado.']);
    }

    private function proximoNumero(int $codquestionario, int $codempresa): int
    {
        $max = 0;
        foreach (MigracaoCrud::selecionar($this->u, 'amb_questionario_versao', ['codquestionario' => $codquestionario]) as $v)
            if (in_array((int)$v['codempresa'], [0, $codempresa], true)) $max = max($max, (int)$v['versao']);
        return $max + 1;
    }

    private function cabecalho(array $v): array
    {
        $c = [];
        foreach (['instrucoes_pt', 'instrucoes_en', 'instrucoes_es', 'percentual_minimo'] as $k) if (array_key_exists($k, $v)) $c[$k] = $v[$k];
        return $c;
    }

    // ------------------------------------------------------------------ publicação

    /** Publica o rascunho do escopo; se for universal, faz o merge nas empresas com versão própria. */
    public function publicar(int $codversao, array $escopo, int $usuario): array
    {
        $v = $this->exigirEdicao($codversao, $escopo);
        $pend = array_filter($this->conflitos($v), static fn($c) => empty($c['resolvido']));
        if ($pend) throw new DomainException(sprintf('Resolva os %d conflito(s) com a versão universal antes de publicar.', count($pend)));
        $this->u->beginTransaction();
        try {
            $this->publicarSemTransacao($v, $usuario);
            $this->u->commit();
        } catch (Throwable $e) { if ($this->u->inTransaction()) $this->u->rollBack(); throw $e; }
        $r = ['codversao' => $codversao, 'status' => 'PUBLICADA', 'versao' => (int)$v['versao']];
        if ((int)$v['codempresa'] === 0) $r['merge'] = $this->mesclarEmpresas($codversao, $usuario);
        return $r;
    }

    private function publicarSemTransacao(array $v, int $usuario): void
    {
        $cv = (int)$v['codversao']; $emp = (int)$v['codempresa'];
        if (!array_filter(MigracaoCrud::selecionar($this->u, 'amb_questionario_pergunta', ['codversao' => $cv]), static fn($p) => (int)$p['ativo'] === 1))
            throw new DomainException('A versão precisa ter ao menos uma pergunta.');
        foreach (MigracaoCrud::selecionar($this->u, 'amb_questionario_versao', ['codquestionario' => (int)$v['codquestionario'], 'status' => 'PUBLICADA']) as $p)
            if ((int)$p['codempresa'] === $emp && (int)$p['codversao'] !== $cv)
                MigracaoCrud::atualizar($this->u, 'amb_questionario_versao', ['codversao' => (int)$p['codversao']], ['status' => 'ARQUIVADA']);
        MigracaoCrud::atualizar($this->u, 'amb_questionario_versao', ['codversao' => $cv], ['status' => 'PUBLICADA', 'publicado_em' => date('Y-m-d H:i:s'), 'publicado_por' => $usuario ?: null]);
        $q = MigracaoCrud::primeiro($this->u, 'amb_questionario', ['codquestionario' => (int)$v['codquestionario']]);
        $codigo = sprintf('Q:%s:%sV:%d', (string)($q['codigo'] ?? $v['codquestionario']), $emp > 0 ? 'E' . $emp . ':' : '', (int)$v['versao']);
        if (!MigracaoCrud::primeiro($this->u, 'amb_questionario_publicacao', ['codversao' => $cv]))
            MigracaoCrud::inserir($this->u, 'amb_questionario_publicacao', ['codversao' => $cv, 'codigo_publicacao' => substr($codigo, 0, 150), 'publicado_por' => $usuario ?: null]);
    }

    // ------------------------------------------------------------------ merge

    /** Depois de publicar a universal $codversaoUniversal: merge em cada empresa que tem versão própria */
    public function mesclarEmpresas(int $codversaoUniversal, int $usuario): array
    {
        $nova = $this->versao($codversaoUniversal);
        if (!$nova || (int)$nova['codempresa'] !== 0) return [];
        $cq = (int)$nova['codquestionario'];
        $empresas = [];
        foreach (MigracaoCrud::selecionar($this->u, 'amb_questionario_versao', ['codquestionario' => $cq]) as $v)
            if ((int)$v['codempresa'] > 0 && in_array((string)$v['status'], self::STATUS_VIVOS, true)) $empresas[(int)$v['codempresa']] = true;
        $rel = [];
        foreach (array_keys($empresas) as $emp) {
            try { $rel[$emp] = $this->mesclarEmpresa($cq, $emp, $nova, $usuario); }
            catch (Throwable $e) { $rel[$emp] = ['erro' => $e->getMessage()]; }
        }
        return $rel;
    }

    private function mesclarEmpresa(int $cq, int $emp, array $nova, int $usuario): array
    {
        $ours = $this->ultima($cq, $emp, ['RASCUNHO']) ?? $this->ultima($cq, $emp, ['PUBLICADA']);
        if (!$ours) return ['situacao' => 'sem versão própria'];
        $codBase = (int)($ours['codversao_base_universal'] ?? 0);
        if ($codBase === (int)$nova['codversao']) return ['situacao' => 'já atualizada'];
        $base = $codBase > 0 ? $this->carregar($codBase) : ['cab' => [], 'blocos' => [], 'perguntas' => [], 'regras' => []];
        $o = $this->carregar((int)$ours['codversao']);
        $t = $this->carregar((int)$nova['codversao']);
        [$conteudo, $conflitos, $avisos] = self::mesclar($base, $o, $t);
        $eraRascunho = (string)$ours['status'] === 'RASCUNHO';
        $publicar = !$conflitos && !$eraRascunho;
        $cab = $conteudo['cab'] + [
            'codquestionario' => $cq, 'codempresa' => $emp, 'status' => 'RASCUNHO', 'criado_por' => $usuario ?: null, 'origem' => 'MERGE',
            'codversao_base_universal' => (int)$nova['codversao'], 'codversao_base_empresa' => (int)$ours['codversao'],
            'conflitos_json' => json_encode(['conflitos' => $conflitos, 'avisos' => $avisos], JSON_UNESCAPED_UNICODE),
            'observacao' => sprintf('Merge da versão universal %d com a versão %d da empresa%s.', (int)$nova['versao'], (int)$ours['versao'], $eraRascunho ? ' (rascunho em edição)' : ''),
        ];
        $this->u->beginTransaction();
        try {
            $cab['versao'] = $this->proximoNumero($cq, $emp);
            $cv = $this->gravar($cab, $conteudo);
            if ($eraRascunho) MigracaoCrud::atualizar($this->u, 'amb_questionario_versao', ['codversao' => (int)$ours['codversao']],
                ['status' => 'ARQUIVADA', 'observacao' => sprintf('Substituído pelo merge com a versão universal %d (versão %d).', (int)$nova['versao'], (int)$cab['versao'])]);
            if ($publicar) $this->publicarSemTransacao($this->versao($cv) ?? [], $usuario);
            $this->u->commit();
        } catch (Throwable $e) { if ($this->u->inTransaction()) $this->u->rollBack(); throw $e; }
        return ['versao' => (int)$cab['versao'], 'status' => $publicar ? 'PUBLICADA' : 'RASCUNHO', 'conflitos' => count($conflitos), 'avisos' => count($avisos)];
    }

    /**
     * Merge de três vias (puro, sem banco). Cada lado: ['cab' => campos da versão, 'blocos' => [codigo => campos],
     * 'perguntas' => [codigo => campos + _bloco + _opcoes], 'regras' => [codigo => campos]].
     * @return array{0: array, 1: array, 2: array} conteúdo, conflitos, avisos
     */
    public static function mesclar(array $base, array $ours, array $theirs): array
    {
        $conflitos = []; $avisos = [];
        $r = ['cab' => self::mesclarCampos($base['cab'] ?? [], $ours['cab'] ?? [], $theirs['cab'] ?? [])];
        foreach (['blocos' => 'BLOCO', 'perguntas' => 'PERGUNTA', 'regras' => 'REGRA'] as $col => $tipo)
            $r[$col] = self::mesclarColecao($base[$col] ?? [], $ours[$col] ?? [], $theirs[$col] ?? [], $tipo, $conflitos, $avisos);
        return [$r, $conflitos, $avisos];
    }

    private static function mesclarCampos(array $b, array $o, array $t): array
    {
        $r = [];
        foreach (array_unique(array_merge(array_keys($o), array_keys($t))) as $k) {
            $vb = $b[$k] ?? null; $vo = $o[$k] ?? null; $vt = $t[$k] ?? null;
            $r[$k] = (string)$vo === (string)$vb ? $vt : $vo;
        }
        return $r;
    }

    private static function assinatura(?array $x): ?string
    {
        if ($x === null) return null;
        $n = $x; unset($n['_rotulo']);
        if (isset($n['_opcoes'])) { ksort($n['_opcoes']); foreach ($n['_opcoes'] as &$op) { ksort($op); $op = array_map(static fn($v) => $v === null ? null : (string)$v, $op); } unset($op); }
        ksort($n);
        foreach ($n as $k => $v) if (!is_array($v)) $n[$k] = $v === null ? null : (string)$v;
        return json_encode($n, JSON_UNESCAPED_UNICODE);
    }

    private static function rotulo(?array $x): string
    {
        return mb_substr((string)($x['texto_pt'] ?? $x['titulo_pt'] ?? ''), 0, 160);
    }

    private static function mesclarColecao(array $b, array $o, array $t, string $tipo, array &$conflitos, array &$avisos): array
    {
        $r = [];
        $chaves = array_unique(array_merge(array_keys($o), array_keys($t), array_keys($b)));
        foreach ($chaves as $k) {
            $vb = $b[$k] ?? null; $vo = $o[$k] ?? null; $vt = $t[$k] ?? null;
            $sb = self::assinatura($vb); $so = self::assinatura($vo); $st = self::assinatura($vt);
            if ($vo === null && $vt === null) continue;
            if ($vb === null) {                                    // item novo em um dos lados (ou nos dois)
                if ($vt === null) { $r[$k] = $vo; continue; }
                if ($vo === null) { $r[$k] = $vt; continue; }
                $r[$k] = $vo;
                if ($so !== $st) $conflitos[] = ['tipo' => $tipo, 'codigo' => (string)$k, 'motivo' => 'CRIADO_NOS_DOIS', 'empresa' => self::rotulo($vo), 'universal_rotulo' => self::rotulo($vt), 'universal' => $vt, 'resolvido' => false];
                continue;
            }
            if ($vo === null) {                                    // a empresa retirou: continua retirado
                if ($st !== $sb) $avisos[] = ['tipo' => $tipo, 'codigo' => (string)$k, 'motivo' => 'RETIRADO_PELA_EMPRESA_ALTERADO_NA_UNIVERSAL', 'universal_rotulo' => self::rotulo($vt)];
                continue;
            }
            if ($vt === null) {                                    // a universal retirou
                if ($so === $sb) { $avisos[] = ['tipo' => $tipo, 'codigo' => (string)$k, 'motivo' => 'RETIRADO_DA_UNIVERSAL', 'empresa' => self::rotulo($vo)]; continue; }
                $r[$k] = $vo;
                $avisos[] = ['tipo' => $tipo, 'codigo' => (string)$k, 'motivo' => 'RETIRADO_DA_UNIVERSAL_MANTIDO_PELA_EMPRESA', 'empresa' => self::rotulo($vo)];
                continue;
            }
            if ($so === $sb) { $r[$k] = $vt; continue; }          // só a universal mudou (ou ninguém)
            if ($st === $sb || $so === $st) { $r[$k] = $vo; continue; }   // só a empresa mudou (ou mudaram igual)
            $r[$k] = $vo;                                          // os dois mudaram: fica o da empresa
            $conflitos[] = ['tipo' => $tipo, 'codigo' => (string)$k, 'motivo' => 'ALTERADO_NOS_DOIS', 'empresa' => self::rotulo($vo), 'universal_rotulo' => self::rotulo($vt), 'universal' => $vt, 'resolvido' => false];
        }
        return $r;
    }

    /** Resolve um conflito do rascunho: MANTER (fica o da empresa) ou UNIVERSAL (troca pelo item da universal) */
    public function resolverConflito(int $codversao, string $tipo, string $codigo, string $acao, array $escopo): void
    {
        $v = $this->exigirEdicao($codversao, $escopo);
        $d = json_decode((string)($v['conflitos_json'] ?? ''), true) ?: ['conflitos' => [], 'avisos' => []];
        $achou = false;
        foreach ($d['conflitos'] as &$c) {
            if ($c['tipo'] !== $tipo || (string)$c['codigo'] !== $codigo || !empty($c['resolvido'])) continue;
            $achou = true;
            if ($acao === 'UNIVERSAL') {
                $atual = $this->carregar($codversao);
                $col = ['BLOCO' => 'blocos', 'PERGUNTA' => 'perguntas', 'REGRA' => 'regras'][$tipo];
                $atual[$col][$codigo] = $c['universal'];
                $this->u->beginTransaction();
                try { $this->substituirItem($codversao, $tipo, $codigo, $c['universal'], $atual); $this->u->commit(); }
                catch (Throwable $e) { if ($this->u->inTransaction()) $this->u->rollBack(); throw $e; }
            }
            $c['resolvido'] = $acao === 'UNIVERSAL' ? 'UNIVERSAL' : 'EMPRESA';
        }
        unset($c);
        if (!$achou) throw new InvalidArgumentException('Conflito não localizado.');
        MigracaoCrud::atualizar($this->u, 'amb_questionario_versao', ['codversao' => $codversao], ['conflitos_json' => json_encode($d, JSON_UNESCAPED_UNICODE)]);
    }

    private function substituirItem(int $cv, string $tipo, string $codigo, array $item, array $atual): void
    {
        if ($tipo === 'BLOCO') {
            $ex = $this->porCodigo('amb_questionario_bloco', ['codversao' => $cv], $codigo);
            if ($ex) MigracaoCrud::atualizar($this->u, 'amb_questionario_bloco', ['codbloco' => (int)$ex['codbloco']], $this->limpo('amb_questionario_bloco', $item));
            else MigracaoCrud::inserir($this->u, 'amb_questionario_bloco', $this->limpo('amb_questionario_bloco', $item) + ['codversao' => $cv, 'codigo' => $codigo]);
            return;
        }
        if ($tipo === 'REGRA') {
            $ex = $this->porCodigo('amb_questionario_regra', ['codversao' => $cv], $codigo);
            if ($ex) MigracaoCrud::atualizar($this->u, 'amb_questionario_regra', ['codregra' => (int)$ex['codregra']], $this->limpo('amb_questionario_regra', $item));
            else MigracaoCrud::inserir($this->u, 'amb_questionario_regra', $this->limpo('amb_questionario_regra', $item) + ['codversao' => $cv, 'codigo' => $codigo]);
            return;
        }
        $codbloco = null;
        if (!empty($item['_bloco'])) $codbloco = (int)($this->porCodigo('amb_questionario_bloco', ['codversao' => $cv], (string)$item['_bloco'])['codbloco'] ?? 0) ?: null;
        $dados = $this->limpo('amb_questionario_pergunta', $item) + ['codbloco' => $codbloco, 'ativo' => 1];
        $ex = $this->porCodigo('amb_questionario_pergunta', ['codversao' => $cv], $codigo);
        if ($ex) { $cp = (int)$ex['codpergunta']; MigracaoCrud::atualizar($this->u, 'amb_questionario_pergunta', ['codpergunta' => $cp], $dados); }
        else $cp = MigracaoCrud::inserir($this->u, 'amb_questionario_pergunta', $dados + ['codversao' => $cv, 'codigo' => $codigo]);
        $this->gravarOpcoes($cp, $item['_opcoes'] ?? []);
    }

    private function porCodigo(string $tabela, array $filtro, string $codigo): ?array
    {
        foreach (MigracaoCrud::selecionar($this->u, $tabela, $filtro + ['codigo' => $codigo]) as $r) if (strtoupper((string)$r['codigo']) === strtoupper($codigo)) return $r;
        return null;
    }

    // ------------------------------------------------------------------ opções da pergunta

    /** Grava as opções da pergunta (rascunho): atualiza pelo código, inclui as novas e desativa as que saíram */
    public function salvarOpcoes(int $codpergunta, array $opcoes, array $escopo): void
    {
        $p = MigracaoCrud::primeiro($this->u, 'amb_questionario_pergunta', ['codpergunta' => $codpergunta]);
        if (!$p) throw new InvalidArgumentException('Pergunta não localizada.');
        $this->exigirEdicao((int)$p['codversao'], $escopo);
        $lista = [];
        foreach ($opcoes as $i => $o) {
            $cod = strtoupper(trim((string)($o['codigo'] ?? '')));
            $rot = trim((string)($o['rotulo_pt'] ?? ''));
            if ($cod === '' && $rot === '') continue;
            if ($cod === '' && $rot !== '') {   // código interno da opção nova, a partir do rótulo (a tela não mostra)
                $base = strtoupper(trim((string)preg_replace('/[^A-Za-z0-9]+/', '_', (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $rot)), '_')) ?: 'OPCAO';
                $base = substr($base, 0, 60); $cod = $base;
                for ($n = 2; isset($lista[$cod]); $n++) $cod = $base . '_' . $n;
            }
            if (!preg_match('/^[A-Z0-9_.]{1,100}$/', $cod) || $rot === '') throw new InvalidArgumentException('Cada opção precisa de código (letras, números, _ e .) e rótulo.');
            if (isset($lista[$cod])) throw new InvalidArgumentException("Código de opção repetido: $cod.");
            $pont = trim((string)($o['pontuacao'] ?? ''));
            $lista[$cod] = ['rotulo_pt' => mb_substr($rot, 0, 180), 'pontuacao' => $pont === '' ? null : (float)str_replace(',', '.', $pont), 'ordem' => ($i + 1) * 10, 'valor' => $cod];
        }
        $this->u->beginTransaction();
        try { $this->gravarOpcoes($codpergunta, $lista, true); $this->u->commit(); }
        catch (Throwable $e) { if ($this->u->inTransaction()) $this->u->rollBack(); throw $e; }
    }

    /** $opcoes: [codigo => campos]. $soPt: só os campos editáveis na tela (preserva rótulos em inglês/espanhol) */
    private function gravarOpcoes(int $codpergunta, array $opcoes, bool $soPt = false): void
    {
        $exist = [];
        foreach (MigracaoCrud::selecionar($this->u, 'amb_questionario_opcao', ['codpergunta' => $codpergunta]) as $r) $exist[strtoupper((string)$r['codigo'])] = $r;
        foreach ($opcoes as $cod => $campos) {
            $dados = ($soPt ? $campos : $this->limpo('amb_questionario_opcao', $campos)) + ['ativo' => 1];
            if (isset($exist[$cod])) MigracaoCrud::atualizar($this->u, 'amb_questionario_opcao', ['codopcao' => (int)$exist[$cod]['codopcao']], $dados);
            else MigracaoCrud::inserir($this->u, 'amb_questionario_opcao', $dados + ['codpergunta' => $codpergunta, 'codigo' => (string)$cod]);
        }
        foreach ($exist as $cod => $r) if (!isset($opcoes[$cod]) && (int)$r['ativo'] === 1)
            MigracaoCrud::atualizar($this->u, 'amb_questionario_opcao', ['codopcao' => (int)$r['codopcao']], ['ativo' => 0]);
    }

    // ------------------------------------------------------------------ carga e gravação de uma versão

    private function cols(string $tabela): array
    {
        return $this->colunas[$tabela] ??= MigracaoCrud::colunas($this->u, $tabela);
    }

    /** Campos copiáveis da linha (sem chaves, versão e campos internos) */
    private function limpo(string $tabela, array $linha): array
    {
        $fora = ['codbloco', 'codpergunta', 'codopcao', 'codregra', 'codversao', 'codigo'];
        $r = [];
        foreach ($this->cols($tabela) as $c) if (!in_array($c, $fora, true) && array_key_exists($c, $linha)) $r[$c] = $linha[$c];
        return $r;
    }

    /** Conteúdo vivo da versão (itens ativos), indexado pelo código */
    public function carregar(int $cv): array
    {
        $v = $this->versao($cv) ?? [];
        $c = ['cab' => $this->cabecalho($v), 'blocos' => [], 'perguntas' => [], 'regras' => []];
        $blocoCod = [];
        foreach (MigracaoCrud::selecionar($this->u, 'amb_questionario_bloco', ['codversao' => $cv]) as $b) {
            $blocoCod[(int)$b['codbloco']] = strtoupper((string)$b['codigo']);
            if ((int)($b['ativo'] ?? 1) === 1) $c['blocos'][strtoupper((string)$b['codigo'])] = $this->limpo('amb_questionario_bloco', $b);
        }
        foreach (MigracaoCrud::selecionar($this->u, 'amb_questionario_pergunta', ['codversao' => $cv]) as $p) {
            if ((int)($p['ativo'] ?? 1) !== 1) continue;
            $item = $this->limpo('amb_questionario_pergunta', $p);
            unset($item['codbloco'], $item['ativo']);
            $item['_bloco'] = $p['codbloco'] !== null ? ($blocoCod[(int)$p['codbloco']] ?? null) : null;
            $item['_opcoes'] = [];
            foreach (MigracaoCrud::selecionar($this->u, 'amb_questionario_opcao', ['codpergunta' => (int)$p['codpergunta']]) as $o) {
                if ((int)($o['ativo'] ?? 1) !== 1) continue;
                $op = $this->limpo('amb_questionario_opcao', $o); unset($op['codpergunta'], $op['ativo']);
                $item['_opcoes'][strtoupper((string)$o['codigo'])] = $op;
            }
            ksort($item['_opcoes']);
            $c['perguntas'][strtoupper((string)$p['codigo'])] = $item;
        }
        if ($this->cols('amb_questionario_regra')) foreach (MigracaoCrud::selecionar($this->u, 'amb_questionario_regra', ['codversao' => $cv]) as $g) {
            if ((int)($g['ativo'] ?? 1) !== 1) continue;
            $item = $this->limpo('amb_questionario_regra', $g); unset($item['ativo']);
            $c['regras'][strtoupper((string)$g['codigo'])] = $item;
        }
        return $c;
    }

    /** Grava a versão e o conteúdo; devolve o codversao (chamar dentro de transação) */
    private function gravar(array $cab, array $conteudo): int
    {
        $cab = array_intersect_key($cab, array_flip($this->cols('amb_questionario_versao')));
        $cv = MigracaoCrud::inserir($this->u, 'amb_questionario_versao', $cab);
        if ($cv < 1) throw new RuntimeException('Não foi possível criar a versão.');
        $blocos = [];
        foreach ($conteudo['blocos'] as $cod => $b) {
            $d = $this->limpo('amb_questionario_bloco', $b); unset($d['codversao']);
            $blocos[$cod] = MigracaoCrud::inserir($this->u, 'amb_questionario_bloco', $d + ['codversao' => $cv, 'codigo' => (string)$cod, 'ativo' => 1]);
        }
        foreach ($conteudo['perguntas'] as $cod => $p) {
            $d = $this->limpo('amb_questionario_pergunta', $p);
            $d['codbloco'] = !empty($p['_bloco']) ? ($blocos[$p['_bloco']] ?? null) : null;
            $d['ativo'] = 1;
            $cp = MigracaoCrud::inserir($this->u, 'amb_questionario_pergunta', $d + ['codversao' => $cv, 'codigo' => (string)$cod]);
            foreach ($p['_opcoes'] ?? [] as $codO => $o)
                MigracaoCrud::inserir($this->u, 'amb_questionario_opcao', $this->limpo('amb_questionario_opcao', $o) + ['codpergunta' => $cp, 'codigo' => (string)$codO, 'ativo' => 1]);
        }
        foreach ($conteudo['regras'] as $cod => $g)
            MigracaoCrud::inserir($this->u, 'amb_questionario_regra', $this->limpo('amb_questionario_regra', $g) + ['codversao' => $cv, 'codigo' => (string)$cod, 'ativo' => 1]);
        return $cv;
    }
}
