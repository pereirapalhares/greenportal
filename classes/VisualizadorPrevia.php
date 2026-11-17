<?php
declare(strict_types=1);

/*
 * Regras do visualizador universal de arquivos (principal/visualizador_arquivo.php), usadas pela tela, pela API do GED
 * (principal/api/gestao_arquivos.php: action=previa_estado / previa) e pelo serviço scripts/visualizador-worker.php.
 *   pdf     -> PDF.js                      imagem -> <img> do navegador
 *   texto   -> lido e mostrado na tela     audio / video -> player do navegador
 *   previa  -> convertido pelo serviço agendado (LibreOffice; DWG pelo ODA File Converter + ezdxf) para PDF,
 *              ou para HTML no caso das planilhas; o resultado fica no cache storage/runtime/visualizador
 *   outro   -> sem visualização (só Baixar, com permissão)
 */
final class VisualizadorPrevia
{
    public const IMAGEM = ['png', 'jpg', 'jpeg', 'jfif', 'pjpeg', 'gif', 'bmp', 'webp', 'svg', 'ico', 'avif'];
    public const TEXTO = ['txt', 'text', 'log', 'csv', 'tsv', 'xml', 'json', 'md', 'markdown', 'ini', 'cfg', 'conf', 'sql', 'yml', 'yaml',
        'html', 'htm', 'css', 'js', 'ts', 'php', 'py', 'bat', 'cmd', 'ps1', 'sh', 'properties', 'env', 'nfo', 'srt', 'vtt', 'kml', 'gpx', 'geojson', 'ret', 'rem', 'ofx'];
    public const AUDIO = ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac', 'opus', 'weba'];
    public const VIDEO = ['mp4', 'm4v', 'webm', 'ogv', 'mov'];
    // Convertidos para PDF pelo LibreOffice
    public const OFFICE_PDF = ['doc', 'docx', 'docm', 'dot', 'dotx', 'dotm', 'rtf', 'odt', 'ott', 'fodt', 'wpd', 'wps', 'pages',
        'ppt', 'pptx', 'pptm', 'pps', 'ppsx', 'pot', 'potx', 'odp', 'otp', 'fodp', 'key',
        'odg', 'otg', 'vsd', 'vsdx', 'vdx', 'pub', 'cdr', 'emf', 'wmf', 'eps', 'tif', 'tiff', 'psd', 'tga', 'pcx'];
    // Convertidas para HTML (tabela navegável, todas as abas) pelo LibreOffice
    public const PLANILHA = ['xls', 'xlsx', 'xlsm', 'xlsb', 'xlt', 'xltx', 'ods', 'ots', 'fods', 'numbers'];
    // Desenhos: DWG -> DXF (ODA File Converter) -> PDF (ezdxf); DXF -> PDF direto
    public const CAD = ['dwg', 'dxf'];
    private const VERSAO_CACHE = 'v1';

    public static function formato(string $extensao): string
    {
        $e = strtolower(trim($extensao, '. '));
        return match (true) {
            $e === 'pdf' => 'pdf',
            in_array($e, self::IMAGEM, true) => 'imagem',
            in_array($e, self::TEXTO, true) => 'texto',
            in_array($e, self::AUDIO, true) => 'audio',
            in_array($e, self::VIDEO, true) => 'video',
            self::alvo($e) !== null => 'previa',
            default => 'outro',
        };
    }

    /** Formato gerado pela conversão: pdf, html (planilhas) ou null (não precisa ou não tem conversão). */
    public static function alvo(string $extensao): ?string
    {
        $e = strtolower(trim($extensao, '. '));
        if (in_array($e, self::PLANILHA, true)) return 'html';
        if (in_array($e, self::OFFICE_PDF, true) || in_array($e, self::CAD, true)) return 'pdf';
        return null;
    }

    /** Formatos que o navegador mostra sem conversão (a API entrega ao visualizador mesmo sem "Baixar/imprimir"). */
    public static function exibivelDireto(string $extensao): bool
    {
        return in_array(self::formato($extensao), ['pdf', 'imagem', 'texto', 'audio', 'video'], true);
    }

    /** Conteúdo ativo (HTML, SVG, XML) não pode rodar na origem do portal: a API envia com CSP sandbox. */
    public static function conteudoAtivo(string $extensao): bool
    {
        return in_array(strtolower($extensao), ['html', 'htm', 'svg', 'xml', 'xhtml', 'xsl', 'kml', 'gpx'], true);
    }

    public static function chave(string $arquivo, string $alvo): string
    {
        $real = realpath($arquivo) ?: $arquivo;
        clearstatcache(true, $real);
        return sha1(strtolower(str_replace('\\', '/', $real)) . '|' . (int)@filemtime($real) . '|' . (int)@filesize($real) . '|' . $alvo . '|' . self::VERSAO_CACHE);
    }

    public static function pastaCache(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'visualizador';
    }

    public static function arquivoCache(string $chave, string $alvo): string
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $chave) || !in_array($alvo, ['pdf', 'html'], true)) throw new InvalidArgumentException('Chave de prévia inválida.');
        return self::pastaCache() . DIRECTORY_SEPARATOR . $chave . '.' . $alvo;
    }

    /** Arquivo de prévia pronto (existe e não está vazio) ou null. */
    public static function cachePronto(string $chave, string $alvo): ?string
    {
        $f = self::arquivoCache($chave, $alvo);
        clearstatcache(true, $f);
        return is_file($f) && filesize($f) > 0 ? $f : null;
    }

    public static function mime(string $extensao, string $arquivo): string
    {
        $e = strtolower($extensao);
        $mapa = ['csv' => 'text/csv', 'tsv' => 'text/tab-separated-values', 'json' => 'application/json', 'geojson' => 'application/json', 'md' => 'text/plain',
            'svg' => 'image/svg+xml', 'webp' => 'image/webp', 'avif' => 'image/avif', 'jfif' => 'image/jpeg', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4',
            'aac' => 'audio/aac', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'opus' => 'audio/ogg', 'flac' => 'audio/flac', 'weba' => 'audio/webm',
            'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg', 'mov' => 'video/quicktime', 'pdf' => 'application/pdf'];
        if (isset($mapa[$e])) return $mapa[$e];
        if (in_array($e, self::TEXTO, true)) return 'text/plain';
        return (function_exists('mime_content_type') ? (mime_content_type($arquivo) ?: '') : '') ?: 'application/octet-stream';
    }

    /**
     * Envia um arquivo com suporte a Range (player de áudio/vídeo avança e volta sem baixar tudo).
     * $disposicao: inline | attachment.
     */
    public static function enviar(string $arquivo, string $mime, string $disposicao, string $nome, bool $sandbox = false): never
    {
        $tamanho = (int)filesize($arquivo);
        $inicio = 0; $fim = $tamanho - 1;
        $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
        if ($range !== '' && $tamanho > 0 && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') { $inicio = max(0, $tamanho - (int)$m[2]); }
            else { $inicio = (int)$m[1]; if ($m[2] !== '') $fim = min($fim, (int)$m[2]); }
            if ($inicio > $fim || $inicio >= $tamanho) { http_response_code(416); header('Content-Range: bytes */' . $tamanho); exit; }
            http_response_code(206);
            header('Content-Range: bytes ' . $inicio . '-' . $fim . '/' . $tamanho);
        }
        $nomeLimpo = str_replace(['"', "\r", "\n"], '', $nome);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $disposicao . '; filename="' . $nomeLimpo . '"; filename*=UTF-8\'\'' . rawurlencode($nome));
        header('Content-Length: ' . (string)($fim - $inicio + 1));
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        if ($sandbox) header("Content-Security-Policy: sandbox; default-src 'none'; img-src data:; style-src 'unsafe-inline'");
        while (ob_get_level() > 0) ob_end_clean();
        $h = fopen($arquivo, 'rb');
        if ($h === false) exit;
        if ($inicio > 0) fseek($h, $inicio);
        $resta = $fim - $inicio + 1;
        while ($resta > 0 && !feof($h)) {
            $bloco = fread($h, (int)min(1048576, $resta));
            if ($bloco === false || $bloco === '') break;
            echo $bloco; $resta -= strlen($bloco);
            flush();
        }
        fclose($h);
        exit;
    }
}
