<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/HomologacaoFornecedorAcesso.php';
/*
 * Homologação de Fornecedores › Gestão de Fornecedores (só ativos) e, pelo mesmo código, a tela
 * "Fornecedores desativados" (principal/homologacaofornecedores_fornecedoresdesativados.php define GP_FORNECEDORES_DESATIVADOS).
 * Migração 296: views vw_homologacao_fornecedores_ativos_grid / _desativados_grid; para os perfis do fornecedor externo
 * (FORNECEDOR / FORNECEDOR_GESTOR) vw_homologacao_fornecedores_usuario_grid filtrada pelo usuário logado, grade só de
 * consulta e, para FORNECEDOR, sem as abas Certificados, Auditoria, Produtos químicos, Equipamentos e Plano de ação.
 * Migração 316 (restrição por unidade, como o legado fo_fornecedor.php): ADMINISTRADOR e ADM_DA_EMPRESA veem todos; os demais
 * perfis internos veem os fornecedores das suas unidades e os fornecedores pelos quais respondem
 * (vw_homologacao_fornecedores_unidade_grid / _desativados_unidade_grid filtradas pelo usuário logado). Gravação e exclusão
 * conferidas no servidor: só fornecedores que ele vê, e a unidade gravada tem de ser uma das dele.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$desativados = defined('GP_FORNECEDORES_DESATIVADOS');
$externo = HomologacaoFornecedorAcesso::externo();
$fornecedorComum = HomologacaoFornecedorAcesso::comum();
if ($desativados && $externo) { http_response_code(403); exit(htmlspecialchars(IdiomaService::t('comum.permissao.negada', ['pt' => 'Perfil sem permissão de acesso!', 'en' => 'Profile does not have access permission!', 'es' => '¡El perfil no tiene permiso de acceso!']), ENT_QUOTES, 'UTF-8')); }
$u = static fn(string $s): string => (string)json_decode('"' . $s . '"');
$yesNo = ['Sim' => 'Sim', $u('N\u00e3o') => $u('N\u00e3o')];
// Dicas dos botões em Administração › Textos e idiomas (cadastro automático ao abrir como ADMINISTRADOR).
$tb = static fn(string $chave, array $padrao): string => IdiomaService::t('homologacaofornecedor_fornecedor.botao.' . $chave, $padrao);
$relationButton = static function (string $id, string $url, string $title, string $icon): string {
    return '<button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-id="' . $id . '" data-context-url="/principal/' . $url . '" data-context-fields="CODFORNECEDOR" data-context-title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" disabled title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" aria-label="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"><i class="bi ' . $icon . '"></i></button>';
};
$relationButtons = $relationButton('contatos', 'homologacaofornecedor_fornecedorxcontatos.php', $tb('contatos', ['pt' => 'Contatos', 'en' => 'Contacts', 'es' => 'Contactos']), 'bi-person-lines-fill')
    . str_replace('btn-outline-secondary', 'btn-outline-success', str_replace('data-context-fields="CODFORNECEDOR"', 'data-context-fields="CODFORNECEDOR,CODUNIDADE"', $relationButton('questionario', 'questionario_execucao.php', $tb('questionario', ['pt' => 'Questionário do fornecedor', 'en' => 'Supplier questionnaire', 'es' => 'Cuestionario del proveedor']), 'bi-ui-checks-grid')))
    . str_replace('btn-outline-secondary', 'btn-outline-primary', $relationButton('responsabilidades', 'fornecedor_responsabilidades.php', $tb('responsabilidades', ['pt' => 'Responsabilidades', 'en' => 'Responsibilities', 'es' => 'Responsabilidades']), 'bi-people'))
    . $relationButton('certificados', 'homologacaofornecedor_fornecedorxcertificados.php', $tb('certificados', ['pt' => 'Certificados', 'en' => 'Certificates', 'es' => 'Certificados']), 'bi-patch-check')
    . $relationButton('auditoria', 'homologacaofornecedor_fornecedorxauditoria.php', $tb('auditoria', ['pt' => 'Auditoria de homologação', 'en' => 'Approval audit', 'es' => 'Auditoría de homologación']), 'bi-clipboard-check')
    // Migração 293: Tipos do fornecedor (duas listas; vincular cria os documentos) e Documentos (aba completa, com cores, histórico e copiar/colar)
    . $relationButton('tipos', 'homologacaofornecedor_fornecedorxtipos.php', $tb('tipos', ['pt' => 'Tipos do fornecedor', 'en' => 'Supplier types', 'es' => 'Tipos del proveedor']), 'bi-diagram-3')
    . $relationButton('criterios', 'homologacaofornecedor_fornecedorxcriterios.php', $tb('documentos', ['pt' => 'Documentos', 'en' => 'Documents', 'es' => 'Documentos']), 'bi-file-earmark-check')
    . $relationButton('funcionarios', 'homologacaofornecedor_fornecedorxfuncionarios.php', $tb('funcionarios', ['pt' => 'Funcionários', 'en' => 'Employees', 'es' => 'Empleados']), 'bi-people')
    . $relationButton('produtos', 'homologacaofornecedor_fornecedorxprodutosquimicos.php', $tb('produtos', ['pt' => 'Produtos químicos', 'en' => 'Chemical products', 'es' => 'Productos químicos']), 'bi-droplet')
    . $relationButton('equipamentos', 'homologacaofornecedor_fornecedorxequipamentos.php', $tb('equipamentos', ['pt' => 'Equipamentos', 'en' => 'Equipment', 'es' => 'Equipos']), 'bi-tools')
    . $relationButton('plano', 'homologacaofornecedor_fornecedorxplanodeacao.php', $tb('plano', ['pt' => 'Plano de ação', 'en' => 'Action plan', 'es' => 'Plan de acción']), 'bi-list-check')
    // Migração 296: Fornecedor x Usuários (quem responde pelo fornecedor; só para a empresa)
    . ($externo ? '' : str_replace('btn-outline-secondary', 'btn-outline-primary', $relationButton('usuarios', 'homologacaofornecedor_fornecedorxusuarios.php', $tb('usuarios', ['pt' => 'Usuários do fornecedor', 'en' => 'Supplier users', 'es' => 'Usuarios del proveedor']), 'bi-person-badge')));
if ($fornecedorComum) {
    // Perfil FORNECEDOR: sem Certificados, Auditoria, Produtos químicos, Equipamentos e Plano de ação (também bloqueados no servidor).
    foreach (['certificados', 'auditoria', 'produtos', 'equipamentos', 'plano'] as $oculta)
        $relationButtons = (string)preg_replace('#<button[^>]*data-context-id="' . $oculta . '"[^>]*>.*?</button>#s', '', $relationButtons);
}

// Ranking de fornecedores (migração 289): faixa, nota e bloqueio no grid, só depois que a migração cria as colunas da view.
$rankingNoGrid = false;
$temViews296 = false;
try {
    $rkPdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
    $temViews296 = (int)$rkPdo->query("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('vw_homologacao_fornecedores_ativos_grid', 'vw_homologacao_fornecedores_desativados_grid', 'vw_homologacao_fornecedores_usuario_grid')")->fetchColumn() === 3;
    $rkView = $temViews296 ? ($desativados ? 'vw_homologacao_fornecedores_desativados_grid' : 'vw_homologacao_fornecedores_ativos_grid') : 'vw_homologacao_fornecedores_grid';
    // Migração 316: interno restrito por unidade (só depois de aplicada a migração no banco).
    $unidadeRestrita = $temViews296 && !$externo && HomologacaoFornecedorAcesso::unidade() && HomologacaoFornecedorAcesso::restricaoUnidadeAtiva($rkPdo);
    if ($unidadeRestrita && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($_SESSION['autenticado'])) {
        $negarUnidade = static function (string $msg): never {
            http_response_code(403);
            if (!empty($_POST['ajax'])) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE); }
            else echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
            exit;
        };
        $acaoPost = (string)($_POST['action'] ?? '');
        $usuarioLogado = (int)($_SESSION['usuario']['codusuario'] ?? 0);
        $unidadeDoUsuario = $rkPdo->prepare('SELECT COUNT(*) FROM vw_homologacao_unidades_usuario_grid WHERE CODUSUARIO_ACESSO = ? AND CODUNIDADE = ?');
        if ($acaoPost === 'delete_row') {
            if (!HomologacaoFornecedorAcesso::pode($rkPdo, 'fornecedor', (int)($_POST['key'] ?? 0))) $negarUnidade(HomologacaoFornecedorAcesso::mensagemNegado());
        } elseif ($acaoPost !== '') {
            foreach ((array)($_POST['rows'] ?? []) as $chave => $linha) {
                $novo = str_starts_with((string)$chave, 'novo_');
                if (!$novo && !HomologacaoFornecedorAcesso::pode($rkPdo, 'fornecedor', (int)$chave)) $negarUnidade(HomologacaoFornecedorAcesso::mensagemNegado());
                $unidadeLinha = is_array($linha) && array_key_exists('CODUNIDADE', $linha) ? trim((string)$linha['CODUNIDADE']) : null;
                if ($novo || ($unidadeLinha !== null && $unidadeLinha !== '')) {
                    $unidadeDoUsuario->execute([$usuarioLogado, (int)$unidadeLinha]);
                    $permitida = (int)$unidadeDoUsuario->fetchColumn() > 0;
                    $unidadeDoUsuario->closeCursor();
                    if (!$permitida) $negarUnidade(IdiomaService::t('homologacaofornecedor_fornecedor.erro.unidade_nao_permitida', ['pt' => 'A unidade informada não é uma das suas unidades.', 'en' => 'The unit entered is not one of your units.', 'es' => 'La unidad informada no es una de sus unidades.']));
                }
            }
        }
    }
    $rkSt = $rkPdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'RANKING_FAIXA'");
    $rkSt->execute([$rkView]);
    $rankingNoGrid = (int)$rkSt->fetchColumn() > 0;
    $rkPdo = null;
} catch (Throwable $e) { $rankingNoGrid = false; $unidadeRestrita = $unidadeRestrita ?? false; }
$unidadeRestrita = $unidadeRestrita ?? false;
// O ranking é interno: não aparece para o fornecedor externo.
if ($externo) $rankingNoGrid = false;
if (!$temViews296 && ($desativados || $externo)) { http_response_code(503); exit(htmlspecialchars(IdiomaService::t('homologacaofornecedor_fornecedor.erro.migracao_296', ['pt' => 'Tela indisponível: falta aplicar a migração 296.', 'en' => 'Screen unavailable: migration 296 has not been applied.', 'es' => 'Pantalla no disponible: falta aplicar la migración 296.']), ENT_QUOTES, 'UTF-8')); }
$rankingCampos = static function (array $campos) use ($rankingNoGrid, $yesNo, $u): array {
    if (!$rankingNoGrid) return $campos;
    $novo = [];
    foreach ($campos as $k => $c) {
        $novo[$k] = $c;
        if ($k === 'NOMEFANTASIA') {
            $novo['RANKING_FAIXA'] = ['label' => ['pt' => 'Faixa no ranking', 'en' => 'Ranking band', 'es' => 'Franja en el ranking'], 'readonly' => true, 'options' => ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D'], 'filter' => 'select', 'width' => 110];
            $novo['RANKING_NOTA'] = ['label' => ['pt' => 'Nota no ranking', 'en' => 'Ranking score', 'es' => 'Nota en el ranking'], 'readonly' => true, 'filter' => 'text', 'width' => 110];
            $novo['BLOQUEADO_RANKING'] = ['label' => ['pt' => 'Bloqueado?', 'en' => 'Blocked?', 'es' => $u('\u00bfBloqueado?')], 'readonly' => true, 'options' => $yesNo, 'filter' => 'select', 'width' => 100];
            $novo['MOTIVO_BLOQUEIO'] = ['label' => ['pt' => 'Motivo do bloqueio', 'en' => 'Block reason', 'es' => 'Motivo del bloqueo'], 'readonly' => true, 'filter' => 'text', 'width' => 260];
        }
    }
    return $novo;
};
$rankingOrdem = static function (array $ordem) use ($rankingNoGrid): array {
    if (!$rankingNoGrid) return $ordem;
    $novo = [];
    foreach ($ordem as $c) { $novo[] = $c; if ($c === 'NOMEFANTASIA') array_push($novo, 'RANKING_FAIXA', 'RANKING_NOTA', 'BLOQUEADO_RANKING', 'MOTIVO_BLOQUEIO'); }
    return $novo;
};

// Fornecedor externo: só consulta; colunas de usuário e unidade pelo nome (sem as listas de usuários e unidades da empresa).
$externoCampos = static function (array $campos) use ($externo): array {
    if (!$externo) return $campos;
    $troca = ['CODUNIDADE' => ['DSCUNIDADE', 'Unidade', 'Unit', 'Unidad'], 'CODUSUARIO_RESPONSAVEL_FORNECEDOR' => ['RESPONSAVEL_FORNECEDOR'], 'CODUSUARIO_RESPONSAVEL_INTEGRACAO' => ['RESPONSAVEL_INTEGRACAO']];
    $novo = [];
    foreach ($campos as $k => $c) {
        if ($k === 'ANEXO') continue;
        unset($c['foreign']);
        $c['readonly'] = true;
        if (($c['filter'] ?? '') === 'select') { $c['filter'] = 'text'; unset($c['options']); }
        if (isset($troca[$k])) { $novo[$troca[$k][0]] = $c; continue; }
        $novo[$k] = $c;
    }
    return $novo;
};
$externoOrdem = static function (array $ordem) use ($externo): array {
    if (!$externo) return $ordem;
    $troca = ['CODUNIDADE' => 'DSCUNIDADE', 'CODUSUARIO_RESPONSAVEL_FORNECEDOR' => 'RESPONSAVEL_FORNECEDOR', 'CODUSUARIO_RESPONSAVEL_INTEGRACAO' => 'RESPONSAVEL_INTEGRACAO'];
    return array_values(array_map(static fn($c) => $troca[$c] ?? $c, array_filter($ordem, static fn($c) => $c !== 'ANEXO')));
};
$gestaoView = !$temViews296 ? 'vw_homologacao_fornecedores_grid'
    : ($desativados ? ($unidadeRestrita ? 'vw_homologacao_fornecedores_desativados_unidade_grid' : 'vw_homologacao_fornecedores_desativados_grid')
        : ($externo ? 'vw_homologacao_fornecedores_usuario_grid' : ($unidadeRestrita ? 'vw_homologacao_fornecedores_unidade_grid' : 'vw_homologacao_fornecedores_ativos_grid')));
$gestaoExtra = $externo
    ? ['read_only_grid' => true, 'user_scope_field' => 'CODUSUARIO_ACESSO', 'force_user_scope' => true,
       'button_options' => ['save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true]]
    : ($unidadeRestrita ? ['user_scope_field' => 'CODUSUARIO_ACESSO', 'force_user_scope' => true] : []);
// Migração 358 (Minhas atividades › Meus fornecedores, principal/meus_fornecedores.php): só os fornecedores pelos quais o usuário
// responde (vw_meus_fornecedores_grid, CODUSUARIO_ACESSO = quem responde); salvar sim, incluir/duplicar/excluir não.
$meusFornecedores = defined('GP_MEUS_FORNECEDORES') && !$desativados && !$externo;
if ($meusFornecedores) {
    $gestaoView = 'vw_meus_fornecedores_grid';
    $gestaoExtra = ['user_scope_field' => 'CODUSUARIO_ACESSO', 'force_user_scope' => true,
        'button_options' => ['save' => true, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true]];
}
// Interno restrito: a lista de unidades do campo Unidade é só a dele.
$unidadeCampos = static function (array $campos) use ($unidadeRestrita): array {
    if (!$unidadeRestrita || !isset($campos['CODUNIDADE']['foreign'])) return $campos;
    $campos['CODUNIDADE']['foreign'] = ['view' => 'vw_homologacao_unidades_usuario_grid', 'value' => 'CODUNIDADE', 'label' => 'DSCUNIDADE', 'description' => 'DSCUNIDADE', 'filters' => ['CODUSUARIO_ACESSO' => (int)($_SESSION['usuario']['codusuario'] ?? 0)]];
    return $campos;
};
ManutencaoLegalCadastroGrid::render($gestaoExtra + [
    // Migração 341: Severidade × Probabilidade = Risco do item (obrigatório ao incluir; ver Ajuda de Requisitos legais)
    'risk_area' => $externo ? '' : 'HOMOLOGACAO',
    'menu' => $desativados ? 'HOMOLOGACAO_FORNECEDORES_DESATIVADOS' : 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR',
    'title' => $meusFornecedores ? ['pt' => 'Fornecedores', 'en' => 'Suppliers', 'es' => 'Proveedores'] : ($desativados ? ['pt' => 'Fornecedores desativados', 'en' => 'Deactivated suppliers', 'es' => 'Proveedores desactivados']
        : ['pt' => $u('Gest\u00e3o de Fornecedores'), 'en' => 'Supplier Management', 'es' => $u('Gesti\u00f3n de Proveedores')]),
    'transaction' => $desativados ? 'homologacaofornecedores_fornecedoresdesativados' : 'homologacaofornecedor_fornecedor', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'fragment_class' => 'homologacao-fornecedor-grid-fragment', 'table' => 'fo_fornecedores',
    'view' => $gestaoView, 'primary' => 'CODFORNECEDOR', 'primary_auto' => true,
    'legacy_fixed' => true,
    'legacy_order' => $externoOrdem($rankingOrdem(['CODUNIDADE','CODFORNECEDOR','CNPJ_CPF','RAZAOSOCIAL','NOMEFANTASIA','TIPOFORNECEDOR','ATIVADOFOR','CODUSUARIO_RESPONSAVEL_FORNECEDOR','TRABALHA_INSTALACAO_FORNECEDOR','TEM_PRODUTOS_QUIMICOS','TEM_EQUIPAMENTOS','CODUSUARIO_RESPONSAVEL_INTEGRACAO','NIVEL_DE_SERVICO','DATA_DA_AVALIACAO','DATA_DA_PROXIMA_AVALIACAO','ANEXO','ENDERECO','CIDADE','ESTADO','CEP','EMAILS'])),
    'extras' => $relationButtons,
    'fields' => $unidadeCampos($externoCampos($rankingCampos([
        'CODFORNECEDOR' => ['label' => ['pt' => $u('C\u00f3digo'), 'en' => 'Code', 'es' => $u('C\u00f3digo')], 'hidden' => true, 'readonly' => true, 'filter' => 'none', 'width' => 0],
        'CODUNIDADE' => ['label' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'], 'filter' => 'select', 'width' => 150, 'foreign' => ['view' => 'vw_unidades_grid', 'value' => 'CODUNIDADE', 'label' => 'DSCUNIDADE', 'description' => 'DSCUNIDADE']],
        'CNPJ_CPF' => ['label' => ['pt' => 'CNPJ/CPF', 'en' => 'CNPJ/CPF', 'es' => 'CNPJ/CPF'], 'filter' => 'text', 'width' => 120],
        'RAZAOSOCIAL' => ['label' => ['pt' => $u('Raz\u00e3o Social'), 'en' => 'Corporate Name', 'es' => $u('Raz\u00f3n Social')], 'filter' => 'text', 'width' => 220],
        'NOMEFANTASIA' => ['label' => ['pt' => 'Nome Fantasia', 'en' => 'Trade Name', 'es' => 'Nombre Comercial'], 'filter' => 'text', 'width' => 200],
        'TIPOFORNECEDOR' => ['label' => ['pt' => 'Tipo de Fornecedor', 'en' => 'Supplier Type', 'es' => 'Tipo de Proveedor'], 'readonly' => true, 'filter' => 'select', 'width' => 160],
        'ATIVADOFOR' => ['label' => ['pt' => 'Ativado?', 'en' => 'Activated?', 'es' => $u('\u00bfActivado?')], 'options' => $desativados ? ['' => ''] + $yesNo : $yesNo, 'filter' => 'select', 'width' => 90], // desativados: vazio aparece vazio (e não como Sim)
        'CODUSUARIO_RESPONSAVEL_FORNECEDOR' => ['label' => ['pt' => $u('Respons\u00e1vel pelo Fornecedor'), 'en' => 'Supplier Responsible', 'es' => 'Responsable del Proveedor'], 'filter' => 'select', 'width' => 180, 'foreign' => ['view' => 'vw_usuarios_grid', 'value' => 'codusuario', 'label' => 'nome', 'filters' => ['ativo' => 1], 'description' => 'RESPONSAVEL_FORNECEDOR']],
        'TRABALHA_INSTALACAO_FORNECEDOR' => ['label' => ['pt' => $u('Trabalha nas Instala\u00e7\u00f5es?'), 'en' => 'Works at Facilities?', 'es' => $u('\u00bfTrabaja en Instalaciones?')], 'options' => $yesNo, 'filter' => 'select', 'width' => 150],
        'TEM_PRODUTOS_QUIMICOS' => ['label' => ['pt' => $u('Ter\u00e1 Produtos Qu\u00edmicos?'), 'en' => 'Will Have Chemicals?', 'es' => $u('\u00bfTendr\u00e1 Productos Qu\u00edmicos?')], 'options' => $yesNo, 'filter' => 'select', 'width' => 150],
        'TEM_EQUIPAMENTOS' => ['label' => ['pt' => $u('Ter\u00e1 Equipamentos?'), 'en' => 'Will Have Equipment?', 'es' => $u('\u00bfTendr\u00e1 Equipos?')], 'options' => $yesNo, 'filter' => 'select', 'width' => 140],
        'CODUSUARIO_RESPONSAVEL_INTEGRACAO' => ['label' => ['pt' => $u('Respons\u00e1vel pela Integra\u00e7\u00e3o'), 'en' => 'Integration Responsible', 'es' => $u('Responsable de Integraci\u00f3n')], 'filter' => 'select', 'width' => 180, 'foreign' => ['view' => 'vw_usuarios_grid', 'value' => 'codusuario', 'label' => 'nome', 'filters' => ['ativo' => 1], 'description' => 'RESPONSAVEL_INTEGRACAO']],
        // A view exp\u00f5e o nome normalizado, mas a tabela legada mant\u00e9m a grafia original.
        'NIVEL_DE_SERVICO' => ['label' => ['pt' => $u('Avalia\u00e7\u00e3o do N\u00edvel do Servi\u00e7o'), 'en' => 'Service Level Evaluation', 'es' => $u('Evaluaci\u00f3n del Nivel de Servicio')], 'db_column' => 'nivel_de_servicao', 'filter' => 'select', 'width' => 180],
        'DATA_DA_AVALIACAO' => ['label' => ['pt' => $u('Data da Avalia\u00e7\u00e3o'), 'en' => 'Evaluation Date', 'es' => $u('Fecha de Evaluaci\u00f3n')], 'type' => 'date', 'display_format' => 'date', 'filter' => 'text', 'width' => 130],
        'DATA_DA_PROXIMA_AVALIACAO' => ['label' => ['pt' => $u('Data da Pr\u00f3xima Avalia\u00e7\u00e3o'), 'en' => 'Next Evaluation Date', 'es' => $u('Pr\u00f3xima Evaluaci\u00f3n')], 'type' => 'date', 'display_format' => 'date', 'filter' => 'text', 'width' => 150],
        'ANEXO' => ['label' => ['pt' => $u('Evid\u00eancia N\u00edvel Servi\u00e7o'), 'en' => 'Service Evidence', 'es' => 'Evidencia del Servicio'], 'readonly' => true, 'filter' => 'none', 'width' => 160],
        'ENDERECO' => ['label' => ['pt' => $u('Endere\u00e7o'), 'en' => 'Address', 'es' => $u('Direcci\u00f3n')], 'type' => 'textarea', 'filter' => 'text', 'width' => 220],
        'CIDADE' => ['label' => ['pt' => 'Cidade', 'en' => 'City', 'es' => 'Ciudad'], 'filter' => 'text', 'width' => 120],
        'ESTADO' => ['label' => ['pt' => 'Estado', 'en' => 'State', 'es' => 'Estado'], 'filter' => 'text', 'width' => 80],
        'CEP' => ['label' => ['pt' => 'CEP', 'en' => 'ZIP Code', 'es' => $u('C\u00f3digo Postal')], 'filter' => 'text', 'width' => 100],
        'EMAILS' => ['label' => ['pt' => 'E-mails', 'en' => 'Emails', 'es' => 'Correos'], 'filter' => 'text', 'width' => 180],
    ]))),
]);
if ($rankingNoGrid): ?>
<style>.homologacao-fornecedor-grid-fragment tr.fornecedor-bloqueado:not(.selected) > td{background-color:#fdf0ef!important}.homologacao-fornecedor-grid-fragment tr.fornecedor-bloqueado > td:first-child{box-shadow:inset 4px 0 0 #c0392b}
.homologacao-fornecedor-grid-fragment tr.fornecedor-bloqueado:not(.selected) .grid-input:not(:focus){background-color:transparent!important}</style>
<script>
// Fornecedor bloqueado no Ranking (Administração › Pesos do ranking): linha em vermelho suave, com o motivo na dica
(function () {
    function marcar() {
        document.querySelectorAll('.homologacao-fornecedor-grid-fragment tbody tr').forEach(function (tr) {
            var b = tr.querySelector('[data-column="BLOQUEADO_RANKING"] input'), m = tr.querySelector('[data-column="MOTIVO_BLOQUEIO"] input');
            var bloq = !!b && String(b.value).trim().toLowerCase() === 'sim';
            tr.classList.toggle('fornecedor-bloqueado', bloq);
            if (bloq && m) tr.title = m.value; else if (tr.classList.contains('fornecedor-bloqueado') === false) tr.removeAttribute('title');
        });
    }
    var f = document.querySelector('.homologacao-fornecedor-grid-fragment');
    if (!f) return;
    marcar();
    if (window.MutationObserver) new MutationObserver(function () { window.clearTimeout(marcar.t); marcar.t = window.setTimeout(marcar, 80); }).observe(f, { childList: true, subtree: true });
}());
</script>
<?php endif;

// Barra de botões em grupos, no mesmo padrão da LAIA: Operações | Correlacionamentos | Avaliação.
require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.homologacao-fornecedor-grid-fragment', [
    'correlacionamentos' => ['[data-context-id="usuarios"]', '[data-context-id="contatos"]', '[data-context-id="certificados"]', '[data-context-id="tipos"]', '[data-context-id="criterios"]', '[data-context-id="funcionarios"]', '[data-context-id="produtos"]', '[data-context-id="equipamentos"]', '[data-context-id="responsabilidades"]'],
    'avaliacao' => ['[data-context-id="questionario"]', '[data-context-id="auditoria"]', '[data-context-id="plano"]', '[data-context-id^="questionario-"]'],
]);
?>
<script src="../assets/js/associacoes-contagem.js?v=6"></script>
