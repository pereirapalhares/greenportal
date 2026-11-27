<?php
declare(strict_types=1);

require_once __DIR__ . '/IdiomaService.php';
require_once __DIR__ . '/GreenPortalDatabase.php';
require_once __DIR__ . '/UsuarioSessao.php';
require_once __DIR__ . '/PermissaoTransacao.php';
require_once __DIR__ . '/AuditService.php';

date_default_timezone_set('America/Sao_Paulo');   // datas gravadas pelo PHP (revogado em, lida em…) no horário de Brasília, como o MySQL

/**
 * Apoio às telas simples da Revisão G (migração 421): sessão, permissão do menu, leitura por sp_crud_select (em lotes, chaves em
 * MAIÚSCULAS), gravação por sp_crud_insert / sp_crud_update / sp_crud_delete, CSRF da tela e o cabeçalho/rodapé padrão.
 */
final class PaginaSimples
{
    public static PDO $pdo;
    public static array $perm = [];
    public static int $uid = 0;
    public static string $idioma = 'pt';

    public static function sair(int $codigo, string $mensagem, bool $json = false): never
    {
        http_response_code($codigo);
        if ($json) { header('Content-Type: application/json; charset=utf-8'); exit(json_encode(['ok' => false, 'message' => $mensagem], JSON_UNESCAPED_UNICODE)); }
        exit('<!doctype html><meta charset="utf-8"><body style="font:14px Arial;padding:16px">' . htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') . '</body>');
    }

    /** Abre a sessão, confere a permissão do menu (visualizar) e conecta no banco da empresa. */
    public static function abrir(string $menu, bool $json = false, bool $liberado = false): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) self::sair(401, IdiomaService::t('home.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.']), $json);
        self::$pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), (string)$_SESSION['tenant']['banco_dados'], 'GPT');
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (!UsuarioSessao::atualizar(self::$pdo, $_SESSION)) self::sair(401, 'Sessão expirada.', $json);
        self::$idioma = IdiomaService::idioma();
        self::$uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);
        self::$perm = PermissaoTransacao::obter($menu);
        if ($liberado) self::$perm['pode_visualizar'] = 1;
        if (empty(self::$perm['pode_visualizar'])) self::sair(403, self::L('Seu perfil não tem acesso a esta tela.', 'Your profile has no access to this screen.', 'Su perfil no tiene acceso a esta pantalla.'), $json);
    }

    public static function admin(): bool { return (string)($_SESSION['usuario']['codigo_perfil'] ?? '') === 'ADMINISTRADOR'; }
    public static function L(string $pt, string $en, string $es): string { return ['pt' => $pt, 'en' => $en, 'es' => $es][self::$idioma] ?? $pt; }
    public static function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
    public static function exato(mixed $v): array { return ['value' => (string)$v, 'exact' => true]; }

    public static function existe(string $objeto): bool
    {
        try { $s = self::$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'); $s->execute([$objeto]); return (int)$s->fetchColumn() > 0; } catch (Throwable) { return false; }
    }

    /** sp_crud_select em lotes de 1000; filtros [coluna => valor] viram filtros exatos. */
    public static function sel(string $view, array $filtros = [], int $max = 5000, ?PDO $pdo = null, string $busca = ''): array
    {
        $pdo ??= self::$pdo;
        $f = [];
        foreach ($filtros as $k => $v) $f[$k] = is_array($v) ? $v : self::exato($v);
        $out = [];
        for ($off = 0; $off < $max; $off += 1000) {
            $s = $pdo->prepare('CALL sp_crud_select(:t, :f, :b, :n, :o)');
            $s->bindValue('t', $view); $s->bindValue('f', json_encode($f ?: new stdClass(), JSON_UNESCAPED_UNICODE)); $s->bindValue('b', $busca === '' ? null : $busca);
            $s->bindValue('n', min(1000, $max - $off), PDO::PARAM_INT); $s->bindValue('o', $off, PDO::PARAM_INT);
            $s->execute(); $lote = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
            foreach ($lote as $l) $out[] = array_change_key_case($l, CASE_UPPER);
            if (count($lote) < 1000) break;
        }
        return $out;
    }

    public static function ins(string $tabela, array $dados, ?PDO $pdo = null): void
    {
        $s = ($pdo ?? self::$pdo)->prepare('CALL sp_crud_insert(:t, :d)'); $s->execute(['t' => $tabela, 'd' => json_encode($dados, JSON_UNESCAPED_UNICODE)]); $s->closeCursor();
    }
    public static function upd(string $tabela, array $chave, array $dados, ?PDO $pdo = null): void
    {
        $s = ($pdo ?? self::$pdo)->prepare('CALL sp_crud_update(:t, :k, :d)'); $s->execute(['t' => $tabela, 'k' => json_encode($chave), 'd' => json_encode($dados, JSON_UNESCAPED_UNICODE)]); $s->closeCursor();
    }
    public static function del(string $tabela, array $chave, ?PDO $pdo = null): void
    {
        $s = ($pdo ?? self::$pdo)->prepare('CALL sp_crud_delete(:t, :k)'); $s->execute(['t' => $tabela, 'k' => json_encode($chave)]); $s->closeCursor();
    }

    public static function csrf(string $nome): string
    {
        if (empty($_SESSION[$nome])) $_SESSION[$nome] = bin2hex(random_bytes(16));
        return (string)$_SESSION[$nome];
    }
    public static function conferirCsrf(string $nome, bool $json = false): void
    {
        if (!hash_equals((string)($_SESSION[$nome] ?? ''), (string)($_POST['csrf'] ?? ''))) self::sair(400, self::L('A página expirou. Abra de novo.', 'The page expired. Open it again.', 'La página expiró. Ábrala de nuevo.'), $json);
    }
    public static function pode(string $acao): bool { return !empty(self::$perm['pode_' . $acao]); }

    public static function usuarios(): array
    {
        $out = [];
        foreach (self::existe('vw_resp_regras_pessoas') ? self::sel('vw_resp_regras_pessoas', [], 20000) : self::sel('usuarios', ['ativo' => 1], 20000) as $u)
            $out[(int)$u['CODUSUARIO']] = trim((string)($u['NOME'] ?? '')) ?: (string)($u['USERID'] ?? $u['CODUSUARIO']);
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    public static function cabecalho(string $titulo, string $icone, string $transacao, string $css = ''): void
    {
        $theme = in_array((string)($_SESSION['layout_tema'] ?? ''), ['verde', 'azul', 'cinza'], true) ? (string)$_SESSION['layout_tema'] : 'verde';
        echo '<!doctype html><html lang="' . (self::$idioma === 'pt' ? 'pt-BR' : self::$idioma) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::e($titulo) . '</title>'
            . '<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css?v=3" rel="stylesheet">';
        require_once dirname(__DIR__) . '/components/pagina_padrao.php';
        greenPortalEstiloPagina();
        echo '<style>body{font-size:13px}.ps{padding:14px 16px}.ps-ajuda{color:var(--gp-muted,#6c757d);max-width:1100px}.ps table td,.ps table th{vertical-align:middle}' . $css . '</style></head>'
            . '<body class="theme-' . self::e($theme) . '" data-transacao="' . self::e($transacao) . '"><main class="ps"><h5 class="mb-1"><i class="bi ' . self::e($icone) . ' me-2"></i>' . self::e($titulo) . '</h5>';
    }
    public static function rodape(string $scripts = ''): void
    {
        echo '</main><script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script>' . $scripts . '</body></html>';
    }

    /** Lista de <option>; mantém o valor atual mesmo fora da lista. */
    public static function opcoes(array $lista, mixed $atual, string $vazio = ''): string
    {
        $h = $vazio !== '' || $atual === null || $atual === '' ? '<option value="">' . self::e($vazio) . '</option>' : '';
        $chaves = array_map('strval', array_keys($lista));
        if ((string)$atual !== '' && !in_array((string)$atual, $chaves, true)) $h .= '<option value="' . self::e($atual) . '" selected>#' . self::e($atual) . '</option>';
        foreach ($lista as $k => $v) $h .= '<option value="' . self::e($k) . '"' . ((string)$k === (string)$atual ? ' selected' : '') . '>' . self::e($v) . '</option>';
        return $h;
    }
}
