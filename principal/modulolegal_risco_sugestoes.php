<?php
declare(strict_types=1);

/*
 * Módulo Legal › Sugestões de risco (IA) — migração 342 (requisitos legais, etapa 2).
 * A IA sugere a Severidade e a Probabilidade dos itens de gestão; aqui o responsável revisa: aceitar, ajustar (trocar os valores
 * na linha e aceitar) ou recusar, um a um ou marcando várias linhas. Só decide quem pode alterar o cadastro do item (edição na
 * transação do pilar). Também é daqui (e do Dashboard) que se pede o lote dos itens com "risco não avaliado" — o lote só roda
 * quando alguém pede. Abas: A revisar, Aguardando a IA, Decididas, Pedidos. ?lote=N (link do alerta) filtra o pedido.
 * Leitura: vw_ml_risco_sugestoes, vw_ml_risco_lotes, vw_ml_risco_nao_avaliados. Gravação: api/risco_sugestoes.php.
 */

require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/RequisitoRiscoIa.php';
require_once dirname(__DIR__) . '/components/grid_local.php';

$pdo = TransactionSecurity::connect(RequisitoRiscoIa::MENU);
$perm = PermissaoTransacao::obter(RequisitoRiscoIa::MENU);
$uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$idioma = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$T = static fn(string $c, array $p): string => IdiomaService::t('modulolegal_risco_sugestoes.' . $c, $p);
$lote = max(0, (int)($_GET['lote'] ?? 0));
$podeLote = !empty($perm['pode_incluir']);
$erro = '';
$sugs = []; $lotes = []; $naoAvaliados = 0;
try {
    if (!RequisitoRiscoIa::disponivel($pdo)) throw new RuntimeException('sem 342');
    $sugs = RequisitoRiscoIa::ler($pdo, 'vw_ml_risco_sugestoes', [], 20000);
    $lotes = RequisitoRiscoIa::ler($pdo, 'vw_ml_risco_lotes', [], 5000);
    $naoAvaliados = count(RequisitoRiscoIa::ler($pdo, 'vw_ml_risco_nao_avaliados', [], 20000));
} catch (Throwable $ex) {
    $erro = $T('erro.instalacao', ['pt' => 'A sugestão de risco pela IA ainda não está instalada nesta empresa: aplique a migração 342.', 'en' => 'AI risk suggestion is not installed for this company yet: run migration 342.', 'es' => 'La sugerencia de riesgo por IA aún no está instalada: aplique la migración 342.']);
    if (!str_contains($ex->getMessage(), 'sem 342')) error_log('[risco sugestoes] ' . $ex->getMessage());
}
$areaNome = static fn(string $a): string => (string)(RequisitoRisco::AREAS[$a][1] ?? $a);
$data = static fn(?string $d): string => $d ? date($idioma === 'en' ? 'm/d/Y H:i' : 'd/m/Y H:i', strtotime($d) ?: time()) : '';
$ordem = static fn(?string $d): string => $d ? date('Y-m-d H:i:s', strtotime($d) ?: 0) : '';
$opSev = RequisitoRisco::opcoesSeveridade(); $opProb = RequisitoRisco::opcoesProbabilidade();
$tr = static fn(mixed $v): string => is_array($v) ? (string)($v[$idioma] ?? $v['pt'] ?? '') : (string)$v;
$select = static function (string $campo, array $opcoes, ?int $valor, bool $pode) use ($e, $tr): string {
    if (!$pode) return $valor ? $e($tr($opcoes[(string)$valor] ?? $valor)) : '';
    $h = '<select class="form-select form-select-sm risco-sug-sel" data-' . $campo . ' data-original="' . (int)$valor . '">';
    foreach ($opcoes as $v => $r) $h .= '<option value="' . $e($v) . '"' . ((string)$v === (string)$valor ? ' selected' : '') . '>' . $e($tr($r)) . '</option>';
    return $h . '</select>';
};
$cont = ['PENDENTE' => 0, 'AGUARDANDO' => 0, 'DECIDIDA' => 0, 'ERRO' => 0];
$rowsPend = []; $rowsAg = []; $rowsDec = [];
$podeCache = [];
foreach ($sugs as $s) {
    $st = (string)$s['STATUS'];
    if ($lote > 0 && (int)$s['CODLOTE'] !== $lote) continue;
    $area = (string)$s['AREA']; $item = (int)$s['ITEM_ID'];
    $loteTxt = ['text' => '#' . (int)$s['CODLOTE'], 'sort' => str_pad((string)(int)$s['CODLOTE'], 9, '0', STR_PAD_LEFT)];
    $atual = $s['CLASSE_ATUAL'] ? RequisitoRisco::rotuloClasse((string)$s['CLASSE_ATUAL']) . ' (' . (int)$s['SEV_ATUAL'] . '×' . (int)$s['PROB_ATUAL'] . ')' : RequisitoRisco::rotuloClasse('NAO_AVALIADO');
    if ($st === 'PENDENTE') {
        $cont['PENDENTE']++;
        $pode = $podeCache[$area . ':' . $item] ??= RequisitoRiscoIa::podeDecidir($pdo, $area, $item, $uid);
        $just = (string)($s['JUSTIFICATIVA'] ?? '');
        $rowsPend[] = ['attrs' => 'data-sug="' . (int)$s['CODSUGESTAO'] . '"', 'cells' => [
            'AREA' => ['text' => $areaNome($area)],
            'ITEM' => ['text' => (string)$s['ITEM'], 'title' => (string)$s['ITEM']],
            'SEV' => ['text' => (string)$s['SEVERIDADE'], 'html' => $select('sev', $opSev, (int)$s['SEVERIDADE'], $pode)],
            'PROB' => ['text' => (string)$s['PROBABILIDADE'], 'html' => $select('prob', $opProb, (int)$s['PROBABILIDADE'], $pode)],
            'RISCO' => ['text' => RequisitoRisco::rotuloClasse((string)$s['CLASSE']), 'html' => '<span data-risco-sug>' . RequisitoRisco::selo((string)$s['CLASSE'], (int)$s['PONTOS']) . '</span>', 'sort' => str_pad((string)(int)$s['PONTOS'], 2, '0', STR_PAD_LEFT)],
            'CONFIANCA' => ['text' => $s['CONFIANCA'] === null ? '' : (int)$s['CONFIANCA'] . '%', 'sort' => str_pad((string)(int)$s['CONFIANCA'], 3, '0', STR_PAD_LEFT)],
            'JUSTIFICATIVA' => ['text' => $just, 'title' => $just],
            'ATUAL' => ['text' => $atual],
            'LOTE' => $loteTxt + ['title' => $T('respondida', ['pt' => 'Respondida em', 'en' => 'Answered at', 'es' => 'Respondida el']) . ' ' . $data($s['RESPONDIDO_EM'])],
        ], 'actions' => $pode ? '<input class="form-check-input" type="checkbox" data-grid-marcar data-sel-sug="' . (int)$s['CODSUGESTAO'] . '" aria-label="' . $e($T('marcar', ['pt' => 'Marcar a sugestão', 'en' => 'Mark the suggestion', 'es' => 'Marcar la sugerencia'])) . '">'
            : '<i class="bi bi-lock text-muted" title="' . $e($T('sem_permissao', ['pt' => 'Seu perfil não pode alterar o cadastro deste item', 'en' => 'Your profile cannot edit this item', 'es' => 'Su perfil no puede modificar este ítem'])) . '"></i>'];
    } elseif ($st === 'AGUARDANDO') {
        $cont['AGUARDANDO']++;
        $rowsAg[] = ['cells' => ['AREA' => ['text' => $areaNome($area)], 'ITEM' => ['text' => (string)$s['ITEM'], 'title' => (string)$s['ITEM']], 'LOTE' => $loteTxt,
            'PEDIDO' => ['text' => trim((string)$s['NOME_PEDIDO'] . ' ' . $data($s['CRIADO_EM'])), 'sort' => $ordem($s['CRIADO_EM'])]]];
    } else {
        if (in_array($st, ['ACEITA', 'AJUSTADA', 'RECUSADA'], true)) $cont['DECIDIDA']++;
        if (in_array($st, ['ERRO', 'SEM_RESPOSTA'], true)) $cont['ERRO']++;
        $sug = $s['SEVERIDADE'] ? (int)$s['SEVERIDADE'] . ' × ' . (int)$s['PROBABILIDADE'] . ' = ' . RequisitoRisco::rotuloClasse((string)$s['CLASSE']) : '';
        $fin = $s['SEV_FINAL'] ? (int)$s['SEV_FINAL'] . ' × ' . (int)$s['PROB_FINAL'] . ' = ' . RequisitoRisco::rotuloClasse(RequisitoRisco::classe((int)$s['SEV_FINAL'], (int)$s['PROB_FINAL'])) : '';
        $obs = trim((string)($s['ERRO'] ?? '') . ' ' . (string)($s['MOTIVO_DECISAO'] ?? ''));
        $rowsDec[] = ['cells' => ['AREA' => ['text' => $areaNome($area)], 'ITEM' => ['text' => (string)$s['ITEM'], 'title' => (string)$s['ITEM']],
            'SITUACAO' => ['text' => (string)$s['STATUS_TEXTO']], 'SUGERIDO' => ['text' => $sug], 'GRAVADO' => ['text' => $fin],
            'JUSTIFICATIVA' => ['text' => (string)($s['JUSTIFICATIVA'] ?? ''), 'title' => (string)($s['JUSTIFICATIVA'] ?? '')], 'OBS' => ['text' => $obs, 'title' => $obs],
            'DECISAO' => ['text' => trim((string)$s['NOME_DECISAO'] . ' ' . $data($s['DECIDIDO_EM'] ?? $s['RESPONDIDO_EM'])), 'sort' => $ordem($s['DECIDIDO_EM'] ?? $s['RESPONDIDO_EM'])], 'LOTE' => $loteTxt]];
    }
}
$rowsLotes = [];
foreach ($lotes as $l) {
    $rowsLotes[] = ['cells' => [
        'LOTE' => ['text' => '#' . (int)$l['CODLOTE'], 'html' => '<a href="?lote=' . (int)$l['CODLOTE'] . '">#' . (int)$l['CODLOTE'] . '</a>', 'sort' => str_pad((string)(int)$l['CODLOTE'], 9, '0', STR_PAD_LEFT)],
        'ORIGEM' => ['text' => (string)$l['ORIGEM'] === 'ITEM' ? $T('orig.item', ['pt' => 'Um item (cadastro)', 'en' => 'One item (register)', 'es' => 'Un ítem (registro)']) : $T('orig.lote', ['pt' => 'Lote (não avaliados)', 'en' => 'Batch (not assessed)', 'es' => 'Lote (no evaluados)'])],
        'AREA' => ['text' => (string)$l['AREA'] !== '' ? $areaNome((string)$l['AREA']) : $T('todas', ['pt' => 'Todas', 'en' => 'All', 'es' => 'Todas'])],
        'PEDIDO' => ['text' => trim((string)$l['NOME_PEDIDO'] . ' ' . $data($l['CRIADO_EM'])), 'sort' => $ordem($l['CRIADO_EM'])],
        'TOTAL' => ['text' => (string)(int)$l['TOTAL'], 'sort' => str_pad((string)(int)$l['TOTAL'], 6, '0', STR_PAD_LEFT)],
        'AGUARDANDO' => ['text' => (string)(int)$l['AGUARDANDO']], 'PENDENTES' => ['text' => (string)(int)$l['PENDENTES']],
        'ACEITAS' => ['text' => (string)(int)$l['ACEITAS']], 'RECUSADAS' => ['text' => (string)(int)$l['RECUSADAS']], 'ERROS' => ['text' => (string)(int)$l['ERROS']],
        'CONCLUIDO' => ['text' => $data($l['CONCLUIDO_EM']), 'sort' => $ordem($l['CONCLUIDO_EM'])],
    ]];
}
$areasOpcoes = ['' => $T('todas_areas', ['pt' => 'Todas as áreas', 'en' => 'All areas', 'es' => 'Todas las áreas'])];
foreach (RequisitoRisco::AREAS as $k => $a) $areasOpcoes[$k] = $a[0];
$cartao = static fn(string $rotulo, int $n, string $cor, string $aba, string $dica = ''): string =>
    '<div class="col-6 col-md-3"><button type="button" class="card w-100 text-start risco-sug-cartao border-' . $cor . '" data-ir-aba="' . $aba . '"' . ($dica !== '' ? ' title="' . $e($dica) . '"' : '') . '><div class="card-body py-2"><div class="fs-4 fw-semibold text-' . $cor . '">' . $n . '</div><div class="small text-muted">' . $e($rotulo) . '</div></div></button></div>';
?>
<!doctype html><html lang="<?= $e($idioma === 'pt' ? 'pt-BR' : $idioma) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($T('titulo', ['pt' => 'Sugestões de risco (IA)', 'en' => 'Risk suggestions (AI)', 'es' => 'Sugerencias de riesgo (IA)'])) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php greenPortalGridLocalHead(); ?>
<style>.risco-sug{padding:12px}.risco-sug .risco-sug-cartao{cursor:pointer;border-width:0 0 0 4px!important}.risco-sug .risco-sug-cartao:hover{background:#f6faf8}
.risco-sug .risco-sug-sel{font-size:11px;padding:2px 22px 2px 6px;min-width:0}.risco-sug .grid-row-actions-heading{width:70px}
.risco-sug-pend th[data-column="AREA"]{width:130px}.risco-sug-pend th[data-column="ITEM"]{width:17%}.risco-sug-pend th[data-column="SEV"]{width:118px}.risco-sug-pend th[data-column="PROB"]{width:128px}
.risco-sug-pend th[data-column="RISCO"]{width:100px}.risco-sug-pend th[data-column="CONFIANCA"]{width:74px}.risco-sug-pend th[data-column="LOTE"]{width:72px}.risco-sug-pend th[data-column="ATUAL"]{width:105px}
.risco-sug-pend tr.risco-ajustado td[data-column="SEV"],.risco-sug-pend tr.risco-ajustado td[data-column="PROB"]{background:#fff8e1}</style>
</head>
<body class="risco-sug" data-transacao="modulolegal_risco_sugestoes" data-csrf="<?= $e(RequisitoRiscoIa::csrf()) ?>" data-api="api/risco_sugestoes.php">
  <h2 class="h5 mb-1"><i class="bi bi-stars me-1"></i><?= $e($T('titulo', ['pt' => 'Sugestões de risco (IA)', 'en' => 'Risk suggestions (AI)', 'es' => 'Sugerencias de riesgo (IA)'])) ?></h2>
  <p class="text-muted small mb-2"><?= $e($T('subtitulo', ['pt' => 'A IA sugere a Severidade e a Probabilidade dos itens de gestão, com justificativa. Nada é gravado no item sem a sua decisão: aceite, ajuste (troque os valores na linha e aceite) ou recuse. Só decide quem pode alterar o cadastro do item.', 'en' => 'The AI suggests the Severity and Probability of management items, with a justification. Nothing is saved without your decision: accept, adjust (change the values on the row and accept) or reject. Only users who can edit the item decide.', 'es' => 'La IA sugiere la Severidad y la Probabilidad de los ítems, con justificación. No se graba nada sin su decisión: acepte, ajuste (cambie los valores en la fila y acepte) o rechace. Solo decide quien puede modificar el ítem.'])) ?></p>
  <div data-nc-aviso hidden></div>
  <?php if ($erro !== ''): ?><div class="alert alert-warning py-2"><?= $e($erro) ?></div><?php else: ?>
  <?php if ($lote > 0): ?><div class="alert alert-info py-2 d-flex align-items-center gap-2"><i class="bi bi-funnel"></i><span><?= $e($T('filtro_lote', ['pt' => 'Mostrando só o pedido', 'en' => 'Showing only request', 'es' => 'Mostrando solo el pedido'])) ?> #<?= $lote ?>.</span><a class="ms-auto" href="modulolegal_risco_sugestoes.php"><?= $e($T('ver_todos', ['pt' => 'Ver todos', 'en' => 'Show all', 'es' => 'Ver todos'])) ?></a></div><?php endif; ?>
  <div class="row g-2 mb-2">
    <?= $cartao($T('c.revisar', ['pt' => 'A revisar', 'en' => 'To review', 'es' => 'A revisar']), $cont['PENDENTE'], 'warning', 'abaPend') ?>
    <?= $cartao($T('c.aguardando', ['pt' => 'Aguardando a IA', 'en' => 'Waiting for the AI', 'es' => 'Esperando la IA']), $cont['AGUARDANDO'], 'info', 'abaAg') ?>
    <?= $cartao($T('c.decididas', ['pt' => 'Decididas', 'en' => 'Decided', 'es' => 'Decididas']), $cont['DECIDIDA'], 'success', 'abaDec') ?>
    <?= $cartao($T('c.nao_avaliados', ['pt' => 'Itens com risco não avaliado', 'en' => 'Items with risk not assessed', 'es' => 'Ítems con riesgo no evaluado']), $naoAvaliados, 'secondary', 'abaPend', $T('c.nao_avaliados_dica', ['pt' => 'Itens ligados a requisitos legais ainda sem Severidade × Probabilidade', 'en' => 'Items linked to legal requirements without Severity × Probability', 'es' => 'Ítems vinculados sin Severidad × Probabilidad'])) ?>
  </div>
  <?php if ($podeLote): ?>
  <div class="card mb-2"><div class="card-body py-2 d-flex flex-wrap align-items-end gap-2">
    <div><label class="form-label mb-1 small fw-semibold" for="riscoLoteArea"><?= $e($T('lote.area', ['pt' => 'Pedir sugestão para os itens não avaliados', 'en' => 'Ask for suggestions for items not assessed', 'es' => 'Pedir sugerencias para los ítems no evaluados'])) ?></label>
      <select class="form-select form-select-sm" id="riscoLoteArea"><?php foreach ($areasOpcoes as $k => $r): ?><option value="<?= $e($k) ?>"><?= $e($r) ?></option><?php endforeach; ?></select></div>
    <button type="button" class="btn btn-sm btn-success" data-risco-lote><i class="bi bi-stars me-1"></i><?= $e($T('lote.botao', ['pt' => 'Sugerir risco para os itens não avaliados', 'en' => 'Suggest risk for items not assessed', 'es' => 'Sugerir riesgo para los ítems no evaluados'])) ?></button>
    <span class="small text-muted"><?= $e($T('lote.nota', ['pt' => 'Até ' . RequisitoRiscoIa::MAX_LOTE . ' itens por pedido, sem os que já têm sugestão aguardando, a revisar ou recusada. Conta no consumo de IA da empresa.', 'en' => 'Up to ' . RequisitoRiscoIa::MAX_LOTE . ' items per request. Counts in the company AI usage.', 'es' => 'Hasta ' . RequisitoRiscoIa::MAX_LOTE . ' ítems por pedido. Cuenta en el consumo de IA de la empresa.'])) ?></span>
  </div></div>
  <?php endif; ?>
  <ul class="nav nav-tabs mb-2" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#abaPend" type="button" role="tab"><?= $e($T('aba.revisar', ['pt' => 'A revisar', 'en' => 'To review', 'es' => 'A revisar'])) ?> (<?= $cont['PENDENTE'] ?>)</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaAg" type="button" role="tab"><?= $e($T('aba.aguardando', ['pt' => 'Aguardando a IA', 'en' => 'Waiting for the AI', 'es' => 'Esperando la IA'])) ?> (<?= $cont['AGUARDANDO'] ?>)</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaDec" type="button" role="tab"><?= $e($T('aba.decididas', ['pt' => 'Decididas e sem resposta', 'en' => 'Decided and unanswered', 'es' => 'Decididas y sin respuesta'])) ?> (<?= count($rowsDec) ?>)</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaLotes" type="button" role="tab"><?= $e($T('aba.pedidos', ['pt' => 'Pedidos', 'en' => 'Requests', 'es' => 'Pedidos'])) ?> (<?= count($rowsLotes) ?>)</button></li>
  </ul>
  <div class="tab-content">
    <div class="tab-pane fade show active" id="abaPend" role="tabpanel">
      <div class="card mb-2" data-painel-recusar hidden><div class="card-body py-2 d-flex flex-wrap gap-2 align-items-end">
        <div class="flex-grow-1"><label class="form-label mb-1 small fw-semibold" for="riscoMotivo"><?= $e($T('recusar.motivo', ['pt' => 'Motivo da recusa (opcional)', 'en' => 'Reason (optional)', 'es' => 'Motivo (opcional)'])) ?></label><input class="form-control form-control-sm" id="riscoMotivo" maxlength="1000"></div>
        <button type="button" class="btn btn-sm btn-danger" data-risco-recusar-confirmar><i class="bi bi-x-circle me-1"></i><?= $e($T('recusar.botao', ['pt' => 'Recusar as marcadas', 'en' => 'Reject marked', 'es' => 'Rechazar las marcadas'])) ?></button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-risco-recusar-cancelar><?= $e($T('cancelar', ['pt' => 'Cancelar', 'en' => 'Cancel', 'es' => 'Cancelar'])) ?></button>
      </div></div>
      <?php greenPortalGridLocal([
          'transaction' => 'modulolegal_risco_sugestoes', 'fragment_class' => 'risco-sug-pend', 'id' => 'riscoSugPend',
          'columns' => [
              'AREA' => ['label' => $T('col.area', ['pt' => 'Área', 'en' => 'Area', 'es' => 'Área']), 'filter' => 'select'],
              'ITEM' => ['label' => $T('col.item', ['pt' => 'Item', 'en' => 'Item', 'es' => 'Ítem'])],
              'SEV' => ['label' => $T('col.sev', ['pt' => 'Severidade', 'en' => 'Severity', 'es' => 'Severidad']), 'filter' => 'none'],
              'PROB' => ['label' => $T('col.prob', ['pt' => 'Probabilidade', 'en' => 'Probability', 'es' => 'Probabilidad']), 'filter' => 'none'],
              'RISCO' => ['label' => $T('col.risco', ['pt' => 'Risco sugerido', 'en' => 'Suggested risk', 'es' => 'Riesgo sugerido']), 'filter' => 'select'],
              'CONFIANCA' => ['label' => $T('col.confianca', ['pt' => 'Confiança', 'en' => 'Confidence', 'es' => 'Confianza']), 'filter' => 'none'],
              'JUSTIFICATIVA' => ['label' => $T('col.justificativa', ['pt' => 'Justificativa da IA', 'en' => 'AI justification', 'es' => 'Justificación de la IA'])],
              'ATUAL' => ['label' => $T('col.atual', ['pt' => 'Risco atual', 'en' => 'Current risk', 'es' => 'Riesgo actual']), 'filter' => 'select'],
              'LOTE' => ['label' => $T('col.lote', ['pt' => 'Pedido', 'en' => 'Request', 'es' => 'Pedido']), 'filter' => 'select'],
          ],
          'actions_label' => $T('col.marcar', ['pt' => 'Marcar as sugestões desta página', 'en' => 'Mark suggestions on this page', 'es' => 'Marcar las sugerencias de esta página']), 'select_all' => true, 'cell_boxes' => false, 'buttons' => ['columns' => true],
          'extras' => '<button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-risco-aceitar title="' . $e($T('aceitar.dica', ['pt' => 'Aceitar as sugestões marcadas (com os valores que estão na linha: se você trocou, fica como ajustada)', 'en' => 'Accept the marked suggestions (with the values on the row: changed values are saved as adjusted)', 'es' => 'Aceptar las sugerencias marcadas (con los valores de la fila)'])) . '" aria-label="' . $e($T('aceitar', ['pt' => 'Aceitar as marcadas', 'en' => 'Accept marked', 'es' => 'Aceptar las marcadas'])) . '"><i class="bi bi-check2-circle"></i></button>'
              . '<button type="button" class="btn btn-sm btn-outline-danger grid-crud-button" data-risco-recusar title="' . $e($T('recusar.dica', ['pt' => 'Recusar as sugestões marcadas (nada é gravado no item)', 'en' => 'Reject the marked suggestions (nothing is saved)', 'es' => 'Rechazar las sugerencias marcadas (no se graba nada)'])) . '" aria-label="' . $e($T('recusar.botao', ['pt' => 'Recusar as marcadas', 'en' => 'Reject marked', 'es' => 'Rechazar las marcadas'])) . '"><i class="bi bi-x-circle"></i></button>',
          'rows' => $rowsPend, 'default_sort' => ['RISCO', 'desc'], 'empty' => $T('vazio.revisar', ['pt' => 'Nenhuma sugestão a revisar.', 'en' => 'No suggestion to review.', 'es' => 'Ninguna sugerencia a revisar.']),
      ]); ?>
    </div>
    <div class="tab-pane fade" id="abaAg" role="tabpanel">
      <?php greenPortalGridLocal([
          'transaction' => 'modulolegal_risco_sugestoes', 'fragment_class' => 'risco-sug-ag', 'id' => 'riscoSugAg',
          'columns' => ['AREA' => ['label' => $T('col.area', ['pt' => 'Área', 'en' => 'Area', 'es' => 'Área']), 'filter' => 'select'], 'ITEM' => ['label' => $T('col.item', ['pt' => 'Item', 'en' => 'Item', 'es' => 'Ítem'])],
              'LOTE' => ['label' => $T('col.lote', ['pt' => 'Pedido', 'en' => 'Request', 'es' => 'Pedido']), 'filter' => 'select'], 'PEDIDO' => ['label' => $T('col.pedido', ['pt' => 'Pedido por / em', 'en' => 'Requested by / at', 'es' => 'Pedido por / el'])]],
          'rows' => $rowsAg, 'default_sort' => ['PEDIDO', 'desc'], 'notice' => $cont['AGUARDANDO'] ? $T('aguardando.nota', ['pt' => 'O serviço de IA do Agendador processa os pedidos em segundo plano; quando o pedido termina, chega um alerta.', 'en' => 'The scheduler AI service processes the requests in the background; an alert arrives when done.', 'es' => 'El servicio de IA del Agendador procesa los pedidos en segundo plano; al terminar llega una alerta.']) : '',
          'empty' => $T('vazio.aguardando', ['pt' => 'Nenhum item aguardando a IA.', 'en' => 'No item waiting for the AI.', 'es' => 'Ningún ítem esperando la IA.']),
      ]); ?>
    </div>
    <div class="tab-pane fade" id="abaDec" role="tabpanel">
      <?php greenPortalGridLocal([
          'transaction' => 'modulolegal_risco_sugestoes', 'fragment_class' => 'risco-sug-dec', 'id' => 'riscoSugDec',
          'columns' => ['AREA' => ['label' => $T('col.area', ['pt' => 'Área', 'en' => 'Area', 'es' => 'Área']), 'filter' => 'select'], 'ITEM' => ['label' => $T('col.item', ['pt' => 'Item', 'en' => 'Item', 'es' => 'Ítem'])],
              'SITUACAO' => ['label' => $T('col.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']), 'filter' => 'select'],
              'SUGERIDO' => ['label' => $T('col.sugerido', ['pt' => 'Sugerido pela IA', 'en' => 'Suggested by the AI', 'es' => 'Sugerido por la IA'])],
              'GRAVADO' => ['label' => $T('col.gravado', ['pt' => 'Gravado no item', 'en' => 'Saved on the item', 'es' => 'Grabado en el ítem'])],
              'JUSTIFICATIVA' => ['label' => $T('col.justificativa', ['pt' => 'Justificativa da IA', 'en' => 'AI justification', 'es' => 'Justificación de la IA'])],
              'OBS' => ['label' => $T('col.obs', ['pt' => 'Motivo / erro', 'en' => 'Reason / error', 'es' => 'Motivo / error'])],
              'DECISAO' => ['label' => $T('col.decisao', ['pt' => 'Decidido por / em', 'en' => 'Decided by / at', 'es' => 'Decidido por / el'])],
              'LOTE' => ['label' => $T('col.lote', ['pt' => 'Pedido', 'en' => 'Request', 'es' => 'Pedido']), 'filter' => 'select']],
          'rows' => $rowsDec, 'default_sort' => ['DECISAO', 'desc'], 'buttons' => ['columns' => true],
          'empty' => $T('vazio.decididas', ['pt' => 'Nenhuma sugestão decidida ainda.', 'en' => 'No suggestion decided yet.', 'es' => 'Ninguna sugerencia decidida todavía.']),
      ]); ?>
    </div>
    <div class="tab-pane fade" id="abaLotes" role="tabpanel">
      <?php greenPortalGridLocal([
          'transaction' => 'modulolegal_risco_sugestoes', 'fragment_class' => 'risco-sug-lotes', 'id' => 'riscoSugLotes',
          'columns' => ['LOTE' => ['label' => $T('col.lote', ['pt' => 'Pedido', 'en' => 'Request', 'es' => 'Pedido'])], 'ORIGEM' => ['label' => $T('col.origem', ['pt' => 'Tipo', 'en' => 'Type', 'es' => 'Tipo']), 'filter' => 'select'],
              'AREA' => ['label' => $T('col.area', ['pt' => 'Área', 'en' => 'Area', 'es' => 'Área']), 'filter' => 'select'], 'PEDIDO' => ['label' => $T('col.pedido', ['pt' => 'Pedido por / em', 'en' => 'Requested by / at', 'es' => 'Pedido por / el'])],
              'TOTAL' => ['label' => $T('col.itens', ['pt' => 'Itens', 'en' => 'Items', 'es' => 'Ítems']), 'filter' => 'none'], 'AGUARDANDO' => ['label' => $T('col.aguardando', ['pt' => 'Aguardando', 'en' => 'Waiting', 'es' => 'Esperando']), 'filter' => 'none'],
              'PENDENTES' => ['label' => $T('col.revisar', ['pt' => 'A revisar', 'en' => 'To review', 'es' => 'A revisar']), 'filter' => 'none'], 'ACEITAS' => ['label' => $T('col.aceitas', ['pt' => 'Aceitas', 'en' => 'Accepted', 'es' => 'Aceptadas']), 'filter' => 'none'],
              'RECUSADAS' => ['label' => $T('col.recusadas', ['pt' => 'Recusadas', 'en' => 'Rejected', 'es' => 'Rechazadas']), 'filter' => 'none'], 'ERROS' => ['label' => $T('col.erros', ['pt' => 'Sem resposta', 'en' => 'Unanswered', 'es' => 'Sin respuesta']), 'filter' => 'none'],
              'CONCLUIDO' => ['label' => $T('col.concluido', ['pt' => 'Concluído em', 'en' => 'Finished at', 'es' => 'Concluido el']), 'filter' => 'none']],
          'rows' => $rowsLotes, 'default_sort' => ['LOTE', 'desc'],
          'empty' => $T('vazio.pedidos', ['pt' => 'Nenhum pedido ainda.', 'en' => 'No request yet.', 'es' => 'Ningún pedido todavía.']),
      ]); ?>
    </div>
  </div>
  <?php endif; ?>
<?php greenPortalGridLocalScripts(); ?>
<script src="../assets/js/nc-administracao.js?v=1"></script>
<script src="../assets/js/ajuda-transacao.js?v=2"></script>
<script>
(function () {
  var NOMES = <?= json_encode(['BAIXO' => RequisitoRisco::rotuloClasse('BAIXO'), 'MODERADO' => RequisitoRisco::rotuloClasse('MODERADO'), 'ALTO' => RequisitoRisco::rotuloClasse('ALTO'), 'CRITICO' => RequisitoRisco::rotuloClasse('CRITICO')], JSON_UNESCAPED_UNICODE) ?>;
  var CORES = { BAIXO: 'text-bg-success', MODERADO: 'text-bg-warning', ALTO: '', CRITICO: 'text-bg-danger' };
  var TXT = <?= json_encode([
      'nada' => $T('js.nada', ['pt' => 'Marque as sugestões (só as que você pode decidir têm a caixa).', 'en' => 'Mark the suggestions (only the ones you can decide have a box).', 'es' => 'Marque las sugerencias.']),
      'aceitar_t' => $T('js.aceitar_t', ['pt' => 'Aceitar sugestões', 'en' => 'Accept suggestions', 'es' => 'Aceptar sugerencias']),
      'aceitar' => $T('js.aceitar', ['pt' => 'Gravar o risco de {n} item(ns)? {a} com valores ajustados por você.', 'en' => 'Save the risk of {n} item(s)? {a} with values adjusted by you.', 'es' => '¿Grabar el riesgo de {n} ítem(s)? {a} con valores ajustados.']),
      'feito' => $T('js.feito', ['pt' => '{c} aceita(s), {j} ajustada(s), {r} recusada(s).', 'en' => '{c} accepted, {j} adjusted, {r} rejected.', 'es' => '{c} aceptada(s), {j} ajustada(s), {r} rechazada(s).']),
      'lote_t' => $T('js.lote_t', ['pt' => 'Pedir sugestões à IA', 'en' => 'Ask the AI for suggestions', 'es' => 'Pedir sugerencias a la IA']),
      'lote' => $T('js.lote', ['pt' => 'Enviar à IA os itens com risco não avaliado ({area})? Cada pedido conta no consumo de IA da empresa.', 'en' => 'Send items with risk not assessed ({area}) to the AI? Each request counts in the company AI usage.', 'es' => '¿Enviar a la IA los ítems no evaluados ({area})? Cuenta en el consumo de IA.']),
  ], JSON_UNESCAPED_UNICODE) ?>;
  function classe(s, p) { if (!s || !p) return ''; var x = s * p; return s === 4 || x >= 12 ? 'CRITICO' : x >= 8 ? 'ALTO' : x >= 4 ? 'MODERADO' : 'BAIXO'; }
  function linhaAtualizar(tr) {
    var s = tr.querySelector('[data-sev]'), p = tr.querySelector('[data-prob]'); if (!s || !p) return;
    var sv = Number(s.value), pv = Number(p.value), c = classe(sv, pv), alvo = tr.querySelector('[data-risco-sug]');
    if (alvo && c) alvo.innerHTML = '<span class="badge ' + CORES[c] + '"' + (c === 'ALTO' ? ' style="background:#fd7e14;color:#fff"' : '') + '>' + NOMES[c] + ' (' + (sv * pv) + ')</span>';
    tr.classList.toggle('risco-ajustado', String(sv) !== s.getAttribute('data-original') || String(pv) !== p.getAttribute('data-original'));
  }
  document.addEventListener('change', function (ev) {
    var sel = ev.target.closest('.risco-sug-sel'); if (!sel) return;
    var tr = sel.closest('tr'); linhaAtualizar(tr);
    var cb = tr.querySelector('[data-sel-sug]'); if (cb) cb.checked = true;
  });
  function marcadas() { return Array.prototype.filter.call(document.querySelectorAll('[data-sel-sug]'), function (x) { return x.checked; }).map(function (x) { return x.closest('tr'); }); }
  function decidir(itens, motivo, botao) {
    if (botao) botao.disabled = true;
    GPNcAdmin.gravar('decidir', { itens: itens, motivo: motivo || '' }).then(function (r) {
      GPNcAdmin.aviso(TXT.feito.replace('{c}', r.ACEITA || 0).replace('{j}', r.AJUSTADA || 0).replace('{r}', r.RECUSADA || 0) + ((r.erros || []).length ? ' ' + r.erros.slice(0, 3).join(' ') : ''), (r.erros || []).length ? 'warning' : 'success');
      window.setTimeout(function () { window.location.reload(); }, 1600);
    }).catch(function (e) { if (botao) botao.disabled = false; GPNcAdmin.aviso(e.message, 'danger'); });
  }
  document.addEventListener('click', function (ev) {
    var c = ev.target.closest('[data-ir-aba]');
    if (c) { var bt = document.querySelector('[data-bs-target="#' + c.getAttribute('data-ir-aba') + '"]'); if (bt && window.bootstrap) window.bootstrap.Tab.getOrCreateInstance(bt).show(); return; }
    var painel = document.querySelector('[data-painel-recusar]');
    if (ev.target.closest('[data-risco-aceitar]')) {
      var trs = marcadas(); if (!trs.length) { GPNcAdmin.aviso(TXT.nada, 'warning'); return; }
      var itens = trs.map(function (tr) {
        var s = tr.querySelector('[data-sev]'), p = tr.querySelector('[data-prob]'), aj = s.value !== s.getAttribute('data-original') || p.value !== p.getAttribute('data-original');
        return { codsugestao: Number(tr.getAttribute('data-sug')), decisao: aj ? 'AJUSTAR' : 'ACEITAR', severidade: Number(s.value), probabilidade: Number(p.value) };
      });
      var aj = itens.filter(function (i) { return i.decisao === 'AJUSTAR'; }).length;
      GPNcAdmin.confirmar(TXT.aceitar_t, TXT.aceitar.replace('{n}', itens.length).replace('{a}', aj), function () { decidir(itens, '', ev.target.closest('[data-risco-aceitar]')); });
      return;
    }
    if (ev.target.closest('[data-risco-recusar]')) { if (!marcadas().length) { GPNcAdmin.aviso(TXT.nada, 'warning'); return; } if (painel) { painel.hidden = false; document.getElementById('riscoMotivo').focus(); } return; }
    if (ev.target.closest('[data-risco-recusar-cancelar]') && painel) { painel.hidden = true; return; }
    if (ev.target.closest('[data-risco-recusar-confirmar]')) {
      var itensR = marcadas().map(function (tr) { return { codsugestao: Number(tr.getAttribute('data-sug')), decisao: 'RECUSAR' }; });
      if (!itensR.length) { GPNcAdmin.aviso(TXT.nada, 'warning'); return; }
      decidir(itensR, document.getElementById('riscoMotivo').value.trim(), ev.target.closest('[data-risco-recusar-confirmar]'));
      return;
    }
    var bl = ev.target.closest('[data-risco-lote]');
    if (bl) {
      var area = document.getElementById('riscoLoteArea');
      GPNcAdmin.confirmar(TXT.lote_t, TXT.lote.replace('{area}', area.selectedOptions[0].textContent), function () {
        bl.disabled = true;
        GPNcAdmin.gravar('sugerir_lote', { area: area.value }).then(function (r) {
          GPNcAdmin.aviso(r.mensagem || '', r.itens ? 'success' : 'info');
          if (r.itens) window.setTimeout(function () { window.location.reload(); }, 2500); else bl.disabled = false;
        }).catch(function (e) { bl.disabled = false; GPNcAdmin.aviso(e.message, 'danger'); });
      });
    }
  });
}());
</script>
</body></html>
