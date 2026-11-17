<?php
declare(strict_types=1);

/*
 * API do Dicionário da pesquisa (principal/busca_dicionario.php). JSON.
 *   GET  action=listar  status, base, texto            → sp_busca_dicionario_listar (empresa; a view junta a base comum)
 *   POST action=gravar  base, id, termo, significado, sinonimos, sigla, contexto[], base_nova, csrf
 *   POST action=status  itens=[{base,id}], status (SUGESTAO|APROVADO|REJEITADO), csrf
 *   POST action=excluir itens=[{base,id}], csrf
 * Base EMPRESA grava no banco da empresa; base UNIVERSAL (comum a todas) grava no guniversal_dbp e só o ADMINISTRADOR pode.
 * Permissões da transação BUSCA_DICIONARIO: incluir (novo), editar (alterar, aprovar, rejeitar), excluir.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';

header('Cache-Control: no-store');
$json = static function (array $data, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; };
$msg = static fn(string $chave, array $padrao): string => IdiomaService::t('busca_dicionario.' . $chave, $padrao);
$sessao = ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'];
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', $sessao)], 401);
$env = GreenPortalDatabase::env();
$pdo = TenantContext::connect($env, $_SESSION);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', $sessao)], 401);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$perm = PermissaoTransacao::obter('BUSCA_DICIONARIO');
$semPermissao = static fn() => $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sem_permissao', ['pt' => 'Seu perfil não tem permissão para esta ação.', 'en' => 'Your profile is not allowed to do this.', 'es' => 'Su perfil no tiene permiso para esta acción.'])], 403);
if (empty($perm['pode_visualizar'])) $semPermissao();
$admin = strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) === 'ADMINISTRADOR';
$usuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$universal = null;
$conexao = static function (string $base) use (&$universal, $env, $pdo): PDO {
    if ($base !== 'UNIVERSAL') return $pdo;
    if ($universal === null) {
        $universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
        $universal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $universal;
};
$chamar = static function (PDO $db, string $sql, array $p = []): array {
    $s = $db->prepare($sql); $s->execute($p); $sets = [];
    do { try { $sets[] = $s->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable) { $sets[] = []; } } while ($s->nextRowset());
    $s->closeCursor(); return $sets;
};
$soAdmin = $msg('so_admin', ['pt' => 'Termos da base comum só podem ser alterados pelo ADMINISTRADOR.', 'en' => 'Common base terms can only be changed by ADMINISTRADOR.', 'es' => 'Los términos de la base común solo los cambia el ADMINISTRADOR.']);
$tiposValidos = ['licencas', 'controles', 'requisitos', 'treinamentos', 'infraestrutura', 'fornecedorcriterios', 'funcionariocriterios', 'funcionariotreinamentos'];
$acao = (string)($_REQUEST['action'] ?? 'listar');

try {
    if ($acao === 'listar') {
        $status = in_array((string)($_GET['status'] ?? ''), ['SUGESTAO', 'APROVADO', 'REJEITADO'], true) ? (string)$_GET['status'] : '';
        $base = in_array((string)($_GET['base'] ?? ''), ['UNIVERSAL', 'EMPRESA'], true) ? (string)$_GET['base'] : '';
        $sets = $chamar($pdo, 'CALL sp_busca_dicionario_listar(?, ?, ?)', [$status, $base, mb_substr(trim((string)($_GET['texto'] ?? '')), 0, 200)]);
        $json(['ok' => true, 'itens' => $sets[0] ?? [], 'contagens' => array_column($sets[1] ?? [], 'qtd', 'status')]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $json(['ok' => false, 'message' => 'Método inválido.'], 405);
    $csrf = (string)($_SESSION['busca_dicionario_csrf'] ?? '');
    if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $json(['ok' => false, 'message' => IdiomaService::t('api.token_invalido', ['pt' => 'Token de segurança inválido. Recarregue a página e tente novamente.', 'en' => 'Invalid security token. Reload the page and try again.', 'es' => 'Token de seguridad inválido. Recargue la página e inténtelo nuevamente.'])], 419);

    if ($acao === 'gravar') {
        $id = (int)($_POST['id'] ?? 0);
        $base = $id > 0 ? (string)($_POST['base'] ?? '') : ($admin && ($_POST['base_nova'] ?? '') === 'UNIVERSAL' ? 'UNIVERSAL' : 'EMPRESA');
        if (!in_array($base, ['UNIVERSAL', 'EMPRESA'], true)) $json(['ok' => false, 'message' => 'Base inválida.'], 422);
        if ($id > 0 ? empty($perm['pode_editar']) : empty($perm['pode_incluir'])) $semPermissao();
        if ($base === 'UNIVERSAL' && !$admin) $json(['ok' => false, 'message' => $soAdmin], 403);
        $termo = trim((string)($_POST['termo'] ?? '')); $significado = trim((string)($_POST['significado'] ?? ''));
        if ($termo === '' || $significado === '') $json(['ok' => false, 'message' => $msg('obrigatorio', ['pt' => 'Informe o termo e o significado.', 'en' => 'Enter the term and the meaning.', 'es' => 'Informe el término y el significado.'])], 422);
        $sinonimos = implode('; ', array_values(array_filter(array_map('trim', explode(';', (string)($_POST['sinonimos'] ?? ''))), static fn($x) => $x !== '')));
        $contexto = implode(',', array_values(array_intersect($tiposValidos, (array)($_POST['contexto'] ?? []))));
        $sets = $chamar($conexao($base), 'CALL sp_busca_dicionario_gravar(?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, mb_substr($termo, 0, 100), mb_substr($significado, 0, 300), mb_substr($sinonimos, 0, 1000), !empty($_POST['sigla']) ? 1 : 0, $contexto, $id > 0 ? '' : 'APROVADO', $usuario]);
        $json(['ok' => true, 'id' => (int)($sets[0][0]['coddicionario'] ?? $id), 'base' => $base]);
    }

    if ($acao === 'status' || $acao === 'excluir') {
        if ($acao === 'status' ? empty($perm['pode_editar']) : empty($perm['pode_excluir'])) $semPermissao();
        $status = (string)($_POST['status'] ?? '');
        if ($acao === 'status' && !in_array($status, ['SUGESTAO', 'APROVADO', 'REJEITADO'], true)) $json(['ok' => false, 'message' => 'Situação inválida.'], 422);
        $itens = json_decode((string)($_POST['itens'] ?? '[]'), true);
        $porBase = ['UNIVERSAL' => [], 'EMPRESA' => []]; $ignorados = 0;
        foreach (is_array($itens) ? $itens : [] as $it) {
            $b = (string)($it['base'] ?? ''); $i = (int)($it['id'] ?? 0);
            if ($i < 1 || !isset($porBase[$b])) continue;
            if ($b === 'UNIVERSAL' && !$admin) { $ignorados++; continue; }
            $porBase[$b][] = $i;
        }
        $total = 0;
        foreach ($porBase as $b => $ids) {
            if (!$ids) continue;
            $sets = $acao === 'status'
                ? $chamar($conexao($b), 'CALL sp_busca_dicionario_status(?, ?, ?)', [json_encode($ids), $status, $usuario])
                : $chamar($conexao($b), 'CALL sp_busca_dicionario_excluir(?)', [json_encode($ids)]);
            $total += (int)($sets[0][0]['alterados'] ?? $sets[0][0]['excluidos'] ?? 0);
        }
        $json(['ok' => true, 'alterados' => $total, 'ignorados' => $ignorados, 'message' => $ignorados ? $soAdmin : null]);
    }
    $json(['ok' => false, 'message' => 'Ação inválida.'], 400);
} catch (Throwable $e) {
    error_log('busca_dicionario: ' . $e->getMessage());
    $duplicado = str_contains($e->getMessage(), '1062') || str_contains($e->getMessage(), 'Duplicate');
    $json(['ok' => false, 'message' => $duplicado
        ? $msg('duplicado', ['pt' => 'Esse termo com esse significado já existe nesta base.', 'en' => 'This term with this meaning already exists in this base.', 'es' => 'Este término con este significado ya existe en esta base.'])
        : $msg('erro', ['pt' => 'Não foi possível concluir agora. Tente de novo em instantes.', 'en' => 'This could not be completed now. Try again shortly.', 'es' => 'No fue posible concluir ahora. Intente de nuevo en instantes.'])], $duplicado ? 409 : 500);
}
