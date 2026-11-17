<?php
declare(strict_types=1);

// Gera a calculadora Excel da análise de custo dos cinco pilares da IA.
// Uso: php scripts/gerar-calculadora-custo-ia.php

$root = dirname(__DIR__);
$outputName = trim((string)($argv[1] ?? ''));
if ($outputName === '' || !preg_match('/^[A-Za-z0-9_.-]+\.xlsx$/i', $outputName)) $outputName = 'calculadora_custo_ia.xlsx';
$output = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . $outputName;

function x(string $value): string
{
    $value = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value) ?? '';
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function col(int $number): string
{
    $letters = '';
    while ($number > 0) {
        $number--;
        $letters = chr(65 + ($number % 26)) . $letters;
        $number = intdiv($number, 26);
    }
    return $letters;
}

function cell(string $ref, mixed $value = '', int $style = 0, bool $formula = false): string
{
    if ($formula) {
        return '<c r="' . $ref . '" s="' . $style . '"><f>' . x((string)$value) . '</f><v>0</v></c>';
    }
    if (is_numeric($value) && !is_string($value)) {
        return '<c r="' . $ref . '" s="' . $style . '"><v>' . x((string)$value) . '</v></c>';
    }
    return '<c r="' . $ref . '" t="inlineStr" s="' . $style . '"><is><t>' . x((string)$value) . '</t></is></c>';
}

function row(int $number, array $cells): string
{
    return '<row r="' . $number . '">' . implode('', $cells) . '</row>';
}

function worksheet(array $rows, string $dimension, array $widths = []): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $xml .= '<dimension ref="A1:' . $dimension . '"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
    foreach ($widths as $i => $width) $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
    $xml .= '</cols><sheetData>' . implode('', $rows) . '</sheetData><autoFilter ref="A1:' . $dimension . '"/></worksheet>';
    return $xml;
}

$companies = [
    ['Goodyear SBO', 2346], ['Goodyear Anchieta', 1878], ['Goodyear Epiacaba', 2101],
    ['Goodyear Americana', 3039], ['Goodyear Diadema', 683], ['Goodyear Gravatai', 1957],
    ['Goodyear São Paulo', 1725],
];
$pillars = ['Licenças', 'Medição e Monitoramento', 'Treinamentos', 'Infraestrutura', 'Documentos'];
$models = [
    ['OpenAI GPT-5 mini', 0.25, 2.00],
    ['Google Gemini 3.1 Flash-Lite', 0.25, 1.50],
    ['Anthropic Claude Haiku 4.5', 1.00, 5.00],
];

$intro = [
    row(1, [cell('A1', 'Calculadora de custo da Inteligência Artificial', 1)]),
    row(2, [cell('A2', 'Leia primeiro', 2)]),
    row(3, [cell('A3', 'Esta planilha mostra uma estimativa de quanto pode custar usar a Inteligência Artificial para analisar os requisitos legais.')]),
    row(4, [cell('A4', 'A ideia é transformar uma quantidade de requisitos em uma previsão de custo fácil de entender e comparar.')]),
    row(6, [cell('A6', 'Como o cálculo começa', 2)]),
    row(7, [cell('A7', '1. Primeiro contamos quantos requisitos legais existem em cada empresa.')]),
    row(8, [cell('A8', '2. Cada requisito é avaliado em cinco assuntos: Licenças, Medição e Monitoramento, Treinamentos, Infraestrutura e Documentos.')]),
    row(9, [cell('A9', '3. Por isso, a quantidade de análises é a quantidade de requisitos multiplicada por cinco.')]),
    row(10, [cell('A10', 'Exemplo: 100 requisitos x 5 assuntos = 500 análises da Inteligência Artificial.')]),
    row(12, [cell('A12', 'Como os arquivos entram no cálculo', 2)]),
    row(13, [cell('A13', 'Quando o usuário anexa um arquivo a um item, a Inteligência Artificial precisa ler esse arquivo e interpretar o conteúdo.')]),
    row(14, [cell('A14', 'Ela pode identificar, por exemplo, data de vencimento, número da licença, órgão emissor, responsável e outros dados para indexação.')]),
    row(15, [cell('A15', 'O custo dos arquivos é calculado separadamente porque nem todos os itens terão um arquivo anexado.')]),
    row(16, [cell('A16', 'A planilha permite simular três situações: 25%, 50% ou 100% dos itens com arquivos.')]),
    row(18, [cell('A18', 'Como ler os valores', 2)]),
    row(19, [cell('A19', 'O valor apresentado é uma estimativa. Ele considera o tamanho médio do texto enviado para a IA e o tamanho médio da resposta recebida.')]),
    row(20, [cell('A20', 'A cotação do dólar e os valores cobrados por cada IA ficam na aba Parametros e podem ser atualizados.')]),
    row(21, [cell('A21', 'O custo total é a soma da análise dos requisitos com a leitura e interpretação dos arquivos.')]),
    row(23, [cell('A23', 'O que não está incluído automaticamente', 2)]),
    row(24, [cell('A24', 'Leitura de imagens por OCR, armazenamento dos arquivos, impostos, novas tentativas e reprocessamentos podem aumentar o custo real.')]),
    row(25, [cell('A25', 'Por isso, esta planilha serve para planejamento e comparação. O custo definitivo deve ser acompanhado pelo consumo real registrado pelo sistema.')]),
    row(27, [cell('A27', 'Para começar, altere somente as células destacadas na aba Parametros e confira o resultado na aba Comparativo.')]),
    row(29, [cell('A29', 'Como interpretar cada aba', 2)]),
    row(30, [cell('A30', 'Parametros: onde ficam os números usados nos cálculos. Os campos destacados podem ser alterados quando mudarem a quantidade, os tokens, os preços ou o dólar.')]),
    row(31, [cell('A31', 'Empresas: mostra os requisitos de cada empresa e quantas análises e arquivos seriam considerados em cada cenário.')]),
    row(32, [cell('A32', 'Pilares: separa a análise inicial por assunto e mostra quanto cada um dos cinco pilares representa no custo.')]),
    row(33, [cell('A33', 'Arquivos: mostra o custo adicional para ler e interpretar os arquivos anexados nos cenários de 25%, 50% e 100%.')]),
    row(34, [cell('A34', 'Comparativo: apresenta o resumo para comparar as IAs e mostra o custo inicial, o custo dos arquivos e o total estimado.')]),
    row(35, [cell('A35', 'Instrucoes: traz um resumo rápido de uso da planilha para consulta durante o preenchimento.')]),
];

$param = [];
$param[] = row(1, [cell('A1', 'Parâmetro', 1), cell('B1', 'Valor', 1), cell('C1', 'Observação', 1)]);
$param[] = row(2, [cell('A2', 'Total de requisitos'), cell('B2', '=SUM(Empresas!B2:B8)', 3, true), cell('C2', 'Calculado pela aba Empresas')]);
$param[] = row(3, [cell('A3', 'Quantidade de pilares'), cell('B3', 5, 2), cell('C3', 'Licenças, Medição e Monitoramento, Treinamentos, Infraestrutura e Documentos')]);
$param[] = row(4, [cell('A4', 'Tokens de entrada por análise'), cell('B4', 2000, 2), cell('C4', 'Premissa editável')]);
$param[] = row(5, [cell('A5', 'Tokens de saída por análise'), cell('B5', 500, 2), cell('C5', 'Premissa editável')]);
$param[] = row(6, [cell('A6', 'Cotação do dólar em reais'), cell('B6', 5.50, 7), cell('C6', 'Premissa editável')]);
$param[] = row(7, [cell('A7', 'Tokens de entrada por arquivo'), cell('B7', 5000, 2), cell('C7', 'Premissa para leitura/interpretação')]);
$param[] = row(8, [cell('A8', 'Tokens de saída por arquivo'), cell('B8', 400, 2), cell('C8', 'Premissa para metadados estruturados')]);
$param[] = row(10, [cell('A10', 'Modelo', 1), cell('B10', 'Entrada US$/1M', 1), cell('C10', 'Saída US$/1M', 1), cell('D10', 'Entrada R$/1M', 1), cell('E10', 'Saída R$/1M', 1)]);
foreach ($models as $i => $model) {
    $r = 11 + $i;
    $param[] = row($r, [cell('A' . $r, $model[0]), cell('B' . $r, $model[1], 8), cell('C' . $r, $model[2], 8), cell('D' . $r, '=B' . $r . '*$B$6', 5, true), cell('E' . $r, '=C' . $r . '*$B$6', 5, true)]);
}
$param[] = row(16, [cell('A16', 'Cenário', 1), cell('B16', 'Percentual com arquivo', 1)]);
foreach ([[25, .25], [50, .50], [100, 1.00]] as $i => $scenario) {
    $r = 17 + $i;
    $param[] = row($r, [cell('A' . $r, $scenario[0] . '%'), cell('B' . $r, $scenario[1], 4)]);
}

$emp = [row(1, [cell('A1', 'Empresa', 1), cell('B1', 'Requisitos', 1), cell('C1', 'Análises (5 pilares)', 1), cell('D1', 'Arquivos 25%', 1), cell('E1', 'Arquivos 50%', 1), cell('F1', 'Arquivos 100%', 1)])];
foreach ($companies as $i => $company) {
    $r = $i + 2;
    $emp[] = row($r, [cell('A' . $r, $company[0]), cell('B' . $r, $company[1], 2), cell('C' . $r, '=B' . $r . '*Parametros!$B$3', 3, true), cell('D' . $r, '=B' . $r . '*Parametros!$B$17', 3, true), cell('E' . $r, '=B' . $r . '*Parametros!$B$18', 3, true), cell('F' . $r, '=B' . $r . '*Parametros!$B$19', 3, true)]);
}
$emp[] = row(9, [cell('A9', 'Total', 1), cell('B9', '=SUM(B2:B8)', 3, true), cell('C9', '=SUM(C2:C8)', 3, true), cell('D9', '=SUM(D2:D8)', 3, true), cell('E9', '=SUM(E2:E8)', 3, true), cell('F9', '=SUM(F2:F8)', 3, true)]);

$pil = [row(1, [cell('A1', 'Pilar', 1), cell('B1', 'Requisitos', 1), cell('C1', 'Análises', 1), cell('D1', 'Tokens entrada', 1), cell('E1', 'Tokens saída', 1), cell('F1', 'GPT USD', 1), cell('G1', 'GPT R$', 1), cell('H1', 'Gemini USD', 1), cell('I1', 'Gemini R$', 1), cell('J1', 'Claude USD', 1), cell('K1', 'Claude R$', 1)])];
foreach ($pillars as $i => $pillar) {
    $r = $i + 2;
    $pil[] = row($r, [cell('A' . $r, $pillar), cell('B' . $r, '=Parametros!$B$2', 3, true), cell('C' . $r, '=B' . $r . '*1', 3, true), cell('D' . $r, '=C' . $r . '*Parametros!$B$4', 3, true), cell('E' . $r, '=C' . $r . '*Parametros!$B$5', 3, true), cell('F' . $r, '=(D' . $r . '/1000000*Parametros!$B$11+E' . $r . '/1000000*Parametros!$C$11)', 6, true), cell('G' . $r, '=F' . $r . '*Parametros!$B$6', 5, true), cell('H' . $r, '=(D' . $r . '/1000000*Parametros!$B$12+E' . $r . '/1000000*Parametros!$C$12)', 6, true), cell('I' . $r, '=H' . $r . '*Parametros!$B$6', 5, true), cell('J' . $r, '=(D' . $r . '/1000000*Parametros!$B$13+E' . $r . '/1000000*Parametros!$C$13)', 6, true), cell('K' . $r, '=J' . $r . '*Parametros!$B$6', 5, true)]);
}
$pil[] = row(7, [cell('A7', 'Total', 1), cell('B7', '=SUM(B2:B6)', 3, true), cell('C7', '=SUM(C2:C6)', 3, true), cell('D7', '=SUM(D2:D6)', 3, true), cell('E7', '=SUM(E2:E6)', 3, true), cell('F7', '=SUM(F2:F6)', 6, true), cell('G7', '=SUM(G2:G6)', 5, true), cell('H7', '=SUM(H2:H6)', 6, true), cell('I7', '=SUM(I2:I6)', 5, true), cell('J7', '=SUM(J2:J6)', 6, true), cell('K7', '=SUM(K2:K6)', 5, true)]);

$files = [row(1, [cell('A1', 'Cenário', 1), cell('B1', '% com arquivo', 1), cell('C1', 'Arquivos', 1), cell('D1', 'Tokens entrada', 1), cell('E1', 'Tokens saída', 1), cell('F1', 'GPT USD', 1), cell('G1', 'GPT R$', 1), cell('H1', 'Gemini USD', 1), cell('I1', 'Gemini R$', 1), cell('J1', 'Claude USD', 1), cell('K1', 'Claude R$', 1)])];
foreach ([[25, 17], [50, 18], [100, 19]] as $i => [$label, $paramRow]) {
    $r = $i + 2;
    $files[] = row($r, [cell('A' . $r, $label . '%'), cell('B' . $r, '=Parametros!$B$' . $paramRow, 3, true), cell('C' . $r, '=Parametros!$B$2*B' . $r, 3, true), cell('D' . $r, '=C' . $r . '*Parametros!$B$7', 3, true), cell('E' . $r, '=C' . $r . '*Parametros!$B$8', 3, true), cell('F' . $r, '=(D' . $r . '/1000000*Parametros!$B$11+E' . $r . '/1000000*Parametros!$C$11)', 6, true), cell('G' . $r, '=F' . $r . '*Parametros!$B$6', 5, true), cell('H' . $r, '=(D' . $r . '/1000000*Parametros!$B$12+E' . $r . '/1000000*Parametros!$C$12)', 6, true), cell('I' . $r, '=H' . $r . '*Parametros!$B$6', 5, true), cell('J' . $r, '=(D' . $r . '/1000000*Parametros!$B$13+E' . $r . '/1000000*Parametros!$C$13)', 6, true), cell('K' . $r, '=J' . $r . '*Parametros!$B$6', 5, true)]);
}

// Modelo, coluna USD inicial, coluna R$ inicial, USD/R$ dos arquivos e totais em R$.
$comp = [row(1, [cell('A1', 'Modelo', 1), cell('B1', 'Inicial US$', 1), cell('C1', 'Inicial R$', 1), cell('D1', 'Arquivos 50% US$', 1), cell('E1', 'Arquivos 50% R$', 1), cell('F1', 'Arquivos 100% US$', 1), cell('G1', 'Arquivos 100% R$', 1), cell('H1', 'Total 50% R$', 1), cell('I1', 'Total 100% R$', 1)])];
foreach ([['OpenAI GPT-5 mini', 'F7', 'G7', 'F3', 'G3', 'F4', 'G4'], ['Google Gemini 3.1 Flash-Lite', 'H7', 'I7', 'H3', 'I3', 'H4', 'I4'], ['Anthropic Claude Haiku 4.5', 'J7', 'K7', 'J3', 'K3', 'J4', 'K4']] as $i => [$name, $usdInitial, $brlInitial, $usd50, $brl50, $usd100, $brl100]) {
    $r = $i + 2;
    $comp[] = row($r, [cell('A' . $r, $name), cell('B' . $r, '=Pilares!' . $usdInitial, 6, true), cell('C' . $r, '=Pilares!' . $brlInitial, 5, true), cell('D' . $r, '=Arquivos!' . $usd50, 6, true), cell('E' . $r, '=Arquivos!' . $brl50, 5, true), cell('F' . $r, '=Arquivos!' . $usd100, 6, true), cell('G' . $r, '=Arquivos!' . $brl100, 5, true), cell('H' . $r, '=C' . $r . '+E' . $r, 5, true), cell('I' . $r, '=C' . $r . '+G' . $r, 5, true)]);
}
$comp[] = row(6, [cell('A6', 'Observação', 1), cell('B6', 'Valores em reais usam a cotação da aba Parametros.', 0), cell('D6', 'OCR e armazenamento não incluídos.', 0)]);

$instructions = [
    row(1, [cell('A1', 'Como usar a calculadora', 1)]),
    row(2, [cell('A2', '1. Altere as células destacadas na aba Parametros.')]),
    row(3, [cell('A3', '2. Atualize requisitos por empresa na aba Empresas.')]),
    row(4, [cell('A4', '3. Revise as premissas de tokens por análise e por arquivo.')]),
    row(5, [cell('A5', '4. Consulte Pilares para o custo da análise inicial.')]),
    row(6, [cell('A6', '5. Consulte Arquivos para o custo de leitura/interpretação.')]),
    row(7, [cell('A7', '6. Consulte Comparativo para o custo total por modelo.')]),
    row(9, [cell('A9', 'Importante', 1)]),
    row(10, [cell('A10', 'Os valores são estimativas. O custo real depende dos tokens medidos, OCR, reprocessamentos, tarifas e câmbio.')]),
];

$sheets = [
    'Como Usar' => worksheet($intro, 'A35', [120]),
    'Instrucoes' => worksheet($instructions, 'A10', [110]),
    'Parametros' => worksheet($param, 'E19', [36, 22, 85, 22, 22]),
    'Empresas' => worksheet($emp, 'F9', [28, 14, 20, 16, 16, 16]),
    'Pilares' => worksheet($pil, 'K7', [30, 14, 14, 18, 18, 16, 16, 18, 16, 18, 16]),
    'Arquivos' => worksheet($files, 'K4', [14, 16, 16, 18, 18, 16, 16, 18, 16, 18, 16]),
    'Comparativo' => worksheet($comp, 'I6', [34, 16, 16, 18, 18, 18, 18, 18, 18]),
];

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="2"><numFmt numFmtId="164" formatCode="R$ #,##0.00"/><numFmt numFmtId="165" formatCode="$ #,##0.00"/></numFmts><fonts count="3"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font><font><b/><sz val="12"/><color rgb="FF176B50"/><name val="Arial"/></font></fonts><fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF176B50"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFF2CC"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEAF5EF"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellXfs count="9"><xf/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/><xf fontId="2"/><xf fillId="4" applyFill="1"/><xf fillId="3" applyFill="1"/><xf numFmtId="164" applyNumberFormat="1"/><xf numFmtId="165" applyNumberFormat="1"/><xf numFmtId="164" fillId="3" applyNumberFormat="1" applyFill="1"/><xf numFmtId="165" fillId="3" applyNumberFormat="1" applyFill="1"/></cellXfs></styleSheet>';
$types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
foreach (array_keys($sheets) as $i => $_) $types .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
$types .= '</Types>';
$rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><calcPr fullCalcOnLoad="1" forceFullCalc="1"/><sheets>';
$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
foreach (array_keys($sheets) as $i => $name) { $id = $i + 1; $workbook .= '<sheet name="' . x($name) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>'; $rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>'; }
$workbook .= '</sheets></workbook>';
$rels .= '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
$workbookRels = $rels;

$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Não foi possível criar a planilha.');
$zip->addFromString('[Content_Types].xml', $types);
$zip->addFromString('_rels/.rels', $rootRels);
$zip->addFromString('xl/workbook.xml', $workbook);
$zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
$zip->addFromString('xl/styles.xml', $styles);
foreach (array_values($sheets) as $i => $sheet) $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $sheet);
$zip->close();
echo $output . PHP_EOL;
