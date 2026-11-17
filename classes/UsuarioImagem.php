<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';

/**
 * Idioma do cadastro do usuário, foto do usuário e logo da empresa (pedido GER-1/GER-18 de docs/01_Mudancas_Gerais.txt;
 * decisões do usuário de 12/11/2026: "Cadastro manda", logo no cabeçalho e no login trocado pelo ADMINISTRADOR e pelo ADM DA
 * EMPRESA, foto enviada pelo próprio usuário e removível pelo ADM).
 *
 * - Idioma: preferencias_usuario (chave "idioma"), gravado só por sp_crud_*. Depois do login vale o do cadastro.
 * - Foto: storage/usuarios_fotos/<banco>/<codusuario>.jpg — recortada em quadrado 256×256 e regravada pelo GD (o arquivo
 *   enviado nunca é guardado como veio: tira metadados e qualquer conteúdo que não seja imagem). Até 2 MB, JPG/PNG/WebP.
 * - Logo: storage/empresa_logos/<banco>.png — até 1 MB, JPG/PNG/WebP, reduzido para no máximo 480×160 mantendo a proporção
 *   e a transparência.
 * As pastas ganham .htaccess "Require all denied": as imagens só saem pelos PHPs que conferem a sessão (usuario_foto.php,
 * empresa_logo_img.php) ou, no caso do logo, por logo_empresa.php (tela de login, empresas da lista pública).
 */
final class UsuarioImagem
{
    public const FOTO_MAX = 2 * 1024 * 1024;
    public const LOGO_MAX = 1 * 1024 * 1024;
    private const FOTO_LADO = 256;
    private const LOGO_LARGURA = 480;
    private const LOGO_ALTURA = 160;

    // ------------------------------------------------------------ idioma do cadastro
    public static function idioma(PDO $pdo, int $codusuario): ?string
    {
        $v = self::preferencia($pdo, $codusuario, 'idioma');
        return in_array($v, ['pt', 'en', 'es'], true) ? $v : null;
    }

    public static function salvarIdioma(PDO $pdo, int $codusuario, string $idioma): string
    {
        $idioma = strtolower(trim($idioma));
        if (!in_array($idioma, ['pt', 'en', 'es'], true)) throw new InvalidArgumentException('Idioma inválido.');
        self::gravarPreferencia($pdo, $codusuario, 'idioma', $idioma);
        return $idioma;
    }

    // ------------------------------------------------------------ foto do usuário
    public static function fotoArquivo(string $banco, int $codusuario): string
    {
        return self::pasta('usuarios_fotos' . DIRECTORY_SEPARATOR . self::banco($banco)) . DIRECTORY_SEPARATOR . $codusuario . '.jpg';
    }

    /** Versão da foto (mtime) ou 0 quando não tem: vai na URL para o navegador não mostrar a antiga. */
    public static function fotoVersao(string $banco, int $codusuario): int
    {
        $f = self::fotoArquivo($banco, $codusuario);
        return is_file($f) ? (int)filemtime($f) : 0;
    }

    public static function salvarFoto(string $banco, int $codusuario, array $upload): void
    {
        $img = self::lerUpload($upload, self::FOTO_MAX);
        $w = imagesx($img); $h = imagesy($img); $lado = min($w, $h);
        $saida = imagecreatetruecolor(self::FOTO_LADO, self::FOTO_LADO);
        imagefill($saida, 0, 0, imagecolorallocate($saida, 255, 255, 255));
        imagecopyresampled($saida, $img, 0, 0, intdiv($w - $lado, 2), intdiv($h - $lado, 2), self::FOTO_LADO, self::FOTO_LADO, $lado, $lado);
        $destino = self::fotoArquivo($banco, $codusuario);
        $tmp = $destino . '.tmp';
        if (!imagejpeg($saida, $tmp, 86) || !rename($tmp, $destino)) { @unlink($tmp); throw new RuntimeException('Não foi possível gravar a foto.'); }
        imagedestroy($img); imagedestroy($saida);
    }

    public static function removerFoto(string $banco, int $codusuario): bool
    {
        $f = self::fotoArquivo($banco, $codusuario);
        return is_file($f) && unlink($f);
    }

    // ------------------------------------------------------------ logo da empresa
    public static function logoArquivo(string $banco): string
    {
        return self::pasta('empresa_logos') . DIRECTORY_SEPARATOR . self::banco($banco) . '.png';
    }

    public static function logoVersao(string $banco): int
    {
        $f = self::logoArquivo($banco);
        return is_file($f) ? (int)filemtime($f) : 0;
    }

    public static function salvarLogo(string $banco, array $upload): void
    {
        $img = self::lerUpload($upload, self::LOGO_MAX);
        $w = imagesx($img); $h = imagesy($img);
        $escala = min(1, self::LOGO_LARGURA / $w, self::LOGO_ALTURA / $h);
        $nw = max(1, (int)round($w * $escala)); $nh = max(1, (int)round($h * $escala));
        $saida = imagecreatetruecolor($nw, $nh);
        imagealphablending($saida, false); imagesavealpha($saida, true);
        imagefill($saida, 0, 0, imagecolorallocatealpha($saida, 255, 255, 255, 127));
        imagecopyresampled($saida, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $destino = self::logoArquivo($banco);
        $tmp = $destino . '.tmp';
        if (!imagepng($saida, $tmp, 6) || !rename($tmp, $destino)) { @unlink($tmp); throw new RuntimeException('Não foi possível gravar o logo.'); }
        imagedestroy($img); imagedestroy($saida);
    }

    public static function removerLogo(string $banco): bool
    {
        $f = self::logoArquivo($banco);
        return is_file($f) && unlink($f);
    }

    /** Envia a imagem ao navegador (cache privado; a URL leva a versão). */
    public static function enviar(string $arquivo, string $tipo): never
    {
        if (!is_file($arquivo)) { http_response_code(404); exit; }
        header('Content-Type: ' . $tipo);
        header('Content-Length: ' . (string)filesize($arquivo));
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline');
        readfile($arquivo);
        exit;
    }

    // ------------------------------------------------------------ auxiliares
    private static function lerUpload(array $upload, int $max): GdImage
    {
        if (!function_exists('imagecreatefromstring')) throw new RuntimeException('O servidor não tem a extensão GD do PHP.');
        $erro = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($erro === UPLOAD_ERR_INI_SIZE || $erro === UPLOAD_ERR_FORM_SIZE) throw new InvalidArgumentException('TAMANHO');
        if ($erro !== UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) throw new InvalidArgumentException('ARQUIVO');
        if ((int)($upload['size'] ?? 0) > $max) throw new InvalidArgumentException('TAMANHO');
        $info = @getimagesize((string)$upload['tmp_name']);
        if (!$info || !in_array((int)$info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) throw new InvalidArgumentException('TIPO');
        if ((int)$info[0] > 6000 || (int)$info[1] > 6000) throw new InvalidArgumentException('DIMENSAO');
        $img = @imagecreatefromstring((string)file_get_contents((string)$upload['tmp_name']));
        if (!$img) throw new InvalidArgumentException('TIPO');
        return $img;
    }

    private static function pasta(string $relativa): string
    {
        $raiz = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
        $dir = $raiz . DIRECTORY_SEPARATOR . $relativa;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Não foi possível criar a pasta de imagens.');
        foreach ([$raiz . DIRECTORY_SEPARATOR . 'usuarios_fotos', $raiz . DIRECTORY_SEPARATOR . 'empresa_logos'] as $p)
            if (is_dir($p) && !is_file($p . DIRECTORY_SEPARATOR . '.htaccess')) @file_put_contents($p . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\n");
        return $dir;
    }

    private static function banco(string $banco): string
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $banco)) throw new InvalidArgumentException('Empresa inválida.');
        return strtolower($banco);
    }

    private static function preferencia(PDO $pdo, int $codusuario, string $chave): ?string
    {
        try {
            $s = $pdo->prepare('CALL sp_crud_select(:t, :f, NULL, 5, 0)');
            $s->execute(['t' => 'preferencias_usuario', 'f' => json_encode(['codusuario' => ['value' => (string)$codusuario, 'exact' => true], 'chave' => ['value' => $chave, 'exact' => true]])]);
            $linhas = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
        } catch (Throwable $e) { error_log('UsuarioImagem preferencia: ' . $e->getMessage()); return null; }
        foreach ($linhas as $l) if ((int)$l['codusuario'] === $codusuario && (string)$l['chave'] === $chave) return (string)$l['valor'];
        return null;
    }

    private static function gravarPreferencia(PDO $pdo, int $codusuario, string $chave, string $valor): void
    {
        $s = $pdo->prepare('CALL sp_crud_select(:t, :f, NULL, 5, 0)');
        $s->execute(['t' => 'preferencias_usuario', 'f' => json_encode(['codusuario' => ['value' => (string)$codusuario, 'exact' => true], 'chave' => ['value' => $chave, 'exact' => true]])]);
        $atual = null;
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $l) if ((int)$l['codusuario'] === $codusuario && (string)$l['chave'] === $chave) $atual = $l;
        $s->closeCursor();
        if ($atual) {
            $u = $pdo->prepare('CALL sp_crud_update(:t, :k, :d)');
            $u->execute(['t' => 'preferencias_usuario', 'k' => json_encode(['codpreferenciausuario' => (int)$atual['codpreferenciausuario']]), 'd' => json_encode(['valor' => $valor])]);
        } else {
            $u = $pdo->prepare('CALL sp_crud_insert(:t, :d)');
            $u->execute(['t' => 'preferencias_usuario', 'd' => json_encode(['codusuario' => $codusuario, 'chave' => $chave, 'valor' => $valor])]);
        }
        $u->closeCursor();
    }
}
