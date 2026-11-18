<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/AdministracaoTextos.php';
/*
 * Administração › Migração › Sincronismo (migração 305).
 * Agendamento diário (horário único para todas as empresas), "Rodar agora" (simular / validar; aplicar só a partir de uma
 * simulação concluída nas últimas 24 horas) e o histórico das execuções com o relatório de cada uma.
 * A tela só grava pedidos (procedures sp_sinc_*): quem executa é o serviço scripts/sincronismo-worker.php no Agendador do Windows.
 */
session_start();
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/AssistenteAplicacaoService.php';
require_once dirname(__DIR__) . '/components/pagina_padrao.php';
require_once dirname(__DIR__) . '/components/botoes_crud.php';

if (empty($_SESSION['autenticado'])) { http_response_code(401); exit(adm_t('Sessao expirada.')); }
if (strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) !== 'ADMINISTRADOR') { http_response_code(403); exit(adm_t('Acesso restrito ao perfil ADMINISTRADOR.')); }

$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$call = static fn(string $p, array $a = []): array => AssistenteAplicacaoService::chamarProcedure($pdo, $p, $a);
$user = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$userName = (string)($_SESSION['usuario']['nome'] ?? ($_SESSION['usuario']['login'] ?? ''));
if (empty($_SESSION['sincronismo_csrf'])) $_SESSION['sincronismo_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['sincronismo_csrf'];
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$message = (string)($_SESSION['sincronismo_msg'] ?? ''); $error = (string)($_SESSION['sincronismo_erro'] ?? '');
unset($_SESSION['sincronismo_msg'], $_SESSION['sincronismo_erro']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException(adm_t('Token de segurança inválido. Recarregue a tela.'));
        $acao = (string)($_POST['acao'] ?? '');
        if ($acao === 'agenda') {
            $call('sp_sinc_config_salvar', [isset($_POST['ativo']) ? 1 : 0, trim((string)($_POST['horario'] ?? '')), $user]);
            $_SESSION['sincronismo_msg'] = adm_t('Agendamento salvo.');
        } elseif ($acao === 'simular' || $acao === 'validar') {
            $r = $call('sp_sinc_execucao_solicitar', [$acao === 'simular' ? 'SIMULAR' : 'VALIDAR', trim((string)($_POST['empresa'] ?? '')), $user, $userName, 0]);
            $_SESSION['sincronismo_msg'] = ($acao === 'simular' ? adm_t('Simulação') : adm_t('Validação')) . ' #' . (int)($r[0]['codexecucao'] ?? 0) . adm_t(' colocada na fila. O serviço começa em até 1 minuto.');
        } elseif ($acao === 'aplicar') {
            $sim = (int)($_POST['codexecucao'] ?? 0);
            $r = $call('sp_sinc_execucao_solicitar', ['APLICAR', '', $user, $userName, $sim]);
            $_SESSION['sincronismo_msg'] = adm_t('Aplicação #') . (int)($r[0]['codexecucao'] ?? 0) . adm_t(' (da simulação #') . $sim . adm_t(') colocada na fila. O serviço começa em até 1 minuto.');
        } elseif ($acao === 'cancelar') {
            $call('sp_sinc_execucao_cancelar', [(int)($_POST['codexecucao'] ?? 0), $user]);
            $_SESSION['sincronismo_msg'] = 'Pedido #' . (int)$_POST['codexecucao'] . ' cancelado.';
        }
    } catch (Throwable $ex) { $_SESSION['sincronismo_erro'] = $ex->getMessage(); }
    header('Location: ' . strtok((string)$_SERVER['REQUEST_URI'], '?'));
    exit;
}

$config = null; $empresas = []; $execucoes = []; $falta = '';
try {
    $config = $call('sp_sinc_config_obter')[0] ?? null;
    $empresas = $call('sp_sinc_empresas_listar');
    $execucoes = $call('sp_sinc_execucoes_listar', [300]);
} catch (Throwable $ex) { $falta = adm_t('Tela indisponível: falta aplicar a migração 305 (') . $ex->getMessage() . ').'; }
$pendente = false;
foreach ($execucoes as $x) if (in_array($x['status'], ['NA_FILA', 'EM_EXECUCAO'], true)) $pendente = true;
$workerParado = $config && ($config['worker_segundos'] === null || (int)$config['worker_segundos'] > 300);
$dataBr = static fn($d): string => $d ? date('d/m/Y H:i', strtotime((string)$d)) : '';
$duracao = static function ($s): string { if ($s === null || $s === '') return ''; $s = (int)$s; return $s >= 3600 ? sprintf('%dh%02d', intdiv($s, 3600), intdiv($s % 3600, 60)) : sprintf('%dmin%02ds', intdiv($s, 60), $s % 60); };
$rotStatus = ['NA_FILA' => [adm_t('Na fila'), 'secondary'], 'EM_EXECUCAO' => [adm_t('Em execução'), 'primary'], 'CONCLUIDA' => [adm_t('Concluída'), 'success'],
    'CONCLUIDA_COM_ERRO' => [adm_t('Concluída com erro'), 'warning'], 'ERRO' => ['Erro', 'danger'], 'CANCELADA' => ['Cancelada', 'light']];
$rotModo = ['SIMULAR' => adm_t('Simulação'), 'APLICAR' => adm_t('Aplicação'), 'VALIDAR' => adm_t('Validação')];
$nomeEmpresa = [];
foreach ($empresas as $em) $nomeEmpresa[(string)$em['codigo']] = (string)$em['nome'];
$num = static fn($n): string => number_format((int)$n, 0, ',', '.');
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= adm_h('Sincronismo') ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css?v=5" rel="stylesheet"><link href="../assets/css/main.css?v=18" rel="stylesheet">
<?php greenPortalEstiloPagina(); ?>
<style>
body{padding:12px 14px}
.sc-grade{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px;margin-bottom:12px}
.sc-card{background:#fff;border:1px solid var(--gp-border,#dfe9e4);border-radius:10px;padding:12px 14px}
.sc-card h3{font-size:13px;margin:0 0 10px;color:var(--gp-primary,#24493c)}
.sc-linha{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.sc-linha label{margin:0}
.sc-ajuda{color:#6b8279;font-size:11px;margin:4px 0 0}
.sc-tabela{font-size:11px}
.sc-tabela td,.sc-tabela th{white-space:nowrap;vertical-align:middle}
.sc-tabela td.sc-msg{white-space:normal;min-width:220px;max-width:420px;color:#b42318}
.sc-tabela tbody tr{cursor:pointer}
.sc-tabela tbody tr.selected{outline:2px solid var(--gp-primary,#2f7d5b);outline-offset:-2px;background:#eef6f2}
.sc-scroll{max-height:calc(100vh - 330px);min-height:220px;overflow:auto;border:1px solid var(--gp-border,#dfe9e4);border-radius:8px}
.sc-scroll thead th{position:sticky;top:0;background:#f4f8f6;z-index:1}
.sc-num{text-align:right}
.sc-card .form-control{height:auto;min-height:calc(1.5em + .5rem + 2px);padding:.25rem .5rem;font-size:.8125rem;border-radius:var(--bs-border-radius-sm)}
</style>
</head>
<body>
<h2><i class="bi bi-arrow-repeat me-1"></i><?= adm_h('Sincronismo legado → greenportal') ?></h2>
<p class="text-muted mb-2"><?= adm_h('Mantém o greenportal igual ao legado até a virada: inclui o que é novo, altera o que mudou e exclui o que foi apagado no legado (homologação, não conformidades, Módulo Legal e legislação central). O legado não é alterado.') ?></p>
<?php if ($falta): ?><div class="alert alert-danger py-2"><?= $e($falta) ?></div></body></html><?php exit; endif; ?>
<?php if ($message): ?><div class="alert alert-success py-2" role="status"><?= $e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger py-2" role="alert"><?= $e($error) ?></div><?php endif; ?>
<?php if ($workerParado): ?><div class="alert alert-warning py-2" role="alert"><i class="bi bi-exclamation-triangle me-1"></i><?= adm_h('O serviço') ?> <strong><?= adm_h('GreenPortal-SincronismoWorker') ?></strong> <?= adm_h('não roda há mais de 5 minutos') ?><?= $config['worker_ultimo_ciclo_em'] ? adm_t(' (último ciclo em ') . $e($dataBr($config['worker_ultimo_ciclo_em'])) . ')' : '' ?><?= adm_h('. Os pedidos ficam na fila até ele voltar. Instalação:') ?> <code>scripts\criar-agendamento-sincronismo.ps1</code>.</div><?php endif; ?>

<div class="sc-grade">
  <section class="sc-card">
    <h3><i class="bi bi-clock me-1"></i><?= adm_h('Agendamento diário') ?></h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="acao" value="agenda">
      <div class="sc-linha">
        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="scAtivo" name="ativo" value="1" <?= !empty($config['ativo']) ? 'checked' : '' ?>><label class="form-check-label" for="scAtivo"><?= adm_h('Ligado') ?></label></div>
        <label for="scHorario"><?= adm_h('Horário') ?></label><input class="form-control form-control-sm text-center" style="width:80px" type="text" inputmode="numeric" maxlength="5" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" title="<?= adm_a('Hora no formato HH:MM (24 horas)') ?>" placeholder="05:00" id="scHorario" name="horario" required value="<?= $e($config['horario'] ?? '05:00') ?>">
        <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-check2 me-1"></i><?= adm_h('Salvar') ?></button>
      </div>
      <p class="sc-ajuda mb-0"><?= adm_h('Um horário para todas as empresas. Primeiro simula; se as exclusões ficarem até o limite (') ?><?= (int)($config['limite_exclusoes'] ?? 500) ?><?= adm_h('), grava em seguida. Acima do limite, para e avisa os administradores, para revisar e aplicar pela lista abaixo. Entram só as empresas com o sincronismo ligado.
        Próxima execução:') ?> <strong><?= !empty($config['ativo']) ? $e($dataBr($config['proxima_execucao'])) : 'desligado' ?></strong><?= adm_h('.
        Se o horário de hoje já passou, a próxima é amanhã.') ?></p>
    </form>
  </section>
  <section class="sc-card">
    <h3><i class="bi bi-play-circle me-1"></i><?= adm_h('Rodar agora') ?></h3>
    <form method="post" id="scRodar">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="acao" id="scAcao" value="simular">
      <div class="sc-linha">
        <label for="scEmpresa"><?= adm_h('Empresa') ?></label>
        <select class="form-select form-select-sm" style="width:auto;min-width:220px" id="scEmpresa" name="empresa">
          <option value=""><?= adm_h('Todas as empresas') ?></option>
          <?php foreach ($empresas as $em): ?><option value="<?= $e($em['codigo']) ?>"><?= $e($em['nome']) ?> (<?= $e($em['banco_dados']) ?>)</option><?php endforeach; ?>
        </select>
      </div>
      <div class="sc-linha">
        <button class="btn btn-sm btn-primary" type="submit" data-acao="simular" title="<?= adm_a('Mostra o que vai mudar, sem gravar nada') ?>"><i class="bi bi-eye me-1"></i><?= adm_h('Simular') ?></button>
        <button class="btn btn-sm btn-outline-secondary" type="submit" data-acao="validar" title="<?= adm_a('Confere se o legado está replicado no greenportal, sem sincronizar') ?>"><i class="bi bi-clipboard-check me-1"></i><?= adm_h('Validar') ?></button>
      </div>
      <p class="sc-ajuda mb-0"><?= adm_h('Para gravar: rode a') ?> <strong><?= adm_h('Simular') ?></strong><?= adm_h(', confira o resultado e, na lista abaixo, selecione a simulação e clique em') ?> <strong><?= adm_h('Aplicar') ?></strong> <?= adm_h('(vale por 24 horas, uma vez).
        A aplicação grava as diferenças do momento em que roda. A legislação central entra em toda execução.') ?></p>
    </form>
  </section>
</div>

<section class="sc-card">
  <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
    <h3 class="mb-0 me-2"><i class="bi bi-list-check me-1"></i><?= adm_h('Execuções') ?></h3>
    <div class="crud-toolbar menu-grid-toolbar flex-grow-1">
    <?php greenPortalCrudButtons([
        'save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'export' => false, 'columns' => false,
        'extras' => ('<button class="btn btn-sm btn-outline-success grid-crud-button" type="button" data-sc-aplicar disabled title="' . adm_a('Aplicar a simulação selecionada (grava no greenportal)') . '" aria-label="' . adm_a('Aplicar') . '"><i class="bi bi-check2-circle"></i></button>')
            . ('<button class="btn btn-sm btn-outline-danger grid-crud-button" type="button" data-sc-cancelar disabled title="' . adm_a('Cancelar pedido na fila') . '" aria-label="' . adm_a('Cancelar') . '"><i class="bi bi-x-circle"></i></button>')
            . ('<button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-sc-relatorio disabled title="' . adm_a('Ver relatório da execução') . '" aria-label="' . adm_a('Relatório') . '"><i class="bi bi-journal-text"></i></button>'),
    ]); ?>
    </div>
    <?php if ($pendente): ?><span class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span><?= adm_h('atualiza sozinho a cada 20 s') ?></span><?php endif; ?>
  </div>
  <div class="sc-scroll">
  <table class="table table-sm table-hover mb-0 sc-tabela" id="scGrid">
    <thead><tr><th>Nº</th><th><?= adm_h('Solicitado em') ?></th><th><?= adm_h('Origem') ?></th><th><?= adm_h('Modo') ?></th><th><?= adm_h('Empresa') ?></th><th><?= adm_h('Usuário') ?></th><th>Status</th><th><?= adm_h('Duração') ?></th>
      <th class="sc-num" title="fo_* e nc_*"><?= adm_h('Incluídos') ?></th><th class="sc-num"><?= adm_h('Alterados') ?></th><th class="sc-num"><?= adm_h('Excluídos') ?></th><th class="sc-num" title="<?= adm_a('Módulo Legal: incluídos ou alterados / excluídos') ?>"><?= adm_h('Módulo Legal') ?></th><th><?= adm_h('Validação') ?></th><th><?= adm_h('Mensagem') ?></th></tr></thead>
    <tbody>
    <?php if (!$execucoes): ?><tr><td colspan="14" class="text-muted text-center py-3"><?= adm_h('Nenhuma execução ainda.') ?></td></tr><?php endif; ?>
    <?php foreach ($execucoes as $x): $res = $x['resumo'] ? (json_decode((string)$x['resumo'], true) ?: []) : []; $t = $res['totais'] ?? null;
        [$stT, $stC] = $rotStatus[$x['status']] ?? [$x['status'], 'secondary'];
        $emp = $x['empresa'] ? ($x['empresa_nome'] ?: ($nomeEmpresa[$x['empresa']] ?? $x['empresa'])) : 'Todas';
        $val = (string)($res['validacao'] ?? ''); ?>
      <tr data-sc-row data-id="<?= $e($x['codexecucao']) ?>" data-status="<?= $e($x['status']) ?>" data-modo="<?= $e($x['modo']) ?>" data-pode-aplicar="<?= (int)$x['pode_aplicar'] ?>" data-relatorio="<?= $x['relatorio'] ? 1 : 0 ?>" data-empresa="<?= $e($emp) ?>">
        <td>#<?= $e($x['codexecucao']) ?></td>
        <td><?= $e($dataBr($x['solicitado_em'])) ?></td>
        <td><?= $x['origem'] === 'AGENDADO' ? ('<i class="bi bi-clock me-1"></i>' . adm_t('Agendado')) : ('<i class="bi bi-person me-1"></i>' . adm_t('Manual')) ?></td>
        <td><?= $e($rotModo[$x['modo']] ?? $x['modo']) ?><?php if ($x['codsimulacao']): ?> <span class="text-muted">(da #<?= $e($x['codsimulacao']) ?>)</span><?php endif; ?>
            <?php if ($x['modo'] === 'SIMULAR' && $x['aplicada_por']): ?> <span class="badge text-bg-light"><?= adm_h('aplicada na #') ?><?= $e($x['aplicada_por']) ?></span><?php elseif ((int)$x['pode_aplicar'] === 1): ?> <span class="badge text-bg-info">pode aplicar</span><?php endif; ?></td>
        <td><?= $e($emp) ?></td>
        <td><?= $e($x['usuario_nome'] ?? '') ?></td>
        <td><span class="badge text-bg-<?= $stC ?>"><?= $e($stT) ?></span></td>
        <td><?= $e($duracao($x['duracao_segundos'])) ?></td>
        <td class="sc-num"><?= $t ? $num($t['incluidos']) : '' ?></td>
        <td class="sc-num"><?= $t ? $num($t['alterados']) : '' ?></td>
        <td class="sc-num"><?= $t ? $num($t['excluidos']) : '' ?></td>
        <td class="sc-num"><?= $t ? $num($t['legal_afetados']) . ' / ' . $num($t['legal_excluidos']) : '' ?></td>
        <td><?php if ($val !== ''): ?><span class="badge text-bg-<?= str_starts_with($val, 'OK') ? 'success' : 'warning' ?>"><?= $e($val) ?></span><?php endif; ?></td>
        <td class="sc-msg"><?= $e(mb_strimwidth((string)($x['erro'] ?? ''), 0, 300, '…')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<form method="post" id="scAcaoLinha" hidden><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="acao" id="scAcaoLinhaAcao"><input type="hidden" name="codexecucao" id="scAcaoLinhaId"></form>

<script>
(function () {
    'use strict';
    var sel = null, q = function (s) { return document.querySelector(s); };
    var bAplicar = q('[data-sc-aplicar]'), bCancelar = q('[data-sc-cancelar]'), bRel = q('[data-sc-relatorio]'), bReload = q('[data-reload-grid]');
    function botoes() {
        bAplicar.disabled = !(sel && sel.dataset.podeAplicar === '1');
        bAplicar.title = sel && sel.dataset.modo === 'SIMULAR' && sel.dataset.podeAplicar !== '1' ? 'Esta simulação não pode mais ser aplicada (mais de 24 horas, já aplicada ou não concluída): rode outra simulação' : <?= adm_js('Aplicar a simulação selecionada (grava no greenportal)') ?>;
        bCancelar.disabled = !(sel && sel.dataset.status === 'NA_FILA');
        bRel.disabled = !(sel && sel.dataset.relatorio === '1');
    }
    document.querySelectorAll('[data-sc-row]').forEach(function (tr) {
        tr.addEventListener('click', function () { document.querySelectorAll('[data-sc-row].selected').forEach(function (r) { r.classList.remove('selected'); }); tr.classList.add('selected'); sel = tr; botoes(); });
        tr.addEventListener('dblclick', function () { if (tr.dataset.relatorio === '1') abrirRelatorio(tr); });
    });
    // Confirmação no popup do portal; fora do portal, a do navegador
    function confirmar(texto, fn) {
        var h = null; try { h = window.top && window.top.GreenPortalPopups && window.top.GreenPortalPopups.mensagem ? window.top : null; } catch (e) { h = null; }
        if (h && h.document.getElementById('greenPortalMensagemModal')) h.GreenPortalPopups.mensagem({ html: String(texto).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }).replace(/\n/g, '<br>'), icone: 'bi-question-circle', confirmar: fn });
        else if (window.confirm(texto)) fn();
    }
    function enviar(acao) { q('#scAcaoLinhaAcao').value = acao; q('#scAcaoLinhaId').value = sel.dataset.id; q('#scAcaoLinha').submit(); }
    function abrirRelatorio(tr) {
        var url = new URL('administracao_sincronismo_relatorio.php?id=' + encodeURIComponent(tr.dataset.id), window.location.href).toString(), h = null;
        try { h = window.top && window.top.GreenPortalPopups ? window.top : null; } catch (e) { h = null; }
        if (h) h.GreenPortalPopups.transacao90({ titulo: <?= adm_js('Relatório do sincronismo #') ?> + tr.dataset.id, url: url }); else window.open(url, '_blank');
    }
    bAplicar.addEventListener('click', function () {
        if (!sel || sel.dataset.podeAplicar !== '1') return;
        confirmar(<?= adm_js('Aplicar a simulação #') ?> + sel.dataset.id + ' (' + sel.dataset.empresa + <?= adm_js(')?
O sincronismo vai GRAVAR no greenportal as diferenças encontradas no momento em que rodar.') ?>, function () { enviar('aplicar'); });
    });
    bCancelar.addEventListener('click', function () { if (sel && sel.dataset.status === 'NA_FILA') confirmar(<?= adm_js('Cancelar o pedido #') ?> + sel.dataset.id + '?', function () { enviar('cancelar'); }); });
    bRel.addEventListener('click', function () { if (sel) abrirRelatorio(sel); });
    if (bReload) bReload.addEventListener('click', function () { window.location.href = window.location.pathname; });
    document.querySelectorAll('#scRodar [data-acao]').forEach(function (b) { b.addEventListener('click', function () { q('#scAcao').value = b.dataset.acao; }); });
    var rodarOk = false;
    q('#scRodar').addEventListener('submit', function (ev) {
        if (rodarOk) return;
        ev.preventDefault();
        var emp = q('#scEmpresa').selectedOptions[0].textContent, acao = q('#scAcao').value;
        confirmar((acao === 'simular' ? 'Simular' : 'Validar') + <?= adm_js(' o sincronismo de: ') ?> + emp + <?= adm_js('?
Nada é gravado no greenportal.') ?>, function () { rodarOk = true; q('#scRodar').submit(); });
    });
    <?php if ($pendente): ?>(function atualizar() { setTimeout(function () { if (!sel && document.visibilityState === 'visible') window.location.href = window.location.pathname; else atualizar(); }, 20000); }());<?php endif; ?>
    botoes();
}());
</script>
</body></html>
