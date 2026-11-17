<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';
require_once __DIR__ . '/IdiomaService.php';

/*
 * Perfil de atribuições do usuário (migração 268).
 *
 * O questionário PERFIL_ATRIBUICOES fica no catálogo universal de questionários (guniversal_dbp, amb_questionario_*, versão
 * PUBLICADA). Cada bloco corresponde a um módulo: GERAL (todos) e os módulos comerciais (MODULO_LEGAL, HOMOLOGACAO_FORNECEDORES,
 * NAO_CONFORMIDADES). O usuário responde o bloco do módulo só quando a empresa tem o módulo e o perfil de acesso dele vê alguma
 * tela desse módulo.
 *
 * Convenção do código da pergunta (vira item estruturado em perfil_atribuicoes_itens):
 *   <BLOCO>.PILAR.<PILAR>  opções EXECUTA / APROVA / ACOMPANHA   → tipo PILAR (modulo, pilar, papel)
 *   <BLOCO>.TEMAS          opções = temas                        → tipo TEMA (valor)
 *   <BLOCO>.CATEGORIAS     opções = categorias                   → tipo CATEGORIA (valor)
 *   GERAL.UNIDADES         opções = unidades da empresa (dinâmico) → tipo UNIDADE (codunidade)
 *   demais                                                       → tipo RESPOSTA (pilar = código da pergunta, valor)
 * Só responde quem tem, no perfil de acesso principal, "Aplicar questionário" marcado (perfis.aplicar_questionario).
 *
 * Situação do usuário (precisa responder?): nunca respondeu, devolvido pelo gestor, revisão periódica vencida (valido_ate),
 * questionário publicado em versão mais nova ou um bloco novo (módulo / acesso novo). Pendente de aprovação: aguarda o gestor.
 * Leitura por views (vw_perfil_atribuicoes_*, vw_amb_questionario_*); gravação por procedures (sp_perfil_atrib_*).
 */
final class PerfilAtribuicoesService
{
    public const QUESTIONARIO = 'PERFIL_ATRIBUICOES';
    public const MESES_REVISAO = 12;
    public const MODULOS = ['MODULO_LEGAL', 'HOMOLOGACAO_FORNECEDORES', 'NAO_CONFORMIDADES'];
    public const PAPEIS = ['EXECUTA', 'APROVA', 'ACOMPANHA'];

    /** Catálogos já montados, por codversao */
    private array $catalogos = [];

    public function __construct(private PDO $tenant, private PDO $universal) {}

    public static function universal(): PDO
    {
        $env = GreenPortalDatabase::env();
        return GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
    }

    private function linhas(PDO $pdo, string $sql, array $p = []): array
    {
        $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r;
    }

    private function texto(array $row, string $prefixo): string
    {
        $idioma = IdiomaService::idioma();
        $v = trim((string)($row[$prefixo . '_' . $idioma] ?? ''));
        return $v !== '' ? $v : (string)($row[$prefixo . '_pt'] ?? '');
    }

    /**
     * Versão publicada em uso pela empresa (migração 291): a versão própria da empresa, senão a universal.
     * Sem a migração 291, a universal (vw_amb_questionario_catalogo).
     */
    private function cabecalhoVigente(): ?array
    {
        $empresa = (int)($_SESSION['tenant']['codempresa'] ?? 0);
        try {
            $cab = $this->linhas($this->universal, 'SELECT codquestionario, codversao, versao, codempresa, nome_pt, nome_en, nome_es FROM vw_amb_questionario_publicadas
                WHERE codigo = ? AND ativo = 1 AND codempresa IN (0, ?) ORDER BY codempresa DESC, versao DESC LIMIT 1', [self::QUESTIONARIO, $empresa]);
        } catch (Throwable) {
            $cab = $this->linhas($this->universal, 'SELECT codquestionario, codversao, versao, nome_pt, nome_en, nome_es FROM vw_amb_questionario_catalogo
                WHERE codigo = ? AND ativo = 1 AND codversao IS NOT NULL ORDER BY versao DESC LIMIT 1', [self::QUESTIONARIO]);
        }
        return $cab[0] ?? null;
    }

    /** Versão em uso pela empresa, com blocos, perguntas e opções (unidades preenchidas da empresa). */
    public function catalogo(): ?array
    {
        $cab = $this->cabecalhoVigente();
        return $cab ? $this->catalogoVersao((int)$cab['codversao'], $cab) : null;
    }

    /**
     * Uma versão específica (a que o usuário respondeu): o gestor consulta o perfil pela versão do envio, mesmo que a
     * empresa já use uma versão mais nova (versões nunca são apagadas).
     */
    public function catalogoVersao(int $codversao, ?array $cab = null): ?array
    {
        if (isset($this->catalogos[$codversao])) return $this->catalogos[$codversao] ?: null;
        if ($cab === null) {
            try {
                $cab = $this->linhas($this->universal, 'SELECT codquestionario, codversao, versao, nome_pt, nome_en, nome_es FROM vw_amb_questionario_versoes WHERE codversao = ?', [$codversao])[0] ?? null;
            } catch (Throwable) { $cab = null; }
            if (!$cab) {   // sem a migração 291
                $cab = $this->linhas($this->universal, 'SELECT codquestionario, codversao, versao, nome_pt, nome_en, nome_es FROM vw_amb_questionario_catalogo WHERE codversao = ?', [$codversao])[0] ?? null;
            }
            if (!$cab) { $this->catalogos[$codversao] = []; return null; }
        }
        $blocos = [];
        foreach ($this->linhas($this->universal, 'SELECT codbloco, codigo, titulo_pt, titulo_en, titulo_es, ordem FROM vw_amb_questionario_blocos
            WHERE codversao = ? AND ativo = 1 ORDER BY ordem, codbloco', [(int)$cab['codversao']]) as $b) {
            $blocos[(int)$b['codbloco']] = ['codigo' => strtoupper((string)$b['codigo']), 'titulo' => $this->texto($b, 'titulo'), 'perguntas' => []];
        }
        $perguntas = [];
        foreach ($this->linhas($this->universal, 'SELECT codpergunta, codbloco, codigo, texto_pt, texto_en, texto_es, tipo, obrigatoria, ordem,
                codigo_opcao, rotulo_pt, rotulo_en, rotulo_es, ordem_opcao
            FROM vw_amb_questionario_perguntas WHERE codversao = ? ORDER BY ordem, codpergunta, ordem_opcao', [(int)$cab['codversao']]) as $r) {
            $id = (int)$r['codpergunta'];
            if (!isset($perguntas[$id])) {
                $perguntas[$id] = ['bloco' => (int)$r['codbloco'], 'codigo' => strtoupper((string)$r['codigo']), 'texto' => $this->texto($r, 'texto'),
                    'tipo' => (string)$r['tipo'], 'obrigatoria' => (int)$r['obrigatoria'] === 1, 'opcoes' => []];
            }
            if ($r['codigo_opcao'] !== null) $perguntas[$id]['opcoes'][] = ['codigo' => strtoupper((string)$r['codigo_opcao']), 'rotulo' => $this->texto($r, 'rotulo')];
        }
        foreach ($perguntas as $p) {
            if (!isset($blocos[$p['bloco']])) continue;
            if ($p['codigo'] === 'GERAL.UNIDADES') $p['opcoes'] = $this->unidades();
            $blocos[$p['bloco']]['perguntas'][] = $p;
        }
        return $this->catalogos[$codversao] = ['codquestionario' => (int)$cab['codquestionario'], 'codversao' => (int)$cab['codversao'], 'versao' => (int)$cab['versao'],
            'nome' => $this->texto($cab, 'nome'), 'blocos' => array_values(array_filter($blocos, static fn($b) => $b['perguntas']))];
    }

    private function unidades(): array
    {
        return array_map(static fn($u) => ['codigo' => (string)(int)$u['CODUNIDADE'], 'rotulo' => (string)$u['DSCUNIDADE']],
            $this->linhas($this->tenant, 'SELECT CODUNIDADE, DSCUNIDADE FROM vw_unidades_grid WHERE CODUNIDADE IS NOT NULL ORDER BY DSCUNIDADE'));
    }

    /** Módulos que o usuário responde: GERAL + módulos da empresa que o perfil de acesso dele usa. */
    public function blocosDoUsuario(array $sessao): array
    {
        $admin = strtoupper((string)($sessao['usuario']['codigo_perfil'] ?? '')) === 'ADMINISTRADOR';
        $empresa = (int)($sessao['tenant']['codempresa'] ?? 0);
        try {
            $daEmpresa = array_map('strtoupper', array_column($this->linhas($this->universal,
                'SELECT codigo_modulo FROM vw_perfil_atrib_empresa_modulos WHERE codempresa = ?', [$empresa]), 'codigo_modulo'));
        } catch (Throwable) { $daEmpresa = self::MODULOS; }   // sem o controle de módulos por empresa (migração 265): todos
        // Raiz de cada menu (o módulo é a raiz da árvore; Manutenção Legal = Módulo Legal)
        $menus = [];
        foreach ($this->linhas($this->universal, 'SELECT codmenu, codmenu_pai, codigo FROM vw_perfil_atrib_menus') as $m) $menus[(int)$m['codmenu']] = $m;
        $porCodigo = [];
        foreach ($menus as $m) $porCodigo[strtoupper((string)$m['codigo'])] = $m;
        $moduloDe = static function (string $codigo) use ($menus, $porCodigo): ?string {
            $atual = $porCodigo[strtoupper($codigo)] ?? null; $guarda = 0;
            while ($atual && $guarda++ < 50) {
                $c = strtoupper((string)$atual['codigo']);
                if ($c === 'MANUTENCAO_LEGAL') return 'MODULO_LEGAL';
                if (in_array($c, self::MODULOS, true)) return $c;
                $atual = $menus[(int)($atual['codmenu_pai'] ?? 0)] ?? null;
            }
            return null;
        };
        $usados = [];
        foreach ((array)($sessao['permissoes'] ?? []) as $p) {
            if (empty($p['pode_visualizar'])) continue;
            $m = $moduloDe((string)($p['menu_codigo'] ?? ''));
            if ($m !== null) $usados[$m] = true;
        }
        $blocos = ['GERAL'];
        foreach (self::MODULOS as $m) if (in_array($m, $daEmpresa, true) && ($admin || isset($usados[$m]))) $blocos[] = $m;
        return $blocos;
    }

    /** Catálogo filtrado para o usuário (só os blocos que ele responde). */
    public function questionarioDoUsuario(array $sessao): ?array
    {
        $cat = $this->catalogo();
        if (!$cat) return null;
        $blocos = $this->blocosDoUsuario($sessao);
        $cat['blocos'] = array_values(array_filter($cat['blocos'], static fn($b) => in_array($b['codigo'], $blocos, true)));
        $cat['modulos'] = array_column($cat['blocos'], 'codigo');
        return $cat;
    }

    /** Situação do usuário: precisa responder? por quê? último envio e perfil vigente. */
    public function situacao(array $sessao): array
    {
        $usuario = (int)($sessao['usuario']['codusuario'] ?? 0);
        $q = $this->questionarioDoUsuario($sessao);
        $s = $this->linhas($this->tenant, 'SELECT * FROM vw_perfil_atribuicoes_situacao WHERE codusuario = ?', [$usuario])[0] ?? [];
        $aplica = (int)($s['aplicar_questionario'] ?? 1) === 1;
        $r = ['disponivel' => (bool)$q && $aplica, 'aplica' => $aplica, 'precisa_responder' => false, 'motivo' => null, 'ultimo_status' => $s['ultimo_status'] ?? null,
            'ultimo_motivo' => $s['ultimo_motivo'] ?? null, 'valido_ate' => $s['valido_ate'] ?? null, 'gestor_nome' => $s['gestor_nome'] ?? null,
            'tem_gestor' => !empty($s['codgestor']), 'ultimo_codperfilatrib' => isset($s['ultimo_codperfilatrib']) ? (int)$s['ultimo_codperfilatrib'] : null];
        if (!$q || !$aplica) return $r;
        $motivo = null;
        $vigente = !empty($s['vigente_codperfilatrib']);
        if (($s['ultimo_status'] ?? '') === 'PENDENTE_APROVACAO') $motivo = null;                 // aguarda o gestor
        elseif (($s['ultimo_status'] ?? '') === 'DEVOLVIDO') $motivo = 'DEVOLVIDO';
        elseif (!$vigente) $motivo = 'NUNCA_RESPONDEU';
        elseif (!empty($s['valido_ate']) && (string)$s['valido_ate'] < date('Y-m-d')) $motivo = 'REVISAO_PERIODICA';
        // Versão nova do questionário NÃO obriga a responder de novo: o perfil aprovado continua valendo na versão em que
        // foi respondido; a versão nova entra na próxima resposta (revisão periódica, devolução ou novo módulo).
        elseif (array_diff($q['modulos'], array_filter(explode(',', strtoupper((string)($s['vigente_modulos'] ?? '')))))) $motivo = 'NOVO_MODULO';
        $r['precisa_responder'] = $motivo !== null;
        $r['motivo'] = $motivo;
        return $r;
    }

    /** Respostas para preencher o formulário: último envio (pendente / devolvido) ou o perfil vigente. */
    public function respostasAtuais(int $usuario): array
    {
        $s = $this->linhas($this->tenant, 'SELECT ultimo_codperfilatrib, vigente_codperfilatrib FROM vw_perfil_atribuicoes_situacao WHERE codusuario = ?', [$usuario])[0] ?? [];
        $id = (int)($s['ultimo_codperfilatrib'] ?? 0) ?: (int)($s['vigente_codperfilatrib'] ?? 0);
        if (!$id) return [];
        $r = $this->linhas($this->tenant, 'SELECT respostas FROM vw_perfil_atribuicoes_grid WHERE codperfilatrib = ?', [$id])[0]['respostas'] ?? null;
        $d = json_decode((string)$r, true);
        return is_array($d) ? $d : [];
    }

    /** Valida as respostas contra o catálogo do usuário, monta os itens e grava (sp_perfil_atrib_enviar). */
    public function enviar(array $sessao, array $respostas): array
    {
        $usuario = (int)($sessao['usuario']['codusuario'] ?? 0);
        $q = $this->questionarioDoUsuario($sessao);
        if (!$q) throw new RuntimeException('QUESTIONARIO_INDISPONIVEL');
        $limpas = []; $itens = []; $faltando = [];
        foreach ($q['blocos'] as $b) {
            foreach ($b['perguntas'] as $p) {
                $cod = $p['codigo']; $valor = $respostas[$cod] ?? null;
                $permitidas = array_column($p['opcoes'], 'codigo');
                if (in_array($p['tipo'], ['MULTIPLA_ESCOLHA'], true)) {
                    $valor = array_values(array_unique(array_filter(array_map(static fn($v) => strtoupper(trim((string)$v)), (array)$valor), static fn($v) => $v !== '' && in_array($v, $permitidas, true))));
                    $vazio = !$valor;
                } elseif (in_array($p['tipo'], ['UNICA_ESCOLHA', 'SIM_NAO'], true)) {
                    $valor = strtoupper(trim((string)(is_array($valor) ? ($valor[0] ?? '') : $valor)));
                    if ($p['tipo'] === 'SIM_NAO' && !in_array($valor, ['SIM', 'NAO'], true)) $valor = '';
                    if ($p['tipo'] === 'UNICA_ESCOLHA' && !in_array($valor, $permitidas, true)) $valor = '';
                    $vazio = $valor === '';
                } else {
                    $valor = mb_substr(trim((string)(is_array($valor) ? '' : $valor)), 0, 500);
                    $vazio = $valor === '';
                }
                if ($vazio) { if ($p['obrigatoria']) $faltando[] = $p['texto']; continue; }
                $limpas[$cod] = $valor;
                $partes = explode('.', $cod);
                $tipo = $partes[1] ?? '';
                foreach ((array)$valor as $v) {
                    if ($tipo === 'PILAR' && isset($partes[2])) $itens[] = ['tipo' => 'PILAR', 'modulo' => $b['codigo'], 'pilar' => $partes[2], 'papel' => $v, 'valor' => null, 'codunidade' => null];
                    elseif ($cod === 'GERAL.UNIDADES') $itens[] = ['tipo' => 'UNIDADE', 'modulo' => 'GERAL', 'pilar' => null, 'papel' => null, 'valor' => null, 'codunidade' => (int)$v];
                    elseif ($tipo === 'TEMAS') $itens[] = ['tipo' => 'TEMA', 'modulo' => $b['codigo'], 'pilar' => null, 'papel' => null, 'valor' => $v, 'codunidade' => null];
                    elseif ($tipo === 'CATEGORIAS') $itens[] = ['tipo' => 'CATEGORIA', 'modulo' => $b['codigo'], 'pilar' => null, 'papel' => null, 'valor' => $v, 'codunidade' => null];
                    else $itens[] = ['tipo' => 'RESPOSTA', 'modulo' => $b['codigo'], 'pilar' => mb_substr($cod, 0, 100), 'papel' => null, 'valor' => mb_substr((string)$v, 0, 255), 'codunidade' => null];
                }
            }
        }
        if ($faltando) return ['ok' => false, 'faltando' => $faltando];
        $gestor = (int)($this->linhas($this->tenant, 'SELECT codgestor FROM vw_perfil_atribuicoes_situacao WHERE codusuario = ?', [$usuario])[0]['codgestor'] ?? 0);
        $id = (int)($this->linhas($this->tenant, 'CALL sp_perfil_atrib_enviar(?, ?, ?, ?, ?, ?, ?, ?)', [$usuario, $gestor ?: null, $q['codquestionario'],
            $q['codversao'], $q['versao'], implode(',', $q['modulos']), json_encode($limpas ?: new stdClass(), JSON_UNESCAPED_UNICODE),
            json_encode($itens, JSON_UNESCAPED_UNICODE)])[0]['codperfilatrib'] ?? 0);
        return ['ok' => true, 'codperfilatrib' => $id, 'codgestor' => $gestor ?: null];
    }

    public function decidir(int $id, int $decisor, bool $gerenciaTodos, bool $admin, string $acao, string $motivo): array
    {
        return $this->linhas($this->tenant, 'CALL sp_perfil_atrib_decidir(?, ?, ?, ?, ?, ?, ?)',
            [$id, $decisor, $gerenciaTodos ? 1 : 0, $admin ? 1 : 0, strtoupper($acao), $motivo, self::MESES_REVISAO])[0] ?? [];
    }

    /** Situação de todos (gestão) ou só da equipe do gestor. */
    public function equipe(int $gestor, bool $todos): array
    {
        // Só usuários cujo perfil de acesso aplica o questionário (Segurança › Perfil, "Aplicar questionário")
        return $todos ? $this->linhas($this->tenant, 'SELECT * FROM vw_perfil_atribuicoes_situacao WHERE aplicar_questionario = 1 ORDER BY usuario_nome')
            : $this->linhas($this->tenant, 'SELECT * FROM vw_perfil_atribuicoes_situacao WHERE codgestor = ? AND aplicar_questionario = 1 ORDER BY usuario_nome', [$gestor]);
    }

    public function envio(int $id): ?array
    {
        return $this->linhas($this->tenant, 'SELECT * FROM vw_perfil_atribuicoes_grid WHERE codperfilatrib = ?', [$id])[0] ?? null;
    }

    public function temEquipe(int $gestor): bool
    {
        return (bool)$this->linhas($this->tenant, 'SELECT 1 FROM vw_perfil_atribuicoes_situacao WHERE codgestor = ? AND aplicar_questionario = 1 LIMIT 1', [$gestor]);
    }

    /** Catálogo completo (todos os blocos) para mostrar as respostas de um envio com os textos das perguntas. */
    public function catalogoCompleto(): ?array { return $this->catalogo(); }
}
