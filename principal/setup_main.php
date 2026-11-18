<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/AdministracaoTextos.php';
session_start();
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'UsuarioSessao.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'ApplicationIdentity.php';

if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }

$config = [];
foreach (file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    if (!array_key_exists(trim($key), $config)) $config[trim($key)] = trim(trim($value), "\"'");
}
$pdo = new PDO('mysql:host=' . ($config['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($config['GPT_DB_PORT'] ?? '3306') . ';dbname=' . $_SESSION['tenant']['banco_dados'] . ';charset=utf8mb4', $config['GPT_DB_USERNAME'] ?? '', $config['GPT_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (!UsuarioSessao::atualizar($pdo, $_SESSION) || ($_SESSION['usuario']['codigo_perfil'] ?? '') !== 'ADMINISTRADOR') { http_response_code(403); exit(adm_t('Acesso negado.')); }

$fields = [
    'main_marca_texto' => ['label' => adm_t('Texto GreenSolutions'), 'default' => ApplicationIdentity::LABEL],
    'main_cabecalho_texto' => ['label' => adm_t('Texto do cabeçalho'), 'default' => ''],
    'main_rodape_texto' => ['label' => adm_t('Texto do rodapé'), 'default' => ApplicationIdentity::LABEL . adm_t(' - Todos os direitos reservados.')],
    'sessao_timeout_minutos' => ['label' => adm_t('Timeout de inatividade (minutos)'), 'default' => '30'],
];

function setupMainCall(PDO $pdo, string $sql, array $params): array { $statement = $pdo->prepare($sql); $statement->execute($params); $rows = $statement->fetchAll(); $statement->closeCursor(); return $rows; }
$values = [];
foreach (setupMainCall($pdo, 'CALL sp_crud_select(:tabela, :filtros, NULL, 100, 0)', ['tabela' => 'vw_setup_empresa_grid', 'filtros' => json_encode(['idioma' => 'pt'], JSON_UNESCAPED_UNICODE)]) as $row) { $values[(string)$row['chave']] = ['value' => (string)($row['valor'] ?? ''), 'id' => (int)$row['codsetupempresa']]; }
if (isset($values['main_cabecalho_texto']) && mb_strtolower(trim($values['main_cabecalho_texto']['value']), 'UTF-8') === 'área de trabalho') $values['main_cabecalho_texto']['value'] = '';
foreach ($fields as $key => $field) if (!isset($values[$key])) $values[$key] = ['value' => $field['default'], 'id' => null];
if (($values['main_marca_texto']['value'] ?? '') === 'Green Portal') $values['main_marca_texto']['value'] = ApplicationIdentity::LABEL;
if (($values['main_rodape_texto']['value'] ?? '') === 'Green Portal - Todos os direitos reservados.') $values['main_rodape_texto']['value'] = ApplicationIdentity::LABEL . adm_t(' - Todos os direitos reservados.');

$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($fields as $key => $field) {
        $value = trim((string)($_POST[$key] ?? ''));
        if ($key === 'sessao_timeout_minutos') $value = (string)max(1, min(1440, (int)$value));
        if ($values[$key]['id']) {
            setupMainCall($pdo, 'CALL sp_crud_update(:tabela, :chave, :dados)', ['tabela' => 'setup_empresa', 'chave' => json_encode(['codsetupempresa' => $values[$key]['id']]), 'dados' => json_encode(['valor' => $value, 'idioma' => 'pt'], JSON_UNESCAPED_UNICODE)]);
        } else {
            setupMainCall($pdo, 'CALL sp_crud_insert(:tabela, :dados)', ['tabela' => 'setup_empresa', 'dados' => json_encode(['chave' => $key, 'valor' => $value, 'idioma' => 'pt'], JSON_UNESCAPED_UNICODE)]);
        }
        $values[$key]['value'] = $value;
    }
    $message = adm_t('Configurações salvas com sucesso.');
}
?>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet">
<link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<link href="../assets/css/app.css" rel="stylesheet"><link href="../assets/css/main.css?v=18" rel="stylesheet"><link href="../assets/css/design-system.css?v=3" rel="stylesheet"><link href="../assets/css/botoes-crud.css?v=4" rel="stylesheet">
<section class="setup-fragment">
    <?php $gpAjuda = class_exists('IdiomaService') ? IdiomaService::t('comum.botao.ajuda', ['pt' => 'Ajuda', 'en' => 'Help', 'es' => 'Ayuda']) : 'Ajuda'; $gpSalvar = class_exists('IdiomaService') ? IdiomaService::t('comum.botao.salvar', ['pt' => 'Salvar', 'en' => 'Save', 'es' => 'Guardar']) : 'Salvar'; ?><div class="crud-toolbar menu-grid-toolbar"><div class="crud-actions crud-actions-standard"><button class="btn btn-sm btn-success grid-crud-button grid-icon-button" type="submit" form="setupMainForm" data-crud-action="save" title="<?php echo htmlspecialchars($gpSalvar, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($gpSalvar, ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-check2"></i></button><button class="btn btn-sm btn-outline-secondary grid-crud-button grid-icon-button" type="button" data-preview-main title="<?= adm_a('Preview') ?>" aria-label="<?= adm_a('Preview') ?>"><i class="bi bi-eye"></i></button><button class="btn btn-sm btn-outline-info grid-crud-button grid-icon-button" type="button" data-open-transacao-ajuda data-help-transaction="setup_main" title="<?php echo htmlspecialchars($gpAjuda, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($gpAjuda, ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-question-circle"></i></button><script src="../assets/js/ajuda-transacao.js?v=1"></script></div></div>
    <?php if ($message): ?><div class="alert alert-success" role="alert"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <form method="post" id="setupMainForm" class="setup-form"><div class="setup-card"><div class="setup-fields">
        <?php foreach ($fields as $key => $field): ?><div class="setup-field <?php echo str_contains($key, 'texto_') ? 'setup-field-wide' : ''; ?>"><label for="<?php echo $key; ?>"><?php echo htmlspecialchars($field['label'], ENT_QUOTES, 'UTF-8'); ?></label><?php if (str_contains($key, 'texto_')): ?><textarea id="<?php echo $key; ?>" name="<?php echo $key; ?>" rows="3"><?php echo htmlspecialchars($values[$key]['value'], ENT_QUOTES, 'UTF-8'); ?></textarea><?php else: ?><input type="text" id="<?php echo $key; ?>" name="<?php echo $key; ?>" value="<?php echo htmlspecialchars($values[$key]['value'], ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?></div><?php endforeach; ?>
    </div></div></form>
</section>
<div class="modal fade" id="mainPreviewModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><?= adm_h('Preview da tela principal') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= adm_a('Fechar') ?>"></button></div><div class="modal-body"><div class="main-preview"><div class="main-preview-header"><strong id="previewMainBrand"><?= adm_h('Green Portal') ?></strong><span id="previewMainHeader"></span></div><div class="main-preview-content"><h2><?= adm_h('Conteúdo da área de trabalho') ?></h2><p><?= adm_h('O menu permanece com seus labels padrão.') ?></p></div><div class="main-preview-footer" id="previewMainFooter"><?= adm_h('Green Portal - Todos os direitos reservados.') ?></div></div></div></div></div></div>
<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script><script src="../assets/js/setup-main.js"></script>
<?php require_once dirname(__DIR__) . '/components/toolbar_grupos.php'; greenPortalToolbarGruposAuto(); ?>
