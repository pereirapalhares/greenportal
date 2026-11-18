<?php
declare(strict_types=1);
session_start();
header('Content-Type: text/html; charset=utf-8');
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'UsuarioSessao.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'LegislacaoGridService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'PermissaoTransacao.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'botoes_crud.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'TransactionSecurity.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'LegislacaoAssociacoes.php'; // migração 382
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'LegislacaoEmpresas.php'; // migração 384

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
// Migração 384: transação exclusiva do perfil ADMINISTRADOR (administra a legislação de todas as empresas migradas)
if (!$isAdmin) { http_response_code(403); exit(htmlspecialchars(LegislacaoTextos::t('grid.so_admin'), ENT_QUOTES, 'UTF-8')); }

$legislacao = new LegislacaoGridService(new PDO('mysql:host=' . ($config['GLEGISLACAO_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($config['GLEGISLACAO_DB_PORT'] ?? '3306') . ';dbname=' . ($config['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp') . ';charset=utf8mb4', $config['GLEGISLACAO_DB_USERNAME'] ?? '', $config['GLEGISLACAO_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]));
$callTenant = static function (PDO $pdo, string $sql, array $params = []): array { $statement = $pdo->prepare($sql); $statement->execute($params); $rows = $statement->fetchAll(PDO::FETCH_ASSOC); $statement->closeCursor(); return $rows; };
$idioma = strtolower((string)($_SESSION['idioma'] ?? 'pt'));
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
        // filtros das colunas chegam como {"value": "1", "exact": true} (grid-pagination.js); Empresa/Unidade/Mês como texto
        if (is_array($raw)) $raw = $raw['value'] ?? null;
        if (!is_scalar($raw)) continue;
        $text = trim((string)$raw, "| \t\n\r\0\x0B");
        if ($text !== '') $normalized[(string)$key] = $text;
    }
    return ['empresa' => $value($filters['CODEMPRESA'] ?? null, true), 'unidade' => $value($filters['CODUNIDADE'] ?? null, true), 'periodo' => $value($filters['MES_ANO_ATUALIZACAO'] ?? null), 'padrao' => trim((string)($filters['PADRAO'] ?? '')) ?: null, 'busca' => trim($search) ?: null, 'filtros' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
};
// Migração 383: Empresa e Unidade vêm do vínculo do banco da empresa logada (legislacaoxempresa), na MESMA linha.
// As procedures *_ids recebem a lista de leis já filtrada (NULL = sem filtro de empresa/unidade).
$legislationQuery = static function (array $params, bool $count, int $limit = 25, int $offset = 0) use ($legislacao): array {
    $ids = null;
    if ($params['empresa'] !== null || $params['unidade'] !== null) {
        // vínculo lei × empresa/unidade no banco da empresa escolhida (empresa e unidade na mesma linha); sem empresa a unidade não vale
        try { $ids = $params['empresa'] !== null ? LegislacaoEmpresas::leis((int)$params['empresa'], $params['unidade'] !== null ? (int)$params['unidade'] : null) : null; }
        catch (InvalidArgumentException) { $ids = []; }
    }
    $args = ['ids' => $ids === null ? null : json_encode($ids), 'periodo' => $params['periodo'], 'padrao' => $params['padrao'], 'busca' => $params['busca'], 'filtros' => $params['filtros']];
    try {
        if ($count) return $legislacao->call('CALL sp_legislacao_count_ids(:ids,:periodo,:padrao,:busca,:filtros)', $args);
        return $legislacao->call('CALL sp_legislacao_select_ids(:ids,:periodo,:padrao,:busca,:filtros,' . max(1, min(1000, $limit)) . ',:offset)', $args + ['offset' => max(0, $offset)]);
    } catch (PDOException $error) {
        if (!str_contains($error->getMessage(), '_ids')) throw $error; // antes da migração 383: procedures antigas
        if ($count) return $legislacao->call('CALL sp_legislacao_count(:empresa,:unidade,:periodo,:padrao,:busca,:filtros)', $params);
        return $legislacao->call('CALL sp_legislacao_select(:empresa,:unidade,:periodo,:padrao,:busca,:filtros,' . max(1, min(1000, $limit)) . ',:offset)', $params + ['offset' => max(0, $offset)]);
    }
};
// Migração 390: os filtros das colunas com lista mostram só os valores das leis que passam nos OUTROS filtros
// (Empresa, Unidade, Mês/Ano, Padrão, busca e as demais colunas), como o filtro automático do Excel.
$optionColumns = ['CODAMBITO', 'CODTIPO', 'MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE', 'situacao_da_lei', 'devido_da_lei'];
$optionKey = static function (mixed $value, string $column): string {
    $text = trim((string)$value, "| \t\n\r\0\x0B");
    if ($column === 'CODAMBITO' || $column === 'CODTIPO') return ctype_digit($text) ? (string)(int)$text : $text;
    // sem acento e em minúsculas, igual ao optionKey do legislacao-grid.js (mapa fixo: o iconv do Windows translitera diferente)
    return strtr(mb_strtolower($text, 'UTF-8'), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']);
};
$availableOptions = static function (array $filters, string $search) use ($legislationFilterParams, $legislacao, $optionColumns, $optionKey): ?array {
    $base = $filters; $wanted = [];
    foreach ($optionColumns as $column) {
        $raw = $filters[$column] ?? '';
        if (is_array($raw)) $raw = $raw['value'] ?? '';
        $key = is_scalar($raw) ? $optionKey($raw, $column) : '';
        if ($key !== '') $wanted[$column] = $key;
        unset($base[$column]);
    }
    $params = $legislationFilterParams($base, $search);
    $ids = null;
    if ($params['empresa'] !== null) {
        try { $ids = LegislacaoEmpresas::leis((int)$params['empresa'], $params['unidade'] !== null ? (int)$params['unidade'] : null); }
        catch (InvalidArgumentException) { $ids = []; }
    }
    try {
        $groups = $legislacao->call('CALL sp_legislacao_opcoes_ids(:ids,:periodo,:padrao,:busca,:filtros)', ['ids' => $ids === null ? null : json_encode($ids), 'periodo' => $params['periodo'], 'padrao' => $params['padrao'], 'busca' => $params['busca'], 'filtros' => $params['filtros']]);
    } catch (PDOException $error) {
        if (str_contains($error->getMessage(), 'sp_legislacao_opcoes_ids')) return null; // antes da migração 390: listas completas
        throw $error;
    }
    $out = array_fill_keys($optionColumns, []);
    foreach ($groups as $group) {
        $keys = [];
        foreach ($optionColumns as $column) $keys[$column] = $optionKey($group[$column] ?? '', $column);
        $misses = [];
        foreach ($wanted as $column => $value) if ($keys[$column] !== $value) $misses[] = $column;
        if (count($misses) > 1) continue;
        foreach ($optionColumns as $column) {
            if ($keys[$column] === '') continue;
            if ($misses && $misses[0] !== $column) continue; // a linha só falha no filtro desta coluna: o valor dela continua disponível
            $out[$column][$keys[$column]] = true;
        }
    }
    return array_map('array_keys', $out);
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
    header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'assuntos' => (string)($updated[0]['ASSUNTOS'] ?? ''), 'message' => LegislacaoTextos::t('grid.assuntos_ok')], JSON_UNESCAPED_UNICODE); exit;
}
if ($isAjax && ($_POST['action'] ?? '') === 'count_rows') { $filters = json_decode((string)($_POST['filtros'] ?? '{}'), true); $params = $legislationFilterParams(is_array($filters) ? $filters : [], (string)($_POST['busca'] ?? '')); $count = $legislationQuery($params, true); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'total' => (int)($count[0]['total_registros'] ?? 0)]); exit; }
if ($isAjax && ($_POST['action'] ?? '') === 'export_request' && !empty($gridPermission['pode_exportar_excel'])) {
    $uid = (int)($_SESSION['usuario']['codusuario'] ?? 0); $filters = json_decode((string)($_POST['filtros'] ?? '{}'), true); $filters = is_array($filters) ? $filters : []; $search = trim((string)($_POST['busca'] ?? ''));
    // Pedido na fila do GreenPortal-ExportWorker (Agendador do Windows) + alerta "Exportação iniciada".
    require_once dirname(__DIR__).DIRECTORY_SEPARATOR.'classes'.DIRECTORY_SEPARATOR.'ExportacaoSolicitacao.php';
    $jobId = ExportacaoSolicitacao::solicitar($tenantPdo, $uid, 'manutencaolegal_legislacao', LegislacaoTextos::t('grid.exportacao_titulo'), ['filtros' => $filters, 'busca' => $search]);
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
        $jsonResponse(false, LegislacaoTextos::t('grid.empresa_unidade'), 422);
    }
    $linkedContext = $contextEmpresa > 0 && $contextUnidade > 0;
    if ($hasNewRows && $linkedContext) {
        if (!isset(LegislacaoEmpresas::migradas()[$contextEmpresa]) || !isset(LegislacaoEmpresas::unidades($contextEmpresa)[$contextUnidade])) $jsonResponse(false, LegislacaoTextos::t('grid.empresa_unidade_invalida'), 422);
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
            if (!$found) throw new RuntimeException(LegislacaoTextos::t('grid.nao_localizada'));
            $newId = (int)$found[0]['CODLEGISLACAO'];
            $created[(string)$key] = $newId;
            if ($linkedContext) {
                try {
                    LegislacaoEmpresas::vincular($newId, $contextEmpresa, $contextUnidade); // no banco da empresa escolhida
                } catch (Throwable $relationError) {
                    $legislacao->call('CALL sp_crud_delete(:tabela,:chave)', ['tabela' => 'legislacao', 'chave' => $json(['CODLEGISLACAO' => $newId])]);
                    throw $relationError;
                }
            }
        }
        $message = LegislacaoTextos::t(!$created ? 'grid.alteracoes_ok' : ($linkedContext ? 'grid.cadastrada_vinculada' : 'grid.cadastrada_geral'));
        $jsonResponse(true, $message, 200, ['created' => $created]);
    } catch (Throwable $error) {
        $jsonResponse(false, LegislacaoTextos::t('grid.erro_cadastro'), 422);
    }
}
if ($isAjax && ($_POST['action'] ?? '') === 'delete_row' && ctype_digit((string)($_POST['CODLEGISLACAO'] ?? ''))) { if (empty($gridPermission['pode_excluir'])) { http_response_code(403); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'message' => LegislacaoTextos::t('grid.sem_permissao_excluir')], JSON_UNESCAPED_UNICODE); exit; } $legislationId = (int)$_POST['CODLEGISLACAO']; LegislacaoAssociacoes::limparLei($legislationId); LegislacaoEmpresas::desvincularDeTodas($legislationId); $legislacao->call('CALL sp_crud_delete(:tabela,:chave)', ['tabela' => 'legislacaoxassunto', 'chave' => $json(['CODLEGISLACAO' => $legislationId])]); $legislacao->call('CALL sp_crud_delete(:tabela,:chave)', ['tabela' => 'legislacao', 'chave' => $json(['CODLEGISLACAO' => $legislationId])]); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'message' => LegislacaoTextos::t('grid.excluida')], JSON_UNESCAPED_UNICODE); exit; }
$page = max(1, (int)($_GET['pagina'] ?? 1)); $offset = ($page - 1) * 25; $initialFilters = json_decode((string)($_GET['filtros'] ?? '{}'), true); $initialFilters = is_array($initialFilters) ? $initialFilters : []; $initialSearch = trim((string)($_GET['busca'] ?? ''));
$filterParams = $legislationFilterParams($initialFilters, $initialSearch);
$rows = $legislationQuery($filterParams, false, 25, $offset);
$decodeLabel = static fn(string $value): string => html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$C = static fn(string $k): string => LegislacaoTextos::t('grid.col_' . $k);
$columns = ['CODLEGISLACAO' => $C('codigo'), 'CODIGO' => $C('codigo'), 'ASSUNTOS' => $C('assuntos'), 'CODAMBITO' => $C('ambito'), 'CODTIPO' => $C('tipo'), 'NR' => $C('numero'), 'ANO' => $C('ano'), 'ARTIGOS' => $C('artigos'), 'MEIO_AMBIENTE' => $C('meio_ambiente'), 'SEGURANCA' => $C('seguranca'), 'QUALIDADE' => $C('qualidade'), 'RESPOSABILIDADE' => $C('responsabilidade'), 'PADRAO' => $C('padrao'), 'data_atualizacao' => $C('data_atualizacao'), 'situacao_da_lei' => $C('situacao'), 'devido_da_lei' => $C('devido')];
// Grid via AJAX (paginação e filtros): devolve só o corpo da tabela, com o total quando pedido (contar=1), sem montar a página inteira
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $total = isset($_GET['contar']) ? (int)($legislationQuery($filterParams, true)[0]['total_registros'] ?? 0) : null;
    $available = isset($_GET['contar']) ? $availableOptions($initialFilters, $initialSearch) : null;
    // embrulhado num <div>: o grid-pagination.js procura '#legislacaoBatchGrid tbody' DENTRO da resposta (sem o div não achava)
    echo '<div><table id="legislacaoBatchGrid"><tbody' . ($total !== null ? ' data-grid-total="' . $total . '"' : '') . ($available !== null ? ' data-grid-opcoes="' . h($json($available)) . '"' : '') . '>';
    foreach ($rows as $row) {
        $id = (int)$row['CODLEGISLACAO'];
        echo '<tr data-row data-key="' . $id . '">';
        foreach ($columns as $column => $label) {
            echo '<td data-column="' . h($column) . '">' . ($column === 'CODLEGISLACAO' ? '<input class="grid-input" value="' . $id . '" readonly>' : '<input class="grid-input" name="rows[' . $id . '][' . h($column) . ']" value="' . h($row[$column] ?? '') . '">') . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    exit;
}
// Opções dos filtros e combos: só na página inteira
// sp_crud_select devolve no máximo 1.000 linhas por chamada: o catálogo de tipos tem mais (lê em páginas; antes parava em 1.000)
$catalog = static function (string $table) use ($legislacao): array {
    $all = [];
    for ($offset = 0; ; $offset += 1000) {
        $page = $legislacao->call('CALL sp_crud_select(:tabela,:filtros,:busca,1000,' . $offset . ')', ['tabela' => $table, 'filtros' => '{}', 'busca' => '']);
        array_push($all, ...$page);
        if (count($page) < 1000) break;
    }
    return $all;
};
$ambitos = $catalog('ambitos');
$tipos = $catalog('tipos');
usort($tipos, static function (array $left, array $right): int {
    return strnatcasecmp((string)($left['DSCTIPO'] ?? ''), (string)($right['DSCTIPO'] ?? ''));
});
// Migração 384: empresas já migradas (as da tela de login, MODELO incluída) e as unidades de cada uma, do banco dela
$migratedCompanies = LegislacaoEmpresas::migradas();
$periodRows = $legislacao->call('CALL sp_crud_select(:tabela,:filtros,:busca,1000,0)', ['tabela' => 'vw_legislacao_periodos', 'filtros' => '{}', 'busca' => '']);
$periodOptions = [];
foreach ($periodRows as $periodRow) {
    $period = trim((string)($periodRow['periodo'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) continue;
    $periodOptions[$period] = substr($period, 5, 2) . '/' . substr($period, 0, 4);
}
uksort($periodOptions, static fn(string $left, string $right): int => strcmp($right, $left));
$patternValues = []; foreach ($rows as $patternRow) $patternValues[(string)$patternRow['CODLEGISLACAO']] = (string)($patternRow['PADRAO'] ?? '');
$prefRows = $callTenant($tenantPdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,100,0)', ['tabela' => 'vw_preferencias_grid', 'filtros' => $json(['codusuario' => $uid, 'transacao' => 'manutencaolegal_legislacao'])]); $preferences = []; foreach ($prefRows as $preference) $preferences[(string)$preference['coluna']] = $preference;
$ambitoOptions = []; foreach ($ambitos as $item) $ambitoOptions[(string)$item['CODAMBITO']] = (string)($item['DSCAMBITO'] ?? $item['CODAMBITO']);
$tipoOptions = []; foreach ($tipos as $item) $tipoOptions[(string)$item['CODTIPO']] = (string)($item['DSCTIPO'] ?? $item['CODTIPO']);
$companyOptions = []; $companyUnitOptions = []; $unitLabels = []; $unitRclTypes = [];
foreach ($migratedCompanies as $companyId => $company) {
    $companyOptions['|' . $companyId . '|'] = $company['nome'];
    foreach (LegislacaoEmpresas::unidadesRcl($companyId) as $unitId => $unit) { $companyUnitOptions['|' . $companyId . '|']['|' . $unitId . '|'] = $unit['nome']; $unitRclTypes['|' . $companyId . '|']['|' . $unitId . '|'] = $unit['tipo']; }
}
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$J = static fn(string $k, string $pt): string => IdiomaService::t(LegislacaoTextos::GRUPO . '.js.' . $k, LegislacaoTextos::js()[LegislacaoTextos::GRUPO . '.js.' . $k] ?? $pt);
$rclLabels = [$J('rcl_completa', 'RCL Completa'), $J('rcl_padrao', 'RCL Padrão')];
$assocLabels = [$J('titulo_urls', 'URL de pesquisa'), $J('titulo_filhas', 'Legislações filhas'), $J('titulo_alteracoes', 'Alterações na legislação'), $J('titulo_checklist', 'CheckList'), $J('titulo_palavras', 'Palavras-chave')];
$assocButtons = '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-assoc-open="urls" disabled title="' . h($assocLabels[0]) . '" aria-label="' . h($assocLabels[0]) . '"><i class="bi bi-link-45deg"></i></button><button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-assoc-open="filhas" disabled title="' . h($assocLabels[1]) . '" aria-label="' . h($assocLabels[1]) . '"><i class="bi bi-diagram-3"></i></button>'
    . '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-assoc-open="alteracoes" disabled title="' . h($assocLabels[2]) . '" aria-label="' . h($assocLabels[2]) . '"><i class="bi bi-clock-history"></i></button>'
    . '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-assoc-open="checklist" disabled title="' . h($assocLabels[3]) . '" aria-label="' . h($assocLabels[3]) . '"><i class="bi bi-ui-checks"></i></button>'
    . '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-assoc-open="palavras" disabled title="' . h($assocLabels[4]) . '" aria-label="' . h($assocLabels[4]) . '"><i class="bi bi-tags"></i></button>';
$rclButtons = '<button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-rcl-completa title="' . h($rclLabels[0]) . '" aria-label="' . h($rclLabels[0]) . '"><i class="bi bi-file-earmark-text"></i></button><button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-rcl-padrao title="' . h($rclLabels[1]) . '" aria-label="' . h($rclLabels[1]) . '"><i class="bi bi-file-earmark"></i></button>';
?>
<script>window.legislacaoGridOptions = <?php echo $json(['ambitos' => $ambitoOptions, 'tipos' => $tipoOptions, 'empresas' => $companyOptions, 'unidades' => $unitLabels, 'empresaUnidades' => $companyUnitOptions, 'unidadeTipoRcl' => $unitRclTypes, 'periodos' => $periodOptions, 'filtros' => $initialFilters, 'disponiveis' => $availableOptions($initialFilters, $initialSearch)]); ?>; window.legislacaoPatternValues = <?php echo $json($patternValues); ?>; window.greenPortalIdioma = <?php echo $json($idioma); ?>;</script>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css" rel="stylesheet"><link href="../assets/css/main.css?v=15" rel="stylesheet"><link href="../assets/css/design-system.css?v=3" rel="stylesheet"><link href="../assets/css/modulo-legal.css?v=1" rel="stylesheet">
<section class="setup-fragment menu-grid-fragment legislacao-grid-fragment" data-grid-inline-count data-grid-transaction="manutencaolegal_legislacao" data-preferences="<?php echo h($json($preferences)); ?>"><div class="crud-toolbar menu-grid-toolbar"><?php greenPortalCrudButtons(['form_id' => 'legislacaoGridForm', 'language' => $idioma, 'permission' => $gridPermission, 'permission_title' => LegislacaoTextos::t('grid.sem_permissao'), 'transaction' => 'manutencaolegal_legislacao', 'new_attribute' => 'data-new-legislacao', 'duplicate_attribute' => 'data-duplicate-legislacao', 'delete_attribute' => 'data-delete-legislacao', 'extras' => $assocButtons . $rclButtons]); // Barra em grupos: Operações | Associações | RCL | Outros (configuração explícita desta tela)
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'toolbar_grupos.php';
$toolbarConfig = greenPortalToolbarGruposConfig('.legislacao-grid-fragment', ['associacoes' => ['[data-assoc-open]']], false);
$toolbarConfig['grupos'] = array_values(array_filter($toolbarConfig['grupos'], static fn(array $g): bool => $g['id'] !== 'avaliacao'));
$toolbarConfig['grupos'][] = ['id' => 'rcl', 'rotulo' => 'RCL', 'botoes' => ['[data-rcl-completa]', '[data-rcl-padrao]']];
greenPortalToolbarGruposEmitir($toolbarConfig); ?><div class="crud-search"><i class="bi bi-search"></i><input type="search" placeholder="<?php echo h(LegislacaoTextos::t('grid.buscar')); ?>" aria-label="<?php echo h(LegislacaoTextos::t('grid.buscar')); ?>"></div></div><form method="post" id="legislacaoGridForm" class="setup-form menu-grid-form"><input type="hidden" name="action" value="save_rows"><div class="menu-grid-scroll"><table class="table table-sm menu-batch-grid" id="legislacaoBatchGrid"><thead><tr><?php foreach ($columns as $column => $label): ?><th data-column="<?php echo h($column); ?>" style="width:<?php echo (int)($preferences[$column]['largura'] ?? 140); ?>px"><div class="column-heading"><span><?php echo h($label); ?></span><input class="column-filter" type="search" data-column-filter="<?php echo h($column); ?>" placeholder="<?php echo h(LegislacaoTextos::t('grid.buscar_coluna')); ?>" aria-label="<?php echo h(LegislacaoTextos::t('grid.buscar_coluna') . ' ' . $label); ?>"></div></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($rows as $row): $id = (int)$row['CODLEGISLACAO']; ?><tr data-row data-key="<?php echo $id; ?>"><?php foreach ($columns as $column => $label): ?><td data-column="<?php echo h($column); ?>"><?php if ($column === 'CODLEGISLACAO'): ?><input class="grid-input" value="<?php echo $id; ?>" readonly><?php else: ?><input class="grid-input" name="rows[<?php echo $id; ?>][<?php echo h($column); ?>]" value="<?php echo h($row[$column] ?? ''); ?>"><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div></form><nav class="grid-pagination" aria-label="<?php echo h(LegislacaoTextos::t('grid.paginacao')); ?>"><button type="button" class="btn btn-sm btn-outline-secondary grid-page-prev"><i class="bi bi-chevron-left"></i><span><?php echo h(LegislacaoTextos::t('grid.anterior')); ?></span></button><span class="grid-page-status"><?php echo h(LegislacaoTextos::t('grid.calculando')); ?></span><button type="button" class="btn btn-sm btn-outline-secondary grid-page-next"><span><?php echo h(LegislacaoTextos::t('grid.proxima')); ?></span><i class="bi bi-chevron-right"></i></button></nav></section><script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script><script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script><script src="../assets/js/visualizador-pdf.js?v=6"></script><?php echo IdiomaService::script(['comum', LegislacaoTextos::GRUPO], LegislacaoTextos::js()); ?><script src="../assets/js/legislacao-grid.js?v=29"></script><script src="../assets/js/grid-sort.js?v=1"></script><script src="../assets/js/grid-column-filters.js?v=4"></script><script src="../assets/js/grid-column-resize.js?v=1"></script><script src="../assets/js/grid-pagination.js?v=15"></script><script src="../assets/js/grid-loading.js?v=4"></script><script src="../assets/js/grid-ajax.js?v=3"></script>
