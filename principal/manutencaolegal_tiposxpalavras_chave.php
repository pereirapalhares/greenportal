<?php
declare(strict_types=1);
// Migração 394 — Manutenção Legal › Tipos × Palavras-chave, em LISTA DUPLA (components/lista_dupla.php), no lugar da
// transação "Tipos X Palavras Chaves" do legado. No alto escolhe-se o tipo (Controle, Infra-Estrutura, Licença, Medição,
// Treinamento...); à esquerda o catálogo único de palavras-chave (o mesmo das leis e das licenças), com os tipos que cada
// palavra já tem; à direita as palavras do tipo escolhido. › inclui e ‹ retira (a palavra continua no catálogo).
require_once dirname(__DIR__) . '/classes/TiposPalavrasChave.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/components/grid_local.php';
require_once dirname(__DIR__) . '/components/lista_dupla.php';

$L = LegislacaoAssociacoes::class;
$T = TiposPalavrasChave::class;
$X = static fn(string $k, array $v = []): string => LegislacaoTextos::t($k, $v);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$ajax = ($_POST['ajax'] ?? '') === '1';
$negar = static function (int $http, string $msg) use ($ajax, $L): never {
    if ($ajax) $L::json(['ok' => false, 'message' => $msg], $http);
    http_response_code($http); header('Content-Type: text/html; charset=utf-8'); exit($L::h($msg));
};
if (empty($_SESSION['autenticado'])) $negar(401, $X('erro.sessao'));
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
if (!UsuarioSessao::atualizar($L::empresa(), $_SESSION)) $negar(401, $X('erro.sessao'));
// Migração 396: aceita a permissão da tela Tipos e Palavras-chave (onde esta tela abre como aba); o código antigo continua valendo.
$pNova = PermissaoTransacao::obter('MANUTENCAOLEGAL_TIPOS_PALAVRAS');
$p = !empty($pNova['pode_visualizar']) ? $pNova : PermissaoTransacao::obter($T::MENU);
$perm = ['ver' => !empty($p['pode_visualizar']), 'editar' => !empty($p['pode_editar']) || !empty($p['pode_incluir'])];
if (!$perm['ver']) $negar(403, $X('tpc.sem_permissao'));
$csrf = $L::csrf();
if ($ajax && ($_POST['action'] ?? '') !== 'ld_listar') {
    if (!$perm['editar']) $negar(403, $X('tpc.sem_permissao_alterar'));
    if (!$L::csrfValido($_POST['csrf'] ?? null)) $negar(400, $X('tpc.pagina_expirada'));
}
session_write_close();

$tipos = $T::tipos();
$tipo = (int)($_GET['tipo'] ?? 0);
if (!isset($tipos[$tipo])) $tipo = (int)(array_key_first($tipos) ?? 0);

if ($ajax) {
    $acao = (string)($_POST['action'] ?? '');
    try {
        if (!$tipo) $L::json(['ok' => false, 'message' => $X('tpc.sem_tipos')], 422);
        if ($acao === 'ld_listar') {
            $lado = (string)($_POST['lado'] ?? '');
            $chave = $L::chave(trim((string)($_POST['busca'] ?? '')));
            $pagina = max(1, (int)($_POST['pagina'] ?? 1));
            $filtros = (array)json_decode((string)($_POST['filtros'] ?? '{}'), true);
            $vinc = $T::vinculos();
            $itens = [];
            foreach ($T::palavras() as $cod => $texto) {
                $dele = $vinc[$cod] ?? [];
                $noTipo = isset($dele[$tipo]);
                if ($lado === 'direita' ? !$noTipo : (($filtros['mostrar'] ?? '') === 'sem_tipo' && $dele)) continue;
                if ($chave !== '' && !str_contains($L::chave($texto . ' ' . $cod), $chave)) continue;
                $outros = array_values(array_filter(array_map(static fn($t) => $tipos[$t]['nome'] ?? null, array_keys($dele)), static fn($n) => $n !== null));
                sort($outros, SORT_NATURAL | SORT_FLAG_CASE);
                $item = ['id' => $cod, 'cells' => ['TEXTO' => $texto, 'CODIGO' => (string)$cod], 'detalhe' => $outros ? $X('tpc.tipos_da_palavra', ['tipos' => implode(', ', $outros)]) : $X('tpc.sem_tipo')];
                if ($lado === 'esquerda' && $noTipo) $item['bloqueado'] = $X('tpc.bloq_ja_esta', ['tipo' => $tipos[$tipo]['nome']]);
                $itens[] = $item;
            }
            usort($itens, static fn($a, $b) => strnatcasecmp($a['cells']['TEXTO'], $b['cells']['TEXTO']));
            $L::json(['ok' => true, 'itens' => array_slice($itens, ($pagina - 1) * 25, 25), 'total' => count($itens), 'por_pagina' => 25]);
        }
        if ($acao === 'ld_mover') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)json_decode((string)($_POST['ids'] ?? '[]'), true)))));
            if (!$ids || count($ids) > 200) $L::json(['ok' => false, 'message' => $X('ld.marque_palavras')], 422);
            $para = (string)($_POST['para'] ?? '');
            $ok = 0; $ja = 0; $erros = [];
            foreach ($ids as $cod) {
                try {
                    if ($para === 'direita') { if ($T::vincular($tipo, $cod)) $ok++; else $ja++; }
                    else $ok += $T::desvincular($tipo, $cod) > 0 ? 1 : 0;
                } catch (InvalidArgumentException | RuntimeException $e) { $erros[$e->getMessage()] = ($erros[$e->getMessage()] ?? 0) + 1; }
            }
            $nome = $tipos[$tipo]['nome'];
            $msg = $para === 'direita' ? $X('tpc.msg_incluidas', ['n' => $ok, 'tipo' => $nome]) . ($ja ? ' ' . $X('tpc.msg_ja', ['n' => $ja]) : '')
                : $X('tpc.msg_retiradas', ['n' => $ok, 'tipo' => $nome]);
            foreach ($erros as $m => $n) $msg .= ' ' . $X('ld.erros', ['n' => $n, 'motivo' => $m]);
            $L::json(['ok' => true, 'message' => $msg]);
        }
        $L::json(['ok' => false, 'message' => $X('erro.acao_desconhecida')], 400);
    } catch (InvalidArgumentException | RuntimeException $e) {
        $L::json(['ok' => false, 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        error_log('[tiposxpalavras_chave] ' . $e->getMessage());
        $L::json(['ok' => false, 'message' => $X('erro.nao_concluido')], 500);
    }
}

$h = [$L, 'h'];
$opcoes = '';
foreach ($tipos as $t) $opcoes .= '<option value="' . $t['cod'] . '"' . ($t['cod'] === $tipo ? ' selected' : '') . '>' . $h($t['nome']) . ' (' . $t['qtd'] . ')</option>';
// a escolha do tipo fica numa linha própria: a barra padrão (botoes_crud) só mantém botões nos grupos
$escolha = '<div class="tpc-tipo"><label for="tpcTipo">' . $h($X('tpc.tipo_label')) . '</label>'
    . '<select class="form-select form-select-sm" id="tpcTipo" data-tpc-tipo aria-label="' . $h($X('tpc.tipo_label')) . '">' . $opcoes . '</select>'
    . '<span class="text-muted small">' . $h($X('tpc.tipo_dica')) . '</span></div>';
?><!doctype html>
<html lang="<?= $h(IdiomaService::idioma()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($X('tpc.titulo')) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php greenPortalGridLocalHead(); greenPortalListaDuplaHead(); ?>
<style>body{display:flex;flex-direction:column;padding:10px 12px;gap:8px;background:#fff}html,body{height:100%!important;overflow:hidden!important}
.tpc-sub{flex:0 0 auto;font-size:11px;color:var(--gp-muted,#71877d)}.tpc-aviso{flex:0 0 auto;font-size:12px}.tpc-aviso:empty{display:none}
.tpc-tipo{flex:0 0 auto;display:flex;align-items:center;gap:8px;flex-wrap:wrap}.tpc-tipo label{font-size:12px;font-weight:700;color:#1f4d3a;margin:0}.tpc-tipo select{width:auto;min-width:220px;font-size:12px}</style>
</head><body>
<div class="tpc-sub"><?= $X('tpc.explica') ?><?= $perm['editar'] ? '' : ' <i class="bi bi-lock"></i> ' . $h($X('texto.somente_consulta')) ?></div>
<div class="tpc-aviso" data-tpc-aviso></div>
<?php if (!$tipos): ?>
<div class="alert alert-warning py-2 small"><?= $h($X('tpc.nenhum_tipo')) ?></div>
<?php else:
echo $escolha;
greenPortalListaDupla([
    'id' => 'ldTiposPalavras', 'transaction' => 'manutencaolegal_tiposxpalavras_chave', 'editavel' => $perm['editar'], 'csrf' => $csrf,
    'colunas' => ['TEXTO' => ['label' => $X('pal.col'), 'width' => '78%'], 'CODIGO' => ['label' => $X('ld.col_codigo')]],
    'titulo_esquerda' => $X('tpc.titulo_esquerda'), 'titulo_direita' => $X('tpc.titulo_direita', ['tipo' => $tipos[$tipo]['nome']]),
    'filtros_esquerda' => '<select class="form-select form-select-sm" data-ld-filtro="mostrar" aria-label="' . $h($X('ld.mostrar')) . '" title="' . $h($X('tpc.dica_mostrar')) . '"><option value="todas" selected>' . $h($X('ld.todas')) . '</option><option value="sem_tipo">' . $h($X('tpc.sem_tipo')) . '</option></select>',
    'vazio_esquerda' => $X('tpc.vazio_esquerda'), 'vazio_direita' => $X('tpc.vazio_direita'),
]);
endif; ?>
<?php greenPortalGridLocalScripts(); ?>
<script>
window.LP = window.LP || { aviso: function (t, k) {
    var a = document.querySelector('[data-tpc-aviso]'); if (!a) return;
    if (!t) { a.innerHTML = ''; return; }
    a.innerHTML = '<div class="alert alert-' + (k || 'info') + ' py-1 px-2 mb-0"></div>'; a.firstChild.textContent = t;
    clearTimeout(window.__tpcAviso); if (k === 'success') window.__tpcAviso = setTimeout(function () { a.innerHTML = ''; }, 6000);
} };
document.addEventListener('change', function (ev) {
    if (!ev.target.matches('[data-tpc-tipo]')) return;
    var u = new URL(window.location.href); u.searchParams.set('tipo', ev.target.value); window.location.href = u.toString();
});
</script>
<?php greenPortalListaDuplaScripts(); ?>
</body></html>
