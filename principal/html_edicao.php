<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: text/html; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit('Sessão expirada.'); }

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
$env = GreenPortalDatabase::env();
$id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$lang = (string)($_GET['idioma'] ?? $_POST['idioma'] ?? 'pt');
if (!in_array($lang, ['pt', 'en', 'es'], true)) $lang = 'pt';
$pdo = GreenPortalDatabase::connect($env, $env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp', 'GUNIVERSAL');
$permission = PermissaoTransacao::obter('CONFIG_AJUDA_TRANSACOES');
if (empty($permission['pode_editar'])) { http_response_code(403); exit('Acesso negado.'); }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save' && $id > 0) {
    $field = 'texto_' . $lang;
    $value = strip_tags((string)($_POST['texto'] ?? ''), '<p><br><strong><b><em><i><ul><ol><li><a><h1><h2><h3><span><font><img>');
    $value = preg_replace_callback('/<(a|img)\b[^>]*>/i', static function (array $match): string {
        $tag = strtolower($match[1]);
        if (!preg_match("~(?:href|src)\s*=\s*['\"](https?://[^'\"]+)['\"]~i", $match[0], $url)) return '';
        return '<' . $tag . ' ' . ($tag === 'a' ? 'href' : 'src') . '="' . htmlspecialchars($url[1], ENT_QUOTES, 'UTF-8') . '"' . ($tag === 'a' ? ' target="_blank" rel="noopener noreferrer"' : '') . '>';
    }, $value) ?? '';
    GreenPortalDatabase::call($pdo, 'CALL sp_crud_update(:tabela,:chave,:dados)', ['tabela' => 'ajuda_transacoes', 'chave' => json_encode(['codajudatransacao' => $id]), 'dados' => json_encode([$field => $value], JSON_UNESCAPED_UNICODE)]);
    $saved = true;
}

$rows = GreenPortalDatabase::call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'ajuda_transacoes', 'filtros' => json_encode(['codajudatransacao' => $id, 'ativo' => 1], JSON_UNESCAPED_UNICODE)]);
$row = $rows[0] ?? null;
if (!$row) { http_response_code(404); exit('Registro não encontrado.'); }
$title = (string)($row['titulo_' . $lang] ?? 'Ajuda');
$text = (string)($row['texto_' . $lang] ?? '');
$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Edição da ajuda</title><link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><style>body{padding:16px;background:#fff;color:var(--gp-text)}.editor{min-height:300px;border:1px solid var(--gp-border);border-radius:4px;padding:12px;overflow:auto}.actions{margin-top:12px;display:flex;justify-content:flex-end}</style><?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?></head><body><h1 class="h5">Editar ajuda — <?= $e((string)$row['transacao']) ?> — <?= strtoupper($e($lang)) ?></h1><?php if (!empty($saved)): ?><div class="alert alert-success py-2">Texto salvo com sucesso.</div><?php endif; ?><form method="post"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="idioma" value="<?= $e($lang) ?>"><input type="hidden" name="texto" id="texto"><div id="editor" class="editor" contenteditable="true"><?= $text ?></div><div class="actions"><button type="submit" class="btn btn-success" onclick="document.getElementById('texto').value=document.getElementById('editor').innerHTML">Salvar</button></div></form></body></html>
