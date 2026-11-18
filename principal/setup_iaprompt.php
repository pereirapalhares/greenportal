<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/AdministracaoTextos.php';

session_start();
header('Content-Type: text/html; charset=utf-8');
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/components/botoes_crud.php';

if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, (string)$_SESSION['tenant']['banco_dados'], 'GPT');
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { http_response_code(401); exit(adm_t('Sessao expirada.')); }
$transaction = 'SETUP_IA_PROMPT';
$permission = PermissaoTransacao::obter($transaction);
if (!$permission['pode_visualizar']) { http_response_code(403); exit(adm_t('Perfil sem permissao para consultar os prompts de IA.')); }
$language = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';
$labels = [
    'pt' => ['code'=>'Codigo','name_pt'=>adm_t('Nome PT'),'name_en'=>adm_t('Nome EN'),'name_es'=>adm_t('Nome ES'),'prompt_pt'=>adm_t('Prompt PT'),'prompt_en'=>adm_t('Prompt EN'),'prompt_es'=>adm_t('Prompt ES'),'version'=>adm_t('Versao'),'changed'=>adm_t('Alterado em'),'save'=>adm_t('Salvar'),'history'=>adm_t('Historico'),'info'=>adm_t('Somente prompts existentes podem ser alterados. PT, EN e ES sao obrigatorios.'),'search'=>adm_t('Buscar no grid')],
    'en' => ['code'=>'Code','name_pt'=>adm_t('Name PT'),'name_en'=>adm_t('Name EN'),'name_es'=>adm_t('Name ES'),'prompt_pt'=>adm_t('Prompt PT'),'prompt_en'=>adm_t('Prompt EN'),'prompt_es'=>adm_t('Prompt ES'),'version'=>adm_t('Version'),'changed'=>adm_t('Changed at'),'save'=>adm_t('Save'),'history'=>adm_t('History'),'info'=>adm_t('Only existing prompts can be changed. PT, EN and ES are required.'),'search'=>adm_t('Search grid')],
    'es' => ['code'=>'Codigo','name_pt'=>adm_t('Nombre PT'),'name_en'=>adm_t('Nombre EN'),'name_es'=>adm_t('Nombre ES'),'prompt_pt'=>adm_t('Prompt PT'),'prompt_en'=>adm_t('Prompt EN'),'prompt_es'=>adm_t('Prompt ES'),'version'=>adm_t('Version'),'changed'=>adm_t('Modificado en'),'save'=>adm_t('Guardar'),'history'=>adm_t('Historico'),'info'=>adm_t('Solo se pueden modificar prompts existentes. PT, EN y ES son obligatorios.'),'search'=>adm_t('Buscar en el grid')],
][$language];
$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$json = static fn(mixed $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (empty($_SESSION['setup_ia_prompt_csrf'])) $_SESSION['setup_ia_prompt_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['setup_ia_prompt_csrf'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(419); exit(adm_t('Token de seguranca invalido.')); }
    if (($_POST['action'] ?? '') === 'save_prompt') {
        if (!$permission['pode_editar']) { http_response_code(403); exit(adm_t('Perfil sem permissao para alterar prompts.')); }
        try {
            $result = GreenPortalDatabase::call($pdo, 'CALL sp_ia_prompt_atualizar(:id,:pt,:en,:es,:usuario)', ['id'=>(int)($_POST['codprompt']??0),'pt'=>(string)($_POST['prompt_pt']??''),'en'=>(string)($_POST['prompt_en']??''),'es'=>(string)($_POST['prompt_es']??''),'usuario'=>(int)$_SESSION['usuario']['codusuario']]);
            header('Content-Type: application/json; charset=utf-8'); echo $json(['ok'=>true,'message'=>adm_t('Prompt salvo com historico.'),'versao'=>(int)($result[0]['versao']??0)]);
        } catch (Throwable $error) { http_response_code(422); header('Content-Type: application/json; charset=utf-8'); echo $json(['ok'=>false,'message'=>$error->getMessage()]); }
        exit;
    }
    if (($_POST['action'] ?? '') === 'history') {
        $result = GreenPortalDatabase::call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,500,0)', ['tabela'=>'vw_ia_prompts_historico_grid','filtros'=>$json(['codprompt'=>(int)($_POST['codprompt']??0)])]);
        header('Content-Type: application/json; charset=utf-8'); echo $json(['ok'=>true,'rows'=>$result]); exit;
    }
}
$rows = GreenPortalDatabase::call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,100,0)', ['tabela'=>'vw_ia_prompts_grid','filtros'=>$json([])]);
?>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet">
<link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<link href="../assets/css/app.css?v=5" rel="stylesheet">
<link href="../assets/css/main.css?v=18" rel="stylesheet"><link href="../assets/css/design-system.css?v=3" rel="stylesheet">
<link href="../assets/css/botoes-crud.css?v=4" rel="stylesheet">
<section class="setup-fragment menu-grid-fragment ia-prompt-grid-fragment" data-grid-transaction="<?= $e($transaction) ?>" data-grid-permissions="<?= $e($json($permission)) ?>" data-csrf="<?= $e($csrf) ?>">
<div class="crud-toolbar menu-grid-toolbar"><?php greenPortalCrudButtons(['form_id'=>'iaPromptGridForm','language'=>$language,'permission'=>$permission,'permission_title'=>adm_t('Perfil sem permissao de acesso!'),'transaction'=>$transaction,'save'=>false,'new'=>false,'duplicate'=>false,'delete'=>false,'export'=>false,'columns'=>true,'extras'=>'<button class="btn btn-sm btn-success grid-crud-button" type="button" data-save-ia-prompt'.(!$permission['pode_editar']?' disabled data-permission-locked="1"':'').' title="'.$e($permission['pode_editar']?$labels['save']:adm_t('Perfil sem permissao')).'" aria-label="'.$e($labels['save']).'"><i class="bi bi-check2"></i></button><button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-view-ia-history title="'.$e($labels['history']).'" aria-label="'.$e($labels['history']).'"><i class="bi bi-clock-history"></i></button>']); ?><div class="crud-search"><i class="bi bi-search"></i><input type="search" data-grid-search placeholder="<?= $e($labels['search']) ?>" aria-label="<?= $e($labels['search']) ?>"></div></div>
<div class="alert alert-info py-2"><?= $e($labels['info']) ?></div>
<form id="iaPromptGridForm" class="menu-grid-form"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><div class="menu-grid-scroll"><table class="table table-sm menu-batch-grid" id="iaPromptGrid"><thead><tr><?php foreach(['codigo'=>'code','nome_pt'=>'name_pt','nome_en'=>'name_en','nome_es'=>'name_es','prompt_pt'=>'prompt_pt','prompt_en'=>'prompt_en','prompt_es'=>'prompt_es','versao'=>'version','alterado_em'=>'changed'] as $column=>$label): ?><th data-column="<?= $e($column) ?>"><div class="column-heading"><span><?= $e($labels[$label]) ?></span><input class="column-filter" type="search" data-column-filter="<?= $e($column) ?>" placeholder="<?= $e($labels['search']) ?>" aria-label="<?= $e($labels[$label]) ?>"></div></th><?php endforeach; ?></tr></thead><tbody><?php foreach($rows as $row): $key=(int)$row['codprompt']; ?><tr data-row data-key="<?= $key ?>"><td data-column="codigo"><?= $e((string)$row['codigo']) ?></td><td data-column="nome_pt"><?= $e((string)$row['nome_pt']) ?></td><td data-column="nome_en"><?= $e((string)$row['nome_en']) ?></td><td data-column="nome_es"><?= $e((string)$row['nome_es']) ?></td><?php foreach(['pt','en','es'] as $langCode): ?><td data-column="prompt_<?= $langCode ?>"><textarea class="grid-input ia-prompt-input" data-lang="<?= $langCode ?>" rows="8"<?= !$permission['pode_editar']?' readonly':'' ?>><?= $e((string)$row['prompt_'.$langCode]) ?></textarea></td><?php endforeach; ?><td data-column="versao"><?= (int)$row['versao'] ?></td><td data-column="alterado_em"><?= $e((string)$row['alterado_em']) ?></td></tr><?php endforeach; ?></tbody></table></div></form>
</section>
<script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script><script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script><?= AdministracaoTextos::script() ?><script src="../assets/js/setup-ia-prompt.js?v=2-i3"></script><script src="../assets/js/grid-sort.js?v=1"></script><script src="../assets/js/grid-column-filters.js?v=3"></script><script src="../assets/js/grid-column-resize.js?v=1"></script><script src="../assets/js/grid-loading.js?v=4"></script>
