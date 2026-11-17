<?php
declare(strict_types=1);

/**
 * Planilha .xlsx da Pesquisa de documentos, no mesmo layout das exportações dos grids (ExportacaoGridWorker::createProfessionalXlsx):
 * título, tipo de informação, data e usuário nas linhas 1 a 5, cabeçalho na 6 (fixo, com filtro) e linhas zebradas.
 */
final class BuscaExportacaoXlsx
{
    private static function coluna(int $n): string { $l = ''; while ($n > 0) { $n--; $l = chr(65 + ($n % 26)) . $l; $n = intdiv($n, 26); } return $l; }

    /** @param list<array<string, scalar|null>> $linhas  @param array<string, int> $larguras título da coluna => largura */
    public static function gravar(string $arquivo, array $linhas, string $usuario, string $tipoInformacao, array $larguras = []): void
    {
        $colunas = $linhas ? array_keys($linhas[0]) : ['Informação'];
        $ultima = self::coluna(count($colunas));
        $xml = static function (string $v): string { $v = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $v) ?? ''; return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
        $celula = static fn(string $ref, $valor, int $estilo = 0): string => '<c r="' . $ref . '" t="inlineStr" s="' . $estilo . '"><is><t xml:space="preserve">' . $xml((string)$valor) . '</t></is></c>';
        $total = max(1, count($linhas));
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:' . $ultima . (6 + $total) . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="6" topLeftCell="A7" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
        foreach ($colunas as $i => $c) $sheet .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (int)($larguras[$c] ?? 18) . '" customWidth="1"/>';
        $sheet .= '</cols><sheetData><row r="1">' . $celula('A1', 'Green Portal', 1) . '</row><row r="2">' . $celula('A2', 'Relatório profissional de dados do portal', 2) . '</row>'
            . '<row r="3">' . $celula('A3', 'Tipo de informação: ' . $tipoInformacao) . '</row><row r="4">' . $celula('A4', 'Data da solicitação: ' . date('d/m/Y H:i:s')) . '</row>'
            . '<row r="5">' . $celula('A5', 'Usuário solicitante: ' . $usuario) . '</row><row r="6">';
        foreach ($colunas as $i => $c) $sheet .= $celula(self::coluna($i + 1) . '6', (string)$c, 6);
        $sheet .= '</row>';
        if (!$linhas) $linhas = [['Informação' => 'Nenhum registro encontrado.']];
        $r = 6;
        foreach ($linhas as $n => $linha) {
            $r = $n + 7; $estilo = ($n % 2) ? 5 : 4;
            $sheet .= '<row r="' . $r . '">';
            foreach (array_values($linha) as $i => $v) $sheet .= $celula(self::coluna($i + 1) . $r, $v ?? '', $estilo);
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData><autoFilter ref="A6:' . $ultima . $r . '"/></worksheet>';
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="4"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="18"/><color rgb="FFFFFFFF"/><name val="Arial"/></font><font><b/><sz val="12"/><color rgb="FF176B50"/><name val="Arial"/></font><font><b/><sz val="11"/><color rgb="FF000000"/><name val="Arial"/></font></fonts><fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF176B50"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEAF5EF"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFBFE3CF"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellXfs count="7"><xf/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/><xf fontId="2"/><xf fontId="0" fillId="2" applyFont="1" applyFill="1"/><xf fillId="0"/><xf fillId="3" applyFill="1"/><xf fontId="3" fillId="4" applyFont="1" applyFill="1"/></cellXfs></styleSheet>';
        $tipos = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Pesquisa" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $zip = new ZipArchive();
        if ($zip->open($arquivo, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Não foi possível criar a planilha.');
        $zip->addFromString('[Content_Types].xml', $tipos); $zip->addFromString('_rels/.rels', $rels); $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels); $zip->addFromString('xl/styles.xml', $styles); $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
    }
}
