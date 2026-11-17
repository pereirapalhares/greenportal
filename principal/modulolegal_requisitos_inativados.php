<?php
declare(strict_types=1);
/*
 * Requisitos inativados (Módulo Legal, migração 286): requisitos retirados da base ativa de uma unidade, com quem, quando e
 * por quê; botão Reativar (ADMINISTRADOR, ADM DA EMPRESA ou quem pode excluir em Requisitos legais) e o histórico.
 * Dados só por views (vw_requisitos_inativados_grid, vw_requisitos_inativacao_historico) e procedures (sp_requisito_reativar).
 * Migração 369: sub-abas (Requisitos inativados | Histórico) com as listas no grid padrão (DashboardGrid / grid local).
 */
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/DashboardGrid.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$pdo = TransactionSecurity::connect('REQUISITOS_LEGAIS');
$permission = PermissaoTransacao::obter('REQUISITOS_LEGAIS');
$perfil = strtoupper(trim((string)($_SESSION['usuario']['codigo_perfil'] ?? '')));
$perfil = preg_replace('/\s+/', ' ', preg_replace('/[_-]+/', ' ', $perfil) ?? $perfil) ?? $perfil;
$podeReativar = in_array($perfil, ['ADMINISTRADOR', 'ADM DA EMPRESA', 'ADM EMPRESA'], true) || !empty($permission['pode_excluir']);
$T = static fn(string $chave, array $textos): string => IdiomaService::t('requisitos_inativados.' . $chave, $textos);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$sel = static function (string $view, array $filtros = []) use ($pdo): ?array {
    try {
        $out = [];
        for ($o = 0; ; $o += 1000) {
            $s = $pdo->prepare('CALL sp_crud_select(:t, :f, NULL, 1000, :o)'); $s->execute(['t' => $view, 'f' => json_encode((object)$filtros), 'o' => $o]);
            $l = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); array_push($out, ...$l);
            if (count($l) < 1000) break;
        }
        return $out;
    } catch (Throwable) { return null; }
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'reativar') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$podeReativar) { echo json_encode(['ok' => false, 'message' => $T('sem_permissao', ['pt' => 'Seu perfil não pode reativar requisitos.', 'en' => 'Your profile cannot reactivate requirements.', 'es' => 'Su perfil no puede reactivar requisitos.'])], JSON_UNESCAPED_UNICODE); exit; }
    try {
        $st = $pdo->prepare('CALL sp_requisito_reativar(:l, :u, :m, :usr, @resultado_reativar)');
        $st->execute(['l' => (int)($_POST['codlegislacao'] ?? 0), 'u' => (int)($_POST['codunidade'] ?? 0), 'm' => trim((string)($_POST['motivo'] ?? '')), 'usr' => (int)($_SESSION['usuario']['codusuario'] ?? 0)]);
        do { try { $st->fetchAll(); } catch (Throwable) {} } while ($st->nextRowset()); $st->closeCursor();
        $r = (string)($pdo->query('SELECT @resultado_reativar')->fetchColumn() ?: '');
    } catch (Throwable $erro) { echo json_encode(['ok' => false, 'message' => $erro->getMessage()], JSON_UNESCAPED_UNICODE); exit; }
    $msg = ['OK' => ['pt' => 'Requisito reativado: voltou para a base ativa da unidade com as avaliações e evidências que tinha.', 'en' => 'Requirement reactivated: it is back in the unit\'s active base with its previous assessments and evidence.', 'es' => 'Requisito reactivado: volvió a la base activa de la unidad con sus evaluaciones y evidencias.'],
        'JA_ATIVO' => ['pt' => 'A lei já estava de novo ativa nesta unidade; a inativação foi encerrada.', 'en' => 'The law was already active again for this unit; the deactivation was closed.', 'es' => 'La ley ya estaba activa de nuevo en esta unidad; la inactivación se cerró.'],
        'NAO_ENCONTRADO' => ['pt' => 'Este requisito não está mais inativado.', 'en' => 'This requirement is no longer deactivated.', 'es' => 'Este requisito ya no está inactivado.']];
    echo json_encode(['ok' => in_array($r, ['OK', 'JA_ATIVO'], true), 'message' => $T('msg.' . strtolower($r ?: 'erro'), $msg[$r] ?? ['pt' => 'Não foi possível reativar.', 'en' => 'Could not reactivate.', 'es' => 'No fue posible reactivar.'])], JSON_UNESCAPED_UNICODE);
    exit;
}

$linhas = $sel('vw_requisitos_inativados_grid');
$historico = $sel('vw_requisitos_inativacao_historico') ?? [];
usort($historico, static fn($a, $b) => (int)$b['CODHISTORICO'] <=> (int)$a['CODHISTORICO']);
if ($linhas !== null) usort($linhas, static fn($a, $b) => strcmp((string)$b['INATIVADO_EM'], (string)$a['INATIVADO_EM']));
$data = static fn($v): string => $v ? date('d/m/Y H:i', strtotime((string)$v)) : '–';
?>
<!doctype html><html lang="<?= IdiomaService::idioma() === 'pt' ? 'pt-BR' : IdiomaService::idioma() ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $e($T('titulo', ['pt' => 'Requisitos inativados', 'en' => 'Deactivated requirements', 'es' => 'Requisitos inactivados'])) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2"><link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2"><link rel="stylesheet" href="../assets/css/app.css?v=3"><link rel="stylesheet" href="../assets/css/main.css?v=15"><?php DashboardGrid::head(); ?>
<style>
body{background:var(--gp-surface)}.ri{padding:12px 16px;color:var(--gp-text)}.ri h2{font-size:16px;margin:0 0 2px}.ri .sub{font-size:11px;color:var(--gp-muted);margin-bottom:10px}
.ri-busca{max-width:340px;height:auto!important;padding:5px 9px!important;font-size:12px}.ri table{width:100%;font-size:11.5px;background:#fff;border:1px solid var(--gp-border);border-radius:8px}
.ri th{color:var(--gp-muted);font-weight:600;background:#fff;position:sticky;top:0}.ri td,.ri th{padding:6px 7px;border-bottom:1px solid var(--gp-border);vertical-align:top}.ri .motivo{max-width:380px}
.ri-rolagem{max-height:calc(100vh - 170px);overflow:auto}.ri .vazio{padding:28px;text-align:center;color:var(--gp-muted);font-size:12px;background:#fff;border:1px solid var(--gp-border);border-radius:8px}
.ri-hist{margin-top:14px}.ri-hist summary{font-size:12px;color:var(--gp-primary);cursor:pointer}.ri .badge{font-weight:500}
</style><?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?></head>
<body><main class="ri">
<h2><i class="bi bi-archive me-1"></i><?= $e($T('titulo', ['pt' => 'Requisitos inativados', 'en' => 'Deactivated requirements', 'es' => 'Requisitos inactivados'])) ?></h2>
<div class="sub"><?= $e($T('sub', ['pt' => 'Requisitos retirados da base ativa de uma unidade. Não aparecem no grid, nos dashboards, no cálculo da situação legal nem no relatório. Reativar devolve o requisito com as avaliações e evidências que tinha.', 'en' => 'Requirements removed from a unit\'s active base. They do not show in the grid, dashboards, legal status calculation or report. Reactivating brings the requirement back with its assessments and evidence.', 'es' => 'Requisitos retirados de la base activa de una unidad. No aparecen en la cuadrícula, los paneles, el cálculo de la situación legal ni el informe. Reactivar devuelve el requisito con sus evaluaciones y evidencias.'])) ?></div>
<?php if ($linhas === null): ?><div class="alert alert-warning small"><?= $e($T('sem_migracao', ['pt' => 'Funcionalidade ainda não instalada nesta empresa (rode a migração 286).', 'en' => 'Feature not installed for this company yet (run migration 286).', 'es' => 'Funcionalidad aún no instalada en esta empresa (ejecute la migración 286).'])) ?></div>
<?php else:
$dataSort = static fn($v): array => ['text' => $data($v), 'sort' => $v ? date('Y-m-d H:i:s', strtotime((string)$v)) : ''];
$rows = [];
foreach ($linhas as $r) {
    $cod = (string)($r['CODIGO'] ?: ('#' . $r['CODLEGISLACAO'])); $padrao = trim((string)$r['PADRAO']);
    $rows[] = ['cells' => [
        'UNIDADE' => (string)$r['UNIDADE'],
        'CODIGO' => ['text' => $cod, 'html' => '<b>' . $e($cod) . '</b>'],
        'NORMA' => ['text' => trim($r['NORMA'] . ' ' . $padrao), 'title' => $padrao, 'html' => $e($r['NORMA']) . ($padrao !== '' ? ' <span class="text-muted small">' . $e(mb_strimwidth($padrao, 0, 120, '…')) . '</span>' : '')],
        'MOTIVO' => ['text' => (string)$r['MOTIVO'], 'title' => (string)$r['MOTIVO']],
        'POR' => (string)$r['INATIVADO_POR_NOME'],
        'EM' => $dataSort($r['INATIVADO_EM']),
        'ACOES' => ['text' => '', 'html' => $podeReativar ? '<button type="button" class="btn btn-sm btn-outline-success py-0" data-reativar="' . (int)$r['CODLEGISLACAO'] . ':' . (int)$r['CODUNIDADE'] . '" data-rotulo="' . $e($cod . ' · ' . $r['UNIDADE']) . '"><i class="bi bi-arrow-counterclockwise me-1"></i>' . $e($T('reativar', ['pt' => 'Reativar', 'en' => 'Reactivate', 'es' => 'Reactivar'])) . '</button>' : ''],
    ]];
}
$colunas = [
    'UNIDADE' => ['label' => $T('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), 'filter' => 'select', 'width' => '150px'],
    'CODIGO' => ['label' => $T('col.codigo', ['pt' => 'Requisito', 'en' => 'Requirement', 'es' => 'Requisito']), 'width' => '120px'],
    'NORMA' => ['label' => $T('col.norma', ['pt' => 'Norma', 'en' => 'Standard', 'es' => 'Norma'])],
    'MOTIVO' => ['label' => $T('col.motivo', ['pt' => 'Motivo', 'en' => 'Reason', 'es' => 'Motivo'])],
    'POR' => ['label' => $T('col.por', ['pt' => 'Inativado por', 'en' => 'Deactivated by', 'es' => 'Inactivado por']), 'filter' => 'select', 'width' => '150px'],
    'EM' => ['label' => $T('col.em', ['pt' => 'Em', 'en' => 'On', 'es' => 'En']), 'filter' => 'none', 'width' => '125px'],
];
if ($podeReativar) $colunas['ACOES'] = ['label' => '', 'filter' => 'none', 'width' => '110px'];
$gridLista = DashboardGrid::grid('riLista', $colunas, $rows, ['transacao' => 'modulolegal_requisitos_inativados', 'ordem' => ['EM', 'desc'],
    'vazio' => $T('vazio', ['pt' => 'Nenhum requisito inativado.', 'en' => 'No deactivated requirement.', 'es' => 'Ningún requisito inactivado.'])]);
$rowsH = [];
foreach (array_slice($historico, 0, 500) as $h) {
    $inat = $h['ACAO'] === 'INATIVAR';
    $txt = $inat ? $T('acao.inativar', ['pt' => 'Inativado', 'en' => 'Deactivated', 'es' => 'Inactivado']) : $T('acao.reativar', ['pt' => 'Reativado', 'en' => 'Reactivated', 'es' => 'Reactivado']);
    $rowsH[] = ['cells' => ['EM' => $dataSort($h['EM']), 'ACAO' => ['text' => $txt, 'html' => '<span class="badge text-bg-' . ($inat ? 'secondary' : 'success') . '">' . $e($txt) . '</span>'],
        'UNIDADE' => (string)$h['UNIDADE'], 'LEI' => ['text' => '#' . (int)$h['CODLEGISLACAO'], 'sort' => (string)(int)$h['CODLEGISLACAO']], 'USUARIO' => (string)$h['USUARIO'],
        'MOTIVO' => ['text' => (string)$h['MOTIVO'], 'title' => (string)$h['MOTIVO']]]];
}
$gridHist = $historico ? DashboardGrid::grid('riHistorico', [
    'EM' => ['label' => $T('col.em', ['pt' => 'Em', 'en' => 'On', 'es' => 'En']), 'filter' => 'none', 'width' => '125px'],
    'ACAO' => ['label' => $T('col.acao', ['pt' => 'Ação', 'en' => 'Action', 'es' => 'Acción']), 'filter' => 'select', 'width' => '110px'],
    'UNIDADE' => ['label' => $T('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), 'filter' => 'select', 'width' => '150px'],
    'LEI' => ['label' => $T('col.lei', ['pt' => 'Lei', 'en' => 'Law', 'es' => 'Ley']), 'width' => '80px'],
    'USUARIO' => ['label' => $T('col.usuario', ['pt' => 'Usuário', 'en' => 'User', 'es' => 'Usuario']), 'filter' => 'select', 'width' => '150px'],
    'MOTIVO' => ['label' => $T('col.motivo', ['pt' => 'Motivo', 'en' => 'Reason', 'es' => 'Motivo'])],
], $rowsH, ['transacao' => 'modulolegal_requisitos_inativados', 'ordem' => ['EM', 'desc']]) : '';
echo DashboardGrid::subabas('requisitos-inativados', [
    ['id' => 'lista', 'icone' => 'bi-archive', 'rotulo' => $T('titulo', ['pt' => 'Requisitos inativados', 'en' => 'Deactivated requirements', 'es' => 'Requisitos inactivados']), 'html' => $gridLista, 'contagem' => count($linhas)],
    ['id' => 'historico', 'icone' => 'bi-clock-history', 'rotulo' => $T('historico_curto', ['pt' => 'Histórico', 'en' => 'History', 'es' => 'Historial']), 'html' => $gridHist, 'contagem' => count($historico)],
], true);
endif; ?>
</main>
<?php DashboardGrid::scripts(); ?>
<script>
(function () {
    'use strict';
    var T = <?= json_encode([
        'confirmar' => $T('confirmar', ['pt' => 'Reativar este requisito na unidade? Ele volta para a base ativa com as avaliações e evidências que tinha.', 'en' => 'Reactivate this requirement for the unit? It goes back to the active base with its previous assessments and evidence.', 'es' => '¿Reactivar este requisito en la unidad? Vuelve a la base activa con sus evaluaciones y evidencias.']),
        'titulo' => $T('titulo', ['pt' => 'Requisitos inativados', 'en' => 'Deactivated requirements', 'es' => 'Requisitos inactivados']),
        'erro' => $T('erro', ['pt' => 'Não foi possível reativar.', 'en' => 'Could not reactivate.', 'es' => 'No fue posible reactivar.']),
    ], JSON_UNESCAPED_UNICODE) ?>;
    function host() { var w = window; for (var i = 0; i < 6 && w; i++) { try { if (w.GreenPortalPopups && w.jQuery) return w; } catch (e) { break; } if (w === w.parent) break; w = w.parent; } return null; }
    function mensagem(texto, ok, confirmar) {
        var h = host();
        if (h && h.GreenPortalPopups.mensagem) h.GreenPortalPopups.mensagem({ titulo: T.titulo, mensagem: texto, icone: ok ? 'bi-check-circle' : 'bi-question-circle', confirmar: confirmar });
        else if (!confirmar || window.confirm(texto)) { if (confirmar) confirmar(); }
    }
    document.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-reativar]'); if (!b) return;
        var p = b.getAttribute('data-reativar').split(':');
        mensagem(T.confirmar + '\n\n' + b.getAttribute('data-rotulo'), false, function () {
            b.disabled = true;
            fetch(window.location.pathname, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ action: 'reativar', codlegislacao: p[0], codunidade: p[1] }) })
                .then(function (r) { return r.json(); })
                .then(function (r) { mensagem((r && r.message) || T.erro, r && r.ok); if (r && r.ok) window.setTimeout(function () { window.location.reload(); }, 600); else b.disabled = false; })
                .catch(function () { mensagem(T.erro, false); b.disabled = false; });
        });
    });
}());
</script>
</body></html>
