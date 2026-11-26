<?php
declare(strict_types=1);

/*
 * API da transação Administração › Textos e idiomas (ADMINISTRACAO_IDIOMAS).
 * Leitura (GET): carregar, publicacoes, itens.
 * Gravação (POST): salvar, publicar, descartar, previa.
 * Revisão G item 17 (26/11/2026): traduzir_ia (POST) — a IA traduz o PT para EN/ES; volta para a tela como edição (rascunho
 * só depois de Salvar), para revisar antes de publicar.
 * Os textos valem para todas as empresas: alterações só pelo perfil ADMINISTRADOR, com CSRF e auditoria no tenant.
 */

require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__, 2) . '/classes/AuditService.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaAdminService.php';
require_once dirname(__DIR__, 2) . '/classes/InteligenciaArtificialDocumento.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$json = static function (array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok' => false, 'message' => 'Sessão expirada.'], 401);
$transacao = IdiomaService::TRANSACAO;
if (!PermissaoTransacao::obter($transacao)['pode_visualizar']) $json(['ok' => false, 'message' => 'Sem permissão para administrar textos e idiomas.'], 403);

try {
    $env = GreenPortalDatabase::env();
    $tenant = TenantContext::connect($env, $_SESSION);
    if (!UsuarioSessao::atualizar($tenant, $_SESSION)) $json(['ok' => false, 'message' => 'Sessão expirada.'], 401);
    $universal = GreenPortalDatabase::connect($env, $env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp', 'GUNIVERSAL');
} catch (Throwable $e) {
    error_log('administracao_idiomas: ' . $e->getMessage());
    $json(['ok' => false, 'message' => 'Banco de dados indisponível.'], 503);
}
$service = new IdiomaAdminService($universal);

$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? 'carregar')));
$metodo = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$mutacoes = ['salvar', 'publicar', 'descartar', 'previa', 'traduzir_ia'];

if (in_array($action, $mutacoes, true)) {
    if ($metodo !== 'POST') $json(['ok' => false, 'message' => 'Método não permitido.'], 405);
    $csrf = (string)($_SESSION['idiomas_csrf'] ?? '');
    if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $json(['ok' => false, 'message' => 'Token de segurança inválido. Recarregue a tela.'], 419);
    // Os textos valem para todas as empresas: além da permissão da transação, exige o perfil ADMINISTRADOR.
    if (strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) !== 'ADMINISTRADOR' || !PermissaoTransacao::obter($transacao)['pode_editar']) {
        AuditService::record($tenant, $transacao, strtoupper($action), 'NEGADO', 'idioma_textos');
        $json(['ok' => false, 'message' => 'Somente o perfil ADMINISTRADOR pode alterar os textos.'], 403);
    }
}

$autor = [
    'nome' => (string)($_SESSION['usuario']['nome'] ?? $_SESSION['usuario']['userid'] ?? ''),
    'codusuario' => (int)($_SESSION['usuario']['codusuario'] ?? 0),
    'banco' => (string)($_SESSION['tenant']['banco_dados'] ?? ''),
];

try {
    if ($action === 'carregar') {
        $service->sincronizarMenus($autor['nome']);
        $json(['ok' => true, 'grupos' => $service->grupos(), 'textos' => $service->textos(), 'situacao' => $service->situacao(), 'previa' => IdiomaService::previa()]);
    }
    if ($action === 'publicacoes') $json(['ok' => true, 'publicacoes' => $service->publicacoes()]);
    if ($action === 'itens') $json(['ok' => true, 'itens' => $service->itensPublicacao((int)($_GET['codpublicacao'] ?? 0))]);

    if ($action === 'salvar') {
        $itens = json_decode((string)($_POST['itens'] ?? ''), true);
        if (!is_array($itens)) $json(['ok' => false, 'message' => 'Alterações inválidas.'], 422);
        $revisoes = $service->salvar($itens, $autor);
        AuditService::record($tenant, $transacao, 'SALVAR', 'SUCESSO', 'idioma_traducoes_rascunho', null, ['qtd' => count($itens), 'textos' => array_values(array_unique(array_map(static fn($i): int => (int)($i['codtexto'] ?? 0), $itens)))]);
        $json(['ok' => true, 'revisoes' => $revisoes, 'textos' => $service->textos(), 'situacao' => $service->situacao()]);
    }
    if ($action === 'publicar') {
        $publicacao = $service->publicar((string)($_POST['observacao'] ?? ''), $autor);
        AuditService::record($tenant, $transacao, 'PUBLICAR', 'SUCESSO', 'idioma_publicacoes', (string)$publicacao['codpublicacao'], ['numero' => $publicacao['numero'], 'qtd' => $publicacao['qtd']]);
        $json(['ok' => true, 'publicacao' => $publicacao, 'textos' => $service->textos(), 'situacao' => $service->situacao()]);
    }
    if ($action === 'descartar') {
        $lista = (string)($_POST['codtextos'] ?? '');
        $codtextos = $lista === '' || $lista === 'todos' ? null : json_decode($lista, true);
        if ($codtextos !== null && !is_array($codtextos)) $json(['ok' => false, 'message' => 'Lista de textos inválida.'], 422);
        $qtd = $service->descartar($codtextos, $autor);
        AuditService::record($tenant, $transacao, 'DESCARTAR', 'SUCESSO', 'idioma_traducoes_rascunho', null, ['qtd' => $qtd, 'textos' => $codtextos ?? 'todos']);
        $json(['ok' => true, 'descartados' => $qtd, 'textos' => $service->textos(), 'situacao' => $service->situacao()]);
    }
    if ($action === 'traduzir_ia') {
        $itens = json_decode((string)($_POST['itens'] ?? ''), true);
        if (!is_array($itens) || !$itens) $json(['ok' => false, 'message' => 'Nada para traduzir.'], 422);
        $lista = [];
        foreach (array_slice($itens, 0, 40) as $i) {
            $pt = trim((string)($i['pt'] ?? ''));
            $alvo = array_values(array_intersect(['en', 'es'], (array)($i['idiomas'] ?? [])));
            if ($pt === '' || !$alvo || (int)($i['codtexto'] ?? 0) <= 0) continue;
            $lista[] = ['codtexto' => (int)$i['codtexto'], 'pt' => mb_substr($pt, 0, 600), 'idiomas' => $alvo, 'contexto' => mb_substr((string)($i['descricao'] ?? ''), 0, 120)];
        }
        if (!$lista) $json(['ok' => false, 'message' => 'Nada para traduzir.'], 422);
        $prompt = 'Você traduz textos da interface de um software de gestão ambiental, de saúde e segurança e de requisitos legais (GreenSolutions). '
            . 'Para cada item, traduza o texto "pt" (português do Brasil) para os idiomas pedidos em "idiomas" (en = inglês, es = espanhol). '
            . 'Mantenha o sentido, o tom de interface (curto), maiúsculas iniciais, números, siglas (ISO, NR, RCL, GED, IA→AI em inglês) e marcadores como {n}, {t}, %s, %d exatamente como estão. '
            . 'Não use os caracteres < e >. Retorne só JSON: {"traducoes":[{"codtexto":0,"en":"...","es":"..."}]} (só os idiomas pedidos).';
        $r = InteligenciaArtificialDocumento::analisar(json_encode($lista, JSON_UNESCAPED_UNICODE), $prompt, 'pt');
        $r = is_array($r['sugestao'] ?? null) ? $r['sugestao'] : $r;
        $pedidos = [];
        foreach ($lista as $i) $pedidos[$i['codtexto']] = $i['idiomas'];
        $saida = [];
        foreach ((array)($r['traducoes'] ?? []) as $t) {
            $cod = (int)($t['codtexto'] ?? 0);
            if (!isset($pedidos[$cod])) continue;
            foreach ($pedidos[$cod] as $lg) {
                $v = trim(str_replace(['<', '>'], '', (string)($t[$lg] ?? '')));
                if ($v !== '') $saida[] = ['codtexto' => $cod, 'idioma' => $lg, 'texto' => mb_substr($v, 0, 2000)];
            }
        }
        AuditService::record($tenant, $transacao, 'TRADUZIR_IA', 'SUCESSO', 'idioma_textos', null, ['pedidos' => count($lista), 'traduzidos' => count($saida)]);
        $json(['ok' => true, 'traducoes' => $saida]);
    }
    if ($action === 'previa') {
        $_SESSION['idioma_previa_rascunho'] = ((string)($_POST['ativa'] ?? '0')) === '1';
        AuditService::record($tenant, $transacao, 'PREVIA', 'SUCESSO', 'idioma_textos', null, ['ativa' => $_SESSION['idioma_previa_rascunho']]);
        $json(['ok' => true, 'previa' => IdiomaService::previa()]);
    }
    $json(['ok' => false, 'message' => 'Ação não reconhecida.'], 400);
} catch (Throwable $e) {
    [$status, $mensagem] = IdiomaAdminService::mensagemErro($e);
    if (in_array($action, $mutacoes, true)) AuditService::record($tenant, $transacao, strtoupper($action), 'ERRO', 'idioma_textos', null, ['mensagem' => mb_substr($mensagem, 0, 300)]);
    $json(['ok' => false, 'message' => $mensagem], $status);
}
