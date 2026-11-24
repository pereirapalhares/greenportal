<?php
declare(strict_types=1);

/*
 * Segurança › Usuários › "Grupos do usuário" (19/11/2026, pedido do usuário: um usuário pode pertencer a um ou mais grupos).
 *
 * Os vínculos ficam em responsabilidade_grupoxusuario (já existia desde a migração 093 e já é usada pelas responsabilidades,
 * regras de responsáveis, delegações e substituições pela view vw_responsabilidade_grupoxusuario). usuarios.codgrupo continua
 * sendo o GRUPO PRINCIPAL (perfil sugerido, filtros, exportação). Regra mantida aqui: o grupo principal sempre tem vínculo
 * ativo na tabela (a view só usa usuarios.codgrupo quando NÃO há linha do par, nem inativa).
 *
 * Lista dupla: Grupos disponíveis × Grupos do usuário. › inclui, ‹ retira (o principal não sai: escolha outro antes), o rádio
 * define o principal. Grava na hora (sp_crud_insert/update). Retirar = ATIVO 0 (histórico preservado).
 * Mesmo controle da tela de usuários: ADMINISTRADOR ou ADM DA EMPRESA + transação SEGURANCA_USUARIOS; o ADM DA EMPRESA não
 * altera usuários ADMINISTRADOR. Auditoria: SEGURANCA_USUARIOS / GRUPOS.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/AuditService.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';

$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$idioma = IdiomaService::idioma();
$L = static fn(string $pt, string $en, string $es): string => ['pt' => $pt, 'en' => $en, 'es' => $es][$idioma] ?? $pt;
$responder = static function (array $d, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
$negar = static function () use ($post, $responder, $L): never {
    $m = $L('Perfil sem permissão de acesso!', 'Profile without access permission!', '¡Perfil sin permiso de acceso!');
    if ($post) $responder(['ok' => false, 'message' => $m], 403);
    http_response_code(403); exit($m);
};
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) { http_response_code(401); exit; }
$pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { http_response_code(401); exit; }
$perfil = strtoupper(trim((string)($_SESSION['usuario']['codigo_perfil'] ?? '')));
$admPlataforma = $perfil === 'ADMINISTRADOR';
if (!$admPlataforma && !in_array($perfil, ['ADM DA EMPRESA', 'ADM EMPRESA', 'ADM_DA_EMPRESA'], true)) $negar();
$perm = PermissaoTransacao::obter('SEGURANCA_USUARIOS');
if (!$admPlataforma && empty($perm['pode_visualizar'])) $negar();
$podeEditar = $admPlataforma || !empty($perm['pode_editar']);
$eu = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$colAlt = (static function () use ($pdo): bool { $s = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'responsabilidade_grupoxusuario' AND COLUMN_NAME = 'CODUSUARIOALTERACAO'"); $n = (int)$s->fetchColumn(); $s->closeCursor(); return $n > 0; })();
if (!$colAlt) $eu = 0; // sem a coluna de auditoria na tabela: não envia o campo

$sel = static function (string $tabela, array $filtros, int $max = 1000, int $offset = 0) use ($pdo): array {
    $s = $pdo->prepare('CALL sp_crud_select(:t, :f, NULL, :m, :o)');
    $s->bindValue('t', $tabela); $s->bindValue('f', json_encode($filtros, JSON_UNESCAPED_UNICODE));
    $s->bindValue('m', $max, PDO::PARAM_INT); $s->bindValue('o', $offset, PDO::PARAM_INT);
    $s->execute(); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r;
};
$crud = static function (string $proc, array $p) use ($pdo): void {
    $s = $pdo->prepare("CALL $proc(" . implode(', ', array_map(static fn($k) => ':' . $k, array_keys($p))) . ')');
    $s->execute(array_map(static fn($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v, $p)); $s->closeCursor();
};
$exato = static fn($v): array => ['value' => (string)$v, 'exact' => true];

$cod = max(0, (int)($_GET['codusuario'] ?? $_POST['codusuario'] ?? 0));
$alvo = null;
if ($cod > 0) {
    foreach ($sel('vw_usuarios_grid', ['codusuario' => $exato($cod)], 5) as $u) if ((int)$u['codusuario'] === $cod) { $alvo = $u; break; }
}
if (!$alvo || (!$admPlataforma && strtoupper(trim((string)($alvo['perfil_codigo'] ?? ''))) === 'ADMINISTRADOR')) {
    if ($post) $responder(['ok' => false, 'message' => $L('Usuário não encontrado.', 'User not found.', 'Usuario no encontrado.')], 404);
    http_response_code(404); exit($L('Selecione um usuário na lista.', 'Select a user in the list.', 'Seleccione un usuario en la lista.'));
}
$temTabela = (static function () use ($pdo): bool {
    $s = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'responsabilidade_grupoxusuario'");
    $n = (int)$s->fetchColumn(); $s->closeCursor(); return $n > 0;
})();

/** Grupos ativos do cadastro (lotes de 1000). */
$grupos = [];
for ($lote = 0; $lote < 50; $lote++) {
    $parte = $sel('vw_grupos_grid', ['ativo' => 1], 1000, $lote * 1000);
    foreach ($parte as $g) $grupos[(int)$g['codgrupo']] = (string)($g['nome'] ?? $g['codgrupo']);
    if (count($parte) < 1000) break;
}
asort($grupos, SORT_NATURAL | SORT_FLAG_CASE);

/** Linhas do usuário em responsabilidade_grupoxusuario: [codgrupo => linha ativa e vigente | null]. */
$linhas = static function () use ($sel, $cod, $exato, $temTabela): array {
    if (!$temTabela) return [];
    $hoje = date('Y-m-d'); $r = [];
    foreach ($sel('responsabilidade_grupoxusuario', ['CODUSUARIO' => $exato($cod)], 1000) as $x) {
        $x = array_change_key_case($x, CASE_UPPER);
        if ((int)$x['CODUSUARIO'] !== $cod) continue;
        $g = (int)$x['CODGRUPO'];
        $vigente = (int)$x['ATIVO'] === 1 && (string)$x['VALIDO_DE'] <= $hoje && ($x['VALIDO_ATE'] === null || (string)$x['VALIDO_ATE'] >= $hoje);
        if ($vigente) $r[$g] = $x; elseif (!array_key_exists($g, $r)) $r[$g] = null;
    }
    return $r;
};
$principalAtual = static function () use ($sel, $cod, $exato): int {
    foreach ($sel('vw_usuarios_grid', ['codusuario' => $exato($cod)], 5) as $u) if ((int)$u['codusuario'] === $cod) return (int)($u['codgrupo'] ?? 0);
    return 0;
};
/** Garante vínculo ativo do par (reativa a linha desativada sem data final, senão inclui uma nova). */
$vincular = static function (int $g, array &$atual) use ($sel, $crud, $cod, $exato, $eu): bool {
    if (!empty($atual[$g])) return false;
    $hoje = date('Y-m-d');
    foreach ($sel('responsabilidade_grupoxusuario', ['CODUSUARIO' => $exato($cod), 'CODGRUPO' => $exato($g)], 100) as $x) {
        $x = array_change_key_case($x, CASE_UPPER);
        if ((int)$x['CODUSUARIO'] !== $cod || (int)$x['CODGRUPO'] !== $g || (int)$x['ATIVO'] === 1 || $x['VALIDO_ATE'] !== null || (string)$x['VALIDO_DE'] > $hoje) continue;
        $crud('sp_crud_update', ['tabela' => 'responsabilidade_grupoxusuario', 'chave' => ['CODGRUPOXUSUARIO' => (int)$x['CODGRUPOXUSUARIO']], 'dados' => ['ATIVO' => 1] + ($eu > 0 ? ['CODUSUARIOALTERACAO' => $eu] : [])]);
        $atual[$g] = $x; return true;
    }
    $crud('sp_crud_insert', ['tabela' => 'responsabilidade_grupoxusuario', 'dados' => ['CODGRUPO' => $g, 'CODUSUARIO' => $cod, 'VALIDO_DE' => $hoje, 'ATIVO' => 1] + ($eu > 0 ? ['CODUSUARIOALTERACAO' => $eu] : [])]);
    $atual[$g] = ['CODGRUPO' => $g]; return true;
};

$_SESSION['seg_usuario_grupos_csrf'] ??= bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['seg_usuario_grupos_csrf'];

$estado = static function () use ($linhas, $principalAtual, $grupos): array {
    $principal = $principalAtual();
    $do = [];
    foreach ($linhas() as $g => $x) if ($x !== null) $do[$g] = true;
    if ($principal > 0) $do[$principal] = true; // sem linha do par, a view usa usuarios.codgrupo
    $lista = [];
    foreach (array_keys($do) as $g) $lista[] = ['id' => $g, 'nome' => $grupos[$g] ?? ('#' . $g), 'principal' => $g === $principal, 'inativo' => !isset($grupos[$g])];
    usort($lista, static fn($a, $b) => [$b['principal'], mb_strtolower($a['nome'])] <=> [$a['principal'], mb_strtolower($b['nome'])]);
    return ['principal' => $principal, 'grupos' => $lista];
};

if ($post) {
    if (!$podeEditar) $negar();
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $responder(['ok' => false, 'message' => $L('A tela ficou desatualizada. Feche e abra de novo.', 'This screen is out of date. Close and open it again.', 'La pantalla quedó desactualizada. Ciérrela y ábrala de nuevo.')], 419);
    if (!$temTabela) $responder(['ok' => false, 'message' => $L('Esta empresa ainda não tem a tabela de vínculos de grupos (responsabilidade_grupoxusuario).', 'This company does not have the group links table yet.', 'Esta empresa aún no tiene la tabla de vínculos de grupos.')], 409);
    $acao = (string)($_POST['acao'] ?? '');
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['grupos'] ?? [])), static fn($g) => $g > 0)));
    try {
        $pdo->beginTransaction();
        $atual = $linhas();
        $principal = $principalAtual();
        $feitos = 0; $msg = '';
        if ($acao === 'incluir') {
            foreach ($ids as $g) { if (!isset($grupos[$g])) continue; if ($vincular($g, $atual)) $feitos++; }
            if ($principal <= 0 && $ids && isset($grupos[$ids[0]])) {
                $crud('sp_crud_update', ['tabela' => 'usuarios', 'chave' => ['codusuario' => $cod], 'dados' => ['codgrupo' => $ids[0]]]);
                $principal = $ids[0];
            }
            $msg = $L("$feitos grupo(s) incluído(s).", "$feitos group(s) added.", "$feitos grupo(s) incluido(s).");
        } elseif ($acao === 'retirar') {
            if (in_array($principal, $ids, true)) { $pdo->rollBack(); $responder(['ok' => false, 'message' => $L('O grupo principal não pode ser retirado. Escolha outro grupo como principal antes.', 'The main group cannot be removed. Choose another main group first.', 'El grupo principal no se puede retirar. Elija otro grupo principal antes.')] + $estado(), 422); }
            foreach ($ids as $g) {
                foreach ($atual as $gg => $x) if ($gg === $g && $x !== null && isset($x['CODGRUPOXUSUARIO'])) {
                    $crud('sp_crud_update', ['tabela' => 'responsabilidade_grupoxusuario', 'chave' => ['CODGRUPOXUSUARIO' => (int)$x['CODGRUPOXUSUARIO']], 'dados' => ['ATIVO' => 0] + ($eu > 0 ? ['CODUSUARIOALTERACAO' => $eu] : [])]);
                    $feitos++;
                }
            }
            $msg = $L("$feitos grupo(s) retirado(s).", "$feitos group(s) removed.", "$feitos grupo(s) retirado(s).");
        } elseif ($acao === 'principal') {
            $g = $ids[0] ?? 0;
            if (!isset($grupos[$g])) { $pdo->rollBack(); $responder(['ok' => false, 'message' => $L('Grupo inválido.', 'Invalid group.', 'Grupo inválido.')] + $estado(), 422); }
            if ($principal > 0 && $principal !== $g) $vincular($principal, $atual); // o principal anterior continua como grupo do usuário
            $vincular($g, $atual);
            if ($principal !== $g) $crud('sp_crud_update', ['tabela' => 'usuarios', 'chave' => ['codusuario' => $cod], 'dados' => ['codgrupo' => $g]]);
            $msg = $principal === $g ? $L('Este já é o grupo principal.', 'This is already the main group.', 'Este ya es el grupo principal.')
                : $L('Grupo principal alterado. O perfil do usuário não muda.', 'Main group changed. The user profile does not change.', 'Grupo principal cambiado. El perfil del usuario no cambia.');
        } else {
            $pdo->rollBack(); $responder(['ok' => false, 'message' => 'Ação inválida.'], 400);
        }
        $pdo->commit();
        AuditService::record($pdo, 'SEGURANCA_USUARIOS', 'GRUPOS', 'SUCESSO', 'usuarios', (string)$cod, ['acao' => $acao, 'grupos' => $ids]);
        $responder(['ok' => true, 'message' => $msg] + $estado());
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('seguranca_usuario_grupos: ' . $e->getMessage());
        $responder(['ok' => false, 'message' => $L('Não foi possível salvar.', 'Could not save.', 'No fue posible guardar.')], 500);
    }
}

$inicial = $estado();
$theme = in_array((string)($_SESSION['layout_tema'] ?? ''), ['verde', 'azul', 'cinza'], true) ? (string)$_SESSION['layout_tema'] : 'verde';
session_write_close();
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$T = [
    'disponiveis' => $L('Grupos disponíveis', 'Available groups', 'Grupos disponibles'),
    'do_usuario' => $L('Grupos do usuário', 'User groups', 'Grupos del usuario'),
    'filtrar' => $L('Filtrar', 'Filter', 'Filtrar'),
    'incluir' => $L('Incluir os marcados', 'Add selected', 'Incluir los marcados'),
    'retirar' => $L('Retirar os marcados', 'Remove selected', 'Retirar los marcados'),
    'principal' => $L('Principal', 'Main', 'Principal'),
    'tornar' => $L('Tornar principal', 'Make main', 'Hacer principal'),
    'principal_trava' => $L('Grupo principal: para retirar, escolha outro principal antes', 'Main group: choose another main group before removing it', 'Grupo principal: para retirarlo, elija otro principal antes'),
    'vazio' => $L('Nenhum grupo.', 'No groups.', 'Ningún grupo.'),
    'erro' => $L('Não foi possível salvar.', 'Could not save.', 'No fue posible guardar.'),
    'inativo' => $L('grupo inativo', 'inactive group', 'grupo inactivo'),
];
?><!doctype html>
<html lang="<?= $idioma === 'pt' ? 'pt-BR' : $e($idioma) ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($T['do_usuario']) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2">
<link rel="stylesheet" href="../assets/css/app.css?v=4"><link rel="stylesheet" href="../assets/css/main.css?v=18"><link rel="stylesheet" href="../assets/css/minha-conta.css?v=3">
<style>
.ug-cab{margin-bottom:12px}.ug-cab strong{font-size:15px}.ug-cab small{color:#6b7a73;margin-left:6px}
.ug-wrap{display:grid;grid-template-columns:1fr auto 1fr;gap:12px;align-items:stretch;min-height:360px}
.ug-col{display:flex;flex-direction:column;border:1px solid #dfe7e3;border-radius:8px;background:#fff;min-width:0}
.ug-col h6{margin:0;padding:8px 10px;border-bottom:1px solid #edf2ef;font-size:13px;font-weight:600;display:flex;justify-content:space-between;align-items:center}
.ug-col .ug-busca{padding:6px 10px;border-bottom:1px solid #edf2ef}
.ug-lista{list-style:none;margin:0;padding:4px 0;overflow:auto;flex:1;max-height:calc(100vh - 190px)}
.ug-lista li{display:flex;align-items:center;gap:8px;padding:4px 10px;font-size:13px}
.ug-lista li:hover{background:#f4f8f6}
.ug-lista li label{flex:1;cursor:pointer;margin:0;min-width:0;overflow:hidden;text-overflow:ellipsis}
.ug-lista li.principal{background:#eef7f2;font-weight:600}
.ug-meio{display:flex;flex-direction:column;justify-content:center;gap:8px}
.ug-vazio{padding:10px;color:#8a9893;font-size:13px}
</style>
</head>
<body class="account-iframe-page theme-<?= $e($theme) ?>">
<main class="account-iframe-content">
  <div class="ug-cab"><strong><?= $e($alvo['nome']) ?></strong><small><?= $e($alvo['userid'] ?? '') ?></small>
    <div class="small text-muted mt-1"><?= $e($L('O usuário pode pertencer a vários grupos; as regras de responsáveis por grupo valem para todos eles. O grupo marcado como principal é o grupo de referência do cadastro (o primeiro incluído, se ainda não houver). As permissões continuam vindo do perfil.', 'The user can belong to several groups; group-based responsibility rules apply to all of them. The group marked as main is the reference group of the record (the first one added, if there is none yet). Permissions still come from the profile.', 'El usuario puede pertenecer a varios grupos; las reglas de responsables por grupo valen para todos ellos. El grupo marcado como principal es el grupo de referencia del registro (el primero incluido, si aún no hay). Los permisos siguen viniendo del perfil.')) ?></div>
  </div>
  <?php if (!$temTabela): ?><div class="alert alert-warning py-2"><?= $e($L('Esta empresa ainda não tem a tabela de vínculos de grupos (responsabilidade_grupoxusuario). Só o grupo principal está disponível.', 'This company does not have the group links table yet. Only the main group is available.', 'Esta empresa aún no tiene la tabla de vínculos de grupos. Solo está disponible el grupo principal.')) ?></div><?php endif; ?>
  <div class="ug-wrap">
    <section class="ug-col">
      <h6><span><?= $e($T['disponiveis']) ?></span><span class="badge text-bg-light" id="ugQtdDisp"></span></h6>
      <div class="ug-busca"><input type="search" class="form-control form-control-sm" id="ugFiltro" placeholder="<?= $e($T['filtrar']) ?>"></div>
      <ul class="ug-lista" id="ugDisp"></ul>
    </section>
    <div class="ug-meio">
      <button type="button" class="btn btn-sm btn-outline-success" id="ugIncluir" title="<?= $e($T['incluir']) ?>" aria-label="<?= $e($T['incluir']) ?>"<?= $podeEditar && $temTabela ? '' : ' disabled' ?>><i class="bi bi-chevron-right"></i></button>
      <button type="button" class="btn btn-sm btn-outline-danger" id="ugRetirar" title="<?= $e($T['retirar']) ?>" aria-label="<?= $e($T['retirar']) ?>"<?= $podeEditar && $temTabela ? '' : ' disabled' ?>><i class="bi bi-chevron-left"></i></button>
    </div>
    <section class="ug-col">
      <h6><span><?= $e($T['do_usuario']) ?></span><span class="badge text-bg-light" id="ugQtdDo"></span></h6>
      <ul class="ug-lista" id="ugDo"></ul>
    </section>
  </div>
  <div class="account-message mt-2" id="ugMsg" role="status"></div>
</main>
<script>
(function () {
  var TODOS = <?= json_encode(array_map(static fn($id, $n) => ['id' => $id, 'nome' => $n], array_keys($grupos), array_values($grupos)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var estado = <?= json_encode($inicial, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var T = <?= json_encode($T, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var editar = <?= $podeEditar && $temTabela ? 'true' : 'false' ?>, csrf = <?= json_encode($csrf) ?>, cod = <?= $cod ?>;
  var disp = document.getElementById('ugDisp'), doU = document.getElementById('ugDo'), filtro = document.getElementById('ugFiltro'), msg = document.getElementById('ugMsg');
  function norm(s) { return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }
  function aviso(t, erro) { msg.textContent = t || ''; msg.className = 'account-message mt-2 ' + (erro ? 'text-danger' : 'text-success'); }
  function item(g, lado) {
    var li = document.createElement('li'), id = 'ug' + lado + g.id;
    var cb = document.createElement('input'); cb.type = 'checkbox'; cb.className = 'form-check-input m-0'; cb.value = g.id; cb.id = id; cb.disabled = !editar || (lado === 'do' && g.principal);
    var lb = document.createElement('label'); lb.htmlFor = id; lb.textContent = g.nome; lb.title = g.nome;
    li.appendChild(cb); li.appendChild(lb);
    if (lado === 'do') {
      if (g.principal) { li.className = 'principal'; li.title = T.principal_trava; var b = document.createElement('span'); b.className = 'badge text-bg-success'; b.textContent = T.principal; li.appendChild(b); }
      else if (editar) { var r = document.createElement('button'); r.type = 'button'; r.className = 'btn btn-link p-0 text-decoration-none'; r.style.fontSize = '12px'; r.textContent = T.tornar; r.addEventListener('click', function () { enviar('principal', [g.id]); }); li.appendChild(r); }
      if (g.inativo) { var s = document.createElement('small'); s.className = 'text-muted'; s.textContent = '(' + T.inativo + ')'; li.appendChild(s); }
    }
    return li;
  }
  function desenhar() {
    var tem = {}; estado.grupos.forEach(function (g) { tem[g.id] = true; });
    var f = norm(filtro.value);
    disp.innerHTML = ''; doU.innerHTML = '';
    var livres = TODOS.filter(function (g) { return !tem[g.id]; });
    var vis = livres.filter(function (g) { return !f || norm(g.nome).indexOf(f) >= 0; });
    vis.forEach(function (g) { disp.appendChild(item(g, 'disp')); });
    if (!vis.length) disp.innerHTML = '<li class="ug-vazio">' + T.vazio + '</li>';
    estado.grupos.forEach(function (g) { doU.appendChild(item(g, 'do')); });
    if (!estado.grupos.length) doU.innerHTML = '<li class="ug-vazio">' + T.vazio + '</li>';
    document.getElementById('ugQtdDisp').textContent = livres.length;
    document.getElementById('ugQtdDo').textContent = estado.grupos.length;
  }
  function marcados(ul) { return Array.prototype.map.call(ul.querySelectorAll('input:checked'), function (c) { return c.value; }); }
  function enviar(acao, ids) {
    if (!ids.length) return;
    var d = new FormData(); d.append('csrf', csrf); d.append('codusuario', cod); d.append('acao', acao); ids.forEach(function (i) { d.append('grupos[]', i); });
    fetch(window.location.pathname, { method: 'POST', body: d, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (j) { if (j.grupos) { estado = { principal: j.principal, grupos: j.grupos }; desenhar(); } aviso(j.message || (j.ok ? '' : T.erro), !j.ok); })
      .catch(function () { aviso(T.erro, true); });
  }
  filtro.addEventListener('input', desenhar);
  document.getElementById('ugIncluir').addEventListener('click', function () { enviar('incluir', marcados(disp)); });
  document.getElementById('ugRetirar').addEventListener('click', function () { enviar('retirar', marcados(doU)); });
  desenhar();
}());
</script>
</body>
</html>
