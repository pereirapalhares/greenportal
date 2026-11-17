<?php
declare(strict_types=1);
// Migrações 382/387/388/389 — Legislação › Legislações filhas, em LISTA DUPLA (components/lista_dupla.php): à esquerda as leis
// disponíveis — por padrão só as SUGERIDAS (mesmo âmbito e tipo relacionado ou assunto em comum, da mais provável para a menos;
// migração 389) ou TODAS as 38 mil —, com busca, filtro por tipo e paginação no servidor; à direita as filhas; › inclui, ‹ retira.
require_once dirname(__DIR__) . '/classes/LegislacaoPopup.php';
require_once dirname(__DIR__) . '/components/lista_dupla.php';

[$id, $perm, $lei] = LegislacaoAssociacoes::iniciarPopup();
$L = LegislacaoAssociacoes::class;

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
                    'cells' => ['ROTULO' => $L::rotulo($l), 'CODIGO' => (string)($l['CODIGO'] ?? ''), 'SITUACAO' => (string)($l['situacao_da_lei'] ?? '')]], $rows);
                $L::json(['ok' => true, 'itens' => $itens, 'total' => $total, 'por_pagina' => 25]);
            }
            if ($lado === 'esquerda') {
                [$rows, $total] = $L::paginaLeis((int)($filtros['tipo'] ?? 0), $busca, $pagina);
                $itens = array_map(static function (array $l) use ($L, $id, $filhas): array {
                    $cod = (int)$l['CODLEGISLACAO'];
                    return ['id' => $cod, 'cells' => ['ROTULO' => $L::rotulo($l), 'CODIGO' => (string)($l['CODIGO'] ?? ''), 'SITUACAO' => (string)($l['situacao_da_lei'] ?? '')],
                        'bloqueado' => $cod === $id ? 'Esta é a própria lei' : (isset($filhas[$cod]) ? 'Já é filha' : '')];
                }, $rows);
                $L::json(['ok' => true, 'itens' => $itens, 'total' => $total, 'por_pagina' => 25]);
            }
            // direita: as filhas (poucas) — filtro e paginação aqui
            $chave = $L::chave($busca);
            $itens = [];
            foreach ($filhas as $cod => $r) {
                $cells = ['ROTULO' => $L::rotulo($r, 'FILHA_'), 'CODIGO' => (string)($r['FILHA_CODIGO'] ?? ''), 'SITUACAO' => (string)($r['FILHA_SITUACAO'] ?? '')];
                if ($chave !== '' && !str_contains($L::chave(implode(' ', $cells)), $chave)) continue;
                $itens[] = ['id' => $cod, 'cells' => $cells];
            }
            usort($itens, static fn($a, $b) => strnatcasecmp($a['cells']['ROTULO'], $b['cells']['ROTULO']));
            $L::json(['ok' => true, 'itens' => array_slice($itens, ($pagina - 1) * 25, 25), 'total' => count($itens), 'por_pagina' => 25]);
        }
        if ($acao === 'ld_mover') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)json_decode((string)($_POST['ids'] ?? '[]'), true)))));
            if (!$ids || count($ids) > 200) $L::json(['ok' => false, 'message' => 'Marque de 1 a 200 leis.'], 422);
            $para = (string)($_POST['para'] ?? '');
            $ok = 0; $erros = [];
            foreach ($ids as $filha) {
                try { $para === 'direita' ? $L::incluirFilha($id, $filha) : $L::removerFilhaPorLei($id, $filha); $ok++; }
                catch (InvalidArgumentException | RuntimeException $e) { $erros[$e->getMessage()] = ($erros[$e->getMessage()] ?? 0) + 1; }
            }
            $msg = $para === 'direita' ? $ok . ' lei(s) incluída(s) como filha.' : $ok . ' lei(s) retirada(s) das filhas (continuam cadastradas).';
            foreach ($erros as $m => $n) $msg .= ' ' . $n . ' não: ' . $m;
            $L::json(['ok' => true, 'message' => $msg, 'resumo' => $L::resumo($id)]);
        }
        $L::json(['ok' => false, 'message' => 'Ação desconhecida.'], 400);
    } catch (InvalidArgumentException | RuntimeException $e) {
        $L::json(['ok' => false, 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        error_log('[legislacao_filhas] ' . $e->getMessage());
        $L::json(['ok' => false, 'message' => 'Não foi possível concluir a operação.'], 500);
    }
}

$pais = $L::pais($id);
$explica = 'À esquerda, as leis que podem ser filhas desta: em <b>Sugeridas</b>, só as do mesmo âmbito com tipo relacionado ou assunto em comum, da mais provável para a menos (o motivo aparece sob o nome); em <b>Todas</b>, o cadastro inteiro. À direita, as filhas. Tirar uma filha não exclui a lei. Vale para todas as empresas.'
    . ($pais ? '<br><b>É filha de:</b> ' . implode(', ', array_map(static fn($p) => $L::h($L::rotulo($p, 'PAI_')), $pais)) : '');
$opTipos = '<select class="form-select form-select-sm" data-ld-filtro="mostrar" aria-label="Mostrar" title="Sugeridas: possíveis filhas desta lei. Todas: o cadastro inteiro.">'
    . '<option value="sugeridas" selected>Sugeridas</option><option value="todas">Todas</option></select>'
    . '<select class="form-select form-select-sm" data-ld-filtro="tipo" aria-label="Tipo"><option value="0">Todos os tipos</option>';
foreach ($L::todosTipos() as $cod => $nome) $opTipos .= '<option value="' . (int)$cod . '">' . $L::h($nome !== '' ? $nome : (string)$cod) . '</option>';
$opTipos .= '</select>';

LegislacaoPopup::inicio('Legislações filhas', $lei, $explica);
greenPortalListaDuplaHead();
greenPortalListaDupla([
    'id' => 'ldFilhas', 'transaction' => 'manutencaolegal_legislacao', 'editavel' => $perm['editar'], 'csrf' => $L::csrf(),
    'colunas' => ['ROTULO' => ['label' => 'Legislação', 'width' => '48%'], 'CODIGO' => ['label' => 'Código', 'width' => '22%'], 'SITUACAO' => ['label' => 'Situação']],
    'titulo_esquerda' => 'Possíveis filhas', 'titulo_direita' => 'Leis filhas', 'filtros_esquerda' => $opTipos,
    'vazio_esquerda' => 'Nenhuma lei com esse filtro. Em Sugeridas, troque para Todas.', 'vazio_direita' => 'Nenhuma lei filha. Marque à esquerda e use ›.',
]);
LegislacaoPopup::fim($id, 'filhas', '', '<script src="../assets/js/lista-dupla.js?v=3"></script>');
