<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/HomologacaoFornecedorAcesso.php';
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
/*
 * Migração 297: para o fornecedor externo (FORNECEDOR / FORNECEDOR_GESTOR) a tela lista só os funcionários dos
 * fornecedores ativos ligados a ele (vw_homologacao_funcionarios_usuario_grid, filtrada pelo usuário logado), a lista de
 * fornecedores do campo Fornecedor é só a dele e cada gravação/exclusão confere no servidor se o funcionário e o
 * fornecedor são dele. O perfil FORNECEDOR não exclui funcionários (como no legado).
 * Migração 316: o interno restrito (perfis que não são ADMINISTRADOR nem ADM_DA_EMPRESA) vê os funcionários dos fornecedores
 * das suas unidades e dos fornecedores pelos quais responde (vw_homologacao_funcionarios_unidade_grid); gravação e exclusão
 * conferidas no servidor da mesma forma.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$fuExterno = HomologacaoFornecedorAcesso::externo();
$fuComum = HomologacaoFornecedorAcesso::comum();
$fuUsuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);
// Migração 316: interno restrito por unidade (só depois de aplicada a migração no banco).
$fuUnidade = false;
if (!$fuExterno && HomologacaoFornecedorAcesso::unidade() && !empty($_SESSION['autenticado'])) {
    try { $fuUnidade = HomologacaoFornecedorAcesso::restricaoUnidadeAtiva(TenantContext::connect(GreenPortalDatabase::env(), $_SESSION)); } catch (Throwable) { $fuUnidade = false; }
}
if (($fuExterno || $fuUnidade) && !empty($_SESSION['autenticado'])) {
    $fuNegar = static function (string $msg): never {
        http_response_code(403);
        if (!empty($_POST['ajax'])) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE); }
        else echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
        exit;
    };
    try { $fuAcessoPdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION); } catch (Throwable) { http_response_code(401); exit(htmlspecialchars(IdiomaService::t('comum.erro.sessao_expirada', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.']), ENT_QUOTES, 'UTF-8')); }
    $fuVazia = $fuExterno && (int)$fuAcessoPdo->query("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vw_homologacao_funcionarios_usuario_grid'")->fetchColumn() === 0;
    if ($fuVazia) { http_response_code(503); exit(htmlspecialchars(IdiomaService::t('homologacaofornecedor_funcionario.erro.migracao', ['pt' => 'Tela indisponível: falta aplicar a migração 297.', 'en' => 'Screen unavailable: migration 297 has not been applied.', 'es' => 'Pantalla no disponible: falta aplicar la migración 297.']), ENT_QUOTES, 'UTF-8')); }
    $fuAcao = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? (string)($_POST['action'] ?? '') : '';
    if ($fuAcao === 'delete_row') {
        if ($fuComum) $fuNegar(IdiomaService::t('comum.permissao.negada', ['pt' => 'Perfil sem permissão de acesso!', 'en' => 'Profile does not have access permission!', 'es' => '¡El perfil no tiene permiso de acceso!']));
        if (!HomologacaoFornecedorAcesso::pode($fuAcessoPdo, 'funcionario', (int)($_POST['key'] ?? 0))) $fuNegar(HomologacaoFornecedorAcesso::mensagemNegado());
    } elseif ($fuAcao !== '') {
        foreach ((array)($_POST['rows'] ?? []) as $fuChave => $fuLinha) {
            $fuNovo = str_starts_with((string)$fuChave, 'novo_');
            if (!$fuNovo && !HomologacaoFornecedorAcesso::pode($fuAcessoPdo, 'funcionario', (int)$fuChave)) $fuNegar(HomologacaoFornecedorAcesso::mensagemNegado());
            $fuForn = is_array($fuLinha) ? trim((string)($fuLinha['CODFORNECEDOR'] ?? '')) : '';
            if (($fuNovo || $fuForn !== '') && !HomologacaoFornecedorAcesso::pode($fuAcessoPdo, 'fornecedor', (int)$fuForn)) $fuNegar(HomologacaoFornecedorAcesso::mensagemNegado());
        }
    }
    $fuAcessoPdo = null;
}
$u = static fn(string $s): string => (string)json_decode('"' . $s . '"');
// Dicas dos botões em Administração › Textos e idiomas (cadastro automático ao abrir como ADMINISTRADOR).
$tb = static fn(string $chave, array $padrao): string => IdiomaService::t('homologacaofornecedor_funcionario.botao.' . $chave, $padrao);
$employeeRelationButton = static function (string $id, string $url, string $title, string $icon): string {
    return '<button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-id="' . $id . '" data-context-url="/principal/' . $url . '" data-context-fields="CODFUNCIONARIO,CODTIPOFUNCIONARIO" data-context-title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" disabled title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" aria-label="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"><i class="bi ' . $icon . '"></i></button>';
};
$employeeRelations = $employeeRelationButton('criterios', 'homologacaofornecedor_funcionarioxcriterios.php', $tb('criterios', ['pt' => 'Critérios', 'en' => 'Criteria', 'es' => 'Criterios']), 'bi-file-earmark-check')
    . $employeeRelationButton('treinamentos', 'homologacaofornecedor_funcionarioxtreinamentos.php', $tb('treinamentos', ['pt' => 'Treinamentos', 'en' => 'Training', 'es' => 'Capacitaciones']), 'bi-mortarboard')
    . $employeeRelationButton('responsabilidades', 'funcionario_responsabilidades.php', $tb('responsabilidades', ['pt' => 'Responsabilidades', 'en' => 'Responsibilities', 'es' => 'Responsabilidades']), 'bi-people');

$fuCampos = static function (array $campos) use ($fuExterno, $fuUnidade, $fuUsuario): array {
    if ($fuUnidade) {
        // Interno restrito: o campo Fornecedor lista só os fornecedores que ele pode ver.
        $campos['CODFORNECEDOR']['foreign'] = ['view' => 'vw_homologacao_fornecedores_unidade_grid', 'value' => 'CODFORNECEDOR', 'label' => 'RAZAOSOCIAL', 'description' => 'RAZAOSOCIAL', 'filters' => ['CODUSUARIO_ACESSO' => $fuUsuario]];
        return $campos;
    }
    if (!$fuExterno) return $campos;
    foreach ($campos as $k => &$c) {
        // Sem listas de valores de todos os fornecedores nos filtros (as opções sairiam da view inteira).
        if (($c['filter'] ?? '') === 'select') $c['filter'] = 'text';
    }
    unset($c);
    $campos['CODFORNECEDOR']['foreign'] = ['view' => 'vw_homologacao_fornecedores_usuario_grid', 'value' => 'CODFORNECEDOR', 'label' => 'RAZAOSOCIAL', 'description' => 'RAZAOSOCIAL', 'filters' => ['CODUSUARIO_ACESSO' => $fuUsuario]];
    return $campos;
};
$fuExtra = ($fuExterno || $fuUnidade) ? ['user_scope_field' => 'CODUSUARIO_ACESSO', 'force_user_scope' => true] : [];
if ($fuComum) $fuExtra['button_options'] = ['save' => true, 'new' => true, 'duplicate' => true, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true];
// Migração 358 (Minhas atividades › Meus funcionários, principal/meus_funcionarios.php): só os funcionários dos fornecedores pelos
// quais o usuário responde e os atribuídos a ele (vw_meus_funcionarios_grid); salvar sim, incluir/duplicar/excluir não.
$fuMeus = defined('GP_MEUS_FUNCIONARIOS') && !$fuExterno;
if ($fuMeus) $fuExtra = ['user_scope_field' => 'CODUSUARIO_ACESSO', 'force_user_scope' => true,
    'button_options' => ['save' => true, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true]];
ManutencaoLegalCadastroGrid::render($fuExtra + [
    'menu' => 'HOMOLOGACAO_FORNECEDOR_FUNCIONARIO',
    'title' => $fuMeus ? ['pt' => $u('Funcion\u00e1rios de fornecedores'), 'en' => 'Supplier employees', 'es' => 'Empleados de proveedores'] : ['pt' => $u('Gest\u00e3o de Funcion\u00e1rios'), 'en' => 'Employee Management', 'es' => $u('Gesti\u00f3n de Empleados')],
    'transaction' => 'homologacaofornecedor_funcionario', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'fragment_class' => 'homologacao-funcionario-grid-fragment', 'table' => 'fo_funcionarios',
    'view' => $fuMeus ? 'vw_meus_funcionarios_grid' : ($fuExterno ? 'vw_homologacao_funcionarios_usuario_grid' : ($fuUnidade ? 'vw_homologacao_funcionarios_unidade_grid' : 'vw_homologacao_funcionarios_grid')), 'primary' => 'CODFUNCIONARIO', 'primary_auto' => true,
    'legacy_fixed' => true,
    'extras' => $employeeRelations,
    // Funcionários ligados a fornecedor ou função que não existem mais (herança do sistema antigo):
    // em vez de campos vazios, a tela informa o código que ficou sem cadastro.
    'row_enricher' => static function (array $rows, PDO $pdo): array {
        foreach ($rows as &$row) {
            $fornecedor = (int)($row['CODFORNECEDOR'] ?? 0);
            if ($fornecedor > 0 && trim((string)($row['RAZAOSOCIAL'] ?? '')) === '') {
                $row['RAZAOSOCIAL'] = IdiomaService::f('homologacaofornecedor_funcionario.aviso.fornecedor_nao_cadastrado', ['pt' => 'Fornecedor não cadastrado (código {codigo})', 'en' => 'Supplier not registered (code {codigo})', 'es' => 'Proveedor no registrado (código {codigo})'], ['codigo' => $fornecedor]);
                if (trim((string)($row['DSCUNIDADE'] ?? '')) === '') $row['DSCUNIDADE'] = '—';
            }
            $tipo = (int)($row['CODTIPOFUNCIONARIO'] ?? 0);
            if (trim((string)($row['DSCTIPO'] ?? '')) === '') {
                $row['DSCTIPO'] = $tipo > 0
                    ? IdiomaService::f('homologacaofornecedor_funcionario.aviso.funcao_nao_cadastrada', ['pt' => 'Função não cadastrada (código {codigo})', 'en' => 'Role not registered (code {codigo})', 'es' => 'Función no registrada (código {codigo})'], ['codigo' => $tipo])
                    : IdiomaService::t('homologacaofornecedor_funcionario.aviso.sem_funcao', ['pt' => 'Sem função informada', 'en' => 'No role informed', 'es' => 'Sin función informada']);
            }
        }
        unset($row);
        return $rows;
    },
    // Sem fornecedor o funcionário some da tela (a view só lista funcionários com fornecedor): Fornecedor e Nome são obrigatórios.
    // Planilha do Excel: Unidade e Razão Social no início (no lugar do código do fornecedor), nome da função,
    // quantidade de anexos do GED e datas zeradas do legado em branco.
    // Fornecedor é a primeira coluna visível do grid (Unidade, Código e Razão Social ficam ocultos).
    'legacy_order' => ['CODFORNECEDOR','DSCUNIDADE','CODFUNCIONARIO','RAZAOSOCIAL','NOME','CELULAR','TELEFONE','EMAIL','NRIDENTIFICACAO','CODTIPOFUNCIONARIO','SETOR','ANEXO','DATA_VALIDADE','DATA_DE_INICIO'],
    'fields' => $fuCampos([
        'CODFORNECEDOR' => ['label' => ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor'], 'required' => true, 'export' => false, 'filter' => 'select', 'width' => 220, 'foreign' => ['view' => 'vw_homologacao_fornecedores_grid', 'value' => 'CODFORNECEDOR', 'label' => 'RAZAOSOCIAL', 'description' => 'RAZAOSOCIAL']],
        'CODFUNCIONARIO' => ['label' => ['pt' => $u('C\u00f3digo'), 'en' => 'Code', 'es' => $u('C\u00f3digo')], 'hidden' => true, 'readonly' => true, 'filter' => 'none', 'width' => 0],
        // Unidade e Razão Social ficam ocultas na tela (há o filtro Unidade / Fornecedor), mas saem na planilha do Excel.
        'DSCUNIDADE' => ['label' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'], 'readonly' => true, 'hidden' => true, 'export' => true, 'filter' => 'none', 'width' => 180],
        'RAZAOSOCIAL' => ['label' => ['pt' => $u('Raz\u00e3o Social'), 'en' => 'Corporate Name', 'es' => $u('Raz\u00f3n Social')], 'readonly' => true, 'hidden' => true, 'export' => true, 'filter' => 'none', 'width' => 220],
        'NOME' => ['label' => ['pt' => 'Nome', 'en' => 'Name', 'es' => 'Nombre'], 'required' => true, 'filter' => 'text', 'width' => 250],
        'CELULAR' => ['label' => ['pt' => 'Celular', 'en' => 'Mobile', 'es' => 'Celular'], 'filter' => 'text', 'width' => 130],
        'TELEFONE' => ['label' => ['pt' => 'Telefone', 'en' => 'Phone', 'es' => $u('Tel\u00e9fono')], 'filter' => 'text', 'width' => 130],
        'EMAIL' => ['label' => ['pt' => 'E-mail', 'en' => 'Email', 'es' => 'Correo'], 'filter' => 'text', 'width' => 220],
        'NRIDENTIFICACAO' => ['label' => ['pt' => $u('N\u00ba de Registro'), 'en' => 'Registration No.', 'es' => $u('N.\u00ba de Registro')], 'filter' => 'text', 'width' => 150],
        'CODTIPOFUNCIONARIO' => ['label' => ['pt' => $u('Fun\u00e7\u00e3o'), 'en' => 'Role', 'es' => $u('Funci\u00f3n')], 'filter' => 'select', 'width' => 200, 'export_from' => 'DSCTIPO', 'foreign' => ['view' => 'vw_homologacao_tipos_funcionarios_grid', 'value' => 'codtipofuncionario', 'label' => 'DSCTIPO', 'description' => 'DSCTIPO']],
        'SETOR' => ['label' => ['pt' => 'Setor', 'en' => 'Department', 'es' => 'Sector'], 'filter' => 'text', 'width' => 140],
        'ANEXO' => ['label' => ['pt' => 'Anexo', 'en' => 'Attachment', 'es' => 'Anexo'], 'readonly' => true, 'export_ged' => 'fo_funcionarios', 'filter' => 'none', 'width' => 90],
        'DATA_VALIDADE' => ['label' => ['pt' => $u('Data de Realiza\u00e7\u00e3o da Integra\u00e7\u00e3o'), 'en' => 'Integration Date', 'es' => $u('Fecha de Integraci\u00f3n')], 'export_date' => true, 'filter' => 'text', 'width' => 180],
        'DATA_DE_INICIO' => ['label' => ['pt' => $u('Data de In\u00edcio'), 'en' => 'Start Date', 'es' => 'Fecha de Inicio'], 'export_date' => true, 'filter' => 'text', 'width' => 120],
    ]),
]);

// Filtro Unidade / Fornecedor, à esquerda da busca do grid. As listas vêm dos próprios funcionários
// exibidos (vw_homologacao_funcionarios_grid), então só aparecem unidades e fornecedores com funcionários.
$fuFiltroLinhas = [];
try {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
    require_once dirname(__DIR__) . '/classes/TenantContext.php';
    $fuFiltroPdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
    if ($fuExterno || $fuUnidade) {
        $fuFiltroSt = $fuFiltroPdo->prepare('SELECT DISTINCT CODUNIDADE, DSCUNIDADE, CODFORNECEDOR, RAZAOSOCIAL FROM ' . ($fuExterno ? 'vw_homologacao_funcionarios_usuario_grid' : 'vw_homologacao_funcionarios_unidade_grid') . ' WHERE CODUSUARIO_ACESSO = ?');
        $fuFiltroSt->execute([$fuUsuario]);
        $fuFiltroLinhas = $fuFiltroSt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $fuFiltroLinhas = $fuFiltroPdo->query('SELECT DISTINCT CODUNIDADE, DSCUNIDADE, CODFORNECEDOR, RAZAOSOCIAL FROM vw_homologacao_funcionarios_grid')->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $fuFiltroLinhas = []; // Antes da migração 219 a view não tem CODUNIDADE: a tela segue sem o filtro.
}
$fuUnidades = [];
$fuFornecedores = [];
foreach ($fuFiltroLinhas as $linha) {
    $codUnidade = (int)($linha['CODUNIDADE'] ?? 0);
    $codFornecedor = (int)($linha['CODFORNECEDOR'] ?? 0);
    if ($codUnidade > 0) $fuUnidades[$codUnidade] = trim((string)($linha['DSCUNIDADE'] ?? '')) ?: (string)$codUnidade;
    if ($codFornecedor > 0) $fuFornecedores[$codFornecedor] = ['v' => $codFornecedor, 'n' => trim((string)($linha['RAZAOSOCIAL'] ?? '')) ?: (string)$codFornecedor, 'u' => $codUnidade];
}
asort($fuUnidades, SORT_NATURAL | SORT_FLAG_CASE);
uasort($fuFornecedores, static fn(array $a, array $b): int => strnatcasecmp($a['n'], $b['n']));
$fuFiltrosAtuais = json_decode((string)($_GET['filtros'] ?? '{}'), true);
$fuFiltrosAtuais = is_array($fuFiltrosAtuais) ? $fuFiltrosAtuais : [];
$fuValor = static function (string $campo) use ($fuFiltrosAtuais): string { $v = $fuFiltrosAtuais[$campo] ?? ''; return (string)(is_array($v) ? ($v['value'] ?? '') : $v); };
$fuH = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$fuT = static fn(string $chave, array $padrao): string => IdiomaService::t('homologacaofornecedor_funcionario.filtro.' . $chave, $padrao);
if ($fuFiltroLinhas):
?>
<div class="fu-filtro-contexto" data-fu-filtro hidden>
    <select class="form-select form-select-sm" id="fuFiltroUnidade" data-grid-filter="CODUNIDADE" title="<?= $fuH($fuT('unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'])) ?>" aria-label="<?= $fuH($fuT('unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'])) ?>">
        <option value=""><?= $fuH($fuT('todas_unidades', ['pt' => 'Todas as unidades', 'en' => 'All units', 'es' => 'Todas las unidades'])) ?></option>
        <?php foreach ($fuUnidades as $cod => $nome): ?><option value="<?= (int)$cod ?>"<?= $fuValor('CODUNIDADE') === (string)$cod ? ' selected' : '' ?>><?= $fuH($nome) ?></option><?php endforeach; ?>
    </select>
    <select class="form-select form-select-sm" id="fuFiltroFornecedor" data-grid-filter="CODFORNECEDOR" data-valor-inicial="<?= $fuH($fuValor('CODFORNECEDOR')) ?>" title="<?= $fuH($fuT('fornecedor', ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor'])) ?>" aria-label="<?= $fuH($fuT('fornecedor', ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor'])) ?>">
        <option value=""><?= $fuH($fuT('todos_fornecedores', ['pt' => 'Todos os fornecedores', 'en' => 'All suppliers', 'es' => 'Todos los proveedores'])) ?></option>
    </select>
</div>
<style>
.fu-filtro-contexto { display: flex; gap: 6px; align-items: center; margin-left: auto; }
.fu-filtro-contexto select { width: 200px; border-color: var(--gp-border); color: var(--gp-text); background: var(--gp-surface); }
.fu-filtro-contexto + .crud-search { margin-left: 0 !important; }
@media (max-width: 900px) { .fu-filtro-contexto { flex: 1 1 100%; } .fu-filtro-contexto select { flex: 1 1 50%; width: auto; } }
</style>
<script>
(function ($) {
    'use strict';
    var fornecedores = <?= json_encode(array_values($fuFornecedores), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    $(function () {
        var $fragment = $('.homologacao-funcionario-grid-fragment').first(), $filtro = $('[data-fu-filtro]').first();
        if (!$fragment.length || !$filtro.length) return;
        var $busca = $fragment.find('.menu-grid-toolbar > .crud-search').first();
        if ($busca.length) $filtro.insertBefore($busca); else $fragment.find('.menu-grid-toolbar').first().append($filtro);
        $filtro.prop('hidden', false);
        var $unidade = $filtro.find('#fuFiltroUnidade'), $fornecedor = $filtro.find('#fuFiltroFornecedor');
        // Fornecedor depende da unidade: com unidade escolhida, lista só os fornecedores dela.
        function montarFornecedores(valor) {
            var unidade = Number($unidade.val() || 0), $primeira = $fornecedor.find('option').first().detach();
            $fornecedor.empty().append($primeira);
            fornecedores.forEach(function (f) { if (!unidade || f.u === unidade) $fornecedor.append($('<option>', { value: f.v, text: f.n })); });
            $fornecedor.val($fornecedor.find('option[value="' + valor + '"]').length ? valor : '');
        }
        montarFornecedores(String($fornecedor.attr('data-valor-inicial') || ''));
        $unidade.on('change', function () { montarFornecedores(String($fornecedor.val() || '')); });
        // Novo funcionário já vem com o fornecedor escolhido no filtro.
        $fragment.on('click', '[data-new-cadastro]', function () {
            window.setTimeout(function () {
                var valor = String($fornecedor.val() || ''); if (!valor) return;
                var $campo = $fragment.find('tbody tr[data-key^="novo_"]').first().find('[data-column="CODFORNECEDOR"]').find('select,input').first();
                if (!$campo.length) return;
                if ($campo.is('select') && !$campo.find('option[value="' + valor + '"]').length) $campo.append($('<option>', { value: valor, text: $fornecedor.find('option:selected').text() }));
                $campo.val(valor).trigger('change');
            }, 30);
        });
    });
}(jQuery));
</script>
<?php
endif;

// Coluna Anexo: botão com a quantidade de arquivos do funcionário, que abre a gestão de arquivos
// (pasta E:\projetoGreen\GED\green_<empresa>\fo_funcionarios\<codfuncionario>).
?>
<script src="../assets/js/modulo-legal-arquivos.js?v=6"></script>
<?php
// Barra de botões em grupos, no mesmo padrão da LAIA: Operações | Correlacionamentos (Avaliação fica oculta sem botões).
require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.homologacao-funcionario-grid-fragment', [
    'correlacionamentos' => ['[data-context-id="criterios"]', '[data-context-id="treinamentos"]', '[data-context-id="responsabilidades"]'],
    'avaliacao' => ['[data-context-id^="questionario-"]'],
]);
