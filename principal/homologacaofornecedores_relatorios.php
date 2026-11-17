<?php
declare(strict_types=1);

/*
 * Homologação de Fornecedores › Relatórios › Relatórios de Homologação (migração 314, menu HOMOLOGACAO_RELATORIOS_GERENCIAIS).
 * Substitui, numa tela só, os relatórios gerenciais do legado (fo_relatorio_*: integração, documentos de funcionários,
 * treinamento, auditoria, documentos do fornecedor, plano de ação, químicos, equipamentos), a árvore de Estatísticas
 * (fo_estatisticas_c: vira filtros rápidos) e o Relatório unificado de funcionários (foFuncionariosLista: vira matriz).
 *   catálogo com o nº de itens vermelhos · números · barra de distribuição · filtros rápidos · grid padrão (filtro e ordem
 *   por coluna, busca, paginação, linhas pastel pela regra única) · agrupar com subtotais · evidência (GED) · Excel pela
 *   fila do Agendador · "Meus relatórios" (visões salvas, envio semanal por alerta).
 * Regras: classes/HomologacaoRelatorios.php (as mesmas da planilha e do envio semanal). Dados por procedimentos (314).
 * Permissões: visualizar = ver e salvar os próprios relatórios; exportar = Excel. Fornecedor externo: só os fornecedores dele.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/AuditService.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/HomologacaoFornecedorAcesso.php';
require_once dirname(__DIR__) . '/classes/HomologacaoRelatorios.php';
require_once dirname(__DIR__) . '/classes/ExportacaoSolicitacao.php';

const REL_MENU = 'HOMOLOGACAO_RELATORIOS_GERENCIAIS';
const REL_TRANSACAO = 'homologacaofornecedores_relatorios';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$t = static fn(string $chave, array $padrao): string => IdiomaService::t(REL_TRANSACAO . '.' . $chave, $padrao);
$acao = (string)($_GET['acao'] ?? '');
$falhar = static function (int $codigo, string $mensagem) use ($acao): never {
    http_response_code($codigo);
    if ($acao !== '') { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'message' => $mensagem], JSON_UNESCAPED_UNICODE); }
    else { header('Content-Type: text/html; charset=utf-8'); echo htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8'); }
    exit;
};
try {
    $env = GreenPortalDatabase::env();
    $pdo = TenantContext::connect($env, $_SESSION);
    if (!UsuarioSessao::atualizar($pdo, $_SESSION)) throw new RuntimeException('sessao');
} catch (Throwable) {
    $falhar(401, IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.']));
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$permissao = PermissaoTransacao::obter(REL_MENU);
if (empty($permissao['pode_visualizar'])) $falhar(403, IdiomaService::t('comum.erro.sem_permissao', ['pt' => 'Seu perfil não tem permissão para acessar esta transação.', 'en' => 'Your profile is not allowed to access this transaction.', 'es' => 'Su perfil no tiene permiso para acceder a esta transacción.']));
$externo = HomologacaoFornecedorAcesso::externo();
// Migração 316: 0 = vê tudo, 1 = fornecedor externo, 2 = interno restrito às suas unidades (e aos fornecedores pelos quais responde).
$modoAcesso = HomologacaoFornecedorAcesso::modoBanco($pdo);
$podeExportar = !empty($permissao['pode_exportar_excel']);
$usuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);
if (empty($_SESSION['fo_relatorios_csrf'])) $_SESSION['fo_relatorios_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['fo_relatorios_csrf'];
$idioma = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';
$sessao = $_SESSION;
session_write_close();

$temMigracao = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_fo_relatorio_itens'")->fetchColumn() > 0;
if (!$temMigracao) $falhar(503, $t('erro.migracao', ['pt' => 'Tela indisponível: falta aplicar a migração 314.', 'en' => 'Screen unavailable: migration 314 has not been applied.', 'es' => 'Pantalla no disponible: falta aplicar la migración 314.']));

$R = new HomologacaoRelatorios($idioma);
$entrada = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : $_GET;
$entrada = is_array($entrada) ? $entrada : [];
$json = static fn(mixed $v): string => (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$responder = static function (array $dados): never { echo json_encode(['ok' => true] + $dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; };
$chamar = static function (string $sql, array $p) use ($pdo): array {
    $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) { } $s->closeCursor(); return $r;
};
$visoes = static function () use ($pdo, $usuario, $json): array {
    $s = $pdo->prepare('CALL sp_crud_select(:t, :f, NULL, 100, 0)');
    $s->execute(['t' => 'vw_fo_relatorios_visoes', 'f' => $json(['CODUSUARIO' => ['value' => (string)$usuario, 'exact' => true]])]);
    $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) { } $s->closeCursor();
    $out = [];
    foreach ($r as $v) {
        if ((int)$v['CODUSUARIO'] !== $usuario) continue;
        $p = json_decode((string)($v['PARAMETROS'] ?? '{}'), true);
        $out[] = ['cod' => (int)$v['CODVISAO'], 'nome' => (string)$v['NOME'], 'relatorio' => (string)$v['RELATORIO'], 'envio' => (int)$v['ENVIO_SEMANAL'] === 1, 'parametros' => is_array($p) ? $p : []];
    }
    usort($out, static fn($a, $b) => strnatcasecmp($a['nome'], $b['nome']));
    return $out;
};
$ged = null;
$gedObter = static function () use (&$ged, $pdo, $env, $sessao): ?GedStorageService {
    if ($ged) return $ged;
    try { require_once dirname(__DIR__) . '/classes/GedStorageService.php'; $ged = new GedStorageService($pdo, $env, $sessao); } catch (Throwable) { $ged = null; }
    return $ged;
};

// ---------- Ações ----------
if ($acao !== '') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($acao === 'dados') {
            $p = HomologacaoRelatorios::normalizarPedido($entrada, $externo);
            if ($p['relatorio'] === 'unificado') {
                [$fu, $ed, $et, $si] = HomologacaoRelatorios::lerUnificado($pdo, $usuario, $modoAcesso, $p['filtros']);
                $base = $R->unificado($fu, $ed, $et, $si, HomologacaoRelatorios::lerBloqueados($pdo));
                $u = $R->aplicarUnificado($base, $p);
                $u['tipos'] = $base['tipos'];
                $total = count($u['linhas']);
                $paginas = max(1, (int)ceil($total / HomologacaoRelatorios::POR_PAGINA));
                $pagina = min(max(1, (int)($entrada['pagina'] ?? 1)), $paginas);
                $u['linhas'] = array_slice($u['linhas'], ($pagina - 1) * HomologacaoRelatorios::POR_PAGINA, HomologacaoRelatorios::POR_PAGINA);
                $responder(['unificado' => true, 'relatorio' => ['id' => 'unificado', 'nome' => $R->nome('unificado')], 'pedido' => $p] + $u + ['total' => $total, 'pagina' => $pagina, 'paginas' => $paginas]);
            }
            $def = HomologacaoRelatorios::relatorios()[$p['relatorio']];
            $todas = $R->linhasRelatorio(HomologacaoRelatorios::lerItens($pdo, $usuario, $modoAcesso, $p['filtros'], $def['categoria']));
            $resumo = $R->resumo($p['relatorio'], $todas);
            $ap = $R->aplicar($p['relatorio'], $todas, $p);
            $total = count($ap['linhas']);
            $paginas = max(1, (int)ceil($total / HomologacaoRelatorios::POR_PAGINA));
            $pagina = min(max(1, (int)($entrada['pagina'] ?? 1)), $paginas);
            $pag = array_slice($ap['linhas'], ($pagina - 1) * HomologacaoRelatorios::POR_PAGINA, HomologacaoRelatorios::POR_PAGINA);
            if (in_array('evidencia', HomologacaoRelatorios::chavesColunas($p['relatorio'], true), true)) HomologacaoRelatorios::contarEvidencias($pag, $gedObter());
            $rot = HomologacaoRelatorios::rotulosColunas();
            $responder(['unificado' => false, 'pedido' => $p,
                'relatorio' => ['id' => $p['relatorio'], 'nome' => $R->L($def['nome']), 'desc' => $R->L($def['desc']), 'legado' => $R->L($def['legado']), 'categoria' => $def['categoria'],
                    'colunas' => array_map(static fn($c) => ['k' => $c[0], 'rotulo' => $R->L($rot[$c[1]] ?? ['pt' => $c[1]])], $def['colunas']),
                    'agrupar' => array_map(static fn($k) => ['k' => $k, 'rotulo' => $R->L($rot[$k] ?? ['pt' => $k])], $def['agrupar']),
                    'ged' => isset(HomologacaoRelatorios::GED[$def['categoria']]) ? ['tipo' => HomologacaoRelatorios::GED[$def['categoria']][1], 'chave' => HomologacaoRelatorios::GED[$def['categoria']][2]] : null],
                'kpis' => $resumo['kpis'], 'chips' => $resumo['chips'], 'dist' => $resumo['dist'],
                'lista' => ['total' => $total, 'pagina' => $pagina, 'paginas' => $paginas, 'chip' => $ap['chip'], 'agrupar' => $ap['agrupar'], 'ordem' => $ap['ordem'],
                    'subtotais' => HomologacaoRelatorios::subtotais($ap['linhas'], $ap['agrupar']),
                    'linhas' => array_map(static fn($l) => HomologacaoRelatorios::saida($p['relatorio'], $l), $pag)]]);
        }
        if ($acao === 'contagens') {
            $p = HomologacaoRelatorios::normalizarPedido($entrada, $externo);
            $c = HomologacaoRelatorios::lerContagens($pdo, $usuario, $modoAcesso, $p['filtros']);
            $out = [];
            foreach (HomologacaoRelatorios::relatorios() as $id => $r) $out[$id] = ['vermelhos' => (int)($c[$r['categoria']]['VERMELHO'] ?? 0), 'total' => array_sum($c[$r['categoria']] ?? [])];
            $responder(['contagens' => $out]);
        }
        if ($acao === 'visoes') $responder(['visoes' => $visoes()]);

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $falhar(405, 'POST');
        if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) $falhar(419, IdiomaService::t('comum.erro.token', ['pt' => 'Token de segurança inválido. Atualize a tela.', 'en' => 'Invalid security token. Refresh the page.', 'es' => 'Token de seguridad inválido. Actualice la pantalla.']));
        if ($acao === 'exportar') {
            // Excel: só o pedido. A planilha é gerada pelo GreenPortal-ExportWorker (Agendador do Windows,
            // classes/HomologacaoRelatoriosExportacao.php) com as mesmas regras da tela e chega nos alertas com o link.
            if (!$podeExportar) $falhar(403, IdiomaService::t('comum.permissao.negada', ['pt' => 'Perfil sem permissão de acesso!', 'en' => 'Profile does not have access permission!', 'es' => '¡El perfil no tiene permiso de acceso!']));
            $p = HomologacaoRelatorios::normalizarPedido(is_array($entrada['pedido'] ?? null) ? $entrada['pedido'] : $entrada, $externo);
            $portaria = !empty($entrada['portaria']) && $p['relatorio'] === 'unificado';
            $nome = $portaria ? $t('portaria', ['pt' => 'Lista para a portaria', 'en' => 'Gatehouse list', 'es' => 'Lista para la portería']) : $R->nome($p['relatorio']);
            $pedido = ExportacaoSolicitacao::solicitar($pdo, $usuario, REL_TRANSACAO, 'Relatórios de Homologação (' . $nome . ')', [
                'filtros' => ['pedido' => $p, 'externo' => $externo ? 1 : 0, 'modo' => $modoAcesso, 'idioma' => $idioma, 'portaria' => $portaria ? 1 : 0], 'busca' => $p['filtros']['busca']]);
            AuditService::record($pdo, REL_TRANSACAO, 'EXPORTAR', $pedido > 0 ? 'SUCESSO' : 'ERRO', 'exportacoes_grid', $p['relatorio'], ['pedido' => $pedido, 'portaria' => $portaria]);
            if ($pedido <= 0) $falhar(500, IdiomaService::t('comum.erro.exportacao', ['pt' => 'Não foi possível solicitar a exportação.', 'en' => 'The export could not be requested.', 'es' => 'No fue posible solicitar la exportación.']));
            $responder(['message' => ExportacaoSolicitacao::mensagem()]);
        }
        if ($acao === 'visao_salvar') {
            $nome = mb_substr(trim((string)($entrada['nome'] ?? '')), 0, 120);
            if ($nome === '') $falhar(422, $t('erro.nome', ['pt' => 'Informe o nome do relatório.', 'en' => 'Enter the report name.', 'es' => 'Informe el nombre del informe.']));
            $p = HomologacaoRelatorios::normalizarPedido(is_array($entrada['pedido'] ?? null) ? $entrada['pedido'] : [], $externo);
            $cod = max(0, (int)($entrada['cod'] ?? 0));
            $r = $chamar('CALL sp_fo_relatorio_visao_salvar(?, ?, ?, ?, ?, ?, ?)', [$usuario, $cod ?: null, $nome, $p['relatorio'], $json($p), $externo ? 1 : 0, !empty($entrada['envio']) ? 1 : 0]);
            AuditService::record($pdo, REL_TRANSACAO, 'SALVAR', 'SUCESSO', 'fo_relatorios_visoes', (string)($r[0]['CODVISAO'] ?? ''), ['nome' => $nome, 'relatorio' => $p['relatorio']]);
            $responder(['visao' => $r[0] ?? null, 'visoes' => $visoes()]);
        }
        if ($acao === 'visao_excluir') {
            $cod = max(0, (int)($entrada['cod'] ?? 0));
            $chamar('CALL sp_fo_relatorio_visao_excluir(?, ?)', [$usuario, $cod]);
            AuditService::record($pdo, REL_TRANSACAO, 'EXCLUIR', 'SUCESSO', 'fo_relatorios_visoes', (string)$cod, []);
            $responder(['visoes' => $visoes()]);
        }
        if ($acao === 'visao_envio') {
            $cod = max(0, (int)($entrada['cod'] ?? 0));
            $chamar('CALL sp_fo_relatorio_visao_envio(?, ?, ?)', [$usuario, $cod, !empty($entrada['envio']) ? 1 : 0]);
            $responder(['visoes' => $visoes()]);
        }
        $falhar(400, $t('erro.acao', ['pt' => 'Ação inválida.', 'en' => 'Invalid action.', 'es' => 'Acción inválida.']));
    } catch (Throwable $e) {
        error_log('Relatórios de Homologação: ' . $e->getMessage());
        $falhar(500, IdiomaService::erro($e, 'consultar'));
    }
}

// ---------- Página ----------
AuditService::record($pdo, REL_TRANSACAO, 'ACESSAR', 'SUCESSO', 'transacao', REL_TRANSACAO);
$unidades = []; $tipos = [];
if ($externo) {
    foreach (MigracaoCrud::selecionar($pdo, 'vw_homologacao_fornecedores_usuario_grid', ['CODUSUARIO_ACESSO' => $usuario]) as $f)
        if ((int)($f['CODUNIDADE'] ?? 0) > 0) $unidades[(int)$f['CODUNIDADE']] = (string)($f['DSCUNIDADE'] ?? $f['CODUNIDADE']);
} else {
    // Interno restrito (316): só as unidades dele no filtro.
    foreach ($modoAcesso === 2 ? MigracaoCrud::selecionar($pdo, 'vw_homologacao_unidades_usuario_grid', ['CODUSUARIO_ACESSO' => $usuario]) : MigracaoCrud::selecionar($pdo, 'vw_unidades_grid') as $u) $unidades[(int)($u['CODUNIDADE'] ?? $u['codunidade'] ?? 0)] = (string)($u['DSCUNIDADE'] ?? $u['dscunidade'] ?? '');
    foreach (MigracaoCrud::selecionar($pdo, 'vw_homologacao_tipos_fornecedores_grid') as $tp) $tipos[(int)($tp['codtipofornecedor'] ?? $tp['CODTIPOFORNECEDOR'] ?? 0)] = (string)($tp['DSCTIPO'] ?? '');
}
unset($unidades[0], $tipos[0]);
asort($unidades, SORT_NATURAL | SORT_FLAG_CASE); asort($tipos, SORT_NATURAL | SORT_FLAG_CASE);
$listaVisoes = [];
try { $listaVisoes = $visoes(); } catch (Throwable) { }
// Abrir uma visão salva (link do alerta semanal ou de "Meus relatórios") ou um relatório direto (?relatorio=).
$inicial = ['relatorio' => preg_match('/^[a-z]{2,20}$/', (string)($_GET['relatorio'] ?? '')) ? (string)$_GET['relatorio'] : '', 'visao' => null];
$codVisao = (int)($_GET['visao'] ?? 0);
if ($codVisao > 0) foreach ($listaVisoes as $v) if ($v['cod'] === $codVisao) $inicial['visao'] = $v;
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$titulo = $t('titulo', ['pt' => 'Relatórios de Homologação', 'en' => 'Supplier Approval Reports', 'es' => 'Informes de Homologación']);
$tx = static fn(string $k, string $pt, string $en, string $es): string => $t($k, ['pt' => $pt, 'en' => $en, 'es' => $es]);
$cfg = [
    'url' => basename(__FILE__), 'csrf' => $csrf, 'externo' => $externo, 'podeExportar' => $podeExportar, 'usuario' => $usuario, 'idioma' => $idioma,
    'catalogo' => $R->catalogo(), 'visoes' => $listaVisoes, 'inicial' => $inicial,
    'fichaUrl' => 'homologacaofornecedores_ficha.php', 'arquivosUrl' => 'gestao_arquivos.php',
    'textos' => [
        'carregando' => $tx('msg.carregando', 'Carregando...', 'Loading...', 'Cargando...'),
        'vazio' => $tx('msg.vazio', 'Nada encontrado com os filtros atuais.', 'Nothing found with the current filters.', 'Nada encontrado con los filtros actuales.'),
        'itens' => $tx('msg.itens', 'item(ns)', 'item(s)', 'ítem(s)'),
        'funcionarios' => $tx('msg.funcionarios', 'funcionário(s)', 'employee(s)', 'empleado(s)'),
        'pagina' => $tx('msg.pagina', 'Página', 'Page', 'Página'), 'de' => $tx('msg.de', 'de', 'of', 'de'), 'registros' => $tx('msg.registros', 'registros', 'records', 'registros'),
        'fichaTitulo' => $tx('ficha.titulo', 'Ficha do fornecedor', 'Supplier profile', 'Ficha del proveedor'),
        'abrirFicha' => $tx('ficha.abrir', 'Abrir ficha', 'Open profile', 'Abrir ficha'),
        'evidencias' => $tx('ged.titulo', 'Evidências', 'Evidence', 'Evidencias'),
        'arquivo' => $tx('ged.arquivo', 'arquivo(s)', 'file(s)', 'archivo(s)'),
        'semAgrupar' => $tx('agrupar.nenhum', 'Sem agrupar', 'No grouping', 'Sin agrupar'),
        'substitui' => $tx('legado', 'Substitui no sistema anterior:', 'Replaces in the previous system:', 'Reemplaza en el sistema anterior:'),
        'salvo' => $tx('visao.salva', 'Relatório salvo em "Meus relatórios".', 'Report saved in "My reports".', 'Informe guardado en "Mis informes".'),
        'excluirConfirma' => $tx('visao.excluir_confirma', 'Clique de novo para excluir', 'Click again to delete', 'Haga clic de nuevo para eliminar'),
        'envioLigado' => $tx('visao.envio_on', 'Envio semanal ligado', 'Weekly delivery on', 'Envío semanal activado'),
        'envioDesligado' => $tx('visao.envio_off', 'Envio semanal desligado', 'Weekly delivery off', 'Envío semanal desactivado'),
        'semVisoes' => $tx('visao.vazio', 'Nenhum relatório salvo. Use "Salvar como meu relatório".', 'No saved reports. Use "Save as my report".', 'Ningún informe guardado. Use "Guardar como mi informe".'),
        'exportado' => ExportacaoSolicitacao::mensagem(),
        'todosTipos' => $tx('filtro.todos_tipos_func', 'Todos os tipos', 'All types', 'Todos los tipos'),
        'naoExigido' => $tx('uni.nao_exigido', 'não exigido para o tipo', 'not required for the type', 'no exigido para el tipo'),
        'emDia' => $tx('uni.em_dia', 'em dia', 'up to date', 'al día'),
        'desde' => $tx('uni.desde', 'desde', 'since', 'desde'),
        'subtotal' => $tx('grupo.subtotal', 'item(ns) · vermelhos', 'item(s) · red', 'ítem(s) · rojos'),
        'amarelos' => $tx('grupo.amarelos', 'amarelos', 'yellow', 'amarillos'), 'verdes' => $tx('grupo.verdes', 'verdes', 'green', 'verdes'),
    ],
];
$busca = IdiomaService::t('comum.busca.grid', ['pt' => 'Buscar no grid', 'en' => 'Search the grid', 'es' => 'Buscar en la cuadrícula']);
$pagAnt = $tx('pag.anterior', 'Página anterior', 'Previous page', 'Página anterior');
$pagProx = $tx('pag.proxima', 'Próxima página', 'Next page', 'Página siguiente');
$paginacao = static fn(string $sufixo): string => '<nav class="grid-pagination" aria-label="' . $e(IdiomaService::t('comum.paginacao.rotulo', ['pt' => 'Paginação', 'en' => 'Pagination', 'es' => 'Paginación'])) . '">'
    . '<button type="button" class="btn btn-sm btn-outline-secondary grid-page-prev" data-pag' . $sufixo . '="-1" title="' . $e($pagAnt) . '" aria-label="' . $e($pagAnt) . '"><i class="bi bi-chevron-left"></i></button>'
    . '<span class="grid-page-status" data-pagina' . $sufixo . '></span>'
    . '<button type="button" class="btn btn-sm btn-outline-secondary grid-page-next" data-pag' . $sufixo . '="1" title="' . $e($pagProx) . '" aria-label="' . $e($pagProx) . '"><i class="bi bi-chevron-right"></i></button></nav>';
?><!doctype html>
<html lang="<?= $e($idioma === 'pt' ? 'pt-BR' : $idioma) ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($titulo) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2">
<link rel="stylesheet" href="../assets/css/app.css?v=5">
<link rel="stylesheet" href="../assets/css/main.css?v=18">
<link rel="stylesheet" href="../assets/css/homologacao-painel.css?v=5">
<link rel="stylesheet" href="../assets/css/homologacao-relatorios.css?v=1">
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?></head>
<body class="painel-body">
<?php greenPortalCabecalhoImpressao('Relatórios de homologação de fornecedores'); ?>
<main class="painel rel" data-relatorios>
  <header class="painel-cab">
    <div>
      <div class="painel-trilha"><?= $e($tx('trilha', 'Homologação de Fornecedores › Relatórios', 'Supplier Approval › Reports', 'Homologación de Proveedores › Informes')) ?></div>
      <h1 class="painel-titulo"><?= $e($titulo) ?></h1>
      <div class="painel-atualizado"><?= $e($tx('atualizado', 'Dados de', 'Data as of', 'Datos de')) ?> <span data-atualizado></span><?php if ($externo): ?> · <span class="painel-selo"><?= $e($tx('selo_externo', 'Somente os seus fornecedores', 'Only your suppliers', 'Solo sus proveedores')) ?></span><?php endif; ?></div>
    </div>
    <div class="painel-acoes">
      <a class="btn painel-btn" href="homologacaofornecedores_painel.php"><i class="bi bi-speedometer2" aria-hidden="true"></i><?= $e($tx('botao.painel', 'Painel de Homologação', 'Approval Dashboard', 'Panel de Homologación')) ?></a>
      <button type="button" class="btn painel-btn" data-open-transacao-ajuda data-help-transaction="<?= $e(REL_TRANSACAO) ?>" title="<?= $e($tx('botao.ajuda', 'Ajuda', 'Help', 'Ayuda')) ?>" aria-label="<?= $e($tx('botao.ajuda', 'Ajuda', 'Help', 'Ayuda')) ?>"><i class="bi bi-question-circle" aria-hidden="true"></i></button>
    </div>
  </header>

  <form class="painel-filtros rel-filtros" data-filtros aria-label="<?= $e($tx('filtros', 'Filtros', 'Filters', 'Filtros')) ?>">
    <label><?= $e($tx('filtro.busca', 'Fornecedor ou CNPJ', 'Supplier or tax ID', 'Proveedor o CNPJ')) ?>
      <input type="search" name="busca" class="form-control" autocomplete="off" placeholder="<?= $e($tx('filtro.busca_dica', 'Parte do nome ou do CNPJ', 'Part of the name or tax ID', 'Parte del nombre o del CNPJ')) ?>"></label>
    <label><?= $e($tx('filtro.unidade', 'Unidade', 'Unit', 'Unidad')) ?>
      <select name="unidade" class="form-select"><option value=""><?= $e($tx('filtro.todas_unidades', 'Todas as unidades', 'All units', 'Todas las unidades')) ?></option><?php foreach ($unidades as $k => $v): ?><option value="<?= (int)$k ?>"><?= $e($v) ?></option><?php endforeach; ?></select></label>
    <?php if (!$externo): ?>
    <label><?= $e($tx('filtro.tipo', 'Tipo de fornecedor', 'Supplier type', 'Tipo de proveedor')) ?>
      <select name="tipo" class="form-select"><option value=""><?= $e($tx('filtro.todos_tipos', 'Todos os tipos', 'All types', 'Todos los tipos')) ?></option><?php foreach ($tipos as $k => $v): ?><option value="<?= (int)$k ?>"><?= $e($v) ?></option><?php endforeach; ?></select></label>
    <?php endif; ?>
    <div class="painel-filtros-botoes">
      <button type="button" class="btn painel-btn" data-limpar><?= $e($tx('botao.limpar', 'Limpar', 'Clear', 'Limpiar')) ?></button>
    </div>
  </form>

  <div class="painel-status" data-status aria-live="polite"></div>

  <div class="painel-corpo">
    <aside class="rel-lado">
      <nav class="painel-cartao rel-catalogo" aria-label="<?= $e($tx('catalogo', 'Relatórios', 'Reports', 'Informes')) ?>">
        <div class="crud-search rel-procurar"><i class="bi bi-search" aria-hidden="true"></i><input type="search" data-procurar placeholder="<?= $e($tx('catalogo.procurar', 'Procurar relatório', 'Find a report', 'Buscar informe')) ?>" aria-label="<?= $e($tx('catalogo.procurar', 'Procurar relatório', 'Find a report', 'Buscar informe')) ?>"></div>
        <div data-catalogo></div>
      </nav>
      <section class="painel-cartao rel-visoes" aria-label="<?= $e($tx('visoes', 'Meus relatórios', 'My reports', 'Mis informes')) ?>">
        <h2><i class="bi bi-bookmark-star" aria-hidden="true"></i> <?= $e($tx('visoes', 'Meus relatórios', 'My reports', 'Mis informes')) ?></h2>
        <div class="painel-mudo painel-pequeno"><?= $e($tx('visoes.dica', 'Com o envio semanal ligado, toda segunda-feira um resumo chega em Home › Alertas.', 'With weekly delivery on, a summary arrives in Home › Alerts every Monday.', 'Con el envío semanal activado, cada lunes llega un resumen a Inicio › Alertas.')) ?></div>
        <ul class="rel-visoes-lista" data-visoes></ul>
      </section>
    </aside>

    <div class="painel-principal">
      <!-- Relatório (fornecedores e funcionários) -->
      <section class="rel-relatorio" data-vista-relatorio>
        <div class="painel-cartao rel-cab">
          <h2 data-rel-nome></h2>
          <div data-rel-desc></div>
          <div class="painel-mudo painel-pequeno"><strong><?= $e($tx('legado', 'Substitui no sistema anterior:', 'Replaces in the previous system:', 'Reemplaza en el sistema anterior:')) ?></strong> <span data-rel-legado></span></div>
        </div>
        <section class="painel-kpis" data-kpis aria-label="<?= $e($tx('indicadores', 'Indicadores', 'Indicators', 'Indicadores')) ?>"></section>
        <div class="painel-cartao rel-distribuicao">
          <div class="rel-barra" data-dist role="group" aria-label="<?= $e($tx('distribuicao', 'Distribuição por cor', 'Distribution by color', 'Distribución por color')) ?>"></div>
          <div class="rel-chips" data-chips role="group" aria-label="<?= $e($tx('filtros_rapidos', 'Filtros rápidos', 'Quick filters', 'Filtros rápidos')) ?>"></div>
        </div>
        <section class="setup-fragment menu-grid-fragment grid-ready painel-grid rel-grid" aria-label="<?= $e($tx('resultado', 'Resultado', 'Result', 'Resultado')) ?>">
          <div class="crud-toolbar menu-grid-toolbar rel-grid-barra">
            <?php
            require_once dirname(__DIR__) . '/components/botoes_crud.php';
            $extras = '<label class="rel-agrupar"><span>' . $e($tx('agrupar', 'Agrupar por', 'Group by', 'Agrupar por')) . '</span><select class="form-select form-select-sm" data-agrupar></select></label>'
                . '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-salvar-visao title="' . $e($tx('botao.salvar_visao', 'Salvar como meu relatório', 'Save as my report', 'Guardar como mi informe')) . '" aria-label="' . $e($tx('botao.salvar_visao', 'Salvar como meu relatório', 'Save as my report', 'Guardar como mi informe')) . '"><i class="bi bi-bookmark-plus" aria-hidden="true"></i><span>' . $e($tx('botao.salvar_visao', 'Salvar como meu relatório', 'Save as my report', 'Guardar como mi informe')) . '</span></button>'
                . '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-imprimir title="' . $e($tx('botao.pdf', 'Gerar PDF', 'Create PDF', 'Generar PDF')) . '" aria-label="' . $e($tx('botao.pdf', 'Gerar PDF', 'Create PDF', 'Generar PDF')) . '"><i class="bi bi-filetype-pdf" aria-hidden="true"></i></button>';
            greenPortalCrudButtons(['save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'columns' => false, 'permission' => $permissao, 'transaction' => REL_TRANSACAO, 'extras' => $extras]);
            ?>
            <div class="rel-grid-direita"><?= $paginacao('') ?><div class="crud-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" data-busca-grid placeholder="<?= $e($busca) ?>" aria-label="<?= $e($busca) ?>"></div></div>
          </div>
          <div class="menu-grid-scroll painel-grid-scroll">
            <table class="table table-sm menu-batch-grid" id="relatoriosHomologacaoGrid">
              <thead data-cabecalho></thead>
              <tbody data-linhas></tbody>
            </table>
          </div>
          <div class="painel-paginacao"><span class="painel-mudo" data-rodape></span></div>
        </section>
      </section>

      <!-- Relatório unificado de funcionários -->
      <section class="rel-unificado" data-vista-unificado hidden>
        <div class="painel-cartao rel-cab">
          <h2><?= $e($tx('uni.titulo', 'Relatório unificado de funcionários', 'Unified employee report', 'Informe unificado de empleados')) ?></h2>
          <div><?= $e($tx('uni.desc', 'Uma linha por funcionário com integração, documentos e treinamentos exigidos pelo tipo de funcionário, lado a lado.', 'One row per employee with induction, documents and training required by the employee type, side by side.', 'Una fila por empleado con integración, documentos y capacitaciones exigidos por el tipo de empleado, lado a lado.')) ?></div>
          <div class="painel-mudo painel-pequeno"><strong><?= $e($tx('legado', 'Substitui no sistema anterior:', 'Replaces in the previous system:', 'Reemplaza en el sistema anterior:')) ?></strong> <?= $e($tx('uni.legado', 'Relatório unificado de funcionários (lá só a lista: unidade, empresa, nº do registro, setor, função, data de início)', 'Unified employee report (there only the list: unit, company, record no., department, role, start date)', 'Informe unificado de empleados (allí solo la lista: unidad, empresa, nº de registro, sector, función, fecha de inicio)')) ?></div>
        </div>
        <section class="painel-kpis" data-uni-kpis></section>
        <div class="painel-cartao rel-distribuicao">
          <div class="rel-uni-filtros">
            <label><?= $e($tx('uni.tipo', 'Tipo de funcionário', 'Employee type', 'Tipo de empleado')) ?> <select class="form-select form-select-sm" data-uni-tipo></select></label>
            <div class="rel-chips" data-uni-chips role="group"></div>
          </div>
          <div class="rel-legenda painel-pequeno">
            <span><i class="rel-amostra s-V"></i><?= $e($tx('uni.l_v', 'Vencido ou não atende', 'Expired or not met', 'Vencido o no atiende')) ?></span>
            <span><i class="rel-amostra s-A"></i><?= $e($tx('uni.l_a', 'Vence nos dias de reação', 'Expiring within reaction days', 'Vence en los días de reacción')) ?></span>
            <span><i class="rel-amostra s-G"></i><?= $e($tx('uni.l_g', 'Em dia', 'Up to date', 'Al día')) ?></span>
            <span><i class="rel-amostra s-B"></i><?= $e($tx('uni.l_b', 'Não avaliado (bloqueia)', 'Not evaluated (blocks)', 'No evaluado (bloquea)')) ?></span>
            <span><i class="rel-amostra s-X"></i><?= $e($tx('uni.l_x', 'Não exigido / não se aplica', 'Not required / not applicable', 'No exigido / no aplica')) ?></span>
            <span class="painel-mudo"><?= $e($tx('uni.l_dica', 'Clique numa célula para abrir as evidências do item.', 'Click a cell to open the item evidence.', 'Haga clic en una celda para abrir las evidencias del ítem.')) ?></span>
          </div>
        </div>
        <section class="setup-fragment menu-grid-fragment grid-ready painel-grid rel-grid" aria-label="<?= $e($tx('uni.titulo', 'Relatório unificado de funcionários', 'Unified employee report', 'Informe unificado de empleados')) ?>">
          <div class="crud-toolbar menu-grid-toolbar rel-grid-barra">
            <div class="crud-actions crud-actions-standard">
              <button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-uni-recarregar title="<?= $e(IdiomaService::t('comum.botao.recarregar', ['pt' => 'Recarregar', 'en' => 'Reload', 'es' => 'Recargar'])) ?>" aria-label="<?= $e(IdiomaService::t('comum.botao.recarregar', ['pt' => 'Recarregar', 'en' => 'Reload', 'es' => 'Recargar'])) ?>"><i class="bi bi-arrow-clockwise"></i></button>
              <?php if ($podeExportar): ?>
              <button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-uni-exportar title="<?= $e(IdiomaService::t('comum.botao.exportar', ['pt' => 'Exportar Excel', 'en' => 'Export to Excel', 'es' => 'Exportar a Excel'])) ?>" aria-label="<?= $e(IdiomaService::t('comum.botao.exportar', ['pt' => 'Exportar Excel', 'en' => 'Export to Excel', 'es' => 'Exportar a Excel'])) ?>"><i class="bi bi-file-earmark-excel"></i></button>
              <button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-uni-portaria title="<?= $e($tx('portaria.dica', 'Lista para a portaria (Excel só com os liberados)', 'Gatehouse list (Excel with cleared employees only)', 'Lista para la portería (Excel solo con los liberados)')) ?>" aria-label="<?= $e($tx('portaria', 'Lista para a portaria', 'Gatehouse list', 'Lista para la portería')) ?>"><i class="bi bi-door-open" aria-hidden="true"></i><span><?= $e($tx('portaria', 'Lista para a portaria', 'Gatehouse list', 'Lista para la portería')) ?></span></button>
              <?php endif; ?>
              <button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-salvar-visao title="<?= $e($tx('botao.salvar_visao', 'Salvar como meu relatório', 'Save as my report', 'Guardar como mi informe')) ?>" aria-label="<?= $e($tx('botao.salvar_visao', 'Salvar como meu relatório', 'Save as my report', 'Guardar como mi informe')) ?>"><i class="bi bi-bookmark-plus" aria-hidden="true"></i><span><?= $e($tx('botao.salvar_visao', 'Salvar como meu relatório', 'Save as my report', 'Guardar como mi informe')) ?></span></button>
              <button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-imprimir title="<?= $e($tx('botao.pdf', 'Gerar PDF', 'Create PDF', 'Generar PDF')) ?>" aria-label="<?= $e($tx('botao.pdf', 'Gerar PDF', 'Create PDF', 'Generar PDF')) ?>"><i class="bi bi-filetype-pdf" aria-hidden="true"></i></button>
            </div>
            <div class="rel-grid-direita"><?= $paginacao('-uni') ?><div class="crud-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" data-uni-busca placeholder="<?= $e($tx('uni.busca', 'Funcionário, registro ou fornecedor', 'Employee, record or supplier', 'Empleado, registro o proveedor')) ?>" aria-label="<?= $e($busca) ?>"></div></div>
          </div>
          <div class="menu-grid-scroll painel-grid-scroll rel-uni-scroll">
            <table class="table table-sm menu-batch-grid rel-matriz" id="relatorioUnificadoGrid">
              <thead data-uni-cabecalho></thead>
              <tbody data-uni-linhas></tbody>
            </table>
          </div>
          <div class="painel-paginacao"><span class="painel-mudo" data-uni-rodape></span></div>
        </section>
      </section>

      <section class="painel-cartao painel-legenda" aria-label="<?= $e($tx('legenda', 'Regra única de cores', 'Single color rule', 'Regla única de colores')) ?>">
        <strong><?= $e($tx('legenda', 'Regra única de cores', 'Single color rule', 'Regla única de colores')) ?></strong>
        <span><i class="painel-bola cor-VERMELHO"></i><?= $e($tx('legenda.vermelho', 'Vermelho: reprovado, vencido, não atende ou pendente com o fornecedor', 'Red: rejected, expired, not met or pending with the supplier', 'Rojo: reprobado, vencido, no atiende o pendiente con el proveedor')) ?></span>
        <span><i class="painel-bola cor-AMARELO"></i><?= $e($tx('legenda.amarelo', 'Amarelo: pendente com a empresa ou vence nos dias de reação (30)', 'Yellow: pending with the company or expiring within the reaction days (30)', 'Amarillo: pendiente con la empresa o vence en los días de reacción (30)')) ?></span>
        <span><i class="painel-bola cor-VERDE"></i><?= $e($tx('legenda.verde', 'Verde: aprovado / atende / em dia', 'Green: approved / met / up to date', 'Verde: aprobado / atiende / al día')) ?></span>
        <span><i class="painel-bola cor-NEUTRO"></i><?= $e($tx('legenda.cinza', 'Cinza: não se aplica', 'Gray: not applicable', 'Gris: no aplica')) ?></span>
        <span><i class="painel-bola cor-BRANCO"></i><?= $e($tx('legenda.branco', 'Branco: sem situação', 'White: no status', 'Blanco: sin situación')) ?></span>
      </section>
    </div>
  </div>

  <dialog class="rel-dialogo" data-dialogo-visao aria-labelledby="relDialogoTitulo">
    <form method="dialog" data-form-visao>
      <h2 id="relDialogoTitulo"><?= $e($tx('botao.salvar_visao', 'Salvar como meu relatório', 'Save as my report', 'Guardar como mi informe')) ?></h2>
      <label><?= $e($tx('visao.nome', 'Nome', 'Name', 'Nombre')) ?><input type="text" class="form-control" name="nome" maxlength="120" required autocomplete="off"></label>
      <label class="rel-check"><input type="checkbox" name="envio"> <?= $e($tx('visao.envio', 'Enviar um resumo toda segunda-feira (Home › Alertas)', 'Send a summary every Monday (Home › Alerts)', 'Enviar un resumen cada lunes (Inicio › Alertas)')) ?></label>
      <label class="rel-check" data-substituir-caixa hidden><input type="checkbox" name="substituir"> <?= $e($tx('visao.substituir', 'Atualizar o relatório aberto', 'Update the opened report', 'Actualizar el informe abierto')) ?> "<span data-substituir-nome></span>"</label>
      <div class="rel-dialogo-botoes">
        <button type="button" class="btn painel-btn" data-cancelar><?= $e($tx('botao.cancelar', 'Cancelar', 'Cancel', 'Cancelar')) ?></button>
        <button type="submit" class="btn painel-btn painel-btn-primario" value="salvar"><?= $e(IdiomaService::t('comum.botao.salvar', ['pt' => 'Salvar', 'en' => 'Save', 'es' => 'Guardar'])) ?></button>
      </div>
    </form>
  </dialog>
</main>

<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script>
<script>
window.greenRelatorios = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
</script>
<script src="../assets/js/homologacao-relatorios.js?v=1"></script>
</body>
</html>
