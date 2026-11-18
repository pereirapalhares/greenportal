<?php
declare(strict_types=1);
// Legislação › Assuntos da legislação, em LISTA DUPLA (components/lista_dupla.php), como Filhas, Palavras-chave e CheckList
// (17/11/2026). Antes era um grid com Salvar que carregava só os 1.000 primeiros dos ~21 mil assuntos e, ao salvar, tirava da
// lei os assuntos que não estavam nessa lista. À esquerda o catálogo (busca por texto ou código, paginado no servidor), à
// direita os assuntos da lei; › vincula, ‹ tira o vínculo (o assunto continua no catálogo). Vale para todas as empresas.
require_once dirname(__DIR__) . '/classes/LegislacaoPopup.php';
require_once dirname(__DIR__) . '/components/lista_dupla.php';

[$id, $perm, $lei] = LegislacaoAssociacoes::iniciarPopup();
$L = LegislacaoAssociacoes::class;
$X = static fn(string $k, array $v = []): string => LegislacaoTextos::t($k, $v);

// texto da coluna Assuntos do grid de trás (mesmo formato da vw_legislacao_grid: "A; B; C")
$resumoAssuntos = static fn(): array => ['type' => 'legislacao-assuntos-saved', 'id' => $id, 'assuntos' => implode('; ', array_column($L::assuntosDaLei($id), 'texto'))];

if (($_POST['ajax'] ?? '') === '1') {
    $acao = (string)($_POST['action'] ?? '');
    try {
        if ($acao === 'ld_listar') {
            $lado = (string)($_POST['lado'] ?? '');
            $busca = trim((string)($_POST['busca'] ?? ''));
            $pagina = max(1, (int)($_POST['pagina'] ?? 1));
            $daLei = [];
            foreach ($L::assuntosDaLei($id) as $a) $daLei[$a['cod']] = $a;
            if ($lado === 'esquerda') {
                [$rows, $total] = $L::paginaAssuntos($busca, $pagina);
                $itens = array_map(static fn(array $a): array => ['id' => $a['cod'], 'cells' => ['TEXTO' => $a['texto'], 'CODIGO' => (string)$a['cod']],
                    'bloqueado' => isset($daLei[$a['cod']]) ? $X('ld.bloq_ja_vinculado') : ''], $rows);
                $L::json(['ok' => true, 'itens' => $itens, 'total' => $total, 'por_pagina' => 25]);
            }
            $chave = $L::chave($busca); $itens = [];
            foreach ($daLei as $c => $a) {
                if ($chave !== '' && !str_contains($L::chave($a['texto'] . ' ' . $c), $chave)) continue;
                $itens[] = ['id' => $c, 'cells' => ['TEXTO' => $a['texto'], 'CODIGO' => (string)$c]];
            }
            $L::json(['ok' => true, 'itens' => array_slice($itens, ($pagina - 1) * 25, 25), 'total' => count($itens), 'por_pagina' => 25]);
        }
        if ($acao === 'ld_mover') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)json_decode((string)($_POST['ids'] ?? '[]'), true)))));
            if (!$ids || count($ids) > 200) $L::json(['ok' => false, 'message' => $X('ld.marque_assuntos')], 422);
            $para = (string)($_POST['para'] ?? ''); $ok = 0; $erros = [];
            foreach ($ids as $c) {
                try { $para === 'direita' ? $L::vincularAssunto($id, $c) : $L::desvincularAssunto($id, $c); $ok++; }
                catch (InvalidArgumentException | RuntimeException $e) { $erros[$e->getMessage()] = ($erros[$e->getMessage()] ?? 0) + 1; }
            }
            $msg = $X($para === 'direita' ? 'assuntos.msg_vinculados' : 'assuntos.msg_retirados', ['n' => $ok]);
            foreach ($erros as $m => $n) $msg .= ' ' . $X('ld.erros', ['n' => $n, 'motivo' => $m]);
            $L::json(['ok' => true, 'message' => $msg, 'resumo' => $resumoAssuntos()]);
        }
        $L::json(['ok' => false, 'message' => $X('erro.acao_desconhecida')], 400);
    } catch (InvalidArgumentException | RuntimeException $e) {
        $L::json(['ok' => false, 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        error_log('[legislacao_assuntos] ' . $e->getMessage());
        $L::json(['ok' => false, 'message' => $X('erro.nao_concluido')], 500);
    }
}

$explica = $L::h($X('assuntos.explica'))
    . ($perm['editar'] ? '' : ' <i class="bi bi-lock"></i> ' . $L::h($X('texto.somente_consulta')));
LegislacaoPopup::inicio($X('assuntos.titulo'), $lei, $explica, '.lista-dupla td[data-column="TEXTO"]{white-space:normal!important;overflow-wrap:anywhere}');
greenPortalListaDuplaHead();
greenPortalListaDupla([
    'id' => 'ldAssuntos', 'transaction' => 'manutencaolegal_legislacao', 'editavel' => $perm['editar'], 'csrf' => $L::csrf(),
    'colunas' => ['TEXTO' => ['label' => $X('assuntos.col_assunto'), 'width' => '78%'], 'CODIGO' => ['label' => $X('ld.col_codigo')]],
    'titulo_esquerda' => $X('assuntos.titulo_esquerda'), 'titulo_direita' => $X('assuntos.titulo_direita'),
    'vazio_esquerda' => $X('assuntos.vazio_esquerda'), 'vazio_direita' => $X('assuntos.vazio_direita'),
]);
LegislacaoPopup::fim($id, 'assuntos', '', '<script src="../assets/js/lista-dupla.js?v=5"></script>');
