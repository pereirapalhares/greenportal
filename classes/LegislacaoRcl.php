<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';
require_once __DIR__ . '/LegislacaoEmpresas.php';

/*
 * Migração 385 — RCL (Registro de Consulta à Legislação), botões RCL Completa / RCL Padrão de Manutenção Legal › Legislação.
 * Mesma regra do legado (newlegislacao/cadastros/gerarrcl.php + dbgreen::getRCL), por empresa + unidade + mês:
 *   - leis vinculadas à unidade (legislacaoxempresa do banco da empresa) e com Poder para a unidade (legislacaoxpoder);
 *   - "Data atualização" da lei no mês; Alteradas e Retiradas também com alteração registrada no mês;
 *   - Normas Inseridas: Lei Inclusa (+ "Devido à Atualização" na Padrão);
 *     Normas Alteradas: Lei Alterada (+ devido diferente de "Devido ao Ajuste" na Padrão);
 *     Normas Retiradas: Lei Revogada (+ "Devido à Atualização" na Padrão);
 *   - uma linha por assunto × poder (× alteração), sem repetição, em ordem de código;
 *   - grava o histórico do dia em legislacaoxrevisoes do banco da empresa (apaga o do dia e grava de novo, como o legado).
 * Diferenças intencionais: situação/devido comparados sem espaços nas pontas (o cadastro tem "Devido à Atualização " com
 * espaço no fim) e Escopo montado com " / " (o legado colava "Meio AmbienteQualidade" ao tirar as barras repetidas).
 * Saída: .xlsx de verdade (o legado era HTML com extensão .xls).
 */
final class LegislacaoRcl
{
    public const SECOES = ['INSERIDAS' => 'Normas Inseridas', 'ALTERADAS' => 'Normas Alteradas', 'RETIRADAS' => 'Normas Retiradas'];
    public const TEXTO = ['INSERIDAS' => 'DISPOSIÇÃO/AÇÕES NECESSÁRIAS', 'ALTERADAS' => 'ALTERAÇÕES', 'RETIRADAS' => 'Revogado por'];

    /** @return array{secoes: array<string, list<array>>, empresa: string, unidade: string, revisao: string} */
    public static function dados(int $codempresa, int $codunidade, string $mes, bool $completa): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $mes)) throw new InvalidArgumentException('Mês/Ano inválido.');
        $empresas = LegislacaoEmpresas::migradas();
        if (!isset($empresas[$codempresa])) throw new InvalidArgumentException('Empresa não migrada para o GreenPortal.');
        $unidades = LegislacaoEmpresas::unidades($codempresa);
        if (!isset($unidades[$codunidade])) throw new InvalidArgumentException('A unidade não pertence a esta empresa.');
        $pdoEmpresa = LegislacaoEmpresas::pdo($codempresa);
        $env = GreenPortalDatabase::env();
        $leg = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
        $leg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $vinculadas = array_fill_keys(LegislacaoEmpresas::leis($codempresa, $codunidade), true);
        $poderes = [];
        foreach (LegislacaoEmpresas::ler($pdoEmpresa, 'legislacaoxpoder', ['codunidade' => $codunidade]) as $p) {
            $p = array_change_key_case($p, CASE_LOWER);
            $poderes[(int)$p['codlegislacao']][(string)($p['poder'] ?? '')] = true;
        }
        // leis (uma linha por assunto) com "Data atualização" no mês
        $leis = [];
        foreach (LegislacaoEmpresas::ler($leg, 'vw_legislacao_rcl', ['MES_ATUALIZACAO' => $mes]) as $l) {
            if ((string)$l['MES_ATUALIZACAO'] !== $mes) continue; // o filtro de texto do sp_crud_select é por trecho
            $id = (int)$l['CODLEGISLACAO'];
            if (isset($vinculadas[$id], $poderes[$id])) $leis[] = $l;
        }
        $alteracoes = [];
        foreach (LegislacaoEmpresas::ler($leg, 'vw_legislacao_rcl_alteracoes', ['MES_ALTERACAO' => $mes]) as $a)
            if ((string)$a['MES_ALTERACAO'] === $mes) $alteracoes[(int)$a['CODLEGISLACAO']][] = $a;

        $norm = static fn($v): string => trim((string)$v);
        $regra = [
            'INSERIDAS' => static fn($l) => $norm($l['SITUACAO']) === 'Lei Inclusa' && ($completa || $norm($l['DEVIDO']) === 'Devido à Atualização'),
            'ALTERADAS' => static fn($l) => $norm($l['SITUACAO']) === 'Lei Alterada' && ($completa || $norm($l['DEVIDO']) !== 'Devido ao Ajuste'),
            'RETIRADAS' => static fn($l) => $norm($l['SITUACAO']) === 'Lei Revogada' && ($completa || $norm($l['DEVIDO']) === 'Devido à Atualização'),
        ];
        $secoes = ['INSERIDAS' => [], 'ALTERADAS' => [], 'RETIRADAS' => []];
        $itens = ['INSERIDAS' => [], 'ALTERADAS' => [], 'RETIRADAS' => []];
        foreach ($leis as $l) {
            $id = (int)$l['CODLEGISLACAO'];
            foreach ($secoes as $sec => $_) {
                if (!$regra[$sec]($l)) continue;
                if ($sec === 'INSERIDAS') $textos = [self::texto((string)($l['PADRAO'] ?? ''))];
                else {
                    if (empty($alteracoes[$id])) continue;
                    $textos = array_map(static fn($a) => $sec === 'ALTERADAS'
                        ? implode('/', array_filter([trim((string)($a['TIPO'] ?? '')), self::texto((string)($a['DESCRICAO'] ?? ''))], static fn($x) => $x !== ''))
                        : self::texto((string)($a['DESCRICAO'] ?? '')), $alteracoes[$id]);
                }
                $item = self::item($l);
                foreach (array_keys($poderes[$id]) as $poder) foreach ($textos as $t) {
                    $linha = ['CODIGO' => (string)$l['CODIGO'], 'ITEM' => $item, 'AMBITO' => (string)($l['DSCAMBITO'] ?? ''), 'ASSUNTO' => (string)($l['DSCASSUNTO'] ?? ''),
                        'PODER' => $poder, 'TEXTO' => $t, 'ESCOPO' => self::escopo($l)];
                    $secoes[$sec][md5(json_encode($linha))] = $linha;
                }
                $itens[$sec][$item] = true;
            }
        }
        foreach ($secoes as $sec => $linhas) { $linhas = array_values($linhas); usort($linhas, static fn($a, $b) => strcmp($a['CODIGO'], $b['CODIGO'])); $secoes[$sec] = $linhas; }
        $lista = static fn(array $i): string => implode(',', array_map(static fn($x) => str_replace("'", ' ', (string)$x), array_keys($i)));
        $revisao = "Requisitos Inclusos:\n" . $lista($itens['INSERIDAS']) . "\n" . "\nRequisitos Alterados:\n" . $lista($itens['ALTERADAS']) . "\n" . "\nRequisitos Revogados:\n" . $lista($itens['RETIRADAS']) . "\n";
        return ['secoes' => $secoes, 'empresa' => $empresas[$codempresa]['nome'], 'banco' => $empresas[$codempresa]['banco'], 'unidade' => $unidades[$codunidade], 'revisao' => $revisao];
    }

    /** Histórico do dia em legislacaoxrevisoes (como o legado: um registro por dia, regravado a cada RCL gerado). */
    public static function gravarRevisao(int $codempresa, string $texto): void
    {
        $pdo = LegislacaoEmpresas::pdo($codempresa);
        $hoje = date('Y-m-d');
        foreach (LegislacaoEmpresas::ler($pdo, 'legislacaoxrevisoes', ['data' => $hoje]) as $r) {
            $r = array_change_key_case($r, CASE_LOWER);
            if (substr((string)$r['data'], 0, 10) !== $hoje) continue;
            self::chamar($pdo, 'CALL sp_crud_delete(?, ?)', ['legislacaoxrevisoes', json_encode(['chave' => (int)$r['chave']])]);
        }
        self::chamar($pdo, 'CALL sp_crud_insert(?, ?)', ['legislacaoxrevisoes', json_encode(['dscrevisao' => $texto, 'data' => $hoje], JSON_UNESCAPED_UNICODE)]);
    }

    public static function nomeArquivo(string $empresa, string $unidade, bool $completa, string $mes): string
    {
        $limpa = static function (string $v): string {
            if (class_exists('Normalizer')) $v = (string)preg_replace('/\p{Mn}/u', '', (string)Normalizer::normalize($v, Normalizer::FORM_D));
            return trim((string)preg_replace('/[^A-Za-z0-9]+/', '_', $v), '_');
        };
        return 'RCL_' . ($completa ? 'Completa' : 'Padrao') . '_' . $limpa($empresa) . '_' . $limpa($unidade) . '_' . substr($mes, 5, 2) . '_' . substr($mes, 0, 4) . '.xlsx';
    }

    // ------------------------------------------------------------------ planilha

    public static function gravarXlsx(string $arquivo, array $dados, bool $completa, string $mes, string $usuario, ?string $logo): void
    {
        $x = static function (string $v): string { $v = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $v) ?? ''; return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
        $cel = static fn(string $ref, string $v, int $s): string => '<c r="' . $ref . '" s="' . $s . '"' . ($v === '' ? '/>' : ' t="inlineStr"><is><t xml:space="preserve">' . $x($v) . '</t></is></c>');
        $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];
        $rows = []; $merges = []; $alturas = [];
        $linha = static function (int $r, array $celulas) use (&$rows): void { $rows[$r] = $celulas; };
        // cabeçalho: logo (A1:A4) e título (B1:I4)
        $c = []; foreach ($cols as $col) $c[] = $cel($col . '1', $col === 'B' ? 'REGISTRO DE CONSULTA À LEGISLAÇÃO' : '', $col === 'A' ? 7 : 1);
        $linha(1, $c);
        for ($r = 2; $r <= 4; $r++) { $c = []; foreach ($cols as $col) $c[] = $cel($col . $r, '', $col === 'A' ? 7 : 1); $linha($r, $c); }
        $merges[] = 'A1:A4'; $merges[] = 'B1:I4';
        $info = ['Empresa: ' . $dados['empresa'] . '    Unidade: ' . $dados['unidade'], 'Mês/Ano: ' . substr($mes, 5, 2) . '/' . substr($mes, 0, 4) . '    Tipo: ' . ($completa ? 'RCL Completa' : 'RCL Padrão'),
            'Gerado por ' . $usuario . ' em ' . date('d/m/Y H:i')];
        $r = 5;
        foreach ($info as $t) { $linha($r, [$cel('A' . $r, $t, 6)]); $merges[] = 'A' . $r . ':I' . $r; $r++; }
        foreach (self::SECOES as $sec => $titulo) {
            $r++;
            $c = []; foreach ($cols as $col) $c[] = $cel($col . $r, $col === 'A' ? $titulo : '', 2);
            $linha($r, $c); $merges[] = 'A' . $r . ':I' . $r; $r++;
            $cab = ['CÓDIGO', 'ITEM AVALIADO', 'ÂMBITO', 'ASSUNTO', 'PODER', self::TEXTO[$sec], '', '', 'ESCOPO'];
            $c = []; foreach ($cols as $i => $col) $c[] = $cel($col . $r, $cab[$i], 3);
            $linha($r, $c); $merges[] = 'F' . $r . ':H' . $r; $r++;
            if (!$dados['secoes'][$sec]) {
                $c = []; foreach ($cols as $col) $c[] = $cel($col . $r, $col === 'A' ? 'Nenhuma norma neste mês.' : '', 4);
                $linha($r, $c); $merges[] = 'A' . $r . ':I' . $r; $r++;
                continue;
            }
            foreach ($dados['secoes'][$sec] as $d) {
                $v = [$d['CODIGO'], $d['ITEM'], $d['AMBITO'], $d['ASSUNTO'], $d['PODER'], $d['TEXTO'], '', '', $d['ESCOPO']];
                $c = []; foreach ($cols as $i => $col) $c[] = $cel($col . $r, $v[$i], in_array($col, ['F', 'G', 'H'], true) ? 5 : 4);
                $linha($r, $c); $merges[] = 'F' . $r . ':H' . $r;
                // altura aproximada pelo tamanho do texto (Excel não ajusta sozinho em célula mesclada)
                $alturas[$r] = min(409, max(15, 13 * (int)ceil(max(1, mb_strlen($d['TEXTO'])) / 95) + 2 * substr_count($d['TEXTO'], "\n")));
                $r++;
            }
        }
        $ultima = $r - 1;
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:I' . $ultima . '"/><sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews><sheetFormatPr defaultRowHeight="15"/>'
            . '<cols><col min="1" max="1" width="14" customWidth="1"/><col min="2" max="2" width="22" customWidth="1"/><col min="3" max="3" width="14" customWidth="1"/><col min="4" max="4" width="22" customWidth="1"/>'
            . '<col min="5" max="5" width="16" customWidth="1"/><col min="6" max="8" width="30" customWidth="1"/><col min="9" max="9" width="24" customWidth="1"/></cols><sheetData>';
        foreach ($rows as $n => $celulas) {
            $alt = $n <= 4 ? ' ht="22" customHeight="1"' : (isset($alturas[$n]) ? ' ht="' . $alturas[$n] . '" customHeight="1"' : '');
            $sheet .= '<row r="' . $n . '"' . $alt . '>' . implode('', $celulas) . '</row>';
        }
        $sheet .= '</sheetData><mergeCells count="' . count($merges) . '">';
        foreach ($merges as $m) $sheet .= '<mergeCell ref="' . $m . '"/>';
        $sheet .= '</mergeCells><pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/><pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/>'
            . ($logo ? '<drawing r:id="rId1"/>' : '') . '</worksheet>';
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="4"><font><sz val="10"/><name val="Arial"/></font><font><b/><sz val="16"/><name val="Arial"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font><font><b/><sz val="10"/><name val="Arial"/></font></fonts>'
            . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF088A08"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFA4A4A4"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FF808080"/></left><right style="thin"><color rgb="FF808080"/></right><top style="thin"><color rgb="FF808080"/></top><bottom style="thin"><color rgb="FF808080"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="8">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
        $tipos = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . ($logo ? '<Default Extension="png" ContentType="image/png"/><Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' : '')
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="RCL" sheetId="1" r:id="rId1"/></sheets>'
            . '<definedNames><definedName name="_xlnm.Print_Titles" localSheetId="0">RCL!$1:$4</definedName></definedNames></workbook>';
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $zip = new ZipArchive();
        if ($zip->open($arquivo, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Não foi possível criar a planilha.');
        $zip->addFromString('[Content_Types].xml', $tipos); $zip->addFromString('_rels/.rels', $rels); $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels); $zip->addFromString('xl/styles.xml', $styles); $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        if ($logo) {
            // logo da empresa (Setup › Layout) na célula A1:A4, até 100 × 80 px mantendo a proporção
            [$w, $h] = @getimagesize($logo) ?: [100, 80];
            $esc = min(100 / max(1, $w), 80 / max(1, $h), 1);
            $cx = (int)round($w * $esc * 9525); $cy = (int)round($h * $esc * 9525);
            $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>');
            $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image1.png"/></Relationships>');
            $zip->addFromString('xl/drawings/drawing1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<xdr:oneCellAnchor><xdr:from><xdr:col>0</xdr:col><xdr:colOff>38100</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>38100</xdr:rowOff></xdr:from><xdr:ext cx="' . $cx . '" cy="' . $cy . '"/>'
                . '<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="2" name="Logo"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr><xdr:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
                . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>');
            $zip->addFile($logo, 'xl/media/image1.png');
        }
        $zip->close();
    }

    // ------------------------------------------------------------------ util

    private static function item(array $l): string
    {
        // como o legado: concat(dsctipo,' ',nr,'/',ano); sem tipo/número/ano cai no código
        $t = trim((string)($l['DSCTIPO'] ?? '')); $n = trim((string)($l['NR'] ?? '')); $a = trim((string)($l['ANO'] ?? ''));
        return ($t !== '' || $n !== '' || $a !== '') ? trim($t . ' ' . $n . ($a !== '' ? '/' . $a : '')) : (string)$l['CODIGO'];
    }

    private static function escopo(array $l): string
    {
        $e = [];
        foreach (['MEIO_AMBIENTE' => 'Meio Ambiente', 'SEGURANCA' => 'Segurança e Saúde Ocupacional', 'QUALIDADE' => 'Qualidade', 'RESPOSABILIDADE' => 'Responsabilidade Social'] as $c => $rotulo)
            if (strcasecmp(trim((string)($l[$c] ?? '')), 'Sim') === 0) $e[] = $rotulo;
        return implode(' / ', $e);
    }

    /** Texto do cadastro (pode ter HTML do editor) para a célula: quebras de linha mantidas, tags tiradas. */
    private static function texto(string $v): string
    {
        $v = (string)preg_replace('#<\s*(br|/p|/div|/li)\s*/?>#i', "\n", $v);
        $v = html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $v = (string)preg_replace("/[ \t]+/", ' ', $v);
        $v = (string)preg_replace("/\n{3,}/", "\n\n", $v);
        return mb_substr(trim($v), 0, 32000);
    }

    private static function chamar(PDO $pdo, string $sql, array $p): array
    {
        $s = $pdo->prepare($sql); $s->execute($p);
        $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) {} $s->closeCursor();
        return $r;
    }
}
