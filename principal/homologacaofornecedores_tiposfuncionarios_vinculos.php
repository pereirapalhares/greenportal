<?php
declare(strict_types=1);

/*
 * Homologação de Fornecedores › Tipos de Funcionários › Critérios / Treinamentos / Palavras-chave
 * Popup aberto pelos botões da tela homologacaofornecedores_tiposfuncionarios.php (tipo selecionado no grid).
 * Equivale às abas do legado (correlacionamento.php, transações 144, 146 e 143): duas listas, disponíveis e vinculados.
 *
 * Leitura: CALL sp_fo_tipofuncionario_vinculos_listar(relacao, tipo).
 * Gravação: CALL sp_fo_tipofuncionario_vinculos_gravar(relacao, tipo, incluir JSON, remover JSON) — gravação imediata.
 * Permissão: menu HOMOLOGACAO_TIPOS_FUNCIONARIOS (visualizar para ver; editar para vincular/desvincular).
 * Objetos: migração 249.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/AuditService.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';

const VINC_MENU = 'HOMOLOGACAO_TIPOS_FUNCIONARIOS';
const VINC_TRANSACAO = 'homologacaofornecedores_tiposfuncionarios_vinculos';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$u = static fn(string $s): string => (string)json_decode('"' . $s . '"');
$t = static fn(string $chave, array $padrao): string => IdiomaService::t(VINC_TRANSACAO . '.' . $chave, $padrao);
$ajax = isset($_GET['acao']);
$falhar = static function (int $codigo, string $mensagem) use ($ajax): never {
    http_response_code($codigo);
    if ($ajax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'message' => $mensagem], JSON_UNESCAPED_UNICODE); }
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
$permissao = PermissaoTransacao::obter(VINC_MENU);
if (empty($permissao['pode_visualizar'])) $falhar(403, IdiomaService::t('comum.erro.sem_permissao', ['pt' => 'Seu perfil não tem permissão para acessar esta transação.', 'en' => 'Your profile is not allowed to access this transaction.', 'es' => 'Su perfil no tiene permiso para acceder a esta transacción.']));
$podeEditar = !empty($permissao['pode_editar']);

$relacoes = [
    'CRITERIOS' => ['titulo' => $t('titulo.criterios', ['pt' => $u('Critérios'), 'en' => 'Criteria', 'es' => 'Criterios']), 'icone' => 'bi-list-check'],
    'TREINAMENTOS' => ['titulo' => $t('titulo.treinamentos', ['pt' => 'Treinamentos', 'en' => 'Training', 'es' => 'Capacitaciones']), 'icone' => 'bi-mortarboard'],
    'PALAVRASCHAVE' => ['titulo' => $t('titulo.palavraschave', ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave']), 'icone' => 'bi-tags'],
];
$relacao = strtoupper(trim((string)($_GET['relacao'] ?? '')));
if (!isset($relacoes[$relacao])) $falhar(400, $t('erro.relacao', ['pt' => 'Relação inválida.', 'en' => 'Invalid relation.', 'es' => 'Relación inválida.']));
$filtros = json_decode((string)($_GET['filtros'] ?? '{}'), true);
$codtipo = filter_var($_GET['CODTIPOFUNCIONARIO'] ?? (is_array($filtros) ? ($filtros['CODTIPOFUNCIONARIO'] ?? null) : null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$codtipo) $falhar(400, $t('erro.tipo', ['pt' => 'Selecione um tipo de funcionário já salvo.', 'en' => 'Select an employee type that has been saved.', 'es' => 'Seleccione un tipo de empleado ya guardado.']));

$chamar = static function (string $sql, array $p) use ($pdo): array {
    $s = $pdo->prepare($sql); $s->execute($p);
    $conjuntos = [];
    do { try { $conjuntos[] = $s->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException) { } } while ($s->nextRowset());
    $s->closeCursor();
    return $conjuntos;
};
$listar = static function () use ($chamar, $relacao, $codtipo): array {
    $r = $chamar('CALL sp_fo_tipofuncionario_vinculos_listar(?, ?)', [$relacao, $codtipo]);
    return ['tipo' => $r[0][0] ?? [], 'itens' => array_map(static fn($i) => ['codigo' => (string)$i['codigo'], 'descricao' => (string)$i['descricao'], 'vinculado' => (int)$i['vinculado'] === 1], $r[1] ?? [])];
};

if ($ajax) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($_GET['acao'] === 'listar') {
            echo json_encode(['ok' => true] + $listar(), JSON_UNESCAPED_UNICODE); exit;
        }
        if ($_GET['acao'] === 'gravar' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            if (!$podeEditar) $falhar(403, IdiomaService::t('comum.permissao.negada', ['pt' => 'Perfil sem permissão de acesso!', 'en' => 'Profile does not have access permission!', 'es' => '¡El perfil no tiene permiso de acceso!']));
            $corpo = json_decode((string)file_get_contents('php://input'), true);
            $lista = static fn($v): array => array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : []), static fn($n) => $n !== 0)));
            $incluir = $lista($corpo['incluir'] ?? []); $remover = $lista($corpo['remover'] ?? []);
            $r = $chamar('CALL sp_fo_tipofuncionario_vinculos_gravar(?, ?, ?, ?)', [$relacao, $codtipo, json_encode($incluir), json_encode($remover)]);
            $res = $r[0][0] ?? ['incluidos' => 0, 'removidos' => 0];
            AuditService::record($pdo, VINC_TRANSACAO, 'SALVAR', 'SUCESSO', 'fo_tipofuncionarios.' . strtolower($relacao), (string)$codtipo, ['incluir' => $incluir, 'remover' => $remover, 'resultado' => $res]);
            echo json_encode(['ok' => true, 'incluidos' => (int)$res['incluidos'], 'removidos' => (int)$res['removidos']] + $listar(), JSON_UNESCAPED_UNICODE); exit;
        }
        $falhar(400, $t('erro.acao', ['pt' => 'Ação inválida.', 'en' => 'Invalid action.', 'es' => 'Acción inválida.']));
    } catch (Throwable $e) {
        AuditService::record($pdo, VINC_TRANSACAO, 'SALVAR', 'ERRO', 'fo_tipofuncionarios.' . strtolower($relacao), (string)$codtipo, ['mensagem' => mb_substr($e->getMessage(), 0, 300)]);
        $falhar(422, IdiomaService::erro($e, 'salvar'));
    }
}

AuditService::record($pdo, VINC_TRANSACAO, 'ACESSAR', 'SUCESSO', 'transacao', $relacao . ':' . $codtipo);
try { $inicial = $listar(); } catch (Throwable $e) { $falhar(404, IdiomaService::erro($e, 'consultar')); }
$cfg = $relacoes[$relacao];
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$textos = [
    'disponiveis' => $t('lista.disponiveis', ['pt' => $u('Disponíveis'), 'en' => 'Available', 'es' => 'Disponibles']),
    'vinculados' => $t('lista.vinculados', ['pt' => 'Vinculados ao tipo', 'en' => 'Linked to the type', 'es' => 'Vinculados al tipo']),
    'filtrar' => $t('lista.filtrar', ['pt' => 'Filtrar...', 'en' => 'Filter...', 'es' => 'Filtrar...']),
    'vazio' => $t('lista.vazio', ['pt' => 'Nenhum item.', 'en' => 'No items.', 'es' => 'Ningún ítem.']),
    'vincular' => $t('botao.vincular', ['pt' => 'Vincular marcados', 'en' => 'Link checked', 'es' => 'Vincular marcados']),
    'vincular_todos' => $t('botao.vincular_todos', ['pt' => 'Vincular todos os exibidos', 'en' => 'Link all shown', 'es' => 'Vincular todos los mostrados']),
    'desvincular' => $t('botao.desvincular', ['pt' => 'Desvincular marcados', 'en' => 'Unlink checked', 'es' => 'Desvincular marcados']),
    'desvincular_todos' => $t('botao.desvincular_todos', ['pt' => 'Desvincular todos os exibidos', 'en' => 'Unlink all shown', 'es' => 'Desvincular todos los mostrados']),
    'confirmar_todos' => $t('msg.confirmar_todos', ['pt' => 'Desvincular {n} itens deste tipo? Clique de novo para confirmar.', 'en' => 'Unlink {n} items from this type? Click again to confirm.', 'es' => '¿Desvincular {n} ítems de este tipo? Haga clic de nuevo para confirmar.']),
    'gravado' => $t('msg.gravado', ['pt' => 'Gravado: {i} vinculados, {r} desvinculados.', 'en' => 'Saved: {i} linked, {r} unlinked.', 'es' => 'Guardado: {i} vinculados, {r} desvinculados.']),
    'gravando' => $t('msg.gravando', ['pt' => 'Gravando...', 'en' => 'Saving...', 'es' => 'Guardando...']),
    'erro' => $t('msg.erro', ['pt' => 'Não foi possível gravar.', 'en' => 'Could not save.', 'es' => 'No fue posible guardar.']),
    'contagem' => $t('lista.contagem', ['pt' => '{exibidos} de {total}', 'en' => '{exibidos} of {total}', 'es' => '{exibidos} de {total}']),
    'consulta' => $t('msg.consulta', ['pt' => 'Consulta: seu perfil não pode alterar os vínculos.', 'en' => 'Read-only: your profile cannot change the links.', 'es' => 'Consulta: su perfil no puede modificar los vínculos.']),
];
$idioma = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';
?><!doctype html>
<html lang="<?= $e($idioma === 'pt' ? 'pt-BR' : $idioma) ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($cfg['titulo']) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/bootstrap-icons.css?v=2">
<link rel="stylesheet" href="../assets/css/app.css?v=1">
<style>
  .vinc-wrap { display:grid; grid-template-columns:minmax(0, 1fr) auto minmax(0, 1fr); gap:.75rem; align-items:stretch; min-height:0; }
  .vinc-painel { display:flex; flex-direction:column; min-height:0; border:1px solid var(--bs-border-color); border-radius:.375rem; background:var(--bs-body-bg); }
  .vinc-cab { padding:.5rem .75rem; border-bottom:1px solid var(--bs-border-color); display:flex; justify-content:space-between; align-items:center; gap:.5rem; }
  .vinc-lista { overflow:auto; height:calc(100vh - 190px); min-height:200px; margin:0; padding:0; list-style:none; }
  .vinc-lista li { display:flex; gap:.5rem; align-items:flex-start; padding:.3rem .75rem; border-bottom:1px solid var(--bs-border-color-translucent); cursor:pointer; }
  .vinc-lista li:hover { background:var(--bs-tertiary-bg); }
  .vinc-lista li input { margin-top:.2rem; flex:none; }
  .vinc-acoes { display:flex; flex-direction:column; justify-content:center; gap:.5rem; }
  @media (max-width:640px) { .vinc-wrap { grid-template-columns:1fr; } .vinc-acoes { flex-direction:row; justify-content:center; } .vinc-acoes .bi { display:inline-block; transform:rotate(90deg); } .vinc-lista { height:35vh; } }
</style>
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?></head>
<body class="p-3 bg-light">
<main class="container-fluid" data-vinculos data-podeeditar="<?= $podeEditar ? '1' : '0' ?>">
  <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
    <div>
      <h5 class="mb-1"><i class="bi <?= $e($cfg['icone']) ?> me-2"></i><?= $e($cfg['titulo']) ?></h5>
      <div class="text-muted small"><?= $e($inicial['tipo']['DSCTIPO'] ?? '') ?></div>
    </div>
    <div class="small text-end" data-status aria-live="polite"><?php if (!$podeEditar): ?><span class="badge text-bg-secondary"><?= $e($textos['consulta']) ?></span><?php endif; ?></div>
  </div>
  <div class="vinc-wrap">
    <section class="vinc-painel" data-painel="disponiveis">
      <div class="vinc-cab"><strong><?= $e($textos['disponiveis']) ?></strong><span class="badge text-bg-light border" data-contagem></span></div>
      <div class="p-2 border-bottom"><input type="search" class="form-control form-control-sm" data-filtro placeholder="<?= $e($textos['filtrar']) ?>" aria-label="<?= $e($textos['filtrar']) ?>"></div>
      <ul class="vinc-lista" data-lista></ul>
    </section>
    <div class="vinc-acoes">
      <button type="button" class="btn btn-sm btn-primary" data-mover="vincular" title="<?= $e($textos['vincular']) ?>" aria-label="<?= $e($textos['vincular']) ?>"><i class="bi bi-chevron-right"></i></button>
      <button type="button" class="btn btn-sm btn-outline-primary" data-mover="vincular_todos" title="<?= $e($textos['vincular_todos']) ?>" aria-label="<?= $e($textos['vincular_todos']) ?>"><i class="bi bi-chevron-double-right"></i></button>
      <button type="button" class="btn btn-sm btn-outline-danger" data-mover="desvincular_todos" title="<?= $e($textos['desvincular_todos']) ?>" aria-label="<?= $e($textos['desvincular_todos']) ?>"><i class="bi bi-chevron-double-left"></i></button>
      <button type="button" class="btn btn-sm btn-danger" data-mover="desvincular" title="<?= $e($textos['desvincular']) ?>" aria-label="<?= $e($textos['desvincular']) ?>"><i class="bi bi-chevron-left"></i></button>
    </div>
    <section class="vinc-painel" data-painel="vinculados">
      <div class="vinc-cab"><strong><?= $e($textos['vinculados']) ?></strong><span class="badge text-bg-success" data-contagem></span></div>
      <div class="p-2 border-bottom"><input type="search" class="form-control form-control-sm" data-filtro placeholder="<?= $e($textos['filtrar']) ?>" aria-label="<?= $e($textos['filtrar']) ?>"></div>
      <ul class="vinc-lista" data-lista></ul>
    </section>
  </div>
</main>
<script>
window.greenVinculos = <?= json_encode(['itens' => $inicial['itens'], 'textos' => $textos, 'url' => 'homologacaofornecedores_tiposfuncionarios_vinculos.php?relacao=' . rawurlencode($relacao) . '&CODTIPOFUNCIONARIO=' . (int)$codtipo], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="../assets/js/homologacao-tipos-funcionarios-vinculos.js?v=1"></script>
</body>
</html>
