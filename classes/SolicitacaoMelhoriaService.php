<?php
declare(strict_types=1);

require_once __DIR__ . '/IaTaximetro.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GreenPortalDatabase.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'IdiomaService.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ApplicationIdentity.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AssistenteAplicacaoService.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'InteligenciaArtificialAssistente.php';

/**
 * Solicitações de melhoria (etapa 2 do assistente da aplicação).
 *
 * - Quem pede: qualquer usuário (tela Minhas solicitações) ou quem usa o assistente (registro com confirmação no painel).
 * - Quem gere: perfis com acesso ao menu ADMINISTRACAO_SOLICITACOES_MELHORIA (ADMINISTRADOR e ADM DA EMPRESA),
 *   sempre dentro do banco da própria empresa.
 * - Cada mudança de situação vai para o histórico e gera um alerta para quem pediu.
 */
final class SolicitacaoMelhoriaService
{
    public const MENU_MINHAS = 'ADMINISTRACAO_MINHAS_SOLICITACOES';
    public const MENU_GESTAO = 'ADMINISTRACAO_SOLICITACOES_MELHORIA';
    public const PROMPT = 'SOLICITACAO_MELHORIA';
    public const STATUS = ['NOVA', 'EM_ANALISE', 'PLANEJADA', 'EM_DESENVOLVIMENTO', 'ENTREGUE', 'RECUSADA', 'DUPLICADA'];
    public const CATEGORIAS = ['FUNCIONALIDADE_INEXISTENTE', 'MELHORIA', 'INTEGRACAO', 'RELATORIO', 'IA', 'DIFICULDADE_USO', 'OUTRO'];
    public const PRIORIDADES = ['BAIXA', 'MEDIA', 'ALTA'];
    /** Categorias do assistente que permitem registrar uma solicitação a partir da resposta. */
    public const CATEGORIAS_REGISTRAVEIS = ['FUNCIONALIDADE_INEXISTENTE', 'MELHORIA', 'INTEGRACAO', 'RELATORIO', 'IA', 'DIFICULDADE_USO'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly array $usuario,
        private readonly array $permissoes,
        private readonly string $idioma,
    ) {}

    public static function rotulosStatus(): array
    {
        return [
            'NOVA' => IdiomaService::t('solicitacoes.status.NOVA', ['pt' => 'Nova', 'en' => 'New', 'es' => 'Nueva']),
            'EM_ANALISE' => IdiomaService::t('solicitacoes.status.EM_ANALISE', ['pt' => 'Em análise', 'en' => 'Under analysis', 'es' => 'En análisis']),
            'PLANEJADA' => IdiomaService::t('solicitacoes.status.PLANEJADA', ['pt' => 'Planejada', 'en' => 'Planned', 'es' => 'Planificada']),
            'EM_DESENVOLVIMENTO' => IdiomaService::t('solicitacoes.status.EM_DESENVOLVIMENTO', ['pt' => 'Em desenvolvimento', 'en' => 'In development', 'es' => 'En desarrollo']),
            'ENTREGUE' => IdiomaService::t('solicitacoes.status.ENTREGUE', ['pt' => 'Entregue', 'en' => 'Delivered', 'es' => 'Entregada']),
            'RECUSADA' => IdiomaService::t('solicitacoes.status.RECUSADA', ['pt' => 'Recusada', 'en' => 'Rejected', 'es' => 'Rechazada']),
            'DUPLICADA' => IdiomaService::t('solicitacoes.status.DUPLICADA', ['pt' => 'Duplicada', 'en' => 'Duplicate', 'es' => 'Duplicada']),
        ];
    }

    public static function rotulosCategoria(): array
    {
        return [
            'FUNCIONALIDADE_INEXISTENTE' => IdiomaService::t('solicitacoes.categoria.FUNCIONALIDADE_INEXISTENTE', ['pt' => 'Nova funcionalidade', 'en' => 'New feature', 'es' => 'Nueva funcionalidad']),
            'MELHORIA' => IdiomaService::t('solicitacoes.categoria.MELHORIA', ['pt' => 'Melhoria', 'en' => 'Improvement', 'es' => 'Mejora']),
            'INTEGRACAO' => IdiomaService::t('solicitacoes.categoria.INTEGRACAO', ['pt' => 'Integração', 'en' => 'Integration', 'es' => 'Integración']),
            'RELATORIO' => IdiomaService::t('solicitacoes.categoria.RELATORIO', ['pt' => 'Relatório', 'en' => 'Report', 'es' => 'Informe']),
            'IA' => IdiomaService::t('solicitacoes.categoria.IA', ['pt' => 'Inteligência artificial', 'en' => 'Artificial intelligence', 'es' => 'Inteligencia artificial']),
            'DIFICULDADE_USO' => IdiomaService::t('solicitacoes.categoria.DIFICULDADE_USO', ['pt' => 'Dificuldade de uso', 'en' => 'Usability issue', 'es' => 'Dificultad de uso']),
            'OUTRO' => IdiomaService::t('solicitacoes.categoria.OUTRO', ['pt' => 'Outro', 'en' => 'Other', 'es' => 'Otro']),
        ];
    }

    // ------------------------------------------------------------------ permissões

    public function podeGerir(bool $editar = false): bool
    {
        if (strtoupper((string)($this->usuario['codigo_perfil'] ?? '')) === 'ADMINISTRADOR') return true;
        foreach ($this->permissoes as $p) {
            if (strtoupper((string)($p['menu_codigo'] ?? '')) !== self::MENU_GESTAO) continue;
            return $editar ? !empty($p['pode_editar']) : !empty($p['pode_visualizar']);
        }
        return false;
    }

    /** Módulos (primeiro nível do menu) que o usuário enxerga, para o campo Módulo do formulário. */
    public function modulos(): array
    {
        $catalogo = (new AssistenteAplicacaoService($this->pdo, $this->usuario, $this->permissoes, $this->idioma))->catalogo();
        $modulos = [];
        foreach ($catalogo as $item) {
            $modulo = trim(explode(' › ', (string)$item['caminho'])[0] ?? '');
            if ($modulo !== '') $modulos[$modulo] = true;
        }
        $lista = array_keys($modulos);
        sort($lista, SORT_NATURAL | SORT_FLAG_CASE);
        return $lista;
    }

    // ------------------------------------------------------------------ registro

    /**
     * Rascunho de solicitação a partir de uma resposta do assistente: a IA redige título e descrição com base
     * na pergunta original. Nada é gravado aqui; o usuário revisa e confirma em registrar().
     */
    public function rascunhoDaMensagem(int $codmensagem): array
    {
        $origem = $this->mensagemOrigem($codmensagem);
        $existente = $this->solicitacaoDaMensagem($codmensagem);
        if ($existente) return ['ja_registrada' => true, 'codsolicitacao' => $existente];
        $tela = $origem['transacao'] !== '' ? ($this->catalogo()[$origem['transacao']] ?? null) : null;
        $categoria = in_array($origem['categoria'], self::CATEGORIAS, true) ? $origem['categoria'] : 'MELHORIA';
        $base = [
            'titulo' => mb_substr($origem['necessidade'] !== '' ? $origem['necessidade'] : $origem['pergunta'], 0, 200),
            'descricao' => $origem['pergunta'],
            'beneficio' => '',
            'categoria' => $categoria,
            'modulo' => $tela ? trim(explode(' › ', $tela['caminho'])[0]) : '',
            'prioridade_usuario' => 'MEDIA',
            'tela' => $tela['caminho'] ?? '',
        ];
        try {
            $prompt = $this->prompt();
            $entrada = "Perfil do usuário: " . trim((string)($this->usuario['perfil_nome'] ?? $this->usuario['codigo_perfil'] ?? ''))
                . "\nTela de origem: " . ($tela['caminho'] ?? 'não informada')
                . "\nCategoria identificada pelo assistente: " . $categoria
                . "\nO que o usuário pediu: " . $origem['pergunta']
                . ($origem['necessidade'] !== '' ? "\nResumo da necessidade feito pelo assistente: " . $origem['necessidade'] : '');
            // Migração 340: limite mensal de IA da empresa (bloqueado, o rascunho fica com o texto do usuário) e taxímetro
            IaTaximetro::exigirDisponivel($this->pdo);
            IaTaximetro::contexto($this->pdo, ['tipo' => 'SOLICITACAO_MELHORIA', 'entidade' => 'solicitacoes_melhoria', 'codusuario' => (int)($this->usuario['codusuario'] ?? 0), 'origem' => 'TELA']);
            try { $ia = InteligenciaArtificialAssistente::conversar([['role' => 'system', 'content' => $prompt], ['role' => 'user', 'content' => $entrada]], 600); }
            finally { IaTaximetro::limpar(); }
            $d = $ia['dados'];
            if (trim((string)($d['titulo'] ?? '')) !== '') $base['titulo'] = mb_substr(AssistenteAplicacaoService::anonimizar(strip_tags(trim((string)$d['titulo']))), 0, 200);
            if (trim((string)($d['descricao'] ?? '')) !== '') $base['descricao'] = mb_substr(AssistenteAplicacaoService::anonimizar(strip_tags(trim((string)$d['descricao']))), 0, 4000);
            if (trim((string)($d['beneficio'] ?? '')) !== '') $base['beneficio'] = mb_substr(AssistenteAplicacaoService::anonimizar(strip_tags(trim((string)$d['beneficio']))), 0, 500);
            $cat = strtoupper(trim((string)($d['categoria'] ?? '')));
            if (in_array($cat, self::CATEGORIAS, true)) $base['categoria'] = $cat;
        } catch (Throwable $e) {
            // Sem IA o rascunho usa a própria pergunta; o usuário pode ajustar antes de confirmar.
            error_log('SolicitacaoMelhoriaService rascunho: ' . $e->getMessage());
        }
        return ['ja_registrada' => false, 'rascunho' => $base];
    }

    /** Grava a solicitação confirmada pelo usuário. $codmensagem liga o pedido à resposta do assistente. */
    public function registrar(array $dados, string $origem, ?int $codmensagem = null): int
    {
        // CPF, CNPJ, e-mail e telefone saem também do texto revisado pelo usuário.
        $titulo = AssistenteAplicacaoService::anonimizar($this->texto($dados['titulo'] ?? '', 200));
        $descricao = AssistenteAplicacaoService::anonimizar($this->texto($dados['descricao'] ?? '', 4000));
        if ($titulo === '' || $descricao === '') {
            throw new RuntimeException(IdiomaService::t('solicitacoes.erro.obrigatorios', ['pt' => 'Informe o título e a descrição da solicitação.', 'en' => 'Fill in the request title and description.', 'es' => 'Informe el título y la descripción de la solicitud.']));
        }
        $categoria = strtoupper((string)($dados['categoria'] ?? 'MELHORIA'));
        if (!in_array($categoria, self::CATEGORIAS, true)) $categoria = 'MELHORIA';
        $prioridade = strtoupper((string)($dados['prioridade_usuario'] ?? 'MEDIA'));
        if (!in_array($prioridade, self::PRIORIDADES, true)) $prioridade = 'MEDIA';
        $modulo = $this->texto($dados['modulo'] ?? '', 150);
        if ($modulo !== '' && !in_array($modulo, $this->modulos(), true)) $modulo = '';
        $origemPergunta = null;
        $transacao = null;
        $confianca = null;
        if ($codmensagem) {
            if ($this->solicitacaoDaMensagem($codmensagem)) throw new RuntimeException(IdiomaService::t('solicitacoes.erro.ja_registrada', ['pt' => 'Esta sugestão já foi registrada.', 'en' => 'This suggestion was already registered.', 'es' => 'Esta sugerencia ya fue registrada.']));
            $m = $this->mensagemOrigem($codmensagem);
            $origemPergunta = $m['pergunta'];
            $transacao = $m['transacao'] ?: null;
            $confianca = $m['confianca'];
        } else {
            $transacao = preg_replace('/[^A-Z0-9_]/', '', strtoupper((string)($dados['transacao'] ?? ''))) ?: null;
            if ($transacao !== null && !isset($this->catalogo()[$transacao])) $transacao = null;
        }
        // Solicitação e primeiro registro do histórico gravados juntos pela procedure (migração 246).
        $cod = (int)(AssistenteAplicacaoService::chamarProcedure($this->pdo, 'sp_solicitacao_melhoria_registrar', [
            $this->codusuario(), $titulo, $descricao, $origemPergunta, AssistenteAplicacaoService::anonimizar($this->texto($dados['beneficio'] ?? '', 500)) ?: null,
            $modulo ?: null, $transacao, $categoria, $prioridade, $origem === 'ASSISTENTE' ? 'ASSISTENTE' : 'TELA', $codmensagem ?: null, $confianca, ApplicationIdentity::VERSION,
        ])[0]['codsolicitacao'] ?? 0);
        $this->auditar('REGISTRAR', $cod, ['origem' => $origem, 'categoria' => $categoria, 'codmensagem' => $codmensagem]);
        return $cod;
    }

    // ------------------------------------------------------------------ consulta

    public function minhas(): array
    {
        return array_map(fn(array $r): array => $this->formatar($r, false), AssistenteAplicacaoService::chamarProcedure($this->pdo, 'sp_solicitacoes_melhoria_minhas', [$this->codusuario()]));
    }

    public function listar(array $filtros): array
    {
        $status = strtoupper((string)($filtros['status'] ?? ''));
        if ($status !== 'ABERTAS' && !in_array($status, self::STATUS, true)) $status = '';
        $categoria = strtoupper((string)($filtros['categoria'] ?? ''));
        if (!in_array($categoria, self::CATEGORIAS, true)) $categoria = '';
        $busca = mb_substr(trim((string)($filtros['busca'] ?? '')), 0, 200);
        $itens = AssistenteAplicacaoService::chamarProcedure($this->pdo, 'sp_solicitacoes_melhoria_listar', [$status, $categoria, $busca]);
        $totais = array_fill_keys(self::STATUS, 0);
        foreach (AssistenteAplicacaoService::chamarProcedure($this->pdo, 'sp_solicitacoes_melhoria_totais') as $t) $totais[(string)$t['status']] = (int)$t['n'];
        return ['itens' => array_map(fn(array $r): array => $this->formatar($r, true), $itens), 'totais' => $totais];
    }

    public function detalhe(int $cod, bool $gestao): array
    {
        $r = AssistenteAplicacaoService::chamarProcedure($this->pdo, 'sp_solicitacao_melhoria_obter', [$cod])[0] ?? null;
        if (!$r || (!$gestao && (int)$r['codusuario'] !== $this->codusuario())) throw new RuntimeException(IdiomaService::t('solicitacoes.erro.nao_encontrada', ['pt' => 'Solicitação não encontrada.', 'en' => 'Request not found.', 'es' => 'Solicitud no encontrada.']));

        $rotulos = self::rotulosStatus();
        $historico = array_map(static fn(array $x): array => [
            'status' => (string)$x['status_novo'], 'status_rotulo' => $rotulos[$x['status_novo']] ?? $x['status_novo'], 'resposta' => (string)($x['resposta'] ?? ''),
            'escopo' => (string)($x['escopo'] ?? ''), 'quando' => (string)$x['criado_em'], 'por' => $gestao ? (string)($x['usuario_nome'] ?? '') : '',
        ], AssistenteAplicacaoService::chamarProcedure($this->pdo, 'sp_solicitacao_melhoria_historico', [$cod]));
        return $this->formatar($r, $gestao) + ['historico' => $historico];
    }

    // ------------------------------------------------------------------ gestão

    public function atualizar(int $cod, array $dados): array
    {
        $atual = AssistenteAplicacaoService::chamarProcedure($this->pdo, 'sp_solicitacao_melhoria_obter', [$cod])[0] ?? null;
        if (!$atual) throw new RuntimeException(IdiomaService::t('solicitacoes.erro.nao_encontrada', ['pt' => 'Solicitação não encontrada.', 'en' => 'Request not found.', 'es' => 'Solicitud no encontrada.']));
        $status = strtoupper((string)($dados['status'] ?? $atual['status']));
        if (!in_array($status, self::STATUS, true)) $status = (string)$atual['status'];
        $escopo = strtoupper((string)($dados['escopo'] ?? $atual['escopo'])) === 'PRODUTO' ? 'PRODUTO' : 'CLIENTE';
        $resposta = $this->texto($dados['resposta'] ?? '', 2000);
        $original = null;
        if ($status === 'DUPLICADA') {
            $original = (int)($dados['codsolicitacao_original'] ?? 0);
            // A existência da solicitação original é conferida também pela procedure de atualização.
            if ($original < 1 || $original === $cod) throw new RuntimeException(IdiomaService::t('solicitacoes.erro.original', ['pt' => 'Informe o número da solicitação que já trata deste assunto.', 'en' => 'Enter the number of the request that already covers this subject.', 'es' => 'Informe el número de la solicitud que ya trata este asunto.']));
        }
        if (in_array($status, ['RECUSADA', 'DUPLICADA'], true) && $resposta === '' && $status !== $atual['status']) {
            throw new RuntimeException(IdiomaService::t('solicitacoes.erro.resposta', ['pt' => 'Escreva uma resposta para quem pediu explicando o motivo.', 'en' => 'Write a reply to the requester explaining why.', 'es' => 'Escriba una respuesta a quien pidió explicando el motivo.']));
        }
        $mudouStatus = $status !== $atual['status'];
        $mudouEscopo = $escopo !== $atual['escopo'];
        if (!$mudouStatus && !$mudouEscopo && $resposta === '') return $this->detalhe($cod, true);

        // Situação, escopo, resposta e histórico gravados juntos pela procedure (migração 246).
        AssistenteAplicacaoService::chamarProcedure($this->pdo, 'sp_solicitacao_melhoria_atualizar', [$cod, (string)$atual['status'], $status, $escopo, $status === 'DUPLICADA' ? $original : null, $resposta, $this->codusuario()]);
        $this->auditar('ATUALIZAR', $cod, ['de' => $atual['status'], 'para' => $status, 'escopo' => $escopo]);

        // Quem pediu é avisado quando a situação muda ou recebe uma resposta (a mudança de escopo é interna).
        if (($mudouStatus || $resposta !== '') && (int)$atual['codusuario'] > 0) {
            $rotulo = self::rotulosStatus()[$status] ?? $status;
            $titulo = IdiomaService::f('solicitacoes.alerta.titulo', ['pt' => 'Sua solicitação #{n}: {status}', 'en' => 'Your request #{n}: {status}', 'es' => 'Su solicitud #{n}: {status}'], ['n' => $cod, 'status' => $rotulo]);
            $mensagem = mb_substr((string)$atual['titulo'], 0, 200) . ($resposta !== '' ? ' — ' . $resposta : '');
            try {
                AssistenteAplicacaoService::chamarProcedure($this->pdo, 'sp_alerta_registrar', [(int)$atual['codusuario'], mb_substr($titulo, 0, 180), $mensagem, '/principal/minhas_solicitacoes.php?id=' . $cod]);
            } catch (Throwable $e) { error_log('SolicitacaoMelhoriaService alerta: ' . $e->getMessage()); }
        }
        return $this->detalhe($cod, true);
    }

    /**
     * Sinais do assistente nos últimos $dias: respostas marcadas como "não ajudou", dificuldades de uso, erros e
     * funcionalidades inexistentes que ninguém registrou como solicitação. Mostra a pergunta (já sem dados pessoais).
     */
    public function sinais(int $dias = 30): array
    {
        $dias = max(1, min(365, $dias));
        try {
            $linhas = AssistenteAplicacaoService::chamarProcedure($this->pdo, 'sp_ia_assistente_sinais', [$dias]);
            $r = AssistenteAplicacaoService::chamarProcedure($this->pdo, 'sp_ia_assistente_sinais_resumo', [$dias])[0] ?? [];
        } catch (Throwable $e) {
            error_log('SolicitacaoMelhoriaService sinais: ' . $e->getMessage());
            return ['itens' => [], 'resumo' => ['total' => 0, 'uteis' => 0, 'nao_uteis' => 0, 'usuarios' => 0]];
        }
        $telas = $this->nomesTelas();
        $itens = [];
        foreach ($linhas as $l) {
            $motivo = (int)$l['avaliacao'] === -1 ? 'NAO_AJUDOU' : ((int)$l['funcionalidade_inexistente'] === 1 ? 'INEXISTENTE' : (string)$l['categoria']);
            if ($motivo === 'INEXISTENTE' && $l['codsolicitacao']) continue; // já virou solicitação
            $itens[] = [
                'codmensagem' => (int)$l['codmensagem'], 'quando' => (string)$l['criado_em'], 'usuario' => (string)($l['usuario_nome'] ?? ''),
                'pergunta' => (string)($l['pergunta'] ?? ''), 'necessidade' => (string)($l['necessidade'] ?? ''), 'motivo' => $motivo,
                'categoria' => (string)($l['categoria'] ?? ''), 'tela' => $telas[(string)$l['transacao']] ?? (string)($l['transacao'] ?? ''),
            ];
        }
        return ['itens' => $itens, 'resumo' => ['total' => (int)($r['total'] ?? 0), 'uteis' => (int)($r['uteis'] ?? 0), 'nao_uteis' => (int)($r['nao_uteis'] ?? 0), 'usuarios' => (int)($r['usuarios'] ?? 0)]];
    }

    // ------------------------------------------------------------------ apoio

    private function formatar(array $r, bool $gestao): array
    {
        $status = self::rotulosStatus();
        $categorias = self::rotulosCategoria();
        $telas = $this->nomesTelas();
        $saida = [
            'codsolicitacao' => (int)$r['codsolicitacao'], 'titulo' => (string)$r['titulo'], 'descricao' => (string)$r['descricao'],
            'beneficio' => (string)($r['beneficio'] ?? ''), 'modulo' => (string)($r['modulo'] ?? ''), 'tela' => $telas[(string)($r['transacao'] ?? '')] ?? '',
            'categoria' => (string)$r['categoria'], 'categoria_rotulo' => $categorias[$r['categoria']] ?? $r['categoria'],
            'prioridade_usuario' => (string)$r['prioridade_usuario'], 'origem' => (string)$r['origem'],
            'status' => (string)$r['status'], 'status_rotulo' => $status[$r['status']] ?? $r['status'],
            'resposta' => (string)($r['resposta'] ?? ''), 'codsolicitacao_original' => $r['codsolicitacao_original'] ? (int)$r['codsolicitacao_original'] : null,
            'criado_em' => (string)$r['criado_em'], 'atualizado_em' => (string)$r['atualizado_em'],
        ];
        if ($gestao) $saida += ['usuario' => (string)($r['usuario_nome'] ?? ''), 'escopo' => (string)$r['escopo'], 'descricao_original' => (string)($r['descricao_original'] ?? '')];
        return $saida;
    }

    /** Pergunta, categoria e tela de uma resposta do assistente que pertence ao usuário logado. */
    private function mensagemOrigem(int $codmensagem): array
    {
        $m = AssistenteAplicacaoService::chamarProcedure($this->pdo, 'sp_ia_assistente_mensagem_origem', [$codmensagem, $this->codusuario()])[0] ?? null;
        if (!$m) throw new RuntimeException(IdiomaService::t('solicitacoes.erro.mensagem', ['pt' => 'Resposta do assistente não encontrada.', 'en' => 'Assistant answer not found.', 'es' => 'Respuesta del asistente no encontrada.']));
        return ['pergunta' => (string)($m['pergunta'] ?? ''), 'categoria' => strtoupper((string)($m['categoria'] ?? '')), 'necessidade' => (string)($m['necessidade'] ?? ''),
            'transacao' => strtoupper((string)($m['transacao'] ?? '')), 'confianca' => $m['confianca'] !== null ? (float)$m['confianca'] : null];
    }

    public function solicitacaoDaMensagem(int $codmensagem): ?int
    {
        $cod = (int)(AssistenteAplicacaoService::chamarProcedure($this->pdo, 'sp_solicitacao_melhoria_da_mensagem', [$codmensagem])[0]['codsolicitacao'] ?? 0);
        return $cod > 0 ? $cod : null;
    }

    private ?array $catalogo = null;
    private function catalogo(): array
    {
        return $this->catalogo ??= (new AssistenteAplicacaoService($this->pdo, $this->usuario, $this->permissoes, $this->idioma))->catalogo();
    }

    private ?array $telas = null;
    /** Nome das telas (todas, não só as do usuário) para exibir a origem na gestão. */
    private function nomesTelas(): array
    {
        if ($this->telas !== null) return $this->telas;
        $this->telas = [];
        try {
            $env = GreenPortalDatabase::env();
            $u = GreenPortalDatabase::connect($env, $env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp', 'GUNIVERSAL');
            foreach (AssistenteAplicacaoService::chamarProcedure($u, 'sp_menus_nomes_telas', [in_array($this->idioma, ['pt', 'en', 'es'], true) ? $this->idioma : 'pt']) as $m) {
                $this->telas[strtoupper((string)$m['codigo'])] = trim(($m['pai'] ? $m['pai'] . ' › ' : '') . $m['nome']);
            }
        } catch (Throwable $e) { error_log('SolicitacaoMelhoriaService telas: ' . $e->getMessage()); }
        return $this->telas;
    }

    private function prompt(): string
    {
        $rows = GreenPortalDatabase::call($this->pdo, 'CALL sp_ia_prompt_obter(:codigo,:idioma)', ['codigo' => self::PROMPT, 'idioma' => $this->idioma]);
        $prompt = trim((string)($rows[0]['prompt'] ?? ''));
        if ($prompt === '') throw new RuntimeException('Prompt SOLICITACAO_MELHORIA não configurado (migração 230).');
        return $prompt;
    }

    private function texto(mixed $valor, int $limite): string
    {
        $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', strip_tags((string)$valor)) ?? '';
        return mb_substr(trim($texto), 0, $limite);
    }

    private function auditar(string $acao, int $cod, array $detalhes): void
    {
        try {
            AssistenteAplicacaoService::chamarProcedure($this->pdo, 'sp_auditoria_registrar', [$this->codusuario(), self::MENU_GESTAO, $acao, 'solicitacoes_melhoria', (string)$cod, 'SUCESSO', json_encode($detalhes, JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) { error_log('SolicitacaoMelhoriaService auditoria: ' . $e->getMessage()); }
    }

    private function codusuario(): int
    {
        return (int)($this->usuario['codusuario'] ?? 0);
    }
}
