<?php
declare(strict_types=1);

/*
 * Administração › E-mails enviados (migração 333, 22/10/2026): fila de e-mails da empresa (email_fila), enviada pelo serviço
 * do Agendador do Windows (scripts/email-worker.php, Administração › Serviços › E-mails). Mostra os últimos 2.000: pendentes,
 * enviados e com erro (5 tentativas). "Reenviar" põe um e-mail com erro de volta na fila (permissão Editar).
 * Leitura: vw_email_fila (sp_crud_select). Gravação: api/nc_administracao.php (sp_email_reenviar).
 */

require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeAdministracao.php';
require_once dirname(__DIR__) . '/classes/SmtpCliente.php';
require_once dirname(__DIR__) . '/components/grid_local.php';

$pdo = TransactionSecurity::connect(NaoConformidadeAdministracao::MENU_EMAILS);
$perm = PermissaoTransacao::obter(NaoConformidadeAdministracao::MENU_EMAILS);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$A = NaoConformidadeAdministracao::class;
$idioma = IdiomaService::idioma();
$aviso = SmtpCliente::configurado(GreenPortalDatabase::env()) ? '' : 'Servidor de e-mail (SMTP) ainda não configurado no servidor: os e-mails ficam pendentes até a configuração.';
$rows = [];
$situacao = ['PENDENTE' => 'Pendente', 'PROCESSANDO' => 'Enviando', 'CONCLUIDA' => 'Enviado', 'ERRO' => 'Erro', 'CANCELADA' => 'Cancelado'];
$origem = ['NAO_CONFORMIDADE' => 'Não conformidade'];
try {
    $fila = $A::ler($pdo, 'vw_email_fila', [], 6000);
    usort($fila, static fn($a, $b) => (int)$b['codemail'] <=> (int)$a['codemail']);
    foreach (array_slice($fila, 0, 2000) as $m) {
        $st = (string)$m['status'];
        $badge = ['PENDENTE' => 'text-bg-light', 'PROCESSANDO' => 'text-bg-info', 'CONCLUIDA' => 'text-bg-success', 'ERRO' => 'text-bg-danger', 'CANCELADA' => 'text-bg-secondary'][$st] ?? 'text-bg-light';
        $para = trim((string)($m['nome_destinatario'] ?? '')) !== '' ? $m['nome_destinatario'] . ' <' . $m['destinatario'] . '>' : (string)$m['destinatario'];
        // c537: Reenviar fica na barra (habilitado quando a linha selecionada está com erro)
        $acoes = $st === 'ERRO' && !empty($perm['pode_editar']) ? greenPortalGridLocalLinha('<button type="button" data-linha-acao="reenviar" data-reenviar="' . (int)$m['codemail'] . '"></button>') : '';
        $rows[] = ['cells' => [
            'CRIADO_EM' => ['text' => $A::data($m['criado_em'], true), 'sort' => $A::ordenacao($m['criado_em']), 'html' => $e($A::data($m['criado_em'], true)) . $acoes],
            'PARA' => ['text' => $para, 'title' => $para],
            'ASSUNTO' => ['text' => (string)$m['assunto'], 'title' => (string)$m['assunto']],
            'ORIGEM' => ['text' => $origem[(string)$m['origem']] ?? (string)$m['origem']],
            'STATUS' => ['text' => $situacao[$st] ?? $st, 'html' => '<span class="badge ' . $badge . '">' . $e($situacao[$st] ?? $st) . '</span>'],
            'TENTATIVAS' => ['text' => (string)(int)$m['tentativas']],
            'ENVIADO_EM' => ['text' => $A::data($m['enviado_em'] ?? '', true), 'sort' => $A::ordenacao($m['enviado_em'] ?? '')],
            'ERRO' => ['text' => (string)($m['ultimo_erro'] ?? ''), 'title' => (string)($m['ultimo_erro'] ?? '')],
        ]];
    }
} catch (Throwable $ex) {
    $aviso = 'Fila de e-mails não disponível neste banco: aplique a migração 333 (scripts/aplicar-migracao-333-nc-administracao.php).';
    error_log('[emails enviados] ' . $ex->getMessage());
}
?>
<!doctype html><html lang="<?= $idioma === 'pt' ? 'pt-BR' : $e($idioma) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>E-mails enviados</title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php greenPortalGridLocalHead(); ?>
<style>.emails-grid-fragment th[data-column="CRIADO_EM"],.emails-grid-fragment th[data-column="ENVIADO_EM"]{width:120px}.emails-grid-fragment th[data-column="PARA"]{width:24%}
.emails-grid-fragment th[data-column="STATUS"]{width:100px}.emails-grid-fragment th[data-column="TENTATIVAS"]{width:80px}.emails-grid-fragment th[data-column="ORIGEM"]{width:130px}</style>
</head><body data-transacao="administracao_emailsenviados" data-csrf="<?= $e($A::csrf()) ?>" data-api="api/nc_administracao.php">
<div class="px-2 pt-2"><div data-nc-aviso hidden></div></div>
<?php greenPortalGridLocal([
    'transaction' => 'administracao_emailsenviados', 'fragment_class' => 'emails-grid-fragment', 'id' => 'emailsGrid',
    'columns' => [
        'CRIADO_EM' => ['label' => 'Criado em', 'filter' => 'none'],
        'PARA' => ['label' => 'Para'],
        'ASSUNTO' => ['label' => 'Assunto'],
        'ORIGEM' => ['label' => 'Origem', 'filter' => 'select'],
        'STATUS' => ['label' => 'Situação', 'filter' => 'select'],
        'TENTATIVAS' => ['label' => 'Tentativas', 'filter' => 'none'],
        'ENVIADO_EM' => ['label' => 'Enviado em', 'filter' => 'none'],
        'ERRO' => ['label' => 'Último erro'],
    ],
    'cell_boxes' => true, 'buttons' => ['columns' => true],
    'extras' => !empty($perm['pode_editar']) ? '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-grid-acao-linha="reenviar" disabled title="Reenviar o e-mail selecionado (só os com erro)" aria-label="Reenviar o e-mail selecionado"><i class="bi bi-arrow-repeat"></i></button>' : '',
    'rows' => $rows, 'default_sort' => ['CRIADO_EM', 'desc'],
    'empty' => 'Nenhum e-mail na fila.',
    'notice' => $aviso,
]); ?>
<?php greenPortalGridLocalScripts(); ?>
<script src="../assets/js/nc-administracao.js?v=1"></script>
<script>
document.addEventListener('click', function (ev) {
  var b = ev.target.closest('[data-reenviar]'); if (!b) return;
  b.disabled = true;
  GPNcAdmin.gravar('email_reenviar', { codigo: Number(b.getAttribute('data-reenviar')) }).then(function () { window.location.reload(); })
    .catch(function (e) { b.disabled = false; GPNcAdmin.aviso(e.message, 'danger'); });
});
</script>
</body></html>
