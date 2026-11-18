<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/NaoConformidadeTextos.php';

/**
 * HTML do PDF completo da não conformidade (c526). Recebe o relatório de NaoConformidadeRelatorio::montar (não consulta o banco).
 * Layout em tabelas (o Dompdf não suporta grid/flex). Campos sem valor aparecem com "—", para o PDF mostrar tudo o que a tela mostra.
 */
if (!function_exists('renderNaoConformidadeRelatorioPdf')) {
    function renderNaoConformidadeRelatorioPdf(array $rel): string
    {
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $txt = static fn($v): string => trim((string)$v) === '' ? '<span class="vazio">—</span>' : nl2br($e(trim((string)$v)));
        $tipo = (string)($rel['tipo'] ?? '');
        $T = static fn(string $k, array $v = []): string => NaoConformidadeTextos::t('rel.' . $k, $v);
        $titulo = $T('nc_numero', ['numero' => (string)($rel['numero'] ?? '')]);
        $lang = ['en' => 'en', 'es' => 'es'][IdiomaService::idioma()] ?? 'pt-BR';
        ob_start(); ?>
<!doctype html><html lang="<?= $lang ?>"><head><meta charset="UTF-8"><title><?= $e($titulo . ($tipo !== '' ? ' - ' . $tipo : '')) ?></title>
<style>
@page{size:A4;margin:14mm 12mm 16mm}body{margin:0;color:#24332a;font:9.5px/1.4 DejaVu Sans,Arial,Helvetica,sans-serif}
.cab{width:100%;border-collapse:collapse;background:#1f5638;color:#fff}.cab td{padding:10px 12px;vertical-align:middle}.marca{font-size:15px;font-weight:bold}.sub{font-size:9px;color:#d9ebdf}
.cab .dir{text-align:right;font-size:9px}.cab .dir b{font-size:12px;display:block}
.resumo{width:100%;border-collapse:collapse;background:#edf5ef;border:1px solid #d4e5d8}.resumo td{padding:6px 10px;font-size:9px;vertical-align:top}.resumo b{color:#1f5638}
h2{margin:14px 0 6px;padding:5px 8px;color:#1f5638;background:#e7f1e9;border-left:4px solid #2d7c50;font-size:10.5px;text-transform:uppercase;page-break-after:avoid}
table.campos{width:100%;border-collapse:collapse;table-layout:fixed}table.campos td{padding:4px 6px;border-bottom:1px solid #e2e9e3;vertical-align:top;word-wrap:break-word}
table.campos .rot{width:19%;color:#5c6d63;font-size:8px;font-weight:bold;text-transform:uppercase}table.campos .val{width:31%}
table.grade{width:100%;border-collapse:collapse;table-layout:fixed}table.grade th{padding:4px 3px;color:#fff;background:#2c6947;font-size:6.5px;word-wrap:break-word;text-align:left;text-transform:uppercase}
table.grade td{padding:3px 4px;border:1px solid #d8e2da;vertical-align:top;font-size:8px;word-wrap:break-word}table.grade tr{page-break-inside:avoid}table.grade tbody tr:nth-child(even) td{background:#f6faf7}
thead{display:table-header-group}.vazio{color:#9aa69e}.aviso{padding:7px;color:#69766d;border:1px dashed #cbd8ce;background:#fbfcfb}
.rodape{margin-top:16px;padding-top:6px;color:#718077;border-top:1px solid #dbe5dd;font-size:7.5px}
</style></head><body>
<table class="cab"><tr><td><div class="marca"><?= $e(($rel['empresa'] ?? '') !== '' ? $rel['empresa'] : 'Green Portal') ?></div><div class="sub">Green Portal · <?= $e($T('relatorio_completo')) ?></div></td>
<td class="dir"><b><?= $e(mb_strtoupper($tipo, 'UTF-8')) ?></b><?= $e($titulo) ?></td></tr></table>
<table class="resumo"><tr>
<td><b><?= $e($T('etapa')) ?>:</b> <?= $txt($rel['etapa'] ?? '') ?></td><td><b><?= $e($T('situacao')) ?>:</b> <?= $txt($rel['situacao'] ?? '') ?></td>
<?php foreach ((array)($rel['pessoas'] ?? []) as $rot => $nome): ?><td><b><?= $e(NaoConformidadeTextos::porTexto((string)$rot)) ?>:</b> <?= $txt($nome) ?></td><?php endforeach; ?>
</tr></table>
<?php foreach ((array)($rel['secoes'] ?? []) as $s): ?>
<h2><?= $e($s['titulo'] ?? '') ?></h2>
<?php if (isset($s['campos'])):
    // campos curtos dois por linha; textos longos na linha inteira
    $linha = [];
    $saida = static function (array $par) use ($txt, $e): string {
        $h = '<tr>';
        foreach ($par as [$r, $v]) $h .= '<td class="rot">' . $e($r) . '</td><td class="val">' . $txt($v) . '</td>';
        if (count($par) === 1) $h .= '<td class="rot"></td><td class="val"></td>';
        return $h . '</tr>';
    }; ?>
<table class="campos"><?php
    foreach ($s['campos'] as [$rot, $val, $longo]) {
        if ($longo) {
            if ($linha) { echo $saida($linha); $linha = []; }
            echo '<tr><td class="rot">' . $e($rot) . '</td><td colspan="3">' . $txt($val) . '</td></tr>';
            continue;
        }
        $linha[] = [$rot, $val];
        if (count($linha) === 2) { echo $saida($linha); $linha = []; }
    }
    if ($linha) echo $saida($linha); ?></table>
<?php elseif (isset($s['tabela'])): $t = $s['tabela']; ?>
<?php if (!$t['linhas']): ?><div class="aviso"><?= $e($s['vazio'] ?? $T('nenhum_registro')) ?></div>
<?php else: $lg = (array)($t['larguras'] ?? []); ?><table class="grade"><thead><tr><?php foreach ($t['colunas'] as $i => $col): ?><th<?= isset($lg[$i]) ? ' style="width:' . (int)$lg[$i] . '%"' : '' ?>><?= $e($col) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($t['linhas'] as $l): ?><tr><?php foreach ($l as $cel): ?><td><?= $txt($cel) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table><?php endif; ?>
<?php if (!empty($s['vazio']) && $t['linhas'] && !empty($s['anexos']) && !empty($s['erro'])): ?><div class="aviso"><?= $e($s['vazio']) ?></div><?php endif; ?>
<?php endif; ?>
<?php endforeach; ?>
<div class="rodape"><?= $e($T('rodape', ['empresa' => (string)($rel['empresa'] ?? ''), 'numero' => (string)($rel['numero'] ?? ''), 'codigo' => (int)($rel['codigo'] ?? 0), 'data' => date((IdiomaService::idioma() === 'en' ? 'm/d/Y' : 'd/m/Y') . ' H:i')])) ?></div>
</body></html>
<?php return (string)ob_get_clean();
    }
}
