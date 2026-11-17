<?php
declare(strict_types=1);

/*
 * Dicionário da pesquisa — menu BUSCA_DICIONARIO (Administração). Termos do negócio que a Pesquisa de documentos entende:
 * sigla / termo, significado, sinônimos, se é sigla (palavra inteira) e o contexto (tipos onde é mais relevante).
 * Base comum (guniversal_dbp, todas as empresas — só ADMINISTRADOR altera) + termos da empresa. Sugestões da carga inicial e
 * do serviço de indexação são revisadas aqui (Aprovar / Rejeitar); a pesquisa usa só os aprovados.
 * Dados: principal/api/busca_dicionario.php → sp_busca_dicionario_listar / _gravar / _status / _excluir (migração 264).
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: text/html; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$falhar = static function (int $codigo, string $mensagem): never { http_response_code($codigo); echo htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8'); exit; };
try {
    $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
    if (!UsuarioSessao::atualizar($pdo, $_SESSION)) throw new RuntimeException('sessao');
} catch (Throwable) {
    $falhar(401, IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.']));
}
$permissao = PermissaoTransacao::obter('BUSCA_DICIONARIO');
if (empty($permissao['pode_visualizar'])) $falhar(403, IdiomaService::t('comum.erro.sem_permissao', ['pt' => 'Seu perfil não tem permissão para acessar esta transação.', 'en' => 'Your profile is not allowed to access this transaction.', 'es' => 'Su perfil no tiene permiso para acceder a esta transacción.']));
$admin = strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) === 'ADMINISTRADOR';
if (empty($_SESSION['busca_dicionario_csrf'])) $_SESSION['busca_dicionario_csrf'] = bin2hex(random_bytes(32));

$t = static fn(string $chave, array $padrao): string => IdiomaService::t('busca_dicionario.' . $chave, $padrao);
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$T = [
    'titulo' => $t('titulo', ['pt' => 'Dicionário da pesquisa', 'en' => 'Search dictionary', 'es' => 'Diccionario de la búsqueda']),
    'novo' => $t('novo', ['pt' => 'Novo termo', 'en' => 'New term', 'es' => 'Nuevo término']),
    'aprovar' => $t('aprovar', ['pt' => 'Aprovar', 'en' => 'Approve', 'es' => 'Aprobar']),
    'rejeitar' => $t('rejeitar', ['pt' => 'Rejeitar', 'en' => 'Reject', 'es' => 'Rechazar']),
    'sugestao_voltar' => $t('sugestao_voltar', ['pt' => 'Voltar para sugestão', 'en' => 'Back to suggestion', 'es' => 'Volver a sugerencia']),
    'editar' => $t('editar', ['pt' => 'Editar', 'en' => 'Edit', 'es' => 'Editar']),
    'excluir' => $t('excluir', ['pt' => 'Excluir', 'en' => 'Delete', 'es' => 'Eliminar']),
    'buscar' => $t('buscar', ['pt' => 'Buscar no dicionário', 'en' => 'Search the dictionary', 'es' => 'Buscar en el diccionario']),
    'f_status' => $t('f_status', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']),
    'f_base' => $t('f_base', ['pt' => 'Base', 'en' => 'Base', 'es' => 'Base']),
    'todos' => $t('todos', ['pt' => 'Todos', 'en' => 'All', 'es' => 'Todos']),
    's_SUGESTAO' => $t('status.sugestao', ['pt' => 'Sugestão', 'en' => 'Suggestion', 'es' => 'Sugerencia']),
    's_APROVADO' => $t('status.aprovado', ['pt' => 'Aprovado', 'en' => 'Approved', 'es' => 'Aprobado']),
    's_REJEITADO' => $t('status.rejeitado', ['pt' => 'Rejeitado', 'en' => 'Rejected', 'es' => 'Rechazado']),
    'b_UNIVERSAL' => $t('base.universal', ['pt' => 'Comum (todas as empresas)', 'en' => 'Common (all companies)', 'es' => 'Común (todas las empresas)']),
    'b_EMPRESA' => $t('base.empresa', ['pt' => 'Desta empresa', 'en' => 'This company', 'es' => 'De esta empresa']),
    'o_SETOR' => $t('origem.setor', ['pt' => 'Carga do setor', 'en' => 'Industry load', 'es' => 'Carga del sector']),
    'o_DADOS' => $t('origem.dados', ['pt' => 'Extraído dos cadastros', 'en' => 'Found in records', 'es' => 'Extraído de los registros']),
    'o_MANUAL' => $t('origem.manual', ['pt' => 'Manual', 'en' => 'Manual', 'es' => 'Manual']),
    'c_termo' => $t('coluna.termo', ['pt' => 'Termo / sigla', 'en' => 'Term / acronym', 'es' => 'Término / sigla']),
    'c_significado' => $t('coluna.significado', ['pt' => 'Significado', 'en' => 'Meaning', 'es' => 'Significado']),
    'c_sinonimos' => $t('coluna.sinonimos', ['pt' => 'Sinônimos', 'en' => 'Synonyms', 'es' => 'Sinónimos']),
    'c_sigla' => $t('coluna.sigla', ['pt' => 'Sigla (palavra inteira)', 'en' => 'Acronym (whole word)', 'es' => 'Sigla (palabra entera)']),
    'c_contexto' => $t('coluna.contexto', ['pt' => 'Contexto', 'en' => 'Context', 'es' => 'Contexto']),
    'c_base' => $t('coluna.base', ['pt' => 'Base', 'en' => 'Base', 'es' => 'Base']),
    'c_origem' => $t('coluna.origem', ['pt' => 'Origem', 'en' => 'Source', 'es' => 'Origen']),
    'c_ocorrencias' => $t('coluna.ocorrencias', ['pt' => 'Ocorrências', 'en' => 'Occurrences', 'es' => 'Ocurrencias']),
    'c_status' => $t('coluna.status', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']),
    'sigla_curta' => $t('sigla_curta', ['pt' => 'sigla', 'en' => 'acronym', 'es' => 'sigla']),
    'sim' => $t('sim', ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí']),
    'nao' => $t('nao', ['pt' => 'Não', 'en' => 'No', 'es' => 'No']),
    'salvar' => $t('salvar', ['pt' => 'Salvar', 'en' => 'Save', 'es' => 'Guardar']),
    'cancelar' => $t('cancelar', ['pt' => 'Cancelar', 'en' => 'Cancel', 'es' => 'Cancelar']),
    'sinonimos_ajuda' => $t('sinonimos_ajuda', ['pt' => 'Separe com ponto e vírgula. Ex.: exame ocupacional; atestado médico ocupacional', 'en' => 'Separate with semicolons.', 'es' => 'Separe con punto y coma.']),
    'contexto_ajuda' => $t('contexto_ajuda', ['pt' => 'Onde o termo é mais relevante: esses resultados aparecem primeiro na pesquisa.', 'en' => 'Where the term matters most: those results come first.', 'es' => 'Donde el término es más relevante: esos resultados aparecen primero.']),
    'exemplo' => $t('exemplo', ['pt' => 'Encontrado em', 'en' => 'Found in', 'es' => 'Encontrado en']),
    'selecionados' => $t('selecionados', ['pt' => '{n} selecionado(s)', 'en' => '{n} selected', 'es' => '{n} seleccionado(s)']),
    'nenhum' => $t('nenhum', ['pt' => 'Nenhum termo encontrado.', 'en' => 'No terms found.', 'es' => 'Ningún término encontrado.']),
    'confirmar_excluir' => $t('confirmar_excluir', ['pt' => 'Excluir {n} termo(s)? Esta ação não pode ser desfeita.', 'en' => 'Delete {n} term(s)? This cannot be undone.', 'es' => '¿Eliminar {n} término(s)? No se puede deshacer.']),
    'so_admin' => $t('so_admin', ['pt' => 'Termos da base comum só podem ser alterados pelo ADMINISTRADOR.', 'en' => 'Common base terms can only be changed by ADMINISTRADOR.', 'es' => 'Los términos de la base común solo los cambia el ADMINISTRADOR.']),
    'obrigatorio' => $t('obrigatorio', ['pt' => 'Informe o termo e o significado.', 'en' => 'Enter the term and the meaning.', 'es' => 'Informe el término y el significado.']),
    'gravado' => $t('gravado', ['pt' => 'Gravado. A pesquisa já usa os termos aprovados.', 'en' => 'Saved. The search already uses approved terms.', 'es' => 'Guardado. La búsqueda ya usa los términos aprobados.']),
    'tipo.licencas' => IdiomaService::t('busca_documentos.tipo.licencas', ['pt' => 'Licença', 'en' => 'License', 'es' => 'Licencia']),
    'tipo.controles' => IdiomaService::t('busca_documentos.tipo.controles', ['pt' => 'Controle de documento', 'en' => 'Document control', 'es' => 'Control de documento']),
    'tipo.requisitos' => IdiomaService::t('busca_documentos.tipo.requisitos', ['pt' => 'Requisito legal', 'en' => 'Legal requirement', 'es' => 'Requisito legal']),
    'tipo.treinamentos' => IdiomaService::t('busca_documentos.tipo.treinamentos', ['pt' => 'Treinamento', 'en' => 'Training', 'es' => 'Capacitación']),
    'tipo.infraestrutura' => IdiomaService::t('busca_documentos.tipo.infraestrutura', ['pt' => 'Infraestrutura', 'en' => 'Infrastructure', 'es' => 'Infraestructura']),
    'tipo.fornecedorcriterios' => IdiomaService::t('busca_documentos.tipo.fornecedorcriterios', ['pt' => 'Documento de fornecedor', 'en' => 'Supplier document', 'es' => 'Documento de proveedor']),
    'tipo.funcionariocriterios' => IdiomaService::t('busca_documentos.tipo.funcionariocriterios', ['pt' => 'Documento de funcionário', 'en' => 'Employee document', 'es' => 'Documento de empleado']),
    'tipo.funcionariotreinamentos' => IdiomaService::t('busca_documentos.tipo.funcionariotreinamentos', ['pt' => 'Treinamento de funcionário', 'en' => 'Employee training', 'es' => 'Capacitación de empleado']),
];
$tipos = ['licencas', 'controles', 'requisitos', 'treinamentos', 'infraestrutura', 'fornecedorcriterios', 'funcionariocriterios', 'funcionariotreinamentos'];
$pode = ['incluir' => !empty($permissao['pode_incluir']), 'editar' => !empty($permissao['pode_editar']), 'excluir' => !empty($permissao['pode_excluir']), 'admin' => $admin];
?><!doctype html>
<html lang="<?= IdiomaService::idioma() === 'pt' ? 'pt-BR' : $h(IdiomaService::idioma()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($T['titulo']) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2">
<link rel="stylesheet" href="../assets/css/app.css?v=5">
<link rel="stylesheet" href="../assets/css/main.css?v=18">
<link rel="stylesheet" href="../assets/css/portaria-documentacao-fornecedor.css?v=5">
<link rel="stylesheet" href="../assets/css/busca-dicionario.css?v=1">
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?>
</head>
<body class="dic-pagina">
<main class="dic" data-dic data-csrf="<?= $h((string)$_SESSION['busca_dicionario_csrf']) ?>">
    <div class="dic-barra">
        <?php if ($pode['incluir']): ?><button type="button" class="btn btn-sm btn-outline-success" data-dic-novo><i class="bi bi-plus-lg" aria-hidden="true"></i> <?= $h($T['novo']) ?></button><?php endif; ?>
        <?php if ($pode['editar']): ?>
        <button type="button" class="btn btn-sm btn-outline-success" data-dic-lote="APROVADO" disabled><i class="bi bi-check2-circle" aria-hidden="true"></i> <?= $h($T['aprovar']) ?></button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-dic-lote="REJEITADO" disabled><i class="bi bi-x-circle" aria-hidden="true"></i> <?= $h($T['rejeitar']) ?></button>
        <?php endif; ?>
        <?php if ($pode['excluir']): ?><button type="button" class="btn btn-sm btn-outline-danger" data-dic-excluir disabled><i class="bi bi-trash" aria-hidden="true"></i> <?= $h($T['excluir']) ?></button><?php endif; ?>
        <span class="dic-selecionados" data-dic-selecionados></span>
        <label class="dic-filtro"><?= $h($T['f_status']) ?>
            <select class="form-select form-select-sm" data-dic-status>
                <option value=""><?= $h($T['todos']) ?></option>
                <option value="SUGESTAO" selected><?= $h($T['s_SUGESTAO']) ?></option>
                <option value="APROVADO"><?= $h($T['s_APROVADO']) ?></option>
                <option value="REJEITADO"><?= $h($T['s_REJEITADO']) ?></option>
            </select></label>
        <label class="dic-filtro"><?= $h($T['f_base']) ?>
            <select class="form-select form-select-sm" data-dic-base>
                <option value=""><?= $h($T['todos']) ?></option>
                <option value="UNIVERSAL"><?= $h($T['b_UNIVERSAL']) ?></option>
                <option value="EMPRESA"><?= $h($T['b_EMPRESA']) ?></option>
            </select></label>
        <div class="crud-search dic-busca"><i class="bi bi-search" aria-hidden="true"></i><input type="search" placeholder="<?= $h($T['buscar']) ?>" aria-label="<?= $h($T['buscar']) ?>" data-dic-texto></div>
    </div>
    <div class="alert py-2 mb-0" role="status" data-dic-mensagem hidden></div>
    <form class="dic-form" data-dic-form hidden>
        <input type="hidden" name="base"><input type="hidden" name="id">
        <label><?= $h($T['c_termo']) ?> <input type="text" class="form-control form-control-sm" name="termo" maxlength="100" required></label>
        <label class="dic-form-largo"><?= $h($T['c_significado']) ?> <input type="text" class="form-control form-control-sm" name="significado" maxlength="300" required></label>
        <label class="dic-form-largo"><?= $h($T['c_sinonimos']) ?> <input type="text" class="form-control form-control-sm" name="sinonimos" maxlength="1000" placeholder="<?= $h($T['sinonimos_ajuda']) ?>"></label>
        <label class="dic-form-check"><input type="checkbox" class="form-check-input" name="sigla" value="1"> <?= $h($T['c_sigla']) ?></label>
        <?php if ($admin): ?>
        <label><?= $h($T['c_base']) ?>
            <select class="form-select form-select-sm" name="base_nova"><option value="EMPRESA"><?= $h($T['b_EMPRESA']) ?></option><option value="UNIVERSAL"><?= $h($T['b_UNIVERSAL']) ?></option></select></label>
        <?php endif; ?>
        <fieldset class="dic-contexto"><legend><?= $h($T['c_contexto']) ?> <small><?= $h($T['contexto_ajuda']) ?></small></legend>
            <?php foreach ($tipos as $tp): ?><label class="dic-form-check"><input type="checkbox" class="form-check-input" name="contexto" value="<?= $h($tp) ?>"> <?= $h($T['tipo.' . $tp]) ?></label><?php endforeach; ?>
        </fieldset>
        <div class="dic-form-acoes"><button type="submit" class="btn btn-sm btn-primary"><?= $h($T['salvar']) ?></button> <button type="button" class="btn btn-sm btn-outline-secondary" data-dic-cancelar><?= $h($T['cancelar']) ?></button></div>
    </form>
    <div class="menu-grid-scroll dic-grid-caixa">
        <table class="table table-sm menu-batch-grid dic-grid">
            <thead><tr>
                <th class="dic-col-sel"><input type="checkbox" class="form-check-input" data-dic-todos aria-label="<?= $h($T['todos']) ?>"></th>
                <th><div class="column-heading"><span><?= $h($T['c_termo']) ?></span></div></th>
                <th><div class="column-heading"><span><?= $h($T['c_significado']) ?></span></div></th>
                <th><div class="column-heading"><span><?= $h($T['c_sinonimos']) ?></span></div></th>
                <th><div class="column-heading"><span><?= $h($T['c_contexto']) ?></span></div></th>
                <th><div class="column-heading"><span><?= $h($T['c_base']) ?></span></div></th>
                <th><div class="column-heading"><span><?= $h($T['c_origem']) ?></span></div></th>
                <th class="dic-col-num"><div class="column-heading"><span><?= $h($T['c_ocorrencias']) ?></span></div></th>
                <th><div class="column-heading"><span><?= $h($T['c_status']) ?></span></div></th>
                <th class="dic-col-acoes"></th>
            </tr></thead>
            <tbody data-dic-linhas></tbody>
        </table>
    </div>
    <div class="dic-rodape" data-dic-rodape></div>
</main>
<script>window.DIC_TEXTOS = <?= json_encode($T, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; window.DIC_PODE = <?= json_encode($pode) ?>;</script>
<script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script>
<script src="../assets/js/busca-dicionario.js?v=2"></script>
</body>
</html>
