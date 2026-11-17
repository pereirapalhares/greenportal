<?php
declare(strict_types=1);
$env=[]; foreach(file(__DIR__.'/../.env', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$line=trim($line);if($line===''||$line[0]==='#'||!str_contains($line,'='))continue;[$k,$v]=explode('=',$line,2);$env[trim($k)]=trim(trim($v),'"\'');}
$pdo=new PDO('mysql:host='.($env['GPT_DB_HOST']??'127.0.0.1').';port='.($env['GPT_DB_PORT']??'3306').';dbname=gmodelo_dbp;charset=utf8mb4',$env['GPT_DB_USERNAME']??'',$env['GPT_DB_PASSWORD']??[],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$codes=['LAIA','CORRELACIONAMENTO_AMBIENTAL','CORRELACIONAMENTO_OCUPACIONAL','CORRELACIONAMENTO_SOCIAL','LICENCAS','LICENCAS_CONDICIONANTES'];
$in=implode(',',array_fill(0,count($codes),'?'));
$sql="SELECT p.codigo perfil,m.codigo menu,pm.pode_incluir incluir,pm.pode_visualizar visualizar,pm.pode_editar editar,pm.pode_excluir excluir,pm.pode_exportar_excel exportar,pm.pode_gerar_pdf pdf FROM permissoes pm JOIN perfis p ON p.codperfil=pm.codperfil JOIN menus m ON m.codmenu=pm.codmenu WHERE m.codigo IN ($in) ORDER BY p.codigo,m.codigo";
$s=$pdo->prepare($sql);$s->execute($codes);echo json_encode($s->fetchAll(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
