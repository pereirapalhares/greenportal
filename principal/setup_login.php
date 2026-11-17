<?php
declare(strict_types=1);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
session_start();
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'UsuarioSessao.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'GreenPortalDatabase.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'ApplicationIdentity.php';

if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }

$config = [];
foreach (file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) { if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue; [$key, $value] = explode('=', $line, 2); if (!array_key_exists(trim($key), $config)) $config[trim($key)] = trim(trim($value), "\"'"); }
$tenantDatabase = (string)($_SESSION['tenant']['banco_dados'] ?? '');
$pdo = GreenPortalDatabase::connect($config, $tenantDatabase, 'GPT');
if (!UsuarioSessao::atualizar($pdo, $_SESSION) || ($_SESSION['usuario']['codigo_perfil'] ?? '') !== 'ADMINISTRADOR') { http_response_code(403); exit('Acesso negado.'); }

$fields = [
    'login_marca' => ['label' => 'Texto da marca', 'default' => ApplicationIdentity::LABEL],
    'login_eyebrow' => ['label' => 'Texto superior', 'default' => 'Bem-vindo de volta'],
    'login_titulo' => ['label' => 'Título', 'default' => 'Acesse seu portal'],
    'login_subtitulo' => ['label' => 'Subtítulo', 'default' => 'Entre com seus dados para continuar.'],
    'login_label_empresa' => ['label' => 'Label empresa', 'default' => 'Empresa'],
    'login_label_usuario' => ['label' => 'Label usuário', 'default' => 'Usuário'],
    'login_label_senha' => ['label' => 'Label senha', 'default' => 'Senha'],
    'login_botao_entrar' => ['label' => 'Botão entrar', 'default' => 'Entrar'],
    'login_marketing_eyebrow' => ['label' => 'Texto superior marketing', 'default' => 'Um novo jeito de gerenciar'],
    'login_marketing_titulo' => ['label' => 'Título marketing', 'default' => 'Sistemas de gestão. Mais conformidade.'],
    'login_marketing_texto' => ['label' => 'Texto marketing', 'default' => 'Sistemas de Gestão em conformidade com as normas ISO 14001, OHSAS 18001 e ISO 16001.'],
];

$fields += [
    'login_marketing_texto_secundario' => ['label' => 'Texto marketing secundário', 'default' => 'Completo suporte ao gerenciamento dos resíduos e efluentes industriais existentes, levantamento da legislação ambiental e trabalhista aplicável e orientação para adequar a empresa aos requisitos que não estejam sendo plenamente atendidos.'],
    'login_marketing_top_note' => ['label' => 'Aviso superior do painel', 'default' => 'Gestão inteligente para o seu negócio'],
    'login_marketing_feature_1' => ['label' => 'Benefício 1', 'default' => 'Integração dos sistemas de gestão'],
    'login_marketing_feature_2' => ['label' => 'Benefício 2', 'default' => 'Gestão de resíduos e efluentes industriais'],
    'login_marketing_feature_3' => ['label' => 'Benefício 3', 'default' => 'Orientação ambiental e trabalhista'],
    'login_marketing_rodape_esquerdo' => ['label' => 'Rodapé esquerdo', 'default' => ApplicationIdentity::NAME],
    'login_marketing_rodape_direito' => ['label' => 'Rodapé direito', 'default' => 'GESTÃO QUE TRANSFORMA'],
];
$textareaFields = ['login_marketing_texto', 'login_marketing_texto_secundario'];
$values = [];
foreach (GreenPortalDatabase::call($pdo, 'CALL sp_crud_select(:tabela, :filtros, NULL, 100, 0)', ['tabela' => 'vw_setup_empresa_grid', 'filtros' => json_encode(['idioma' => 'pt'], JSON_UNESCAPED_UNICODE)]) as $row) { $values[(string)$row['chave']] = ['value' => (string)($row['valor'] ?? ''), 'id' => (int)$row['codsetupempresa']]; }
foreach ($fields as $key => $field) if (!isset($values[$key])) $values[$key] = ['value' => $field['default'], 'id' => null];
if (($values['login_marca']['value'] ?? '') === 'Green Portal') $values['login_marca']['value'] = ApplicationIdentity::LABEL;
if (($values['login_marketing_rodape_esquerdo']['value'] ?? '') === 'GREEN PORTAL') $values['login_marketing_rodape_esquerdo']['value'] = ApplicationIdentity::NAME;

$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($fields as $key => $field) {
        $value = trim((string)($_POST[$key] ?? ''));
        if ($values[$key]['id']) {
            GreenPortalDatabase::call($pdo, 'CALL sp_crud_update(:tabela, :chave, :dados)', ['tabela' => 'setup_empresa', 'chave' => json_encode(['codsetupempresa' => $values[$key]['id']]), 'dados' => json_encode(['valor' => $value, 'idioma' => 'pt'], JSON_UNESCAPED_UNICODE)]);
        } else {
            GreenPortalDatabase::call($pdo, 'CALL sp_crud_insert(:tabela, :dados)', ['tabela' => 'setup_empresa', 'dados' => json_encode(['chave' => $key, 'valor' => $value, 'idioma' => 'pt'], JSON_UNESCAPED_UNICODE)]);
        }
        $values[$key]['value'] = $value;
    }
    $message = 'Configurações salvas com sucesso.';
}
?>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet">
<link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<link href="../assets/css/app.css" rel="stylesheet">
<link href="../assets/css/main.css?v=18" rel="stylesheet"><link href="../assets/css/design-system.css?v=3" rel="stylesheet"><link href="../assets/css/botoes-crud.css?v=4" rel="stylesheet">
<section class="setup-fragment">
    <?php $gpAjuda = class_exists('IdiomaService') ? IdiomaService::t('comum.botao.ajuda', ['pt' => 'Ajuda', 'en' => 'Help', 'es' => 'Ayuda']) : 'Ajuda'; $gpSalvar = class_exists('IdiomaService') ? IdiomaService::t('comum.botao.salvar', ['pt' => 'Salvar', 'en' => 'Save', 'es' => 'Guardar']) : 'Salvar'; ?><div class="crud-toolbar menu-grid-toolbar"><div class="crud-actions crud-actions-standard"><button class="btn btn-sm btn-success grid-crud-button grid-icon-button" type="submit" form="setupLoginForm" data-crud-action="save" title="<?php echo htmlspecialchars($gpSalvar, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($gpSalvar, ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-check2"></i></button><button class="btn btn-sm btn-outline-secondary grid-crud-button grid-icon-button" type="button" data-preview-login title="Preview" aria-label="Preview"><i class="bi bi-eye"></i></button><button class="btn btn-sm btn-outline-info grid-crud-button grid-icon-button" type="button" data-open-transacao-ajuda data-help-transaction="setup_login" title="<?php echo htmlspecialchars($gpAjuda, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($gpAjuda, ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-question-circle"></i></button><script src="../assets/js/ajuda-transacao.js?v=1"></script></div></div>
    <?php if ($message): ?><div class="alert alert-success" role="alert"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <form method="post" id="setupLoginForm" class="setup-form">
        <div class="setup-card"><div class="setup-fields">
            <?php foreach ($fields as $key => $field): ?><div class="setup-field <?php echo in_array($key, $textareaFields, true) ? 'setup-field-wide' : ''; ?>"><label for="<?php echo $key; ?>"><?php echo htmlspecialchars($field['label'], ENT_QUOTES, 'UTF-8'); ?></label><?php if (in_array($key, $textareaFields, true)): ?><textarea id="<?php echo $key; ?>" name="<?php echo $key; ?>" rows="3"><?php echo htmlspecialchars($values[$key]['value'], ENT_QUOTES, 'UTF-8'); ?></textarea><?php else: ?><input type="text" id="<?php echo $key; ?>" name="<?php echo $key; ?>" value="<?php echo htmlspecialchars($values[$key]['value'], ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?></div><?php endforeach; ?>
        </div></div>
    </form>
</section>
<div class="modal fade" id="loginPreviewModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Preview da tela de login</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"><div class="preview-login"><div class="preview-login-form"><div class="preview-brand" id="previewBrand">Green Portal</div><span class="eyebrow" id="previewEyebrow">Bem-vindo de volta</span><h2 id="previewTitle">Acesse seu portal</h2><p id="previewSubtitle">Entre com seus dados para continuar.</p><div class="preview-input">Empresa</div><div class="preview-input">Usuário</div><div class="preview-input">Senha</div><button type="button" id="previewButton">Entrar</button></div><div class="preview-login-marketing"><span class="eyebrow light-eyebrow" id="previewMarketingEyebrow">Um novo jeito de gerenciar</span><h2 id="previewMarketingTitle">Sistemas de gestão. Mais conformidade.</h2><p id="previewMarketingText">Sistemas de Gestão em conformidade com as normas ISO 14001, OHSAS 18001 e ISO 16001.</p></div></div></div></div></div></div>
<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script><script src="../assets/js/setup-login.js"></script>
<?php require_once dirname(__DIR__) . '/components/toolbar_grupos.php'; greenPortalToolbarGruposAuto(); ?>
