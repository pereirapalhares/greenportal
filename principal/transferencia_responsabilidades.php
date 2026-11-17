<?php
declare(strict_types=1);

/*
 * Home › Transferências (migração 380; GER-8 — transferência/delegação de responsabilidades em todos os módulos).
 * Para ADMINISTRADOR / ADM DA EMPRESA (todos os usuários) e gestores (a equipe: usuarios.codusuario_superior, todos os níveis).
 * Decisão do usuário (16/11/2026): REDIRECIONAR, sem alterar os registros (até a virada o sincronismo com o legado desfaria):
 * enquanto vale, o que é de quem passa (nos módulos escolhidos) aparece e é cobrado de quem assume — Minhas pendências, sino,
 * Agenda, barra direita, alertas e e-mails — e quem passa deixa de receber. Temporária (até a data de volta) ou definitiva
 * (até ser encerrada). Prévia por módulo, motivo obrigatório, log e avisos (classes/TransferenciaResponsabilidade.php).
 */
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$sair = static function (int $c, string $m): never { http_response_code($c); exit(htmlspecialchars($m, ENT_QUOTES, 'UTF-8')); };
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $sair(401, 'Sessão expirada.');
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PendenciasGestao.php';
require_once dirname(__DIR__) . '/classes/TransferenciaResponsabilidade.php';
require_once dirname(__DIR__) . '/classes/DashboardGrid.php';
$pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $sair(401, 'Sessão expirada.');
$T = TransferenciaResponsabilidade::class;
if (!$T::disponivel($pdo)) $sair(503, 'A Transferência de responsabilidades ainda não foi ativada nesta empresa (migração 380).');
$autor = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$adm = PendenciasGestao::perfilAdm();
$escopo = PendenciasGestao::escopo($pdo, $autor);
if (!$adm && count($escopo) < 2) $sair(403, 'A Transferência de responsabilidades é para administradores e gestores com equipe.');
if (empty($_SESSION['gp380_csrf'])) $_SESSION['gp380_csrf'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['gp380_csrf'];
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$origem = (int)($_REQUEST['origem'] ?? 0); if (!isset($escopo[$origem])) $origem = 0;
$msg = mb_substr(trim((string)($_GET['ok'] ?? '')), 0, 300); $erro = '';
$hoje = (string)$pdo->query('SELECT CURDATE()')->fetchColumn();

// ---------------------------------------------------------------- ações
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão da tela expirada: recarregue a página.');
        $acao = (string)($_POST['acao'] ?? '');
        if ($acao === 'transferir') {
            $destino = (int)($_POST['destino'] ?? 0);
            if (!isset($escopo[$origem]) || !isset($escopo[$destino])) throw new RuntimeException($adm ? 'Escolha pessoas ativas.' : 'As duas pessoas precisam ser da sua equipe.');
            $mods = array_map('strval', (array)($_POST['modulos'] ?? []));
            $cod = $T::transferir($pdo, $autor, $origem, $destino, $mods, (string)($_POST['tipo'] ?? ''), (string)($_POST['inicio'] ?? ''), (string)($_POST['fim'] ?? '') ?: null,
                (string)($_POST['motivo'] ?? ''), $T::previa($pdo, $origem));
            $msg = 'Transferência nº ' . $cod . ' registrada: o que é de ' . $escopo[$origem] . ' passa a aparecer para ' . $escopo[$destino] . '.';
            header('Location: transferencia_responsabilidades.php?' . http_build_query(['ok' => $msg, 'aba' => 'lista'])); exit;
        }
        if ($acao === 'encerrar') {
            $cod = (int)($_POST['codtransferencia'] ?? 0);
            $t = $T::ler($pdo, 'vw_transferencias_responsabilidade_grid', ['CODTRANSFERENCIA' => ['value' => (string)$cod, 'exact' => true]], 1)[0] ?? null;
            if (!$t || (!$adm && !isset($escopo[(int)$t['CODUSUARIO_ORIGEM']]))) throw new RuntimeException('Esta transferência não é da sua equipe.');
            $T::encerrar($pdo, $autor, $cod, (string)($_POST['motivo'] ?? ''));
            header('Location: transferencia_responsabilidades.php?' . http_build_query(['ok' => 'Transferência nº ' . $cod . ' encerrada: as responsabilidades voltaram para ' . $t['ORIGEM'] . '.', 'aba' => 'lista'])); exit;
        }
        throw new RuntimeException('Ação inválida.');
    } catch (Throwable $ex) {
        $erro = $ex instanceof RuntimeException ? $ex->getMessage() : 'Não foi possível concluir. Avise o administrador.';
        if (!($ex instanceof RuntimeException)) error_log('transferencia_responsabilidades: ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------- dados
$previa = $origem > 0 ? $T::previa($pdo, $origem) : [];
$naEquipe = static fn(array $r): bool => $adm || isset($escopo[(int)$r['CODUSUARIO_ORIGEM']]) || isset($escopo[(int)$r['CODUSUARIO_DESTINO']]);
$lista = array_values(array_filter($T::ler($pdo, 'vw_transferencias_responsabilidade_grid', [], 5), $naEquipe));
$log = array_values(array_filter($T::ler($pdo, 'vw_transferencias_responsabilidade_log', [], 3), $naEquipe));
$emVigor = count(array_filter($lista, static fn($r) => $r['ESTADO'] === 'EM_VIGOR'));
$rotEstado = ['EM_VIGOR' => ['Em vigor', 'success'], 'AGENDADA' => ['Agendada', 'info'], 'VENCIDA' => ['Venceu (voltou)', 'secondary'], 'ENCERRADA' => ['Encerrada', 'secondary']];
$dt = static fn(?string $d, bool $h = false): string => $d ? date($h ? 'd/m/Y H:i' : 'd/m/Y', strtotime($d)) : '';
$rowsLista = [];
foreach ($lista as $r) {
    [$rot, $cor] = $rotEstado[$r['ESTADO']] ?? [$r['ESTADO'], 'secondary'];
    $periodo = $r['TIPO'] === 'TEMPORARIA' ? $dt($r['INICIO']) . ' a ' . $dt($r['FIM']) : 'desde ' . $dt($r['INICIO']) . ' (definitiva)';
    $pode = $r['SITUACAO'] === 'ATIVA' && $r['ESTADO'] !== 'VENCIDA';
    $rowsLista[] = ['cells' => [
        'NUM' => ['text' => (string)$r['CODTRANSFERENCIA'], 'sort' => (string)$r['CODTRANSFERENCIA']],
        'ESTADO' => ['text' => $rot, 'html' => '<span class="badge text-bg-' . $cor . '">' . $e($rot) . '</span>'],
        'ORIGEM' => (string)$r['ORIGEM'], 'DESTINO' => (string)$r['DESTINO'],
        'MODULOS' => ['text' => $T::rotuloModulos((string)$r['MODULOS']), 'title' => $T::rotuloModulos((string)$r['MODULOS'])],
        'PERIODO' => ['text' => $periodo, 'sort' => (string)$r['INICIO']],
        'MOTIVO' => ['text' => (string)$r['MOTIVO'] . ($r['MOTIVO_ENCERRAMENTO'] ? ' · Encerrada: ' . $r['MOTIVO_ENCERRAMENTO'] : ''), 'title' => (string)$r['MOTIVO']],
        'POR' => ['text' => $r['AUTOR'] . ' · ' . $dt($r['CRIADO_EM'], true), 'sort' => (string)$r['CRIADO_EM']],
        'ACOES' => ['text' => '', 'html' => $pode ? '<button type="button" class="btn btn-sm btn-outline-danger py-0" data-tr-encerrar="' . (int)$r['CODTRANSFERENCIA'] . '" data-tr-rotulo="' . $e('nº ' . $r['CODTRANSFERENCIA'] . ': ' . $r['ORIGEM'] . ' → ' . $r['DESTINO']) . '"><i class="bi bi-arrow-counterclockwise me-1"></i>Encerrar</button>' : ''],
    ], 'grupos' => $r['ESTADO']];
}
$gridLista = DashboardGrid::grid('trLista', [
    'NUM' => ['label' => 'Nº', 'width' => '60px'], 'ESTADO' => ['label' => 'Situação', 'filter' => 'select', 'width' => '125px'],
    'ORIGEM' => ['label' => 'Quem passou', 'filter' => 'select', 'width' => '170px'], 'DESTINO' => ['label' => 'Quem assumiu', 'filter' => 'select', 'width' => '170px'],
    'MODULOS' => ['label' => 'Módulos'], 'PERIODO' => ['label' => 'Período', 'filter' => 'none', 'width' => '190px'], 'MOTIVO' => ['label' => 'Motivo'],
    'POR' => ['label' => 'Registrada por', 'filter' => 'none', 'width' => '170px'], 'ACOES' => ['label' => '', 'filter' => 'none', 'width' => '105px'],
], $rowsLista, ['transacao' => 'transferencia_responsabilidades', 'ordem' => ['NUM', 'desc'], 'por_pagina' => 50, 'vazio' => 'Nenhuma transferência registrada.',
    'grupo_inicial' => $emVigor > 0 ? ['EM_VIGOR', 'Em vigor'] : []]);
$rotAcao = ['TRANSFERIR' => 'Transferiu', 'ENCERRAR' => 'Encerrou'];
$rowsLog = [];
foreach ($log as $l) $rowsLog[] = ['cells' => [
    'QUANDO' => ['text' => $dt($l['CRIADO_EM'], true), 'sort' => (string)$l['CRIADO_EM']], 'QUEM' => (string)$l['AUTOR'], 'ACAO' => $rotAcao[$l['ACAO']] ?? (string)$l['ACAO'],
    'NUM' => ['text' => (string)$l['CODTRANSFERENCIA'], 'sort' => (string)$l['CODTRANSFERENCIA']], 'ORIGEM' => (string)$l['ORIGEM'], 'DESTINO' => (string)$l['DESTINO'],
    'MOTIVO' => ['text' => (string)$l['MOTIVO'], 'title' => (string)$l['MOTIVO']], 'DETALHE' => ['text' => (string)$l['DETALHE'], 'title' => (string)$l['DETALHE']],
]];
$gridLog = DashboardGrid::grid('trLog', [
    'QUANDO' => ['label' => 'Quando', 'filter' => 'none', 'width' => '120px'], 'QUEM' => ['label' => 'Quem', 'filter' => 'select', 'width' => '150px'],
    'ACAO' => ['label' => 'Ação', 'filter' => 'select', 'width' => '100px'], 'NUM' => ['label' => 'Nº', 'width' => '60px'],
    'ORIGEM' => ['label' => 'Quem passou', 'filter' => 'select', 'width' => '150px'], 'DESTINO' => ['label' => 'Quem assumiu', 'filter' => 'select', 'width' => '150px'],
    'MOTIVO' => ['label' => 'Motivo'], 'DETALHE' => ['label' => 'Detalhe'],
], $rowsLog, ['transacao' => 'transferencia_responsabilidades', 'ordem' => ['QUANDO', 'desc'], 'por_pagina' => 50, 'vazio' => 'Nada registrado ainda.']);
$recebidasDaOrigem = $origem > 0 ? array_values(array_filter($lista, static fn($r) => (int)$r['CODUSUARIO_DESTINO'] === $origem && $r['ESTADO'] === 'EM_VIGOR')) : [];
$theme = in_array((string)($_SESSION['layout_tema'] ?? ''), ['verde', 'azul', 'cinza'], true) ? (string)$_SESSION['layout_tema'] : 'verde';
session_write_close();
ob_start(); ?>
<form method="get" class="tr-linha">
  <label class="form-label mb-0" for="trOrigem"><b>1.</b> Quem passa as responsabilidades</label>
  <select class="form-select form-select-sm" id="trOrigem" name="origem" onchange="this.form.submit()">
    <option value="">— escolha —</option>
    <?php foreach ($escopo as $id => $nome): ?><option value="<?= (int)$id ?>"<?= $id === $origem ? ' selected' : '' ?>><?= $e($nome) ?></option><?php endforeach; ?>
  </select>
  <span class="small text-muted"><?= $adm ? count($escopo) . ' usuário(s) ativos' : 'Sua equipe: ' . count($escopo) . ' pessoa(s)' ?></span>
</form>
<?php if ($origem <= 0): ?><div class="tr-vazio">Escolha a pessoa para ver o que está com ela em cada módulo.</div>
<?php else: ?>
<?php if ($recebidasDaOrigem): ?><div class="alert alert-warning py-2 small mb-2"><i class="bi bi-info-circle me-1"></i><?= $e($escopo[$origem]) ?> está respondendo agora por responsabilidades de outra(s) pessoa(s) (<?= $e(implode(', ', array_map(static fn($r) => $r['ORIGEM'], $recebidasDaOrigem))) ?>). Essas não são passadas adiante: encerre aquela transferência e faça uma nova, se for o caso.</div><?php endif; ?>
<form method="post" id="trForm" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="acao" value="transferir"><input type="hidden" name="origem" value="<?= (int)$origem ?>">
  <div class="tr-passo"><b>2.</b> O que está com <?= $e($escopo[$origem]) ?> hoje — marque os módulos que vão passar</div>
  <table class="table table-sm tr-previa mb-1"><thead><tr><th style="width:34px"><input class="form-check-input" type="checkbox" id="trTodos" checked title="Todos os módulos"></th><th>Módulo</th><th class="text-end" style="width:90px">Itens</th><th>Detalhe</th></tr></thead><tbody>
  <?php foreach ($T::MODULOS as $cod => $rot): $p = $previa[$cod] ?? ['n' => 0, 'detalhe' => '']; ?>
    <tr><td><input class="form-check-input tr-mod" type="checkbox" name="modulos[]" value="<?= $e($cod) ?>" checked></td><td><?= $e($rot) ?></td><td class="text-end"><b><?= (int)$p['n'] ?></b></td><td class="small text-muted"><?= $e($p['detalhe']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <input type="hidden" name="modulos[]" value="TODOS" id="trModTodos">
  <div class="small text-muted mb-2">Com todos marcados vale também para o que não tem módulo definido (ex.: alertas gerais). Os números são de hoje; o que entrar depois para <?= $e($escopo[$origem]) ?> nesses módulos também vai para quem assume, enquanto a transferência valer.</div>
  <div class="tr-grade">
    <div><label class="form-label" for="trDestino"><b>3.</b> Quem assume</label>
      <select class="form-select form-select-sm" id="trDestino" name="destino" required><option value="">— escolha —</option>
      <?php foreach ($escopo as $id => $nome) if ($id !== $origem): ?><option value="<?= (int)$id ?>"><?= $e($nome) ?></option><?php endif; ?></select></div>
    <div><label class="form-label"><b>4.</b> Por quanto tempo</label>
      <div class="form-check"><input class="form-check-input" type="radio" name="tipo" id="trTemp" value="TEMPORARIA" checked><label class="form-check-label" for="trTemp">Temporária (férias, afastamento) — volta sozinha depois da data</label></div>
      <div class="form-check"><input class="form-check-input" type="radio" name="tipo" id="trDef" value="DEFINITIVA"><label class="form-check-label" for="trDef">Definitiva (desligamento, troca de função) — até ser encerrada</label></div></div>
    <div><label class="form-label" for="trInicio">Início</label><input class="form-control form-control-sm" type="date" id="trInicio" name="inicio" value="<?= $e($hoje) ?>" min="<?= $e($hoje) ?>" required></div>
    <div id="trFimBox"><label class="form-label" for="trFim">Último dia (volta no dia seguinte)</label><input class="form-control form-control-sm" type="date" id="trFim" name="fim" min="<?= $e($hoje) ?>"></div>
  </div>
  <label class="form-label mt-2" for="trMotivo"><b>5.</b> Motivo (obrigatório; vai para o log e para os avisos)</label>
  <textarea class="form-control form-control-sm" id="trMotivo" name="motivo" rows="2" minlength="5" maxlength="1000" required></textarea>
  <div class="d-flex align-items-center gap-2 mt-2"><button type="button" class="btn btn-sm btn-success" id="trConfirmar"><i class="bi bi-arrow-left-right me-1"></i>Transferir</button><small class="text-danger" id="trAviso" role="status"></small></div>
</form>
<?php endif;
$htmlNova = (string)ob_get_clean();
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Transferência de responsabilidades</title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><?php DashboardGrid::head(); ?>
<style>
html,body{height:auto}body{background:#fff;font-size:13px}.tr{padding:14px 16px}.tr-desc{color:var(--gp-muted);max-width:1050px;margin-bottom:10px}
.tr-linha{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px}.tr-linha .form-select{width:auto;min-width:300px}
.tr-passo{font-weight:600;margin:6px 0}.tr-previa{max-width:820px;font-size:12px}.tr-previa td,.tr-previa th{padding:4px 6px}
.tr-grade{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;max-width:1050px}.tr-vazio{color:var(--gp-muted);padding:18px 4px}
.tr .menu-grid-scroll{max-height:calc(100vh - 260px);min-height:160px;overflow:auto}textarea.form-control{max-width:1050px}
</style></head>
<body class="theme-<?= $e($theme) ?>" data-transacao="transferencia_responsabilidades"><main class="tr">
<h5 class="mb-1"><i class="bi bi-arrow-left-right me-2"></i>Transferência de responsabilidades</h5>
<div class="tr-desc small">Passe para outra pessoa, por um período (férias, afastamento) ou em definitivo (desligamento, troca de função), o que está com alguém nos módulos escolhidos. <b>Os registros não mudam</b>: enquanto a transferência vale, as pendências, a agenda, os prazos da barra direita, os alertas e os e-mails dessa pessoa nesses módulos <b>vão para quem assume</b> (com "de Fulano") e ela deixa de receber. Ao encerrar ou passar a data de volta, tudo volta sozinho. Motivo obrigatório; fica no log e as pessoas envolvidas (e o gestor de quem passa) recebem um alerta.</div>
<?php if ($msg): ?><div class="alert alert-success py-2 small"><?= $e($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-warning py-2 small"><?= $e($erro) ?></div><?php endif; ?>
<?= DashboardGrid::subabas('transferencias', [
    ['id' => 'nova', 'icone' => 'bi-plus-circle', 'rotulo' => 'Nova transferência', 'html' => $htmlNova],
    ['id' => 'lista', 'icone' => 'bi-list-ul', 'rotulo' => 'Transferências', 'html' => $gridLista, 'contagem' => $emVigor],
    ['id' => 'log', 'icone' => 'bi-clock-history', 'rotulo' => 'Log', 'html' => $gridLog, 'contagem' => count($log)],
], true) ?>
</main>
<div class="modal fade" id="trModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title" id="trModalTitulo">Confirmar</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body small" id="trModalTexto"></div>
  <div class="modal-body pt-0" id="trEncBox" hidden><form method="post" id="trEncForm"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="acao" value="encerrar"><input type="hidden" name="codtransferencia" id="trEncCod">
    <label class="form-label small" for="trEncMotivo">Motivo do encerramento (obrigatório)</label><textarea class="form-control form-control-sm" id="trEncMotivo" name="motivo" rows="2" minlength="5" maxlength="1000" required></textarea></form></div>
  <div class="modal-footer py-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-sm btn-success" id="trModalOk"><i class="bi bi-check2 me-1"></i>Confirmar</button></div>
</div></div></div>
<?php DashboardGrid::scripts(); ?>
<script>
(function () {
  var g = document.querySelector('[data-subabas="transferencias"]'), aba = <?= json_encode((string)($_GET['aba'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
  if (aba && g && window.greenPortalSubabas) window.greenPortalSubabas.abrir(g, aba);
  var modal = window.bootstrap ? new bootstrap.Modal(document.getElementById('trModal')) : null, acao = null;
  function aviso(t) { var a = document.getElementById('trAviso'); if (a) a.textContent = t || ''; }
  var todos = document.getElementById('trTodos'), modTodos = document.getElementById('trModTodos');
  function sincronizar() { var m = [].slice.call(document.querySelectorAll('.tr-mod')); var todosMarcados = m.every(function (c) { return c.checked; }); if (todos) todos.checked = todosMarcados; if (modTodos) modTodos.disabled = !todosMarcados; }
  if (todos) todos.addEventListener('change', function () { document.querySelectorAll('.tr-mod').forEach(function (c) { c.checked = todos.checked; }); sincronizar(); });
  document.querySelectorAll('.tr-mod').forEach(function (c) { c.addEventListener('change', sincronizar); });
  sincronizar();
  function tipo() { var t = document.querySelector('input[name="tipo"]:checked'); return t ? t.value : 'TEMPORARIA'; }
  document.querySelectorAll('input[name="tipo"]').forEach(function (r) { r.addEventListener('change', function () { document.getElementById('trFimBox').hidden = tipo() !== 'TEMPORARIA'; }); });
  var bt = document.getElementById('trConfirmar');
  if (bt) bt.addEventListener('click', function () {
    var f = document.getElementById('trForm'), dest = document.getElementById('trDestino'), mot = document.getElementById('trMotivo'), fim = document.getElementById('trFim'), ini = document.getElementById('trInicio');
    var mods = [].slice.call(document.querySelectorAll('.tr-mod:checked')).map(function (c) { return c.closest('tr').children[1].textContent; });
    if (!mods.length) return aviso('Marque ao menos um módulo.');
    if (!dest.value) return aviso('Escolha quem assume.');
    if (tipo() === 'TEMPORARIA' && !fim.value) return aviso('Informe o último dia da transferência temporária.');
    if (tipo() === 'TEMPORARIA' && fim.value < ini.value) return aviso('O último dia não pode ser antes do início.');
    if (mot.value.trim().length < 5) return aviso('Escreva o motivo (pelo menos 5 letras).');
    aviso('');
    var origem = document.getElementById('trOrigem'); var nO = origem.options[origem.selectedIndex].text, nD = dest.options[dest.selectedIndex].text;
    var periodo = tipo() === 'TEMPORARIA' ? 'de ' + ini.value.split('-').reverse().join('/') + ' até ' + fim.value.split('-').reverse().join('/') : 'a partir de ' + ini.value.split('-').reverse().join('/') + ', em definitivo (até ser encerrada)';
    document.getElementById('trModalTitulo').textContent = 'Transferir responsabilidades';
    document.getElementById('trModalTexto').textContent = 'O que é de ' + nO + ' em ' + (todos && todos.checked ? 'todos os módulos' : mods.join(', ')) + ' vai aparecer e ser cobrado de ' + nD + ', ' + periodo + '. Os registros não mudam. ' + nO + ' deixa de receber pendências e alertas desses módulos nesse período.';
    document.getElementById('trEncBox').hidden = true; acao = function () { f.submit(); };
    if (modal) modal.show();
  });
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-tr-encerrar]'); if (!b) return;
    document.getElementById('trModalTitulo').textContent = 'Encerrar transferência';
    document.getElementById('trModalTexto').textContent = 'Transferência ' + b.getAttribute('data-tr-rotulo') + '. As responsabilidades voltam na hora para quem passou.';
    document.getElementById('trEncCod').value = b.getAttribute('data-tr-encerrar'); document.getElementById('trEncMotivo').value = '';
    document.getElementById('trEncBox').hidden = false;
    acao = function () { var m = document.getElementById('trEncMotivo'); if (m.value.trim().length < 5) { m.focus(); m.classList.add('is-invalid'); return; } document.getElementById('trEncForm').submit(); };
    if (modal) modal.show();
  });
  document.getElementById('trModalOk').addEventListener('click', function () { if (acao) acao(); });
}());
</script></body></html>
