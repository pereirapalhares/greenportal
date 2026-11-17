<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$banco = 'gcrown_dbp'; foreach ($argv ?? [] as $arg) if (str_starts_with((string)$arg, '--banco=')) $banco = strtolower(trim(substr((string)$arg, 8)));
if (!preg_match('/^g[a-z0-9_]+_dbp$/', $banco)) throw new InvalidArgumentException('Banco destino inválido.');
$env = GreenPortalDatabase::env(); $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT'); $technicalUser = 1;
$call = static function (PDO $pdo, string $sql, array $params = []): array { $s=$pdo->prepare($sql); $s->execute($params); $r=$s->fetchAll(PDO::FETCH_ASSOC); while($s->nextRowset()){} $s->closeCursor(); return $r; };
$rows = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela'=>'vw_migracao_orfaos_integridade','filtros'=>'{}']);
$classified = ['condicionantes_justificadas'=>0,'responsaveis_removidos'=>0,'erros'=>[]];
foreach ($rows as $row) {
    $tipo=(string)$row['tipo']; $id=(int)$row['registro_id'];
    if ($tipo==='CONDICIONANTE_SEM_LICENCA') { $acao='JUSTIFICAR'; $dados=['CODLICENCA'=>(int)($row['referencia_id']??0)]; $just='Órfão histórico do legado: a licença de origem não existe no banco legado Crown. Registro preservado para auditoria e aguardando decisão de negócio.'; }
    elseif ($tipo==='RESPONSAVEL_INEXISTENTE') { $acao='REMOVER_RESPONSAVEL'; $dados=[]; $just='Responsável legado inexistente no banco destino; referência removida, mantendo o registro da decisão para auditoria.'; }
    else continue;
    try { $call($pdo,'CALL sp_migracao_orfao_decidir(:tipo,:registro,:acao,:dados,:just,:usuario)',['tipo'=>$tipo,'registro'=>$id,'acao'=>$acao,'dados'=>json_encode($dados,JSON_UNESCAPED_UNICODE),'just'=>$just,'usuario'=>$technicalUser]); if($tipo==='CONDICIONANTE_SEM_LICENCA')$classified['condicionantes_justificadas']++;else$classified['responsaveis_removidos']++; }
    catch(Throwable $e){$classified['erros'][]=['tipo'=>$tipo,'registro'=>$id,'erro'=>$e->getMessage()];}
}
$classified['ok']=!$classified['erros']; $classified['banco']=$banco; echo json_encode($classified,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL; exit($classified['ok']?0:1);
