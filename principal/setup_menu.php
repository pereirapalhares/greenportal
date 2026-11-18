<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/AdministracaoTextos.php';
session_start();
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'UsuarioSessao.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'MensagemAplicacao.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'MenuCatalogoService.php';

if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
$config = [];
foreach (file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    if (!array_key_exists(trim($key), $config)) $config[trim($key)] = trim(trim($value), "\"'");
}
$pdo = new PDO('mysql:host=' . ($config['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($config['GPT_DB_PORT'] ?? '3306') . ';dbname=' . $_SESSION['tenant']['banco_dados'] . ';charset=utf8mb4', $config['GPT_DB_USERNAME'] ?? '', $config['GPT_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (!UsuarioSessao::atualizar($pdo, $_SESSION) || ($_SESSION['usuario']['codigo_perfil'] ?? '') !== 'ADMINISTRADOR') { http_response_code(403); exit(adm_t('Acesso negado.')); }
// Migração 326: não há menu específico de empresa. Os menus são lidos e gravados no catálogo universal (de onde vem o menu
// lateral) e replicados, pelo código, em todos os bancos de empresa (onde ficam as permissões). Preferências e exportação
// continuam no banco da empresa.
$catalogoMenus = new MenuCatalogoService();
$menuPdo = $catalogoMenus->universal();

function menuCall(PDO $pdo, string $procedure, array $params): array { if (str_contains($procedure, 'sp_crud_select') && str_contains($procedure, 'NULL, 25')) { $f = json_decode((string)($_GET['filtros'] ?? '{}'), true); if (isset($params['filtros']) && is_array($f)) $params['filtros'] = menuJson($f); $procedure = str_replace('NULL, 25', ':busca, 25', $procedure); $params['busca'] = trim((string)($_GET['busca'] ?? '')); } $s = $pdo->prepare($procedure); $s->execute($params); $rows = $s->fetchAll(); $s->closeCursor(); return $rows; }
function menuJson(mixed $value): string { if (is_array($value) && (($value['status'] ?? '') === 'SOLICITADA')) { $value['filtros'] = json_encode((array)json_decode((string)($_POST['filtros'] ?? '{}'), true), JSON_UNESCAPED_UNICODE); $value['busca'] = trim((string)($_POST['busca'] ?? '')); } return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

/*
 * 18/11/2026: o filtro das colunas não achava nada (o sp_crud_select do banco universal não entende {"value", "exact"}).
 * Os menus (poucas centenas) são lidos uma vez e filtrados aqui: igual nas colunas de lista, "contém" (sem acento e
 * sem maiúscula) nas de texto, e a busca geral em todas as colunas.
 */
function menuNormaliza(mixed $v): string { $t = mb_strtolower(trim((string)$v), 'UTF-8'); return strtr($t, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ü'=>'u','ç'=>'c']); }
function menuFiltrados(PDO $menuPdo, array $filtros, string $busca): array
{
    $s = $menuPdo->prepare('CALL sp_crud_select(:tabela, :filtros, NULL, 5000, 0)');
    $s->execute(['tabela' => 'vw_menus_grid', 'filtros' => '{}']);
    $todos = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
    $busca = menuNormaliza($busca);
    return array_values(array_filter($todos, static function (array $m) use ($filtros, $busca): bool {
        foreach ($filtros as $col => $regra) {
            if (!is_string($col) || !array_key_exists($col, $m)) continue;
            $exato = is_array($regra) ? !empty($regra['exact']) : false;
            $valor = menuNormaliza(is_array($regra) ? ($regra['value'] ?? '') : $regra);
            if ($valor === '') continue;
            $campo = menuNormaliza($m[$col] ?? '');
            if ($exato ? $campo !== $valor : !str_contains($campo, $valor)) return false;
        }
        if ($busca !== '' && !str_contains(menuNormaliza(implode(' ', array_map('strval', $m))), $busca)) return false;
        return true;
    }));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_preferences') {
    header('Content-Type: application/json; charset=utf-8');
    $preferences = json_decode((string)($_POST['preferences'] ?? '[]'), true);
    if (!is_array($preferences)) { http_response_code(422); echo json_encode(['ok' => false]); exit; }
    $codusuario = (int)$_SESSION['usuario']['codusuario'];
    foreach ($preferences as $preference) {
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($preference['coluna'] ?? ''));
        if ($column === '') continue;
        $existing = menuCall($pdo, 'CALL sp_crud_select(:tabela, :filtros, NULL, 1, 0)', ['tabela' => 'vw_preferencias_grid', 'filtros' => menuJson(['codusuario' => $codusuario, 'transacao' => 'setup_menu', 'coluna' => $column])]);
        $data = ['codusuario' => $codusuario, 'transacao' => 'setup_menu', 'coluna' => $column, 'ordem' => (int)($preference['ordem'] ?? 0), 'visivel' => (int)!empty($preference['visivel']), 'largura' => max(60, (int)($preference['largura'] ?? 120))];
        if ($existing) menuCall($pdo, 'CALL sp_crud_update(:tabela, :chave, :dados)', ['tabela' => 'preferencias_grid', 'chave' => menuJson(['codpreferenciagrid' => $existing[0]['codpreferenciagrid']]), 'dados' => menuJson($data)]);
        else menuCall($pdo, 'CALL sp_crud_insert(:tabela, :dados)', ['tabela' => 'preferencias_grid', 'dados' => menuJson($data)]);
    }
    echo json_encode(['ok' => true]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export_request') {
    $codusuario = (int)$_SESSION['usuario']['codusuario'];
    // Pedido na fila do GreenPortal-ExportWorker (Agendador do Windows) + alerta "Exportação iniciada".
    require_once dirname(__DIR__).DIRECTORY_SEPARATOR.'classes'.DIRECTORY_SEPARATOR.'ExportacaoSolicitacao.php';
    ExportacaoSolicitacao::solicitar($pdo, $codusuario, 'setup_menu', adm_t('Setup / Menu'), ['filtros' => (array)json_decode((string)($_POST['filtros'] ?? '{}'), true), 'busca' => (string)($_POST['busca'] ?? ''), 'colunas' => trim((string)($_POST['colunas'] ?? '[]')) ?: '[]']);
    $message = ExportacaoSolicitacao::mensagem();
}

$message = null;
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' || ($_POST['ajax'] ?? '') === '1';
if ($isAjax) {
    set_exception_handler(static function (Throwable $exception): void {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        $message = MensagemAplicacao::erro($exception, 'salvar o menu');
        echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    });
}
if ($isAjax && ($_POST['action'] ?? '') === 'count_rows') { $f = json_decode((string)($_POST['filtros'] ?? '{}'), true); $total = count(menuFiltrados($menuPdo, is_array($f) ? $f : [], trim((string)($_POST['busca'] ?? '')))); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'total' => $total]); exit; }
if ($isAjax && ($_POST['action'] ?? '') === 'save_rows') { $created = []; foreach ((array)($_POST['rows'] ?? []) as $key => $row) { $data = ['codmenu_pai' => ($row['codmenu_pai'] ?? '') === '' ? null : (int)$row['codmenu_pai'], 'modulo' => trim((string)($row['modulo'] ?? '')), 'codigo' => trim((string)($row['codigo'] ?? '')), 'label' => trim((string)($row['label'] ?? '')), 'label_pt' => trim((string)($row['label_pt'] ?? '')), 'label_en' => trim((string)($row['label_en'] ?? '')), 'label_es' => trim((string)($row['label_es'] ?? '')), 'url' => trim((string)($row['url'] ?? '')) ?: null, 'nivel' => max(1, (int)($row['nivel'] ?? 1)), 'ordem' => (int)($row['ordem'] ?? 0), 'ativo' => !empty($row['ativo']) ? 1 : 0]; if ($data['codigo'] === '' || $data['label_pt'] === '') continue; try { $gravado = $catalogoMenus->gravar($data, ctype_digit((string)$key) ? (int)$key : null); } catch (DomainException $e) { http_response_code(422); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'message' => adm_t('O código do menu ') . $data['codigo'] . adm_t(' já existe. Informe um código único para o novo menu.')], JSON_UNESCAPED_UNICODE); exit; } if (!ctype_digit((string)$key)) $created[(string)$key] = (int)$gravado['codmenu']; } header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'message' => adm_t('Menus salvos com sucesso.'), 'created' => $created], JSON_UNESCAPED_UNICODE); exit; }
if ($isAjax && ($_POST['action'] ?? '') === 'delete_row' && ctype_digit((string)($_POST['codmenu'] ?? ''))) { header('Content-Type: application/json; charset=utf-8'); try { $catalogoMenus->excluir((int)$_POST['codmenu']); } catch (DomainException $e) { http_response_code(422); echo json_encode(['ok' => false, 'message' => adm_t('Não foi possível excluir: ') . $e->getMessage() . '.'], JSON_UNESCAPED_UNICODE); exit; } echo json_encode(['ok' => true, 'message' => adm_t('Menu excluído com sucesso.')], JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_rows') {
    foreach ((array)($_POST['rows'] ?? []) as $key => $row) {
        $data = [
            'codmenu_pai' => ($row['codmenu_pai'] ?? '') === '' ? null : (int)$row['codmenu_pai'],
            'modulo' => trim((string)($row['modulo'] ?? '')),
            'codigo' => trim((string)($row['codigo'] ?? '')),
            'label' => trim((string)($row['label'] ?? '')),
            'label_pt' => trim((string)($row['label_pt'] ?? '')),
            'label_en' => trim((string)($row['label_en'] ?? '')),
            'label_es' => trim((string)($row['label_es'] ?? '')),
            'url' => trim((string)($row['url'] ?? '')) ?: null,
            'nivel' => max(1, (int)($row['nivel'] ?? 1)),
            'ordem' => (int)($row['ordem'] ?? 0),
            'ativo' => !empty($row['ativo']) ? 1 : 0,
        ];
        if ($data['codigo'] === '' || $data['label_pt'] === '') continue;
        try {
            $catalogoMenus->gravar($data, ctype_digit((string)$key) ? (int)$key : null);
        } catch (DomainException) {
            $message = adm_t('O código do menu já existe. Informe um código único para o novo menu.');
            continue;
        }
    }
    if ($message === null) $message = adm_t('Menus salvos com sucesso.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_row' && ctype_digit((string)($_POST['codmenu'] ?? ''))) {
    try { $catalogoMenus->excluir((int)$_POST['codmenu']); $message = adm_t('Menu excluído com sucesso.'); }
    catch (DomainException $e) { $message = adm_t('Não foi possível excluir: ') . $e->getMessage() . '.'; }
}

$gridPage = max(1, (int)($_GET['pagina'] ?? 1)); $gridSize = 25; $gridOffset = ($gridPage - 1) * $gridSize;
$filtrosTela = json_decode((string)($_GET['filtros'] ?? '{}'), true);
$menusFiltrados = menuFiltrados($menuPdo, is_array($filtrosTela) ? $filtrosTela : [], trim((string)($_GET['busca'] ?? '')));
$menus = array_slice($menusFiltrados, $gridOffset, $gridSize);
// Nome do pai (caminho) para a coluna Pai e para o filtro dela
$todosMenus = menuFiltrados($menuPdo, [], '');
$rotuloMenu = [];
foreach ($todosMenus as $m) $rotuloMenu[(int)$m['codmenu']] = trim((string)($m['label_pt'] ?? '')) !== '' ? (string)$m['label_pt'] : (string)$m['codigo'];
$caminhoMenu = static function (int $cod) use ($todosMenus, $rotuloMenu): string {
    static $pai = null; if ($pai === null) { $pai = []; foreach ($todosMenus as $m) $pai[(int)$m['codmenu']] = (int)($m['codmenu_pai'] ?? 0); }
    $partes = []; $vistos = [];
    while ($cod > 0 && isset($rotuloMenu[$cod]) && !isset($vistos[$cod])) { $vistos[$cod] = true; array_unshift($partes, $rotuloMenu[$cod]); $cod = $pai[$cod] ?? 0; }
    return implode(' / ', $partes);
};
$opcoesPai = [];
foreach ($todosMenus as $m) if (!empty($m['codmenu_pai'])) $opcoesPai[(string)(int)$m['codmenu_pai']] = $caminhoMenu((int)$m['codmenu_pai']);
$todosPais = [];
foreach ($todosMenus as $m) $todosPais[(string)(int)$m['codmenu']] = $caminhoMenu((int)$m['codmenu']);
$preferenceRows = menuCall($pdo, 'CALL sp_crud_select(:tabela, :filtros, NULL, 100, 0)', ['tabela' => 'vw_preferencias_grid', 'filtros' => menuJson(['codusuario' => (int)$_SESSION['usuario']['codusuario'], 'transacao' => 'setup_menu'])]);
$preferences = [];
foreach ($preferenceRows as $preference) $preferences[(string)$preference['coluna']] = $preference;
$columns = [
    'codmenu' => adm_t('Código'), 'codmenu_pai' => adm_t('Pai'), 'modulo' => adm_t('Módulo'), 'codigo' => adm_t('Código menu'),
    'label_pt' => adm_t('Label PT'), 'label_en' => adm_t('Label EN'), 'label_es' => adm_t('Label ES'), 'url' => 'URL',
    'nivel' => adm_t('Nível'), 'ordem' => adm_t('Ordem'), 'ativo' => adm_t('Ativo')
];
?>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet">
<link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<link href="../assets/css/app.css" rel="stylesheet"><link href="../assets/css/main.css?v=18" rel="stylesheet"><link href="../assets/css/design-system.css?v=3" rel="stylesheet"><link href="../assets/css/botoes-crud.css?v=4" rel="stylesheet">
<section class="setup-fragment menu-grid-fragment" data-preferences="<?php echo htmlspecialchars(menuJson($preferences), ENT_QUOTES, 'UTF-8'); ?>">
    <div class="crud-toolbar menu-grid-toolbar"><div class="crud-actions crud-actions-standard"><button data-crud-action="save" class="btn grid-crud-button btn-sm btn-success" type="submit" form="menuGridForm"><i class="bi bi-check2 me-1"></i><?= adm_h('Salvar') ?></button><button data-crud-action="new" class="btn grid-crud-button btn-sm btn-outline-success" type="button" data-new-menu><i class="bi bi-plus-lg me-1"></i><?= adm_h('Novo') ?></button><button data-crud-action="delete" class="btn grid-crud-button btn-sm btn-outline-danger" type="button" data-delete-menu><i class="bi bi-trash me-1"></i><?= adm_h('Excluir') ?></button><button data-crud-action="reload" class="btn grid-crud-button btn-sm btn-outline-secondary" type="button" data-reload-grid><i class="bi bi-arrow-clockwise me-1"></i><?= adm_h('Recarregar') ?></button><button data-crud-action="export" class="btn grid-crud-button btn-sm btn-outline-success" type="submit" name="action" value="export_request" form="menuExportForm"><i class="bi bi-file-earmark-excel me-1"></i><?= adm_h('Exportar Excel') ?></button><button data-crud-action="columns" class="btn grid-crud-button btn-sm btn-outline-secondary" type="button" data-columns><i class="bi bi-layout-three-columns me-1"></i><?= adm_h('Colunas') ?></button><?php $gpAjuda = class_exists('IdiomaService') ? IdiomaService::t('comum.botao.ajuda', ['pt' => 'Ajuda', 'en' => 'Help', 'es' => 'Ayuda']) : 'Ajuda'; ?><button class="btn btn-sm btn-outline-info grid-crud-button" type="button" data-open-transacao-ajuda data-help-transaction="setup_menu" title="<?php echo htmlspecialchars($gpAjuda, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($gpAjuda, ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-question-circle"></i></button><script src="../assets/js/ajuda-transacao.js?v=1"></script></div><div class="crud-search"><i class="bi bi-search"></i><input type="search" id="menuGridSearch" placeholder="<?= adm_a('Buscar no grid') ?>" aria-label="<?= adm_a('Buscar no grid') ?>"></div></div>
    <?php if ($message): ?><div class="alert alert-success py-2 mb-2" role="alert"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <form method="post" id="menuGridForm" class="setup-form menu-grid-form"><input type="hidden" name="action" value="save_rows"><div class="menu-grid-scroll"><table class="table table-sm menu-batch-grid" id="menuBatchGrid" data-pais="<?php echo htmlspecialchars(menuJson($todosPais), ENT_QUOTES, 'UTF-8'); ?>"><thead><tr><?php foreach ($columns as $key => $label): ?><th data-column="<?php echo $key; ?>" style="width:<?php echo (int)($preferences[$key]['largura'] ?? 120); ?>px"><div class="column-heading"><span><?php echo $label; ?></span><input class="column-filter" type="search" data-column-filter="<?php echo $key; ?>"<?php if ($key === 'codmenu_pai'): ?> data-filter-options="<?php echo htmlspecialchars(menuJson($opcoesPai), ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?> placeholder="<?= adm_a('Buscar') ?>" aria-label="Buscar <?php echo $label; ?>"></div></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($menus as $menu): $id = (int)$menu['codmenu']; ?><tr data-row data-key="<?php echo $id; ?>"><td data-column="codmenu"><input class="grid-input" value="<?php echo $id; ?>" readonly></td><td data-column="codmenu_pai"><input class="grid-input" name="rows[<?php echo $id; ?>][codmenu_pai]" value="<?php echo htmlspecialchars((string)($menu['codmenu_pai'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></td><td data-column="modulo"><input class="grid-input" name="rows[<?php echo $id; ?>][modulo]" value="<?php echo htmlspecialchars((string)$menu['modulo'], ENT_QUOTES, 'UTF-8'); ?>"></td><td data-column="codigo"><input class="grid-input" name="rows[<?php echo $id; ?>][codigo]" value="<?php echo htmlspecialchars((string)$menu['codigo'], ENT_QUOTES, 'UTF-8'); ?>"></td><td data-column="label_pt"><input class="grid-input" name="rows[<?php echo $id; ?>][label_pt]" value="<?php echo htmlspecialchars((string)$menu['label_pt'], ENT_QUOTES, 'UTF-8'); ?>"></td><td data-column="label_en"><input class="grid-input" name="rows[<?php echo $id; ?>][label_en]" value="<?php echo htmlspecialchars((string)$menu['label_en'], ENT_QUOTES, 'UTF-8'); ?>"></td><td data-column="label_es"><input class="grid-input" name="rows[<?php echo $id; ?>][label_es]" value="<?php echo htmlspecialchars((string)$menu['label_es'], ENT_QUOTES, 'UTF-8'); ?>"></td><td data-column="url"><input class="grid-input" name="rows[<?php echo $id; ?>][url]" value="<?php echo htmlspecialchars((string)($menu['url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></td><td data-column="nivel"><input class="grid-input grid-number" name="rows[<?php echo $id; ?>][nivel]" value="<?php echo (int)$menu['nivel']; ?>"></td><td data-column="ordem"><input class="grid-input grid-number" name="rows[<?php echo $id; ?>][ordem]" value="<?php echo (int)$menu['ordem']; ?>"></td><td data-column="ativo" class="text-center"><input type="checkbox" name="rows[<?php echo $id; ?>][ativo]" value="1" <?php echo !empty($menu['ativo']) ? 'checked' : ''; ?>></td></tr><?php endforeach; ?>
    </tbody></table></div></form><nav class="grid-pagination" aria-label="<?= adm_a('Paginação do grid') ?>"><button type="button" class="btn btn-sm btn-outline-secondary grid-page-prev"><i class="bi bi-chevron-left"></i><span><?= adm_h('Anterior') ?></span></button><span class="grid-page-status"><?= adm_h('Calculando total...') ?></span><button type="button" class="btn btn-sm btn-outline-secondary grid-page-next"><span><?= adm_h('Próxima') ?></span><i class="bi bi-chevron-right"></i></button></nav>
</section>
<form method="post" id="menuExportForm"><input type="hidden" name="action" value="export_request"></form>
<div class="modal fade" id="menuColumnsModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><?= adm_h('Colunas do grid') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= adm_a('Fechar') ?>"></button></div><div class="modal-body" id="menuColumnsList"></div><div class="modal-footer"><button type="button" class="btn btn-sm btn-success" data-save-columns><?= adm_h('Salvar colunas') ?></button></div></div></div></div>
<script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script><script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script><script src="../assets/js/setup-menu.js?v=3"></script>
<script src="../assets/js/crud-grid-confirm.js?v=1"></script>
<script src="../assets/js/menu-duplicate.js?v=1"></script>
<script src="../assets/js/grid-sort.js?v=1"></script>

<script src="../assets/js/grid-column-filters.js?v=5"></script><script src="../assets/js/grid-pagination.js?v=14"></script>
<script src="../assets/js/grid-loading.js?v=4"></script>
<script src="../assets/js/grid-ajax.js?v=2"></script>
<script src="../assets/js/grid-context-fields.js?v=3"></script>
<?php require_once dirname(__DIR__) . '/components/toolbar_grupos.php'; greenPortalToolbarGruposAuto(); ?>
