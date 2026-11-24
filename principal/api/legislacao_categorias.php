<?php
declare(strict_types=1);

/*
 * Categorias de uma lei nesta empresa (migração 407; onda 2 da Revisão G, LEG-26/27/28 — 24/11/2026).
 * Catálogo central glegislacao_dbp.legislacao_categoria (só o ADMINISTRADOR mantém; aqui só leitura, por vw_legislacao_categorias).
 * O usuário vincula a lei a uma ou mais categorias SÓ para a empresa dele (legislacaoxcategoria_empresa). Lei sem vínculo próprio
 * usa o vínculo central (glegislacao_dbp.legislacaoxcategoria), lido por vw_legislacao_categorias_empresa.
 * Responde ao botão "Categorias" de Requisitos legais (assets/js/legislacao-categorias.js).
 *
 * GET  leg                              → {ok, lei, categorias:[{id,nome}], selecionadas:[ids], origem: EMPRESA|CENTRAL|NENHUMA|SEM, central:[ids], pode_editar, csrf}
 * POST leg, csrf, acao=salvar, cats[]   → grava as categorias da empresa (nenhuma marcada = "sem categoria" nesta empresa)
 * POST leg, csrf, acao=padrao           → apaga o vínculo da empresa (volta às categorias centrais)
 * Permissão: Requisitos legais (REQUISITOS_LEGAIS) — visualizar para ler, editar para gravar. Escrita por sp_crud_insert/sp_crud_delete.
 */
require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
require_once dirname(__DIR__, 2) . '/classes/PermissaoTransacao.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$json = static function (array $d, int $status = 200): never { http_response_code($status); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
$T = static fn(string $c, array $p): string => IdiomaService::t('legislacao_categorias.' . $c, $p);
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok' => false, 'message' => $T('sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.'])], 401);
$leg = max(0, (int)($_POST['leg'] ?? $_GET['leg'] ?? 0));
if ($leg <= 0) $json(['ok' => false, 'message' => $T('lei', ['pt' => 'Lei inválida.', 'en' => 'Invalid law.', 'es' => 'Ley inválida.'])], 400);

try {
    $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $json(['ok' => false, 'message' => $T('sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.'])], 401);
    $perm = PermissaoTransacao::obter('REQUISITOS_LEGAIS');
    if (empty($perm['pode_visualizar'])) $json(['ok' => false, 'message' => $T('sem_permissao', ['pt' => 'Sem permissão em Requisitos legais.', 'en' => 'No permission in Legal requirements.', 'es' => 'Sin permiso en Requisitos legales.'])], 403);
    $podeEditar = !empty($perm['pode_editar']);
    $uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);

    $ler = static function (string $fonte, array $iguais, int $max = 500) use ($pdo): array {
        $f = [];
        foreach ($iguais as $c => $v) $f[$c] = ['value' => (string)$v, 'exact' => true];
        $s = $pdo->prepare('CALL sp_crud_select(:t, :f, NULL, :n, 0)');
        $s->bindValue('t', $fonte); $s->bindValue('f', json_encode($f ?: new stdClass(), JSON_UNESCAPED_UNICODE)); $s->bindValue('n', $max, PDO::PARAM_INT);
        $s->execute(); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
        return array_values(array_filter(array_map(static fn($l) => array_change_key_case($l, CASE_UPPER), $r), static function (array $l) use ($iguais): bool {
            foreach ($iguais as $c => $v) if ((string)($l[strtoupper($c)] ?? '') !== (string)$v) return false;
            return true;
        }));
    };
    // A lei precisa ser desta empresa.
    if (!$ler('legislacaoxempresa', ['CODLEGISLACAO' => $leg], 1)) $json(['ok' => false, 'message' => $T('nao_empresa', ['pt' => 'Esta lei não está nos requisitos da empresa.', 'en' => 'This law is not in the company requirements.', 'es' => 'Esta ley no está en los requisitos de la empresa.'])], 404);
    $proprias = $ler('legislacaoxcategoria_empresa', ['CODLEGISLACAO' => $leg], 200);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!$podeEditar) $json(['ok' => false, 'message' => $T('sem_editar', ['pt' => 'Seu perfil não pode alterar Requisitos legais.', 'en' => 'Your profile cannot edit Legal requirements.', 'es' => 'Su perfil no puede editar Requisitos legales.'])], 403);
        if (!hash_equals((string)($_SESSION['legislacao_categorias_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) $json(['ok' => false, 'message' => $T('csrf', ['pt' => 'A janela expirou: abra as Categorias de novo.', 'en' => 'The window expired: open Categories again.', 'es' => 'La ventana expiró: abra Categorías de nuevo.'])], 419);
        $acao = (string)($_POST['acao'] ?? 'salvar');
        $validas = array_map(static fn($c) => (int)$c['CODCATEGORIALEGISLACAO'], $ler('vw_legislacao_categorias', [], 500));
        $novas = $acao === 'padrao' ? [] : array_values(array_unique(array_filter(array_map('intval', (array)($_POST['cats'] ?? [])), static fn($c) => in_array($c, $validas, true))));
        if ($acao !== 'padrao' && !$novas) $novas = [0]; // nenhuma marcada: "sem categoria" nesta empresa
        $del = $pdo->prepare('CALL sp_crud_delete(?, ?)');
        foreach ($proprias as $p) { $del->execute(['legislacaoxcategoria_empresa', json_encode(['CODLEGCATEMPRESA' => (int)$p['CODLEGCATEMPRESA']])]); $del->closeCursor(); }
        $ins = $pdo->prepare('CALL sp_crud_insert(?, ?)');
        foreach ($novas as $c) { $ins->execute(['legislacaoxcategoria_empresa', json_encode(['CODLEGISLACAO' => $leg, 'CODCATEGORIALEGISLACAO' => $c, 'CODUSUARIO' => $uid])]); $ins->closeCursor(); }
        try { $a = $pdo->prepare('CALL sp_auditoria_registrar(?, ?, ?, ?, ?, ?, ?)'); $a->execute([$uid, 'modulolegal_requisitoslegais', $acao === 'padrao' ? 'CATEGORIAS_PADRAO' : 'CATEGORIAS_SALVAR', 'legislacaoxcategoria_empresa', (string)$leg, 'SUCESSO', json_encode(['categorias' => $novas])]); $a->closeCursor(); } catch (Throwable) {}
        $json(['ok' => true, 'message' => $acao === 'padrao'
            ? $T('ok_padrao', ['pt' => 'A lei voltou a usar as categorias da base central.', 'en' => 'The law uses the central categories again.', 'es' => 'La ley volvió a usar las categorías centrales.'])
            : $T('ok', ['pt' => 'Categorias da lei gravadas para esta empresa. As regras de Responsáveis do Legal valem na hora.', 'en' => 'Law categories saved for this company. The Legal responsibility rules apply right away.', 'es' => 'Categorías de la ley guardadas para esta empresa. Las reglas de Responsables del Legal valen de inmediato.'])]);
    }

    $categorias = array_map(static fn($c) => ['id' => (int)$c['CODCATEGORIALEGISLACAO'], 'nome' => (string)$c['NOME']], $ler('vw_legislacao_categorias', [], 500));
    usort($categorias, static fn($a, $b) => strnatcasecmp($a['nome'], $b['nome']));
    $central = array_map(static fn($c) => (int)$c['CODCATEGORIALEGISLACAO'], array_filter($ler('vw_legislacao_categorias_empresa', ['CODLEGISLACAO' => $leg], 200), static fn($c) => (string)$c['ORIGEM'] === 'CENTRAL'));
    $empresa = array_map(static fn($c) => (int)$c['CODCATEGORIALEGISLACAO'], $proprias);
    $origem = $proprias ? (array_filter($empresa) ? 'EMPRESA' : 'NENHUMA') : ($central ? 'CENTRAL' : 'SEM');
    if ($podeEditar) { if (empty($_SESSION['legislacao_categorias_csrf'])) $_SESSION['legislacao_categorias_csrf'] = bin2hex(random_bytes(16)); }
    $json(['ok' => true, 'lei' => $leg, 'categorias' => $categorias, 'selecionadas' => $proprias ? array_values(array_filter($empresa)) : array_values($central),
        'central' => array_values($central), 'origem' => $origem, 'pode_editar' => $podeEditar, 'csrf' => $podeEditar ? (string)$_SESSION['legislacao_categorias_csrf'] : '']);
} catch (Throwable $e) {
    error_log('legislacao_categorias: ' . $e->getMessage());
    $json(['ok' => false, 'message' => $T('erro', ['pt' => 'Não foi possível ler ou gravar as categorias. Confira se a migração 407 foi aplicada.', 'en' => 'Could not read or save the categories.', 'es' => 'No fue posible leer o guardar las categorías.'])], 500);
}
