<?php
declare(strict_types=1);

/*
 * Ajuda interativa (botão ? / F1 no cabeçalho e o ? de cada tela) — assets/js/ajuda.js.
 * Ações:
 *   dados (GET)      — guias passo a passo, "Sobre esta tela", perfis de leitura e menus que ESTE usuário pode abrir,
 *                      no idioma da sessão. Fonte: Guia do usuário publicado (Administração › Guia do usuário);
 *                      sem guias publicados, usa a carga inicial (principal/guia_interativo_conteudo.php).
 *                      Os "Como fazer" do guia viram guias simples da tela da transação.
 *   transacao (GET)  — texto da Ajuda da transação (Configurações › Ajuda das transações) para "Sobre esta tela".
 *   feedback (POST)  — "Esta ajuda foi útil?" (auditoria AJUDA_INTERATIVA).
 * Só leitura do guia (views/procedures do GuiaUsuarioService e sp_crud_select); gravação só por sp_auditoria_registrar.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
require_once dirname(__DIR__, 2) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__, 2) . '/classes/GuiaUsuarioService.php';

$json = static function (array $d, int $s = 200): never { http_response_code($s); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; };
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'])], 401);

$idioma = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';
$acao = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? 'dados')));
$env = GreenPortalDatabase::env();
$l = static fn(mixed $v): string => is_array($v) ? trim((string)(($v[$idioma] ?? '') !== '' ? $v[$idioma] : ($v['pt'] ?? ''))) : trim((string)$v);
$perfilSessao = strtoupper(trim((string)($_SESSION['usuario']['codigo_perfil'] ?? '')));
$admin = $perfilSessao === 'ADMINISTRADOR';
$cache = [];
$podeVer = static function (string $codigo) use (&$cache, $admin): bool {
    $codigo = strtoupper(trim($codigo));
    if ($codigo === '' || $admin) return true;
    if (!array_key_exists($codigo, $cache)) { try { $cache[$codigo] = !empty(PermissaoTransacao::obter($codigo)['pode_visualizar']); } catch (Throwable) { $cache[$codigo] = false; } }
    return $cache[$codigo];
};
if (empty($_SESSION['ajuda_csrf'])) $_SESSION['ajuda_csrf'] = bin2hex(random_bytes(24));

if ($acao === 'feedback') {
    if (!hash_equals((string)$_SESSION['ajuda_csrf'], (string)($_POST['csrf'] ?? ''))) $json(['ok' => false, 'message' => 'Token inválido.'], 419);
    $detalhe = ['tela' => mb_substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', (string)($_POST['tela'] ?? '')) ?? '', 0, 120), 'guia' => mb_substr(preg_replace('/[^a-z0-9_-]/', '', (string)($_POST['guia'] ?? '')) ?? '', 0, 60), 'util' => !empty($_POST['util']) ? 1 : 0, 'comentario' => mb_substr(trim(strip_tags((string)($_POST['comentario'] ?? ''))), 0, 500)];
    try {
        require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
        $pdo = TenantContext::connect($env, $_SESSION);
        GreenPortalDatabase::call($pdo, 'CALL sp_auditoria_registrar(?, ?, ?, ?, ?, ?, ?)', [(int)($_SESSION['usuario']['codusuario'] ?? 0), 'AJUDA_INTERATIVA', $detalhe['util'] ? 'AJUDA_UTIL' : 'AJUDA_NAO_UTIL', 'ajuda', $detalhe['tela'], 'SUCESSO', json_encode($detalhe, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) { error_log('ajuda feedback: ' . $e->getMessage()); }
    $json(['ok' => true, 'message' => IdiomaService::t('ajuda.obrigado', ['pt' => 'Obrigado! A sua resposta ajuda a melhorar a ajuda.', 'en' => 'Thank you! Your answer helps improve the help.', 'es' => '¡Gracias! Su respuesta ayuda a mejorar la ayuda.'])]);
}

try {
    $universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
    $universal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) { error_log('ajuda: ' . $e->getMessage()); $json(['ok' => false, 'message' => 'Ajuda indisponível no momento.'], 500); }

if ($acao === 'transacao') {
    $chaves = array_slice(array_filter(array_map(static fn($c) => preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$c) ?? '', explode(',', (string)($_GET['chaves'] ?? '')))), 0, 6);
    foreach ($chaves as $chave) {
        try {
            $row = GreenPortalDatabase::call($universal, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'ajuda_transacoes', 'filtros' => json_encode(['transacao' => $chave, 'ativo' => 1], JSON_UNESCAPED_UNICODE)])[0] ?? null;
        } catch (Throwable) { $row = null; }
        if ($row && trim(strip_tags((string)($row['texto_' . $idioma] ?? $row['texto_pt'] ?? ''))) !== '') {
            $texto = (string)(($row['texto_' . $idioma] ?? '') !== '' ? $row['texto_' . $idioma] : $row['texto_pt']);
            // o texto já é limpo na gravação (Configurações › Ajuda das transações); aqui só as tags permitidas
            // 24/11/2026: títulos (h1–h6) viram parágrafo em negrito — antes o strip_tags os tirava e o título ficava colado ao texto
            // (ajudas das Homes por módulo, migração 381, e outras gravadas com <h5>).
            $texto = preg_replace('#<h[1-6][^>]*>(.*?)</h[1-6]>#is', '<p><strong>$1</strong></p>', $texto) ?? $texto;
            $texto = strip_tags($texto, '<p><br><strong><b><em><i><ul><ol><li><a>');
            $texto = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $texto) ?? '';
            $texto = preg_replace('/href\s*=\s*("|\')\s*javascript:[^"\']*\1/i', 'href="#"', $texto) ?? '';
            $json(['ok' => true, 'transacao' => $chave, 'titulo' => (string)($row['titulo_' . $idioma] ?? $row['titulo_pt'] ?? ''), 'html' => $texto]);
        }
    }
    $json(['ok' => true, 'html' => '']);
}

if ($acao !== 'dados') $json(['ok' => false, 'message' => 'Ação inválida.'], 400);

$servico = new GuiaUsuarioService($universal);
$doc = null;
try { $pub = $servico->publicado(); if ($pub) $doc = $servico->normalizar($pub['conteudo']); } catch (Throwable $e) { error_log('ajuda publicado: ' . $e->getMessage()); }
if (!defined('GREEN_GUIA_USUARIO')) define('GREEN_GUIA_USUARIO', true);
if (!$doc) {
    $base = require dirname(__DIR__) . '/guia_usuario_conteudo.php';
    $doc = $servico->normalizar(['perfis' => $base['perfis'], 'modulos' => $base['modulos']]);
}
// Sem guias publicados ainda (antes da migração 309): usa a carga inicial do arquivo.
$temGuias = false;
foreach ($doc['modulos'] as $m) if (!empty($m['guias'])) $temGuias = true;
if (!$temGuias) {
    $carga = require dirname(__DIR__) . '/guia_interativo_conteudo.php';
    foreach ($doc['modulos'] as &$m) if (isset($carga[$m['id']])) { $m['guias'] = $carga[$m['id']]['guias'] ?? []; $m['telas'] = $carga[$m['id']]['telas'] ?? []; }
    unset($m);
    $doc = $servico->normalizar($doc);
}

$menus = [];
try { $menus = $servico->menusComUrl(); } catch (Throwable $e) { error_log('ajuda menus: ' . $e->getMessage()); }
$menusUsuario = [];
foreach ($menus as $codigo => $menu) if ($podeVer($codigo)) $menusUsuario[$codigo] = ['nome' => $l($menu['nome']), 'grupo' => $l($menu['grupo'])];
$ehMenu = static fn(string $c): bool => isset($menus[strtoupper($c)]);
$telaPermitida = static function (array $telas) use ($ehMenu, $podeVer): bool {
    $menusDaLista = array_filter($telas, static fn($t) => $ehMenu((string)$t));
    if (!$menusDaLista) return true; // só telas internas (janelas): vale a permissão de quem abre a janela
    foreach ($menusDaLista as $t) if ($podeVer((string)$t)) return true;
    return false;
};

$perfis = [];
foreach ($doc['perfis'] as $chave => $perfil) if ($chave !== 'sistema') $perfis[] = ['chave' => $chave, 'nome' => $l($perfil['nome']), 'icone' => $perfil['icone']];
$assuntos = []; $guias = []; $telas = [];
foreach ($doc['modulos'] as $m) {
    if (empty($m['ativo'])) continue;
    $assunto = ['id' => $m['id'], 'nome' => $l($m['nome']), 'icone' => $m['icone']];
    $qtd = 0;
    foreach ($m['guias'] ?? [] as $g) {
        if (!$telaPermitida($g['telas'])) continue;
        $passos = [];
        foreach ($g['passos'] as $p) {
            $passos[] = ['texto' => $l($p['texto']), 'ver' => $l($p['ver']), 'alvo' => $p['alvo'], 'quem' => $p['quem'], 'auto' => $p['auto'],
                'tela' => $p['tela'] !== '' && isset($menusUsuario[$p['tela']]) ? $p['tela'] : ''];
        }
        $guias[] = ['id' => $g['id'], 'assunto' => $m['id'], 'titulo' => $l($g['titulo']), 'resumo' => $l($g['resumo']), 'antes' => $l($g['antes']), 'depois' => $l($g['depois']),
            'perfis' => $g['perfis'], 'telas' => $g['telas'], 'passos' => $passos, 'completo' => true];
        $qtd++;
    }
    // "Como fazer" do guia (texto simples): guia sem "Mostre-me" ligado à tela da transação
    foreach (array_values($m['tarefas'] ?? []) as $i => $t) {
        $cod = (string)($t['transacao'] ?? '');
        if ($cod !== '' && !$podeVer($cod)) continue;
        $titulo = $l($t['titulo']);
        if ($titulo === '') continue;
        $repetido = false;
        foreach ($guias as $g) if ($g['assunto'] === $m['id'] && mb_strtolower($g['titulo']) === mb_strtolower($titulo)) $repetido = true;
        if ($repetido) continue;
        $guias[] = ['id' => 'tarefa-' . $m['id'] . '-' . ($i + 1), 'assunto' => $m['id'], 'titulo' => $titulo, 'resumo' => '', 'antes' => '', 'depois' => '', 'perfis' => [],
            'telas' => $cod !== '' ? [$cod] : [], 'passos' => array_map(static fn($p) => ['texto' => $l($p), 'ver' => '', 'alvo' => '', 'quem' => '', 'auto' => false, 'tela' => ''], $t['passos']),
            'completo' => false];
        if ($i === 0 && $cod !== '' && isset($menusUsuario[$cod])) $guias[count($guias) - 1]['passos'][0]['tela'] = $cod;
        $qtd++;
    }
    foreach ($m['telas'] ?? [] as $t) {
        $telas[] = ['chaves' => $t['chaves'], 'assunto' => $m['id'], 'oque' => $l($t['oque']), 'antes' => $l($t['antes']), 'depois' => $l($t['depois']),
            'pode' => array_values(array_filter(array_map(static fn($p) => ['texto' => $l($p['texto']), 'alvo' => $p['alvo']], $t['pode']), static fn($p) => $p['texto'] !== '')),
            'duvidas' => array_values(array_filter(array_map(static fn($d) => ['p' => $l($d['p']), 'r' => $l($d['r'])], $t['duvidas']), static fn($d) => $d['p'] !== ''))];
    }
    $palavras = [];
    foreach ($m['termos'] ?? [] as $t) if ($l($t['termo']) !== '' && empty($t['dica'])) $palavras[] = ['termo' => $l($t['termo']), 'texto' => $l($t['texto'])];
    $menuModulo = [];
    foreach ($m['menu'] ?? [] as $it) if ((string)$it['transacao'] !== '') $menuModulo[] = strtoupper((string)$it['transacao']);
    $assunto['palavras'] = $palavras;
    $assunto['menus'] = array_values(array_unique($menuModulo));
    $assunto['qtd_guias'] = $qtd;
    $assuntos[] = $assunto;
}

$perfilPadrao = '';
if ($admin) $perfilPadrao = isset($doc['perfis']['adm']) ? 'adm' : '';
$json([
    'ok' => true, 'idioma' => $idioma, 'csrf' => (string)$_SESSION['ajuda_csrf'], 'usuario' => (string)($_SESSION['usuario']['nome'] ?? ''),
    'perfil_padrao' => $perfilPadrao, 'perfis' => $perfis, 'assuntos' => $assuntos, 'guias' => $guias, 'telas' => $telas, 'menus' => $menusUsuario,
]);
