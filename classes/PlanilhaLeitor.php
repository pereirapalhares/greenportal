<?php
declare(strict_types=1);

/**
 * Leitura da primeira planilha de um arquivo Excel ou CSV, sem bibliotecas externas (migração 397).
 *   .xls  (Excel 97-2003, BIFF8 dentro de um arquivo OLE2)
 *   .xlsx (Office Open XML, pelo ZipArchive)
 *   .csv  (separador ; , ou tab; UTF-8 ou Windows-1252)
 * Devolve [linha => [coluna => texto]] com linha e coluna a partir de 1 (A = 1), só as células preenchidas.
 * Números inteiros saem sem casas decimais (1 e não 1.0). Datas saem como o número de série do Excel.
 */
final class PlanilhaLeitor
{
    public const LIMITE_BYTES = 20 * 1024 * 1024;

    public static function ler(string $arquivo, string $nomeOriginal = ''): array
    {
        if (!is_file($arquivo)) throw new RuntimeException('Arquivo não encontrado.');
        if (filesize($arquivo) > self::LIMITE_BYTES) throw new RuntimeException('Arquivo maior que 20 MB.');
        $inicio = (string)file_get_contents($arquivo, false, null, 0, 8);
        if ($inicio === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") return self::lerXls($arquivo);
        if (str_starts_with($inicio, "PK\x03\x04")) return self::lerXlsx($arquivo);
        $ext = strtolower(pathinfo($nomeOriginal !== '' ? $nomeOriginal : $arquivo, PATHINFO_EXTENSION));
        if (in_array($ext, ['csv', 'txt'], true)) return self::lerCsv($arquivo);
        throw new RuntimeException('Formato não reconhecido. Envie a planilha em .xls, .xlsx ou .csv.');
    }

    // ------------------------------------------------------------------ CSV
    private static function lerCsv(string $arquivo): array
    {
        $texto = (string)file_get_contents($arquivo);
        if (str_starts_with($texto, "\xEF\xBB\xBF")) $texto = substr($texto, 3);
        if (!mb_check_encoding($texto, 'UTF-8')) $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        $primeira = strtok($texto, "\n") ?: '';
        $sep = ';';
        foreach ([';', "\t", ','] as $s) if (substr_count($primeira, $s) > substr_count($primeira, $sep)) $sep = $s;
        $h = fopen('php://memory', 'w+');
        fwrite($h, $texto); rewind($h);
        $out = []; $l = 0;
        while (($campos = fgetcsv($h, 0, $sep, '"', '')) !== false) {
            $l++;
            foreach ($campos as $i => $v) { $v = trim((string)$v); if ($v !== '') $out[$l][$i + 1] = $v; }
        }
        fclose($h);
        return $out;
    }

    // ------------------------------------------------------------------ XLSX
    private static function lerXlsx(string $arquivo): array
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('A extensão zip do PHP não está ativa (necessária para .xlsx).');
        $zip = new ZipArchive();
        if ($zip->open($arquivo) !== true) throw new RuntimeException('Não foi possível abrir o .xlsx.');
        try {
            $xml = static function (string $nome) use ($zip): ?SimpleXMLElement {
                $c = $zip->getFromName($nome);
                if ($c === false) return null;
                $x = @simplexml_load_string($c, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
                return $x === false ? null : $x;
            };
            $wb = $xml('xl/workbook.xml');
            if (!$wb) throw new RuntimeException('Planilha .xlsx inválida (workbook.xml).');
            $wb->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $folhas = $wb->xpath('//m:sheets/m:sheet') ?: [];
            if (!$folhas) throw new RuntimeException('A planilha não tem abas.');
            $rid = (string)($folhas[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
            $caminho = 'xl/worksheets/sheet1.xml';
            $rels = $xml('xl/_rels/workbook.xml.rels');
            if ($rels && $rid !== '') {
                foreach ($rels->children() as $r) {
                    if ((string)$r['Id'] !== $rid) continue;
                    $alvo = (string)$r['Target'];
                    $caminho = str_starts_with($alvo, '/') ? ltrim($alvo, '/') : 'xl/' . $alvo;
                }
            }
            $sst = [];
            if ($ss = $xml('xl/sharedStrings.xml')) {
                foreach ($ss->si as $si) {
                    if (isset($si->t)) { $sst[] = (string)$si->t; continue; }
                    $t = ''; foreach ($si->r as $r) $t .= (string)$r->t; $sst[] = $t;
                }
            }
            $folha = $xml($caminho);
            if (!$folha) throw new RuntimeException('Planilha .xlsx inválida (aba).');
            $out = [];
            foreach ($folha->sheetData->row ?? [] as $row) {
                foreach ($row->c as $c) {
                    if (!preg_match('/^([A-Z]+)(\d+)$/', (string)$c['r'], $m)) continue;
                    $col = 0; foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
                    $t = (string)$c['t'];
                    if ($t === 's') $v = $sst[(int)$c->v] ?? '';
                    elseif ($t === 'inlineStr') { $v = isset($c->is->t) ? (string)$c->is->t : ''; if ($v === '' && isset($c->is->r)) foreach ($c->is->r as $r) $v .= (string)$r->t; }
                    elseif ($t === 'b') $v = ((string)$c->v) === '1' ? 'VERDADEIRO' : 'FALSO';
                    else $v = self::numero((string)$c->v);
                    $v = trim($v);
                    if ($v !== '') $out[(int)$m[2]][$col] = $v;
                }
            }
            return $out;
        } finally {
            $zip->close();
        }
    }

    // ------------------------------------------------------------------ XLS (OLE2 + BIFF8)
    private static function lerXls(string $arquivo): array
    {
        $dados = (string)file_get_contents($arquivo);
        $book = self::oleStream($dados, ['Workbook', 'Book']);
        return self::biff($book);
    }

    /** Conteúdo do fluxo (stream) com um dos nomes, dentro do arquivo OLE2 (Compound File Binary). */
    private static function oleStream(string $d, array $nomes): string
    {
        $u16 = static fn(int $o): int => unpack('v', substr($d, $o, 2))[1];
        $u32 = static fn(int $o): int => unpack('V', substr($d, $o, 4))[1];
        if (strlen($d) < 512) throw new RuntimeException('Arquivo .xls inválido.');
        $tamSetor = 1 << $u16(0x1E);
        $tamMini = 1 << $u16(0x20);
        $nFat = $u32(0x2C);
        $dirInicio = $u32(0x30);
        $corteMini = $u32(0x38);
        $miniFatInicio = $u32(0x3C);
        $difatInicio = $u32(0x44);
        $nDifat = $u32(0x48);
        $setor = static fn(int $s): string => substr($d, ($s + 1) * $tamSetor, $tamSetor);
        // Setores da FAT: 109 no cabeçalho + cadeia DIFAT
        $fatSetores = [];
        for ($i = 0; $i < 109 && count($fatSetores) < $nFat; $i++) { $s = $u32(0x4C + $i * 4); if ($s < 0xFFFFFFFA) $fatSetores[] = $s; }
        $s = $difatInicio; $guarda = 0;
        while ($nDifat-- > 0 && $s < 0xFFFFFFFA && $guarda++ < 100000) {
            $bloco = $setor($s); $n = intdiv($tamSetor, 4) - 1;
            for ($i = 0; $i < $n && count($fatSetores) < $nFat; $i++) { $v = unpack('V', substr($bloco, $i * 4, 4))[1]; if ($v < 0xFFFFFFFA) $fatSetores[] = $v; }
            $s = unpack('V', substr($bloco, $n * 4, 4))[1];
        }
        $fatBin = ''; foreach ($fatSetores as $fs) $fatBin .= $setor($fs);
        $fat = array_values(unpack('V*', $fatBin) ?: []);
        $cadeia = static function (int $inicio, array $tabela) : array {
            $out = []; $s = $inicio; $guarda = 0;
            while ($s < 0xFFFFFFFA && isset($tabela[$s]) && $guarda++ < 1000000) { $out[] = $s; $s = $tabela[$s]; }
            if ($s < 0xFFFFFFFA && !isset($tabela[$s]) && $s >= 0) $out[] = $s;
            return $out;
        };
        $lerCadeia = static function (int $inicio) use ($cadeia, $fat, $setor): string { $b = ''; foreach ($cadeia($inicio, $fat) as $s) $b .= $setor($s); return $b; };
        $dir = $lerCadeia($dirInicio);
        $entradas = [];
        for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
            $len = unpack('v', substr($dir, $o + 0x40, 2))[1];
            $nome = $len >= 2 ? mb_convert_encoding(substr($dir, $o, $len - 2), 'UTF-8', 'UTF-16LE') : '';
            $entradas[] = ['nome' => $nome, 'tipo' => ord($dir[$o + 0x42]), 'inicio' => unpack('V', substr($dir, $o + 0x74, 4))[1], 'tamanho' => unpack('V', substr($dir, $o + 0x78, 4))[1]];
        }
        $raiz = $entradas[0] ?? null;
        foreach ($entradas as $e) {
            if ($e['tipo'] !== 2 || !in_array($e['nome'], $nomes, true)) continue;
            if ($e['tamanho'] >= $corteMini) return substr($lerCadeia($e['inicio']), 0, $e['tamanho']);
            // Fluxo pequeno: está no mini fluxo da raiz
            $miniFatBin = $miniFatInicio < 0xFFFFFFFA ? $lerCadeia($miniFatInicio) : '';
            $miniFat = array_values(unpack('V*', $miniFatBin) ?: []);
            $miniFluxo = $raiz ? $lerCadeia($raiz['inicio']) : '';
            $b = ''; foreach ($cadeia($e['inicio'], $miniFat) as $ms) $b .= substr($miniFluxo, $ms * $tamMini, $tamMini);
            return substr($b, 0, $e['tamanho']);
        }
        throw new RuntimeException('Arquivo .xls sem a pasta de trabalho (Workbook).');
    }

    /** Registros BIFF8 da primeira aba de planilha. */
    private static function biff(string $b): array
    {
        $tam = strlen($b);
        $registros = static function (int $pos) use ($b, $tam): Generator {
            while ($pos + 4 <= $tam) {
                ['id' => $id, 'len' => $len] = unpack('vid/vlen', substr($b, $pos, 4));
                yield $pos => [$id, substr($b, $pos + 4, $len)];
                $pos += 4 + $len;
            }
        };
        // Globais: BOUNDSHEET (aba) e SST (textos compartilhados, com CONTINUE)
        $abas = []; $sstPartes = null; $versao = 0x0600;
        foreach ($registros(0) as [$id, $dado]) {
            if ($id === 0x0809 && $versao === 0x0600) $versao = unpack('v', substr($dado, 0, 2))[1];
            if ($id === 0x0085) { $abas[] = ['pos' => unpack('V', substr($dado, 0, 4))[1], 'tipo' => ord($dado[5] ?? "\0")]; }
            elseif ($id === 0x00FC) $sstPartes = [$dado];
            elseif ($id === 0x003C && $sstPartes !== null) $sstPartes[] = $dado;
            if ($id === 0x000A) break; // EOF dos globais
        }
        if ($versao !== 0x0600) throw new RuntimeException('Versão antiga do Excel (anterior ao 97). Salve a planilha como .xlsx ou .xls 97-2003.');
        $sst = $sstPartes ? self::sst($sstPartes) : [];
        $aba = null; foreach ($abas as $a) if ($a['tipo'] === 0) { $aba = $a; break; }
        if ($aba === null) throw new RuntimeException('A planilha não tem abas de dados.');
        $out = [];
        $poe = static function (int $r, int $c, string $v) use (&$out): void { $v = trim($v); if ($v !== '') $out[$r + 1][$c + 1] = $v; };
        $formulaTexto = null;
        foreach ($registros($aba['pos']) as $pos => [$id, $d]) {
            if ($pos !== $aba['pos'] && $id === 0x0809) break;
            switch ($id) {
                case 0x00FD: // LABELSST
                    $x = unpack('vr/vc/vxf/Vi', $d); $poe($x['r'], $x['c'], (string)($sst[$x['i']] ?? '')); break;
                case 0x0204: // LABEL
                    $x = unpack('vr/vc', $d); $poe($x['r'], $x['c'], self::textoBiff(substr($d, 6), 2)); break;
                case 0x00D6: // RSTRING
                    $x = unpack('vr/vc', $d); $poe($x['r'], $x['c'], self::textoBiff(substr($d, 6), 2)); break;
                case 0x0203: // NUMBER
                    $x = unpack('vr/vc/vxf/ev', $d); $poe($x['r'], $x['c'], self::numero((string)$x['v'])); break;
                case 0x027E: // RK
                    $x = unpack('vr/vc/vxf/Vrk', $d); $poe($x['r'], $x['c'], self::numero((string)self::rk($x['rk']))); break;
                case 0x00BD: // MULRK
                    $x = unpack('vr/vc', $d); $n = intdiv(strlen($d) - 6, 6);
                    for ($i = 0; $i < $n; $i++) { $rk = unpack('V', substr($d, 4 + $i * 6 + 2, 4))[1]; $poe($x['r'], $x['c'] + $i, self::numero((string)self::rk($rk))); }
                    break;
                case 0x0205: // BOOLERR
                    $x = unpack('vr/vc/vxf/Cv/Ce', $d); if ($x['e'] === 0) $poe($x['r'], $x['c'], $x['v'] ? 'VERDADEIRO' : 'FALSO'); break;
                case 0x0006: // FORMULA: resultado numérico, ou texto no registro STRING seguinte
                    $x = unpack('vr/vc', $d); $res = substr($d, 6, 8);
                    if (substr($res, 6, 2) === "\xFF\xFF") { if (ord($res[0]) === 0) $formulaTexto = [$x['r'], $x['c']]; elseif (ord($res[0]) === 1) $poe($x['r'], $x['c'], ord($res[2]) ? 'VERDADEIRO' : 'FALSO'); }
                    else $poe($x['r'], $x['c'], self::numero((string)unpack('e', $res)[1]));
                    break;
                case 0x0207: // STRING (resultado de fórmula)
                    if ($formulaTexto) { $poe($formulaTexto[0], $formulaTexto[1], self::textoBiff($d, 2)); $formulaTexto = null; } break;
            }
            if ($id === 0x000A) break;
        }
        ksort($out); foreach ($out as &$l) ksort($l); unset($l);
        return $out;
    }

    /** Texto BIFF8 isolado (LABEL, STRING): tamanho de 1 ou 2 bytes, opções, caracteres de 1 ou 2 bytes. */
    private static function textoBiff(string $d, int $bytesTamanho): string
    {
        $n = $bytesTamanho === 2 ? unpack('v', substr($d, 0, 2))[1] : ord($d[0]);
        $op = ord($d[$bytesTamanho] ?? "\0");
        $p = $bytesTamanho + 1;
        if ($op & 0x08) $p += 2;
        if ($op & 0x04) $p += 4;
        return ($op & 0x01) ? mb_convert_encoding(substr($d, $p, $n * 2), 'UTF-8', 'UTF-16LE') : mb_convert_encoding(substr($d, $p, $n), 'UTF-8', 'Windows-1252');
    }

    /** Tabela SST com as quebras em registros CONTINUE (cada continuação de texto recomeça com o byte de opções). */
    private static function sst(array $partes): array
    {
        $parte = 0; $pos = 8; $dado = $partes[0];
        $total = unpack('V', substr($dado, 4, 4))[1];
        $byte = static function () use (&$parte, &$pos, &$dado, $partes): int {
            while ($pos >= strlen($dado)) { $parte++; if (!isset($partes[$parte])) throw new RuntimeException('SST incompleta.'); $dado = $partes[$parte]; $pos = 0; }
            return ord($dado[$pos++]);
        };
        $pular = static function (int $n) use ($byte): void { for ($i = 0; $i < $n; $i++) $byte(); };
        $out = [];
        try {
            for ($k = 0; $k < $total; $k++) {
                $n = $byte() | ($byte() << 8);
                $op = $byte();
                $runs = 0; $ext = 0;
                if ($op & 0x08) $runs = $byte() | ($byte() << 8);
                if ($op & 0x04) $ext = $byte() | ($byte() << 8) | ($byte() << 16) | ($byte() << 24);
                $largo = (bool)($op & 0x01);
                $txt = ''; $lidos = 0;
                while ($lidos < $n) {
                    if ($pos >= strlen($dado)) { // texto continua no próximo CONTINUE: novo byte de opções
                        $parte++; if (!isset($partes[$parte])) break 2;
                        $dado = $partes[$parte]; $pos = 0;
                        $largo = (bool)(ord($dado[$pos++]) & 0x01);
                    }
                    $cabem = $largo ? intdiv(strlen($dado) - $pos, 2) : strlen($dado) - $pos;
                    $q = min($n - $lidos, $cabem);
                    if ($q <= 0) { $pos = strlen($dado); continue; }
                    $pedaco = substr($dado, $pos, $largo ? $q * 2 : $q);
                    $txt .= $largo ? mb_convert_encoding($pedaco, 'UTF-8', 'UTF-16LE') : mb_convert_encoding($pedaco, 'UTF-8', 'Windows-1252');
                    $pos += $largo ? $q * 2 : $q; $lidos += $q;
                }
                $pular($runs * 4 + $ext);
                $out[] = $txt;
            }
        } catch (RuntimeException) { /* SST truncada: usa o que foi lido */ }
        return $out;
    }

    private static function rk(int $rk): float
    {
        $cem = $rk & 1; $inteiro = $rk & 2;
        if ($inteiro) { $v = $rk >> 2; if ($v & 0x20000000) $v -= 0x40000000; $v = (float)$v; }
        else $v = unpack('e', pack('VV', 0, $rk & 0xFFFFFFFC))[1];
        return $cem ? $v / 100 : $v;
    }

    private static function numero(string $v): string
    {
        if ($v === '' || !is_numeric($v)) return $v;
        $f = (float)$v;
        if (floor($f) === $f && abs($f) < 1e15) return (string)(int)$f;
        return rtrim(rtrim(sprintf('%.10F', $f), '0'), '.');
    }
}
