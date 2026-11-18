<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/AdministracaoTextos.php';
/*
 * Administração › Backup (migração 351; decisão do usuário de 25/10/2026).
 * Configuração do backup diário (horário, quantos manter, o que entra), "Fazer backup agora" e o histórico.
 * A tela só grava pedidos e configuração (procedures sp_backup_*): quem faz o backup é scripts/backup-worker.php no Agendador do Windows.
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
if (empty($_SESSION['backup_csrf'])) $_SESSION['backup_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['backup_csrf'];
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$message = (string)($_SESSION['backup_msg'] ?? ''); $error = (string)($_SESSION['backup_erro'] ?? '');
unset($_SESSION['backup_msg'], $_SESSION['backup_erro']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException(adm_t('Token de segurança inválido. Recarregue a tela.'));
        $acao = (string)($_POST['acao'] ?? '');
        if ($acao === 'config') {
            $call('sp_backup_config_salvar', [isset($_POST['ativo']) ? 1 : 0, trim((string)($_POST['horario'] ?? '')), (int)($_POST['diarios'] ?? 0), (int)($_POST['semanais'] ?? -1),
                isset($_POST['aplicacao']) ? 1 : 0, isset($_POST['ged']) ? 1 : 0, isset($_POST['retencao']) ? 1 : 0, $user]);
            $_SESSION['backup_msg'] = adm_t('Configuração salva.');
        } elseif ($acao === 'agora') {
            $r = $call('sp_backup_solicitar', [$user, $userName]);
            $_SESSION['backup_msg'] = 'Backup #' . (int)($r[0]['codbackup'] ?? 0) . adm_t(' colocado na fila. O serviço começa em até 15 minutos.');
        } elseif ($acao === 'cancelar') {
            $r = $call('sp_backup_cancelar', [(int)($_POST['codbackup'] ?? 0), $user]);
            $_SESSION['backup_msg'] = 'Backup #' . (int)$_POST['codbackup'] . (($r[0]['status'] ?? '') === 'CANCELANDO'
                ? adm_t(': cancelamento pedido. O serviço para em até 1 minuto e apaga a pasta do dia.') : ' cancelado.');
        }
    } catch (Throwable $ex) { $_SESSION['backup_erro'] = $ex->getMessage(); }
    header('Location: ' . strtok((string)$_SERVER['REQUEST_URI'], '?'));
    exit;
}

$config = null; $execucoes = []; $falta = '';
try {
    $config = $call('sp_backup_config_obter')[0] ?? null;
    $execucoes = $call('sp_backup_execucoes_listar', [200]);
} catch (Throwable $ex) { $falta = adm_t('Tela indisponível: falta aplicar a migração 351 (') . $ex->getMessage() . ').'; }
$pendente = false;
foreach ($execucoes as $x) if (in_array($x['status'], ['NA_FILA', 'EM_EXECUCAO', 'CANCELANDO'], true)) $pendente = true;
$workerParado = $config && ($config['worker_segundos'] === null || (int)$config['worker_segundos'] > 1800);
$atrasado = $config && (int)$config['ativo'] === 1 && ($config['horas_desde_ultimo_ok'] === null || (int)$config['horas_desde_ultimo_ok'] > 26);
// Destino (mesma regra do serviço): protegido = BACKUP_DIR em outro disco ou na rede
$dirCfg = rtrim(trim((string)($env['BACKUP_DIR'] ?? '')), '\\/');
$unidade = static fn(string $p): string => preg_match('/^([A-Za-z]):/', $p, $m) ? strtoupper($m[1]) : '';
$avisoDestino = $dirCfg === '' ? adm_t('BACKUP_DIR não está configurado no .env: os backups ficam dentro da aplicação, no mesmo disco. Se o disco falhar, o sistema e os backups se perdem juntos.')
    : (!str_starts_with($dirCfg, '\\\\') && $unidade($dirCfg) !== '' && $unidade($dirCfg) === $unidade(dirname(__DIR__)) ? adm_t('BACKUP_DIR está no mesmo disco da aplicação (') . $unidade($dirCfg) . adm_t(':). Use outro disco ou um compartilhamento de rede.') : '');
$dataBr = static fn($d): string => $d ? date('d/m/Y H:i', strtotime((string)$d)) : '';
$duracao = static function ($s): string { if ($s === null || $s === '') return ''; $s = (int)$s; return $s >= 3600 ? sprintf('%dh%02d', intdiv($s, 3600), intdiv($s % 3600, 60)) : sprintf('%dmin%02ds', intdiv($s, 60), $s % 60); };
$tamanho = static function ($b): string { if ($b === null || $b === '') return ''; $b = (float)$b; foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $u) { if ($b < 1024 || $u === 'TB') return number_format($b, $u === 'B' ? 0 : 1, ',', '.') . ' ' . $u; $b /= 1024; } return ''; };
$rotStatus = ['NA_FILA' => [adm_t('Na fila'), 'secondary'], 'EM_EXECUCAO' => [adm_t('Em execução'), 'primary'], 'CANCELANDO' => ['Cancelando', 'warning'], 'CONCLUIDO' => [adm_t('Concluído'), 'success'],
    'CONCLUIDO_COM_AVISO' => [adm_t('Concluído com aviso'), 'warning'], 'ERRO' => ['Erro', 'danger'], 'CANCELADO' => ['Cancelado', 'light']];
$num = static fn($n): string => number_format((int)$n, 0, ',', '.');
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Backup</title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css?v=5" rel="stylesheet"><link href="../assets/css/main.css?v=18" rel="stylesheet">
<?php greenPortalEstiloPagina(); ?>
<style>
body{padding:12px 14px}
.bk-grade{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px;margin-bottom:12px}
.bk-card{background:#fff;border:1px solid var(--gp-border,#dfe9e4);border-radius:10px;padding:12px 14px}
.bk-card h3{font-size:13px;margin:0 0 10px;color:var(--gp-primary,#24493c)}
.bk-linha{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.bk-linha label{margin:0}
.bk-ajuda{color:#6b8279;font-size:11px;margin:4px 0 0}
.bk-tabela{font-size:11px}
.bk-tabela td,.bk-tabela th{white-space:nowrap;vertical-align:middle}
.bk-tabela td.bk-msg{white-space:normal;min-width:220px;max-width:460px}
.bk-tabela tbody tr{cursor:pointer}
.bk-tabela tbody tr.selected{outline:2px solid var(--gp-primary,#2f7d5b);outline-offset:-2px;background:#eef6f2}
.bk-scroll{max-height:calc(100vh - 360px);min-height:220px;overflow:auto;border:1px solid var(--gp-border,#dfe9e4);border-radius:8px}
.bk-scroll thead th{position:sticky;top:0;background:#f4f8f6;z-index:1}
.bk-num{text-align:right}
.bk-estado{font-size:22px;font-weight:600}
.bk-card .form-control{height:auto;min-height:calc(1.5em + .5rem + 2px);padding:.25rem .5rem;font-size:.8125rem;border-radius:var(--bs-border-radius-sm)}
</style>
</head>
<body>
<h2><i class="bi bi-hdd-stack me-1"></i>Backup</h2>
<p class="text-muted mb-2"><?= adm_h('Cópia diária dos bancos, da aplicação e do GED, com rotação dos backups antigos e a retenção de exportações e alertas. O sistema anterior só é lido.') ?></p>
<?php if ($falta): ?><div class="alert alert-danger py-2"><?= $e($falta) ?></div></body></html><?php exit; endif; ?>
<?php if ($message): ?><div class="alert alert-success py-2" role="status"><?= $e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger py-2" role="alert"><?= $e($error) ?></div><?php endif; ?>
<?php if ($workerParado): ?><div class="alert alert-warning py-2" role="alert"><i class="bi bi-exclamation-triangle me-1"></i><?= adm_h('O serviço') ?> <strong><?= adm_h('GreenPortal-Backup') ?></strong> <?= adm_h('não roda há mais de 30 minutos') ?><?= $config['worker_ultimo_ciclo_em'] ? adm_t(' (último ciclo em ') . $e($dataBr($config['worker_ultimo_ciclo_em'])) . ')' : '' ?><?= adm_h('. Sem ele não há backup. Instalação:') ?> <code>scripts\criar-agendamento-backup.ps1</code>.</div><?php endif; ?>
<?php if ($avisoDestino !== ''): ?><div class="alert alert-warning py-2" role="alert"><i class="bi bi-shield-exclamation me-1"></i><?= $e($avisoDestino) ?></div><?php endif; ?>

<div class="bk-grade">
  <section class="bk-card">
    <h3><i class="bi bi-activity me-1"></i><?= adm_h('Situação') ?></h3>
    <div class="bk-estado <?= $atrasado ? 'text-danger' : 'text-success' ?>"><i class="bi <?= $atrasado ? 'bi-x-octagon' : 'bi-check-circle' ?> me-1"></i><?= $config['ultimo_ok_em'] ? adm_t('Último backup: ') . $e($dataBr($config['ultimo_ok_em'])) : adm_t('Nenhum backup concluído') ?></div>
    <p class="bk-ajuda mb-0"><?php if ($atrasado): ?><?= adm_h('Mais de 26 horas sem backup concluído: confira o histórico abaixo e o serviço.') ?><?php else: ?><?= adm_h('Há') ?> <?= (int)$config['horas_desde_ultimo_ok'] ?> hora(s).<?php endif; ?>
      Próximo: <strong><?= (int)$config['ativo'] === 1 ? $e($dataBr($config['proxima_execucao'])) : 'agendamento desligado' ?></strong><?= adm_h('. Destino:') ?> <code><?= $e($dirCfg !== '' ? $dirCfg : adm_t('backups\automatico (na aplicação)')) ?></code>.</p>
    <form method="post" class="mt-2" data-bk-agora><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="acao" value="agora">
      <button class="btn btn-sm btn-primary" type="submit" <?= $pendente ? (adm_t('disabled title="Já existe um backup na fila ou em execução"')) : '' ?>><i class="bi bi-play-circle me-1"></i><?= adm_h('Fazer backup agora') ?></button></form>
  </section>
  <section class="bk-card">
    <h3><i class="bi bi-gear me-1"></i><?= adm_h('Configuração') ?></h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="acao" value="config">
      <div class="bk-linha">
        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="bkAtivo" name="ativo" value="1" <?= (int)$config['ativo'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="bkAtivo"><?= adm_h('Backup diário ligado') ?></label></div>
        <label for="bkHorario"><?= adm_h('Horário') ?></label><input class="form-control form-control-sm text-center" style="width:80px" type="text" inputmode="numeric" maxlength="5" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" title="<?= adm_a('Hora no formato HH:MM (24 horas)') ?>" id="bkHorario" name="horario" required value="<?= $e($config['horario']) ?>">
      </div>
      <div class="bk-linha">
        <label for="bkDiarios"><?= adm_h('Manter os') ?></label><input class="form-control form-control-sm text-center" style="width:64px" type="number" min="1" max="90" id="bkDiarios" name="diarios" required value="<?= (int)$config['manter_diarios'] ?>"><span><?= adm_h('diários e o último de cada uma das') ?></span>
        <input class="form-control form-control-sm text-center" style="width:64px" type="number" min="0" max="52" id="bkSemanais" name="semanais" required value="<?= (int)$config['manter_semanais'] ?>" aria-label="<?= adm_a('Semanas') ?>"><span>semanas</span>
      </div>
      <div class="bk-linha">
        <div class="form-check"><input class="form-check-input" type="checkbox" id="bkApp" name="aplicacao" value="1" <?= (int)$config['incluir_aplicacao'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="bkApp"><?= adm_h('Aplicação (com o .env)') ?></label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="bkGed" name="ged" value="1" <?= (int)$config['incluir_ged'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="bkGed"><?= adm_h('Espelho do GED') ?></label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="bkRet" name="retencao" value="1" <?= (int)$config['retencao'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="bkRet"><?= adm_h('Retenção de exportações e alertas') ?></label></div>
        <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-check2 me-1"></i><?= adm_h('Salvar') ?></button>
      </div>
      <p class="bk-ajuda mb-0"><?= adm_h('Os bancos *_dbp entram sempre. Retenção: exportações concluídas ou com erro há mais de 30 dias e alertas lidos há mais de 90 dias, em todas as empresas.') ?></p>
    </form>
  </section>
</div>

<section class="bk-card">
  <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
    <h3 class="mb-0 me-2"><i class="bi bi-list-check me-1"></i><?= adm_h('Histórico') ?></h3>
    <div class="crud-toolbar menu-grid-toolbar flex-grow-1">
    <?php greenPortalCrudButtons([
        'save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'export' => false, 'columns' => false,
        'extras' => ('<button class="btn btn-sm btn-outline-danger grid-crud-button" type="button" data-bk-cancelar disabled title="' . adm_a('Cancelar backup (na fila ou em execução)') . '" aria-label="' . adm_a('Cancelar') . '"><i class="bi bi-x-circle"></i></button>'),
    ]); ?>
    </div>
    <?php if ($pendente): ?><span class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span><?= adm_h('atualiza sozinho a cada 30 s') ?></span><?php endif; ?>
  </div>
  <div class="bk-scroll">
  <table class="table table-sm table-hover mb-0 bk-tabela" id="bkGrid">
    <thead><tr><th>Nº</th><th><?= adm_h('Solicitado em') ?></th><th><?= adm_h('Origem') ?></th><th>Status</th><th><?= adm_h('Duração') ?></th><th class="bk-num"><?= adm_h('Tamanho') ?></th><th class="bk-num" title="<?= adm_a('Bancos copiados / bancos') ?>"><?= adm_h('Bancos') ?></th>
      <th class="bk-num" title="<?= adm_a('Arquivos novos ou alterados copiados para o espelho do GED') ?>"><?= adm_h('GED copiados') ?></th><th class="bk-num" title="<?= adm_a('Exportações / alertas removidos pela retenção') ?>"><?= adm_h('Retenção') ?></th><th><?= adm_h('Pasta') ?></th><th><?= adm_h('Mensagem') ?></th></tr></thead>
    <tbody>
    <?php if (!$execucoes): ?><tr><td colspan="11" class="text-muted text-center py-3"><?= adm_h('Nenhum backup ainda.') ?></td></tr><?php endif; ?>
    <?php foreach ($execucoes as $x): $res = $x['resumo'] ? (json_decode((string)$x['resumo'], true) ?: []) : [];
        [$stT, $stC] = $rotStatus[$x['status']] ?? [$x['status'], 'secondary'];
        $bancos = (array)($res['bancos'] ?? []); $okB = count(array_filter($bancos, static fn($b) => empty($b['erro'])));
        $ged = 0; foreach ((array)($res['ged'] ?? []) as $g) $ged += (int)($g['copiados'] ?? 0);
        $retE = 0; $retA = 0; foreach ((array)($res['retencao'] ?? []) as $r) { $retE += (int)($r['exportacoes'] ?? 0); $retA += (int)($r['alertas'] ?? 0); } ?>
      <tr data-bk-row data-id="<?= $e($x['codbackup']) ?>" data-status="<?= $e($x['status']) ?>">
        <td>#<?= $e($x['codbackup']) ?></td>
        <td><?= $e($dataBr($x['solicitado_em'])) ?></td>
        <td><?= $x['origem'] === 'AGENDADO' ? ('<i class="bi bi-clock me-1"></i>' . adm_t('Agendado')) : '<i class="bi bi-person me-1"></i>' . $e($x['usuario_nome'] ?: 'Manual') ?></td>
        <td><span class="badge text-bg-<?= $stC ?>"><?= $e($stT) ?></span><?php if ($x['removido_em']): ?> <span class="badge text-bg-light" title="Apagado pela rotação em <?= $e($dataBr($x['removido_em'])) ?>">apagado</span><?php endif; ?>
            <?php if ($x['destino_protegido'] !== null && (int)$x['destino_protegido'] === 0 && in_array($x['status'], ['CONCLUIDO', 'CONCLUIDO_COM_AVISO'], true)): ?> <i class="bi bi-shield-exclamation text-warning" title="<?= adm_a('Destino no mesmo disco da aplicação') ?>"></i><?php endif; ?></td>
        <td><?= $e($duracao($x['duracao_segundos'])) ?></td>
        <td class="bk-num"><?= $e($tamanho($x['tamanho_bytes'])) ?></td>
        <td class="bk-num"><?= $bancos ? $okB . ' / ' . count($bancos) : '' ?></td>
        <td class="bk-num"><?= isset($res['ged']) && $res['ged'] ? $num($ged) : '' ?></td>
        <td class="bk-num"><?= isset($res['retencao']) && $res['retencao'] ? $num($retE) . ' / ' . $num($retA) : '' ?></td>
        <td><?= $e($x['pasta'] ? basename((string)$x['pasta']) : '') ?></td>
        <td class="bk-msg <?= $x['status'] === 'ERRO' ? 'text-danger' : 'text-muted' ?>"><?= $e(mb_strimwidth((string)($x['erro'] ?? ''), 0, 400, '…')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<form method="post" id="bkAcaoLinha" hidden><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="acao" value="cancelar"><input type="hidden" name="codbackup" id="bkAcaoLinhaId"></form>

<script>
(function () {
    'use strict';
    var sel = null, q = function (s) { return document.querySelector(s); };
    var bCancelar = q('[data-bk-cancelar]'), bReload = q('[data-reload-grid]');
    // Confirmação no popup do portal; fora do portal, a do navegador
    function confirmar(texto, fn) {
        var h = null; try { h = window.top && window.top.GreenPortalPopups && window.top.GreenPortalPopups.mensagem ? window.top : null; } catch (e) { h = null; }
        if (h && h.document.getElementById('greenPortalMensagemModal')) h.GreenPortalPopups.mensagem({ mensagem: texto, icone: 'bi-question-circle', confirmar: fn });
        else if (window.confirm(texto)) fn();
    }
    document.querySelectorAll('[data-bk-row]').forEach(function (tr) {
        tr.addEventListener('click', function () { document.querySelectorAll('[data-bk-row].selected').forEach(function (r) { r.classList.remove('selected'); }); tr.classList.add('selected'); sel = tr; bCancelar.disabled = ['NA_FILA', 'EM_EXECUCAO'].indexOf(sel.dataset.status) < 0; });
    });
    bCancelar.addEventListener('click', function () { if (sel && ['NA_FILA', 'EM_EXECUCAO'].indexOf(sel.dataset.status) >= 0) confirmar(sel.dataset.status === 'EM_EXECUCAO' ? <?= adm_js('Interromper o backup #') ?> + sel.dataset.id + ' em andamento? A pasta do dia é apagada; o que já foi copiado do GED fica no espelho.' : <?= adm_js('Cancelar o backup #') ?> + sel.dataset.id + '?', function () { q('#bkAcaoLinhaId').value = sel.dataset.id; q('#bkAcaoLinha').submit(); }); });
    if (bReload) bReload.addEventListener('click', function () { window.location.href = window.location.pathname; });
    var fAgora = q('[data-bk-agora]'), ok = false;
    fAgora.addEventListener('submit', function (ev) { if (ok) return; ev.preventDefault(); confirmar(<?= adm_js('Fazer um backup completo agora? Pode levar bastante tempo e deixar o servidor mais lento enquanto roda.') ?>, function () { ok = true; fAgora.submit(); }); });
    <?php if ($pendente): ?>(function atualizar() { setTimeout(function () { if (!sel && document.visibilityState === 'visible') window.location.href = window.location.pathname; else atualizar(); }, 30000); }());<?php endif; ?>
}());
</script>
</body></html>
