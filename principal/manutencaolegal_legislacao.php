<?php
declare(strict_types=1);
session_start();
header('Content-Type: text/html; charset=utf-8');
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'UsuarioSessao.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'LegislacaoGridService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'PermissaoTransacao.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'botoes_crud.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'TransactionSecurity.php';

if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
$config = [];
foreach (file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    if (!array_key_exists(trim($key), $config)) $config[trim($key)] = trim(trim($value), "\"'");
}
$tenantPdo = new PDO('mysql:host=' . ($config['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($config['GPT_DB_PORT'] ?? '3306') . ';dbname=' . $_SESSION['tenant']['banco_dados'] . ';charset=utf8mb4', $config['GPT_DB_USERNAME'] ?? '', $config['GPT_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (!UsuarioSessao::atualizar($tenantPdo, $_SESSION)) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
$tenantPdo = TransactionSecurity::connect('LEGISLACAO');
$isAdmin = ($_SESSION['usuario']['codigo_perfil'] ?? '') === 'ADMINISTRADOR';
$gridPermission = PermissaoTransacao::obter('LEGISLACAO');
$hasViewPermission = $isAdmin;
foreach ((array)($_SESSION['permissoes'] ?? []) as $permission) {
    if (strtoupper((string)($permission['menu_codigo'] ?? '')) === 'LEGISLACAO' && (int)($permission['pode_visualizar'] ?? 0) === 1) $hasViewPermission = true;
}
if (!$hasViewPermission) { http_response_code(403); exit('Seu perfil nao possui permissao para visualizar esta transacao.'); }

$legislacao = new LegislacaoGridService(new PDO('mysql:host=' . ($config['GLEGISLACAO_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($config['GLEGISLACAO_DB_PORT'] ?? '3306') . ';dbname=' . ($config['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp') . ';charset=utf8mb4', $config['GLEGISLACAO_DB_USERNAME'] ?? '', $config['GLEGISLACAO_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]));
$universalPdo = new PDO('mysql:host=' . ($config['GUNIVERSAL_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($config['GUNIVERSAL_DB_PORT'] ?? '3306') . ';dbname=' . ($config['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp') . ';charset=utf8mb4', $config['GUNIVERSAL_DB_USERNAME'] ?? '', $config['GUNIVERSAL_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$callTenant = static function (PDO $pdo, string $sql, array $params = []): array { $statement = $pdo->prepare($sql); $statement->execute($params); $rows = $statement->fetchAll(PDO::FETCH_ASSOC); $statement->closeCursor(); return $rows; };
$idioma = strtolower((string)($_SESSION['idioma'] ?? 'pt'));
$ambitos = $legislacao->call('CALL sp_crud_select(:tabela,:filtros,:busca,10000,0)', ['tabela' => 'ambitos', 'filtros' => '{}', 'busca' => '']);
$tipos = $legislacao->call('CALL sp_crud_select(:tabela,:filtros,:busca,10000,0)', ['tabela' => 'tipos', 'filtros' => '{}', 'busca' => '']);
usort($tipos, static function (array $left, array $right): int {
    return strnatcasecmp((string)($left['DSCTIPO'] ?? ''), (string)($right['DSCTIPO'] ?? ''));
});
$companiesStatement = $universalPdo->prepare('CALL sp_crud_select(:tabela,:filtros,:busca,1000,0)');
$companiesStatement->execute(['tabela' => 'empresa', 'filtros' => json_encode(['status' => 'ATIVA'], JSON_UNESCAPED_UNICODE), 'busca' => '']);
$companies = $companiesStatement->fetchAll(PDO::FETCH_ASSOC); $companiesStatement->closeCursor();
$units = $callTenant($tenantPdo, 'CALL sp_crud_select(:tabela,:filtros,:busca,1000,0)', ['tabela' => 'unidades', 'filtros' => '{}', 'busca' => '']);
$companyRelations = [];
for ($relationOffset = 0; $relationOffset < 10000; $relationOffset += 1000) {
    $relationPage = $callTenant($tenantPdo, 'CALL sp_crud_select(:tabela,:filtros,:busca,1000,:offset)', ['tabela' => 'legislacaoxempresa', 'filtros' => '{}', 'busca' => '', 'offset' => $relationOffset]);
    if (!$relationPage) break;
    $companyRelations = array_merge($companyRelations, $relationPage);
    if (count($relationPage) < 1000) break;
}
$periodRows = $legislacao->call('CALL sp_crud_select(:tabela,:filtros,:busca,1000,0)', ['tabela' => 'vw_legislacao_periodos', 'filtros' => '{}', 'busca' => '']);
$formatDate = static function (mixed $value, string $language): string {
    $text = trim((string)$value);
    if ($text === '') return '';
    try { $date = new DateTime($text); } catch (Throwable) { return $text; }
    return $date->format($language === 'en' ? 'm/d/Y H:i' : 'd/m/Y H:i');
};
$parseDate = static function (mixed $value, string $language): ?string {
    $text = trim((string)$value);
    if ($text === '') return null;
    $formats = $language === 'en' ? ['m/d/Y H:i', 'm/d/Y H:i:s'] : ['d/m/Y H:i', 'd/m/Y H:i:s'];
    $formats[] = 'Y-m-d H:i'; $formats[] = 'Y-m-d H:i:s'; $formats[] = 'Y-m-d\TH:i';
    foreach ($formats as $format) { $date = DateTime::createFromFormat('!' . $format, $text); if ($date instanceof DateTime && DateTime::getLastErrors() === false) return $date->format('Y-m-d H:i:s'); }
    return null;
};
$periodOptions = [];
foreach ($periodRows as $periodRow) {
    $period = trim((string)($periodRow['periodo'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) continue;
    $periodOptions[$period] = substr($period, 5, 2) . '/' . substr($period, 0, 4);
}
uksort($periodOptions, static fn(string $left, string $right): int => strcmp($right, $left));
$uid = (int)$_SESSION['usuario']['codusuario'];
$json = static fn(mixed $value): string => LegislacaoGridService::json($value);
$legislationFilterParams = static function (array $filters, string $search = ''): array {
    $value = static function (mixed $raw, bool $numeric = false): ?string {
        $text = trim((string)$raw, "| \t\n\r\0\x0B");
        if ($text === '' || ($numeric && !ctype_digit($text))) return null;
        return $text;
    };
    $normalized = [];
    foreach ($filters as $key => $raw) {
        if (!is_scalar($raw)) continue;
        $text = trim((string)$raw, "| \t\n\r\0\x0B");
        if ($text !== '') $normalized[(string)$key] = $text;
    }
    return ['empresa' => $value($filters['CODEMPRESA'] ?? null, true), 'unidade' => $value($filters['CODUNIDADE'] ?? null, true), 'periodo' => $value($filters['MES_ANO_ATUALIZACAO'] ?? null), 'padrao' => trim((string)($filters['PADRAO'] ?? '')) ?: null, 'busca' => trim($search) ?: null, 'filtros' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
};
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' || ($_POST['ajax'] ?? '') === '1';
if ($isAjax && ($_POST['action'] ?? '') === 'load_assuntos' && ctype_digit((string)($_POST['CODLEGISLACAO'] ?? ''))) {
    $legislationId = (int)$_POST['CODLEGISLACAO'];
    $linkedRows = $legislacao->call('CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela' => 'legislacaoxassunto', 'filtros' => $json(['CODLEGISLACAO' => $legislationId])]);
    $linked = []; foreach ($linkedRows as $link) $linked[(string)$link['CODASSUNTO']] = true;
    $subjectRows = $legislacao->call('CALL sp_crud_select(:tabela,:filtros,:busca,1000,0)', ['tabela' => 'assuntos', 'filtros' => '{}', 'busca' => trim((string)($_POST['busca'] ?? ''))]);
    foreach (array_keys($linked) as $subjectId) { if (array_filter($subjectRows, static fn(array $subject): bool => (string)$subject['CODASSUNTO'] === (string)$subjectId)) continue; $extra = $legislacao->call('CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'assuntos', 'filtros' => $json(['CODASSUNTO' => (int)$subjectId])]); if ($extra) $subjectRows[] = $extra[0]; }
    usort($subjectRows, static fn(array $left, array $right): int => strcasecmp((string)($left['DSCASSUNTO'] ?? ''), (string)($right['DSCASSUNTO'] ?? '')));
    header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'assuntos' => array_map(static fn(array $subject): array => ['codigo' => (int)$subject['CODASSUNTO'], 'descricao' => (string)($subject['DSCASSUNTO'] ?? ''), 'associado' => isset($linked[(string)$subject['CODASSUNTO']])], $subjectRows)], JSON_UNESCAPED_UNICODE); exit;
}
if ($isAjax && ($_POST['action'] ?? '') === 'save_assuntos' && ctype_digit((string)($_POST['CODLEGISLACAO'] ?? ''))) {
    $legislationId = (int)$_POST['CODLEGISLACAO']; $selected = json_decode((string)($_POST['assuntos'] ?? '[]'), true); $selected = array_values(array_unique(array_filter(array_map('intval', is_array($selected) ? $selected : []))));
    $currentRows = $legislacao->call('CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela' => 'legislacaoxassunto', 'filtros' => $json(['CODLEGISLACAO' => $legislationId])]); $current = []; foreach ($currentRows as $link) $current[(int)$link['CODASSUNTO']] = true;
    foreach (array_keys($current) as $subjectId) if (!in_array($subjectId, $selected, true)) $legislacao->call('CALL sp_crud_delete(:tabela,:chave)', ['tabela' => 'legislacaoxassunto', 'chave' => $json(['CODLEGISLACAO' => $legislationId, 'CODASSUNTO' => $subjectId])]);
    foreach ($selected as $subjectId) if (!isset($current[$subjectId])) $legislacao->call('CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'legislacaoxassunto', 'dados' => $json(['CODLEGISLACAO' => $legislationId, 'CODASSUNTO' => $subjectId])]);
    $updated = $legislacao->call('CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'vw_legislacao_grid', 'filtros' => $json(['CODLEGISLACAO' => $legislationId])]);
    header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'assuntos' => (string)($updated[0]['ASSUNTOS'] ?? ''), 'message' => 'Assuntos atualizados com sucesso.'], JSON_UNESCAPED_UNICODE); exit;
}
if ($isAjax && ($_POST['action'] ?? '') === 'count_rows') { $filters = json_decode((string)($_POST['filtros'] ?? '{}'), true); $params = $legislationFilterParams(is_array($filters) ? $filters : [], (string)($_POST['busca'] ?? '')); $count = $legislacao->call('CALL sp_legislacao_count(:empresa,:unidade,:periodo,:padrao,:busca,:filtros)', $params); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'total' => (int)($count[0]['total_registros'] ?? 0)]); exit; }
if ($isAjax && ($_POST['action'] ?? '') === 'export_request' && !empty($gridPermission['pode_exportar_excel'])) {
    $uid = (int)($_SESSION['usuario']['codusuario'] ?? 0); $filters = json_decode((string)($_POST['filtros'] ?? '{}'), true); $filters = is_array($filters) ? $filters : []; $search = trim((string)($_POST['busca'] ?? ''));
    // Pedido na fila do GreenPortal-ExportWorker (Agendador do Windows) + alerta "Exportação iniciada".
    require_once dirname(__DIR__).DIRECTORY_SEPARATOR.'classes'.DIRECTORY_SEPARATOR.'ExportacaoSolicitacao.php';
    $jobId = ExportacaoSolicitacao::solicitar($tenantPdo, $uid, 'manutencaolegal_legislacao', 'Cadastro de legislação', ['filtros' => $filters, 'busca' => $search]);
    header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => $jobId > 0, 'message' => ExportacaoSolicitacao::mensagem()], JSON_UNESCAPED_UNICODE); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_preferences') { foreach ((array)json_decode((string)($_POST['preferences'] ?? '[]'), true) as $item) { $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($item['coluna'] ?? '')); if ($column === '') continue; $old = $callTenant($tenantPdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'vw_preferencias_grid', 'filtros' => $json(['codusuario' => $uid, 'transacao' => 'manutencaolegal_legislacao', 'coluna' => $column])]); $data = ['codusuario' => $uid, 'transacao' => 'manutencaolegal_legislacao', 'coluna' => $column, 'ordem' => (int)($item['ordem'] ?? 0), 'visivel' => (int)!empty($item['visivel']), 'largura' => max(60, (int)($item['largura'] ?? 140))]; if ($old) $callTenant($tenantPdo, 'CALL sp_crud_update(:tabela,:chave,:dados)', ['tabela' => 'preferencias_grid', 'chave' => $json(['codpreferenciagrid' => $old[0]['codpreferenciagrid']]), 'dados' => $json($data)]); else $callTenant($tenantPdo, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'preferencias_grid', 'dados' => $json($data)]); } if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true]); exit; } }
if ($isAjax && ($_POST['action'] ?? '') === 'save_rows') {
    $contextEmpresa = (int)preg_replace('/[^0-9]/', '', (string)($_POST['context_empresa'] ?? ''));
    $contextUnidade = (int)preg_replace('/[^0-9]/', '', (string)($_POST['context_unidade'] ?? ''));
    $rowsRequest = (array)($_POST['rows'] ?? []);
    $hasNewRows = false;
    foreach (array_keys($rowsRequest) as $rowKey) {
        if (!ctype_digit((string)$rowKey)) { $hasNewRows = true; break; }
    }
    $jsonResponse = static function (bool $ok, string $message, int $status = 200, array $extra = []): never {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
        exit;
    };
    if ($hasNewRows && (($contextEmpresa > 0) xor ($contextUnidade > 0))) {
        $jsonResponse(false, 'Para cadastrar uma lei vinculada, selecione a Empresa e a Unidade. Para uma lei geral, deixe os dois campos sem seleção.', 422);
    }
    $linkedContext = $contextEmpresa > 0 && $contextUnidade > 0;
    if ($hasNewRows && $linkedContext) {
        $validRelation = false;
        foreach ($companyRelations as $relation) {
            if ((int)($relation['CODEMPRESA'] ?? 0) === $contextEmpresa && (int)($relation['codunidade'] ?? 0) === $contextUnidade) { $validRelation = true; break; }
        }
        if (!$validRelation) $jsonResponse(false, 'A Empresa e a Unidade selecionadas não formam uma combinação válida.', 422);
    }
    $created = [];
    try {
        foreach ($rowsRequest as $key => $row) {
            $data = [];
            foreach (['CODIGO','CODAMBITO','CODTIPO','NR','ANO','ARTIGOS','MEIO_AMBIENTE','SEGURANCA','QUALIDADE','RESPOSABILIDADE','PADRAO','data_atualizacao','situacao_da_lei','devido_da_lei'] as $field) {
                $value = $row[$field] ?? null;
                if ($field === 'PADRAO') $data[$field] = trim((string)$value);
                elseif ($field === 'data_atualizacao') $data[$field] = $parseDate($value, $idioma);
                else $data[$field] = ($value === '') ? null : ($field === 'CODAMBITO' || $field === 'CODTIPO' ? (int)preg_replace('/[^0-9]/', '', (string)$value) : trim((string)$value));
            }
            if (ctype_digit((string)$key)) {
                $legislacao->call('CALL sp_crud_update(:tabela,:chave,:dados)', ['tabela' => 'legislacao', 'chave' => $json(['CODLEGISLACAO' => (int)$key]), 'dados' => $json($data)]);
                continue;
            }
            $legislacao->call('CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'legislacao', 'dados' => $json($data)]);
            $found = $legislacao->call('CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'vw_legislacao_grid', 'filtros' => $json(['CODIGO' => $data['CODIGO']])]);
            if (!$found) throw new RuntimeException('A nova legislação não foi localizada após o cadastro.');
            $newId = (int)$found[0]['CODLEGISLACAO'];
            $created[(string)$key] = $newId;
            if ($linkedContext) {
                try {
                    $callTenant($tenantPdo, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'legislacaoxempresa', 'dados' => $json(['CODLEGISLACAO' => $newId, 'CODEMPRESA' => $contextEmpresa, 'codunidade' => $contextUnidade])]);
                } catch (Throwable $relationError) {
                    $legislacao->call('CALL sp_crud_delete(:tabela,:chave)', ['tabela' => 'legislacao', 'chave' => $json(['CODLEGISLACAO' => $newId])]);
                    throw $relationError;
                }
            }
        }
        $message = $linkedContext ? 'Legislação cadastrada e vinculada à empresa e unidade.' : 'Legislação geral cadastrada com sucesso.';
        $jsonResponse(true, $message, 200, ['created' => $created]);
    } catch (Throwable $error) {
        $jsonResponse(false, 'Não foi possível concluir o cadastro da legislação. Verifique os dados informados.', 422);
    }
}
if ($isAjax && ($_POST['action'] ?? '') === 'delete_row' && ctype_digit((string)($_POST['CODLEGISLACAO'] ?? ''))) { $legislationId = (int)$_POST['CODLEGISLACAO']; $callTenant($tenantPdo, 'CALL sp_crud_delete(:tabela,:chave)', ['tabela' => 'legislacaoxempresa', 'chave' => $json(['CODLEGISLACAO' => $legislationId])]); $legislacao->call('CALL sp_crud_delete(:tabela,:chave)', ['tabela' => 'legislacaoxassunto', 'chave' => $json(['CODLEGISLACAO' => $legislationId])]); $legislacao->call('CALL sp_crud_delete(:tabela,:chave)', ['tabela' => 'legislacao', 'chave' => $json(['CODLEGISLACAO' => $legislationId])]); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'message' => 'Legislacao excluida com sucesso.'], JSON_UNESCAPED_UNICODE); exit; }
$page = max(1, (int)($_GET['pagina'] ?? 1)); $offset = ($page - 1) * 25; $initialFilters = json_decode((string)($_GET['filtros'] ?? '{}'), true); $initialFilters = is_array($initialFilters) ? $initialFilters : []; $initialSearch = trim((string)($_GET['busca'] ?? ''));
$filterParams = $legislationFilterParams($initialFilters, $initialSearch);
$rows = $legislacao->call('CALL sp_legislacao_select(:empresa,:unidade,:periodo,:padrao,:busca,:filtros,25,:offset)', $filterParams + ['offset' => $offset]);
$patternValues = []; foreach ($rows as $patternRow) $patternValues[(string)$patternRow['CODLEGISLACAO']] = (string)($patternRow['PADRAO'] ?? '');
$prefRows = $callTenant($tenantPdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,100,0)', ['tabela' => 'vw_preferencias_grid', 'filtros' => $json(['codusuario' => $uid, 'transacao' => 'manutencaolegal_legislacao'])]); $preferences = []; foreach ($prefRows as $preference) $preferences[(string)$preference['coluna']] = $preference;
$ambitoOptions = []; foreach ($ambitos as $item) $ambitoOptions[(string)$item['CODAMBITO']] = (string)($item['DSCAMBITO'] ?? $item['CODAMBITO']);
$tipoOptions = []; foreach ($tipos as $item) $tipoOptions[(string)$item['CODTIPO']] = (string)($item['DSCTIPO'] ?? $item['CODTIPO']);
$companyOptions = []; foreach ($companies as $company) $companyOptions['|' . (int)$company['codempresa'] . '|'] = (string)($company['nome'] ?? $company['codigo'] ?? $company['codempresa']);
$unitLabels = []; foreach ($units as $unit) $unitLabels['|' . (int)$unit['CODUNIDADE'] . '|'] = (string)($unit['DSCUNIDADE'] ?? $unit['CODUNIDADE']);
$companyUnitOptions = [];
foreach ($companyRelations as $relation) {
    $companyKey = '|' . (int)($relation['CODEMPRESA'] ?? 0) . '|';
    $unitKey = '|' . (int)($relation['codunidade'] ?? 0) . '|';
    if ($companyKey === '|0|' || $unitKey === '|0|' || !isset($unitLabels[$unitKey])) continue;
    $companyUnitOptions[$companyKey][$unitKey] = $unitLabels[$unitKey];
}
$decodeLabel = static fn(string $value): string => html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$columns = ['CODLEGISLACAO' => $decodeLabel('C&oacute;digo'), 'CODIGO' => $decodeLabel('C&oacute;digo'), 'ASSUNTOS' => 'Assuntos', 'CODAMBITO' => $decodeLabel('&Acirc;mbito'), 'CODTIPO' => 'Tipo', 'NR' => $decodeLabel('N&uacute;mero'), 'ANO' => 'Ano', 'ARTIGOS' => 'Artigos', 'MEIO_AMBIENTE' => 'Meio ambiente', 'SEGURANCA' => $decodeLabel('Seguran&ccedil;a'), 'QUALIDADE' => 'Qualidade', 'RESPOSABILIDADE' => 'Responsabilidade', 'PADRAO' => $decodeLabel('Padr&atilde;o'), 'data_atualizacao' => $decodeLabel('Data atualiza&ccedil;&atilde;o'), 'situacao_da_lei' => $decodeLabel('Situa&ccedil;&atilde;o da lei'), 'devido_da_lei' => 'Devido da lei'];
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$rclLabels = ['pt' => ['RCL Completa', 'RCL Padrão'], 'en' => ['Complete RCL', 'Standard RCL'], 'es' => ['RCL Completa', 'RCL Estándar']][$idioma] ?? ['RCL Completa', 'RCL Padrão'];
$rclButtons = '<button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-rcl-completa title="' . h($rclLabels[0]) . '" aria-label="' . h($rclLabels[0]) . '"><i class="bi bi-file-earmark-text"></i></button><button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-rcl-padrao title="' . h($rclLabels[1]) . '" aria-label="' . h($rclLabels[1]) . '"><i class="bi bi-file-earmark"></i></button>';
?>
<script>window.legislacaoGridOptions = <?php echo $json(['ambitos' => $ambitoOptions, 'tipos' => $tipoOptions, 'empresas' => $companyOptions, 'unidades' => $unitLabels, 'empresaUnidades' => $companyUnitOptions, 'periodos' => $periodOptions, 'filtros' => $initialFilters]); ?>; window.legislacaoPatternValues = <?php echo $json($patternValues); ?>; window.greenPortalIdioma = <?php echo $json($idioma); ?>;</script>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css" rel="stylesheet"><link href="../assets/css/main.css?v=15" rel="stylesheet"><link href="../assets/css/design-system.css?v=3" rel="stylesheet"><link href="../assets/css/modulo-legal.css?v=1" rel="stylesheet">
<section class="setup-fragment menu-grid-fragment legislacao-grid-fragment" data-grid-transaction="manutencaolegal_legislacao" data-preferences="<?php echo h($json($preferences)); ?>"><div class="crud-toolbar menu-grid-toolbar"><?php greenPortalCrudButtons(['form_id' => 'legislacaoGridForm', 'language' => $idioma, 'permission' => $gridPermission, 'permission_title' => 'Perfil sem permissão de acesso!', 'transaction' => 'manutencaolegal_legislacao', 'new_attribute' => 'data-new-legislacao', 'duplicate_attribute' => 'data-duplicate-legislacao', 'delete_attribute' => 'data-delete-legislacao', 'extras' => $rclButtons]); ?><div class="crud-search"><i class="bi bi-search"></i><input type="search" placeholder="Buscar no grid" aria-label="Buscar no grid"></div></div><form method="post" id="legislacaoGridForm" class="setup-form menu-grid-form"><input type="hidden" name="action" value="save_rows"><div class="menu-grid-scroll"><table class="table table-sm menu-batch-grid" id="legislacaoBatchGrid"><thead><tr><?php foreach ($columns as $column => $label): ?><th data-column="<?php echo h($column); ?>" style="width:<?php echo (int)($preferences[$column]['largura'] ?? 140); ?>px"><div class="column-heading"><span><?php echo h($label); ?></span><input class="column-filter" type="search" data-column-filter="<?php echo h($column); ?>" placeholder="Buscar" aria-label="Buscar <?php echo h($label); ?>"></div></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($rows as $row): $id = (int)$row['CODLEGISLACAO']; ?><tr data-row data-key="<?php echo $id; ?>"><?php foreach ($columns as $column => $label): ?><td data-column="<?php echo h($column); ?>"><?php if ($column === 'CODLEGISLACAO'): ?><input class="grid-input" value="<?php echo $id; ?>" readonly><?php else: ?><input class="grid-input" name="rows[<?php echo $id; ?>][<?php echo h($column); ?>]" value="<?php echo h($row[$column] ?? ''); ?>"><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div></form><nav class="grid-pagination" aria-label="Paginação do grid"><button type="button" class="btn btn-sm btn-outline-secondary grid-page-prev"><i class="bi bi-chevron-left"></i><span>Anterior</span></button><span class="grid-page-status">Calculando total...</span><button type="button" class="btn btn-sm btn-outline-secondary grid-page-next"><span>Próxima</span><i class="bi bi-chevron-right"></i></button></nav></section><script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script><script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script><script src="../assets/js/visualizador-pdf.js?v=3"></script><script src="../assets/js/legislacao-grid.js?v=13"></script><script src="../assets/js/grid-sort.js?v=1"></script><script src="../assets/js/grid-column-filters.js?v=3"></script><script src="../assets/js/grid-column-resize.js?v=1"></script><script src="../assets/js/grid-pagination.js?v=14"></script><script src="../assets/js/grid-loading.js?v=4"></script><script src="../assets/js/grid-ajax.js?v=2"></script>

POST['action'] ?? '') === 'save_rows') {
    $contextEmpresa = (int)preg_replace('/[^0-9]/', '', (string)(
