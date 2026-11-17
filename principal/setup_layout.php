<?php
declare(strict_types=1);
session_start();
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'UsuarioSessao.php';
if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
$config = [];
foreach (file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) { if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue; [$key, $value] = explode('=', $line, 2); if (!array_key_exists(trim($key), $config)) $config[trim($key)] = trim(trim($value), "\"'"); }
$pdo = new PDO('mysql:host=' . ($config['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($config['GPT_DB_PORT'] ?? '3306') . ';dbname=' . $_SESSION['tenant']['banco_dados'] . ';charset=utf8mb4', $config['GPT_DB_USERNAME'] ?? '', $config['GPT_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
// Layout pessoal (menu do usuário no cabeçalho › Layout): cada usuário escolhe o seu tema de cores e a tela que abre
// ao entrar no portal (preferencias_usuario: layout_tema e default_transacao). Qualquer usuário logado pode usar.
// Quem tem Editar na transação SETUP_LAYOUT (Segurança › Permissões) pode também gravar o tema como padrão da empresa
// (setup_empresa.layout_tema), que vale para quem ainda não escolheu o seu.
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'PermissaoTransacao.php';
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { http_response_code(403); exit('Acesso negado.'); }
$layoutPermissao = PermissaoTransacao::obter('SETUP_LAYOUT');
$layoutPodeEmpresa = !empty($layoutPermissao['pode_editar']) || !empty($layoutPermissao['pode_incluir']);
$layoutPodeEditar = true;
function layoutCall(PDO $pdo, string $sql, array $params): array { $s = $pdo->prepare($sql); $s->execute($params); $rows = $s->fetchAll(); $s->closeCursor(); return $rows; }
function layoutJson(mixed $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
$idioma = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';
$viewLabel = ['pt' => 'Visualizar', 'en' => 'View', 'es' => 'Visualizar'][$idioma];
$textosPessoais = ['pt' => ['inicio' => 'Tela inicial ao entrar no portal', 'inicio_ajuda' => 'A transação que abre automaticamente depois do login.', 'empresa' => 'Aplicar este tema também como padrão da empresa (para quem ainda não escolheu o seu)', 'dica' => 'Estas preferências valem só para você.', 'dashboard' => 'Dashboard'],
    'en' => ['inicio' => 'Home screen after signing in', 'inicio_ajuda' => 'The screen that opens automatically after login.', 'empresa' => 'Also apply this theme as the company default (for users who have not chosen one)', 'dica' => 'These preferences apply only to you.', 'dashboard' => 'Dashboard'],
    'es' => ['inicio' => 'Pantalla inicial al entrar al portal', 'inicio_ajuda' => 'La transacción que se abre automáticamente después del inicio de sesión.', 'empresa' => 'Aplicar este tema también como predeterminado de la empresa (para quien aún no eligió el suyo)', 'dica' => 'Estas preferencias valen solo para usted.', 'dashboard' => 'Dashboard']][$idioma];
$translations = ['pt' => ['title' => 'Paleta de cores do portal', 'save' => 'Salvar', 'success' => 'Layout salvo com sucesso.', 'options' => ['verde' => ['nome' => 'Verde pastel', 'descricao' => 'Tema atual do Green Portal'], 'azul' => ['nome' => 'Azul pastel', 'descricao' => 'Visual suave em tons de azul'], 'cinza' => ['nome' => 'Cinza pastel', 'descricao' => 'Visual neutro e discreto']]], 'en' => ['title' => 'Portal color palette', 'save' => 'Save', 'success' => 'Layout saved successfully.', 'options' => ['verde' => ['nome' => 'Pastel green', 'descricao' => 'Current Green Portal theme'], 'azul' => ['nome' => 'Pastel blue', 'descricao' => 'Soft blue visual'], 'cinza' => ['nome' => 'Pastel gray', 'descricao' => 'Neutral and discreet visual']]], 'es' => ['title' => 'Paleta de colores del portal', 'save' => 'Guardar', 'success' => 'Diseño guardado correctamente.', 'options' => ['verde' => ['nome' => 'Verde pastel', 'descricao' => 'Tema actual de Green Portal'], 'azul' => ['nome' => 'Azul pastel', 'descricao' => 'Visual suave en tonos azules'], 'cinza' => ['nome' => 'Gris pastel', 'descricao' => 'Visual neutro y discreto']]]][$idioma];
$colors = ['verde' => '#3ba179', 'azul' => '#5b8fc4', 'cinza' => '#7f8d96'];
$options = $translations['options']; foreach ($options as $key => &$option) $option['cor'] = $colors[$key]; unset($option);
$userId = (int)$_SESSION['usuario']['codusuario'];
$companyRows = layoutCall($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,10,0)', ['tabela' => 'vw_setup_empresa_grid', 'filtros' => layoutJson(['chave' => 'layout_tema', 'idioma' => 'pt'])]);
$userRows = layoutCall($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'preferencias_usuario', 'filtros' => layoutJson(['codusuario' => $userId, 'chave' => 'layout_tema'])]);
$current = in_array((string)($userRows[0]['valor'] ?? ''), array_keys($options), true) ? (string)$userRows[0]['valor'] : (in_array((string)($companyRows[0]['valor'] ?? ''), array_keys($options), true) ? (string)$companyRows[0]['valor'] : 'verde');
$startRows = layoutCall($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'preferencias_usuario', 'filtros' => layoutJson(['codusuario' => $userId, 'chave' => 'default_transacao'])]);
$currentStart = (string)($startRows[0]['valor'] ?? 'menu-dashboard');
$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected = (string)($_POST['layout_tema'] ?? 'verde');
    if (!array_key_exists($selected, $options)) $selected = 'verde';
    $data = ['codusuario' => $userId, 'chave' => 'layout_tema', 'valor' => $selected];
    if ($userRows) layoutCall($pdo, 'CALL sp_crud_update(:tabela,:chave,:dados)', ['tabela' => 'preferencias_usuario', 'chave' => layoutJson(['codpreferenciausuario' => (int)$userRows[0]['codpreferenciausuario']]), 'dados' => layoutJson($data)]);
    else layoutCall($pdo, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'preferencias_usuario', 'dados' => layoutJson($data)]);
    // Tela inicial (id da transação no menu, ex.: menu-dashboard)
    $start = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['default_transacao'] ?? '')) ?: 'menu-dashboard';
    $startData = ['codusuario' => $userId, 'chave' => 'default_transacao', 'valor' => $start];
    if ($startRows) layoutCall($pdo, 'CALL sp_crud_update(:tabela,:chave,:dados)', ['tabela' => 'preferencias_usuario', 'chave' => layoutJson(['codpreferenciausuario' => (int)$startRows[0]['codpreferenciausuario']]), 'dados' => layoutJson($startData)]);
    else layoutCall($pdo, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'preferencias_usuario', 'dados' => layoutJson($startData)]);
    $currentStart = $start;
    // Padrão da empresa: só quando marcado e o perfil pode editar SETUP_LAYOUT
    $companyTheme = $companyRows[0] ?? null;
    if (!$layoutPodeEmpresa || empty($_POST['aplicar_empresa'])) $companyTheme = false;
    if ($companyTheme === false) { /* só o layout pessoal */ }
    elseif ($companyTheme) layoutCall($pdo, 'CALL sp_crud_update(:tabela,:chave,:dados)', ['tabela' => 'setup_empresa', 'chave' => layoutJson(['codsetupempresa' => (int)$companyTheme['codsetupempresa']]), 'dados' => layoutJson(['valor' => $selected, 'idioma' => 'pt'])]);
    else layoutCall($pdo, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'setup_empresa', 'dados' => layoutJson(['chave' => 'layout_tema', 'valor' => $selected, 'idioma' => 'pt'])]);
    $current = $selected; $message = $translations['success'];
}
?>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet">
<link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<link href="../assets/css/app.css" rel="stylesheet"><link href="../assets/css/main.css?v=18" rel="stylesheet"><link href="../assets/css/design-system.css?v=3" rel="stylesheet"><link href="../assets/css/botoes-crud.css?v=4" rel="stylesheet"><link href="../assets/css/layout-estrutura.css?v=3" rel="stylesheet">
<section class="setup-fragment setup-layout-fragment">
    <?php $gpAjuda = class_exists('IdiomaService') ? IdiomaService::t('comum.botao.ajuda', ['pt' => 'Ajuda', 'en' => 'Help', 'es' => 'Ayuda']) : 'Ajuda'; $gpSalvar = class_exists('IdiomaService') ? IdiomaService::t('comum.botao.salvar', ['pt' => 'Salvar', 'en' => 'Save', 'es' => 'Guardar']) : 'Salvar'; ?><div class="crud-toolbar menu-grid-toolbar"><div class="crud-actions crud-actions-standard"><button class="btn btn-sm btn-success grid-crud-button grid-icon-button" type="submit" form="setupLayoutForm" data-crud-action="save"<?php echo $layoutPodeEditar ? '' : ' disabled'; ?> title="<?php echo htmlspecialchars($translations['save'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($translations['save'], ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-check2"></i></button><button class="btn btn-sm btn-outline-secondary grid-crud-button grid-icon-button" type="button" data-layout-view title="<?php echo htmlspecialchars($viewLabel, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($viewLabel, ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-eye"></i></button><button class="btn btn-sm btn-outline-info grid-crud-button grid-icon-button" type="button" data-open-transacao-ajuda data-help-transaction="setup_layout" title="<?php echo htmlspecialchars($gpAjuda, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($gpAjuda, ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-question-circle"></i></button><script src="../assets/js/ajuda-transacao.js?v=1"></script></div></div>
    <?php if ($message): ?><div class="alert alert-success py-2" role="alert"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <form method="post" id="setupLayoutForm" class="setup-form">
        <div class="setup-card"><div class="setup-fields"><div class="setup-field setup-field-wide"><label><?php echo htmlspecialchars($translations['title'], ENT_QUOTES, 'UTF-8'); ?></label><div class="layout-theme-options">
        <?php foreach ($options as $key => $option): ?><label class="layout-theme-option theme-<?php echo $key; ?> <?php echo $current === $key ? 'selected' : ''; ?>"><input type="radio" name="layout_tema" value="<?php echo $key; ?>" <?php echo $current === $key ? 'checked' : ''; ?>><span class="layout-theme-swatch" style="--theme-swatch:<?php echo $option['cor']; ?>"></span><span><strong><?php echo htmlspecialchars($option['nome'], ENT_QUOTES, 'UTF-8'); ?></strong><small><?php echo htmlspecialchars($option['descricao'], ENT_QUOTES, 'UTF-8'); ?></small></span></label><?php endforeach; ?>
        </div></div>
        <div class="setup-field setup-field-wide"><label for="layoutInicio"><?php echo htmlspecialchars($textosPessoais['inicio'], ENT_QUOTES, 'UTF-8'); ?></label>
            <select class="form-select form-select-sm" id="layoutInicio" name="default_transacao" data-atual="<?php echo htmlspecialchars($currentStart, ENT_QUOTES, 'UTF-8'); ?>" style="max-width:420px"><option value="menu-dashboard"><?php echo htmlspecialchars($textosPessoais['dashboard'], ENT_QUOTES, 'UTF-8'); ?></option><?php if ($currentStart !== 'menu-dashboard'): ?><option value="<?php echo htmlspecialchars($currentStart, ENT_QUOTES, 'UTF-8'); ?>" selected><?php echo htmlspecialchars($currentStart, ENT_QUOTES, 'UTF-8'); ?></option><?php endif; ?></select>
            <small class="text-muted d-block mt-1"><?php echo htmlspecialchars($textosPessoais['inicio_ajuda'], ENT_QUOTES, 'UTF-8'); ?></small></div>
        <?php if ($layoutPodeEmpresa): ?><div class="setup-field setup-field-wide"><label class="form-check-label d-flex gap-2 align-items-center" style="font-weight:400"><input class="form-check-input mt-0" type="checkbox" name="aplicar_empresa" value="1" style="width:16px;height:16px;flex:none;padding:0"> <span><?php echo htmlspecialchars($textosPessoais['empresa'], ENT_QUOTES, 'UTF-8'); ?></span></label></div><?php endif; ?>
        <div class="setup-field setup-field-wide"><small class="text-muted"><i class="bi bi-person me-1"></i><?php echo htmlspecialchars($textosPessoais['dica'], ENT_QUOTES, 'UTF-8'); ?></small></div>
        </div></div>
    </form>
</section>
<div class="modal fade" id="layoutViewModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">View</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"><div class="layout-preview theme-<?php echo htmlspecialchars($current, ENT_QUOTES, 'UTF-8'); ?>"><div class="layout-preview-header"><strong><i class="bi bi-leaf me-1"></i>Green Portal</strong><span><i class="bi bi-bell me-2"></i>Administrador</span></div><div class="layout-preview-body"><aside><div class="preview-menu-title"><i class="bi bi-layout-sidebar-inset"></i> Menu</div><button class="active"><i class="bi bi-speedometer2"></i> Dashboard</button><button><i class="bi bi-people"></i> Usuários</button><button><i class="bi bi-table"></i> Grid</button></aside><main><div class="preview-tabs"><span class="active">Dashboard</span><span>Grid exemplo</span></div><section class="preview-dashboard"><h5>Dashboard</h5><div class="preview-cards"><div><small>Registros</small><strong>1.248</strong></div><div><small>Ativos</small><strong>982</strong></div><div><small>Alertas</small><strong>12</strong></div></div><div class="preview-grid"><div class="preview-grid-toolbar"><strong>Grid exemplo</strong><button class="btn btn-sm btn-success">Salvar</button><button class="btn btn-sm btn-outline-success">Novo</button><button class="btn btn-sm btn-primary">Exportar</button></div><table><thead><tr><th>Código</th><th>Nome</th><th>Status</th></tr></thead><tbody><tr><td>001</td><td>Registro demonstrativo</td><td>Ativo</td></tr><tr><td>002</td><td>Outro registro</td><td>Ativo</td></tr></tbody></table></div></section></main></div><footer>Green Portal - Todos os direitos reservados.</footer></div></div></div></div></div>
<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script>
<script>
/* Tela inicial: as transações do menu do portal (as que o perfil vê). Ao salvar, aplica o tema e a tela inicial na página principal. */
(function(){var sel=document.getElementById('layoutInicio');if(!sel)return;var atual=sel.getAttribute('data-atual')||'menu-dashboard',host=null;try{host=window.top&&window.top.document?window.top:null;}catch(e){}
if(host){var vistos={'menu-dashboard':1};var itens=[];host.document.querySelectorAll('[data-open-tab]').forEach(function(b){var id=String(b.getAttribute('data-open-tab')||''),t=String(b.getAttribute('data-tab-title')||b.textContent||'').trim();if(!id||id==='home'||vistos[id]||!t)return;vistos[id]=1;
 var caminho=[],sm=b.closest('.portal-submenu');while(sm){var pai=sm.previousElementSibling;if(pai&&pai.hasAttribute('data-menu-parent')){var sp=pai.querySelector('span');if(sp)caminho.unshift(sp.textContent.trim());}sm=sm.parentElement?sm.parentElement.closest('.portal-submenu'):null;}
 caminho.push(t);itens.push([id,caminho.join(' › ')]);});
 itens.sort(function(a,b){return a[1].localeCompare(b[1]);});Array.prototype.slice.call(sel.options).forEach(function(o){if(o.value!=='menu-dashboard')o.remove();});itens.forEach(function(i){var o=document.createElement('option');o.value=i[0];o.textContent=i[1];sel.appendChild(o);});}
sel.value=atual;if(sel.value!==atual)sel.value='menu-dashboard';}());
<?php if ($message !== null): ?>try{var w=window.top||window;w.postMessage({type:'greenportal-account-theme',theme:<?php echo json_encode($current); ?>},window.location.origin);w.postMessage({type:'greenportal-account-default-transaction',transacao:<?php echo json_encode($currentStart); ?>},window.location.origin);}catch(e){}<?php endif; ?>
</script>
<script>document.querySelectorAll('.layout-theme-option input').forEach(function(input){input.addEventListener('change',function(){document.querySelectorAll('.layout-theme-option').forEach(function(item){item.classList.remove('selected')});input.closest('.layout-theme-option').classList.add('selected')})});document.querySelector('[data-layout-view]').addEventListener('click',function(){var selected=document.querySelector('.layout-theme-option input:checked').value;var preview=document.querySelector('.layout-preview');preview.className='layout-preview theme-'+selected;bootstrap.Modal.getOrCreateInstance(document.getElementById('layoutViewModal')).show()});</script>
<?php require_once dirname(__DIR__) . '/components/toolbar_grupos.php'; greenPortalToolbarGruposAuto(); ?>
