<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';

/**
 * Textos da interface em PT/EN/ES, lidos de guniversal_dbp (migração 211).
 *
 * Uso nas telas:
 *   IdiomaService::t('comum.botao.salvar', ['pt' => 'Salvar', 'en' => 'Save', 'es' => 'Guardar'])
 *       → texto no idioma do usuário.
 *   IdiomaService::textos('modulolegal_controle.coluna.CODIGO', $labelPadrao)
 *       → ['pt' => ..., 'en' => ..., 'es' => ...] (para configurações que já guardam os três idiomas).
 *   IdiomaService::script(['comum', 'modulolegal_controle'])
 *       → <script> com window.greenPortalTextos e window.greenT(chave, padrao) para o JavaScript.
 *
 * O texto padrão passado pelo código é sempre o último recurso: se o banco estiver indisponível,
 * se a migração ainda não rodou ou se a chave não existir, a tela continua com o texto do código.
 * Ordem: texto do banco no idioma do usuário → padrão do código no idioma → texto do banco em PT → padrão em PT.
 *
 * O ADMINISTRADOR pode ver o rascunho nas telas ("Ver rascunho nas telas", Administração › Textos e idiomas).
 */
final class IdiomaService
{
    public const TRANSACAO = 'ADMINISTRACAO_IDIOMAS';
    public const IDIOMAS = ['pt', 'en', 'es'];
    /** Nome de cada idioma escrito nele mesmo (lista de escolha no login e no cabeçalho). */
    public const NOMES = ['pt' => 'Português', 'en' => 'English', 'es' => 'Español'];
    /** Cookie que lembra a última escolha neste navegador (pré-seleciona o login). */
    public const COOKIE = 'gp_idioma';
    private static ?string $idiomaForcado = null;

    /** @var array<string, array<string, array<string, string>>> grupo => chave => idioma => texto */
    private static array $cache = [];
    private static ?PDO $pdo = null;
    private static bool $indisponivel = false;
    /** @var array<string, array<string, array>> textos novos a cadastrar: grupo => chave => item */
    private static array $pendentes = [];
    /** @var array<string, array> nome (pt/en/es) do grupo de uma transação, quando a tela informa */
    private static array $titulos = [];
    private static bool $gravacaoAgendada = false;
    private const GRUPOS_FIXOS = ['COMUM', 'BANCO', 'MENUS'];

    public static function idioma(): string
    {
        if (self::$idiomaForcado !== null) return self::$idiomaForcado;
        self::sessao();
        $idioma = strtolower((string)($_SESSION['idioma'] ?? 'pt'));
        return in_array($idioma, self::IDIOMAS, true) ? $idioma : 'pt';
    }

    /** Usa um idioma nesta requisição sem depender da sessão (tela de login). */
    public static function usarIdioma(string $idioma): void
    {
        $idioma = strtolower(trim($idioma));
        self::$idiomaForcado = in_array($idioma, self::IDIOMAS, true) ? $idioma : 'pt';
    }

    /**
     * Idioma para quem ainda não entrou: escolha na própria tela (?idioma=), última escolha neste navegador
     * (cookie) ou o idioma do navegador; sem nenhum deles, português.
     */
    public static function idiomaPreferido(?string $escolhido = null): string
    {
        foreach ([$escolhido, $_COOKIE[self::COOKIE] ?? null] as $valor) {
            $valor = strtolower(trim((string)$valor));
            if (in_array($valor, self::IDIOMAS, true)) return $valor;
        }
        foreach (explode(',', strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))) as $parte) {
            $codigo = substr(trim($parte), 0, 2);
            if (in_array($codigo, self::IDIOMAS, true)) return $codigo;
        }
        return 'pt';
    }

    /** Lembra a escolha neste navegador por um ano. */
    public static function lembrarIdioma(string $idioma, string $caminho = '/'): void
    {
        $idioma = strtolower(trim($idioma));
        if (!in_array($idioma, self::IDIOMAS, true) || headers_sent()) return;
        setcookie(self::COOKIE, $idioma, ['expires' => time() + 31536000, 'path' => $caminho ?: '/', 'samesite' => 'Lax', 'httponly' => false, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    }

    /** O ADMINISTRADOR ligou a pré-visualização do rascunho nesta sessão. */
    public static function previa(): bool
    {
        // Tela de login (idioma escolhido na própria tela): não abre sessão só para isso.
        if (self::$idiomaForcado !== null && session_status() !== PHP_SESSION_ACTIVE) return false;
        self::sessao();
        return !empty($_SESSION['idioma_previa_rascunho'])
            && strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) === 'ADMINISTRADOR';
    }

    /** Texto no idioma do usuário. $padrao: string (PT) ou ['pt' => ..., 'en' => ..., 'es' => ...]. */
    public static function t(string $chave, array|string $padrao = ''): string
    {
        $textos = self::textos($chave, $padrao);
        return $textos[self::idioma()] ?? $textos['pt'] ?? '';
    }

    /** Os três idiomas de uma chave, já com os textos do banco aplicados. */
    public static function textos(string $chave, array|string $padrao = ''): array
    {
        $padrao = is_array($padrao) ? $padrao : ['pt' => $padrao];
        $doGrupo = self::grupo(self::grupoDaChave($chave));
        $banco = $doGrupo[$chave] ?? [];
        // Texto ainda não cadastrado: entra sozinho em Textos e idiomas quando um ADMINISTRADOR abre a tela.
        if (!$banco) self::agendar($chave, $padrao);
        $resultado = [];
        foreach (self::IDIOMAS as $idioma) {
            $resultado[$idioma] = self::primeiro([
                $banco[$idioma] ?? null, $padrao[$idioma] ?? null, $banco['pt'] ?? null, $padrao['pt'] ?? null,
            ]);
        }
        return $resultado;
    }

    /** Texto com {marcadores} substituídos: IdiomaService::f('comum.erro.vinculo', $padrao, ['acao' => ...]). */
    public static function f(string $chave, array|string $padrao, array $valores): string
    {
        $texto = self::t($chave, $padrao);
        return (string)preg_replace_callback('/\{(\w+)\}/', static fn(array $m): string => array_key_exists($m[1], $valores) ? (string)$valores[$m[1]] : $m[0], $texto);
    }

    /**
     * Aviso gerado por uma procedure (SIGNAL ... MESSAGE_TEXT) no idioma do usuário.
     * Procura banco.m<md5 da mensagem original>; sem cadastro, devolve a mensagem como veio.
     */
    public static function mensagemBanco(string $mensagem): string
    {
        $mensagem = trim($mensagem);
        if ($mensagem === '') return '';
        return self::t('banco.m' . substr(md5($mensagem), 0, 12), $mensagem);
    }

    /**
     * Mensagem amigável para uma exceção de banco, no idioma do usuário.
     * $acao: concluir, salvar, excluir, associar, salvar_menu (comum.acao.*).
     * Aviso de procedure (erro 1644) → mensagemBanco; demais → duplicado / vínculo / obrigatório / genérico.
     */
    public static function erro(Throwable $e, string $acao = 'concluir'): string
    {
        $mensagem = $e->getMessage();
        if (preg_match('/1644\s+(.+)$/su', $mensagem, $m)) return self::mensagemBanco($m[1]);
        // Sem conexão com o banco (servidor parado, rede, credencial).
        if ($e instanceof PDOException && in_array((int)$e->getCode(), [1045, 1049, 2002, 2003, 2006, 2013], true)) {
            return self::t('comum.erro.banco_indisponivel', ['pt' => 'Banco de dados indisponível. Tente novamente em instantes.', 'en' => 'Database unavailable. Please try again shortly.', 'es' => 'Base de datos no disponible. Inténtelo de nuevo en unos instantes.']);
        }
        if (!class_exists('MensagemAplicacao')) require_once __DIR__ . '/MensagemAplicacao.php';
        return MensagemAplicacao::erro($e, $acao);
    }

    /** Existe texto cadastrado no banco para a chave? */
    public static function existe(string $chave): bool
    {
        return isset(self::grupo(self::grupoDaChave($chave))[$chave]);
    }

    /**
     * Dicionário do idioma atual para o JavaScript (window.greenPortalTextos) e a função window.greenT.
     * Os acentos saem como \u.... para não sofrerem a correção automática de acentuação do portal.
     */
    public static function script(array $grupos, array $padroes = []): string
    {
        $idioma = self::idioma();
        // Textos usados só no JavaScript: passam por textos() para valer o banco e entrar no cadastro automático.
        foreach ($padroes as $chave => $padrao) self::textos((string)$chave, $padrao);
        $dicionario = [];
        foreach ($grupos as $grupo) {
            foreach (self::grupo((string)$grupo) as $chave => $porIdioma) {
                $texto = self::primeiro([$porIdioma[$idioma] ?? null, $porIdioma['pt'] ?? null]);
                if ($texto !== '') $dicionario[$chave] = $texto;
            }
        }
        foreach ($padroes as $chave => $padrao) {
            if (!isset($dicionario[(string)$chave])) {
                $texto = self::t((string)$chave, $padrao);
                if ($texto !== '') $dicionario[(string)$chave] = $texto;
            }
        }
        $json = $dicionario ? json_encode($dicionario, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) : '{}';
        return '<script>window.greenPortalTextos=Object.assign(window.greenPortalTextos||{},' . ($json ?: '{}') . ');'
            . 'window.greenT=window.greenT||function(k,p){var v=window.greenPortalTextos&&window.greenPortalTextos[k];return(typeof v==="string"&&v!=="")?v:(p===undefined?k:p);};</script>';
    }

    /** Textos de um grupo ('comum', 'modulolegal_controle'...), carregados uma vez por requisição. */
    public static function grupo(string $grupo): array
    {
        $grupo = strtoupper(trim($grupo));
        if ($grupo === '' || !preg_match('/^[A-Z0-9_]+$/', $grupo)) return [];
        if (array_key_exists($grupo, self::$cache)) return self::$cache[$grupo];
        self::$cache[$grupo] = [];
        $pdo = self::conexao();
        if (!$pdo) return [];
        try {
            $view = self::previa() ? 'vw_idioma_textos_rascunho' : 'vw_idioma_textos_publicados';
            $stmt = $pdo->prepare('SELECT chave, codidioma, texto FROM ' . $view . ' WHERE codgrupo = ?');
            $stmt->execute([$grupo]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                self::$cache[$grupo][(string)$row['chave']][(string)$row['codidioma']] = (string)$row['texto'];
            }
        } catch (Throwable $e) {
            // Migração 211 ainda não aplicada ou banco indisponível: as telas usam os textos do código.
            self::$indisponivel = true;
            error_log('IdiomaService: ' . $e->getMessage());
        }
        return self::$cache[$grupo];
    }

    /**
     * Cadastro automático dos rótulos de coluna de um grid (<transacao>.coluna.<CAMPO>).
     * Só acontece quando um ADMINISTRADOR abre a transação e só para as colunas que ainda não existem;
     * os textos atuais do código viram a carga inicial. Nunca sobrescreve o que já foi editado.
     * $campos: [CAMPO => ['label' => ['pt' => ..., 'en' => ..., 'es' => ...] | 'texto', 'hidden' => bool], ...]
     */
    public static function registrarColunas(string $transacao, array $campos, array|string|null $titulo = null): int
    {
        $transacao = strtolower((string)preg_replace('/[^A-Za-z0-9_]/', '', $transacao));
        if ($transacao === '' || !self::podeRegistrar()) return 0;
        $grupo = strtoupper($transacao);
        $existentes = self::grupo($grupo);
        if (self::$indisponivel) return 0;
        if ($titulo !== null) self::$titulos[$grupo] = is_array($titulo) ? $titulo : ['pt' => (string)$titulo];
        $ordem = 0;
        foreach ($campos as $campo => $cfg) {
            $ordem += 10;
            $campo = (string)$campo;
            $chave = $transacao . '.coluna.' . $campo;
            if (isset($existentes[$chave]) || !preg_match('/^[A-Za-z0-9_]+$/', $campo)) continue;
            $label = is_array($cfg) ? ($cfg['label'] ?? $campo) : $campo;
            $label = is_array($label) ? $label : ['pt' => (string)$label];
            $pt = trim((string)($label['pt'] ?? ''));
            if ($pt === '') continue; // coluna técnica sem título
            self::agendar($chave, ['pt' => $pt, 'en' => (string)($label['en'] ?? ''), 'es' => (string)($label['es'] ?? '')], 'COLUNA',
                'Coluna ' . $pt . (!empty($cfg['hidden']) ? ' (oculta)' : ''), $ordem);
        }
        return self::gravarPendentes();
    }

    /** Nome do grupo de uma transação (usado se o menu da transação não for encontrado). */
    public static function nomeGrupo(string $transacao, array|string $titulo): void
    {
        $grupo = strtoupper((string)preg_replace('/[^A-Za-z0-9_]/', '', $transacao));
        if ($grupo !== '') self::$titulos[$grupo] = is_array($titulo) ? $titulo : ['pt' => $titulo];
    }

    /** Grava os textos novos desta requisição (chamado no fim da página ou por registrarColunas). */
    public static function gravarPendentes(): int
    {
        if (!self::$pendentes) return 0;
        $pdo = self::conexao();
        if (!$pdo) { self::$pendentes = []; return 0; }
        $total = 0;
        $autor = mb_substr('Cadastro automático (' . (string)($_SESSION['usuario']['nome'] ?? $_SESSION['usuario']['userid'] ?? 'administrador') . ')', 0, 150);
        foreach (self::$pendentes as $grupo => $itens) {
            $transacao = in_array($grupo, self::GRUPOS_FIXOS, true) ? '' : strtolower($grupo);
            $titulo = self::$titulos[$grupo] ?? ['pt' => $transacao ?: $grupo];
            try {
                $rows = GreenPortalDatabase::call($pdo, 'CALL sp_idioma_registrar_lote(?, ?, ?, ?, ?, ?, ?)', [
                    $grupo, (string)($titulo['pt'] ?? $grupo), (string)($titulo['en'] ?? ''), (string)($titulo['es'] ?? ''), $transacao,
                    json_encode(array_values($itens), JSON_UNESCAPED_UNICODE), $autor,
                ]);
                $total += (int)($rows[0]['novos'] ?? 0);
                foreach ($itens as $item) self::$cache[$grupo][$item['chave']] = ['pt' => $item['pt'], 'en' => $item['en'], 'es' => $item['es']];
            } catch (Throwable $e) {
                // Migração 212 ainda não aplicada ou banco indisponível: a tela segue com os textos do código.
                error_log('IdiomaService::gravarPendentes: ' . $e->getMessage());
            }
        }
        self::$pendentes = [];
        return $total;
    }

    private static function podeRegistrar(): bool
    {
        return PHP_SAPI !== 'cli' && !self::$indisponivel
            && strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) === 'ADMINISTRADOR' && !self::previa();
    }

    /** Põe um texto novo na fila de cadastro (só para ADMINISTRADOR; nunca sobrescreve o que existe). */
    private static function agendar(string $chave, array $padrao, ?string $tipo = null, ?string $descricao = null, ?int $ordem = null): void
    {
        if (!self::podeRegistrar() || !preg_match('/^[a-z0-9_]+(\.[A-Za-z0-9_]+)+$/', $chave) || strlen($chave) > 150) return;
        $grupo = strtoupper(self::grupoDaChave($chave));
        if ($grupo === 'MENUS' || isset(self::$pendentes[$grupo][$chave])) return;
        $pt = trim((string)($padrao['pt'] ?? ''));
        $en = trim((string)($padrao['en'] ?? ''));
        $es = trim((string)($padrao['es'] ?? ''));
        if ($pt === '' || preg_match('/[<>]/', $pt . $en . $es)) return;
        $partes = explode('.', $chave);
        $secao = strtolower($partes[1] ?? '');
        $tipos = ['coluna' => 'COLUNA', 'botao' => 'BOTAO', 'titulo' => 'TITULO', 'dica' => 'DICA', 'msg' => 'MENSAGEM', 'mensagem' => 'MENSAGEM', 'erro' => 'MENSAGEM', 'confirmacao' => 'MENSAGEM'];
        $nomes = ['COLUNA' => 'Coluna', 'BOTAO' => 'Botão', 'TITULO' => 'Título', 'DICA' => 'Dica', 'MENSAGEM' => 'Mensagem', 'ROTULO' => 'Texto'];
        $tipo = $tipo ?? ($tipos[$secao] ?? 'ROTULO');
        self::$pendentes[$grupo][$chave] = [
            'chave' => $chave, 'tipo' => $tipo, 'ordem' => $ordem ?? (5000 + 10 * count(self::$pendentes[$grupo] ?? [])),
            'descricao' => mb_substr($descricao ?? ($nomes[$tipo] . ': ' . $pt), 0, 300),
            'pt' => mb_substr($pt, 0, 1000), 'en' => mb_substr($en, 0, 1000), 'es' => mb_substr($es, 0, 1000),
        ];
        if (!self::$gravacaoAgendada) {
            self::$gravacaoAgendada = true;
            register_shutdown_function(static function (): void { self::gravarPendentes(); });
        }
    }

    /** Limpa o cache da requisição (usado após publicar). */
    public static function limparCache(): void
    {
        self::$cache = [];
    }

    /** As telas podem pedir textos antes de abrir a sessão (ex.: botões montados antes do render do grid). */
    private static function sessao(): void
    {
        if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli' && !headers_sent()) session_start();
    }

    private static function grupoDaChave(string $chave): string
    {
        $pos = strpos($chave, '.');
        return $pos === false ? '' : substr($chave, 0, $pos);
    }

    private static function primeiro(array $valores): string
    {
        foreach ($valores as $valor) {
            if (is_string($valor) && trim($valor) !== '') return $valor;
        }
        return '';
    }

    private static function conexao(): ?PDO
    {
        if (self::$pdo) return self::$pdo;
        if (self::$indisponivel) return null;
        try {
            $env = GreenPortalDatabase::env();
            self::$pdo = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
        } catch (Throwable $e) {
            self::$indisponivel = true;
            error_log('IdiomaService: ' . $e->getMessage());
            return null;
        }
        return self::$pdo;
    }
}
