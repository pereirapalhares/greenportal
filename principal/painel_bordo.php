<?php
declare(strict_types=1);

/*
 * Home › Painel de bordo (Revisão G item 5.3; 26/11/2026): quem fez e quem não fez, por usuário, nos módulos da empresa.
 * Para cada pessoa: pendências por módulo (vencidas e a vencer em 30 dias, a mesma regra de Minhas pendências), ações gravadas nos
 * últimos 30 dias (logs) e o último acesso, com a bandeira do caminho crítico:
 *   vermelha = tem pendência vencida e (3 ou mais vencidas ou nenhuma ação gravada em 30 dias);
 *   amarela  = tem vencida, ou mais de 5 a vencer;  verde = em dia.
 * Os módulos que aparecem são os que geram pendências na empresa (os contratados). ADMINISTRADOR e ADM DA EMPRESA veem todos;
 * o gestor vê a própria equipe. A lista de cada pendência fica em Gestão de pendências.
 * Leitura: MinhasPendencias (sp_crud_select em lote) e SELECT na view vw_logs_acoes (migração 421).
 */
require_once dirname(__DIR__) . '/classes/PaginaSimples.php';
require_once dirname(__DIR__) . '/classes/MinhasPendencias.php';
require_once dirname(__DIR__) . '/classes/PainelLateralService.php';
require_once dirname(__DIR__) . '/classes/UsuarioImagem.php';
use PaginaSimples as P;

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$perfil = strtoupper((string)preg_replace('/[\s_-]+/', ' ', (string)($_SESSION['usuario']['codigo_perfil'] ?? '')));
$adm = in_array($perfil, ['ADMINISTRADOR', 'ADM DA EMPRESA', 'ADM EMPRESA'], true);
P::abrir('DASHBOARD', false, in_array($perfil, ['ADMINISTRADOR', 'ADM DA EMPRESA', 'ADM EMPRESA', 'GESTOR'], true));
@set_time_limit(180);
$equipe = (new PainelLateralService(P::$pdo, P::$uid))->equipe();
$pessoas = [];
foreach (P::sel('vw_usuarios_validade', ['ATIVO' => 1], 20000) as $u) if ((int)($u['CODFORNECEDOR'] ?? 0) === 0) $pessoas[(int)$u['CODUSUARIO']] = $u;
if (!$adm) $pessoas = array_intersect_key($pessoas, $equipe);
$det = MinhasPendencias::detalhePorUsuario(P::$pdo, array_keys($pessoas));
$acoes = [];
try {
    $s = P::$pdo->prepare("SELECT `codusuario` AS U, COUNT(*) AS N FROM `vw_logs_acoes` WHERE `DATA_EVENTO` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND UPPER(COALESCE(`acao`, '')) NOT IN ('ACESSAR', 'VISUALIZAR', 'LER', 'LISTAR', 'NEGADO', 'DOWNLOAD') GROUP BY `codusuario`");
    $s->execute(); foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $acoes[(int)$r['U']] = (int)$r['N']; $s->closeCursor();
} catch (Throwable) { $acoes = null; }
$pilares = [];
foreach ($det as $p) foreach ($p as $nome => $_) $pilares[$nome] = true;
$pilares = array_keys($pilares); sort($pilares, SORT_NATURAL | SORT_FLAG_CASE);
$linhas = [];
foreach ($pessoas as $id => $u) {
    $t = ['total' => 0, 'vencidas' => 0, 'avencer' => 0];
    foreach ($det[$id] ?? [] as $c) foreach ($t as $k => $_) $t[$k] += $c[$k];
    $fez = $acoes === null ? null : ($acoes[$id] ?? 0);
    $flag = $t['vencidas'] > 0 && ($t['vencidas'] >= 3 || $fez === 0) ? 'vermelha' : ($t['vencidas'] > 0 || $t['avencer'] > 5 ? 'amarela' : 'verde');
    $linhas[] = ['id' => $id, 'u' => $u, 't' => $t, 'fez' => $fez, 'flag' => $flag];
}
$ordem = ['vermelha' => 0, 'amarela' => 1, 'verde' => 2];
usort($linhas, static fn($a, $b) => [$ordem[$a['flag']], -$a['t']['vencidas'], -$a['t']['total'], (string)$a['u']['NOME']] <=> [$ordem[$b['flag']], -$b['t']['vencidas'], -$b['t']['total'], (string)$b['u']['NOME']]);
$cont = array_count_values(array_column($linhas, 'flag'));
$banco = (string)$_SESSION['tenant']['banco_dados'];
session_write_close();
$fmt = static fn(?string $d): string => $d && ($t = strtotime($d)) ? date(P::$idioma === 'en' ? 'm/d/Y' : 'd/m/Y', $t) : '—';
P::cabecalho(P::L('Painel de bordo', 'Management dashboard', 'Panel de control'), 'bi-flag', 'painel_bordo', '.pb-av{width:26px;height:26px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:#dfe9e4 center/cover no-repeat;font-size:11px;font-weight:600;margin-right:6px;vertical-align:middle}.pb-flag{font-size:16px}.pb-vermelha{color:#c62828}.pb-amarela{color:#e0a800}.pb-verde{color:#2f8a5f}.pb td.num{text-align:center}.pb td .v{color:#c62828;font-weight:600}.pb td .a{color:#b8860b}.pb-tab{max-height:66vh;overflow:auto}.pb-tab thead th{position:sticky;top:0;background:#f4f7f5;z-index:1}');
?>
<p class="ps-ajuda small mb-2"><?= P::e(P::L('Quem fez e quem não fez: pendências de cada pessoa por módulo (vencidas em vermelho, a vencer em 30 dias em amarelo), ações gravadas nos últimos 30 dias e último acesso. Bandeira vermelha = caminho crítico.', 'Who did and who did not: each person\'s pending items per module (overdue in red, due in 30 days in yellow), actions saved in the last 30 days and last access. Red flag = critical path.', 'Quién hizo y quién no: pendientes de cada persona por módulo (vencidos en rojo, por vencer en 30 días en amarillo), acciones grabadas en 30 días y último acceso. Bandera roja = camino crítico.')) ?></p>
<div class="d-flex gap-3 mb-2 small"><span><i class="bi bi-flag-fill pb-vermelha"></i> <?= (int)($cont['vermelha'] ?? 0) ?> <?= P::e(P::L('críticas', 'critical', 'críticas')) ?></span><span><i class="bi bi-flag-fill pb-amarela"></i> <?= (int)($cont['amarela'] ?? 0) ?> <?= P::e(P::L('em atenção', 'attention', 'en atención')) ?></span><span><i class="bi bi-flag-fill pb-verde"></i> <?= (int)($cont['verde'] ?? 0) ?> <?= P::e(P::L('em dia', 'up to date', 'al día')) ?></span>
<span class="ms-auto"><select class="form-select form-select-sm" data-pb-filtro data-sem-memoria><option value=""><?= P::e(P::L('Todas as pessoas', 'Everyone', 'Todas las personas')) ?></option><option value="vermelha"><?= P::e(P::L('Só críticas', 'Critical only', 'Solo críticas')) ?></option><option value="amarela"><?= P::e(P::L('Críticas e em atenção', 'Critical and attention', 'Críticas y en atención')) ?></option></select></span></div>
<div class="pb-tab border rounded bg-white pb"><table class="table table-sm table-hover mb-0"><thead><tr><th></th><th><?= P::e(P::L('Pessoa', 'Person', 'Persona')) ?></th>
<?php foreach ($pilares as $p): ?><th class="text-center small"><?= P::e($p) ?></th><?php endforeach; ?><th class="text-center"><?= P::e(P::L('Total', 'Total', 'Total')) ?></th><th class="text-center small"><?= P::e(P::L('Ações (30 dias)', 'Actions (30 days)', 'Acciones (30 días)')) ?></th><th class="small"><?= P::e(P::L('Último acesso', 'Last access', 'Último acceso')) ?></th></tr></thead><tbody>
<?php foreach ($linhas as $l): $u = $l['u']; $v = UsuarioImagem::fotoVersao($banco, $l['id']); $ini = mb_strtoupper(mb_substr(trim((string)$u['NOME']), 0, 1)); ?>
<tr data-flag="<?= $l['flag'] ?>"><td><i class="bi bi-flag-fill pb-flag pb-<?= $l['flag'] ?>"></i></td>
<td class="text-nowrap"><span class="pb-av"<?= $v ? ' style="background-image:url(usuario_foto.php?u=' . $l['id'] . '&amp;v=' . $v . ')"' : '' ?>><?= $v ? '' : P::e($ini) ?></span><?= P::e($u['NOME']) ?><div class="small text-muted ps-4 ms-2"><?= P::e($u['PERFIL'] ?? '') ?></div></td>
<?php foreach ($pilares as $p): $c = $det[$l['id']][$p] ?? null; ?><td class="num small"><?= $c ? (int)$c['total'] . ($c['vencidas'] ? ' <span class="v">(' . (int)$c['vencidas'] . ')</span>' : '') . ($c['avencer'] ? ' <span class="a">+' . (int)$c['avencer'] . '</span>' : '') : '<span class="text-muted">—</span>' ?></td><?php endforeach; ?>
<td class="num fw-semibold"><?= (int)$l['t']['total'] ?><?= $l['t']['vencidas'] ? ' <span class="v">(' . (int)$l['t']['vencidas'] . ')</span>' : '' ?></td><td class="num small"><?= $l['fez'] === null ? '—' : (int)$l['fez'] ?></td><td class="small"><?= P::e($fmt($u['ULTIMO_ACESSO'] ?? null)) ?></td></tr>
<?php endforeach; if (!$linhas): ?><tr><td colspan="<?= count($pilares) + 5 ?>" class="text-muted"><?= P::e(P::L('Ninguém na sua equipe.', 'No one in your team.', 'Nadie en su equipo.')) ?></td></tr><?php endif; ?></tbody></table></div>
<p class="small text-muted mt-1"><?= P::e(P::L('Na célula: total de pendências, (vencidas) em vermelho e +a vencer em 30 dias.', 'In each cell: total pending, (overdue) in red and +due within 30 days.', 'En la celda: total de pendientes, (vencidos) en rojo y +por vencer en 30 días.')) ?></p>
<script>
document.querySelector('[data-pb-filtro]').addEventListener('change', function () { var v = this.value; document.querySelectorAll('tr[data-flag]').forEach(function (tr) { tr.classList.toggle('d-none', v === 'vermelha' ? tr.dataset.flag !== 'vermelha' : v === 'amarela' ? tr.dataset.flag === 'verde' : false); }); });
</script>
<?php P::rodape(); ?>
