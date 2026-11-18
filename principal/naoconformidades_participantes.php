<?php
declare(strict_types=1);

/*
 * Não Conformidades › Administração › Participantes externos (migração 333, 22/10/2026 — decisão do usuário: "continuam,
 * recebendo por e-mail"). Pessoas sem login colocadas nas listas de distribuição; recebem por e-mail o aviso que os usuários
 * recebem em Home › Alertas. Os do sistema anterior vêm pelo sincronismo (nome e e-mail só lá; aqui só Ativo); os novos são
 * incluídos aqui. Abrir (ou duplo clique) mostra o participante em popup (naoconformidades_participante.php).
 * Leitura: vw_nc_participantes (sp_crud_select).
 */

require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeAdministracao.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeTextos.php';
require_once dirname(__DIR__) . '/components/grid_local.php';

$pdo = TransactionSecurity::connect(NaoConformidadeAdministracao::MENU_PARTICIPANTES);
$perm = PermissaoTransacao::obter(NaoConformidadeAdministracao::MENU_PARTICIPANTES);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$idioma = IdiomaService::idioma();
$aviso = '';
$rows = [];
try {
    foreach (NaoConformidadeAdministracao::ler($pdo, 'vw_nc_participantes') as $p) {
        $cod = (int)$p['CODUSUARIO'];
        $ativo = (int)$p['ATIVO'] === 1;
        $rows[] = [
            'cells' => [
                'CODUSUARIO' => ['text' => (string)$cod, 'sort' => str_pad((string)$cod, 10, '0', STR_PAD_LEFT)],
                'NOME' => ['text' => (string)$p['NOME'], 'title' => (string)$p['NOME']],
                'EMAIL' => ['text' => (string)$p['EMAIL'], 'title' => (string)$p['EMAIL']],
                'ATIVO' => ['text' => $ativo ? ncT('ld.ativo') : ncT('ld.desativado'), 'html' => $ativo ? $e(ncT('ld.ativo')) : '<span class="badge text-bg-warning">' . $e(ncT('ld.desativado')) . '</span>'],
                'QTD_LISTAS' => ['text' => (string)(int)$p['QTD_LISTAS'], 'sort' => str_pad((string)(int)$p['QTD_LISTAS'], 5, '0', STR_PAD_LEFT)],
                'ORIGEM' => ['text' => (string)$p['ORIGEM'] === 'LEGADO' ? ncT('cor.sistema_anterior') : 'Greenportal'],
            ],
            'attrs' => 'data-nc-url="naoconformidades_participante.php?codigo=' . $cod . '" data-nc-titulo="' . $e(ncT('pa.titulo_nome', ['nome' => $p['NOME']])) . '"',
        ];
    }
} catch (Throwable $ex) {
    $aviso = ncT('pa.sem_migracao2');
    error_log('[nc participantes] ' . $ex->getMessage());
}
$extras = '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-nc-abrir-selecionado disabled title="' . $e(ncT('pa.abrir_sel')) . '" aria-label="' . $e(ncT('pa.abrir_sel')) . '"><i class="bi bi-box-arrow-up-right"></i></button>';
if (!empty($perm['pode_incluir'])) $extras = '<button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-nc-abrir="naoconformidades_participante.php?codigo=0" data-nc-titulo="' . $e(ncT('pa.novo')) . '" title="' . $e(ncT('pa.novo_curto')) . '" aria-label="' . $e(ncT('pa.novo_curto')) . '"><i class="bi bi-plus-lg"></i></button>' . $extras;
?>
<!doctype html><html lang="<?= $idioma === 'pt' ? 'pt-BR' : $e($idioma) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e(ncT('pa.titulo')) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php greenPortalGridLocalHead(); ?>
<style>.nc-participantes-grid-fragment th[data-column="CODUSUARIO"]{width:100px}.nc-participantes-grid-fragment th[data-column="NOME"]{width:32%}
.nc-participantes-grid-fragment th[data-column="ATIVO"]{width:120px}.nc-participantes-grid-fragment th[data-column="QTD_LISTAS"]{width:80px}.nc-participantes-grid-fragment th[data-column="ORIGEM"]{width:140px}</style>
</head><body data-transacao="naoconformidades_participantes">
<?php greenPortalGridLocal([
    'transaction' => 'naoconformidades_participantes', 'fragment_class' => 'nc-participantes-grid-fragment', 'id' => 'ncParticipantesGrid',
    'columns' => [
        'CODUSUARIO' => ['label' => ncT('col.codigo')],
        'NOME' => ['label' => ncT('ld.nome')],
        'EMAIL' => ['label' => ncT('col.email')],
        'ATIVO' => ['label' => ncT('oc.situacao'), 'filter' => 'select'],
        'QTD_LISTAS' => ['label' => ncT('col.listas'), 'filter' => 'none'],
        'ORIGEM' => ['label' => ncT('col.origem'), 'filter' => 'select'],
    ],
    'cell_boxes' => true, 'buttons' => ['columns' => true], 'extras' => $extras,
    'rows' => $rows, 'default_sort' => ['NOME', 'asc'],
    'empty' => ncT('pa.nenhum'),
    'notice' => $aviso,
]); ?>
<?php greenPortalGridLocalScripts(); ?>
<?= NaoConformidadeTextos::script() ?>
<script src="../assets/js/nc-administracao.js?v=2"></script>
</body></html>
