<?php
declare(strict_types=1);

/*
 * Módulo Legal › Reavaliação por mudança de lei (migração 346; LEG-006 da apresentação Atlas).
 * Lista as alterações e revogações de leis recebidas da base de legislação depois da ativação, com o que o sistema encontrou
 * na empresa: os requisitos da própria lei e os itens parecidos pelo sentido (classes/LeiReavaliacao.php), a tarefa "Revisar"
 * e quem a recebeu. Abrir (ou duplo clique) mostra os itens da alteração; cada item abre o cadastro.
 * ?codreav=N (link do alerta e da tarefa): já abre os itens daquela alteração.
 * Leitura só por views (vw_lei_reavaliacao, vw_lei_reavaliacao_itens) com sp_crud_select.
 */
require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/LeiReavaliacao.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/components/grid_local.php';

$pdo = TransactionSecurity::connect(LeiReavaliacao::MENU);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$T = static fn(string $c, array $p): string => IdiomaService::t('modulolegal_reavaliacao_leis.' . $c, $p);
$ler = static function (string $view, array $filtros, int $limite = 1000) use ($pdo): ?array {
    try { $s = $pdo->prepare('CALL sp_crud_select(?, ?, NULL, ?, 0)'); $s->execute([$view, json_encode($filtros ?: new stdClass()), $limite]); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; }
    catch (Throwable) { return null; }
};
$data = static function (?string $v): string { if (!$v || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) return ''; return IdiomaService::idioma() === 'en' ? "$m[2]/$m[3]/$m[1]" : "$m[3]/$m[2]/$m[1]"; };
$rotTipo = ['requisitos' => $T('t.requisito', ['pt' => 'Requisito legal', 'en' => 'Legal requirement', 'es' => 'Requisito legal']), 'licencas' => $T('t.licenca', ['pt' => 'Licença', 'en' => 'License', 'es' => 'Licencia']),
    'controles' => $T('t.controle', ['pt' => 'Controle', 'en' => 'Control', 'es' => 'Control']), 'treinamentos' => $T('t.treinamento', ['pt' => 'Treinamento', 'en' => 'Training', 'es' => 'Capacitación']),
    'infraestrutura' => $T('t.infra', ['pt' => 'Infraestrutura', 'en' => 'Infrastructure', 'es' => 'Infraestructura'])];
$rotStatus = ['PENDENTE' => $T('s.pendente', ['pt' => 'Tarefa pendente', 'en' => 'Task pending', 'es' => 'Tarea pendiente']), 'PROCESSANDO' => $T('s.pendente', ['pt' => 'Tarefa pendente', 'en' => 'Task pending', 'es' => 'Tarea pendiente']),
    'CONCLUIDA' => $T('s.concluida', ['pt' => 'Revisada (tarefa concluída)', 'en' => 'Reviewed (task completed)', 'es' => 'Revisada (tarea concluida)']), 'CANCELADA' => $T('s.cancelada', ['pt' => 'Tarefa cancelada', 'en' => 'Task cancelled', 'es' => 'Tarea cancelada'])];
$codreav = max(0, (int)($_GET['codreav'] ?? 0));
$lista = $ler('vw_lei_reavaliacao', []);
$titulo = $T('titulo', ['pt' => 'Reavaliação por mudança de lei', 'en' => 'Review on law changes', 'es' => 'Revisión por cambio de ley']);
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $e($titulo) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php greenPortalGridLocalHead(); ?>
</head>
<body data-transacao="modulolegal_reavaliacao_leis">
<?php if ($lista === null): ?>
<div class="alert alert-warning m-3"><?= $e($T('sem_migracao', ['pt' => 'A reavaliação por mudança de lei ainda não está disponível nesta empresa (migração 346).', 'en' => 'Not available for this company yet (migration 346).', 'es' => 'Aún no disponible en esta empresa (migración 346).'])) ?></div>
<?php elseif ($codreav > 0): ?>
<?php
    $r = null; foreach ($lista as $x) if ((int)$x['CODREAV'] === $codreav) $r = $x;
    $itens = $r ? array_values(array_filter($ler('vw_lei_reavaliacao_itens', ['CODREAV' => ['value' => (string)$codreav, 'exact' => true]]) ?? [], static fn($i) => (int)$i['CODREAV'] === $codreav)) : [];
    usort($itens, static fn($a, $b) => [$a['MOTIVO'] !== 'REQUISITO', -(float)$a['SEMELHANCA']] <=> [$b['MOTIVO'] !== 'REQUISITO', -(float)$b['SEMELHANCA']]);
    $rows = [];
    foreach ($itens as $i) {
        $pag = preg_replace('#^/?principal/#', '', (string)$i['PAGINA']);
        $url = $pag !== '' ? $pag . '?filtros=' . rawurlencode((string)($i['FILTROS'] ?: '{}')) : '';
        $sem = $i['MOTIVO'] === 'REQUISITO' ? $T('m.requisito', ['pt' => 'Requisito da lei alterada', 'en' => 'Requirement of the changed law', 'es' => 'Requisito de la ley modificada'])
            : strtr($T('m.sentido', ['pt' => 'Parecido pelo sentido ({n}%)', 'en' => 'Similar in meaning ({n}%)', 'es' => 'Parecido por el sentido ({n}%)']), ['{n}' => number_format((float)$i['SEMELHANCA'] * 100, 0)]);
        $rows[] = ['cells' => ['TIPO' => ['text' => $rotTipo[$i['TIPO']] ?? $i['TIPO']], 'NOME' => ['text' => (string)$i['NOME'], 'title' => (string)$i['NOME']], 'UNIDADE' => ['text' => (string)$i['UNIDADE']],
            'MOTIVO' => ['text' => $sem, 'sort' => $i['MOTIVO'] === 'REQUISITO' ? '2' : number_format((float)$i['SEMELHANCA'], 3)]], 'open' => ['url' => $url, 'title' => (string)$i['NOME']]];
    }
?>
<div class="p-2">
<?php if (!$r): ?><div class="alert alert-warning py-2"><?= $e($T('nao_encontrada', ['pt' => 'Reavaliação não encontrada.', 'en' => 'Review not found.', 'es' => 'Revisión no encontrada.'])) ?></div>
<?php else: ?>
<div class="mb-2 small"><strong><?= $e($r['LEI']) ?></strong> — <?= $e($r['TIPO'] ?: $T('alteracao', ['pt' => 'alteração', 'en' => 'change', 'es' => 'modificación'])) ?><?= $r['DATA_ALTERACAO'] ? ' · ' . $e($data($r['DATA_ALTERACAO'])) : '' ?>
<?php if (trim((string)$r['DESCRICAO']) !== ''): ?><div class="text-muted"><?= $e($r['DESCRICAO']) ?></div><?php endif; ?>
<div><?= $e($r['MOTIVO']) ?><?= $r['RESPONSAVEL'] ? ' · ' . $e($T('tarefa_para', ['pt' => 'Tarefa para', 'en' => 'Task for', 'es' => 'Tarea para'])) . ' ' . $e($r['RESPONSAVEL']) . ' (' . $e($rotStatus[$r['STATUS_TAREFA']] ?? (string)$r['STATUS_TAREFA']) . ')' : '' ?></div></div>
<?php greenPortalGridLocal(['transaction' => 'modulolegal_reavaliacao_leis', 'fragment_class' => 'reav-itens-fragment', 'id' => 'reavItensGrid', 'open_button' => true, 'cell_boxes' => true,
    'columns' => ['TIPO' => ['label' => $T('c.tipo', ['pt' => 'Tipo', 'en' => 'Type', 'es' => 'Tipo']), 'filter' => 'select', 'width' => '150px'], 'NOME' => ['label' => $T('c.item', ['pt' => 'Item a revisar', 'en' => 'Item to review', 'es' => 'Ítem a revisar'])],
        'UNIDADE' => ['label' => $T('c.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), 'filter' => 'select', 'width' => '180px'], 'MOTIVO' => ['label' => $T('c.motivo', ['pt' => 'Por quê', 'en' => 'Why', 'es' => 'Por qué']), 'width' => '220px']],
    'rows' => $rows, 'default_sort' => ['MOTIVO', 'desc'], 'empty' => $T('sem_itens', ['pt' => 'Nenhum requisito ou item parecido.', 'en' => 'No requirement or similar item.', 'es' => 'Ningún requisito o ítem parecido.'])]); ?>
<?php endif; ?>
</div>
<?php else: ?>
<?php
    $rows = [];
    foreach ($lista as $r) {
        $sit = $r['SITUACAO'] === 'SEM_IMPACTO' ? $T('s.sem_impacto', ['pt' => 'Sem impacto', 'en' => 'No impact', 'es' => 'Sin impacto']) : ($rotStatus[$r['STATUS_TAREFA']] ?? $T('s.aberta', ['pt' => 'Aberta', 'en' => 'Open', 'es' => 'Abierta']));
        $rows[] = ['cells' => [
            'DATA' => ['text' => $data($r['DATA_ALTERACAO'] ?: $r['CRIADO_EM']), 'sort' => (string)($r['DATA_ALTERACAO'] ?: $r['CRIADO_EM'])],
            'LEI' => ['text' => (string)$r['LEI'], 'title' => (string)$r['DESCRICAO']], 'TIPO' => ['text' => (string)$r['TIPO']],
            'REQUISITO' => ['text' => $r['EH_REQUISITO_DESC']], 'ITENS' => ['text' => (string)(int)$r['ITENS'], 'sort' => str_pad((string)(int)$r['ITENS'], 5, '0', STR_PAD_LEFT)],
            'SITUACAO' => ['text' => $sit], 'RESPONSAVEL' => ['text' => (string)($r['RESPONSAVEL'] ?? '')], 'MOTIVO' => ['text' => (string)$r['MOTIVO'], 'title' => (string)$r['MOTIVO']],
        ], 'open' => ['url' => 'modulolegal_reavaliacao_leis.php?codreav=' . (int)$r['CODREAV'], 'title' => $titulo . ' - ' . mb_strimwidth((string)$r['LEI'], 0, 60, '…')]];
    }
    greenPortalGridLocal(['transaction' => 'modulolegal_reavaliacao_leis', 'fragment_class' => 'reav-leis-fragment', 'id' => 'reavLeisGrid', 'open_button' => true, 'cell_boxes' => true, 'buttons' => ['columns' => true],
        'columns' => ['DATA' => ['label' => $T('c.data', ['pt' => 'Data', 'en' => 'Date', 'es' => 'Fecha']), 'width' => '110px'], 'LEI' => ['label' => $T('c.lei', ['pt' => 'Lei', 'en' => 'Law', 'es' => 'Ley'])],
            'TIPO' => ['label' => $T('c.alteracao', ['pt' => 'Alteração', 'en' => 'Change', 'es' => 'Modificación']), 'filter' => 'select', 'width' => '150px'],
            'REQUISITO' => ['label' => $T('c.requisito', ['pt' => 'É requisito?', 'en' => 'Requirement?', 'es' => '¿Es requisito?']), 'filter' => 'select', 'width' => '100px'],
            'ITENS' => ['label' => $T('c.itens', ['pt' => 'Itens', 'en' => 'Items', 'es' => 'Ítems']), 'filter' => 'none', 'width' => '70px'],
            'SITUACAO' => ['label' => $T('c.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']), 'filter' => 'select', 'width' => '170px'],
            'RESPONSAVEL' => ['label' => $T('c.responsavel', ['pt' => 'Responsável', 'en' => 'Owner', 'es' => 'Responsable']), 'filter' => 'select', 'width' => '160px'],
            'MOTIVO' => ['label' => $T('c.motivo2', ['pt' => 'O que foi encontrado', 'en' => 'What was found', 'es' => 'Qué se encontró'])]],
        'rows' => $rows, 'default_sort' => ['DATA', 'desc'],
        'empty' => $T('vazio', ['pt' => 'Nenhuma alteração de lei recebida desde a ativação. As novas alterações e revogações aparecem aqui (o serviço da pesquisa verifica a cada 10 minutos).', 'en' => 'No law change received since activation.', 'es' => 'Ninguna modificación de ley recibida desde la activación.'])]);
?>
<?php endif; ?>
<?php greenPortalGridLocalScripts(); ?>
</body></html>
