<?php
declare(strict_types=1);

require_once __DIR__ . '/IaTaximetro.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GreenPortalDatabase.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'MenuService.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'IdiomaService.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ApplicationIdentity.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'InteligenciaArtificialAssistente.php';

/**
 * Assistente da aplicação ("Como posso ajudar?") — etapa 1.
 *
 * - Catálogo de funcionalidades: os menus com tela que ESTE usuário pode abrir (mesma regra do menu lateral,
 *   MenuService). A IA só recebe esse catálogo, então nunca sugere uma tela que o perfil não acessa.
 * - Conhecimento: ajuda de cada transação (guniversal_dbp.ajuda_transacoes) e o Guia do usuário publicado,
 *   escolhidos pelos termos da pergunta e pela tela aberta. Nada de registros do cliente vai para a IA.
 * - Pergunta: CPF, CNPJ, e-mail e telefone são retirados antes de gravar e de enviar.
 * - Tudo fica em ia_assistente_conversas / ia_assistente_mensagens, com a categoria da pergunta
 *   (base das etapas seguintes: solicitações de melhoria e inteligência de produto).
 */
final class AssistenteAplicacaoService
{
    public const PROMPT = 'ASSISTENTE_APLICACAO';
    /** Transação usada em Segurança › Permissões para liberar o assistente por perfil (migração 236). */
    public const TRANSACAO = 'ASSISTENTE_IA';
    private const CATEGORIAS = ['PERGUNTA', 'COMO_FAZER', 'ERRO', 'DIFICULDADE_USO', 'FUNCIONALIDADE_INEXISTENTE', 'MELHORIA', 'INTEGRACAO', 'RELATORIO', 'IA', 'OUTRO'];
    private const MAX_PERGUNTA = 1000;
    private const MAX_CONHECIMENTO = 7000;
    private const STOPWORDS = ['que', 'para', 'com', 'uma', 'como', 'por', 'dos', 'das', 'nos', 'nas', 'meu', 'minha', 'esta', 'este', 'essa', 'esse', 'isso',
        'qual', 'onde', 'quando', 'quero', 'preciso', 'posso', 'fazer', 'tela', 'sistema', 'the', 'and', 'how', 'what', 'where', 'can', 'para', 'los', 'las', 'del', 'una', 'cómo', 'donde'];

    private ?array $config = null;
    private ?array $catalogo = null;
    private ?PDO $universal = null;
    private bool $universalTentado = false;

    private ?array $guiasCache = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly array $usuario,
        private readonly array $permissoes,
        private readonly string $idioma,
    ) {}

    // ------------------------------------------------------------------ acesso

    /** Configuração da empresa (migração 225). Vazio quando a migração ainda não foi aplicada. */
    public function config(): array
    {
        if ($this->config !== null) return $this->config;
        try {
            $rows = array_column(self::chamarProcedure($this->pdo, 'sp_ia_assistente_config_obter'), 'valor', 'chave');
        } catch (Throwable) {
            return $this->config = [];
        }
        return $this->config = [
            'ativo' => (string)($rows['ativo'] ?? '1') === '1',
            'perfis' => array_values(array_filter(array_map([self::class, 'normalizarPerfil'], explode(',', (string)($rows['perfis_liberados'] ?? '')))))
                ?: ['ADMINISTRADOR'],
            'limite_diario' => max(1, (int)($rows['limite_diario'] ?? 40)),
            'historico' => max(0, min(12, (int)($rows['historico_mensagens'] ?? 6))),
            // PERMISSOES (migração 236): quem usa é definido em Segurança › Permissões, transação ASSISTENTE_IA (Visualizar).
            // PERFIS: lista perfis_liberados desta tabela (como na instalação, migração 225).
            'controle' => strtoupper((string)($rows['controle_acesso'] ?? 'PERFIS')) === 'PERMISSOES' ? 'PERMISSOES' : 'PERFIS',
        ];
    }

    public function liberado(): bool
    {
        $c = $this->config();
        if (!$c || empty($c['ativo'])) return false;
        if ($c['controle'] === 'PERMISSOES') {
            if (strtoupper((string)($this->usuario['codigo_perfil'] ?? '')) === 'ADMINISTRADOR') return true;
            foreach ($this->permissoes as $permissao) {
                if (strtoupper((string)($permissao['menu_codigo'] ?? '')) === self::TRANSACAO) return !empty($permissao['pode_visualizar']);
            }
            return false;
        }
        if (in_array('*', $c['perfis'], true)) return true;
        return in_array(self::normalizarPerfil((string)($this->usuario['codigo_perfil'] ?? '')), $c['perfis'], true);
    }

    public function usadosHoje(): int
    {
        return (int)(self::chamarProcedure($this->pdo, 'sp_ia_assistente_usados_hoje', [$this->codusuario()])[0]['usados'] ?? 0);
    }

    public static function normalizarPerfil(string $perfil): string
    {
        $perfil = trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', mb_strtoupper(trim($perfil), 'UTF-8'))) ?? '');
        return $perfil;
    }

    // ------------------------------------------------------------------ catálogo

    /**
     * Telas que o usuário pode abrir, indexadas pelo código do menu:
     * codigo, nome, caminho (Módulo › Grupo › Tela), url, aba (id da aba no portal) e transacao (nome do arquivo).
     */
    public function catalogo(): array
    {
        if ($this->catalogo !== null) return $this->catalogo;
        $arvore = (new MenuService($this->pdo, $this->idioma, $this->usuario, $this->permissoes))->carregar();
        $saida = [];
        $percorrer = static function (int $pai, array $caminho) use (&$percorrer, $arvore, &$saida): void {
            foreach ($arvore[$pai] ?? [] as $menu) {
                $nome = trim((string)($menu['_label'] ?? $menu['codigo']));
                $filhos = $arvore[(int)$menu['codmenu']] ?? [];
                $url = trim((string)($menu['url'] ?? ''));
                if (!$filhos && $url !== '' && preg_match('~^/[A-Za-z0-9_./-]+\.php(\?[A-Za-z0-9_=&.%-]*)?$~', $url)) {
                    $codigo = strtoupper((string)$menu['codigo']);
                    $saida[$codigo] = [
                        'codigo' => $codigo, 'nome' => $nome, 'caminho' => implode(' › ', [...$caminho, $nome]), 'url' => $url,
                        'aba' => (string)($menu['_id'] ?? 'menu-' . strtolower($codigo)),
                        'transacao' => strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME)),
                    ];
                }
                if ($filhos) $percorrer((int)$menu['codmenu'], [...$caminho, $nome]);
            }
        };
        $percorrer(0, []);
        return $this->catalogo = $saida;
    }

    /** Sugestões de perguntas para a tela aberta (exibidas ao abrir o painel). */
    public function sugestoes(string $transacao): array
    {
        $tela = $this->catalogo()[strtoupper($transacao)] ?? null;
        $t = fn(string $k, array $p, array $v = []) => IdiomaService::f('assistente.sugestao.' . $k, $p, $v);
        if ($tela) {
            return [
                $t('tela_para_que', ['pt' => 'Para que serve a tela {tela}?', 'en' => 'What is the {tela} screen for?', 'es' => '¿Para qué sirve la pantalla {tela}?'], ['tela' => $tela['nome']]),
                $t('tela_como_incluir', ['pt' => 'Como incluo um registro em {tela}?', 'en' => 'How do I add a record in {tela}?', 'es' => '¿Cómo incluyo un registro en {tela}?'], ['tela' => $tela['nome']]),
                $t('exportar', ['pt' => 'Como exporto esta lista para o Excel?', 'en' => 'How do I export this list to Excel?', 'es' => '¿Cómo exporto esta lista a Excel?']),
            ];
        }
        return [
            $t('o_que_posso', ['pt' => 'O que eu consigo fazer no sistema com o meu perfil?', 'en' => 'What can I do in the system with my profile?', 'es' => '¿Qué puedo hacer en el sistema con mi perfil?']),
            $t('onde_licencas', ['pt' => 'Onde vejo as licenças que estão vencendo?', 'en' => 'Where do I see licenses that are expiring?', 'es' => '¿Dónde veo las licencias que están por vencer?']),
            $t('anexar', ['pt' => 'Como anexo um documento a um registro?', 'en' => 'How do I attach a document to a record?', 'es' => '¿Cómo adjunto un documento a un registro?']),
        ];
    }

    // ------------------------------------------------------------------ conversa

    /** Última conversa aberta do usuário, com as mensagens (para reabrir o painel onde parou). */
    public function conversaAtual(): array
    {
        $cod = (int)(self::chamarProcedure($this->pdo, 'sp_ia_assistente_conversa_atual', [$this->codusuario()])[0]['codconversa'] ?? 0);
        return ['codconversa' => $cod ?: null, 'mensagens' => $cod ? $this->mensagens($cod) : []];
    }

    public function encerrar(int $codconversa): void
    {
        self::chamarProcedure($this->pdo, 'sp_ia_assistente_conversa_encerrar', [$codconversa, $this->codusuario()]);
    }

    public function avaliar(int $codmensagem, int $nota): bool
    {
        $nota = $nota > 0 ? 1 : ($nota < 0 ? -1 : 0);
        return (int)(self::chamarProcedure($this->pdo, 'sp_ia_assistente_avaliar', [$codmensagem, $this->codusuario(), $nota])[0]['afetados'] ?? 0) > 0;
    }

    /**
     * Responde a pergunta do usuário. $transacao é o código do menu da tela aberta (vazio na Home).
     * Lança RuntimeException com mensagem amigável quando a pergunta não pode ser enviada.
     */
    public function perguntar(string $pergunta, string $transacao, ?int $codconversa): array
    {
        $c = $this->config();
        $pergunta = self::anonimizar(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $pergunta) ?? ''));
        if ($pergunta === '') throw new RuntimeException(IdiomaService::t('assistente.erro.vazia', ['pt' => 'Escreva a sua pergunta.', 'en' => 'Type your question.', 'es' => 'Escriba su pregunta.']));
        if (mb_strlen($pergunta) > self::MAX_PERGUNTA) $pergunta = mb_substr($pergunta, 0, self::MAX_PERGUNTA);
        if ($this->usadosHoje() >= $c['limite_diario']) {
            throw new RuntimeException(IdiomaService::f('assistente.erro.limite', ['pt' => 'Você atingiu o limite de {n} perguntas por dia. Tente novamente amanhã.', 'en' => 'You reached the limit of {n} questions per day. Try again tomorrow.', 'es' => 'Alcanzó el límite de {n} preguntas por día. Inténtelo de nuevo mañana.'], ['n' => $c['limite_diario']]));
        }

        // Migração 340: limite mensal de IA da empresa (a mensagem do bloqueio vai direto para o usuário)
        IaTaximetro::exigirDisponivel($this->pdo);

        $catalogo = $this->catalogo();
        $transacao = strtoupper(trim($transacao));
        $tela = $catalogo[$transacao] ?? null;
        if (!$tela) $transacao = '';

        $codconversa = $this->garantirConversa($codconversa, $transacao);
        $historico = $c['historico'] > 0 ? array_slice($this->mensagens($codconversa), -$c['historico']) : [];
        $codPergunta = $this->gravar($codconversa, 'USUARIO', $pergunta, $transacao);

        $mensagens = [['role' => 'system', 'content' => $this->prompt() . "\n\n" . $this->contexto($pergunta, $tela, $catalogo)]];
        foreach ($historico as $m) {
            $mensagens[] = ['role' => $m['papel'] === 'USUARIO' ? 'user' : 'assistant', 'content' => (string)$m['texto']];
        }
        $mensagens[] = ['role' => 'user', 'content' => $pergunta];

        try {
            IaTaximetro::contexto($this->pdo, ['tipo' => 'ASSISTENTE', 'entidade' => 'ia_assistente_conversas', 'registro' => (string)$codconversa, 'codusuario' => $this->codusuario(), 'origem' => 'TELA']);
            $ia = InteligenciaArtificialAssistente::conversar($mensagens);
            IaTaximetro::limpar();
        } catch (Throwable $e) {
            IaTaximetro::limpar();
            error_log('AssistenteAplicacaoService: ' . $e->getMessage());
            $this->gravar($codconversa, 'ASSISTENTE', '', $transacao, ['erro' => mb_substr($e->getMessage(), 0, 500)]);
            // A pergunta sem resposta não volta na conversa nem vai como histórico na próxima pergunta.
            self::chamarProcedure($this->pdo, 'sp_ia_assistente_mensagem_sem_resposta', [$codPergunta, $this->codusuario()]);
            throw new RuntimeException(IdiomaService::t('assistente.erro.ia', ['pt' => 'Não consegui consultar a inteligência artificial agora. Tente novamente em instantes.', 'en' => 'I could not reach the artificial intelligence right now. Please try again shortly.', 'es' => 'No pude consultar la inteligencia artificial ahora. Inténtelo de nuevo en unos instantes.']));
        }

        $dados = $ia['dados'];
        $resposta = trim((string)($dados['resposta'] ?? ''));
        if ($resposta === '') $resposta = IdiomaService::t('assistente.sem_resposta', ['pt' => 'Não encontrei uma resposta para isso. Pode reformular a pergunta?', 'en' => 'I could not find an answer to that. Could you rephrase the question?', 'es' => 'No encontré una respuesta. ¿Puede reformular la pregunta?']);
        $resposta = mb_substr(self::anonimizar(strip_tags($resposta)), 0, 4000);
        $categoria = strtoupper(trim((string)($dados['categoria'] ?? '')));
        if (!in_array($categoria, self::CATEGORIAS, true)) $categoria = 'OUTRO';
        $confianca = is_numeric($dados['confianca'] ?? null) ? max(0, min(100, (float)$dados['confianca'])) : null;
        $inexistente = !empty($dados['funcionalidade_inexistente']);
        $necessidade = trim((string)($dados['necessidade'] ?? ''));
        $necessidade = $inexistente && $necessidade !== '' && strtolower($necessidade) !== 'null' ? mb_substr(self::anonimizar($necessidade), 0, 500) : null;

        // Só telas do catálogo do usuário, sem repetir, no máximo 3.
        $acoes = [];
        foreach ((array)($dados['acoes'] ?? []) as $acao) {
            $codigo = strtoupper(trim((string)(is_array($acao) ? ($acao['codigo'] ?? '') : $acao)));
            if ($codigo === '' || !isset($catalogo[$codigo]) || isset($acoes[$codigo])) continue;
            $acoes[$codigo] = $catalogo[$codigo];
            if (count($acoes) >= 3) break;
        }
        $acoes = array_values(array_map(static fn(array $a): array => ['tipo' => 'ABRIR_TELA', 'codigo' => $a['codigo'], 'nome' => $a['nome'], 'caminho' => $a['caminho'], 'url' => $a['url'], 'aba' => $a['aba']], $acoes));
        // Guias passo a passo sugeridos pela IA (só os que existem para o usuário), no máximo 2.
        $guiasValidos = $this->guiasInterativos($catalogo);
        $guias = [];
        foreach ((array)($dados['guias'] ?? []) as $g) {
            $g = strtolower(trim((string)(is_array($g) ? ($g['id'] ?? '') : $g)));
            if ($g === '' || !isset($guiasValidos[$g]) || isset($guias[$g])) continue;
            $guias[$g] = ['id' => $g, 'titulo' => $guiasValidos[$g]['titulo']];
            if (count($guias) >= 2) break;
        }
        $guias = array_values($guias);

        $codResposta = $this->gravar($codconversa, 'ASSISTENTE', $resposta, $transacao, [
            'categoria' => $categoria, 'confianca' => $confianca, 'acoes' => $acoes ? array_column($acoes, 'codigo') : null,
            'funcionalidade_inexistente' => $inexistente ? 1 : 0, 'necessidade' => $necessidade,
            'modelo' => mb_substr($ia['modelo'], 0, 60), 'tokens_entrada' => $ia['tokens_entrada'], 'tokens_saida' => $ia['tokens_saida'], 'duracao_ms' => $ia['duracao_ms'],
        ]);
        // A categoria vale também para a pergunta (é ela que as próximas etapas vão agrupar).
        self::chamarProcedure($this->pdo, 'sp_ia_assistente_mensagem_classificar', [$codPergunta, $this->codusuario(), $categoria, $inexistente ? 1 : 0, $necessidade]);

        return [
            'codconversa' => $codconversa, 'codmensagem' => $codResposta, 'resposta' => $resposta, 'categoria' => $categoria,
            'acoes' => $acoes, 'guias' => $guias, 'funcionalidade_inexistente' => $inexistente, 'necessidade' => $necessidade,
            'usados' => $this->usadosHoje(), 'limite' => $c['limite_diario'],
        ];
    }

    // ------------------------------------------------------------------ montagem do contexto

    private function contexto(string $pergunta, ?array $tela, array $catalogo): string
    {
        $linhas = [];
        $linhas[] = 'SISTEMA: ' . ApplicationIdentity::LABEL;
        $linhas[] = 'IDIOMA DA RESPOSTA: ' . (['pt' => 'português do Brasil', 'en' => 'English', 'es' => 'español'][$this->idioma] ?? 'português do Brasil');
        $linhas[] = 'PERFIL DO USUÁRIO: ' . trim((string)($this->usuario['perfil_nome'] ?? $this->usuario['codigo_perfil'] ?? ''));
        $linhas[] = 'TELA ABERTA: ' . ($tela ? $tela['caminho'] . ' (código ' . $tela['codigo'] . ')' : 'nenhuma (página inicial)');
        $linhas[] = '';
        $linhas[] = 'CATÁLOGO (código | caminho no menu):';
        foreach (array_slice($catalogo, 0, 300) as $item) $linhas[] = $item['codigo'] . ' | ' . $item['caminho'];
        $conhecimento = $this->conhecimento($pergunta, $tela, $catalogo);
        $linhas[] = '';
        $linhas[] = 'AJUDA:';
        if (!$conhecimento) $linhas[] = '(nenhum trecho de ajuda encontrado para esta pergunta)';
        foreach ($conhecimento as $trecho) $linhas[] = '- ' . $trecho['texto'];
        // Ajuda interativa (migração 309): guias que acompanham a pessoa na tela, passo a passo, com "Mostre-me".
        $guias = $this->guiasInterativos($catalogo);
        if ($guias) {
            $codigoTela = strtoupper((string)($tela['codigo'] ?? ''));
            uasort($guias, static fn(array $a, array $b): int => (int)in_array($codigoTela, $b['telas'], true) <=> (int)in_array($codigoTela, $a['telas'], true));
            $linhas[] = '';
            $linhas[] = 'GUIAS PASSO A PASSO (id | título | telas):';
            foreach (array_slice($guias, 0, 40) as $g) $linhas[] = $g['id'] . ' | ' . $g['titulo'] . ' | ' . implode(', ', $g['telas']);
            $linhas[] = 'COMO INSTRUTOR: ensine como um instrutor ao lado da pessoa — uma ação por passo, numerados, com o nome exato do botão ou campo em **negrito** e o que ela deve ver depois. '
                . 'Quando um GUIA PASSO A PASSO acima resolver a pergunta, inclua no JSON o campo "guias" com até 2 ids (ex.: "guias":["ml-avaliar-requisito"]) e diga que o botão "Começar o guia" acompanha a pessoa na tela, mostrando cada botão.';
        }
        return implode("\n", $linhas);
    }

    /** Guias passo a passo publicados (Administração › Guia do usuário), só das telas que o usuário pode abrir. */
    private function guiasInterativos(array $catalogo): array
    {
        if ($this->guiasCache !== null) return $this->guiasCache;
        $doc = null;
        $universal = $this->universal();
        if ($universal) {
            try { $row = self::chamarProcedure($universal, 'sp_guia_usuario_publicado_obter')[0] ?? null; $doc = $row ? json_decode((string)$row['conteudo'], true) : null; } catch (Throwable) { $doc = null; }
        }
        $l = fn(mixed $v): string => is_array($v) ? trim((string)(($v[$this->idioma] ?? '') !== '' ? $v[$this->idioma] : ($v['pt'] ?? ''))) : trim((string)$v);
        $modulos = is_array($doc) ? (array)($doc['modulos'] ?? []) : [];
        $tem = false;
        foreach ($modulos as $m) if (!empty($m['guias'])) $tem = true;
        if (!$tem) {
            $arquivo = dirname(__DIR__) . '/principal/guia_interativo_conteudo.php';
            if (is_file($arquivo)) {
                if (!defined('GREEN_GUIA_USUARIO')) define('GREEN_GUIA_USUARIO', true);
                $carga = require $arquivo;
                $modulos = [];
                foreach ((array)$carga as $id => $c) $modulos[] = ['id' => $id, 'guias' => $c['guias'] ?? []];
            }
        }
        $saida = [];
        foreach ($modulos as $m) {
            if (!is_array($m) || (array_key_exists('ativo', $m) && empty($m['ativo']))) continue;
            foreach ((array)($m['guias'] ?? []) as $g) {
                $id = (string)($g['id'] ?? '');
                if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,39}$/', $id)) continue;
                $telas = array_values(array_filter(array_map('strval', (array)($g['telas'] ?? [])), static fn(string $t): bool => (bool)preg_match('/^[A-Z0-9_]{2,100}$/', $t)));
                if ($telas && !array_filter($telas, static fn(string $t): bool => isset($catalogo[$t]))) continue; // telas de menu que o perfil não abre
                $saida[$id] = ['id' => $id, 'titulo' => $l($g['titulo'] ?? ''), 'telas' => $telas];
            }
        }
        return $this->guiasCache = $saida;
    }

    /** Trechos de ajuda mais próximos da pergunta, com prioridade para a tela aberta. */
    private function conhecimento(string $pergunta, ?array $tela, array $catalogo): array
    {
        $trechos = array_merge($this->trechosAjuda($catalogo), $this->trechosGuia($catalogo));
        if (!$trechos) return [];
        $termos = self::radicais($pergunta);
        $transacaoTela = $tela['transacao'] ?? null;
        $codigoTela = $tela['codigo'] ?? null;
        foreach ($trechos as &$trecho) {
            $comum = array_intersect_key($termos, $trecho['radicais']);
            $trecho['pontos'] = count($comum) * 2 + count(array_intersect_key($termos, $trecho['radicais_titulo'])) * 2;
            $daTela = ($transacaoTela !== null && $trecho['transacao'] === $transacaoTela) || ($codigoTela !== null && $trecho['codigo'] === $codigoTela);
            if ($daTela) $trecho['pontos'] += $trecho['tipo'] === 'ajuda' ? 6 : 3;
        }
        unset($trecho);
        $trechos = array_filter($trechos, static fn(array $t): bool => $t['pontos'] > 0);
        usort($trechos, static fn(array $a, array $b): int => $b['pontos'] <=> $a['pontos']);
        $saida = [];
        $total = 0;
        foreach ($trechos as $trecho) {
            $texto = $trecho['texto'];
            if ($total + mb_strlen($texto) > self::MAX_CONHECIMENTO) {
                $resto = self::MAX_CONHECIMENTO - $total;
                if ($resto < 300) break;
                $texto = mb_substr($texto, 0, $resto) . '…';
            }
            $saida[] = ['texto' => $texto];
            $total += mb_strlen($texto);
            if (count($saida) >= 12) break;
        }
        return $saida;
    }

    /** Ajuda das transações (a mesma do botão "?" de cada tela), só das telas que o usuário pode abrir ou que não são menu (telas internas). */
    private function trechosAjuda(array $catalogo): array
    {
        $universal = $this->universal();
        if (!$universal) return [];
        try {
            $menusUrl = [];
            foreach (array_column(self::chamarProcedure($universal, 'sp_assistente_menus_urls'), 'url') as $url) {
                $menusUrl[strtolower(pathinfo((string)parse_url((string)$url, PHP_URL_PATH), PATHINFO_FILENAME))] = true;
            }
            $rows = self::chamarProcedure($universal, 'sp_assistente_ajuda_transacoes', [$this->idioma]);
        } catch (Throwable $e) {
            error_log('AssistenteAplicacaoService ajuda: ' . $e->getMessage());
            return [];
        }
        $permitidas = [];
        foreach ($catalogo as $item) $permitidas[$item['transacao']][] = $item;
        $saida = [];
        foreach ($rows as $row) {
            $transacao = strtolower(trim((string)$row['transacao']));
            if (isset($menusUrl[$transacao]) && !isset($permitidas[$transacao])) continue; // tela de menu que o perfil não acessa
            $titulo = self::textoSimples((string)($row['titulo'] ?: $row['titulo_pt']));
            $texto = self::textoSimples((string)($row['texto'] ?: $row['texto_pt']));
            if ($texto === '') continue;
            $onde = isset($permitidas[$transacao]) ? ' (tela ' . $permitidas[$transacao][0]['caminho'] . ', código ' . $permitidas[$transacao][0]['codigo'] . ')' : '';
            $saida[] = $this->trecho('ajuda', $titulo . $onde . ': ' . mb_substr($texto, 0, 1800), $titulo, $transacao, $permitidas[$transacao][0]['codigo'] ?? null);
        }
        return $saida;
    }

    /** Guia do usuário publicado (Administração › Guia do usuário): resumos dos módulos, "Como fazer", menus e termos. */
    private function trechosGuia(array $catalogo): array
    {
        $universal = $this->universal();
        if (!$universal) return [];
        try {
            $row = self::chamarProcedure($universal, 'sp_guia_usuario_publicado_obter')[0] ?? null;
        } catch (Throwable) {
            return [];
        }
        $doc = $row ? json_decode((string)$row['conteudo'], true) : null;
        if (!is_array($doc)) return [];
        $l = fn(mixed $v): string => is_array($v) ? trim((string)(($v[$this->idioma] ?? '') !== '' ? $v[$this->idioma] : ($v['pt'] ?? ''))) : trim((string)$v);
        $permitido = static fn(string $codigo): bool => $codigo === '' || isset($catalogo[strtoupper($codigo)]);
        $saida = [];
        foreach ((array)($doc['modulos'] ?? []) as $modulo) {
            if (!is_array($modulo) || (array_key_exists('ativo', $modulo) && empty($modulo['ativo']))) continue;
            $nomeModulo = $l($modulo['nome'] ?? '');
            if (($resumo = $l($modulo['resumo'] ?? '')) !== '') $saida[] = $this->trecho('guia', 'Guia › ' . $nomeModulo . ': ' . $resumo, $nomeModulo, null, null);
            foreach ((array)($modulo['tarefas'] ?? []) as $tarefa) {
                $codigo = (string)($tarefa['transacao'] ?? '');
                if (!$permitido($codigo)) continue;
                $passos = [];
                foreach (array_values((array)($tarefa['passos'] ?? [])) as $i => $passo) if (($p = $l($passo)) !== '') $passos[] = ($i + 1) . '. ' . $p;
                $titulo = $l($tarefa['titulo'] ?? '');
                $saida[] = $this->trecho('guia', 'Guia › ' . $nomeModulo . ' › Como fazer: ' . $titulo . ($codigo !== '' ? ' (código ' . strtoupper($codigo) . ')' : '') . ' — ' . implode(' ', $passos), $titulo, null, $codigo !== '' ? strtoupper($codigo) : null);
            }
            foreach ((array)($modulo['menu'] ?? []) as $item) {
                $codigo = (string)($item['transacao'] ?? '');
                if (!$permitido($codigo)) continue;
                $caminho = $l($item['caminho'] ?? '');
                $saida[] = $this->trecho('guia', 'Guia › ' . $nomeModulo . ' › Menu ' . $caminho . ': ' . $l($item['texto'] ?? ''), $caminho, null, $codigo !== '' ? strtoupper($codigo) : null);
            }
            foreach ((array)($modulo['termos'] ?? []) as $item) {
                $termo = $l($item['termo'] ?? '');
                if ($termo === '') continue;
                $saida[] = $this->trecho('guia', 'Guia › ' . $nomeModulo . ' › ' . $termo . ': ' . $l($item['texto'] ?? ''), $termo, null, null);
            }
            foreach ((array)($modulo['fases'] ?? []) as $fase) {
                $nomeFase = $l($fase['nome'] ?? '');
                foreach ((array)($fase['passos'] ?? []) as $passo) {
                    $codigo = (string)($passo['transacao'] ?? '');
                    if (!$permitido($codigo)) continue;
                    $titulo = $l($passo['titulo'] ?? '');
                    $onde = $l($passo['onde'] ?? '');
                    $saida[] = $this->trecho('guia', 'Guia › ' . $nomeModulo . ' › ' . $nomeFase . ' › ' . $titulo . ': ' . $l($passo['texto'] ?? '') . ($onde !== '' ? ' Onde: ' . $onde . '.' : ''), $titulo, null, $codigo !== '' ? strtoupper($codigo) : null);
                }
            }
        }
        return $saida;
    }

    private function trecho(string $tipo, string $texto, string $titulo, ?string $transacao, ?string $codigo): array
    {
        return ['tipo' => $tipo, 'texto' => $texto, 'transacao' => $transacao, 'codigo' => $codigo, 'radicais' => self::radicais($texto), 'radicais_titulo' => self::radicais($titulo)];
    }

    // ------------------------------------------------------------------ apoio

    private function prompt(): string
    {
        try {
            $rows = GreenPortalDatabase::call($this->pdo, 'CALL sp_ia_prompt_obter(:codigo,:idioma)', ['codigo' => self::PROMPT, 'idioma' => $this->idioma]);
            $prompt = trim((string)($rows[0]['prompt'] ?? ''));
        } catch (Throwable) {
            $prompt = '';
        }
        if ($prompt === '') throw new RuntimeException(IdiomaService::t('assistente.erro.prompt', ['pt' => 'O prompt ASSISTENTE_APLICACAO não está configurado (rode a migração 225).', 'en' => 'The ASSISTENTE_APLICACAO prompt is not configured (run migration 225).', 'es' => 'El prompt ASSISTENTE_APLICACAO no está configurado (ejecute la migración 225).']));
        return $prompt;
    }

    private function garantirConversa(?int $codconversa, string $transacao): int
    {
        return (int)(self::chamarProcedure($this->pdo, 'sp_ia_assistente_conversa_garantir', [(int)$codconversa, $this->codusuario(), $this->idioma, $transacao])[0]['codconversa'] ?? 0);
    }

    private function gravar(int $codconversa, string $papel, string $texto, string $transacao, array $extra = []): int
    {
        $dados = $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null;
        return (int)(self::chamarProcedure($this->pdo, 'sp_ia_assistente_mensagem_gravar', [$codconversa, $this->codusuario(), $papel, $texto, $transacao, $dados])[0]['codmensagem'] ?? 0);
    }

    private function mensagens(int $codconversa): array
    {
        // A procedure já traz a solicitação de melhoria gerada por cada resposta (etapa 2, migração 230).
        $linhas = self::chamarProcedure($this->pdo, 'sp_ia_assistente_mensagens', [$codconversa, $this->codusuario()]);
        $catalogo = $this->catalogo();
        $saida = [];
        foreach ($linhas as $m) {
            $acoes = [];
            foreach ((array)json_decode((string)($m['acoes'] ?? '[]'), true) as $codigo) {
                $a = $catalogo[strtoupper((string)$codigo)] ?? null;
                if ($a) $acoes[] = ['tipo' => 'ABRIR_TELA', 'codigo' => $a['codigo'], 'nome' => $a['nome'], 'caminho' => $a['caminho'], 'url' => $a['url'], 'aba' => $a['aba']];
            }
            $saida[] = ['codmensagem' => (int)$m['codmensagem'], 'papel' => (string)$m['papel'], 'texto' => (string)$m['texto'], 'acoes' => $acoes, 'avaliacao' => $m['avaliacao'] === null ? null : (int)$m['avaliacao'],
                'categoria' => (string)($m['categoria'] ?? ''), 'funcionalidade_inexistente' => !empty($m['funcionalidade_inexistente']), 'necessidade' => (string)($m['necessidade'] ?? ''),
                'codsolicitacao' => !empty($m['codsolicitacao']) ? (int)$m['codsolicitacao'] : null];
        }
        return $saida;
    }

    private ?bool $solicitacoes = null;
    /** A etapa 2 (solicitações de melhoria, migração 230) está instalada nesta empresa? */
    public function solicitacoesDisponiveis(): bool
    {
        if ($this->solicitacoes !== null) return $this->solicitacoes;
        try {
            return $this->solicitacoes = !empty(self::chamarProcedure($this->pdo, 'sp_ia_assistente_recursos')[0]['solicitacoes']);
        } catch (Throwable) {
            return $this->solicitacoes = false;
        }
    }

    /**
     * Chama uma procedure (acesso ao banco somente por procedures e views, migração 246) e devolve as linhas.
     * Mensagens de regra do banco (SIGNAL 45000, erro 1644) viram RuntimeException com o texto para a tela.
     */
    public static function chamarProcedure(PDO $pdo, string $procedure, array $parametros = []): array
    {
        if (!preg_match('/^sp_[a-z0-9_]+$/', $procedure)) throw new InvalidArgumentException('Procedure inválida: ' . $procedure);
        try {
            $q = $pdo->prepare('CALL ' . $procedure . '(' . implode(', ', array_fill(0, count($parametros), '?')) . ')');
            $q->execute(array_values($parametros));
            $linhas = $q->columnCount() > 0 ? $q->fetchAll(PDO::FETCH_ASSOC) : [];
            $q->closeCursor();
            return $linhas;
        } catch (PDOException $e) {
            if (preg_match('/1644\s+(.+)$/su', $e->getMessage(), $m)) throw new RuntimeException(IdiomaService::mensagemBanco(trim($m[1])), 1644, $e);
            throw $e;
        }
    }

    private function universal(): ?PDO
    {
        if ($this->universalTentado) return $this->universal;
        $this->universalTentado = true;
        try {
            $env = GreenPortalDatabase::env();
            $this->universal = GreenPortalDatabase::connect($env, $env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp', 'GUNIVERSAL');
        } catch (Throwable $e) {
            error_log('AssistenteAplicacaoService universal: ' . $e->getMessage());
            $this->universal = null;
        }
        return $this->universal;
    }

    private function codusuario(): int
    {
        return (int)($this->usuario['codusuario'] ?? 0);
    }

    /** Retira dados pessoais óbvios do texto: CPF, CNPJ, e-mail e telefone. */
    public static function anonimizar(string $texto): string
    {
        $texto = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', '[e-mail]', $texto) ?? $texto;
        $texto = preg_replace('/\b\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}\b/u', '[CNPJ]', $texto) ?? $texto;
        $texto = preg_replace('/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/u', '[CPF]', $texto) ?? $texto;
        $texto = preg_replace('/(?<!\d)(?:\+?55\s?)?\(?\d{2}\)?[\s.-]?9?\d{4}[\s.-]?\d{4}(?!\d)/u', '[telefone]', $texto) ?? $texto;
        return $texto;
    }

    private static function textoSimples(string $html): string
    {
        $texto = html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</li>'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $texto) ?? '');
    }

    /** Radicais (6 primeiras letras, sem acento) das palavras relevantes, como chaves de um array. */
    private static function radicais(string $texto): array
    {
        $texto = mb_strtolower($texto, 'UTF-8');
        $semAcento = class_exists('Normalizer') ? preg_replace('/\p{Mn}/u', '', (string)Normalizer::normalize($texto, Normalizer::FORM_D)) : $texto;
        $texto = (string)($semAcento ?? $texto);
        $saida = [];
        foreach (preg_split('/[^a-z0-9]+/', strtr($texto, ['ç' => 'c'])) ?: [] as $palavra) {
            if (strlen($palavra) < 3 || in_array($palavra, self::STOPWORDS, true)) continue;
            $saida[substr($palavra, 0, 6)] = true;
        }
        return $saida;
    }
}
