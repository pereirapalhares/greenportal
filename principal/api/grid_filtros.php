<?php
declare(strict_types=1);

/*
 * Filtros favoritos dos grids (Revisão G item 6; migração 421, 26/11/2026). Usado por assets/js/filtros-favoritos.js (estrela na
 * barra de cada grid).
 * GET  tela                                  → {ok, csrf, filtros:[{id, nome, filtros, meu, compartilhado, usuario}]} (meus + compartilhados)
 * POST acao=salvar, tela, nome, filtros, compartilhar, csrf → grava (mesmo nome do mesmo usuário na mesma tela = substitui)
 * POST acao=excluir, id, csrf               → apaga (só o dono; o ADMINISTRADOR apaga qualquer um)
 * Leitura por sp_crud_select; gravação por sp_crud_insert / sp_crud_update / sp_crud_delete.
 */
require_once dirname(__DIR__, 2) . '/classes/PaginaSimples.php';
use PaginaSimples as P;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$json = static function (array $d, int $c = 200): never { http_response_code($c); exit(json_encode($d, JSON_UNESCAPED_UNICODE)); };
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok' => false, 'message' => 'Sessão expirada.'], 401);
P::abrir('', true, true);
if (!P::existe('grid_filtro_favorito')) $json(['ok' => false, 'message' => P::L('Filtros favoritos ainda não instalados nesta empresa (migração 421).', 'Favorite filters are not installed yet (migration 421).', 'Filtros favoritos aún no instalados (migración 421).')], 503);
$tela = mb_substr(trim((string)($_POST['tela'] ?? $_GET['tela'] ?? '')), 0, 255);
$csrf = P::csrf('grid_filtros_csrf');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        P::conferirCsrf('grid_filtros_csrf', true);
        $acao = (string)($_POST['acao'] ?? '');
        if ($acao === 'salvar') {
            $nome = mb_substr(trim((string)preg_replace('/\s+/', ' ', (string)($_POST['nome'] ?? ''))), 0, 100);
            $filtros = json_decode((string)($_POST['filtros'] ?? '{}'), true);
            if ($tela === '' || $nome === '' || !is_array($filtros) || !$filtros) $json(['ok' => false, 'message' => P::L('Dê um nome e use pelo menos um filtro.', 'Give it a name and use at least one filter.', 'Dé un nombre y use al menos un filtro.')], 422);
            $filtros = array_slice(array_map(static fn($v) => mb_substr((string)$v, 0, 500), $filtros), 0, 60, true);
            $dados = ['FILTROS' => json_encode($filtros, JSON_UNESCAPED_UNICODE), 'COMPARTILHADO' => (string)($_POST['compartilhar'] ?? '') === '1' ? 'S' : 'N'];
            $atual = null;
            foreach (P::sel('grid_filtro_favorito', ['TELA' => $tela, 'CODUSUARIO' => P::$uid], 200) as $r) if (mb_strtolower((string)$r['NOME']) === mb_strtolower($nome)) $atual = $r;
            if ($atual) P::upd('grid_filtro_favorito', ['CODFILTRO' => (int)$atual['CODFILTRO']], $dados + ['NOME' => $nome]);
            else P::ins('grid_filtro_favorito', ['TELA' => $tela, 'NOME' => $nome, 'CODUSUARIO' => P::$uid] + $dados);
            AuditService::record(P::$pdo, 'grid_filtros', 'SALVAR', 'SUCESSO', 'grid_filtro_favorito', $tela, ['nome' => $nome, 'compartilhado' => $dados['COMPARTILHADO']]);
        } elseif ($acao === 'excluir') {
            $r = P::sel('grid_filtro_favorito', ['CODFILTRO' => (int)($_POST['id'] ?? 0)], 1)[0] ?? null;
            if (!$r || ((int)$r['CODUSUARIO'] !== P::$uid && !P::admin())) $json(['ok' => false, 'message' => P::L('Só quem criou pode apagar este filtro.', 'Only its creator can delete this filter.', 'Solo quien lo creó puede borrar este filtro.')], 403);
            P::del('grid_filtro_favorito', ['CODFILTRO' => (int)$r['CODFILTRO']]);
            $tela = (string)$r['TELA'];
        } else $json(['ok' => false, 'message' => 'Ação inválida.'], 400);
    }
    $lista = [];
    if ($tela !== '') foreach (P::sel('vw_grid_filtro_favorito', ['TELA' => $tela], 500) as $r) {
        $meu = (int)$r['CODUSUARIO'] === P::$uid;
        if (!$meu && (string)$r['COMPARTILHADO'] !== 'S') continue;
        $lista[] = ['id' => (int)$r['CODFILTRO'], 'nome' => (string)$r['NOME'], 'filtros' => json_decode((string)$r['FILTROS'], true) ?: (object)[], 'meu' => $meu, 'compartilhado' => (string)$r['COMPARTILHADO'] === 'S', 'usuario' => (string)($r['USUARIO'] ?? '')];
    }
    usort($lista, static fn($a, $b) => [!$a['meu'], mb_strtolower($a['nome'])] <=> [!$b['meu'], mb_strtolower($b['nome'])]);
    $json(['ok' => true, 'csrf' => $csrf, 'filtros' => $lista]);
} catch (Throwable $e) { $json(['ok' => false, 'message' => IdiomaService::erro($e, 'gravar')], 500); }
