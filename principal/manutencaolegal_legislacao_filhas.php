<?php
declare(strict_types=1);
// Migrações 382/387/388/389 — Legislação › Legislações filhas, em LISTA DUPLA (components/lista_dupla.php): à esquerda as leis
// disponíveis — por padrão só as SUGERIDAS (mesmo âmbito e tipo relacionado ou assunto em comum, da mais provável para a menos;
// migração 389) ou TODAS as 38 mil —, com busca, filtro por tipo e paginação no servidor; à direita as filhas; › inclui, ‹ retira.
require_once dirname(__DIR__) . '/classes/LegislacaoPopup.php';
require_once dirname(__DIR__) . '/components/lista_dupla.php';

[$id, $perm, $lei] = LegislacaoAssociacoes::iniciarPopup();
$L = LegislacaoAssociacoes::class;
$X = static fn(string $k, array $v = []): string => LegislacaoTextos::t($k, $v);

if (($_POST['ajax'] ?? '') === '1') {
    $acao = (string)($_POST['action'] ?? '');
    try {
        if ($acao === 'ld_listar') {
            $lado = (string)($_POST['lado'] ?? '');
            $busca = trim((string)($_POST['busca'] ?? ''));
            $pagina = max(1, (int)($_POST['pagina'] ?? 1));
            $filtros = (array)json_decode((string)($_POST['filtros'] ?? '{}'), true);
            $filhas = [];
            foreach ($L::filhas($id) as $r) $filhas[(int)$r['CODLEGISLACAO_FILHA']] = $r;
            if ($lado === 'esquerda' && ($filtros['mostrar'] ?? 'sugeridas') !== 'todas') {
                [$rows, $total] = $L::sugestoesFilhas($id, (int)($filtros['tipo'] ?? 0), $busca, $pagina);
                $itens = array_map(static fn(array $l): array => ['id' => (int)$l['CODLEGISLACAO'], 'detalhe' => (string)$l['MOTIVO'],
                    'cells' => ['ROTULO' => $L::rotulo($l), 'CODIGO' => (string)($l['CODIGO'] ?? ''), 'SITUACAO' => LegislacaoTextos::valor((string)($l['situacao_da_lei'] ?? ''))]], $rows);
                $L::json(['ok' => true, 'itens' => $itens, 'total' => $total, 'por_pagina' => 25]);
            }
            if ($lado === 'esquerda') {
                [$rows, $total] = $L::paginaLeis((int)($filtros['tipo'] ?? 0), $busca, $pagina);
                $itens = array_map(static function (array $l) use ($L, $id, $filhas, $X): array {
                    $cod = (int)$l['CODLEGISLACAO'];
                    return ['id' => $cod, 'cells' => ['ROTULO' => $L::rotulo($l), 'CODIGO' => (string)($l['CODIGO'] ?? ''), 'SITUACAO' => LegislacaoTextos::valor((string)($l['situacao_da_lei'] ?? ''))],
                        'bloqueado' => $cod === $id ? $X('ld.bloq_propria') : (isset($filhas[$cod]) ? $X('ld.bloq_ja_filha') : '')];
                }, $rows);
                $L::json(['ok' => true, 'itens' => $itens, 'total' => $total, 'por_pagina' => 25]);
            }
            // direita: as filhas (poucas) — filtro e paginação aqui
            $chave = $L::chave($busca);
            $itens = [];
            foreach ($filhas as $cod => $r) {
                $cells = ['ROTULO' => $L::rotulo($r, 'FILHA_'), 'CODIGO' => (string)($r['FILHA_CODIGO'] ?? ''), 'SITUACAO' => LegislacaoTextos::valor((string)($r['FILHA_SITUACAO'] ?? ''))];
                if ($chave !== '' && !str_contains($L::chave(implode(' ', $cells)), $chave)) continue;
                $itens[] = ['id' => $cod, 'cells' => $cells];
            }
            usort($itens, static fn($a, $b) => strnatcasecmp($a['cells']['ROTULO'], $b['cells']['ROTULO']));
            $L::json(['ok' => true, 'itens' => array_slice($itens, ($pagina - 1) * 25, 25), 'total' => count($itens), 'por_pagina' => 25]);
        }
        if ($acao === 'ld_mover') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)json_decode((string)($_POST['ids'] ?? '[]'), true)))));
            if (!$ids || count($ids) > 200) $L::json(['ok' => false, 'message' => $X('ld.marque_leis')], 422);
            $para = (string)($_POST['para'] ?? '');
            $ok = 0; $erros = [];
            foreach ($ids as $filha) {
                try { $para === 'direita' ? $L::incluirFilha($id, $filha) : $L::removerFilhaPorLei($id, $filha); $ok++; }
                catch (InvalidArgumentException | RuntimeException $e) { $erros[$e->getMessage()] = ($erros[$e->getMessage()] ?? 0) + 1; }
            }
            $msg = $X($para === 'direita' ? 'filhas.msg_incluidas' : 'filhas.msg_retiradas', ['n' => $ok]);
            foreach ($erros as $m => $n) $msg .= ' ' . $X('ld.erros', ['n' => $n, 'motivo' => $m]);
            $L::json(['ok' => true, 'message' => $msg, 'resumo' => $L::resumo($id)]);
        }
        $L::json(['ok' => false, 'message' => $X('erro.acao_desconhecida')], 400);
    } catch (InvalidArgumentException | RuntimeException $e) {
        $L::json(['ok' => false, 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        error_log('[legislacao_filhas] ' . $e->getMessage());
        $L::json(['ok' => false, 'message' => $X('erro.nao_concluido')], 500);
    }
}

$pais = $L::pais($id);
$explica = $X('filhas.explica')
    . ($pais ? '<br><b>' . $L::h($X('filhas.filha_de')) . '</b> ' . implode(', ', array_map(static fn($p) => $L::h($L::rotulo($p, 'PAI_')), $pais)) : '');
$opTipos = '<select class="form-select form-select-sm" data-ld-filtro="mostrar" aria-label="' . $L::h($X('ld.mostrar')) . '" title="' . $L::h($X('filhas.dica_mostrar')) . '">'
    . '<option value="sugeridas" selected>' . $L::h($X('ld.sugeridas')) . '</option><option value="todas">' . $L::h($X('ld.todas')) . '</option></select>'
    . '<select class="form-select form-select-sm" data-ld-filtro="tipo" aria-label="' . $L::h($X('filhas.tipo')) . '"><option value="0">' . $L::h($X('filhas.todos_tipos')) . '</option>';
foreach ($L::todosTipos() as $cod => $nome) $opTipos .= '<option value="' . (int)$cod . '">' . $L::h($nome !== '' ? $nome : (string)$cod) . '</option>';
$opTipos .= '</select>';

LegislacaoPopup::inicio($X('filhas.titulo'), $lei, $explica);
greenPortalListaDuplaHead();
greenPortalListaDupla([
    'id' => 'ldFilhas', 'transaction' => 'manutencaolegal_legislacao', 'editavel' => $perm['editar'], 'csrf' => $L::csrf(),
    'colunas' => ['ROTULO' => ['label' => $X('ld.col_legislacao'), 'width' => '48%'], 'CODIGO' => ['label' => $X('ld.col_codigo'), 'width' => '22%'], 'SITUACAO' => ['label' => $X('ld.col_situacao')]],
    'titulo_esquerda' => $X('filhas.titulo_esquerda'), 'titulo_direita' => $X('filhas.titulo_direita'), 'filtros_esquerda' => $opTipos,
    'vazio_esquerda' => $X('filhas.vazio_esquerda'), 'vazio_direita' => $X('filhas.vazio_direita'),
]);
LegislacaoPopup::fim($id, 'filhas', '', '<script src="../assets/js/lista-dupla.js?v=5"></script>');
