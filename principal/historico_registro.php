<?php
declare(strict_types=1);

/*
 * Histórico de alterações de um registro (migração 359; GER-16 — MM-77, CON-93, TRE-108, INF-123, HOM-141).
 * Aberto pelo botão "Histórico" da grade (assets/js/historico-registro.js) com ?tabela=&id=&menu=&rotulos=.
 * Quem vê a tela do módulo (permissão de visualizar no menu de origem) vê o histórico; o fornecedor externo não vê.
 * Os registros vêm de historico_alteracoes (gatilhos trg_hist_*): inclusão e exclusão com todos os campos; alteração campo a campo.
 */
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$sair = static function (int $c, string $m): never { http_response_code($c); exit(htmlspecialchars($m, ENT_QUOTES, 'UTF-8')); };
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $sair(401, IdiomaService::t('home.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.']));
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/HomologacaoFornecedorAcesso.php';
require_once dirname(__DIR__) . '/classes/HistoricoAlteracoes.php';
$pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), (string)$_SESSION['tenant']['banco_dados'], 'GPT');
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $sair(401, 'Sessão expirada.');
$idioma = IdiomaService::idioma();
$t = static fn(string $c, array $p): string => IdiomaService::t('historico.' . $c, $p);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$tabela = strtolower(trim((string)($_GET['tabela'] ?? '')));
$id = trim((string)($_GET['id'] ?? ''));
$menu = strtoupper(trim((string)($_GET['menu'] ?? '')));
$negado = $t('negado', ['pt' => 'Perfil sem permissão para ver este histórico.', 'en' => 'Your profile cannot see this history.', 'es' => 'Su perfil no puede ver este historial.']);
if (!HistoricoAlteracoes::monitorada($tabela) || $id === '' || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $id)) $sair(404, $t('invalido', ['pt' => 'Registro inválido.', 'en' => 'Invalid record.', 'es' => 'Registro inválido.']));
if (!in_array($menu, HistoricoAlteracoes::TABELAS[$tabela], true)) $menu = HistoricoAlteracoes::TABELAS[$tabela][0];
$podeVer = false;
foreach (array_unique(array_merge([$menu], HistoricoAlteracoes::TABELAS[$tabela])) as $m) if (!empty(PermissaoTransacao::obter($m)['pode_visualizar'])) $podeVer = true;
if (!$podeVer || (str_starts_with($tabela, 'fo_') && HomologacaoFornecedorAcesso::externo())) $sair(403, $negado);
if (!HistoricoAlteracoes::disponivel($pdo)) $sair(503, $t('sem_migracao', ['pt' => 'O histórico ainda não foi ativado nesta empresa (migração 359).', 'en' => 'History is not active for this company yet (migration 359).', 'es' => 'El historial aún no fue activado en esta empresa (migración 359).']));

$rotulos = json_decode((string)($_GET['rotulos'] ?? '{}'), true);
$rotulos = is_array($rotulos) ? array_change_key_case(array_map('strval', $rotulos)) : [];
$rotulo = static fn(?string $c): string => $c === null ? '' : ($rotulos[strtolower($c)] ?? $c);

// leitura (exata) e nomes de usuários / unidades para os campos de código
$ler = static function (string $view, array $filtros, int $max = 5000) use ($pdo): array {
    $f = []; foreach ($filtros as $k => $v) $f[$k] = ['value' => (string)$v, 'exact' => true];
    $out = [];
    for ($off = 0; $off < $max; $off += 1000) {
        $s = $pdo->prepare('CALL sp_crud_select(?, ?, NULL, 1000, ?)');
        $s->execute([$view, json_encode($f ?: new stdClass()), $off]);
        $lote = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
        array_push($out, ...$lote);
        if (count($lote) < 1000) break;
    }
    return $out;
};
$linhas = array_values(array_filter($ler('vw_historico_alteracoes', ['tabela' => $tabela, 'registro_id' => $id]),
    static fn($r) => strtolower((string)$r['tabela']) === $tabela && (string)$r['registro_id'] === $id));
usort($linhas, static fn($a, $b) => [(string)$b['data_hora'], (int)$b['codhistorico']] <=> [(string)$a['data_hora'], (int)$a['codhistorico']]);
$usuarios = []; $unidades = [];
try { foreach ($ler('usuarios', [], 20000) as $u) $usuarios[(string)$u['codusuario']] = trim((string)($u['nome'] ?? '')) ?: (string)($u['userid'] ?? ''); } catch (Throwable) {}
try { foreach ($ler('unidades', [], 5000) as $u) $unidades[(string)($u['CODUNIDADE'] ?? $u['codunidade'] ?? '')] = (string)($u['DSCUNIDADE'] ?? $u['dscunidade'] ?? ''); } catch (Throwable) {}
$valor = static function (?string $campo, $v) use ($usuarios, $unidades): string {
    if ($v === null) return '';
    $v = (string)$v; $c = strtolower((string)$campo);
    if ($v !== '' && ctype_digit($v) && str_contains($c, 'usuario') && isset($usuarios[$v])) return $usuarios[$v] . ' (' . $v . ')';
    if ($v !== '' && ctype_digit($v) && str_contains($c, 'unidade') && isset($unidades[$v])) return $unidades[$v] . ' (' . $v . ')';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})( 00:00:00)?$/', $v, $m)) return $m[3] . '/' . $m[2] . '/' . $m[1];
    return $v;
};
// eventos: mesma data/hora + usuário + origem + operação
$eventos = [];
foreach ($linhas as $r) {
    $k = $r['data_hora'] . '|' . $r['codusuario'] . '|' . $r['origem'] . '|' . $r['operacao'];
    if (!isset($eventos[$k])) $eventos[$k] = ['data' => (string)$r['data_hora'], 'usuario' => (string)($r['usuario'] ?? ''), 'origem' => HistoricoAlteracoes::origem($r['origem'] ?? null, $t), 'op' => (string)$r['operacao'], 'itens' => []];
    if ($r['operacao'] === 'ALTERAR') $eventos[$k]['itens'][] = ['campo' => (string)$r['campo'], 'de' => $valor($r['campo'], $r['valor_anterior']), 'para' => $valor($r['campo'], $r['valor_novo'])];
    else {
        $j = json_decode((string)($r['operacao'] === 'INCLUIR' ? $r['valor_novo'] : $r['valor_anterior']), true);
        foreach (is_array($j) ? $j : [] as $c => $v) if ($v !== null && $v !== '') $eventos[$k]['itens'][] = ['campo' => (string)$c, 'de' => $r['operacao'] === 'EXCLUIR' ? $valor($c, is_scalar($v) ? (string)$v : json_encode($v)) : '', 'para' => $r['operacao'] === 'INCLUIR' ? $valor($c, is_scalar($v) ? (string)$v : json_encode($v)) : ''];
    }
}
$camposUsados = [];
foreach ($eventos as $ev) foreach ($ev['itens'] as $i) $camposUsados[$i['campo']] = $rotulo($i['campo']);
asort($camposUsados);
$ops = ['INCLUIR' => $t('op.incluir', ['pt' => 'Inclusão', 'en' => 'Created', 'es' => 'Inclusión']), 'ALTERAR' => $t('op.alterar', ['pt' => 'Alteração', 'en' => 'Changed', 'es' => 'Cambio']), 'EXCLUIR' => $t('op.excluir', ['pt' => 'Exclusão', 'en' => 'Deleted', 'es' => 'Exclusión'])];
$semUsuario = $t('sem_usuario', ['pt' => '(sem usuário do portal)', 'en' => '(no portal user)', 'es' => '(sin usuario del portal)']);
$theme = in_array((string)($_SESSION['layout_tema'] ?? ''), ['verde', 'azul', 'cinza'], true) ? (string)$_SESSION['layout_tema'] : 'verde';
session_write_close();
?><!doctype html><html lang="<?= $idioma === 'pt' ? 'pt-BR' : $e($idioma) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($t('titulo', ['pt' => 'Histórico de alterações', 'en' => 'Change history', 'es' => 'Historial de cambios'])) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css?v=3" rel="stylesheet"><link href="../assets/css/main.css?v=15" rel="stylesheet">
<style>
body{background:#fff;font-size:13px;color:var(--gp-text)}.hr{padding:14px 16px}
.hr-filtros{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}.hr-filtros .form-control,.hr-filtros .form-select{width:auto}
.hr-ev{border:1px solid var(--gp-border);border-radius:8px;margin-bottom:10px;overflow:hidden}
.hr-cab{display:flex;gap:10px;flex-wrap:wrap;align-items:center;background:var(--gp-surface,#f6f8f7);padding:7px 10px;font-size:12px}
.hr-cab b{font-weight:600}.hr-op{border-radius:10px;padding:1px 8px;font-size:11px;background:#e7ecea}.hr-op.INCLUIR{background:#dff2e7;color:#226b40}.hr-op.EXCLUIR{background:#fde4e1;color:#a5281b}.hr-op.ALTERAR{background:#e6eefc;color:#2a4f9a}
.hr-origem{color:var(--gp-muted)}
.hr-tab{width:100%;border-collapse:collapse}.hr-tab td,.hr-tab th{padding:5px 10px;border-top:1px solid var(--gp-border);vertical-align:top}.hr-tab th{font-weight:600;font-size:11px;color:var(--gp-muted)}
.hr-tab td:first-child{width:220px;font-weight:600}.hr-de{color:#a5281b;text-decoration:line-through;text-decoration-color:rgba(165,40,27,.35);white-space:pre-line}.hr-para{color:#226b40;white-space:pre-line}
.hr-vazio{color:var(--gp-muted);padding:18px 2px}
</style></head>
<body class="theme-<?= $e($theme) ?>" data-transacao="historico_registro"><main class="hr">
<button type="button" class="btn btn-sm btn-link p-0 float-end ms-2" data-open-ajuda title="<?= $e($t('ajuda', ['pt' => 'Ajuda desta tela (F1)', 'en' => 'Help for this screen (F1)', 'es' => 'Ayuda de esta pantalla (F1)'])) ?>"><i class="bi bi-question-circle me-1"></i><?= $e($t('ajuda_curta', ['pt' => 'Ajuda', 'en' => 'Help', 'es' => 'Ayuda'])) ?></button>
<div class="mb-2 text-muted small"><?= $e($t('aviso', ['pt' => 'Registro automático de inclusões, alterações e exclusões, de qualquer origem. Começa na ativação do histórico (migração 359).', 'en' => 'Automatic record of creations, changes and deletions from any source. It starts when history was activated (migration 359).', 'es' => 'Registro automático de inclusiones, cambios y exclusiones de cualquier origen. Comienza con la activación del historial (migración 359).'])) ?> · <?= $e($t('registro', ['pt' => 'Registro', 'en' => 'Record', 'es' => 'Registro'])) ?> <?= $e($id) ?></div>
<?php if (!$eventos): ?>
<div class="hr-vazio"><?= $e($t('vazio', ['pt' => 'Nenhuma alteração registrada para este registro desde a ativação do histórico.', 'en' => 'No changes recorded for this record since history was activated.', 'es' => 'Ningún cambio registrado para este registro desde la activación del historial.'])) ?></div>
<?php else: ?>
<div class="hr-filtros">
  <input type="date" class="form-control form-control-sm" id="hrDe" aria-label="<?= $e($t('filtro.de', ['pt' => 'De', 'en' => 'From', 'es' => 'Desde'])) ?>">
  <input type="date" class="form-control form-control-sm" id="hrAte" aria-label="<?= $e($t('filtro.ate', ['pt' => 'Até', 'en' => 'To', 'es' => 'Hasta'])) ?>">
  <select class="form-select form-select-sm" id="hrCampo"><option value=""><?= $e($t('filtro.campos', ['pt' => 'Todos os campos', 'en' => 'All fields', 'es' => 'Todos los campos'])) ?></option><?php foreach ($camposUsados as $c => $r): ?><option value="<?= $e($c) ?>"><?= $e($r) ?></option><?php endforeach; ?></select>
  <button type="button" class="btn btn-sm btn-outline-success" id="hrExportar"><i class="bi bi-filetype-csv me-1"></i><?= $e($t('exportar', ['pt' => 'Exportar', 'en' => 'Export', 'es' => 'Exportar'])) ?></button>
  <small class="text-muted" id="hrTotal"></small>
</div>
<?php foreach ($eventos as $ev): ?>
<section class="hr-ev" data-data="<?= $e(substr($ev['data'], 0, 10)) ?>">
  <div class="hr-cab"><span class="hr-op <?= $e($ev['op']) ?>"><?= $e($ops[$ev['op']] ?? $ev['op']) ?></span><b><?= $e(date('d/m/Y H:i:s', strtotime($ev['data']) ?: time())) ?></b><span><i class="bi bi-person me-1"></i><?= $e($ev['usuario'] !== '' ? $ev['usuario'] : $semUsuario) ?></span><span class="hr-origem"><?= $e($ev['origem']) ?></span></div>
  <table class="hr-tab"><thead><tr><th><?= $e($t('col.campo', ['pt' => 'Campo', 'en' => 'Field', 'es' => 'Campo'])) ?></th><th><?= $e($t('col.de', ['pt' => 'Antes', 'en' => 'Before', 'es' => 'Antes'])) ?></th><th><?= $e($t('col.para', ['pt' => 'Depois', 'en' => 'After', 'es' => 'Después'])) ?></th></tr></thead><tbody>
  <?php foreach ($ev['itens'] as $i): ?><tr data-campo="<?= $e($i['campo']) ?>"><td><?= $e($rotulo($i['campo'])) ?></td><td class="hr-de"><?= $e(mb_strimwidth($i['de'], 0, 2000, '…')) ?></td><td class="hr-para"><?= $e(mb_strimwidth($i['para'], 0, 2000, '…')) ?></td></tr><?php endforeach; ?>
  </tbody></table>
</section>
<?php endforeach; ?>
<?php endif; ?>
</main>
<script>
(function () {
  var de = document.getElementById('hrDe'); if (!de) return;
  var ate = document.getElementById('hrAte'), campo = document.getElementById('hrCampo'), total = document.getElementById('hrTotal'),
      evs = [].slice.call(document.querySelectorAll('.hr-ev')), rot = <?= json_encode($t('total', ['pt' => '{n} evento(s)', 'en' => '{n} event(s)', 'es' => '{n} evento(s)']), JSON_UNESCAPED_UNICODE) ?>;
  function aplicar() {
    var n = 0;
    evs.forEach(function (ev) {
      var d = ev.getAttribute('data-data'), ok = (!de.value || d >= de.value) && (!ate.value || d <= ate.value), visiveis = 0;
      ev.querySelectorAll('tbody tr').forEach(function (tr) { var m = !campo.value || tr.getAttribute('data-campo') === campo.value; tr.hidden = !m; if (m) visiveis++; });
      ev.hidden = !ok || !visiveis; if (!ev.hidden) n++;
    });
    total.textContent = rot.replace('{n}', n);
  }
  [de, ate, campo].forEach(function (el) { el.addEventListener('change', aplicar); });
  document.getElementById('hrExportar').addEventListener('click', function () {
    var q = function (s) { return '"' + String(s).replace(/"/g, '""') + '"'; }, linhas = [['Data', 'Usuário', 'Origem', 'Operação', 'Campo', 'Antes', 'Depois'].map(q).join(';')];
    evs.forEach(function (ev) {
      if (ev.hidden) return;
      var cab = ev.querySelector('.hr-cab').children;
      ev.querySelectorAll('tbody tr').forEach(function (tr) { if (tr.hidden) return; linhas.push([cab[1].textContent, cab[2].textContent, cab[3].textContent, cab[0].textContent, tr.children[0].textContent, tr.children[1].textContent, tr.children[2].textContent].map(q).join(';')); });
    });
    var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + linhas.join('\r\n')], { type: 'text/csv;charset=utf-8' }));
    a.download = 'historico_<?= $e($tabela) ?>_<?= $e($id) ?>.csv'; document.body.appendChild(a); a.click(); a.remove();
  });
  aplicar();
}());
</script></body></html>
