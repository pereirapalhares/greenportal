<?php
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/ExportWorkerLauncher.php';
$pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), 'gatlas_dbp', 'GPT');
$columns = [['campo'=>'userid','rotulo'=>'Usuário'],['campo'=>'codperfil','rotulo'=>'Perfil'],['campo'=>'codgrupo','rotulo'=>'Grupos'],['campo'=>'senha','rotulo'=>'Senha']];
$data = json_encode(['codusuario'=>1,'transacao'=>'seguranca_usuarios','filtros'=>json_encode(['userid'=>'admin'],JSON_UNESCAPED_UNICODE),'busca'=>'','colunas'=>json_encode($columns,JSON_UNESCAPED_UNICODE),'status'=>'SOLICITADA'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$s=$pdo->prepare('CALL sp_crud_insert(:tabela,:dados)');$s->execute(['tabela'=>'exportacoes_grid','dados'=>$data]);$s->closeCursor();
$s=$pdo->prepare('CALL sp_crud_select(:tabela,:filtros,NULL,1,0)');$s->execute(['tabela'=>'exportacoes_grid','filtros'=>json_encode(['codusuario'=>1,'transacao'=>'seguranca_usuarios','status'=>'SOLICITADA'])]);$job=$s->fetch(PDO::FETCH_ASSOC);$s->closeCursor();
$id=(int)($job['codexportacaogrid']??0);if(!$id)throw new RuntimeException('Job não criado.');
ExportWorkerLauncher::launch($id,'gatlas_dbp');
for($i=0;$i<10;$i++){sleep(1);$q=$pdo->prepare('CALL sp_crud_select(:tabela,:filtros,NULL,1,0)');$q->execute(['tabela'=>'exportacoes_grid','filtros'=>json_encode(['codexportacaogrid'=>$id])]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();if(in_array((string)($row['status']??''),['CONCLUIDA','ERRO'],true)){echo json_encode($row,JSON_UNESCAPED_UNICODE).PHP_EOL;exit((string)$row['status']==='CONCLUIDA'?0:1);}}
echo json_encode(['codexportacaogrid'=>$id,'status'=>'TIMEOUT'],JSON_UNESCAPED_UNICODE).PHP_EOL;exit(1);
