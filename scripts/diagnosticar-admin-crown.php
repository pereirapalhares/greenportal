<?php
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$e=GreenPortalDatabase::env();$p=GreenPortalDatabase::connect($e,'gcrown_dbp','GPT');
foreach($p->query("SELECT u.codusuario,u.userid,u.nome,u.codperfil,u.ativo,p.codigo AS perfil FROM usuarios u LEFT JOIN perfis p ON p.codperfil=u.codperfil WHERE LOWER(u.userid) LIKE '%admin%' OR LOWER(u.nome) LIKE '%admin%' ORDER BY u.codusuario LIMIT 50") as $r) echo json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;
