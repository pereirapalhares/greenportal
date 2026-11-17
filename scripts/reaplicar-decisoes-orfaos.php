<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$banco = 'gcrown_dbp';
foreach ($argv ?? [] as $arg) if (str_starts_with((string)$arg, '--banco=')) $banco = strtolower(trim(substr((string)$arg, 8)));
if (!preg_match('/^g[a-z0-9_]+_dbp$/', $banco)) throw new InvalidArgumentException('Banco destino invalido.');
$pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), $banco, 'GPT');
$rows = $pdo->query("SELECT tipo, registro_id, acao, dados FROM migracao_orfaos_decisoes WHERE acao IN ('REMOVER_RESPONSAVEL','ATRIBUIR_RESPONSAVEL','VINCULAR_LICENCA')")->fetchAll(PDO::FETCH_ASSOC);
$updated = 0;
$pdo->beginTransaction();
try {
    foreach ($rows as $row) {
        $tipo = (string)$row['tipo']; $id = (int)$row['registro_id']; $acao = (string)$row['acao'];
        if ($tipo === 'RESPONSAVEL_INEXISTENTE' && $acao === 'REMOVER_RESPONSAVEL') {
            $s = $pdo->prepare('UPDATE li_condicionantes SET CODUSUARIO=NULL WHERE CODCONDICIONANTE=?'); $s->execute([$id]); $updated += $s->rowCount();
        } elseif ($tipo === 'RESPONSAVEL_INEXISTENTE' && $acao === 'ATRIBUIR_RESPONSAVEL') {
            $dados = json_decode((string)$row['dados'], true) ?: []; $s = $pdo->prepare('UPDATE li_condicionantes SET CODUSUARIO=? WHERE CODCONDICIONANTE=?'); $s->execute([(int)($dados['CODUSUARIO'] ?? 0), $id]); $updated += $s->rowCount();
        } elseif ($tipo === 'CONDICIONANTE_SEM_LICENCA' && $acao === 'VINCULAR_LICENCA') {
            $dados = json_decode((string)$row['dados'], true) ?: []; $s = $pdo->prepare('UPDATE li_condicionantes SET CODLICENCA=? WHERE CODCONDICIONANTE=?'); $s->execute([(int)($dados['CODLICENCA'] ?? 0), $id]); $updated += $s->rowCount();
        }
    }
    $pdo->commit();
} catch (Throwable $e) { $pdo->rollBack(); throw $e; }
echo json_encode(['ok'=>true,'banco'=>$banco,'decisoes_reaplicadas'=>count($rows),'registros_atualizados'=>$updated], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL;
