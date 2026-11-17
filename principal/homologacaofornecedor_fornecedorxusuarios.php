<?php
declare(strict_types=1);

/*
 * Homologação de Fornecedores › Gestão de Fornecedores › Usuários do fornecedor (Fornecedor x Usuários)
 * Popup aberto pelo botão "Usuários do fornecedor" (fornecedor selecionado). Legado: fo_fornecedorxusuario.php
 * (correlacionamento fo_fornecedorxusuario, aberto a partir do usuário). Duas listas: usuários disponíveis e ligados.
 * Leitura: CALL sp_fo_fornecedor_usuarios_listar(fornecedor). Gravação: CALL sp_fo_fornecedor_usuarios_gravar(fornecedor, incluir, remover).
 * Usuários com perfil FORNECEDOR / FORNECEDOR_GESTOR só veem os fornecedores ligados aqui; só usuários ligados podem ser
 * o responsável do fornecedor nos documentos. Objetos: migração 296.
 * Permissão: menu HOMOLOGACAO_FORNECEDOR_FORNECEDOR (visualizar para ver; editar para ligar/desligar). Não abre para o fornecedor externo.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/AuditService.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/HomologacaoFornecedorAcesso.php';

const FT_MENU = 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR';
const FT_TRANSACAO = 'homologacaofornecedor_fornecedorxusuarios';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$u = static fn(string $s): string => (string)json_decode('"' . $s . '"');
$t = static fn(string $chave, array $padrao): string => IdiomaService::t(FT_TRANSACAO . '.' . $chave, $padrao);
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
$permissao = PermissaoTransacao::obter(FT_MENU);
if (empty($permissao['pode_visualizar'])) $falhar(403, IdiomaService::t('comum.erro.sem_permissao', ['pt' => 'Seu perfil não tem permissão para acessar esta transação.', 'en' => 'Your profile is not allowed to access this transaction.', 'es' => 'Su perfil no tiene permiso para acceder a esta transacción.']));
if (HomologacaoFornecedorAcesso::externo()) $falhar(403, IdiomaService::t('comum.permissao.negada', ['pt' => 'Perfil sem permissão de acesso!', 'en' => 'Profile does not have access permission!', 'es' => '¡El perfil no tiene permiso de acceso!']));
$podeEditar = !empty($permissao['pode_editar']);
$filtros = json_decode((string)($_GET['filtros'] ?? '{}'), true);
$cod = filter_var($_GET['CODFORNECEDOR'] ?? (is_array($filtros) ? ($filtros['CODFORNECEDOR'] ?? null) : null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$cod) $falhar(400, $t('erro.fornecedor', ['pt' => 'Selecione um fornecedor já salvo.', 'en' => 'Select a supplier that has been saved.', 'es' => 'Seleccione un proveedor ya guardado.']));
session_write_close();

$chamar = static function (string $sql, array $p) use ($pdo): array {
    $s = $pdo->prepare($sql); $s->execute($p);
    $conjuntos = [];
    do { try { $conjuntos[] = $s->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException) { } } while ($s->nextRowset());
    $s->closeCursor();
    return $conjuntos;
};
$listar = static function () use ($chamar, $cod): array {
    $r = $chamar('CALL sp_fo_fornecedor_usuarios_listar(?)', [$cod]);
    return ['tipo' => $r[0][0] ?? [], 'itens' => array_map(static fn($i) => ['codigo' => (string)$i['codigo'], 'descricao' => (string)$i['descricao'], 'vinculado' => (int)$i['vinculado'] === 1, 'nivel' => ''], $r[1] ?? [])];
};

if ($ajax) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($_GET['acao'] === 'listar') { echo json_encode(['ok' => true] + $listar(), JSON_UNESCAPED_UNICODE); exit; }
        if ($_GET['acao'] === 'gravar' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            if (!$podeEditar) $falhar(403, IdiomaService::t('comum.permissao.negada', ['pt' => 'Perfil sem permissão de acesso!', 'en' => 'Profile does not have access permission!', 'es' => '¡El perfil no tiene permiso de acceso!']));
            $corpo = json_decode((string)file_get_contents('php://input'), true);
            $lista = static fn($v): array => array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : []), static fn($n) => $n > 0)));
            $incluir = $lista($corpo['incluir'] ?? []); $remover = $lista($corpo['remover'] ?? []);
            $r = $chamar('CALL sp_fo_fornecedor_usuarios_gravar(?, ?, ?)', [$cod, json_encode($incluir), json_encode($remover)]);
            // A procedure devolve as contagens no primeiro conjunto; o último conjunto do CALL vem vazio (end() dava sempre 0).
            $res = $r[0][0] ?? ['incluidos' => 0, 'removidos' => 0];
            AuditService::record($pdo, FT_TRANSACAO, 'SALVAR', 'SUCESSO', 'fo_fornecedorxusuario', (string)$cod, ['incluir' => $incluir, 'remover' => $remover, 'resultado' => $res]);
            echo json_encode(['ok' => true, 'incluidos' => (int)$res['incluidos'], 'removidos' => (int)$res['removidos']] + $listar(), JSON_UNESCAPED_UNICODE); exit;
        }
        $falhar(400, $t('erro.acao', ['pt' => 'Ação inválida.', 'en' => 'Invalid action.', 'es' => 'Acción inválida.']));
    } catch (Throwable $e) {
        AuditService::record($pdo, FT_TRANSACAO, 'SALVAR', 'ERRO', 'fo_fornecedorxusuario', (string)$cod, ['mensagem' => mb_substr($e->getMessage(), 0, 300)]);
        $falhar(422, $e instanceof PDOException && preg_match('/1644\s*(.+)$/s', $e->getMessage(), $m) ? trim($m[1]) : IdiomaService::erro($e, 'salvar'));
    }
}

AuditService::record($pdo, FT_TRANSACAO, 'ACESSAR', 'SUCESSO', 'transacao', (string)$cod);
try { $inicial = $listar(); } catch (Throwable $e) { $falhar(404, IdiomaService::erro($e, 'consultar')); }
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$titulo = $t('titulo', ['pt' => $u('Usuários do fornecedor'), 'en' => 'Supplier users', 'es' => 'Usuarios del proveedor']);
$textos = [
    'disponiveis' => $t('lista.disponiveis', ['pt' => $u('Usuários disponíveis'), 'en' => 'Available users', 'es' => 'Usuarios disponibles']),
    'vinculados' => $t('lista.vinculados', ['pt' => $u('Usuários do fornecedor'), 'en' => 'Supplier users', 'es' => 'Usuarios del proveedor']),
    'filtrar' => $t('lista.filtrar', ['pt' => 'Filtrar...', 'en' => 'Filter...', 'es' => 'Filtrar...']),
    'vazio' => $t('lista.vazio', ['pt' => $u('Nenhum usuário.'), 'en' => 'No users.', 'es' => 'Ningún usuario.']),
    'vincular' => $t('botao.vincular', ['pt' => 'Ligar marcados', 'en' => 'Link checked', 'es' => 'Vincular marcados']),
    'vincular_todos' => $t('botao.vincular_todos', ['pt' => 'Ligar todos os exibidos', 'en' => 'Link all shown', 'es' => 'Vincular todos los mostrados']),
    'desvincular' => $t('botao.desvincular', ['pt' => 'Desligar marcados', 'en' => 'Unlink checked', 'es' => 'Desvincular marcados']),
    'desvincular_todos' => $t('botao.desvincular_todos', ['pt' => 'Desligar todos os exibidos', 'en' => 'Unlink all shown', 'es' => 'Desvincular todos los mostrados']),
    'confirmar_todos' => $t('msg.confirmar_todos', ['pt' => $u('Desligar {n} usuários deste fornecedor? Clique de novo para confirmar.'), 'en' => 'Unlink {n} users from this supplier? Click again to confirm.', 'es' => $u('¿Desvincular {n} usuarios de este proveedor? Haga clic de nuevo para confirmar.')]),
    'gravado' => $t('msg.gravado', ['pt' => 'Gravado: {i} ligados, {r} desligados.', 'en' => 'Saved: {i} linked, {r} unlinked.', 'es' => 'Guardado: {i} vinculados, {r} desvinculados.']),
    'gravando' => $t('msg.gravando', ['pt' => 'Gravando...', 'en' => 'Saving...', 'es' => 'Guardando...']),
    'erro' => $t('msg.erro', ['pt' => $u('Não foi possível gravar.'), 'en' => 'Could not save.', 'es' => 'No fue posible guardar.']),
    'contagem' => $t('lista.contagem', ['pt' => '{exibidos} de {total}', 'en' => '{exibidos} of {total}', 'es' => '{exibidos} de {total}']),
    'consulta' => $t('msg.consulta', ['pt' => $u('Consulta: seu perfil não pode alterar os usuários do fornecedor.'), 'en' => 'Read-only: your profile cannot change the supplier users.', 'es' => 'Consulta: su perfil no puede modificar los usuarios del proveedor.']),
    'aviso' => $t('msg.aviso', ['pt' => $u('Usuários com perfil FORNECEDOR ou FORNECEDOR_GESTOR só veem os fornecedores ligados a eles. Só usuários ligados aqui podem ser o responsável do fornecedor nos documentos.'), 'en' => 'Users with the FORNECEDOR or FORNECEDOR_GESTOR profile only see the suppliers linked to them. Only users linked here can be the supplier owner in the documents.', 'es' => 'Los usuarios con perfil FORNECEDOR o FORNECEDOR_GESTOR solo ven los proveedores vinculados a ellos. Solo los usuarios vinculados aquí pueden ser el responsable del proveedor en los documentos.']),
];
$idioma = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';
?><!doctype html>
<html lang="<?= $e($idioma === 'pt' ? 'pt-BR' : $idioma) ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($titulo) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/bootstrap-icons.css?v=2">
<link rel="stylesheet" href="../assets/css/app.css?v=1">
<style>
  .vinc-wrap { display:grid; grid-template-columns:minmax(0, 1fr) auto minmax(0, 1fr); gap:.75rem; align-items:stretch; min-height:0; }
  .vinc-painel { display:flex; flex-direction:column; min-height:0; border:1px solid var(--bs-border-color); border-radius:.375rem; background:var(--bs-body-bg); }
  .vinc-cab { padding:.5rem .75rem; border-bottom:1px solid var(--bs-border-color); display:flex; justify-content:space-between; align-items:center; gap:.5rem; }
  .vinc-lista { overflow:auto; height:calc(100vh - 230px); min-height:200px; margin:0; padding:0; list-style:none; }
  .vinc-lista li { display:flex; gap:.5rem; align-items:flex-start; padding:.3rem .75rem; border-bottom:1px solid var(--bs-border-color-translucent); cursor:pointer; }
  .vinc-lista li:hover { background:var(--bs-tertiary-bg); }
  .vinc-lista li input { margin-top:.2rem; flex:none; }
  .vinc-acoes { display:flex; flex-direction:column; justify-content:center; gap:.5rem; }
  @media (max-width:640px) { .vinc-wrap { grid-template-columns:1fr; } .vinc-acoes { flex-direction:row; justify-content:center; } .vinc-acoes .bi { display:inline-block; transform:rotate(90deg); } .vinc-lista { height:35vh; } }
</style>
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?></head>
<body class="p-3 bg-light">
<main class="container-fluid" data-vinculos data-podeeditar="<?= $podeEditar ? '1' : '0' ?>">
  <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
    <div>
      <h5 class="mb-1"><i class="bi bi-person-badge me-2"></i><?= $e($titulo) ?></h5>
      <div class="text-muted small"><?= $e($inicial['tipo']['RAZAOSOCIAL'] ?? '') ?></div>
    </div>
    <div class="d-flex align-items-start gap-2"><button type="button" class="btn btn-sm btn-outline-info" data-open-transacao-ajuda data-help-transaction="<?= $e(FT_TRANSACAO) ?>" title="Ajuda" aria-label="Ajuda"><i class="bi bi-question-circle"></i></button>
    <div class="small text-end" data-status aria-live="polite"><?php if (!$podeEditar): ?><span class="badge text-bg-secondary"><?= $e($textos['consulta']) ?></span><?php endif; ?></div></div>
  </div>
  <div class="alert alert-light border small py-1 px-2 mb-2"><i class="bi bi-info-circle me-1"></i><?= $e($textos['aviso']) ?></div>
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
window.greenVinculos = <?= json_encode(['itens' => $inicial['itens'], 'textos' => $textos, 'niveis' => [], 'niveisRotulo' => [], 'comNivel' => false, 'url' => basename(__FILE__) . '?CODFORNECEDOR=' . (int)$cod], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="../assets/js/homologacao-tipos-fornecedores-vinculos.js?v=1"></script>
<script src="../assets/js/ajuda-transacao.js?v=1"></script>
</body>
</html>
