<?php
declare(strict_types=1);

/*
 * Home › Minhas pendências (aba da Home, home.php).
 * Pendências dos pilares em que o usuário é o responsável (direto ou por grupo), com o motivo de cada uma, no padrão de grid
 * do portal (components/grid_local.php + assets/js/grid-local.js): barra de ícones e Ajuda, busca, filtro e ordenação por coluna,
 * paginação na barra, datas no formato do idioma com sinal de prazo.
 * c453: coluna "Motivo"; c454: montagem em classes/MinhasPendencias.php (também usada pela Agenda), filtro exato do usuário.
 * c487: Abrir deixou de ser coluna (botão da barra, habilitado ao selecionar a linha; duplo clique abre), células no formato
 * dos campos do grid padrão e botão Colunas — mesmo padrão da Agenda (eventos.php?modo=agenda).
 */

require_once dirname(__DIR__) . '/classes/IdiomaService.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) { http_response_code(401); exit(IdiomaService::t('home.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.'])); }
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/HomologacaoFornecedorAcesso.php';
require_once dirname(__DIR__) . '/classes/MinhasPendencias.php';
require_once dirname(__DIR__) . '/components/grid_local.php';
$pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), (string)$_SESSION['tenant']['banco_dados'], 'GPT');
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { http_response_code(401); exit(IdiomaService::t('home.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.'])); }

$idioma = IdiomaService::idioma();
$t = static fn(string $chave, array $padrao): string => IdiomaService::t($chave, $padrao);
$pendencias = new MinhasPendencias($pdo, (int)($_SESSION['usuario']['codusuario'] ?? 0), HomologacaoFornecedorAcesso::externo());
$rows = [];
foreach ($pendencias->itens() as $item) {
    $rows[] = ['cells' => [
        'PILAR' => ['text' => $item['pilar']],
        'PENDENCIA' => ['text' => $item['titulo'], 'title' => $item['titulo']],
        'MOTIVO' => ['text' => $item['motivo']],
        'PRAZO' => ['text' => MinhasPendencias::formatar($item['prazo']), 'html' => greenPortalGridLocalPrazo(MinhasPendencias::formatar($item['prazo']), MinhasPendencias::sinal($item['prazo'])), 'sort' => $item['prazo']?->format('Y-m-d') ?? '9999-12-31'],
    ], 'open' => ['url' => (string)$item['url'], 'title' => $item['pilar'] . ' - ' . $item['titulo']]];
}
$faltando = $pendencias->faltando();
$migracoes = array_values(array_unique(array_map(static fn(string $v): string => MinhasPendencias::VIEWS_MIGRACAO[$v], $faltando)));
sort($migracoes);
?>
<!doctype html><html lang="<?= $idioma === 'pt' ? 'pt-BR' : htmlspecialchars($idioma, ENT_QUOTES, 'UTF-8') ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php greenPortalGridLocalHead(); ?>
<style>.home-pendencias-grid-fragment th[data-column="PILAR"]{width:170px}.home-pendencias-grid-fragment th[data-column="PRAZO"]{width:130px}.home-pendencias-grid-fragment th[data-column="PENDENCIA"]{width:36%}</style>
</head><body data-transacao="home_pendencias">
<?php greenPortalGridLocal([
    'transaction' => 'home_pendencias', 'fragment_class' => 'home-pendencias-grid-fragment', 'id' => 'homePendenciasGrid',
    'columns' => [
        'PILAR' => ['label' => $t('home.coluna.pilar', ['pt' => 'Pilar', 'en' => 'Pillar', 'es' => 'Pilar']), 'filter' => 'select'],
        'PENDENCIA' => ['label' => $t('home.coluna.pendencia', ['pt' => 'Pendência', 'en' => 'Pending item', 'es' => 'Pendiente'])],
        'MOTIVO' => ['label' => $t('home.coluna.motivo', ['pt' => 'Motivo / o que fazer', 'en' => 'Reason / what to do', 'es' => 'Motivo / qué hacer'])],
        'PRAZO' => ['label' => $t('home.coluna.prazo', ['pt' => 'Prazo', 'en' => 'Due date', 'es' => 'Plazo'])],
    ],
    // c487: Abrir como botão da barra (selecione a linha; duplo clique também abre), células em caixa e Colunas — como a Agenda (c479).
    'open_button' => true, 'open_in_tab' => true, 'cell_boxes' => true, 'buttons' => ['columns' => true],
    'rows' => $rows, 'default_sort' => ['PRAZO', 'asc'],
    'empty' => $t('home.pendencias.vazio', ['pt' => 'Nenhuma pendência atribuída ao usuário.', 'en' => 'No pending items assigned to the user.', 'es' => 'Ningún pendiente asignado al usuario.']),
    'notice' => $migracoes ? strtr($t('home.pendencias.migracao', ['pt' => 'Parte das pendências não pôde ser lida: execute a(s) migração(ões) {migracoes} (scripts/aplicar-migracao-...).', 'en' => 'Some pending items could not be read: run migration(s) {migracoes}.', 'es' => 'Parte de los pendientes no pudo leerse: ejecute la(s) migración(es) {migracoes}.']), ['{migracoes}' => implode(', ', $migracoes)]) : '',
]); ?>
<?php greenPortalGridLocalScripts(); ?>
</body></html>
