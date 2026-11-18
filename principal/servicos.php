<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/AdministracaoTextos.php';
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

/*
 * Administração › Serviços (migração 353; decisão do usuário de 11/11/2026).
 * Mostra o orquestrador: as 3 trilhas (modo, sinal de vida, tarefa do Windows), o vigia, os problemas atuais, cada serviço
 * (frequência, última execução, próxima prevista, falhas, suspensão, fila) e o histórico de execuções (30 dias).
 * O ADMINISTRADOR ajusta o cadastro do serviço (sp_orq_servico_alterar) e tira uma suspensão (sp_orq_servico_liberar);
 * o ADM DA EMPRESA só consulta. A tela não liga nem desliga tarefas do Windows nem muda o modo das trilhas (isso é pelo
 * PowerShell, em scripts\criar-agendamento-orquestrador.ps1 e scripts\orquestrador.php --modo=).
 * Antes da 353 a tela listava as ~20 tarefas soltas (servicos_task_status); elas foram desativadas pelo orquestrador.
 */

if (empty($_SESSION['autenticado'])) { http_response_code(401); exit(adm_t('Sessão expirada.')); }

$cfg = GreenPortalDatabase::env();
$tenantDb = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($_SESSION['tenant']['banco_dados'] ?? ''));
$pdo = GreenPortalDatabase::connect($cfg, $tenantDb, 'GPT');
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { http_response_code(401); exit(adm_t('Sessão expirada.')); }

$profile = strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? ''));
if (!in_array($profile, ['ADMINISTRADOR', 'ADM_DA_EMPRESA'], true)) { http_response_code(403); exit(adm_t('Perfil sem permissão para acessar Serviços.')); }
$isPlatformAdmin = $profile === 'ADMINISTRADOR';
$central = GreenPortalDatabase::connect($cfg, preg_replace('/[^a-zA-Z0-9_]/', '', (string)($cfg['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp')), 'GUNIVERSAL');
date_default_timezone_set('America/Sao_Paulo');

if (empty($_SESSION['servicos_csrf'])) $_SESSION['servicos_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['servicos_csrf'];
$html = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$call = static function (string $sql, array $p = []) use ($central): array {
    $st = $central->prepare($sql); $st->execute($p);
    $r = [];
    do { try { $x = $st->fetchAll(PDO::FETCH_ASSOC); if ($x && !$r) $r = $x; } catch (Throwable) {} } while ($st->nextRowset());
    $st->closeCursor();
    return $r;
};
$responder = static function (array $dados, int $codigo = 200): never {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
$script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/principal/servicos.php'));
$selfUrl = $script !== '' ? $script : '/principal/servicos.php';

// ------------------------------------------------------------------ histórico de execuções (JSON)
if (($_GET['acao'] ?? '') === 'historico') {
    $codigo = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string)($_GET['servico'] ?? '')));
    $limite = max(1, min(500, (int)($_GET['limite'] ?? 100)));
    try { $responder(['ok' => true, 'linhas' => $call('CALL sp_orq_execucoes_listar(?, ?)', [$codigo, $limite])]); }
    catch (Throwable $e) { $responder(['ok' => false, 'mensagem' => adm_t('Não foi possível ler o histórico: ') . $e->getMessage()], 500); }
}

// ------------------------------------------------------------------ alterar cadastro / liberar suspensão (só ADMINISTRADOR)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isPlatformAdmin) $responder(['ok' => false, 'mensagem' => adm_t('Só o ADMINISTRADOR altera os serviços.')], 403);
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $responder(['ok' => false, 'mensagem' => adm_t('Sessão da tela expirou. Atualize a página e tente de novo.')], 400);
    $acao = (string)($_POST['acao'] ?? '');
    $codigo = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string)($_POST['servico'] ?? '')));
    $usuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);
    if ($codigo === '' || !in_array($acao, ['salvar', 'liberar'], true)) $responder(['ok' => false, 'mensagem' => adm_t('Pedido inválido.')], 400);
    $detalhe = [];
    try {
        if ($acao === 'salvar') {
            $dias = implode(',', array_values(array_unique(array_filter(array_map('intval', (array)($_POST['dias'] ?? [])), static fn($d) => $d >= 1 && $d <= 7))));
            $detalhe = [
                'ativo' => !empty($_POST['ativo']) ? 1 : 0, 'trilha' => (int)($_POST['trilha'] ?? 0), 'ordem' => (int)($_POST['ordem'] ?? 0),
                'intervalo_min' => (int)($_POST['intervalo'] ?? 0), 'horario' => trim((string)($_POST['horario'] ?? '')),
                'dias' => $dias, 'limite_seg' => (int)($_POST['limite'] ?? 0),
            ];
            $linha = $call('CALL sp_orq_servico_alterar(?, ?, ?, ?, ?, ?, ?, ?, ?)', [$codigo, $detalhe['ativo'], $detalhe['trilha'], $detalhe['ordem'],
                $detalhe['intervalo_min'], $detalhe['horario'], $dias, $detalhe['limite_seg'], $usuario])[0] ?? null;
            $mensagem = adm_t('Cadastro do serviço salvo. A suspensão e as falhas seguidas foram zeradas.');
        } else {
            $linha = $call('CALL sp_orq_servico_liberar(?, ?)', [$codigo, $usuario])[0] ?? null;
            $mensagem = adm_t('Suspensão retirada: o serviço volta a rodar no próximo ciclo da trilha.');
        }
        $ok = true;
    } catch (PDOException $e) {
        $ok = false; $linha = null;
        $mensagem = (string)($e->errorInfo[2] ?? $e->getMessage());
    }
    try {
        $s = $central->prepare('SELECT codservico FROM servicos WHERE codigo = ? LIMIT 1'); $s->execute([$codigo]); $codservico = $s->fetchColumn() ?: null; $s->closeCursor();
        $central->prepare('INSERT INTO servicos_auditoria (codservico, codusuario, acao, resultado, mensagem) VALUES (?, ?, ?, ?, ?)')
            ->execute([$codservico, $usuario, $acao === 'salvar' ? 'ALTERAR' : 'LIBERAR', $ok ? 'SUCESSO' : 'ERRO',
                mb_substr("$codigo: $mensagem" . ($detalhe ? ' ' . json_encode($detalhe, JSON_UNESCAPED_UNICODE) : ''), 0, 1000)]);
    } catch (Throwable) {}
    $responder(['ok' => $ok, 'mensagem' => $mensagem, 'servico' => $linha], $ok ? 200 : 400);
}

// ------------------------------------------------------------------ dados da tela
$erroOrq = '';
$trilhas = []; $vigia = null; $problemas = []; $servicos = [];
try {
    $trilhas = $call('CALL sp_orq_trilhas_situacao()');
    $vigia = $call('CALL sp_orq_vigia_situacao()')[0] ?? null;
    $problemas = $call('CALL sp_orq_saude()');
    $servicos = $call('CALL sp_orq_situacao()');
} catch (Throwable $e) {
    $erroOrq = adm_t('O orquestrador não está instalado neste banco (migrações 352 e 353): ') . $e->getMessage();
}
$trilhaPorNumero = [];
foreach ($trilhas as $t) $trilhaPorNumero[(int)$t['trilha']] = $t;

$filas = [];
try {
    foreach ($central->query('SELECT codigo_servico, SUM(pendentes) pendentes, SUM(processando) processando, SUM(concluidas) concluidas, SUM(erros) erros FROM servicos_fila_status GROUP BY codigo_servico')->fetchAll() as $q) {
        $filas[(string)$q['codigo_servico']] = array_map('intval', array_intersect_key($q, array_flip(['pendentes', 'processando', 'concluidas', 'erros'])));
    }
    $e = $central->query("SELECT COUNT(*) total, SUM(status IN ('SOLICITADA','PENDENTE')) pendentes, SUM(status='PROCESSANDO') processando, SUM(status IN ('CONCLUIDA','CONCLUIDO')) concluidas, SUM(status IN ('ERRO','FALHA')) erros FROM servicos_exportacoes_cache")->fetch() ?: [];
    $filas['EXPORTACAO_GRID_PLANILHAS'] = array_map('intval', $e);
} catch (Throwable) {}

// ------------------------------------------------------------------ formatação
$agora = new DateTimeImmutable('now');
$diasNome = [1 => 'seg', 2 => 'ter', 3 => 'qua', 4 => 'qui', 5 => 'sex', 6 => adm_t('sáb'), 7 => 'dom'];
$ha = static function (mixed $seg): string {
    if ($seg === null || $seg === '') return adm_t('nunca');
    $s = max(0, (int)$seg);
    if ($s < 60) return adm_t('há {t}', ['t' => $s . ' s']);
    if ($s < 3600) return adm_t('há {t}', ['t' => intdiv($s, 60) . ' min']);
    if ($s < 172800) return adm_t('há {t}', ['t' => intdiv($s, 3600) . ' h' . ($s < 36000 && $s % 3600 >= 60 ? ' ' . intdiv($s % 3600, 60) . ' min' : '')]);
    return adm_t('há {t} dias', ['t' => intdiv($s, 86400)]);
};
$duracao = static function (mixed $seg): string {
    if ($seg === null || $seg === '') return '-';
    $s = (int)$seg;
    if ($s < 60) return "{$s} s";
    if ($s < 3600) return intdiv($s, 60) . ' min' . ($s % 60 ? ' ' . ($s % 60) . ' s' : '');
    return intdiv($s, 3600) . ' h' . ($s % 3600 >= 60 ? ' ' . str_pad((string)intdiv($s % 3600, 60), 2, '0', STR_PAD_LEFT) . ' min' : '');
};
$dataCurta = static function (mixed $v) use ($agora): string {
    if (!$v) return '-';
    $d = new DateTimeImmutable((string)$v);
    return $d->format('Y-m-d') === $agora->format('Y-m-d') ? $d->format('H:i:s') : $d->format('d/m H:i');
};
$frequencia = static function (array $s) use ($diasNome): string {
    $dias = array_filter(array_map('intval', explode(',', (string)($s['dias_semana'] ?? ''))));
    $txtDias = $dias ? ' (' . implode(', ', array_map(static fn($d) => $diasNome[$d] ?? $d, $dias)) . ')' : '';
    if (!empty($s['horario'])) return ($dias ? adm_t('Às ') : adm_t('Diário às ')) . $s['horario'] . $txtDias;
    $m = (int)$s['intervalo_min'];
    $base = adm_t('A cada {t}', ['t' => $m < 60 || $m % 60 ? $m . ' min' : ($m / 60) . ' h']);
    return $base . $txtDias;
};
// Próxima execução prevista, pela mesma regra de sp_orq_devidos (horário fixo: uma vez por dia, a partir do horário)
$proxima = static function (array $s, ?array $t) use ($agora): array {
    if ((int)$s['ativo'] !== 1) return ['Desligado', 'text-muted'];
    if ($t && (int)$t['ativo'] !== 1) return [adm_t('Trilha desligada'), 'text-danger'];
    if (!empty($s['suspenso_ate']) && new DateTimeImmutable((string)$s['suspenso_ate']) > $agora) return [adm_t('Suspenso até ') . (new DateTimeImmutable((string)$s['suspenso_ate']))->format('H:i'), 'text-danger'];
    $sim = $t && $t['modo'] !== 'EXECUTAR' ? adm_t(' (só simula)') : '';
    if (!empty($s['codexecucao_atual'])) return [adm_t('Executando agora'), 'text-primary'];
    $dias = array_filter(array_map('intval', explode(',', (string)($s['dias_semana'] ?? ''))));
    if (!empty($s['horario'])) {
        $ultima = !empty($s['ultima_inicio']) ? new DateTimeImmutable((string)$s['ultima_inicio']) : null;
        for ($i = 0; $i <= 7; $i++) {
            $dia = $agora->modify("+{$i} day");
            if ($dias && !in_array((int)$dia->format('N'), $dias, true)) continue;
            $quando = new DateTimeImmutable($dia->format('Y-m-d') . ' ' . $s['horario'] . ':00');
            if ($i === 0) {
                if ($ultima && $ultima->format('Y-m-d') === $agora->format('Y-m-d')) continue;
                if ($agora >= $quando) return [adm_t('No próximo ciclo') . $sim, ''];
            }
            return [($i === 0 ? 'Hoje' : ($i === 1 ? adm_t('Amanhã') : $quando->format('d/m'))) . adm_t(' às ') . $quando->format('H:i') . $sim, ''];
        }
        return ['-', 'text-muted'];
    }
    if (empty($s['ultima_inicio'])) return [adm_t('No próximo ciclo') . $sim, ''];
    $quando = (new DateTimeImmutable((string)$s['ultima_inicio']))->modify('+' . (int)$s['intervalo_min'] . ' minutes');
    return [$quando <= $agora ? adm_t('No próximo ciclo') . $sim : ($quando->format('Y-m-d') === $agora->format('Y-m-d') ? $quando->format('H:i') : $quando->format('d/m H:i')) . $sim, ''];
};
$classeStatus = static fn(?string $st): string => match ((string)$st) {
    'CONCLUIDA' => 'text-bg-success', 'EXECUTANDO' => 'text-bg-primary', 'SIMULADA' => 'text-bg-light border',
    'ERRO', 'TEMPO_ESGOTADO' => 'text-bg-danger', 'ABANDONADA' => 'text-bg-warning', default => 'text-bg-secondary',
};
$sinal = static function (?array $t) use ($ha): array {
    if (!$t || $t['batimento_em'] === null) return [adm_t('nunca'), 'text-bg-danger'];
    $s = (int)$t['segundos_sem_sinal'];
    return [$ha($s), $s > 900 ? 'text-bg-danger' : ($s > 180 ? 'text-bg-warning' : 'text-bg-success')];
};
$tarefaTxt = static function (?array $t) use ($ha): array {
    if (!$t || empty($t['tarefa_coletada_em'])) return [adm_t('Aguardando coleta'), 'text-bg-secondary', ''];
    $estado = (string)$t['tarefa_estado'];
    $cl = match ($estado) { 'READY', 'RUNNING', 'QUEUED' => 'text-bg-success', 'DISABLED', 'NAO_INSTALADO' => 'text-bg-danger', default => 'text-bg-warning' };
    $res = $t['tarefa_resultado'] === null ? '' : ' · resultado ' . $t['tarefa_resultado'];
    $nomes = ['READY' => adm_t('Pronta'), 'RUNNING' => adm_t('Rodando'), 'QUEUED' => adm_t('Na fila'), 'DISABLED' => adm_t('Desativada'), 'NAO_INSTALADO' => adm_t('Não encontrada')];
    return [($nomes[$estado] ?? $estado) . $res, $cl, adm_t('coletado {t}', ['t' => $ha($t['segundos_desde_coleta'] ?? null)])];
};
$nomeTrilha = static fn(int $n, ?array $t): string => adm_t('Trilha {n}', ['n' => $n]) . ($t ? ' — ' . adm_t((string)$t['nome']) : '');
$fila = static function (string $codigo) use ($filas): string {
    $partes = [];
    if (isset($filas[$codigo])) {
        $f = $filas[$codigo];
        $partes[] = adm_t('Pendentes {p} · Processando {r} · Erros {e}', ['p' => $f['pendentes'], 'r' => $f['processando'], 'e' => $f['erros']]);
    }
    if ($codigo === 'EXPORTACAO_GRID' && isset($filas['EXPORTACAO_GRID_PLANILHAS'])) {
        $p = $filas['EXPORTACAO_GRID_PLANILHAS'];
        $partes[] = adm_t('Planilhas (cache): {t} · erros {e}', ['t' => (int)($p['total'] ?? 0), 'e' => (int)($p['erros'] ?? 0)]);
    }
    return implode("\n", $partes);
};
$grupos = [];
foreach ($servicos as $s) $grupos[(int)$s['trilha']][] = $s;
ksort($grupos);
?>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2"><link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2"><link rel="stylesheet" href="../assets/css/app.css?v=5"><link href="../assets/css/main.css?v=18" rel="stylesheet"><link href="../assets/css/design-system.css?v=3" rel="stylesheet">
<style>
.orq-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:.75rem;margin-bottom:1rem}
.orq-card{border:1px solid var(--bs-border-color);border-radius:.5rem;padding:.75rem;background:var(--bs-body-bg)}
.orq-card h3{font-size:.95rem;margin:0 0 .5rem;font-weight:600}
.orq-card dl{display:grid;grid-template-columns:auto 1fr;gap:.2rem .6rem;margin:0;font-size:.82rem}
.orq-card dt{font-weight:500;color:var(--bs-secondary-color)}.orq-card dd{margin:0;overflow-wrap:anywhere}
.orq-problemas{border-left:4px solid #dc3545}.orq-problemas.so-aviso{border-left-color:#ffc107}
.orq-problemas li{margin-bottom:.35rem}
.services-grid-scroll{overflow-x:auto}.services-grid{min-width:1500px}
.services-grid th,.services-grid td{vertical-align:middle;font-size:.84rem}
.services-grid tr.orq-grupo td{background:var(--bs-tertiary-bg);font-weight:600}
.services-grid tr[data-service-row]{cursor:pointer}.services-grid tr[data-service-row].table-active{--bs-table-bg-state:var(--bs-primary-bg-subtle)}
.services-grid .service-description{display:block;white-space:normal;color:var(--bs-secondary-color);font-size:.78rem}
.services-grid .service-message{white-space:normal;overflow-wrap:anywhere;max-height:66px;overflow:auto;font-size:.78rem}
.services-grid .orq-fila{white-space:pre-line;font-size:.78rem}
.orq-historico{margin-top:1rem}.orq-historico table{font-size:.8rem}.orq-historico td.msg{white-space:normal;overflow-wrap:anywhere;max-width:520px}
.orq-modal{position:fixed;inset:0;background:rgba(0,0,0,.4);display:none;align-items:flex-start;justify-content:center;z-index:2000;padding-top:6vh}
.orq-modal.aberto{display:flex}.orq-modal-caixa{background:var(--bs-body-bg);border-radius:.5rem;width:min(560px,94vw);padding:1rem 1.25rem;box-shadow:0 10px 30px rgba(0,0,0,.25)}
.orq-modal-caixa label{font-size:.82rem;font-weight:500}.orq-modal-caixa .form-control,.orq-modal-caixa .form-select{padding:.25rem .5rem;min-height:0;height:auto}.orq-dias label{font-weight:400;margin-right:.6rem}
</style>
<section class="setup-fragment menu-grid-fragment grid-ready services-fragment" data-services-page data-url="<?= $html($selfUrl) ?>" data-csrf="<?= $html($csrf) ?>" data-platform-admin="<?= $isPlatformAdmin ? '1' : '0' ?>">
    <div class="crud-toolbar menu-grid-toolbar">
        <div class="crud-actions crud-actions-standard">
            <button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-services-refresh title="<?= adm_a('Atualizar') ?>" aria-label="<?= adm_a('Atualizar') ?>"><i class="bi bi-arrow-clockwise"></i></button>
            <button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-historico-todos title="<?= adm_a('Últimas execuções de todos os serviços') ?>" aria-label="<?= adm_a('Últimas execuções de todos os serviços') ?>"><i class="bi bi-clock-history"></i></button>
            <?php $gpAjuda = class_exists('IdiomaService') ? IdiomaService::t('comum.botao.ajuda', ['pt' => 'Ajuda', 'en' => 'Help', 'es' => 'Ayuda']) : 'Ajuda'; ?><button class="btn btn-sm btn-outline-info grid-crud-button" type="button" data-open-transacao-ajuda data-help-transaction="servicos" title="<?= $html($gpAjuda) ?>" aria-label="<?= $html($gpAjuda) ?>"><i class="bi bi-question-circle"></i></button><script src="../assets/js/ajuda-transacao.js?v=1"></script>
        </div>
        <div class="crud-search"><i class="bi bi-search"></i><input type="search" data-service-search placeholder="<?= adm_a('Buscar no grid') ?>" aria-label="<?= adm_a('Buscar no grid') ?>"></div>
    </div>

    <?php if ($erroOrq !== ''): ?>
        <div class="alert alert-danger border mb-3"><i class="bi bi-exclamation-triangle me-2"></i><?= $html($erroOrq) ?></div>
    <?php endif; ?>

    <?php if ($problemas): $soAviso = !array_filter($problemas, static fn($p) => $p['gravidade'] === 'ERRO'); ?>
        <div class="alert alert-light border orq-problemas <?= $soAviso ? 'so-aviso' : '' ?> mb-3">
            <strong><i class="bi bi-exclamation-octagon me-1 <?= $soAviso ? 'text-warning' : 'text-danger' ?>"></i><?= count($problemas) ?> <?= adm_h('problema(s) nos serviços agora') ?></strong>
            <ul class="mb-0 mt-2 ps-3">
                <?php foreach ($problemas as $p): ?>
                    <li><span class="badge <?= $p['gravidade'] === 'ERRO' ? 'text-bg-danger' : 'text-bg-warning' ?> me-1"><?= $html($p['gravidade']) ?></span><strong><?= $html($p['titulo']) ?>.</strong> <span class="text-body-secondary"><?= $html($p['mensagem']) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php elseif ($erroOrq === ''): ?>
        <div class="alert alert-light border mb-3"><i class="bi bi-check-circle text-success me-2"></i><?= adm_h('Nenhum problema: as trilhas estão rodando e nenhum serviço ou empresa está suspenso.') ?></div>
    <?php endif; ?>

    <?php if ($trilhas): ?>
    <div class="orq-cards">
        <?php foreach ($trilhas as $t): [$sTxt, $sCl] = $sinal($t); [$tTxt, $tCl, $tCol] = $tarefaTxt($t); $n = (int)$t['trilha']; ?>
            <div class="orq-card">
                <h3><?= $html($nomeTrilha($n, $t)) ?></h3>
                <div class="mb-2">
                    <span class="badge <?= $t['modo'] === 'EXECUTAR' ? 'text-bg-success' : 'text-bg-warning' ?>"><?= $html($t['modo']) ?></span>
                    <?php if ((int)$t['ativo'] !== 1): ?><span class="badge text-bg-danger"><?= adm_h('Desligada') ?></span><?php endif; ?>
                </div>
                <dl>
                    <dt><?= adm_h('Sinal de vida') ?></dt><dd><span class="badge <?= $sCl ?>"><?= $html($sTxt) ?></span></dd>
                    <dt><?= adm_h('Rodando agora') ?></dt><dd><?= $html($t['servico_atual'] ?: '—') ?></dd>
                    <dt><?= adm_h('Último ciclo') ?></dt><dd><?php if (!$t['ciclo_inicio']): ?>—<?php else: ?><?= $html($dataCurta($t['ciclo_inicio'])) ?><?= $t['ciclo_fim'] && $t['ciclo_fim'] >= $t['ciclo_inicio'] ? ' → ' . $html($dataCurta($t['ciclo_fim'])) : adm_t(' (em andamento)') ?><?php endif; ?></dd>
                    <dt><?= adm_h('Tarefa do Windows') ?></dt><dd><span class="badge <?= $tCl ?>"><?= $html($tTxt) ?></span> <small class="text-muted"><?= $html($tCol) ?></small></dd>
                    <dt><?= adm_h('Serviços') ?></dt><dd><?= $html(adm_t('{n} ligados', ['n' => (int)$t['servicos_ligados']])) ?><?= (int)$t['servicos_suspensos'] ? ' · <span class="text-danger">' . $html(adm_t('{n} suspenso(s)', ['n' => (int)$t['servicos_suspensos']])) . '</span>' : '' ?></dd>
                    <dt><?= adm_h('Falhas em 24 h') ?></dt><dd class="<?= (int)$t['falhas_24h'] ? 'text-danger' : '' ?>"><?= (int)$t['falhas_24h'] ?></dd>
                </dl>
            </div>
        <?php endforeach; ?>
        <?php [$vTxt, $vCl, $vCol] = $tarefaTxt($vigia); $vSeg = $vigia['segundos_sem_rodar'] ?? null; ?>
        <div class="orq-card">
            <h3><?= adm_h('Vigia') ?></h3>
            <div class="mb-2 small text-muted"><?= adm_h('Confere as trilhas a cada 10 min e alerta os administradores.') ?></div>
            <dl>
                <dt><?= adm_h('Última execução') ?></dt><dd><span class="badge <?= $vSeg === null || (int)$vSeg > 1800 ? 'text-bg-danger' : 'text-bg-success' ?>"><?= $html($ha($vSeg)) ?></span></dd>
                <dt><?= adm_h('Resultado') ?></dt><dd><?= $html($vigia['mensagem'] ?? '-') ?></dd>
                <dt><?= adm_h('Tarefa do Windows') ?></dt><dd><span class="badge <?= $vCl ?>"><?= $html($vTxt) ?></span> <small class="text-muted"><?= $html($vCol) ?></small></dd>
                <dt><?= adm_h('Avisos abertos') ?></dt><dd><?= (int)($vigia['avisos_abertos'] ?? 0) ?></dd>
            </dl>
        </div>
    </div>
    <?php endif; ?>

    <div class="d-flex gap-2 align-items-center mb-2">
        <select class="form-select form-select-sm w-auto" data-filtro-trilha aria-label="<?= adm_a('Filtrar trilha') ?>"><option value=""><?= adm_h('Todas as trilhas') ?></option><?php foreach (array_keys($grupos) as $n): ?><option value="<?= $n ?>"><?= adm_h('Trilha') ?> <?= $n ?></option><?php endforeach; ?></select>
        <select class="form-select form-select-sm w-auto" data-filtro-status aria-label="<?= adm_a('Filtrar situação') ?>"><option value=""><?= adm_h('Todas as situações') ?></option><option>CONCLUIDA</option><option>EXECUTANDO</option><option>ERRO</option><option>TEMPO_ESGOTADO</option><option>ABANDONADA</option><option>SIMULADA</option><option value="SUSPENSO"><?= adm_h('Suspenso') ?></option><option value="DESLIGADO"><?= adm_h('Desligado') ?></option></select>
        <small class="text-muted"><?= adm_h('Clique num serviço para ver as execuções dele.') ?></small>
    </div>
    <div class="menu-grid-scroll services-grid-scroll">
        <table class="table table-sm menu-batch-grid services-grid" id="servicosBatchGrid">
            <thead><tr>
                <th><?= adm_h('Serviço') ?></th><th><?= adm_h('Ordem') ?></th><th><?= adm_h('Frequência') ?></th><th><?= adm_h('Tempo máx.') ?></th><th><?= adm_h('Última execução') ?></th><th><?= adm_h('Próxima (prevista)') ?></th><th><?= adm_h('Falhas') ?></th><th><?= adm_h('Fila') ?></th><th><?= adm_h('Mensagem') ?></th>
                <?php if ($isPlatformAdmin): ?><th><?= adm_h('Ações') ?></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($grupos as $n => $lista): $t = $trilhaPorNumero[$n] ?? null; ?>
                <tr class="orq-grupo" data-grupo="<?= $n ?>"><td colspan="<?= $isPlatformAdmin ? 10 : 9 ?>"><?= $html($nomeTrilha($n, $t)) ?><?= $t && $t['modo'] !== 'EXECUTAR' ? ' · <span class="badge text-bg-warning">SIMULAR</span>' : '' ?></td></tr>
                <?php foreach ($lista as $s):
                    $suspenso = !empty($s['suspenso_ate']) && new DateTimeImmutable((string)$s['suspenso_ate']) > $agora;
                    $status = !empty($s['codexecucao_atual']) ? 'EXECUTANDO' : (string)($s['ultima_status'] ?? '');
                    $filtroStatus = trim($status . ' ' . ($suspenso ? 'SUSPENSO' : '') . ' ' . ((int)$s['ativo'] !== 1 ? 'DESLIGADO' : ''));
                    [$pTxt, $pCl] = $proxima($s, $t);
                    $dados = ['codigo' => $s['codigo'], 'nome' => $s['nome'], 'ativo' => (int)$s['ativo'], 'trilha' => (int)$s['trilha'], 'ordem' => (int)$s['ordem'],
                        'intervalo' => (int)$s['intervalo_min'], 'horario' => (string)($s['horario'] ?? ''), 'dias' => (string)($s['dias_semana'] ?? ''),
                        'limite' => (int)$s['limite_seg'], 'script' => trim($s['script'] . ' ' . ($s['argumentos'] ?? ''))];
                ?>
                <tr data-service-row data-service-code="<?= $html($s['codigo']) ?>" data-trilha="<?= $n ?>" data-status="<?= $html($filtroStatus) ?>" class="<?= (int)$s['ativo'] !== 1 ? 'text-muted' : '' ?>">
                    <td><strong><?= $html($s['nome']) ?></strong><?php if ((int)$s['ativo'] !== 1): ?> <span class="badge text-bg-secondary"><?= adm_h('Desligado') ?></span><?php endif; ?><small class="service-description"><?= $html($s['descricao']) ?></small><small class="service-description"><?= adm_h('Código:') ?> <?= $html($s['codigo']) ?></small></td>
                    <td><?= (int)$s['ordem'] ?></td>
                    <td><?= $html($frequencia($s)) ?><?php if (!empty($s['janela_inicio']) && !empty($s['janela_fim'])): ?><small class="service-description"><?= adm_h('Janela') ?> <?= $html(substr((string)$s['janela_inicio'], 0, 5)) ?>–<?= $html(substr((string)$s['janela_fim'], 0, 5)) ?></small><?php endif; ?></td>
                    <td><?= $html($duracao($s['limite_seg'])) ?></td>
                    <td>
                        <?php if ($status !== ''): ?><span class="badge <?= $classeStatus($status) ?>"><?= $html($status) ?></span><?php endif; ?>
                        <small class="service-description"><?= $html($dataCurta($s['ultima_inicio'])) ?><?= $s['ultima_duracao_seg'] !== null && $status !== 'EXECUTANDO' ? ' · ' . $html($duracao($s['ultima_duracao_seg'])) : '' ?></small>
                    </td>
                    <td class="<?= $pCl ?>"><?= $html($pTxt) ?></td>
                    <td><?php if ($suspenso): ?><span class="badge text-bg-danger"><?= adm_h('Suspenso até') ?> <?= $html((new DateTimeImmutable((string)$s['suspenso_ate']))->format('H:i')) ?></span><?php endif; ?><?= (int)$s['falhas_seguidas'] ? ' <span class="text-danger">' . (int)$s['falhas_seguidas'] . ' seguida(s)</span>' : ($suspenso ? '' : '0') ?></td>
                    <td class="orq-fila"><?= $html($fila((string)$s['codigo']) ?: '-') ?></td>
                    <td class="service-message" title="<?= $html($s['ultima_mensagem'] ?? '') ?>"><?= $html($s['ultima_mensagem'] ?: '') ?></td>
                    <?php if ($isPlatformAdmin): ?>
                    <td class="text-nowrap">
                        <button type="button" class="btn btn-sm btn-outline-primary" data-editar='<?= $html(json_encode($dados, JSON_UNESCAPED_UNICODE)) ?>' title="<?= adm_a('Alterar cadastro') ?>" aria-label="Alterar cadastro de <?= $html($s['nome']) ?>"><i class="bi bi-pencil"></i></button>
                        <?php if ($suspenso || (int)$s['falhas_seguidas'] > 0): ?><button type="button" class="btn btn-sm btn-outline-danger" data-liberar="<?= $html($s['codigo']) ?>" data-nome="<?= $html($s['nome']) ?>" title="<?= adm_a('Tirar a suspensão e zerar as falhas') ?>" aria-label="Liberar <?= $html($s['nome']) ?>"><i class="bi bi-unlock"></i></button><?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if (!$servicos && $erroOrq === ''): ?><tr><td colspan="10" class="text-muted"><?= adm_h('Nenhum serviço no cadastro do orquestrador.') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="orq-historico" data-historico hidden>
        <div class="d-flex gap-2 align-items-center mb-2">
            <strong data-historico-titulo><?= adm_h('Execuções') ?></strong>
            <select class="form-select form-select-sm w-auto" data-historico-status aria-label="<?= adm_a('Filtrar situação das execuções') ?>"><option value=""><?= adm_h('Todas') ?></option><option>CONCLUIDA</option><option>ERRO</option><option>TEMPO_ESGOTADO</option><option>ABANDONADA</option><option>EXECUTANDO</option><option>SIMULADA</option></select>
            <small class="text-muted"><?= adm_h('Últimas 100 · o histórico guarda 30 dias') ?></small>
        </div>
        <div class="menu-grid-scroll"><table class="table table-sm"><thead><tr><th>#</th><th><?= adm_h('Serviço') ?></th><th><?= adm_h('Trilha') ?></th><th><?= adm_h('Início') ?></th><th><?= adm_h('Fim') ?></th><th><?= adm_h('Duração') ?></th><th><?= adm_h('Situação') ?></th><th><?= adm_h('Saída') ?></th><th><?= adm_h('Processo') ?></th><th><?= adm_h('Mensagem') ?></th></tr></thead><tbody data-historico-linhas></tbody></table></div>
    </div>

    <?php if ($isPlatformAdmin): ?>
    <div class="orq-modal" data-modal role="dialog" aria-modal="true" aria-labelledby="orqModalTitulo">
        <form class="orq-modal-caixa" data-form>
            <h2 class="h6 mb-1" id="orqModalTitulo" data-modal-titulo><?= adm_h('Serviço') ?></h2>
            <div class="small text-muted mb-3" data-modal-script></div>
            <input type="hidden" name="servico">
            <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="ativo" id="orqAtivo" value="1"><label class="form-check-label" for="orqAtivo"><?= adm_h('Ligado') ?></label></div>
            <div class="row g-2">
                <div class="col-6"><label for="orqTrilha"><?= adm_h('Trilha') ?></label><select class="form-select form-select-sm" name="trilha" id="orqTrilha"><option value="1"><?= adm_h('1 — rápida') ?></option><option value="2">2 — filas e regras</option><option value="3"><?= adm_h('3 — pesada') ?></option></select></div>
                <div class="col-6"><label for="orqOrdem"><?= adm_h('Ordem na trilha') ?></label><input class="form-control form-control-sm" type="number" name="ordem" id="orqOrdem" min="0" max="9999"></div>
                <div class="col-6"><label for="orqIntervalo"><?= adm_h('Frequência (minutos)') ?></label><input class="form-control form-control-sm" type="number" name="intervalo" id="orqIntervalo" min="1" max="10080" required></div>
                <div class="col-6"><label for="orqHorario"><?= adm_h('Horário fixo (opcional)') ?></label><input class="form-control form-control-sm" type="time" name="horario" id="orqHorario"></div>
                <div class="col-12 orq-dias"><label class="d-block"><?= adm_h('Dias da semana') ?> <small class="text-muted"><?= adm_h('(nenhum marcado = todos)') ?></small></label>
                    <?php foreach ($diasNome as $d => $nomeDia): ?><label><input type="checkbox" name="dias[]" value="<?= $d ?>"> <?= $html($nomeDia) ?></label><?php endforeach; ?>
                </div>
                <div class="col-6"><label for="orqLimite"><?= adm_h('Tempo máximo (segundos)') ?></label><input class="form-control form-control-sm" type="number" name="limite" id="orqLimite" min="30" max="259200" required><small class="text-muted" data-limite-txt></small></div>
            </div>
            <p class="small text-muted mt-2 mb-2"><?= adm_h('Com horário fixo o serviço roda uma vez por dia, a partir do horário. Salvar também tira a suspensão e zera as falhas seguidas. Vale a partir do próximo ciclo da trilha.') ?></p>
            <div class="alert alert-danger py-1 px-2 small d-none" data-modal-erro></div>
            <div class="d-flex justify-content-end gap-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-fechar><?= adm_h('Cancelar') ?></button><button type="submit" class="btn btn-sm btn-primary"><?= adm_h('Salvar') ?></button></div>
        </form>
    </div>
    <?php endif; ?>
</section>
<script>
(function(){
    const root=document.querySelector('[data-services-page]'); if(!root)return;
    const url=root.dataset.url, csrf=root.dataset.csrf;
    const rows=[...root.querySelectorAll('[data-service-row]')], grupos=[...root.querySelectorAll('tr.orq-grupo')];
    const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    root.querySelector('[data-services-refresh]')?.addEventListener('click',()=>location.reload());

    // Filtros
    const filtrar=()=>{
        const busca=(root.querySelector('[data-service-search]')?.value||'').toLowerCase();
        const trilha=root.querySelector('[data-filtro-trilha]')?.value||'', status=root.querySelector('[data-filtro-status]')?.value||'';
        rows.forEach(r=>{let ok=(!busca||r.innerText.toLowerCase().includes(busca))&&(!trilha||r.dataset.trilha===trilha)&&(!status||(' '+r.dataset.status+' ').includes(' '+status+' '));r.classList.toggle('d-none',!ok);});
        grupos.forEach(g=>g.classList.toggle('d-none',!rows.some(r=>r.dataset.trilha===g.dataset.grupo&&!r.classList.contains('d-none'))));
    };
    root.querySelector('[data-service-search]')?.addEventListener('input',filtrar);
    root.querySelectorAll('[data-filtro-trilha],[data-filtro-status]').forEach(s=>s.addEventListener('change',filtrar));

    // Histórico
    const hist=root.querySelector('[data-historico]'), corpo=root.querySelector('[data-historico-linhas]'), titulo=root.querySelector('[data-historico-titulo]'), filtroHist=root.querySelector('[data-historico-status]');
    let linhasHist=[];
    const cls=s=>({CONCLUIDA:'text-bg-success',EXECUTANDO:'text-bg-primary',SIMULADA:'text-bg-light border',ERRO:'text-bg-danger',TEMPO_ESGOTADO:'text-bg-danger',ABANDONADA:'text-bg-warning'}[s]||'text-bg-secondary');
    const dur=s=>{if(s===null||s==='')return '-';s=+s;if(s<60)return s+' s';if(s<3600)return Math.floor(s/60)+' min'+(s%60?' '+s%60+' s':'');return Math.floor(s/3600)+' h '+String(Math.floor(s%3600/60)).padStart(2,'0')+' min';};
    const desenhar=()=>{const f=filtroHist.value;const lista=linhasHist.filter(l=>!f||l.status===f);corpo.innerHTML=lista.length?lista.map(l=>'<tr><td>'+esc(l.codexecucao)+'</td><td>'+esc(l.codigo)+'</td><td>'+esc(l.trilha)+'</td><td>'+esc(l.inicio_em)+'</td><td>'+esc(l.fim_em||'')+'</td><td>'+esc(dur(l.duracao_seg))+'</td><td><span class="badge '+cls(l.status)+'">'+esc(l.status)+'</span></td><td>'+esc(l.codigo_saida??'')+'</td><td>'+esc(l.pid??'')+'</td><td class="msg">'+esc(l.mensagem||'')+'</td></tr>').join(''):<?= adm_js('<tr><td colspan="10" class="text-muted">Nenhuma execução.</td></tr>') ?>;};
    filtroHist?.addEventListener('change',desenhar);
    const carregar=(codigo,nome)=>{
        hist.hidden=false; titulo.textContent=<?= adm_js('Execuções — ') ?>+(nome||<?= adm_js('todos os serviços') ?>); corpo.innerHTML='<tr><td colspan="10" class="text-muted">Carregando…</td></tr>';
        fetch(url+'?acao=historico&limite=100&servico='+encodeURIComponent(codigo||''),{credentials:'same-origin'}).then(r=>r.json()).then(j=>{if(!j.ok)throw new Error(j.mensagem||'Erro');linhasHist=j.linhas||[];desenhar();hist.scrollIntoView({behavior:'smooth',block:'nearest'});}).catch(e=>{corpo.innerHTML='<tr><td colspan="10" class="text-danger">'+esc(e.message)+'</td></tr>';});
    };
    rows.forEach(r=>r.addEventListener('click',ev=>{if(ev.target.closest('button'))return;rows.forEach(x=>x.classList.remove('table-active'));r.classList.add('table-active');carregar(r.dataset.serviceCode,r.querySelector('strong')?.textContent);}));
    root.querySelector('[data-historico-todos]')?.addEventListener('click',()=>{rows.forEach(x=>x.classList.remove('table-active'));carregar('', '');});

    // Alterar e liberar (ADMINISTRADOR)
    const enviar=dados=>{dados.append('csrf',csrf);return fetch(url,{method:'POST',body:dados,credentials:'same-origin'}).then(r=>r.json());};
    root.querySelectorAll('[data-liberar]').forEach(b=>b.addEventListener('click',()=>{
        if(!confirm(<?= adm_js('Tirar a suspensão de "') ?>+b.dataset.nome+<?= adm_js('" e zerar as falhas seguidas?') ?>))return;
        const d=new FormData();d.append('acao','liberar');d.append('servico',b.dataset.liberar);
        enviar(d).then(j=>{alert(j.mensagem||'');if(j.ok)location.reload();}).catch(e=>alert(e.message));
    }));
    const modal=root.querySelector('[data-modal]'); if(!modal)return;
    const form=modal.querySelector('[data-form]'), erro=modal.querySelector('[data-modal-erro]'), limTxt=modal.querySelector('[data-limite-txt]');
    const fechar=()=>modal.classList.remove('aberto');
    modal.querySelector('[data-fechar]').addEventListener('click',fechar);
    modal.addEventListener('click',e=>{if(e.target===modal)fechar();});
    document.addEventListener('keydown',e=>{if(e.key==='Escape'&&modal.classList.contains('aberto'))fechar();});
    form.limite.addEventListener('input',()=>{limTxt.textContent='= '+dur(form.limite.value);});
    root.querySelectorAll('[data-editar]').forEach(b=>b.addEventListener('click',()=>{
        const s=JSON.parse(b.dataset.editar);
        modal.querySelector('[data-modal-titulo]').textContent=s.nome+' ('+s.codigo+')';
        modal.querySelector('[data-modal-script]').textContent='Script: '+s.script;
        form.servico.value=s.codigo; form.ativo.checked=s.ativo===1; form.trilha.value=String(s.trilha); form.ordem.value=s.ordem;
        form.intervalo.value=s.intervalo; form.horario.value=s.horario||''; form.limite.value=s.limite; limTxt.textContent='= '+dur(s.limite);
        const dias=(s.dias||'').split(',').filter(Boolean);
        form.querySelectorAll('input[name="dias[]"]').forEach(c=>{c.checked=dias.includes(c.value);});
        erro.classList.add('d-none'); modal.classList.add('aberto'); form.intervalo.focus();
    }));
    form.addEventListener('submit',ev=>{
        ev.preventDefault();
        const d=new FormData(form);d.append('acao','salvar');if(!form.ativo.checked)d.set('ativo','0');
        enviar(d).then(j=>{if(!j.ok){erro.textContent=j.mensagem||<?= adm_js('Não foi possível salvar.') ?>;erro.classList.remove('d-none');return;}fechar();location.reload();}).catch(e=>{erro.textContent=e.message;erro.classList.remove('d-none');});
    });
})();
</script>
<?php require_once dirname(__DIR__) . '/components/toolbar_grupos.php'; greenPortalToolbarGruposAuto(); ?>
