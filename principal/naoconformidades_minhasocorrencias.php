<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$uid=(int)($_SESSION['usuario']['codusuario']??0);
$myFilters=json_decode((string)($_GET['filtros']??'{}'),true);
$myFilters=is_array($myFilters)?$myFilters:[];
$myFilters['CODUSUARIO_ENVOLVIDO']=$uid;
$_GET['minhas']='1'; $_GET['filtros']=json_encode($myFilters,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
require __DIR__.'/naoconformidades_listageralocorrencias.php';
