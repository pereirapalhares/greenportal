<?php
declare(strict_types=1);

/*
 * Minhas atividades › Meus requisitos legais (migração 358, GER-5.1 / LEG-30.1 e LEG-47).
 * A tela de Requisitos legais com o filtro Responsável preso no usuário logado, em toda requisição (página, contagem,
 * exportação, relatório). Responsável = responsável da situação ou atribuição por usuário/grupo (RESPONSAVEL_IDS de
 * vw_modulolegal_requisitos_grid, migração 358). As demais regras e permissões são as da tela de Requisitos legais.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$meuId = (int)($_SESSION['usuario']['codusuario'] ?? 0);
foreach (['_GET', '_POST'] as $origem) {
    $bruto = $origem === '_GET' ? ($_GET['filtros'] ?? null) : ($_POST['filtros'] ?? null);
    if ($origem === '_POST' && $bruto === null) continue;
    $f = json_decode((string)($bruto ?? '{}'), true);
    $f = is_array($f) ? $f : [];
    $f['RESPONSAVEL_IDS'] = ',' . $meuId . ',';
    $novo = json_encode($f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($origem === '_GET') $_GET['filtros'] = $novo; else $_POST['filtros'] = $novo;
}
require __DIR__ . '/modulolegal_requisitoslegais.php';
?>
<script>
/* Meus requisitos: o filtro Responsável fica preso no usuário logado. */
(function () {
  function prender() {
    var s = document.getElementById('requisitosResponsavel');
    if (!s || s.disabled) return;
    s.disabled = true;
    s.title = <?= json_encode(IdiomaService::t('meus_requisitos.filtro_preso', ['pt' => 'Nesta aba aparecem só os requisitos pelos quais você responde (direto ou por grupo).', 'en' => 'This tab shows only the requirements you are responsible for (directly or through a group).', 'es' => 'En esta pestaña aparecen solo los requisitos de los que usted es responsable (directo o por grupo).']), JSON_UNESCAPED_UNICODE) ?>;
  }
  prender();
  new MutationObserver(prender).observe(document.body, { childList: true, subtree: true });
}());
</script>
