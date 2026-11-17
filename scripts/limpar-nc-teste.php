<?php
declare(strict_types=1);

/*
 * Remove do greenportal uma ocorrência de NC criada para TESTE (20/10/2026, etapa 1 da reconstrução da NC).
 * Só apaga se: a ocorrência existe no greenportal, NÃO existe no legado (nada do legado é tocado) e o sumário começa com
 * "TESTE GREENPORTAL". Apaga ações (nc_planodeacao), causas (nc_causas), histórico (nc_workflow_historico) e a ocorrência.
 * 21/10/2026: também os alertas da ocorrência (Home › Alertas, link da tela da ocorrência) e o histórico das ações.
 * Motivo: até a virada o legado manda; uma NC criada no greenportal colidiria com o próximo código criado no legado.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\limpar-nc-teste.php --banco=gcrown_dbp --codigo=19562 [--aplicar]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$banco = ''; $codigo = 0; $aplicar = in_array('--aplicar', $argv ?? [], true);
foreach ($argv ?? [] as $a) {
    if (preg_match('/^--banco=(g[a-z0-9_]+_dbp)$/', $a, $m)) $banco = $m[1];
    if (preg_match('/^--codigo=(\d+)$/', $a, $m)) $codigo = (int)$m[1];
}
$leg = (string)HomologacaoBancos::legado($banco);
if ($leg === '' || $codigo <= 0) { fwrite(STDERR, "Informe --banco=g..._dbp e --codigo=N\n"); exit(1); }
$pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), $banco, 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$v = static function (string $sql, array $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchColumn(); $s->closeCursor(); return $r; };
// Sumário, ou a descrição quando o tipo não tem sumário (Cliente da Crown)
$sumario = $v("SELECT COALESCE(NULLIF(TRIM(IDENTIFICACAO), ''), DESCRICAO) FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = ?", [$codigo]);
$r = ['banco' => $banco, 'codigo' => $codigo, 'modo' => $aplicar ? 'APLICAR' : 'SIMULACAO (use --aplicar)'];
if ($sumario === false) { $r['resultado'] = 'não existe no greenportal'; }
elseif ((int)$v("SELECT COUNT(*) FROM `$leg`.nc_indicacao_ocorrencia WHERE CODOCORRENCIA = ?", [$codigo]) > 0) { $r['resultado'] = 'RECUSADO: o código existe no legado (não é a ocorrência de teste)'; }
elseif (!str_starts_with(strtoupper(trim((string)$sumario)), 'TESTE GREENPORTAL')) { $r['resultado'] = 'RECUSADO: o sumário (ou a descrição, sem sumário) não começa com "TESTE GREENPORTAL"'; }
else {
    $r['apagar'] = [
        'nc_planodeacao' => (int)$v('SELECT COUNT(*) FROM nc_planodeacao WHERE CODOCORRENCIA = ?', [$codigo]),
        'nc_causas' => (int)$v('SELECT COUNT(*) FROM nc_causas WHERE CODOCORRENCIA = ?', [$codigo]),
        'nc_workflow_historico' => (int)$v('SELECT COUNT(*) FROM nc_workflow_historico WHERE CODOCORRENCIA = ?', [$codigo]),
        'nc_indicacao_ocorrencia' => 1,
    ];
    $hp = (int)$v("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nc_planodeacao_historico'") > 0;
    if ($hp) $r['apagar'] = ['nc_planodeacao_historico' => (int)$v('SELECT COUNT(*) FROM nc_planodeacao_historico WHERE CODOCORRENCIA = ?', [$codigo])] + $r['apagar'];
    $link = '/principal/naoconformidades_ocorrencia.php?codigo=' . $codigo . '&%';
    $r['apagar']['alertas'] = (int)$v('SELECT COUNT(*) FROM alertas WHERE link LIKE ?', [$link]);
    if ($aplicar) {
        $pdo->beginTransaction();
        foreach (array_keys($r['apagar']) as $t) {
            if ($t === 'alertas') $pdo->prepare('DELETE FROM alertas WHERE link LIKE ?')->execute([$link]);
            else $pdo->prepare("DELETE FROM `$t` WHERE CODOCORRENCIA = ?")->execute([$codigo]);
        }
        $pdo->commit();
        $r['resultado'] = 'apagada';
    } else $r['resultado'] = 'seria apagada';
}
echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
