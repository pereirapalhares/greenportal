<?php
declare(strict_types=1);
require_once __DIR__ . '/AdministracaoTextos.php';

require_once __DIR__ . '/IdiomaService.php';

/**
 * Guia do usuário: conteúdo universal (guniversal_dbp) com rascunho e versões publicadas.
 *
 * - Leitura somente pelas views vw_guia_usuario_*.
 * - Gravação somente pelas procedures sp_guia_usuario_*.
 * - Todo conteúdo recebido é normalizado por uma lista fechada de campos
 *   (normalizar), com limites de tamanho e textos simples (sem HTML).
 */
final class GuiaUsuarioService
{
    public const TRANSACAO = 'ADMINISTRACAO_GUIA_USUARIO';
    private const IDIOMAS = ['pt', 'en', 'es'];
    private const MAX_JSON_BYTES = 1048576;

    public function __construct(private readonly PDO $universal)
    {
    }

    /** Conteúdo publicado vigente, ou null quando não há versão publicada. */
    public function publicado(): ?array
    {
        $row = $this->universal->query('SELECT codversao, numero, conteudo, publicado_em, publicado_por FROM vw_guia_usuario_publicado LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $conteudo = json_decode((string)$row['conteudo'], true);
        return is_array($conteudo) ? ['versao' => $this->metaVersao($row), 'conteudo' => $conteudo] : null;
    }

    public function rascunho(): ?array
    {
        $row = $this->universal->query('SELECT conteudo, revisao, alterado_por, alterado_banco, alterado_em, numero_publicado, publicado_em, publicado_por, pendente FROM vw_guia_usuario_rascunho LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $conteudo = json_decode((string)$row['conteudo'], true);
        return [
            'conteudo' => is_array($conteudo) ? $conteudo : [],
            'revisao' => (int)$row['revisao'],
            'alterado_por' => (string)($row['alterado_por'] ?? ''),
            'alterado_em' => (string)($row['alterado_em'] ?? ''),
            'pendente' => (bool)$row['pendente'],
            'publicado' => $row['numero_publicado'] === null ? null : [
                'numero' => (int)$row['numero_publicado'],
                'publicado_em' => (string)$row['publicado_em'],
                'publicado_por' => (string)($row['publicado_por'] ?? ''),
            ],
        ];
    }

    public function versoes(): array
    {
        $rows = $this->universal->query('SELECT codversao, numero, origem, observacao, publicado_por, publicado_banco, publicado_em, qtd_modulos, vigente FROM vw_guia_usuario_versoes ORDER BY numero DESC')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $r): array => [
            'codversao' => (int)$r['codversao'], 'numero' => (int)$r['numero'], 'origem' => (string)$r['origem'],
            'observacao' => (string)($r['observacao'] ?? ''), 'publicado_por' => (string)($r['publicado_por'] ?? ''),
            'publicado_banco' => (string)($r['publicado_banco'] ?? ''), 'publicado_em' => (string)$r['publicado_em'],
            'qtd_modulos' => (int)$r['qtd_modulos'], 'vigente' => (bool)$r['vigente'],
        ], $rows);
    }

    /**
     * Transações que podem receber link direto: menus ativos com URL no catálogo universal,
     * indexados pelo código. A permissão de cada usuário é verificada na exibição do guia.
     */
    public function menusComUrl(): array
    {
        $rows = $this->universal->query("SELECT m.codigo, m.url, m.icone, m.label_pt, m.label_en, m.label_es, m.modulo, p.label_pt AS pai_pt, p.label_en AS pai_en, p.label_es AS pai_es
            FROM menus m LEFT JOIN menus p ON p.codmenu = m.codmenu_pai
            WHERE m.ativo = 1 AND m.url IS NOT NULL AND m.url <> '' ORDER BY COALESCE(p.label_pt, m.modulo), m.label_pt")->fetchAll(PDO::FETCH_ASSOC);
        $menus = [];
        foreach ($rows as $r) {
            $url = (string)$r['url'];
            if (!preg_match('~^/[A-Za-z0-9_./-]+\.php(\?[A-Za-z0-9_=&.-]*)?$~', $url)) continue;
            $menus[(string)$r['codigo']] = [
                'codigo' => (string)$r['codigo'], 'url' => $url, 'icone' => (string)($r['icone'] ?? ''),
                'nome' => ['pt' => (string)($r['label_pt'] ?? $r['codigo']), 'en' => (string)($r['label_en'] ?: $r['label_pt']), 'es' => (string)($r['label_es'] ?: $r['label_pt'])],
                'grupo' => ['pt' => (string)($r['pai_pt'] ?? ''), 'en' => (string)($r['pai_en'] ?: $r['pai_pt'] ?? ''), 'es' => (string)($r['pai_es'] ?: $r['pai_pt'] ?? '')],
            ];
        }
        return $menus;
    }

    /** Conteúdo de uma versão específica (para pré-visualização). */
    public function versao(int $codversao): ?array
    {
        $query = $this->universal->prepare('SELECT v.codversao, v.numero, v.conteudo, v.publicado_em, v.publicado_por FROM guia_usuario_versoes v WHERE v.codversao = ?');
        $query->execute([$codversao]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $conteudo = json_decode((string)$row['conteudo'], true);
        return is_array($conteudo) ? ['versao' => $this->metaVersao($row), 'conteudo' => $conteudo] : null;
    }

    public function salvarRascunho(array $conteudo, int $revisao, array $autor): int
    {
        $json = $this->codificar($this->normalizar($conteudo));
        $rows = $this->chamar('CALL sp_guia_usuario_salvar_rascunho(?, ?, ?, ?, ?)', [$json, $revisao, ...$this->autor($autor)]);
        return (int)($rows[0]['revisao'] ?? 0);
    }

    public function publicar(int $revisao, string $observacao, array $autor): array
    {
        $rascunho = $this->rascunho();
        if (!$rascunho) throw new RuntimeException(adm_t('Rascunho do guia não inicializado.'));
        $this->validarParaPublicacao($this->normalizar($rascunho['conteudo']));
        $observacao = mb_substr(trim(preg_replace('/\s+/u', ' ', $observacao) ?? ''), 0, 500);
        $rows = $this->chamar('CALL sp_guia_usuario_publicar(?, ?, ?, ?, ?)', [$revisao, $observacao, ...$this->autor($autor)]);
        return ['codversao' => (int)($rows[0]['codversao'] ?? 0), 'numero' => (int)($rows[0]['numero'] ?? 0)];
    }

    public function restaurar(int $codversao, int $revisao, array $autor): int
    {
        $rows = $this->chamar('CALL sp_guia_usuario_restaurar_rascunho(?, ?, ?, ?, ?)', [$codversao, $revisao, ...$this->autor($autor)]);
        return (int)($rows[0]['revisao'] ?? 0);
    }

    /** Carga inicial idempotente: só grava quando ainda não existe versão publicada. */
    public function cargaInicial(array $conteudo, array $autor): bool
    {
        $json = $this->codificar($this->normalizar($conteudo));
        $rows = $this->chamar('CALL sp_guia_usuario_carga_inicial(?, ?, ?, ?)', [$json, ...$this->autor($autor)]);
        return !empty($rows[0]['carregado']);
    }

    /**
     * Normaliza o documento do guia: mantém apenas campos conhecidos, limita tamanhos,
     * remove HTML e garante identificadores válidos.
     */
    public function normalizar(array $doc): array
    {
        $perfis = [];
        foreach ((array)($doc['perfis'] ?? []) as $chave => $perfil) {
            $chave = $this->chave((string)$chave);
            if ($chave === '' || isset($perfis[$chave]) || !is_array($perfil) || count($perfis) >= 40) continue;
            $perfis[$chave] = ['icone' => $this->icone($perfil['icone'] ?? ''), 'nome' => $this->texto($perfil['nome'] ?? '', 80)];
        }
        if (!isset($perfis['sistema'])) {
            $perfis['sistema'] = ['icone' => 'bi-gear', 'nome' => ['pt' => 'Sistema', 'en' => 'System', 'es' => 'Sistema']];
        }

        $modulos = [];
        $ids = [];
        $guiaIds = [];
        foreach (array_slice(array_values((array)($doc['modulos'] ?? [])), 0, 30) as $modulo) {
            if (!is_array($modulo)) continue;
            $id = $this->chave((string)($modulo['id'] ?? ''));
            if ($id === '' || isset($ids[$id])) $id = 'modulo_' . (count($modulos) + 1);
            $ids[$id] = true;
            $perfisModulo = [];
            foreach ((array)($modulo['perfis'] ?? []) as $p) {
                $p = $this->chave((string)$p);
                if ($p !== '' && $p !== 'sistema' && isset($perfis[$p]) && !in_array($p, $perfisModulo, true)) $perfisModulo[] = $p;
            }
            $fases = [];
            foreach (array_slice(array_values((array)($modulo['fases'] ?? [])), 0, 20) as $fase) {
                if (!is_array($fase)) continue;
                $passos = [];
                foreach (array_slice(array_values((array)($fase['passos'] ?? [])), 0, 40) as $passo) {
                    if (!is_array($passo)) continue;
                    $tipo = strtoupper((string)($passo['tipo'] ?? 'M')) === 'A' ? 'A' : 'M';
                    $perfil = $this->chave((string)($passo['perfil'] ?? ''));
                    if (!isset($perfis[$perfil])) $perfil = $tipo === 'A' ? 'sistema' : ($perfisModulo[0] ?? 'sistema');
                    $passos[] = [
                        'tipo' => $tipo, 'perfil' => $perfil,
                        'titulo' => $this->texto($passo['titulo'] ?? '', 120),
                        'texto' => $this->texto($passo['texto'] ?? '', 600),
                        'onde' => $this->texto($passo['onde'] ?? '', 200),
                        'transacao' => $this->transacao($passo['transacao'] ?? ''),
                    ];
                }
                $fases[] = ['nome' => $this->texto($fase['nome'] ?? '', 80), 'passos' => $passos];
            }
            $tarefas = [];
            foreach (array_slice(array_values((array)($modulo['tarefas'] ?? [])), 0, 40) as $tarefa) {
                if (!is_array($tarefa)) continue;
                $itens = [];
                foreach (array_slice(array_values((array)($tarefa['passos'] ?? [])), 0, 20) as $item) $itens[] = $this->texto($item, 300);
                $tarefas[] = ['titulo' => $this->texto($tarefa['titulo'] ?? '', 150), 'passos' => $itens, 'transacao' => $this->transacao($tarefa['transacao'] ?? '')];
            }
            $menu = [];
            foreach (array_slice(array_values((array)($modulo['menu'] ?? [])), 0, 60) as $item) {
                if (!is_array($item)) continue;
                $menu[] = ['caminho' => $this->texto($item['caminho'] ?? '', 200), 'texto' => $this->texto($item['texto'] ?? '', 300), 'transacao' => $this->transacao($item['transacao'] ?? '')];
            }
            $termos = [];
            foreach (array_slice(array_values((array)($modulo['termos'] ?? [])), 0, 60) as $item) {
                if (!is_array($item)) continue;
                $termo = $this->texto($item['termo'] ?? '', 80);
                $dica = !empty($item['dica']) || in_array($termo['pt'], ['Dica'], true);
                $termos[] = ['termo' => $termo, 'texto' => $this->texto($item['texto'] ?? '', 400), 'dica' => $dica];
            }
            // Ajuda interativa (botão ? / F1): guias passo a passo e "Sobre esta tela" do assunto.
            $guias = [];
            foreach (array_slice(array_values((array)($modulo['guias'] ?? [])), 0, 60) as $guia) {
                if (!is_array($guia)) continue;
                $gid = $this->chave((string)($guia['id'] ?? ''));
                if ($gid === '' || isset($guiaIds[$gid])) $gid = substr($id, 0, 28) . '-guia-' . (count($guias) + 1);
                $guiaIds[$gid] = true;
                $passosGuia = [];
                foreach (array_slice(array_values((array)($guia['passos'] ?? [])), 0, 80) as $passo) {
                    if (!is_array($passo)) $passo = ['texto' => $passo];
                    $quem = $this->chave((string)($passo['quem'] ?? ''));
                    $passosGuia[] = [
                        'quem' => isset($perfis[$quem]) ? $quem : '',
                        'texto' => $this->texto($passo['texto'] ?? '', 400),
                        'ver' => $this->texto($passo['ver'] ?? '', 300),
                        'alvo' => $this->alvo($passo['alvo'] ?? ''),
                        'tela' => $this->transacao($passo['tela'] ?? ''),
                        'auto' => !array_key_exists('auto', $passo) || !empty($passo['auto']),
                    ];
                }
                $perfisGuia = [];
                foreach ((array)($guia['perfis'] ?? []) as $p) { $p = $this->chave((string)$p); if ($p !== '' && isset($perfis[$p]) && !in_array($p, $perfisGuia, true)) $perfisGuia[] = $p; }
                $guias[] = [
                    'id' => $gid, 'titulo' => $this->texto($guia['titulo'] ?? '', 150), 'resumo' => $this->texto($guia['resumo'] ?? '', 300),
                    'antes' => $this->texto($guia['antes'] ?? '', 400), 'depois' => $this->texto($guia['depois'] ?? '', 400),
                    'perfis' => $perfisGuia, 'telas' => $this->telas($guia['telas'] ?? []), 'passos' => $passosGuia,
                ];
            }
            $telas = [];
            foreach (array_slice(array_values((array)($modulo['telas'] ?? [])), 0, 60) as $tela) {
                if (!is_array($tela)) continue;
                $pode = [];
                foreach (array_slice(array_values((array)($tela['pode'] ?? [])), 0, 15) as $item) {
                    if (!is_array($item)) $item = ['texto' => $item];
                    $pode[] = ['texto' => $this->texto($item['texto'] ?? '', 200), 'alvo' => $this->alvo($item['alvo'] ?? '')];
                }
                $duvidas = [];
                foreach (array_slice(array_values((array)($tela['duvidas'] ?? [])), 0, 20) as $item) {
                    if (!is_array($item)) continue;
                    $duvidas[] = ['p' => $this->texto($item['p'] ?? '', 200), 'r' => $this->texto($item['r'] ?? '', 600)];
                }
                $chavesTela = $this->telas($tela['chaves'] ?? []);
                if (!$chavesTela) continue;
                $telas[] = ['chaves' => $chavesTela, 'oque' => $this->texto($tela['oque'] ?? '', 400), 'pode' => $pode,
                    'antes' => $this->texto($tela['antes'] ?? '', 400), 'depois' => $this->texto($tela['depois'] ?? '', 400), 'duvidas' => $duvidas];
            }
            $modulos[] = [
                'id' => $id,
                'icone' => $this->icone($modulo['icone'] ?? ''),
                'ativo' => !array_key_exists('ativo', $modulo) || !empty($modulo['ativo']),
                'nome' => $this->texto($modulo['nome'] ?? '', 80),
                'resumo' => $this->texto($modulo['resumo'] ?? '', 400),
                'perfis' => $perfisModulo,
                'perfis_nota' => $this->texto($modulo['perfis_nota'] ?? '', 300),
                'fases' => $fases, 'tarefas' => $tarefas, 'menu' => $menu, 'termos' => $termos,
                'guias' => $guias, 'telas' => $telas,
            ];
        }
        return ['formato' => 1, 'perfis' => $perfis, 'modulos' => $modulos];
    }

    /** Regras mínimas para publicar: evita publicar um guia vazio ou com textos obrigatórios em branco. */
    public function validarParaPublicacao(array $doc): void
    {
        $erros = [];
        $ativos = array_filter($doc['modulos'], static fn(array $m): bool => !empty($m['ativo']));
        if (!$ativos) $erros[] = adm_t('Inclua ao menos uma aba ativa.');
        foreach ($doc['perfis'] as $chave => $perfil) if ($perfil['nome']['pt'] === '') $erros[] = 'Perfil "' . $chave . adm_t('" sem nome em português.');
        foreach ($doc['modulos'] as $m) {
            if (empty($m['ativo'])) continue; // abas ocultas podem ficar em construção
            $nome = $m['nome']['pt'] !== '' ? $m['nome']['pt'] : $m['id'];
            if ($m['nome']['pt'] === '') $erros[] = 'Aba "' . $m['id'] . adm_t('" sem nome em português.');
            foreach ($m['fases'] as $i => $fase) {
                if ($fase['nome']['pt'] === '') $erros[] = $nome . ': fase ' . ($i + 1) . adm_t(' sem nome.');
                foreach ($fase['passos'] as $j => $passo) if ($passo['titulo']['pt'] === '') $erros[] = $nome . ': etapa ' . ($i + 1) . '.' . ($j + 1) . adm_t(' sem título.');
            }
            foreach ($m['tarefas'] as $i => $tarefa) if ($tarefa['titulo']['pt'] === '') $erros[] = $nome . adm_t(': "Como fazer" ') . ($i + 1) . adm_t(' sem título.');
            foreach ($m['termos'] as $i => $termo) if ($termo['termo']['pt'] === '') $erros[] = $nome . ': termo ' . ($i + 1) . adm_t(' sem nome.');
            foreach ($m['guias'] ?? [] as $i => $guia) {
                $tg = $guia['titulo']['pt'] !== '' ? $guia['titulo']['pt'] : 'guia ' . ($i + 1);
                if ($guia['titulo']['pt'] === '') $erros[] = $nome . ': guia passo a passo ' . ($i + 1) . adm_t(' sem título.');
                if (!$guia['passos']) $erros[] = $nome . ': guia "' . $tg . adm_t('" sem passos.');
                foreach ($guia['passos'] as $j => $passo) if ($passo['texto']['pt'] === '') $erros[] = $nome . ': guia "' . $tg . '", passo ' . ($j + 1) . adm_t(' sem texto.');
                if (!$guia['telas']) $erros[] = $nome . ': guia "' . $tg . adm_t('" sem tela onde aparece.');
            }
        }
        if ($erros) throw new InvalidArgumentException("Corrija antes de publicar:\n- " . implode("\n- ", array_slice($erros, 0, 12)));
    }

    /** Mensagens das procedures convertidas para textos amigáveis. */
    public static function mensagemErro(Throwable $e): array
    {
        $msg = $e->getMessage();
        if (str_contains($msg, 'CONFLITO_REVISAO')) return [409, adm_t('Outro administrador alterou o rascunho enquanto você editava. Recarregue a tela para ver a versão mais recente.')];
        if (str_contains($msg, 'SEM_ALTERACOES')) return [422, adm_t('O rascunho é igual à versão publicada. Não há nada novo para publicar.')];
        if ($e instanceof InvalidArgumentException) return [422, $msg];
        if (preg_match('/1644\s+(.+)$/u', $msg, $m)) return [422, IdiomaService::mensagemBanco(trim($m[1]))];
        error_log('GuiaUsuarioService: ' . $msg);
        return [500, adm_t('Não foi possível concluir a operação no guia.')];
    }

    private function texto(mixed $valor, int $limite): array
    {
        if (!is_array($valor)) $valor = ['pt' => (string)$valor, 'en' => (string)$valor, 'es' => (string)$valor];
        $saida = [];
        foreach (self::IDIOMAS as $idioma) {
            $texto = (string)($valor[$idioma] ?? '');
            $texto = strip_tags($texto);
            $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $texto) ?? '';
            $texto = trim(preg_replace('/[ \t]+/u', ' ', $texto) ?? '');
            $saida[$idioma] = mb_substr($texto, 0, $limite);
        }
        return $saida;
    }

    /**
     * Onde o "Mostre-me" procura o item na tela: menu:CODIGO, acao:new|save|delete|..., texto:Nome do botão,
     * campo:COLUNA, coluna:COLUNA, filtro:COLUNA, aba:Nome, linha, busca ou css:seletor. Vazio = o nome em **negrito** do texto.
     */
    private function alvo(mixed $valor): string
    {
        $valor = trim(strip_tags((string)$valor));
        $valor = preg_replace('/[\x00-\x1F\x7F]/u', '', $valor) ?? '';
        return mb_substr($valor, 0, 200);
    }

    /** Telas onde o guia/texto aparece: código do menu, transação da tela (botão ?) ou arquivo:nome.php. */
    private function telas(mixed $valor): array
    {
        $saida = [];
        foreach (array_slice((array)$valor, 0, 20) as $chave) {
            $chave = trim((string)$chave);
            if (preg_match('/^[A-Za-z0-9_.:-]{2,120}$/', $chave) && !in_array($chave, $saida, true)) $saida[] = $chave;
        }
        return $saida;
    }

    /** Código de menu usado no link direto (vazio = sem link). */
    private function transacao(mixed $valor): string
    {
        $valor = strtoupper(trim((string)$valor));
        return preg_match('/^[A-Z0-9_]{2,100}$/', $valor) ? $valor : '';
    }

    private function chave(string $valor): string
    {
        $valor = strtolower(trim($valor));
        return preg_match('/^[a-z0-9][a-z0-9_-]{1,39}$/', $valor) ? $valor : '';
    }

    private function icone(mixed $valor): string
    {
        $valor = strtolower(trim((string)$valor));
        return preg_match('/^bi-[a-z0-9-]{1,50}$/', $valor) ? $valor : 'bi-circle';
    }

    private function codificar(array $doc): string
    {
        $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new InvalidArgumentException(adm_t('Conteúdo do guia inválido.'));
        if (strlen($json) > self::MAX_JSON_BYTES) throw new InvalidArgumentException(adm_t('O guia ficou grande demais para ser salvo.'));
        return $json;
    }

    private function autor(array $autor): array
    {
        $banco = (string)($autor['banco'] ?? '');
        return [
            mb_substr(trim((string)($autor['nome'] ?? '')), 0, 150) ?: null,
            (int)($autor['codusuario'] ?? 0) ?: null,
            preg_match('/^[A-Za-z0-9_]{1,64}$/', $banco) ? $banco : null,
        ];
    }

    private function metaVersao(array $row): array
    {
        return ['codversao' => (int)$row['codversao'], 'numero' => (int)$row['numero'], 'publicado_em' => (string)$row['publicado_em'], 'publicado_por' => (string)($row['publicado_por'] ?? '')];
    }

    private function chamar(string $sql, array $params): array
    {
        $statement = $this->universal->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        return $rows;
    }
}
