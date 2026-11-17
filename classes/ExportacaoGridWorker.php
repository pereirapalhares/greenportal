<?php
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');

if (PHP_SAPI !== 'cli' || empty($argv[1]) || empty($argv[2]) || getenv('GREENPORTAL_EXPORT_FROM_SCHEDULER') !== '1') exit(1);
$jobId = (int)$argv[1];
$tenantDatabase = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$argv[2]);
$root = dirname(__DIR__);
$env = [];
foreach (file($root . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) { if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue; [$key, $value] = explode('=', $line, 2); if (!isset($env[trim($key)])) $env[trim($key)] = trim(trim($value), "\"'"); }
$pdo = new PDO('mysql:host=' . ($env['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['GPT_DB_PORT'] ?? '3306') . ';dbname=' . $tenantDatabase . ';charset=utf8mb4', $env['GPT_DB_USERNAME'] ?? '', $env['GPT_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function workerCall(PDO $pdo, string $sql, array $params): array { $s = $pdo->prepare($sql); $s->execute($params); $rows = $s->fetchAll(); $s->closeCursor(); return $rows; }
function excelColumn(int $number): string { $letters = ''; while ($number > 0) { $number--; $letters = chr(65 + ($number % 26)) . $letters; $number = intdiv($number, 26); } return $letters; }
function filenamePart(string $value, string $fallback): string { $value = trim($value); /* Acentos removidos antes do iconv: no Windows ele troca "ã" por "'a" (Gest_ao). */ if (class_exists('Normalizer')) { $semAcento = preg_replace('/\p{Mn}/u', '', (string)Normalizer::normalize($value, Normalizer::FORM_D)); if (is_string($semAcento)) $value = $semAcento; } $value = strtr($value, ['ç' => 'c', 'Ç' => 'C', 'ã' => 'a', 'õ' => 'o', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'â' => 'a', 'ê' => 'e', 'ô' => 'o', 'à' => 'a', 'Ã' => 'A', 'Õ' => 'O', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Â' => 'A', 'Ê' => 'E', 'Ô' => 'O', 'À' => 'A', 'ñ' => 'n', 'Ñ' => 'N']); $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value); if ($converted !== false) $value = $converted; $value = preg_replace('/[^a-zA-Z0-9]+/', '_', $value) ?? ''; $value = trim($value, '_'); return $value !== '' ? $value : $fallback; }
function createProfessionalXlsx(string $file, array $rows, string $userName, string $requestedAt, string $informationType): void {
    $columns = $rows ? array_keys($rows[0]) : ['Informação']; $lastColumn = excelColumn(count($columns)); $lastRow = 6 + max(1, count($rows));
    $xml = static function (string $value): string {
        $value = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value) ?? '';
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };
    $cell = static function (string $ref, mixed $value, int $style = 0) use ($xml): string { return '<c r="' . $ref . '" t="inlineStr" s="' . $style . '"><is><t>' . $xml((string)$value) . '</t></is></c>'; };
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:' . $lastColumn . $lastRow . '"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
    for ($i = 1; $i <= count($columns); $i++) $sheet .= '<col min="' . $i . '" max="' . $i . '" width="18" customWidth="1"/>';
    $sheet .= '</cols><sheetData><row r="1">' . $cell('A1', 'Green Portal', 1) . '</row><row r="2">' . $cell('A2', 'Relatório profissional de dados do portal', 2) . '</row><row r="3">' . $cell('A3', 'Tipo de informação: ' . $informationType) . '</row><row r="4">' . $cell('A4', 'Data da solicitação: ' . $requestedAt) . '</row><row r="5">' . $cell('A5', 'Usuário solicitante: ' . $userName) . '</row><row r="6">';
    foreach ($columns as $i => $column) $sheet .= $cell(excelColumn($i + 1) . '6', (string)$column, 6); $sheet .= '</row>';
    if (!$rows) $rows = [['Informação' => 'Nenhum registro encontrado.']];
    foreach ($rows as $rowIndex => $row) { $excelRow = $rowIndex + 7; $sheet .= '<row r="' . $excelRow . '" s="' . (($rowIndex % 2) ? 5 : 4) . '"'; $sheet .= '>'; foreach (array_values($row) as $i => $value) $sheet .= $cell(excelColumn($i + 1) . $excelRow, $value, (($rowIndex % 2) ? 5 : 4)); $sheet .= '</row>'; }
    $sheet .= '</sheetData><autoFilter ref="A6:' . $lastColumn . ($excelRow ?? 6) . '"/></worksheet>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="4"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="18"/><color rgb="FFFFFFFF"/><name val="Arial"/></font><font><b/><sz val="12"/><color rgb="FF176B50"/><name val="Arial"/></font><font><b/><sz val="11"/><color rgb="FF000000"/><name val="Arial"/></font></fonts><fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF176B50"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEAF5EF"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFBFE3CF"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellXfs count="7"><xf/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/><xf fontId="2"/><xf fontId="0" fillId="2" applyFont="1" applyFill="1"/><xf fillId="0"/><xf fillId="3" applyFill="1"/><xf fontId="3" fillId="4" applyFont="1" applyFill="1"/></cellXfs></styleSheet>';
    $content = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Setup Menu" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $zip = new ZipArchive(); if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Não foi possível criar a planilha.'); $zip->addFromString('[Content_Types].xml', $content); $zip->addFromString('_rels/.rels', $rels); $zip->addFromString('xl/workbook.xml', $workbook); $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels); $zip->addFromString('xl/styles.xml', $styles); $zip->addFromString('xl/worksheets/sheet1.xml', $sheet); $zip->close();
}
/**
 * Pesquisa de documentos: um lote de 1000 itens (registros e arquivos) da pesquisa gravada no pedido, já com as colunas da
 * planilha — tudo o que a tela mostra: negócio, item, título, trecho encontrado, situação, unidade, responsável, datas e os
 * vínculos do negócio por relação (sp_busca_vinculos_texto), com o link da transação.
 */
function buscaExportarLote(PDO $pdo, array $p, int $offset): array
{
    $s = $pdo->prepare('CALL sp_busca_pesquisar(?, ?, ?, ?, ?, ?, ?, ?)');
    $s->execute([(string)($p['termo'] ?? ''), json_encode($p['filtros'] ?? new stdClass(), JSON_UNESCAPED_UNICODE), json_encode($p['permissoes'] ?? []),
        !empty($p['admin']) ? 1 : 0, (int)($p['usuario'] ?? 0), (string)($p['ordem'] ?? 'relevancia'), 1000, $offset]);
    $sets = []; do { try { $sets[] = $s->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable) { $sets[] = []; } } while ($s->nextRowset()); $s->closeCursor();
    $itens = $sets[1] ?? [];
    if (!$itens) return [];
    $grupos = [];
    foreach ($itens as $i) $grupos[$i['grupo_tipo'] . '|' . $i['grupo_chave']] = ['t' => (string)$i['grupo_tipo'], 'c' => (string)$i['grupo_chave']];
    $v = $pdo->prepare('CALL sp_busca_vinculos_texto(?, ?, ?, ?)');
    $v->execute([json_encode(array_values($grupos), JSON_UNESCAPED_UNICODE), json_encode($p['permissoes'] ?? []), !empty($p['admin']) ? 1 : 0, (int)($p['usuario'] ?? 0)]);
    $vinc = []; foreach ($v->fetchAll(PDO::FETCH_ASSOC) as $x) $vinc[$x['grupo_tipo'] . '|' . $x['grupo_chave']][] = $x['rotulo_pt'] . ' (' . (int)$x['total'] . '): ' . $x['nomes'];
    $v->closeCursor(); while ($v->nextRowset()) {}
    $tipo = ['licencas' => 'Licença', 'controles' => 'Controle de documento', 'requisitos' => 'Requisito legal', 'treinamentos' => 'Treinamento', 'infraestrutura' => 'Infraestrutura',
        'fornecedorcriterios' => 'Documento de fornecedor', 'funcionariocriterios' => 'Documento de funcionário', 'funcionariotreinamentos' => 'Treinamento de funcionário'];
    $negocio = ['fornecedor' => 'Fornecedor', 'funcionario' => 'Funcionário'] + $tipo;
    $data = static fn($x): string => $x && preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/', (string)$x, $m) && $m[1] !== '0000' ? $m[3] . '/' . $m[2] . '/' . $m[1] . (isset($m[4]) ? ' ' . $m[4] . ':' . $m[5] : '') : '';
    $raiz = rtrim((string)($p['raiz'] ?? ''), '/');
    $linhas = [];
    foreach ($itens as $i) {
        $g = $i['grupo_tipo'] . '|' . $i['grupo_chave'];
        $linhas[] = [
            'Negócio' => $negocio[$i['grupo_tipo']] ?? (string)$i['grupo_tipo'],
            'Nome do negócio' => (string)($i['grupo_nome'] ?? ''),
            'Detalhe do negócio' => (string)($i['grupo_detalhe'] ?? ''),
            'Tipo' => $tipo[$i['tipo']] ?? (string)$i['tipo'],
            'Item' => $i['item'] === 'ARQUIVO' ? 'Arquivo' : 'Registro',
            'Nome' => (string)$i['nome'],
            'Título / descrição' => (string)($i['item'] === 'ARQUIVO' ? $i['titulo'] : ($i['descricao'] ?? '')),
            'Trecho encontrado' => (string)($i['trecho'] ?? ''),
            'Formato' => $i['item'] === 'ARQUIVO' ? strtoupper((string)$i['formato']) : '',
            'Situação' => (string)($i['situacao'] ?? ''),
            'Unidade' => (string)($i['unidade'] ?? ''),
            'Responsável' => (string)($i['responsavel'] ?? ''),
            'Validade' => $data($i['validade'] ?? null),
            'Modificado' => $data($i['modificado_em'] ?? null),
            'Vínculos' => implode(' | ', $vinc[$g] ?? []),
            'Código' => (string)$i['chave'],
            'Link' => $raiz . (string)$i['pagina'] . '?filtros=' . rawurlencode((string)($i['filtros'] ?? '{}')),
        ];
    }
    return $linhas;
}

$job = workerCall($pdo, 'CALL sp_crud_select(:tabela, :filtros, NULL, 1, 0)', ['tabela' => 'exportacoes_grid', 'filtros' => json_encode(['codexportacaogrid' => $jobId])])[0] ?? null;
if (!$job) exit(1);
$startedAt = (string)($job['iniciada_em'] ?? $job['solicitado_em'] ?? date('Y-m-d H:i:s'));
workerCall($pdo, 'CALL sp_crud_update(:tabela, :chave, :dados)', ['tabela' => 'exportacoes_grid', 'chave' => json_encode(['codexportacaogrid' => $jobId]), 'dados' => json_encode(['status' => 'PROCESSANDO', 'iniciada_em' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE)]);
$startedAt = date('Y-m-d H:i:s');
$slotHandle = null;
$slotDir = $root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'libreoffice-slots';
if (!is_dir($slotDir)) @mkdir($slotDir, 0775, true);
while ($slotHandle === null) {
    for ($slot = 1; $slot <= 2; $slot++) {
        $candidate = @fopen($slotDir . DIRECTORY_SEPARATOR . 'slot-' . $slot . '.lock', 'c');
        if ($candidate && @flock($candidate, LOCK_EX | LOCK_NB)) { $slotHandle = $candidate; break; }
        if ($candidate) @fclose($candidate);
    }
    if ($slotHandle === null) usleep(500000);
}
register_shutdown_function(static function () use (&$slotHandle): void {
    if ($slotHandle) { @flock($slotHandle, LOCK_UN); @fclose($slotHandle); $slotHandle = null; }
});
$exportConfig = match ((string)$job['transacao']) { 'seguranca_grupos' => ['view' => 'vw_grupos_grid', 'tipo' => 'Cadastro de grupos', 'base' => 'seguranca_grupos'], 'seguranca_usuarios' => ['view' => 'vw_usuarios_grid', 'tipo' => 'Cadastro de usuários', 'base' => 'seguranca_usuarios'], 'seguranca_perfil' => ['view' => 'vw_perfis_grid', 'tipo' => 'Cadastro de perfis', 'base' => 'seguranca_perfil'], 'seguranca_permissoes' => ['view' => 'vw_permissoes_grid', 'tipo' => 'Cadastro de permissões', 'base' => 'seguranca_permissoes'], 'seguranca_formulario' => ['view' => 'vw_formularios_campos_grid', 'tipo' => 'Configuração de campos de formulário', 'base' => 'seguranca_formulario'], 'seguranca_lista' => ['view' => 'vw_formulario_lista_grid', 'tipo' => 'Cadastro de listas', 'base' => 'seguranca_lista'], default => ['view' => 'vw_menus_grid', 'tipo' => 'Cadastro de menus - Setup / Menu', 'base' => 'setup_menu'] };
$legalExports = [
    'modulolegal_treinamentos' => ['view' => 'vw_modulolegal_treinamentos_grid', 'tipo' => 'Treinamentos', 'base' => 'modulolegal_treinamentos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'meus_treinamentos' => ['view' => 'vw_minhas_treinamentos_grid', 'tipo' => 'Meus treinamentos', 'base' => 'meus_treinamentos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'minhas_infraestruturas' => ['view' => 'vw_minhas_infraestrutura_grid', 'tipo' => 'Minhas infraestruturas', 'base' => 'minhas_infraestruturas', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_infraestrutura' => ['view' => 'vw_modulolegal_infraestrutura_grid', 'tipo' => 'Infraestrutura', 'base' => 'modulolegal_infraestrutura', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_correlacionamnetoambiental' => ['view' => 'vw_modulolegal_correlacionamento_ambiental_grid', 'tipo' => 'Correlacionamento ambiental', 'base' => 'modulolegal_correlacionamnetoambiental', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_correlacionamentoambiental_aspectos' => ['view' => 'vw_modulolegal_correlacionamento_ambiental_aspectos', 'tipo' => 'Aspectos relacionados', 'base' => 'modulolegal_correlacionamentoambiental_aspectos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_correlacionamentoambiental_atividades' => ['view' => 'vw_modulolegal_correlacionamento_ambiental_atividades', 'tipo' => 'Atividades relacionadas', 'base' => 'modulolegal_correlacionamentoambiental_atividades', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_correlacionamentoambiental_impactos' => ['view' => 'vw_modulolegal_correlacionamento_ambiental_impactos', 'tipo' => 'Impactos relacionados', 'base' => 'modulolegal_correlacionamentoambiental_impactos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_correlacionamentoambiental_licencas' => ['view' => 'vw_modulolegal_correlacionamento_ambiental_licencas', 'tipo' => 'Licencas relacionadas', 'base' => 'modulolegal_correlacionamentoambiental_licencas', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_correlacionamentoambiental_documentos' => ['view' => 'vw_modulolegal_correlacionamento_ambiental_documentos', 'tipo' => 'Documentos relacionados', 'base' => 'modulolegal_correlacionamentoambiental_documentos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_correlacionamentosocial' => ['view' => 'vw_modulolegal_correlacionamento_social_grid', 'tipo' => 'Correlacionamento social', 'base' => 'modulolegal_correlacionamentosocial', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_correlacionamentosocial_aspectos' => ['view' => 'vw_modulolegal_correlacionamento_social_aspectos', 'tipo' => 'Aspectos sociais relacionados', 'base' => 'modulolegal_correlacionamentosocial_aspectos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_correlacionamentosocial_impactos' => ['view' => 'vw_modulolegal_correlacionamento_social_impactos', 'tipo' => 'Impactos sociais relacionados', 'base' => 'modulolegal_correlacionamentosocial_impactos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_correlacionamentosocial_documentos' => ['view' => 'vw_modulolegal_correlacionamento_social_documentos', 'tipo' => 'Documentos relacionados', 'base' => 'modulolegal_correlacionamentosocial_documentos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_correlacionamentoocupacional' => ['view' => 'vw_modulolegal_correlacionamento_ocupacional_grid', 'tipo' => 'Correlacionamento ocupacional', 'base' => 'modulolegal_correlacionamentoocupacional', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_correlacionamentoocupacional_perigos' => ['view' => 'vw_modulolegal_correlacionamento_ocupacional_perigos', 'tipo' => 'Perigos ocupacionais relacionados', 'base' => 'modulolegal_correlacionamentoocupacional_perigos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_correlacionamentoocupacional_atividades' => ['view' => 'vw_modulolegal_correlacionamento_ocupacional_atividades', 'tipo' => 'Atividades ocupacionais relacionadas', 'base' => 'modulolegal_correlacionamentoocupacional_atividades', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_correlacionamentoocupacional_riscos' => ['view' => 'vw_modulolegal_correlacionamento_ocupacional_riscos', 'tipo' => 'Riscos ocupacionais relacionados', 'base' => 'modulolegal_correlacionamentoocupacional_riscos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_correlacionamentoocupacional_licencas' => ['view' => 'vw_modulolegal_correlacionamento_ocupacional_licencas', 'tipo' => 'Licencas relacionadas', 'base' => 'modulolegal_correlacionamentoocupacional_licencas', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_correlacionamentoocupacional_documentos' => ['view' => 'vw_modulolegal_correlacionamento_ocupacional_documentos', 'tipo' => 'Documentos relacionados', 'base' => 'modulolegal_correlacionamentoocupacional_documentos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'manutencaolegal_tipos' => ['view' => 'vw_tipos_grid', 'tipo' => 'Cadastro de tipos de legislação', 'base' => 'manutencaolegal_tipos', 'database' => 'glegislacao_dbp', 'prefix' => 'GLEGISLACAO'],
    'manutencaolegal_ambitos' => ['view' => 'vw_ambitos_grid', 'tipo' => 'Cadastro de âmbitos', 'base' => 'manutencaolegal_ambitos', 'database' => 'glegislacao_dbp', 'prefix' => 'GLEGISLACAO'],
    'manutencaolegal_aspectos' => ['view' => 'vw_aspectos_grid', 'tipo' => 'Cadastro de aspectos', 'base' => 'manutencaolegal_aspectos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'manutencaolegal_impactos' => ['view' => 'vw_impactos_grid', 'tipo' => 'Cadastro de impactos', 'base' => 'manutencaolegal_impactos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'manutencaolegal_perigos' => ['view' => 'vw_perigos_grid', 'tipo' => 'Cadastro de perigos', 'base' => 'manutencaolegal_perigos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'manutencaolegal_riscos' => ['view' => 'vw_riscos_grid', 'tipo' => 'Cadastro de riscos', 'base' => 'manutencaolegal_riscos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'manutencaolegal_assuntos' => ['view' => 'vw_assuntos_grid', 'tipo' => 'Cadastro de assuntos', 'base' => 'manutencaolegal_assuntos', 'database' => 'glegislacao_dbp', 'prefix' => 'GLEGISLACAO'],
    'administracao_unidades' => ['view' => 'vw_unidades_grid', 'tipo' => 'Cadastro de unidades', 'base' => 'administracao_unidades', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'administracao_areas' => ['view' => 'vw_areas_grid', 'tipo' => 'Cadastro de áreas', 'base' => 'administracao_areas', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'administracao_atividades' => ['view' => 'vw_atividades_grid', 'tipo' => 'Cadastro de atividades', 'base' => 'administracao_atividades', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_requisitoslegais' => ['view' => 'vw_modulolegal_requisitos_filtro_grid', 'tipo' => 'Requisitos legais', 'base' => 'modulolegal_requisitoslegais', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_laia' => ['view' => 'vw_laia_grid', 'tipo' => 'LAIA', 'base' => 'modulolegal_laia', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_lpro' => ['view' => 'vw_lpro_grid', 'tipo' => 'LPRO', 'base' => 'modulolegal_lpro', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_lais' => ['view' => 'vw_lais_grid', 'tipo' => 'LAIS', 'base' => 'modulolegal_lais', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'modulolegal_controle' => ['view' => 'vw_controle_documentos_grid', 'tipo' => 'Controle de documentos', 'base' => 'modulolegal_controle', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'meus_controles' => ['view' => 'vw_meus_controles_grid', 'tipo' => 'Meus controles', 'base' => 'meus_controles', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'manutencaolegal_licencas' => ['view' => 'vw_licencas_grid', 'tipo' => 'Cadastro de licenças', 'base' => 'manutencaolegal_licencas', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
    'minhas_licencas' => ['view' => 'vw_licencas_grid', 'tipo' => 'Minhas licenças', 'base' => 'minhas_licencas', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
     'manutencaolegal_legislacao' => ['view' => 'vw_legislacao_grid', 'tipo' => 'Cadastro de legislação', 'base' => 'manutencaolegal_legislacao', 'database' => 'glegislacao_dbp', 'prefix' => 'GLEGISLACAO', 'special' => 'legislacao'],
     'manutencaolegal_legislacaoxempresa' => ['view' => 'vw_legislacaoxempresa_matrix', 'tipo' => 'Legislação x Empresas', 'base' => 'manutencaolegal_legislacaoxempresa', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
     'manutencaolegal_legislacaoxpoder' => ['view' => 'vw_legislacaoxpoder_matrix', 'tipo' => 'Legislação x Poder', 'base' => 'manutencaolegal_legislacaoxpoder', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT'],
 ];
$columnPayload = json_decode((string)($job['colunas'] ?? '[]'), true);
$runtimeExportConfig = is_array($columnPayload) && is_array($columnPayload['__config'] ?? null) ? $columnPayload['__config'] : [];
if (isset($legalExports[(string)$job['transacao']])) {
    $exportConfig = $legalExports[(string)$job['transacao']];
    $exportPdo = $pdo;
    if ($exportConfig['database'] !== $tenantDatabase) {
        $exportPdo = new PDO('mysql:host=' . ($env[$exportConfig['prefix'] . '_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env[$exportConfig['prefix'] . '_DB_PORT'] ?? '3306') . ';dbname=' . $exportConfig['database'] . ';charset=utf8mb4', $env[$exportConfig['prefix'] . '_DB_USERNAME'] ?? '', $env[$exportConfig['prefix'] . '_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
} else {
    $exportPdo = $pdo;
}
if ($runtimeExportConfig && isset($runtimeExportConfig['view'], $runtimeExportConfig['database'], $runtimeExportConfig['prefix'])) {
    $runtimeExportConfig['view'] = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$runtimeExportConfig['view']);
    $runtimeExportConfig['database'] = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$runtimeExportConfig['database']);
    $runtimeExportConfig['prefix'] = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$runtimeExportConfig['prefix']);
    if ($runtimeExportConfig['view'] !== '' && $runtimeExportConfig['database'] !== '' && $runtimeExportConfig['prefix'] !== '') {
        $exportConfig = array_merge($exportConfig, $runtimeExportConfig);
        if ($exportConfig['database'] !== $tenantDatabase && $exportPdo === $pdo) {
            $exportPdo = new PDO('mysql:host=' . ($env[$exportConfig['prefix'] . '_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env[$exportConfig['prefix'] . '_DB_PORT'] ?? '3306') . ';dbname=' . $exportConfig['database'] . ';charset=utf8mb4', $env[$exportConfig['prefix'] . '_DB_USERNAME'] ?? '', $env[$exportConfig['prefix'] . '_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        }
    }
}
// Pesquisa de documentos (transação busca_documentos): os dados vêm de sp_busca_pesquisar com os parâmetros e as permissões
// do usuário gravados no pedido (filtros), e não de uma view; mesma fila, alertas e planilha das outras exportações.
if ((string)($job['transacao'] ?? '') === 'busca_documentos') {
    $exportConfig = ['view' => '', 'tipo' => 'Pesquisa de documentos', 'base' => 'busca_documentos', 'special' => 'busca'];
    $exportPdo = $pdo;
}
// Painel de Homologação (migração 298): mesmas consultas e cores da tela (classes/HomologacaoPainelExportacao.php).
if ((string)($job['transacao'] ?? '') === 'homologacaofornecedores_painel') {
    $exportConfig = ['view' => '', 'tipo' => 'Painel de Homologação', 'base' => 'homologacaofornecedores_painel', 'special' => 'painel_homologacao'];
    $exportPdo = $pdo;
}
// Documentos do fornecedor (tela Documentos/critérios): mesmas linhas e filtros da tela (classes/HomologacaoDocumentosExportacao.php).
if ((string)($job['transacao'] ?? '') === 'homologacaofornecedor_fornecedorxcriterios') {
    $exportConfig = ['view' => '', 'tipo' => 'Documentos do fornecedor', 'base' => 'homologacaofornecedor_fornecedorxcriterios', 'special' => 'documentos_fornecedor'];
    $exportPdo = $pdo;
}
// Relatórios de Homologação (migração 314): mesmas regras da tela (classes/HomologacaoRelatoriosExportacao.php).
if ((string)($job['transacao'] ?? '') === 'homologacaofornecedores_relatorios') {
    $exportConfig = ['view' => '', 'tipo' => 'Relatórios de Homologação', 'base' => 'homologacaofornecedores_relatorios', 'special' => 'relatorios_homologacao'];
    $exportPdo = $pdo;
}
// Consumo de IA (migração 340): chamadas do mês pedido (classes/IaConsumoExportacao.php).
if ((string)($job['transacao'] ?? '') === 'administracao_consumo_ia') {
    require_once $root . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'IaConsumoExportacao.php';
    $exportConfig = ['view' => '', 'tipo' => IaConsumoExportacao::titulo($job), 'base' => 'administracao_consumo_ia', 'special' => 'consumo_ia'];
    $exportPdo = $pdo;
}
// Registros órfãos de uma migração (Administração › Migração › Log): classes/MigracaoOrfaosExportacao.php.
if ((string)($job['transacao'] ?? '') === 'administracao_migracao_log') {
    $exportConfig = ['view' => '', 'tipo' => 'Registros órfãos da migração', 'base' => 'administracao_migracao_log', 'special' => 'migracao_orfaos'];
    $exportPdo = $pdo;
}
// Relatório de Atendimento Legal (migração 284): PDF ou Excel gerado por classes/RelatorioAtendimentoLegal.php, mesma fila e alertas.
if ((string)($job['transacao'] ?? '') === 'modulolegal_relatorio_atendimento') {
    $exportConfig = ['view' => '', 'tipo' => 'Relatório de Atendimento Legal', 'base' => 'modulolegal_relatorio_atendimento', 'special' => 'relatorio_legal'];
    $exportPdo = $pdo;
}
$dir = $root . DIRECTORY_SEPARATOR . 'exports'; if (!is_dir($dir)) mkdir($dir, 0775, true);
$userRows = workerCall($pdo, 'CALL sp_crud_select(:tabela, :filtros, NULL, 1, 0)', ['tabela' => 'vw_usuarios_grid', 'filtros' => json_encode(['codusuario' => (int)$job['codusuario']])]);
$userName = (string)($userRows[0]['nome'] ?? $userRows[0]['userid'] ?? 'usuario_' . (int)$job['codusuario']);
$exportRequesterProfile = strtoupper(trim((string)($userRows[0]['perfil_codigo'] ?? '')));
$functionalName = match ((string)$job['transacao']) { 'modulolegal_relatorio_atendimento' => 'Relatorio_atendimento_legal', 'modulolegal_requisitoslegais' => 'Requisitos_legais', 'manutencaolegal_licencas', 'minhas_licencas' => 'Licencas', default => filenamePart((string)($exportConfig['tipo'] ?? $exportConfig['base'] ?? 'Exportacao'), 'Exportacao') };
$base = $functionalName . '_' . date('Ymd_His') . '_' . filenamePart($userName, 'usuario_' . (int)$job['codusuario']); $xlsx = $dir . DIRECTORY_SEPARATOR . $base . '.xlsx';
try {
    if (($exportConfig['special'] ?? '') === 'relatorio_legal') {
        require_once $root . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'RelatorioAtendimentoLegal.php';
        $generated = RelatorioAtendimentoLegal::gerar($pdo, $job, $dir, $base, $userName, $root);
        goto concluir;
    }
    if (($exportConfig['special'] ?? '') === 'migracao_orfaos') {
        require_once $root . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'MigracaoOrfaosExportacao.php';
        createProfessionalXlsx($xlsx, MigracaoOrfaosExportacao::linhas($env, $job), $userName, date('d/m/Y H:i:s'), $exportConfig['tipo']);
        $generated = $xlsx;
        goto concluir;
    }
    if (in_array(($exportConfig['special'] ?? ''), ['painel_homologacao', 'documentos_fornecedor', 'relatorios_homologacao', 'consumo_ia'], true)) {
        $classeEspecial = match ($exportConfig['special']) { 'painel_homologacao' => 'HomologacaoPainelExportacao', 'relatorios_homologacao' => 'HomologacaoRelatoriosExportacao', 'consumo_ia' => 'IaConsumoExportacao', default => 'HomologacaoDocumentosExportacao' };
        require_once $root . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . $classeEspecial . '.php';
        createProfessionalXlsx($xlsx, $classeEspecial::linhas($pdo, $job), $userName, date('d/m/Y H:i:s'), $exportConfig['tipo']);
        $generated = $xlsx;
        goto concluir;
    }
    $filters = json_decode((string)($job['filtros'] ?? '{}'), true);
    $filters = is_array($filters) ? $filters : [];
    if (($job['transacao'] ?? '') === 'seguranca_usuarios' && $exportRequesterProfile !== 'ADMINISTRADOR') {
        $filters['perfil_codigo'] = ['operator' => 'not', 'value' => 'ADMINISTRADOR'];
    }
    $search = trim((string)($job['busca'] ?? ''));
    $exportColumnLabels = $columnPayload;
    if (is_array($exportColumnLabels) && array_key_exists('colunas', $exportColumnLabels)) $exportColumnLabels = $exportColumnLabels['colunas'];
    $exportColumnLabels = is_array($exportColumnLabels) ? $exportColumnLabels : [];
    $exportColumnMaps = [];
    $exportDateFormats = [];
    $exportSources = [];
    $exportGedCounts = [];
    if (array_is_list($exportColumnLabels)) {
        $orderedColumnLabels = [];
        foreach ($exportColumnLabels as $item) {
            if (is_array($item) && (string)($item['campo'] ?? '') !== '') {
                $column = (string)$item['campo'];
                $orderedColumnLabels[$column] = (string)($item['rotulo'] ?? $column);
                if (is_array($item['mapa'] ?? null)) $exportColumnMaps[$column] = $item['mapa'];
                if ((string)($item['formato_data'] ?? '') !== '') $exportDateFormats[$column] = (string)$item['formato_data'];
                // 'origem': a planilha mostra outra coluna da view (ex.: nome da função no lugar do código).
                if (preg_match('/^[A-Za-z0-9_]+$/', (string)($item['origem'] ?? ''))) $exportSources[$column] = (string)$item['origem'];
                // 'ged': quantidade de arquivos do registro no GED (pasta + coluna com o código do registro).
                if (is_array($item['ged'] ?? null) && preg_match('/^[a-z0-9_]+$/', (string)($item['ged']['pasta'] ?? '')) && preg_match('/^[A-Za-z0-9_]+$/', (string)($item['ged']['chave'] ?? ''))) $exportGedCounts[$column] = $item['ged'];
            }
            elseif (is_string($item) && $item !== '') $orderedColumnLabels[$item] = $item;
        }
        $exportColumnLabels = $orderedColumnLabels;
    }
    if (($job['transacao'] ?? '') === 'seguranca_usuarios') {
        foreach (array_keys($exportColumnLabels) as $column) {
            if (strtolower((string)$column) === 'senha') unset($exportColumnLabels[$column]);
        }
        $userDisplaySources = [
            'codperfil' => 'perfil_nome',
            'codgrupo' => 'grupo_nome',
            'codusuario_superior' => 'usuario_superior_nome',
        ];
        foreach ($userDisplaySources as $column => $source) {
            if (array_key_exists($column, $exportColumnLabels)) $exportSources[$column] = $source;
        }
    }
    $exportColumns = array_keys($exportColumnLabels);
    // Contagem de anexos no GED (mesmas pastas da Gestão de Arquivos), só quando alguma coluna pede.
    $gedStorage = null;
    $gedCount = static function (string $pasta, int $codigo) use (&$gedStorage, $exportPdo, $env, $tenantDatabase, $root): int {
        if ($codigo < 1) return 0;
        if ($gedStorage === null) {
            require_once $root . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'GedStorageService.php';
            $gedStorage = new GedStorageService($exportPdo, $env, ['tenant' => ['banco_dados' => $tenantDatabase]]);
        }
        $nomes = [];
        foreach ($gedStorage->folders($pasta, $codigo) as $folder) {
            if (!is_dir($folder)) continue;
            foreach (new DirectoryIterator($folder) as $file) if (!$file->isDot() && $file->isFile()) $nomes[strtolower($file->getFilename())] = true;
        }
        return count($nomes);
    };
    $rows = [];
    $offset = 0;
    do {
        if (($exportConfig['special'] ?? '') === 'busca') {
            $batch = buscaExportarLote($exportPdo, $filters, $offset);
        } elseif (($exportConfig['special'] ?? '') === 'legislacao') {
            $value = static function (mixed $raw, bool $numeric = false): ?string { $text = trim((string)$raw, "| \t\n\r\0\x0B"); if ($text === '') return null; return $numeric && !ctype_digit($text) ? null : $text; };
            $batch = workerCall($exportPdo, 'CALL sp_legislacao_select(:empresa,:unidade,:periodo,:padrao,:busca,:filtros,1000,:offset)', ['empresa' => $value($filters['CODEMPRESA'] ?? null, true), 'unidade' => $value($filters['CODUNIDADE'] ?? null, true), 'periodo' => $value($filters['MES_ANO_ATUALIZACAO'] ?? null), 'padrao' => trim((string)($filters['PADRAO'] ?? '')) ?: null, 'busca' => $search ?: null, 'filtros' => json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'offset' => $offset]);
        } else {
            $matrixView = $exportConfig['view'];
            if (($job['transacao'] ?? '') === 'manutencaolegal_legislacaoxempresa' && (($filters['CORRELACIONADO'] ?? '') !== 'Sim')) $matrixView = 'vw_legislacaoxempresa_matrix_all';
            if (($job['transacao'] ?? '') === 'manutencaolegal_legislacaoxpoder' && (($filters['CORRELACIONADO'] ?? '') !== 'Sim')) $matrixView = 'vw_legislacaoxpoder_matrix_all';
            $batch = workerCall($exportPdo, 'CALL sp_crud_select(:tabela, :filtros, :busca, 1000, :offset)', ['tabela' => $matrixView, 'filtros' => json_encode($filters, JSON_UNESCAPED_UNICODE), 'busca' => $search, 'offset' => $offset]);
        }
        if (in_array(($job['transacao'] ?? ''), ['manutencaolegal_licencas', 'minhas_licencas'], true)) {
            // O grid apresenta o nome do responsável, embora a chave de
            // exportação continue sendo CODUSUARIO para preservar a coluna.
            foreach ($batch as &$licenseRow) {
                if (array_key_exists('CODUSUARIO', $licenseRow) && array_key_exists('RESPONSAVEL', $licenseRow)) $licenseRow['CODUSUARIO'] = $licenseRow['RESPONSAVEL'];
            }
            unset($licenseRow);
        }
        foreach ($batch as &$displayRow) {
            foreach ($exportSources as $column => $source) {
                if (array_key_exists($source, $displayRow)) $displayRow[$column] = $displayRow[$source];
            }
            foreach ($exportGedCounts as $column => $ged) {
                $displayRow[$column] = $gedCount((string)$ged['pasta'], (int)($displayRow[(string)$ged['chave']] ?? 0));
            }
            // Datas zeradas do legado (0000-00-00) saem em branco, e não como 30/11/-0001.
            foreach ($displayRow as $column => $cellValue) {
                if (is_string($cellValue) && preg_match('/^0000-00-00( 00:00(:00)?)?$/', trim($cellValue))) $displayRow[$column] = '';
            }
            foreach ($exportColumnMaps as $column => $map) {
                if (array_key_exists($column, $displayRow) && array_key_exists((string)$displayRow[$column], $map)) $displayRow[$column] = $map[(string)$displayRow[$column]];
            }
            foreach ($exportDateFormats as $column => $format) {
                if (!array_key_exists($column, $displayRow) || trim((string)$displayRow[$column]) === '') continue;
                $raw = substr(trim((string)$displayRow[$column]), 0, 10);
                $date = DateTime::createFromFormat('Y-m-d', $raw);
                if ($date instanceof DateTime) $displayRow[$column] = $date->format($format);
            }
            if (($job['transacao'] ?? '') === 'seguranca_usuarios') {
                foreach (['codperfil'=>'perfil_nome','codgrupo'=>'grupo_nome','codusuario_superior'=>'usuario_superior_nome'] as $column => $source) {
                    if (array_key_exists($column, $displayRow) && array_key_exists($source, $displayRow)) $displayRow[$column] = $displayRow[$source];
                }
                foreach (array_keys($displayRow) as $column) {
                    if (strtolower((string)$column) === 'senha') unset($displayRow[$column]);
                }
                if (array_key_exists('ativo', $displayRow)) $displayRow['ativo'] = (int)$displayRow['ativo'] === 1 ? 'Sim' : 'Não';
            }
        }
        unset($displayRow);
        if ($exportColumns) {
            $batch = array_map(static function (array $row) use ($exportColumns): array {
                $filtered = [];
                foreach ($exportColumns as $column) if (array_key_exists($column, $row)) $filtered[$column] = $row[$column];
                return $filtered;
            }, $batch);
        }
        $rows = array_merge($rows, $batch);
        $offset += count($batch);
    } while (count($batch) === 1000);
    if ($exportColumnLabels && array_keys($exportColumnLabels) !== range(0, count($exportColumnLabels) - 1)) {
        $rows = array_map(static function (array $row) use ($exportColumnLabels): array {
            $labeled = [];
            foreach ($exportColumnLabels as $column => $label) if (array_key_exists((string)$column, $row)) $labeled[(string)$label] = $row[(string)$column];
            return $labeled;
        }, $rows);
    }
    createProfessionalXlsx($xlsx, $rows, $userName, date('d/m/Y H:i:s'), $exportConfig['tipo']);
    $generated = $xlsx;
    concluir:
    $finishedAt = date('Y-m-d H:i:s');
    $data = ['arquivo' => '/exports/' . basename($generated), 'status' => 'CONCLUIDA', 'concluido_em' => $finishedAt, 'erro' => null];
    workerCall($pdo, 'CALL sp_crud_update(:tabela, :chave, :dados)', ['tabela' => 'exportacoes_grid', 'chave' => json_encode(['codexportacaogrid' => $jobId]), 'dados' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
    $statusLink = '/principal/export_status.php?codexportacaogrid=' . $jobId . '&transacao=' . rawurlencode((string)$job['transacao']);
    workerCall($pdo, 'CALL sp_crud_insert(:tabela, :dados)', ['tabela' => 'alertas', 'dados' => json_encode(['codusuario' => $job['codusuario'], 'titulo' => 'Exportação concluída', 'mensagem' => (str_ends_with(strtolower($generated), '.pdf') ? 'O PDF de ' : 'A planilha de ') . $exportConfig['tipo'] . ' está disponível para download. Início: ' . date('d/m/Y H:i:s', strtotime($startedAt) ?: time()) . '. Fim: ' . date('d/m/Y H:i:s', strtotime($finishedAt) ?: time()) . '.', 'link' => '/exports/' . basename($generated), 'link_status' => $statusLink, 'lido' => 0], JSON_UNESCAPED_UNICODE)]);
} catch (Throwable $e) {
    $finishedAt = date('Y-m-d H:i:s');
    workerCall($pdo, 'CALL sp_crud_update(:tabela, :chave, :dados)', ['tabela' => 'exportacoes_grid', 'chave' => json_encode(['codexportacaogrid' => $jobId]), 'dados' => json_encode(['status' => 'ERRO', 'concluido_em' => $finishedAt, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE)]);
    workerCall($pdo, 'CALL sp_crud_insert(:tabela, :dados)', ['tabela' => 'alertas', 'dados' => json_encode(['codusuario' => $job['codusuario'], 'titulo' => 'Erro na exportação', 'mensagem' => 'Não foi possível gerar a planilha de ' . $exportConfig['tipo'] . '. Início: ' . $startedAt . '. Fim: ' . $finishedAt . '. Erro: ' . $e->getMessage(), 'lido' => 0], JSON_UNESCAPED_UNICODE)]);
}
