<?php
declare(strict_types=1);

/*
 * Homologação de Fornecedores › Painel de Homologação (relatórios gerenciais, estatísticas e dashboard) — etapa 5.
 * Menu/permissão HOMOLOGACAO_PAINEL. Substitui a árvore de estatísticas e os relatórios do legado (fo_estatisticas_c.php,
 * fo_relatorio_*, Dashboard do greennewd) por uma tela de consulta:
 *   filtros que valem para tudo · números clicáveis · consultas prontas · lista com exportação · gráficos · ficha do fornecedor
 *   (c455: página homologacaofornecedores_ficha.php em popup do portal).
 * Dados: CALL sp_fo_painel_itens (migração 298, view vw_homologacao_painel_itens — regra única de cores).
 * Permissões: visualizar = ver; exportar = Excel (pedido na fila do Agendador do Windows, chega nos alertas) e PDF (impressão); editar = "Cobrar responsáveis" (alertas na Home).
 * Fornecedor externo (FORNECEDOR / FORNECEDOR_GESTOR): só os fornecedores ligados a ele; sem ranking e sem cobrança.
 * O resumo semanal é gerado pelo serviço scripts/homologacao-resumo-worker.php (Agendador de Tarefas do Windows).
 * Migração 370: resultado da consulta, gráficos e matriz Unidade × situação em sub-abas (assets/js/subabas.js), sem a página
 * rolar com tudo empilhado; escolher uma consulta (número, consulta pronta ou clique num gráfico) abre a sub-aba do resultado.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/AuditService.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/HomologacaoFornecedorAcesso.php';

const PAINEL_MENU = 'HOMOLOGACAO_PAINEL';
const PAINEL_TRANSACAO = 'homologacaofornecedores_painel';
const PAINEL_POR_PAGINA = 50;

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$t = static fn(string $chave, array $padrao): string => IdiomaService::t(PAINEL_TRANSACAO . '.' . $chave, $padrao);
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
$permissao = PermissaoTransacao::obter(PAINEL_MENU);
if (empty($permissao['pode_visualizar'])) $falhar(403, IdiomaService::t('comum.erro.sem_permissao', ['pt' => 'Seu perfil não tem permissão para acessar esta transação.', 'en' => 'Your profile is not allowed to access this transaction.', 'es' => 'Su perfil no tiene permiso para acceder a esta transacción.']));
$externo = HomologacaoFornecedorAcesso::externo();
// Migração 316: 0 = vê tudo, 1 = fornecedor externo, 2 = interno restrito às suas unidades (e aos fornecedores pelos quais responde).
$modoAcesso = HomologacaoFornecedorAcesso::modoBanco($pdo);
$podeExportar = !empty($permissao['pode_exportar_excel']);
$podeCobrar = !empty($permissao['pode_editar']) && !$externo;
$usuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);
if (empty($_SESSION['fo_painel_csrf'])) $_SESSION['fo_painel_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['fo_painel_csrf'];
$idioma = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';
session_write_close();

$temMigracao = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_fo_painel_itens'")->fetchColumn() > 0;
if (!$temMigracao) $falhar(503, $t('erro.migracao', ['pt' => 'Tela indisponível: falta aplicar a migração 298.', 'en' => 'Screen unavailable: migration 298 has not been applied.', 'es' => 'Pantalla no disponible: falta aplicar la migración 298.']));

// ---------- Filtros ----------
$entrada = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : $_GET;
$inteiro = static fn($v): ?int => ($n = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false ? null : (int)$n;
$filtros = [
    'unidade' => $inteiro($entrada['unidade'] ?? null),
    'tipo' => $externo ? null : $inteiro($entrada['tipo'] ?? null),
    'busca' => mb_substr(trim((string)($entrada['busca'] ?? '')), 0, 100),
    'responsavel' => in_array((string)($entrada['responsavel'] ?? ''), ['EU', 'SEM'], true) ? (string)$entrada['responsavel'] : '',
    'vencimento' => in_array((string)($entrada['vencimento'] ?? ''), ['VENCIDOS', '30', '90'], true) ? (string)$entrada['vencimento'] : '',
];
$chamar = static function (string $sql, array $p) use ($pdo): array {
    $s = $pdo->prepare($sql); $s->execute($p);
    $conjuntos = [];
    do { try { $conjuntos[] = $s->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException) { } } while ($s->nextRowset());
    $s->closeCursor();
    return $conjuntos;
};
$itens = static function (array $f, ?int $fornecedor = null) use ($chamar, $usuario, $modoAcesso): array {
    $r = $chamar('CALL sp_fo_painel_itens(?, ?, ?, ?, ?, ?, ?, ?)', [$usuario, $modoAcesso, $f['unidade'], $f['tipo'], $f['busca'], $f['responsavel'], $f['vencimento'], $fornecedor]);
    return ['itens' => $r[0] ?? [], 'fornecedores' => $r[1][0] ?? ['ATIVOS' => 0, 'DESATIVADOS' => 0]];
};

// ---------- Consultas prontas, cores, lista e resumo (mesmas regras da planilha exportada) ----------
require dirname(__DIR__) . '/classes/HomologacaoPainelConsultas.php';

// ---------- Ações ----------
if ($acao !== '') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($acao === 'dados') {
            $r = $itens($filtros);
            $res = $resumir($r['itens'], $r['fornecedores']);
            $consulta = (string)($entrada['consulta'] ?? 'vencidos');
            [$c, $sel] = $aplicarConsulta($marcarOutras($r['itens'], $res['unidadesPrincipais']), $consulta);
            if (!$c['fn']) { $consulta = 'vencidos'; [$c, $sel] = $aplicarConsulta($r['itens'], $consulta); }
            $ordenar($sel);
            // c457: grid padrão — filtro por coluna (contém, sem acento) e ordenação por coluna, no servidor (valem para todas as páginas).
            $linhas = array_map($linhaSaida, $sel);
            $semAcento = static fn(string $v): string => mb_strtolower(strtr($v, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','Á'=>'a','À'=>'a','Â'=>'a','Ã'=>'a','É'=>'e','Ê'=>'e','Í'=>'i','Ó'=>'o','Ô'=>'o','Õ'=>'o','Ú'=>'u','Ç'=>'c']));
            $colunasGrid = ['unidade', 'fornecedor', 'item', 'situacao', 'validade', 'dias', 'responsavel'];
            $filtroColunas = json_decode((string)($entrada['colunas'] ?? ''), true);
            $filtroColunas = is_array($filtroColunas) ? array_intersect_key(array_filter(array_map(static fn($v) => is_scalar($v) ? trim((string)$v) : '', $filtroColunas), 'strlen'), array_flip($colunasGrid)) : [];
            $valorColuna = static fn(array $l, string $c): string => $c === 'item' ? $l['item'] . ' ' . $l['categoria'] : (string)$l[$c];
            // Busca no grid (todas as colunas da lista).
            $buscaGrid = $semAcento(mb_substr(trim((string)($entrada['busca_grid'] ?? '')), 0, 100));
            if ($buscaGrid !== '') $linhas = array_values(array_filter($linhas, static function (array $l) use ($buscaGrid, $semAcento, $colunasGrid, $valorColuna): bool {
                foreach ($colunasGrid as $c) if (str_contains($semAcento($valorColuna($l, $c)), $buscaGrid)) return true;
                return false;
            }));
            if ($filtroColunas) $linhas = array_values(array_filter($linhas, static function (array $l) use ($filtroColunas, $semAcento, $valorColuna): bool {
                foreach ($filtroColunas as $c => $v) if (!str_contains($semAcento($valorColuna($l, $c)), $semAcento($v))) return false;
                return true;
            }));
            $ordemCol = in_array((string)($entrada['ordem_col'] ?? ''), $colunasGrid, true) ? (string)$entrada['ordem_col'] : '';
            $ordemDir = (string)($entrada['ordem_dir'] ?? '') === 'desc' ? -1 : 1;
            if ($ordemCol !== '') {
                $chave = static fn(array $l) => match ($ordemCol) {
                    'dias' => $l['dias'] === '' ? PHP_INT_MAX : (int)$l['dias'],
                    'validade' => preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $l['validade'], $m) ? "$m[3]$m[2]$m[1]" : '99999999',
                    default => $semAcento($valorColuna($l, $ordemCol)),
                };
                usort($linhas, static fn($a, $b) => (is_int($chave($a)) ? $chave($a) <=> $chave($b) : strnatcmp((string)$chave($a), (string)$chave($b))) * $ordemDir);
            }
            $pagina = max(1, (int)($entrada['pagina'] ?? 1));
            $total = count($linhas);
            $paginas = max(1, (int)ceil($total / PAINEL_POR_PAGINA));
            $pagina = min($pagina, $paginas);
            $res['lista'] = ['consulta' => $consulta, 'titulo' => $c['label'], 'descricao' => $c['descricao'], 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas,
                'linhas' => array_slice($linhas, ($pagina - 1) * PAINEL_POR_PAGINA, PAINEL_POR_PAGINA), 'ordem' => ['col' => $ordemCol, 'dir' => $ordemDir === -1 ? 'desc' : 'asc']];
            unset($res['unidadesPrincipais']);
            echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE); exit;
        }
        // c455: a antiga ação 'ficha' (JSON) virou a página homologacaofornecedores_ficha.php.
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $falhar(405, 'POST');
        if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) $falhar(419, IdiomaService::t('comum.erro.token', ['pt' => 'Token de segurança inválido. Atualize a tela.', 'en' => 'Invalid security token. Refresh the page.', 'es' => 'Token de seguridad inválido. Actualice la pantalla.']));
        if ($acao === 'exportar') {
            // Exportação para o Excel: só o pedido. A planilha é gerada pelo GreenPortal-ExportWorker (Agendador do Windows,
            // classes/HomologacaoPainelExportacao.php) com as mesmas regras da tela e chega nos alertas com o link.
            if (!$podeExportar) $falhar(403, IdiomaService::t('comum.permissao.negada', ['pt' => 'Perfil sem permissão de acesso!', 'en' => 'Profile does not have access permission!', 'es' => '¡El perfil no tiene permiso de acceso!']));
            $consulta = (string)($entrada['consulta'] ?? 'todos');
            if (!preg_match('/^[a-z0-9:]{1,40}$/', $consulta) || !$filtroConsulta($consulta)['fn']) $consulta = 'todos';
            require_once dirname(__DIR__) . '/classes/ExportacaoSolicitacao.php';
            $pedido = ExportacaoSolicitacao::solicitar($pdo, $usuario, PAINEL_TRANSACAO, IdiomaService::t('homologacaofornecedores_painel.exportacao.nome', ['pt' => 'Painel de Homologação', 'en' => 'Approval panel', 'es' => 'Panel de Homologación']) . ' (' . $filtroConsulta($consulta)['label'] . ')', [
                'filtros' => ['filtros' => $filtros, 'consulta' => $consulta, 'externo' => $externo ? 1 : 0, 'modo' => $modoAcesso, 'idioma' => $idioma], 'busca' => $filtros['busca']]);
            AuditService::record($pdo, PAINEL_TRANSACAO, 'EXPORTAR', $pedido > 0 ? 'SUCESSO' : 'ERRO', 'exportacoes_grid', $consulta, ['pedido' => $pedido, 'filtros' => $filtros]);
            if ($pedido <= 0) $falhar(500, IdiomaService::t('comum.erro.exportacao', ['pt' => 'Não foi possível solicitar a exportação.', 'en' => 'The export could not be requested.', 'es' => 'No fue posible solicitar la exportación.']));
            echo json_encode(['ok' => true, 'message' => ExportacaoSolicitacao::mensagem()], JSON_UNESCAPED_UNICODE); exit;
        }
        if ($acao === 'cobrar') {
            if (!$podeCobrar) $falhar(403, IdiomaService::t('comum.permissao.negada', ['pt' => 'Perfil sem permissão de acesso!', 'en' => 'Profile does not have access permission!', 'es' => '¡El perfil no tiene permiso de acceso!']));
            $r = $itens($filtros);
            $res = $resumir($r['itens'], $r['fornecedores']);
            $consulta = (string)($entrada['consulta'] ?? '');
            [$c, $sel] = $aplicarConsulta($marcarOutras($r['itens'], $res['unidadesPrincipais']), $consulta);
            if (!$c['fn']) $falhar(422, $t('erro.consulta', ['pt' => 'Escolha uma consulta.', 'en' => 'Choose a query.', 'es' => 'Elija una consulta.']));
            $porUsuario = [];
            foreach ($sel as $i) foreach (['CODUSUARIO_EMPRESA', 'CODUSUARIO_FORNECEDOR'] as $campo) {
                $u = (int)($i[$campo] ?? 0); if ($u > 0 && $u !== 99999) $porUsuario[$u] = ($porUsuario[$u] ?? 0) + 1;
            }
            $link = '/principal/' . PAINEL_TRANSACAO . '.php?responsavel=EU&consulta=' . rawurlencode($consulta);
            $alertas = 0;
            foreach ($porUsuario as $u => $n) {
                $chamar('CALL sp_alerta_criar(?, ?, ?, ?, ?)', [$u, 'Homologação: ' . $c['label'], "Há $n item(ns) em \"" . $c['label'] . '" sob sua responsabilidade. Abra o Painel de Homologação para ver a lista.', $link, 'NAO']);
                $alertas++;
            }
            AuditService::record($pdo, PAINEL_TRANSACAO, 'SALVAR', 'SUCESSO', 'alertas.cobranca', $consulta, ['usuarios' => array_keys($porUsuario), 'itens' => count($sel)]);
            echo json_encode(['ok' => true, 'usuarios' => $alertas, 'itens' => count($sel), 'sem_responsavel' => count(array_filter($sel, static fn($i) => (int)($i['CODUSUARIO_EMPRESA'] ?? 0) === 0 && in_array((int)($i['CODUSUARIO_FORNECEDOR'] ?? 0), [0, 99999], true)))], JSON_UNESCAPED_UNICODE); exit;
        }
        if ($acao === 'resumo') {
            $r = $chamar('CALL sp_fo_painel_resumo_preferencia(?, ?)', [$usuario, !empty($entrada['receber']) ? 1 : 0]);
            echo json_encode(['ok' => true, 'receber' => (int)($r[0][0]['RECEBER'] ?? 1) === 1], JSON_UNESCAPED_UNICODE); exit;
        }
        $falhar(400, $t('erro.acao', ['pt' => 'Ação inválida.', 'en' => 'Invalid action.', 'es' => 'Acción inválida.']));
    } catch (Throwable $e) {
        error_log('Painel de Homologação: ' . $e->getMessage());
        $falhar(500, IdiomaService::erro($e, 'consultar'));
    }
}

// ---------- Página ----------
AuditService::record($pdo, PAINEL_TRANSACAO, 'ACESSAR', 'SUCESSO', 'transacao', PAINEL_TRANSACAO);
if ($externo) {
    $unidades = [];
    foreach (MigracaoCrud::selecionar($pdo, 'vw_homologacao_fornecedores_usuario_grid', ['CODUSUARIO_ACESSO' => $usuario]) as $f)
        if ((int)($f['CODUNIDADE'] ?? 0) > 0) $unidades[(int)$f['CODUNIDADE']] = (string)($f['DSCUNIDADE'] ?? $f['CODUNIDADE']);
    $tipos = [];
} else {
    $unidades = [];
    // Interno restrito (316): só as unidades dele no filtro.
    foreach ($modoAcesso === 2 ? MigracaoCrud::selecionar($pdo, 'vw_homologacao_unidades_usuario_grid', ['CODUSUARIO_ACESSO' => $usuario]) : MigracaoCrud::selecionar($pdo, 'vw_unidades_grid') as $u) $unidades[(int)($u['CODUNIDADE'] ?? $u['codunidade'] ?? 0)] = (string)($u['DSCUNIDADE'] ?? $u['dscunidade'] ?? '');
    $tipos = [];
    foreach (MigracaoCrud::selecionar($pdo, 'vw_homologacao_tipos_fornecedores_grid') as $tp) $tipos[(int)($tp['codtipofornecedor'] ?? $tp['CODTIPOFORNECEDOR'] ?? 0)] = (string)($tp['DSCTIPO'] ?? '');
}
unset($unidades[0], $tipos[0]);
asort($unidades, SORT_NATURAL | SORT_FLAG_CASE); asort($tipos, SORT_NATURAL | SORT_FLAG_CASE);
$receberResumo = true;
try { $p = MigracaoCrud::primeiro($pdo, 'vw_fo_painel_resumo_preferencias', ['CODUSUARIO' => $usuario]); if ($p) $receberResumo = (int)$p['RECEBER'] === 1; } catch (Throwable) { }
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$titulo = $t('titulo', ['pt' => 'Painel de Homologação', 'en' => 'Supplier Approval Dashboard', 'es' => 'Panel de Homologación']);
$cfg = [
    'url' => basename(__FILE__), 'csrf' => $csrf, 'externo' => $externo, 'podeExportar' => $podeExportar, 'podeCobrar' => $podeCobrar,
    'receberResumo' => $receberResumo, 'usuario' => $usuario, 'documentosUrl' => 'homologacaofornecedor_fornecedorxcriterios.php', 'fichaUrl' => 'homologacaofornecedores_ficha.php', 'fichaTitulo' => $t('ficha.titulo', ['pt' => 'Ficha do fornecedor', 'en' => 'Supplier profile', 'es' => 'Ficha del proveedor']),
    'inicial' => ['consulta' => preg_match('/^[a-z0-9:]{1,40}$/', (string)($_GET['consulta'] ?? '')) ? (string)$_GET['consulta'] : '', 'responsavel' => $filtros['responsavel']],
];
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
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?><style>.dash-subabas-nav{display:flex;flex-wrap:wrap;gap:4px;border-bottom:1px solid #d9e6df;margin-bottom:8px}.dash-subaba{border:1px solid transparent;border-bottom:0;background:none;padding:6px 12px;border-radius:8px 8px 0 0;color:#4f675d;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:5px;margin-bottom:-1px}.dash-subaba:hover{color:#1f4d3a;background:#f2f8f5}.dash-subaba.active{background:#fff;border-color:#d9e6df;color:#1f4d3a}.painel-subabas .painel-graficos{margin-top:0}.painel-subabas .painel-cartao-matriz{margin:0}</style></head>
<body class="painel-body">
<?php greenPortalCabecalhoImpressao(IdiomaService::t('homologacaofornecedores_painel.impressao.titulo', ['pt' => 'Painel de homologação de fornecedores', 'en' => 'Supplier approval panel', 'es' => 'Panel de homologación de proveedores'])); ?>
<main class="painel" data-painel>
  <header class="painel-cab">
    <div>
      <div class="painel-trilha"><?= $e($t('trilha', ['pt' => 'Homologação de Fornecedores › Relatórios gerenciais', 'en' => 'Supplier Approval › Management reports', 'es' => 'Homologación de Proveedores › Informes gerenciales'])) ?></div>
      <h1 class="painel-titulo"><?= $e($titulo) ?></h1>
      <div class="painel-atualizado"><?= $e($t('atualizado', ['pt' => 'Dados de', 'en' => 'Data as of', 'es' => 'Datos de'])) ?> <span data-atualizado></span><?php if ($externo): ?> · <span class="painel-selo"><?= $e($t('selo_externo', ['pt' => 'Somente os seus fornecedores', 'en' => 'Only your suppliers', 'es' => 'Solo sus proveedores'])) ?></span><?php endif; ?></div>
    </div>
    <div class="painel-acoes">
      <?php if ($podeExportar): ?>
      <button type="button" class="btn painel-btn" data-exportar-tudo><i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i><?= $e($t('botao.exportar_tudo', ['pt' => 'Exportar Excel', 'en' => 'Export to Excel', 'es' => 'Exportar Excel'])) ?></button>
      <button type="button" class="btn painel-btn" data-imprimir><i class="bi bi-filetype-pdf" aria-hidden="true"></i><?= $e($t('botao.pdf', ['pt' => 'Gerar PDF', 'en' => 'Create PDF', 'es' => 'Generar PDF'])) ?></button>
      <?php endif; ?>
      <button type="button" class="btn painel-btn painel-btn-primario" data-resumo aria-pressed="<?= $receberResumo ? 'true' : 'false' ?>"><i class="bi bi-envelope" aria-hidden="true"></i><span data-resumo-texto></span></button>
      <button type="button" class="btn painel-btn" data-open-transacao-ajuda data-help-transaction="<?= $e(PAINEL_TRANSACAO) ?>" title="<?= $e($t('botao.ajuda', ['pt' => 'Ajuda', 'en' => 'Help', 'es' => 'Ayuda'])) ?>" aria-label="<?= $e($t('botao.ajuda', ['pt' => 'Ajuda', 'en' => 'Help', 'es' => 'Ayuda'])) ?>"><i class="bi bi-question-circle" aria-hidden="true"></i></button>
    </div>
  </header>

  <form class="painel-filtros" data-filtros aria-label="<?= $e($t('filtros', ['pt' => 'Filtros', 'en' => 'Filters', 'es' => 'Filtros'])) ?>">
    <label><?= $e($t('filtro.busca', ['pt' => 'Fornecedor ou CNPJ', 'en' => 'Supplier or tax ID', 'es' => 'Proveedor o CNPJ'])) ?>
      <input type="search" name="busca" class="form-control" autocomplete="off" placeholder="<?= $e($t('filtro.busca_dica', ['pt' => 'Parte do nome ou do CNPJ', 'en' => 'Part of the name or tax ID', 'es' => 'Parte del nombre o del CNPJ'])) ?>"></label>
    <label><?= $e($t('filtro.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'])) ?>
      <select name="unidade" class="form-select"><option value=""><?= $e($t('filtro.todas_unidades', ['pt' => 'Todas as unidades', 'en' => 'All units', 'es' => 'Todas las unidades'])) ?></option><?php foreach ($unidades as $k => $v): ?><option value="<?= (int)$k ?>"><?= $e($v) ?></option><?php endforeach; ?></select></label>
    <?php if (!$externo): ?>
    <label><?= $e($t('filtro.tipo', ['pt' => 'Tipo de fornecedor', 'en' => 'Supplier type', 'es' => 'Tipo de proveedor'])) ?>
      <select name="tipo" class="form-select"><option value=""><?= $e($t('filtro.todos_tipos', ['pt' => 'Todos os tipos', 'en' => 'All types', 'es' => 'Todos los tipos'])) ?></option><?php foreach ($tipos as $k => $v): ?><option value="<?= (int)$k ?>"><?= $e($v) ?></option><?php endforeach; ?></select></label>
    <?php endif; ?>
    <label><?= $e($t('filtro.responsavel', ['pt' => 'Responsável', 'en' => 'Owner', 'es' => 'Responsable'])) ?>
      <select name="responsavel" class="form-select"><option value=""><?= $e($t('filtro.todos', ['pt' => 'Todos', 'en' => 'All', 'es' => 'Todos'])) ?></option><option value="EU"><?= $e($t('filtro.eu', ['pt' => 'Sou eu', 'en' => 'Me', 'es' => 'Soy yo'])) ?></option><option value="SEM"><?= $e($t('filtro.sem', ['pt' => 'Sem responsável', 'en' => 'No owner', 'es' => 'Sin responsable'])) ?></option></select></label>
    <label><?= $e($t('filtro.vencimento', ['pt' => 'Vencimento', 'en' => 'Expiry', 'es' => 'Vencimiento'])) ?>
      <select name="vencimento" class="form-select"><option value=""><?= $e($t('filtro.qualquer', ['pt' => 'Qualquer data', 'en' => 'Any date', 'es' => 'Cualquier fecha'])) ?></option><option value="VENCIDOS"><?= $e($t('filtro.vencidos', ['pt' => 'Já vencidos', 'en' => 'Already expired', 'es' => 'Ya vencidos'])) ?></option><option value="30"><?= $e($t('filtro.30', ['pt' => 'Próximos 30 dias', 'en' => 'Next 30 days', 'es' => 'Próximos 30 días'])) ?></option><option value="90"><?= $e($t('filtro.90', ['pt' => 'Próximos 90 dias', 'en' => 'Next 90 days', 'es' => 'Próximos 90 días'])) ?></option></select></label>
    <div class="painel-filtros-botoes">
      <button type="button" class="btn painel-btn" data-limpar><?= $e($t('botao.limpar', ['pt' => 'Limpar', 'en' => 'Clear', 'es' => 'Limpiar'])) ?></button>
      <button type="button" class="btn painel-btn painel-btn-suave" data-salvar-filtro><?= $e($t('botao.salvar_filtro', ['pt' => 'Salvar filtro', 'en' => 'Save filter', 'es' => 'Guardar filtro'])) ?></button>
    </div>
  </form>

  <div class="painel-status" data-status aria-live="polite"></div>

  <section class="painel-kpis" data-kpis aria-label="<?= $e($t('indicadores', ['pt' => 'Indicadores', 'en' => 'Indicators', 'es' => 'Indicadores'])) ?>"></section>

  <div class="painel-corpo">
    <nav class="painel-cartao painel-consultas" aria-label="<?= $e($t('consultas', ['pt' => 'Consultas prontas', 'en' => 'Ready-made queries', 'es' => 'Consultas listas'])) ?>">
      <h2><?= $e($t('consultas', ['pt' => 'Consultas prontas', 'en' => 'Ready-made queries', 'es' => 'Consultas listas'])) ?></h2>
      <div data-consultas></div>
    </nav>

    <div class="painel-principal">
      <div class="dash-subabas painel-subabas" data-subabas="painel-homologacao" data-lembrar><nav class="dash-subabas-nav" role="tablist">
        <button type="button" role="tab" class="dash-subaba active" data-subaba="resultado"><i class="bi bi-table"></i> <?= $e($t('resultado', ['pt' => 'Resultado da consulta', 'en' => 'Query result', 'es' => 'Resultado de la consulta'])) ?></button>
        <button type="button" role="tab" class="dash-subaba" data-subaba="graficos"><i class="bi bi-bar-chart"></i> <?= $e($t('aba.graficos', ['pt' => 'Gráficos', 'en' => 'Charts', 'es' => 'Gráficos'])) ?></button>
        <button type="button" role="tab" class="dash-subaba" data-subaba="matriz"><i class="bi bi-grid-3x3"></i> <?= $e($t('g.matriz', ['pt' => 'Unidade × situação', 'en' => 'Unit × status', 'es' => 'Unidad × situación'])) ?></button>
      </nav>
      <div class="dash-subaba-painel" data-subaba-painel="resultado">
      <section class="painel-cartao painel-lista" aria-label="<?= $e($t('resultado', ['pt' => 'Resultado da consulta', 'en' => 'Query result', 'es' => 'Resultado de la consulta'])) ?>">
        <div class="painel-lista-cab">
          <div><h2 data-lista-titulo></h2><div class="painel-mudo" data-lista-descricao></div></div>
          <div class="painel-acoes">
            <?php if ($podeExportar): ?><button type="button" class="btn painel-btn painel-btn-p" data-exportar-lista><i class="bi bi-download" aria-hidden="true"></i><?= $e($t('botao.exportar_lista', ['pt' => 'Exportar esta lista', 'en' => 'Export this list', 'es' => 'Exportar esta lista'])) ?></button><?php endif; ?>
            <?php if ($podeCobrar): ?><button type="button" class="btn painel-btn painel-btn-p" data-cobrar><i class="bi bi-bell" aria-hidden="true"></i><?= $e($t('botao.cobrar', ['pt' => 'Cobrar responsáveis', 'en' => 'Remind owners', 'es' => 'Cobrar responsables'])) ?></button><?php endif; ?>
          </div>
        </div>
        <div class="menu-grid-fragment grid-ready painel-grid">
          <div class="crud-toolbar menu-grid-toolbar painel-grid-barra">
            <nav class="grid-pagination" aria-label="<?= $e(IdiomaService::t('comum.paginacao.rotulo', ['pt' => 'Paginação', 'en' => 'Pagination', 'es' => 'Paginación'])) ?>"><button type="button" class="btn btn-sm btn-outline-secondary grid-page-prev" data-pag="-1" title="<?= $e($t('pag.anterior', ['pt' => 'Página anterior', 'en' => 'Previous page', 'es' => 'Página anterior'])) ?>" aria-label="<?= $e($t('pag.anterior', ['pt' => 'Página anterior', 'en' => 'Previous page', 'es' => 'Página anterior'])) ?>"><i class="bi bi-chevron-left"></i></button><span class="grid-page-status" data-pagina></span><button type="button" class="btn btn-sm btn-outline-secondary grid-page-next" data-pag="1" title="<?= $e($t('pag.proxima', ['pt' => 'Próxima página', 'en' => 'Next page', 'es' => 'Página siguiente'])) ?>" aria-label="<?= $e($t('pag.proxima', ['pt' => 'Próxima página', 'en' => 'Next page', 'es' => 'Página siguiente'])) ?>"><i class="bi bi-chevron-right"></i></button></nav>
            <div class="crud-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" data-busca-grid placeholder="<?= $e(IdiomaService::t('comum.busca.grid', ['pt' => 'Buscar no grid', 'en' => 'Search the grid', 'es' => 'Buscar en la cuadrícula'])) ?>" aria-label="<?= $e(IdiomaService::t('comum.busca.grid', ['pt' => 'Buscar no grid', 'en' => 'Search the grid', 'es' => 'Buscar en la cuadrícula'])) ?>"></div>
          </div>
          <div class="menu-grid-scroll painel-grid-scroll">
          <table class="table table-sm menu-batch-grid" id="painelHomologacaoGrid">
            <thead><tr>
              <th data-column="unidade"><div class="column-heading"><span><?= $e($t('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'])) ?></span><input class="column-filter" type="search" data-coluna-filtro="unidade" placeholder="<?= $e(IdiomaService::t('comum.busca.coluna', ['pt' => 'Buscar', 'en' => 'Search', 'es' => 'Buscar'])) ?>" aria-label="<?= $e($t('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'])) ?>"></div></th>
              <th data-column="fornecedor"><div class="column-heading"><span><?= $e($t('col.fornecedor', ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor'])) ?></span><input class="column-filter" type="search" data-coluna-filtro="fornecedor" placeholder="<?= $e(IdiomaService::t('comum.busca.coluna', ['pt' => 'Buscar', 'en' => 'Search', 'es' => 'Buscar'])) ?>" aria-label="<?= $e($t('col.fornecedor', ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor'])) ?>"></div></th>
              <th class="grid-row-actions-heading"><div class="column-heading"><span><?= $e($t('col.ficha', ['pt' => 'Ficha', 'en' => 'Profile', 'es' => 'Ficha'])) ?></span></div></th>
              <th data-column="item"><div class="column-heading"><span><?= $e($t('col.item', ['pt' => 'Item', 'en' => 'Item', 'es' => 'Ítem'])) ?></span><input class="column-filter" type="search" data-coluna-filtro="item" placeholder="<?= $e(IdiomaService::t('comum.busca.coluna', ['pt' => 'Buscar', 'en' => 'Search', 'es' => 'Buscar'])) ?>" aria-label="<?= $e($t('col.item', ['pt' => 'Item', 'en' => 'Item', 'es' => 'Ítem'])) ?>"></div></th>
              <th data-column="situacao"><div class="column-heading"><span><?= $e($t('col.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación'])) ?></span><input class="column-filter" type="search" data-coluna-filtro="situacao" placeholder="<?= $e(IdiomaService::t('comum.busca.coluna', ['pt' => 'Buscar', 'en' => 'Search', 'es' => 'Buscar'])) ?>" aria-label="<?= $e($t('col.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación'])) ?>"></div></th>
              <th data-column="validade"><div class="column-heading"><span><?= $e($t('col.validade', ['pt' => 'Validade', 'en' => 'Expiry', 'es' => 'Vencimiento'])) ?></span><input class="column-filter" type="search" data-coluna-filtro="validade" placeholder="<?= $e(IdiomaService::t('comum.busca.coluna', ['pt' => 'Buscar', 'en' => 'Search', 'es' => 'Buscar'])) ?>" aria-label="<?= $e($t('col.validade', ['pt' => 'Validade', 'en' => 'Expiry', 'es' => 'Vencimiento'])) ?>"></div></th>
              <th data-column="dias"><div class="column-heading"><span><?= $e($t('col.dias', ['pt' => 'Dias', 'en' => 'Days', 'es' => 'Días'])) ?></span><input class="column-filter" type="search" data-coluna-filtro="dias" placeholder="<?= $e(IdiomaService::t('comum.busca.coluna', ['pt' => 'Buscar', 'en' => 'Search', 'es' => 'Buscar'])) ?>" aria-label="<?= $e($t('col.dias', ['pt' => 'Dias', 'en' => 'Days', 'es' => 'Días'])) ?>"></div></th>
              <th data-column="responsavel"><div class="column-heading"><span><?= $e($t('col.responsavel', ['pt' => 'Responsável (empresa / fornecedor)', 'en' => 'Owner (company / supplier)', 'es' => 'Responsable (empresa / proveedor)'])) ?></span><input class="column-filter" type="search" data-coluna-filtro="responsavel" placeholder="<?= $e(IdiomaService::t('comum.busca.coluna', ['pt' => 'Buscar', 'en' => 'Search', 'es' => 'Buscar'])) ?>" aria-label="<?= $e($t('col.responsavel', ['pt' => 'Responsável (empresa / fornecedor)', 'en' => 'Owner (company / supplier)', 'es' => 'Responsable (empresa / proveedor)'])) ?>"></div></th>
            </tr></thead>
            <tbody data-linhas></tbody>
          </table>
          </div>
          <div class="painel-paginacao">
            <span class="painel-mudo" data-rodape></span>
  
          </div>
        </div>
      </section>
      </div>
      <div class="dash-subaba-painel" data-subaba-painel="graficos" hidden>
      <div class="painel-graficos">
        <section class="painel-cartao" aria-label="<?= $e($t('g.situacao', ['pt' => 'Documentos por situação', 'en' => 'Documents by status', 'es' => 'Documentos por situación'])) ?>">
          <h2><?= $e($t('g.situacao', ['pt' => 'Documentos por situação', 'en' => 'Documents by status', 'es' => 'Documentos por situación'])) ?></h2>
          <div data-g-situacao></div>
        </section>
        <section class="painel-cartao" aria-label="<?= $e($t('g.semanas', ['pt' => 'Vencimentos nas próximas 12 semanas', 'en' => 'Expiries in the next 12 weeks', 'es' => 'Vencimientos en las próximas 12 semanas'])) ?>">
          <h2><?= $e($t('g.semanas', ['pt' => 'Vencimentos nas próximas 12 semanas', 'en' => 'Expiries in the next 12 weeks', 'es' => 'Vencimientos en las próximas 12 semanas'])) ?></h2>
          <div class="painel-semanas" data-g-semanas></div>
          <div class="painel-mudo painel-pequeno"><?= $e($t('g.semanas_dica', ['pt' => 'Amarelo: semana com documentos dentro dos dias de reação. Clique numa semana para listar.', 'en' => 'Yellow: week with documents within the reaction days. Click a week to list.', 'es' => 'Amarillo: semana con documentos en los días de reacción. Haga clic en una semana para listar.'])) ?></div>
        </section>
        <section class="painel-cartao" aria-label="<?= $e($t('g.top', ['pt' => 'Fornecedores com mais pendências', 'en' => 'Suppliers with most pending items', 'es' => 'Proveedores con más pendientes'])) ?>">
          <h2><?= $e($t('g.top', ['pt' => 'Fornecedores com mais pendências', 'en' => 'Suppliers with most pending items', 'es' => 'Proveedores con más pendientes'])) ?></h2>
          <div data-g-top></div>
        </section>
      </div>
      </div>
      <div class="dash-subaba-painel" data-subaba-painel="matriz" hidden>
        <section class="painel-cartao painel-cartao-matriz" aria-label="<?= $e($t('g.matriz', ['pt' => 'Unidade × situação', 'en' => 'Unit × status', 'es' => 'Unidad × situación'])) ?>">
          <h2><?= $e($t('g.matriz', ['pt' => 'Unidade × situação', 'en' => 'Unit × status', 'es' => 'Unidad × situación'])) ?></h2>
          <div class="painel-matriz-caixa"><div class="painel-matriz" data-g-matriz></div></div>
        </section>
      </div>
      </div>

      <section class="painel-cartao painel-legenda" aria-label="<?= $e($t('legenda', ['pt' => 'Regra única de cores', 'en' => 'Single color rule', 'es' => 'Regla única de colores'])) ?>">
        <strong><?= $e($t('legenda', ['pt' => 'Regra única de cores', 'en' => 'Single color rule', 'es' => 'Regla única de colores'])) ?></strong>
        <span><i class="painel-bola cor-VERMELHO"></i><?= $e($t('legenda.vermelho', ['pt' => 'Vermelho: reprovado, vencido ou pendente com o fornecedor', 'en' => 'Red: rejected, expired or pending with the supplier', 'es' => 'Rojo: reprobado, vencido o pendiente con el proveedor'])) ?></span>
        <span><i class="painel-bola cor-AMARELO"></i><?= $e($t('legenda.amarelo', ['pt' => 'Amarelo: pendente com a empresa ou vence nos dias de reação (30)', 'en' => 'Yellow: pending with the company or expiring within the reaction days (30)', 'es' => 'Amarillo: pendiente con la empresa o vence en los días de reacción (30)'])) ?></span>
        <span><i class="painel-bola cor-VERDE"></i><?= $e($t('legenda.verde', ['pt' => 'Verde: aprovado, aprovado sem validade ou ciente', 'en' => 'Green: approved, approved without expiry or aware', 'es' => 'Verde: aprobado, sin vencimiento o consciente'])) ?></span>
        <span><i class="painel-bola cor-NEUTRO"></i><?= $e($t('legenda.cinza', ['pt' => 'Cinza: não se aplica / não enviar', 'en' => 'Gray: not applicable / do not send', 'es' => 'Gris: no aplica / no enviar'])) ?></span>
        <span><i class="painel-bola cor-BRANCO"></i><?= $e($t('legenda.branco', ['pt' => 'Branco: sem situação', 'en' => 'White: no status', 'es' => 'Blanco: sin situación'])) ?></span>
      </section>
    </div>
  </div>
</main>

<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script>
<script>
window.greenPainel = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="../assets/js/subabas.js?v=2"></script>
<script src="../assets/js/homologacao-painel.js?v=8"></script>
<script src="../assets/js/ajuda-transacao.js?v=1"></script>
</body>
</html>
