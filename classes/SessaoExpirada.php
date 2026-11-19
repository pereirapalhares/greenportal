<?php
declare(strict_types=1);

/**
 * Sessão expirada em qualquer tela (pedido do usuário de 19/11/2026: "nunca deve aparecer Sessão expirada, e sim voltar
 * para a tela de login"). Instalado por GreenPortalDatabase.php, que todas as telas carregam.
 *
 * Quando uma tela de principal/ ou components/ termina com HTTP 401 (as telas fazem exit('Sessão expirada.') em vários
 * formatos), a resposta é trocada no fim do script:
 *   - abertura de página/iframe/popup: no lugar do texto vai uma página que leva a janela principal para o login
 *     (index.php?erro=sessao_expirada);
 *   - chamada AJAX (fetch/XHR): mantém o corpo e o 401 e acrescenta o cabeçalho X-Sessao-Expirada: 1; o
 *     assets/js/sessao-expirada.js (carregado no main.php) intercepta e leva ao login.
 */
final class SessaoExpirada
{
    private static bool $instalado = false;

    public static function instalar(): void
    {
        if (self::$instalado || PHP_SAPI === 'cli') return;
        self::$instalado = true;
        if (self::rotaPortal() === null) return;
        ob_start(null, 65536); // segura respostas pequenas (como a de 401) até o fim; downloads grandes seguem em blocos
        register_shutdown_function([self::class, 'aoFinalizar']);
    }

    /** Caminho do script relativo à raiz do portal, só para telas de principal/ e components/; senão null. */
    private static function rotaPortal(): ?string
    {
        $arquivo = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $raiz = realpath(dirname(__DIR__));
        if ($arquivo === false || $raiz === false) return null;
        $arquivo = str_replace('\\', '/', $arquivo); $raiz = rtrim(str_replace('\\', '/', $raiz), '/') . '/';
        if (stripos($arquivo, $raiz) !== 0) return null;
        $rel = substr($arquivo, strlen($raiz));
        return preg_match('#^(principal|components)/#i', $rel) ? $rel : null;
    }

    public static function urlLogin(): string
    {
        $rel = (string)self::rotaPortal();
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = $script;
        for ($i = substr_count($rel, '/') + 1; $i > 0; $i--) $base = dirname($base);
        $base = str_replace('\\', '/', $base);
        $base = $base === '/' || $base === '.' ? '' : rtrim($base, '/');
        return $base . '/index.php?erro=sessao_expirada';
    }

    private static function ehNavegacao(): bool
    {
        $destino = strtolower((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
        if ($destino !== '') return in_array($destino, ['document', 'iframe', 'frame', 'embed', 'object'], true);
        if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') return false;
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'text/html');
    }

    public static function aoFinalizar(): void
    {
        if (http_response_code() !== 401) return;
        $login = self::urlLogin();
        if (!self::ehNavegacao()) {
            if (!headers_sent()) { header('X-Sessao-Expirada: 1'); header('Access-Control-Expose-Headers: X-Sessao-Expirada'); }
            return;
        }
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store');
            header('X-Sessao-Expirada: 1');
        }
        $u = json_encode($login, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $h = htmlspecialchars($login, ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="3;url=' . $h . '"><title>GreenPortal</title></head><body>'
            . '<script>(function(){var u=' . $u . ';try{window.top.location.replace(u);}catch(e){window.location.replace(u);}}());</script>'
            . '<noscript><a href="' . $h . '">Login</a></noscript></body></html>';
    }
}
