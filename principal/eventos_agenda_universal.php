<?php
declare(strict_types=1);

/*
 * Home › Agenda: tela de eventos.php?modo=agenda (os itens vêm de $agendaItems, montados em eventos.php).
 * c454: padrão de grid do portal (components/grid_local.php + assets/js/grid-local.js) — barra de ícones e Ajuda, busca,
 * filtro e ordenação por coluna, paginação na barra, datas no formato do idioma com sinal de prazo, botão Abrir em popup.
 * c479: Abrir deixou de ser coluna — é um botão da barra (selecione a linha; duplo clique também abre) — e as células seguem o
 * formato dos campos do grid padrão (caixa com borda, uma linha, texto completo na dica); botão Colunas.
 */

require_once dirname(__DIR__) . '/components/grid_local.php';
// Situações gravadas como código (ATIVO, PENDENTE...): só o texto exibido segue o idioma.
$agendaStatus = static function (string $codigo) use ($labels): string {
    $mapa = ['ATIVO' => ['pt' => 'Agendado', 'en' => 'Scheduled', 'es' => 'Programado'], 'PENDENTE' => ['pt' => 'Pendente', 'en' => 'Pending', 'es' => 'Pendiente'], 'PROCESSANDO' => ['pt' => 'Processando', 'en' => 'Processing', 'es' => 'Procesando'], 'CONCLUIDO' => ['pt' => 'Concluído', 'en' => 'Completed', 'es' => 'Concluido'], 'CANCELADO' => ['pt' => 'Cancelado', 'en' => 'Cancelled', 'es' => 'Cancelado'], 'ATRASADO' => ['pt' => 'Atrasado', 'en' => 'Overdue', 'es' => 'Atrasado']];
    $chave = strtoupper(trim($codigo));
    return isset($mapa[$chave]) ? $labels($mapa[$chave]) : $codigo;
};
// Migração 345: concluir tarefa (só aparece com a procedure sp_tarefa_concluir no banco)
$podeConcluir = false;
try { $sc = $pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_tarefa_concluir'"); $podeConcluir = (int)$sc->fetchColumn() > 0; $sc->closeCursor(); } catch (Throwable) { $podeConcluir = false; }
if (empty($_SESSION['tarefa_concluir_csrf'])) $_SESSION['tarefa_concluir_csrf'] = bin2hex(random_bytes(16));
$rotuloConcluir = $labels(['pt' => 'Concluir a tarefa selecionada (quem aprova o pilar recebe o aviso para conferir)', 'en' => 'Complete the selected task (the pillar approvers are notified)', 'es' => 'Concluir la tarea seleccionada (quien aprueba el pilar recibe el aviso)']);
$rows = [];
foreach ($agendaItems as $item) {
    $d = $item['date'] ?? null;
    $texto = $d ? MinhasPendencias::formatar($d, !empty($item['time']) && $d->format('H:i') !== '00:00') : '';
    $rows[] = ['cells' => [
        'DATA' => ['text' => $texto, 'html' => greenPortalGridLocalPrazo($texto, MinhasPendencias::sinal($d)), 'sort' => $d ? $d->format('Y-m-d H:i:s') : '9999-12-31'],
        'TIPO' => ['text' => (string)$item['type']],
        'ACAO' => ['text' => (string)$item['title'], 'title' => (string)$item['title']],
        'DESCRICAO' => ['text' => (string)$item['description']],
        'STATUS' => ['text' => $agendaStatus((string)$item['status']), 'html' => $e($agendaStatus((string)$item['status']))
            . (!empty($item['codtarefa']) && $podeConcluir ? greenPortalGridLocalLinha('<button type="button" data-linha-acao="concluir" data-codtarefa="' . (int)$item['codtarefa'] . '"></button>') : '')],
    ], 'open' => ['url' => (string)$item['url'], 'title' => (string)$item['type'] . ' - ' . (string)$item['title']]];
}
?><!doctype html>
<html lang="<?= $e($locale) ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $e($agendaTitle) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php greenPortalGridLocalHead(); ?>
<style>.home-agenda-grid-fragment th[data-column="DATA"]{width:140px}.home-agenda-grid-fragment th[data-column="TIPO"]{width:170px}.home-agenda-grid-fragment th[data-column="STATUS"]{width:120px}.home-agenda-grid-fragment th[data-column="ACAO"]{width:32%}</style>
</head>
<body data-transacao="home_agenda">
<?php greenPortalGridLocal([
    'transaction' => 'home_agenda', 'fragment_class' => 'home-agenda-grid-fragment', 'id' => 'homeAgendaGrid',
    'columns' => [
        'DATA' => ['label' => $labels(['pt' => 'Data', 'en' => 'Date', 'es' => 'Fecha'])],
        'TIPO' => ['label' => $labels(['pt' => 'Tipo', 'en' => 'Type', 'es' => 'Tipo']), 'filter' => 'select'],
        'ACAO' => ['label' => $labels(['pt' => 'Ação', 'en' => 'Action', 'es' => 'Acción'])],
        'DESCRICAO' => ['label' => $labels(['pt' => 'Descrição / motivo', 'en' => 'Description / reason', 'es' => 'Descripción / motivo'])],
        'STATUS' => ['label' => $labels(['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']), 'filter' => 'select'],
    ],
    // c479: Abrir como botão na barra (seleciona a linha e abre; duplo clique também abre), células no formato do grid padrão e Colunas.
    'open_button' => true, 'open_in_tab' => true, 'cell_boxes' => true, 'buttons' => ['columns' => true],
    'extras' => $podeConcluir ? '<button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-grid-acao-linha="concluir" disabled title="' . $e($rotuloConcluir) . '" aria-label="' . $e($rotuloConcluir) . '"><i class="bi bi-check2-square"></i></button>' : '',
    'rows' => $rows, 'default_sort' => ['DATA', 'asc'], 'empty' => $emptyLabel,
    'notice' => $agendaMissing ? strtr($labels(['pt' => 'Parte da agenda não pôde ser lida: execute a(s) migração(ões) {m}.', 'en' => 'Part of the agenda could not be read: run migration(s) {m}.', 'es' => 'Parte de la agenda no pudo leerse: ejecute la(s) migración(es) {m}.']), ['{m}' => implode(', ', $agendaMissing)]) : '',
]); ?>
<?php greenPortalGridLocalScripts(); ?>
<?php if ($podeConcluir): ?>
<script>
(function () {
    // Migração 345: concluir a tarefa (api/tarefa_concluir.php); confirmação no popup de mensagem do portal
    var host = window.top || window, csrf = <?= json_encode($_SESSION['tarefa_concluir_csrf']) ?>;
    var txt = <?= json_encode(['titulo' => $labels(['pt' => 'Concluir tarefa', 'en' => 'Complete task', 'es' => 'Concluir tarea']), 'pergunta' => $labels(['pt' => 'Confirma a conclusão desta tarefa?', 'en' => 'Complete this task?', 'es' => '¿Confirma la conclusión de esta tarea?']), 'erro' => $labels(['pt' => 'Não foi possível concluir a tarefa.', 'en' => 'The task could not be completed.', 'es' => 'No fue posible concluir la tarea.'])], JSON_UNESCAPED_UNICODE) ?>;
    function confirmar(fn) {
        if (host.GreenPortalPopups && typeof host.GreenPortalPopups.mensagem === 'function' && host.document.getElementById('greenPortalMensagemModal')) host.GreenPortalPopups.mensagem({ titulo: txt.titulo, mensagem: txt.pergunta, icone: 'bi-check2-square', confirmar: fn });
        else if (window.confirm(txt.pergunta)) fn();
    }
    function aviso(m, ok) { if (host.GreenPortalPopups && typeof host.GreenPortalPopups.mensagem === 'function' && host.document.getElementById('greenPortalMensagemModal')) host.GreenPortalPopups.mensagem({ titulo: txt.titulo, mensagem: m, icone: ok ? 'bi-check2-circle' : 'bi-exclamation-triangle' }); }
    document.addEventListener('click', function (ev) {
        var b = ev.target.closest ? ev.target.closest('[data-linha-acao="concluir"]') : null;
        if (!b) return;
        ev.preventDefault();
        var id = b.getAttribute('data-codtarefa');
        confirmar(function () {
            var f = new FormData(); f.append('codtarefa', id); f.append('csrf', csrf);
            fetch('api/tarefa_concluir.php', { method: 'POST', body: f, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
                if (!d || !d.ok) { aviso((d && d.message) || txt.erro, false); return; }
                aviso(d.message, true);
                window.setTimeout(function () { window.location.reload(); }, 900);
            }).catch(function () { aviso(txt.erro, false); });
        });
    });
}());
</script>
<?php endif; ?>
</body>
</html>
