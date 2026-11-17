<?php
declare(strict_types=1);
// Migração 387 — Legislação › Assuntos da legislação (popup do grid de Manutenção Legal › Legislação), no grid padrão do portal:
// coluna de marcação (vinculado), filtro "Vinculado" e Salvar na barra. Gravar exige Editar em LEGISLACAO.
require_once dirname(__DIR__) . '/classes/LegislacaoPopup.php';

[$id, $perm, $lei] = LegislacaoAssociacoes::iniciarPopup();
$L = LegislacaoAssociacoes::class;

if (($_POST['ajax'] ?? '') === '1' || ($_POST['action'] ?? '') === 'save_assuntos') {
    try {
        if (($_POST['action'] ?? '') !== 'save_assuntos') $L::json(['ok' => false, 'message' => 'Ação desconhecida.'], 400);
        $selected = json_decode((string)($_POST['assuntos'] ?? '[]'), true);
        $selected = array_values(array_unique(array_filter(array_map('intval', is_array($selected) ? $selected : []))));
        $current = [];
        foreach ($L::ler('legislacaoxassunto', ['CODLEGISLACAO' => $id]) as $link) $current[(int)$link['CODASSUNTO']] = true;
        $db = LegislacaoAssociacoes::legislacao();
        $chamar = static function (string $sql, array $p) use ($db): void { $s = $db->prepare($sql); $s->execute($p); while ($s->nextRowset()) {} $s->closeCursor(); };
        foreach (array_keys($current) as $sub) if (!in_array($sub, $selected, true)) $chamar('CALL sp_crud_delete(?, ?)', ['legislacaoxassunto', json_encode(['CODLEGISLACAO' => $id, 'CODASSUNTO' => $sub])]);
        foreach ($selected as $sub) if (!isset($current[$sub])) $chamar('CALL sp_crud_insert(?, ?)', ['legislacaoxassunto', json_encode(['CODLEGISLACAO' => $id, 'CODASSUNTO' => $sub])]);
        $L::json(['ok' => true, 'message' => 'Assuntos gravados (' . count($selected) . ').', 'resumo' => ['type' => 'legislacao-assuntos-saved', 'id' => $id]]);
    } catch (Throwable $e) {
        error_log('[legislacao_assuntos] ' . $e->getMessage());
        $L::json(['ok' => false, 'message' => 'Não foi possível gravar os assuntos.'], 500);
    }
}

$sel = [];
foreach ($L::ler('legislacaoxassunto', ['CODLEGISLACAO' => $id]) as $link) $sel[(int)$link['CODASSUNTO']] = true;
$linhas = [];
foreach ($L::ler('assuntos') as $a) {
    $cod = (int)($a['CODASSUNTO'] ?? 0); $v = isset($sel[$cod]);
    $linhas[] = ['cells' => [
        'ASSUNTO' => (string)($a['DSCASSUNTO'] ?? $cod),
        'VINCULADO' => ['text' => $v ? 'Sim' : 'Não', 'sort' => $v ? '0' : '1'],
    ], 'actions' => '<input class="form-check-input" type="checkbox" data-grid-marcar value="' . $cod . '"' . ($v ? ' checked' : '') . ($perm['editar'] ? '' : ' disabled') . ' aria-label="Vincular">'];
}
LegislacaoPopup::inicio('Assuntos da legislação', $lei, 'Marque os assuntos da lei e clique em Salvar. A lista vale para todas as empresas.' . ($perm['editar'] ? '' : ' <i class="bi bi-lock"></i> Somente consulta.'));
greenPortalGridLocal([
    'transaction' => 'manutencaolegal_legislacao', 'id' => 'lpAssuntosGrid', 'fragment_class' => 'lp-grid',
    'columns' => ['ASSUNTO' => ['label' => 'Assunto', 'filter' => 'text'], 'VINCULADO' => ['label' => 'Vinculado', 'filter' => 'select', 'width' => '120px']],
    'actions_label' => 'Marcar', 'select_all' => $perm['editar'],
    'rows' => $linhas, 'empty' => 'Nenhum assunto cadastrado.', 'default_sort' => ['VINCULADO', 'asc'], 'page_size' => 50,
    'buttons' => ['reload' => true, 'columns' => false],
    'extras' => $perm['editar'] ? LegislacaoPopup::botao('bi-floppy', 'Salvar os assuntos marcados', 'data-lp-salvar', false, 'success') : '',
]);
LegislacaoPopup::fim($id, 'assuntos', <<<'JS'
(function () {
    var $ = window.jQuery;
    $(document).on('click', '[data-lp-salvar]', function () {
        var marcados = $('[data-grid-marcar]:checked').map(function () { return Number(this.value); }).get();
        LP.enviar({ action: 'save_assuntos', assuntos: JSON.stringify(marcados) }, $(this)).then(LP.gravado, function () {});
    });
}());
JS);
