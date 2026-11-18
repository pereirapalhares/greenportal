<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/AdministracaoTextos.php';
session_start();
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'UsuarioSessao.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'TransactionSecurity.php';

if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }

$config = [];
foreach (file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    $config[trim($key)] ??= trim(trim($value), "\"'");
}
// Migração 395: a tela abre dentro de Segurança › Perfis e Permissões (permissão própria); o código antigo continua valendo.
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
TransactionSecurity::connect(!empty(PermissaoTransacao::obter('SEGURANCA_PERFIS_PERMISSOES')['pode_visualizar']) ? 'SEGURANCA_PERFIS_PERMISSOES' : 'SEGURANCA_PERMISSOES');

$pdo = new PDO(
    'mysql:host=' . ($config['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($config['GPT_DB_PORT'] ?? '3306') . ';dbname=' . $_SESSION['tenant']['banco_dados'] . ';charset=utf8mb4',
    $config['GPT_DB_USERNAME'] ?? '',
    $config['GPT_DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

if (!UsuarioSessao::atualizar($pdo, $_SESSION) || !in_array((string)($_SESSION['usuario']['codigo_perfil'] ?? ''), ['ADMINISTRADOR', 'ADM_DA_EMPRESA'], true)) {
    http_response_code(403); exit(adm_t('Acesso negado.'));
}

function permissaoCall(PDO $pdo, string $procedure, array $params = []): array {
    if (str_contains($procedure, 'sp_crud_select') && str_contains($procedure, 'NULL,25')) {
        $requestFilters = json_decode((string)($_GET['filtros'] ?? '{}'), true);
        $requestFilters = is_array($requestFilters) ? $requestFilters : [];
        $baseFilters = isset($params['filtros']) ? json_decode((string)$params['filtros'], true) : [];
        $baseFilters = is_array($baseFilters) ? $baseFilters : [];
        if (isset($params['filtros'])) $params['filtros'] = permissaoJson(array_merge($baseFilters, $requestFilters));
        $procedure = str_replace('NULL,25', ':busca,25', $procedure);
        $params['busca'] = trim((string)($_GET['busca'] ?? ''));
    }
    $statement = $pdo->prepare($procedure);
    $statement->execute($params);
    $result = $statement->fetchAll();
    $statement->closeCursor();
    return $result;
}
function permissaoCallLimit(PDO $pdo, string $tabela, string $filtros, string $busca, int $limit = 1000): array {
    $statement = $pdo->prepare('CALL sp_crud_select(:tabela,:filtros,:busca,' . max(1, $limit) . ',0)');
    $statement->execute(['tabela' => $tabela, 'filtros' => $filtros, 'busca' => $busca]);
    $result = $statement->fetchAll();
    $statement->closeCursor();
    return $result;
}
function permissaoFiltroInteiro(mixed $value): int {
    if (is_array($value)) $value = $value['value'] ?? $value['valor'] ?? 0;
    return max(0, (int)$value);
}
function permissaoRamoMenu(PDO $pdo, int $menuId): array {
    if ($menuId <= 0) return [];
    $menus = permissaoCallLimit($pdo, 'vw_menus_grid', '{}', '', 1000);   // inclui as transações fora do menu (inativas)
    $children = [];
    foreach ($menus as $menu) $children[(int)($menu['codmenu_pai'] ?? 0)][] = (int)$menu['codmenu'];
    $ids = [$menuId];
    $visitados = [$menuId => true];
    $fila = [$menuId];
    while ($fila) {
        $pai = array_shift($fila);
        foreach ($children[$pai] ?? [] as $filho) {
            if (isset($visitados[$filho])) continue;
            $visitados[$filho] = true;
            $ids[] = $filho;
            $fila[] = $filho;
        }
    }
    return $ids;
}
/** 12/11/2026 (pedido do usuário): os menus dos ramos Administração, Segurança e Configurações (Setup) só aparecem e só são
 *  alterados pelo ADMINISTRADOR. */
function permissaoMenusSoAdministrador(PDO $pdo): array {
    $raizes = [];
    foreach (permissaoCallLimit($pdo, 'vw_menus_grid', '{}', '', 1000) as $menu) {
        $codigo = strtoupper(trim((string)($menu['codigo'] ?? '')));
        $rotulo = strtoupper(trim((string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)($menu['label_pt'] ?? $menu['label'] ?? ''))));
        if (in_array($codigo, ['ADMINISTRACAO', 'SEGURANCA', 'SETUP', 'CONFIGURACOES'], true)
            || ((int)($menu['codmenu_pai'] ?? 0) === 0 && in_array($rotulo, ['ADMINISTRACAO', 'SEGURANCA', 'CONFIGURACOES', 'SETUP'], true))) $raizes[] = (int)$menu['codmenu'];
    }
    $ids = [];
    foreach ($raizes as $r) foreach (permissaoRamoMenu($pdo, $r) as $id) $ids[$id] = $id;
    return array_values($ids);
}
function permissaoSemMenusAdm(array $rows, array $menuIds): array {
    if (!$menuIds) return $rows;
    $negados = array_fill_keys($menuIds, true);
    return array_values(array_filter($rows, static fn(array $row): bool => !isset($negados[(int)($row['codmenu'] ?? 0)])));
}
function permissaoFiltrarRamo(array $rows, array $menuIds): array {
    if (!$menuIds) return $rows;
    $allowed = array_fill_keys($menuIds, true);
    return array_values(array_filter($rows, static fn(array $row): bool => isset($allowed[(int)($row['codmenu'] ?? 0)])));
}
function permissaoJson(mixed $value): string {
    if (is_array($value) && array_key_exists('codperfil', $value) && array_key_exists('codmenu', $value)) {
        foreach ((array)($_POST['rows'] ?? []) as $postedRow) {
            if ((int)($postedRow['codperfil'] ?? 0) !== (int)$value['codperfil'] || (int)($postedRow['codmenu'] ?? 0) !== (int)$value['codmenu']) continue;
            $value['pode_importar_arquivos'] = !empty($postedRow['pode_importar_arquivos']) ? 1 : 0;
            $value['pode_visualizar_arquivos'] = !empty($postedRow['pode_visualizar_arquivos']) ? 1 : 0;
            $value['pode_baixar_arquivos'] = !empty($postedRow['pode_baixar_arquivos']) ? 1 : 0;
            break;
        }
        $value['pode_importar_arquivos'] ??= 0;
        $value['pode_visualizar_arquivos'] ??= 0;
        $value['pode_baixar_arquivos'] ??= 0;
    }
    if (is_array($value) && (($value['status'] ?? '') === 'SOLICITADA')) { $value['filtros'] = json_encode((array)json_decode((string)($_POST['filtros'] ?? '{}'), true), JSON_UNESCAPED_UNICODE); $value['busca'] = trim((string)($_POST['busca'] ?? '')); $value['colunas'] = trim((string)($_POST['colunas'] ?? '[]')) ?: '[]'; }
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$userId = (int)$_SESSION['usuario']['codusuario'];
$loggedProfileCode = (string)($_SESSION['usuario']['codigo_perfil'] ?? '');
$isAdministrator = $loggedProfileCode === 'ADMINISTRADOR';
$menusSoAdministrador = $isAdministrator ? [] : permissaoMenusSoAdministrador($pdo);
$administratorProfileId = (int)(permissaoCall($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'vw_perfis_grid', 'filtros' => permissaoJson(['codigo' => 'ADMINISTRADOR'])])[0]['codperfil'] ?? 0);
$selectedProfileId = max(0, (int)($_GET['perfil'] ?? 0));
$activeProfileIds = array_map('intval', array_column(permissaoCall($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela' => 'vw_perfis_grid', 'filtros' => permissaoJson(['ativo' => 1])]), 'codperfil'));
// 12/11/2026 (pedido do usuário): os perfis de ADM da empresa (ADM_DA_EMPRESA / ADM DA EMPRESA / ADM EMPRESA) só têm os acessos
// alterados pelo ADMINISTRADOR. O ADM da empresa vê esses perfis aqui, só para consulta (salvar, incluir e excluir negados no servidor).
$admEmpresaProfileIds = [];
foreach (permissaoCall($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela' => 'vw_perfis_grid', 'filtros' => '{}']) as $perfilAdm) {
    $codigoAdm = preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', strtoupper(trim((string)($perfilAdm['codigo'] ?? '')))));
    if (in_array($codigoAdm, ['ADM DA EMPRESA', 'ADM EMPRESA'], true)) $admEmpresaProfileIds[] = (int)$perfilAdm['codperfil'];
}
function permissaoPerfilPermitido(int $profileId): bool {
    global $selectedProfileId, $isAdministrator, $administratorProfileId, $activeProfileIds, $admEmpresaProfileIds;
    return $selectedProfileId > 0 && $profileId === $selectedProfileId && in_array($profileId, $activeProfileIds, true) && ($isAdministrator || ($profileId !== $administratorProfileId && !in_array($profileId, $admEmpresaProfileIds, true)));
}
$perfilSomenteConsulta = !$isAdministrator && $selectedProfileId > 0 && in_array($selectedProfileId, $admEmpresaProfileIds, true);
$message = null;
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' || ($_POST['ajax'] ?? '') === '1';
// 12/11/2026 (pedido do usuário): fora o ADMINISTRADOR, a tela só altera permissões existentes; incluir, duplicar e excluir não.
if (!$isAdministrator && ($_POST['action'] ?? '') === 'delete_row') { http_response_code(403); exit(adm_t('Somente o ADMINISTRADOR pode excluir permissões.')); }
if (!$isAdministrator && ($_POST['action'] ?? '') === 'save_rows') {
    foreach ((array)($_POST['rows'] ?? []) as $chaveLinha => $linhaPostada) {
        if (!ctype_digit((string)$chaveLinha)) { http_response_code(403); exit(adm_t('Somente o ADMINISTRADOR pode incluir permissões.')); }
        $atual = null;
        foreach (permissaoCall($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,5,0)', ['tabela' => 'vw_permissoes_grid', 'filtros' => json_encode(['codpermissao' => ['value' => (string)(int)$chaveLinha, 'exact' => true]])]) as $r) if ((int)$r['codpermissao'] === (int)$chaveLinha) $atual = $r;
        // alterar = só as marcações; trocar o perfil ou o menu de uma linha equivaleria a incluir
        if (!$atual || (int)$atual['codperfil'] !== (int)($linhaPostada['codperfil'] ?? 0) || (int)$atual['codmenu'] !== (int)($linhaPostada['codmenu'] ?? 0)) { http_response_code(403); exit(adm_t('Somente o ADMINISTRADOR pode incluir permissões ou trocar o perfil/menu de uma linha.')); }
    }
}
if (($_POST['action'] ?? '') === 'save_rows') {
    foreach ((array)($_POST['rows'] ?? []) as $postedRow) {
        $postedProfileId = (int)($postedRow['codperfil'] ?? 0);
        if ($postedProfileId > 0 && !permissaoPerfilPermitido($postedProfileId)) { http_response_code(403); exit(adm_t('Perfil não autorizado para manutenção.')); }
        if (in_array((int)($postedRow['codmenu'] ?? 0), $menusSoAdministrador, true)) { http_response_code(403); exit(adm_t('Os acessos dos menus de Administração, Segurança e Configurações só podem ser alterados pelo ADMINISTRADOR.')); }
    }
}
if (($_POST['action'] ?? '') === 'delete_row' && ctype_digit((string)($_POST['codpermissao'] ?? ''))) {
    $linhaExcluir = permissaoCall($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'vw_permissoes_grid', 'filtros' => permissaoJson(['codpermissao' => (int)$_POST['codpermissao']])])[0] ?? [];
    $postedProfileId = (int)($linhaExcluir['codperfil'] ?? 0);
    if (!permissaoPerfilPermitido($postedProfileId)) { http_response_code(403); exit(adm_t('Perfil não autorizado para manutenção.')); }
    if (in_array((int)($linhaExcluir['codmenu'] ?? 0), $menusSoAdministrador, true)) { http_response_code(403); exit(adm_t('Os acessos dos menus de Administração, Segurança e Configurações só podem ser alterados pelo ADMINISTRADOR.')); }
}
if ($isAjax && ($_POST['action'] ?? '') === 'count_rows') {
    $f = json_decode((string)($_POST['filtros'] ?? '{}'), true);
    $f = is_array($f) ? $f : [];
    if ($selectedProfileId > 0) $f['codperfil'] = $selectedProfileId;
    $canCount = $selectedProfileId > 0 && in_array($selectedProfileId, $activeProfileIds, true) && ($isAdministrator || $selectedProfileId !== $administratorProfileId);
    $menuBranchIds = permissaoFiltroInteiro($f['codmenu'] ?? 0) > 0 ? permissaoRamoMenu($pdo, permissaoFiltroInteiro($f['codmenu'])) : [];
    unset($f['codmenu']);
    if ($canCount && ($menuBranchIds || $menusSoAdministrador)) {
        $countRows = permissaoCallLimit($pdo, 'vw_permissoes_grid', permissaoJson($f), trim((string)($_POST['busca'] ?? '')));
        $total = count(permissaoSemMenusAdm(permissaoFiltrarRamo($countRows, $menuBranchIds), $menusSoAdministrador));
    } else {
        $c = $canCount ? permissaoCall($pdo, 'CALL sp_crud_count(:tabela,:filtros,:busca)', ['tabela' => 'vw_permissoes_grid', 'filtros' => permissaoJson($f), 'busca' => trim((string)($_POST['busca'] ?? ''))]) : [];
        $total = (int)($c[0]['total_registros'] ?? 0);
    }
    header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'total' => $total]); exit;
}
if ($isAjax && ($_POST['action'] ?? '') === 'save_rows') { $created = []; foreach ((array)($_POST['rows'] ?? []) as $key => $row) { $data = ['codperfil' => max(0, (int)($row['codperfil'] ?? 0)), 'codmenu' => max(0, (int)($row['codmenu'] ?? 0)), 'pode_incluir' => !empty($row['pode_incluir']) ? 1 : 0, 'pode_visualizar' => !empty($row['pode_visualizar']) ? 1 : 0, 'pode_visualizar_total' => !empty($row['pode_visualizar_total']) ? 1 : 0, 'pode_editar' => !empty($row['pode_editar']) ? 1 : 0, 'pode_excluir' => !empty($row['pode_excluir']) ? 1 : 0, 'pode_exportar_excel' => !empty($row['pode_exportar_excel']) ? 1 : 0, 'pode_gerar_pdf' => !empty($row['pode_gerar_pdf']) ? 1 : 0]; if (!$data['codperfil'] || !$data['codmenu']) continue; if (ctype_digit((string)$key)) permissaoCall($pdo, 'CALL sp_crud_update(:tabela,:chave,:dados)', ['tabela' => 'permissoes', 'chave' => permissaoJson(['codpermissao' => (int)$key]), 'dados' => permissaoJson($data)]); else { permissaoCall($pdo, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'permissoes', 'dados' => permissaoJson($data)]); $found = permissaoCall($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'vw_permissoes_grid', 'filtros' => permissaoJson(['codperfil' => $data['codperfil'], 'codmenu' => $data['codmenu']])]); if ($found) $created[(string)$key] = (int)$found[0]['codpermissao']; } } header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'message' => adm_t('Permissões salvas com sucesso.'), 'created' => $created], JSON_UNESCAPED_UNICODE); exit; }
if ($isAjax && ($_POST['action'] ?? '') === 'delete_row' && ctype_digit((string)($_POST['codpermissao'] ?? ''))) { permissaoCall($pdo, 'CALL sp_crud_delete(:tabela,:chave)', ['tabela' => 'permissoes', 'chave' => permissaoJson(['codpermissao' => (int)$_POST['codpermissao']])]); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'message' => adm_t('Permissão excluída com sucesso.')], JSON_UNESCAPED_UNICODE); exit; }
$duplicateProfileId = 0;
$duplicateMenuId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_preferences') {
    header('Content-Type: application/json; charset=utf-8');
    $items = json_decode((string)($_POST['preferences'] ?? '[]'), true);
    foreach (is_array($items) ? $items : [] as $item) {
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($item['coluna'] ?? ''));
        if (!$column) continue;
        $old = permissaoCall($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', [
            'tabela' => 'vw_preferencias_grid',
            'filtros' => permissaoJson(['codusuario' => $userId, 'transacao' => 'seguranca_permissoes', 'coluna' => $column])
        ]);
        $data = [
            'codusuario' => $userId, 'transacao' => 'seguranca_permissoes', 'coluna' => $column,
            'ordem' => (int)($item['ordem'] ?? 0), 'visivel' => (int)!empty($item['visivel']),
            'largura' => max(60, (int)($item['largura'] ?? 120))
        ];
        if ($old) permissaoCall($pdo, 'CALL sp_crud_update(:tabela,:chave,:dados)', ['tabela' => 'preferencias_grid', 'chave' => permissaoJson(['codpreferenciagrid' => $old[0]['codpreferenciagrid']]), 'dados' => permissaoJson($data)]);
        else permissaoCall($pdo, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'preferencias_grid', 'dados' => permissaoJson($data)]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export_request') {
    // Pedido na fila do GreenPortal-ExportWorker (Agendador do Windows) + alerta "Exportação iniciada".
    require_once dirname(__DIR__).DIRECTORY_SEPARATOR.'classes'.DIRECTORY_SEPARATOR.'ExportacaoSolicitacao.php';
    ExportacaoSolicitacao::solicitar($pdo, (int)$userId, 'seguranca_permissoes', adm_t('Permissões'));
    $message = ExportacaoSolicitacao::mensagem();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_rows') {
    $duplicate = false;
    foreach ((array)($_POST['rows'] ?? []) as $key => $row) {
        $data = [
            'codperfil' => max(0, (int)($row['codperfil'] ?? 0)),
            'codmenu' => max(0, (int)($row['codmenu'] ?? 0)),
            'pode_incluir' => !empty($row['pode_incluir']) ? 1 : 0,
            'pode_visualizar' => !empty($row['pode_visualizar']) ? 1 : 0,
            'pode_visualizar_total' => !empty($row['pode_visualizar_total']) ? 1 : 0,
            'pode_editar' => !empty($row['pode_editar']) ? 1 : 0,
            'pode_excluir' => !empty($row['pode_excluir']) ? 1 : 0,
            'pode_exportar_excel' => !empty($row['pode_exportar_excel']) ? 1 : 0,
            'pode_gerar_pdf' => !empty($row['pode_gerar_pdf']) ? 1 : 0,
            'pode_importar_arquivos' => !empty($row['pode_importar_arquivos']) ? 1 : 0,
            'pode_visualizar_arquivos' => !empty($row['pode_visualizar_arquivos']) ? 1 : 0,
            'pode_baixar_arquivos' => !empty($row['pode_baixar_arquivos']) ? 1 : 0,
        ];
        if (!$data['codperfil'] || !$data['codmenu']) continue;
        try {
            if (ctype_digit((string)$key)) permissaoCall($pdo, 'CALL sp_crud_update(:tabela,:chave,:dados)', ['tabela' => 'permissoes', 'chave' => permissaoJson(['codpermissao' => (int)$key]), 'dados' => permissaoJson($data)]);
            else permissaoCall($pdo, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'permissoes', 'dados' => permissaoJson($data)]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000' && str_contains($exception->getMessage(), 'uk_permissoes_perfil_menu')) { $duplicate = true; $duplicateProfileId = $data['codperfil']; $duplicateMenuId = $data['codmenu']; continue; }
            throw $exception;
        }
    }
    $message = $duplicate ? adm_t('Já existe uma permissão para este perfil e transação.') : adm_t('Permissões salvas com sucesso.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_row' && ctype_digit((string)($_POST['codpermissao'] ?? ''))) {
    permissaoCall($pdo, 'CALL sp_crud_delete(:tabela,:chave)', ['tabela' => 'permissoes', 'chave' => permissaoJson(['codpermissao' => (int)$_POST['codpermissao']])]);
    $message = adm_t('Permissão excluída com sucesso.');
}

$profiles = permissaoCall($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela' => 'vw_perfis_grid', 'filtros' => permissaoJson(['ativo' => 1])]);
$profiles = array_values(array_filter($profiles, static function (array $profile) use ($isAdministrator): bool {
    return $isAdministrator || (string)($profile['codigo'] ?? '') !== 'ADMINISTRADOR';
}));
if ($selectedProfileId > 0 && !in_array($selectedProfileId, array_map(static fn(array $profile): int => (int)$profile['codperfil'], $profiles), true)) {
    $selectedProfileId = 0;
    $message = adm_t('Selecione um perfil autorizado para manutenção.');
}
// Todas as transações com tela, inclusive as que ficam fora do menu (inativas no menu) e só abrem por botão ou alerta,
// como o resultado da análise por IA (MODULOLEGAL_POTENCIAIS) e a Gestão de Arquivos: elas também precisam de permissão.
$menus = permissaoCall($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela' => 'vw_menus_grid', 'filtros' => '{}']);
$menuCatalog = $menus;
$menus = array_values(array_filter($menus, static function (array $menu): bool {
    return trim((string)($menu['url'] ?? '')) !== '' && strtoupper((string)($menu['codigo'] ?? '')) !== 'SETUP_LOGIN';
}));
// Ramos Administração, Segurança e Configurações: fora da matriz (não cria linhas) para quem não é ADMINISTRADOR
if ($menusSoAdministrador) $menus = array_values(array_filter($menus, static fn(array $menu): bool => !in_array((int)$menu['codmenu'], $menusSoAdministrador, true)));
// Só o perfil selecionado entra na matriz: basta saber as transações que ele já tem
$existingPermissions = [];
for ($offset = 0; $selectedProfileId > 0; $offset += 1000) {
    $lote = permissaoCall($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,' . $offset . ')', ['tabela' => 'vw_permissoes_grid', 'filtros' => permissaoJson(['codperfil' => $selectedProfileId])]);
    array_push($existingPermissions, ...$lote);
    if (count($lote) < 1000) break;
}
$permissionKeys = [];
foreach ($existingPermissions as $existingPermission) $permissionKeys[(int)$existingPermission['codperfil'] . ':' . (int)$existingPermission['codmenu']] = true;
$matrixProfiles = $selectedProfileId > 0 && !$perfilSomenteConsulta ? array_values(array_filter($profiles, static fn(array $profile): bool => (int)$profile['codperfil'] === $selectedProfileId)) : []; // perfil só de consulta: não cria as linhas que faltam
foreach ($matrixProfiles as $profile) {
    foreach ($menus as $menu) {
        $profileId = (int)$profile['codperfil'];
        $menuId = (int)$menu['codmenu'];
        $permissionKey = $profileId . ':' . $menuId;
        if (isset($permissionKeys[$permissionKey])) continue;
        $fullAccess = (string)($profile['codigo'] ?? '') === 'ADMINISTRADOR' ? 1 : 0;
        try {
            permissaoCall($pdo, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'permissoes', 'dados' => permissaoJson(['codperfil' => $profileId, 'codmenu' => $menuId, 'pode_incluir' => $fullAccess, 'pode_visualizar' => 1 * ($fullAccess || $menu['codigo'] === 'DASHBOARD'), 'pode_visualizar_total' => $fullAccess, 'pode_editar' => $fullAccess, 'pode_excluir' => $fullAccess, 'pode_exportar_excel' => $fullAccess, 'pode_gerar_pdf' => $fullAccess, 'pode_baixar_arquivos' => $fullAccess])]);
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '23000' || !str_contains($exception->getMessage(), 'uk_permissoes_perfil_menu')) throw $exception;
        }
        $permissionKeys[$permissionKey] = true;
    }
}
$gridPage = max(1, (int)($_GET['pagina'] ?? 1)); $gridSize = 25; $gridOffset = ($gridPage - 1) * $gridSize;
$permissionFilters = $selectedProfileId > 0 ? ['codperfil' => $selectedProfileId] : [];
$requestPermissionFilters = json_decode((string)($_GET['filtros'] ?? '{}'), true);
$requestPermissionFilters = is_array($requestPermissionFilters) ? $requestPermissionFilters : [];
$menuBranchIds = permissaoFiltroInteiro($requestPermissionFilters['codmenu'] ?? 0) > 0 ? permissaoRamoMenu($pdo, permissaoFiltroInteiro($requestPermissionFilters['codmenu'])) : [];
unset($requestPermissionFilters['codmenu']);
$allPermissionRows = $selectedProfileId > 0 ? permissaoCallLimit($pdo, 'vw_permissoes_grid', permissaoJson(array_merge($requestPermissionFilters, $permissionFilters)), trim((string)($_GET['busca'] ?? ''))) : [];
$allPermissionRows = permissaoSemMenusAdm(permissaoFiltrarRamo($allPermissionRows, $menuBranchIds), $menusSoAdministrador);
$rows = $allPermissionRows;
$profileLabels = [];
foreach ($profiles as $profile) $profileLabels[(int)$profile['codperfil']] = trim((string)($profile['nome'] ?? '')) !== '' ? (string)$profile['nome'] : (string)$profile['codigo'];
$menuLabels = [];
foreach ($menus as $menu) $menuLabels[(int)$menu['codmenu']] = (string)$menu['codigo'];
$menuMeta = [];
foreach ($menuCatalog as $menu) {
    $menuId = (int)$menu['codmenu'];
    $menuMeta[$menuId] = [
        'parent' => (int)($menu['codmenu_pai'] ?? 0),
        'label' => (string)($menu['label_pt'] ?? $menu['label'] ?? $menu['codigo']) . (array_key_exists('ativo', $menu) && (int)$menu['ativo'] !== 1 ? adm_t(' (fora do menu)') : ''),
        'codigo' => (string)($menu['codigo'] ?? ''),
        'nivel' => (int)($menu['nivel'] ?? 0),
        'ordem' => (int)($menu['ordem'] ?? 0),
    ];
}
$menusByParent = [];
foreach ($menuMeta as $menuId => $meta) $menusByParent[$meta['parent']][] = $menuId;
$menuPath = static function (int $menuId) use (&$menuMeta): string {
    $parts = [];
    $visited = [];
    while ($menuId > 0 && isset($menuMeta[$menuId]) && !isset($visited[$menuId])) {
        $visited[$menuId] = true;
        $parts[] = $menuMeta[$menuId]['label'];
        $menuId = (int)$menuMeta[$menuId]['parent'];
    }
    return implode(' / ', array_reverse($parts));
};
foreach ($menusByParent as &$children) {
    usort($children, static fn(int $left, int $right): int => ($menuMeta[$left]['ordem'] <=> $menuMeta[$right]['ordem']) ?: ($left <=> $right));
}
unset($children);
$orderedMenuIds = [];
$menuDepth = [];
$appendMenuBranch = static function (int $parentId, int $level = 0) use (&$appendMenuBranch, &$orderedMenuIds, &$menuDepth, $menusByParent, $menuMeta): void {
    foreach ($menusByParent[$parentId] ?? [] as $menuId) {
        $menuDepth[$menuId] = $level;
        $orderedMenuIds[] = $menuId;
        $appendMenuBranch($menuId, $level + 1);
    }
};
$appendMenuBranch(0);
foreach ($menuMeta as $menuId => $meta) {
    if (isset($menuDepth[$menuId])) continue;
    $menuDepth[$menuId] = max(0, (int)($meta['nivel'] ?? 1) - 1);
    $orderedMenuIds[] = $menuId;
}
$menuOrder = array_flip($orderedMenuIds);
$orderedMenus = [];
foreach ($orderedMenuIds as $menuId) {
    foreach ($menuCatalog as $menu) if ((int)$menu['codmenu'] === $menuId && trim((string)($menu['url'] ?? '')) !== '' && strtoupper((string)($menu['codigo'] ?? '')) !== 'SETUP_LOGIN') { $orderedMenus[] = $menu; break; }
}
$menus = $orderedMenus;
$filterMenuOptions = array_values(array_filter($orderedMenuIds, static fn(int $menuId): bool => isset($menuMeta[$menuId]) && strtoupper((string)$menuMeta[$menuId]['codigo']) !== 'SETUP_LOGIN' && !in_array($menuId, $menusSoAdministrador, true)));
$visibleMenuIds = array_fill_keys(array_map(static fn(array $menu): int => (int)$menu['codmenu'], $menus), true);
$rows = array_values(array_filter($rows, static fn(array $row): bool => isset($visibleMenuIds[(int)($row['codmenu'] ?? 0)])));
usort($rows, static function (array $left, array $right) use ($menuOrder): int {
    $leftOrder = $menuOrder[(int)($left['codmenu'] ?? 0)] ?? PHP_INT_MAX;
    $rightOrder = $menuOrder[(int)($right['codmenu'] ?? 0)] ?? PHP_INT_MAX;
    return $leftOrder <=> $rightOrder;
});
$rows = array_slice($rows, $gridOffset, $gridSize);
if ($duplicateProfileId && $duplicateMenuId) {
    $message = adm_t('Já existe uma permissão para o perfil ') . ($profileLabels[$duplicateProfileId] ?? (string)$duplicateProfileId) . adm_t(' na transação ') . ($menuLabels[$duplicateMenuId] ?? (string)$duplicateMenuId) . '.';
}
$preferenceRows = permissaoCall($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,100,0)', ['tabela' => 'vw_preferencias_grid', 'filtros' => permissaoJson(['codusuario' => $userId, 'transacao' => 'seguranca_permissoes'])]);
$preferences = [];
foreach ($preferenceRows as $preference) $preferences[(string)$preference['coluna']] = $preference;
$columns = ['codpermissao' => adm_t('Código'), 'codperfil' => adm_t('Perfil'), 'perfil_codigo' => adm_t('Código perfil'), 'codmenu' => 'Menu', 'pode_visualizar' => adm_t('Visualizar Parcial'), 'pode_visualizar_total' => adm_t('Visualizar Total'), 'pode_incluir' => adm_t('Incluir'), 'pode_editar' => adm_t('Editar'), 'pode_excluir' => adm_t('Excluir'), 'pode_exportar_excel' => adm_t('Exportar Excel'), 'pode_gerar_pdf' => adm_t('Gerar PDF'), 'pode_importar_arquivos' => adm_t('Importar Arquivos'), 'pode_visualizar_arquivos' => adm_t('Visualizar Arquivos'), 'pode_baixar_arquivos' => adm_t('Baixar/Imprimir Arquivos')];
?>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css" rel="stylesheet"><link href="../assets/css/main.css?v=18" rel="stylesheet"><link href="../assets/css/design-system.css?v=3" rel="stylesheet"><link href="../assets/css/botoes-crud.css?v=4" rel="stylesheet"><style>.permission-grid-fragment .crud-toolbar .permission-profile-picker{display:inline-flex;align-items:center;gap:6px;margin:0 -13px 0 auto;font-size:12px;color:var(--gp-muted,#6b8279)}.permission-profile-picker select{min-width:200px;font-size:12px}.permission-profile-picker select.is-empty{border-color:var(--gp-primary,#2f7d5b);box-shadow:0 0 0 3px rgba(47,125,91,.15)}#permissionBatchGrid .permission-check-all{margin:0 5px 0 0;vertical-align:-2px;cursor:pointer}</style>
<section class="setup-fragment menu-grid-fragment permission-grid-fragment" data-selected-profile="<?php echo $selectedProfileId; ?>" data-preferences="<?php echo htmlspecialchars(permissaoJson($preferences), ENT_QUOTES, 'UTF-8'); ?>">
<div class="crud-toolbar menu-grid-toolbar"><div class="crud-actions crud-actions-standard"><button data-crud-action="save" class="btn grid-crud-button btn-sm btn-success" type="submit" form="permissionGridForm" data-testid="save-permissions"><i class="bi bi-check2 me-1"></i><?= adm_h('Salvar') ?></button><button data-crud-action="new" class="btn grid-crud-button btn-sm btn-outline-success" type="button" data-new-permission><i class="bi bi-plus-lg me-1"></i><?= adm_h('Novo') ?></button><button data-crud-action="duplicate" class="btn grid-crud-button btn-sm btn-outline-primary" type="button" data-crud-duplicate><i class="bi bi-copy me-1"></i><?= adm_h('Duplicar') ?></button><button data-crud-action="delete" class="btn grid-crud-button btn-sm btn-outline-danger" type="button" data-delete-permission><i class="bi bi-trash me-1"></i><?= adm_h('Excluir') ?></button><button data-crud-action="reload" class="btn grid-crud-button btn-sm btn-outline-secondary" type="button" data-reload-grid><i class="bi bi-arrow-clockwise me-1"></i><?= adm_h('Recarregar') ?></button><button data-crud-action="export" class="btn grid-crud-button btn-sm btn-outline-success" type="submit" name="action" value="export_request" form="permissionExportForm"><i class="bi bi-file-earmark-excel me-1"></i><?= adm_h('Exportar Excel') ?></button><button data-crud-action="columns" class="btn grid-crud-button btn-sm btn-outline-secondary" type="button" data-columns><i class="bi bi-layout-three-columns me-1"></i><?= adm_h('Colunas') ?></button><?php $gpAjuda = class_exists('IdiomaService') ? IdiomaService::t('comum.botao.ajuda', ['pt' => 'Ajuda', 'en' => 'Help', 'es' => 'Ayuda']) : 'Ajuda'; ?><button class="btn btn-sm btn-outline-info grid-crud-button" type="button" data-open-transacao-ajuda data-help-transaction="seguranca_permissoes" title="<?php echo htmlspecialchars($gpAjuda, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($gpAjuda, ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-question-circle"></i></button><?= AdministracaoTextos::script() ?><script src="../assets/js/ajuda-transacao.js?v=1"></script></div><label class="permission-profile-picker" for="permissionProfilePicker"><span><?= adm_h('Perfil') ?></span><select class="form-select form-select-sm" id="permissionProfilePicker" aria-label="<?= adm_a('Perfil') ?>"><option value=""<?php echo $selectedProfileId > 0 ? '' : ' selected'; ?>><?= adm_h('Selecione o perfil') ?></option><?php foreach ($profiles as $profile): ?><option value="<?php echo (int)$profile['codperfil']; ?>" <?php echo (int)$profile['codperfil'] === $selectedProfileId ? 'selected' : ''; ?>><?php echo htmlspecialchars(trim((string)($profile['nome'] ?? '')) !== '' ? (string)$profile['nome'] : (string)$profile['codigo'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></label><div class="crud-search"><i class="bi bi-search"></i><input type="search" id="permissionSearch" placeholder="<?= adm_a('Buscar no grid') ?>" aria-label="<?= adm_a('Buscar no grid') ?>"></div></div>
<?php if ($message): ?><div class="alert alert-success py-2 mb-2" role="alert"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?><?php if ($perfilSomenteConsulta): ?><div class="alert alert-warning py-2 mb-2" role="alert" data-perfil-somente-consulta><i class="bi bi-lock me-1"></i><?= adm_h('Os acessos do perfil ADM da empresa só podem ser alterados pelo ADMINISTRADOR. Aqui você pode apenas consultar.') ?></div><?php endif; ?>
<form method="post" id="permissionGridForm" class="setup-form menu-grid-form"><input type="hidden" name="action" value="save_rows"><div class="menu-grid-scroll"><table class="table table-sm menu-batch-grid" id="permissionBatchGrid"><thead><tr><?php foreach ($columns as $key => $label): ?><th data-column="<?php echo $key; ?>" style="width:<?php echo (int)($preferences[$key]['largura'] ?? 140); ?>px"><div class="column-heading"><span><?php echo $label; ?></span><input class="column-filter" type="search" data-column-filter="<?php echo $key; ?>" placeholder="<?= adm_a('Buscar') ?>" aria-label="Buscar <?php echo $label; ?>"></div></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($rows as $row): $id = (int)$row['codpermissao']; $rowMenuId = (int)$row['codmenu']; $rowMenuLabel = $menuPath($rowMenuId) ?: (string)($menuMeta[$rowMenuId]['label'] ?? $row['menu_label'] ?? $row['menu_codigo'] ?? ''); ?><tr data-row data-key="<?php echo $id; ?>"><td data-column="codpermissao"><input class="grid-input" value="<?php echo $id; ?>" readonly></td><td data-column="codperfil"><select class="grid-input" name="rows[<?php echo $id; ?>][codperfil]"><option value=""><?= adm_h('Selecione') ?></option><?php foreach ($profiles as $profile): ?><option value="<?php echo (int)$profile['codperfil']; ?>" <?php echo (int)$profile['codperfil'] === (int)$row['codperfil'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(trim((string)($profile['nome'] ?? '')) !== '' ? (string)$profile['nome'] : (string)$profile['codigo'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></td><td data-column="perfil_codigo"><?php echo htmlspecialchars((string)$row['perfil_codigo'], ENT_QUOTES, 'UTF-8'); ?></td><td data-column="codmenu"><span class="permission-menu-label"><?php echo htmlspecialchars($rowMenuLabel, ENT_QUOTES, 'UTF-8'); ?></span><input type="hidden" name="rows[<?php echo $id; ?>][codmenu]" value="<?php echo $rowMenuId; ?>"></td><?php foreach (['pode_incluir', 'pode_visualizar', 'pode_visualizar_total', 'pode_editar', 'pode_excluir', 'pode_exportar_excel', 'pode_gerar_pdf', 'pode_importar_arquivos', 'pode_visualizar_arquivos', 'pode_baixar_arquivos'] as $field): ?><td data-column="<?php echo $field; ?>" class="text-center"><input type="checkbox" name="rows[<?php echo $id; ?>][<?php echo $field; ?>]" value="1" <?php echo !empty($row[$field]) ? 'checked' : ''; ?>></td><?php endforeach; ?></tr><?php endforeach; ?>
<?php if ($selectedProfileId <= 0): ?><tr class="permission-empty-state"><td colspan="<?php echo count($columns); ?>" class="text-center text-muted py-4"><i class="bi bi-person-gear me-1"></i><?= adm_h('Selecione o perfil ao lado da busca para carregar as permissões.') ?></td></tr><?php endif; ?></tbody></table></div></form><nav class="grid-pagination" aria-label="<?= adm_a('Paginação do grid') ?>"><button type="button" class="btn btn-sm btn-outline-secondary grid-page-prev"><i class="bi bi-chevron-left"></i><span><?= adm_h('Anterior') ?></span></button><span class="grid-page-status"><?= adm_h('Calculando total...') ?></span><button type="button" class="btn btn-sm btn-outline-secondary grid-page-next"><span><?= adm_h('Próxima') ?></span><i class="bi bi-chevron-right"></i></button></nav></section>
<script>
/* 12/11/2026: fora o ADMINISTRADOR, sem incluir/duplicar/excluir; perfil de ADM da empresa só de consulta (o servidor também nega). Reaplica quando a grade recarrega. */
(function () {
  var soAdministrador = <?php echo $isAdministrator ? 'false' : 'true'; ?>;
  function travar() {
    var f = document.querySelector('.permission-grid-fragment'); if (!f) return;
    // fora o ADMINISTRADOR: sem incluir, duplicar e excluir
    // (a grade reativa Duplicar/Excluir ao selecionar uma linha: os botões ficam escondidos e o observador desativa de novo)
    if (soAdministrador) f.querySelectorAll('[data-crud-action="new"],[data-crud-action="duplicate"],[data-crud-action="delete"]').forEach(function (b) { if (!b.disabled) b.disabled = true; if (!b.hidden) b.hidden = true; if (b.style.display !== 'none') b.style.display = 'none'; });
    if (!f.querySelector('[data-perfil-somente-consulta]')) return;
    f.querySelectorAll('[data-crud-action="save"],[data-crud-action="new"],[data-crud-action="duplicate"],[data-crud-action="delete"]').forEach(function (b) { if (!b.disabled) { b.disabled = true; b.title = <?= adm_js('Somente o ADMINISTRADOR altera os acessos deste perfil') ?>; } });
    f.querySelectorAll('#permissionGridForm input[type="checkbox"], #permissionGridForm select, .permission-check-all').forEach(function (i) { if (!i.disabled) i.disabled = true; });
  }
  travar(); document.addEventListener('DOMContentLoaded', travar);
  new MutationObserver(travar).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'hidden', 'style'] });
}());
</script>

<form method="post" id="permissionExportForm"><input type="hidden" name="action" value="export_request"></form><div class="modal fade" id="permissionColumnsModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><?= adm_h('Colunas do grid') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="permissionColumnsList"></div><div class="modal-footer"><button type="button" class="btn btn-sm btn-success" data-save-permission-columns><?= adm_h('Salvar colunas') ?></button></div></div></div></div>
<div id="permissionFilterTemplates" hidden><select data-permission-filter="codperfil"><option value=""><?= adm_h('Todos') ?></option><?php foreach ($profiles as $profile): ?><option value="<?php echo (int)$profile['codperfil']; ?>"><?php echo htmlspecialchars(trim((string)($profile['nome'] ?? '')) !== '' ? (string)$profile['nome'] : (string)$profile['codigo'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select><select data-permission-filter="codmenu"><option value=""><?= adm_h('Todos') ?></option><?php foreach ($filterMenuOptions as $filterMenuId): ?><option value="<?php echo $filterMenuId; ?>"><?php echo htmlspecialchars($menuPath($filterMenuId) ?: (string)$menuMeta[$filterMenuId]['label'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
<script type="application/json" id="permissionMenuTreeData"><?php echo json_encode($menuMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
<?php /* escolha do perfil: seletor na barra do grid (ao lado da busca) */ ?>
<script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script><script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script><script src="../assets/js/seguranca-permissoes.js?v=7-i3"></script><script src="../assets/js/grid-sort.js?v=1"></script><script src="../assets/js/grid-loading.js?v=3"></script>
<script src="../assets/js/grid-column-filters.js?v=4"></script>
<script src="../assets/js/grid-ajax.js?v=2"></script>
<script src="../assets/js/grid-pagination.js?v=14"></script>
<script src="../assets/js/grid-column-filters.js?v=4"></script>
<?php require_once dirname(__DIR__) . '/components/toolbar_grupos.php'; greenPortalToolbarGruposAuto(); ?>
