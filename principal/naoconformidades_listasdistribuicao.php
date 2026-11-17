<?php
declare(strict_types=1);

/*
 * Não Conformidades › Administração › Listas de distribuição (migração 333, 22/10/2026 — Administração de NC, fase 1).
 * Grid das listas com quantas pessoas têm (usuários do portal e participantes externos), quantas estão desativadas e em quantas
 * NCs a lista foi usada. Abrir (ou duplo clique) mostra a lista em popup (naoconformidades_listadistribuicao.php): membros,
 * categorias e, nas listas do greenportal, inclusão/retirada de pessoas.
 * Até a virada as listas do sistema anterior (código abaixo de 50001) só são consultadas; "Copiar como nova lista" cria uma do greenportal.
 * Leitura: vw_nc_listas (sp_crud_select).
 */

require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeAdministracao.php';
require_once dirname(__DIR__) . '/components/grid_local.php';

$pdo = TransactionSecurity::connect(NaoConformidadeAdministracao::MENU_LISTAS);
$perm = PermissaoTransacao::obter(NaoConformidadeAdministracao::MENU_LISTAS);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$idioma = IdiomaService::idioma();
$aviso = '';
$rows = [];
try {
    $listas = NaoConformidadeAdministracao::ler($pdo, 'vw_nc_listas');
    foreach ($listas as $l) {
        $cod = (int)$l['CODLISTA'];
        $legado = $cod < NaoConformidadeAdministracao::FAIXA_LISTA;
        $inativos = (int)$l['QTD_INATIVOS'];
        $rows[] = [
            'cells' => [
                'CODLISTA' => ['text' => (string)$cod, 'sort' => str_pad((string)$cod, 9, '0', STR_PAD_LEFT)],
                'DSCLISTA' => ['text' => (string)$l['DSCLISTA'], 'title' => (string)$l['DSCLISTA']],
                'CATEGORIAS' => ['text' => (string)($l['CATEGORIAS'] ?? ''), 'title' => (string)($l['CATEGORIAS'] ?? '')],
                'QTD_MEMBROS' => ['text' => (string)(int)$l['QTD_MEMBROS'], 'sort' => str_pad((string)(int)$l['QTD_MEMBROS'], 6, '0', STR_PAD_LEFT)],
                'QTD_PARTICIPANTES' => ['text' => (string)(int)$l['QTD_PARTICIPANTES'], 'sort' => str_pad((string)(int)$l['QTD_PARTICIPANTES'], 6, '0', STR_PAD_LEFT)],
                'QTD_INATIVOS' => ['text' => (string)$inativos, 'html' => $inativos > 0 ? '<span class="badge text-bg-warning" title="Pessoas desativadas ou não cadastradas: não recebem os avisos">' . $inativos . '</span>' : '0', 'sort' => str_pad((string)$inativos, 6, '0', STR_PAD_LEFT)],
                'QTD_NCS' => ['text' => (string)(int)$l['QTD_NCS'], 'sort' => str_pad((string)(int)$l['QTD_NCS'], 7, '0', STR_PAD_LEFT), 'title' => (int)$l['QTD_ABERTAS'] . ' em aberto'],
                'ULTIMO_USO' => ['text' => NaoConformidadeAdministracao::data($l['ULTIMO_USO'] ?? ''), 'sort' => NaoConformidadeAdministracao::ordenacao($l['ULTIMO_USO'] ?? '')],
                'ORIGEM' => ['text' => $legado ? 'Sistema anterior' : 'Greenportal', 'title' => $legado ? 'Só consulta até a virada; para mudar, abra a lista e use "Copiar como nova lista"' : 'Lista criada no greenportal'],
            ],
            'attrs' => 'data-nc-url="naoconformidades_listadistribuicao.php?codigo=' . $cod . '" data-nc-titulo="' . $e('Lista de distribuição ' . $cod . ' — ' . $l['DSCLISTA']) . '"',
        ];
    }
} catch (Throwable $ex) {
    $aviso = 'Listas não disponíveis neste banco: aplique a migração 333 (scripts/aplicar-migracao-333-nc-administracao.php).';
    error_log('[nc listas] ' . $ex->getMessage());
}
$extras = '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-nc-abrir-selecionado disabled title="Abrir a lista selecionada" aria-label="Abrir a lista selecionada"><i class="bi bi-box-arrow-up-right"></i></button>';
if (!empty($perm['pode_incluir'])) $extras = '<button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-nc-abrir="naoconformidades_listadistribuicao.php?codigo=0" data-nc-titulo="Nova lista de distribuição" title="Nova lista" aria-label="Nova lista"><i class="bi bi-plus-lg"></i></button>' . $extras;
?>
<!doctype html><html lang="<?= $idioma === 'pt' ? 'pt-BR' : $e($idioma) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Listas de distribuição</title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php greenPortalGridLocalHead(); ?>
<style>.nc-listas-grid-fragment th[data-column="CODLISTA"]{width:80px}.nc-listas-grid-fragment th[data-column="DSCLISTA"]{width:30%}
.nc-listas-grid-fragment th[data-column="QTD_MEMBROS"],.nc-listas-grid-fragment th[data-column="QTD_PARTICIPANTES"],.nc-listas-grid-fragment th[data-column="QTD_INATIVOS"],.nc-listas-grid-fragment th[data-column="QTD_NCS"]{width:84px}
.nc-listas-grid-fragment th[data-column="ULTIMO_USO"]{width:110px}.nc-listas-grid-fragment th[data-column="ORIGEM"]{width:140px}</style>
</head><body data-transacao="naoconformidades_listasdistribuicao">
<?php greenPortalGridLocal([
    'transaction' => 'naoconformidades_listasdistribuicao', 'fragment_class' => 'nc-listas-grid-fragment', 'id' => 'ncListasGrid',
    'columns' => [
        'CODLISTA' => ['label' => 'Código'],
        'DSCLISTA' => ['label' => 'Lista'],
        'CATEGORIAS' => ['label' => 'Categorias'],
        'QTD_MEMBROS' => ['label' => 'Pessoas', 'filter' => 'none'],
        'QTD_PARTICIPANTES' => ['label' => 'Externos', 'filter' => 'none'],
        'QTD_INATIVOS' => ['label' => 'Inativos', 'filter' => 'none'],
        'QTD_NCS' => ['label' => 'NCs', 'filter' => 'none'],
        'ULTIMO_USO' => ['label' => 'Último uso', 'filter' => 'none'],
        'ORIGEM' => ['label' => 'Origem', 'filter' => 'select'],
    ],
    'cell_boxes' => true, 'buttons' => ['columns' => true], 'extras' => $extras,
    'rows' => $rows, 'default_sort' => ['DSCLISTA', 'asc'],
    'empty' => 'Nenhuma lista de distribuição.',
    // c537: sem faixa de aviso permanente (padrão do grid); a regra do sistema anterior aparece na própria lista e na dica da coluna Origem
    'notice' => $aviso,
]); ?>
<?php greenPortalGridLocalScripts(); ?>
<script src="../assets/js/nc-administracao.js?v=1"></script>
</body></html>
